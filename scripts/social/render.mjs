/**
 * Writes the social cards of the Phalcon repositories:
 * public/phalcon/social/github.phalcon.<file>.png, one for each card of
 * cards.json, from template.html, the design tokens and the falcon. The PNGs
 * are committed; GitHub takes them under Settings → Social preview of each
 * repository (there is no API for it).
 *
 * Run in the Puppeteer image, at the root of the repository (all cards, or the
 * cards that you name):
 *
 *   docker run --rm --shm-size=2g -u "$(id -u):$(id -g)" -e HOME=/app/.cache -e TMPDIR=/app/.cache \
 *     -e PUPPETEER_CACHE_DIR=/home/pptruser/.cache/puppeteer -v "$PWD:/app" -w /app \
 *     ghcr.io/puppeteer/puppeteer:latest node scripts/social/render.mjs [file …]
 */
import { mkdirSync, readFileSync } from 'node:fs';
import { createRequire } from 'node:module';

const require = createRequire('/home/pptruser/package.json');
const puppeteer = require('puppeteer');

/** The text with the characters of HTML escaped. */
const escape = (text) => text.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');

/** The page of a card: the template with the fields of the card, the tokens and the falcon. */
const fill = (template, card, tokens, falcon) => template
    .replace('{{tokens}}', () => tokens)
    .replace('{{falcon}}', () => falcon)
    .replace('{{kicker}}', () => escape(card.kicker))
    .replace('{{name}}', () => escape(card.name))
    .replace('{{tagline}}', () => escape(card.tagline))
    .replace('{{text}}', () => escape(card.text))
    .replace('{{pills}}', () => card.pills.map((pill) => `<span class="pill">${escape(pill)}</span>`).join(''))
    .replace('{{url}}', () => escape(card.url));

const only = process.argv.slice(2);
const cards = JSON.parse(readFileSync('scripts/social/cards.json', 'utf8'))
    .filter((card) => only.length === 0 || only.includes(card.file));
const template = readFileSync('scripts/social/template.html', 'utf8');
const tokens = readFileSync('public/phalcon/css/tokens.css', 'utf8');
const falcon = `data:image/svg+xml;base64,${readFileSync('public/phalcon/images/svg/falcon.svg').toString('base64')}`;

if (cards.length === 0) {
    throw new Error(`no card named ${only.join(', ')}`);
}

// Chrome keeps its profile in TMPDIR: a folder of the repository, not /tmp.
if (process.env.TMPDIR) {
    mkdirSync(process.env.TMPDIR, { recursive: true });
}

const browser = await puppeteer.launch({ args: ['--no-sandbox'] });
const tab = await browser.newPage();

await tab.setViewport({ deviceScaleFactor: 1, height: 640, width: 1280 });

for (const card of cards) {
    const file = `public/phalcon/social/github.phalcon.${card.file}.png`;

    await tab.setContent(fill(template, card, tokens, falcon), { waitUntil: 'load' });

    // The name must not run into the falcon: the script of the template makes a long name smaller.
    const width = await tab.evaluate(() => document.querySelector('h1 .name').scrollWidth);

    if (width > 620) {
        throw new Error(`${card.file}: the name is ${width}px wide (620px at most)`);
    }

    await tab.screenshot({ path: file });
    console.log(`wrote ${file}`);
}

await browser.close();
