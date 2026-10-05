<?php

namespace App\Modules\Imports\Sanitizing;

/**
 * Removes the parts of publisher CSS that can load remote resources, run
 * script in legacy engines or reach outside the publication.
 */
class CssSanitizer
{
    public function sanitize(string $css): string
    {
        // Strip comments first so they cannot be used to hide or split tokens.
        $css = preg_replace('#/\*.*?\*/#s', '', $css) ?? '';

        // CSS escapes (u\72l, \75 rl …) could spell a blocked keyword. Decode
        // hexadecimal escapes to the characters they stand for, then drop any
        // remaining backslashes, so the checks below see what a browser would.
        $css = preg_replace_callback(
            '/\\\\([0-9a-fA-F]{1,6})[ \t\n\r\f]?/',
            fn (array $m) => mb_chr(min(hexdec($m[1]), 0x10FFFF), 'UTF-8') ?: '',
            $css
        ) ?? '';
        $css = preg_replace('/\\\\(?![\'"])/', '', $css) ?? '';

        // @import: only relative stylesheet references survive.
        $css = preg_replace_callback(
            '/@import\s+(?:url\(\s*)?["\']?([^"\')\s;]+)["\']?\s*\)?[^;]*;?/i',
            fn (array $m) => $this->isLocalReference($m[1]) ? '@import url("'.$m[1].'");' : '',
            $css
        ) ?? '';

        $css = preg_replace_callback(
            '/url\(\s*(["\']?)(.*?)\1\s*\)/is',
            fn (array $m) => $this->isSafeUrl(trim($m[2])) ? 'url("'.str_replace('"', '%22', trim($m[2])).'")' : 'none',
            $css
        ) ?? '';

        // Legacy script vectors and bindings.
        $css = preg_replace('/expression\s*\(|-moz-binding\s*:|behavior\s*:|javascript\s*:|vbscript\s*:/i', 'blocked:', $css) ?? '';

        // A style block must never be able to close its own element.
        return str_ireplace(['</style', '<!--', '-->', '<![CDATA[', ']]>'], '', $css);
    }

    /** Sanitizes the value of a style="" attribute. */
    public function sanitizeDeclarations(string $style): string
    {
        return trim(str_replace(['{', '}'], '', $this->sanitize($style)));
    }

    private function isSafeUrl(string $url): bool
    {
        if (preg_match('#^data:(image/(png|jpeg|gif|webp)|font/(woff2?|ttf|otf)|application/(font-woff2?|x-font-ttf|vnd\.ms-opentype));#i', $url)) {
            return true;
        }

        return $this->isLocalReference($url);
    }

    private function isLocalReference(string $url): bool
    {
        if ($url === '' || str_starts_with($url, '#')) {
            return $url !== '';
        }

        // No scheme, no protocol-relative URL, no absolute path, no backslashes.
        return ! preg_match('#^([a-z][a-z0-9+.\-]*:|//|/|\\\\)#i', $url) && ! str_contains($url, '\\');
    }
}
