import { strict as assert } from 'node:assert';
import { existsSync, readdirSync, readFileSync, statSync } from 'node:fs';
import { test } from 'node:test';

const root = new URL('../', import.meta.url);
const read = (file) => readFileSync(new URL(file, root), 'utf8');
const cards = () => JSON.parse(read('scripts/social/cards.json'));
const png = (file) => `public/phalcon/social/github.phalcon.${file}.png`;

test('each social card has a file name, its text, one or two pills and its repository', () => {
    const list = cards();
    const files = list.map((card) => card.file);

    assert.ok(list.length >= 11, `${list.length} cards`);
    assert.deepEqual([...new Set(files)], files, 'each file name once');

    for (const card of list) {
        assert.match(card.file, /^[a-z0-9-]+$/, card.file);

        for (const field of ['kicker', 'name', 'tagline', 'text']) {
            assert.ok(typeof card[field] === 'string' && card[field].trim() !== '', `${card.file}: ${field}`);
        }

        assert.ok(Array.isArray(card.pills) && card.pills.length >= 1 && card.pills.length <= 2, `${card.file}: pills`);
        assert.match(card.url, /^github\.com\/phalcon\/[\w.-]+$/, `${card.file}: url`);
    }
});

test('each social card has its PNG, at 1280 x 640 and under 1 MB (the limits of GitHub)', () => {
    // The PNG header gives the width and the height at bytes 16 to 23.
    for (const card of cards()) {
        const file = new URL(png(card.file), root);

        assert.ok(existsSync(file), `${card.file}: no PNG; run scripts/social/render.mjs`);

        const header = readFileSync(file).subarray(0, 24);

        assert.deepEqual([header.readUInt32BE(16), header.readUInt32BE(20)], [1280, 640], card.file);
        assert.ok(statSync(file).size < 1024 * 1024, `${card.file}: 1 MB or more`);
    }
});

test('every github.phalcon PNG of public/phalcon/social/ comes from the data', () => {
    // A card that the data does not have would not change with the template.
    const files = new Set(cards().map((card) => `github.phalcon.${card.file}.png`));
    const orphans = readdirSync(new URL('public/phalcon/social/', root))
        .filter((file) => file.startsWith('github.phalcon.') && !files.has(file));

    assert.deepEqual(orphans, []);
});

test('the template of the social cards has every field of the data', () => {
    const template = read('scripts/social/template.html');

    for (const field of ['kicker', 'name', 'tagline', 'text', 'pills', 'url']) {
        assert.ok(template.includes(`{{${field}}}`), field);
    }
});
