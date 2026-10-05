<?php

namespace App\Modules\Downloads;

use App\Modules\Catalog\Models\EditionFile;

/**
 * Decides whether a cookie-less request may fetch reading-copy resources.
 *
 * Public content is addressed by its content key. Staff previews of
 * unpublished content use a short-lived signed token instead, so the content
 * routes never need a session and can live on a credential-free origin.
 */
class ContentAccess
{
    private const PREVIEW_MINUTES = 120;

    public function previewToken(EditionFile $file): string
    {
        $expires = now()->addMinutes(self::PREVIEW_MINUTES)->getTimestamp();

        return 'p'.$expires.'-'.$this->sign($file, $expires);
    }

    /** The path segment a reader should use for this file. */
    public function accessSegment(EditionFile $file, bool $preview): string
    {
        return $preview ? $this->previewToken($file) : $file->contentKey();
    }

    public function allows(EditionFile $file, string $access): bool
    {
        if ($file->import_status !== 'ready' || ! $file->isBrowserReadable()) {
            return false;
        }

        if (preg_match('/^p(\d{10})-([a-f0-9]{64})$/', $access, $match)) {
            return (int) $match[1] >= now()->getTimestamp() && hash_equals($this->sign($file, (int) $match[1]), $match[2]);
        }

        return hash_equals($file->contentKey(), $access) && $file->publiclyReadable();
    }

    public function isPreview(string $access): bool
    {
        return str_starts_with($access, 'p');
    }

    public function baseUrl(EditionFile $file, string $access): string
    {
        $origin = rtrim((string) (config('library.content_origin') ?: ''), '/');

        return $origin.'/content/'.$file->id.'/'.$access.'/';
    }

    private function sign(EditionFile $file, int $expires): string
    {
        return hash_hmac('sha256', 'content-preview|'.$file->id.'|'.$file->sha256.'|'.$expires, (string) config('app.key'));
    }
}
