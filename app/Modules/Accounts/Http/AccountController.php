<?php

namespace App\Modules\Accounts\Http;

use App\Modules\Imports\Models\AuditEvent;
use App\Modules\Reader\Models\Annotation;
use App\Modules\Reader\Models\Bookmark;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class AccountController
{
    public function show(Request $request): View
    {
        $user = $request->user();

        return view('account.show', [
            'user' => $user,
            'favorites' => $user->favorites()->whereHas('publishedEditions')->with(['translations', 'authors.translations'])->orderBy('original_title')->get(),
            'lists' => $user->readingLists()->with(['editions' => fn ($q) => $q->published(), 'editions.work'])->orderBy('name')->get(),
            'progress' => $user->readingProgress()->with(['edition', 'file'])->latest('updated_at')->limit(30)->get(),
            // Notes and bookmarks made on a file version that has since been replaced.
            'staleCount' => Bookmark::query()->where('user_id', $user->id)->whereHas('file', fn ($q) => $q->where('is_current', false))->count()
                + Annotation::query()->where('user_id', $user->id)->whereHas('file', fn ($q) => $q->where('is_current', false))->count(),
        ]);
    }

    public function security(Request $request): View
    {
        return view('account.security', ['user' => $request->user()]);
    }

    /** Everything stored about the account, as one JSON download. */
    public function export(Request $request): JsonResponse
    {
        $user = $request->user();

        $data = [
            'exported_at' => now()->toIso8601String(),
            'account' => $user->only(['name', 'email', 'locale', 'created_at', 'email_verified_at', 'reader_preferences']),
            'favorites' => $user->favorites()->get(['works.slug', 'works.original_title'])->map->only(['slug', 'original_title'])->all(),
            'reading_lists' => $user->readingLists()->with('editions:id,slug,title')->get()
                ->map(fn ($list) => ['name' => $list->name, 'editions' => $list->editions->map->only(['slug', 'title'])->all()])->all(),
            'reading_progress' => $user->readingProgress()->with('edition:id,slug,title')->get()
                ->map(fn ($p) => [
                    'edition' => $p->edition?->slug, 'title' => $p->edition?->title, 'file_id' => $p->edition_file_id,
                    'locator_type' => $p->locator_type, 'locator' => $p->locator, 'fraction' => $p->fraction,
                    'label' => $p->label, 'updated_at' => $p->client_updated_at?->toIso8601String(),
                ])->all(),
            'bookmarks' => $user->bookmarks()->with('edition:id,slug,title')->get()
                ->map(fn ($b) => [
                    'edition' => $b->edition?->slug, 'file_id' => $b->edition_file_id, 'locator_type' => $b->locator_type,
                    'locator' => $b->locator, 'label' => $b->label, 'created_at' => $b->created_at?->toIso8601String(),
                ])->all(),
            'annotations' => $user->annotations()->with('edition:id,slug,title')->get()
                ->map(fn ($a) => [
                    'edition' => $a->edition?->slug, 'file_id' => $a->edition_file_id, 'type' => $a->type,
                    'locator_type' => $a->locator_type, 'locator' => $a->locator, 'quote' => $a->quote, 'note' => $a->note,
                    'color' => $a->color, 'conflict_copy_of' => $a->conflict_of, 'created_at' => $a->created_at?->toIso8601String(),
                ])->all(),
        ];

        return response()->json($data, 200, [
            'Content-Disposition' => 'attachment; filename="library-account-export.json"',
            'Cache-Control' => 'private, no-store',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function destroy(Request $request): RedirectResponse
    {
        $request->validate(['password' => ['required', 'current_password']]);
        $user = $request->user();

        // The last administrator cannot remove the only way into /admin.
        if ($user->isAdmin() && $user->newQuery()->where('role', 'admin')->count() <= 1) {
            return back()->withErrors(['password' => __('ui.account.last_admin')]);
        }

        AuditEvent::record('account.deleted', null, ['role' => $user->role]);

        // Sign out first: signing out afterwards would save the user model
        // again (remember-token rotation) and recreate the row just deleted.
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        DB::transaction(function () use ($user) {
            // Soft-deleted bookmarks and notes are removed for real as well.
            Bookmark::withTrashed()->where('user_id', $user->id)->forceDelete();
            Annotation::withTrashed()->where('user_id', $user->id)->forceDelete();
            DB::table('sessions')->where('user_id', $user->id)->delete();
            $user->newQuery()->whereKey($user->id)->delete(); // remaining personal rows cascade
        });

        return redirect()->route('home')->with('status', __('ui.account.deleted'))->with('clear_account_storage', $user->id);
    }
}
