/**
 * PDF engine, built on PDF.js. One page is rendered at a time; the file is
 * fetched in byte ranges, so a large PDF is neither downloaded nor drawn whole.
 *
 * A PDF is fixed layout: this viewer zooms pages, it does not reflow text.
 * PDF JavaScript is never executed: only the display layer of PDF.js is used,
 * not its scripting sandbox or form (XFA) support.
 */
import * as pdfjs from 'pdfjs-dist';
import workerUrl from 'pdfjs-dist/build/pdf.worker.min.mjs?url';
import type { ReaderState } from './state';
import type { Engine, ReaderConfig } from './types';
import type { ReaderUi } from './ui';

pdfjs.GlobalWorkerOptions.workerSrc = workerUrl;

export async function createPdfEngine(config: ReaderConfig, state: ReaderState, ui: ReaderUi): Promise<Engine> {
    const viewer = document.getElementById('viewer')!;
    const pageInput = document.querySelector<HTMLInputElement>('[data-pdf-page]')!;
    const totalLabel = document.querySelector<HTMLElement>('[data-pdf-total]')!;
    const assets = config.urls.pdfAssets;

    const pdf = await pdfjs.getDocument({
        url: config.baseUrl + 'book.pdf',
        withCredentials: false,
        enableXfa: false,
        disableAutoFetch: true, // fetch pages as they are needed, by byte range
        rangeChunkSize: 262144,
        cMapUrl: assets + 'cmaps/',
        cMapPacked: true,
        standardFontDataUrl: assets + 'standard_fonts/',
        wasmUrl: assets + 'wasm/',
        iccUrl: assets + 'iccs/',
    }).promise;

    const total = pdf.numPages;
    totalLabel.textContent = ui.t('pdfOf', { total });
    pageInput.max = String(total);

    let current = 1;
    let scale = 0; // 0 = fit to width
    let shownScale = 1; // the scale the visible page was drawn at
    let renderToken = 0;
    let highlight = '';
    let ready = false;
    let knownNoText = config.hasTextLayer === false;
    let emptyPages = 0;

    if (knownNoText) ui.notice(ui.t('pdfNoText'));

    const position = () => ({
        locatorType: 'page' as const,
        locator: String(current),
        fraction: total > 0 ? current / total : null,
        label: `${ui.t('pdfPage')} ${current}`,
    });

    const render = async (): Promise<void> => {
        const token = ++renderToken;
        const page = await pdf.getPage(current);
        if (token !== renderToken) return;

        const base = page.getViewport({ scale: 1 });
        const available = Math.max(200, viewer.clientWidth - 24);
        const cssScale = scale > 0 ? scale : available / base.width;
        shownScale = cssScale;
        const viewport = page.getViewport({ scale: cssScale });
        // Sharp on high-density screens, but capped so a big page cannot exhaust canvas memory.
        const ratio = Math.min(window.devicePixelRatio || 1, 2, Math.sqrt(16_000_000 / (viewport.width * viewport.height)));

        const holder = document.createElement('div');
        holder.className = 'pdf-page';
        holder.style.width = Math.floor(viewport.width) + 'px';
        holder.style.height = Math.floor(viewport.height) + 'px';

        const canvas = document.createElement('canvas');
        canvas.width = Math.floor(viewport.width * ratio);
        canvas.height = Math.floor(viewport.height * ratio);
        canvas.style.width = Math.floor(viewport.width) + 'px';
        canvas.style.height = Math.floor(viewport.height) + 'px';
        canvas.setAttribute('role', 'img');
        canvas.setAttribute('aria-label', `${ui.t('pdfPage')} ${current} ${ui.t('pdfOf', { total })}`);
        holder.append(canvas);

        // eslint-disable-next-line @typescript-eslint/no-explicit-any
        await page.render({ canvas, canvasContext: canvas.getContext('2d')!, viewport, transform: ratio !== 1 ? [ratio, 0, 0, ratio, 0, 0] : undefined } as any).promise;
        if (token !== renderToken) return;

        const text = await page.getTextContent();
        if (token !== renderToken) return;

        const layer = document.createElement('div');
        layer.className = 'pdf-text textLayer';
        layer.style.setProperty('--scale-factor', String(cssScale));
        layer.style.setProperty('--total-scale-factor', String(cssScale));
        layer.style.setProperty('--user-unit', '1');
        holder.append(layer);

        const hasText = text.items.some((item) => 'str' in item && item.str.trim() !== '');
        if (hasText) {
            await new pdfjs.TextLayer({ textContentSource: text, container: layer, viewport }).render();
            if (highlight) {
                const needle = highlight.toLowerCase();
                layer.querySelectorAll('span').forEach((span) => {
                    if ((span.textContent ?? '').toLowerCase().includes(needle)) span.classList.add('hit');
                });
            }
            emptyPages = 0;
        } else if (!knownNoText && ++emptyPages >= 3) {
            // Three pages in a row without any text: almost certainly a scan.
            knownNoText = true;
            ui.notice(ui.t('pdfNoText'));
        }

        viewer.replaceChildren(holder);
        viewer.scrollTop = 0;
        pageInput.value = String(current);
        ui.setPosition(`${ui.t('pdfPage')} ${current} ${ui.t('pdfOf', { total })}`);
        if (ready) state.setPosition(position());
    };

    const go = async (page: number): Promise<void> => {
        const target = Math.min(total, Math.max(1, Math.round(page)));
        if (target === current && viewer.childElementCount > 0) return;
        current = target;
        await render();
    };

    pageInput.addEventListener('change', () => void go(Number(pageInput.value) || current));
    document.querySelector('[data-action="zoom-in"]')?.addEventListener('click', () => zoom(1.2));
    document.querySelector('[data-action="zoom-out"]')?.addEventListener('click', () => zoom(1 / 1.2));
    document.querySelector('[data-action="fit"]')?.addEventListener('click', () => {
        scale = 0;
        void render();
    });

    const zoom = (factor: number) => {
        scale = Math.min(5, Math.max(0.25, shownScale * factor));
        void render();
    };

    let resizeTimer: number | undefined;
    window.addEventListener('resize', () => {
        window.clearTimeout(resizeTimer);
        resizeTimer = window.setTimeout(() => {
            if (scale === 0) void render();
        }, 200);
    });

    const saved = state.progress();
    const start = saved?.locatorType === 'page' ? Number(saved.locator) : 1;
    await go(Number.isFinite(start) ? start : 1);
    if (viewer.childElementCount === 0) await render();
    ready = true;
    if (start > 1) ui.announce(ui.t('positionRestored'));

    return {
        next: () => void go(current + 1),
        prev: () => void go(current - 1),
        goTo: async (locator: string) => {
            const [page, term] = locator.split('|');
            highlight = term ?? '';
            const target = Number(page) || 1;
            if (target === current) await render();
            else await go(target);
        },
        current: position,
        refreshAnnotations: () => undefined,
        destroy: () => void pdf.loadingTask.destroy(),

        async search(query, onResult, onProgress, signal) {
            const needle = query.toLowerCase();
            let pagesWithText = 0;
            for (let number = 1; number <= total; number++) {
                if (signal.aborted) return;
                onProgress(number, total);
                const page = await pdf.getPage(number);
                const content = await page.getTextContent();
                const text = content.items.map((item) => ('str' in item ? item.str : '')).join(' ');
                if (text.trim() !== '') pagesWithText++;
                const lower = text.toLowerCase();
                const at = lower.indexOf(needle);
                if (at !== -1) {
                    onResult({
                        locator: `${number}|${query}`,
                        excerpt: (at > 40 ? '…' : '') + text.slice(Math.max(0, at - 40), at + needle.length + 60) + '…',
                        label: ui.t('pdfSearchPage', { page: number }),
                    });
                }
                page.cleanup();
            }
            if (pagesWithText === 0) ui.notice(ui.t('pdfNoText'));
        },
    };
}
