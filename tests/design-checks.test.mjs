import { strict as assert } from 'node:assert';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

import {
    codeThemeProblems,
    commonCssProblems,
    definedCodeRoles,
    definedTokens,
    footerProblems,
    missingTokens,
    resolveToken,
    sidebarCssProblems,
    sidebarProblems,
    tokensProblems,
    usedTokens,
} from '../phalcon/tools/design-checks.mjs';

const css = `/* A comment: --ph-fake: #000000; var(--ph-ghost) */
:root {
    --ph-night-950: #070d0c;
    --ph-dark-bg: var(--ph-night-950);
    --ph-loop-a: var(--ph-loop-b);
    --ph-loop-b: var(--ph-loop-a);
}
`;

/** A code theme that uses the roles bg, comment and text. $changes replaces top-level keys. */
const theme = (changes = {}) => JSON.stringify({
    colors: { 'editor.background': 'var(--code-bg)', 'editor.foreground': 'var(--code-text)' },
    name: 'phalcon',
    tokenColors: [
        { scope: ['comment'], settings: { foreground: 'var(--code-comment)' } },
        { scope: 'markup.underline', settings: { fontStyle: 'underline' } },
    ],
    type: 'dark',
    ...changes,
});

// The tokens

test('definedTokens lists the --ph- names that the rule defines', () => {
    assert.deepEqual([...definedTokens(css)].sort(), ['--ph-dark-bg', '--ph-loop-a', '--ph-loop-b', '--ph-night-950']);
});

test('definedTokens ignores names in a comment', () => {
    assert.ok(!definedTokens(css).has('--ph-fake'));
});

test('usedTokens finds var() references, also with spaces and a fallback', () => {
    const text = 'a { color: var(--ph-one); background: var( --ph-two , red); }';

    assert.deepEqual([...usedTokens(text)].sort(), ['--ph-one', '--ph-two']);
});

test('usedTokens ignores references in a comment', () => {
    assert.deepEqual([...usedTokens(css)].sort(), ['--ph-loop-a', '--ph-loop-b', '--ph-night-950']);
});

test('missingTokens lists the used names that are not defined, sorted', () => {
    assert.deepEqual(missingTokens(css, ['--ph-zeta', '--ph-dark-bg', '--ph-alpha']), ['--ph-alpha', '--ph-zeta']);
});

test('resolveToken follows var() references to the value', () => {
    assert.equal(resolveToken(css, '--ph-dark-bg'), '#070d0c');
    assert.equal(resolveToken(css, '--ph-night-950'), '#070d0c');
});

test('resolveToken returns null for an unknown name and for a loop', () => {
    assert.equal(resolveToken(css, '--ph-nope'), null);
    assert.equal(resolveToken(css, '--ph-loop-a'), null);
});

test('tokensProblems accepts a file that defines every used token', () => {
    assert.deepEqual(tokensProblems(css, ['--ph-dark-bg']), []);
});

test('tokensProblems names each used token that is missing', () => {
    assert.deepEqual(tokensProblems(css, ['--ph-dark-bg', '--ph-light-bg']), ['--ph-light-bg is missing']);
});

test('tokensProblems names a reference that the file does not define', () => {
    const file = ':root { --ph-dark-bg: var(--ph-night-951); --ph-light-bg: #f7faf8; }';

    assert.deepEqual(tokensProblems(file, ['--ph-light-bg']), ['--ph-night-951 is missing']);
});

test('tokensProblems names each used token that has no value', () => {
    assert.deepEqual(tokensProblems(css, ['--ph-dark-bg', '--ph-loop-a']), ['--ph-loop-a has no value']);
});

test('tokensProblems rejects a file that is not a tokens file', () => {
    const page = '<!DOCTYPE html><html><body>Not found</body></html>';

    assert.deepEqual(tokensProblems(page, ['--ph-dark-bg']), ['the file has no :root rule']);
    assert.deepEqual(tokensProblems('', ['--ph-dark-bg']), ['the file has no :root rule']);
});

