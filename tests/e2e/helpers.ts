import { expect, type Frame, type Page } from '@playwright/test';

export const BOOKS = {
    en: { slug: 'lighthouse-keepers-almanac-en', first: 'The lighthouse at the end of the breakwater', chapters: ['The Lamp', 'Weather', 'Visitors'], dir: 'ltr' },
    ru: { slug: 'almanakh-smotritelya-mayaka-ru', first: 'На маяке в конце волнореза', chapters: ['Лампа', 'Погода', 'Гости'], dir: 'ltr' },
    he: { slug: 'almanakh-shomer-hamigdalor-he', first: 'במגדלור שבקצה שובר הגלים', chapters: ['הפנס', 'מזג האוויר', 'אורחים'], dir: 'rtl' },
} as const;

export const POINTED_HEBREW = 'shurot-shel-boker-he';
export const PDF_BOOK = 'short-guide-to-the-reading-room-en';

/** Opens a reader page and waits until the engine reports it is ready. */
export async function openReader(page: Page, path: string): Promise<void> {
    await page.goto(path);
    await expect(page.locator('body')).toHaveAttribute('data-ready', 'true', { timeout: 90_000 });
    await expect(page.locator('[data-error]')).toBeHidden();
}

/** The document of the book section currently shown (EPUB). */
export async function bookFrame(page: Page): Promise<Frame> {
    const handle = await page.locator('#viewer iframe').first().elementHandle();
    const frame = await handle!.contentFrame();
    if (!frame) throw new Error('The book frame is not available.');
    return frame;
}

export async function positionText(page: Page): Promise<string> {
    return (await page.locator('[data-position]').textContent()) ?? '';
}

/** Current locator as the reader would save it. */
export async function currentLocator(page: Page): Promise<string> {
    return page.evaluate(() => (window as any).__reader.engine.current()?.locator ?? '');
}

/** Section (spine) part of an EPUB CFI, e.g. "/6/6" from "epubcfi(/6/6!/4/2/1:0)". */
export function cfiSection(cfi: string): string {
    return /^epubcfi\(([^!]+)!/.exec(cfi)?.[1] ?? '';
}

/** Goes to a chapter through the table of contents, like a reader would. */
export async function goToChapter(page: Page, title: string): Promise<void> {
    await page.getByRole('button', { name: 'Contents' }).click();
    await page.locator('#panel-toc').getByRole('link', { name: title, exact: true }).click();
    await expect(page.locator('[data-position]')).toContainText(title);
}

/** Waits for the debounced local save to have happened. */
export async function waitForSavedProgress(page: Page): Promise<void> {
    await page.waitForFunction(() => {
        const reader = (window as any).__reader;
        const saved = reader.state.progress();
        return saved && saved.locator === reader.engine.current()?.locator;
    }, undefined, { timeout: 15_000 });
}

export async function hasHorizontalOverflow(page: Page): Promise<boolean> {
    return page.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth + 1);
}

let counter = 0;
export function uniqueEmail(prefix = 'reader'): string {
    return `${prefix}-${Date.now()}-${++counter}@example.org`;
}

export const PASSWORD = 'correct-horse-42';

export async function register(page: Page, email: string, name = 'E2E Reader'): Promise<void> {
    await page.goto('/register');
    await page.getByLabel('Name').fill(name);
    await page.getByLabel('Email address').fill(email);
    await page.getByLabel('Password', { exact: true }).fill(PASSWORD);
    await page.getByLabel('Repeat password').fill(PASSWORD);
    await page.getByRole('button', { name: 'Create account' }).click();
    await page.waitForURL(/\/(en|ru|he)\/?$/);
}

export async function signIn(page: Page, email: string): Promise<void> {
    await page.goto('/login');
    await page.getByLabel('Email address').fill(email);
    await page.getByLabel('Password', { exact: true }).fill(PASSWORD);
    await page.getByRole('button', { name: 'Sign in' }).click();
    await page.waitForURL(/\/(en|ru|he)\/?$/);
}
