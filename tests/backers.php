<?php

/**
 * Tests for scripts/backers/functions.php. Plain PHP, with no framework: each
 * check prints one line, and the exit code is 1 when a check fails.
 *
 * Usage: php tests/backers.php
 */

declare(strict_types=1);

require __DIR__ . '/../scripts/sponsors/functions.php';
require __DIR__ . '/../scripts/backers/functions.php';

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

$entry = static fn (string $group, ?string $logo, ?string $url, string $name = 'Alpha'): array => [
    'name'  => $name,
    'url'   => $url,
    'logo'  => $logo,
    'group' => $group,
];

$escaped = 'A &amp; &lt;B&gt; &quot;C&quot;';
$logo    = 'https://logo.test/a.png';
$url     = 'https://a.test';

// backerHtml
$check(
    'backerHtml shows a sponsor as a logo with a link',
    '<a href="https://a.test"><img src="https://logo.test/a.png" alt="Alpha" title="Alpha" height="40"></a>'
        === backerHtml($entry('sponsor', $logo, $url))
);
$check(
    'backerHtml shows a partner as a logo',
    str_contains(backerHtml($entry('partner', $logo, $url)), ' height="40">')
);
$check(
    'backerHtml shows a supporter as an avatar',
    str_contains(backerHtml($entry('supporter', $logo, $url)), ' width="60" height="60">')
);
$check(
    'backerHtml shows a backer as an avatar',
    str_contains(backerHtml($entry('backer', $logo, $url)), ' width="60" height="60">')
);
$check(
    'backerHtml shows the name of an entry with no logo',
    '<a href="https://a.test">Alpha</a>' === backerHtml($entry('sponsor', null, $url))
);
$check(
    'backerHtml shows no link for an entry with no address',
    '<img src="https://logo.test/a.png" alt="Alpha" title="Alpha" width="60" height="60">'
        === backerHtml($entry('backer', $logo, null))
);
$check(
    'backerHtml shows only the name for an entry with no logo and no address',
    'Alpha' === backerHtml($entry('backer', null, null))
);
$check(
    'backerHtml escapes the name, the address and the logo',
    '<a href="https://a.test/?a=1&amp;b=2"><img src="https://logo.test/a.png?u=1&amp;v=4" alt="' . $escaped
        . '" title="' . $escaped . '" width="60" height="60"></a>'
        === backerHtml($entry('backer', 'https://logo.test/a.png?u=1&v=4', 'https://a.test/?a=1&b=2', 'A & <B> "C"'))
);

// backersMarkdown
$check(
    'backersMarkdown gives the sections in their order, with no empty section and the order of the roster',
    "### Sponsors\n\n<a href=\"https://a.test\">S</a>\n\n"
        . "### Supporters\n\n<a href=\"https://a.test\">U</a>\n\n"
        . "### Backers\n\n<a href=\"https://a.test\">Zed</a>\n<a href=\"https://a.test\">alpha</a>\n\n"
        === backersMarkdown([
            $entry('backer', null, $url, 'Zed'),
            $entry('supporter', null, $url, 'U'),
            $entry('backer', null, $url, 'alpha'),
            $entry('sponsor', null, $url, 'S'),
        ])
);
$check(
    'backersMarkdown does not show a group that it does not know',
    "### Backers\n\n<a href=\"https://a.test\">Alpha</a>\n\n"
        === backersMarkdown([$entry('donor', null, $url, 'Donor'), $entry('backer', null, $url)])
);
$check(
    'backersMarkdown rejects a roster with no group that it knows',
    $throws(static fn () => backersMarkdown([$entry('donor', null, $url)]), RuntimeException::class)
);

// backerEntries
$check(
    'backerEntries gives the name, the address, the logo and the group of each sponsor',
    [['name' => 'Alpha', 'url' => 'https://a.test', 'logo' => null, 'group' => 'backer']] === backerEntries(
        '{"sponsors":[{"id":"gh:a","name":"Alpha","url":"https://a.test","logo":null,'
        . '"group":"backer","kind":"financial","source":["github"]}]}'
    )
);
$check(
    'backerEntries rejects text that is not JSON',
    $throws(static fn () => backerEntries('not json'), RuntimeException::class)
);
$check(
    'backerEntries rejects a roster with an empty list',
    $throws(static fn () => backerEntries('{"sponsors":[]}'), RuntimeException::class)
);
$check(
    'backerEntries rejects a roster with no list',
    $throws(static fn () => backerEntries('{}'), RuntimeException::class)
);
$check(
    'backerEntries rejects a sponsor with no name',
    $throws(static fn () => backerEntries('{"sponsors":[{"group":"backer"}]}'), RuntimeException::class)
);

$committed = (string) file_get_contents(__DIR__ . '/../public/phalcon/sponsors.json');
$decoded   = json_decode($committed, true);

$check(
    'backerEntries reads every sponsor of the committed sponsors.json',
    is_array($decoded) && count(nodesAt($decoded, 'sponsors')) === count(backerEntries($committed))
);

// replaceRoster
$file   = "intro\n\n" . BACKERS_START . "\nold\n" . BACKERS_END . "\n\nend\n";
$roster = "### Backers\n\nx\n\n";

$check(
    'replaceRoster puts the roster between the markers and keeps the text outside them',
    "intro\n\n<!-- backers:start -->\n\n### Backers\n\nx\n\n<!-- backers:end -->\n\nend\n"
        === replaceRoster($file, $roster)
);
$check(
    'replaceRoster gives the same text on a second run',
    replaceRoster($file, $roster) === replaceRoster(replaceRoster($file, $roster), $roster)
);
$check(
    'replaceRoster rejects a file with no markers',
    $throws(static fn () => replaceRoster("intro\n", $roster), RuntimeException::class)
);
$check(
    'replaceRoster rejects a file with no end marker',
    $throws(static fn () => replaceRoster("intro\n" . BACKERS_START . "\n", $roster), RuntimeException::class)
);
$check(
    'replaceRoster rejects a file with the end marker before the start marker',
    $throws(
        static fn () => replaceRoster(BACKERS_END . "\n" . BACKERS_START . "\n", $roster),
        RuntimeException::class
    )
);

$failed = 0;

foreach ($results as [$label, $ok]) {
    echo ($ok ? 'ok    ' : 'FAIL  ') . $label . PHP_EOL;
    $failed += $ok ? 0 : 1;
}

echo PHP_EOL . (count($results) - $failed) . ' of ' . count($results) . ' checks passed' . PHP_EOL;

exit(0 === $failed ? 0 : 1);