test('tokensProblems accepts the value forms of a tokens file: hex, rgb(), var() and a font stack', () => {
    const file = ':root {\n    --ph-a: #070d0c;\n    --ph-b: rgb(15 158 134 / 0.35);\n    --ph-c: var(--ph-a);\n'
        + '    --ph-d: ui-monospace, "Liberation Mono",\n        monospace;\n}\n';

    assert.deepEqual(tokensProblems(file, ['--ph-b', '--ph-c', '--ph-d']), []);
});

test('tokensProblems rejects a value that is not a color, a var() or a font stack', () => {
    // A downloaded file goes into every page: a url() or an expression must not come in with it.
    const file = ':root { --ph-a: #070d0c; --ph-b: url(https://example.com/t.png); }';

    assert.deepEqual(tokensProblems(file, ['--ph-a']), [
        '--ph-b: url(https://example.com/t.png) is not a token with a color, a var() or a font stack',
    ]);
});

test('tokensProblems rejects a declaration that is not a --ph- token', () => {
    const file = ':root { --ph-a: #070d0c; color: red; }';

    assert.deepEqual(tokensProblems(file, ['--ph-a']), ['color: red is not a token with a color, a var() or a font stack']);
});

test('tokensProblems rejects a file with more than the :root rule, and a file that is cut', () => {
    for (const file of [
        ':root { --ph-a: #070d0c; }\nbody { display: none; }',
        '@import url("https://example.com/x.css");\n:root { --ph-a: #070d0c; }',
        ':root { --ph-a: #070d0c; --ph-b: #f7f',
    ]) {
        assert.deepEqual(tokensProblems(file, ['--ph-a']), ['the file is not a single :root rule'], file);
    }
});

// The code theme

test('codeThemeProblems accepts a theme whose roles the site maps', () => {
    assert.deepEqual(codeThemeProblems(theme(), ['bg', 'comment', 'text']), []);
});

test('codeThemeProblems rejects a file that is not a JSON object', () => {
    assert.deepEqual(codeThemeProblems('<!DOCTYPE html><html><body>Not found</body></html>', ['bg']), ['the file is not JSON']);
    assert.deepEqual(codeThemeProblems('[]', ['bg']), ['the file is not a JSON object']);
    assert.deepEqual(codeThemeProblems('', ['bg']), ['the file is not JSON']);
});

test('codeThemeProblems rejects a file with no rules', () => {
    assert.deepEqual(codeThemeProblems(theme({ tokenColors: [] }), ['bg', 'comment', 'text']), ['the file has no list of rules']);
});

test('codeThemeProblems names a color that is not a --code- variable, and a role that the site does not map', () => {
    const json = theme({
        tokenColors: [
            { scope: 'comment', settings: { foreground: 'red' } },
            { scope: 'keyword', settings: { foreground: 'var(--code-keyword)' } },
        ],
    });

    assert.deepEqual(codeThemeProblems(json, ['bg', 'text']), ['--code-keyword has no value on this site', 'red is not a --code- variable']);
});

test('codeThemeProblems checks the background of a rule as a color, and allows every other setting', () => {
    const json = theme({
        tokenColors: [
            { scope: 'comment', settings: { background: 'red', fontStyle: 'italic', foreground: 'var(--code-comment)' } },
            { scope: 'markup.inserted', settings: { background: 'var(--code-bg)' } },
        ],
    });

    assert.deepEqual(codeThemeProblems(json, ['bg', 'comment', 'text']), ['red is not a --code- variable']);
});

test('codeThemeProblems names every typed color, in a rule and in the keys that Shiki also reads (bg, settings)', () => {
    const json = theme({
        bg: '#ff0000',
        settings: [{ settings: { foreground: 'rgb(255 0 0)' } }],
        tokenColors: [{ scope: 'comment', settings: { foreground: '#8b949e' } }],
    });

    assert.deepEqual(codeThemeProblems(json, ['bg', 'comment', 'text']), [
        '#8b949e is a typed color',
        '#ff0000 is a typed color',
        'bg is not allowed',
        'rgb(255 0 0) is a typed color',
        'settings is not allowed',
    ]);
});

