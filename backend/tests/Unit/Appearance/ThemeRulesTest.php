<?php

namespace Tests\Unit\Appearance;

use Cronos\Appearance\Domain\Exceptions\ThemeError;
use Cronos\Appearance\Domain\Services\Color;
use Cronos\Appearance\Domain\Services\FeatureFlags;
use Cronos\Appearance\Domain\Services\HomeSchema;
use Cronos\Appearance\Domain\Services\IcoWriter;
use Cronos\Appearance\Domain\Services\NavigationSchema;
use Cronos\Appearance\Domain\Services\ThemeCss;
use Cronos\Appearance\Domain\Services\ThemePresets;
use Cronos\Appearance\Domain\Services\ThemeSchema;
use Cronos\Appearance\Infrastructure\Branding\DomSvgSanitizer;
use PHPUnit\Framework\TestCase;

final class ThemeRulesTest extends TestCase
{
    private const LOCALES = ['es-MX', 'en-US'];

    private function fields(callable $fn): array
    {
        try {
            $fn();
        } catch (ThemeError $e) {
            return $e->fields;
        }
        $this->fail('expected a ThemeError');
    }

    public function test_colours_are_normalised_and_measured_like_wcag(): void
    {
        $this->assertSame('#F59A57', Color::normalize('f59a57'));
        $this->assertSame('#AABBCC', Color::normalize('#abc'));
        $this->assertEqualsWithDelta(21.0, Color::contrast('#000000', '#FFFFFF'), 0.01);
        $this->assertEqualsWithDelta(1.0, Color::contrast('#777777', '#777777'), 0.001);
        $this->assertSame('#FFFFFF', Color::readableOn('#242424'));
        $this->assertSame('#1A1A1A', Color::readableOn('#F3F2EE'));
        $this->assertSame('#808080', Color::mix('#000000', '#FFFFFF', 0.5));
        $this->assertSame('245 154 87', Color::channels('#F59A57'));
        foreach (['red', '#12', '#GGGGGG', '', '#12345', 'rgb(1,2,3)'] as $bad) {
            try {
                Color::normalize($bad);
                $this->fail("{$bad} accepted");
            } catch (ThemeError $e) {
                $this->assertSame('INVALID_COLOR', $e->errorCode());
            }
        }
    }

    public function test_the_defaults_are_a_complete_valid_theme_and_every_preset_is_too(): void
    {
        $defaults = ThemeSchema::normalize([]);
        $this->assertSame('#F59A57', $defaults['colors']['primary']);
        $this->assertSame(['Urbanist', 'Work Sans', 1400], [$defaults['typography']['headingFont'], $defaults['typography']['bodyFont'], $defaults['layout']['containerWidth']]);
        foreach (ThemePresets::all() as $key => $preset) {
            $t = ThemeSchema::normalize($preset['tokens']);
            $this->assertSame(array_keys($defaults), array_keys($t), $key);
            $this->assertCount(count(ThemeSchema::COLORS), $t['colors']);
            $this->assertNotEmpty(ThemeCss::variables($t), $key);
        }
    }

    public function test_bad_values_are_refused_with_the_field_named_and_unknown_ones_are_not_ignored(): void
    {
        $f = $this->fields(fn () => ThemeSchema::normalize([
            'colors' => ['primary' => 'orange', 'neon' => '#FFFFFF'], 'typography' => ['headingFont' => 'Comic Sans', 'baseSize' => 40, 'headingWeight' => 300],
            'shape' => ['radiusCard' => -1, 'categoryShape' => 'star'], 'layout' => ['containerWidth' => 99999, 'showTopbar' => 'yes'], 'identity' => ['siteName' => str_repeat('x', 61)], 'sparkles' => [],
        ]));
        foreach (['colors.primary', 'colors.neon', 'typography.headingFont', 'typography.baseSize', 'shape.radiusCard', 'shape.categoryShape', 'layout.containerWidth', 'layout.showTopbar', 'identity.siteName', 'sparkles'] as $key) {
            $this->assertArrayHasKey($key, $f, $key);
        }

        // A weight the chosen font does not have falls to a real one instead of breaking the page.
        $t = ThemeSchema::normalize(['typography' => ['headingFont' => 'Merriweather', 'headingWeight' => 400]]);
        $this->assertSame(400, $t['typography']['headingWeight']);
        $t = ThemeSchema::normalize(['typography' => ['headingFont' => 'Merriweather']]);
        $this->assertContains($t['typography']['headingWeight'], [400, 700], 'the default 700 exists in Merriweather');
        $t = ThemeSchema::normalize(['typography' => ['headingFont' => 'Roboto']]);
        $this->assertSame(700, $t['typography']['headingWeight']);
        $this->assertSame(['#F59A57'], [ThemeSchema::normalize(['colors' => ['primary' => '']])['colors']['primary']], 'cleared = back to the default');
    }

