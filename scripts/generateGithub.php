<?php

/**
 * Builds phalcon/repositories.json and phalcon/contributors.json. The work is
 * in scripts/github/generator.php; this file only loads it and runs it.
 *
 * Usage: GITHUB_TOKEN=… php scripts/generateGithub.php
 */

declare(strict_types=1);

require __DIR__ . '/github/functions.php';
require __DIR__ . '/github/generator.php';

exit(main());
