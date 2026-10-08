<?php

/**
 * Tests for _tokens/functions.php, and the checks of phalcon/css/tokens.css,
 * phalcon/css/code-theme.json, phalcon/css/common.css and
 * phalcon/css/sidebar.css.
 * Plain PHP, with no framework: each check prints one line, and the exit code
 * is 1 when a check fails.
 *
 * Usage: php tests/tokens.php
 */

declare(strict_types=1);

require __DIR__ . '/../_tokens/color-names.php';
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

// COLOR_NAMES (generated from the npm package color-name). The typed copy keeps
// the static analysis from reading the checks as always true.
/** @var list<string> $colorNames */
$colorNames = COLOR_NAMES;
$sortedNames = $colorNames;
sort($sortedNames);
$check('COLOR_NAMES has the 148 CSS color names, in order', 148 === count($colorNames) && $sortedNames === $colorNames);
$check(
    'COLOR_NAMES has white and rebeccapurple, and not transparent or currentcolor',
    in_array('white', $colorNames, true)
        && in_array('rebeccapurple', $colorNames, true)
        && !in_array('transparent', $colorNames, true)
        && !in_array('currentcolor', $colorNames, true)
);

// SYSTEM_COLORS (generated from the npm package mdn-data)
/** @var list<string> $systemColors */
$systemColors = SYSTEM_COLORS;
$sortedSystem = $systemColors;
sort($sortedSystem);
$check(
    'SYSTEM_COLORS has the system colors of CSS, in lowercase and in order',
    in_array('canvastext', $systemColors, true)
        && in_array('highlight', $systemColors, true)
        && $sortedSystem === $systemColors
        && $systemColors === array_map('strtolower', $systemColors)
);

// commonProblems
$commonTokens = ['--ph-font-sans', '--ph-mist-100'];
$common = static fn (string $extra = ''): string => "/* A comment can name #fff, rgb(0 0 0) and red. */\n"
    . ".ph-nav { color: var(--ph-mist-100); font-family: var(--ph-font-sans); }\n"
    . ".ph-nav__link:hover, .ph-footer :where(a) { color: transparent; }\n"
    . "@media (min-width: 64rem) {\n    .ph-nav__links { display: flex; }\n}\n"
    . "@media (hover: hover) {\n    .ph-nav__link:hover { color: currentcolor; }\n}\n"
    . $extra;
$commonFinds = static fn (string $css, string $needle): bool => str_contains(
    implode("\n", commonProblems($css, $commonTokens)),
    $needle
);

