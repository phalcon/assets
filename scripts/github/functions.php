<?php

/**
 * Pure functions for generateGithub.php. They do no network calls and read
 * no files, so tests/github.php calls them directly.
 */

declare(strict_types=1);

/** A release version: 5.22.1, 6.0.0RC3, 6.0.0beta11, with or without a leading "v". */
const VERSION_PATTERN = '/^v?\d+\.\d+\.\d+(?:(?:alpha|beta|RC)\d+)?$/i';

/**
 * The value when it is an array, or an empty array.
 *
 * @return array<mixed>
 */
function arrayOf(mixed $value): array
{
    return is_array($value) ? $value : [];
}

/**
 * The value as a whole number, as an (int) cast gives it for a number, a
 * numeric string or a boolean; 0 for anything else.
 */
function intOf(mixed $value): int
{
    return is_int($value) || is_float($value) || is_bool($value) || is_numeric($value) ? (int) $value : 0;
}

/**
 * True for a bot account: type Bot, or a login that ends in "[bot]".
 *
 * @param array<mixed> $contributor
 */
function isBot(array $contributor): bool
{
    return 'Bot' === ($contributor['type'] ?? '')
        || str_ends_with(stringOf($contributor['login'] ?? ''), '[bot]');
}

/** True when the version has an alpha, beta or RC suffix. */
function isPrerelease(string $version): bool
{
    return 1 === preg_match('/(?:alpha|beta|RC)\d+$/i', $version);
}

/**
 * Adds up the contributions of each login across repositories, leaves out
 * bots, and returns the first $limit: by contributions (high to low), then by
 * login (A to Z). Logins match without regard to letter case; the first
 * spelling seen is kept.
 *
 * @param array<mixed> $perRepository REST contributor lists, one per repository
 *
 * @return list<array{login: string, url: string, avatar: string, contributions: int}>
 */
function mergeContributors(array $perRepository, int $limit): array
{
    $byLogin = [];

    foreach ($perRepository as $contributors) {
        foreach (arrayOf($contributors) as $contributor) {
            $contributor = arrayOf($contributor);
            $login       = stringOf($contributor['login'] ?? '');

            if ('' === $login || isBot($contributor)) {
                continue;
            }

            $key = strtolower($login);

            $byLogin[$key] ??= [
                'login'         => $login,
                'url'           => stringOf($contributor['html_url'] ?? ''),
                'avatar'        => stringOf($contributor['avatar_url'] ?? ''),
                'contributions' => 0,
            ];

            $byLogin[$key]['contributions'] += intOf($contributor['contributions'] ?? 0);
        }
    }

    $list = array_values($byLogin);

    usort(
        $list,
        static fn (array $a, array $b): int => [$b['contributions'], strtolower($a['login'])]
            <=> [$a['contributions'], strtolower($b['login'])],
    );

    return array_slice($list, 0, $limit);
}

/**
 * The newest stable release and the newest release of any kind. The order is
 * by version (version_compare: alpha < beta < RC < no suffix), not by date,
 * because an older line can publish a patch after a newer line.
 *
 * @param array<mixed> $releases GraphQL release nodes: tagName, isDraft, publishedAt
 *
 * @return array{stable: array<string, string|null>|null, latest: array<string, string|null>|null}
 */
function pickReleases(array $releases): array
{
    $stable = null;
    $latest = null;

    foreach ($releases as $release) {
        if (!is_array($release) || true === ($release['isDraft'] ?? false)) {
            continue;
        }

        $tag     = stringOf($release['tagName'] ?? '');
        $version = versionFromTag($tag);

        if (null === $version) {
            continue;
        }

        $published = stringOf($release['publishedAt'] ?? '');
        $entry     = [
            'tag'     => $tag,
            'version' => $version,
            'date'    => '' === $published ? null : substr($published, 0, 10),
        ];

        if (null === $latest || version_compare($version, (string) $latest['version'], '>')) {
            $latest = $entry;
        }

        if (
            !isPrerelease($version)
            && (null === $stable || version_compare($version, (string) $stable['version'], '>'))
        ) {
            $stable = $entry;
        }
    }

    return ['stable' => $stable, 'latest' => $latest];
}

/**
 * One GraphQL query for all repositories, with the alias r<index> for each,
 * so the stars and releases of every repository come back in one call.
 *
 * @param list<string> $ids
 */
function repositoriesQuery(array $ids): string
{
    $fields = 'url stargazerCount '
        . 'releases(first: 50, orderBy: {field: CREATED_AT, direction: DESC}) '
        . '{ nodes { tagName isDraft publishedAt } }';
    $parts  = [];

    foreach ($ids as $index => $id) {
        [$owner, $name] = splitId($id);

        $parts[] = sprintf('r%d: repository(owner: "%s", name: "%s") { %s }', $index, $owner, $name, $fields);
    }

    return '{ ' . implode(' ', $parts) . ' }';
}

/**
 * The output record of one repository.
 *
 * @param array<mixed>|null $node The GraphQL node of the repository, or null when GitHub returned none
 *
 * @return array{
 *     id: string,
 *     url: string,
 *     stars: int,
 *     stable: array<string, string|null>|null,
 *     latest: array<string, string|null>|null
 * }
 */
function repositoryRecord(string $id, ?array $node): array
{
    if (null === $node) {
        throw new RuntimeException(sprintf('GitHub returned no data for %s', $id));
    }

    $releases = pickReleases(arrayOf(arrayOf($node['releases'] ?? null)['nodes'] ?? null));

    return [
        'id'     => $id,
        'url'    => stringOf($node['url'] ?? ''),
        'stars'  => intOf($node['stargazerCount'] ?? 0),
        'stable' => $releases['stable'],
        'latest' => $releases['latest'],
    ];
}

/**
 * The owner and name of an "owner/name" repository id. Only letters, digits,
 * "-", "." and "_" are allowed, so the parts can go into a query unescaped.
 *
 * @return array{0: string, 1: string}
 */
function splitId(string $id): array
{
    if (1 !== preg_match('#^([A-Za-z0-9-]+)/([A-Za-z0-9._-]+)$#', $id, $match)) {
        throw new InvalidArgumentException(sprintf('"%s" is not an owner/name repository id', $id));
    }

    return [$match[1], $match[2]];
}

/**
 * The value as text, as a (string) cast gives it for a scalar; an empty
 * string for anything else.
 */
function stringOf(mixed $value): string
{
    return is_scalar($value) ? (string) $value : '';
}

/** The version of a release tag ("v6.0.0RC3" → "6.0.0RC3"), or null when the tag is not a release version. */
function versionFromTag(string $tag): ?string
{
    if (1 !== preg_match(VERSION_PATTERN, $tag)) {
        return null;
    }

    return ltrim($tag, 'vV');
}
