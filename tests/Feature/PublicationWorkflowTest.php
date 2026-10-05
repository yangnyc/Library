<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Catalog\Models\Edition;
use App\Modules\Catalog\Models\Work;
use App\Modules\Imports\Models\AuditEvent;
use App\Modules\Reader\Services\SyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class PublicationWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpLibrary();
        $this->actingAs($this->staff('editor'));
    }

    public function test_an_editor_can_take_a_book_from_upload_to_publication(): void
    {
        // Work → draft edition
        $this->post('/admin/works', ['original_title' => 'Workflow Book', 'original_language_tag' => 'en', 'new_authors' => 'Jane Writer'])
            ->assertRedirect();
        $work = Work::firstWhere('original_title', 'Workflow Book');
        $this->post("/admin/works/{$work->id}/editions", ['title' => 'Workflow Book', 'language_tag' => 'en'])->assertRedirect();
        $edition = $work->editions()->firstOrFail();
        $this->assertSame('draft', $edition->status);

        // Upload: processed (sync queue here), then waits for review — still not public.
        $this->post("/admin/editions/{$edition->id}/files", ['file' => $this->epubUpload(['title' => 'Workflow Book', 'publisher' => 'Sample Press'])])
            ->assertRedirect()->assertSessionHasNoErrors();
        $edition->refresh();
        $file = $edition->files()->firstOrFail();
        $this->assertSame('review', $edition->status);
        $this->assertSame('ready', $file->import_status);
        $this->assertFalse($file->is_current);
        $this->assertFalse($file->can_read);

        // Publishing is refused until rights, source, attribution, approval and a permission exist.
        $this->post("/admin/editions/{$edition->id}/publish")->assertSessionHasErrors('publish');
        $this->assertSame('review', $edition->fresh()->status);

        $this->get("/admin/editions/{$edition->id}")->assertOk()
            ->assertSee('Not ready to publish')->assertSee('Metadata found in the file')->assertSee('Sample Press');

        // Editable metadata preview → applied only on request.
        $this->post("/admin/files/{$file->id}/apply-metadata", ['publisher' => 'Sample Press (edited)', 'title' => 'Workflow Book'])->assertRedirect();
        $this->assertSame('Sample Press (edited)', $edition->fresh()->publisher);

        $this->post("/admin/files/{$file->id}/approve")->assertRedirect();
        $this->put("/admin/files/{$file->id}", ['can_read' => 1, 'can_download' => 1, 'can_offline' => 1])->assertRedirect();

        $details = [
            'title' => 'Workflow Book', 'slug' => $edition->slug, 'language_tag' => 'en', 'direction' => 'ltr', 'page_progression' => 'default',
            'rights_status' => 'unknown',
        ];
        $this->put("/admin/editions/{$edition->id}", $details)->assertSessionHasNoErrors();
        $this->post("/admin/editions/{$edition->id}/publish")->assertSessionHasErrors('publish'); // rights unknown

        $this->put("/admin/editions/{$edition->id}", ['rights_status' => 'public_domain', 'source_name' => 'Sample archive', 'attribution' => 'Text from the Sample archive.'] + $details)
            ->assertSessionHasNoErrors();
        $this->assertSame([], $edition->fresh()->publicationBlockers());

        // Preview before publication works for staff only.
        $this->get("/en/read/{$edition->slug}")->assertOk()->assertSee('Staff preview');

        $this->post("/admin/editions/{$edition->id}/publish")->assertSessionHasNoErrors();
        $this->assertSame('published', $edition->fresh()->status);

        auth()->logout();
        $this->get("/en/editions/{$edition->slug}")->assertOk()->assertSee('Public domain')->assertSee('Text from the Sample archive.');
        $this->get("/download/{$file->id}")->assertOk();

        $this->assertNotNull(AuditEvent::firstWhere('action', 'edition.published'));
        $this->assertNotNull(AuditEvent::firstWhere('action', 'file.approved'));
    }

    public function test_unpublishing_is_reversible_and_withdrawal_is_recorded(): void
    {
        $edition = $this->publishedEdition(['title' => 'Now You See It']);
        $file = $this->currentFile($edition);

        $this->post("/admin/editions/{$edition->id}/unpublish")->assertRedirect();
        $this->assertSame('review', $edition->fresh()->status);

        auth()->logout();
        $this->get("/en/editions/{$edition->slug}")->assertNotFound();
        $this->get("/download/{$file->id}")->assertNotFound();
        $this->get("/content/{$file->id}/{$file->contentKey()}/OEBPS/chapter-1.xhtml")->assertNotFound();
        $this->get("/api/offline-manifest/{$file->id}")->assertStatus(410);

        // Publishing again restores everything, unchanged.
        $this->actingAs($this->staff('editor'));
        $this->post("/admin/editions/{$edition->id}/publish")->assertSessionHasNoErrors();
        $this->assertSame('published', $edition->fresh()->status);
        $this->assertTrue($file->fresh()->can_read);
        $this->get("/download/{$file->id}")->assertOk();

        $this->post("/admin/editions/{$edition->id}/withdraw", ['reason' => 'Rights holder request'])->assertRedirect();
        $withdrawn = $edition->fresh();
        $this->assertSame('withdrawn', $withdrawn->status);
        $this->assertSame('Rights holder request', $withdrawn->withdrawn_reason);
        $this->assertSame('Rights holder request', AuditEvent::firstWhere('action', 'edition.withdrawn')->data['reason']);

        auth()->logout();
        $this->get("/en/editions/{$edition->slug}")->assertNotFound();
        $this->get('/en/catalog')->assertDontSee('Now You See It');
    }

    public function test_removing_required_rights_information_unpublishes_the_edition(): void
    {
        $edition = $this->publishedEdition(['title' => 'Needs Rights']);

        $this->put("/admin/editions/{$edition->id}", [
            'title' => 'Needs Rights', 'slug' => $edition->slug, 'language_tag' => 'en', 'direction' => 'ltr',
            'page_progression' => 'default', 'rights_status' => 'unknown',
        ])->assertSessionHasErrors('publish');

        $this->assertSame('review', $edition->fresh()->status);
    }

    public function test_translation_rights_are_separate_from_the_work(): void
    {
        $original = $this->publishedEdition(['title' => 'Old Original', 'rights_status' => 'public_domain']);

        // A new translation of a public-domain work is not publishable just because the original is.
        $translation = $this->draftEdition(['title' => 'Modern Translation', 'language_tag' => 'ru', 'rights_status' => 'unknown'], $original->work);
        $file = $this->import($translation, $this->epubUpload())->file;
        $this->post("/admin/files/{$file->id}/approve");
        $this->put("/admin/files/{$file->id}", ['can_read' => 1]);

        $this->post("/admin/editions/{$translation->id}/publish")->assertSessionHasErrors('publish');
        $this->assertContains(
            'Rights status must be public domain, open license or explicitly authorized.',
            $translation->fresh()->publicationBlockers()
        );

        // "Authorized" needs the authorization to be written down.
        $translation->update(['rights_status' => 'authorized']);
        $this->assertNotEmpty($translation->fresh()->publicationBlockers());
        $translation->update(['rights_holder' => 'The Translator']);
        $this->assertSame([], $translation->fresh()->publicationBlockers());
    }

    public function test_replacing_a_file_creates_a_version_and_never_reuses_reading_positions(): void
    {
        $edition = $this->publishedEdition(['title' => 'Versioned']);
        $first = $this->currentFile($edition);
        $reader = User::factory()->create();

        app(SyncService::class)->sync($reader, [
            'progress' => [['fileId' => $first->id, 'locatorType' => 'cfi', 'locator' => 'epubcfi(/6/4!/4/2)', 'fraction' => 0.4]],
            'annotations' => [['uuid' => (string) Str::uuid(), 'fileId' => $first->id, 'locatorType' => 'cfi', 'locator' => 'epubcfi(/6/4!/4/2,/1:0,/1:5)', 'quote' => 'First', 'note' => 'My note']],
        ]);

        // A corrected file is uploaded: version 2, processed, but not public yet.
        $corrected = $this->epubUpload(['title' => 'Versioned', 'chapters' => [['title' => 'One', 'html' => '<p>Inserted paragraph.</p><p>First chapter text.</p>']]]);
        $this->post("/admin/editions/{$edition->id}/files", ['file' => $corrected])->assertSessionHasNoErrors();
        $second = $edition->files()->where('version', 2)->firstOrFail();

        $this->assertSame('ready', $second->import_status);
        $this->assertFalse($second->is_current);
        $this->assertSame('published', $edition->fresh()->status, 'A pending replacement must not disturb the published edition.');
        $this->assertTrue($first->fresh()->is_current);
        $this->assertNotSame($first->contentKey(), $second->contentKey());

        auth()->logout();
        $this->get("/content/{$second->id}/{$second->contentKey()}/OEBPS/chapter-1.xhtml")->assertNotFound();
        $this->get("/content/{$first->id}/{$first->contentKey()}/OEBPS/chapter-1.xhtml")->assertOk();

        // Approval switches versions.
        $this->actingAs($this->staff('editor'));
        $this->post("/admin/files/{$second->id}/approve")->assertRedirect();
        $this->put("/admin/files/{$second->id}", ['can_read' => 1, 'can_download' => 1, 'can_offline' => 1]);
        auth()->logout();

        $this->assertFalse($first->fresh()->is_current);
        $this->get("/content/{$first->id}/{$first->contentKey()}/OEBPS/chapter-1.xhtml")->assertNotFound();
        $this->get("/content/{$second->id}/{$second->contentKey()}/OEBPS/chapter-1.xhtml")->assertOk();
        $this->get("/api/offline-manifest/{$first->id}")->assertStatus(410)->assertJson(['replacementFileId' => $second->id]);

        // The reader page now hands out the new content key …
        $this->get("/en/read/{$edition->slug}")->assertOk()->assertSee($second->contentKey())->assertDontSee($first->contentKey());

        // … and the old position and note stay attached to version 1 only.
        $this->assertSame(0, $reader->readingProgress()->where('edition_file_id', $second->id)->count());
        $this->assertSame(1, $reader->readingProgress()->where('edition_file_id', $first->id)->count());
        $this->assertSame(1, $reader->annotations()->where('edition_file_id', $first->id)->count());

        $this->actingAs($reader);
        $this->get('/en/account')->assertOk()->assertSee('earlier file version')->assertSee('belongs to an earlier version');
    }

    public function test_cover_upload_is_reencoded_and_bounded(): void
    {
        $edition = $this->draftEdition();

        $image = imagecreatetruecolor(1600, 2400);
        $path = $this->temporaryPath('png');
        imagepng($image, $path);
        $this->post("/admin/editions/{$edition->id}/cover", ['cover' => new UploadedFile($path, 'cover.png', 'image/png', null, true)])
            ->assertSessionHasNoErrors();

        $stored = Storage::disk('covers')->path($edition->fresh()->cover_path);
        [$width, $height, $type] = getimagesize($stored);
        $this->assertSame(IMAGETYPE_JPEG, $type);
        $this->assertLessThanOrEqual(640, $width);
        $this->assertLessThanOrEqual(960, $height);

        // Not an image at all.
        $fake = $this->temporaryPath('png');
        file_put_contents($fake, '<?php echo 1;');
        $this->post("/admin/editions/{$edition->id}/cover", ['cover' => new UploadedFile($fake, 'cover.png', 'image/png', null, true)])
            ->assertSessionHasErrors('cover');
    }

    public function test_editions_that_were_public_are_withdrawn_not_deleted(): void
    {
        $edition = $this->publishedEdition();
        $this->post("/admin/editions/{$edition->id}/unpublish");

        $this->delete("/admin/editions/{$edition->id}")->assertSessionHasErrors('publish');
        $this->assertNotNull(Edition::find($edition->id));

        $draft = $this->draftEdition();
        $this->delete("/admin/editions/{$draft->id}")->assertRedirect();
        $this->assertNull(Edition::find($draft->id));
    }
}
