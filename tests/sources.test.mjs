import { strict as assert } from 'node:assert';
import { createHash } from 'node:crypto';
import { existsSync, readdirSync, readFileSync } from 'node:fs';
import { test } from 'node:test';

import { missingTokens } from '../public/phalcon/tools/design-checks.mjs';
import { sourceFiles, usedBySite } from '../scripts/token-sources.mjs';

const root = new URL('../', import.meta.url);
const read = (file) => readFileSync(new URL(file, root), 'utf8');

test('the source scan covers the stylesheet, components, layout and pages, not tests or the shared files', () => {
    // A token that only a component uses must count as used.
    const files = sourceFiles();

    for (const file of ['public/css/site.css', 'src/components/Header.astro', 'src/components/Meta.astro', 'src/layouts/Base.astro', 'src/pages/index.astro']) {
        assert.ok(files.includes(file), file);
    }

    assert.ok(!files.some((file) => file.startsWith('public/phalcon/')));
    assert.ok(!files.some((file) => file.endsWith('.test.mjs')));
});

test('no source types a color', () => {
    // Colors come from public/phalcon/css/tokens.css, so a palette change is made once. A data URI hides a hex color
    // as %23…, and an SVG paint attribute can type one (fill="white").
    const color = /#[0-9a-fA-F]{3,8}\b|rgba?\(|%23[0-9a-fA-F]{3,8}\b|\b(?:fill|stroke|stop-color|flood-color|lighting-color)=["'](?!currentColor|none|var\(|url\(|inherit|transparent)[^"']+["']/g;
    const typed = sourceFiles().flatMap((file) =>
        read(file)
            .split('\n')
            .flatMap((line, index) => [...line.matchAll(color)].map((match) => `${file}:${index + 1} ${match[0]}`))
    );

    assert.deepEqual(typed, []);
});

test('the tokens file defines every token that the site uses', () => {
    assert.deepEqual(missingTokens(read('public/phalcon/css/tokens.css'), usedBySite()), []);
});

test('the source scan counts a token that a component reads by name', () => {
    // Meta.astro gives --ph-brand-400 and --ph-dark-bg to resolveToken(), for the browser colors. No stylesheet uses
    // --ph-brand-400, so it shows that the scan counts a name in quotes.
    const used = usedBySite();

    assert.ok(used.has('--ph-brand-400'), '--ph-brand-400');
    assert.ok(used.has('--ph-dark-bg'), '--ph-dark-bg');
});

test('the page uses the shared files of this repository, not copies of them', () => {
    // This repository publishes the shared files; the other sites copy them. A copy here would go live only after a
    // second deploy.
    const meta = read('src/components/Meta.astro');
    const links = [...meta.matchAll(/<link rel="stylesheet" href="([^"]*)">/g)].map((match) => match[1]);
    const copies = [
        'public/css/tokens.css', 'public/css/common.css', 'public/css/sidebar.css', 'src/footer.json', 'src/sidebar.json',
        'src/repositories.json', 'src/sponsors.json', 'src/fanart.html', 'src/lib', 'scripts/update-tokens.mjs',
    ];

    assert.deepEqual(links, ['/phalcon/css/tokens.css', '/phalcon/css/common.css', '/phalcon/css/sidebar.css', '/css/site.css']);
    assert.deepEqual(copies.filter((file) => existsSync(new URL(file, root))), []);
});

test('the CI workflow runs the PHP analyzers and the PHP checks and tests before the build', () => {
    // A broken shared file, a broken generator or code that the analyzers reject stops the deploy.
    const workflow = read('.github/workflows/main.yml');
    const at = (text) => workflow.indexOf(text);
    const steps = ['run: phpcs', 'run: phpstan analyse --no-progress', 'run: php tests/tokens.php', 'run: php tests/github.php', 'run: php tests/sponsors.php'];

    assert.match(workflow, /tools: phpcs:4\.0\.4, phpstan:2\.2\.16/);
    assert.deepEqual(steps.filter((step) => at(step) < 0), []);
    assert.ok(steps.every((step) => at(step) < at('run: npm test')), 'before the Node tests');
    assert.ok(at('run: npm test') < at('run: npm run build'), 'before the build');
});