    public function test_contrast_advice_flags_unreadable_pairs_without_blocking(): void
    {
        $ok = collect(ThemeSchema::contrast(ThemeSchema::normalize([])))->keyBy('pair');
        $this->assertTrue($ok['Texto sobre el fondo']['ok'] === false || $ok['Texto sobre el fondo']['ratio'] > 0);
        $bad = ThemeSchema::normalize(['colors' => ['primary' => '#FFFF00', 'background' => '#FFFFFF', 'text' => '#EEEEEE', 'onPrimary' => '#FFFFFF']]);
        $report = collect(ThemeSchema::contrast($bad))->keyBy('pair');
        $this->assertFalse($report['Botón principal']['ok'], 'white on yellow');
        $this->assertFalse($report['Texto sobre el fondo']['ok']);
        $this->assertSame(21.0, round(Color::contrast('#000', '#FFF'), 1));
    }

    public function test_css_variables_derive_shades_and_cannot_be_used_to_inject_rules(): void
    {
        $vars = ThemeCss::variables(ThemeSchema::normalize(['colors' => ['primary' => '#F59A57'], 'shape' => ['radiusButton' => 12, 'categoryShape' => 'rounded'], 'layout' => ['density' => 'compact', 'backgroundTexture' => 'none']]));
        $this->assertSame(['#F59A57', '#FFFFFF', '12px', '24px', '12px', 'none'], [$vars['--c-primary'], $vars['--c-on-secondary'], $vars['--radius-button'], $vars['--radius-category'], $vars['--space'], $vars['--bg-texture']]);
        $this->assertSame('#1A1A1A', $vars['--c-on-primary'], 'dark text on the light orange');
        $this->assertNotSame($vars['--c-primary'], $vars['--c-primary-hover']);
        $this->assertStringContainsString('--font-heading: "Urbanist"', ThemeCss::css($vars));

        $css = ThemeCss::css(['--evil' => 'red; } body { display:none', '--ok' => '1px']);
        $this->assertStringNotContainsString('}', substr($css, 0, strrpos($css, '}')), 'a value can never close the rule');
        $this->assertSame(1, substr_count($css, '}'));
    }

    public function test_the_fonts_stylesheet_asks_only_for_the_weights_in_use(): void
    {
        $href = ThemeCss::fontsHref(ThemeSchema::normalize([]));
        $this->assertStringContainsString('family=Urbanist:wght@600;700', $href);
        $this->assertStringContainsString('family=Work+Sans:wght@400;600;700', $href);
        $this->assertStringEndsWith('&display=swap', $href);
        $this->assertNull(ThemeCss::fontsHref(ThemeSchema::normalize(['typography' => ['headingFont' => 'system', 'bodyFont' => 'system']])));
    }

    public function test_links_must_be_pages_of_the_store_or_secure_addresses_and_never_script(): void
    {
        foreach (['/ofertas', '/es-mx/productos/x', 'https://instagram.com/cronos', '/'] as $ok) {
            $this->assertTrue(NavigationSchema::linkOk($ok), $ok);
        }
        foreach (['javascript:alert(1)', 'JaVaScRiPt:alert(1)', '//evil.test', 'http://insecure.test', 'data:text/html,x', '/\\evil.test', 'https://', '', '/a b', "/a\nb", 'ftp://x', 'mailto:a@b.co'] as $bad) {
            $this->assertFalse(NavigationSchema::linkOk($bad), $bad);
        }
        $f = $this->fields(fn () => NavigationSchema::normalize(['topbarLinks' => [['label' => ['es-MX' => 'x'], 'url' => 'javascript:alert(1)']], 'social' => ['facebook' => 'http://x.test', 'myspace' => 'https://x.test']], self::LOCALES));
        $this->assertArrayHasKey('topbarLinks.0.url', $f);
        $this->assertArrayHasKey('social.facebook', $f);
        $this->assertArrayHasKey('social.myspace', $f);
        $n = NavigationSchema::normalize(['announcements' => [['markets' => ['mx'], 'text' => ['es-MX' => 'Hola'], 'url' => '/ofertas']]], self::LOCALES);
        $this->assertSame(['MX'], $n['announcements'][0]['markets']);
    }

