<?php

namespace App\Modules\Administration\Http;

use App\Modules\Imports\Models\AuditEvent;
use App\Modules\Localization\Models\Language;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Book and metadata languages. Adding one is a row here, not a deployment. */
class LanguageAdminController
{
    public function index(): View
    {
        return view('admin.languages', ['languages' => Language::query()->orderBy('position')->orderBy('english_name')->get()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            // BCP 47: language[-Script][-REGION], e.g. he, sr-Latn, pt-BR.
            'tag' => ['required', 'regex:/^[a-z]{2,3}(-[A-Z][a-z]{3})?(-([A-Z]{2}|[0-9]{3}))?$/', 'max:35', 'unique:languages,tag'],
            'native_name' => ['required', 'string', 'max:100'],
            'english_name' => ['required', 'string', 'max:100'],
            'direction' => ['required', 'in:ltr,rtl'],
        ]);

        $language = Language::create($data + ['position' => (int) Language::max('position') + 1]);
        AuditEvent::record('language.created', null, ['tag' => $language->tag]);

        return back()->with('status', 'Language added.');
    }

    public function update(Request $request, int $language): RedirectResponse
    {
        $language = Language::findOrFail($language);
        $data = $request->validate([
            'native_name' => ['required', 'string', 'max:100'],
            'english_name' => ['required', 'string', 'max:100'],
            'direction' => ['required', Rule::in(['ltr', 'rtl'])],
            'position' => ['nullable', 'integer', 'min:0', 'max:65000'],
            'is_active' => ['nullable', 'boolean'],
        ]);
        $language->update(['is_active' => (bool) ($data['is_active'] ?? false), 'position' => (int) ($data['position'] ?? 0)] + $data);

        return back()->with('status', 'Language saved.');
    }
}
