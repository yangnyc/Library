<?php

namespace App\Modules\Administration\Http;

use App\Modules\Accounts\Models\RightsReport;
use App\Modules\Imports\Models\AuditEvent;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ReportAdminController
{
    public function index(Request $request): View
    {
        $status = $request->query('status') === 'resolved' ? 'resolved' : 'open';

        return view('admin.reports', [
            'reports' => RightsReport::query()->where('status', $status)->with('edition')->latest()->paginate(30)->withQueryString(),
            'status' => $status,
        ]);
    }

    public function update(Request $request, int $report): RedirectResponse
    {
        $report = RightsReport::findOrFail($report);
        $data = $request->validate([
            'status' => ['required', 'in:open,resolved'],
            'resolution' => ['nullable', 'string', 'max:5000'],
        ]);
        $report->update($data);
        AuditEvent::record('report.'.$data['status'], null, ['report' => $report->id, 'kind' => $report->kind]);

        return back()->with('status', 'Report updated.');
    }
}
