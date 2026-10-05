<?php

namespace Database\Seeders;

use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Collection;
use App\Modules\Catalog\Models\Contributor;
use App\Modules\Catalog\Models\Work;
use Database\Seeders\Support\DemoBookBuilder;
use Database\Seeders\Support\SefariaSeeder;
use DOMDocument;
use DOMElement;
use DOMNode;
use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * The Tanya (Likkutei Amarim) by Rabbi Shneur Zalman of Liadi, in the original
 * Hebrew, under a "תניא" category: once as a single volume, and once as its
 * five parts gathered in the collection "ספר התניא — מהדורה מנוקדת".
 * Everything visitors see for it is in Hebrew.
 *
 * The text is downloaded from Sefaria when the seeder runs (so it needs network
 * access). Sefaria's only Hebrew version is the vocalized Kehot Publication
 * Society edition, © Kehot and offered under CC BY-NC: it may be shared with
 * credit, not used commercially, and every edition is recorded that way. Each
 * book is one EPUB with a section per chapter. Safe to run again: a book that
 * is already there keeps its file and only has its catalog details brought up
 * to date.
 *
 *     php artisan db:seed --class=TanyaSeeder
 */
class TanyaSeeder extends SefariaSeeder
{
    private const VERSION = 'Kehot Publication Society';

    /**
     * The text in reading order: [path into Sefaria's text, Hebrew heading,
     * word that numbers its chapters or null for a single unnumbered section,
     * expected number of chapters]. The counts guard against a cut-off download.
     */
    private const SECTIONS = [
        [['Part I; Likkutei Amarim', 'Title Page'], 'שער', null, 1],
        [['Part I; Likkutei Amarim', 'Approbation'], 'הסכמות', 'הסכמה', 3],
        [['Part I; Likkutei Amarim', "Compiler's Foreword"], 'הקדמת המלקט', null, 1],
        [['Part I; Likkutei Amarim', ''], 'ליקוטי אמרים', 'פרק', 53],
        [['Part II; Shaar HaYichud VehaEmunah', 'Chinukh Katan'], 'חינוך קטן', null, 1],
        [['Part II; Shaar HaYichud VehaEmunah', ''], 'שער היחוד והאמונה', 'פרק', 12],
        [['Part III; Iggeret HaTeshuvah'], 'אגרת התשובה', 'פרק', 12],
        [['Part IV; Iggeret HaKodesh'], 'אגרת הקדש', 'סימן', 32],
        [['Part V; Kuntres Acharon'], 'קונטרס אחרון', 'סימן', 9],
    ];

    /**
     * Slug => [title, subtitle, indexes into SECTIONS, the section the book is
     * named after]. The first is the whole work; the rest are its five parts,
     * in the order of the collection.
     */
    private const BOOKS = [
        'tanya' => ['תניא', 'ליקוטי אמרים — הספר השלם', [0, 1, 2, 3, 4, 5, 6, 7, 8], null],
        'tanya-likkutei-amarim' => ['ליקוטי אמרים', 'ספר התניא, חלק ראשון — ספר של בינונים', [0, 1, 2, 3], 3],
        'tanya-shaar-hayichud-vehaemunah' => ['שער היחוד והאמונה', 'ספר התניא, חלק שני', [4, 5], 5],
        'tanya-iggeret-hateshuvah' => ['אגרת התשובה', 'ספר התניא, חלק שלישי', [6], 6],
        'tanya-iggeret-hakodesh' => ['אגרת הקדש', 'ספר התניא, חלק רביעי', [7], 7],
        'tanya-kuntres-acharon' => ['קונטרס אחרון', 'ספר התניא, חלק חמישי', [8], 8],
    ];

    /** The downloaded text, fetched once and only when a book has to be built. */
    private ?array $text = null;

