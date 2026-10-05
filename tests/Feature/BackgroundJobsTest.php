<?php

namespace Tests\Feature;

use App\Modules\Imports\Jobs\ProcessImport;
use App\Modules\Imports\Models\ImportJob;
use App\Modules\Imports\Services\EpubProcessor;
use App\Modules\Imports\Services\ImportPipeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Deployment without a persistent worker: everything runs from short, cron-launched commands. */
class BackgroundJobsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpLibrary();
    }

    public function test_uploads_wait_in_the_database_queue_until_a_bounded_worker_run(): void
    {
        config(['queue.default' => 'database']);
        $edition = $this->draftEdition();

        $job = $this->import($edition, $this->epubUpload());

        // Nothing has processed it yet: quarantined, private, edition marked as processing.
        $this->assertSame('queued', $job->status);
        $this->assertSame('quarantined', $job->file->import_status);
        $this->assertSame('processing', $edition->fresh()->status);
        $this->assertSame(1, DB::table('jobs')->count());
        $this->assertNotEmpty(Storage::disk('library')->allFiles('quarantine'));

        // What cron runs: drains the queue, then exits by itself.
        $exit = Artisan::call('queue:work', ['--stop-when-empty' => true, '--max-time' => 30, '--timeout' => 40, '--sleep' => 0]);

        $this->assertSame(0, $exit);
        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame('review', $job->fresh()->status);
        $this->assertSame('ready', $job->file->fresh()->import_status);
        $this->assertSame('review', $edition->fresh()->status);
        $this->assertSame([], Storage::disk('library')->allFiles('quarantine'));
    }

    public function test_worker_timing_cannot_cause_the_same_job_to_run_twice(): void
    {
        $job = new ProcessImport(1);
        $retryAfter = (int) config('queue.connections.database.retry_after');
        $maxTime = (int) config('library.worker.max_time');

        // slice budget (20 s) < job timeout < retry_after: a running job is never handed out again.
        $this->assertLessThan($job->timeout, 20 + 1);
        $this->assertLessThan($retryAfter, $job->timeout);
        $this->assertSame($job->timeout, (int) config('library.worker.job_timeout'));
        $this->assertLessThan($retryAfter, $maxTime, 'A worker run must end before a reserved job could be released.');
        $this->assertTrue($job->failOnTimeout);
        $this->assertSame(3, $job->maxExceptions);
        $this->assertSame([60, 300], $job->backoff);
        $this->assertNotEmpty($job->middleware(), 'Overlap protection must be attached.');

        // The scheduler entry is bounded and cannot overlap itself.
        $event = collect(Schedule::events())->first(fn ($e) => str_contains($e->command ?? '', 'queue:work'));
        $this->assertNotNull($event);
        $this->assertStringContainsString('--stop-when-empty', $event->command);
        $this->assertStringContainsString('--max-time='.$maxTime, $event->command);
        $this->assertTrue($event->withoutOverlapping);
    }

    public function test_large_books_are_processed_in_resumable_slices(): void
    {
        $chapters = [];
        for ($i = 1; $i <= 12; $i++) {
            $chapters[] = ['title' => "Chapter $i", 'html' => "<p>Text of chapter $i.</p>"];
        }
        $upload = $this->epubUpload(['chapters' => $chapters]);
        $processor = new EpubProcessor;
        $package = $processor->inspect($upload->getRealPath());

        $target = Storage::disk('library')->path('tmp/slices');
        @mkdir($target, 0755, true);
        $state = ['files' => [], 'notes' => []];

        // A deadline that has already passed forces the smallest possible slice each time.
        $cursor = 0;
        $runs = 0;
        do {
            $cursor = $processor->extract($upload->getRealPath(), $target, $package, $cursor, microtime(true) - 1, $state);
            $runs++;
            $this->assertLessThan(100, $runs, 'Extraction is not making progress.');
        } while ($cursor !== null);

        $this->assertGreaterThan(5, $runs, 'The work should have been split over several runs.');
        $written = array_column($state['files'], 'path');
        $this->assertSame(count($written), count(array_unique($written)), 'No resource may be written twice.');
        foreach (range(1, 12) as $i) {
            $this->assertContains("OEBPS/chapter-$i.xhtml", $written);
        }
    }

    public function test_unexpected_failures_are_retried_then_reported_and_cleaned_up(): void
    {
        config(['queue.default' => 'database']);
        $edition = $this->draftEdition();
        $job = $this->import($edition, $this->epubUpload());

        // Simulate an infrastructure fault: the package inspection blows up with a non-validation error.
        $this->app->bind(EpubProcessor::class, fn () => new class extends EpubProcessor
        {
            public function inspect(string $path): array
            {
                throw new \RuntimeException('disk hiccup');
            }
        });
        $this->app->forgetInstance(ImportPipeline::class);

        // First run: the job fails once and is put back with a delay (not lost, not marked failed).
        Artisan::call('queue:work', ['--stop-when-empty' => true, '--max-time' => 20, '--sleep' => 0]);
        $this->assertSame(1, DB::table('jobs')->count());
        $this->assertSame(0, DB::table('failed_jobs')->count());
        $this->assertNotSame('failed', $job->fresh()->status);
        $this->assertGreaterThanOrEqual(now()->addSeconds(50)->getTimestamp(), DB::table('jobs')->value('available_at'), 'Retry must be delayed (backoff).');

        // Later runs, after the backoff: the exception limit is reached.
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $this->travel(10)->minutes();
            Artisan::call('queue:work', ['--stop-when-empty' => true, '--max-time' => 20, '--sleep' => 0]);
        }

        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame(1, DB::table('failed_jobs')->count());
        $failed = $job->fresh();
        $this->assertSame('failed', $failed->status);
        $this->assertStringContainsString('disk hiccup', $failed->error);
        $this->assertSame('failed', $failed->file->import_status);
        $this->assertSame('draft', $edition->fresh()->status);
        $this->assertSame([], Storage::disk('library')->allFiles('quarantine'));

        // It shows up for staff.
        $this->actingAs($this->staff('editor'))->get('/admin')->assertOk()->assertSee('disk hiccup')->assertSee('failed queue jobs');
    }

    public function test_stale_imports_and_temporary_files_are_cleaned_up(): void
    {
        config(['queue.default' => 'database']);
        $edition = $this->draftEdition();
        $stuck = $this->import($edition, $this->epubUpload());
        Storage::disk('library')->put('quarantine/orphan.upload', 'left behind');
        Storage::disk('library')->put('tmp/import-999999/OEBPS/x.xhtml', 'left behind');

        // Fresh items are left alone.
        Artisan::call('library:cleanup-imports');
        $this->assertSame('queued', $stuck->fresh()->status);
        $this->assertTrue(Storage::disk('library')->exists('quarantine/orphan.upload'));

        $this->travel(config('library.imports.stale_hours') + 1)->hours();
        touch(Storage::disk('library')->path('quarantine/orphan.upload'), now()->subDays(3)->getTimestamp());
        ImportJob::query()->update(['updated_at' => now()->subDays(3)]);

        Artisan::call('library:cleanup-imports');

        $this->assertSame('failed', $stuck->fresh()->status);
        $this->assertSame('draft', $edition->fresh()->status);
        $this->assertFalse(Storage::disk('library')->exists('quarantine/orphan.upload'));
        $this->assertSame([], Storage::disk('library')->allFiles('tmp'));
        $this->assertSame([], Storage::disk('library')->allFiles('quarantine'));
    }
}
