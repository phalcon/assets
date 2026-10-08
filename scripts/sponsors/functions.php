<?php

/**
 * Pure functions for generateSponsors.php. They do no network calls and read
 * no files, so tests/sponsors.php calls them directly.
 *
 * A record (SponsorRecord) is a sponsor as the sources give it, with its
 * monthly amount. A roster entry (RosterEntry) is what sponsors.json holds:
 * no amount reaches the output, the amount decides the group and nothing
 * else.
 */

declare(strict_types=1);

/** Monthly dollars, high to low. The first match wins. */
const GROUP_THRESHOLDS = [
    100 => 'sponsor',
    10  => 'supporter',
    0   => 'backer',
];

/** Open Collective lists the GitHub Sponsors payout as a member of itself. */
const OC_EXCLUDED_SLUGS = ['github-sponsors'];

/**
 * The roster entries of the records: the fields of the output, in their
 * order, with no amount. An override wins over the APIs for the name, the
 * address, the logo and the group.
 *
 * @param array<array-key, SponsorRecord> $records
 * @param array<mixed>                    $overrides overrides.json: an object for each record id
 *
 * @return list<RosterEntry>
 */
function applyOverrides(array $records, array $overrides): array
{
    $result = [];

    foreach ($records as $id => $record) {
        $override = arrayAt($overrides, (string) $id) ?? [];

        $result[] = [
            'id'     => $record['id'],
            'name'   => stringAt($override, 'name') ?? $record['name'],
            'url'    => stringAt($override, 'url') ?? $record['url'],
            'logo'   => stringAt($override, 'logo') ?? $record['logo'],
            'group'  => stringAt($override, 'group') ?? groupFor($record),
            'kind'   => $record['kind'],
            'source' => $record['source'],
        ];
    }

    return $result;
}

/**
 * The value at $key of $data when it is an array, or null.
 *
 * @param array<mixed> $data
 *
 * @return array<mixed>|null
 */
function arrayAt(array $data, string $key): ?array
{
    $value = $data[$key] ?? null;

    return is_array($value) ? $value : null;
}

/**
 * The record of a GitHub sponsorship node, or null when the sponsorship is
 * private (the sponsor asked not to be listed) or the token cannot read the
 * sponsor (an entity of null).
 *
 * @param array<mixed> $node
 *
 * @return SponsorRecord|null
 */
function gitHubRecord(array $node): ?array
{
    $entity = arrayAt($node, 'sponsorEntity');

    if (null === $entity || 'PRIVATE' === (stringAt($node, 'privacyLevel') ?? 'PRIVATE')) {
        return null;
    }

    $login = stringAt($entity, 'login');

    if (null === $login) {
        throw new RuntimeException('a GitHub sponsor has no login');
    }

    return [
        'id'         => 'gh:' . strtolower($login),
        'name'       => (stringAt($entity, 'name') ?? '') ?: $login,
        'url'        => stringAt($entity, 'url'),
        'logo'       => stringAt($entity, 'avatarUrl'),
        'kind'       => 'financial',
        'source'     => ['github'],
        'monthlyUsd' => intAt(arrayAt($node, 'tier') ?? [], 'monthlyPriceInDollars'),
    ];
}

/**
 * The group of a record: partner for an in-kind record, else the group of its
 * monthly amount.
 *
 * @param SponsorRecord $record
 */
function groupFor(array $record): string
{
    if ('in-kind' === $record['kind']) {
        return 'partner';
    }

    foreach (GROUP_THRESHOLDS as $floor => $group) {
        if ($record['monthlyUsd'] >= $floor) {
            return $group;
        }
    }

    return 'backer';
}

/**
 * The value at $key of $data as a whole number: an integer, or a number cut
 * to an integer; 0 for anything else.
 *
 * @param array<mixed> $data
 */
function intAt(array $data, string $key): int
{
    $value = $data[$key] ?? null;

    if (is_int($value)) {
        return $value;
    }

    return is_float($value) || is_numeric($value) ? (int) $value : 0;
}

/**
 * Records are merged into one another when an override names a target, so a
 * person sponsoring on both platforms appears once. The surviving record
 * gains the other's source and their monthly values are added: someone
 * giving on both platforms is one supporter at the combined level.
 *
 * @param list<SponsorRecord> $records
 * @param array<mixed>        $overrides overrides.json: an object for each record id
 *
 * @return array<array-key, SponsorRecord> the records by id
 */
