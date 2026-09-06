<?php

declare(strict_types=1);

/**
 * Builds phalcon/sponsors.json from the two funding platforms plus the
 * hand-kept partner list.
 *
 * Only public data is written. No amount reaches the output: money decides
 * the group and nothing else. The file carries no timestamp and is sorted
 * deterministically, so an unchanged roster produces an identical file and
 * the workflow has nothing to commit.
 *
 * Sources:
 *   - Open Collective, public GraphQL, no token. Recurring orders with
 *     status ACTIVE, which is who pays today. The members list is not used:
 *     it keeps everyone who ever gave, with the tier they had at the time.
 *   - GitHub Sponsors, GraphQL, needs SPONSORS_TOKEN (read:org + read:user).
 *     read:user is what makes privacyLevel readable; without it a private
 *     sponsor cannot be told from a public one.
 *   - _sponsors/partners.json, the in-kind list. Cloudflare and the like give
 *     us service tiers, never appear in either API, and are most of the block.
 *
 * Usage: php generateSponsors.php
 */

const GITHUB_API      = 'https://api.github.com/graphql';
const OPENCOLLECTIVE  = 'https://api.opencollective.com/graphql/v2';
const OUTPUT          = __DIR__ . '/phalcon/sponsors.json';
// Underscore-prefixed so Jekyll leaves it out of the published site: these
// are inputs to the generator, not artifacts for the sites to fetch.
const OVERRIDES_FILE  = __DIR__ . '/_sponsors/overrides.json';
const PARTNERS_FILE   = __DIR__ . '/_sponsors/partners.json';

/** Open Collective lists the GitHub Sponsors payout as a member of itself. */
const OC_EXCLUDED_SLUGS = ['github-sponsors'];

/** Monthly dollars, high to low. The first match wins. */
const GROUP_THRESHOLDS = [
    100 => 'sponsor',
    10  => 'supporter',
    0   => 'backer',
];

exit(main());

function main(): int
{
    try {
        $records = array_merge(
            openCollectiveRecords(),
            gitHubRecords(),
            partnerRecords(),
        );
    } catch (RuntimeException $e) {
        fwrite(STDERR, 'error: ' . $e->getMessage() . PHP_EOL);

        return 1;
    }

    $records = applyOverrides(mergeAliases($records));

    usort(
        $records,
        static fn (array $a, array $b): int => [$a['group'], strtolower($a['name'])]
            <=> [$b['group'], strtolower($b['name'])],
    );

    $json = json_encode(
        ['sponsors' => array_values($records)],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
    );

    file_put_contents(OUTPUT, $json . PHP_EOL);
    fwrite(STDOUT, sprintf('wrote %d sponsors to %s%s', count($records), OUTPUT, PHP_EOL));

    return 0;
}

/**
 * Records are merged into one another when an override names a target, so a
 * person sponsoring on both platforms appears once. The surviving record
 * gains the other's source and their monthly values are added: someone
 * giving on both platforms is one supporter at the combined level.
 */
