/**
 * Checks the build of the assets site. Run after `npm run build`.
 */
import { existsSync, readFileSync, readdirSync, statSync } from 'node:fs';

import { missingTokens, resolveToken, usedTokens } from '../public/phalcon/tools/design-checks.mjs';
import { cphalconStars, formatStars } from '../public/phalcon/tools/stars.mjs';

/* The folders of the assets that the other sites load, under public/. */
const ASSET_FOLDERS = ['phalcon', 'zephir', 'debug'];

const checks = [];

const count = (label, actual, expected) =>
    checks.push({ actual, expected, label, ok: actual === expected });

const present = (label, path) =>
    checks.push({ actual: existsSync(path), expected: true, label, ok: existsSync(path) });

/** Runs a measurement, turning a throw into a reported failure. */
const measure = (fn) => {
    try {
        return fn();
    } catch (error) {
        return `error: ${error.code ?? error.message}`;
    }
};

/**
 * Lists every file under `root`. It skips nothing: a folder of the assets can
 * have any name (public/debug/ has jquery/dist/ folders).
 */
const listFiles = (root) => {
    const files = [];

    for (const entry of readdirSync(root, { withFileTypes: true })) {
        const path = `${root}/${entry.name}`;

        if (entry.isDirectory()) {
            files.push(...listFiles(path));
        } else if (entry.isFile()) {
            files.push(path);
        }
    }

    return files;
};

/** The file with the newest mtime among the given paths (files or directory trees). */
const newestOf = (paths) => {
    const files = paths.flatMap((path) => (statSync(path).isDirectory() ? listFiles(path) : path));

    let newest = { mtimeMs: -Infinity, path: 'none' };

    for (const file of files) {
        const mtimeMs = statSync(file).mtimeMs;

        if (mtimeMs > newest.mtimeMs) {
            newest = { mtimeMs, path: file };
        }
    }

    return newest;
};

/*
 * `dist/` carries no memory of the source it was built from, so a failed
 * rebuild that leaves an old `dist/` in place looks identical to a good one.
 * This must run before any check below.
 */
count(
    'dist is current',
    measure(() => {
        const source = newestOf(['src', 'public', 'astro.config.mjs', 'package.json', 'scripts']);
        const dist = newestOf(['dist']);

        return source.mtimeMs > dist.mtimeMs
            ? `${source.path} (${new Date(source.mtimeMs).toISOString()}) is newer than dist (${new Date(dist.mtimeMs).toISOString()})`
            : 'ok';
    }),
    'ok'
);

/*
 * The assets. Every file of the asset folders is in dist/ at the same path,
 * with the same bytes: the other sites load them by address. The committed
 * list tests/assets.txt names them, so a file that the commit does not hold
 * (an ignore rule, a delete) fails the check, and so does a file that the
 * list does not name.
 */
const assets = measure(() => ASSET_FOLDERS.flatMap((folder) => listFiles(`public/${folder}`)));
const listed = measure(() => readFileSync('tests/assets.txt', 'utf8').split('\n').filter((line) => line !== ''));
const relative = (path) => path.replace(/^public\//, '');

count(
    'files of tests/assets.txt that are not in public/',
    measure(() => listed.filter((path) => !existsSync(`public/${path}`)).join(', ') || 'none'),
    'none'
);
count(
    'files of the asset folders that tests/assets.txt does not list',
    measure(() => assets.map(relative).filter((path) => !listed.includes(path)).join(', ') || 'none'),
    'none'
);
count(
    'asset files that are missing from dist or are not the same',
    measure(() => assets.filter((file) => {
        const built = file.replace(/^public\//, 'dist/');

        return !existsSync(built) || !readFileSync(built).equals(readFileSync(file));
    }).join(', ') || 'none'),
    'none'
);
count(
    'files in dist/phalcon/css/',
    measure(() => readdirSync('dist/phalcon/css').sort().join(' ')),
    'code-theme.json common.css sidebar.css tokens.css'
);

const pages = ['dist/index.html', 'dist/404.html'];
const html = (file) => readFileSync(file, 'utf8');

/* The page: the name of the site as its h1. Each page has one h1. */
count('home page heading', measure(() => /<h1>([^<]*)<\/h1>/.exec(html('dist/index.html'))?.[1] ?? 'none'), 'Phalcon Assets');
count('pages without exactly one h1', measure(() => pages.filter((file) => (html(file).match(/<h1[\s>]/g) ?? []).length !== 1).length), 0);

/*
 * Design tokens. Every page loads the shared tokens.css, common.css and
 * sidebar.css of this repository and then site.css, and no other stylesheet.
 * The stylesheets use only tokens that tokens.css defines, and the browser bar
 * takes its color from the tokens.
 */
count(
    'pages that do not load exactly tokens.css, common.css, sidebar.css and then site.css',
    measure(() => pages.filter((file) => {
        const links = [...html(file).matchAll(/<link[^>]*rel="stylesheet"[^>]*>/g)].map((match) => /href="([^"]*)"/.exec(match[0])?.[1]);

        return links.join(' ') !== '/phalcon/css/tokens.css /phalcon/css/common.css /phalcon/css/sidebar.css /css/site.css';
    }).length),
    0
);
count(
    'tokens that site.css, common.css and sidebar.css use and tokens.css does not define',
    measure(() => missingTokens(html('dist/phalcon/css/tokens.css'), [...usedTokens(html('dist/css/site.css')), ...usedTokens(html('dist/phalcon/css/common.css')), ...usedTokens(html('dist/phalcon/css/sidebar.css'))]).join(', ') || 'none'),
    'none'
);
count(
    'browser bar color',
    measure(() => /<meta name="theme-color" content="([^"]*)"/.exec(html('dist/index.html'))?.[1] ?? 'none'),
    resolveToken(readFileSync('public/phalcon/css/tokens.css', 'utf8'), '--ph-dark-bg')
);

