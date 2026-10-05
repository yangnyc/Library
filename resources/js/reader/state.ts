import { Annotation, Bookmark, LocatorType, Progress, ScopedStore, scopeFor, session, uuid } from '../lib/store';
import { Conflict, fetchSession, syncBooks } from '../lib/sync';
import type { ReaderConfig } from './types';

export interface Position {
    locatorType: LocatorType;
    locator: string;
    fraction: number | null;
    label: string | null;
}

type Listener = (status: 'guest' | 'signed-in' | 'syncing' | 'synced' | 'offline' | 'failed', detail?: string) => void;

/**
 * Reading state for the open book: where it is stored, when it is written and
 * when it is synced. The reader engines only call the methods here.
 *
 * Writes are debounced: turning pages quickly produces one local write per
 * second and, for signed-in readers, at most one sync request per 20 seconds
 * (plus one when the page is hidden or closed).
 */
export class ReaderState {
    private store: ScopedStore | null = null;
    private csrf: string | null = null;
    private userName: string | null = null;
    private localTimer: number | undefined;
    private syncTimer: number | undefined;
    private pending: Position | null = null;
    private syncing = false;
    private listener: Listener = () => undefined;
    public onConflict: (conflict: Conflict) => void = () => undefined;
    public onRemoteChange: () => void = () => undefined;

    constructor(private readonly config: ReaderConfig) {}

    get enabled(): boolean {
        return this.store !== null;
    }

    get signedIn(): boolean {
        return this.csrf !== null || (this.store !== null && this.store.scope !== 'guest');
    }

    onStatus(listener: Listener): void {
        this.listener = listener;
    }

    /** Finds out who is reading and loads their local state. Staff previews save nothing. */
    async start(): Promise<void> {
        if (this.config.preview) return;

        const { info, online } = await fetchSession(this.config.urls.session);
        let userId: number | null = null;

        if (online && info) {
            userId = info.user?.id ?? null;
            this.csrf = info.user ? info.csrf : null;
            this.userName = info.user?.name ?? null;
        } else {
            // Offline: keep using the scope of whoever was last signed in here.
            const known = session.get();
            userId = known?.userId ?? null;
            this.userName = known?.name ?? null;
        }

        this.store = new ScopedStore(scopeFor(userId));
        this.store.remember(this.config.contentKey, { fileId: this.config.fileId, title: this.config.title });

        if (userId === null) {
            this.listener('guest');
        } else if (!online) {
            this.listener('offline', this.userName ?? '');
        } else {
            this.listener('signed-in', this.userName ?? '');
            await this.sync();
        }

        window.addEventListener('online', () => void this.reconnect());
        document.addEventListener('visibilitychange', () => {
            if (document.visibilityState === 'hidden') this.flush(true);
        });
        window.addEventListener('pagehide', () => this.flush(true));
    }

    prefs<T extends object>(fallback: T): T {
        return this.store ? this.store.prefs(fallback) : fallback;
    }

    savePrefs(value: object): void {
        this.store?.setPrefs(value);
    }

    progress(): Progress | null {
        return this.store?.progress(this.config.contentKey) ?? null;
    }

    /** Called on every relocation; cheap. */
    setPosition(position: Position): void {
        if (!this.store) return;
        this.pending = position;
        window.clearTimeout(this.localTimer);
        this.localTimer = window.setTimeout(() => this.writeLocal(), 1000);
    }

    private writeLocal(): void {
        if (!this.store || !this.pending) return;
        const previous = this.store.progress(this.config.contentKey);
        if (previous && previous.locator === this.pending.locator) {
            this.pending = null;
            return;
        }
        this.store.setProgress(this.config.contentKey, {
            fileId: this.config.fileId,
            ...this.pending,
            updatedAt: new Date().toISOString(),
            revision: previous?.revision ?? 0,
            dirty: true,
        });
        this.pending = null;
        this.scheduleSync();
    }

    bookmarks(): Bookmark[] {
        return (this.store?.bookmarks(this.config.contentKey) ?? []).filter((b) => !b.deleted);
    }

    addBookmark(position: Position): Bookmark | null {
        if (!this.store) return null;
        const all = this.store.bookmarks(this.config.contentKey);
        const existing = all.find((b) => !b.deleted && b.locator === position.locator);
        if (existing) return existing;
        const bookmark: Bookmark = {
            uuid: uuid(), fileId: this.config.fileId, locatorType: position.locatorType, locator: position.locator,
            label: position.label, updatedAt: new Date().toISOString(), revision: 0, dirty: true, deleted: false,
        };
        this.store.setBookmarks(this.config.contentKey, [...all, bookmark]);
        this.scheduleSync();
        return bookmark;
    }

