<?php

/**
 * Tests for _tokens/functions.php, and the check of phalcon/css/tokens.css.
 * Plain PHP, with no framework: each check prints one line, and the exit code
 * is 1 when a check fails.
 *
 * Usage: php tests/tokens.php
 */

declare(strict_types=1);

require __DIR__ . '/../_tokens/functions.php';

$results = [];

$check = static function (string $label, bool $ok) use (&$results): void {
    $results[] = [$label, $ok];
};

/*
 * A correct tokens file with two colors only. In the light tone, code-bg is
 * white and every other role is black. The dark tone is the reverse. Thus
 * every syntax role has a contrast of 21:1. $extra adds lines at the end of
 * the rule, and $remove drops tokens by name.
 */
$fixture = static function (string $extra = '', string ...$remove): string {
    $tokens = [
        '--ph-black'     => '#000000',
        '--ph-font-mono' => 'monospace',
        '--ph-font-sans' => 'sans-serif',
        '--ph-white'     => '#ffffff',
    ];

    foreach (REQUIRED_ROLES as $role) {
        $tokens['--ph-light-' . $role] = 'code-bg' === $role ? 'var(--ph-white)' : 'var(--ph-black)';
        $tokens['--ph-dark-' . $role] = 'code-bg' === $role ? 'var(--ph-black)' : 'var(--ph-white)';
    }

    $body = '';

    foreach ($tokens as $name => $value) {
        if (!in_array($name, $remove, true)) {
            $body .= '    ' . $name . ': ' . $value . ";\n";
        }
    }

    return "/* A fixture { with braces } in a comment. */\n:root {\n" . $body . $extra . "}\n";
};

$finds = static fn (string $css, string $needle): bool => str_contains(implode("\n", tokenProblems($css)), $needle);

// contrastRatio
$check('contrastRatio of black on white is 21:1', '21.00' === sprintf('%.2f', contrastRatio('#000000', '#ffffff')));
$check('contrastRatio of a color on itself is 1:1', '1.00' === sprintf('%.2f', contrastRatio('#0f9e86', '#0f9e86')));
$check(
    'contrastRatio does not depend on the order',
    contrastRatio('#0f9e86', '#eef4f0') === contrastRatio('#eef4f0', '#0f9e86')
);
$check('contrastRatio of #767676 on white is 4.54:1', '4.54' === sprintf('%.2f', contrastRatio('#767676', '#ffffff')));

// tokenProblems: correct files
$check('tokenProblems accepts a correct file (with braces in a comment)', [] === tokenProblems($fixture()));
$check(
    'tokenProblems accepts rgb() with an alpha for a translucent role',
    [] === tokenProblems($fixture(
        "    --ph-light-ring: rgb(15 158 134 / 0.35);\n    --ph-dark-ring: rgb(116 204 178 / 0.4);\n",
        '--ph-light-ring',
        '--ph-dark-ring'
    ))
);
$check(
    'tokenProblems accepts a role that both tones add',
    [] === tokenProblems($fixture("    --ph-light-extra: var(--ph-black);\n    --ph-dark-extra: var(--ph-white);\n"))
);
$check(
    'tokenProblems accepts Windows line endings',
    [] === tokenProblems(str_replace("\n", "\r\n", $fixture()))
);
$check(
    'tokenProblems accepts upper-case hex',
    [] === tokenProblems($fixture("    --ph-white: #FFFFFF;\n", '--ph-white'))
);
$check(
    'tokenProblems accepts a comment with ; and : inside the rule',
    [] === tokenProblems($fixture("    /* note: one; two */\n"))
);
$check(
    'tokenProblems reads a required last declaration with no semicolon',
    [] === tokenProblems($fixture("    --ph-font-sans: sans-serif\n", '--ph-font-sans'))
);
$check(
    'tokenProblems accepts a syntax color at 4.54:1',
    [] === tokenProblems($fixture(
        "    --ph-gray: #767676;\n    --ph-light-syntax-comment: var(--ph-gray);\n",
        '--ph-light-syntax-comment'
    ))
);

