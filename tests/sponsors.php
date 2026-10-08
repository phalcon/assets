<?php

/**
 * Tests for scripts/sponsors/functions.php. Plain PHP, with no framework: each
 * check prints one line, and the exit code is 1 when a check fails.
 *
 * Usage: php tests/sponsors.php
 */

declare(strict_types=1);

require __DIR__ . '/../scripts/sponsors/functions.php';

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

$gitHubNode = static fn (array $entity, string $privacy = 'PUBLIC', ?int $dollars = 25): array => [
    'privacyLevel'  => $privacy,
    'sponsorEntity' => $entity,
    'tier'          => null === $dollars ? null : ['monthlyPriceInDollars' => $dollars],
];

$account = static fn (array $change = []): array => array_merge([
    'imageUrl'    => 'https://images.test/acme.png',
    'isIncognito' => false,
    'name'        => 'ACME',
    'slug'        => 'Acme',
    'website'     => 'https://acme.test',
], $change);

$ocNode = static fn (array $account, string $frequency = 'MONTHLY', int $cents = 2500): array => [
    'amount'      => ['valueInCents' => $cents],
    'frequency'   => $frequency,
    'fromAccount' => $account,
];

$record = static fn (string $id, int $dollars, string $source, string $kind = 'financial'): array => [
    'id'         => $id,
    'kind'       => $kind,
    'logo'       => null,
    'monthlyUsd' => $dollars,
    'name'       => strtoupper($id),
    'source'     => [$source],
    'url'        => 'https://' . $id . '.test',
];

// monthlyDollars
$check('monthlyDollars keeps a monthly amount in whole dollars', 10 === monthlyDollars(1099, 'MONTHLY'));
$check('monthlyDollars divides a yearly amount by 12', 10 === monthlyDollars(12000, 'YEARLY'));
$check('monthlyDollars gives 0 for less than a dollar', 0 === monthlyDollars(99, 'MONTHLY'));

// groupFor
$check('groupFor gives partner to an in-kind record', 'partner' === groupFor($record('p', 500, 'manual', 'in-kind')));
$check('groupFor gives sponsor from 100 dollars a month', 'sponsor' === groupFor($record('a', 100, 'github')));
$check('groupFor gives supporter from 10 to 99 dollars a month', 'supporter' === groupFor($record('a', 99, 'github')));
$check('groupFor gives supporter at 10 dollars a month', 'supporter' === groupFor($record('a', 10, 'github')));
$check('groupFor gives backer below 10 dollars a month', 'backer' === groupFor($record('a', 9, 'github')));

// gitHubRecord
$entity = [
    'avatarUrl' => 'https://avatars.test/1',
    'login'     => 'NiDen',
    'name'      => 'Nikos',
    'url'       => 'https://github.com/niden',
];

$check(
    'gitHubRecord maps a public sponsorship',
    [
        'id'         => 'gh:niden',
        'name'       => 'Nikos',
        'url'        => 'https://github.com/niden',
        'logo'       => 'https://avatars.test/1',
        'kind'       => 'financial',
        'source'     => ['github'],
        'monthlyUsd' => 25,
    ] === gitHubRecord($gitHubNode($entity))
);
$check(
    'gitHubRecord takes the login when the name is empty, and 0 dollars with no tier',
    ['gh:niden', 'NiDen', null, 0] === array_values(array_intersect_key(
        gitHubRecord(
            $gitHubNode(['login' => 'NiDen', 'name' => '', 'url' => 'https://github.com/niden'], 'PUBLIC', null)
        )
            ?? [],
        ['id' => 0, 'name' => 0, 'logo' => 0, 'monthlyUsd' => 0]
    ))
);
$check('gitHubRecord skips a private sponsorship', null === gitHubRecord($gitHubNode($entity, 'PRIVATE')));
$check(
    'gitHubRecord skips a sponsorship with no privacy level',
    null === gitHubRecord(['sponsorEntity' => $entity, 'tier' => ['monthlyPriceInDollars' => 5]])
);
$check('gitHubRecord skips a sponsor that the token cannot read', null === gitHubRecord(['privacyLevel' => 'PUBLIC']));
$check(
    'gitHubRecord rejects a sponsor with no login',
    $throws(static fn () => gitHubRecord($gitHubNode(['name' => 'X'])), RuntimeException::class)
);

