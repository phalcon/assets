<?php

/**
 * Builds public/phalcon/sponsors.json from the two funding platforms plus the
 * hand-kept partner list. The work is in scripts/sponsors/generator.php (the
 * network and the files) and scripts/sponsors/functions.php (the pure
 * functions); this file only loads them and runs them.
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
 *   - scripts/sponsors/partners.json, the in-kind list. Cloudflare and the
 *     like give us service tiers, never appear in either API, and are most of
 *     the block.
 *
 * Usage: SPONSORS_TOKEN=… php scripts/generateSponsors.php
 */

declare(strict_types=1);

require __DIR__ . '/sponsors/functions.php';
require __DIR__ . '/sponsors/generator.php';

exit(main());