    public function run(): void
    {
        $this->call(DatabaseSeeder::class);

        // Imports run inline here instead of waiting for the queue.
        config(['queue.default' => 'sync']);
        $directory = storage_path('app/tanya-build');
        File::ensureDirectoryExists($directory);

        $category = Category::updateOrCreate(['slug' => 'tanya'], ['name' => 'תניא', 'position' => 20]);

        $collection = Collection::updateOrCreate(['slug' => 'tanya'], [
            'name' => 'ספר התניא — מהדורה מנוקדת', 'is_featured' => true, 'is_published' => true,
            'description' => 'ספר התניא מאת רבי שניאור זלמן מליאדי, בעל התניא, בחמישה חלקים, בלשון המקור ובניקוד.',
        ]);

        $author = Contributor::updateOrCreate(['slug' => 'shneur-zalman-of-liadi'], [
            'name' => 'רבי שניאור זלמן מליאדי', 'sort_name' => 'שניאור זלמן מליאדי', 'name_language_tag' => 'he',
            'born' => '1745', 'died' => '1812',
            'biography' => 'בעל התניא והשולחן ערוך, אדמו״ר הזקן, מייסד חסידות חב״ד.',
        ]);

        $added = 0;
        foreach (array_keys(self::BOOKS) as $order => $slug) {
            [$title, $subtitle, $sections, $namedAfter] = self::BOOKS[$slug];
            $whole = $namedAfter === null;

            $work = Work::updateOrCreate(['slug' => $slug], [
                'original_title' => $title, 'original_language_tag' => 'he', 'first_published' => '1796',
                'summary' => $whole
                    ? 'ספר התניא — ליקוטי אמרים, ספר היסוד של חסידות חב״ד, מאת בעל התניא.'
                    : $title.' — '.$subtitle.', מאת בעל התניא.',
            ]);
            $work->categories()->sync([$category->id]);
            $this->writer->syncContributors($work, 'author', [$author->id]);
            if (! $whole) {
                $collection->works()->syncWithoutDetaching([$work->id => ['position' => $order - 1]]);
            }

            $details = [
                'language_tag' => 'he', 'direction' => 'rtl', 'page_progression' => 'rtl',
                'title' => $title, 'subtitle' => $subtitle,
                'description' => $whole
                    ? "ספר התניא מאת רבי שניאור זלמן מליאדי, בעל התניא, בלשון המקור ובניקוד.\n\n"
                        .'חמשת חלקי הספר: ליקוטי אמרים (ספר של בינונים), שער היחוד והאמונה, אגרת התשובה, אגרת הקדש וקונטרס אחרון.'
                    : $title.' — '.$subtitle.'. מאת רבי שניאור זלמן מליאדי, בעל התניא, בלשון המקור ובניקוד.',
                'publisher' => 'הוצאת ספרים קה״ת',
                'rights_status' => 'open_license',
                'license_name' => 'CC BY-NC',
                'license_url' => 'https://creativecommons.org/licenses/by-nc/4.0/',
                'rights_holder' => 'Kehot Publication Society',
                'rights_notes' => 'Sefaria lists this version as CC-BY-NC, © Kehot Publication Society. Non-commercial use only.',
                'source_name' => 'ספריא — הוצאת ספרים קה״ת',
                'source_url' => 'https://www.sefaria.org.il/Tanya?lang=he&vhe=hebrew%7CKehot_Publication_Society',
                'attribution' => '© הוצאת ספרים קה״ת (Kehot Publication Society). הטקסט מאתר ספריא (sefaria.org.il), ברישיון CC BY-NC: מותר להפיץ עם מתן קרדיט, לשימוש לא מסחרי בלבד.',
            ];

            if ($edition = $work->editions()->where('slug', $slug.'-he')->first()) {
                $edition->update($details);
                $this->writer->reindex($edition);

                continue;
            }

            $chapters = $this->chapters($sections, $namedAfter);
            $edition = $work->editions()->create($details + ['slug' => $slug.'-he', 'status' => 'draft']);
            $path = DemoBookBuilder::epub($directory.'/'.$slug.'-he.epub', [
                'title' => $title,
                'language' => 'he',
                'direction' => 'rtl',
                'author' => $author->name,
                'identifier' => 'urn:orl-sefaria:'.$slug.':he:kehot-publication-society',
                'publisher' => $details['publisher'],
                'description' => $details['description'],
                'chapters' => $chapters,
            ]);
            $this->publish($edition, $path);

            $paragraphs = array_sum(array_map(fn (array $chapter) => substr_count($chapter['html'], '<p>'), $chapters));
            $this->command?->info("{$slug}: ".count($chapters)." sections, {$paragraphs} paragraphs.");
            $added++;
        }

        File::deleteDirectory($directory);
        $this->command?->info("Tanya: {$added} book(s) added.");
    }