// openCollectiveRecord
$check(
    'openCollectiveRecord maps an active order, a yearly amount in dollars a month',
    [
        'id'         => 'oc:acme',
        'name'       => 'ACME',
        'url'        => 'https://acme.test',
        'logo'       => 'https://images.test/acme.png',
        'kind'       => 'financial',
        'source'     => ['opencollective'],
        'monthlyUsd' => 100,
    ] === openCollectiveRecord($ocNode($account(), 'YEARLY', 120000))
);
$check(
    'openCollectiveRecord takes the collective page with no website, and the slug with no name',
    ['oc:acme', 'acme', 'https://opencollective.com/acme'] === array_values(array_intersect_key(
        openCollectiveRecord($ocNode($account(['name' => '', 'website' => null]))) ?? [],
        ['id' => 0, 'name' => 0, 'url' => 0]
    ))
);
$check(
    'openCollectiveRecord skips an incognito account',
    null === openCollectiveRecord($ocNode($account(['isIncognito' => true])))
);
$check(
    'openCollectiveRecord skips the GitHub Sponsors payout',
    null === openCollectiveRecord($ocNode($account(['slug' => 'github-sponsors'])))
);
$check(
    'openCollectiveRecord skips an account with no slug',
    null === openCollectiveRecord($ocNode($account(['slug' => ''])))
);
$check(
    'openCollectiveRecord skips an order with no account',
    null === openCollectiveRecord(['frequency' => 'MONTHLY'])
);

// partnerRecord
$check(
    'partnerRecord maps a partner of partners.json',
    [
        'id'         => 'manual:cloudflare',
        'name'       => 'Cloudflare',
        'url'        => 'https://www.cloudflare.com/',
        'logo'       => 'https://logos.test/cloudflare.svg',
        'kind'       => 'in-kind',
        'source'     => ['manual'],
        'monthlyUsd' => 0,
    ] === partnerRecord([
        'id'   => 'cloudflare',
        'logo' => 'https://logos.test/cloudflare.svg',
        'name' => 'Cloudflare',
        'url'  => 'https://www.cloudflare.com/',
    ])
);
$check(
    'partnerRecord rejects a partner with no id',
    $throws(static fn () => partnerRecord(['name' => 'X', 'url' => 'https://x.test']), RuntimeException::class)
);

// mergeAliases
$merged = mergeAliases(
    [$record('gh:a', 10, 'github'), $record('oc:a', 5, 'opencollective'), $record('gh:b', 3, 'github')],
    ['gh:a' => ['mergeInto' => 'oc:a'], 'gh:b' => ['mergeInto' => 'oc:missing']]
);

$check('mergeAliases keeps one record for a person on both platforms', ['oc:a', 'gh:b'] === array_keys($merged));
$check('mergeAliases adds the monthly amounts', 15 === $merged['oc:a']['monthlyUsd']);
$check('mergeAliases joins the sources', ['opencollective', 'github'] === $merged['oc:a']['source']);
$check('mergeAliases keeps a record whose target is missing', 3 === $merged['gh:b']['monthlyUsd']);

// applyOverrides
$roster = applyOverrides(
    ['gh:a' => $record('gh:a', 10, 'github'), 'oc:c' => $record('oc:c', 200, 'opencollective')],
    ['gh:a' => ['group' => 'sponsor', 'logo' => 'https://logo.test', 'name' => 'Alpha', 'url' => 'https://alpha.test']]
);

$check(
    'applyOverrides gives the fields of the roster, in order, and no amount',
    '["id","name","url","logo","group","kind","source"]' === json_encode(array_keys($roster[0]))
);
$check(
    'applyOverrides takes the name, the address, the logo and the group of an override',
    ['Alpha', 'https://alpha.test', 'https://logo.test', 'sponsor']
        === [$roster[0]['name'], $roster[0]['url'], $roster[0]['logo'], $roster[0]['group']]
);
$check('applyOverrides gives the group of the amount when no override names one', 'sponsor' === $roster[1]['group']);

// nodesAt
$check(
    'nodesAt gives the nodes at a path, without the ones that are not objects',
    [['x' => 1], ['y' => 2]]
        === nodesAt(['a' => ['b' => ['nodes' => [['x' => 1], 'bad', ['y' => 2]]]]], 'a', 'b', 'nodes')
);
$check('nodesAt gives no nodes for a missing path', [] === nodesAt(['a' => null], 'a', 'b', 'nodes'));

// sortedRoster and rosterJson
$sorted = sortedRoster([
    ['group' => 'supporter', 'name' => 'beta'],
    ['group' => 'backer', 'name' => 'Zed'],
    ['group' => 'backer', 'name' => 'alpha'],
]);

$check(
    'sortedRoster orders by group, then by name with no regard to case',
    ['alpha', 'Zed', 'beta'] === array_column($sorted, 'name')
);

$committed = (string) file_get_contents(__DIR__ . '/../public/phalcon/sponsors.json');
$decoded   = json_decode($committed, true);

$check(
    'rosterJson writes the committed sponsors.json again byte for byte',
    is_array($decoded) && is_array($decoded['sponsors'] ?? null) && $committed === rosterJson($decoded['sponsors'])
);

$failed = 0;

foreach ($results as [$label, $ok]) {
    echo ($ok ? 'ok    ' : 'FAIL  ') . $label . PHP_EOL;
    $failed += $ok ? 0 : 1;
}

echo PHP_EOL . (count($results) - $failed) . ' of ' . count($results) . ' checks passed' . PHP_EOL;

exit(0 === $failed ? 0 : 1);