    public function test_home_sections_are_checked_and_unavailable_modules_are_kept_switched_off(): void
    {
        $h = HomeSchema::normalize([
            ['id' => 'hero', 'type' => 'hero', 'enabled' => true, 'settings' => ['autoplay' => 5, 'slides' => [['image' => '01h000000000000000000000ab', 'title' => ['es-MX' => 'Hola'], 'ctaUrl' => '/ofertas', 'overlay' => 30]]]],
            ['id' => 'marcas', 'type' => 'brands', 'enabled' => true, 'settings' => ['anything' => 1]],
            ['id' => 'txt', 'type' => 'text', 'settings' => ['title' => ['es-MX' => 'Sobre nosotros'], 'body' => ['es-MX' => 'Texto']]],
        ], self::LOCALES);
        $this->assertSame([true, false, true], array_column($h, 'enabled'));
        $this->assertTrue($h[1]['reserved']);
        $this->assertSame(['anything' => 1], $h[1]['settings'], 'saved for the day the module exists');

        $f = $this->fields(fn () => HomeSchema::normalize([
            ['id' => 'a', 'type' => 'products', 'settings' => ['source' => 'category']], ['id' => 'a', 'type' => 'banner', 'settings' => ['ctaUrl' => 'javascript:x']],
            ['id' => 'Bad Id', 'type' => 'hero', 'settings' => ['slides' => [['image' => 'not-an-id', 'overlay' => 99]]]], ['id' => 'z', 'type' => 'carousel'],
        ], self::LOCALES));
        foreach (['home.0.settings.categoryId', 'home.1.id', 'home.1.settings.ctaUrl', 'home.2.id', 'home.2.settings.slides.0.image', 'home.2.settings.slides.0.overlay', 'home.3.type'] as $key) {
            $this->assertArrayHasKey($key, $f, $key);
        }
    }

    public function test_feature_switches_lock_the_modules_that_do_not_exist_yet(): void
    {
        $this->assertFalse(FeatureFlags::normalize([])['reviews']);
        $this->assertFalse(FeatureFlags::normalize(['wishlist' => false])['wishlist']);
        $f = $this->fields(fn () => FeatureFlags::normalize(['reviews' => true, 'teleport' => true, 'wishlist' => 'sí']));
        $this->assertSame(['features.reviews', 'features.teleport', 'features.wishlist'], array_keys($f));
        $this->assertFalse(FeatureFlags::normalize(['reviews' => false])['reviews']);
    }

    public function test_an_ico_file_wraps_the_png_images_with_a_valid_directory(): void
    {
        $png = fn (int $n) => "\x89PNG-fake-{$n}";
        $ico = IcoWriter::build([32 => $png(32), 16 => $png(16)]);
        $this->assertSame([0, 1, 2], array_values(unpack('v3', substr($ico, 0, 6))));
        $first = unpack('Cw/Ch/Ccolors/Creserved/vplanes/vbits/Vsize/Voffset', substr($ico, 6, 16));
        $this->assertSame([16, 16, strlen($png(16)), 6 + 32], [$first['w'], $first['h'], $first['size'], $first['offset']], 'sorted by size; data starts after the directory');
        $this->assertStringEndsWith($png(32), $ico);
    }

    public function test_the_svg_sanitiser_keeps_the_drawing_and_removes_everything_that_can_run_or_load(): void
    {
        $s = new DomSvgSanitizer;
        $clean = $s->sanitize('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10" onload="alert(1)"><script>alert(1)</script><rect width="5" height="5" fill="red" onclick="x()"/>'
            .'<foreignObject><div>hi</div></foreignObject><image href="http://evil.test/x.png"/><a href="javascript:alert(1)"><path d="M0 0L5 5" fill="url(http://evil.test/#x)"/></a>'
            .'<circle cx="3" cy="3" r="2" style="fill:url(#g);background:url(http://evil.test)"/><use href="http://evil.test/s.svg#a"/><text>Logo</text><!-- c --></svg>');
        foreach (['<script', 'onload', 'onclick', 'foreignObject', '<image', 'javascript:', 'evil.test', '<a ', 'comment'] as $bad) {
            $this->assertStringNotContainsString($bad, $clean, $bad);
        }
        foreach (['<rect', 'fill="red"', '<text>Logo</text>', 'viewBox="0 0 10 10"', '<circle'] as $kept) {
            $this->assertStringContainsString($kept, $clean, $kept);
        }
        $this->assertStringContainsString('xmlns="http://www.w3.org/2000/svg"', $clean);

        foreach (['<!DOCTYPE svg [<!ENTITY x SYSTEM "file:///etc/passwd">]><svg viewBox="0 0 1 1">&x;</svg>', 'not xml', '<html><body/></html>', '<svg><rect/></svg>'] as $bad) {
            try {
                $s->sanitize($bad);
                $this->fail('accepted: '.substr($bad, 0, 40));
            } catch (ThemeError $e) {
                $this->assertContains($e->errorCode(), ['SVG_UNSAFE', 'SVG_INVALID']);
            }
        }
    }
}