test('the CI workflow also runs after each data workflow, so that their data goes live', () => {
    // The data workflows commit with GITHUB_TOKEN, which starts no workflow.
    const workflow = read('.github/workflows/main.yml');
    const names = ['sponsors.yml', 'github-data.yml'].map((file) => /^name: (.+)$/m.exec(read(`.github/workflows/${file}`))[1]);

    assert.deepEqual(names, ['Update sponsors', 'Update GitHub data']);
    assert.match(workflow, /\n {2}workflow_run:\n {4}workflows:\n {6}- Update sponsors\n {6}- Update GitHub data\n {4}types:\n {6}- completed\n/);
});

test('the data workflows run the generators of scripts/ and commit the files of public/phalcon/', () => {
    const sponsors = read('.github/workflows/sponsors.yml');
    const github = read('.github/workflows/github-data.yml');

    assert.match(sponsors, /run: php scripts\/generateSponsors\.php/);
    assert.match(sponsors, /git add public\/phalcon\/sponsors\.json/);
    assert.match(github, /run: php scripts\/generateGithub\.php/);
    assert.match(github, /git add public\/phalcon\/repositories\.json public\/phalcon\/contributors\.json/);
    assert.match(read('scripts/sponsors/generator.php'), /const OUTPUT = __DIR__ \. '\/\.\.\/\.\.\/public\/phalcon\/sponsors\.json';/);
});

test('the CI workflow publishes the build to the production branch, which Cloudflare Pages serves', () => {
    const workflow = read('.github/workflows/main.yml');

    assert.match(workflow, /DEPLOY_BRANCH: production/);
    assert.match(workflow, /if: github\.ref == 'refs\/heads\/master' && github\.event_name != 'pull_request'/);
    assert.match(workflow, /branches-ignore:\n\s+- production/);
});

test('the CI workflow runs one deploy at a time on each branch, as phalcon.io does', () => {
    // Two runs close together (a push and a data workflow) must not let the older build publish last.
    // A group keeps only one waiting run, so a pull request run must not cancel a waiting master run.
    assert.match(read('.github/workflows/main.yml'), /\nconcurrency:\n {2}group: deploy-\$\{\{ github\.ref \}\}\n {2}cancel-in-progress: false\n/);
});

