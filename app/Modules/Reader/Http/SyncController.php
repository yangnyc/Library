<?php

namespace App\Modules\Reader\Http;

use App\Modules\Reader\Services\SyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SyncController
{
    public function __invoke(Request $request, SyncService $sync): JsonResponse
    {
        $locator = ['required', 'string', 'max:2000'];
        $locatorType = ['required', 'in:cfi,page,anchor'];

        $payload = $request->validate([
            'deviceId' => ['nullable', 'string', 'max:64'],
            'fileIds' => ['array', 'max:50'],
            'fileIds.*' => ['integer'],

            'progress' => ['array', 'max:50'],
            'progress.*.fileId' => ['required', 'integer'],
            'progress.*.locatorType' => $locatorType,
            'progress.*.locator' => $locator,
            'progress.*.fraction' => ['nullable', 'numeric', 'between:0,1'],
            'progress.*.label' => ['nullable', 'string', 'max:500'],
            'progress.*.baseRevision' => ['nullable', 'integer', 'min:0'],
            'progress.*.updatedAt' => ['nullable', 'date'],

            'bookmarks' => ['array', 'max:200'],
            'bookmarks.*.uuid' => ['required', 'uuid'],
            'bookmarks.*.fileId' => ['required', 'integer'],
            'bookmarks.*.locatorType' => $locatorType,
            'bookmarks.*.locator' => $locator,
            'bookmarks.*.label' => ['nullable', 'string', 'max:500'],
            'bookmarks.*.baseRevision' => ['nullable', 'integer', 'min:0'],
            'bookmarks.*.updatedAt' => ['nullable', 'date'],
            'bookmarks.*.deleted' => ['nullable', 'boolean'],

            'annotations' => ['array', 'max:200'],
            'annotations.*.uuid' => ['required', 'uuid'],
            'annotations.*.fileId' => ['required', 'integer'],
            'annotations.*.type' => ['nullable', 'in:highlight,note'],
            'annotations.*.locatorType' => $locatorType,
            'annotations.*.locator' => $locator,
            'annotations.*.quote' => ['nullable', 'string', 'max:4000'],
            'annotations.*.note' => ['nullable', 'string', 'max:10000'],
            'annotations.*.color' => ['nullable', 'in:yellow,green,blue,pink'],
            'annotations.*.baseRevision' => ['nullable', 'integer', 'min:0'],
            'annotations.*.updatedAt' => ['nullable', 'date'],
            'annotations.*.deleted' => ['nullable', 'boolean'],

            'preferences' => ['nullable', 'array'],
            'preferences.value' => ['nullable', 'array', 'max:30'],
            'preferences.value.*' => ['nullable', 'max:100'],
        ]);

        // Every query in the service is scoped to this user's id; a uuid or
        // file id belonging to someone else can never be read or changed.
        return response()->json($sync->sync($request->user(), $payload))
            ->header('Cache-Control', 'private, no-store');
    }
}
