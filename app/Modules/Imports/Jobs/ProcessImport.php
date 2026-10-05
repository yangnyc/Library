<?php

namespace App\Modules\Imports\Jobs;

use App\Modules\Imports\ImportRejected;
use App\Modules\Imports\Models\ImportJob;
use App\Modules\Imports\Services\ImportPipeline;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

/**
 * Processes one upload in short, resumable slices.
 *
 * Timing contract (see config/queue.php and DEPLOYMENT_CPANEL.md):
 *   pipeline time budget (20 s) < job timeout (40 s) < queue retry_after (90 s)
 * so a slice that is still running can never be picked up a second time, and
 * WithoutOverlapping guards the same import against concurrent workers.
 */
class ProcessImport implements ShouldQueue
{
    use Queueable;

    public int $timeout = 40;

    public bool $failOnTimeout = true;

    // Resuming a large book is not a failure, so slices are not limited by
    // $tries; real errors are limited by $maxExceptions and the deadline below.
    public int $tries = 0;

    public int $maxExceptions = 3;

    /** @var list<int> */
    public array $backoff = [60, 300];

    public function __construct(public int $importJobId) {}

    public function retryUntil(): DateTimeInterface
    {
        return now()->addHours(6);
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('import-'.$this->importJobId))->releaseAfter(30)->expireAfter(120)];
    }

    public function handle(ImportPipeline $pipeline): void
    {
        $job = ImportJob::find($this->importJobId);
        if (! $job) {
            return;
        }

        try {
            // The sync driver cannot re-queue, so it runs all slices in a row.
            $sync = $this->job?->getConnectionName() === 'sync';
            do {
                $done = $pipeline->process($job->refresh());
            } while (! $done && $sync);

            if (! $done) {
                $this->release(1); // more slices to do
            }
        } catch (ImportRejected $e) {
            // The file is bad; retrying cannot fix it.
            $pipeline->fail($job, $e->getMessage());
        }
    }

    public function failed(?Throwable $exception): void
    {
        $job = ImportJob::find($this->importJobId);
        if ($job && ! in_array($job->status, ['review', 'completed', 'failed'], true)) {
            app(ImportPipeline::class)->fail($job, 'Processing failed after several attempts: '.($exception?->getMessage() ?? 'unknown error'));
        }
    }
}
