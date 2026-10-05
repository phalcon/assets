<?php

/**
 * Pure functions for tests/tokens.php. They check phalcon/css/tokens.css, the
 * design tokens that every Phalcon site uses, and phalcon/css/code-theme.json,
 * the code theme that uses them. They read no files.
 */

declare(strict_types=1);

/**
 * The top-level keys of a code theme. Shiki also reads bg, fg, settings and colorReplacements, and each of them
 * can set a color that is not a token.
 */
const CODE_KEYS = ['colors', 'name', 'tokenColors', 'type'];

/** The keys that a rule of the code theme can set. A background is not one: the tokens have no such colors. */
const CODE_SETTINGS = ['fontStyle', 'foreground'];

/** A color of the code theme: var(--code-<role>). The match is the role. */
const CODE_VAR_PATTERN = '/^var\(--code-([a-z0-9-]+)\)$/';

/** The fonts that every site needs. */
const FONT_TOKENS = ['--ph-font-mono', '--ph-font-sans'];

/** A palette color: #rrggbb. */
const HEX_PATTERN = '/^#[0-9a-f]{6}$/i';

/** The smallest contrast of a syntax color on the code background of its tone (WCAG AA). */
const MIN_CONTRAST = 4.5;

/** The roles that each tone must have. The sites use these names. */
const REQUIRED_ROLES = [
    'accent',
    'accent-contrast',
    'accent-hover',
    'bg',
    'bg-alt',
    'border',
    'border-strong',
    'code-bg',
    'code-border',
    'danger',
    'danger-bg',
    'faint',
    'heading',
    'info',
    'info-bg',
    'kicker',
    'muted',
    'panel',
    'ring',
    'success',
    'success-bg',
    'surface',
    'syntax-changed',
    'syntax-comment',
    'syntax-constant',
    'syntax-deleted',
    'syntax-function',
    'syntax-inserted',
    'syntax-keyword',
    'syntax-link',
    'syntax-parameter',
    'syntax-punctuation',
    'syntax-string',
    'syntax-string-expression',
    'syntax-text',
    'text',
    'warning',
    'warning-bg',
];

/** A translucent tone value: rgb(r g b / alpha). */
const RGB_PATTERN = '/^rgb\(\d{1,3} \d{1,3} \d{1,3} \/ (?:0|1|0?\.\d+)\)$/';

/** The two tones, as they appear in the token names. */
const TONES = ['dark', 'light'];

/** A tone value that refers to a palette color. The match is the name of the color. */
const VAR_PATTERN = '/^var\((--ph-[a-z0-9-]+)\)$/';

/**
 * Every problem in a code theme file, one line each. $roles are the syntax
 * roles of the tokens file (see syntaxRoles()). An empty list means that the
 * file is correct.
 *
 * @param list<string> $roles
 *
 * @return list<string>
 */
function codeThemeProblems(string $json, array $roles): array
{
    $theme = json_decode($json, true);

    if (!is_array($theme) || array_is_list($theme)) {
        return ['the file is not a JSON object'];
    }

    $problems = [];

    foreach (array_keys($theme) as $key) {
        if (!in_array($key, CODE_KEYS, true)) {
            $problems[] = $key . ': not allowed (only colors, name, tokenColors and type)';
        }
    }

    if (!is_string($theme['name'] ?? null) || '' === $theme['name']) {
        $problems[] = 'name: missing';
    }

    if (!in_array($theme['type'] ?? null, ['dark', 'light'], true)) {
        $problems[] = 'type: must be dark or light';
    }

    $colors = $theme['colors'] ?? null;

    if (
        !is_array($colors)
        || 'var(--code-bg)' !== ($colors['editor.background'] ?? null)
        || 'var(--code-text)' !== ($colors['editor.foreground'] ?? null)
    ) {
        $problems[] = 'colors: editor.background must be var(--code-bg), and editor.foreground var(--code-text)';
    }

    foreach (is_array($colors) ? array_keys($colors) : [] as $key) {
        if (!in_array($key, ['editor.background', 'editor.foreground'], true)) {
            $problems[] = 'colors.' . $key . ': not allowed (only editor.background and editor.foreground)';
        }
    }

    $rules = $theme['tokenColors'] ?? null;

    if (!is_array($rules) || [] === $rules || !array_is_list($rules)) {
        return [...$problems, 'tokenColors: must be a list of rules'];
    }

    foreach ($rules as $index => $rule) {
        $label = 'tokenColors[' . $index . ']';
        $scope = is_array($rule) ? ($rule['scope'] ?? null) : null;
        $settings = is_array($rule) ? ($rule['settings'] ?? null) : null;
        // A blank scope makes the rule the default of all text, so every scope must have a name.
        $scopes = is_string($scope) ? [$scope] : (is_array($scope) && array_is_list($scope) ? $scope : []);
        $named = array_filter($scopes, static fn (mixed $item): bool => is_string($item) && '' !== trim($item));

        if ([] === $scopes || count($named) !== count($scopes)) {
            $problems[] = $label . ': scope must be a string or a list of strings, and none can be blank';
        }

        if (!is_array($settings) || [] === $settings) {
            $problems[] = $label . ': settings missing';

            continue;
        }

        foreach (array_keys($settings) as $key) {
            if (!in_array($key, CODE_SETTINGS, true)) {
                $problems[] = $label . ': settings.' . $key . ' is not allowed (only fontStyle and foreground)';
            }
        }

        if (!array_key_exists('foreground', $settings)) {
            continue;
        }

        $foreground = $settings['foreground'];
        $role = is_string($foreground) && 1 === preg_match(CODE_VAR_PATTERN, $foreground, $match) ? $match[1] : null;

        if (null === $role) {
            $problems[] = $label . ': foreground must be var(--code-<role>)';
        } elseif (!in_array($role, $roles, true)) {
            $problems[] = $label . ': --code-' . $role . ' has no syntax token in the tokens file';
        }
    }

    return $problems;
}

