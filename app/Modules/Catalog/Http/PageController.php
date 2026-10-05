<?php

namespace App\Modules\Catalog\Http;

use App\Modules\Accounts\Models\RightsReport;
use App\Modules\Catalog\Models\Edition;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class PageController
{
    public const HELP_TOPICS = ['reading', 'downloads', 'kindle', 'offline'];

    public function help(string $topic): View
    {
        return view('public.pages.help', ['topic' => $topic]);
    }

    public function page(Request $request): View
    {
        return view('public.pages.text', ['page' => $request->route()->defaults['page']]);
    }

    public function contact(Request $request): View
    {
        $kind = $request->route()->defaults['kind'];
        $edition = $request->filled('edition')
            ? Edition::query()->published()->where('slug', $request->string('edition'))->first()
            : null;

        return view('public.pages.contact', compact('kind', 'edition'));
    }

    /**
     * Stores a contact message or rights report for staff. Nothing is emailed
     * here, so this works (and is honest) whether or not mail is configured.
     */
    public function storeReport(Request $request): RedirectResponse
    {
        // Honeypot: real visitors never see or fill this field.
        if ($request->filled('website')) {
            return back()->with('status', __('ui.contact.sent'));
        }

        $data = $request->validate([
            'kind' => ['required', 'in:contact,rights,accessibility'],
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255'],
            'message' => ['required', 'string', 'min:10', 'max:5000'],
            'edition' => ['nullable', 'string', 'max:190'],
        ]);

        RightsReport::create([
            'kind' => $data['kind'],
            'name' => $data['name'],
            'email' => $data['email'],
            'message' => $data['message'],
            'edition_id' => isset($data['edition'])
                ? Edition::query()->published()->where('slug', $data['edition'])->value('id')
                : null,
        ]);

        return back()->with('status', __('ui.contact.sent'));
    }
}
