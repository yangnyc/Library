/*
 * Service worker. Plain JavaScript, served from the site root so its scope is
 * the whole site. It is deliberately conservative:
 *
 *  - It never stores anything by itself except immutable, hashed build files.
 *    Books and page shells are stored only by the page, when the reader
 *    presses "Save for offline reading".
 *  - Administration, account, sign-in, API and download URLs are never
 *    answered from or written to a cache.
 *  - Everything falls back to the network, so the site behaves normally if
 *    this file fails or is unsupported.
 */

const SHELL_CACHE = 'orl-shell-v2';
const KNOWN_PREFIXES = ['orl-shell-', 'orl-book-'];

// Never touched by the worker: private, authenticated or state-changing areas.
const PRIVATE = /^\/(admin|api|login|logout|register|user|two-factor-challenge|forgot-password|reset-password|email|download|healthz)(\/|$)|^\/[a-z]{2}(-[A-Za-z]+)?\/account(\/|$)/;

self.addEventListener('install', () => self.skipWaiting());

self.addEventListener('activate', (event) => {
    event.waitUntil((async () => {
        // Drop shell caches from older versions of this worker.
        for (const name of await caches.keys()) {
            if (name.startsWith('orl-shell-') && name !== SHELL_CACHE) await caches.delete(name);
        }
        await self.clients.claim();
    })());
});

self.addEventListener('fetch', (event) => {
    const request = event.request;
    if (request.method !== 'GET') return;

    const url = new URL(request.url);
    const sameOrigin = url.origin === self.location.origin;

    if (sameOrigin && PRIVATE.test(url.pathname)) return;

    if (url.pathname.startsWith('/content/')) {
        event.respondWith(fromBookCache(request));
        return;
    }
    if (!sameOrigin) return;

    if (url.pathname.startsWith('/build/')) {
        event.respondWith(immutableAsset(request));
        return;
    }
    if (/^\/(icons|vendor|media)\//.test(url.pathname) || url.pathname === '/manifest.webmanifest') {
        event.respondWith(caches.match(request, { ignoreSearch: true }).then((hit) => hit || fetch(request)));
        return;
    }
    if (request.mode === 'navigate') {
        event.respondWith(navigation(request, url));
    }
});

/** Saved book resources first; otherwise the network. Supports byte ranges for PDF. */
async function fromBookCache(request) {
    const cached = await caches.match(request.url, { ignoreVary: true });
    if (!cached) return fetch(request);

    const range = request.headers.get('Range');
    if (!range) return cached;

    const match = /^bytes=(\d*)-(\d*)$/.exec(range.trim());
    if (!match) return cached;

    const buffer = await cached.arrayBuffer();
    const size = buffer.byteLength;
    let start = match[1] === '' ? Math.max(0, size - Number(match[2])) : Number(match[1]);
    let end = match[1] === '' || match[2] === '' ? size - 1 : Math.min(Number(match[2]), size - 1);
    if (start >= size || start > end) {
        return new Response('', { status: 416, headers: { 'Content-Range': `bytes */${size}` } });
    }

    return new Response(buffer.slice(start, end + 1), {
        status: 206,
        headers: {
            'Content-Type': cached.headers.get('Content-Type') || 'application/octet-stream',
            'Content-Range': `bytes ${start}-${end}/${size}`,
            'Content-Length': String(end - start + 1),
            'Accept-Ranges': 'bytes',
        },
    });
}

/** Build files have content hashes in their names, so a cached copy never goes stale. */
async function immutableAsset(request) {
    const cached = await caches.match(request, { ignoreSearch: true });
    if (cached) return cached;

    const response = await fetch(request);
    if (response.ok && response.type === 'basic') {
        const copy = response.clone();
        caches.open(SHELL_CACHE).then((cache) => cache.put(request, copy)).catch(() => undefined);
    }
    return response;
}

/** Pages: always the network when there is one. Offline, only pages the reader saved. */
async function navigation(request, url) {
    try {
        return await fetch(request);
    } catch (error) {
        const saved = await caches.match(url.pathname, { ignoreSearch: true });
        if (saved) return saved;

        const locale = url.pathname.split('/')[1] || 'en';
        const shelf = await caches.match(`/${locale}/offline`, { ignoreSearch: true });
        if (shelf) return shelf;

        return new Response(
            '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Offline</title>'
            + '<body style="font-family:system-ui,sans-serif;padding:2rem;line-height:1.6"><h1>Offline</h1>'
            + '<p>This page has not been saved for offline use. Reconnect and try again.</p></body>',
            { status: 503, headers: { 'Content-Type': 'text/html; charset=utf-8' } },
        );
    }
}

// Lets a page ask which caches this worker knows about (used by tests).
self.addEventListener('message', (event) => {
    if (event.data === 'orl:caches') {
        caches.keys().then((names) => event.ports[0]?.postMessage(names.filter((n) => KNOWN_PREFIXES.some((p) => n.startsWith(p)))));
    }
});
