<?php

namespace Tests\Feature;

use App\Modules\Downloads\ContentAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DownloadAndContentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpLibrary();
    }

    public function test_download_sends_safe_headers_with_unicode_and_ascii_filenames(): void
    {
        $edition = $this->publishedEdition(['title' => 'Альманах «Маяк»: том 1', 'language_tag' => 'ru']);
        $file = $this->currentFile($edition);

        $response = $this->get("/download/{$file->id}")->assertOk();
        $response->assertHeader('Content-Type', 'application/epub+zip');
        $response->assertHeader('Accept-Ranges', 'bytes');
        $response->assertHeader('Content-Length', (string) $file->byte_size);
        $response->assertHeader('X-Content-Type-Options', 'nosniff');

        $disposition = $response->headers->get('Content-Disposition');
        $this->assertStringStartsWith('attachment; filename="', $disposition);
        // ASCII fallback has no quotes, path separators or non-ASCII bytes …
        $this->assertMatchesRegularExpression('/filename="[A-Za-z0-9.\-]+\.epub"/', $disposition);
        // … and the real title travels percent-encoded (RFC 6266 / 5987).
        $this->assertStringContainsString("filename*=UTF-8''".rawurlencode('Альманах «Маяк» том 1.epub'), $disposition);

        $this->assertSame($file->byte_size, strlen($response->streamedContent()));
        $this->assertSame(1, $file->fresh()->download_count);
        // The response never reveals where the file lives on disk.
        $this->assertStringNotContainsString('originals', (string) $response->headers);
    }

    public function test_head_and_byte_ranges(): void
    {
        $edition = $this->publishedEdition(['title' => 'Ranges'], $this->pdfUpload());
        $file = $this->currentFile($edition, 'pdf');
        $bytes = Storage::disk('library')->get($file->storage_path);
        $size = strlen($bytes);
        $url = "/download/{$file->id}/ranges.pdf";

        $head = $this->call('HEAD', $url);
        $head->assertOk()->assertHeader('Content-Length', (string) $size);
        $this->assertSame('', $head->getContent());

        $partial = $this->withHeader('Range', 'bytes=0-9')->get($url);
        $partial->assertStatus(206)->assertHeader('Content-Range', "bytes 0-9/$size")->assertHeader('Content-Length', '10');
        $this->assertSame(substr($bytes, 0, 10), $partial->streamedContent());

        $middle = $this->withHeader('Range', 'bytes=100-')->get($url);
        $middle->assertStatus(206)->assertHeader('Content-Range', 'bytes 100-'.($size - 1)."/$size");
        $this->assertSame(substr($bytes, 100), $middle->streamedContent());

        $suffix = $this->withHeader('Range', 'bytes=-5')->get($url);
        $suffix->assertStatus(206);
        $this->assertSame(substr($bytes, -5), $suffix->streamedContent());

        $this->withHeader('Range', "bytes=$size-")->get($url)->assertStatus(416)->assertHeader('Content-Range', "bytes */$size");

        // Unparseable or multi-part ranges fall back to the whole file.
        $this->withHeader('Range', 'bytes=0-1,5-6')->get($url)->assertOk();

        $etag = $this->get($url)->headers->get('ETag');
        $this->withHeader('If-None-Match', $etag)->get($url)->assertStatus(304);

        // Only requests that start at the beginning count as a download: the
        // first range, the two whole-file responses above — not the 304.
        $this->assertSame(3, $file->fresh()->download_count);
    }

    public function test_downloads_respect_publication_and_per_file_permissions(): void
    {
        $edition = $this->publishedEdition(['title' => 'Guarded'], null, null, ['can_download' => false]);
        $file = $this->currentFile($edition);

        $this->get("/download/{$file->id}")->assertNotFound();   // reading allowed, download not
        $this->get("/en/read/{$edition->slug}")->assertOk();

        $file->update(['can_download' => true, 'can_read' => false, 'can_offline' => false]);
        $this->get("/download/{$file->id}")->assertOk();
        $this->get("/en/read/{$edition->slug}")->assertNotFound();
        $this->get("/api/offline-manifest/{$file->id}")->assertStatus(410);

        $edition->update(['status' => 'withdrawn']);
        $this->get("/download/{$file->id}")->assertNotFound();
        $this->get('/download/999999')->assertNotFound();
    }

    public function test_reading_copy_is_served_without_cookies_and_only_for_known_paths(): void
    {
        $edition = $this->publishedEdition(['title' => 'Content']);
        $file = $this->currentFile($edition);
        $base = "/content/{$file->id}/{$file->contentKey()}";

        $response = $this->get("$base/OEBPS/chapter-1.xhtml")->assertOk();
        $response->assertHeader('Content-Type', 'application/xhtml+xml');
        $this->assertStringContainsString('sandbox', $response->headers->get('Content-Security-Policy'));
        $this->assertStringContainsString("default-src 'none'", $response->headers->get('Content-Security-Policy'));
        $this->assertEmpty($response->headers->getCookies(), 'Book content must never set a cookie.');
        $this->assertStringContainsString('First chapter text', $response->streamedContent());

        $this->get("$base/OEBPS/content.opf")->assertOk();
        $this->get("$base/OEBPS/style.css")->assertOk()->assertHeader('Content-Type', 'text/css; charset=utf-8');

        // Anything not written by the import is a 404 — including traversal attempts.
        foreach (['OEBPS/../../../../.env', '..%2F..%2F.env', 'OEBPS/missing.xhtml', '/etc/passwd', 'mimetype', 'OEBPS/chapter-1.xhtml%00.png'] as $path) {
            $this->get("$base/$path")->assertNotFound();
        }

        // A wrong or stale content key gives nothing.
        $this->get("/content/{$file->id}/{$file->id}-v9-000000000000/OEBPS/chapter-1.xhtml")->assertNotFound();

        $file->update(['can_read' => false]);
        $this->get("$base/OEBPS/chapter-1.xhtml")->assertNotFound();
    }

    public function test_unpublished_content_needs_a_valid_unexpired_preview_token(): void
    {
        $draft = $this->draftEdition();
        $file = $this->import($draft, $this->epubUpload())->file;
        $access = app(ContentAccess::class);

        $this->get("/content/{$file->id}/{$file->contentKey()}/OEBPS/chapter-1.xhtml")->assertNotFound();

        $token = $access->previewToken($file);
        $this->get("/content/{$file->id}/$token/OEBPS/chapter-1.xhtml")->assertOk()->assertHeader('Cache-Control', 'no-store, private');

        // A token for one file does not open another, and tampering breaks it.
        $other = $this->import($this->draftEdition(), $this->epubUpload())->file;
        $this->get("/content/{$other->id}/$token/OEBPS/chapter-1.xhtml")->assertNotFound();
        $tampered = substr($token, 0, -1).(str_ends_with($token, '0') ? '1' : '0');
        $this->get("/content/{$file->id}/$tampered/OEBPS/chapter-1.xhtml")->assertNotFound();
        // Extending the expiry without the key does not work either.
        $extended = preg_replace('/^p\d{10}/', 'p'.now()->addYear()->getTimestamp(), $token);
        $this->get("/content/{$file->id}/$extended/OEBPS/chapter-1.xhtml")->assertNotFound();

        $this->travel(3)->hours();
        $this->get("/content/{$file->id}/$token/OEBPS/chapter-1.xhtml")->assertNotFound();
    }

    public function test_pdf_reading_supports_ranges_for_incremental_loading(): void
    {
        $edition = $this->publishedEdition(['title' => 'Pdf'], $this->pdfUpload());
        $file = $this->currentFile($edition, 'pdf');
        $url = "/content/{$file->id}/{$file->contentKey()}/book.pdf";

        $this->get($url)->assertOk()->assertHeader('Content-Type', 'application/pdf')->assertHeader('Accept-Ranges', 'bytes');
        $part = $this->withHeader('Range', 'bytes=0-4')->get($url)->assertStatus(206);
        $this->assertSame('%PDF-', $part->streamedContent());
        $this->get("/content/{$file->id}/{$file->contentKey()}/other.pdf")->assertNotFound();
    }

    public function test_offline_manifest_lists_only_what_rights_allow(): void
    {
        $edition = $this->publishedEdition(['title' => 'Offline']);
        $file = $this->currentFile($edition);

        $manifest = $this->getJson("/api/offline-manifest/{$file->id}")->assertOk()->json();
        $this->assertTrue($manifest['available']);
        $this->assertSame($file->contentKey(), $manifest['contentKey']);
        $this->assertContains("/content/{$file->id}/{$file->contentKey()}/OEBPS/chapter-1.xhtml", array_column($manifest['resources'], 'url'));
        $this->assertContains("/en/read/{$edition->slug}/epub", $manifest['pages']);
        $this->assertSame(array_sum(array_column($manifest['resources'], 'bytes')), $manifest['totalBytes']);

        $file->update(['can_offline' => false]);
        $this->getJson("/api/offline-manifest/{$file->id}")->assertStatus(410)->assertJson(['available' => false]);
    }
}