test('codeThemeProblems allows only colors, name, tokenColors and type (Shiki reads fg and settings in their place)', () => {
    const json = theme({ fg: 'var(--code-text)', settings: [{ settings: { foreground: 'var(--code-comment)' } }] });

    assert.deepEqual(codeThemeProblems(json, ['bg', 'comment', 'text']), ['fg is not allowed', 'settings is not allowed']);
});

test('codeThemeProblems takes the --code- roles from the whole file', () => {
    const json = theme({ bg: 'var(--code-unmapped)' });

    assert.deepEqual(codeThemeProblems(json, ['bg', 'comment', 'text']), ['--code-unmapped has no value on this site', 'bg is not allowed']);
});

test('codeThemeProblems needs the two editor colors (Shiki falls back to typed colors)', () => {
    assert.deepEqual(codeThemeProblems(theme({ colors: {} }), ['bg', 'comment', 'text']), [
        'colors.editor.background is missing',
        'colors.editor.foreground is missing',
    ]);
});

test('definedCodeRoles reads the --code- roles that a stylesheet defines, not the ones in a comment', () => {
    const text = '/* --code-ghost: red; */ :root { --code-bg: var(--ph-light-code-bg); color: var(--code-text); }';

    assert.deepEqual([...definedCodeRoles(text)], ['bg']);
});

// The shared header and footer

/** The last line of common.css. A file without it is cut. */
const end = '/* The end of common.css. */\n';

/** A small common.css that uses the tokens --ph-mist-100 and --ph-night-950. */
const common = '/* The shared header and footer. */\n.ph-nav { color: var(--ph-mist-100); }\n'
    + `.ph-footer { background-color: var(--ph-night-950); }\n${end}`;

test('commonCssProblems accepts a file whose tokens the site defines', () => {
    assert.deepEqual(commonCssProblems(common, `${css}:root { --ph-mist-100: #e6f2ec; }`), []);
});

test('commonCssProblems rejects a file that is not a stylesheet', () => {
    assert.deepEqual(commonCssProblems('<!DOCTYPE html><html><body>Not found</body></html>', css), ['the file is not a stylesheet']);
    assert.deepEqual(commonCssProblems('', css), ['the file is not a stylesheet']);
});

test('commonCssProblems rejects a stylesheet with no rules for the nav and the footer', () => {
    assert.deepEqual(commonCssProblems('.header { color: var(--ph-night-950); }', css), ['the file has no rules for .ph-nav and .ph-footer']);
});

test('commonCssProblems rejects a file that is cut', () => {
    assert.deepEqual(commonCssProblems(common.slice(0, -3), css), ['the file is not whole']);
});

test('commonCssProblems rejects a file that is cut right after a rule', () => {
    assert.deepEqual(commonCssProblems(common.replace(end, ''), css), ['the file is not whole']);
});

test('commonCssProblems accepts a < in valid CSS', () => {
    const file = common.replace(end, `@media (width < 40rem) {\n    .ph-nav { display: none; }\n}\n${end}`);

    assert.deepEqual(commonCssProblems(file, `${css}:root { --ph-mist-100: #e6f2ec; }`), []);
});

test('commonCssProblems names each token that the tokens file does not define', () => {
    const file = common.replace(end, `.ph-nav__bar { border-color: var(--ph-line-900); }\n${end}`);

    assert.deepEqual(commonCssProblems(file, css), ['--ph-line-900 is not in the tokens file', '--ph-mist-100 is not in the tokens file']);
});

test('commonCssProblems ignores tokens in a comment', () => {
    const file = `/* var(--ph-ghost) */\n${common}`;

    assert.deepEqual(commonCssProblems(file, `${css}:root { --ph-mist-100: #e6f2ec; }`), []);
});

// The shared sidebar

/** The last line of sidebar.css. A file without it is cut. */
const sidebarEnd = '/* The end of sidebar.css. */\n';

/** A small sidebar.css that uses the tokens --ph-night-950 and --ph-dark-bg, in both tones. */
const sidebarCss = '/* The shared sidebar. */\n.ph-side__box { color: var(--ph-night-950); }\n'
    + `html.dark .ph-side__box { color: var(--ph-dark-bg); }\n${sidebarEnd}`;

