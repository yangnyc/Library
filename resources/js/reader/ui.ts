import { OfflineError, formatBytes, fetchManifest, getRecord, isAvailableOffline, offlineSupported, removeBook, saveBook } from '../lib/offline';
import type { ReaderState } from './state';
import type { Engine, ReaderConfig } from './types';

const $ = <T extends HTMLElement = HTMLElement>(selector: string, root: ParentNode = document) => root.querySelector<T>(selector);
const $$ = <T extends HTMLElement = HTMLElement>(selector: string, root: ParentNode = document) => [...root.querySelectorAll<T>(selector)];

/** The reader chrome shared by both engines: panels, lists, messages, offline controls. */
export class ReaderUi {
    private engine: Engine | null = null;
    private searchAbort: AbortController | null = null;
    private offlineAbort: AbortController | null = null;
    private noteTarget: { uuid?: string; locator: string; quote: string | null } | null = null;
    public onSelectionAction: (action: 'highlight' | 'note') => void = () => undefined;

    constructor(private readonly config: ReaderConfig, private readonly state: ReaderState) {
        this.bindPanels();
        this.bindToolbar();
        this.bindSearch();
        this.bindNotes();
        this.bindFullscreen();
        void this.initOffline();
    }

    t(key: string, replace: Record<string, string | number> = {}): string {
        let text = this.config.strings[key] ?? key;
        for (const [name, value] of Object.entries(replace)) text = text.replace(':' + name, String(value));
        return text;
    }

    attach(engine: Engine): void {
        this.engine = engine;
        this.renderMarks();
    }

    /* ---- messages ---- */

    /** Announced to screen readers without moving focus. */
    announce(text: string): void {
        const live = $('[data-live]');
        if (!live) return;
        live.textContent = '';
        window.setTimeout(() => (live.textContent = text), 50);
    }

    notice(text: string | null): void {
        const notice = $('[data-notice]');
        if (!notice) return;
        notice.hidden = !text;
        notice.textContent = text ?? '';
    }

    setPosition(text: string): void {
        const el = $('[data-position]');
        if (el) el.textContent = text;
    }

    setSyncStatus(text: string): void {
        const el = $('[data-sync-status]');
        if (el) el.textContent = text;
    }

    setIdentity(text: string): void {
        const el = $('[data-identity]');
        if (el) el.textContent = text;
    }

    loaded(): void {
        const loading = $('[data-loading]');
        if (loading) loading.hidden = true;
    }

    fail(message: string): void {
        this.loaded();
        const error = $('[data-error]');
        const text = $('[data-error-text]');
        if (text) text.textContent = message;
        if (error) error.hidden = false;
    }

    /* ---- panels ---- */

    open(name: string): void {
        const dialog = document.getElementById('panel-' + name) as HTMLDialogElement | null;
        if (dialog && !dialog.open) dialog.showModal();
    }

    closeAll(): void {
        $$<HTMLDialogElement>('dialog[open]').forEach((d) => d.close());
    }

    private bindPanels(): void {
        $$('[data-open]').forEach((button) => button.addEventListener('click', () => this.open(button.dataset.open!)));
        $$<HTMLDialogElement>('dialog.panel').forEach((dialog) => {
            $$('[data-close]', dialog).forEach((b) => b.addEventListener('click', () => dialog.close()));
            // Click on the backdrop closes, like Esc does.
            dialog.addEventListener('click', (event) => {
                if (event.target === dialog) dialog.close();
            });
        });
    }

    private bindToolbar(): void {
        document.addEventListener('click', (event) => {
            const button = (event.target as HTMLElement).closest<HTMLElement>('[data-action]');
            if (!button) return;
            switch (button.dataset.action) {
                case 'next': this.engine?.next(); break;
                case 'prev': this.engine?.prev(); break;
                case 'reload': location.reload(); break;
                case 'bookmark': this.addBookmark(); break;
                case 'highlight': this.onSelectionAction('highlight'); break;
                case 'note': this.onSelectionAction('note'); break;
            }
        });
    }

    private bindFullscreen(): void {
        const button = $('[data-action="fullscreen"]');
        const root = document.documentElement;
        if (!button || !root.requestFullscreen) return; // e.g. iPhone Safari: no button is shown
        button.hidden = false;
        button.addEventListener('click', () => {
            document.fullscreenElement ? void document.exitFullscreen() : void root.requestFullscreen().catch(() => undefined);
        });
        document.addEventListener('fullscreenchange', () => {
            const on = Boolean(document.fullscreenElement);
            button.textContent = on ? this.t('exitFullscreen') : this.t('fullscreen');
            button.setAttribute('aria-pressed', String(on));
        });
    }

    /* ---- bookmarks and notes ---- */

    private addBookmark(): void {
        const position = this.engine?.current();
        if (!position || !this.state.enabled) return;
        this.state.addBookmark(position);
        this.renderMarks();
        this.announce(this.t('bookmarkAdded'));
        this.notice(this.t('bookmarkAdded'));
        window.setTimeout(() => this.notice(null), 2500);
    }

