<?php

namespace App\Modules\Imports\Services;

use App\Modules\Imports\ImportRejected;
use App\Modules\Imports\Sanitizing\CssSanitizer;
use App\Modules\Imports\Sanitizing\MarkupSanitizer;
use App\Modules\Imports\Sanitizing\SafeXml;
use DOMDocument;
use DOMElement;
use DOMXPath;
use ZipArchive;

/**
 * Validates an EPUB archive and writes a sanitized, extracted reading copy.
 *
 * Nothing from the archive is ever written under its own name without the
 * path being normalized and the type being allow-listed, and nothing is
 * executed. The uploaded file itself is left untouched for download.
 */
class EpubProcessor
{
    private const FONT_OBFUSCATION = ['http://www.idpf.org/2008/embedding', 'http://ns.adobe.com/pdf/enc#RC'];

    /** media type => [handler, allowed extensions] */
    private const TYPES = [
        'application/xhtml+xml' => ['xhtml', ['xhtml', 'html', 'htm', 'xml']],
        'text/css' => ['css', ['css']],
        'image/svg+xml' => ['svg', ['svg']],
        'image/png' => ['image', ['png']],
        'image/jpeg' => ['image', ['jpg', 'jpeg']],
        'image/gif' => ['image', ['gif']],
        'image/webp' => ['image', ['webp']],
        'application/x-dtbncx+xml' => ['xml', ['ncx']],
        'font/ttf' => ['font', ['ttf']],
        'font/otf' => ['font', ['otf', 'ttf']],
        'font/woff' => ['font', ['woff']],
        'font/woff2' => ['font', ['woff2']],
        'application/font-woff' => ['font', ['woff']],
        'application/font-sfnt' => ['font', ['ttf', 'otf']],
        'application/vnd.ms-opentype' => ['font', ['otf', 'ttf']],
        'application/x-font-ttf' => ['font', ['ttf']],
        'application/x-font-opentype' => ['font', ['otf']],
    ];

    public function __construct(
        private readonly MarkupSanitizer $markup = new MarkupSanitizer,
        private readonly CssSanitizer $css = new CssSanitizer,
    ) {}

    /**
     * Checks the archive against the resource limits and reads the package.
     *
     * @return array{opf:string, metadata:array, layout:string, direction:?string, items:list<array>, cover:?string, warnings:list<string>}
     */
    public function inspect(string $path): array
    {
        $zip = $this->open($path);
        try {
            $warnings = [];
            $names = $this->checkArchive($zip, $warnings);

            $mimetype = $zip->getFromName('mimetype', 64);
            if ($mimetype === false || trim($mimetype) !== 'application/epub+zip') {
                throw new ImportRejected('The file is not an EPUB: the mimetype entry is missing or wrong.');
            }
            if ($zip->getNameIndex(0) !== 'mimetype') {
                $warnings[] = 'The mimetype entry is not the first entry in the archive. Some reading systems are strict about this.';
            }

            $obfuscated = $this->checkEncryption($zip, $warnings);

            $container = $this->readXml($zip, 'META-INF/container.xml', 'The EPUB has no META-INF/container.xml.');
            $opfPath = null;
            foreach ($container->getElementsByTagNameNS('*', 'rootfile') as $rootfile) {
                $opfPath = $this->normalize(rawurldecode($rootfile->getAttribute('full-path')));
                break;
            }
            if (! $opfPath || ! isset($names[$opfPath])) {
                throw new ImportRejected('The EPUB package document named in container.xml was not found.');
            }

            $opf = $this->readXml($zip, $opfPath, 'The package document could not be read.');
            $xpath = new DOMXPath($opf);
            $xpath->registerNamespace('opf', 'http://www.idpf.org/2007/opf');
            $xpath->registerNamespace('dc', 'http://purl.org/dc/elements/1.1/');

            $metadata = $this->readMetadata($xpath);
            $layout = strtolower(trim($this->first($xpath, '//opf:metadata/opf:meta[@property="rendition:layout"]') ?? ''));
            $layout = $layout === 'pre-paginated' ? 'fixed' : 'reflowable';
            if ($layout === 'fixed') {
                $warnings[] = 'This is a fixed-layout EPUB. Font and spacing controls will not apply; readers get a fallback and the download.';
            }

            $spine = $xpath->query('//opf:spine')->item(0);
            $direction = $spine instanceof DOMElement ? strtolower($spine->getAttribute('page-progression-direction')) : '';
            $direction = in_array($direction, ['ltr', 'rtl'], true) ? $direction : null;

            $base = str_contains($opfPath, '/') ? substr($opfPath, 0, strrpos($opfPath, '/') + 1) : '';
            $items = [];
            $cover = null;
            $coverId = $this->first($xpath, '//opf:metadata/opf:meta[@name="cover"]/@content');
            $scripted = false;

            foreach ($xpath->query('//opf:manifest/opf:item') as $item) {
                /** @var DOMElement $item */
                $href = $this->normalize($base.rawurldecode(strtok($item->getAttribute('href'), '#')));
                $type = strtolower(trim($item->getAttribute('media-type')));
                $properties = preg_split('/\s+/', strtolower($item->getAttribute('properties')), -1, PREG_SPLIT_NO_EMPTY);
                $scripted = $scripted || in_array('scripted', $properties, true);

                if ($href === null) {
                    $warnings[] = 'A manifest entry points outside the publication and was ignored.';

                    continue;
                }
                if (in_array('cover-image', $properties, true) || ($coverId && $item->getAttribute('id') === $coverId)) {
                    $cover = $href;
                }
                $items[] = [
                    'path' => $href,
                    'type' => $type,
                    'present' => isset($names[$href]),
                    'obfuscated' => in_array($href, $obfuscated, true),
                ];
            }

            if ($scripted) {
                $warnings[] = 'The publication declares scripted content. Scripts are removed, so interactive features will not work.';
            }
            if ($xpath->query('//opf:spine/opf:itemref')->length === 0) {
                throw new ImportRejected('The EPUB has an empty reading order (spine).');
            }

            return [
                'opf' => $opfPath,
                'metadata' => $metadata,
                'layout' => $layout,
                'direction' => $direction,
                'items' => $items,
                'cover' => $cover,
                'warnings' => $warnings,
            ];
        } finally {
            $zip->close();
        }
    }

