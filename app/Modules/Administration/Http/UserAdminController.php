<?php

namespace App\Modules\Administration\Http;

use App\Models\User;
use App\Modules\Imports\Models\AuditEvent;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UserAdminController
{
    public function index(Request $request): View
    {
        $users = User::query()
            ->when($request->string('q')->trim()->value(), fn ($q, $term) => $q->where('email', 'like', '%'.addcslashes($term, '%_\\').'%'))
            ->orderByRaw("FIELD(role, 'admin', 'editor', 'reader')")->orderBy('email')->paginate(50)->withQueryString();

        return view('admin.users', compact('users'));
    }

    /** Role changes only. Staff never see or set another person's password. */
    public function update(Request $request, int $user): RedirectResponse
    {
        $user = User::findOrFail($user);
        $data = $request->validate(['role' => ['required', Rule::in(User::ROLES)]]);

        if ($user->isAdmin() && $data['role'] !== 'admin' && User::query()->where('role', 'admin')->count() <= 1) {
            return back()->withErrors(['role' => 'There must always be at least one administrator.']);
        }

        $from = $user->role;
        $user->forceFill(['role' => $data['role']])->save();
        AuditEvent::record('user.role_changed', $user, ['from' => $from, 'to' => $data['role']]);

        return back()->with('status', 'Role updated.');
    }
}
