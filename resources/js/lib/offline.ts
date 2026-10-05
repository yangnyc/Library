/**
 * Explicit, per-book offline storage.
 *
 * A book is saved only when the reader asks for it. Its resources go into a
 * Cache Storage bucket of its own; a small record in localStorage says what
 * state the copy is in. A book is reported as available offline only when the
 * record says "complete" — never while (or after) a save was interrupted.
 */

export interface OfflineRecord {
    fileId: number;
    contentKey: string;
    format: string;
    title: string;
    language: string;
    direction: string;
    editionSlug: string;
    state: 'downloading' | 'complete';
    resources: number;
    bytes: number;
    savedAt: string;
}

interface Manifest {
    available: boolean;
    fileId: number;
    contentKey: string;
    format: string;
    title: string;
    language: string;
    direction: string;
    editionSlug: string;
    resources: Array<{ url: string; bytes: number }>;
    pages: string[];
    assets: string[];
    totalBytes: number;
    replacementFileId?: number | null;
}

export class OfflineError extends Error {
    constructor(public readonly reason: 'quota' | 'network' | 'aborted' | 'unavailable' | 'unsupported') {
        super(reason);
    }
}

export const SHELL_CACHE = 'orl-shell-v2';
const RECORD_PREFIX = 'orl:offline:';
const bookCache = (contentKey: string) => `orl-book-${contentKey}`;

export function offlineSupported(): boolean {
    return 'caches' in window && 'serviceWorker' in navigator && window.isSecureContext;
}

export function getRecord(fileId: number): OfflineRecord | null {
    try {
        const raw = localStorage.getItem(RECORD_PREFIX + fileId);
        return raw ? (JSON.parse(raw) as OfflineRecord) : null;
    } catch {
        return null;
    }
}

function putRecord(record: OfflineRecord): void {
    try {
        localStorage.setItem(RECORD_PREFIX + record.fileId, JSON.stringify(record));
    } catch {
        throw new OfflineError('quota');
    }
}

export function listRecords(): OfflineRecord[] {
    const records: OfflineRecord[] = [];
    try {
        for (let i = 0; i < localStorage.length; i++) {
            const key = localStorage.key(i);
            if (key?.startsWith(RECORD_PREFIX)) {
                const record = getRecord(Number(key.slice(RECORD_PREFIX.length)));
                if (record) records.push(record);
            }
        }
    } catch {
        /* storage unavailable */
    }
    return records.sort((a, b) => a.title.localeCompare(b.title));
}

/**
 * True only if the record is complete and the browser still holds every
 * resource. Browsers may evict cached data; this notices when they did.
 */
export async function isAvailableOffline(record: OfflineRecord | null): Promise<boolean> {
    if (!record || record.state !== 'complete' || !('caches' in window)) return false;
    try {
        if (!(await caches.has(bookCache(record.contentKey)))) return false;
        const cache = await caches.open(bookCache(record.contentKey));
        return (await cache.keys()).length >= record.resources;
    } catch {
        return false;
    }
}

export async function removeBook(fileId: number): Promise<void> {
    const record = getRecord(fileId);
    if (record && 'caches' in window) await caches.delete(bookCache(record.contentKey));
    try {
        localStorage.removeItem(RECORD_PREFIX + fileId);
    } catch {
        /* nothing to do */
    }
}

export async function storageEstimate(): Promise<{ usage: number; quota: number } | null> {
    if (!navigator.storage?.estimate) return null;
    try {
        const { usage = 0, quota = 0 } = await navigator.storage.estimate();
        return { usage, quota };
    } catch {
        return null;
    }
}

export function formatBytes(bytes: number): string {
    if (bytes >= 1073741824) return (bytes / 1073741824).toFixed(1) + ' GB';
    if (bytes >= 1048576) return (bytes / 1048576).toFixed(1) + ' MB';
    return Math.max(1, Math.round(bytes / 1024)) + ' KB';
}

export async function fetchManifest(url: string): Promise<Manifest> {
    let response: Response;
    try {
        response = await fetch(url, { cache: 'no-store', credentials: 'omit', headers: { Accept: 'application/json' } });
    } catch {
        throw new OfflineError('network');
    }
    if (response.status === 410 || response.status === 404) {
        const body = (await response.json().catch(() => ({}))) as Partial<Manifest>;
        const error = new OfflineError('unavailable');
        (error as OfflineError & { replacementFileId?: number | null }).replacementFileId = body.replacementFileId ?? null;
        throw error;
    }
    if (!response.ok) throw new OfflineError('network');
    return (await response.json()) as Manifest;
}

