// Render a release changelog thumbnail (1200x630 at 2x) on the marketing site's design: the
// /channels hero of trypost-site with the logo, a "Changelog · <version>" pill, the release
// headline, the themes as glass pills and the app's week calendar. It follows the site's share
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

const layout = { w: 1200, h: 630, appw: 1320, title: 54, logo: 34, scale: 0.66, pad: -150, top: 40, dpr: 2 };

let server: ChildProcess | null = null;
if (!(await siteAnswers())) {
    server = await startSite();
}

const browser = await chromium.launch();
try {
    const page = await browser.newPage({
        viewport: { width: layout.w, height: 1000 },
        deviceScaleFactor: layout.dpr,
    });
    await page.goto(`${SITE_URL}/en/channels`, { waitUntil: 'load', timeout: 120000 });
    await page.waitForTimeout(2500);

    await page.evaluate(
        ({ layout, line1, line2, themes, version }) => {
            const q = (selector: string): HTMLElement => document.querySelector(selector) as HTMLElement;
            const hide = (element: HTMLElement | null | undefined): void => {
                if (element) {
                    element.style.display = 'none';
                }
            };

            document.querySelectorAll<HTMLElement>('header, astro-dev-toolbar').forEach(hide);
            hide(q('[data-testid="feature-hero-description"]'));
            hide(q('[data-testid="feature-hero-google"]')?.parentElement);
            hide(q('[data-testid="social-proof"]')?.parentElement);
            hide(q('[data-testid="hero-eyebrow"]'));
            document.querySelectorAll<HTMLElement>('[data-testid="feature-hero-decor"]').forEach(hide);

            const title = q('[data-testid="feature-hero-title"]');
            title.style.fontSize = `${layout.title}px`;
            title.style.whiteSpace = 'nowrap';
            title.innerHTML = '';
            for (const text of [line1, line2]) {
                const line = document.createElement('span');
                line.style.display = 'block';
                line.textContent = text;
                title.append(line);
            }

            const logo = document.createElement('img');
            logo.src = '/images/trypost/logo-light.png';
            logo.id = 'banner-logo';
            logo.style.cssText = `height:${layout.logo}px;display:block;margin:0 auto 18px`;

            const pill = document.createElement('div');
            pill.style.cssText =
                'display:flex;width:max-content;margin:0 auto 18px;align-items:center;gap:8px;padding:6px 14px;border-radius:999px;background:rgba(255,255,255,.75);box-shadow:0 0 0 1px rgba(124,58,237,.18),0 4px 14px rgba(91,33,182,.10);font:600 14px/1 var(--font-sans);letter-spacing:.02em;color:#6d28d9';
            pill.innerHTML = '<span style="width:7px;height:7px;border-radius:50%;background:#7c3aed"></span>';
            pill.append(`Changelog · ${version}`);

            title.parentElement!.insertBefore(logo, title);
            title.parentElement!.insertBefore(pill, title);

            const chips = document.createElement('div');
            chips.style.cssText = 'display:flex;justify-content:center;gap:12px;margin:26px 0 74px';
            for (const theme of themes) {
                const chip = document.createElement('span');
                chip.textContent = theme;
                chip.style.cssText =
                    'padding:10px 18px;border-radius:14px;background:rgba(255,255,255,.85);box-shadow:0 0 0 1px rgba(15,15,30,.06),0 6px 16px rgba(60,40,140,.10);font:550 18px/1 var(--font-sans);color:#18181b';
                chips.append(chip);
            }

            const strip = q('[data-testid="feature-hero-strip"]');
            if (strip) {
                strip.replaceWith(chips);
            } else {
                title.after(chips);
            }

            window.scrollTo(0, 0);
        },
        { layout, line1, line2, themes, version: args.version },
    );
    await page.waitForTimeout(800);

    await page.evaluate((layout) => {
        const q = (selector: string): HTMLElement => document.querySelector(selector) as HTMLElement;
        const inner = q('[data-testid="feature-hero-inner"]');
        Object.assign(inner.style, { transform: `translateY(${layout.pad}px)`, position: 'relative', zIndex: '5' });
        Object.assign(q('[data-testid="feature-hero-app"]').style, {
            maxWidth: 'none',
            width: `${layout.appw}px`,
            position: 'relative',
            left: '50%',
            marginLeft: '0',
            marginRight: '0',
            transform: `translateX(-50%) scale(${layout.scale})`,
            transformOrigin: 'top center',
        });
        q('[data-testid="feature-hero-frame"]').style.padding = '0';
        Object.assign(q('[data-testid="feature-hero-stage"]').style, { borderRadius: '0', margin: '0' });
    }, layout);
    await page.waitForTimeout(600);

    const logoTop: number = await page.evaluate(() => document.getElementById('banner-logo')!.getBoundingClientRect().top + window.scrollY);
    const y = Math.max(0, logoTop - layout.top);
    await page.screenshot({ path: args.out, clip: { x: 0, y, width: layout.w, height: layout.h } });
} finally {
    await browser.close();
    if (server) {
        stopSite(server);
    }
}

console.log('wrote', args.out);
