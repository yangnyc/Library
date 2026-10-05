<?php

namespace Tests\Unit;

use App\Modules\Imports\Sanitizing\CssSanitizer;
use App\Modules\Imports\Sanitizing\MarkupSanitizer;
use App\Modules\Imports\Sanitizing\SafeXml;
use App\Modules\Localization\SearchNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SanitizerTest extends TestCase
{
    private function xhtml(string $body): string
    {
        return (new MarkupSanitizer)->sanitizeXhtml(
            '<?xml version="1.0"?><html xmlns="http://www.w3.org/1999/xhtml" xmlns:epub="http://www.idpf.org/2007/ops"><head><title>t</title></head><body>'.$body.'</body></html>'
        );
    }

    /** @return array<string, array{string, list<string>}> */
    public static function hostileMarkup(): array
    {
        return [
            'script element' => ['<script>alert(1)</script><p>ok</p>', ['<script', 'alert(1)']],
            'event handler' => ['<p onmouseover="x()">ok</p>', ['onmouseover']],
            'uppercase handler' => ['<p ONCLICK="x()">ok</p>', ['onclick', 'ONCLICK']],
            'javascript href' => ['<a href="javascript:x()">ok</a>', ['javascript:']],
            'whitespace in scheme' => ["<a href=\"java\nscript:x()\">ok</a>", ['script:x']],
            'vbscript href' => ['<a href="vbscript:x">ok</a>', ['vbscript:']],
            'data html href' => ['<a href="data:text/html;base64,PHNjcmlwdD4=">ok</a>', ['data:text/html']],
            'file scheme image' => ['<img src="file:///etc/passwd" alt=""/>', ['file:']],
            'remote image' => ['<img src="http://t.example/p.gif" alt=""/>', ['t.example']],
            'absolute path image' => ['<img src="/admin/secret.png" alt=""/>', ['/admin/']],
            'srcset' => ['<img src="a.png" srcset="http://t.example/a.png 2x" alt=""/>', ['srcset', 't.example']],
            'iframe srcdoc' => ['<iframe srcdoc="&lt;script&gt;x&lt;/script&gt;"></iframe><p>ok</p>', ['iframe', 'srcdoc']],
            'form elements' => ['<form action="/x"><input name="a"/><textarea>t</textarea><select><option>o</option></select></form><p>ok</p>', ['<form', '<input', '<textarea', '<select']],
            'meta refresh' => ['<meta http-equiv="refresh" content="0;url=http://e.example"/><p>ok</p>', ['http-equiv', 'e.example']],
            'svg script' => ['<svg xmlns="http://www.w3.org/2000/svg"><script>x</script><circle r="1"/></svg>', ['<script']],
            'svg animate href' => ['<svg xmlns="http://www.w3.org/2000/svg"><a><animate attributeName="href" to="javascript:x"/><text>t</text></a></svg>', ['animate', 'javascript:']],
            'style expression' => ['<p style="width: expression(alert(1))">ok</p>', ['expression(']],
            'style remote url' => ['<p style="background:url(//t.example/x.gif)">ok</p>', ['t.example']],
            'object and embed' => ['<object data="x.swf"><param name="a" value="b"/></object><embed src="x.swf"/><p>ok</p>', ['<object', '<embed']],
            'unknown custom element' => ['<x-widget onload="x()"><p>ok</p></x-widget>', ['x-widget', 'onload']],
            'processing instruction' => ['<?php echo 1; ?><p>ok</p>', ['<?php']],
            'comment' => ['<!--[if IE]><script>x</script><![endif]--><p>ok</p>', ['<!--', 'script']],
        ];
    }

    #[DataProvider('hostileMarkup')]
    public function test_hostile_markup_is_removed_and_text_survives(string $body, array $forbidden): void
    {
        $clean = $this->xhtml($body);

        foreach ($forbidden as $needle) {
            $this->assertStringNotContainsStringIgnoringCase($needle, $clean);
        }
        // The output is still well-formed XML.
        $this->assertNotFalse(simplexml_load_string(preg_replace('/<!DOCTYPE[^>]*>/', '', $clean)));
        if (str_contains($body, 'ok')) {
            $this->assertStringContainsString('ok', $clean);
        }
    }

    public function test_ordinary_book_markup_is_preserved(): void
    {
        $body = '<section epub:type="chapter" id="c1"><h1 class="title" lang="he" dir="rtl">כותרת</h1>'
            .'<p>Text with <em>emphasis</em>, <a href="notes.xhtml#n1" epub:type="noteref">a note</a>, &nbsp;&mdash; entities,'
            .' <span lang="ru">слово</span> and <ruby>漢<rt>kan</rt></ruby>.</p>'
            .'<table><tr><th scope="col">A</th><td colspan="2">B</td></tr></table>'
            .'<img src="images/fig.png" alt="Figure" width="10" height="10"/>'
            .'<img src="data:image/png;base64,iVBORw0KGgo=" alt="inline"/>'
            .'<a href="https://example.org/page">external</a><a href="mailto:a@example.org">mail</a></section>';

        $clean = $this->xhtml($body);

        foreach (['epub:type="chapter"', 'id="c1"', 'lang="he"', 'dir="rtl"', 'כותרת', '<em>emphasis</em>', 'href="notes.xhtml#n1"', 'epub:type="noteref"',
            'слово', '<ruby>', 'colspan="2"', 'src="images/fig.png"', 'alt="Figure"', 'data:image/png;base64', 'href="https://example.org/page"',
            'rel="noopener noreferrer external"', 'href="mailto:a@example.org"'] as $kept) {
            $this->assertStringContainsString($kept, $clean);
        }
        // Named HTML entities are converted, not dropped.
        $this->assertStringContainsString("\u{00A0}", html_entity_decode($clean, ENT_QUOTES | ENT_XML1, 'UTF-8'));
        $this->assertStringContainsString('—', html_entity_decode($clean, ENT_QUOTES | ENT_XML1, 'UTF-8'));
    }

    public function test_malformed_markup_is_repaired_instead_of_passed_through(): void
    {
        $sanitizer = new MarkupSanitizer;
        $clean = $sanitizer->sanitizeXhtml('<html><body><p>Unclosed <b>bold<script>x()</script><p>Next</body></html>');

        $this->assertStringNotContainsString('<script', $clean);
        $this->assertStringContainsString('Unclosed', $clean);
        $this->assertStringContainsString('xmlns="http://www.w3.org/1999/xhtml"', $clean);
        $this->assertNotEmpty($sanitizer->notes());
    }

    public function test_css_keeps_local_rules_and_drops_remote_or_active_ones(): void
    {
        $css = (new CssSanitizer)->sanitize(
            "@charset 'utf-8';\n@import url(http://e.example/a.css);\n@import \"local.css\";\n"
            ."@font-face { font-family: X; src: url(fonts/x.woff2), url(https://e.example/x.woff); }\n"
            ."p { color: #333; background: url( 'img/bg.png' ); }\n"
            ."a { background: url(javascript:alert(1)); behavior: url(x.htc); -moz-binding: url(x.xml#a); width: expression(1+1); }\n"
            ."/* comment with url(http://e.example/hidden) */ b { background: u\\72l(http://e.example/escaped.gif); }\n"
            .'</style><script>x()</script>'
        );

        foreach (['e.example', 'javascript:', 'behavior', '-moz-binding', 'expression(', '</style', 'comment with'] as $forbidden) {
            $this->assertStringNotContainsStringIgnoringCase($forbidden, $css);
        }
        foreach (['@import url("local.css")', 'url("fonts/x.woff2")', 'url("img/bg.png")', 'color: #333'] as $kept) {
            $this->assertStringContainsString($kept, $css);
        }
    }

    public function test_xml_with_entity_declarations_is_neutralized_or_refused(): void
    {
        // Internal subset with a classic "billion laughs" start: the DOCTYPE is dropped before parsing.
        $bomb = '<?xml version="1.0"?><!DOCTYPE lolz [<!ENTITY lol "lol"><!ENTITY lol2 "&lol;&lol;&lol;&lol;&lol;&lol;&lol;&lol;">]><root>&lol2;</root>';
        try {
            $document = SafeXml::load($bomb);
            $this->assertStringNotContainsString('lollol', $document->saveXML());
        } catch (\RuntimeException) {
            $this->addToAssertionCount(1); // refused outright is equally fine
        }

        $this->expectException(\RuntimeException::class);
        SafeXml::load('<root><unclosed></root>');
    }

    public function test_search_normalization_keeps_letters_and_drops_marks(): void
    {
        $this->assertSame('שומר המגדלור', SearchNormalizer::normalize('שׁוֹמֵר הַמִּגְדַּלּוֹר'));
        $this->assertSame('בן גוריון', SearchNormalizer::normalize('בֶּן־גּוּרִיּוֹן'));      // maqaf joins words
        $this->assertSame('елка еж', SearchNormalizer::normalize('Ёлка, ЁЖ!'));
        $this->assertSame('йод', SearchNormalizer::normalize('Йод'));                           // й is a letter, not е + mark
        $this->assertSame('cafe naive', SearchNormalizer::normalize('Café naïve'));
        $this->assertSame('978 1 23456 789 7', SearchNormalizer::normalize('978-1-23456-789-7'));
        $this->assertSame('', SearchNormalizer::normalize(" \t—!? "));
        $this->assertSame(['a', 'b'], SearchNormalizer::tokens('a b a'));
        $this->assertCount(8, SearchNormalizer::tokens('1 2 3 4 5 6 7 8 9 10 11 12'));
    }
}
