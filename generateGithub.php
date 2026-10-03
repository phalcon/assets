<?php

/**
 * Builds phalcon/repositories.json and phalcon/contributors.json. The work is
 * in _github/generator.php; this file only loads it and runs it.
 *
 * Usage: GITHUB_TOKEN=… php generateGithub.php
 */

declare(strict_types=1);

require __DIR__ . '/_github/functions.php';
require __DIR__ . '/_github/generator.php';

exit(main());