// tokenProblems: structure
$check('tokenProblems rejects an empty file', $finds('', 'exactly one rule'));
$check('tokenProblems rejects a second rule', $finds($fixture() . "html { color: red; }\n", 'exactly one rule'));
$check(
    'tokenProblems rejects a rule that is not :root',
    $finds(str_replace(':root {', 'html {', $fixture()), 'exactly one rule')
);
$check(
    'tokenProblems rejects a theme selector around the rule',
    $finds("@media (prefers-color-scheme: dark) {\n" . $fixture() . "}\n", 'text outside the :root rule')
);

// tokenProblems: names and palette
$check(
    'tokenProblems rejects a name with no --ph- prefix',
    $finds($fixture("    --color-black: #000000;\n"), '--color-black: not a --ph- custom property')
);
$check(
    'tokenProblems rejects a token defined twice',
    $finds($fixture("    --ph-black: #000000;\n"), '--ph-black: defined twice')
);
$check(
    'tokenProblems rejects a 3-digit hex',
    $finds($fixture("    --ph-black: #000;\n", '--ph-black'), '--ph-black: a palette color must be #rrggbb')
);
$check(
    'tokenProblems rejects a named color in the palette',
    $finds($fixture("    --ph-black: black;\n", '--ph-black'), '--ph-black: a palette color must be #rrggbb')
);

// tokenProblems: tones
$check(
    'tokenProblems reports a role missing in one tone',
    $finds($fixture('', '--ph-dark-accent'), '--ph-dark-accent: missing (--ph-light-accent exists)')
);
$check(
    'tokenProblems reports a role that only one tone adds',
    $finds($fixture("    --ph-light-extra: var(--ph-black);\n"), '--ph-dark-extra: missing')
);
$check(
    'tokenProblems reports a required role missing in both tones',
    $finds($fixture('', '--ph-light-accent', '--ph-dark-accent'), '--ph-light-accent: missing')
);
$check(
    'tokenProblems rejects var() of an unknown color',
    $finds(
        $fixture("    --ph-dark-text: var(--ph-nope);\n", '--ph-dark-text'),
        '--ph-dark-text: --ph-nope is not a palette color'
    )
);
$check(
    'tokenProblems rejects a tone value that is not var() or rgb()',
    $finds($fixture("    --ph-light-text: #000000;\n", '--ph-light-text'), '--ph-light-text: must be var(')
);
$check(
    'tokenProblems rejects the rgba() form',
    $finds($fixture("    --ph-light-ring: rgba(0, 0, 0, 0.1);\n", '--ph-light-ring'), '--ph-light-ring: must be var(')
);

// tokenProblems: fonts
$check('tokenProblems reports a missing font', $finds($fixture('', '--ph-font-mono'), '--ph-font-mono: missing'));
$check(
    'tokenProblems reports an empty font as missing',
    $finds($fixture("    --ph-font-mono: ;\n", '--ph-font-mono'), '--ph-font-mono: missing')
);

// tokenProblems: syntax contrast
$check(
    'tokenProblems rejects a syntax color below 4.5:1',
    $finds(
        $fixture(
            "    --ph-gray: #777777;\n    --ph-light-syntax-comment: var(--ph-gray);\n",
            '--ph-light-syntax-comment'
        ),
        '--ph-light-syntax-comment: contrast 4.48:1 on --ph-light-code-bg'
    )
);
$check(
    'tokenProblems rejects a translucent syntax color',
    $finds(
        $fixture("    --ph-dark-syntax-text: rgb(255 255 255 / 0.5);\n", '--ph-dark-syntax-text'),
        '--ph-dark-syntax-text: the contrast needs a palette color'
    )
);

// phalcon/css/tokens.css
$file = __DIR__ . '/../phalcon/css/tokens.css';
$problems = is_file($file) ? tokenProblems((string) file_get_contents($file)) : ['the file is missing'];

foreach ($problems as $problem) {
    echo 'tokens.css: ' . $problem . PHP_EOL;
}

$check('phalcon/css/tokens.css has no problems', [] === $problems);

$failed = 0;

foreach ($results as [$label, $ok]) {
    echo ($ok ? 'ok    ' : 'FAIL  ') . $label . PHP_EOL;
    $failed += $ok ? 0 : 1;
}

echo PHP_EOL . (count($results) - $failed) . ' of ' . count($results) . ' checks passed' . PHP_EOL;

exit(0 === $failed ? 0 : 1);
