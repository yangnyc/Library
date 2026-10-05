<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Reader\Models\Annotation;
use App\Modules\Reader\Models\Bookmark;
use App\Modules\Reader\Models\ReadingProgress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class SyncTest extends TestCase
{
    use RefreshDatabase;

    private int $fileId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpLibrary();
        $this->fileId = $this->currentFile($this->publishedEdition(['title' => 'Synced Book']))->id;
    }

    private function progress(string $locator, int $baseRevision, string $updatedAt, float $fraction = 0.1): array
    {
        return ['fileId' => $this->fileId, 'locatorType' => 'cfi', 'locator' => $locator, 'fraction' => $fraction,
            'label' => 'Chapter', 'baseRevision' => $baseRevision, 'updatedAt' => $updatedAt];
    }

    public function test_sync_requires_an_account_and_guests_are_told_so(): void
    {
        $this->postJson('/api/sync', ['fileIds' => [$this->fileId]])->assertUnauthorized();
        $this->getJson('/api/session')->assertOk()->assertJson(['user' => null])->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_progress_is_versioned_and_newer_change_wins_not_the_furthest(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        // Device A reads to 80 %.
        $first = $this->postJson('/api/sync', ['deviceId' => 'A', 'progress' => [$this->progress('cfi-80', 0, now()->subHour()->toIso8601String(), 0.8)]])
            ->assertOk()->json();
        $this->assertSame(1, $first['progress'][0]['revision']);
        $this->assertSame([], $first['conflicts']);

        // Same device moves on, quoting the revision it saw: plain update.
        $second = $this->postJson('/api/sync', ['deviceId' => 'A', 'progress' => [$this->progress('cfi-85', 1, now()->subMinutes(50)->toIso8601String(), 0.85)]])->json();
        $this->assertSame(2, $second['progress'][0]['revision']);
        $this->assertSame([], $second['conflicts']);

        // Device B never saw those revisions and deliberately went BACK to 10 % more recently.
        $third = $this->postJson('/api/sync', ['deviceId' => 'B', 'progress' => [$this->progress('cfi-10', 0, now()->subMinute()->toIso8601String(), 0.1)]])->json();

        // The newer change wins even though it is "less far"; the other place is offered, not lost.
        $this->assertSame('cfi-10', $third['progress'][0]['locator']);
        $this->assertSame('this_device', $third['conflicts'][0]['kept']);
        $this->assertSame('cfi-85', $third['conflicts'][0]['other']['locator']);

        // A stale device with an OLDER change does not overwrite, and is told where the account is.
        $fourth = $this->postJson('/api/sync', ['deviceId' => 'C', 'progress' => [$this->progress('cfi-99', 0, now()->subDays(2)->toIso8601String(), 0.99)]])->json();
        $this->assertSame('cfi-10', $fourth['progress'][0]['locator']);
        $this->assertSame('other_device', $fourth['conflicts'][0]['kept']);
        $this->assertSame('cfi-99', $fourth['conflicts'][0]['other']['locator']);

        $this->assertSame(1, ReadingProgress::where('user_id', $user->id)->count());
    }

    public function test_conflicting_note_edits_are_both_kept(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $uuid = (string) Str::uuid();
        $note = fn (string $text, int $base, array $extra = []) => $extra + [
            'uuid' => $uuid, 'fileId' => $this->fileId, 'locatorType' => 'cfi', 'locator' => 'cfi-range', 'quote' => 'quoted', 'note' => $text,
            'baseRevision' => $base, 'updatedAt' => now()->toIso8601String(),
        ];

        $this->postJson('/api/sync', ['annotations' => [$note('original', 0)]])->assertOk();
        // Device A edits (saw revision 1).
        $this->postJson('/api/sync', ['annotations' => [$note('edited on A', 1)]])->assertOk();
        // Device B edits the same note while still on revision 1.
        $response = $this->postJson('/api/sync', ['annotations' => [$note('edited on B', 1)]])->assertOk()->json();

        $this->assertSame('both', $response['conflicts'][0]['kept']);
        $notes = Annotation::where('user_id', $user->id)->orderBy('id')->get();
        $this->assertCount(2, $notes);
        $this->assertSame('edited on A', $notes[0]->note);
        $this->assertSame('edited on B', $notes[1]->note);
        $this->assertSame($uuid, $notes[1]->conflict_of);
        $this->assertCount(2, $response['annotations'], 'The device receives both versions.');

        // Delete on one device vs. edit on another: the edit survives.
        $this->postJson('/api/sync', ['annotations' => [$note('', 1, ['deleted' => true])]])->assertOk();
        $this->assertNull(Annotation::where('uuid', $uuid)->first()->deleted_at);

        // Edit vs. delete: a deleted note that was edited elsewhere comes back with the edit.
        Annotation::where('uuid', $uuid)->first()->delete();
        $restored = $this->postJson('/api/sync', ['annotations' => [$note('edited after delete', 1)]])->assertOk()->json();
        $this->assertSame('restored', $restored['conflicts'][0]['kept']);
        $this->assertSame('edited after delete', Annotation::where('uuid', $uuid)->first()->note);
    }

    public function test_bookmarks_sync_and_tombstones_propagate(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $uuid = (string) Str::uuid();
        $bookmark = ['uuid' => $uuid, 'fileId' => $this->fileId, 'locatorType' => 'cfi', 'locator' => 'cfi-b', 'label' => 'Here', 'baseRevision' => 0, 'updatedAt' => now()->toIso8601String()];

        $created = $this->postJson('/api/sync', ['bookmarks' => [$bookmark]])->assertOk()->json();
        $this->assertFalse($created['bookmarks'][0]['deleted']);

        $deleted = $this->postJson('/api/sync', ['bookmarks' => [['deleted' => true, 'baseRevision' => 1] + $bookmark]])->assertOk()->json();
        $this->assertTrue($deleted['bookmarks'][0]['deleted']);
        $this->assertSame(0, Bookmark::where('user_id', $user->id)->count());
        $this->assertSame(1, Bookmark::withTrashed()->where('user_id', $user->id)->count());
    }

    public function test_one_reader_can_never_read_or_change_anothers_data(): void
    {
        $alice = User::factory()->create();
        $mallory = User::factory()->create();
        $uuid = (string) Str::uuid();

        $this->actingAs($alice)->postJson('/api/sync', [
            'progress' => [$this->progress('alice-place', 0, now()->toIso8601String())],
            'annotations' => [['uuid' => $uuid, 'fileId' => $this->fileId, 'locatorType' => 'cfi', 'locator' => 'x', 'note' => 'private thought']],
        ])->assertOk();

        // Mallory pulls the same file and even guesses Alice's note uuid.
        $response = $this->actingAs($mallory)->postJson('/api/sync', [
            'fileIds' => [$this->fileId],
            'annotations' => [['uuid' => $uuid, 'fileId' => $this->fileId, 'locatorType' => 'cfi', 'locator' => 'x', 'note' => 'overwritten', 'baseRevision' => 1]],
        ])->assertOk();

        $this->assertStringNotContainsString('private thought', $response->getContent());
        $this->assertStringNotContainsString('alice-place', $response->getContent());
        $this->assertSame('private thought', Annotation::where('user_id', $alice->id)->first()->note);
        $this->assertSame(1, Annotation::where('user_id', $mallory->id)->count(), 'Mallory only created a note of their own.');

        // Exports and account pages are scoped the same way.
        $export = $this->actingAs($mallory)->get('/en/account/export')->assertOk();
        $this->assertStringNotContainsString('private thought', $export->getContent());
        $this->assertStringContainsString('private thought', $this->actingAs($alice)->get('/en/account/export')->getContent());
    }

    public function test_state_cannot_be_attached_to_unpublished_files_or_invalid_payloads(): void
    {
        $draftFile = $this->import($this->draftEdition(), $this->epubUpload())->file;
        $user = User::factory()->create();
        $this->actingAs($user);

        $response = $this->postJson('/api/sync', ['progress' => [['fileId' => $draftFile->id, 'locatorType' => 'cfi', 'locator' => 'x']]])->assertOk()->json();
        $this->assertSame([], $response['acceptedFileIds']);
        $this->assertSame(0, ReadingProgress::count());

        $this->postJson('/api/sync', ['progress' => [['fileId' => $this->fileId, 'locatorType' => 'sql', 'locator' => 'x']]])->assertStatus(422);
        $this->postJson('/api/sync', ['bookmarks' => [['uuid' => 'not-a-uuid', 'fileId' => $this->fileId, 'locatorType' => 'cfi', 'locator' => 'x']]])->assertStatus(422);
        $this->postJson('/api/sync', ['progress' => [['fileId' => $this->fileId, 'locatorType' => 'cfi', 'locator' => str_repeat('x', 5000)]]])->assertStatus(422);
    }
}