test('sidebar.css has a rule for every ph-side class that the sidebar uses', () => {
    // A renamed class would leave the sidebar with no style.
    const css = read('public/phalcon/css/sidebar.css').replace(/\/\*[\s\S]*?\*\//g, '');
    const used = ['src/components/Sidebar.astro', 'src/components/Sponsors.astro']
        .flatMap((file) => [...read(file).matchAll(/class="([^"]*)"/g)])
        .flatMap((match) => match[1].split(/\s+/))
        .filter((name) => name.startsWith('ph-side'));
    const missing = [...new Set(used)].filter((name) => !new RegExp(`\\.${name}(?![\\w-])`).test(css));

    assert.ok(used.length >= 8, 'the sidebar uses the shared classes');
    assert.deepEqual(missing, []);
});

test('common.css has a rule for every ph- class that the nav and the footer use', () => {
    // A renamed class would leave an element with no style.
    const css = read('public/phalcon/css/common.css').replace(/\/\*[\s\S]*?\*\//g, '');
    const used = ['src/components/Header.astro', 'src/components/Footer.astro']
        .flatMap((file) => [...read(file).matchAll(/class="([^"]*)"/g)])
        .flatMap((match) => match[1].split(/\s+/))
        .filter((name) => name.startsWith('ph-'));
    const missing = [...new Set(used)].filter((name) => !new RegExp(`\\.${name}(?![\\w-])`).test(css));

    assert.ok(used.length > 30, 'the nav and the footer use the shared classes');
    assert.deepEqual(missing, []);
});

test('the nav has the links of phalcon.io, with absolute addresses', () => {
    // The site is not on phalcon.io: a relative link of phalcon.io (/download) would point into this site. The
    // scripts come after the markup.
    const header = read('src/components/Header.astro').split('<script')[0];
    const links = [...header.matchAll(/href: '([^']*)'|href="([^"]*)"/g)].map((match) => match[1] ?? match[2]);

    assert.ok(links.length >= 15, `${links.length} links`);
    assert.deepEqual(links.filter((href) => !href.startsWith('https://') && href !== '/'), []);
});

test('the page uses the class names of this site, not the ones of the old blog layout', () => {
    // The old shared sheet named the page after the blog (phalcon-blog, phalcon-blog__main, …).
    assert.match(read('src/layouts/Base.astro'), /<div class="page">/);
    assert.deepEqual(sourceFiles().filter((file) => read(file).includes('phalcon-blog')), []);
});

test('no file of the Jekyll site is left, and the asset folders are under public/', () => {
    // The site is built by Astro and published by .github/workflows/main.yml. The generators are in scripts/.
    const old = [
        '.github/workflows/jekyll.yml', '.github/workflows/tokens.yml', '.ruby-version', 'Gemfile', '_config.yml',
        '_includes', '_layouts', '_sass', 'css', 'index.html', '404.html', 'build.sh', 'updateData.php', 'serve',
        'generateSponsors.php', 'generateGithub.php', '_github', '_sponsors', '_tokens', 'phalcon', 'zephir', 'debug',
    ];

    assert.deepEqual(old.filter((file) => existsSync(new URL(file, root))), []);
    assert.deepEqual(['phalcon', 'zephir', 'debug'].filter((folder) => !existsSync(new URL(`public/${folder}`, root))), []);
});

test('the shared stylesheets are the only stylesheets in public/phalcon/css/', () => {
    // The old stylesheets of the old design are gone; no Phalcon site loads them.
    assert.deepEqual(readdirSync(new URL('public/phalcon/css/', root)).sort(), ['code-theme.json', 'common.css', 'sidebar.css', 'tokens.css']);
});

test('the ignore rules of .gitignore start at the root, so that no file of the asset folders is left out', () => {
    // A rule with no leading slash matches at any depth: "dist" also hid the jquery/dist/ folders of public/debug/.
    const rules = read('.gitignore').split('\n').map((line) => line.trim()).filter((line) => line !== '' && !line.startsWith('#'));

    assert.ok(rules.length > 0, 'rules');
    assert.deepEqual(rules.filter((rule) => !rule.startsWith('/')), []);
});

test('.gitattributes keeps the bytes of the files under public/', () => {
    // With text=auto (a global setting of the developer), git stores a CRLF file at a new path with LF. The other
    // sites and the debug pages load these files as they are.
    assert.match(read('.gitattributes'), /^public\/\*\* -text$/m);
});

test('no file under public/ has a name that starts with a dot or an underscore', () => {
    // Jekyll did not serve such files; Astro serves every file of public/. They were deleted, so no address changed.
    const hidden = readdirSync(new URL('public/', root), { recursive: true })
        .filter((path) => path.split('/').some((part) => /^[._]/.test(part)));

    assert.deepEqual(hidden, []);
});

test('the theme switcher shows a ring for the keyboard focus', () => {
    // WCAG 2.4.7: a keyboard user sees where the focus is. A mouse click shows no ring (:focus-visible).
    const css = read('public/css/site.css');

    assert.doesNotMatch(/\.switcher button \{[^}]*\}/.exec(css)?.[0] ?? '', /outline:\s*none/);
    assert.match(css, /\.switcher button:focus-visible \{\s*outline: 2px solid var\(--ph-white\);\s*outline-offset: 2px;\s*\}/);
});

test('site.css sets its text sizes in rem, so that they follow the default size of the reader', () => {
    // A px size stays the same when the reader sets a larger default font size in the browser.
    const css = read('public/css/site.css').replace(/\/\*[\s\S]*?\*\//g, '');

    assert.deepEqual([...css.matchAll(/(?:font-size|line-height)\s*:\s*[^;]*\dpx/g)].map((match) => match[0]), []);
});

test('common.css has the end group of the nav and the Discord icon, with the light color on hover', () => {
    // The end group holds the links, the icon, the items of a site and the burger (roadmap Phase 9).
    const css = read('public/phalcon/css/common.css');

    assert.match(css, /\n\.ph-nav__end \{\n {4}align-items: center;\n {4}display: flex;\n {4}gap: 1\.5rem;\n\}\n/);
    assert.match(css, /\n\.ph-nav__discord \{\n {4}color: var\(--ph-sage-400\);\n {4}display: flex;\n {4}padding: 2px;\n\}\n/);
    assert.match(css, /@media \(hover: hover\) \{[^}]*\.ph-nav__discord:hover,[^{]*\{\n {8}color: var\(--ph-mist-100\);/);
});

test('common.css draws the burger lines with a border, so that they show in forced colors', () => {
    // In forced colors the browser removes a background color, but it keeps a border and gives it a system color.
    const rule = /\n\.ph-nav__burger-line \{([^}]*)\}/.exec(read('public/phalcon/css/common.css'))?.[1] ?? '';

    assert.match(rule, /\n {4}border-color: var\(--ph-mist-100\);\n {4}border-top-width: 2px;\n/);
    assert.doesNotMatch(rule, /background/);
});

test('the nav has the Discord icon in the end group of common.css, after the links', () => {
    // Discord is one click away at all widths (roadmap Phase 9). The link has a name for screen readers; the SVG
    // has none. The order of the end group: the links, the icon, the theme switcher, the burger.
    const nav = read('src/components/Header.astro');
    const order = ['class="ph-nav__end"', 'class="ph-nav__links"', 'class="ph-nav__discord"', 'class="switcher"', 'class="ph-nav__burger"']
        .map((part) => nav.indexOf(part));
    const glyph = /class="ph-nav__discord"[^>]*>\s*<svg[^>]*>\s*<path fill="currentColor" d="([^"]+)"\/>/.exec(nav)?.[1] ?? '';

    assert.ok(order.every((at, index) => at > (order[index - 1] ?? -1)), `the places: ${order.join(', ')}`);
    // The burger is the last item of the end group, and the end group is the last item of the bar.
    assert.match(nav, /<span class="ph-nav__burger-line"><\/span>\s*<\/button>\s*<\/div>\s*<\/div>\s*<div id="nav-mobile"/);
    assert.match(nav, /<a href="https:\/\/phalcon\.io\/discord" class="ph-nav__discord" aria-label="Discord">\s*<svg width="20" height="20" viewBox="0 0 24 24" aria-hidden="true" focusable="false">/);
    // The Discord glyph of Simple Icons 16.32.0 (CC0-1.0), the same on every site.
    assert.equal(createHash('sha256').update(glyph).digest('hex'), '6806ec0e2319eb7f7d9f5d02636a197d42903ff8f8e9bab0c267c8fabc1b03fe');
});

test('the nav uses the end group of common.css, not one of its own', () => {
    // .nav-end was the end group of this site before common.css had .ph-nav__end.
    assert.deepEqual(['src/components/Header.astro', 'public/css/site.css'].filter((file) => read(file).includes('nav-end')), []);
});

test('the nav has the GitHub icon and the star count as the last item of the links, left of the Discord icon', () => {
    // The end of the bar: the links (the GitHub icon and the star count last), then the Discord icon (roadmap
    // Phase 9). The link has a name for screen readers; the SVG has none.
    const nav = read('src/components/Header.astro');
    const github = /<a href=\{github\} class="ph-nav__github" aria-label=\{`GitHub, \$\{stars\} stars`\}>\s*<svg width="20" height="20" viewBox="0 0 24 24" aria-hidden="true" focusable="false">\s*<path fill="currentColor" d="([^"]+)"\/>\s*<\/svg>\s*<span class="ph-nav__stars">★ \{stars\}<\/span>\s*<\/a>\s*<\/div>\s*<a href="https:\/\/phalcon\.io\/discord"/.exec(nav);

    assert.ok(github, 'the GitHub link, with its icon and the star count, is the last item of the links');
    assert.ok(nav.indexOf('class="ph-nav__cta"') < nav.indexOf('class="ph-nav__github"'), 'the GitHub link comes after "Get Phalcon"');
    // The GitHub glyph of Simple Icons 16.32.0 (CC0-1.0), the same on every site.
    assert.equal(createHash('sha256').update(github?.[1] ?? '').digest('hex'), 'd82e21f6c9bfbfd889fed4b8d8604121be1d364ef75b7fe42cc9c0b8737ae529');
});
