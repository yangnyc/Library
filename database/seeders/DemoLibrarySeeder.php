<?php

namespace Database\Seeders;

use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Collection;
use App\Modules\Catalog\Models\Edition;
use App\Modules\Catalog\Models\Work;
use App\Modules\Catalog\Services\CatalogWriter;
use App\Modules\Imports\Services\ImportPipeline;
use Database\Seeders\Support\DemoBookBuilder;
use Database\Seeders\Support\DemoTexts;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;

/**
 * A small demonstration library made only of texts written for this project.
 * Every file goes through the real import pipeline, exactly like an upload.
 */
class DemoLibrarySeeder extends Seeder
{
    private const ATTRIBUTION = 'Demonstration text written for the Jewish Library project. Dedicated to the public domain (CC0 1.0).';

    public function __construct(private readonly CatalogWriter $writer, private readonly ImportPipeline $pipeline) {}

    public function run(): void
    {
        $this->call(DatabaseSeeder::class);

        if (Work::query()->where('slug', 'lighthouse-keepers-almanac')->exists()) {
            $this->command?->warn('Demo library already present; nothing changed.');

            return;
        }

        // Imports run inline here instead of waiting for the queue.
        config(['queue.default' => 'sync']);
        $directory = storage_path('app/demo-build');
        File::ensureDirectoryExists($directory);

        $author = $this->writer->contributor('Demo Author');
        $author->syncTranslations(['ru' => ['name' => 'Демо Автор'], 'he' => ['name' => 'מחבר לדוגמה']]);
        $translatorRu = $this->writer->contributor('Demo Translator (Russian)');
        $translatorRu->syncTranslations(['ru' => ['name' => 'Демо Переводчик']]);
        $translatorHe = $this->writer->contributor('Demo Translator (Hebrew)');
        $translatorHe->syncTranslations(['he' => ['name' => 'מתרגם לדוגמה']]);
        $translatorEn = $this->writer->contributor('Demo Translator (English)');

        $fiction = Category::create(['name' => 'Short fiction', 'slug' => 'short-fiction']);
        $fiction->syncTranslations(['ru' => ['name' => 'Малая проза'], 'he' => ['name' => 'סיפורת קצרה']]);
        $poetry = Category::create(['name' => 'Poetry', 'slug' => 'poetry', 'position' => 1]);
        $poetry->syncTranslations(['ru' => ['name' => 'Поэзия'], 'he' => ['name' => 'שירה']]);
        $guides = Category::create(['name' => 'Guides', 'slug' => 'guides', 'position' => 2]);
        $guides->syncTranslations(['ru' => ['name' => 'Руководства'], 'he' => ['name' => 'מדריכים']]);

        $collection = Collection::create([
            'name' => 'Demonstration shelf', 'slug' => 'demonstration-shelf', 'is_featured' => true, 'is_published' => true,
            'description' => 'Short original texts for trying the reader in English, Russian and Hebrew.',
        ]);
        $collection->syncTranslations([
            'ru' => ['name' => 'Демонстрационная полка', 'description' => 'Короткие оригинальные тексты, чтобы опробовать чтение на английском, русском и иврите.'],
            'he' => ['name' => 'מדף הדגמה', 'description' => 'טקסטים מקוריים קצרים להתנסות בקריאה באנגלית, ברוסית ובעברית.'],
        ]);

        /* ---- Work 1: one work, four editions, two of them in English ---- */

        $lighthouse = Work::create([
            'slug' => 'lighthouse-keepers-almanac', 'original_title' => 'The Lighthouse Keeper’s Almanac',
            'original_language_tag' => 'en', 'first_published' => '2026',
            'summary' => 'A lighthouse keeper’s notes on lamps, weather and visitors.',
        ]);
        $lighthouse->syncTranslations(['ru' => ['title' => 'Альманах смотрителя маяка'], 'he' => ['title' => 'אלמנך שומר המגדלור']]);
        $this->writer->syncContributors($lighthouse, 'author', [$author->id]);
        $lighthouse->categories()->sync([$fiction->id]);
        $this->writer->syncTags($lighthouse, ['sea', 'demo']);
        $collection->works()->attach($lighthouse->id, ['position' => 0]);

        $this->edition($lighthouse, $directory, [
            'slug' => 'lighthouse-keepers-almanac-en', 'language_tag' => 'en', 'direction' => 'ltr',
            'title' => 'The Lighthouse Keeper’s Almanac', 'published_date' => '2026',
            'description' => "A lighthouse keeper’s notes on lamps, weather and visitors.\n\nA three-chapter demonstration text with a footnote, an outside link and words in Hebrew and Russian inside English sentences.",
        ], DemoTexts::lighthouseEnglish(), 'Demo Author');

        $this->edition($lighthouse, $directory, [
            'slug' => 'lighthouse-keepers-almanac-en-revised', 'language_tag' => 'en', 'direction' => 'ltr',
            'title' => 'The Lighthouse Keeper’s Almanac', 'subtitle' => 'Revised edition', 'edition_statement' => 'Second, revised edition',
            'published_date' => '2027',
            'description' => 'The same work in a second English edition, to show that a work can have more than one edition in one language.',
        ], DemoTexts::lighthouseEnglish(revised: true), 'Demo Author');

        $this->edition($lighthouse, $directory, [
            'slug' => 'almanakh-smotritelya-mayaka-ru', 'language_tag' => 'ru', 'direction' => 'ltr',
            'title' => 'Альманах смотрителя маяка', 'published_date' => '2026',
            'description' => 'Записки смотрителя маяка о лампах, погоде и гостях. Демонстрационный текст из трёх глав.',
        ], DemoTexts::lighthouseRussian(), 'Демо Автор', $translatorRu->id, 'Демо Переводчик');

        $this->edition($lighthouse, $directory, [
            'slug' => 'almanakh-shomer-hamigdalor-he', 'language_tag' => 'he', 'direction' => 'rtl', 'page_progression' => 'rtl',
            'title' => 'אלמנך שומר המגדלור', 'published_date' => '2026',
            'description' => 'רשימותיו של שומר מגדלור על פנסים, מזג אוויר ואורחים. טקסט הדגמה בשלושה פרקים.',
        ], DemoTexts::lighthouseHebrew(), 'מחבר לדוגמה', $translatorHe->id, 'מתרגם לדוגמה');

        /* ---- Work 2: Hebrew original with vowel points, English translation ---- */

        $morning = Work::create([
            'slug' => 'shurot-shel-boker', 'original_title' => 'שורות של בוקר', 'original_language_tag' => 'he', 'first_published' => '2026',
            'summary' => 'Seven short lines for morning and evening, in pointed Hebrew.',
        ]);
        $morning->syncTranslations(['en' => ['title' => 'Morning Lines'], 'ru' => ['title' => 'Утренние строки']]);
        $this->writer->syncContributors($morning, 'author', [$author->id]);
        $morning->categories()->sync([$poetry->id]);
        $collection->works()->attach($morning->id, ['position' => 1]);

        $this->edition($morning, $directory, [
            'slug' => 'shurot-shel-boker-he', 'language_tag' => 'he', 'direction' => 'rtl', 'page_progression' => 'rtl',
            'title' => 'שורות של בוקר', 'subtitle' => 'מהדורה מנוקדת', 'published_date' => '2026',
            'description' => 'שבע שורות קצרות לבוקר ולערב, בכתיב מנוקד.',
        ], DemoTexts::morningHebrew(), 'מחבר לדוגמה');

        $this->edition($morning, $directory, [
            'slug' => 'morning-lines-en', 'language_tag' => 'en', 'direction' => 'ltr',
            'title' => 'Morning Lines', 'published_date' => '2026',
            'description' => 'Seven short lines for morning and evening, translated from the Hebrew.',
        ], DemoTexts::morningEnglish(), 'Demo Author', $translatorEn->id, 'Demo Translator (English)');

        /* ---- Work 3: PDF only — reading allowed, no EPUB, no offline copy ---- */

        $guide = Work::create([
            'slug' => 'short-guide-to-the-reading-room', 'original_title' => 'A Short Guide to the Reading Room',
            'original_language_tag' => 'en', 'first_published' => '2026',
            'summary' => 'A three-page PDF used to demonstrate the PDF reader.',
        ]);
        $this->writer->syncContributors($guide, 'author', [$author->id]);
        $guide->categories()->sync([$guides->id]);
        $collection->works()->attach($guide->id, ['position' => 2]);

        $pdfEdition = $guide->editions()->create($this->rights() + [
            'slug' => 'short-guide-to-the-reading-room-en', 'language_tag' => 'en', 'direction' => 'ltr',
            'title' => 'A Short Guide to the Reading Room', 'published_date' => '2026', 'status' => 'draft',
            'description' => 'A three-page PDF with a text layer. PDF pages keep their printed layout; there is no EPUB of this edition.',
        ]);
        $pdfPath = DemoBookBuilder::pdf($directory.'/reading-room.pdf', 'A Short Guide to the Reading Room', DemoTexts::readingRoomPdfPages());
        $this->import($pdfEdition, $pdfPath, canOffline: false, hasTextLayer: true);

        File::deleteDirectory($directory);
        $this->command?->info('Demo library created: 3 works, 7 editions.');
    }

