<?php

declare(strict_types=1);

namespace Cronos\Appearance\Domain\Services;

/**
 * Turns the tokens into CSS custom properties, once, here: the storefront and the admin preview paint exactly the same thing, and
 * the derived shades (hover, soft tints, text on the main colour) cannot drift apart between two implementations.
 */
final class ThemeCss
{
    /** @param array<string, mixed> $tokens normalised tokens @return array<string, string> */
    public static function variables(array $tokens): array
    {
        $c = $tokens['colors'];
        $t = $tokens['typography'];
        $s = $tokens['shape'];
        $l = $tokens['layout'];
        $onPrimary = $c['onPrimary'] ?? Color::readableOn($c['primary']);
        $onSecondary = $c['onSecondary'] ?? Color::readableOn($c['secondary']);
        $tight = $l['density'] === 'compact';

        return [
            '--c-primary' => $c['primary'], '--c-primary-rgb' => Color::channels($c['primary']),
            '--c-primary-hover' => Color::darken($c['primary'], 0.1), '--c-primary-soft' => Color::mix($c['primary'], $c['background'], 0.85),
            '--c-on-primary' => $onPrimary,
            // The brand colour as TEXT (prices, links, kickers): the nearest shade that passes WCAG AA on the page and card backgrounds.
            '--c-primary-ink' => Color::inkOn($c['primary'], [$c['background'], $c['surface']]),
            '--c-secondary' => $c['secondary'], '--c-secondary-hover' => Color::lighten($c['secondary'], 0.15), '--c-on-secondary' => $onSecondary,
            '--c-bg' => $c['background'], '--c-surface' => $c['surface'], '--c-surface-alt' => Color::mix($c['surface'], $c['background'], 0.5),
            '--c-text' => $c['text'], '--c-heading' => $c['heading'], '--c-link' => $c['link'], '--c-border' => $c['border'],
            '--c-success' => $c['success'], '--c-on-success' => Color::readableOn($c['success']),
            '--c-danger' => $c['danger'], '--c-on-danger' => Color::readableOn($c['danger']),
            '--c-sale' => $c['sale'], '--c-on-sale' => Color::readableOn($c['sale']),
            '--c-header-bg' => $c['headerBg'], '--c-header-text' => $c['headerText'],
            '--c-topbar-bg' => $c['topbarBg'], '--c-topbar-text' => $c['topbarText'],
            '--c-ribbon-bg' => $c['ribbonBg'], '--c-ribbon-text' => $c['ribbonText'],
            '--c-footer-bg' => $c['footerBg'], '--c-footer-text' => $c['footerText'], '--c-footer-heading' => $c['footerHeading'],
            '--font-heading' => ThemeSchema::FONTS[$t['headingFont']]['stack'], '--font-body' => ThemeSchema::FONTS[$t['bodyFont']]['stack'],
            '--fw-heading' => (string) $t['headingWeight'], '--fw-body' => (string) $t['bodyWeight'],
            '--fs-base' => $t['baseSize'].'px', '--lh-base' => (string) $t['lineHeight'],
            '--heading-transform' => $t['headingTransform'], '--letter-spacing' => $t['letterSpacing'].'px',
            '--radius-card' => $s['radiusCard'].'px', '--radius-button' => $s['radiusButton'].'px', '--radius-input' => $s['radiusInput'].'px', '--radius-badge' => $s['radiusBadge'].'px',
            '--radius-category' => ['circle' => '50%', 'rounded' => '24px', 'square' => '0'][$s['categoryShape']],
            '--card-border' => $s['cardStyle'] === 'bordered' ? '1px solid '.$c['border'] : '0',
            '--card-shadow' => $s['cardStyle'] === 'shadow' ? '0 6px 24px rgba(0,0,0,.08)' : 'none',
            '--container' => $l['containerWidth'].'px', '--space' => $tight ? '12px' : '20px', '--space-lg' => $tight ? '32px' : '56px',
            '--grid-cols-desktop' => (string) $l['gridColumnsDesktop'], '--grid-cols-mobile' => (string) $l['gridColumnsMobile'],
            '--bg-texture' => $l['backgroundTexture'] === 'grain' ? 'url("data:image/svg+xml;utf8,'.rawurlencode(self::grain()).'")' : 'none',
        ];
    }

    /** @param array<string, string> $vars */
    public static function css(array $vars): string
    {
        $lines = [];
        foreach ($vars as $name => $value) {
            // Values come from validated tokens; this is a last barrier against anything that could close the rule or add another.
            $lines[] = '  '.$name.': '.str_replace([';', '{', '}', '<', '>'], '', $value).';';
        }

        return ":root {\n".implode("\n", $lines)."\n}\n";
    }

    /** A barely-there noise pattern as an inline SVG: no image request, no weight. */
    private static function grain(): string
    {
        return '<svg xmlns="http://www.w3.org/2000/svg" width="160" height="160"><filter id="n"><feTurbulence type="fractalNoise" baseFrequency=".9" numOctaves="2" stitchTiles="stitch"/><feColorMatrix values="0 0 0 0 0  0 0 0 0 0  0 0 0 0 0  0 0 0 .05 0"/></filter><rect width="100%" height="100%" filter="url(#n)"/></svg>';
    }

    /** Google Fonts stylesheet for the two chosen families (none when both are "system"). */
    public static function fontsHref(array $tokens): ?string
    {
        $t = $tokens['typography'];
        $families = [];
        foreach ([[$t['headingFont'], $t['headingWeight']], [$t['bodyFont'], $t['bodyWeight']]] as [$font, $weight]) {
            if ($font === 'system') {
                continue;
            }
            // The weights in use plus 600/700 for UI text: only what the page can show is downloaded.
            $families[$font] = array_unique([...($families[$font] ?? []), $weight, 600, 700]);
        }
        if ($families === []) {
            return null;
        }
        $parts = [];
        foreach ($families as $font => $weights) {
            $allowed = array_values(array_intersect($weights, ThemeSchema::FONTS[$font]['weights']));
            sort($allowed);
            $parts[] = 'family='.str_replace(' ', '+', (string) $font).':wght@'.implode(';', $allowed);
        }

        return 'https://fonts.googleapis.com/css2?'.implode('&', $parts).'&display=swap';
    }
}