function mergeAliases(array $records): array
{
    $byId      = [];
    $overrides = readJsonFile(OVERRIDES_FILE);

    foreach ($records as $record) {
        $byId[$record['id']] = $record;
    }

    foreach ($overrides as $id => $override) {
        $target = $override['mergeInto'] ?? null;

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

/** Overrides win over anything the APIs said. Amounts stay internal. */
function applyOverrides(array $records): array
{
    $overrides = readJsonFile(OVERRIDES_FILE);
    $result    = [];

    foreach ($records as $id => $record) {
        foreach (['name', 'url', 'logo'] as $field) {
            if (isset($overrides[$id][$field])) {
                $record[$field] = $overrides[$id][$field];
            }
        }

        $result[] = [
            'id'     => $record['id'],
            'name'   => $record['name'],
            'url'    => $record['url'],
            'logo'   => $record['logo'],
            'group'  => $overrides[$id]['group'] ?? groupFor($record),
            'kind'   => $record['kind'],
            'source' => $record['source'],
        ];
    }

    return $result;
}

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

function gitHubRecords(): array
{
    $token = getenv('SPONSORS_TOKEN');

    if (false === $token || '' === $token) {
        throw new RuntimeException('SPONSORS_TOKEN is not set');
    }

    $query = <<<'GRAPHQL'
        {
          organization(login: "phalcon") {
            sponsorshipsAsMaintainer(first: 100, activeOnly: true, includePrivate: true) {
              nodes {
                privacyLevel
                tier { monthlyPriceInDollars }
                sponsorEntity {
                  ... on User         { login name url avatarUrl }
                  ... on Organization { login name url avatarUrl }
                }
              }
            }
          }
        }
        GRAPHQL;

    $data  = graphql(GITHUB_API, $query, ['Authorization: bearer ' . $token]);
    $nodes = $data['organization']['sponsorshipsAsMaintainer']['nodes'] ?? [];

    if ([] === $nodes) {
        throw new RuntimeException('GitHub returned no sponsorships; refusing to write a thinner roster');
    }

    $records = [];

    foreach ($nodes as $node) {
        $entity = $node['sponsorEntity'] ?? null;

        // A private sponsor asked not to be listed. An entity of null is a
        // sponsor this token cannot resolve; either way, skip it.
        if (null === $entity || 'PRIVATE' === ($node['privacyLevel'] ?? 'PRIVATE')) {
            continue;
        }

        $records[] = [
            'id'         => 'gh:' . strtolower($entity['login']),
            'name'       => $entity['name'] ?: $entity['login'],
            'url'        => $entity['url'],
            'logo'       => $entity['avatarUrl'] ?? null,
            'kind'       => 'financial',
            'source'     => ['github'],
            'monthlyUsd' => (int) ($node['tier']['monthlyPriceInDollars'] ?? 0),
        ];
    }

    return $records;
}

function openCollectiveRecords(): array
{
    $query = <<<'GRAPHQL'
        {
          collective(slug: "phalcon") {
            orders(filter: INCOMING, status: [ACTIVE], limit: 100) {
              nodes {
                frequency
                amount { valueInCents }
                fromAccount { slug name website imageUrl isIncognito }
              }
            }
          }
        }
        GRAPHQL;

    $data  = graphql(OPENCOLLECTIVE, $query);
    $nodes = $data['collective']['orders']['nodes'] ?? [];

    if ([] === $nodes) {
        throw new RuntimeException('Open Collective returned no active orders; refusing to write a thinner roster');
    }

    $records = [];

    foreach ($nodes as $node) {
        $account = $node['fromAccount'] ?? null;

        if (null === $account || true === ($account['isIncognito'] ?? false)) {
            continue;
        }

        $slug = strtolower((string) ($account['slug'] ?? ''));

        if ('' === $slug || in_array($slug, OC_EXCLUDED_SLUGS, true)) {
            continue;
        }

        $records[] = [
            'id'         => 'oc:' . $slug,
            'name'       => $account['name'] ?: $slug,
            'url'        => $account['website'] ?? 'https://opencollective.com/' . $slug,
            'logo'       => $account['imageUrl'] ?? null,
            'kind'       => 'financial',
            'source'     => ['opencollective'],
            'monthlyUsd' => monthlyDollars(
                (int) ($node['amount']['valueInCents'] ?? 0),
                (string) ($node['frequency'] ?? 'MONTHLY'),
            ),
        ];
    }

    return $records;
}

function partnerRecords(): array
{
    $records = [];

    foreach (readJsonFile(PARTNERS_FILE) as $partner) {
        $records[] = [
            'id'         => 'manual:' . $partner['id'],
            'name'       => $partner['name'],
            'url'        => $partner['url'],
            'logo'       => $partner['logo'] ?? null,
            'kind'       => 'in-kind',
            'source'     => ['manual'],
            'monthlyUsd' => 0,
        ];
    }

    return $records;
}

function monthlyDollars(int $valueInCents, string $frequency): int
{
    $dollars = intdiv($valueInCents, 100);

    return 'YEARLY' === $frequency ? intdiv($dollars, 12) : $dollars;
}

function graphql(string $endpoint, string $query, array $headers = []): array
{
    $handle = curl_init($endpoint);

    curl_setopt_array($handle, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode(['query' => $query]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_USERAGENT      => 'phalcon-assets-sponsors',
        CURLOPT_HTTPHEADER     => array_merge(['Content-Type: application/json'], $headers),
    ]);

    // No curl_close(): the handle is an object since PHP 8.0 and frees itself
    // when it goes out of scope. Calling it is deprecated as of PHP 8.5.
    $body   = curl_exec($handle);
    $status = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    $error  = curl_error($handle);

    if (false === $body) {
        throw new RuntimeException(sprintf('%s: %s', $endpoint, $error));
    }

    if (200 !== $status) {
        throw new RuntimeException(sprintf('%s returned HTTP %d', $endpoint, $status));
    }

    $decoded = json_decode((string) $body, true);

    if (!is_array($decoded)) {
        throw new RuntimeException(sprintf('%s returned a body that is not JSON', $endpoint));
    }

    if (isset($decoded['errors'][0]['message'])) {
        throw new RuntimeException(sprintf('%s: %s', $endpoint, $decoded['errors'][0]['message']));
    }

    return $decoded['data'] ?? [];
}

function readJsonFile(string $path): array
{
    if (!is_file($path)) {
        return [];
    }

    $decoded = json_decode((string) file_get_contents($path), true);

    if (!is_array($decoded)) {
        throw new RuntimeException(sprintf('%s is not valid JSON', $path));
    }

    return $decoded;
}
