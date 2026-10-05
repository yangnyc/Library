<?php

namespace App\Modules\Reader\Services;

use App\Models\User;
use App\Modules\Catalog\Models\EditionFile;
use App\Modules\Reader\Models\Annotation;
use App\Modules\Reader\Models\Bookmark;
use App\Modules\Reader\Models\ReadingProgress;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Merges reading state sent by a device into the account.
 *
 * Conflict policy
 * ---------------
 * Every row has a server `revision`. A device sends the revision it last saw
 * (`baseRevision`). If it still matches, the change is applied.
 *
 * If it does not match, another device changed the row in the meantime:
 *  - Progress: the more recently made change wins (by the devices' own
 *    timestamps), never "whichever is further into the book". The losing
 *    position is returned so the reader can be offered a jump to it.
 *  - Bookmarks: last change wins; a bookmark has no content worth merging.
 *  - Notes/highlights: nothing is discarded. An edit beats a delete, and two
 *    different edits are both kept — the second as a separate note marked as
 *    a conflict copy of the first.
 */
class SyncService
{
    /** @return array<string, mixed> */
    public function sync(User $user, array $payload): array
    {
        $deviceId = Str::limit((string) ($payload['deviceId'] ?? ''), 64, '');
        $conflicts = [];

        // Only files this user may currently read can receive state.
        $requested = collect($payload['fileIds'] ?? [])
            ->merge(array_column($payload['progress'] ?? [], 'fileId'))
            ->merge(array_column($payload['bookmarks'] ?? [], 'fileId'))
            ->merge(array_column($payload['annotations'] ?? [], 'fileId'))
            ->map(fn ($id) => (int) $id)->unique()->values();

        $files = EditionFile::query()->whereIn('id', $requested)->where('import_status', 'ready')->with('edition')->get()
            ->filter(fn (EditionFile $f) => $f->edition?->isPublished() || $user->isStaff())
            ->keyBy('id');

        DB::transaction(function () use ($user, $payload, $files, $deviceId, &$conflicts) {
            foreach ($payload['progress'] ?? [] as $item) {
                if ($file = $files->get((int) $item['fileId'])) {
                    $this->mergeProgress($user, $file, $item, $deviceId, $conflicts);
                }
            }
            foreach ($payload['bookmarks'] ?? [] as $item) {
                if ($file = $files->get((int) $item['fileId'])) {
                    $this->mergeBookmark($user, $file, $item);
                }
            }
            foreach ($payload['annotations'] ?? [] as $item) {
                if ($file = $files->get((int) $item['fileId'])) {
                    $this->mergeAnnotation($user, $file, $item, $conflicts);
                }
            }
            if (isset($payload['preferences']['value'])) {
                $this->mergePreferences($user, $payload['preferences']);
            }
        });

        $ids = $files->keys();

        return [
            'progress' => ReadingProgress::query()->where('user_id', $user->id)->whereIn('edition_file_id', $ids)->get()
                ->map(fn (ReadingProgress $p) => $this->progressRow($p))->all(),
            'bookmarks' => Bookmark::withTrashed()->where('user_id', $user->id)->whereIn('edition_file_id', $ids)->get()
                ->map(fn (Bookmark $b) => [
                    'uuid' => $b->uuid, 'fileId' => $b->edition_file_id, 'locatorType' => $b->locator_type,
                    'locator' => $b->locator, 'label' => $b->label, 'revision' => $b->revision,
                    'updatedAt' => $b->client_updated_at?->toIso8601String(), 'deleted' => $b->trashed(),
                ])->all(),
            'annotations' => Annotation::withTrashed()->where('user_id', $user->id)->whereIn('edition_file_id', $ids)->get()
                ->map(fn (Annotation $a) => [
                    'uuid' => $a->uuid, 'fileId' => $a->edition_file_id, 'type' => $a->type,
                    'locatorType' => $a->locator_type, 'locator' => $a->locator, 'quote' => $a->quote,
                    'note' => $a->note, 'color' => $a->color, 'revision' => $a->revision,
                    'conflictOf' => $a->conflict_of, 'updatedAt' => $a->client_updated_at?->toIso8601String(),
                    'deleted' => $a->trashed(),
                ])->all(),
            'preferences' => ['value' => $user->reader_preferences, 'revision' => $user->preferences_revision],
            'conflicts' => $conflicts,
            'acceptedFileIds' => $ids->all(),
        ];
    }

