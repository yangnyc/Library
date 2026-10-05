<?php

namespace App\Modules\Administration\Http;

use App\Modules\Imports\Models\AuditEvent;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class AuditController
{
    public function __invoke(Request $request): View
    {
        $events = AuditEvent::query()->with('user')
            ->when($request->string('action')->trim()->value(), fn ($q, $action) => $q->where('action', 'like', addcslashes($action, '%_\\').'%'))
            ->latest('created_at')->latest('id')->paginate(60)->withQueryString();

        return view('admin.audit', compact('events'));
    }
}
