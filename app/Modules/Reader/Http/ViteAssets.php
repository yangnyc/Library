<?php

namespace App\Modules\Reader\Http;

/** Resolves the built files (scripts, styles, fonts) behind Vite entry points. */
class ViteAssets
{
    /**
     * @param  list<string>  $entries
     * @return list<string> root-relative URLs, including dynamic imports
     */
    public static function forEntries(array $entries): array
    {
        $path = public_path('build/manifest.json');
        if (! is_file($path)) {
            return [];
        }
        $manifest = json_decode((string) file_get_contents($path), true) ?: [];

        $urls = [];
        $seen = [];
        $visit = function (string $key) use (&$visit, &$urls, &$seen, $manifest) {
            if (isset($seen[$key]) || ! isset($manifest[$key])) {
                return;
            }
            $seen[$key] = true;
            $chunk = $manifest[$key];
            $urls[] = '/build/'.$chunk['file'];
            foreach ($chunk['css'] ?? [] as $css) {
                $urls[] = '/build/'.$css;
            }
            foreach ($chunk['assets'] ?? [] as $asset) {
                $urls[] = '/build/'.$asset;
            }
            foreach (array_merge($chunk['imports'] ?? [], $chunk['dynamicImports'] ?? []) as $import) {
                $visit($import);
            }
        };
        array_map($visit, $entries);

        return array_values(array_unique($urls));
    }
}
