// Development probe: opens a reader page, reports console errors and what the
// book frame contains, and saves a screenshot.
//   node scripts/probe-reader.mjs <path> [chromium|firefox|webkit] [out.png]
import { chromium, firefox, webkit } from '@playwright/test';

const [path = '/en/read/lighthouse-keepers-almanac-en', engineName = 'chromium', out = 'storage/probe.png'] = process.argv.slice(2);
const engine = { chromium, firefox, webkit }[engineName];
const browser = await engine.launch();
const page = await browser.newPage({ viewport: { width: 1100, height: 760 } });

const problems = [];
page.on('console', (message) => {
    if (['error', 'warning'].includes(message.type())) problems.push(`${message.type()}: ${message.text()}`);
});
page.on('pageerror', (error) => problems.push('pageerror: ' + error.message));
page.on('requestfailed', (request) => problems.push(`failed: ${request.url()} ${request.failure()?.errorText}`));
page.on('response', (response) => {
    if (response.status() >= 400) problems.push(`${response.status()}: ${response.url()}`);
});

await page.goto('http://127.0.0.1:8000' + path, { waitUntil: 'load' });
try {
    await page.waitForSelector('body[data-ready="true"]', { timeout: 60000 });
} catch {
    problems.push('reader did not become ready');
}
await page.waitForTimeout(1500);

const info = await page.evaluate(() => {
    const frame = document.querySelector('#viewer iframe');
    const doc = frame?.contentDocument;
    return {
        position: document.querySelector('[data-position]')?.textContent,
        stageDir: document.querySelector('.rstage')?.getAttribute('dir'),
        sandbox: frame?.getAttribute('sandbox') ?? null,
        frames: document.querySelectorAll('#viewer iframe').length,
        text: (doc?.body?.textContent ?? '').trim().slice(0, 90),
        htmlLang: doc?.documentElement.getAttribute('lang') ?? null,
        htmlDir: doc?.documentElement.getAttribute('dir') ?? null,
        scripts: doc ? doc.querySelectorAll('script').length : null,
        pdfCanvas: document.querySelectorAll('#viewer canvas').length,
        pdfSpans: document.querySelectorAll('#viewer .pdf-text span').length,
        errorShown: !document.querySelector('[data-error]')?.hidden,
    };
});

await page.screenshot({ path: out });
console.log(JSON.stringify(info, null, 2));
console.log(problems.length ? problems.join('\n') : 'no console problems');
await browser.close();
