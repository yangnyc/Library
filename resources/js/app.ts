/**
 * Site-wide behaviour. Every page works without this script; it only adds
 * conveniences (offline notice, service worker, local clean-up). The menu,
 * notices and dialogs built on Bootstrap are in ui.ts.
 */
import { clearAccount, session } from './lib/store';

interface AppConfig {
    userId: number | null;
    clearAccountStorage: number | null;
    swUrl: string;
}

function config(): AppConfig {
    try {
        return JSON.parse(document.getElementById('app-config')?.textContent ?? '{}') as AppConfig;
    } catch {
        return { userId: null, clearAccountStorage: null, swUrl: '/sw.js' };
    }
}

const app = config();

// Keep the catalog's full filter form available without JavaScript.
const filterPanel = document.querySelector<HTMLDetailsElement>('[data-catalog-filters]');
if (filterPanel) {
    const narrow = window.matchMedia('(max-width: 46rem)');
    filterPanel.open = !narrow.matches;
    narrow.addEventListener('change', (event) => {
        filterPanel.open = !event.matches;
    });
}

// Settings page: show the chosen colour theme at once. Saving is a normal form post.
const themeSelect = document.querySelector<HTMLSelectElement>('[data-theme-select]');
themeSelect?.addEventListener('change', () => {
    document.body.dataset.uiTheme = themeSelect.value;
    const scheme = themeSelect.selectedOptions[0]?.dataset.scheme;
    if (scheme) document.querySelector('meta[name="color-scheme"]')?.setAttribute('content', scheme);
});

// Connection notice.
const banner = document.querySelector<HTMLElement>('[data-offline-banner]');
if (banner) {
    const update = () => (banner.hidden = navigator.onLine);
    window.addEventListener('online', update);
    window.addEventListener('offline', update);
    update();
}

// Keep the locally remembered identity in step with the server-rendered page.
if (document.getElementById('app-config') && navigator.onLine) {
    const known = session.get();
    if (!app.userId && known) session.set(null);
}

// After an account was deleted, remove what it had synced into this browser.
if (app.clearAccountStorage) clearAccount(app.clearAccountStorage);

// Signing out removes that account's synced reading data from this browser.
// Offline books are public content and are left alone (see the help page).
document.querySelectorAll<HTMLFormElement>('[data-logout-form]').forEach((form) => {
    form.addEventListener('submit', () => {
        const id = Number(form.dataset.userId);
        if (id && form.matches('[action$="/logout"]')) clearAccount(id);
    });
});

// "Add to reading list": the chosen list decides the form's target.
document.querySelectorAll<HTMLFormElement>('[data-list-form]').forEach((form) => {
    form.addEventListener('submit', () => {
        const select = form.querySelector<HTMLSelectElement>('[data-list-select]');
        form.action = (form.dataset.actionTemplate ?? '').replace('__LIST__', encodeURIComponent(select?.value ?? ''));
    });
});

// Install prompt, where the browser offers one. Others get written instructions.
let installEvent: (Event & { prompt: () => Promise<void> }) | null = null;
window.addEventListener('beforeinstallprompt', (event) => {
    event.preventDefault();
    installEvent = event as typeof installEvent;
    document.querySelectorAll<HTMLElement>('[data-install-button]').forEach((button) => (button.hidden = false));
});
document.querySelectorAll<HTMLElement>('[data-install-button]').forEach((button) => {
    button.addEventListener('click', () => void installEvent?.prompt());
});

// Service worker: HTTPS (or localhost) only. Failure leaves the site fully usable.
if ('serviceWorker' in navigator && window.isSecureContext) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register(app.swUrl || '/sw.js', { scope: '/' }).catch(() => undefined);
    });
}
