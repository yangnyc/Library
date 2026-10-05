<?php

namespace App\Modules\Catalog\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

/** One translation or published version of a work. */
class Edition extends Model
{
    public const STATUSES = ['draft', 'processing', 'review', 'published', 'withdrawn'];

    public const RIGHTS = ['public_domain', 'open_license', 'authorized', 'unknown'];

    /** Rights statuses under which an edition may be published at all. */
    public const PUBLISHABLE_RIGHTS = ['public_domain', 'open_license', 'authorized'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'is_featured' => 'boolean',
            'published_at' => 'datetime',
            'withdrawn_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('editions.status', 'published');
    }

    public function isPublished(): bool
    {
        return $this->status === 'published';
    }

    public function work(): BelongsTo
    {
        return $this->belongsTo(Work::class);
    }

    public function files(): HasMany
    {
        return $this->hasMany(EditionFile::class);
    }

    /** Current, successfully imported files. Permission checks still apply per action. */
    public function currentFiles(): HasMany
    {
        return $this->files()->where('is_current', true)->where('import_status', 'ready');
    }

    public function contributors(): MorphToMany
    {
        return $this->morphToMany(Contributor::class, 'contributable', 'contributions')
            ->withPivot('role', 'position')->orderByPivot('position');
    }

    public function translators(): MorphToMany
    {
        return $this->contributors()->wherePivot('role', 'translator');
    }

    public function readableFile(?string $format = null): ?EditionFile
    {
        return $this->currentFiles
            ->filter(fn (EditionFile $f) => $f->can_read && $f->isBrowserReadable() && ($format === null || $f->format === $format))
            ->sortBy(fn (EditionFile $f) => array_search($f->format, ['epub', 'html', 'pdf', 'txt'], true))
            ->first();
    }

    /** Text direction to use for this edition's own title and text. */
    public function dir(): string
    {
        return $this->direction;
    }

    /**
     * Problems that block publication. Empty means publishable.
     *
     * @return list<string>
     */
    public function publicationBlockers(): array
    {
        $blockers = [];

        if (! in_array($this->rights_status, self::PUBLISHABLE_RIGHTS, true)) {
            $blockers[] = 'Rights status must be public domain, open license or explicitly authorized.';
        }
        if (blank($this->source_name) && blank($this->source_url)) {
            $blockers[] = 'A source (name or URL) must be recorded.';
        }
        if ($this->rights_status === 'open_license' && blank($this->license_name)) {
            $blockers[] = 'An open license needs a license name.';
        }
        if ($this->rights_status === 'authorized' && blank($this->rights_holder) && blank($this->rights_notes)) {
            $blockers[] = 'An authorized edition needs the rights holder or the authorization recorded in the rights notes.';
        }
        if (blank($this->attribution)) {
            $blockers[] = 'Attribution text is required.';
        }

        $ready = $this->files()->where('is_current', true)->where('import_status', 'ready')->get();
        if ($ready->isEmpty()) {
            $blockers[] = 'At least one successfully imported file is required.';
        } elseif (! $ready->contains(fn (EditionFile $f) => $f->can_read || $f->can_download)) {
            $blockers[] = 'At least one file must allow reading or downloading.';
        }
        if ($this->files()->where('is_current', true)->whereIn('import_status', ['quarantined', 'processing'])->exists()) {
            $blockers[] = 'An import is still being processed.';
        }

        return $blockers;
    }
}