    renderMarks(): void {
        const list = $('[data-bookmarks]');
        if (list) {
            const bookmarks = this.state.bookmarks();
            list.replaceChildren(...bookmarks.map((b) => this.markItem(b.label || b.locator, null, () => this.go(b.locator), () => {
                this.state.removeBookmark(b.uuid);
                this.renderMarks();
            })));
            const empty = $('[data-bookmarks-empty]');
            if (empty) empty.hidden = bookmarks.length > 0;
        }

        const notes = $('[data-annotations]');
        if (notes) {
            const annotations = this.state.annotations();
            notes.replaceChildren(...annotations.map((a) => {
                const item = this.markItem(a.quote || a.locator, a.note, () => this.go(a.locator), () => {
                    this.state.removeAnnotation(a.uuid);
                    this.renderMarks();
                    this.engine?.refreshAnnotations();
                }, () => this.editNote({ uuid: a.uuid, locator: a.locator, quote: a.quote }, a.note ?? ''));
                if (a.conflictOf) {
                    const flag = document.createElement('p');
                    flag.className = 'hint';
                    flag.textContent = this.t('conflictCopy');
                    item.prepend(flag);
                }
                return item;
            }));
            const empty = $('[data-annotations-empty]');
            if (empty) empty.hidden = annotations.length > 0;
        }
    }

    // All text is set with textContent: book excerpts and notes are never parsed as HTML.
    private markItem(label: string, note: string | null, onGo: () => void, onDelete: () => void, onEdit?: () => void): HTMLLIElement {
        const li = document.createElement('li');
        const go = document.createElement('button');
        go.type = 'button';
        go.className = 'mark__go';
        go.dir = 'auto';
        go.textContent = label.length > 160 ? label.slice(0, 160) + '…' : label;
        go.addEventListener('click', onGo);
        li.append(go);

        if (note) {
            const p = document.createElement('p');
            p.className = 'mark__note';
            p.dir = 'auto';
            p.textContent = note;
            li.append(p);
        }

        const tools = document.createElement('div');
        tools.className = 'mark__tools';
        if (onEdit) tools.append(this.smallButton(this.t('noteLabel'), onEdit));
        tools.append(this.smallButton(this.t('delete'), onDelete));
        li.append(tools);
        return li;
    }

    private smallButton(text: string, onClick: () => void): HTMLButtonElement {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'rbtn';
        button.textContent = text;
        button.addEventListener('click', onClick);
        return button;
    }

    private go(locator: string): void {
        this.closeAll();
        void this.engine?.goTo(locator);
        $('#book')?.focus();
    }

    editNote(target: { uuid?: string; locator: string; quote: string | null }, text = ''): void {
        this.noteTarget = target;
        const quote = $('[data-note-quote]');
        if (quote) {
            quote.textContent = target.quote ?? '';
            quote.hidden = !target.quote;
        }
        const area = $<HTMLTextAreaElement>('#note-text');
        if (area) area.value = text;
        this.closeAll();
        this.open('note');
        area?.focus();
    }

    private bindNotes(): void {
        const form = $<HTMLFormElement>('[data-note-form]');
        form?.addEventListener('submit', (event) => {
            const submitter = (event as SubmitEvent).submitter as HTMLButtonElement | null;
            if (submitter?.value !== 'save' || !this.noteTarget) return;
            const text = $<HTMLTextAreaElement>('#note-text')?.value.trim() ?? '';
            this.state.saveAnnotation({ ...this.noteTarget, note: text || null });
            this.noteTarget = null;
            this.renderMarks();
            this.engine?.refreshAnnotations();
        });
    }

    /* ---- pop-ups (footnotes, external links, sync conflicts) ---- */

    popup(title: string, body: string, action?: { label: string; run: () => void }): void {
        const titleEl = $('[data-popup-title]');
        const bodyEl = $('[data-popup-body]');
        const actions = $('[data-popup-actions]');
        if (titleEl) titleEl.textContent = title;
        if (bodyEl) bodyEl.textContent = body;
        if (actions) {
            actions.replaceChildren();
            if (action) {
                const button = this.smallButton(action.label, () => {
                    action.run();
                    this.closeAll();
                });
                button.className = 'button button--small';
                actions.append(button);
            }
        }
        this.open('popup');
    }

    /** Asks before leaving the library for another site. Only http(s) and mailto links get this far. */
    confirmExternal(url: string): void {
        if (!/^(https?:|mailto:)/i.test(url)) return;
        this.popup(this.t('externalLink'), url, {
            label: this.t('externalOpen'),
            run: () => window.open(url, '_blank', 'noopener,noreferrer'),
        });
    }

    offerOtherPosition(label: string, locator: string): void {
        const text = $('[data-conflict-text]');
        const go = $('[data-conflict-go]');
        if (!text || !go) return;
        text.textContent = this.t('conflictProgress', { label });
        go.onclick = () => this.go(locator);
        this.open('conflict');
    }

    /* ---- search ---- */

