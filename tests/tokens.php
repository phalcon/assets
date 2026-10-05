<?php

/**
 * Tests for _tokens/functions.php, and the checks of phalcon/css/tokens.css and
 * phalcon/css/code-theme.json.
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

// syntaxRoles
$check(
    'syntaxRoles lists the syntax roles of both tones, sorted',
    ['comment', 'keyword'] === syntaxRoles(
        ":root {\n    --ph-light-syntax-keyword: var(--ph-a);\n    --ph-dark-syntax-keyword: var(--ph-b);\n"
        . "    --ph-light-syntax-comment: var(--ph-a);\n    --ph-dark-syntax-comment: var(--ph-b);\n"
        . "    --ph-light-syntax-only: var(--ph-a);\n    --ph-light-text: var(--ph-a);\n}\n"
    )
);

/*
 * A correct code theme: a rule with a list scope, a rule with a font style,
 * and a rule with a font style only. $changes replaces top-level keys.
 *
 * @param array<string, mixed> $changes
 */
$theme = static function (array $changes = []): string {
    $base = [
        'colors'      => ['editor.background' => 'var(--code-bg)', 'editor.foreground' => 'var(--code-text)'],
        'name'        => 'phalcon',
        'tokenColors' => [
            ['scope' => ['comment', 'string.comment'], 'settings' => ['foreground' => 'var(--code-comment)']],
            ['scope' => 'markup.bold', 'settings' => ['fontStyle' => 'bold', 'foreground' => 'var(--code-text)']],
            ['scope' => 'markup.underline', 'settings' => ['fontStyle' => 'underline']],
        ],
        'type'        => 'dark',
    ];

    return (string) json_encode(array_replace($base, $changes));
};

/** @param array<string, mixed> $settings */
$rule = static fn (mixed $scope, array $settings): array => ['scope' => $scope, 'settings' => $settings];

$themeFinds = static fn (string $json, string $needle): bool => str_contains(
    implode("\n", codeThemeProblems($json, ['comment', 'text'])),
    $needle
);

// codeThemeProblems
$check('codeThemeProblems accepts a correct theme', [] === codeThemeProblems($theme(), ['comment', 'text']));
$check('codeThemeProblems rejects text that is not JSON', $themeFinds('not json', 'not a JSON object'));
$check('codeThemeProblems rejects a JSON list', $themeFinds('[]', 'not a JSON object'));
$check('codeThemeProblems rejects a theme with no name', $themeFinds($theme(['name' => '']), 'name: missing'));
$check('codeThemeProblems rejects an unknown type', $themeFinds($theme(['type' => 'blue']), 'type: must be'));
$check(
    'codeThemeProblems rejects a typed background color',
    $themeFinds($theme(['colors' => ['editor.background' => '#0d1117']]), 'colors: editor.background must be')
);
$check(
    'codeThemeProblems rejects an empty rule list',
    $themeFinds($theme(['tokenColors' => []]), 'tokenColors: must be')
);
$check(
    'codeThemeProblems rejects a typed foreground',
    $themeFinds(
        $theme(['tokenColors' => [$rule('comment', ['foreground' => '#8b949e'])]]),
        'tokenColors[0]: foreground must be var(--code-<role>)'
    )
);
$check(
    'codeThemeProblems rejects a role with no syntax token',
    $themeFinds(
        $theme(['tokenColors' => [$rule('comment', ['foreground' => 'var(--code-nope)'])]]),
        'tokenColors[0]: --code-nope has no syntax token'
    )
);
$check(
    'codeThemeProblems rejects a background in a rule',
    $themeFinds(
        $theme(['tokenColors' => [$rule('comment', ['background' => 'var(--code-comment)'])]]),
        'tokenColors[0]: settings.background is not allowed'
    )
);
$check(
    'codeThemeProblems rejects a scope that is not a string or a list of strings',
    $themeFinds(
        $theme(['tokenColors' => [$rule(42, ['foreground' => 'var(--code-text)'])]]),
        'tokenColors[0]: scope must be'
    )
);
$check(
    'codeThemeProblems rejects a top-level color that Shiki reads (bg)',
    $themeFinds($theme(['bg' => '#ff0000']), 'bg: not allowed')
);
$check(
    'codeThemeProblems rejects top-level settings, which Shiki uses in place of tokenColors',
    $themeFinds($theme(['settings' => [$rule('comment', ['foreground' => '#ff0000'])]]), 'settings: not allowed')
);
$check(
    'codeThemeProblems rejects a color other than the two editor colors',
    $themeFinds(
        $theme(['colors' => [
            'editor.background' => 'var(--code-bg)',
            'editor.foreground' => 'var(--code-text)',
            'terminal.ansiRed'  => '#ff0000',
        ]]),
        'colors.terminal.ansiRed: not allowed'
    )
);
$check(
    'codeThemeProblems rejects a blank scope, which Shiki makes the default of all text',
    $themeFinds(
        $theme(['tokenColors' => [$rule(' ', ['foreground' => 'var(--code-text)'])]]),
        'tokenColors[0]: scope must be'
    )
);
$check(
    'codeThemeProblems rejects a blank scope in a list',
    $themeFinds(
        $theme(['tokenColors' => [$rule(['comment', ''], ['foreground' => 'var(--code-comment)'])]]),
        'tokenColors[0]: scope must be'
    )
);

