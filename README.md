<p align="center"><a href="https://docs.phalcon.io" target="_blank">
    <img src="https://assets.phalcon.io/phalcon/images/svg/phalcon-logo-transparent-black.svg" height="100" alt="Phalcon"/>
</a></p>

## Phalcon Assets

This repository holds all the assets that Phalcon sites use: the design tokens and the shared stylesheets, the sponsor and GitHub data, the images, logos and icons, and the assets of the Phalcon debug page. <a href="https://assets.phalcon.io/">assets.phalcon.io</a> serves them.

The site is built with [Astro](https://astro.build). The CI workflow (`.github/workflows/main.yml`) checks the PHP and runs the tests, builds the site, and publishes the build to the `production` branch, which Cloudflare Pages serves. It runs after every push and after each data workflow.

#### The folders

- `public/phalcon/`, `public/zephir/`, `public/debug/`: the assets, served at `/phalcon/…`, `/zephir/…` and `/debug/…`. The other sites load them by address, so a file keeps its path and its bytes (`npm run verify` checks it).
- `public/phalcon/css/`: the shared stylesheets of the sites: `tokens.css` (the design tokens), `common.css` (the shared nav and footer), `sidebar.css` (the shared sidebar) and `code-theme.json` (the code theme).
- `public/phalcon/footer.json` and `public/phalcon/sidebar.json`: the links of the shared footer, and the titles and text of the shared sidebar.
- `public/phalcon/tools/`: the shared tools that each site gets before every build: `design-checks.mjs` and `design-refresh.mjs` (the checks and the refresh of the design files) and `stars.mjs` (the star count of the nav).
- `scripts/`: the generators of the data (`generateSponsors.php`, `generateGithub.php`, with `sponsors/` and `github/`), the PHP checks of the shared files (`tokens/`), and the checks of the build (`verify-build.mjs`).
- `tests/`: the PHP tests (`tokens.php`, `github.php`, `sponsors.php`) and the Node tests.
- `src/`: the page of the site (the shared nav, footer and sidebar).

#### The data workflows

- `sponsors.yml` (daily): `php scripts/generateSponsors.php` writes `public/phalcon/sponsors.json` from GitHub Sponsors, Open Collective and `scripts/sponsors/partners.json`.
- `github-data.yml` (daily): `php scripts/generateGithub.php` writes `public/phalcon/repositories.json` and `public/phalcon/contributors.json` for the repositories of `scripts/github/repositories.json`.

Each commits when its data changed. The CI workflow runs after each of them, so the new data is live some minutes later.

#### Local use

The tools run in Docker:

- Install: `docker run --rm -v "$PWD:/app" -w /app node:22-alpine npm ci`
- Tests: `docker run --rm -v "$PWD:/app" -w /app node:22-alpine npm test`
- PHP tests: `docker run --rm -v "$PWD:/app" -w /app php:8.4-cli sh -c 'php tests/tokens.php && php tests/github.php && php tests/sponsors.php'`
- PHP analyzers: `phpcs` (PSR-12, `phpcs.xml`) and `phpstan analyse` (level max, `phpstan.neon`).
- Build and checks: `docker run --rm -v "$PWD:/app" -w /app node:22-alpine sh -c 'npm run build && npm run verify'`
- Preview: `docker run --rm -p 4321:4321 -v "$PWD:/app" -w /app node:22-alpine npx astro preview --host 0.0.0.0`

#### Colors, fonts, the nav and the footer

- To change a color, change `public/phalcon/css/tokens.css`. The PHP checks (`tests/tokens.php`) and the Node tests check the shared files; the other sites get them on their next CI run.
- `public/css/site.css` has the rules of this site. Use a color through the tokens: `var(--nd-…)` or `var(--ph-…)`. `npm test` fails on a typed color in `public/css/site.css` and `src/`.
- The nav (`src/components/Header.astro`) and the footer (`src/components/Footer.astro`) are the ones of phalcon.io, with the classes of `common.css`. `public/js/nav.js` is a copy of the mobile menu script of phalcon.io.

## Sponsors

Become a sponsor and get your logo on our README on Github with a link to your site. [[Become a sponsor](https://opencollective.com/phalcon#sponsor)]

<a href="https://opencollective.com/phalcon/#contributors">
<img src="https://opencollective.com/phalcon/tiers/sponsors.svg?avatarHeight=48&width=800">
</a>

## Backers

Support us with a monthly donation and help us continue our activities. [[Become a backer](https://opencollective.com/phalcon#backer)]

<a href="https://opencollective.com/phalcon/#contributors">
<img src="https://opencollective.com/phalcon/tiers/backers.svg?avatarHeight=48&width=800&height=200">
</a>
