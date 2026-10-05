<?php

namespace Tests\Feature;

use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Collection;
use App\Modules\Catalog\Models\Contributor;
use App\Modules\Catalog\Models\Edition;
use App\Modules\Catalog\Models\Work;
use App\Modules\Catalog\Services\CatalogSearch;
use App\Modules\Catalog\Services\CatalogWriter;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Runs against MariaDB/MySQL with real commits: an InnoDB full-text index
 * does not see rows inside an uncommitted transaction, so this class migrates
 * the database instead of wrapping each test in one.
 */
class CatalogSearchTest extends TestCase
{
    use DatabaseMigrations;

    private CatalogWriter $writer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpLibrary();
        $this->writer = app(CatalogWriter::class);
        $this->seedCatalog();
    }

    private function edition(Work $work, array $attributes, array $translators = [], bool $withFile = true): Edition
    {
        $edition = $work->editions()->create($attributes + [
            'slug' => 'e-'.md5(json_encode($attributes)), 'direction' => 'ltr', 'status' => 'published', 'published_at' => now(),
            'rights_status' => 'public_domain', 'source_name' => 'x', 'attribution' => 'x',
        ]);
        if ($translators) {
            $this->writer->syncContributors($edition, 'translator', array_map(fn ($n) => $this->writer->contributor($n)->id, $translators));
        }
        if ($withFile) {
            $edition->files()->create([
                'format' => $attributes['format'] ?? 'epub', 'mime_type' => 'application/epub+zip', 'byte_size' => 10, 'sha256' => str_repeat('a', 64),
                'storage_disk' => 'library', 'storage_path' => 'x', 'is_current' => true, 'import_status' => 'ready', 'can_read' => true, 'can_download' => true,
            ]);
        }
        $this->writer->reindex($edition);

        return $edition;
    }

    private function seedCatalog(): void
    {
        $sea = Category::create(['name' => 'Sea stories', 'slug' => 'sea']);
        $shelf = Collection::create(['name' => 'Shelf', 'slug' => 'shelf', 'is_published' => true]);

        $lighthouse = Work::create(['slug' => 'lighthouse', 'original_title' => 'The Lighthouse Keeper', 'original_language_tag' => 'en']);
        $this->writer->syncContributors($lighthouse, 'author', [$this->writer->contributor('Ada Seaborn')->id]);
        $lighthouse->categories()->attach($sea);
        $lighthouse->collections()->attach($shelf);
        $this->writer->syncTags($lighthouse, ['maritime']);

        $this->edition($lighthouse, ['title' => 'The Lighthouse Keeper', 'language_tag' => 'en', 'isbn' => '978-1-23456-789-7', 'description' => 'A keeper counts boats at night.', 'published_date' => '1901']);
        $this->edition($lighthouse, ['title' => 'Смотритель маяка и ёлка', 'language_tag' => 'ru', 'description' => 'Смотритель считает лодки.', 'published_date' => '1950'], ['Иван Переводчиков']);
        $this->edition($lighthouse, ['title' => 'שׁוֹמֵר הַמִּגְדַּלּוֹר', 'language_tag' => 'he', 'direction' => 'rtl', 'description' => 'סיפור על ים', 'published_date' => '1999'], ['דנה מתרגמת']);

        $other = Work::create(['slug' => 'garden', 'original_title' => 'An Ox in the Garden', 'original_language_tag' => 'en']);
        $this->writer->syncContributors($other, 'author', [$this->writer->contributor('Bo Li')->id]);
        $this->edition($other, ['title' => 'An Ox in the Garden', 'language_tag' => 'en', 'format' => 'pdf', 'published_date' => '2020']);

        // Unpublished: must never be found.
        $this->edition($other, ['title' => 'Lighthouse Draft Secret', 'language_tag' => 'en', 'status' => 'draft', 'published_at' => null]);
    }

    /** @return list<string> */
    private function titles(array $filters): array
    {
        return app(CatalogSearch::class)->paginate($filters)->pluck('title')->all();
    }

    public function test_english_search_finds_titles_people_descriptions_tags_and_identifiers(): void
    {
        $this->assertContains('The Lighthouse Keeper', $this->titles(['q' => 'lighthouse']));
        $this->assertContains('The Lighthouse Keeper', $this->titles(['q' => 'LIGHT']));          // prefix, case-insensitive
        $this->assertContains('The Lighthouse Keeper', $this->titles(['q' => 'seaborn']));        // author
        $this->assertContains('The Lighthouse Keeper', $this->titles(['q' => 'boats night']));    // description, all words
        $this->assertContains('The Lighthouse Keeper', $this->titles(['q' => 'maritime']));       // tag
        $this->assertSame(['The Lighthouse Keeper'], $this->titles(['q' => '9781234567897']));    // ISBN without hyphens
        $this->assertSame(['The Lighthouse Keeper'], $this->titles(['q' => '978-1-23456-789-7'])); // ISBN as printed
        $this->assertSame([], $this->titles(['q' => 'zeppelin']));
        $this->assertNotContains('Lighthouse Draft Secret', $this->titles(['q' => 'lighthouse']));
    }

    public function test_russian_search_is_case_insensitive_and_treats_yo_as_ye(): void
    {
        $title = 'Смотритель маяка и ёлка';

        $this->assertSame([$title], $this->titles(['q' => 'смотритель маяка']));
        $this->assertSame([$title], $this->titles(['q' => 'МАЯК']));
        $this->assertSame([$title], $this->titles(['q' => 'елка']));   // typed without ё
        $this->assertSame([$title], $this->titles(['q' => 'ёлка']));
        $this->assertSame([$title], $this->titles(['q' => 'переводчиков'])); // translator
    }

    public function test_hebrew_search_ignores_vowel_points_but_never_alters_the_stored_title(): void
    {
        $pointed = 'שׁוֹמֵר הַמִּגְדַּלּוֹר';

        $this->assertSame([$pointed], $this->titles(['q' => 'שומר המגדלור']));   // unpointed query
        $this->assertSame([$pointed], $this->titles(['q' => $pointed]));          // pointed query
        $this->assertSame([$pointed], $this->titles(['q' => 'מתרגמת']));          // translator
        $this->assertSame([$pointed], $this->titles(['q' => 'ים']));              // two-letter word → bounded fallback

        // The displayed value keeps its points; only search_text is normalized.
        $edition = Edition::firstWhere('language_tag', 'he');
        $this->assertSame($pointed, $edition->title);
        $this->assertStringContainsString('שומר המגדלור', $edition->search_text);
        $this->get('/en/catalog?q='.urlencode('שומר'))->assertOk()->assertSee($pointed);
    }

    public function test_short_words_and_stop_words_use_the_bounded_fallback(): void
    {
        $minimum = (int) DB::selectOne("SHOW VARIABLES LIKE 'innodb_ft_min_token_size'")->Value;
        $this->assertGreaterThan(2, $minimum, 'This test assumes two-letter words are below the full-text minimum.');

        $this->assertSame(['An Ox in the Garden'], $this->titles(['q' => 'ox']));           // shorter than the index minimum
        $this->assertContains('An Ox in the Garden', $this->titles(['q' => 'li']));         // short author name
        $this->assertContains('The Lighthouse Keeper', $this->titles(['q' => 'the']));       // InnoDB stop word
        $this->assertSame(['An Ox in the Garden'], $this->titles(['q' => 'ox garden']));     // mixed: fallback + full-text

        // Hostile input is just text.
        $this->assertSame([], $this->titles(['q' => "'; DROP TABLE editions; --"]));
        // Only punctuation: there is nothing to search for, so it behaves like an empty search box.
        $this->assertCount(4, $this->titles(['q' => '+-*"()~<>@ %_']));
        $this->assertGreaterThan(0, Edition::count());

        config(['library.search.fallback_limit' => 1]);
        $this->assertCount(1, $this->titles(['q' => 'a']), 'The fallback scan must be capped.');
    }

    public function test_filters_sorting_and_shareable_pagination(): void
    {
        $this->assertCount(4, $this->titles([]));
        $this->assertSame(['Смотритель маяка и ёлка'], $this->titles(['language' => 'ru']));
        $this->assertSame(['An Ox in the Garden'], $this->titles(['format' => 'pdf']));
        $this->assertSame(['An Ox in the Garden'], $this->titles(['author' => 'bo-li']));
        $this->assertCount(3, $this->titles(['category' => 'sea']));
        $this->assertCount(3, $this->titles(['collection' => 'shelf']));
        $this->assertSame(['The Lighthouse Keeper'], $this->titles(['category' => 'sea', 'language' => 'en', 'q' => 'keeper']));

        $translator = Contributor::firstWhere('name', 'Иван Переводчиков');
        $this->assertSame(['Смотритель маяка и ёлка'], $this->titles(['translator' => $translator->slug]));

        $this->assertSame('An Ox in the Garden', $this->titles(['sort' => 'title'])[0]);
        $this->assertSame('An Ox in the Garden', $this->titles(['sort' => 'year'])[0]);

        // Pagination links carry the filters, so page 2 of a filtered list can be shared.
        config(['library.search.per_page' => 1]);
        $this->get('/en/catalog?language=en&sort=title')->assertOk()
            ->assertSee('An Ox in the Garden')->assertDontSee('The Lighthouse Keeper')
            ->assertSee('language=en&amp;sort=title&amp;page=2', false);
        $this->get('/en/catalog?language=en&sort=title&page=2')->assertOk()->assertSee('The Lighthouse Keeper');
        config(['library.search.per_page' => 24]);

        // The same filters work from a shared URL, in any interface language.
        $this->get('/he/catalog?language=ru&sort=title')->assertOk()->assertSee('Смотритель маяка и ёлка')->assertDontSee('An Ox in the Garden');
        $this->get('/en/catalog?q=zeppelin')->assertOk()->assertSee('Nothing matches these filters');
        $this->get('/en/catalog?format=exe')->assertStatus(302); // invalid filter value is refused, not passed to SQL
    }

    public function test_suggestions_list_published_matches_with_links_to_their_editions(): void
    {
        $response = $this->getJson('/en/catalog/suggest?q=9781234567897')->assertOk();

        $response->assertJsonPath('total', 1);
        $response->assertJsonPath('results.0.title', 'The Lighthouse Keeper');
        $response->assertJsonPath('results.0.language', 'en');
        $response->assertJsonPath('results.0.people', 'Ada Seaborn');
        $response->assertJsonPath('results.0.url', url('/en/editions/'.Edition::firstWhere('title', 'The Lighthouse Keeper')->slug));
    }

    public function test_suggestions_never_include_unpublished_editions(): void
    {
        $this->getJson('/en/catalog/suggest?q=lighthouse')->assertOk()->assertJsonCount(3, 'results')->assertDontSee('Lighthouse Draft Secret');
    }

    public function test_suggestions_are_empty_when_nothing_matches(): void
    {
        $this->getJson('/en/catalog/suggest?q=zeppelin')->assertOk()->assertExactJson(['results' => [], 'total' => 0]);
    }

    public function test_suggestions_refuse_a_query_shorter_than_two_characters_with_422(): void
    {
        $this->getJson('/en/catalog/suggest?q=a')->assertStatus(422)->assertJsonValidationErrors(['q']);
    }
}
