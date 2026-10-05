import { Annotation, Bookmark, Progress, ScopedStore, deviceId, session } from './store';

export interface SessionInfo {
    user: { id: number; name: string; preferences: Record<string, unknown> | null; preferencesRevision: number } | null;
    csrf: string;
}

export interface Conflict {
    kind: 'progress' | 'annotation';
    fileId: number;
    kept?: string;
    other?: { locatorType: string; locator: string; fraction: number | null; label: string | null };
}

/**
 * Who is signed in, asked from the server on every page load and never
 * cached. Offline, the last known identity is used so reading state still
 * goes to the right local scope.
 */
export async function fetchSession(url = '/api/session'): Promise<{ info: SessionInfo | null; online: boolean }> {
    try {
        const controller = new AbortController();
        const timer = setTimeout(() => controller.abort(), 5000);
        const response = await fetch(url, { credentials: 'same-origin', cache: 'no-store', signal: controller.signal, headers: { Accept: 'application/json' } });
        clearTimeout(timer);
        if (!response.ok) throw new Error(String(response.status));
        const info = (await response.json()) as SessionInfo;
        session.set(info.user ? { userId: info.user.id, name: info.user.name } : null);
        return { info, online: true };
    } catch {
        return { info: null, online: false };
    }
}

interface SyncResponse {
    progress: Array<Omit<Progress, 'dirty'>>;
    bookmarks: Array<Omit<Bookmark, 'dirty'>>;
    annotations: Array<Omit<Annotation, 'dirty'>>;
    preferences: { value: Record<string, unknown> | null; revision: number };
    conflicts: Conflict[];
    acceptedFileIds: number[];
}

export interface BookKey {
    fileId: number;
    contentKey: string;
}

/**
 * Sends this device's unsent changes for the given books and applies the
 * server's answer. Local items changed while the request was in flight stay
 * dirty and go out with the next sync.
 */
export async function syncBooks(store: ScopedStore, books: BookKey[], csrf: string, url = '/api/sync', keepalive = false): Promise<Conflict[]> {
    const sent = {
        progress: [] as Progress[],
        bookmarks: [] as Bookmark[],
        annotations: [] as Annotation[],
    };
    const keyOf = new Map<number, string>();

    for (const { fileId, contentKey } of books) {
        keyOf.set(fileId, contentKey);
        const progress = store.progress(contentKey);
        if (progress?.dirty) sent.progress.push(progress);
        sent.bookmarks.push(...store.bookmarks(contentKey).filter((b) => b.dirty));
        sent.annotations.push(...store.annotations(contentKey).filter((a) => a.dirty));
    }

    const body = {
        deviceId: deviceId(),
        fileIds: books.map((b) => b.fileId),
        progress: sent.progress.map((p) => ({ ...p, baseRevision: p.revision })),
        bookmarks: sent.bookmarks.slice(0, 200).map((b) => ({ ...b, baseRevision: b.revision })),
        annotations: sent.annotations.slice(0, 200).map((a) => ({ ...a, baseRevision: a.revision })),
    };

    const response = await fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        keepalive,
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest' },
        body: JSON.stringify(body),
    });
    if (!response.ok) throw new Error('sync ' + response.status);
    const data = (await response.json()) as SyncResponse;

    for (const { fileId, contentKey } of books) {
        if (!data.acceptedFileIds.includes(fileId)) continue;

        const serverProgress = data.progress.find((p) => p.fileId === fileId);
        const localProgress = store.progress(contentKey);
        const sentProgress = sent.progress.find((p) => p.fileId === fileId);
        const changedSince = localProgress && sentProgress && localProgress.updatedAt !== sentProgress.updatedAt;
        if (serverProgress && !changedSince) {
            store.setProgress(contentKey, { ...serverProgress, updatedAt: serverProgress.updatedAt ?? new Date().toISOString(), dirty: false });
        } else if (serverProgress && localProgress) {
            store.setProgress(contentKey, { ...localProgress, revision: serverProgress.revision });
        }

        store.setBookmarks(contentKey, mergeList(store.bookmarks(contentKey), sent.bookmarks, data.bookmarks.filter((b) => b.fileId === fileId)));
        store.setAnnotations(contentKey, mergeList(store.annotations(contentKey), sent.annotations, data.annotations.filter((a) => a.fileId === fileId)));
    }

    return data.conflicts ?? [];
}

function mergeList<T extends Bookmark>(local: T[], sent: T[], server: Array<Omit<T, 'dirty'>>): T[] {
    const sentAt = new Map(sent.map((item) => [item.uuid, item.updatedAt]));
    const result = new Map<string, T>();

    for (const item of server) {
        if (!item.deleted) result.set(item.uuid, { ...(item as T), dirty: false });
    }
    for (const item of local) {
        if (!item.dirty) continue; // clean local copies are replaced by the server's view
        const wasSent = sentAt.has(item.uuid);
        const editedDuringRequest = wasSent && sentAt.get(item.uuid) !== item.updatedAt;
        const beyondBatch = !wasSent;
        if (editedDuringRequest || beyondBatch) {
            const serverItem = server.find((s) => s.uuid === item.uuid);
            result.set(item.uuid, { ...item, revision: serverItem?.revision ?? item.revision });
        }
    }
    return [...result.values()];
}
