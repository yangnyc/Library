/** Offline shelf: lists the books saved on this device and their real state. */
import { OfflineError, OfflineRecord, fetchManifest, formatBytes, isAvailableOffline, listRecords, offlineSupported, removeBook, saveBook, storageEstimate } from './lib/offline';

const strings = JSON.parse(document.getElementById('offline-strings')?.textContent ?? '{}') as Record<string, string>;
const list = document.querySelector<HTMLElement>('[data-offline-shelf]');
const empty = document.querySelector<HTMLElement>('[data-offline-empty]');
const storage = document.querySelector<HTMLElement>('[data-offline-storage]');
const unsupported = document.querySelector<HTMLElement>('[data-offline-unsupported]');

function button(text: string, onClick: () => void, ghost = true): HTMLButtonElement {
    const el = document.createElement('button');
    el.type = 'button';
    el.className = 'button button--small' + (ghost ? ' button--ghost' : '');
    el.textContent = text;
    el.addEventListener('click', onClick);
    return el;
}

async function showStorage(): Promise<void> {
    if (!storage) return;
    const estimate = await storageEstimate();
    storage.textContent = estimate
        ? strings.storage.replace(':used', formatBytes(estimate.usage)).replace(':quota', formatBytes(estimate.quota))
        : strings.storage_unknown;
}

async function row(record: OfflineRecord): Promise<HTMLLIElement> {
    const li = document.createElement('li');
    const title = document.createElement('h2');
    const bdi = document.createElement('bdi');
    bdi.lang = record.language;
    bdi.dir = record.direction === 'rtl' ? 'rtl' : record.direction === 'ltr' ? 'ltr' : 'auto';
    bdi.textContent = record.title;
    title.append(bdi);

    const status = document.createElement('p');
    status.className = 'hint';
    const actions = document.createElement('div');
    actions.className = 'shelf-list__row';
    li.append(title, status, actions);

    const available = await isAvailableOffline(record);
    const openLink = document.createElement('a');
    openLink.className = 'button button--small';
    openLink.href = `${strings.readUrl}/${encodeURIComponent(record.editionSlug)}/${record.format}`;
    openLink.textContent = strings.open;

    const remove = button(strings.remove, async () => {
        await removeBook(record.fileId);
        await render();
    });

    const resave = (label: string, fileId: number) => button(label, async (event?: Event) => {
        status.textContent = '…';
        try {
            await saveBook(`${strings.manifestUrl}/${fileId}`, (f) => (status.textContent = Math.round(f * 100) + '%'), new AbortController().signal);
            if (fileId !== record.fileId) await removeBook(record.fileId);
        } catch (error) {
            status.textContent = error instanceof OfflineError && error.reason === 'quota' ? strings.state_partial : strings.state_partial;
        }
        await render();
    }, false);

    if (!available) {
        // Interrupted, or the browser evicted it: never shown as readable.
        status.textContent = strings.state_partial;
        actions.append(resave(strings.retry, record.fileId), remove);
        return li;
    }

    status.textContent = `${strings.state_complete} · ${formatBytes(record.bytes)} · ${record.format.toUpperCase()}`;
    actions.append(openLink, remove);

    // When online, check whether the library still offers this exact version.
    if (navigator.onLine) {
        fetchManifest(`${strings.manifestUrl}/${record.fileId}`)
            .then((manifest) => {
                if (manifest.contentKey !== record.contentKey) {
                    status.textContent = strings.state_updated;
                    actions.append(resave(strings.update, record.fileId));
                }
            })
            .catch((error: OfflineError & { replacementFileId?: number | null }) => {
                if (!(error instanceof OfflineError) || error.reason !== 'unavailable') return;
                if (error.replacementFileId) {
                    status.textContent = strings.state_updated;
                    actions.append(resave(strings.update, error.replacementFileId));
                } else {
                    // The copy on this device still opens; the library just no longer offers it.
                    status.textContent = strings.state_withdrawn;
                }
            });
    }
    return li;
}

async function render(): Promise<void> {
    if (!list || !empty) return;
    const records = listRecords();
    list.replaceChildren(...(await Promise.all(records.map(row))));
    empty.hidden = records.length > 0;
    await showStorage();
}

if (!offlineSupported()) {
    if (unsupported) unsupported.hidden = false;
    if (empty) empty.hidden = false;
} else {
    void render();
}
