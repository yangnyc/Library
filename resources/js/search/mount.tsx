import { createRoot } from 'react-dom/client';
import { SearchSuggest } from './SearchSuggest';

/** Attaches suggestions to the server-rendered search box of the host's form. */
export function mount(host: HTMLElement): void {
    const input = host.closest('form')?.querySelector<HTMLInputElement>('input[type="search"]');
    const { url, label, all } = host.dataset;
    if (!input || !url) return;

    createRoot(host).render(<SearchSuggest input={input} url={url} label={label ?? ''} allLabel={all ?? ''} />);
}