    /**
     * One section per chapter, in reading order. In a book named after one of
     * its sections, that section's chapters are headed by their number alone.
     *
     * @param  list<int>  $sections  indexes into SECTIONS
     * @return list<array{title: string, html: string}>
     */
    private function chapters(array $sections, ?int $namedAfter): array
    {
        $this->text ??= $this->version('Tanya', self::VERSION, 'CC-BY-NC')['text'] ?? [];

        $chapters = [];
        foreach ($sections as $sectionIndex) {
            [$keys, $heading, $unit, $expected] = self::SECTIONS[$sectionIndex];

            $node = $this->text;
            foreach ($keys as $key) {
                $node = $node[$key] ?? throw new RuntimeException('Tanya: “'.implode(' / ', $keys).'” is missing from the download.');
            }
            // A section without chapters is a plain list of paragraphs.
            $parts = $unit === null ? [$node] : $node;
            if (count($parts) !== $expected) {
                throw new RuntimeException("Tanya: expected {$expected} section(s) in “{$heading}”, received ".count($parts).'.');
            }

            foreach ($parts as $index => $paragraphs) {
                $html = implode('', array_filter(array_map(fn ($paragraph) => self::paragraph((string) $paragraph), $paragraphs)));
                if ($html === '') {
                    throw new RuntimeException("Tanya: “{$heading}” ".($index + 1).' is empty.');
                }
                $number = $unit === null ? null : $unit.' '.self::hebrewNumeral($index + 1);
                $chapters[] = [
                    'title' => match (true) {
                        $number === null => $heading,
                        $sectionIndex === $namedAfter => $number,
                        default => $heading.' · '.$number,
                    },
                    'html' => $html,
                ];
            }
        }

        return $chapters;
    }

    /**
     * One paragraph as well-formed XHTML. Sefaria's markup is loose HTML: the
     * parser repairs it, the empty page and study-day markers are dropped, and
     * only plain emphasis survives.
     */
    private static function paragraph(string $source): string
    {
        $document = new DOMDocument;
        @$document->loadHTML('<?xml encoding="utf-8"?><div>'.$source.'</div>', LIBXML_NOERROR | LIBXML_NOWARNING);
        $root = $document->getElementsByTagName('div')->item(0);
        if (! $root || trim($root->textContent) === '') {
            return '';
        }

        $keep = ['b' => 'b', 'strong' => 'b', 'big' => 'b', 'small' => 'small', 'br' => 'br'];
        $render = function (DOMNode $node) use (&$render, $keep): string {
            $out = '';
            foreach ($node->childNodes as $child) {
                if ($child instanceof DOMElement) {
                    $tag = $keep[strtolower($child->tagName)] ?? null;
                    $inner = $render($child);
                    $out .= match (true) {
                        $tag === 'br' => '<br/>',
                        $tag !== null && trim($inner) !== '' => "<{$tag}>{$inner}</{$tag}>",
                        default => $inner,
                    };
                } elseif ($child->nodeType === XML_TEXT_NODE) {
                    $out .= htmlspecialchars($child->nodeValue, ENT_QUOTES | ENT_XML1, 'UTF-8');
                }
            }

            return $out;
        };

        return '<p>'.trim(preg_replace('/[ \t\r\n]+/u', ' ', $render($root))).'</p>';
    }
}
