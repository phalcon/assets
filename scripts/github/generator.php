<?php

/**
 * Builds phalcon/repositories.json (stars and releases) and
 * phalcon/contributors.json for the repositories in scripts/github/repositories.json.
 *
 * Only public data is read and written. The files carry no timestamp and are
 * sorted deterministically, so unchanged data produces identical files and
 * the workflow has nothing to commit. Both files are written, or neither:
 * when one call fails, main() returns 1 before it writes anything.
 *
 * Sources:
 *   - GraphQL, one query for all repositories: stargazerCount and the last
 *     50 releases of each.
 *   - REST /repos/{id}/contributors, 100 per page, every page.
 *
 * Needs GITHUB_TOKEN. The default token of a workflow is enough: the data is
 * public. Entry point: generateGithub.php.
 */

declare(strict_types=1);

const API                 = 'https://api.github.com';
const CONTRIBUTORS_LIMIT  = 100;
const CONTRIBUTORS_OUTPUT = __DIR__ . '/../../public/phalcon/contributors.json';
const PAGE_SIZE           = 100;
// In scripts/, out of the published site (public/), as scripts/sponsors/.
const REPOSITORIES_FILE   = __DIR__ . '/repositories.json';
const REPOSITORIES_OUTPUT = __DIR__ . '/../../public/phalcon/repositories.json';

/**
 * @param list<string> $ids
 *
 * @return list<array{login: string, url: string, avatar: string, contributions: int}>
 */
function contributors(array $ids, string $token): array
{
    $perRepository = [];

    foreach ($ids as $id) {
        $all  = [];
        $page = 1;

        do {
            $url  = sprintf('%s/repos/%s/contributors?per_page=%d&page=%d', API, $id, PAGE_SIZE, $page);
            $list = request($url, $token);
            $all  = array_merge($all, $list);
            $page++;
        } while (PAGE_SIZE === count($list));

        if ([] === $all) {
            throw new RuntimeException(
                sprintf('GitHub returned no contributors for %s; refusing to write a thinner list', $id)
            );
        }

        $perRepository[] = $all;
    }

    return mergeContributors($perRepository, CONTRIBUTORS_LIMIT);
}

/**
 * @param array<string, mixed> $data
 */
function encode(array $data): string
{
    $flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR;

    return json_encode($data, $flags) . PHP_EOL;
}

function main(): int
{
    $token = getenv('GITHUB_TOKEN');

    if (false === $token || '' === $token) {
        fwrite(STDERR, 'error: GITHUB_TOKEN is not set' . PHP_EOL);

        return 1;
    }

    try {
        $ids          = repositoryIds();
        $repositories = repositories($ids, $token);
        $contributors = contributors($ids, $token);
    } catch (RuntimeException | InvalidArgumentException $e) {
        fwrite(STDERR, 'error: ' . $e->getMessage() . PHP_EOL);

        return 1;
    }

    file_put_contents(REPOSITORIES_OUTPUT, encode(['repositories' => $repositories]));
    file_put_contents(CONTRIBUTORS_OUTPUT, encode(['contributors' => $contributors]));

    fwrite(STDOUT, sprintf(
        'wrote %d repositories and %d contributors%s',
        count($repositories),
        count($contributors),
        PHP_EOL,
    ));

    return 0;
}

/**
 * @param list<string> $ids
 *
 * @return list<array<string, mixed>>
 */
function repositories(array $ids, string $token): array
{
    $body    = json_encode(['query' => repositoriesQuery($ids)], JSON_THROW_ON_ERROR);
    $data    = request(API . '/graphql', $token, $body);
    $records = [];

    foreach ($ids as $index => $id) {
        $node      = arrayOf($data['data'] ?? null)['r' . $index] ?? null;
        $records[] = repositoryRecord($id, is_array($node) ? $node : null);
    }

    return $records;
}

/**
 * @return list<string>
 */
function repositoryIds(): array
{
    $decoded = json_decode((string) file_get_contents(REPOSITORIES_FILE), true);

    if (!is_array($decoded) || [] === $decoded || !array_is_list($decoded)) {
        throw new RuntimeException(sprintf('%s must be a non-empty JSON list', REPOSITORIES_FILE));
    }

    $ids = [];

    foreach ($decoded as $id) {
        if (!is_string($id)) {
            throw new RuntimeException(sprintf('%s must list repository ids as strings', REPOSITORIES_FILE));
        }

        $ids[] = $id;
    }

    return $ids;
}

/**
 * A GET (no body) or POST (body) to the GitHub API. Returns the decoded JSON.
 *
 * @return array<mixed>
 */
function request(string $url, string $token, ?string $body = null): array
{
    $handle = curl_init($url);

    if (false === $handle) {
        throw new RuntimeException(sprintf('%s: cannot open a connection', $url));
    }

    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_USERAGENT      => 'phalcon-assets-github',
        CURLOPT_HTTPHEADER     => [
            'Accept: application/vnd.github+json',
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
            'X-GitHub-Api-Version: 2022-11-28',
        ],
    ]);

    if (null !== $body) {
        curl_setopt($handle, CURLOPT_POST, true);
        curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
    }

    // No curl_close(): the handle is an object since PHP 8.0 and frees itself
    // when it goes out of scope. Calling it is deprecated as of PHP 8.5.
    $response = curl_exec($handle);
    $status   = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    $error    = curl_error($handle);

    if (!is_string($response)) {
        throw new RuntimeException(sprintf('%s: %s', $url, $error));
    }

    // 204 is an empty repository: no contributors.
    if (204 === $status) {
        return [];
    }

    if (200 !== $status) {
        throw new RuntimeException(sprintf('%s returned HTTP %d', $url, $status));
    }

    $decoded = json_decode($response, true);

    if (!is_array($decoded)) {
        throw new RuntimeException(sprintf('%s returned a body that is not JSON', $url));
    }

    $message = arrayOf(arrayOf($decoded['errors'] ?? null)[0] ?? null)['message'] ?? null;

    if (null !== $message) {
        throw new RuntimeException(sprintf('%s: %s', $url, stringOf($message)));
    }

    return $decoded;
}
