<?php

namespace Database\Seeders;

use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Collection;
use App\Modules\Catalog\Models\Work;
use Database\Seeders\Support\DemoBookBuilder;
use Database\Seeders\Support\SefariaSeeder;
use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * The Tanakh in Hebrew under a "תנ״ך" category: Torah and Nevi'im, 26 books.
 * Ketuvim is described below too and is added by naming it in IMPORT.
 * Everything visitors see for these books is in Hebrew.
 *
 * The text is downloaded from Sefaria when the seeder runs (so it needs network
 * access): the public-domain "Tanach with Nikkud" version, which Sefaria takes
 * from tanach.us. Each book becomes one EPUB with a section per chapter and goes
 * through the real import pipeline, exactly like an upload. Safe to run again:
 * a book that is already there keeps its file and only has its catalog details
 * brought up to date.
 *
 *     php artisan db:seed --class=TanakhSeeder
 */
class TanakhSeeder extends SefariaSeeder
{
    private const VERSION = 'Tanach with Nikkud';

    private const VERSION_HEBREW = 'תנ״ך עם ניקוד';

    /** The sections to put in the catalog, from SECTIONS. */
    private const IMPORT = ['torah', 'neviim'];

    /**
     * Section slug => Hebrew name, wording, and its books in canonical order as
     * Sefaria title => [slug, Hebrew title, chapters]. The chapter counts guard
     * against a cut-off download.
     */
    private const SECTIONS = [
        'torah' => [
            'name' => 'תורה', 'part' => 'מחמישה חומשי תורה', 'description' => 'חמישה חומשי תורה בעברית, בנוסח מנוקד.',
            'books' => [
                'Genesis' => ['bereshit', 'בראשית', 50], 'Exodus' => ['shemot', 'שמות', 40], 'Leviticus' => ['vayikra', 'ויקרא', 27],
                'Numbers' => ['bamidbar', 'במדבר', 36], 'Deuteronomy' => ['devarim', 'דברים', 34],
            ],
        ],
        'neviim' => [
            'name' => 'נביאים', 'part' => 'מספרי הנביאים', 'description' => 'ספרי הנביאים בעברית, בנוסח מנוקד.',
            'books' => [
                'Joshua' => ['yehoshua', 'יהושע', 24], 'Judges' => ['shoftim', 'שופטים', 21],
                'I Samuel' => ['shmuel-1', 'שמואל א', 31], 'II Samuel' => ['shmuel-2', 'שמואל ב', 24],
                'I Kings' => ['melakhim-1', 'מלכים א', 22], 'II Kings' => ['melakhim-2', 'מלכים ב', 25],
                'Isaiah' => ['yeshayahu', 'ישעיהו', 66], 'Jeremiah' => ['yirmeyahu', 'ירמיהו', 52], 'Ezekiel' => ['yechezkel', 'יחזקאל', 48],
                'Hosea' => ['hoshea', 'הושע', 14], 'Joel' => ['yoel', 'יואל', 4], 'Amos' => ['amos', 'עמוס', 9],
                'Obadiah' => ['ovadyah', 'עובדיה', 1], 'Jonah' => ['yonah', 'יונה', 4], 'Micah' => ['mikhah', 'מיכה', 7],
                'Nahum' => ['nachum', 'נחום', 3], 'Habakkuk' => ['chavakuk', 'חבקוק', 3], 'Zephaniah' => ['tzefanyah', 'צפניה', 3],
                'Haggai' => ['chaggai', 'חגי', 2], 'Zechariah' => ['zekharyah', 'זכריה', 14], 'Malachi' => ['malakhi', 'מלאכי', 3],
            ],
        ],
        'ketuvim' => [
            'name' => 'כתובים', 'part' => 'מספרי הכתובים', 'description' => 'ספרי הכתובים בעברית, בנוסח מנוקד.',
            'books' => [
                'Psalms' => ['tehillim', 'תהילים', 150], 'Proverbs' => ['mishlei', 'משלי', 31], 'Job' => ['iyov', 'איוב', 42],
                'Song of Songs' => ['shir-hashirim', 'שיר השירים', 8], 'Ruth' => ['rut', 'רות', 4], 'Lamentations' => ['eikhah', 'איכה', 5],
                'Ecclesiastes' => ['kohelet', 'קהלת', 12], 'Esther' => ['ester', 'אסתר', 10], 'Daniel' => ['daniel', 'דניאל', 12],
                'Ezra' => ['ezra', 'עזרא', 10], 'Nehemiah' => ['nechemyah', 'נחמיה', 13],
                'I Chronicles' => ['divrei-hayamim-1', 'דברי הימים א', 29], 'II Chronicles' => ['divrei-hayamim-2', 'דברי הימים ב', 36],
            ],
        ],
    ];

    /** Translations entered by an earlier version of this seeder; the Hebrew name now stands alone. */
    private const NO_TRANSLATIONS = ['en' => ['name' => '', 'title' => '', 'description' => ''], 'ru' => ['name' => '', 'title' => '', 'description' => ''], 'he' => ['name' => '', 'description' => '']];

