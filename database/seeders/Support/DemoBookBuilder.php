<?php

namespace Database\Seeders\Support;

use RuntimeException;
use ZipArchive;

/**
 * Builds small, valid EPUB 3 and PDF files from plain arrays. Used for the
 * demonstration library (original texts written for this project) and for
 * test fixtures, so no third-party book ever needs to be committed.
 */
class DemoBookBuilder
{
    /**
     * @param array{
     *   title:string, language:string, direction?:string, author?:string, translator?:string, identifier:string,
     *   publisher?:string, description?:string, chapters:list<array{title:string, html:string}>,
     *   extra?:array<string, string>, manifestExtra?:string, layout?:string
     * } $book  `extra` adds raw archive entries (path => bytes); `html` is trusted here.
     */
    public static function epub(string $path, array $book): string
    {
        $language = $book['language'];
        $direction = $book['direction'] ?? 'ltr';
        $e = fn (string $text) => htmlspecialchars($text, ENT_QUOTES | ENT_XML1, 'UTF-8');

        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException("Cannot create $path");
        }

        // The mimetype entry must come first and be stored uncompressed.
        $zip->addFromString('mimetype', 'application/epub+zip');
        $zip->setCompressionName('mimetype', ZipArchive::CM_STORE);

        $zip->addFromString('META-INF/container.xml',
            '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<container version="1.0" xmlns="urn:oasis:names:tc:opendocument:xmlns:container"><rootfiles>'
            .'<rootfile full-path="OEBPS/content.opf" media-type="application/oebps-package+xml"/></rootfiles></container>');

        $css = "body { font-family: serif; line-height: 1.5; margin: 0 5%; }\n"
            ."h1 { font-size: 1.6em; margin: 1.2em 0 0.8em; }\n"
            ."p { margin: 0 0 0.9em; text-align: start; }\n"
            .".note { font-size: 0.9em; border-top: 1px solid #999; margin-top: 2em; padding-top: 0.5em; }\n";
        $zip->addFromString('OEBPS/style.css', $css);

        $manifest = '';
        $spine = '';
        $navItems = '';
        foreach ($book['chapters'] as $index => $chapter) {
            $n = $index + 1;
            $file = "chapter-$n.xhtml";
            $zip->addFromString("OEBPS/$file",
                '<?xml version="1.0" encoding="utf-8"?>'."\n".'<!DOCTYPE html>'."\n"
                .'<html xmlns="http://www.w3.org/1999/xhtml" xmlns:epub="http://www.idpf.org/2007/ops" lang="'.$language.'" xml:lang="'.$language.'" dir="'.$direction.'">'
                .'<head><meta charset="utf-8"/><title>'.$e($chapter['title']).'</title><link rel="stylesheet" type="text/css" href="style.css"/></head>'
                .'<body><section epub:type="chapter"><h1>'.$e($chapter['title']).'</h1>'.$chapter['html'].'</section></body></html>');
            $manifest .= '<item id="c'.$n.'" href="'.$file.'" media-type="application/xhtml+xml"/>';
            $spine .= '<itemref idref="c'.$n.'"/>';
            $navItems .= '<li><a href="'.$file.'">'.$e($chapter['title']).'</a></li>';
        }

        $zip->addFromString('OEBPS/nav.xhtml',
            '<?xml version="1.0" encoding="utf-8"?>'."\n".'<!DOCTYPE html>'."\n"
            .'<html xmlns="http://www.w3.org/1999/xhtml" xmlns:epub="http://www.idpf.org/2007/ops" lang="'.$language.'" dir="'.$direction.'">'
            .'<head><meta charset="utf-8"/><title>'.$e($book['title']).'</title></head>'
            .'<body><nav epub:type="toc"><h1>'.$e($book['title']).'</h1><ol>'.$navItems.'</ol></nav></body></html>');

        $layout = ($book['layout'] ?? '') === 'fixed' ? '<meta property="rendition:layout">pre-paginated</meta>' : '';
        $translator = isset($book['translator'])
            ? '<dc:contributor id="trl">'.$e($book['translator']).'</dc:contributor><meta refines="#trl" property="role" scheme="marc:relators">trl</meta>'
            : '';

