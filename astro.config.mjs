// @ts-check
import { defineConfig } from 'astro/config';

// https://astro.build/config
export default defineConfig({
    site: 'https://assets.phalcon.io',
    /*
     * The URLs of the site: `file` writes `404.html`, the page that Cloudflare
     * Pages serves for a path that does not exist, and `never` serves each page
     * with no trailing slash.
     */
    build: {
        format: 'file',
    },
    trailingSlash: 'never',
    server: {
        host: true,
        port: 4321,
    },
});
