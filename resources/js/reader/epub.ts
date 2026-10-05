/**
 * EPUB engine, built on EPUB.js.
 *
 * Isolation: EPUB.js renders each section into an iframe sandboxed with
 * "allow-same-origin" only. `allowScriptedContent` stays false — turning it on
 * would add "allow-scripts" and let book markup run in this page's origin.
 * The reading copy was sanitized at import, and the page's CSP (script-src
 * 'self') applies inside the frames as a second barrier.
 */
import ePub from 'epubjs';
import fontsUrl from '../../css/book-fonts.css?url';
import type { ReaderState } from './state';
import type { Engine, ReaderConfig, SearchResult } from './types';
import type { ReaderUi } from './ui';

interface Settings {
    flow: 'paginated' | 'scrolled';
    spread: boolean;
    theme: 'light' | 'sepia' | 'dark' | 'contrast';
    font: 'publisher' | 'serif' | 'sans';
    fontSize: number;   // percent
    lineHeight: number; // percent
    margin: number;     // steps
    width: number;      // percent of the available width
}

const DEFAULTS: Settings = { flow: 'paginated', spread: true, theme: 'light', font: 'publisher', fontSize: 100, lineHeight: 150, margin: 3, width: 100 };

const THEMES: Record<Settings['theme'], { bg: string; fg: string; link: string; force: boolean }> = {
    light: { bg: '#ffffff', fg: '#1a1a1a', link: '#7a1f33', force: false },
    sepia: { bg: '#f6eedb', fg: '#3a2f1e', link: '#6b3f10', force: false },
    dark: { bg: '#0a0a0b', fg: '#ece6d8', link: '#d9b65c', force: true },
    contrast: { bg: '#000000', fg: '#ffffff', link: '#ffff00', force: true },
};

const FONTS = {
    serif: "'Noto Serif', 'Noto Serif Hebrew', Georgia, 'Times New Roman', serif",
    sans: "'Noto Sans', 'Noto Sans Hebrew', system-ui, Arial, sans-serif",
};

// Hebrew points and cantillation marks: ignored when searching, never removed from the text shown.
const HEBREW_MARKS = /[֑-ׇֽֿׁׂׅׄ]/;

/** Text folded for matching, with a map back to positions in the original string. */
function fold(text: string): { text: string; map: number[] } {
    let out = '';
    const map: number[] = [];
    for (let i = 0; i < text.length; i++) {
        const ch = text[i];
        if (HEBREW_MARKS.test(ch)) continue;
        const lower = ch.toLowerCase();
        out += lower.length === 1 ? (lower === 'ё' ? 'е' : lower) : ch;
        map.push(i);
    }
    return { text: out, map };
}