        $zip->addFromString('OEBPS/content.opf',
            '<?xml version="1.0" encoding="utf-8"?>'."\n"
            .'<package xmlns="http://www.idpf.org/2007/opf" version="3.0" unique-identifier="uid" xml:lang="'.$language.'" dir="'.$direction.'">'
            .'<metadata xmlns:dc="http://purl.org/dc/elements/1.1/">'
            .'<dc:identifier id="uid">'.$e($book['identifier']).'</dc:identifier>'
            .'<dc:title>'.$e($book['title']).'</dc:title>'
            .'<dc:language>'.$language.'</dc:language>'
            .(isset($book['author']) ? '<dc:creator id="aut">'.$e($book['author']).'</dc:creator><meta refines="#aut" property="role" scheme="marc:relators">aut</meta>' : '')
            .$translator
            .(isset($book['publisher']) ? '<dc:publisher>'.$e($book['publisher']).'</dc:publisher>' : '')
            .(isset($book['description']) ? '<dc:description>'.$e($book['description']).'</dc:description>' : '')
            .'<meta property="dcterms:modified">2026-01-01T00:00:00Z</meta>'.$layout
            .'</metadata>'
            .'<manifest><item id="nav" href="nav.xhtml" media-type="application/xhtml+xml" properties="nav"/>'
            .'<item id="css" href="style.css" media-type="text/css"/>'.$manifest.($book['manifestExtra'] ?? '').'</manifest>'
            .'<spine'.($direction === 'rtl' ? ' page-progression-direction="rtl"' : '').'>'.$spine.'</spine>'
            .'</package>');

        foreach ($book['extra'] ?? [] as $entry => $bytes) {
            $zip->addFromString($entry, $bytes);
        }

        $zip->close();

        return $path;
    }

    /**
     * A minimal text PDF (Helvetica, Latin-1 text only) with one page per entry.
     *
     * @param  list<list<string>>  $pages  lines of text per page
     */
    public static function pdf(string $path, string $title, array $pages): string
    {
        $objects = [];
        $escape = fn (string $text) => strtr($text, ['\\' => '\\\\', '(' => '\\(', ')' => '\\)']);

        $pageCount = count($pages);
        $kids = [];
        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objects[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';

        $next = 4;
        foreach ($pages as $lines) {
            $stream = "BT\n/F1 13 Tf\n72 760 Td\n18 TL\n";
            foreach ($lines as $index => $line) {
                $stream .= ($index === 0 ? '/F1 18 Tf ' : ($index === 1 ? '/F1 13 Tf ' : '')).'('.$escape($line).") Tj T*\n";
            }
            $stream .= 'ET';

            $content = $next++;
            $page = $next++;
            $objects[$content] = '<< /Length '.strlen($stream)." >>\nstream\n".$stream."\nendstream";
            $objects[$page] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 3 0 R >> >> /Contents $content 0 R >>";
            $kids[] = "$page 0 R";
        }
        $info = $next++;
        $objects[2] = '<< /Type /Pages /Count '.$pageCount.' /Kids ['.implode(' ', $kids).'] >>';
        $objects[$info] = '<< /Title ('.$escape($title).') /Producer (Jewish Library demo builder) >>';
        ksort($objects);

        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        foreach ($objects as $number => $body) {
            $offsets[$number] = strlen($pdf);
            $pdf .= "$number 0 obj\n$body\nendobj\n";
        }
        $xref = strlen($pdf);
        $size = max(array_keys($objects)) + 1;
        $pdf .= "xref\n0 $size\n0000000000 65535 f \n";
        for ($i = 1; $i < $size; $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }
        $pdf .= "trailer\n<< /Size $size /Root 1 0 R /Info $info 0 R >>\nstartxref\n$xref\n%%EOF\n";

        if (file_put_contents($path, $pdf) === false) {
            throw new RuntimeException("Cannot write $path");
        }

        return $path;
    }
}