/**
 * The WCAG 2 contrast ratio of two #rrggbb colors, from 1 to 21. The order of
 * the two colors does not matter.
 */
function contrastRatio(string $first, string $second): float
{
    $lighter = max(relativeLuminance($first), relativeLuminance($second));
    $darker = min(relativeLuminance($first), relativeLuminance($second));

    return ($lighter + 0.05) / ($darker + 0.05);
}

/**
 * The declarations of a rule body, in order. A name can occur two times.
 *
 * @return list<array{name: string, value: string}>
 */
function parseDeclarations(string $body): array
{
    $declarations = [];

    foreach (explode(';', $body) as $part) {
        if ('' === trim($part)) {
            continue;
        }

        [$name, $value] = array_pad(explode(':', $part, 2), 2, '');
        $declarations[] = ['name' => trim($name), 'value' => trim($value)];
    }

    return $declarations;
}

/**
 * The rules of a stylesheet with no nesting, and the text outside them. The
 * comments are removed first.
 *
 * @return array{rules: list<array{selector: string, body: string}>, outside: string}
 */
function parseRules(string $css): array
{
    $css = (string) preg_replace('~/\*.*?\*/~s', '', $css);
    preg_match_all('/([^{}]*)\{([^{}]*)\}/', $css, $matches, PREG_SET_ORDER);

    $rules = [];

    foreach ($matches as $match) {
        $rules[] = ['selector' => trim($match[1]), 'body' => $match[2]];
    }

    return [
        'rules'   => $rules,
        'outside' => trim((string) preg_replace('/[^{}]*\{[^{}]*\}/', '', $css)),
    ];
}

/** The WCAG 2 relative luminance of a #rrggbb color, from 0 to 1. */
function relativeLuminance(string $hex): float
{
    $channels = array_map(
        static function (string $pair): float {
            $value = hexdec($pair) / 255;

            return $value <= 0.03928 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
        },
        str_split(substr($hex, 1), 2)
    );

    return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
}

/**
 * The #rrggbb color that a tone value refers to. Null when the value is not
 * var() of a palette color that has a correct hex value.
 *
 * @param array<string, string> $palette
 */
function resolvedHex(string $value, array $palette): ?string
{
    if (1 !== preg_match(VAR_PATTERN, $value, $match)) {
        return null;
    }

    $hex = $palette[$match[1]] ?? '';

    return 1 === preg_match(HEX_PATTERN, $hex) ? $hex : null;
}

/**
 * The color that Shiki gives a scope: the foreground of the last rule that names the scope and sets a foreground.
 * A later rule with a font style only does not change the color. Null when no rule names the scope.
 *
 * @param array<mixed> $rules the tokenColors of a code theme
 */
function scopeColor(array $rules, string $scope): ?string
{
    $color = null;

    foreach ($rules as $rule) {
        if (!is_array($rule) || !in_array($scope, (array) ($rule['scope'] ?? []), true)) {
            continue;
        }

        $settings = is_array($rule['settings'] ?? null) ? $rule['settings'] : [];

        if (is_string($settings['foreground'] ?? null)) {
            $color = $settings['foreground'];
        }
    }

    return $color;
}