function isQuota(error: unknown): boolean {
    return error instanceof DOMException && (error.name === 'QuotaExceededError' || error.name === 'NS_ERROR_DOM_QUOTA_REACHED');
}

/** Fetches without credentials: what is stored is the public, non-personal response. */
async function fetchForCache(url: string, signal: AbortSignal): Promise<Response> {
    for (let attempt = 0; ; attempt++) {
        let response: Response;
        try {
            response = await fetch(url, { credentials: 'omit', cache: 'no-cache', signal });
        } catch (error) {
            if (signal.aborted) throw new OfflineError('aborted');
            if (attempt >= 2) throw new OfflineError('network');
            await new Promise((r) => setTimeout(r, 800 * (attempt + 1)));
            continue;
        }
        // The content endpoints are rate-limited; wait and continue rather than fail.
        if (response.status === 429 && attempt < 6) {
            const wait = Math.min(30, Number(response.headers.get('Retry-After')) || 5);
            await new Promise((r) => setTimeout(r, wait * 1000));
            continue;
        }
        if (!response.ok) throw new OfflineError(response.status === 404 || response.status === 410 ? 'unavailable' : 'network');
        return response;
    }
}

/**
 * Saves one book. Resolves only when every resource is stored; on any
 * failure the partial copy is deleted and an OfflineError is thrown.
 */
export async function saveBook(manifestUrl: string, onProgress: (fraction: number) => void, signal: AbortSignal): Promise<OfflineRecord> {
    if (!offlineSupported()) throw new OfflineError('unsupported');

    const manifest = await fetchManifest(manifestUrl);
    if (!manifest.available) throw new OfflineError('unavailable');

    const estimate = await storageEstimate();
    if (estimate && estimate.quota > 0 && estimate.quota - estimate.usage < manifest.totalBytes * 1.15) {
        throw new OfflineError('quota');
    }

    // Replace any earlier copy of this file (older content version included).
    await removeBook(manifest.fileId);

    const record: OfflineRecord = {
        fileId: manifest.fileId,
        contentKey: manifest.contentKey,
        format: manifest.format,
        title: manifest.title,
        language: manifest.language,
        direction: manifest.direction,
        editionSlug: manifest.editionSlug,
        state: 'downloading',
        resources: manifest.resources.length,
        bytes: manifest.totalBytes,
        savedAt: new Date().toISOString(),
    };
    putRecord(record);

    const cacheName = bookCache(manifest.contentKey);
    try {
        const cache = await caches.open(cacheName);
        const total = Math.max(1, manifest.totalBytes);
        let done = 0;

        const queue = [...manifest.resources];
        const worker = async () => {
            for (let item = queue.shift(); item; item = queue.shift()) {
                if (signal.aborted) throw new OfflineError('aborted');
                await cache.put(item.url, await fetchForCache(item.url, signal));
                done += item.bytes;
                onProgress(Math.min(0.97, done / total));
            }
        };
        await Promise.all([worker(), worker(), worker(), worker()]);

        // The pages and scripts needed to open the book without a connection.
        const shell = await caches.open(SHELL_CACHE);
        for (const url of [...manifest.pages, ...manifest.assets, '/manifest.webmanifest', '/icons/icon-192.png']) {
            if (signal.aborted) throw new OfflineError('aborted');
            await shell.put(url, await fetchForCache(url, signal));
        }
        if (manifest.format === 'pdf') {
            for (const url of PDF_RUNTIME) {
                await shell.put(url, await fetchForCache(url, signal)).catch(() => undefined);
            }
        }

        record.state = 'complete';
        record.savedAt = new Date().toISOString();
        putRecord(record);
        onProgress(1);

        // Ask the browser not to evict this origin's data. It may say no.
        void navigator.storage?.persist?.().catch(() => false);

        return record;
    } catch (error) {
        await caches.delete(cacheName).catch(() => false);
        try {
            localStorage.removeItem(RECORD_PREFIX + manifest.fileId);
        } catch {
            /* nothing to do */
        }
        if (error instanceof OfflineError) throw error;
        throw new OfflineError(isQuota(error) ? 'quota' : signal.aborted ? 'aborted' : 'network');
    }
}

// Decoders PDF.js may load on demand for some PDFs.
const PDF_RUNTIME = ['/vendor/pdfjs/wasm/openjpeg.wasm', '/vendor/pdfjs/wasm/qcms_bg.wasm', '/vendor/pdfjs/wasm/jbig2.wasm'];
