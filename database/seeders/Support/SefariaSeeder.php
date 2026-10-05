<?php

namespace Database\Seeders\Support;

use App\Modules\Catalog\Models\Edition;
use App\Modules\Catalog\Services\CatalogWriter;
use App\Modules\Imports\Services\ImportPipeline;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Shared by the seeders that bring a text in from Sefaria: downloading one
 * version of it, and putting the EPUB built from it through the real import
 * pipeline, exactly like an upload. They need network access when they run.
 */
abstract class SefariaSeeder extends Seeder
{
    public function __construct(protected readonly CatalogWriter $writer, protected readonly ImportPipeline $pipeline) {}

    /**
     * One version of a text, as Sefaria publishes it for download.
     *
     * The licence is what allows publishing, so the download is refused if
     * Sefaria ever reports a different one from the licence the seeder was written for.
     */
    protected function version(string $title, string $versionTitle, string $license): array
    {
        $url = 'https://www.sefaria.org/download/version/'.rawurlencode($title.' - he - '.$versionTitle.'.json');
        $version = Http::timeout(120)->retry(3, 3000)->get($url)->throw()->json();

        if (! is_array($version)
            || ($version['title'] ?? null) !== $title
            || ($version['versionTitle'] ?? null) !== $versionTitle
            || ($version['language'] ?? null) !== 'he'
            || ! is_array($version['text'] ?? null)
            || $version['text'] === []) {
            throw new RuntimeException("{$title}: the download is not the expected non-empty Hebrew version {$versionTitle}.");
        }

        if (($version['license'] ?? null) !== $license) {
            throw new RuntimeException("{$title}: expected the licence {$license}, got ".json_encode($version['license'] ?? null).'.');
        }

        return $version;
    }

    /** 1 => א, 15 => טו, 16 => טז, 31 => לא, 150 => קנ. */
    protected static function hebrewNumeral(int $number): string
    {
        $out = '';
        foreach ([400 => 'ת', 300 => 'ש', 200 => 'ר', 100 => 'ק'] as $value => $letter) {
            while ($number >= $value) {
                $out .= $letter;
                $number -= $value;
            }
        }
        if ($number === 15 || $number === 16) {
            return $out.($number === 15 ? 'טו' : 'טז');
        }

        return $out.['', 'י', 'כ', 'ל', 'מ', 'נ', 'ס', 'ע', 'פ', 'צ'][intdiv($number, 10)].['', 'א', 'ב', 'ג', 'ד', 'ה', 'ו', 'ז', 'ח', 'ט'][$number % 10];
    }

    /** Imports the file for a draft edition and publishes it for reading, download and offline use. */
    protected function publish(Edition $edition, string $path): void
    {
        $upload = new UploadedFile($path, basename($path), null, null, true);
        $job = $this->pipeline->intake($upload, $edition, null, allowDuplicate: true)->refresh();

        if ($job->status !== 'review') {
            throw new RuntimeException("Import failed for {$edition->slug}: ".($job->error ?? $job->status));
        }

        $file = $job->file;
        $this->pipeline->approve($file);
        $file->update(['can_read' => true, 'can_download' => true, 'can_offline' => true]);

        $edition->refresh();
        if ($blockers = $edition->publicationBlockers()) {
            throw new RuntimeException("Edition {$edition->slug} cannot be published: ".implode(' ', $blockers));
        }
        $edition->forceFill(['status' => 'published', 'published_at' => now()])->save();
        $this->writer->reindex($edition);
    }
}
