import { strict as assert } from 'node:assert';
import { existsSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import http from 'node:http';
import { join } from 'node:path';
import { after, before, test } from 'node:test';
import { fileURLToPath } from 'node:url';

import { fromArgument, refresh } from '../phalcon/tools/design-refresh.mjs';

// A folder in the checkout, not in the temporary folder of the system. The tests remove it at the end.
const work = mkdtempSync(join(fileURLToPath(new URL('..', import.meta.url)), '.refresh-test-'));
const served = { '/css/bad.css': 'bad text', '/css/good.css': 'new text' };
const noProblem = () => [];
let server;
let source;

/** A server that answers with the files of `served`, and 404 for every other path. */
const listen = (handler) => new Promise((resolve) => {
    const next = http.createServer(handler);

    next.listen(0, '127.0.0.1', () => resolve(next));
});

/** A committed copy with this text, in the work folder. */
const copyOf = (name, text) => {
    const path = join(work, name);

    writeFileSync(path, text);

    return path;
};

/** A log that keeps its lines. */
const lines = () => {
    const out = [];

    return { log: (line) => out.push(line), out };
};

before(async () => {
    server = await listen((request, response) => {
        const body = served[request.url];

        response.writeHead(body === undefined ? 404 : 200).end(body);
    });
    source = `http://127.0.0.1:${server.address().port}/css`;
});

after(() => {
    server.close();
    rmSync(work, { force: true, recursive: true });
});

test('refresh replaces a copy with a new file that passes its check', async () => {
    const copy = copyOf('changed.css', 'old text');
    const { log, out } = lines();
    const results = await refresh({ files: [{ copy, name: 'good.css', problems: noProblem }], log, source });

    assert.deepEqual(results, [{ copy, problems: [], result: 'changed' }]);
    assert.equal(readFileSync(copy, 'utf8'), 'new text');
    assert.deepEqual(out, [`changed       ${copy}`]);
});

test('refresh leaves a copy that is the same', async () => {
    const copy = copyOf('same.css', 'new text');
    const { log, out } = lines();
    const results = await refresh({ files: [{ copy, name: 'good.css', problems: noProblem }], log, source });

    assert.deepEqual(results, [{ copy, problems: [], result: 'same' }]);
    assert.deepEqual(out, [`same          ${copy}`]);
});

test('refresh keeps the copy and warns when the new file has a problem', async () => {
    const copy = copyOf('problem.css', 'old text');
    const { log, out } = lines();
    const results = await refresh({ files: [{ copy, name: 'good.css', problems: () => ['a problem'] }], log, source });

    assert.deepEqual(results, [{ copy, problems: ['a problem'], result: 'kept' }]);
    assert.equal(readFileSync(copy, 'utf8'), 'old text');
    assert.deepEqual(out, ['::warning title=Design tokens::good.css: a problem', `kept          ${copy}`]);
});

test('refresh keeps the copy and warns when the server does not have the file', async () => {
    const copy = copyOf('missing.css', 'old text');
    const { log, out } = lines();
    const results = await refresh({ files: [{ copy, name: 'missing.css', problems: noProblem }], log, source });

    assert.deepEqual(results, [{ copy, problems: ['cannot read the file: HTTP 404'], result: 'kept' }]);
    assert.equal(readFileSync(copy, 'utf8'), 'old text');
    assert.equal(out[0], '::warning title=Design tokens::missing.css: cannot read the file: HTTP 404');
});

test('refresh keeps the copy when the server cannot be reached', async () => {
    const closed = await listen(() => {});
    const port = closed.address().port;

    await new Promise((resolve) => closed.close(resolve));

    const copy = copyOf('unreachable.css', 'old text');
    const { log } = lines();
    const [result] = await refresh({ files: [{ copy, name: 'good.css', problems: noProblem }], log, source: `http://127.0.0.1:${port}/css` });

    assert.equal(result.result, 'kept');
    assert.match(result.problems[0], /^cannot read the file: /);
    assert.equal(readFileSync(copy, 'utf8'), 'old text');
});

test('refresh checks each file alone', async () => {
    const good = copyOf('alone-good.css', 'old text');
    const bad = copyOf('alone-bad.css', 'old text');
    const check = (text) => (text === 'bad text' ? ['not good'] : []);
    const { log } = lines();
    const results = await refresh({
        files: [
            { copy: bad, name: 'bad.css', problems: check },
            { copy: good, name: 'good.css', problems: check },
        ],
        log,
        source,
    });

    assert.deepEqual(results.map(({ result }) => result), ['kept', 'changed']);
    assert.equal(readFileSync(bad, 'utf8'), 'old text');
    assert.equal(readFileSync(good, 'utf8'), 'new text');
});

test('refresh reads the files from a folder when from is given', async () => {
    copyOf('local.css', 'local text');

    const copy = copyOf('from.css', 'old text');
    const { log } = lines();
    const results = await refresh({ files: [{ copy, name: 'local.css', problems: noProblem }], from: work, log, source });

    assert.deepEqual(results, [{ copy, problems: [], result: 'changed' }]);
    assert.equal(readFileSync(copy, 'utf8'), 'local text');
});

test('refresh writes a copy that does not exist yet', async () => {
    const copy = join(work, 'first.css');
    const { log } = lines();
    const results = await refresh({ files: [{ copy, name: 'good.css', problems: noProblem }], log, source });

    assert.deepEqual(results, [{ copy, problems: [], result: 'changed' }]);
    assert.ok(existsSync(copy));
    assert.equal(readFileSync(copy, 'utf8'), 'new text');
});

test('fromArgument gives the folder after --from, or null', () => {
    assert.equal(fromArgument(['--from', 'files']), 'files');
    assert.equal(fromArgument([]), null);
    assert.equal(fromArgument(['--from']), null);
});
