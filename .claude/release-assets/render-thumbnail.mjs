// Render a release changelog thumbnail (1200x630 PNG) from the shared template.
//
// Usage:
//   node .claude/release-assets/render-thumbnail.mjs \
//     --headline "Your language, mobile, and per-image alt text" \
//     --underline "alt text" \
//     --themes "Languages,Mobile,Alt text & previews" \
//     --out releases/v1.0.6/thumbnail.png
//
// Optional:
//   --version "v1.0.6"   — stamped in the badge as a mono version segment.
//   --badge "Changelog"  (default) — the amber sticker label, top-right.
//   --underline "phrase" — a phrase inside the headline to get the hand-drawn
//                          violet squiggle (mirrors the marketing-site hero).
//
// Playwright is resolved from the repo's node_modules, so the script works
// regardless of where it is invoked.

import { createRequire } from 'module';
import { readFileSync, writeFileSync, unlinkSync } from 'fs';
import { fileURLToPath } from 'url';
import { dirname, join } from 'path';
import { tmpdir } from 'os';

const here = dirname(fileURLToPath(import.meta.url));
const repoRoot = join(here, '..', '..');
const require = createRequire(join(repoRoot, 'package.json'));
const { chromium } = require('playwright');

const args = {};
for (let i = 2; i < process.argv.length; i += 2) {
    args[process.argv[i].replace(/^--/, '')] = process.argv[i + 1];
}

if (!args.headline || !args.out) {
    console.error('usage: render-thumbnail.mjs --headline "..." --themes "a,b,c" --out path.png [--version "v1.0.6"] [--underline "phrase"] [--badge "Changelog"]');
    process.exit(1);
}

const escapeHtml = (value) =>
    String(value)
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;');

// Hand-drawn violet squiggle under an emphasis phrase — same path the hero uses.
const squiggle = (phrase) =>
    `<span class="ul">${phrase}<svg class="squiggle" viewBox="0 0 200 12" preserveAspectRatio="none" fill="none" stroke="currentColor" stroke-width="4" stroke-linecap="round" aria-hidden="true"><path d="M 5 6 Q 25 0, 50 6 T 100 6 T 150 6 T 195 6" /></svg></span>`;

let headlineHtml = escapeHtml(args.headline);
if (args.underline) {
    const escapedPhrase = escapeHtml(args.underline);
    if (headlineHtml.includes(escapedPhrase)) {
        headlineHtml = headlineHtml.replace(escapedPhrase, squiggle(escapedPhrase));
    }
}

const themes = (args.themes ?? '')
    .split(',')
    .map((theme) => theme.trim())
    .filter(Boolean);

const chips = themes
    .map((theme) => `<span class="chip">${escapeHtml(theme)}</span>`)
    .join('\n      ');

const star = `<svg class="star" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 2l2.9 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l7.1-1.01z" /></svg>`;
let badgeInner = `<span class="label">${star}${escapeHtml(args.badge ?? 'Changelog')}</span>`;
if (args.version) {
    badgeInner += `<span class="ver">${escapeHtml(args.version)}</span>`;
}

const logoDataUri = `data:image/png;base64,${readFileSync(join(here, 'logo.png')).toString('base64')}`;

const html = readFileSync(join(here, 'thumbnail.template.html'), 'utf8')
    .replace('{{LOGO}}', logoDataUri)
    .replace('{{BADGE_INNER}}', badgeInner)
    .replace('{{HEADLINE}}', headlineHtml)
    .replace('{{CHIPS}}', chips);

const tmpFile = join(tmpdir(), `trypost-thumbnail-${process.pid}.html`);
writeFileSync(tmpFile, html);

const browser = await chromium.launch();
try {
    const page = await browser.newPage({
        viewport: { width: 1200, height: 630 },
        deviceScaleFactor: 2,
    });
    await page.goto(`file://${tmpFile}`, { waitUntil: 'networkidle' });
    await page.evaluate(() => document.fonts.ready);
    await page.screenshot({ path: args.out });
} finally {
    await browser.close();
    unlinkSync(tmpFile);
}

console.log('wrote', args.out);
