/**
 * Account page: offers to copy guest reading data into the account. Nothing
 * is merged unless the reader presses the button.
 */
import { ScopedStore, mergeGuestInto, scopeFor } from './lib/store';
import { fetchSession, syncBooks } from './lib/sync';

const panel = document.querySelector<HTMLElement>('[data-guest-merge]');
const guest = new ScopedStore('guest');

if (panel && guest.hasReadingData() && !guest.flag('merge-dismissed')) {
    panel.hidden = false;

    panel.querySelector('[data-guest-merge-skip]')?.addEventListener('click', () => {
        guest.setFlag('merge-dismissed', true);
        panel.hidden = true;
    });

    panel.querySelector<HTMLButtonElement>('[data-guest-merge-accept]')?.addEventListener('click', async (event) => {
        const button = event.currentTarget as HTMLButtonElement;
        button.disabled = true;

        const { info } = await fetchSession();
        if (!info?.user) {
            button.disabled = false;
            return;
        }

        const account = new ScopedStore(scopeFor(info.user.id));
        mergeGuestInto(account);

        // Send the copied items now; anything that fails stays marked for the next sync.
        const books = Object.entries(account.index()).map(([contentKey, ref]) => ({ contentKey, fileId: ref.fileId }));
        for (let i = 0; i < books.length; i += 40) {
            await syncBooks(account, books.slice(i, i + 40), info.csrf).catch(() => undefined);
        }

        panel.querySelectorAll('button').forEach((b) => (b.hidden = true));
        const done = panel.querySelector<HTMLElement>('[data-guest-merge-done]');
        if (done) done.hidden = false;
    });
}
