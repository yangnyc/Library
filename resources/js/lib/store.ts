/**
 * Reading state kept in this browser.
 *
 * Everything is namespaced by a scope — "guest" or "u<id>" — so one person's
 * synced data never mixes with another's or with guest data on a shared
 * device, and signing out can remove exactly one account's keys.
 *
 * Keys also include the file's content key, so state made on one version of a
 * file is never applied to a replaced version.
 */

export type LocatorType = 'cfi' | 'page' | 'anchor';

export interface Progress {
    fileId: number;
    locatorType: LocatorType;
    locator: string;
    fraction: number | null;
    label: string | null;
    updatedAt: string;
    revision: number; // last revision seen from the server; 0 = never synced
    dirty: boolean;
}

export interface Bookmark {
    uuid: string;
    fileId: number;
    locatorType: LocatorType;
    locator: string;
    label: string | null;
    updatedAt: string;
    revision: number;
    dirty: boolean;
    deleted: boolean;
}

export interface Annotation extends Bookmark {
    type: 'highlight' | 'note';
    quote: string | null;
    note: string | null;
    color: string;
    conflictOf?: string | null;
}

export interface BookRef {
    fileId: number;
    title: string;
}

export interface Session {
    userId: number;
    name: string;
}

const PREFIX = 'orl:';

function read<T>(key: string, fallback: T): T {
    try {
        const raw = localStorage.getItem(key);
        return raw === null ? fallback : (JSON.parse(raw) as T);
    } catch {
        return fallback;
    }
}

/** Returns false when the browser refuses (private mode, quota). */
function write(key: string, value: unknown): boolean {
    try {
        localStorage.setItem(key, JSON.stringify(value));
        return true;
    } catch {
        return false;
    }
}

function remove(key: string): void {
    try {
        localStorage.removeItem(key);
    } catch {
        /* nothing to do */
    }
}

function keys(prefix: string): string[] {
    const found: string[] = [];
    try {
        for (let i = 0; i < localStorage.length; i++) {
            const key = localStorage.key(i);
            if (key && key.startsWith(prefix)) found.push(key);
        }
    } catch {
        /* storage unavailable */
    }
    return found;
}

export function uuid(): string {
    if (typeof crypto.randomUUID === 'function') return crypto.randomUUID();
    const b = crypto.getRandomValues(new Uint8Array(16));
    b[6] = (b[6] & 0x0f) | 0x40;
    b[8] = (b[8] & 0x3f) | 0x80;
    const h = [...b].map((x) => x.toString(16).padStart(2, '0')).join('');
    return `${h.slice(0, 8)}-${h.slice(8, 12)}-${h.slice(12, 16)}-${h.slice(16, 20)}-${h.slice(20)}`;
}

export function deviceId(): string {
    let id = read<string | null>(`${PREFIX}device`, null);
    if (!id) {
        id = uuid();
        write(`${PREFIX}device`, id);
    }
    return id;
}

export const session = {
    get: (): Session | null => read<Session | null>(`${PREFIX}session`, null),
    set: (value: Session | null): void => (value ? void write(`${PREFIX}session`, value) : remove(`${PREFIX}session`)),
};

export const scopeFor = (userId: number | null | undefined): string => (userId ? `u${userId}` : 'guest');

/** Removes one account's synced reading data from this browser (sign-out, account deletion). */
export function clearAccount(userId: number): void {
    keys(`${PREFIX}u${userId}:`).forEach(remove);
    const current = session.get();
    if (current && current.userId === userId) session.set(null);
}

export class ScopedStore {
    constructor(public readonly scope: string) {}

    private key(kind: string, contentKey?: string): string {
        return `${PREFIX}${this.scope}:${kind}${contentKey ? ':' + contentKey : ''}`;
    }

    /** Which books this scope holds state for, by content key. */
    index(): Record<string, BookRef> {
        return read(this.key('index'), {} as Record<string, BookRef>);
    }

    remember(contentKey: string, ref: BookRef): void {
        const index = this.index();
        if (!index[contentKey] || index[contentKey].title !== ref.title) {
            index[contentKey] = ref;
            write(this.key('index'), index);
        }
    }

    progress(contentKey: string): Progress | null {
        return read<Progress | null>(this.key('progress', contentKey), null);
    }

    setProgress(contentKey: string, value: Progress): boolean {
        return write(this.key('progress', contentKey), value);
    }

    bookmarks(contentKey: string): Bookmark[] {
        return read<Bookmark[]>(this.key('bookmarks', contentKey), []);
    }

    setBookmarks(contentKey: string, value: Bookmark[]): boolean {
        return write(this.key('bookmarks', contentKey), value);
    }

    annotations(contentKey: string): Annotation[] {
        return read<Annotation[]>(this.key('annotations', contentKey), []);
    }

    setAnnotations(contentKey: string, value: Annotation[]): boolean {
        return write(this.key('annotations', contentKey), value);
    }

    prefs<T extends object>(fallback: T): T {
        return { ...fallback, ...read<Partial<T>>(this.key('prefs'), {}) };
    }

    setPrefs(value: object): void {
        write(this.key('prefs'), value);
    }

    flag(name: string): boolean {
        return read<boolean>(this.key('flag:' + name), false);
    }

    setFlag(name: string, value: boolean): void {
        value ? write(this.key('flag:' + name), true) : remove(this.key('flag:' + name));
    }

    hasReadingData(): boolean {
        return Object.keys(this.index()).some(
            (k) => this.progress(k) !== null || this.bookmarks(k).some((b) => !b.deleted) || this.annotations(k).some((a) => !a.deleted),
        );
    }

    /** Removes the reading data of this scope (used after a guest merge). */
    clearReadingData(): void {
        for (const k of Object.keys(this.index())) {
            remove(this.key('progress', k));
            remove(this.key('bookmarks', k));
            remove(this.key('annotations', k));
        }
        remove(this.key('index'));
    }
}

/**
 * Copies guest reading data into an account scope. Only ever called after the
 * reader explicitly agreed to it. Copied items are marked dirty so the next
 * sync sends them; existing account items are never overwritten.
 */
export function mergeGuestInto(account: ScopedStore): number[] {
    const guest = new ScopedStore('guest');
    const fileIds: number[] = [];

    for (const [contentKey, ref] of Object.entries(guest.index())) {
        account.remember(contentKey, ref);
        fileIds.push(ref.fileId);

        const guestProgress = guest.progress(contentKey);
        if (guestProgress && !account.progress(contentKey)) {
            account.setProgress(contentKey, { ...guestProgress, revision: 0, dirty: true });
        }

        const bookmarks = account.bookmarks(contentKey);
        const known = new Set(bookmarks.map((b) => b.uuid));
        for (const b of guest.bookmarks(contentKey)) {
            if (!b.deleted && !known.has(b.uuid)) bookmarks.push({ ...b, revision: 0, dirty: true });
        }
        account.setBookmarks(contentKey, bookmarks);

        const annotations = account.annotations(contentKey);
        const knownNotes = new Set(annotations.map((a) => a.uuid));
        for (const a of guest.annotations(contentKey)) {
            if (!a.deleted && !knownNotes.has(a.uuid)) annotations.push({ ...a, revision: 0, dirty: true });
        }
        account.setAnnotations(contentKey, annotations);
    }

    guest.clearReadingData();
    return fileIds;
}