/**
 * The syntax roles that both tones of a tokens file define, without the
 * "syntax-" prefix, sorted. A code theme can use --code-<role> for each one.
 *
 * @return list<string>
 */
function syntaxRoles(string $css): array
{
    $names = [];

    foreach (parseRules($css)['rules'] as $rule) {
        foreach (parseDeclarations($rule['body']) as ['name' => $name]) {
            $names[$name] = true;
        }
    }

    $roles = [];

    foreach (array_keys($names) as $name) {
        if (
            1 === preg_match('/^--ph-light-syntax-([a-z0-9-]+)$/', $name, $match)
            && array_key_exists('--ph-dark-syntax-' . $match[1], $names)
        ) {
            $roles[] = $match[1];
        }
    }

    sort($roles);

    return $roles;
}

/**
 * Every problem in a tokens file, one line each. An empty list means that the
 * file is correct.
 *
 * @return list<string>
 */
function tokenProblems(string $css): array
{
    $parsed = parseRules($css);
    $problems = [];

    if ('' !== $parsed['outside']) {
        $problems[] = 'text outside the :root rule: ' . substr($parsed['outside'], 0, 40);
    }

    if (1 !== count($parsed['rules']) || ':root' !== $parsed['rules'][0]['selector']) {
        return [...$problems, 'the file must have exactly one rule, :root'];
    }

    $tokens = [];

    foreach (parseDeclarations($parsed['rules'][0]['body']) as ['name' => $name, 'value' => $value]) {
        if (1 !== preg_match('/^--ph-[a-z0-9-]+$/', $name)) {
            $problems[] = $name . ': not a --ph- custom property';

            continue;
        }

        if (array_key_exists($name, $tokens)) {
            $problems[] = $name . ': defined twice';

            continue;
        }

        $tokens[$name] = $value;
    }

    $fonts = [];
    $palette = [];
    /** @var array<string, array<string, string>> $tones */
    $tones = ['dark' => [], 'light' => []];

    foreach ($tokens as $name => $value) {
        if (1 === preg_match('/^--ph-(dark|light)-(.+)$/', $name, $match)) {
            $tones[$match[1]][$match[2]] = $value;
        } elseif (str_starts_with($name, '--ph-font-')) {
            $fonts[$name] = $value;
        } else {
            $palette[$name] = $value;
        }
    }

    foreach ($palette as $name => $value) {
        if (1 !== preg_match(HEX_PATTERN, $value)) {
            $problems[] = $name . ': a palette color must be #rrggbb, not ' . $value;
        }
    }

    foreach (FONT_TOKENS as $name) {
        if ('' === ($fonts[$name] ?? '')) {
            $problems[] = $name . ': missing';
        }
    }

    foreach (TONES as $tone) {
        $other = 'dark' === $tone ? 'light' : 'dark';

        foreach (array_keys($tones[$other]) as $role) {
            if (!array_key_exists($role, $tones[$tone])) {
                $problems[] = "--ph-{$tone}-{$role}: missing (--ph-{$other}-{$role} exists)";
            }
        }

        foreach (REQUIRED_ROLES as $role) {
            if (!array_key_exists($role, $tones[$tone]) && !array_key_exists($role, $tones[$other])) {
                $problems[] = "--ph-{$tone}-{$role}: missing";
            }
        }

        foreach ($tones[$tone] as $role => $value) {
            $name = "--ph-{$tone}-{$role}";

            if (1 === preg_match(VAR_PATTERN, $value, $match)) {
                if (!array_key_exists($match[1], $palette)) {
                    $problems[] = $name . ': ' . $match[1] . ' is not a palette color';
                }
            } elseif (1 !== preg_match(RGB_PATTERN, $value)) {
                $problems[] = $name . ': must be var(--ph-<palette color>) or rgb(r g b / a), not ' . $value;
            }
        }

        $background = resolvedHex($tones[$tone]['code-bg'] ?? '', $palette);

        foreach ($tones[$tone] as $role => $value) {
            if (!str_starts_with($role, 'syntax-')) {
                continue;
            }

            $name = "--ph-{$tone}-{$role}";
            $color = resolvedHex($value, $palette);

            if (null === $color || null === $background) {
                $problems[] = $name . ": the contrast needs a palette color here and in --ph-{$tone}-code-bg";

                continue;
            }

            $ratio = contrastRatio($color, $background);

            if ($ratio < MIN_CONTRAST) {
                $problems[] = sprintf(
                    '%s: contrast %.2f:1 on --ph-%s-code-bg, below %.1f:1',
                    $name,
                    $ratio,
                    $tone,
                    MIN_CONTRAST
                );
            }
        }
    }

    return $problems;
}