$check(
    'commonProblems accepts a correct file (comments can name colors)',
    [] === commonProblems($common(), $commonTokens)
);
$check('commonProblems rejects a hex color', $commonFinds($common(".ph-nav { color: #fff; }\n"), 'a typed color'));
$check(
    'commonProblems rejects an rgb() color',
    $commonFinds($common(".ph-nav { color: rgb(0 0 0); }\n"), 'a typed color')
);
$check(
    'commonProblems rejects a color name',
    $commonFinds($common(".ph-nav { border-color: white; }\n"), 'a color name: white')
);
$check(
    'commonProblems accepts a token that has a color name in it',
    [] === commonProblems($common(".ph-nav { color: var(--ph-white); }\n"), [...$commonTokens, '--ph-white'])
);
$check(
    'commonProblems rejects a color name in the fallback of a var()',
    $commonFinds($common(".ph-nav { color: var(--ph-mist-100, white); }\n"), 'a color name: white')
);
$check(
    'commonProblems rejects a color name in the fallback of a nested var()',
    $commonFinds($common(".ph-nav { color: var(--ph-mist-100, var(--ph-font-sans, black)); }\n"), 'a color name: black')
);
$check(
    'commonProblems rejects a token that tokens.css does not define',
    $commonFinds($common(".ph-nav { color: var(--ph-nope); }\n"), '--ph-nope: not in tokens.css')
);
$check(
    'commonProblems rejects a variable that is not a token',
    $commonFinds($common(".ph-nav { box-shadow: var(--tw-shadow); }\n"), '--tw-shadow: not a --ph- token')
);
$check(
    'commonProblems rejects a custom property definition',
    $commonFinds($common(".ph-nav { --x: 1px; }\n"), '--x: defines a custom property')
);
$check(
    'commonProblems rejects a selector outside the nav and the footer',
    $commonFinds($common("a { color: inherit; }\n"), 'a: not inside .ph-nav or .ph-footer')
);
$check(
    'commonProblems rejects a class that only starts like the nav',
    $commonFinds($common(".ph-navigation { display: block; }\n"), '.ph-navigation: not inside .ph-nav or .ph-footer')
);
$check('commonProblems rejects @import', $commonFinds('@import "x.css";' . "\n" . $common(), '@import: not allowed'));
$check(
    'commonProblems rejects url()',
    $commonFinds($common(".ph-nav { background-image: url(x.png); }\n"), 'url(): not allowed')
);
$check(
    'commonProblems rejects another media query',
    $commonFinds(
        $common("@media (max-width: 10rem) {\n    .ph-nav { display: none; }\n}\n"),
        '@media (max-width: 10rem): not allowed'
    )
);
$check(
    'commonProblems accepts the @supports block of color-mix()',
    [] === commonProblems(
        $common("@supports (color: color-mix(in lab, red, red)) {\n    .ph-nav { color: var(--ph-mist-100); }\n}\n"),
        $commonTokens
    )
);
$check(
    'commonProblems rejects another @supports block',
    $commonFinds(
        $common("@supports (display: grid) {\n    .ph-nav { display: grid; }\n}\n"),
        '@supports (display: grid): not allowed'
    )
);
$check(
    'commonProblems rejects another at-rule',
    $commonFinds($common("@font-face { font-family: x; }\n"), '@font-face: an at-rule that is not allowed')
);
$check(
    'commonProblems rejects a nested @media block',
    $commonFinds(
        $common(
            "@media (min-width: 64rem) {\n    @media (hover: hover) {\n        .ph-nav { display: none; }\n    }\n}\n"
        ),
        '@media: an at-rule that is not allowed'
    )
);
$check(
    'commonProblems rejects text outside a rule',
    $commonFinds($common(".ph-nav { display: block; }\nstray\n"), 'text outside a rule: stray')
);
$check(
    'commonProblems rejects a sibling of the nav',
    $commonFinds(
        $common(".ph-nav ~ * { display: none; }\n"),
        '.ph-nav ~ *: a sibling combinator leaves .ph-nav or .ph-footer'
    )
);
$check(
    'commonProblems rejects a sibling of the footer',
    $commonFinds(
        $common(".ph-footer + main { display: none; }\n"),
        '.ph-footer + main: a sibling combinator leaves .ph-nav or .ph-footer'
    )
);
$check(
    'commonProblems accepts a sibling inside the nav',
    [] === commonProblems($common(".ph-nav li + li { margin: 0; }\n"), $commonTokens)
);
$check(
    'commonProblems rejects a system color',
    $commonFinds($common(".ph-nav { color: CanvasText; }\n"), 'a color name: canvastext')
);
$check(
    'commonProblems rejects initial as a color',
    $commonFinds($common(".ph-nav { color: initial; }\n"), 'initial: not allowed for a color')
);
$check(
    'commonProblems accepts a property name in a transition',
    [] === commonProblems($common(".ph-nav { transition: background 0.2s; }\n"), $commonTokens)
);
$check(
    'commonProblems rejects image-set()',
    $commonFinds(
        $common(".ph-nav { background-image: image-set(\"x.png\" 1x); }\n"),
        'image-set(): not allowed'
    )
);
$check(
    'commonProblems accepts a function that has a color name',
    [] === commonProblems($common(".ph-nav { rotate: calc(tan(45deg) * 1rad); }\n"), $commonTokens)
);
$check(
    'commonProblems puts a selector on one line in its messages',
    $commonFinds($common(".ph-nav,\n.ph-footer { color: white; }\n"), '.ph-nav, .ph-footer color: a color name: white')
);
$check(
    'commonProblems rejects the dark tone of a site (the nav and the footer have one tone)',
    $commonFinds(
        $common("html.dark .ph-nav { color: inherit; }\n"),
        'html.dark .ph-nav: not inside .ph-nav or .ph-footer'
    )
);