    /**
     * Writes reading-copy resources starting at $cursor until done or until
     * the deadline passes, so a large book is processed over several short
     * queue runs instead of one long request.
     *
     * @param  array  $package  result of inspect()
     * @param  array{files:list<array>, notes:list<string>}  $state  carried between runs
     * @return ?int next cursor, or null when extraction is complete
     */
    public function extract(string $path, string $targetDir, array $package, int $cursor, float $deadline, array &$state): ?int
    {
        $zip = $this->open($path);
        try {
            if ($cursor === 0) {
                $this->write($targetDir, 'META-INF/container.xml', $this->containerXml($package['opf']), $state);
                $opf = $this->readXml($zip, $package['opf'], 'The package document could not be read.');
                $this->write($targetDir, $package['opf'], $opf->saveXML(), $state);
            }

            $items = $package['items'];
            for ($i = $cursor, $count = count($items); $i < $count; $i++) {
                // Checked after the first item, so every run makes progress
                // even if it starts late.
                if ($i > $cursor && microtime(true) > $deadline) {
                    return $i;
                }
                $this->extractItem($zip, $targetDir, $items[$i], $state);
            }

            $state['notes'] = array_values(array_unique(array_merge($state['notes'], $this->markup->notes())));

            return null;
        } finally {
            $zip->close();
        }
    }

    /** Raw bytes of the cover image, size-capped, or null. */
    public function coverBytes(string $path, ?string $coverPath): ?string
    {
        if (! $coverPath) {
            return null;
        }
        $zip = $this->open($path);
        try {
            $bytes = $zip->getFromName($coverPath, config('library.imports.max_cover_bytes') + 1);

            return $bytes !== false && strlen($bytes) <= config('library.imports.max_cover_bytes') ? $bytes : null;
        } finally {
            $zip->close();
        }
    }

    private function extractItem(ZipArchive $zip, string $targetDir, array $item, array &$state): void
    {
        $path = $item['path'];
        if (! $item['present']) {
            $state['notes'][] = "Listed in the manifest but missing from the archive: $path";

            return;
        }

        [$handler, $extensions] = self::TYPES[$item['type']] ?? [null, []];
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if ($handler === null || ! in_array($extension, $extensions, true)) {
            $state['notes'][] = "Not included in the reading copy (type {$item['type']}): $path";

            return;
        }
        if ($item['obfuscated']) {
            $state['notes'][] = "Obfuscated font left out; a reader font is used instead: $path";

            return;
        }

        $limit = in_array($handler, ['xhtml', 'css', 'svg', 'xml'], true)
            ? config('library.imports.xml_max_bytes')
            : config('library.imports.zip_max_entry_bytes');
        $bytes = $zip->getFromName($path, $limit + 1);
        if ($bytes === false || strlen($bytes) > $limit) {
            throw new ImportRejected("A resource is larger than allowed: $path");
        }

        try {
            $clean = match ($handler) {
                'xhtml' => $this->markup->sanitizeXhtml($bytes),
                'svg' => $this->markup->sanitizeSvg($bytes),
                'css' => $this->css->sanitize($bytes),
                'xml' => SafeXml::load($bytes)->saveXML(),
                'image' => $this->checkedImage($bytes, $item['type'], $path),
                'font' => $bytes,
            };
        } catch (ImportRejected $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ImportRejected("Could not process $path: ".$e->getMessage());
        }

        $this->write($targetDir, $path, $clean, $state, $item['type']);
    }

