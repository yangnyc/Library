// Copies the PDF.js runtime data (character maps, standard fonts, image
// decoders) into public/vendor/pdfjs so it is served from this site. Run by
// `npm run build`; nothing is fetched from a CDN at reading time.
import { cpSync, mkdirSync, rmSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = join(dirname(fileURLToPath(import.meta.url)), '..');
const source = join(root, 'node_modules', 'pdfjs-dist');
const target = join(root, 'public', 'vendor', 'pdfjs');

rmSync(target, { recursive: true, force: true });
mkdirSync(target, { recursive: true });
for (const directory of ['cmaps', 'standard_fonts', 'wasm', 'iccs']) {
    cpSync(join(source, directory), join(target, directory), { recursive: true });
}
console.log('PDF.js assets copied to public/vendor/pdfjs');
