<?php

namespace Tests\Feature;

use App\Modules\Catalog\Models\Edition;
use App\Modules\Catalog\Models\Work;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class CsvImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpLibrary();
        $this->actingAs($this->staff('editor'));
    }

    private function csv(string $content): UploadedFile
    {
        $path = $this->temporaryPath('csv');
        file_put_contents($path, $content);

        return new UploadedFile($path, 'books.csv', 'text/csv', null, true);
    }

    public function test_valid_rows_create_works_and_draft_editions_only(): void
    {
        $csv = "\xEF\xBB\xBForiginal_title,original_language,language,title,authors,translators,rights_status,source_name,attribution,categories,tags\n"
            ."\"War, Peace and Tea\",ru,ru,\"Война, мир и чай\",Лев Толстой,,public_domain,Archive,Text from the archive.,Novels,classic; long\n"
            ."\"War, Peace and Tea\",ru,he,\"מלחמה, שלום ותה\",Лев Толстой,דנה מתרגמת,authorized,Publisher,With permission.,Novels,classic; long\n";

        // Check-only run changes nothing.
        $this->post('/admin/csv-import', ['csv' => $this->csv($csv), 'dry_run' => 1])->assertSessionHas('csv_summary');
        $this->assertSame(0, Work::count());

        $this->post('/admin/csv-import', ['csv' => $this->csv($csv)])->assertSessionHasNoErrors();
        $summary = session('csv_summary');
        $this->assertSame(['rows' => 2, 'created_works' => 1, 'created_editions' => 2, 'updated_editions' => 0], array_intersect_key($summary, array_flip(['rows', 'created_works', 'created_editions', 'updated_editions'])));

        $work = Work::firstOrFail();
        $this->assertSame('Лев Толстой', $work->authors->first()->name);
        $this->assertSame(['classic', 'long'], $work->tags->pluck('name')->sort()->values()->all());

        $hebrew = Edition::firstWhere('language_tag', 'he');
        $this->assertSame('rtl', $hebrew->direction, 'Direction defaults from the language.');
        $this->assertSame('דנה מתרגמת', $hebrew->translators->first()->name);

        // A CSV can never publish: everything is a private draft without files.
        $this->assertSame(['draft'], Edition::pluck('status')->unique()->all());
        auth()->logout();
        $this->get("/en/editions/{$hebrew->slug}")->assertNotFound();
        $this->get('/en/catalog')->assertDontSee('Война, мир и чай');
    }

    public function test_any_invalid_row_stops_the_whole_import_and_is_reported_by_line(): void
    {
        $csv = "original_title,original_language,language,title,rights_status,source_url\n"
            ."Good Book,en,en,Good Book,public_domain,https://example.org/a\n"
            ."Bad Language,en,xx,Bad Language,public_domain,\n"
            ."Bad Rights,en,en,,stolen,javascript:alert(1)\n";

        $this->post('/admin/csv-import', ['csv' => $this->csv($csv)])->assertSessionHas('csv_summary');
        $summary = session('csv_summary');

        $this->assertSame([3, 4], array_column($summary['errors'], 'line'));
        $this->assertSame(0, Work::count(), 'A partly valid file must import nothing.');
        $this->assertSame(0, Edition::count());

        $this->get('/admin/csv-import')->assertOk()->assertSee('failed');
    }

    public function test_structural_problems_are_rejected_with_a_clear_message(): void
    {
        $this->post('/admin/csv-import', ['csv' => $this->csv("title,language\nOnly,en\n")])->assertSessionHasErrors('csv');
        $this->post('/admin/csv-import', ['csv' => $this->csv("original_title,original_language,language,title,password\nA,en,en,A,x\n")])->assertSessionHasErrors('csv');
        $this->post('/admin/csv-import', ['csv' => $this->csv("original_title,original_language,language,title\nA,en,en\n")])->assertSessionHasErrors('csv');
        $this->post('/admin/csv-import', ['csv' => $this->csv("original_title,original_language,language,title\n\xC3\x28,en,en,A\n")])->assertSessionHasErrors('csv');

        $rows = "original_title,original_language,language,title\n".str_repeat("A,en,en,A\n", 501);
        $this->post('/admin/csv-import', ['csv' => $this->csv($rows)])->assertSessionHasErrors('csv');

        $this->assertSame(0, Work::count());
    }

    public function test_existing_editions_are_updated_without_touching_their_status(): void
    {
        $published = $this->publishedEdition(['title' => 'Old Title', 'slug' => 'known-edition']);
        $csv = "original_title,original_language,language,title,edition_slug,publisher\n"
            ."Test Work,en,en,New Title,known-edition,New Press\n";

        $this->post('/admin/csv-import', ['csv' => $this->csv($csv)])->assertSessionHasNoErrors();

        $edition = $published->fresh();
        $this->assertSame('New Title', $edition->title);
        $this->assertSame('New Press', $edition->publisher);
        $this->assertSame('published', $edition->status);
        $this->assertStringContainsString('new title', $edition->search_text);
    }
}
