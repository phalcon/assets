<?php

/**
 * Tests for scripts/github/functions.php. Plain PHP, with no framework: each check
 * prints one line, and the exit code is 1 when a check fails.
 *
 * Usage: php tests/github.php
 */

declare(strict_types=1);

require __DIR__ . '/../scripts/github/functions.php';

$results = [];

$check = static function (string $label, bool $ok) use (&$results): void {
    $results[] = [$label, $ok];
};

$throws = static function (callable $call, string $class): bool {
    try {
        $call();
    } catch (Throwable $e) {
        return $e instanceof $class;
    }

    return false;
};

$release = static fn (string $tag, string $date, bool $draft = false): array => [
    'isDraft'     => $draft,
    'publishedAt' => $date . 'T10:00:00Z',
    'tagName'     => $tag,
];

$contributor = static fn (string $login, int $count, string $type = 'User'): array => [
    'avatar_url'    => 'https://avatars.githubusercontent.com/u/1?v=4',
    'contributions' => $count,
    'html_url'      => 'https://github.com/' . $login,
    'login'         => $login,
    'type'          => $type,
];

// versionFromTag
$check('versionFromTag strips the leading v', '5.22.1' === versionFromTag('v5.22.1'));
$check('versionFromTag keeps a pre-release suffix', '6.0.0RC3' === versionFromTag('v6.0.0RC3'));
$check('versionFromTag accepts a tag with no v', '6.0.0beta11' === versionFromTag('6.0.0beta11'));
$check(
    'versionFromTag rejects tags that are not release versions',
    null === versionFromTag('latest')
    && null === versionFromTag('v5.22')
    && null === versionFromTag('v5.22.1-hotfix')
    && null === versionFromTag('nightly')
);

// isPrerelease
$check(
    'isPrerelease is true for alpha, beta and RC',
    isPrerelease('6.0.0alpha1') && isPrerelease('6.0.0beta11') && isPrerelease('6.0.0RC3')
);
$check('isPrerelease is false for a stable version', !isPrerelease('5.22.1'));

// pickReleases
$picked = pickReleases([
    $release('v6.0.0beta11', '2026-06-01'),
    $release('v6.0.0RC3', '2026-10-01'),
    $release('v6.0.0RC2', '2026-09-22'),
    $release('v6.0.0beta9', '2026-05-01'),
]);
$check('pickReleases orders RC above beta, and RC3 above RC2', '6.0.0RC3' === ($picked['latest']['version'] ?? null));
$check('pickReleases has no stable release when all are pre-releases', null === $picked['stable']);

$picked = pickReleases([$release('v6.0.0RC3', '2026-10-01'), $release('v6.0.0', '2026-11-01')]);
$check(
    'pickReleases puts 6.0.0 above 6.0.0RC3',
    '6.0.0' === ($picked['stable']['version'] ?? null) && '6.0.0' === ($picked['latest']['version'] ?? null)
);

$picked = pickReleases([$release('v6.0.0beta9', '2026-05-01'), $release('v6.0.0beta11', '2026-04-01')]);
$check(
    'pickReleases compares numbers as numbers (beta11 above beta9)',
    '6.0.0beta11' === ($picked['latest']['version'] ?? null)
);

$picked = pickReleases([$release('v5.22.1', '2026-10-01'), $release('v4.1.3', '2026-10-02')]);
$check('the newest version wins, not the newest date', '5.22.1' === ($picked['stable']['version'] ?? null));

$picked = pickReleases([
    $release('v5.23.0', '2026-10-05', true),
    $release('latest', '2026-10-04'),
    $release('v5.22.1', '2026-10-01'),
]);
$check('pickReleases skips drafts and tags that are not versions', '5.22.1' === ($picked['latest']['version'] ?? null));
$check(
    'pickReleases writes tag, version and date',
    ['tag' => 'v5.22.1', 'version' => '5.22.1', 'date' => '2026-10-01'] === $picked['stable']
);
$check('pickReleases with no releases gives null and null', ['stable' => null, 'latest' => null] === pickReleases([]));

