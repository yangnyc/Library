import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

// Build-time only: production serves the compiled files in public/build.
// Nothing here runs on the hosting server.
export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/css/bootstrap.css',
                'resources/css/bootstrap-rtl.css',
                'resources/css/app.css',
                'resources/css/reader.css',
                'resources/js/app.ts',
                'resources/js/ui.ts',
                'resources/js/account.ts',
                'resources/js/offline-page.ts',
                'resources/js/reader/index.ts',
            ],
            refresh: false,
        }),
    ],
    build: {
        target: 'es2022',
        // Fonts stay as separate hashed files (cacheable, never inlined into CSS).
        assetsInlineLimit: 0,
        chunkSizeWarningLimit: 900,
    },
});
