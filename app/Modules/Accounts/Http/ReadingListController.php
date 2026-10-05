<?php

namespace App\Modules\Accounts\Http;

use App\Modules\Accounts\Models\ReadingList;
use App\Modules\Catalog\Models\Edition;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ReadingListController
{
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:120']]);
        abort_if($request->user()->readingLists()->count() >= 100, 422);

        $request->user()->readingLists()->create($data);

        return back();
    }

    public function destroy(Request $request, int $list): RedirectResponse
    {
        $this->owned($request, $list)->delete();

        return back();
    }

    public function addItem(Request $request, int $list): RedirectResponse
    {
        $data = $request->validate(['edition' => ['required', 'string', 'max:190']]);
        $edition = Edition::query()->published()->where('slug', $data['edition'])->firstOrFail();

        $readingList = $this->owned($request, $list);
        $readingList->editions()->syncWithoutDetaching([
            $edition->id => ['position' => $readingList->editions()->count()],
        ]);

        return back()->with('status', __('ui.account.added_to_list'));
    }

    public function removeItem(Request $request, int $list, Edition $edition): RedirectResponse
    {
        $this->owned($request, $list)->editions()->detach($edition->id);

        return back();
    }

    /** Lists are looked up through the signed-in user, so another user's id is a 404. */
    private function owned(Request $request, int $list): ReadingList
    {
        return $request->user()->readingLists()->whereKey($list)->firstOrFail();
    }
}
