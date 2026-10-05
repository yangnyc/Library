<?php

namespace App\Modules\Catalog\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/** One stored file of an edition. Replacing a file creates a new version row. */
class EditionFile extends Model
{
    public const FORMATS = [
        'epub' => 'application/epub+zip',
        'pdf' => 'application/pdf',
        'txt' => 'text/plain',
        'html' => 'text/html',
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'is_current' => 'boolean',
            'can_read' => 'boolean',
            'can_download' => 'boolean',
            'can_offline' => 'boolean',
            'has_text_layer' => 'boolean',
            'validation_report' => 'array',
            'manifest' => 'array',
        ];
    }

    public function edition(): BelongsTo
    {
        return $this->belongsTo(Edition::class);
    }

    public function isReady(): bool
    {
        return $this->is_current && $this->import_status === 'ready';
    }

    public function isBrowserReadable(): bool
    {
        return match ($this->format) {
            'epub' => $this->reading_path !== null,
            'pdf' => true,
            default => false,
        };
    }

    /** Public = published edition + current + imported. Callers add the per-action permission. */
    public function isPubliclyAvailable(): bool
    {
        return $this->isReady() && $this->edition?->isPublished();
    }

    public function publiclyReadable(): bool
    {
        return $this->isPubliclyAvailable() && $this->can_read && $this->isBrowserReadable();
    }

    public function publiclyDownloadable(): bool
    {
        return $this->isPubliclyAvailable() && $this->can_download;
    }

    public function publiclyOfflineable(): bool
    {
        return $this->publiclyReadable() && $this->can_offline;
    }

    /**
     * Identifies the exact content a reading position belongs to. A replaced
     * file gets a new row and therefore a new key, so old locators are never
     * silently applied to different content.
     */
    public function contentKey(): string
    {
        return $this->id.'-v'.$this->version.'-'.substr($this->sha256, 0, 12);
    }

    public function downloadFilename(): string
    {
        $title = $this->edition->title;
        $name = preg_replace('/[\x00-\x1F\x7F\/\\\\:*?"<>|]+/u', ' ', $title);
        $name = trim(preg_replace('/\s+/u', ' ', $name)) ?: 'book';

        return Str::limit($name, 120, '').'.'.$this->format;
    }

    public function asciiDownloadFilename(): string
    {
        $ascii = Str::slug(Str::ascii($this->edition->title));
        if ($ascii === '') {
            $ascii = 'book-'.$this->edition_id;
        }

        return Str::limit($ascii, 80, '').'.'.$this->format;
    }

    public function humanSize(): string
    {
        $bytes = (int) $this->byte_size;
        if ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 1).' MB';
        }

        return max(1, (int) round($bytes / 1024)).' KB';
    }
}