    private function checkedImage(string $bytes, string $type, string $path): string
    {
        $info = @getimagesizefromstring($bytes);
        if ($info === false || $info['mime'] !== $type) {
            throw new ImportRejected("An image does not match its declared type: $path");
        }
        if (config('library.imports.max_cover_pixels') * 2 < $info[0] * $info[1]) {
            throw new ImportRejected("An image has unreasonably large dimensions: $path");
        }

        return $bytes;
    }

    private function write(string $targetDir, string $relative, string $contents, array &$state, ?string $type = null): void
    {
        $relative = $this->normalize($relative);
        if ($relative === null || $this->isHidden($relative)) {
            throw new ImportRejected('A resource path points outside the publication.');
        }

        $full = $targetDir.'/'.$relative;
        $directory = dirname($full);
        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new \RuntimeException('Could not create a reading-copy directory.');
        }
        if (file_put_contents($full, $contents) === false) {
            throw new \RuntimeException('Could not write a reading-copy file.');
        }

        $state['files'][] = ['path' => $relative, 'bytes' => strlen($contents)];
    }

    private function open(string $path): ZipArchive
    {
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            throw new ImportRejected('The file is not a readable ZIP/EPUB archive.');
        }

        return $zip;
    }

    /**
     * @param  list<string>  $warnings
     * @return array<string, true> normalized entry names
     */
    private function checkArchive(ZipArchive $zip, array &$warnings): array
    {
        $limits = config('library.imports');
        if ($zip->numFiles > $limits['zip_max_entries']) {
            throw new ImportRejected('The archive has too many entries.');
        }

        $names = [];
        $lower = [];
        $total = 0;
        $compressed = 0;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if ($stat === false) {
                throw new ImportRejected('The archive directory is damaged.');
            }
            $name = $stat['name'];

            if (str_ends_with($name, '/')) {
                continue; // directory entry
            }
            $normalized = $this->normalize($name);
            if ($normalized === null || $normalized !== $name) {
                throw new ImportRejected('The archive contains an unsafe entry path.');
            }
            if ($this->isHidden($normalized)) {
                $warnings[] = 'Hidden files in the archive were ignored.';
                $total += $stat['size'];

                continue;
            }

            if ($zip->getExternalAttributesIndex($i, $opsys, $attributes)
                && $opsys === ZipArchive::OPSYS_UNIX && ((($attributes >> 16) & 0xF000) === 0xA000)) {
                throw new ImportRejected('The archive contains a symbolic link.');
            }
            if (($stat['encryption_method'] ?? 0) !== 0) {
                throw new ImportRejected('The archive is password-protected or encrypted.');
            }
            if ($stat['size'] > $limits['zip_max_entry_bytes']) {
                throw new ImportRejected('An entry in the archive is larger than allowed.');
            }
            if ($stat['size'] > 1048576 && $stat['comp_size'] > 0 && $limits['zip_max_ratio'] < $stat['size'] / $stat['comp_size']) {
                throw new ImportRejected('An entry in the archive has a suspicious compression ratio.');
            }

            $key = mb_strtolower($normalized);
            if (isset($lower[$key])) {
                throw new ImportRejected('The archive contains duplicate entry names.');
            }
            $lower[$key] = true;
            $names[$normalized] = true;
            $total += $stat['size'];
            $compressed += $stat['comp_size'];

            if ($total > $limits['zip_max_expanded_bytes']) {
                throw new ImportRejected('The archive expands to more than the allowed size.');
            }
        }

        if ($compressed > 0 && $total > 10 * 1048576 && $total / $compressed > $limits['zip_max_ratio']) {
            throw new ImportRejected('The archive has a suspicious overall compression ratio.');
        }

        return $names;
    }

    /** @return list<string> paths of obfuscated fonts */
    private function checkEncryption(ZipArchive $zip, array &$warnings): array
    {
        if ($zip->locateName('META-INF/encryption.xml') === false) {
            return [];
        }

        $document = $this->readXml($zip, 'META-INF/encryption.xml', 'The encryption description could not be read.');
        $obfuscated = [];
        foreach ($document->getElementsByTagNameNS('*', 'EncryptedData') as $data) {
            $method = $data->getElementsByTagNameNS('*', 'EncryptionMethod')->item(0);
            $algorithm = $method instanceof DOMElement ? $method->getAttribute('Algorithm') : '';
            if (! in_array($algorithm, self::FONT_OBFUSCATION, true)) {
                throw new ImportRejected('The EPUB contains encrypted (DRM-protected) content and cannot be imported.');
            }
            $reference = $data->getElementsByTagNameNS('*', 'CipherReference')->item(0);
            if ($reference instanceof DOMElement && ($path = $this->normalize(rawurldecode($reference->getAttribute('URI'))))) {
                $obfuscated[] = $path;
            }
        }
        if ($obfuscated) {
            $warnings[] = 'The EPUB uses obfuscated fonts. They are left out of the browser reading copy.';
        }

        return $obfuscated;
    }

    private function readXml(ZipArchive $zip, string $name, string $error): DOMDocument
    {
        $limit = config('library.imports.xml_max_bytes');
        $bytes = $zip->getFromName($name, $limit + 1);
        if ($bytes === false || strlen($bytes) > $limit) {
            throw new ImportRejected($error);
        }

        try {
            return SafeXml::load($bytes);
        } catch (\Throwable $e) {
            throw new ImportRejected($error.' '.$e->getMessage());
        }
    }

    private function readMetadata(DOMXPath $xpath): array
    {
        $creators = [];
        foreach ($xpath->query('//opf:metadata/dc:creator') as $creator) {
            /** @var DOMElement $creator */
            $role = $creator->getAttributeNS('http://www.idpf.org/2007/opf', 'role');
            if ($role === '' && $creator->getAttribute('id') !== '') {
                $role = $this->first($xpath, '//opf:metadata/opf:meta[@refines="#'.$creator->getAttribute('id').'"][@property="role"]') ?? '';
            }
            $name = trim($creator->textContent);
            if ($name !== '') {
                $creators[] = ['name' => mb_substr($name, 0, 255), 'role' => $role === 'trl' ? 'translator' : 'author'];
            }
        }
        foreach ($xpath->query('//opf:metadata/dc:contributor') as $contributor) {
            /** @var DOMElement $contributor */
            $role = $contributor->getAttributeNS('http://www.idpf.org/2007/opf', 'role');
            if ($role === 'trl' && trim($contributor->textContent) !== '') {
                $creators[] = ['name' => mb_substr(trim($contributor->textContent), 0, 255), 'role' => 'translator'];
            }
        }

        $clip = fn (?string $value, int $max) => $value === null ? null : mb_substr(trim($value), 0, $max);

        return array_filter([
            'title' => $clip($this->first($xpath, '//opf:metadata/dc:title'), 255),
            'language' => $clip($this->first($xpath, '//opf:metadata/dc:language'), 35),
            'identifier' => $clip($this->first($xpath, '//opf:metadata/dc:identifier'), 255),
            'publisher' => $clip($this->first($xpath, '//opf:metadata/dc:publisher'), 255),
            'date' => $clip($this->first($xpath, '//opf:metadata/dc:date'), 20),
            'description' => $clip(strip_tags((string) $this->first($xpath, '//opf:metadata/dc:description')), 5000),
            'rights' => $clip($this->first($xpath, '//opf:metadata/dc:rights'), 1000),
            'contributors' => $creators,
        ], fn ($value) => $value !== null && $value !== '' && $value !== []);
    }

    private function first(DOMXPath $xpath, string $expression): ?string
    {
        $node = $xpath->query($expression)->item(0);
        $value = $node ? trim($node->textContent) : '';

        return $value === '' ? null : $value;
    }

    private function containerXml(string $opfPath): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<container version="1.0" xmlns="urn:oasis:names:tc:opendocument:xmlns:container"><rootfiles>'
            .'<rootfile full-path="'.htmlspecialchars($opfPath, ENT_QUOTES | ENT_XML1).'" media-type="application/oebps-package+xml"/>'
            .'</rootfiles></container>';
    }

    /**
     * Normalizes an archive path. Returns null for anything that is absolute,
     * escapes the archive root or contains characters that have no place in a
     * publication path.
     */
    private function normalize(string $path): ?string
    {
        if ($path === '' || strlen($path) > 512 || str_contains($path, "\0") || str_contains($path, '\\')
            || str_starts_with($path, '/') || preg_match('/^[a-zA-Z]:/', $path) || preg_match('/[\x00-\x1F\x7F]/', $path)) {
            return null;
        }

        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                if ($segments === []) {
                    return null;
                }
                array_pop($segments);

                continue;
            }
            $segments[] = $segment;
        }

        return $segments === [] ? null : implode('/', $segments);
    }

    /** Hidden files (.htaccess, .user.ini, .DS_Store …) are never part of a book. */
    private function isHidden(string $path): bool
    {
        return (bool) preg_match('#(^|/)(\.|__MACOSX/)#', $path);
    }
}