export async function createEpubEngine(config: ReaderConfig, state: ReaderState, ui: ReaderUi): Promise<Engine> {
    if (!config.opfPath) throw new Error('No package document for this EPUB.');

    const viewer = document.getElementById('viewer')!;
    const stage = document.querySelector<HTMLElement>('.rstage')!;
    const packageUrl = config.baseUrl + config.opfPath.split('/').map(encodeURIComponent).join('/');

    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    const book: any = ePub(packageUrl, { openAs: 'opf' } as never);
    await withTimeout(book.ready, 45000);

    const metadata = book.package?.metadata ?? {};
    const fixedLayout = metadata.layout === 'pre-paginated' || config.layout === 'fixed';
    const spineItems: any[] = book.spine.spineItems.filter((item: any) => item.linear !== 'no' || true);
    const spineLength = Math.max(1, spineItems.length);

    // Page direction: an explicit editorial setting wins, then the book's own
    // declaration, then the edition's text direction.
    const bookDirection: 'ltr' | 'rtl' =
        config.pageProgression !== 'default' ? config.pageProgression
            : metadata.direction === 'rtl' || metadata.direction === 'ltr' ? metadata.direction
            : config.direction === 'rtl' ? 'rtl' : 'ltr';
    stage.setAttribute('dir', bookDirection);

    let settings: Settings = { ...DEFAULTS, ...state.prefs(DEFAULTS) };
    if (window.matchMedia('(prefers-color-scheme: dark)').matches && !('theme' in state.prefs({}))) settings.theme = 'dark';

    // Flattened table of contents: label lookup for positions and search results.
    const navigation = await book.loaded.navigation;
    const tocFlat: Array<{ label: string; href: string }> = [];
    const flatten = (items: any[]) => items.forEach((item) => {
        tocFlat.push({ label: String(item.label ?? '').trim(), href: String(item.href ?? '') });
        if (item.subitems?.length) flatten(item.subitems);
    });
    flatten(navigation?.toc ?? []);
    const basePath = (href: string) => decodeURIComponent(href.split('#')[0]).replace(/^.*\//, '');
    const labelFor = (href: string | undefined): string | null => {
        if (!href) return null;
        const wanted = basePath(href);
        return tocFlat.find((entry) => basePath(entry.href) === wanted)?.label || null;
    };

    let rendition: any;
    let lastLocation: any = null;
    let ready = false;
    let pendingSelection: { cfi: string; text: string } | null = null;
    let drawn: string[] = [];
    const selectionTools = document.querySelector<HTMLElement>('[data-selection-tools]');

    const userCss = (): string => {
        const theme = THEMES[settings.theme];
        const rules = [
            `html, body { background: ${theme.bg} !important; color: ${theme.fg} !important; }`,
            `a, a:link, a:visited { color: ${theme.link} !important; }`,
            'img, svg, video { max-width: 100% !important; }',
        ];
        if (theme.force) {
            // Dark and high-contrast themes override publisher colours so no text is left unreadable.
            rules.push(`body * { color: inherit !important; background-color: transparent !important; border-color: currentColor !important; }`);
            rules.push(`a, a * { color: ${theme.link} !important; }`);
        }
        if (!fixedLayout) {
            rules.push(`body { line-height: ${settings.lineHeight / 100} !important; }`);
            rules.push('p, li, blockquote, dd, dt, td, th { line-height: inherit !important; }');
            if (settings.font !== 'publisher') {
                rules.push(`body, p, li, blockquote, dd, dt, td, th, div, span, a, h1, h2, h3, h4, h5, h6 { font-family: ${FONTS[settings.font]} !important; }`);
            }
        }
        if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
            rules.push('*, *::before, *::after { animation: none !important; transition: none !important; scroll-behavior: auto !important; }');
        }
        return rules.join('\n');
    };

    const styleDocument = (doc: Document) => {
        let style = doc.getElementById('orl-user-style');
        if (!style) {
            style = doc.createElement('style');
            style.id = 'orl-user-style';
            (doc.head ?? doc.documentElement).appendChild(style);
        }
        style.textContent = userCss();
    };

    const applyChrome = () => {
        document.body.dataset.theme = settings.theme;
        viewer.style.setProperty('--reader-width', fixedLayout ? '100%' : settings.width + '%');
        viewer.style.setProperty('--reader-margin', fixedLayout ? '0' : settings.margin * 0.3 + 'rem');
    };

    const onLinkClick = (event: Event, doc: Document) => {
        const link = (event.target as Element | null)?.closest?.('a[href]') as HTMLAnchorElement | null;
        if (!link) return;
        const href = link.getAttribute('href') ?? '';

        if (/^(https?:|mailto:)/i.test(href)) {
            // Leaving the library is always the reader's explicit choice.
            event.preventDefault();
            event.stopImmediatePropagation();
            ui.confirmExternal(href);
            return;
        }

        const type = (link.getAttribute('epub:type') ?? '') + ' ' + (link.getAttribute('role') ?? '');
        if (/noteref|doc-noteref/.test(type) && href.includes('#')) {
            const [path, id] = href.split('#');
            const target = path === '' || basePath(path) === basePath(doc.location?.pathname ?? '') ? doc.getElementById(id) : null;
            if (target) {
                event.preventDefault();
                event.stopImmediatePropagation();
                ui.popup(ui.t('footnote'), (target.textContent ?? '').trim());
            }
        }
    };

    const createRendition = () => {
        const options: Record<string, unknown> = {
            width: '100%',
            height: '100%',
            allowScriptedContent: false, // see the note at the top of this file
            spread: settings.spread && !fixedLayout ? 'auto' : fixedLayout ? 'auto' : 'none',
            minSpreadWidth: 1000,
        };
        if (settings.flow === 'scrolled' && !fixedLayout) {
            options.flow = 'scrolled';
            options.manager = 'continuous';
        } else {
            options.flow = 'paginated';
            options.manager = 'default';
        }

        rendition = book.renderTo(viewer, options);
        rendition.direction(bookDirection);

        rendition.hooks.content.register((contents: any) => {
            const doc: Document = contents.document;
            styleDocument(doc);
            // capture: run before EPUB.js's own link handling
            doc.addEventListener('click', (event) => onLinkClick(event, doc), true);
            return contents.addStylesheet(fontsUrl).catch(() => undefined);
        });

        if (!fixedLayout) rendition.themes.fontSize(settings.fontSize + '%');

        rendition.on('relocated', (location: any) => {
            lastLocation = location;
            hideSelectionTools();
            const position = currentPosition();
            if (!position) return;
            ui.setPosition(positionText(position));
            markCurrentTocEntry(location.start?.href);
            if (ready) state.setPosition(position);
        });

        rendition.on('selected', (cfiRange: string, contents: any) => {
            const selection = contents.window.getSelection();
            const text = selection ? String(selection).trim() : '';
            if (!text || !state.enabled) return;
            pendingSelection = { cfi: cfiRange, text };
            showSelectionTools(contents, selection);
        });

        rendition.on('keydown', (event: KeyboardEvent) => {
            (window as unknown as { __readerKey?: (e: KeyboardEvent) => void }).__readerKey?.(event);
        });

        // Swipe to turn pages (paginated mode only; scrolling handles the other).
        let touchX = 0;
        let touchY = 0;
        rendition.on('touchstart', (event: TouchEvent) => {
            touchX = event.changedTouches[0].screenX;
            touchY = event.changedTouches[0].screenY;
        });
        rendition.on('touchend', (event: TouchEvent) => {
            if (settings.flow !== 'paginated') return;
            const dx = event.changedTouches[0].screenX - touchX;
            const dy = event.changedTouches[0].screenY - touchY;
            if (Math.abs(dx) < 50 || Math.abs(dx) < Math.abs(dy) * 1.5) return;
            const forward = bookDirection === 'rtl' ? dx > 0 : dx < 0;
            forward ? rendition.next() : rendition.prev();
        });
    };

    const currentPosition = () => {
        const start = lastLocation?.start;
        if (!start?.cfi) return null;
        const page = start.displayed?.page ?? 1;
        const total = Math.max(1, start.displayed?.total ?? 1);
        // Position within this edition only: section index plus how far into the
        // section. It is an approximation and is never presented as a page number.
        const fraction = Math.min(1, (start.index + (page - 1) / total) / spineLength);
        const label = labelFor(start.href) ?? ui.t('section', { current: start.index + 1, total: spineLength });
        return { locatorType: 'cfi' as const, locator: String(start.cfi), fraction, label };
    };

    const positionText = (position: NonNullable<ReturnType<typeof currentPosition>>) => {
        const start = lastLocation.start;
        const parts = [ui.t('section', { current: start.index + 1, total: spineLength })];
        const label = labelFor(start.href);
        if (label) parts.unshift(label);
        parts.push(ui.t('percent', { percent: Math.round((position.fraction ?? 0) * 100) }));
        return parts.join(' · ');
    };

    /* ---- table of contents ---- */

    const tocRoot = document.querySelector<HTMLElement>('[data-toc]');
    const buildToc = (items: any[], parent: HTMLElement) => {
        for (const item of items) {
            const li = document.createElement('li');
            const link = document.createElement('a');
            link.href = '#';
            link.textContent = String(item.label ?? '').trim();
            link.dataset.href = String(item.href ?? '');
            link.addEventListener('click', (event) => {
                event.preventDefault();
                ui.closeAll();
                void rendition.display(item.href);
                document.getElementById('book')?.focus();
            });
            li.append(link);
            if (item.subitems?.length) {
                const ol = document.createElement('ol');
                buildToc(item.subitems, ol);
                li.append(ol);
            }
            parent.append(li);
        }
    };
    if (tocRoot) buildToc(navigation?.toc ?? [], tocRoot);

    const markCurrentTocEntry = (href: string | undefined) => {
        if (!tocRoot || !href) return;
        const wanted = basePath(href);
        let marked = false;
        tocRoot.querySelectorAll<HTMLAnchorElement>('a').forEach((link) => {
            const match = !marked && basePath(link.dataset.href ?? '') === wanted;
            match ? link.setAttribute('aria-current', 'true') : link.removeAttribute('aria-current');
            marked = marked || match;
        });
    };

    /* ---- selection, highlights, notes ---- */

    const hideSelectionTools = () => {
        if (selectionTools) selectionTools.hidden = true;
    };
    const showSelectionTools = (contents: any, selection: Selection) => {
        if (!selectionTools || selection.rangeCount === 0) return;
        const rect = selection.getRangeAt(0).getBoundingClientRect();
        const frame = (contents.document.defaultView?.frameElement as HTMLElement | null)?.getBoundingClientRect();
        selectionTools.hidden = false;
        const top = (frame?.top ?? 0) + rect.bottom + 8;
        const left = (frame?.left ?? 0) + rect.left;
        selectionTools.style.top = Math.min(window.innerHeight - 70, Math.max(60, top)) + 'px';
        selectionTools.style.left = Math.min(window.innerWidth - 240, Math.max(8, left)) + 'px';
    };
    const clearSelection = () => {
        rendition.getContents().forEach((contents: any) => contents.window.getSelection()?.removeAllRanges());
        hideSelectionTools();
        pendingSelection = null;
    };

    ui.onSelectionAction = (action) => {
        if (!pendingSelection) return;
        const { cfi, text } = pendingSelection;
        if (action === 'highlight') {
            state.saveAnnotation({ locator: cfi, quote: text, note: null });
            clearSelection();
            ui.renderMarks();
            drawAnnotations();
        } else {
            clearSelection();
            ui.editNote({ locator: cfi, quote: text });
        }
    };

    const drawAnnotations = () => {
        for (const cfi of drawn) {
            try {
                rendition.annotations.remove(cfi, 'highlight');
            } catch {
                /* already gone with its view */
            }
        }
        drawn = [];
        for (const annotation of state.annotations()) {
            if (annotation.locatorType !== 'cfi') continue;
            try {
                rendition.annotations.add('highlight', annotation.locator, {}, () => ui.open('marks'), 'orl-highlight', {
                    fill: annotation.note ? '#7cc4ff' : '#ffd84d', 'fill-opacity': '0.4', 'mix-blend-mode': settings.theme === 'dark' || settings.theme === 'contrast' ? 'screen' : 'multiply',
                });
                drawn.push(annotation.locator);
            } catch {
                /* a locator that no longer resolves is skipped, not guessed */
            }
        }
    };

    /* ---- settings ---- */

    const form = document.querySelector<HTMLFormElement>('[data-settings]');
    const syncForm = () => {
        if (!form) return;
        (form.elements.namedItem('flow') as RadioNodeList).value = settings.flow;
        (form.elements.namedItem('theme') as RadioNodeList).value = settings.theme;
        (form.elements.namedItem('font') as RadioNodeList).value = settings.font;
        (form.elements.namedItem('spread') as HTMLInputElement).checked = settings.spread;
        for (const name of ['fontSize', 'lineHeight', 'margin', 'width'] as const) {
            (form.elements.namedItem(name) as HTMLInputElement).value = String(settings[name]);
            const out = form.querySelector<HTMLOutputElement>(`[data-out="${name}"]`);
            if (out) out.textContent = name === 'margin' ? String(settings[name]) : settings[name] + '%';
        }
        if (fixedLayout) {
            const note = document.querySelector<HTMLElement>('[data-fixed-layout]');
            if (note) note.hidden = false;
            form.querySelectorAll<HTMLInputElement>('[name=flow],[name=font],[name=fontSize],[name=lineHeight],[name=margin],[name=width],[name=spread]')
                .forEach((input) => (input.disabled = true));
        }
    };

    const rebuild = async () => {
        const cfi = lastLocation?.start?.cfi;
        ready = false;
        rendition.destroy();
        createRendition();
        await rendition.display(cfi || undefined);
        drawAnnotations();
        ready = true;
    };

    const applySettings = async (previous: Settings) => {
        state.savePrefs(settings);
        applyChrome();
        syncForm();

        if (previous.flow !== settings.flow || previous.spread !== settings.spread) {
            await rebuild();
            return;
        }
        rendition.getContents().forEach((contents: any) => styleDocument(contents.document));
        if (previous.fontSize !== settings.fontSize && !fixedLayout) rendition.themes.fontSize(settings.fontSize + '%');

        const layoutChanged = (['font', 'fontSize', 'lineHeight', 'margin', 'width'] as const).some((k) => previous[k] !== settings[k]);
        if (layoutChanged) {
            // Re-flow, then return to the same place in the text (a CFI), not the same screen page.
            const cfi = lastLocation?.start?.cfi;
            try {
                rendition.resize();
            } catch {
                /* the manager may be mid-render; display() below settles it */
            }
            if (cfi) await rendition.display(cfi);
        }
        if (previous.theme !== settings.theme) drawAnnotations();
    };

    form?.addEventListener('change', () => {
        const previous = { ...settings };
        const data = new FormData(form);
        settings = {
            flow: data.get('flow') === 'scrolled' ? 'scrolled' : 'paginated',
            spread: data.get('spread') === '1',
            theme: (['light', 'sepia', 'dark', 'contrast'] as const).find((t) => t === data.get('theme')) ?? 'light',
            font: (['publisher', 'serif', 'sans'] as const).find((f) => f === data.get('font')) ?? 'publisher',
            fontSize: Number(data.get('fontSize')) || 100,
            lineHeight: Number(data.get('lineHeight')) || 150,
            margin: Number(data.get('margin')) || 0,
            width: Number(data.get('width')) || 100,
        };
        void applySettings(previous);
    });
    document.querySelector('[data-action="reset-settings"]')?.addEventListener('click', () => {
        const previous = { ...settings };
        settings = { ...DEFAULTS };
        void applySettings(previous);
    });

    /* ---- start ---- */

    applyChrome();
    syncForm();
    createRendition();

    const saved = state.progress();
    let restored = false;
    if (saved?.locatorType === 'cfi') {
        try {
            await withTimeout(rendition.display(saved.locator), 30000);
            restored = true;
        } catch {
            /* an unusable saved position falls back to the beginning */
        }
    }
    if (!restored) await withTimeout(rendition.display(), 30000);
    drawAnnotations();
    ready = true;
    if (restored) ui.announce(ui.t('positionRestored'));
    if (fixedLayout) ui.notice(ui.t('fixedLayout'));

    const engine: Engine = {
        next: () => void rendition.next(),
        prev: () => void rendition.prev(),
        goTo: async (locator: string) => {
            await rendition.display(locator);
        },
        current: currentPosition,
        refreshAnnotations: drawAnnotations,
        destroy: () => book.destroy(),

        // Sections are loaded one at a time and released again, so searching a
        // large book never holds the whole text in memory.
        async search(query, onResult, onProgress, signal) {
            const needle = fold(query).text;
            if (!needle) return;

            for (let index = 0; index < spineItems.length; index++) {
                if (signal.aborted) return;
                onProgress(index + 1, spineItems.length);
                const item = spineItems[index];
                try {
                    await item.load(book.load.bind(book));
                    const doc: Document = item.document;
                    const walker = doc.createTreeWalker(doc.body ?? doc.documentElement, NodeFilter.SHOW_TEXT);
                    const label = labelFor(item.href) ?? undefined;

                    for (let node = walker.nextNode() as Text | null; node; node = walker.nextNode() as Text | null) {
                        const folded = fold(node.data);
                        for (let at = folded.text.indexOf(needle); at !== -1; at = folded.text.indexOf(needle, at + needle.length)) {
                            const start = folded.map[at];
                            const end = folded.map[at + needle.length - 1] + 1;
                            const range = doc.createRange();
                            range.setStart(node, start);
                            range.setEnd(node, end);
                            const result: SearchResult = {
                                locator: item.cfiFromRange(range),
                                excerpt: (start > 40 ? '…' : '') + node.data.slice(Math.max(0, start - 40), end + 40).replace(/\s+/g, ' ') + '…',
                                label,
                            };
                            onResult(result);
                            if (signal.aborted) return;
                        }
                    }
                } finally {
                    item.unload();
                }
                await new Promise((resolve) => setTimeout(resolve, 0)); // keep the page responsive
            }
        },
    };

    return engine;
}

function withTimeout<T>(promise: Promise<T>, ms: number): Promise<T> {
    return new Promise<T>((resolve, reject) => {
        const timer = setTimeout(() => reject(new Error('timeout')), ms);
        promise.then(
            (value) => {
                clearTimeout(timer);
                resolve(value);
            },
            (error) => {
                clearTimeout(timer);
                reject(error);
            },
        );
    });
}
