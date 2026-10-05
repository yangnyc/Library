<?php

namespace Tests\Feature;

use Database\Seeders\Support\SefariaSeeder;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class SefariaSourceTest extends TestCase
{
    public function test_exact_hebrew_version_preserves_source_text_and_vowel_marks(): void
    {
        Http::preventStrayRequests();
        $source = $this->source();
        Http::fake(['https://www.sefaria.org/download/version/*' => Http::response($source)]);

        $this->assertSame($source, app(SefariaDownloadProbe::class)->download());
        Http::assertSent(fn ($request) => $request->url() === 'https://www.sefaria.org/download/version/Genesis%20-%20he%20-%20Tanach%20with%20Nikkud.json');
    }

    #[DataProvider('invalidVersions')]
    public function test_wrong_or_empty_source_is_rejected(string $key, mixed $value): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://www.sefaria.org/download/version/*' => Http::response(array_replace($this->source(), [$key => $value]))]);

        $this->expectException(RuntimeException::class);
        app(SefariaDownloadProbe::class)->download();
    }

    public static function invalidVersions(): array
    {
        return [
            'wrong book' => ['title', 'Exodus'],
            'wrong edition' => ['versionTitle', 'Tanach with Text Only'],
            'wrong language' => ['language', 'en'],
            'missing text' => ['text', null],
            'empty text' => ['text', []],
            'invalid text shape' => ['text', 'not an array'],
            'changed license' => ['license', 'Copyright'],
        ];
    }

    private function source(): array
    {
        return [
            'title' => 'Genesis', 'versionTitle' => 'Tanach with Nikkud',
            'language' => 'he', 'license' => 'Public Domain',
            'text' => [['בְּרֵאשִׁית בָּרָא']],
        ];
    }
}

class SefariaDownloadProbe extends SefariaSeeder
{
    public function download(): array
    {
        return $this->version('Genesis', 'Tanach with Nikkud', 'Public Domain');
    }
}
