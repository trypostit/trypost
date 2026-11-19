// Render a release changelog thumbnail (1200x630 at 2x) on the marketing site's design: the
// lilac of trypost-site's /channels hero, the text on the left (logo, a "Changelog · <version>"
// pill, the release headline, the themes as glass pills) and the app's week calendar on the right,
// running off the right and bottom edges. It follows the site's share
// image (trypost-site/.claude/og-assets/shoot-og-hero.mjs) and the profile banners
// (~/Herd/paulo-social-media-banners).
//
// Usage:
//   node .claude/release-assets/render-thumbnail.ts \
//     --version "v2.1.0" \
//     --headline "Plan it on a board, | ask your followers" \
//     --themes "Board view,Polls,Covers & GIFs" \
//     --out releases/v2.1.0/thumbnail.png
//
// The headline breaks into its two lines at "|" (or at the middle word without one).
//
// It shoots trypost-site's dev server on port 4390. When nothing answers there, it starts
// `astro dev` in TRYPOST_SITE_DIR (default: the trypost-site folder next to this repo) and stops
// it at the end. Playwright comes from this repo's node_modules.

import { spawn, type ChildProcess } from 'node:child_process';
import { createRequire } from 'node:module';
import { dirname, join } from 'node:path';
import process from 'node:process';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const repoRoot = join(here, '..', '..');
const require = createRequire(join(repoRoot, 'package.json'));
const { chromium } = require('playwright');

const PORT = 4390;
const SITE_URL = `http://localhost:${PORT}`;
const SITE_DIR = process.env.TRYPOST_SITE_DIR ?? join(repoRoot, '..', 'trypost-site');

const args: Record<string, string> = {};
for (let i = 2; i < process.argv.length; i += 2) {
    args[process.argv[i].replace(/^--/, '')] = process.argv[i + 1];
}

if (!args.headline || !args.out || !args.version) {
    console.error('usage: render-thumbnail.ts --version "v2.1.0" --headline "line one | line two" --themes "a,b,c" --out path.png');
    process.exit(1);
}

const headlineLines = (headline: string): [string, string] => {
    if (headline.includes('|')) {
        const [first, ...rest] = headline.split('|');

        return [first.trim(), rest.join(' ').trim()];
    }

    const words = headline.trim().split(/\s+/);
    const middle = Math.ceil(words.length / 2);

    return [words.slice(0, middle).join(' '), words.slice(middle).join(' ')];
};

const [line1, line2] = headlineLines(args.headline);
const themes = (args.themes ?? '')
    .split(',')
    .map((theme) => theme.trim())
    .filter(Boolean);

const siteAnswers = async (): Promise<boolean> => {
    try {
        await fetch(SITE_URL, { redirect: 'manual' });

        return true;
    } catch {
        return false;
    }
};

// The server may already be gone (another dev server took the port first), so a stop never fails.
const stopSite = (server: ChildProcess): void => {
    try {
        process.kill(-server.pid!);
    } catch {
        // Already stopped.
    }
};

const startSite = async (): Promise<ChildProcess> => {
    const server = spawn('npx', ['astro', 'dev', '--port', String(PORT)], {
        cwd: SITE_DIR,
        env: { ...process.env, TRYPOST_AGENT: '1' },
        stdio: 'ignore',
        detached: true,
    });

    for (let attempt = 0; attempt < 120; attempt++) {
        if (await siteAnswers()) {
            return server;
        }

        await new Promise((resolve) => setTimeout(resolve, 1000));
    }

    stopSite(server);
    throw new Error(`trypost-site did not start on ${SITE_URL} (from ${SITE_DIR})`);
};

const layout = { w: 1200, h: 630, title: 60, logo: 30, appw: 1320, appx: 640, appy: 104, scale: 0.72, dpr: 2 };

let server: ChildProcess | null = null;
if (!(await siteAnswers())) {
    server = await startSite();
}

