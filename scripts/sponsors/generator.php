<?php

/**
 * The network and file part of generateSponsors.php: it reads the two funding
 * platforms and scripts/sponsors/partners.json, and writes
 * public/phalcon/sponsors.json. The pure functions are in functions.php.
 */

declare(strict_types=1);

const GITHUB_API = 'https://api.github.com/graphql';
const OPENCOLLECTIVE = 'https://api.opencollective.com/graphql/v2';
const OUTPUT = __DIR__ . '/../../public/phalcon/sponsors.json';
// In scripts/, outside the published site (public/): these are inputs to the
// generator, not files for the sites to fetch.
const OVERRIDES_FILE = __DIR__ . '/overrides.json';
const PARTNERS_FILE = __DIR__ . '/partners.json';

/**
 * The records of the GitHub sponsorships. Needs SPONSORS_TOKEN.
 *
 * @return list<SponsorRecord>
 */
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
    $nodes = nodesAt($data, 'organization', 'sponsorshipsAsMaintainer', 'nodes');

    if ([] === $nodes) {
        throw new RuntimeException('GitHub returned no sponsorships; refusing to write a thinner roster');
    }

    return array_values(array_filter(array_map('gitHubRecord', $nodes)));
}

/**
 * The data of a GraphQL answer.
 *
 * @param list<string> $headers
 *
 * @return array<mixed>
 */
function graphql(string $endpoint, string $query, array $headers = []): array
{
    $handle = curl_init($endpoint);

    curl_setopt_array($handle, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode(['query' => $query], JSON_THROW_ON_ERROR),
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

    if (!is_string($body)) {
        throw new RuntimeException(sprintf('%s: %s', $endpoint, $error));
    }

    if (200 !== $status) {
        throw new RuntimeException(sprintf('%s returned HTTP %d', $endpoint, $status));
    }

    $decoded = json_decode($body, true);

    if (!is_array($decoded)) {
        throw new RuntimeException(sprintf('%s returned a body that is not JSON', $endpoint));
    }

    $message = stringAt(nodesAt($decoded, 'errors')[0] ?? [], 'message');

    if (null !== $message) {
        throw new RuntimeException(sprintf('%s: %s', $endpoint, $message));
    }

    return arrayAt($decoded, 'data') ?? [];
}

/**
 * Builds the roster and writes it. Gives 1, with the error on STDERR, when a
 * source fails.
 */
function main(): int
{
    try {
        $records = array_merge(
            openCollectiveRecords(),
            gitHubRecords(),
            partnerRecords(),
        );
        $overrides = readJsonFile(OVERRIDES_FILE);
    } catch (RuntimeException $e) {
        fwrite(STDERR, 'error: ' . $e->getMessage() . PHP_EOL);

        return 1;
    }

    $roster = sortedRoster(applyOverrides(mergeAliases($records, $overrides), $overrides));

    file_put_contents(OUTPUT, rosterJson($roster));
    fwrite(STDOUT, sprintf('wrote %d sponsors to %s%s', count($roster), OUTPUT, PHP_EOL));

    return 0;
}

/**
 * The records of the active Open Collective orders. Public, no token.
 *
 * @return list<SponsorRecord>
 */
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
    $nodes = nodesAt($data, 'collective', 'orders', 'nodes');

    if ([] === $nodes) {
        throw new RuntimeException('Open Collective returned no active orders; refusing to write a thinner roster');
    }

    return array_values(array_filter(array_map('openCollectiveRecord', $nodes)));
}

/**
 * The records of the partners of partners.json.
 *
 * @return list<SponsorRecord>
 */
function partnerRecords(): array
{
    return array_map('partnerRecord', nodesAt(['partners' => readJsonFile(PARTNERS_FILE)], 'partners'));
}

/**
 * The decoded JSON of a file, or an empty array when the file is missing.
 *
 * @return array<mixed>
 */
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
