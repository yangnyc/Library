import { expect, test } from '@playwright/test';
import { hasHorizontalOverflow } from './helpers';

for (const locale of ['en', 'ru', 'he']) {
    test(`${locale} library remains usable on narrow screens`, async ({ page }, testInfo) => {
        await page.goto(`/${locale}`);
        await expect(page.locator('html')).toHaveAttribute('dir', locale === 'he' ? 'rtl' : 'ltr');
        await expect(page.locator('#home-q')).toBeVisible();
        expect(await hasHorizontalOverflow(page)).toBe(false);
        if (testInfo.project.name === 'chromium') {
            await page.evaluate(() => document.fonts.ready);
            await page.screenshot({ path: testInfo.outputPath('home-desktop.png'), fullPage: true });
        }

        await page.setViewportSize({ width: 320, height: 720 });
        expect(await hasHorizontalOverflow(page)).toBe(false);
        if (testInfo.project.name === 'chromium') {
            await page.screenshot({ path: testInfo.outputPath('home-mobile.png'), fullPage: true });
        }

        const menu = page.locator('[data-nav-toggle]');
        if (await menu.isVisible()) {
            await menu.click();
            await expect(menu).toHaveAttribute('aria-expanded', 'true');
        }
        await page.locator('#site-nav').getByRole('link').filter({ hasText: /^(Catalog|Каталог|קטלוג)$/ }).click();
        await expect(page).toHaveURL(new RegExp(`/${locale}/catalog$`));
        const filters = page.locator('[data-catalog-filters]');
        await expect(filters).not.toHaveAttribute('open');
        await filters.locator('summary').click();
        await expect(filters).toHaveAttribute('open');
        await expect(page.locator('#q')).toBeVisible();
        expect(await hasHorizontalOverflow(page)).toBe(false);
        if (testInfo.project.name === 'chromium') {
            await page.screenshot({ path: testInfo.outputPath('catalog-mobile.png'), fullPage: true });
            await page.setViewportSize({ width: 1280, height: 800 });
            await page.screenshot({ path: testInfo.outputPath('catalog-desktop.png'), fullPage: true });
        }
    });
}

test('homepage search opens matching catalog results', async ({ page }) => {
    await page.goto('/en');
    await page.getByRole('searchbox').fill('lighthouse');
    await page.getByRole('button', { name: 'Search', exact: true }).click();

    await expect(page.locator('#q')).toHaveValue('lighthouse');
    await expect(page.locator('.card__title').first()).toBeVisible();
});

test('header search reaches the catalog from other library pages', async ({ page }) => {
    await page.goto('/en/collections');
    await page.locator('.header-search').getByRole('searchbox').fill('lighthouse');
    await page.locator('.header-search').getByRole('button', { name: 'Search', exact: true }).click();

    await expect(page.locator('#q')).toHaveValue('lighthouse');
    await expect(page.locator('.card__title').first()).toBeVisible();
});

test('theme preview and saved preference survive navigation', async ({ page }, testInfo) => {
    await page.goto('/en/settings');
    await page.getByLabel('Colour theme').selectOption('noir');
    await expect(page.locator('body')).toHaveAttribute('data-ui-theme', 'noir');
    await page.getByRole('button', { name: 'Save', exact: true }).click();
    await expect(page.getByRole('status')).toContainText('Settings saved');

    await page.goto('/en/catalog');
    await expect(page.locator('body')).toHaveAttribute('data-ui-theme', 'noir');
    if (testInfo.project.name === 'chromium') {
        await page.screenshot({ path: testInfo.outputPath('catalog-dark.png'), fullPage: true });
        await page.goto('/en');
        await page.screenshot({ path: testInfo.outputPath('home-dark.png'), fullPage: true });
    }
});

test('edition, shelf and forms fit narrow screens', async ({ page }, testInfo) => {
    for (const [name, path] of [
        ['edition', '/en/editions/lighthouse-keepers-almanac-en'],
        ['offline', '/en/offline'],
        ['login', '/login'],
        ['settings', '/en/settings'],
    ]) {
        await page.setViewportSize({ width: 1280, height: 800 });
        await page.goto(path);
        await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
        if (testInfo.project.name === 'chromium') {
            await page.screenshot({ path: testInfo.outputPath(`${name}-desktop.png`), fullPage: true });
        }
        await page.setViewportSize({ width: 320, height: 720 });
        expect(await hasHorizontalOverflow(page), `${name} should fit a narrow screen`).toBe(false);
        if (testInfo.project.name === 'chromium') {
            await page.screenshot({ path: testInfo.outputPath(`${name}-mobile.png`), fullPage: true });
        }
    }
    await page.getByLabel('Colour theme').selectOption('contrast');
    await page.getByRole('button', { name: 'Save', exact: true }).click();
    await expect(page.getByRole('status')).toContainText('Settings saved');
    await page.goto('/en');
    await expect(page.locator('body')).toHaveAttribute('data-ui-theme', 'contrast');
    expect(await hasHorizontalOverflow(page)).toBe(false);
    if (testInfo.project.name === 'chromium') {
        await page.setViewportSize({ width: 1280, height: 800 });
        await page.screenshot({ path: testInfo.outputPath('home-contrast.png'), fullPage: true });
    }
});

test.describe('without JavaScript', () => {
    test.use({ javaScriptEnabled: false, viewport: { width: 320, height: 720 } });

    test('mobile navigation and catalog filters remain available', async ({ page }) => {
        await page.goto('/en');
        await page.locator('#site-nav').getByRole('link', { name: 'Catalog', exact: true }).click();
        await expect(page.locator('#q')).toBeVisible();
        await page.locator('#q').fill('lighthouse');
        await page.getByRole('button', { name: 'Apply filters', exact: true }).click();

        await expect(page.locator('.card__title').first()).toBeVisible();
        expect(await hasHorizontalOverflow(page)).toBe(false);
    });
});
