import { strict as assert } from 'node:assert';
import { test } from 'node:test';

import { feedNotes, feedProblems, ISSUE_TITLE, issueAction, newestRun, watchIssue } from '../scripts/watch/checks.mjs';

const NOW = Date.parse('2026-10-09T05:47:00Z');
const RUN = 'https://github.com/phalcon/assets/actions/runs/1';
const WATCH = 'https://github.com/phalcon/assets/actions/runs/2';

const run = (overrides = {}) => ({
    conclusion: 'success',
    createdAt: '2026-10-09T04:17:00Z',
    status: 'completed',
    url: RUN,
    ...overrides,
});
const file = (name, overrides = {}) => ({ live: '{"a":1}\n', master: '{"a":1}\n', name, status: 200, ...overrides });
// A good day: both data workflows and the CI passed this morning, and the live feeds are the files of master.
const day = (overrides = {}) => ({
    ciRun: run({ createdAt: '2026-10-09T04:57:00Z' }),
    dataRuns: { 'github-data.yml': run({ createdAt: '2026-10-09T04:47:00Z' }), 'sponsors.yml': run() },
    files: ['sponsors.json', 'repositories.json', 'contributors.json'].map((name) => file(name)),
    now: NOW,
    ...overrides,
});

test('feedProblems finds no problem on a good day', () => {
    assert.deepEqual(feedProblems(day()), []);
});

test('feedProblems reports a failed scheduled run, with its address', () => {
    const dataRuns = { 'github-data.yml': run({ createdAt: '2026-10-09T04:47:00Z' }), 'sponsors.yml': run({ conclusion: 'failure' }) };

    assert.deepEqual(feedProblems(day({ dataRuns })), [`sponsors.yml: the last scheduled run (2026-10-09T04:17:00Z) is failure: ${RUN}`]);
});

test('feedProblems reports a scheduled run that is older than 26 hours', () => {
    const dataRuns = { 'github-data.yml': run({ createdAt: '2026-10-08T03:46:00Z' }), 'sponsors.yml': run() };

    assert.deepEqual(feedProblems(day({ dataRuns })), [
        'github-data.yml: no scheduled run in the last 26 hours (the last: 2026-10-08T03:46:00Z)',
    ]);
});

test('feedProblems accepts a scheduled run of 26 hours or less', () => {
    const dataRuns = { 'github-data.yml': run({ createdAt: '2026-10-08T03:47:00Z' }), 'sponsors.yml': run() };

    assert.deepEqual(feedProblems(day({ dataRuns })), []);
});

test('feedProblems reports a data workflow with no scheduled run', () => {
    assert.deepEqual(feedProblems(day({ dataRuns: { 'github-data.yml': null, 'sponsors.yml': run() } })), [
        'github-data.yml: no scheduled run',
    ]);
});

test('feedProblems does not report a scheduled run that is still running', () => {
    const dataRuns = { 'github-data.yml': run({ conclusion: null, status: 'in_progress' }), 'sponsors.yml': run() };

    assert.deepEqual(feedProblems(day({ dataRuns })), []);
});

test('feedProblems reports a failed CI run on master, with its address', () => {
    assert.deepEqual(feedProblems(day({ ciRun: run({ conclusion: 'failure', createdAt: '2026-10-09T04:57:00Z' }) })), [
        `main.yml: the last run on master (2026-10-09T04:57:00Z) is failure: ${RUN}`,
    ]);
});

test('feedProblems reports a CI with no run on master', () => {
    assert.deepEqual(feedProblems(day({ ciRun: null })), ['main.yml: no run on master']);
});

test('feedProblems reports a live feed that is not the file of master', () => {
    const files = day().files.map((entry) => (entry.name === 'repositories.json' ? { ...entry, live: '{"a":0}\n' } : entry));

    assert.deepEqual(feedProblems(day({ files })), ['phalcon/repositories.json: the live file is not the file of master']);
});