    private function mergeProgress(User $user, EditionFile $file, array $item, string $deviceId, array &$conflicts): void
    {
        $clientTime = $this->time($item['updatedAt'] ?? null);
        $existing = ReadingProgress::query()->where('user_id', $user->id)->where('edition_file_id', $file->id)->lockForUpdate()->first();

        $values = [
            'edition_id' => $file->edition_id,
            'locator_type' => $item['locatorType'],
            'locator' => $item['locator'],
            'fraction' => isset($item['fraction']) ? max(0, min(1, (float) $item['fraction'])) : null,
            'label' => isset($item['label']) ? Str::limit((string) $item['label'], 250, '') : null,
            'device_id' => $deviceId ?: null,
            'client_updated_at' => $clientTime,
        ];

        if (! $existing) {
            ReadingProgress::create($values + ['user_id' => $user->id, 'edition_file_id' => $file->id, 'revision' => 1]);

            return;
        }

        $sameRevision = (int) ($item['baseRevision'] ?? 0) === (int) $existing->revision;
        $samePlace = $existing->locator === $item['locator'];
        if ($sameRevision || $samePlace) {
            if (! $samePlace) {
                $existing->update($values + ['revision' => $existing->revision + 1]);
            }

            return;
        }

        // Diverged: both sides moved since they last agreed.
        $other = $this->progressRow($existing);
        $clientWins = $existing->client_updated_at === null || $clientTime->greaterThan($existing->client_updated_at);
        if ($clientWins) {
            $existing->update($values + ['revision' => $existing->revision + 1]);
        }

        $conflicts[] = [
            'kind' => 'progress',
            'fileId' => $file->id,
            'kept' => $clientWins ? 'this_device' : 'other_device',
            // The position that was not chosen, so the reader can jump to it.
            'other' => $clientWins ? $other : [
                'locatorType' => $item['locatorType'], 'locator' => $item['locator'],
                'fraction' => $values['fraction'], 'label' => $values['label'],
            ],
        ];
    }

    private function mergeBookmark(User $user, EditionFile $file, array $item): void
    {
        $clientTime = $this->time($item['updatedAt'] ?? null);
        $existing = Bookmark::withTrashed()->where('user_id', $user->id)->where('uuid', $item['uuid'])->lockForUpdate()->first();
        $deleted = (bool) ($item['deleted'] ?? false);

        if (! $existing) {
            if (! $deleted) {
                Bookmark::create([
                    'uuid' => $item['uuid'], 'user_id' => $user->id, 'edition_id' => $file->edition_id,
                    'edition_file_id' => $file->id, 'locator_type' => $item['locatorType'], 'locator' => $item['locator'],
                    'label' => Str::limit((string) ($item['label'] ?? ''), 250, '') ?: null,
                    'client_updated_at' => $clientTime, 'revision' => 1,
                ]);
            }

            return;
        }

        // The uuid belongs to this user, but must still refer to the same file.
        if ($existing->edition_file_id !== $file->id) {
            return;
        }

        $current = (int) ($item['baseRevision'] ?? 0) === (int) $existing->revision;
        if (! $current && $existing->client_updated_at && ! $clientTime->greaterThan($existing->client_updated_at)) {
            return;
        }

        $existing->fill([
            'label' => Str::limit((string) ($item['label'] ?? ''), 250, '') ?: null,
            'client_updated_at' => $clientTime, 'revision' => $existing->revision + 1,
        ]);
        $deleted ? $existing->deleted_at = now() : $existing->deleted_at = null;
        $existing->save();
    }