// isBot and mergeContributors
$check('isBot is true for type Bot', isBot($contributor('renovate', 1, 'Bot')));
$check('isBot is true for a login that ends in [bot]', isBot($contributor('dependabot[bot]', 1)));
$check('isBot is false for a person', !isBot($contributor('niden', 1)));

$merged = mergeContributors(
    [
        [$contributor('niden', 10), $contributor('dependabot[bot]', 99), $contributor('Jeckerson', 5)],
        [$contributor('jeckerson', 7), $contributor('sjinks', 12), $contributor('github-actions', 50, 'Bot')],
    ],
    10
);
$check(
    'mergeContributors adds up a login across repositories, ignoring case',
    12 === $merged[0]['contributions'] && 'Jeckerson' === $merged[0]['login']
);
$check('mergeContributors leaves out bots', 3 === count($merged));
$check(
    'mergeContributors sorts by contributions, then by login',
    ['Jeckerson', 'sjinks', 'niden'] === array_column($merged, 'login')
);
$check(
    'mergeContributors writes login, url, avatar and contributions, in that order',
    '{"login":"Jeckerson","url":"https://github.com/Jeckerson",'
    . '"avatar":"https://avatars.githubusercontent.com/u/1?v=4","contributions":12}'
    === json_encode($merged[0], JSON_UNESCAPED_SLASHES)
);
$check(
    'mergeContributors keeps at most the limit',
    2 === count(mergeContributors([[$contributor('a', 3), $contributor('b', 2), $contributor('c', 1)]], 2))
);

$tie = mergeContributors([[$contributor('zed', 5), $contributor('Amy', 5)]], 10);
$check('a tie in contributions sorts by login, ignoring case', ['Amy', 'zed'] === array_column($tie, 'login'));

// splitId and repositoriesQuery
$check('splitId splits owner and name', ['zephir-lang', 'zephir'] === splitId('zephir-lang/zephir'));
$check('splitId accepts a dot in the name', ['phalcon', 'phalcon.io'] === splitId('phalcon/phalcon.io'));
$check(
    'splitId rejects an id with no owner',
    $throws(static fn () => splitId('cphalcon'), InvalidArgumentException::class)
);
$check('splitId rejects quotes', $throws(static fn () => splitId('phalcon/a"b'), InvalidArgumentException::class));

$query = repositoriesQuery(['phalcon/cphalcon', 'zephir-lang/zephir']);
$check(
    'repositoriesQuery has one alias per repository',
    str_contains($query, 'r0: repository(owner: "phalcon", name: "cphalcon")')
    && str_contains($query, 'r1: repository(owner: "zephir-lang", name: "zephir")')
);
$check(
    'repositoriesQuery asks for stars and releases',
    str_contains($query, 'stargazerCount') && str_contains($query, 'releases(first: 50')
);

// repositoryRecord
$record = repositoryRecord('phalcon/cphalcon', [
    'url'            => 'https://github.com/phalcon/cphalcon',
    'stargazerCount' => 10812,
    'releases'       => ['nodes' => [$release('v5.22.1', '2026-10-01')]],
]);
$check(
    'repositoryRecord writes id, url, stars, stable and latest',
    '["id","url","stars","stable","latest"]' === json_encode(array_keys($record))
    && 10812 === $record['stars']
    && '5.22.1' === ($record['stable']['version'] ?? null)
);
$check(
    'repositoryRecord fails when GitHub returned nothing',
    $throws(static fn () => repositoryRecord('phalcon/x', null), RuntimeException::class)
);

$failed = 0;

foreach ($results as [$label, $ok]) {
    echo ($ok ? 'ok    ' : 'FAIL  ') . $label . PHP_EOL;
    $failed += $ok ? 0 : 1;
}

echo PHP_EOL . (count($results) - $failed) . ' of ' . count($results) . ' checks passed' . PHP_EOL;

exit(0 === $failed ? 0 : 1);