    public function run(): void
    {
        $this->call(DatabaseSeeder::class);

        // Imports run inline here instead of waiting for the queue.
        config(['queue.default' => 'sync']);
        $directory = storage_path('app/tanakh-build');
        File::ensureDirectoryExists($directory);

        $tanakh = Category::updateOrCreate(['slug' => 'tanakh'], ['name' => 'תנ״ך', 'position' => 10]);
        $tanakh->syncTranslations(self::NO_TRANSLATIONS);

        $added = 0;
        foreach (self::IMPORT as $order => $sectionSlug) {
            $section = self::SECTIONS[$sectionSlug];

            $category = Category::updateOrCreate(['slug' => $sectionSlug], ['name' => $section['name'], 'parent_id' => $tanakh->id, 'position' => 11 + $order]);
            $category->syncTranslations(self::NO_TRANSLATIONS);

            // Category pages sort by title; the collection keeps the books in their canonical order.
            $collection = Collection::updateOrCreate(['slug' => $sectionSlug], [
                'name' => $section['name'], 'description' => $section['description'], 'is_featured' => true, 'is_published' => true,
            ]);
            $collection->syncTranslations(self::NO_TRANSLATIONS);

            foreach (array_keys($section['books']) as $position => $title) {
                [$slug, $hebrew, $chapterCount] = $section['books'][$title];

                $work = Work::updateOrCreate(['slug' => $slug], [
                    'original_title' => $hebrew, 'original_language_tag' => 'he', 'summary' => 'ספר '.$hebrew.' — '.$section['part'].'.',
                ]);
                $work->syncTranslations(self::NO_TRANSLATIONS);
                $work->categories()->sync([$tanakh->id, $category->id]);
                $collection->works()->syncWithoutDetaching([$work->id => ['position' => $position]]);

                $details = [
                    'language_tag' => 'he', 'direction' => 'rtl', 'page_progression' => 'rtl',
                    'title' => $hebrew, 'subtitle' => 'נוסח מנוקד',
                    'description' => 'ספר '.$hebrew.' בעברית, בנוסח מנוקד, פרק אחר פרק.',
                    'rights_status' => 'public_domain',
                    'license_name' => 'נחלת הכלל',
                    'source_name' => 'ספריא — '.self::VERSION_HEBREW,
                    'source_url' => 'https://www.sefaria.org.il/'.str_replace(' ', '_', $title).'?lang=he&vhe=hebrew%7CTanach_with_Nikkud',
                    'attribution' => 'הטקסט העברי מאתר ספריא (sefaria.org.il), מהדורת „'.self::VERSION_HEBREW.'“, שמקורה באתר tanach.us. נחלת הכלל.',
                ];

                if ($edition = $work->editions()->where('slug', $slug.'-he')->first()) {
                    $edition->update($details);
                    $this->writer->reindex($edition);

                    continue;
                }

                $chapters = $this->download($title, $chapterCount);
                $edition = $work->editions()->create($details + ['slug' => $slug.'-he', 'status' => 'draft']);
                $path = DemoBookBuilder::epub($directory.'/'.$edition->slug.'.epub', [
                    'title' => $hebrew,
                    'language' => 'he',
                    'direction' => 'rtl',
                    'identifier' => 'urn:orl-sefaria:'.str_replace(' ', '-', strtolower($title)).':he:tanach-with-nikkud',
                    'description' => $edition->description,
                    'chapters' => $chapters,
                ]);
                $this->publish($edition, $path);

                $verses = array_sum(array_map(fn (array $chapter) => substr_count($chapter['html'], '<p>'), $chapters));
                $this->command?->info("{$title}: ".count($chapters)." chapters, {$verses} verses.");
                $added++;

                // One request per book, unhurried: this is someone else's server.
                usleep(400_000);
            }
        }

        File::deleteDirectory($directory);
        $this->command?->info("Tanakh: {$added} book(s) added.");
    }

    /**
     * One chapter per section, one paragraph per verse, numbered with Hebrew letters.
     *
     * @return list<array{title: string, html: string}>
     */
    private function download(string $title, int $expectedChapters): array
    {
        $text = $this->version($title, self::VERSION, 'Public Domain')['text'] ?? [];
        if (count($text) !== $expectedChapters) {
            throw new RuntimeException("{$title}: expected {$expectedChapters} chapters, received ".count($text).'.');
        }

        $chapters = [];
        foreach ($text as $index => $verses) {
            $html = '';
            foreach ($verses as $number => $verse) {
                $clean = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags((string) $verse), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
                if ($clean === '') {
                    throw new RuntimeException("{$title} ".($index + 1).':'.($number + 1).' is empty.');
                }
                $html .= '<p><b>'.self::hebrewNumeral($number + 1).'</b> '.htmlspecialchars($clean, ENT_QUOTES | ENT_XML1, 'UTF-8').'</p>';
            }
            $chapters[] = ['title' => 'פרק '.self::hebrewNumeral($index + 1), 'html' => $html];
        }

        return $chapters;
    }
}
