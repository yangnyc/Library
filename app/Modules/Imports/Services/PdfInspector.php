<?php

namespace App\Modules\Imports\Services;

use App\Modules\Imports\ImportRejected;

/**
 * Structural checks for an uploaded PDF without a PDF library or binary.
 *
 * This confirms the file is a PDF, is not encrypted and reports what it can
 * about it. It cannot tell whether pages are scans: the browser reader checks
 * for a text layer at reading time and editors can record it explicitly.
 */
class PdfInspector
{
    /** @return array{page_count:?int, metadata:array, warnings:list<string>} */
    public function inspect(string $path): array
    {
        $size = filesize($path);
        $handle = fopen($path, 'rb');
        try {
            $head = (string) fread($handle, 1024);
            if (! preg_match('/%PDF-(\d\.\d)/', $head, $version)) {
                throw new ImportRejected('The file is not a PDF.');
            }

            fseek($handle, max(0, $size - 2048));
            $tail = (string) fread($handle, 2048);
            if (! str_contains($tail, '%%EOF')) {
                throw new ImportRejected('The PDF is incomplete: it has no end-of-file marker.');
            }

            $warnings = [];
            $pages = 0;
            $sawPageTree = false;
            $title = null;
            $carry = '';
            rewind($handle);

            // Scan in chunks so a large PDF never has to fit in memory.
            while (! feof($handle)) {
                $chunk = $carry.fread($handle, 1048576);
                $carry = substr($chunk, -256);

                if (preg_match('/\/Encrypt\s+\d+\s+\d+\s+R|\/Encrypt\s*<</', $chunk)) {
                    throw new ImportRejected('The PDF is encrypted or password-protected and cannot be imported.');
                }
                if (! in_array('js', $warnings, true) && preg_match('/\/(JavaScript|JS)\b/', $chunk)) {
                    $warnings[] = 'js';
                }
                if (! in_array('launch', $warnings, true) && preg_match('/\/(Launch|EmbeddedFile)\b/', $chunk)) {
                    $warnings[] = 'launch';
                }
                if (preg_match_all('/\/Type\s*\/Pages\b[^>]*?\/Count\s+(\d+)/s', $chunk, $counts)) {
                    $sawPageTree = true;
                    $pages = max($pages, ...array_map('intval', $counts[1]));
                }
                if ($title === null && preg_match('/\/Title\s*\(((?:[^()\\\\]|\\\\.){1,300})\)/s', $chunk, $match)) {
                    $candidate = stripcslashes($match[1]);
                    if (mb_check_encoding($candidate, 'UTF-8') && ! str_starts_with($candidate, "\xFE\xFF")) {
                        $title = trim($candidate);
                    }
                }
            }
        } finally {
            fclose($handle);
        }

        $messages = [];
        if (in_array('js', $warnings, true)) {
            $messages[] = 'The PDF contains JavaScript. The browser reader never runs it, but the downloaded file still contains it.';
        }
        if (in_array('launch', $warnings, true)) {
            $messages[] = 'The PDF contains embedded files or launch actions. Review the file before allowing downloads.';
        }
        if (! $sawPageTree) {
            $messages[] = 'The page count could not be determined at import; the reader will report it.';
        }
        $messages[] = 'Check whether this PDF is a scan. If it has no text layer, mark it so readers are told that search and selection are unavailable.';

        return [
            'page_count' => $sawPageTree && $pages > 0 ? $pages : null,
            'metadata' => array_filter(['title' => $title, 'pdf_version' => $version[1]]),
            'warnings' => $messages,
        ];
    }
}