test('sidebarCssProblems accepts a file whose tokens the site defines', () => {
    assert.deepEqual(sidebarCssProblems(sidebarCss, css), []);
});

test('sidebarCssProblems rejects a file that is not a stylesheet', () => {
    assert.deepEqual(sidebarCssProblems('<!DOCTYPE html><html><body>Not found</body></html>', css), ['the file is not a stylesheet']);
});

test('sidebarCssProblems rejects a stylesheet with no rule for the box', () => {
    assert.deepEqual(sidebarCssProblems(common, css), ['the file has no rules for .ph-side__box']);
});

test('sidebarCssProblems rejects a file that is cut, or that ends as common.css', () => {
    assert.deepEqual(sidebarCssProblems(sidebarCss.replace(sidebarEnd, ''), css), ['the file is not whole']);
    assert.deepEqual(sidebarCssProblems(sidebarCss.replace(sidebarEnd, end), css), ['the file is not whole']);
});

test('sidebarCssProblems names each token that the tokens file does not define', () => {
    const file = sidebarCss.replace(sidebarEnd, `.ph-side__title { color: var(--ph-light-kicker); }\n${sidebarEnd}`);

    assert.deepEqual(sidebarCssProblems(file, css), ['--ph-light-kicker is not in the tokens file']);
});

const sidebar = {
    supporters: { title: 'Supporters', groups: [{ group: 'sponsor', title: 'Sponsors' }] },
    projects: { title: 'Projects', text: ['We make ', { label: 'Phalcon', href: 'https://phalcon.io' }, '.'] },
};
const sidebarWith = (change) => JSON.stringify({ ...sidebar, ...change });

test('sidebarProblems accepts the box of the supporters and the box of the projects', () => {
    assert.deepEqual(sidebarProblems(JSON.stringify(sidebar)), []);
});

test('sidebarProblems rejects a file that is not a JSON object', () => {
    assert.deepEqual(sidebarProblems('<!doctype html>'), ['the file is not JSON']);
    assert.deepEqual(sidebarProblems('[]'), ['the file is not a JSON object']);
});

test('sidebarProblems rejects a key that a site does not read', () => {
    assert.deepEqual(sidebarProblems(sidebarWith({ tags: {} })), ['tags is not allowed']);
});

test('sidebarProblems asks for the titles and for groups of supporters with a group and a title', () => {
    assert.deepEqual(
        sidebarProblems(sidebarWith({ supporters: { title: '', groups: [] }, projects: { ...sidebar.projects, title: ' ' } })),
        ['projects.title needs text', 'supporters.groups needs at least one group', 'supporters.title needs text'],
    );
    assert.deepEqual(
        sidebarProblems(sidebarWith({ supporters: { title: 'Supporters', groups: [{ group: 'sponsor' }] } })),
        ['supporters.groups[0] needs a group and a title'],
    );
});

test('sidebarProblems asks for text parts, and for a label and an https:// address on each link', () => {
    assert.deepEqual(sidebarProblems(sidebarWith({ projects: { title: 'Projects', text: [] } })), ['projects.text needs at least one part']);
    assert.deepEqual(
        sidebarProblems(sidebarWith({ projects: { title: 'Projects', text: ['', { label: 'Team', href: '/team' }, 7] } })),
        [
            'projects.text[0] needs text, or a label and an https:// address',
            'projects.text[1] needs text, or a label and an https:// address',
            'projects.text[2] needs text, or a label and an https:// address',
        ],
    );
});

// The files of phalcon/assets: a change here must not stop the refresh of every site.

test('the tokens file of phalcon/assets passes the check of the sites', () => {
    const tokens = readFileSync(new URL('../phalcon/css/tokens.css', import.meta.url), 'utf8');

    assert.deepEqual(tokensProblems(tokens, []), []);
});

test('the code theme of phalcon/assets passes the check of the sites', () => {
    const json = readFileSync(new URL('../phalcon/css/code-theme.json', import.meta.url), 'utf8');
    const roles = [...json.matchAll(/var\(--code-([a-z0-9-]+)\)/g)].map((match) => match[1]);

    assert.deepEqual(codeThemeProblems(json, roles), []);
});