// sidebarCssProblems
$sidebar = static fn (string $extra = ''): string => "/* A comment can name #fff and red. */\n"
    . ".ph-side__box { color: var(--ph-mist-100); }\n"
    . "html.dark .ph-side__box { font-family: var(--ph-font-sans); }\n"
    . ".ph-side__text a:hover { border-bottom-color: transparent; }\n"
    . $extra;
$sidebarFinds = static fn (string $css, string $needle): bool => str_contains(
    implode("\n", sidebarCssProblems($css, $commonTokens)),
    $needle
);

$check(
    'sidebarCssProblems accepts a correct file with rules for the dark tone',
    [] === sidebarCssProblems($sidebar(), $commonTokens)
);
$check(
    'sidebarCssProblems rejects a selector outside the sidebar',
    $sidebarFinds($sidebar(".ph-nav { color: inherit; }\n"), '.ph-nav: not inside .ph-side')
);
$check(
    'sidebarCssProblems rejects a class that only starts like the sidebar',
    $sidebarFinds($sidebar(".ph-sidebar { display: block; }\n"), '.ph-sidebar: not inside .ph-side')
);
$check(
    'sidebarCssProblems rejects the dark tone of another block',
    $sidebarFinds($sidebar("html.dark .ph-nav { color: inherit; }\n"), 'html.dark .ph-nav: not inside .ph-side')
);
$check(
    'sidebarCssProblems rejects a @media block',
    $sidebarFinds(
        $sidebar("@media (hover: hover) {\n    .ph-side__box { color: inherit; }\n}\n"),
        '@media (hover: hover): not allowed'
    )
);
$check(
    'sidebarCssProblems rejects a typed color',
    $sidebarFinds($sidebar(".ph-side__text { color: #fff; }\n"), 'a typed color')
);
$check(
    'sidebarCssProblems rejects a sibling of a box (a box has no root element)',
    $sidebarFinds($sidebar(".ph-side__box + div { margin: 0; }\n"), '.ph-side__box + div: a sibling combinator leaves')
);
$check(
    'sidebarCssProblems rejects a sibling of a box in the dark tone',
    $sidebarFinds(
        $sidebar("html.dark .ph-side__box:hover ~ * { color: inherit; }\n"),
        'html.dark .ph-side__box:hover ~ *: a sibling combinator leaves'
    )
);
$check(
    'sidebarCssProblems accepts a sibling inside a box',
    [] === sidebarCssProblems($sidebar(".ph-side__logo + .ph-side__logo { margin: 0; }\n"), $commonTokens)
);

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

// phalcon/css/common.css: the shared header and footer, on the tokens of tokens.css.
$commonFile = __DIR__ . '/../phalcon/css/common.css';
$tokenRules = parseRules(is_file($file) ? (string) file_get_contents($file) : '')['rules'];
$defined = [] === $tokenRules ? [] : array_column(parseDeclarations($tokenRules[0]['body']), 'name');
$problems = is_file($commonFile)
    ? commonProblems((string) file_get_contents($commonFile), $defined)
    : ['the file is missing'];

foreach ($problems as $problem) {
    echo 'common.css: ' . $problem . PHP_EOL;
}

$check('phalcon/css/common.css has no problems', [] === $problems);

// phalcon/css/sidebar.css: the shared sidebar, on the tokens of tokens.css.
$sidebarFile = __DIR__ . '/../phalcon/css/sidebar.css';
$problems = is_file($sidebarFile)
    ? sidebarCssProblems((string) file_get_contents($sidebarFile), $defined)
    : ['the file is missing'];

foreach ($problems as $problem) {
    echo 'sidebar.css: ' . $problem . PHP_EOL;
}

$check('phalcon/css/sidebar.css has no problems', [] === $problems);

$failed = 0;

foreach ($results as [$label, $ok]) {
    echo ($ok ? 'ok    ' : 'FAIL  ') . $label . PHP_EOL;
    $failed += $ok ? 0 : 1;
}

echo PHP_EOL . (count($results) - $failed) . ' of ' . count($results) . ' checks passed' . PHP_EOL;

exit(0 === $failed ? 0 : 1);
