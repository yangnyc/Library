/**
 * Reader entry point. Loaded only on reader pages; the EPUB and PDF engines
 * are separate chunks so a reader downloads only the one the book needs.
 */
import { getRecord, isAvailableOffline } from '../lib/offline';
import { ReaderState } from './state';
import type { Engine, ReaderConfig } from './types';
import { ReaderUi } from './ui';

const config = JSON.parse(document.getElementById('reader-config')!.textContent!) as ReaderConfig;
const state = new ReaderState(config);
const ui = new ReaderUi(config, state);

state.onStatus((status, detail) => {
    switch (status) {
        case 'guest':
            ui.setIdentity(ui.t('guestNotice'));
            break;
        case 'signed-in':
            ui.setIdentity(ui.t('signedInAs', { name: detail ?? '' }));
            break;
        case 'syncing':
            ui.setSyncStatus(ui.t('syncing'));
            break;
        case 'synced':
            ui.setSyncStatus(ui.t('synced'));
            break;
        case 'offline':
            ui.setSyncStatus(ui.t('syncOffline'));
            break;
        case 'failed':
            ui.setSyncStatus(ui.t('syncFailed'));
            break;
    }
});

async function boot(): Promise<void> {
    await state.start();

    let engine: Engine;
    try {
        if (config.format === 'pdf') {
            const { createPdfEngine } = await import('./pdf');
            engine = await createPdfEngine(config, state, ui);
        } else {
            const { createEpubEngine } = await import('./epub');
            engine = await createEpubEngine(config, state, ui);
        }
    } catch (error) {
        console.error(error);
        const saved = await isAvailableOffline(getRecord(config.fileId));
        ui.fail(!navigator.onLine && !saved ? ui.t('loadErrorOffline') : ui.t('loadError'));
        return;
    }

    ui.attach(engine);
    ui.loaded();

    state.onRemoteChange = () => {
        ui.renderMarks();
        engine.refreshAnnotations();
    };
    state.onConflict = (conflict) => {
        if (conflict.kind === 'progress' && conflict.other && conflict.other.locator !== engine.current()?.locator) {
            ui.offerOtherPosition(conflict.other.label ?? '', conflict.other.locator);
        } else if (conflict.kind === 'annotation') {
            ui.notice(ui.t('conflictNote'));
        }
    };

    // Keyboard: arrows follow the book's page direction, not the interface's.
    document.addEventListener('keydown', (event) => {
        if (event.defaultPrevented || event.altKey || event.ctrlKey || event.metaKey) return;
        const target = event.target as HTMLElement;
        if (target.closest('input, textarea, select, dialog, [contenteditable]')) return;
        handleKey(event, engine);
    });
    (window as unknown as { __readerKey?: (e: KeyboardEvent) => void }).__readerKey = (event) => handleKey(event, engine);

    // Test and support hook: read-only view of where the reader is.
    (window as unknown as { __reader?: unknown }).__reader = { engine, state, config };
    document.body.dataset.ready = 'true';
}

export function handleKey(event: KeyboardEvent, engine: Engine): void {
    const rtl = document.querySelector('.rstage')?.getAttribute('dir') === 'rtl';
    switch (event.key) {
        case 'ArrowRight':
            rtl ? engine.prev() : engine.next();
            break;
        case 'ArrowLeft':
            rtl ? engine.next() : engine.prev();
            break;
        case 'PageDown':
            engine.next();
            break;
        case 'PageUp':
            engine.prev();
            break;
        default:
            return;
    }
    event.preventDefault();
}

void boot();
