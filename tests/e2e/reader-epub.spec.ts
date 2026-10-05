import { expect, test } from '@playwright/test';
import { BOOKS, PDF_BOOK, bookFrame, openReader } from './helpers';

for (const locale of ['en', 'ru', 'he'] as const) {
    test(`${locale} EPUB renders readable content without runtime errors`, async ({ page }) => {
        const errors: string[] = [];
        page.on('pageerror', error => errors.push(error.message));

        await openReader(page, `/${locale}/read/${BOOKS[locale].slug}`);
        const frame = await bookFrame(page);

        await expect(frame.locator('body')).toContainText(BOOKS[locale].first);
        expect(errors).toEqual([]);
    });
}

test('PDF reader renders a page without runtime errors', async ({ page }) => {
    const errors: string[] = [];
    page.on('pageerror', error => errors.push(error.message));

    await openReader(page, `/en/read/${PDF_BOOK}/pdf`);

    await expect(page.locator('#viewer canvas').first()).toBeVisible();
    expect(errors).toEqual([]);
});
