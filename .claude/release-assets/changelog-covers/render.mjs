// Render a "What's New" cover (1200x675, saved at 2x) from an HTML scene.
//
// Usage:
//   node .claude/release-assets/changelog-covers/render.mjs \
//     --scene path/to/scene.html \
//     --out resources/changelog/<YYYY-MM-DD>-<slug>/cover.png
//
// The scene is plain HTML using the classes in cover.css (linked for you).
// Placeholders the renderer fills in:
//   <i data-icon="users"></i>             Tabler outline icon (node_modules/@tabler/icons)
//   <i data-icon="heart" data-filled></i> Tabler filled icon
//   {{TRYPOST_MARK}}                      the TryPost mark as inline SVG
//   <img src="logos/x.png">               a service's official logo from logos/, inlined
//
// Playwright and the icons are resolved from the repo's node_modules.

import { existsSync, readFileSync, unlinkSync, writeFileSync } from 'fs';
import { createRequire } from 'module';
import { tmpdir } from 'os';
import { dirname, join, resolve } from 'path';
import { fileURLToPath } from 'url';

const here = dirname(fileURLToPath(import.meta.url));
const repoRoot = join(here, '..', '..', '..');
const require = createRequire(join(repoRoot, 'package.json'));
const { chromium } = require('playwright');

const args = {};
for (let i = 2; i < process.argv.length; i += 2) {
    args[process.argv[i].replace(/^--/, '')] = process.argv[i + 1];
}

if (!args.scene || !args.out) {
    console.error('usage: render.mjs --scene scene.html --out cover.png');
    process.exit(1);
}

const iconsRoot = join(repoRoot, 'node_modules', '@tabler', 'icons', 'icons');

const icon = (name, filled) => {
    const file = join(iconsRoot, filled ? 'filled' : 'outline', `${name}.svg`);

    if (!existsSync(file)) {
        throw new Error(`Unknown Tabler icon: ${name}${filled ? ' (filled)' : ''}`);
    }

    return readFileSync(file, 'utf8')
        .replace(/<!--[\s\S]*?-->/g, '')
        .replace(/\s(width|height)="[^"]*"/g, '')
        .replace('<svg', '<svg class="icon" aria-hidden="true"');
};

const trypostMark = `<svg class="trypost-mark" viewBox="0 0 410 410" aria-hidden="true"><rect width="410" height="410" fill="#fff"/><path d="M410 109.736V410H128.863L410 109.736Z" fill="#3660E0"/><path d="M410 0H281.138L205 81.3178L410 300.264V0Z" fill="#855ED4"/><path d="M0 0V300.264L205 81.3178L281.138 0H0Z" fill="#FFCC33"/><path d="M0 109.736V410H128.862L205 328.682L0 109.736Z" fill="#F39200"/></svg>`;

const scene = readFileSync(resolve(args.scene), 'utf8')
    .replace(/<i data-icon="([a-z0-9-]+)"( data-filled)?><\/i>/g, (_, name, filled) => icon(name, Boolean(filled)))
    .replaceAll('{{TRYPOST_MARK}}', trypostMark)
    .replace(/src="(logos\/[^"]+\.png)"/g, (_, file) => `src="data:image/png;base64,${readFileSync(join(here, file)).toString('base64')}"`);

const css = readFileSync(join(here, 'cover.css'), 'utf8');

const html = `<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8" />
<link rel="preconnect" href="https://fonts.googleapis.com" />
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400..700&display=swap" rel="stylesheet" />
<style>${css}</style>
</head>
<body>${scene}</body>
</html>`;

const tmpFile = join(tmpdir(), `trypost-changelog-cover-${process.pid}.html`);
writeFileSync(tmpFile, html);

const browser = await chromium.launch();
try {
    const page = await browser.newPage({ viewport: { width: 1200, height: 675 }, deviceScaleFactor: 2 });
    await page.goto(`file://${tmpFile}`, { waitUntil: 'networkidle' });
    await page.evaluate(() => document.fonts.ready);
    await page.screenshot({ path: args.out });
} finally {
    await browser.close();
    unlinkSync(tmpFile);
}

console.log('wrote', args.out);