// scopeColor
$scoped = [
    $rule(['string'], ['foreground' => 'var(--code-string)']),
    $rule('keyword', ['foreground' => 'var(--code-keyword)']),
    $rule(['constant', 'string'], ['foreground' => 'var(--code-constant)']),
    $rule('string', ['fontStyle' => 'italic']),
];
$check(
    'scopeColor takes the last rule that names the scope and sets a color, as Shiki does',
    'var(--code-constant)' === scopeColor($scoped, 'string')
);
$check('scopeColor returns null for a scope that no rule names', null === scopeColor($scoped, 'nope'));

// phalcon/css/tokens.css
$file = __DIR__ . '/../phalcon/css/tokens.css';
$problems = is_file($file) ? tokenProblems((string) file_get_contents($file)) : ['the file is missing'];

foreach ($problems as $problem) {
    echo 'tokens.css: ' . $problem . PHP_EOL;
}

$check('phalcon/css/tokens.css has no problems', [] === $problems);

// phalcon/css/code-theme.json: the rules of GitHub's dark theme (Shiki's
// github-dark-default), with --code- variables.
$themeFile = __DIR__ . '/../phalcon/css/code-theme.json';
$themeJson = is_file($themeFile) ? (string) file_get_contents($themeFile) : '';
$problems = codeThemeProblems($themeJson, syntaxRoles(is_file($file) ? (string) file_get_contents($file) : ''));

foreach ($problems as $problem) {
    echo 'code-theme.json: ' . $problem . PHP_EOL;
}

$check('phalcon/css/code-theme.json has no problems', [] === $problems);

$decoded = json_decode($themeJson, true);
$rules = is_array($decoded) && is_array($decoded['tokenColors'] ?? null) ? $decoded['tokenColors'] : [];

$check("phalcon/css/code-theme.json has the 49 rules of GitHub's dark theme", 49 === count($rules));

$githubRoles = [
    'comment'              => 'comment',
    'constant'             => 'constant',
    'entity.name.function' => 'function',
    'entity.name.tag'      => 'string-expression',
    'keyword'              => 'keyword',
    'markup.changed'       => 'changed',
    'markup.deleted'       => 'deleted',
    'markup.inserted'      => 'inserted',
    'string'               => 'string',
    'variable'             => 'parameter',
];
$wrong = [];

foreach ($githubRoles as $scope => $role) {
    if ('var(--code-' . $role . ')' !== scopeColor($rules, $scope)) {
        $wrong[] = $scope . ' -> ' . (scopeColor($rules, $scope) ?? 'none');
    }
}

foreach ($wrong as $line) {
    echo 'code-theme.json: ' . $line . PHP_EOL;
}

$check("phalcon/css/code-theme.json gives GitHub's roles to the main scopes", [] === $wrong);

$failed = 0;

foreach ($results as [$label, $ok]) {
    echo ($ok ? 'ok    ' : 'FAIL  ') . $label . PHP_EOL;
    $failed += $ok ? 0 : 1;
}

echo PHP_EOL . (count($results) - $failed) . ' of ' . count($results) . ' checks passed' . PHP_EOL;

exit(0 === $failed ? 0 : 1);