/*
 * The shared nav and footer (common.css, the classes of phalcon.io). Every
 * page has them, with the theme switcher and the mobile menu script, and
 * nothing of the Jekyll site: its stylesheet and scripts, or a file from
 * raw.githubusercontent.com. The nav shows the stars of
 * public/phalcon/repositories.json, and the footer every link of
 * public/phalcon/footer.json.
 */
const without = (...parts) => pages.filter((file) => parts.some((part) => !part.test(html(file)))).length;

count('pages without the shared nav and footer', measure(() => without(/<nav class="ph-nav">/, /<footer class="ph-footer">/)), 0);
count('pages without the theme switcher', measure(() => without(/<div class="switcher">/)), 0);
count('pages without the mobile menu script', measure(() => without(/<script[^>]*src="\/js\/nav\.js"/)), 0);
count(
    'pages with a part of the Jekyll site',
    measure(() => pages.filter((file) => /\/css\/style\.css|theme\.min\.js|main\.min\.js|raw\.githubusercontent\.com/.test(html(file))).length),
    0
);
count(
    'stars in the nav',
    measure(() => /class="ph-nav__stars">★ ([^<]*)</.exec(html('dist/index.html'))?.[1] ?? 'none'),
    measure(() => formatStars(cphalconStars(readFileSync('public/phalcon/repositories.json', 'utf8'))))
);
count(
    'footer links',
    measure(() => (html('dist/index.html').match(/class="ph-footer__link"/g) ?? []).length),
    measure(() => JSON.parse(readFileSync('public/phalcon/footer.json', 'utf8')).columns.flatMap((column) => column.links).length)
);
/*
 * The shared sidebar (sidebar.css): the box of the supporters and the box of
 * the projects text, with every link of the text of public/phalcon/sidebar.json.
 */
count('sidebar boxes', measure(() => (html('dist/index.html').match(/<div class="ph-side__box">/g) ?? []).length), 2);
count(
    'links of the projects text',
    measure(() => (/<div class="ph-side__text">([\s\S]*?)<\/div>/.exec(html('dist/index.html'))?.[1].match(/<a /g) ?? []).length),
    measure(() => JSON.parse(readFileSync('public/phalcon/sidebar.json', 'utf8')).projects.text.filter((part) => typeof part === 'object').length)
);
count(
    'canonical address of the home page',
    measure(() => /<link rel="canonical" href="([^"]*)"/.exec(html('dist/index.html'))?.[1] ?? 'none'),
    'https://assets.phalcon.io/'
);

/* The files of the site itself. */
for (const path of [
    'dist/index.html',
    'dist/404.html',
    'dist/robots.txt',
    'dist/humans.txt',
    'dist/sitemap.xml',
    'dist/css/site.css',
    'dist/js/nav.js',
]) {
    present(path.replace('dist/', ''), path);
}

for (const check of checks) {
    console.log(`${check.ok ? 'ok  ' : 'FAIL'}  ${check.label}: ${check.actual} (want ${check.expected})`);
}

const failed = checks.filter((check) => !check.ok).length;

console.log(`\n${checks.length - failed} of ${checks.length} checks passed`);
process.exit(failed === 0 ? 0 : 1);
