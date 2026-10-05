<?php

use App\Models\User;
use App\Modules\Catalog\Services\SearchIndexer;
use App\Modules\Imports\Services\ImportPipeline;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

/*
|--------------------------------------------------------------------------
| Scheduled work
|--------------------------------------------------------------------------
| One cron entry runs `php artisan schedule:run`. There is no long-running
| worker: each run drains the queue for a bounded time and exits.
|
|   import slice budget 20 s < job timeout 40 s < --max-time 50 s < retry_after 90 s
|
| withoutOverlapping() stops a second worker starting while one is running,
| whatever interval the host's cron actually allows.
*/

Schedule::command('queue:work', [
    '--stop-when-empty',
    '--max-time='.config('library.worker.max_time'),
    '--timeout='.config('library.worker.job_timeout'),
    '--sleep=1',
    '--memory=192',
])->everyMinute()->withoutOverlapping(5)->name('library-queue');

Schedule::command('library:cleanup-imports')->hourly()->withoutOverlapping();
Schedule::command('queue:prune-failed', ['--hours=336'])->daily();
Schedule::command('auth:clear-resets')->daily();

Artisan::command('library:cleanup-imports', function (ImportPipeline $pipeline) {
    $result = $pipeline->cleanup();
    $this->info("Stale imports failed: {$result['failed']}; temporary items removed: {$result['removed']}.");
})->purpose('Fail imports that never finished and remove their temporary files');

Artisan::command('library:reindex', function (SearchIndexer $indexer) {
    $this->info('Editions indexed: '.$indexer->indexAll());
})->purpose('Rebuild the catalog search text for every edition');

Artisan::command('library:create-admin {email} {--name=Administrator} {--role=admin}', function () {
    $role = $this->option('role');
    if (! in_array($role, ['admin', 'editor'], true)) {
        $this->error('Role must be admin or editor.');

        return 1;
    }

    // The password is asked for interactively so it never appears in shell
    // history or the process list. LIBRARY_ADMIN_PASSWORD is the fallback for
    // hosts where only non-interactive cron execution is available.
    $password = env('LIBRARY_ADMIN_PASSWORD') ?: $this->secret('Password (input hidden)');
    $validator = Validator::make(
        ['email' => $this->argument('email'), 'password' => $password],
        ['email' => ['required', 'email', 'max:255'], 'password' => ['required', Password::defaults()]]
    );
    if ($validator->fails()) {
        foreach ($validator->errors()->all() as $message) {
            $this->error($message);
        }

        return 1;
    }

    $user = User::query()->firstOrNew(['email' => strtolower($this->argument('email'))]);
    $user->forceFill([
        'name' => $user->name ?: $this->option('name'),
        'password' => $password,
        'role' => $role,
        'email_verified_at' => $user->email_verified_at ?? now(),
    ])->save();

    $this->info("User {$user->email} now has the {$role} role.");
    if ($role === 'admin' && config('library.admin_require_2fa')) {
        $this->line('They will be asked to set up two-factor authentication at first sign-in.');
    }

    return 0;
})->purpose('Create or promote a staff account');
