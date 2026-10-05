<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpLibrary();
    }

    public function test_anonymous_visitors_can_browse_read_and_download_without_an_account(): void
    {
        $edition = $this->publishedEdition(['title' => 'Open Book']);
        $file = $this->currentFile($edition);

        foreach (['en', 'ru', 'he'] as $locale) {
            $this->get("/$locale")->assertOk()->assertSee('Open Book');
            $this->get("/$locale/catalog")->assertOk()->assertSee('Open Book');
            $this->get("/$locale/works/{$edition->work->slug}")->assertOk();
            $this->get("/$locale/editions/{$edition->slug}")->assertOk();
            $this->get("/$locale/read/{$edition->slug}")->assertOk();
        }

        $this->get("/download/{$file->id}/book.epub")->assertOk();
        $this->assertGuest();
    }

    public function test_interface_language_and_book_language_are_independent(): void
    {
        $hebrew = $this->publishedEdition(['title' => 'ספר בדיקה', 'language_tag' => 'he', 'direction' => 'rtl']);

        // English interface, Hebrew book: the page is LTR English, the title carries its own language and direction.
        $response = $this->get("/en/editions/{$hebrew->slug}")->assertOk();
        $response->assertSee('<html lang="en" dir="ltr">', false);
        $response->assertSee('<h1 lang="he" dir="rtl">ספר בדיקה</h1>', false);

        // Hebrew interface, English book.
        $english = $this->publishedEdition(['title' => 'English Title']);
        $response = $this->get("/he/editions/{$english->slug}")->assertOk();
        $response->assertSee('<html lang="he" dir="rtl">', false);
        $response->assertSee('<h1 lang="en" dir="ltr">English Title</h1>', false);

        // The reader page keeps the interface language on <html> and marks the book area separately.
        $reader = $this->get("/en/read/{$hebrew->slug}")->assertOk();
        $reader->assertSee('<html lang="en" dir="ltr">', false);
        $reader->assertSee('id="book" class="rbook" tabindex="-1" lang="he" dir="rtl"', false);
    }

    public function test_language_switcher_names_languages_in_their_own_language_and_pages_declare_alternates(): void
    {
        $response = $this->get('/en/catalog')->assertOk();

        $response->assertSee('lang="ru" hreflang="ru"', false)->assertSee('Русский');
        $response->assertSee('lang="he" hreflang="he"', false)->assertSee('עברית');
        $response->assertSee('<link rel="canonical" href="http://localhost/en/catalog">', false);
        $response->assertSee('<link rel="alternate" hreflang="he" href="http://localhost/he/catalog">', false);
        $response->assertSee('hreflang="x-default"', false);
    }

    public function test_root_redirects_to_remembered_then_browser_language_with_fallback(): void
    {
        $this->get('/')->assertRedirect('/en');
        $this->withHeader('Accept-Language', 'ru-RU,ru;q=0.9,en;q=0.5')->get('/')->assertRedirect('/ru');
        // Legacy code some browsers still send for Hebrew.
        $this->withHeader('Accept-Language', 'iw')->get('/')->assertRedirect('/he');
        $this->withHeader('Accept-Language', 'fr-FR')->get('/')->assertRedirect('/en');
        $this->withHeader('Accept-Language', 'ru')->withUnencryptedCookie('ui_locale', 'he')->get('/')->assertRedirect('/he');

        $this->get('/ru/catalog')->assertCookie('ui_locale', 'ru', false);
        $this->get('/fr/catalog')->assertNotFound();
    }

    public function test_a_work_may_have_several_editions_in_the_same_language(): void
    {
        $first = $this->publishedEdition(['title' => 'First English Edition']);
        $second = $this->publishedEdition(['title' => 'Second English Edition'], null, $first->work);
        $russian = $this->publishedEdition(['title' => 'Русское издание', 'language_tag' => 'ru'], null, $first->work);

        $this->assertSame(2, $first->work->editions()->where('language_tag', 'en')->count());

        $this->get("/en/works/{$first->work->slug}")->assertOk()
            ->assertSee('First English Edition')->assertSee('Second English Edition')->assertSee('Русское издание');

        // The edition page lists the others and warns that positions do not carry over.
        $this->get("/en/editions/{$first->slug}")->assertOk()
            ->assertSee('Second English Edition')->assertSee('Русское издание')
            ->assertSee('Page numbers and percentages do not point to the same passage');
    }

    public function test_unpublished_editions_are_hidden_from_everyone_but_staff(): void
    {
        $draft = $this->draftEdition(['title' => 'Secret Draft']);
        $job = $this->import($draft, $this->epubUpload());
        $draft->refresh();
        $this->assertSame('review', $draft->status);

        $paths = ["/en/editions/{$draft->slug}", "/en/read/{$draft->slug}", "/en/works/{$draft->work->slug}", "/download/{$job->file->id}"];

        foreach ($paths as $path) {
            $this->get($path)->assertNotFound();
        }
        $this->get('/en/catalog')->assertDontSee('Secret Draft');
        $this->get('/sitemap.xml')->assertDontSee($draft->slug);

        // An ordinary signed-in reader is treated like a visitor.
        $this->actingAs(User::factory()->create());
        foreach ($paths as $path) {
            $this->get($path)->assertNotFound();
        }

        // Staff get a clearly marked, uncacheable, unindexed preview.
        $this->actingAs($this->staff());
        $this->get("/en/editions/{$draft->slug}")->assertOk()
            ->assertSee('Staff preview')->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $this->get("/en/read/{$draft->slug}")->assertOk();
    }

    public function test_missing_actions_are_explained_on_the_edition_page(): void
    {
        $edition = $this->publishedEdition(['title' => 'Read Only'], null, null, ['can_download' => false, 'can_offline' => false]);

        $this->get("/en/editions/{$edition->slug}")->assertOk()
            ->assertSee('Read in browser')
            ->assertSee('Downloads are not offered for this edition')
            ->assertDontSee('Download EPUB');

        $pdfOnly = $this->publishedEdition(['title' => 'Pdf Only'], $this->pdfUpload());
        $this->get("/en/editions/{$pdfOnly->slug}")->assertOk()
            ->assertSee('Download PDF')
            ->assertSee('No EPUB is available')
            ->assertSee('Text size cannot be changed');
    }

    public function test_seo_endpoints_keep_private_areas_out(): void
    {
        $edition = $this->publishedEdition(['title' => 'Indexed']);

        $this->get('/sitemap.xml')->assertOk()
            ->assertSee("http://localhost/en/editions/{$edition->slug}", false)
            ->assertSee('hreflang="ru"', false)
            ->assertDontSee('/admin')->assertDontSee('/read/');

        $this->get('/robots.txt')->assertOk()->assertSee('Disallow: /admin')->assertSee('Disallow: /*/read/')->assertSee('Sitemap:');
        $this->get("/en/editions/{$edition->slug}")->assertSee('application/ld+json', false)->assertSee('"@type":"Book"', false);
        $this->get("/en/read/{$edition->slug}")->assertHeader('X-Robots-Tag', 'noindex');
        $this->get('/en/catalog?q=indexed')->assertSee('<meta name="robots" content="noindex">', false);
    }

    public function test_help_pages_and_kindle_guidance(): void
    {
        foreach (['reading', 'downloads', 'kindle', 'offline'] as $topic) {
            foreach (['en', 'ru', 'he'] as $locale) {
                $this->get("/$locale/help/$topic")->assertOk();
            }
        }

        $kindle = $this->get('/en/help/kindle')->assertOk();
        $kindle->assertSee('https://www.amazon.com/sendtokindle', false);
        $kindle->assertSee('We never ask for your Amazon details');
        $kindle->assertSee('not connected to your reading position');
        // Nothing on the page can take an Amazon login.
        $kindle->assertDontSee('type="password"', false)->assertDontSee('type="email"', false);
    }

    public function test_security_headers_and_health_check(): void
    {
        $response = $this->get('/en')->assertOk();
        $csp = $response->headers->get('Content-Security-Policy');

        $this->assertStringContainsString("script-src 'self'", $csp);
        $this->assertStringNotContainsString("script-src 'self' 'unsafe-inline'", $csp);
        $this->assertStringContainsString("object-src 'none'", $csp);
        $response->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('X-Frame-Options', 'SAMEORIGIN');

        // No executable inline script anywhere on a public page.
        $this->assertSame(0, preg_match('/<script(?![^>]*type="application\/(json|ld\+json)")(?![^>]*\bsrc=)[^>]*>/i', $response->getContent()));

        $health = $this->get('/healthz')->assertOk()->assertJson(['status' => 'ok']);
        $this->assertSame(['status', 'checks'], array_keys($health->json()));
        $this->assertStringNotContainsStringIgnoringCase('version', $health->getContent());
    }
}
