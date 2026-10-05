<?php

namespace Tests;

use App\Models\User;
use App\Modules\Catalog\Models\Edition;
use App\Modules\Catalog\Models\EditionFile;
use App\Modules\Catalog\Models\Work;
use App\Modules\Catalog\Services\CatalogWriter;
use App\Modules\Imports\Models\ImportJob;
use App\Modules\Imports\Services\ImportPipeline;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\Support\DemoBookBuilder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

abstract class TestCase extends BaseTestCase
{
    /** @var list<string> */
    private array $temporaryFiles = [];

    /** Fakes book storage and seeds the three languages. Call from setUp() after the database is ready. */
    protected function setUpLibrary(): void
    {
        Storage::fake('library');
        Storage::fake('covers');
        $this->seed(DatabaseSeeder::class);
    }

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    protected function temporaryPath(string $extension): string
    {
        return $this->temporaryFiles[] = sys_get_temp_dir().DIRECTORY_SEPARATOR.'orl-test-'.Str::random(12).'.'.$extension;
    }

    protected function staff(string $role = 'editor', bool $twoFactor = true): User
    {
        $user = User::factory()->create();
        $user->forceFill(['role' => $role] + ($twoFactor ? [
            'two_factor_secret' => encrypt('TESTSECRETTESTSECRET'),
            'two_factor_recovery_codes' => encrypt(json_encode(['code-1'])),
            'two_factor_confirmed_at' => now(),
        ] : []))->save();

        return $user;
    }

    /** @param array<string, mixed> $overrides for DemoBookBuilder::epub() */
    protected function epubUpload(array $overrides = [], string $name = 'book.epub'): UploadedFile
    {
        $path = DemoBookBuilder::epub($this->temporaryPath('epub'), $overrides + [
            'title' => 'Test Book', 'language' => 'en', 'author' => 'Test Author',
            'identifier' => 'urn:test:'.Str::random(8),
            'chapters' => [
                ['title' => 'One', 'html' => '<p>First chapter text.</p>'],
                ['title' => 'Two', 'html' => '<p>Second chapter text.</p>'],
            ],
        ]);

        return new UploadedFile($path, $name, 'application/epub+zip', null, true);
    }

    protected function pdfUpload(string $name = 'book.pdf'): UploadedFile
    {
        $path = DemoBookBuilder::pdf($this->temporaryPath('pdf'), 'Test PDF', [['Page one', 'Some text.'], ['Page two', 'More text.']]);

        return new UploadedFile($path, $name, 'application/pdf', null, true);
    }

    protected function draftEdition(array $attributes = [], ?Work $work = null): Edition
    {
        $work ??= Work::create([
            'slug' => 'work-'.Str::lower(Str::random(8)),
            'original_title' => $attributes['title'] ?? 'Test Work',
            'original_language_tag' => 'en',
        ]);

        $edition = $work->editions()->create($attributes + [
            'slug' => 'edition-'.Str::lower(Str::random(8)),
            'language_tag' => 'en', 'direction' => 'ltr', 'title' => 'Test Edition', 'status' => 'draft',
            'rights_status' => 'public_domain', 'source_name' => 'Test source', 'attribution' => 'Test attribution',
        ]);
        app(CatalogWriter::class)->reindex($edition);

        return $edition;
    }

    /** Runs a real import (sync queue) and returns the resulting import job. */
    protected function import(Edition $edition, UploadedFile $upload): ImportJob
    {
        return app(ImportPipeline::class)->intake($upload, $edition, null, allowDuplicate: true)->refresh();
    }

    /** A published edition with one approved, readable, downloadable file. */
    protected function publishedEdition(array $attributes = [], ?UploadedFile $upload = null, ?Work $work = null, array $permissions = []): Edition
    {
        $edition = $this->draftEdition($attributes, $work);
        $job = $this->import($edition, $upload ?? $this->epubUpload(['title' => $edition->title, 'language' => $edition->language_tag, 'direction' => $edition->direction]));
        $this->assertSame('review', $job->status, 'Import failed: '.$job->error);

        app(ImportPipeline::class)->approve($job->file);
        $job->file->update($permissions + ['can_read' => true, 'can_download' => true, 'can_offline' => true]);

        $edition->refresh()->forceFill(['status' => 'published', 'published_at' => now()])->save();

        return $edition->fresh();
    }

    protected function currentFile(Edition $edition, string $format = 'epub'): EditionFile
    {
        return $edition->files()->where('format', $format)->where('is_current', true)->firstOrFail();
    }
}
