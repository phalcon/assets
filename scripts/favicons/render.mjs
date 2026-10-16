/**
 * Writes the home screen icons of the Phalcon sites from
 * public/phalcon/favicons/favicon.svg, in the light colors (a home screen
 * does not follow the dark rule of the SVG). The PNGs are committed.
 *
 * - android-chrome-192x192.png and android-chrome-512x512.png, the icons of
 *   the web app manifests: the falcon fills the height and is in the center,
 *   on a transparent background.
 * - apple-touch-icon.png (180x180): iOS fills a transparent area with black,
 *   and the dark gray wing disappears there. So the icon has the background of
 *   --ph-light-bg, and a margin of 10% for the round corners of iOS.
 *
 * Run in the Puppeteer image, at the root of the repository:
 *
 *   docker run --rm --shm-size=2g -u "$(id -u):$(id -g)" -e HOME=/app/.cache -e TMPDIR=/app/.cache \
 *     -e PUPPETEER_CACHE_DIR=/home/pptruser/.cache/puppeteer -v "$PWD:/app" -w /app \
 *     ghcr.io/puppeteer/puppeteer:latest node scripts/favicons/render.mjs
 */
import { mkdirSync, readFileSync } from 'node:fs';
import { createRequire } from 'node:module';

import { resolveToken } from '../../public/phalcon/tools/design-checks.mjs';

const require = createRequire('/home/pptruser/package.json');
const puppeteer = require('puppeteer');

const FOLDER = 'public/phalcon/favicons';
const ICONS = [
    { background: null, file: 'android-chrome-192x192.png', margin: 0, size: 192 },
    { background: null, file: 'android-chrome-512x512.png', margin: 0, size: 512 },
    {
        background: resolveToken(readFileSync('public/phalcon/css/tokens.css', 'utf8'), '--ph-light-bg'),
        file: 'apple-touch-icon.png',
        margin: 18,
        size: 180,
    },
];

const svg = readFileSync(`${FOLDER}/favicon.svg`).toString('base64');

// Chrome keeps its profile in TMPDIR: a folder of the repository, not /tmp.
if (process.env.TMPDIR) {
    mkdirSync(process.env.TMPDIR, { recursive: true });
}

const browser = await puppeteer.launch({ args: ['--no-sandbox'] });

try {
    const page = await browser.newPage();

    await page.emulateMediaFeatures([{ name: 'prefers-color-scheme', value: 'light' }]);

    for (const { background, file, margin, size } of ICONS) {
        await page.setViewport({ deviceScaleFactor: 1, height: size, width: size });
        await page.setContent(
            `<html><body style="margin:0;background:${background ?? 'transparent'}"><img style="display:block;box-sizing:border-box;width:${size}px;height:${size}px;padding:${margin}px;object-fit:contain" src="data:image/svg+xml;base64,${svg}"></body></html>`,
            { waitUntil: 'load' },
        );
        await page.screenshot({ omitBackground: background === null, path: `${FOLDER}/${file}` });
        console.log(`wrote ${FOLDER}/${file}`);
    }
} finally {
    await browser.close();
}
