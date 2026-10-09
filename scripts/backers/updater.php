<?php

/**
 * The file and network part of updateBackers.php: it reads the roster and
 * the file, and writes the file. The pure functions are in functions.php.
 */

declare(strict_types=1);

/** The published roster. phalcon/assets builds it every day. */
const ROSTER_URL = 'https://assets.phalcon.io/phalcon/sponsors.json';

/**
 * The text at a path or an address. Throws when it cannot be read.
 */
function readText(string $source): string
{
    $text = @file_get_contents($source);

    if (false === $text) {
        throw new RuntimeException('cannot read ' . $source);
    }

    return $text;
}

/**
 * Rebuilds the roster in the file of the first argument from the roster of
 * the second argument (ROSTER_URL when it is not given). Gives 1, with the
 * error on STDERR, when it cannot read the roster or the file, when the
 * roster or the markers are not correct, or when it cannot write the file.
 * The file does not change then.
 *
 * @param array<int, string> $arguments
 */
function updateBackers(array $arguments): int
{
    $file   = $arguments[0] ?? null;
    $source = $arguments[1] ?? ROSTER_URL;

    if (null === $file) {
        fwrite(STDERR, 'usage: php scripts/updateBackers.php <file> [<roster>]' . PHP_EOL);

        return 1;
    }

    try {
        $contents = readText($file);
        $updated  = replaceRoster($contents, backersMarkdown(backerEntries(readText($source))));
    } catch (RuntimeException $e) {
        fwrite(STDERR, 'error: ' . $e->getMessage() . PHP_EOL);

        return 1;
    }

    if ($updated === $contents) {
        fwrite(STDOUT, 'No change to ' . $file . PHP_EOL);

        return 0;
    }

    if (false === file_put_contents($file, $updated)) {
        fwrite(STDERR, 'error: cannot write ' . $file . PHP_EOL);

        return 1;
    }

    fwrite(STDOUT, $file . ' is updated' . PHP_EOL);

    return 0;
}