    removeBookmark(id: string): void {
        if (!this.store) return;
        const all = this.store.bookmarks(this.config.contentKey);
        // Never-synced items can simply go; synced ones leave a tombstone for the server.
        const next = all
            .filter((b) => !(b.uuid === id && b.revision === 0))
            .map((b) => (b.uuid === id ? { ...b, deleted: true, dirty: true, updatedAt: new Date().toISOString() } : b));
        this.store.setBookmarks(this.config.contentKey, next);
        this.scheduleSync();
    }

    annotations(): Annotation[] {
        return (this.store?.annotations(this.config.contentKey) ?? []).filter((a) => !a.deleted);
    }

    saveAnnotation(input: { uuid?: string; locator: string; locatorType?: LocatorType; quote: string | null; note: string | null; color?: string }): Annotation | null {
        if (!this.store) return null;
        const all = this.store.annotations(this.config.contentKey);
        const now = new Date().toISOString();
        const existing = input.uuid ? all.find((a) => a.uuid === input.uuid) : undefined;
        let saved: Annotation;

        if (existing) {
            saved = { ...existing, note: input.note, type: input.note ? 'note' : 'highlight', updatedAt: now, dirty: true, deleted: false };
            this.store.setAnnotations(this.config.contentKey, all.map((a) => (a.uuid === saved.uuid ? saved : a)));
        } else {
            saved = {
                uuid: uuid(), fileId: this.config.fileId, type: input.note ? 'note' : 'highlight',
                locatorType: input.locatorType ?? 'cfi', locator: input.locator, label: null,
                quote: input.quote ? input.quote.slice(0, 1500) : null, note: input.note, color: input.color ?? 'yellow',
                updatedAt: now, revision: 0, dirty: true, deleted: false,
            };
            this.store.setAnnotations(this.config.contentKey, [...all, saved]);
        }
        this.scheduleSync();
        return saved;
    }

    removeAnnotation(id: string): void {
        if (!this.store) return;
        const all = this.store.annotations(this.config.contentKey);
        const next = all
            .filter((a) => !(a.uuid === id && a.revision === 0))
            .map((a) => (a.uuid === id ? { ...a, deleted: true, dirty: true, updatedAt: new Date().toISOString() } : a));
        this.store.setAnnotations(this.config.contentKey, next);
        this.scheduleSync();
    }

    private scheduleSync(): void {
        if (!this.csrf) return;
        window.clearTimeout(this.syncTimer);
        this.syncTimer = window.setTimeout(() => void this.sync(), 20000);
    }

    /** Writes anything pending now; with `leaving`, syncs with keepalive so it survives the page closing. */
    flush(leaving = false): void {
        window.clearTimeout(this.localTimer);
        this.writeLocal();
        if (leaving && this.csrf && this.store && navigator.onLine && this.hasUnsent()) {
            window.clearTimeout(this.syncTimer);
            void syncBooks(this.store, [this.book()], this.csrf, this.config.urls.sync, true).catch(() => undefined);
        }
    }

    private hasUnsent(): boolean {
        if (!this.store) return false;
        const key = this.config.contentKey;
        return Boolean(this.store.progress(key)?.dirty) || this.store.bookmarks(key).some((b) => b.dirty) || this.store.annotations(key).some((a) => a.dirty);
    }

    private book() {
        return { fileId: this.config.fileId, contentKey: this.config.contentKey };
    }

    private async reconnect(): Promise<void> {
        if (!this.store || this.store.scope === 'guest') return;
        if (!this.csrf) {
            const { info } = await fetchSession(this.config.urls.session);
            // Only sync if the same account is still signed in on the server.
            if (!info?.user || scopeFor(info.user.id) !== this.store.scope) return;
            this.csrf = info.csrf;
        }
        await this.sync();
    }

    async sync(): Promise<void> {
        if (!this.store || !this.csrf || this.syncing) return;
        if (!navigator.onLine) {
            this.listener('offline', this.userName ?? '');
            return;
        }
        this.syncing = true;
        this.listener('syncing');
        try {
            const before = JSON.stringify([this.bookmarks(), this.annotations()]);
            const conflicts = await syncBooks(this.store, [this.book()], this.csrf, this.config.urls.sync);
            this.listener('synced');
            conflicts.filter((c) => c.fileId === this.config.fileId).forEach((c) => this.onConflict(c));
            if (before !== JSON.stringify([this.bookmarks(), this.annotations()])) this.onRemoteChange();
        } catch {
            this.listener(navigator.onLine ? 'failed' : 'offline', this.userName ?? '');
            this.scheduleSync();
        } finally {
            this.syncing = false;
        }
    }
}