    private function edition(Work $work, string $directory, array $attributes, array $chapters, string $authorName, ?int $translatorId = null, ?string $translatorName = null): Edition
    {
        $edition = $work->editions()->create($this->rights() + $attributes + ['status' => 'draft']);
        if ($translatorId) {
            $this->writer->syncContributors($edition, 'translator', [$translatorId]);
        }

        $path = DemoBookBuilder::epub($directory.'/'.$edition->slug.'.epub', array_filter([
            'title' => $edition->title,
            'language' => $edition->language_tag,
            'direction' => $edition->direction,
            'author' => $authorName,
            'translator' => $translatorName,
            'identifier' => 'urn:orl-demo:'.$edition->slug,
            'publisher' => 'Jewish Library (demo)',
            'description' => $edition->description,
            'chapters' => $chapters,
        ]));
        $this->import($edition, $path, canOffline: true);

        return $edition;
    }

    private function import(Edition $edition, string $path, bool $canOffline, ?bool $hasTextLayer = null): void
    {
        $upload = new UploadedFile($path, basename($path), null, null, true);
        $job = $this->pipeline->intake($upload, $edition, null, allowDuplicate: true)->refresh();

        if ($job->status !== 'review') {
            throw new \RuntimeException("Demo import failed for {$edition->slug}: ".($job->error ?? $job->status));
        }

        $file = $job->file;
        $this->pipeline->approve($file);
        $file->update(['can_read' => true, 'can_download' => true, 'can_offline' => $canOffline, 'has_text_layer' => $hasTextLayer]);

        $edition->refresh();
        if ($blockers = $edition->publicationBlockers()) {
            throw new \RuntimeException("Demo edition {$edition->slug} cannot be published: ".implode(' ', $blockers));
        }
        $edition->forceFill(['status' => 'published', 'published_at' => now()])->save();
        $this->writer->reindex($edition);
    }

    private function rights(): array
    {
        return [
            'rights_status' => 'open_license',
            'license_name' => 'CC0 1.0 Universal',
            'license_url' => 'https://creativecommons.org/publicdomain/zero/1.0/',
            'source_name' => 'Written for this project',
            'attribution' => self::ATTRIBUTION,
            'publisher' => 'Jewish Library (demo)',
        ];
    }
}
