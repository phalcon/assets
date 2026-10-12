/**
 * The feed watch of assets.phalcon.io (.github/workflows/watch.yml): reads the last runs of the data workflows and of
 * the CI, and the live feeds, then opens, updates or closes the GitHub issue of the feeds. The checks are in
 * scripts/watch/checks.mjs; this file has the network.
 *
 *   node scripts/watch-feeds.mjs             the workflow: GITHUB_TOKEN with issues: write, actions: read
 *   node scripts/watch-feeds.mjs --dry-run   prints the problems and the action, writes nothing (no token needed)
 *
 * Exits 1 when there is a problem (the run then fails too) or when a call to the GitHub API fails; else 0.
 */
import { readFileSync } from 'node:fs';

import {
    DATA_WORKFLOWS, FEEDS, ISSUE_TITLE, feedNotes, feedProblems, issueAction, newestRun,
} from './watch/checks.mjs';

const dryRun = process.argv.includes('--dry-run');
const repo = process.env.GITHUB_REPOSITORY ?? 'phalcon/assets';
const token = process.env.GITHUB_TOKEN ?? '';
const runUrl = process.env.GITHUB_RUN_ID
    ? `${process.env.GITHUB_SERVER_URL}/${repo}/actions/runs/${process.env.GITHUB_RUN_ID}`
    : 'a local run';

/** A call to the GitHub API of the repository. */
async function api(path, options = {}) {
    const response = await fetch(`https://api.github.com/repos/${repo}${path}`, {
        ...options,
        headers: {
            accept: 'application/vnd.github+json',
            'x-github-api-version': '2022-11-28',
            ...(token === '' ? {} : { authorization: `Bearer ${token}` }),
            ...(options.body === undefined ? {} : { 'content-type': 'application/json' }),
        },
    }).catch((error) => {
        // fetch() gives only "fetch failed": the cause has the code (for example ENOTFOUND).
        const cause = error.cause?.code ?? error.message;

        throw new Error(`the GitHub API did not answer ${options.method ?? 'GET'} ${path}: ${cause}`);
    });

    if (!response.ok) {
        throw new Error(`the GitHub API answered ${response.status} for ${options.method ?? 'GET'} ${path}`);
    }

    return response.json();
}

/** The last run of a workflow, with a query (event=schedule, branch=master), or null (see newestRun). */
async function lastRun(workflow, query) {
    const { workflow_runs: runs } = await api(`/actions/workflows/${workflow}/runs?${query}&per_page=10`);
    const run = newestRun(runs);

    if (run === null) {
        return null;
    }

    return { conclusion: run.conclusion, createdAt: run.created_at, status: run.status, url: run.html_url };
}

/** A live feed: the HTTP status (0 when the address does not answer) and the text. */
async function liveFeed(name) {
    try {
        const response = await fetch(`https://assets.phalcon.io/phalcon/${name}`);

        return { live: await response.text(), status: response.status };
    } catch {
        return { live: '', status: 0 };
    }
}

try {
    const now = Date.now();
    const dataRuns = Object.fromEntries(await Promise.all(
        DATA_WORKFLOWS.map(async (workflow) => [workflow, await lastRun(workflow, 'event=schedule')]),
    ));
    const ciRun = await lastRun('main.yml', 'branch=master');
    const files = await Promise.all(FEEDS.map(async (name) => ({
        name,
        ...(await liveFeed(name)),
        master: readFileSync(new URL(`../public/phalcon/${name}`, import.meta.url), 'utf8'),
    })));
    const problems = feedProblems({ ciRun, dataRuns, files, now });
    const issues = await api('/issues?state=open&per_page=100');
    const open = issues.find((issue) => issue.title === ISSUE_TITLE && issue.pull_request === undefined);
    const action = issueAction({
        date: new Date(now).toISOString().slice(0, 10),
        openIssue: open === undefined ? null : { number: open.number },
        problems,
        runUrl,
    });

    const number = action.number === undefined ? '' : ` #${action.number}`;

    console.log(problems.length === 0 ? 'problems: none' : problems.map((problem) => `problem: ${problem}`).join('\n'));
    feedNotes({ ciRun }).forEach((note) => console.log(`note: ${note}`));
    console.log(`issue: ${action.action}${number}${dryRun ? ' (dry run)' : ''}`);

    if (!dryRun && action.action === 'create') {
        await api('/issues', { body: JSON.stringify({ body: action.body, title: action.title }), method: 'POST' });
    }

    if (!dryRun && (action.action === 'comment' || action.action === 'close')) {
        await api(`/issues/${action.number}/comments`, { body: JSON.stringify({ body: action.body }), method: 'POST' });
    }

    if (!dryRun && action.action === 'close') {
        await api(`/issues/${action.number}`, { body: JSON.stringify({ state: 'closed' }), method: 'PATCH' });
    }

    process.exitCode = problems.length === 0 ? 0 : 1;
} catch (error) {
    console.log(`error: ${error.message}`);
    process.exitCode = 1;
}
