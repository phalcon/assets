<?php

/**
 * Rebuilds the roster of sponsors in a Markdown file (for example README.md)
 * from the published roster, https://assets.phalcon.io/phalcon/sponsors.json.
 * The work is in scripts/backers/updater.php (the files and the network) and
 * scripts/backers/functions.php (the pure functions); this file only loads
 * them and runs them.
 *
 * The roster is the text between the <!-- backers:start --> and
 * <!-- backers:end --> markers. The text outside the markers does not change.
 * The roster has no date and a fixed order, so an unchanged roster gives the
 * same file and the workflow has nothing to commit.
 *
 * The reusable workflow .github/workflows/backers.yml runs this script for
 * the repositories that call it.
 *
 * Usage: php scripts/updateBackers.php <file> [<roster>]
 *   <roster> is a path or an address. The default is the published roster.
 */

declare(strict_types=1);

require __DIR__ . '/sponsors/functions.php';
require __DIR__ . '/backers/functions.php';
require __DIR__ . '/backers/updater.php';

exit(updateBackers(array_slice($argv, 1)));
