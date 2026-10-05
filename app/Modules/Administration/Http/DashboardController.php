<?php

namespace App\Modules\Administration\Http;

use App\Modules\Accounts\Models\RightsReport;
use App\Modules\Catalog\Models\Edition;
use App\Modules\Catalog\Models\EditionFile;
use App\Modules\Imports\Models\ImportJob;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class DashboardController
{
    public function __invoke(): View
    {
        // Published files whose stored original has gone missing.
        $broken = EditionFile::query()->where('is_current', true)->where('import_status', 'ready')
            ->whereHas('edition', fn ($q) => $q->where('status', 'published'))->with('edition')->limit(500)->get()
            ->reject(fn (EditionFile $f) => Storage::disk($f->storage_disk)->exists($f->storage_path));

        return view('admin.dashboard', [
            'counts' => Edition::query()->select('status', DB::raw('count(*) as total'))->groupBy('status')->pluck('total', 'status'),
            'failedImports' => ImportJob::query()->where('status', 'failed')->with('edition')->latest()->limit(15)->get(),
            'activeImports' => ImportJob::query()->whereIn('status', ['queued', 'running'])->with('edition')->latest()->limit(15)->get(),
            'reviewQueue' => Edition::query()->where('status', 'review')->with('work')->latest('updated_at')->limit(20)->get(),
            'openReports' => RightsReport::query()->where('status', 'open')->count(),
            'failedQueueJobs' => DB::table('failed_jobs')->count(),
            'brokenFiles' => $broken,
        ]);
    }
}
