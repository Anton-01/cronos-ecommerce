<?php

namespace Tests\Feature\Reports;

use Cronos\Markets\Application\MarketProfileManager;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Orders\OrdersTestCase;

final class SeoTest extends OrdersTestCase
{
    private const Q = 'query($t: SeoTarget!, $l: String!, $s: String, $m: String) { seo(target: $t, locale: $l, slug: $s, market: $m) {
        title description canonical robots alternates { hreflang href } openGraph { type title url image siteName locale alternateLocales priceAmount priceCurrency availability }
        twitter { card } jsonLd } }';

    private function seo(string $target, string $locale, ?string $slug = null, ?string $market = null): ?array
    {
        return $this->gql(self::Q, ['t' => $target, 'l' => $locale, 's' => $slug, 'm' => $market])->assertJsonMissingPath('errors')->json('data.seo');
    }

    private function product(array $extra = []): string
    {
        return $this->published($this->products()->create(['type' => 'simple', 'sku' => 'LIS-1', 'price' => '100.00', 'translations' => [
            'es-MX' => ['name' => 'Listón de satén rojo', 'short_description' => '<p>Ideal   para <b>moños</b> y regalos.</p>', 'meta_title' => 'Listón rojo de satén'],
            'en-US' => ['name' => 'Red satin ribbon', 'meta_description' => 'Soft red satin ribbon for bows and gifts.'],
        ]] + $extra));
    }

    public function test_a_product_page_gets_its_title_canonical_hreflang_and_social_tags(): void
    {
        $id = $this->stocked($this->product(), 10);
        config(['app.storefront_url' => 'https://tienda.test']);
        DB::table('product_translations')->where(['product_id' => $id, 'locale' => 'es-MX'])->update(['slug' => 'liston-rojo']);
        DB::table('product_translations')->where(['product_id' => $id, 'locale' => 'en-US'])->update(['slug' => 'red-ribbon']);

        $es = $this->seo('PRODUCT', 'es-MX', 'liston-rojo', 'MX');
        $this->assertSame(['Listón rojo de satén | Cronos', 'Ideal para moños y regalos.', 'https://tienda.test/es-mx/productos/liston-rojo', 'index,follow'], [$es['title'], $es['description'], $es['canonical'], $es['robots']]);
        $this->assertSame([
            ['hreflang' => 'es-MX', 'href' => 'https://tienda.test/es-mx/productos/liston-rojo'],
            ['hreflang' => 'en-US', 'href' => 'https://tienda.test/en-us/products/red-ribbon'],
            ['hreflang' => 'x-default', 'href' => 'https://tienda.test/es-mx/productos/liston-rojo'],
        ], $es['alternates']);
        $this->assertSame(['product', 'es_MX', ['en_US'], 'Cronos', '100.00', 'MXN', 'instock', 'summary'], [$es['openGraph']['type'], $es['openGraph']['locale'], $es['openGraph']['alternateLocales'], $es['openGraph']['siteName'], $es['openGraph']['priceAmount'], $es['openGraph']['priceCurrency'], $es['openGraph']['availability'], $es['twitter']['card']]);

        $en = $this->seo('PRODUCT', 'en-US', 'red-ribbon', 'US');
        $this->assertSame(['Red satin ribbon | Cronos', 'Soft red satin ribbon for bows and gifts.', 'https://tienda.test/en-us/products/red-ribbon'], [$en['title'], $en['description'], $en['canonical']]);
        $this->assertSame(['USD', 'oos'], [$en['openGraph']['priceCurrency'], $en['openGraph']['availability']], 'the stock is in the Mexican warehouse: the US store has none');

        $viaOtherSlug = $this->seo('PRODUCT', 'en-US', 'liston-rojo');
        $this->assertSame('https://tienda.test/en-us/products/red-ribbon', $viaOtherSlug['canonical'], 'a URL in the wrong language canonicalises to the right one');
    }

    public function test_schema_org_documents_describe_the_product_the_offer_and_the_trail_and_cannot_break_out_of_the_script_tag(): void
    {
        $id = $this->stocked($this->product(), 10);
        $this->products()->create(['type' => 'simple', 'sku' => 'XSS-1', 'price' => '10.00', 'translations' => ['es-MX' => ['name' => '</script><script>alert(1)</script>']]]);
        $xss = (string) DB::table('products')->where('sku', 'XSS-1')->value('id');
        $this->published($xss);
        $cat = $this->category('Listones');
        DB::table('product_categories')->insert(['product_id' => $id, 'category_id' => $cat, 'is_primary' => true]);
        app(MarketProfileManager::class)->update('MX', ['legal_name' => 'Listones Cronos SA de CV']);
        $slug = DB::table('product_translations')->where(['product_id' => $id, 'locale' => 'es-MX'])->value('slug');

        $docs = array_map(fn (string $j) => json_decode($j, true, 512, JSON_THROW_ON_ERROR), $this->seo('PRODUCT', 'es-MX', $slug, 'MX')['jsonLd']);
        $product = collect($docs)->firstWhere('@type', 'Product');
        $this->assertSame(['Listón de satén rojo', 'LIS-1', 'Offer', '100.00', 'MXN', 'https://schema.org/InStock', 'Listones Cronos SA de CV'],
            [$product['name'], $product['sku'], $product['offers']['@type'], $product['offers']['price'], $product['offers']['priceCurrency'], $product['offers']['availability'], $product['offers']['seller']['name']]);
        $crumbs = collect($docs)->firstWhere('@type', 'BreadcrumbList')['itemListElement'];
        $this->assertSame([1, 2, 3], array_column($crumbs, 'position'));
        $this->assertSame('Listones', $crumbs[1]['name']);

        $raw = $this->seo('PRODUCT', 'es-MX', DB::table('product_translations')->where('product_id', $xss)->value('slug'), 'MX')['jsonLd'];
        $this->assertStringNotContainsString('</script>', implode('', $raw));
        $this->assertSame('</script><script>alert(1)</script>', json_decode($raw[0], true)['name'], 'still the exact name once parsed');
    }

    public function test_pages_that_do_not_exist_or_are_not_published_have_no_metadata(): void
    {
        $draft = $this->products()->create(['type' => 'simple', 'sku' => 'DRAFT', 'price' => '1.00', 'translations' => ['es-MX' => ['name' => 'Borrador']]]);
        $slug = DB::table('product_translations')->where('product_id', $draft)->value('slug');

        $this->assertNull($this->seo('PRODUCT', 'es-MX', $slug));
        $this->assertNull($this->seo('PRODUCT', 'es-MX', 'no-existe'));
        $this->assertNull($this->seo('CATEGORY', 'es-MX', 'no-existe'));
        $this->gql(self::Q, ['t' => 'PRODUCT', 'l' => 'fr-FR', 's' => 'x'])->assertJsonPath('errors.0.message', fn ($m) => str_contains($m, 'Unsupported locale'));
        $this->gql(self::Q, ['t' => 'PRODUCT', 'l' => 'es-MX'])->assertJsonPath('errors.0.message', fn ($m) => str_contains($m, 'needs its slug'));
    }

    public function test_the_home_and_a_category_have_their_own_tags_and_the_organization_comes_from_the_store_profile(): void
    {
        config(['app.storefront_url' => 'https://tienda.test']);
        app(MarketProfileManager::class)->update('MX', ['trade_name' => 'Cronos Listones', 'legal_name' => 'Listones Cronos SA de CV', 'contact_phone' => '+52 55 0000 0000']);
        $cat = $this->category('Listones');
        $slug = DB::table('category_translations')->where(['category_id' => $cat, 'locale' => 'es-MX'])->value('slug');

        $home = $this->seo('HOME', 'es-MX');
        $this->assertSame(['https://tienda.test/es-mx', 'Cronos Listones'], [$home['canonical'], $home['openGraph']['siteName'] ?? $home['title']]);
        $this->assertContains('x-default', array_column($home['alternates'], 'hreflang'));
        $org = collect(array_map(fn ($j) => json_decode($j, true), $home['jsonLd']))->firstWhere('@type', 'Organization');
        $this->assertSame(['Cronos Listones', 'Listones Cronos SA de CV', '+52 55 0000 0000'], [$org['name'], $org['legalName'], $org['telephone']]);

        $c = $this->seo('CATEGORY', 'es-MX', $slug);
        $this->assertSame(['https://tienda.test/es-mx/categorias/'.$slug, 'website'], [$c['canonical'], $c['openGraph']['type']]);
        $this->assertSame('BreadcrumbList', json_decode($c['jsonLd'][0], true)['@type']);
    }

    public function test_the_sitemap_lists_published_pages_with_alternates_split_by_language_and_page(): void
    {
        config(['app.storefront_url' => 'https://tienda.test', 'seo.sitemap_page_size' => 100]);
        $a = $this->product();
        $this->products()->create(['type' => 'simple', 'sku' => 'DRAFT', 'price' => '1.00', 'translations' => ['es-MX' => ['name' => 'Borrador oculto']]]);
        $this->category('Listones');
        Cache::flush();

        $index = $this->get('/sitemap.xml')->assertOk()->assertHeader('Content-Type', 'application/xml; charset=UTF-8')->getContent();
        foreach (['es-MX-pages', 'es-MX-products-1', 'en-US-pages', 'en-US-products-1'] as $file) {
            $this->assertStringContainsString("https://tienda.test/sitemaps/{$file}.xml", $index);
        }
        $this->assertSame(2, substr_count($index, '-products-'), 'one product page per language');

        $products = $this->get('/sitemaps/es-MX-products-1.xml')->assertOk()->getContent();
        $this->assertStringContainsString('<loc>https://tienda.test/es-mx/productos/', $products);
        $this->assertStringContainsString('hreflang="en-US" href="https://tienda.test/en-us/products/', $products);
        $this->assertStringContainsString('hreflang="x-default"', $products);
        $this->assertStringNotContainsString('borrador-oculto', $products, 'a draft is not in the sitemap');
        $this->assertSame(1, substr_count($products, '<url>'));
        simplexml_load_string($products) !== false || $this->fail('well-formed XML');

        $pages = $this->get('/sitemaps/es-MX-pages.xml')->assertOk()->getContent();
        $this->assertStringContainsString('<loc>https://tienda.test/es-mx</loc>', $pages);
        $this->assertStringContainsString('/es-mx/categorias/', $pages);
        $this->assertStringContainsString('<lastmod>', $pages);

        $this->get('/sitemaps/es-MX-products-9.xml')->assertNotFound();
        $this->get('/sitemaps/fr-FR-pages.xml')->assertNotFound();
        $this->get('/sitemaps/..%2F..%2F.env.xml')->assertNotFound();
        $this->assertNotNull($a);
    }

    public function test_robots_keeps_everything_out_unless_this_is_the_real_production_site(): void
    {
        config(['app.storefront_url' => 'https://tienda.test', 'seo.allow_indexing' => false]);
        $this->assertSame("User-agent: *\nDisallow: /\n", $this->get('/robots.txt')->assertOk()->assertHeader('Content-Type', 'text/plain; charset=UTF-8')->getContent());

        config(['seo.allow_indexing' => true]);
        $robots = $this->get('/robots.txt')->getContent();
        foreach (['Allow: /', 'Disallow: /es-mx/checkout', 'Disallow: /en-us/account', 'Disallow: /es-mx/cart', 'Disallow: /*?*sort=', 'Sitemap: https://tienda.test/sitemap.xml'] as $line) {
            $this->assertStringContainsString($line, $robots);
        }
        $this->assertStringNotContainsString("Disallow: /\n", str_replace('Disallow: /es', 'X', $robots));
    }

    public function test_the_storefront_can_read_the_url_scheme_it_must_route_by(): void
    {
        $r = $this->gql('{ seoRoutes { locale prefix productSegment categorySegment ogLocale } }')->assertJsonMissingPath('errors')->json('data.seoRoutes');
        $this->assertSame(['locale' => 'es-MX', 'prefix' => 'es-mx', 'productSegment' => 'productos', 'categorySegment' => 'categorias', 'ogLocale' => 'es_MX'], $r[0]);
        $this->assertCount(2, $r);
    }
}
