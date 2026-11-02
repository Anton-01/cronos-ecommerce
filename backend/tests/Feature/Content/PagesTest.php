<?php

namespace Tests\Feature\Content;

use Cronos\Content\Application\PageService;
use Cronos\Shared\Domain\StorefrontCache;
use Database\Seeders\PricingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\GraphQLTestCase;

final class PagesTest extends GraphQLTestCase
{
    use RefreshDatabase;

    private string $admin;

    /** @var list<array<int, string>> */
    private array $invalidated = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PricingSeeder::class);
        Cache::flush();
        $this->admin = $this->adminToken();
        $this->invalidated = [];
        $spy = new class($this->invalidated) implements StorefrontCache
        {
            public function __construct(private array &$log) {}

            public function invalidate(string ...$tags): void
            {
                $this->log[] = $tags;
            }
        };
        $this->app->instance(StorefrontCache::class, $spy);
    }

    private function create(array $over = []): string
    {
        $r = $this->adminGql('mutation($k: String!, $m: [String!], $t: [PageTranslationInput!]!) { createPage(key: $k, markets: $m, translations: $t) { id status } }', [
            'k' => $over['key'] ?? 'shipping', 'm' => $over['markets'] ?? [],
            't' => $over['translations'] ?? [
                ['locale' => 'es-MX', 'title' => 'Política de envíos', 'bodyMd' => "## Envíos\n\nLlegan en **3 días**."],
                ['locale' => 'en-US', 'title' => 'Shipping', 'slug' => 'shipping', 'bodyMd' => 'We ship in 3 days.'],
            ],
        ], $this->admin);
        $r->assertJsonMissingPath('errors');

        return $r->json('data.createPage.id');
    }

    private function publish(string $id): void
    {
        $this->adminGql('mutation($i: ID!) { publishPage(id: $i) { status } }', ['i' => $id], $this->admin)->assertJsonPath('data.publishPage.status', 'published');
    }

    private function page(string $slug, string $locale = 'es-MX', ?string $market = null): ?array
    {
        return $this->gql('query($l: String, $s: String!, $m: String) { page(locale: $l, slug: $s, market: $m) { key slug title bodyHtml seoTitle alternates { locale slug } } }', ['l' => $locale, 's' => $slug, 'm' => $market])->assertJsonMissingPath('errors')->json('data.page');
    }

    public function test_a_page_is_a_draft_until_published_and_the_slug_comes_from_the_title(): void
    {
        $id = $this->create();
        $this->assertNull($this->page('politica-de-envios'), 'a draft is invisible to the shop');

        $this->publish($id);
        $page = $this->page('politica-de-envios');
        $this->assertSame('Política de envíos', $page['title']);
        $this->assertStringContainsString('<h2>Envíos</h2>', $page['bodyHtml']);
        $this->assertStringContainsString('<strong>3 días</strong>', $page['bodyHtml']);
        $this->assertEqualsCanonicalizing([['locale' => 'es-MX', 'slug' => 'politica-de-envios'], ['locale' => 'en-US', 'slug' => 'shipping']], $page['alternates']);
    }

    public function test_what_an_editor_types_can_never_run_a_script_on_the_shop(): void
    {
        $id = $this->create(['translations' => [['locale' => 'es-MX', 'title' => 'Hola', 'bodyMd' => "<script>alert(1)</script>\n\n[clic](javascript:alert(1))\n\n<img src=x onerror=alert(1)>\n\n[ok](https://example.com)"]]]);
        $this->publish($id);
        $html = $this->page('hola')['bodyHtml'];

        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('javascript:', $html);
        $this->assertStringNotContainsString('onerror', $html);
        $this->assertStringContainsString('href="https://example.com"', $html);
    }

    public function test_a_page_limited_to_a_store_is_not_shown_in_the_other(): void
    {
        $id = $this->create(['key' => 'privacy', 'markets' => ['MX'], 'translations' => [['locale' => 'es-MX', 'title' => 'Aviso de privacidad', 'bodyMd' => 'MX']]]);
        $this->publish($id);

        $this->assertNotNull($this->page('aviso-de-privacidad', 'es-MX', 'MX'));
        $this->assertNull($this->page('aviso-de-privacidad', 'es-MX', 'US'));
        $this->assertNull($this->page('aviso-de-privacidad', 'es-MX', null), 'no store given: only pages for every store');
    }

    public function test_two_pages_cannot_share_an_address_and_keys_are_unique(): void
    {
        $this->create();
        $dup = $this->adminGql('mutation($k: String!, $t: [PageTranslationInput!]!) { createPage(key: $k, translations: $t) { id } }', ['k' => 'other', 't' => [['locale' => 'es-MX', 'title' => 'Política de envíos', 'bodyMd' => 'x']]], $this->admin);
        $dup->assertJsonPath('errors.0.extensions.code', 'SLUG_TAKEN');
        $this->assertArrayHasKey('translations.es-MX.slug', $dup->json('errors.0.extensions.fields'));

        $this->adminGql('mutation($k: String!, $t: [PageTranslationInput!]!) { createPage(key: $k, translations: $t) { id } }', ['k' => 'shipping', 't' => [['locale' => 'en-US', 'title' => 'Another', 'bodyMd' => 'x']]], $this->admin)
            ->assertJsonPath('errors.0.extensions.code', 'PAGE_KEY_TAKEN');
    }

    public function test_reads_come_from_the_cache_until_someone_edits_a_page(): void
    {
        $id = $this->create();
        $this->publish($id);
        $this->page('politica-de-envios'); // fills the cache

        DB::enableQueryLog();
        $this->page('politica-de-envios');
        $this->page('politica-de-envios');
        $pageQueries = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], '"pages"') || str_contains($q['query'], 'page_translations'))->count();
        $this->assertSame(0, $pageQueries, 'repeated reads never touch the pages tables');

        $this->adminGql('mutation($i: ID!, $t: [PageTranslationInput!]) { updatePage(id: $i, translations: $t) { id } }', ['i' => $id, 't' => [['locale' => 'es-MX', 'title' => 'Política de envíos', 'slug' => 'politica-de-envios', 'bodyMd' => 'Ahora llegan en 2 días.']]], $this->admin)->assertJsonMissingPath('errors');
        $this->assertStringContainsString('2 días', $this->page('politica-de-envios')['bodyHtml'], 'an edit shows at once');
    }

    public function test_every_change_tells_the_storefront_to_refresh_and_a_missing_page_is_cached_too(): void
    {
        $id = $this->create();
        $this->publish($id);
        $this->adminGql('mutation($i: ID!) { unpublishPage(id: $i) { status } }', ['i' => $id], $this->admin);
        $this->adminGql('mutation($i: ID!) { deletePage(id: $i) }', ['i' => $id], $this->admin)->assertJsonPath('data.deletePage', true);

        $this->assertCount(4, $this->invalidated);
        $this->assertSame(['content'], $this->invalidated[0]);
        $this->assertNull($this->page('politica-de-envios'));
        $this->assertGreaterThan(1, PageService::version());
    }

    public function test_the_list_of_published_pages_follows_the_order_of_the_back_office(): void
    {
        $a = $this->create(['key' => 'b-page', 'translations' => [['locale' => 'es-MX', 'title' => 'Segunda', 'bodyMd' => 'x']]]);
        $b = $this->create(['key' => 'a-page', 'translations' => [['locale' => 'es-MX', 'title' => 'Primera', 'bodyMd' => 'x']]]);
        $this->adminGql('mutation($i: ID!) { updatePage(id: $i, position: 5) { id } }', ['i' => $a], $this->admin);
        $this->adminGql('mutation($i: ID!) { updatePage(id: $i, position: 1) { id } }', ['i' => $b], $this->admin);
        $this->publish($a);
        $this->publish($b);

        $titles = $this->gql('{ pages(locale: "es-MX") { title } }')->json('data.pages.*.title');
        $this->assertSame(['Primera', 'Segunda'], $titles);
        $this->assertSame([], $this->gql('{ pages(locale: "en-US") { title } }')->json('data.pages'), 'no English text yet');
    }

    public function test_only_people_with_the_permission_can_manage_pages_and_the_sitemap_lists_them(): void
    {
        $support = $this->adminToken('help@example.com', 'support');
        $this->assertNotNull($this->adminGql('{ adminPages { id } }', [], $support)->json('errors'));

        $this->publish($this->create());
        $xml = $this->get('/sitemaps/es-MX-pages.xml')->assertOk()->getContent();
        $this->assertStringContainsString('/es-mx/informacion/politica-de-envios', $xml);
        $this->assertStringContainsString('hreflang="en-US" href="http://localhost:3000/en-us/info/shipping"', $xml);
    }
}