    private bindSearch(): void {
        const form = $<HTMLFormElement>('[data-search-form]');
        const input = $<HTMLInputElement>('#search-q');
        const status = $('[data-search-status]');
        const list = $('[data-search-results]');
        const stop = $('[data-search-stop]');
        if (!form || !input || !status || !list || !stop) return;

        stop.addEventListener('click', () => this.searchAbort?.abort());

        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            const query = input.value.trim();
            if (query.length < 2 || !this.engine) return;

            this.searchAbort?.abort();
            const controller = (this.searchAbort = new AbortController());
            list.replaceChildren();
            stop.hidden = false;
            let count = 0;
            const LIMIT = 200;

            try {
                await this.engine.search(
                    query,
                    (result) => {
                        if (count >= LIMIT) {
                            controller.abort();
                            return;
                        }
                        count++;
                        const li = document.createElement('li');
                        const button = document.createElement('button');
                        button.type = 'button';
                        if (result.label) {
                            const small = document.createElement('small');
                            small.textContent = result.label + ' — ';
                            button.append(small);
                        }
                        button.append(...highlightParts(result.excerpt, query));
                        button.addEventListener('click', () => this.go(result.locator));
                        li.append(button);
                        list.append(li);
                    },
                    (current, total) => (status.textContent = this.t('searching', { current, total })),
                    controller.signal,
                );
            } catch {
                /* aborted or a section failed to load: show what was found */
            }

            stop.hidden = true;
            status.textContent = count === 0 ? this.t('searchNone') : count >= LIMIT ? this.t('searchLimited', { count }) : this.t('searchCount', { count });
        });
    }

    /* ---- offline ---- */

    private async initOffline(): Promise<void> {
        const status = $('[data-offline-status]');
        const save = $<HTMLButtonElement>('[data-offline-save]');
        const cancel = $<HTMLButtonElement>('[data-offline-cancel]');
        const remove = $<HTMLButtonElement>('[data-offline-remove]');
        const progress = $<HTMLProgressElement>('[data-offline-progress]');
        if (!status || !save || !cancel || !remove || !progress) return;

        const show = (state: 'idle' | 'saving' | 'saved') => {
            save.hidden = state !== 'idle';
            cancel.hidden = state !== 'saving';
            remove.hidden = state !== 'saved';
            progress.hidden = state !== 'saving';
        };

        if (!this.config.offlineAllowed) {
            status.textContent = this.t('offlineNotAllowed');
            return;
        }
        if (!offlineSupported()) {
            status.textContent = this.t('offlineUnsupported');
            return;
        }

        const refresh = async () => {
            const record = getRecord(this.config.fileId);
            const current = record?.contentKey === this.config.contentKey && (await isAvailableOffline(record));
            if (current) {
                status.textContent = this.t('offlineSaved');
                show('saved');
                return;
            }
            // An interrupted or evicted copy is not offered as available.
            if (record) await removeBook(this.config.fileId);
            show('idle');
            save.textContent = record ? this.t('offlineRetry') : this.t('offlineSave');
            status.textContent = record ? this.t('offlineFailed') : '';
            if (!record && navigator.onLine) {
                fetchManifest(this.config.urls.offlineManifest)
                    .then((m) => (status.textContent = this.t('offlineSize', { size: formatBytes(m.totalBytes) })))
                    .catch(() => undefined);
            }
        };

        save.addEventListener('click', async () => {
            const controller = (this.offlineAbort = new AbortController());
            show('saving');
            progress.value = 0;
            status.textContent = this.t('offlineSaving', { percent: 0 });
            try {
                await saveBook(
                    this.config.urls.offlineManifest,
                    (fraction) => {
                        const percent = Math.round(fraction * 100);
                        progress.value = percent;
                        status.textContent = this.t('offlineSaving', { percent });
                    },
                    controller.signal,
                );
                status.textContent = this.t('offlineSaved');
                this.announce(this.t('offlineSaved'));
                show('saved');
            } catch (error) {
                const reason = error instanceof OfflineError ? error.reason : 'network';
                show('idle');
                save.textContent = this.t('offlineRetry');
                status.textContent = reason === 'quota' ? this.t('offlineQuota')
                    : reason === 'unavailable' ? this.t('offlineNotAllowed')
                    : reason === 'aborted' ? '' : this.t('offlineFailed');
                if (reason !== 'aborted') this.announce(status.textContent);
                if (reason === 'aborted') save.textContent = this.t('offlineSave');
            }
        });
        cancel.addEventListener('click', () => this.offlineAbort?.abort());
        remove.addEventListener('click', async () => {
            await removeBook(this.config.fileId);
            await refresh();
        });

        await refresh();
    }
}

/** Splits an excerpt around matches so they can be marked without using innerHTML. */
function highlightParts(text: string, query: string): Node[] {
    const nodes: Node[] = [];
    const lower = text.toLowerCase();
    const needle = query.toLowerCase();
    let from = 0;
    for (let at = lower.indexOf(needle); at !== -1 && needle; at = lower.indexOf(needle, from)) {
        if (at > from) nodes.push(document.createTextNode(text.slice(from, at)));
        const mark = document.createElement('mark');
        mark.textContent = text.slice(at, at + needle.length);
        nodes.push(mark);
        from = at + needle.length;
    }
    nodes.push(document.createTextNode(text.slice(from)));
    return nodes;
}
