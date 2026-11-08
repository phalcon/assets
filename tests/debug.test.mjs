import { strict as assert } from 'node:assert';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import vm from 'node:vm';

const source = (version) => readFileSync(new URL(`../public/debug/${version}/debug.js`, import.meta.url), 'utf8');

// The smallest element that debug.js uses: attributes, children and the properties it sets.
function element(tag) {
    const attributes = {};
    const node = {
        tagName: tag.toUpperCase(),
        children: [],
        classList: { add() {}, remove() {}, toggle() {} },
        style: {},
        textContent: '',
        innerHTML: '',
        value: '',
        getAttribute: (name) => (name in attributes ? attributes[name] : null),
        setAttribute: (name, value) => { attributes[name] = String(value); },
        appendChild: (child) => { node.children.push(child); return child; },
        removeChild: (child) => { node.children = node.children.filter((item) => item !== child); return child; },
        querySelector: () => null,
        select() {},
        closest: () => null,
    };

    return node;
}

function frameFile(file, line) {
    const node = element('div');
    node.setAttribute('data-file', file);
    node.setAttribute('data-line', line);

    return node;
}

function copyButton(path) {
    const button = element('button');
    button.setAttribute('data-copy', path);
    button.closest = (selector) => (selector === '[data-copy]' ? button : null);

    return button;
}

// Runs debug.js on a fake page. `clipboard` is the navigator.clipboard object; leave it out for an http:// page.
function page({ clipboard, execCommand = () => true, frames = [] } = {}) {
    const body = element('body');
    const commands = [];
    const listeners = {};
    const document = {
        readyState: 'complete',
        body,
        documentElement: element('html'),
        createElement: element,
        querySelectorAll: (selector) => (selector === '.frame-file[data-file]' ? frames : []),
        addEventListener: (type, handler) => { listeners[type] = handler; },
        execCommand: (command) => {
            commands.push({ command, value: body.children.at(-1).value });

            return execCommand(command);
        },
    };

    vm.runInNewContext(source('6.0.x'), {
        document,
        navigator: clipboard ? { clipboard } : {},
        localStorage: { getItem: () => null, setItem() {} },
        setTimeout: () => 0,
        clearTimeout() {},
    });

    return {
        body,
        commands,
        click: (target) => listeners.click({ target, preventDefault() {} }),
    };
}

const toastText = (body) => body.children.find((child) => child.className === 'phalcon-debug-toast')?.textContent;

test('debug.js is the same for 5.0.x and 6.0.x', () => {
    assert.equal(source('5.0.x'), source('6.0.x'));
});

test('the IDE links encode the path, and the copy button escapes it', () => {
    // encodeURI() kept "&", "#" and "?", so such a path broke the "file=" value of the PhpStorm link.
    const frame = frameFile('/app/a&b #c?.php', '12');
    page({ frames: [frame] });

    const html = frame.children[0].innerHTML;

    assert.ok(html.includes('data-copy="/app/a&amp;b #c?.php"'), html);
    assert.ok(html.includes('href="phpstorm://open?file=%2Fapp%2Fa%26b%20%23c%3F.php&amp;line=12"'), html);
    assert.ok(html.includes('href="vscode://file/app/a%26b%20%23c%3F.php:12"'), html);
});

test('copy uses the clipboard API when the page has it', async () => {
    const written = [];
    const { body, click } = page({ clipboard: { writeText: (text) => { written.push(text); return Promise.resolve(); } } });

    click(copyButton('/app/x.php'));
    await new Promise((resolve) => setImmediate(resolve));

    assert.deepEqual(written, ['/app/x.php']);
    assert.equal(toastText(body), 'Path copied');
});

test('copy uses a hidden textarea when the clipboard API is not there', () => {
    // An http:// page that is not localhost has no navigator.clipboard.
    const { body, commands, click } = page();

    click(copyButton('/app/x.php'));

    assert.deepEqual(commands, [{ command: 'copy', value: '/app/x.php' }]);
    assert.equal(body.children.length, 1, 'only the toast stays on the page');
    assert.equal(toastText(body), 'Path copied');
});

test('a copy that fails shows "Copy failed"', async () => {
    const textarea = page({ execCommand: () => false });
    textarea.click(copyButton('/app/x.php'));

    const rejected = page({ clipboard: { writeText: () => Promise.reject(new Error('denied')) } });
    rejected.click(copyButton('/app/x.php'));
    await new Promise((resolve) => setImmediate(resolve));

    assert.equal(toastText(textarea.body), 'Copy failed');
    assert.equal(toastText(rejected.body), 'Copy failed');
});
