import { strict as assert } from 'node:assert';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

import { footerProblems } from '../phalcon/tools/design-checks.mjs';

test('phalcon/footer.json passes the check that each site runs on it', () => {
    // A site keeps its committed copy when the file has a problem, so a bad file would never reach a site.
    const json = readFileSync(new URL('../phalcon/footer.json', import.meta.url), 'utf8');

    assert.deepEqual(footerProblems(json), []);
});
