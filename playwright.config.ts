import { defineConfig, devices } from '@playwright/test';

// Browser tests run against an already running instance with the demo library
// seeded (see TEST_REPORT.md):
//   php artisan migrate:fresh --seed && php artisan db:seed --class=DemoLibrarySeeder
//   php artisan serve
// One worker: `artisan serve` handles requests one at a time on Windows.
export default defineConfig({
    testDir: 'tests/e2e',
    timeout: 120_000,
    expect: { timeout: 30_000 },
    workers: 1,
    retries: 0,
    reporter: [['list']],
    use: {
        baseURL: process.env.E2E_BASE_URL ?? 'http://127.0.0.1:8000',
        trace: 'retain-on-failure',
        screenshot: 'only-on-failure',
    },
    outputDir: 'storage/e2e-results',
    projects: [
        { name: 'chromium', use: { ...devices['Desktop Chrome'] } },
        { name: 'firefox', use: { ...devices['Desktop Firefox'] } },
        { name: 'webkit', use: { ...devices['Desktop Safari'] } },
        { name: 'mobile-chromium', use: { ...devices['Pixel 7'] }, testMatch: /mobile|reader-epub/ },
        { name: 'mobile-webkit', use: { ...devices['iPhone 14'] }, testMatch: /mobile|reader-epub/ },
    ],
});
