<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Accounts\Models\ReadingList;
use App\Modules\Accounts\Models\RightsReport;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Collection;
use App\Modules\Catalog\Services\CatalogWriter;
use App\Modules\Imports\Models\AuditEvent;
use App\Modules\Localization\Models\Language;
use App\Modules\Reader\Models\Annotation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

class AccountsAndAdministrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpLibrary();
    }

    public function test_registration_sign_in_and_session_rotation(): void
    {
        $this->get('/register')->assertOk();
        $this->post('/register', ['name' => 'New Reader', 'email' => 'reader@example.org', 'password' => 'short', 'password_confirmation' => 'short'])
            ->assertSessionHasErrors('password');

        $this->post('/register', ['name' => 'New Reader', 'email' => 'reader@example.org', 'password' => 'correct-horse-42', 'password_confirmation' => 'correct-horse-42'])
            ->assertRedirect();
        $user = User::firstWhere('email', 'reader@example.org');
        $this->assertSame('reader', $user->role);
        $this->assertTrue(Hash::check('correct-horse-42', $user->password));
        $this->assertStringStartsWith('$2y$', $user->password);

        // Registration cannot grant a role.
        $this->post('/logout');
        $this->post('/register', ['name' => 'Sneaky', 'email' => 'sneaky@example.org', 'password' => 'correct-horse-42', 'password_confirmation' => 'correct-horse-42', 'role' => 'admin']);
        $this->assertSame('reader', User::firstWhere('email', 'sneaky@example.org')->role);
        $this->post('/logout');

        $this->get('/login');
        $before = session()->getId();
        $this->post('/login', ['email' => 'reader@example.org', 'password' => 'correct-horse-42'])->assertRedirect();
        $this->assertAuthenticatedAs($user);
        $this->assertNotSame($before, session()->getId(), 'The session id must change at sign-in.');
    }

    public function test_sign_in_is_rate_limited(): void
    {
        User::factory()->create(['email' => 'victim@example.org']);

        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['email' => 'victim@example.org', 'password' => 'wrong-password-'.$i])->assertSessionHasErrors();
        }
        $this->post('/login', ['email' => 'victim@example.org', 'password' => 'wrong-password-x'])->assertStatus(429);
    }

    public function test_email_dependent_flows_do_not_exist_while_mail_is_off(): void
    {
        $this->assertFalse(config('library.mail_enabled'));
        $this->assertFalse(Route::has('password.request'));
        $this->assertFalse(Route::has('verification.notice'));

        $this->get('/forgot-password')->assertNotFound();
        $this->post('/forgot-password', ['email' => 'reader@example.org'])->assertNotFound();

        // The sign-in page says so plainly instead of offering a reset that cannot arrive.
        $this->get('/login')->assertOk()->assertSee('Password reset by email is not available')->assertDontSee('Forgot your password?');

        // Accounts are usable without verification when no mail can be sent.
        $this->actingAs(User::factory()->unverified()->create())->get('/en/account')->assertOk();
    }

    public function test_administration_requires_staff_role_and_admins_need_two_factor(): void
    {
        $this->get('/admin')->assertRedirect('/login');

        $this->actingAs(User::factory()->create())->get('/admin')->assertForbidden();
        $this->post('/admin/works', ['original_title' => 'X', 'original_language_tag' => 'en'])->assertForbidden();

        // An administrator without two-factor is sent to set it up first.
        $this->actingAs($this->staff('admin', twoFactor: false))->get('/admin')->assertRedirect('/en/account/security');
        $this->actingAs($this->staff('admin'))->get('/admin')->assertOk();

        // Editors work in the admin area but cannot manage users or languages.
        $editor = $this->staff('editor', twoFactor: false);
        $this->actingAs($editor)->get('/admin')->assertOk();
        $this->get('/admin/users')->assertForbidden();
        $this->get('/admin/languages')->assertForbidden();
        $this->put("/admin/users/{$editor->id}", ['role' => 'admin'])->assertForbidden();
        $this->assertSame('editor', $editor->fresh()->role);
    }

    public function test_every_admin_screen_renders(): void
    {
        $edition = $this->publishedEdition(['title' => 'Admin View']);
        $this->import($this->draftEdition(), $this->pdfUpload());
        RightsReport::create(['kind' => 'rights', 'name' => 'A', 'email' => 'a@example.org', 'message' => 'Please check this.']);
        $this->actingAs($this->staff('admin'));

        $work = $edition->work;
        $category = Category::create(['name' => 'Cat', 'slug' => 'cat']);
        $collection = Collection::create(['name' => 'Col', 'slug' => 'col']);
        $contributor = app(CatalogWriter::class)->contributor('Some Person');

        foreach ([
            '/admin', '/admin/works', '/admin/works/create', "/admin/works/{$work->id}/edit", "/admin/works/{$work->id}/editions/create",
            '/admin/editions', '/admin/editions?status=published', "/admin/editions/{$edition->id}",
            '/admin/contributors', '/admin/contributors/create', "/admin/contributors/{$contributor->id}/edit",
            '/admin/taxonomy', "/admin/taxonomy/categories/{$category->id}", "/admin/taxonomy/collections/{$collection->id}",
            '/admin/csv-import', '/admin/csv-import/template', '/admin/reports', '/admin/reports?status=resolved', '/admin/audit',
            '/admin/languages', '/admin/users',
        ] as $path) {
            $response = $this->get($path);
            $this->assertSame(200, $response->getStatusCode(), "$path returned ".$response->getStatusCode());
            $response->assertHeader('Cache-Control', 'no-store, private');
        }
    }

    public function test_roles_change_only_through_administrators_and_one_admin_always_remains(): void
    {
        $admin = $this->staff('admin');
        $reader = User::factory()->create();
        $this->actingAs($admin);

        $this->put("/admin/users/{$reader->id}", ['role' => 'editor'])->assertSessionHasNoErrors();
        $this->assertSame('editor', $reader->fresh()->role);
        $this->assertSame(['from' => 'reader', 'to' => 'editor'], AuditEvent::firstWhere('action', 'user.role_changed')->data);

        $this->put("/admin/users/{$admin->id}", ['role' => 'reader'])->assertSessionHasErrors('role');
        $this->assertSame('admin', $admin->fresh()->role);

        // Mass assignment cannot set a role either.
        $reader->update(['role' => 'admin', 'name' => 'Changed']);
        $this->assertSame('editor', $reader->fresh()->role);
    }

    public function test_languages_can_be_added_without_a_schema_change(): void
    {
        $this->actingAs($this->staff('admin'));

        $this->post('/admin/languages', ['tag' => 'ar', 'native_name' => 'العربية', 'english_name' => 'Arabic', 'direction' => 'rtl'])->assertSessionHasNoErrors();
        $this->post('/admin/languages', ['tag' => 'not a tag', 'native_name' => 'x', 'english_name' => 'x', 'direction' => 'ltr'])->assertSessionHasErrors('tag');

        $this->assertSame('rtl', Language::directionFor('ar'));
        $edition = $this->publishedEdition(['title' => 'كتاب', 'language_tag' => 'ar', 'direction' => 'rtl']);
        $this->get("/en/editions/{$edition->slug}")->assertOk()->assertSee('<h1 lang="ar" dir="rtl">', false)->assertSee('العربية');
        $this->get('/en/catalog?language=ar')->assertOk()->assertSee('كتاب');
    }

    public function test_favorites_and_reading_lists_belong_to_their_owner(): void
    {
        $edition = $this->publishedEdition(['title' => 'Listed']);
        $alice = User::factory()->create();
        $bob = User::factory()->create();

        $this->actingAs($alice);
        $this->post("/en/account/favorites/{$edition->work->slug}")->assertRedirect();
        $this->post('/en/account/lists', ['name' => 'To read'])->assertRedirect();
        $list = ReadingList::firstWhere('user_id', $alice->id);
        $this->post("/en/account/lists/{$list->id}/items", ['edition' => $edition->slug])->assertSessionHasNoErrors();
        $this->get('/en/account')->assertOk()->assertSee('To read')->assertSee('Listed');

        // Bob cannot see, add to, or delete Alice's list by guessing its id.
        $this->actingAs($bob);
        $this->get('/en/account')->assertOk()->assertDontSee('To read');
        $this->post("/en/account/lists/{$list->id}/items", ['edition' => $edition->slug])->assertNotFound();
        $this->delete("/en/account/lists/{$list->id}")->assertNotFound();
        $this->delete("/en/account/lists/{$list->id}/items/{$edition->slug}")->assertNotFound();
        $this->assertSame(1, $list->editions()->count());

        // Guests are sent to sign in.
        auth()->logout();
        $this->get('/en/account')->assertRedirect('/login');
        $this->post("/en/account/favorites/{$edition->work->slug}")->assertRedirect('/login');
    }

    public function test_account_export_and_deletion(): void
    {
        $edition = $this->publishedEdition(['title' => 'Exported']);
        $file = $this->currentFile($edition);
        $user = User::factory()->create(['password' => 'correct-horse-42']);
        $this->actingAs($user);

        $this->postJson('/api/sync', [
            'progress' => [['fileId' => $file->id, 'locatorType' => 'cfi', 'locator' => 'my-place']],
            'annotations' => [['uuid' => (string) Str::uuid(), 'fileId' => $file->id, 'locatorType' => 'cfi', 'locator' => 'x', 'note' => 'my note']],
        ])->assertOk();
        Annotation::where('user_id', $user->id)->first()->delete(); // soft-deleted rows must go too

        $export = $this->get('/en/account/export')->assertOk()->assertHeader('Content-Disposition', 'attachment; filename="library-account-export.json"');
        $data = $export->json();
        $this->assertSame($user->email, $data['account']['email']);
        $this->assertSame('my-place', $data['reading_progress'][0]['locator']);
        $this->assertArrayNotHasKey('password', $data['account']);
        $this->assertStringNotContainsString($user->password, $export->getContent());

        $this->delete('/en/account', ['password' => 'wrong'])->assertSessionHasErrors('password');
        $this->assertNotNull($user->fresh());

        $this->delete('/en/account', ['password' => 'correct-horse-42'])->assertRedirect('/en');
        $this->assertGuest();
        $this->assertNull(User::find($user->id));
        foreach (['reading_progress', 'bookmarks', 'annotations', 'favorites', 'reading_lists'] as $table) {
            $this->assertSame(0, DB::table($table)->where('user_id', $user->id)->count(), "$table still holds rows");
        }

        // The only administrator cannot delete the account that guards /admin.
        $admin = $this->staff('admin');
        $admin->forceFill(['password' => 'correct-horse-42'])->save();
        $this->actingAs($admin)->delete('/en/account', ['password' => 'correct-horse-42'])->assertSessionHasErrors('password');
        $this->assertNotNull($admin->fresh());
    }

    public function test_contact_and_rights_reports_are_recorded_with_abuse_controls(): void
    {
        $edition = $this->publishedEdition(['title' => 'Reported']);

        $this->get("/en/rights?edition={$edition->slug}")->assertOk()->assertSee('About: Reported');
        $payload = ['kind' => 'rights', 'name' => 'Rights Holder', 'email' => 'holder@example.org', 'message' => 'This translation is mine. <script>alert(1)</script>', 'edition' => $edition->slug];

        $this->post('/en/reports', $payload)->assertSessionHasNoErrors();
        $report = RightsReport::firstOrFail();
        $this->assertSame($edition->id, $report->edition_id);
        $this->assertSame('open', $report->status);

        // Shown to staff as text, never as markup.
        $this->actingAs($this->staff('editor'));
        $this->get('/admin/reports')->assertOk()->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)->assertDontSee('<script>alert(1)</script>', false);
        $this->put("/admin/reports/{$report->id}", ['status' => 'resolved', 'resolution' => 'Edition withdrawn.'])->assertSessionHasNoErrors();
        $this->assertSame('resolved', $report->fresh()->status);
        auth()->logout();

        // Honeypot field filled: pretend success, store nothing.
        $this->post('/en/reports', ['website' => 'http://spam.example'] + $payload)->assertSessionHasNoErrors();
        $this->assertSame(1, RightsReport::count());

        // Validation and rate limit.
        $this->post('/en/reports', ['message' => 'short'] + $payload)->assertSessionHasErrors('message');
        $this->post('/en/reports', $payload);
        $this->post('/en/reports', $payload)->assertStatus(429);
    }

    public function test_metadata_language_is_independent_of_interface_language(): void
    {
        $edition = $this->publishedEdition(['title' => 'Meta']);
        $edition->work->syncTranslations(['ru' => ['title' => 'Русское название'], 'he' => ['title' => 'שם עברי']]);

        // By default catalog details follow the interface …
        $this->get("/ru/works/{$edition->work->slug}")->assertSee('Русское название');
        $english = $this->get("/en/works/{$edition->work->slug}")->getContent();
        $this->assertMatchesRegularExpression('/<h1><bdi\s+lang="en"\s+dir="ltr"\s*>Meta<\/bdi><\/h1>/u', $english);

        // … but a visitor can keep an English interface with Hebrew catalog details.
        $this->post('/preferences/metadata-language', ['language' => 'he'])->assertCookie('meta_lang', 'he');
        $mixed = $this->withCookie('meta_lang', 'he')->get("/en/works/{$edition->work->slug}")
            ->assertSee('<html lang="en" dir="ltr">', false)->getContent();
        $this->assertMatchesRegularExpression('/<h1><bdi\s+lang="he"\s+dir="rtl"\s*>שם עברי<\/bdi><\/h1>/u', $mixed);
    }

    public function test_interface_theme_is_chosen_in_settings_and_kept_in_a_cookie(): void
    {
        // Without a choice the theme follows the device.
        $this->get('/en/settings')->assertOk()
            ->assertSee('data-ui-theme="auto"', false)->assertSee('<meta name="robots" content="noindex">', false);

        $this->post('/preferences/theme', ['theme' => 'ivory'])->assertCookie('ui_theme', 'ivory');
        $this->withCookie('ui_theme', 'ivory')->get('/en/catalog')
            ->assertSee('data-ui-theme="ivory"', false)->assertSee('<meta name="color-scheme" content="light">', false);

        // Unknown values, like the default itself, leave no cookie behind.
        $this->post('/preferences/theme', ['theme' => 'neon'])->assertCookieExpired('ui_theme');
        $this->withCookie('ui_theme', 'neon')->get('/en')->assertSee('data-ui-theme="auto"', false);
    }
}
