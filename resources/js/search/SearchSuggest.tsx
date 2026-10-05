/**
 * The first catalog matches for what is being typed, shown under a search box.
 *
 * The box itself is the form's own server-rendered field and keeps working
 * without this: pressing Enter still runs the full search. The matches are
 * plain links, reachable with Tab or the arrow keys.
 */
import { useEffect, useId, useRef, useState } from 'react';

interface Suggestion {
    title: string;
    language: string;
    direction: string;
    people: string;
    url: string;
}

interface Props {
    input: HTMLInputElement;
    url: string;
    label: string;
    allLabel: string;
}

const MIN_LENGTH = 2;
const TYPING_PAUSE_MS = 250;

export function SearchSuggest({ input, url, label, allLabel }: Props) {
    const [query, setQuery] = useState('');
    const [results, setResults] = useState<Suggestion[]>([]);
    const [open, setOpen] = useState(false);
    const panel = useRef<HTMLDivElement>(null);
    const labelId = useId();

    useEffect(() => {
        const form = input.form;
        if (!form) return;

        const links = () => Array.from(panel.current?.querySelectorAll<HTMLAnchorElement>('a') ?? []);
        const onInput = () => {
            setQuery(input.value.trim());
            setOpen(true);
        };
        const onFocusOut = (event: FocusEvent) => {
            if (!form.contains(event.relatedTarget as Node | null)) setOpen(false);
        };
        const onKeyDown = (event: KeyboardEvent) => {
            if (event.key === 'Escape') {
                setOpen(false);
                input.focus();
                return;
            }
            if (event.key !== 'ArrowDown' && event.key !== 'ArrowUp') return;

            const all = links();
            if (all.length === 0) return;
            const next = all.indexOf(document.activeElement as HTMLAnchorElement) + (event.key === 'ArrowDown' ? 1 : -1);
            event.preventDefault();
            // Above the first match is the search box again.
            (next < 0 ? input : all[Math.min(next, all.length - 1)]).focus();
        };

        input.addEventListener('input', onInput);
        form.addEventListener('focusout', onFocusOut);
        form.addEventListener('keydown', onKeyDown);

        return () => {
            input.removeEventListener('input', onInput);
            form.removeEventListener('focusout', onFocusOut);
            form.removeEventListener('keydown', onKeyDown);
        };
    }, [input]);

    useEffect(() => {
        if (query.length < MIN_LENGTH) {
            setResults([]);
            return;
        }

        const request = new AbortController();
        const timer = window.setTimeout(() => {
            fetch(`${url}?q=${encodeURIComponent(query)}`, { headers: { Accept: 'application/json' }, signal: request.signal })
                .then((response) => (response.ok ? response.json() : { results: [] }))
                .then((data: { results: Suggestion[] }) => setResults(data.results))
                .catch(() => {
                    // Offline or failed: the form's own search still works.
                    if (!request.signal.aborted) setResults([]);
                });
        }, TYPING_PAUSE_MS);

        return () => {
            window.clearTimeout(timer);
            request.abort();
        };
    }, [query, url]);

    const visible = open && query.length >= MIN_LENGTH && results.length > 0;

    return (
        <>
            <div className="visually-hidden" aria-live="polite">
                {visible ? `${label}: ${results.length}` : ''}
            </div>
            {visible && (
                // Keeps focus in the search box while a match is clicked, so the panel is still there for the click.
                <div className="search-suggest__panel" ref={panel} onMouseDown={(event) => event.preventDefault()}>
                    <p className="search-suggest__label" id={labelId}>
                        {label}
                    </p>
                    <div className="list-group list-group-flush" role="group" aria-labelledby={labelId}>
                        {results.map((result) => (
                            <a key={result.url} className="list-group-item list-group-item-action" href={result.url}>
                                <bdi className="search-suggest__title" lang={result.language} dir={result.direction}>
                                    {result.title}
                                </bdi>
                                {result.people && <bdi className="search-suggest__people">{result.people}</bdi>}
                            </a>
                        ))}
                        <a
                            className="list-group-item list-group-item-action search-suggest__all"
                            href={`${input.form?.action ?? ''}?q=${encodeURIComponent(query)}`}
                        >
                            {allLabel}
                        </a>
                    </div>
                </div>
            )}
        </>
    );
}