    private function mergeAnnotation(User $user, EditionFile $file, array $item, array &$conflicts): void
    {
        $clientTime = $this->time($item['updatedAt'] ?? null);
        $existing = Annotation::withTrashed()->where('user_id', $user->id)->where('uuid', $item['uuid'])->lockForUpdate()->first();
        $deleted = (bool) ($item['deleted'] ?? false);

        $content = [
            'type' => $item['type'] ?? 'highlight',
            'locator_type' => $item['locatorType'],
            'locator' => $item['locator'],
            'quote' => isset($item['quote']) ? Str::limit((string) $item['quote'], 2000, '') : null,
            'note' => isset($item['note']) ? Str::limit((string) $item['note'], 10000, '') : null,
            'color' => $item['color'] ?? 'yellow',
            'client_updated_at' => $clientTime,
        ];

        if (! $existing) {
            if (! $deleted) {
                Annotation::create($content + [
                    'uuid' => $item['uuid'], 'user_id' => $user->id, 'edition_id' => $file->edition_id,
                    'edition_file_id' => $file->id, 'revision' => 1,
                ]);
            }

            return;
        }
        if ($existing->edition_file_id !== $file->id) {
            return;
        }

        $current = (int) ($item['baseRevision'] ?? 0) === (int) $existing->revision;
        $sameContent = $existing->note === $content['note'] && $existing->color === $content['color'] && $existing->type === $content['type'];

        if ($current) {
            $existing->fill($content + ['revision' => $existing->revision + 1]);
            $existing->deleted_at = $deleted ? now() : null;
            $existing->save();

            return;
        }

        // Diverged.
        if ($deleted) {
            // Delete vs. a newer edit elsewhere: the edit is kept.
            if (! $existing->trashed()) {
                $conflicts[] = ['kind' => 'annotation', 'uuid' => $existing->uuid, 'fileId' => $file->id, 'kept' => 'edited_copy'];
            }

            return;
        }
        if ($existing->trashed()) {
            // Edit vs. a delete elsewhere: the edit is kept, so the note comes back.
            $existing->fill($content + ['revision' => $existing->revision + 1]);
            $existing->deleted_at = null;
            $existing->save();
            $conflicts[] = ['kind' => 'annotation', 'uuid' => $existing->uuid, 'fileId' => $file->id, 'kept' => 'restored'];

            return;
        }
        if ($sameContent) {
            return;
        }

        // Two different edits: keep the server's and store this device's as a copy.
        $copy = Annotation::create($content + [
            'uuid' => (string) Str::uuid(), 'user_id' => $user->id, 'edition_id' => $file->edition_id,
            'edition_file_id' => $file->id, 'revision' => 1, 'conflict_of' => $existing->uuid,
        ]);
        $conflicts[] = ['kind' => 'annotation', 'uuid' => $existing->uuid, 'fileId' => $file->id, 'kept' => 'both', 'copy' => $copy->uuid];
    }

    private function mergePreferences(User $user, array $preferences): void
    {
        $locked = User::query()->whereKey($user->id)->lockForUpdate()->first();
        // Preferences are small display settings: last writer wins.
        $locked->forceFill([
            'reader_preferences' => array_slice((array) $preferences['value'], 0, 30, true),
            'preferences_revision' => $locked->preferences_revision + 1,
        ])->save();
        $user->setRawAttributes($locked->getAttributes(), true);
    }

    private function progressRow(ReadingProgress $progress): array
    {
        return [
            'fileId' => $progress->edition_file_id, 'locatorType' => $progress->locator_type,
            'locator' => $progress->locator, 'fraction' => $progress->fraction, 'label' => $progress->label,
            'revision' => $progress->revision, 'deviceId' => $progress->device_id,
            'updatedAt' => $progress->client_updated_at?->toIso8601String(),
        ];
    }

    /** Device clocks are not trusted to be in the future. */
    private function time(?string $value): Carbon
    {
        try {
            $time = $value ? Carbon::parse($value) : now();
        } catch (\Throwable) {
            $time = now();
        }

        return $time->greaterThan(now()) ? now() : $time;
    }
}
