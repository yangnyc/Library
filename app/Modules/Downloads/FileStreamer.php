<?php

namespace App\Modules\Downloads;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams a local file in small chunks with HEAD and single byte-range
 * support. The file is never read into memory as a whole, and the client only
 * ever sees the headers built here — never a filesystem path.
 */
class FileStreamer
{
    private const CHUNK = 262144;

    /**
     * @param  array<string, string>  $headers
     */
    public function stream(Request $request, string $absolutePath, string $mimeType, string $etag, array $headers = []): Response
    {
        if (! is_file($absolutePath) || ! is_readable($absolutePath)) {
            abort(404);
        }

        $size = filesize($absolutePath);
        $etag = '"'.$etag.'"';
        $base = $headers + [
            'Content-Type' => $mimeType,
            'Accept-Ranges' => 'bytes',
            'ETag' => $etag,
            'X-Content-Type-Options' => 'nosniff',
        ];

        if (in_array($etag, array_map('trim', explode(',', (string) $request->headers->get('If-None-Match'))), true)) {
            return response('', 304, array_diff_key($base, ['Content-Type' => 1]));
        }

        [$start, $end, $partial] = $this->range($request, $size, $etag);
        if ($start === null) {
            return response('', 416, $base + ['Content-Range' => "bytes */$size"]);
        }

        $length = $size === 0 ? 0 : $end - $start + 1;
        $base['Content-Length'] = (string) $length;
        if ($partial) {
            $base['Content-Range'] = "bytes $start-$end/$size";
        }
        $status = $partial ? 206 : 200;

        if ($request->isMethod('HEAD') || $length === 0) {
            return response('', $status, $base);
        }

        return new StreamedResponse(function () use ($absolutePath, $start, $length) {
            $handle = fopen($absolutePath, 'rb');
            if ($handle === false) {
                return;
            }
            // Output buffers would otherwise accumulate the whole file.
            // (Left alone under test, where the framework captures output.)
            while (! app()->runningUnitTests() && ob_get_level() > 0) {
                ob_end_flush();
            }
            fseek($handle, $start);
            $remaining = $length;
            while ($remaining > 0 && ! feof($handle) && ! connection_aborted()) {
                $chunk = fread($handle, min(self::CHUNK, $remaining));
                if ($chunk === false || $chunk === '') {
                    break;
                }
                echo $chunk;
                flush();
                $remaining -= strlen($chunk);
            }
            fclose($handle);
        }, $status, $base);
    }

    /**
     * @return array{0:?int, 1:int, 2:bool} start (null = unsatisfiable), end, partial
     */
    private function range(Request $request, int $size, string $etag): array
    {
        $header = $request->headers->get('Range');
        $ifRange = $request->headers->get('If-Range');

        // Multi-range requests are answered with the whole file, which RFC 9110 allows.
        if (! $header || $size === 0 || ($ifRange !== null && $ifRange !== $etag)
            || ! preg_match('/^bytes=(\d*)-(\d*)$/', trim($header), $match)) {
            return [0, max(0, $size - 1), false];
        }

        [$from, $to] = [$match[1], $match[2]];
        if ($from === '' && $to === '') {
            return [0, $size - 1, false];
        }

        if ($from === '') {               // suffix: last N bytes
            $suffix = (int) $to;
            if ($suffix === 0) {
                return [null, 0, false];
            }
            $start = max(0, $size - $suffix);
            $end = $size - 1;
        } else {
            $start = (int) $from;
            $end = $to === '' ? $size - 1 : min((int) $to, $size - 1);
        }

        if ($start >= $size || $start > $end) {
            return [null, 0, false];
        }

        return [$start, $end, true];
    }
}
