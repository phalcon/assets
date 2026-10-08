/**
 * Writes scripts/tokens/color-names.php: the color names of CSS (from the npm package
 * color-name) and its system colors (from the npm package mdn-data). It prints
 * how many names it wrote, not the names.
 *
 * There is no Node on the host. Run it in Docker from the repository root. The
 * packages go to .color-names/, and the command removes that folder at the end:
 *
 *   docker run --rm -v "$PWD:/app" -w /app --user "$(id -u):$(id -g)" \
 *     -e HOME=/app/.color-names -e npm_config_cache=/app/.color-names/cache node:22-alpine \
 *     sh -c 'npm install --prefix .color-names --no-audit --no-fund color-name@2 mdn-data@2 > /dev/null \
 *       && node scripts/tokens/color-names.mjs .color-names/node_modules; rm -rf .color-names'
 */
import { readFileSync, writeFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { pathToFileURL } from 'node:url';

const [modules] = process.argv.slice(2);
const version = (name) => JSON.parse(readFileSync(resolve(modules, name, 'package.json'), 'utf8')).version;

const colorName = await import(pathToFileURL(resolve(modules, 'color-name', 'index.js')).href);
const named = Object.keys(colorName.default ?? colorName).sort();

const syntaxes = JSON.parse(readFileSync(resolve(modules, 'mdn-data', 'css', 'syntaxes.json'), 'utf8'));
const system = ['system-color', 'deprecated-system-color']
    .flatMap((key) => (syntaxes[key]?.syntax ?? '').split('|'))
    .map((word) => word.trim().toLowerCase())
    .filter(Boolean)
    .sort();

if (named.length === 0 || system.length === 0) {
    throw new Error('a package has no names');
}

/** A PHP constant with one string for each word, in lines of 116 characters at most. */
const constant = (name, words) => {
    const lines = [];
    let line = '   ';

    for (const word of words) {
        const item = ` '${word}',`;

        if ((line + item).length > 116) {
            lines.push(line);
            line = '   ';
        }

        line += item;
    }

    return [`const ${name} = [`, ...lines, line, '];'];
};

writeFileSync(new URL('./color-names.php', import.meta.url), [
    '<?php',
    '',
    '/**',
    ` * The color names of CSS (npm package color-name ${version('color-name')}, MIT License)`,
    ` * and its system colors (npm package mdn-data ${version('mdn-data')}, CC0 License), in`,
    ' * lowercase. transparent and currentcolor are not here, because common.css can',
    ' * use them. Do not change this file by hand: run scripts/tokens/color-names.mjs.',
    ' */',
    '',
    'declare(strict_types=1);',
    '',
    ...constant('COLOR_NAMES', named),
    '',
    ...constant('SYSTEM_COLORS', system),
    '',
].join('\n'));

console.log(`${named.length} color names, ${system.length} system colors`);
