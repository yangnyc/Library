<?php

namespace App\Modules\Imports\Sanitizing;

use DOMDocument;
use RuntimeException;

/**
 * XML parsing for untrusted publication files.
 *
 * The document type declaration is removed before parsing, so there are no
 * internal or external entities to expand, and network access is disabled.
 */
class SafeXml
{
    // No LIBXML_NOENT (would substitute entities), no LIBXML_DTDLOAD.
    public const FLAGS = LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT;

    public static function prepare(string $source): string
    {
        if (strlen($source) > config('library.imports.xml_max_bytes')) {
            throw new RuntimeException('An XML document in the file is too large.');
        }

        // UTF-16 with a BOM is legal XML; everything is handled as UTF-8 from here.
        if (str_starts_with($source, "\xFF\xFE") || str_starts_with($source, "\xFE\xFF")) {
            $source = mb_convert_encoding($source, 'UTF-8', 'UTF-16');
            $source = preg_replace('/(<\?xml[^>]*encoding=)(["\'])[^"\']*\2/i', '$1$2utf-8$2', $source, 1) ?? $source;
        }
        $source = preg_replace('/^\xEF\xBB\xBF/', '', $source) ?? $source;

        // Drop the DOCTYPE including any internal subset: <!DOCTYPE x [ ... ]>
        $source = preg_replace('/<!DOCTYPE[^>\[]*(\[[^\]]*\])?[^>]*>/is', '', $source) ?? $source;

        if (stripos($source, '<!ENTITY') !== false || stripos($source, '<!DOCTYPE') !== false) {
            throw new RuntimeException('Entity declarations are not accepted.');
        }

        return $source;
    }

    public static function load(string $source): DOMDocument
    {
        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        try {
            $loaded = $document->loadXML(self::prepare($source), self::FLAGS);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if (! $loaded || ! $document->documentElement) {
            throw new RuntimeException('The XML could not be parsed.');
        }

        return $document;
    }
}