test('feedProblems does not report a different live feed while the CI deploys', () => {
    const files = day().files.map((entry) => ({ ...entry, live: '{"a":0}\n' }));

    assert.deepEqual(feedProblems(day({ ciRun: run({ conclusion: null, status: 'in_progress' }), files })), []);
});

test('feedProblems reports a live feed that does not answer 200, or does not answer', () => {
    const files = day().files.map((entry) => {
        if (entry.name === 'sponsors.json') {
            return { ...entry, live: '', status: 404 };
        }

        return entry.name === 'contributors.json' ? { ...entry, live: '', status: 0 } : entry;
    });

    assert.deepEqual(feedProblems(day({ files })), [
        'phalcon/sponsors.json: the live address answers 404',
        'phalcon/contributors.json: the live address does not answer',
    ]);
});

test('feedNotes says that the watch did not compare the live feeds while the CI deploys', () => {
    const ciRun = run({ conclusion: null, createdAt: '2026-10-09T05:46:00Z', status: 'in_progress' });

    assert.deepEqual(feedNotes({ ciRun }), [
        'main.yml: the run on master (2026-10-09T05:46:00Z) is in_progress, so the watch did not compare the live feeds '
            + `with the files of master: ${RUN}`,
    ]);
});

test('feedNotes has no note when the last CI run on master is complete, or when there is no run', () => {
    assert.deepEqual(feedNotes({ ciRun: run() }), []);
    assert.deepEqual(feedNotes({ ciRun: null }), []);
});

test('issueAction opens an issue for new problems', () => {
    assert.deepEqual(issueAction({ date: '2026-10-09', openIssue: null, problems: ['a', 'b'], runUrl: WATCH }), {
        action: 'create',
        body: `The feed watch (${WATCH}) found these problems on 2026-10-09:\n\n- a\n- b\n\n`
            + 'The watch closes this issue when every check passes again.',
        title: ISSUE_TITLE,
    });
});

test('issueAction adds a comment to the open issue while the problems stay', () => {
    assert.deepEqual(issueAction({ date: '2026-10-10', openIssue: { number: 7 }, problems: ['a'], runUrl: WATCH }), {
        action: 'comment',
        body: `2026-10-10: the feed watch (${WATCH}) still finds these problems:\n\n- a`,
        number: 7,
    });
});

test('issueAction closes the open issue when every check passes again', () => {
    assert.deepEqual(issueAction({ date: '2026-10-11', openIssue: { number: 7 }, problems: [], runUrl: WATCH }), {
        action: 'close',
        body: `2026-10-11: every check passes again (${WATCH}).`,
        number: 7,
    });
});

test('issueAction does nothing on a good day with no open issue', () => {
    assert.deepEqual(issueAction({ date: '2026-10-09', openIssue: null, problems: [], runUrl: WATCH }), { action: 'none' });
});

test('newestRun skips the run of a pull request from a fork branch named master', () => {
    const push = { event: 'push', id: 2 };

    assert.equal(newestRun([{ event: 'pull_request', id: 3 }, push, { event: 'push', id: 1 }]), push);
});

test('newestRun gives null when every run is a pull request run, or when there is no run', () => {
    assert.equal(newestRun([{ event: 'pull_request', id: 3 }]), null);
    assert.equal(newestRun([]), null);
});

test('watchIssue finds the open issue of the watch, not a pull request or an issue with another title', () => {
    const issues = [
        { number: 9, pull_request: {}, title: ISSUE_TITLE },
        { number: 8, title: 'Another issue' },
        { number: 7, title: ISSUE_TITLE },
    ];

    assert.deepEqual(watchIssue(issues), { number: 7 });
});

test('watchIssue gives null when no open issue has the title of the watch', () => {
    assert.equal(watchIssue([{ number: 9, pull_request: {}, title: ISSUE_TITLE }]), null);
    assert.equal(watchIssue([]), null);
});