test('the common.css of phalcon/assets passes the check of the sites, with the tokens file of phalcon/assets', () => {
    const file = readFileSync(new URL('../phalcon/css/common.css', import.meta.url), 'utf8');
    const tokens = readFileSync(new URL('../phalcon/css/tokens.css', import.meta.url), 'utf8');

    assert.deepEqual(commonCssProblems(file, tokens), []);
});

const footer = {
    tagline: 'A full-stack PHP framework.',
    columns: [{ title: 'Framework', links: [{ label: 'Docs', href: 'https://docs.phalcon.io' }] }],
    socials: [{ label: 'Telegram', href: 'https://phalcon.io/telegram' }],
    copyright: 'Phalcon Team',
};
const footerWith = (change) => JSON.stringify({ ...footer, ...change });

test('footerProblems accepts a footer with a tagline, columns, socials and a copyright', () => {
    assert.deepEqual(footerProblems(JSON.stringify(footer)), []);
});

test('footerProblems rejects a file that is not a JSON object', () => {
    assert.deepEqual(footerProblems('<!doctype html>'), ['the file is not JSON']);
    assert.deepEqual(footerProblems('[]'), ['the file is not a JSON object']);
    assert.deepEqual(footerProblems('null'), ['the file is not a JSON object']);
});

test('footerProblems rejects a key that a site does not read', () => {
    assert.deepEqual(footerProblems(footerWith({ title: 'x' })), ['title is not allowed']);
});

test('footerProblems asks for the tagline and the copyright as text', () => {
    assert.deepEqual(footerProblems(footerWith({ copyright: ' ', tagline: undefined })), ['copyright needs text', 'tagline needs text']);
});

test('footerProblems asks for columns with a title and links', () => {
    assert.deepEqual(footerProblems(footerWith({ columns: [] })), ['columns needs at least one column']);
    assert.deepEqual(
        footerProblems(footerWith({ columns: [{ title: '', links: [] }] })),
        ['columns[0] needs a title', 'columns[0].links needs at least one link'],
    );
});

test('footerProblems asks for a label and an https:// address on each link, so that it works on every site', () => {
    const problems = footerProblems(footerWith({
        columns: [{ title: 'Project', links: [{ label: 'Team', href: '/team' }] }],
        socials: [null, { label: '', href: 'https://phalcon.io/t' }, { label: 'X', href: 'javascript:alert(1)' }],
    }));

    assert.deepEqual(problems, [
        'columns[0].links[0] needs a label and an https:// address',
        'socials[0] needs a label and an https:// address',
        'socials[1] needs a label and an https:// address',
        'socials[2] needs a label and an https:// address',
    ]);
});

test('footerProblems asks for at least one social link', () => {
    assert.deepEqual(footerProblems(footerWith({ socials: [] })), ['socials needs at least one link']);
});

test('the sidebar.css of phalcon/assets passes the check of the sites, with the tokens file of phalcon/assets', () => {
    const file = readFileSync(new URL('../phalcon/css/sidebar.css', import.meta.url), 'utf8');
    const tokens = readFileSync(new URL('../phalcon/css/tokens.css', import.meta.url), 'utf8');

    assert.deepEqual(sidebarCssProblems(file, tokens), []);
});

test('the sidebar.json of phalcon/assets passes the check of the sites', () => {
    const json = readFileSync(new URL('../phalcon/sidebar.json', import.meta.url), 'utf8');

    assert.deepEqual(sidebarProblems(json), []);
});

test('the footer.json of phalcon/assets links to the license site, last in the Framework column', () => {
    // phalcon.io has the same link in its own footer data (src/data/site.mjs).
    const data = JSON.parse(readFileSync(new URL('../phalcon/footer.json', import.meta.url), 'utf8'));
    const framework = data.columns.find((column) => column.title === 'Framework');

    assert.deepEqual(framework.links.at(-1), { href: 'https://license.phalcon.io', label: 'License' });
});
