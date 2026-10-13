/**
 * The checks of the feed watch (scripts/watch-feeds.mjs, .github/workflows/watch.yml): pure functions, with no
 * network. The watch runs every day after the data workflows and their deploys. When the feeds of
 * assets.phalcon.io are not current, it opens a GitHub issue (Open Item 10 of the sites roadmap).
 */

/** The data workflows that write the feeds. */
export const DATA_WORKFLOWS = ['sponsors.yml', 'github-data.yml'];

/** The feeds: public/phalcon/<file> of master, https://assets.phalcon.io/phalcon/<file> live. */
export const FEEDS = ['sponsors.json', 'repositories.json', 'contributors.json'];

/** The title of the issue that the watch opens, updates and closes. */
export const ISSUE_TITLE = 'The assets feeds need attention';

/** The data workflows run every day: a last scheduled run older than this is a problem. */
export const MAX_AGE_HOURS = 26;

/**
 * The notes of the watch, as text: the checks that it did not do. An empty list when it did all the checks.
 *
 * @param {object} data
 * @param {object|null} data.ciRun The last run of main.yml on master.
 * @returns {string[]}
 */
export function feedNotes({ ciRun }) {
    if (!deploying(ciRun)) {
        return [];
    }

    const skipped = 'so the watch did not compare the live feeds with the files of master';

    return [`main.yml: the run on master (${ciRun.createdAt}) is ${ciRun.status}, ${skipped}: ${ciRun.url}`];
}

/**
 * The problems of the feeds, as text: an empty list when every check passes.
 *
 * A run is `{ conclusion, createdAt, status, url }` (the GitHub API fields `conclusion`, `created_at`, `status`,
 * `html_url`), or null when there is no run.
 *
 * @param {object} data
 * @param {number} data.now The time of the check, in milliseconds.
 * @param {Record<string, object|null>} data.dataRuns The last scheduled run of each data workflow.
 * @param {object|null} data.ciRun The last run of main.yml on master.
 * @param {{ name: string, status: number, live: string, master: string }[]} data.files Each feed: the HTTP status
 *     of the live address (0 when it does not answer), the live text and the text of master.
 * @returns {string[]}
 */
export function feedProblems({ ciRun, dataRuns, files, now }) {
    const problems = [];

    for (const workflow of DATA_WORKFLOWS) {
        const run = dataRuns[workflow] ?? null;

        if (run === null) {
            problems.push(`${workflow}: no scheduled run`);
        } else if (run.status !== 'completed') {
            // A run that is still running gets its check from the next watch.
        } else if (run.conclusion !== 'success') {
            problems.push(`${workflow}: the last scheduled run (${run.createdAt}) is ${run.conclusion}: ${run.url}`);
        } else if (now - Date.parse(run.createdAt) > MAX_AGE_HOURS * 3_600_000) {
            const last = `(the last: ${run.createdAt})`;

            problems.push(`${workflow}: no scheduled run in the last ${MAX_AGE_HOURS} hours ${last}`);
        }
    }

    if (ciRun === null) {
        problems.push('main.yml: no run on master');
    } else if (ciRun.status === 'completed' && ciRun.conclusion !== 'success') {
        problems.push(`main.yml: the last run on master (${ciRun.createdAt}) is ${ciRun.conclusion}: ${ciRun.url}`);
    }

    for (const { live, master, name, status } of files) {
        if (status === 0) {
            problems.push(`phalcon/${name}: the live address does not answer`);
        } else if (status !== 200) {
            problems.push(`phalcon/${name}: the live address answers ${status}`);
        } else if (live !== master && !deploying(ciRun)) {
            problems.push(`phalcon/${name}: the live file is not the file of master`);
        }
    }

    return problems;
}

/**
 * What the watch does with its issue.
 *
 * @param {object} data
 * @param {string} data.date The date of the check (YYYY-MM-DD).
 * @param {{ number: number }|null} data.openIssue The open issue with ISSUE_TITLE, or null.
 * @param {string[]} data.problems The problems of feedProblems().
 * @param {string} data.runUrl The address of the watch run.
 * @returns {{ action: 'create', body: string, title: string }
 *     | { action: 'comment'|'close', body: string, number: number }
 *     | { action: 'none' }}
 */
export function issueAction({ date, openIssue, problems, runUrl }) {
    const list = problems.map((problem) => `- ${problem}`).join('\n');

    if (problems.length > 0 && openIssue === null) {
        return {
            action: 'create',
            body: `The feed watch (${runUrl}) found these problems on ${date}:\n\n${list}\n\n`
                + 'The watch closes this issue when every check passes again.',
            title: ISSUE_TITLE,
        };
    }

    if (problems.length > 0) {
        return {
            action: 'comment',
            body: `${date}: the feed watch (${runUrl}) still finds these problems:\n\n${list}`,
            number: openIssue.number,
        };
    }

    if (openIssue !== null) {
        return { action: 'close', body: `${date}: every check passes again (${runUrl}).`, number: openIssue.number };
    }

    return { action: 'none' };
}

/**
 * The newest run of a list (the GitHub API `workflow_runs`, newest first) that is not a pull request run, or null.
 * A pull request from a fork branch named master has the head branch master too, so the query branch=master also
 * gives its runs: they are not runs on master.
 *
 * @param {{ event: string }[]} runs
 * @returns {object|null}
 */
export function newestRun(runs) {
    return runs.find((run) => run.event !== 'pull_request') ?? null;
}

/**
 * The open issue of the watch in a list (the GitHub API open issues, which has the pull requests too), or null.
 *
 * @param {{ number: number, pull_request?: object, title: string }[]} issues
 * @returns {{ number: number }|null}
 */
export function watchIssue(issues) {
    const issue = issues.find(({ pull_request: pull, title }) => title === ISSUE_TITLE && pull === undefined);

    return issue === undefined ? null : { number: issue.number };
}

/** While the CI on master runs, a deploy is on its way: a live feed can be older than master for some minutes. */
function deploying(ciRun) {
    return ciRun !== null && ciRun.status !== 'completed';
}