function mergeAliases(array $records, array $overrides): array
{
    $byId = [];

    foreach ($records as $record) {
        $byId[$record['id']] = $record;
    }

    foreach ($overrides as $id => $override) {
        $target = is_array($override) ? stringAt($override, 'mergeInto') : null;

        if (null === $target || !isset($byId[$id], $byId[$target])) {
            continue;
        }

        $byId[$target]['monthlyUsd'] += $byId[$id]['monthlyUsd'];
        $byId[$target]['source']     = array_values(
            array_unique(array_merge($byId[$target]['source'], $byId[$id]['source'])),
        );

        unset($byId[$id]);
    }

    return $byId;
}

/** A recurring amount in whole dollars a month: a yearly amount is divided by 12. */
function monthlyDollars(int $valueInCents, string $frequency): int
{
    $dollars = intdiv($valueInCents, 100);

    return 'YEARLY' === $frequency ? intdiv($dollars, 12) : $dollars;
}

/**
 * The objects of the list at the path in a GraphQL answer: an empty list when
 * the path is missing, and no item that is not an object.
 *
 * @param array<mixed> $data
 *
 * @return list<array<mixed>>
 */
function nodesAt(array $data, string ...$path): array
{
    $value = $data;

    foreach ($path as $key) {
        $value = is_array($value) ? ($value[$key] ?? null) : null;
    }

    $nodes = [];

    foreach (is_array($value) ? $value : [] as $item) {
        if (is_array($item)) {
            $nodes[] = $item;
        }
    }

    return $nodes;
}

/**
 * The record of an active Open Collective order, or null when the account is
 * incognito, has no slug, or is the GitHub Sponsors payout.
 *
 * @param array<mixed> $node
 *
 * @return SponsorRecord|null
 */
function openCollectiveRecord(array $node): ?array
{
    $account = arrayAt($node, 'fromAccount');

    if (null === $account || true === ($account['isIncognito'] ?? false)) {
        return null;
    }

    $slug = strtolower(stringAt($account, 'slug') ?? '');

    if ('' === $slug || in_array($slug, OC_EXCLUDED_SLUGS, true)) {
        return null;
    }

    return [
        'id'         => 'oc:' . $slug,
        'name'       => (stringAt($account, 'name') ?? '') ?: $slug,
        'url'        => stringAt($account, 'website') ?? 'https://opencollective.com/' . $slug,
        'logo'       => stringAt($account, 'imageUrl'),
        'kind'       => 'financial',
        'source'     => ['opencollective'],
        'monthlyUsd' => monthlyDollars(
            intAt(arrayAt($node, 'amount') ?? [], 'valueInCents'),
            stringAt($node, 'frequency') ?? 'MONTHLY',
        ),
    ];
}

/**
 * The record of a partner of partners.json (in kind: service tiers that no
 * API shows).
 *
 * @param array<mixed> $partner
 *
 * @return SponsorRecord
 */
function partnerRecord(array $partner): array
{
    $id = stringAt($partner, 'id');

    if (null === $id) {
        throw new RuntimeException('a partner of partners.json has no id');
    }

    return [
        'id'         => 'manual:' . $id,
        'name'       => stringAt($partner, 'name') ?? '',
        'url'        => stringAt($partner, 'url'),
        'logo'       => stringAt($partner, 'logo'),
        'kind'       => 'in-kind',
        'source'     => ['manual'],
        'monthlyUsd' => 0,
    ];
}

/**
 * The text of sponsors.json: the roster under "sponsors", with no timestamp,
 * so an unchanged roster gives the same file and nothing to commit.
 *
 * @param array<mixed> $roster
 */
function rosterJson(array $roster): string
{
    $json = json_encode(
        ['sponsors' => array_values($roster)],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
    );

    if (false === $json) {
        throw new RuntimeException('the roster cannot be written as JSON');
    }

    return $json . PHP_EOL;
}

/**
 * The roster in a fixed order: by group, then by name with no regard to case.
 *
 * @template T of array{group: string, name: string}
 *
 * @param list<T> $roster
 *
 * @return list<T>
 */
function sortedRoster(array $roster): array
{
    usort(
        $roster,
        static fn (array $a, array $b): int => [$a['group'], strtolower($a['name'])]
            <=> [$b['group'], strtolower($b['name'])],
    );

    return $roster;
}

/**
 * The value at $key of $data when it is a string, or null.
 *
 * @param array<mixed> $data
 */
function stringAt(array $data, string $key): ?string
{
    $value = $data[$key] ?? null;

    return is_string($value) ? $value : null;
}
