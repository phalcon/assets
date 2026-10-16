import { strict as assert } from 'node:assert';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import { inflateSync } from 'node:zlib';

import { icoProblems, pngProblems, resolveToken, svgProblems } from '../public/phalcon/tools/design-checks.mjs';

const root = new URL('../', import.meta.url);
const bytes = (file) => readFileSync(new URL(`public/phalcon/favicons/${file}`, root));
const tokens = readFileSync(new URL('public/phalcon/css/tokens.css', root), 'utf8');
const svg = bytes('favicon.svg').toString('utf8');

/** The relative luminance of a #rrggbb color (WCAG 2). */
const luminance = (hex) => {
    const [r, g, b] = [1, 3, 5]
        .map((at) => parseInt(hex.slice(at, at + 2), 16) / 255)
        .map((value) => (value <= 0.03928 ? value / 12.92 : ((value + 0.055) / 1.055) ** 2.4));

    return 0.2126 * r + 0.7152 * g + 0.0722 * b;
};

/** The contrast ratio of two #rrggbb colors. */
const contrast = (first, second) => {
    const [light, dark] = [luminance(first), luminance(second)].sort((a, b) => b - a);

    return (light + 0.05) / (dark + 0.05);
};

/** The first pixel of a PNG with 8 bits for each sample (RGB or RGBA): its #rrggbb color, and its alpha. */
const firstPixel = (png) => {
    const data = [];

    for (let at = 8; at < png.length; at += 12 + png.readUInt32BE(at)) {
        if (png.toString('latin1', at + 4, at + 8) === 'IDAT') {
            data.push(png.subarray(at + 8, at + 8 + png.readUInt32BE(at)));
        }
    }

    // Each row starts with a filter byte. For the first pixel of the first row, every filter adds 0, so the bytes
    // after it are the samples.
    const row = inflateSync(Buffer.concat(data));

    assert.equal(png[24], 8, 'bits for each sample');
    assert.ok([2, 6].includes(png[25]), 'RGB or RGBA');

    return { alpha: png[25] === 6 ? row[4] : 255, color: `#${row.subarray(1, 4).toString('hex')}` };
};

test('favicon.svg has one style rule: in a dark browser, the dark gray paths take --ph-dark-muted', () => {
    // The tokens map the ink of the light tone (--ph-light-muted) to --ph-dark-muted. An SVG icon cannot read the
    // tokens of a site, so the file types the color, and this test keeps it equal to the token.
    const styles = [...svg.matchAll(/<style>([\s\S]*?)<\/style>/g)].map((match) => match[1]);
    const muted = resolveToken(tokens, '--ph-dark-muted');

    assert.equal(muted, '#9db3aa');
    assert.deepEqual(styles, [`@media (prefers-color-scheme:dark){[fill="#3A3A3A"],[fill="#393839"]{fill:${muted}}}`]);
});

test('in a dark browser, every color of favicon.svg has a contrast of 3:1 or more on --ph-dark-bg', () => {
    // WCAG 1.4.11 asks 3:1 for a graphic. Without the dark rule, the back wing and the claws disappear on a dark tab.
    // A CSS selector matches the case of the attribute value, so only the exact values change.
    const rule = /@media \(prefers-color-scheme:dark\)\{([^{]*)\{fill:(#[0-9a-f]{6})\}\}/.exec(svg);
    const changed = new Set([...(rule?.[1] ?? '').matchAll(/\[fill="(#[0-9A-Fa-f]{6})"\]/g)].map((match) => match[1]));
    const background = resolveToken(tokens, '--ph-dark-bg');
    const fills = [...new Set([...svg.matchAll(/fill="(#[0-9A-Fa-f]{6})"/g)].map((match) => match[1]))];
    const low = fills
        .map((fill) => (changed.has(fill) ? rule[2] : fill))
        .filter((fill) => contrast(fill, background) < 3);

    assert.ok(fills.length > 5, 'the colors of the falcon');
    assert.deepEqual(low, []);
});

test('browserconfig.xml has the tile color of --ph-dark-bg, as the theme-color of the pages', () => {
    const color = /<TileColor>([^<]*)<\/TileColor>/.exec(bytes('browserconfig.xml').toString('utf8'))?.[1];

    assert.equal(color, resolveToken(tokens, '--ph-dark-bg'));
});

test('apple-touch-icon.png is not transparent: its corner has the color of --ph-light-bg', () => {
    // iOS fills a transparent area of a home screen icon with black, and the dark gray wing disappears there.
    assert.deepEqual(firstPixel(bytes('apple-touch-icon.png')), { alpha: 255, color: resolveToken(tokens, '--ph-light-bg') });
});

test('the icons that the sites copy pass the icon checks', () => {
    assert.deepEqual(icoProblems(bytes('favicon.ico')), []);
    assert.deepEqual(svgProblems(bytes('favicon.svg')), []);
    assert.deepEqual(pngProblems(bytes('apple-touch-icon.png'), 180), []);
    assert.deepEqual(pngProblems(bytes('android-chrome-192x192.png'), 192), []);
    assert.deepEqual(pngProblems(bytes('android-chrome-512x512.png'), 512), []);
});
