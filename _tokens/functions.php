<?php

/**
 * Pure functions for tests/tokens.php. They check phalcon/css/tokens.css, the
 * design tokens that every Phalcon site uses. They read no files.
 */

declare(strict_types=1);

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
