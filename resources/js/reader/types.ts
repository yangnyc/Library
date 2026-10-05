export interface ReaderConfig {
    format: 'epub' | 'pdf';
    fileId: number;
    editionId: number;
    contentKey: string;
    baseUrl: string;
    opfPath: string | null;
    title: string;
    language: string;
    direction: 'ltr' | 'rtl' | 'auto';
    pageProgression: 'ltr' | 'rtl' | 'default';
    layout: 'reflowable' | 'fixed';
    hasTextLayer: boolean | null;
    preview: boolean;
    offlineAllowed: boolean;
    urls: {
        edition: string;
        session: string;
        sync: string;
        offlineManifest: string;
        download: string | null;
        pdfAssets: string;
    };
    strings: Record<string, string>;
}

/** What a reading engine (EPUB or PDF) offers to the shared reader chrome. */
export interface Engine {
    next(): void;
    prev(): void;
    goTo(locator: string): Promise<void>;
    current(): { locatorType: 'cfi' | 'page'; locator: string; fraction: number | null; label: string | null } | null;
    search(query: string, onResult: (result: SearchResult) => void, onProgress: (current: number, total: number) => void, signal: AbortSignal): Promise<void>;
    /** Redraw highlights after the stored annotations changed. */
    refreshAnnotations(): void;
    destroy(): void;
}

export interface SearchResult {
    locator: string;
    excerpt: string;
    label?: string;
}
