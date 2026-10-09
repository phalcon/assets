<?php

/**
 * Pure functions for updateBackers.php. They do no network calls and read no
 * files, so tests/backers.php calls them directly. They use nodesAt() and
 * stringAt() of scripts/sponsors/functions.php, so a file that loads this
 * file must load that file first.
 *
 * A backer entry (BackerEntry) is the part of a roster entry of sponsors.json
 * that the roster shows: the name, the address, the logo and the group.
 */

declare(strict_types=1);

/** The marker after the roster. */
const BACKERS_END = '<!-- backers:end -->';

/** The groups that show a logo. The other groups show an avatar. */
const BACKERS_LOGO_GROUPS = ['partner', 'sponsor'];

/** The sections of the roster, in their order: the group and the title. */
const BACKERS_SECTIONS = [
    'sponsor'   => 'Sponsors',
    'partner'   => 'Partners',
    'supporter' => 'Supporters',
    'backer'    => 'Backers',
];

/** The marker before the roster. */
const BACKERS_START = '<!-- backers:start -->';

/**
 * The entries of the text of sponsors.json, in their order. Throws when the
 * text is not a JSON object, when the roster has no sponsors, or when a
 * sponsor has no name or no group.
 *
 * @return list<BackerEntry>
 */
function backerEntries(string $json): array
{
    $data = json_decode($json, true);

    if (!is_array($data)) {
        throw new RuntimeException('the roster is not a JSON object');
    }

    $entries = [];

    foreach (nodesAt($data, 'sponsors') as $node) {
        $name  = stringAt($node, 'name');
        $group = stringAt($node, 'group');

        if (null === $name || null === $group) {
            throw new RuntimeException('a sponsor of the roster has no name or no group');
        }

        $entries[] = [
            'name'  => $name,
            'url'   => stringAt($node, 'url'),
            'logo'  => stringAt($node, 'logo'),
            'group' => $group,
        ];
    }

    if ([] === $entries) {
        throw new RuntimeException('the roster has no sponsors');
    }

    return $entries;
}

/**
 * The HTML of an entry: the logo or the avatar, with a link to the address.
 * An entry with no logo shows its name. An entry with no address has no link.
 *
 * @param BackerEntry $entry
 */
function backerHtml(array $entry): string
{
    $name = htmlspecialchars($entry['name']);
    $html = $name;

    if (null !== $entry['logo']) {
        $html = sprintf(
            '<img src="%s" alt="%s" title="%s" %s>',
            htmlspecialchars($entry['logo']),
            $name,
            $name,
            in_array($entry['group'], BACKERS_LOGO_GROUPS, true) ? 'height="40"' : 'width="60" height="60"',
        );
    }

    if (null === $entry['url']) {
        return $html;
    }

    return sprintf('<a href="%s">%s</a>', htmlspecialchars($entry['url']), $html);
}

/**
 * The roster text between the markers: a section for each group that has
 * entries, in the order of BACKERS_SECTIONS. The entries of a section keep
 * the order of the roster. An entry of a group that is not in
 * BACKERS_SECTIONS is not shown. Throws when no entry is in a group of
 * BACKERS_SECTIONS, so the roster in the file does not become empty.
 *
 * @param list<BackerEntry> $entries
 */
function backersMarkdown(array $entries): string
{
    $markdown = '';

    foreach (BACKERS_SECTIONS as $group => $title) {
        $lines = [];

        foreach ($entries as $entry) {
            if ($group === $entry['group']) {
                $lines[] = backerHtml($entry);
            }
        }

        if ([] !== $lines) {
            $markdown .= '### ' . $title . "\n\n" . implode("\n", $lines) . "\n\n";
        }
    }

    if ('' === $markdown) {
        throw new RuntimeException('the roster has no sponsor in a known group');
    }

    return $markdown;
}

/**
 * The text of the file with the roster between the markers. The text outside
 * the markers does not change. Throws when a marker is missing, or when the
 * end marker is before the start marker.
 */
function replaceRoster(string $contents, string $roster): string
{
    $start = strpos($contents, BACKERS_START);
    $end   = strpos($contents, BACKERS_END);

    if (false === $start || false === $end || $end < $start) {
        throw new RuntimeException(
            sprintf('the file has no %s and %s markers in this order', BACKERS_START, BACKERS_END),
        );
    }

    return substr($contents, 0, $start + strlen(BACKERS_START)) . "\n\n" . $roster . substr($contents, $end);
}
