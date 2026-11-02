<?php

namespace Tests\Feature\Appearance;

use Cronos\Markets\Application\MarketProfileManager;
use Database\Seeders\PricingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\GraphQLTestCase;

final class ThemeApiTest extends GraphQLTestCase
{
    use RefreshDatabase;

    private const PUBLIC_Q = 'query($l: String, $m: String, $p: String) { storefrontTheme(locale: $l, market: $m, preview: $p) {
        version siteName tagline themeColor css cssVariables fontsHref assets navigation home features manifestUrl tokens } }';

    private string $admin;

    private string $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PricingSeeder::class);
        Storage::fake('media');
        Cache::flush();
        $this->admin = $this->adminToken();
        $this->owner = $this->adminToken('owner@example.com', 'super-admin');
    }

    private function theme(array $vars = []): array
    {
        return $this->gql(self::PUBLIC_Q, $vars + ['l' => 'es-MX', 'm' => null, 'p' => null])->assertJsonMissingPath('errors')->json('data.storefrontTheme');
    }

    private function save(array $vars, ?string $token = null): TestResponse
    {
        return $this->adminGql('mutation($t: JSON, $n: JSON, $h: JSON, $f: JSON) { saveThemeDraft(tokens: $t, navigation: $n, home: $h, features: $f) { number status tokens navigation home features contrast { pair ok } cssVariables } }',
            ['t' => $vars['tokens'] ?? null, 'n' => $vars['navigation'] ?? null, 'h' => $vars['home'] ?? null, 'f' => $vars['features'] ?? null], $token ?? $this->admin);
    }

    private function publish(?string $notes = null): TestResponse
    {
        return $this->adminGql('mutation($n: String) { publishTheme(notes: $n) { number status } }', ['n' => $notes], $this->owner);
    }

    private function png(int $w, int $h, array $rgb = [200, 30, 30]): string
    {
        $im = imagecreatetruecolor($w, $h);
        imagealphablending($im, false);
        imagesavealpha($im, true);
        imagefill($im, 0, 0, (int) imagecolorallocatealpha($im, 0, 0, 0, 127));
        imagefilledellipse($im, intdiv($w, 2), intdiv($h, 2), (int) ($w * 0.8), (int) ($h * 0.8), (int) imagecolorallocate($im, ...$rgb));
        ob_start();
        imagepng($im);

        return (string) ob_get_clean();
    }

    private function upload(string $slot, UploadedFile $file, ?array $crop = null, ?string $token = null): TestResponse
    {
        return $this->gqlUpload('mutation($s: ThemeAssetSlot!, $file: Upload!, $c: CropInput) { uploadThemeAsset(slot: $s, file: $file, crop: $c) { number assets } }', ['s' => $slot, 'c' => $crop], ['variables.file' => $file], $token ?? $this->admin);
    }

    public function test_a_fresh_install_already_has_a_complete_storefront_theme_with_the_reference_palette(): void
    {
        $t = $this->theme();
        $this->assertSame([0, 'Cronos'], [$t['version'], $t['siteName']]);
        $this->assertSame('#F59A57', $t['cssVariables']['--c-primary']);
        $this->assertStringStartsWith(":root {\n", $t['css']);
        $this->assertStringContainsString('--container: 1400px;', $t['css']);
        $this->assertSame(['wishlist' => true, 'reviews' => false], array_intersect_key($t['features'], ['wishlist' => 1, 'reviews' => 1]));
        $this->assertFalse($t['features']['brands'], 'reserved modules stay off');
        $this->assertSame('#F59A57', $t['themeColor']);
        $this->assertStringEndsWith('/manifest.webmanifest', $t['manifestUrl']);
        $this->assertNotEmpty(array_filter($t['home'], fn ($s) => $s['type'] === 'categories'));
        $this->assertSame('Nuestras categorías', collect($t['home'])->firstWhere('type', 'categories')['settings']['title'], 'texts already in the visitor\'s language');
        $this->assertSame('Our categories', $this->theme(['l' => 'en-US'])['home'][1]['settings']['title']);
    }

    public function test_changes_go_to_a_draft_and_the_live_look_only_changes_when_it_is_published(): void
    {
        $r = $this->save(['tokens' => ['colors' => ['primary' => '#2F6B4F'], 'identity' => ['siteName' => 'Moños Lupita']]])->assertJsonMissingPath('errors');
        $this->assertSame(['draft', '#2F6B4F'], [$r->json('data.saveThemeDraft.status'), $r->json('data.saveThemeDraft.tokens.colors.primary')]);
        $this->assertSame('#2F6B4F', $r->json('data.saveThemeDraft.cssVariables.--c-primary'));
        $this->assertSame('#F59A57', $this->theme()['cssVariables']['--c-primary'], 'the visitors still see the live look');

        $this->save(['tokens' => ['shape' => ['radiusButton' => 8]]]); // a second save keeps the first
        $draft = $this->adminGql('{ adminTheme { draft { tokens } hasUnpublishedChanges versions { status } } }', [], $this->admin)->json('data.adminTheme');
        $this->assertSame(['#2F6B4F', 8, true], [$draft['draft']['tokens']['colors']['primary'], $draft['draft']['tokens']['shape']['radiusButton'], $draft['hasUnpublishedChanges']]);

        $this->publish('Paleta verde')->assertJsonPath('data.publishTheme.status', 'published');
        $live = $this->theme();
        $this->assertSame([1, '#2F6B4F', 'Moños Lupita'], [$live['version'], $live['cssVariables']['--c-primary'], $live['siteName']]);
        $this->assertFalse($this->adminGql('{ adminTheme { hasUnpublishedChanges } }', [], $this->admin)->json('data.adminTheme.hasUnpublishedChanges'));
        $this->assertSame(1, DB::table('theme_versions')->where('status', 'published')->count());
        $this->assertSame('Paleta verde', DB::table('theme_versions')->where('status', 'published')->value('notes'));
    }

    public function test_invalid_themes_are_refused_naming_every_bad_field(): void
    {
        $r = $this->save(['tokens' => ['colors' => ['primary' => 'orange'], 'typography' => ['baseSize' => 99]], 'navigation' => ['topbarLinks' => [['label' => ['es-MX' => 'x'], 'url' => 'javascript:alert(1)']]], 'features' => ['reviews' => true]]);
        $r->assertJsonPath('errors.0.extensions.code', 'INVALID_THEME');
        $fields = $r->json('errors.0.extensions.fields');
        $this->assertArrayHasKey('colors.primary', $fields);
        $this->assertSame(0, DB::table('theme_versions')->count(), 'a refused save leaves nothing behind');
        $this->save(['features' => ['reviews' => true]])->assertJsonPath('errors.0.extensions.fields', ['features.reviews' => 'Valoraciones de clientes todavía no está disponible.']);
        $this->save(['navigation' => ['topbarLinks' => [['label' => ['es-MX' => 'x'], 'url' => 'javascript:alert(1)']]]])->assertJsonPath('errors.0.extensions.fields', ['topbarLinks.0.url' => 'Debe empezar con / (página de la tienda) o https://']);
    }

    public function test_permissions_separate_looking_from_editing_from_going_live(): void
    {
        $support = $this->adminToken('help@example.com', 'support');
        $this->adminGql('{ adminTheme { hasUnpublishedChanges } }', [], $support)->assertJsonPath('errors.0.message', fn ($m) => str_contains($m, 'unauthorized'));
        $this->save(['tokens' => ['colors' => ['primary' => '#112233']]], $support)->assertJsonPath('errors.0.message', fn ($m) => str_contains($m, 'unauthorized'));

        $this->save(['tokens' => ['colors' => ['primary' => '#112233']]]); // an admin may edit…
        $this->publish()->assertJsonMissingPath('errors');
        $this->adminGql('mutation { publishTheme { number } }', [], $this->admin)->assertJsonPath('errors.0.message', fn ($m) => str_contains($m, 'unauthorized')); // …only the owner publishes
    }

    public function test_history_restore_and_a_draft_that_is_identical_cannot_be_published(): void
    {
        $this->save(['tokens' => ['colors' => ['primary' => '#111111']]]);
        $this->publish('v1');
        $this->save(['tokens' => ['colors' => ['primary' => '#222222']]]);
        $this->publish('v2');
        $this->assertSame([2, '#222222'], [$this->theme()['version'], $this->theme()['cssVariables']['--c-primary']]);

        $versions = $this->adminGql('{ adminTheme { versions { id number status notes } } }', [], $this->admin)->json('data.adminTheme.versions');
        $this->assertSame([[2, 'published'], [1, 'archived']], array_map(fn ($v) => [$v['number'], $v['status']], array_values(array_filter($versions, fn ($v) => $v['status'] !== 'draft'))));

        $first = collect($versions)->firstWhere('number', 1)['id'];
        $this->adminGql('mutation($id: ID!) { restoreThemeVersion(id: $id) { status tokens } }', ['id' => $first], $this->admin)->assertJsonPath('data.restoreThemeVersion.tokens.colors.primary', '#111111');
        $this->assertSame('#222222', $this->theme()['cssVariables']['--c-primary'], 'restoring only prepares the draft');
        $this->publish('back to v1');
        $this->assertSame([3, '#111111'], [$this->theme()['version'], $this->theme()['cssVariables']['--c-primary']]);

        $this->publish()->assertJsonPath('errors.0.extensions.code', 'NO_DRAFT');
        $this->adminGql('mutation { resetThemeDraft { number } }', [], $this->admin);
        $this->adminGql('mutation { discardThemeDraft }', [], $this->admin);
        $this->save(['tokens' => ['colors' => ['primary' => '#111111']]]);
        $this->publish()->assertJsonPath('errors.0.extensions.code', 'NOTHING_TO_PUBLISH');
    }

    public function test_a_preview_link_shows_the_draft_for_a_while_and_a_bad_token_shows_the_live_look(): void
    {
        $this->save(['tokens' => ['colors' => ['primary' => '#AA0000']]]);
        $link = $this->adminGql('mutation { themePreviewLink }', [], $this->admin)->json('data.themePreviewLink');
        $this->assertStringContainsString('?theme_preview=', $link);
        $token = urldecode(explode('theme_preview=', $link)[1]);

        $this->assertSame('#AA0000', $this->theme(['p' => $token])['cssVariables']['--c-primary']);
        $this->assertSame('#F59A57', $this->theme(['p' => 'garbage'])['cssVariables']['--c-primary']);
        $this->assertSame('#F59A57', $this->theme()['cssVariables']['--c-primary']);

        $this->travel(2)->hours();
        $this->assertSame('#F59A57', $this->theme(['p' => $token])['cssVariables']['--c-primary'], 'the link expired');
    }

    public function test_presets_start_a_new_look_without_touching_menus_or_home(): void
    {
        $this->save(['navigation' => ['footerNote' => ['es-MX' => 'Hecho en México']]]);
        $r = $this->adminGql('mutation { applyThemePreset(key: "oscuro") { tokens navigation } }', [], $this->admin)->assertJsonMissingPath('errors');
        $this->assertSame(['#D4A857', 'Cormorant Garamond', 'Hecho en México'], [$r->json('data.applyThemePreset.tokens.colors.primary'), $r->json('data.applyThemePreset.tokens.typography.headingFont'), $r->json('data.applyThemePreset.navigation.footerNote.es-MX')]);
        $this->adminGql('mutation { applyThemePreset(key: "nope") { number } }', [], $this->admin)->assertJsonPath('errors.0.extensions.code', 'UNKNOWN_PRESET');

        $state = $this->adminGql('{ adminTheme { presets { key tokens } fonts { family weights } features { key reserved } homeSectionTypes { type reserved } } }', [], $this->admin)->json('data.adminTheme');
        $this->assertSame(['calido', 'neutro', 'bosque', 'oceano', 'oscuro'], array_column($state['presets'], 'key'));
        $this->assertContains('Urbanist', array_column($state['fonts'], 'family'));
        $this->assertTrue(collect($state['features'])->firstWhere('key', 'blog')['reserved']);
        $this->assertTrue(collect($state['homeSectionTypes'])->firstWhere('type', 'brands')['reserved']);
    }

    public function test_menus_texts_and_the_free_shipping_ribbon_follow_the_language_and_the_store(): void
    {
        app(MarketProfileManager::class)->update('MX', ['shipping_flat' => '150', 'shipping_free_over' => '1500', 'trade_name' => 'Cronos México']);
        app(MarketProfileManager::class)->update('US', ['shipping_flat' => '12', 'shipping_free_over' => '100']);
        $this->save(['navigation' => [
            'topbarLinks' => [['label' => ['es-MX' => 'Sucursales', 'en-US' => 'Showrooms'], 'url' => '/showrooms']],
            'social' => ['instagram' => 'https://instagram.com/cronos'],
            'announcements' => [['markets' => ['US'], 'text' => ['es-MX' => 'Solo EEUU', 'en-US' => 'US only'], 'url' => '/sale'], ['markets' => [], 'text' => ['es-MX' => 'Para todos']]],
            'footerColumns' => [['title' => ['es-MX' => 'Ayuda', 'en-US' => 'Help'], 'links' => [['label' => ['es-MX' => 'Contacto', 'en-US' => 'Contact'], 'url' => '/contact']]]],
        ]]);
        $this->publish();

        $mx = $this->theme(['m' => 'MX']);
        $this->assertSame('Cronos México', $mx['siteName'], 'the store\'s own trade name');
        $this->assertSame(['Sucursales', 'Ayuda'], [$mx['navigation']['topbarLinks'][0]['label'], $mx['navigation']['footerColumns'][0]['title']]);
        $this->assertSame(['free_shipping', 'text'], array_column($mx['navigation']['announcements'], 'type'));
        $this->assertStringContainsString('1,500', $mx['navigation']['announcements'][0]['text']);
        $this->assertStringStartsWith('Envío gratis en pedidos desde', $mx['navigation']['announcements'][0]['text']);

        $us = $this->theme(['m' => 'US', 'l' => 'en-US']);
        $this->assertSame(['free_shipping', 'text', 'text'], array_column($us['navigation']['announcements'], 'type'), 'the US-only notice appears only in the US store');
        $this->assertSame('Free shipping on orders over $100.00', $us['navigation']['announcements'][0]['text']);
        $this->assertSame('US only', $us['navigation']['announcements'][1]['text']);
        $this->assertSame('Showrooms', $us['navigation']['topbarLinks'][0]['label']);

        $this->save(['navigation' => ['showFreeShippingRibbon' => false]]);
        $this->publish();
        $this->assertSame(['text'], array_column($this->theme(['m' => 'MX'])['navigation']['announcements'], 'type'));
    }

    public function test_a_png_logo_is_cropped_scaled_to_the_sizes_the_store_draws_and_named_by_its_content(): void
    {
        $up = $this->upload('LOGO', UploadedFile::fake()->createWithContent('logo.png', $this->png(1200, 400)), ['x' => 0, 'y' => 0, 'width' => 800, 'height' => 400, 'trim' => true])->assertJsonMissingPath('errors');
        $logo = $up->json('data.uploadThemeAsset.assets.logo');
        $this->assertSame('raster', $logo['kind']);
        $files = collect($logo['files']);
        $this->assertSame([64, 128, 128], $files->pluck('height')->all());
        $this->assertSame(['webp', 'webp', 'png'], $files->pluck('format')->all());
        $this->assertLessThanOrEqual(64, $files[0]['height']);
        $this->assertStringContainsString($logo['hash'], $files[0]['url'], 'the address carries a hash of the content');
        Storage::disk('media')->assertExists('branding/logo-'.$logo['hash'].'-2x.webp');
        $this->assertStringContainsString(' 1x, ', $logo['srcset']);

        $transparent = imagecreatefromstring(Storage::disk('media')->get('branding/logo-'.$logo['hash'].'.png'));
        $this->assertSame(127, (imagecolorat($transparent, 1, 1) >> 24) & 0x7F, 'transparency survives');
        $this->assertSame('#2F6B4F', $this->save(['tokens' => ['colors' => ['primary' => '#2F6B4F']]])->json('data.saveThemeDraft.tokens.colors.primary'));

        $this->assertNull($this->theme()['assets']['logo'] ?? null, 'a logo in the draft is not live until published');
        $this->publish();
        $this->assertSame($logo['hash'], $this->theme()['assets']['logo']['hash']);
    }

    public function test_a_bad_crop_or_a_tiny_logo_is_refused_instead_of_producing_a_blurry_one(): void
    {
        $png = UploadedFile::fake()->createWithContent('logo.png', $this->png(600, 200));
        $this->upload('LOGO', $png, ['x' => 500, 'y' => 0, 'width' => 400, 'height' => 100])->assertJsonPath('errors.0.extensions.code', 'INVALID_CROP');
        $this->upload('LOGO', UploadedFile::fake()->createWithContent('l.png', $this->png(600, 200)), ['x' => 0, 'y' => 0, 'width' => 60, 'height' => 20])->assertJsonPath('errors.0.extensions.code', 'CROP_TOO_SMALL');
        $this->upload('LOGO', UploadedFile::fake()->createWithContent('l.png', $this->png(100, 30)))->assertJsonPath('errors.0.extensions.code', 'INVALID_IMAGE');
        $this->upload('LOGO', UploadedFile::fake()->createWithContent('l.png', $this->png(600, 200)), ['x' => 0, 'y' => 0, 'width' => 600, 'height' => 200, 'rotate' => 45])->assertJsonPath('errors.0.extensions.code', 'INVALID_CROP');
    }

    public function test_one_square_picture_becomes_every_favicon_size_the_ico_and_the_manifest(): void
    {
        $up = $this->upload('FAVICON', UploadedFile::fake()->createWithContent('icon.png', $this->png(800, 800)), ['x' => 0, 'y' => 0, 'width' => 800, 'height' => 800])->assertJsonMissingPath('errors');
        $files = collect($up->json('data.uploadThemeAsset.assets.favicon.files'));
        $this->assertSame([16, 32, 48, 180, 192, 512, 48], $files->pluck('size')->all());
        $this->assertSame(['png', 'png', 'png', 'png', 'png', 'png', 'ico'], $files->pluck('format')->all());
        $ico = $files->firstWhere('format', 'ico');
        $path = 'branding/'.basename($ico['url']);
        $bytes = Storage::disk('media')->get($path);
        $this->assertSame([0, 1, 3], array_values(unpack('v3', substr($bytes, 0, 6))), 'an .ico with 16, 32 and 48');
        $this->assertSame([512, 512], array_slice(getimagesizefromstring(Storage::disk('media')->get('branding/'.basename($files->firstWhere('size', 512)['url']))), 0, 2));

        $this->publish();
        $this->get('/favicon.ico')->assertRedirect($ico['url']);
        $m = $this->get('/manifest.webmanifest')->assertOk()->assertHeader('Content-Type', 'application/manifest+json')->json();
        $this->assertSame(['Cronos', '#F59A57', ['192x192', '512x512']], [$m['name'], $m['theme_color'], array_column($m['icons'], 'sizes')]);
    }

    public function test_an_svg_logo_is_accepted_only_after_being_sanitised(): void
    {
        $evil = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 40" onload="alert(1)"><script>alert(1)</script><rect width="100" height="40" fill="#222"/><text x="5" y="25" fill="#fff">Lupita</text></svg>';
        $up = $this->upload('LOGO_DARK', UploadedFile::fake()->createWithContent('logo.svg', $evil))->assertJsonMissingPath('errors');
        $asset = $up->json('data.uploadThemeAsset.assets.logoDark');
        $this->assertSame('svg', $asset['kind']);
        $stored = Storage::disk('media')->get('branding/'.basename($asset['url']));
        $this->assertStringNotContainsString('script', $stored);
        $this->assertStringNotContainsString('onload', $stored);
        $this->assertStringContainsString('Lupita', $stored);

        $this->upload('FAVICON', UploadedFile::fake()->createWithContent('icon.svg', $evil))->assertJsonPath('errors.0.extensions.code', 'SVG_NOT_ALLOWED');
        $this->upload('LOGO', UploadedFile::fake()->createWithContent('x.svg', '<svg xmlns="http://www.w3.org/2000/svg"><rect/></svg>'))->assertJsonPath('errors.0.extensions.code', 'SVG_INVALID');
        $this->adminGql('mutation { removeThemeAsset(slot: LOGO_DARK) { assets } }', [], $this->admin)->assertJsonPath('data.removeThemeAsset.assets', []);
    }

    public function test_home_pictures_come_back_resolved_and_a_missing_picture_cannot_be_saved(): void
    {
        $mutation = 'mutation($file: Upload!) { uploadMedia(file: $file, slot: HERO) { id } }';
        $img = UploadedFile::fake()->image('hero.jpg', 2560, 1067);
        $id = $this->gqlUpload($mutation, [], ['variables.file' => $img], $this->admin, '/admin/graphql')->json('data.uploadMedia.id');

        $home = [['id' => 'hero', 'type' => 'hero', 'enabled' => true, 'settings' => ['autoplay' => 4, 'slides' => [['image' => $id, 'title' => ['es-MX' => 'Hola', 'en-US' => 'Hello'], 'ctaLabel' => ['es-MX' => 'Ver'], 'ctaUrl' => '/ofertas']]]],
            ['id' => 'brands', 'type' => 'brands', 'enabled' => true, 'settings' => []]];
        $this->save(['home' => $home])->assertJsonMissingPath('errors');
        $this->publish();

        $live = $this->theme()['home'];
        $this->assertCount(1, $live, 'the reserved section is stored but never delivered');
        $slide = $live[0]['settings']['slides'][0];
        $this->assertSame(['Hola', 'Ver'], [$slide['title'], $slide['ctaLabel']]);
        $this->assertSame(2560, $slide['image']['width']);
        $this->assertStringContainsString('2560w', $slide['image']['srcset']);
        $this->assertSame('Hello', $this->theme(['l' => 'en-US'])['home'][0]['settings']['slides'][0]['title']);

        $home[0]['settings']['slides'][0]['image'] = '01h00000000000000000000000';
        $this->save(['home' => $home])->assertJsonPath('errors.0.extensions.code', 'MEDIA_NOT_FOUND');
    }
}