const browser = await chromium.launch();
try {
    const page = await browser.newPage({
        viewport: { width: layout.w, height: layout.h },
        deviceScaleFactor: layout.dpr,
    });
    await page.goto(`${SITE_URL}/en/channels`, { waitUntil: 'load', timeout: 120000 });
    await page.waitForTimeout(2500);

    // The hero section becomes the 1200x630 canvas: its lilac stays, the text column is built on the
    // left and the app window sits on the right, running off the right and bottom edges.
    await page.evaluate(
        ({ layout, line1, line2, themes, version }) => {
            const q = (selector: string): HTMLElement => document.querySelector(selector) as HTMLElement;
            const element = (tag: string, css: string, text?: string): HTMLElement => {
                const node = document.createElement(tag);
                node.style.cssText = css;
                if (text) {
                    node.textContent = text;
                }

                return node;
            };

            document.querySelectorAll<HTMLElement>('header, astro-dev-toolbar, [data-testid="feature-hero-decor"]').forEach((node) => {
                node.style.display = 'none';
            });

            const section = q('[data-testid="feature-hero"]');
            const title = q('[data-testid="feature-hero-title"]');
            const titleStyle = getComputedStyle(title);
            const app = q('[data-testid="feature-hero-app"]');
            const lilac = getComputedStyle(q('[data-testid="feature-hero-stage"]')).backgroundImage;

            Object.assign(section.style, {
                backgroundImage: lilac,
                position: 'fixed',
                top: '0',
                left: '0',
                width: `${layout.w}px`,
                height: `${layout.h}px`,
                margin: '0',
                overflow: 'hidden',
                zIndex: '2147483000',
            });
            q('[data-testid="feature-hero-frame"]').style.display = 'none';
            q('[data-testid="feature-hero-inner"]').style.display = 'none';

            section.append(app);
            Object.assign(app.style, {
                position: 'absolute',
                left: `${layout.appx}px`,
                top: `${layout.appy}px`,
                width: `${layout.appw}px`,
                maxWidth: 'none',
                margin: '0',
                transform: `scale(${layout.scale})`,
                transformOrigin: 'top left',
            });

            const column = element(
                'div',
                'position:absolute;left:64px;top:0;bottom:0;width:540px;display:flex;flex-direction:column;justify-content:center;z-index:5',
            );

            const logo = element('img', `position:absolute;left:64px;top:52px;height:${layout.logo}px;z-index:5`);
            logo.setAttribute('src', '/images/trypost/logo-light.png');
            logo.id = 'banner-logo';

            const pill = element(
                'div',
                'display:flex;width:max-content;align-items:center;gap:8px;margin-bottom:22px;padding:7px 14px;border-radius:999px;background:rgba(255,255,255,.8);box-shadow:0 0 0 1px rgba(124,58,237,.18),0 4px 14px rgba(91,33,182,.10);font:600 14px/1 var(--font-sans);letter-spacing:.02em;color:#6d28d9',
            );
            pill.append(element('span', 'width:7px;height:7px;border-radius:50%;background:#7c3aed'), `Changelog · ${version}`);

            const headline = element(
                'h1',
                `margin:0;font-family:${titleStyle.fontFamily};font-weight:${titleStyle.fontWeight};font-size:${layout.title}px;line-height:1.04;letter-spacing:-0.035em;color:${titleStyle.color}`,
            );
            for (const text of [line1, line2]) {
                headline.append(element('span', 'display:block;white-space:nowrap;width:max-content', text));
            }

            const chips = element('div', 'display:flex;flex-wrap:wrap;gap:10px;margin-top:30px');
            for (const theme of themes) {
                chips.append(
                    element(
                        'span',
                        'padding:9px 15px;border-radius:12px;background:rgba(255,255,255,.85);box-shadow:0 0 0 1px rgba(15,15,30,.06),0 6px 16px rgba(60,40,140,.10);font:550 16px/1 var(--font-sans);color:#18181b',
                        theme,
                    ),
                );
            }

            column.append(pill, headline, chips);
            section.append(logo, column);

            // Each line stays on one line: the headline shrinks until its longest line fits the column.
            const widest = (): number => Math.max(...[...headline.children].map((line) => (line as HTMLElement).offsetWidth));
            let size = layout.title;
            while (size > 36 && widest() > column.clientWidth) {
                size -= 1;
                headline.style.fontSize = `${size}px`;
            }
            window.scrollTo(0, 0);
        },
        { layout, line1, line2, themes, version: args.version },
    );
    await page.waitForTimeout(800);

    await page.screenshot({ path: args.out, clip: { x: 0, y: 0, width: layout.w, height: layout.h } });
} finally {
    await browser.close();
    if (server) {
        stopSite(server);
    }
}

console.log('wrote', args.out);
