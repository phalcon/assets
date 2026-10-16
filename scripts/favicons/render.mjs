/**
 * Writes the icons of the web app manifests of the Phalcon sites:
 * public/phalcon/favicons/android-chrome-192x192.png and
 * android-chrome-512x512.png, from public/phalcon/favicons/favicon.svg in the
 * light colors (a home screen does not follow the dark rule of the SVG). The
 * falcon fills the height and is in the center, as in apple-touch-icon.png,
 * on a transparent background. The PNGs are committed.
 *
 * Run in the Puppeteer image, at the root of the repository:
 *
 *   docker run --rm --shm-size=2g -u "$(id -u):$(id -g)" -e HOME=/app/.cache -e TMPDIR=/app/.cache \
 *     -e PUPPETEER_CACHE_DIR=/home/pptruser/.cache/puppeteer -v "$PWD:/app" -w /app \
 *     ghcr.io/puppeteer/puppeteer:latest node scripts/favicons/render.mjs
 */
import { mkdirSync, readFileSync } from 'node:fs';
import { createRequire } from 'node:module';

const require = createRequire('/home/pptruser/package.json');
const puppeteer = require('puppeteer');

const FOLDER = 'public/phalcon/favicons';
const SIZES = [192, 512];

const svg = readFileSync(`${FOLDER}/favicon.svg`).toString('base64');

// Chrome keeps its profile in TMPDIR: a folder of the repository, not /tmp.
if (process.env.TMPDIR) {
    mkdirSync(process.env.TMPDIR, { recursive: true });
}

const browser = await puppeteer.launch({ args: ['--no-sandbox'] });

try {
    const page = await browser.newPage();

    await page.emulateMediaFeatures([{ name: 'prefers-color-scheme', value: 'light' }]);

    for (const size of SIZES) {
        const file = `${FOLDER}/android-chrome-${size}x${size}.png`;

        await page.setViewport({ deviceScaleFactor: 1, height: size, width: size });
        await page.setContent(
            `<html><body style="margin:0"><img style="display:block;width:${size}px;height:${size}px;object-fit:contain" src="data:image/svg+xml;base64,${svg}"></body></html>`,
            { waitUntil: 'load' },
        );
        await page.screenshot({ omitBackground: true, path: file });
        console.log(`wrote ${file}`);
    }
} finally {
    await browser.close();
}
