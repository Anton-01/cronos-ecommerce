<?php

namespace Tests\Feature\Catalog;

use Cronos\Catalog\Application\CatalogReader;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\GraphQLTestCase;

class CatalogApiTest extends GraphQLTestCase
{
    private ?string $token = null;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('media');
        Cache::flush();
    }

    private function admin(): string
    {
        return $this->token ??= $this->adminToken();
    }

    private function a(string $query, array $variables = [], ?string $token = null): TestResponse
    {
        return $this->adminGql($query, $variables, $token ?? $this->admin());
    }

    private function create(array $input): string
    {
        $r = $this->a('mutation($i: CreateProductInput!) { createProduct(input: $i) { id } }', ['i' => $input]);
        $r->assertJsonMissingPath('errors');

        return $r->json('data.createProduct.id');
    }

    private function ribbonInput(array $override = []): array
    {
        return [
            'type' => 'SIMPLE', 'sku' => 'LIST-1', 'price' => '18.00',
            'sales' => ['unit' => 'METER', 'step' => '0.5', 'min' => '1', 'max' => '50', 'sellByCut' => true, 'sellByRoll' => true, 'rollLength' => '25', 'rollPrice' => '400.00'],
            'translations' => [['locale' => 'es-MX', 'name' => 'Listón de satén', 'shortDescription' => 'Para moños'], ['locale' => 'en-US', 'name' => 'Satin ribbon']],
            ...$override,
        ];
    }

    public function test_the_storefront_serves_a_ribbon_with_its_two_ways_of_buying_and_money_as_strings(): void
    {
        $id = $this->create($this->ribbonInput());
        $this->a('mutation($id: ID!) { publishProduct(id: $id) { status publishedAt } }', ['id' => $id])->assertJsonMissingPath('errors')->assertJsonPath('data.publishProduct.status', 'PUBLISHED');

        $r = $this->gql('{ product(slug: "liston-de-saten") { id type publishedAt name price sales { unit step min max sellByCut sellByRoll rollLength rollPrice } stockStatus } }')->assertJsonMissingPath('errors');

        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', $r->json('data.product.publishedAt'));
        $r->assertJsonPath('data.product.type', 'SIMPLE')
            ->assertJsonPath('data.product.price', '18.00')
            ->assertJsonPath('data.product.sales', ['unit' => 'METER', 'step' => '0.500', 'min' => '1.000', 'max' => '50.000', 'sellByCut' => true, 'sellByRoll' => true, 'rollLength' => '25.000', 'rollPrice' => '400.00']);
    }

    public function test_the_whole_variable_ribbon_workflow_through_the_api(): void
    {
        $attr = fn (string $code, string $type, string $name) => $this->a('mutation($code: String!, $type: String, $n: [LocaleTextInput!]!) { createAttribute(code: $code, type: $type, names: $n) { id } }', ['code' => $code, 'type' => $type, 'n' => [['locale' => 'es-MX', 'value' => $name]]])->json('data.createAttribute.id');
        $value = fn (string $attrId, string $code, string $label, ?array $meta = null) => collect($this->a('mutation($a: ID!, $c: String!, $m: JSON, $l: [LocaleTextInput!]!) { addAttributeValue(attributeId: $a, code: $c, meta: $m, labels: $l) { values { id code } } }', ['a' => $attrId, 'c' => $code, 'm' => $meta, 'l' => [['locale' => 'es-MX', 'value' => $label]]])->json('data.addAttributeValue.values'))->firstWhere('code', $code)['id'];

        $color = $attr('color', 'color', 'Color');
        $width = $attr('width', 'select', 'Ancho');
        $red = $value($color, 'rojo', 'Rojo', ['hex' => '#C8285A']);
        $blue = $value($color, 'azul', 'Azul', ['hex' => '#1458C8']);
        $w10 = $value($width, '10mm', '10 mm');
        $w25 = $value($width, '25mm', '25 mm');

        $parent = $this->create($this->ribbonInput(['type' => 'VARIABLE', 'sku' => 'LISO', 'price' => '10.00', 'translations' => [['locale' => 'es-MX', 'name' => 'Listón liso']]]));
        $this->a('mutation($id: ID!, $a: [ProductAttributeInput!]!) { setProductAttributes(id: $id, attributes: $a) { id } }', ['id' => $parent, 'a' => [['attributeId' => $color, 'isVariation' => true], ['attributeId' => $width, 'isVariation' => true]]])->assertJsonMissingPath('errors');
        $this->a('mutation($id: ID!, $v: [ID!]!) { setProductOfferedValues(id: $id, valueIds: $v) { offeredValueIds } }', ['id' => $parent, 'v' => [$red, $blue, $w10, $w25]])->assertJsonMissingPath('errors');

        $generated = $this->a('mutation($id: ID!) { generateVariations(parentId: $id) { variations { id sku status price effectivePrice overridden } publishProblems } }', ['id' => $parent])->assertJsonMissingPath('errors')->json('data.generateVariations');
        $this->assertCount(4, $generated['variations']);
        $this->assertSame([null, '10.00', []], [$generated['variations'][0]['price'], $generated['variations'][0]['effectivePrice'], $generated['variations'][0]['overridden']]);
        $this->assertNotEmpty($generated['publishProblems'], 'nothing is published yet');

        $first = $generated['variations'][0]['id'];
        $second = $generated['variations'][1]['id'];
        $this->a('mutation($id: ID!) { updateVariation(id: $id, input: { price: "15.50" }) { id } }', ['id' => $second])->assertJsonMissingPath('errors');
        foreach ([$first, $second] as $variation) {
            $this->a('mutation($id: ID!) { publishProduct(id: $id) { status } }', ['id' => $variation])->assertJsonMissingPath('errors');
        }
        $this->a('mutation($id: ID!) { publishProduct(id: $id) { status publishProblems } }', ['id' => $parent])->assertJsonPath('data.publishProduct.status', 'PUBLISHED');

        $page = $this->gql('{ product(slug: "liston-liso") { type priceFrom priceTo attributes { code isVariation values { code meta } } variations { price overridden stockStatus } } }')->assertJsonMissingPath('errors')->json('data.product');
        $this->assertSame(['VARIABLE', '10.00', '15.50'], [$page['type'], $page['priceFrom'], $page['priceTo']]);
        $this->assertCount(2, $page['variations'], 'only the two published variations are sold');
        $this->assertSame(['rojo'], array_column(collect($page['attributes'])->firstWhere('code', 'color')['values'], 'code'), 'options nobody can buy yet are not offered');
        $this->assertSame(['hex' => '#C8285A'], collect($page['attributes'])->firstWhere('code', 'color')['values'][0]['meta']);
    }

    public function test_domain_errors_reach_the_client_with_stable_codes(): void
    {
        $this->create($this->ribbonInput());
        $mutation = 'mutation($i: CreateProductInput!) { createProduct(input: $i) { id } }';

        $cases = [
            'INVALID_SALES_UNIT' => $this->ribbonInput(['sku' => 'X1', 'translations' => [['locale' => 'es-MX', 'name' => 'a']], 'sales' => ['unit' => 'METER', 'sellByRoll' => true]]),
            'SKU_TAKEN' => $this->ribbonInput(['translations' => [['locale' => 'es-MX', 'name' => 'Otro nombre']]]),
            'SLUG_TAKEN' => $this->ribbonInput(['sku' => 'X2']),
            'INVALID_PRODUCT' => $this->ribbonInput(['sku' => 'X3', 'price' => '-1', 'translations' => [['locale' => 'es-MX', 'name' => 'b']]]),
        ];
        foreach ($cases as $code => $input) {
            $this->assertSame($code, $this->errorCode($this->a($mutation, ['i' => $input])), $code);
        }

        $draft = $this->create(['type' => 'SIMPLE', 'translations' => [['locale' => 'es-MX', 'name' => 'Incompleto']]]);
        $r = $this->a('mutation($id: ID!) { publishProduct(id: $id) { id } }', ['id' => $draft]);
        $this->assertSame('CANNOT_PUBLISH', $this->errorCode($r));
        $this->assertStringContainsString('SKU', $r->json('errors.0.message'));
    }

    public function test_the_admin_list_treats_explicit_null_filters_as_no_filter(): void
    {
        $id = $this->create($this->ribbonInput());
        $q = 'query($s: String, $st: ProductStatus, $t: ProductType, $c: ID) { adminProducts(first: 25, search: $s, status: $st, type: $t, categoryId: $c) { pageInfo { total } } }';

        $this->a($q, ['s' => null, 'st' => null, 't' => null, 'c' => null])->assertJsonMissingPath('errors')->assertJsonPath('data.adminProducts.pageInfo.total', 1);
        $this->a($q, ['st' => 'DRAFT', 't' => 'SIMPLE'])->assertJsonPath('data.adminProducts.pageInfo.total', 1);
        $this->a($q, ['st' => 'PUBLISHED'])->assertJsonPath('data.adminProducts.pageInfo.total', 0);
        $this->a($q, ['t' => 'VARIABLE'])->assertJsonPath('data.adminProducts.pageInfo.total', 0);
        $this->assertNotEmpty($id);
    }

    public function test_permissions_view_manage_and_publish_are_separate(): void
    {
        $input = ['i' => $this->ribbonInput()];
        $create = 'mutation($i: CreateProductInput!) { createProduct(input: $i) { id } }';
        $unauthorized = fn (TestResponse $r) => str_contains(strtolower((string) $r->json('errors.0.message')), 'unauthorized');

        $this->assertSame('Unauthenticated.', $this->adminGql($create, $input)->json('errors.0.message'));

        $viewer = $this->adminToken('help@example.com', 'support');                    // catalog.view only
        $this->a('{ adminProducts(first: 5) { edges { node { id } } } adminCategories { id } adminAttributes { id } }', [], $viewer)->assertJsonMissingPath('errors');
        $this->assertTrue($unauthorized($this->a($create, $input, $viewer)));

        $editor = $this->makeUser('edit@example.com', 'support');
        $editor->givePermissionTo('catalog.manage');                                   // may edit, may not publish
        $editorToken = $this->adminGql('mutation($i: AdminLoginInput!) { adminLogin(input: $i) { token } }', ['i' => ['email' => 'edit@example.com', 'password' => self::PASSWORD]])->json('data.adminLogin.token');
        $id = $this->a($create, $input, $editorToken)->json('data.createProduct.id');
        $this->assertNotNull($id);
        $this->assertTrue($unauthorized($this->a('mutation($id: ID!) { publishProduct(id: $id) { id } }', ['id' => $id], $editorToken)));
        $this->a('mutation($id: ID!) { publishProduct(id: $id) { status } }', ['id' => $id])->assertJsonPath('data.publishProduct.status', 'PUBLISHED');

        $customer = $this->makeUser('ana@example.com');
        $this->assertNotNull($customer);
        $storefrontToken = $this->gql('mutation { login(input: {email: "ana@example.com", password: "'.self::PASSWORD.'"}) { token } }')->json('data.login.token');
        $this->assertTrue($unauthorized($this->a('{ adminProducts(first: 1) { edges { node { id } } } }', [], $storefrontToken)));
    }

    public function test_the_public_endpoint_has_no_back_office_operations(): void
    {
        foreach (['adminProducts(first: 1) { edges { node { id } } }', 'adminCategories { id }'] as $field) {
            $this->gql("{ {$field} }")->assertJsonPath('errors.0.message', fn ($m) => str_contains($m, 'Cannot query field'));
        }
        $this->gql('mutation { deleteProduct(id: "x") }')->assertJsonPath('errors.0.message', fn ($m) => str_contains($m, 'Cannot query field'));
    }

    public function test_public_queries_enforce_limits_and_never_expose_drafts(): void
    {
        $this->create($this->ribbonInput());   // stays a draft

        $this->gql('{ products(first: 100) { total } }')->assertJsonPath('errors.0.extensions.validation', fn ($v) => isset($v['first']));
        $this->gql('{ products { total items { slug } } }')->assertJsonPath('data.products.total', 0);
        $this->gql('{ product(slug: "liston-de-saten") { id } }')->assertJsonPath('data.product', null)->assertJsonMissingPath('errors');
        $this->gql('{ productPreview(token: "nope") { id } }')->assertJsonPath('errors.0.extensions.code', 'INVALID_PREVIEW_TOKEN');
    }

    public function test_a_draft_is_previewed_through_a_link_generated_in_the_back_office(): void
    {
        $id = $this->create($this->ribbonInput());
        $link = $this->a('mutation($id: ID!) { issueProductPreview(id: $id, ttlMinutes: 30) { token path expiresAt } }', ['id' => $id])->assertJsonMissingPath('errors')->json('data.issueProductPreview');

        $this->assertStringStartsWith('/preview/', $link['path']);
        $this->gql('query($t: String!) { productPreview(token: $t) { name status } }', ['t' => $link['token']])
            ->assertJsonPath('data.productPreview', ['name' => 'Listón de satén', 'status' => 'DRAFT']);
    }

    public function test_the_gallery_uses_the_media_library_and_keeps_its_order(): void
    {
        $id = $this->create($this->ribbonInput());
        $media = fn (string $name) => $this->gqlUpload('mutation($file: Upload!) { uploadMedia(file: $file) { id } }', [], ['variables.file' => UploadedFile::fake()->image("{$name}.jpg", 400, 300)], $this->admin())->json('data.uploadMedia.id');
        [$one, $two, $three] = [$media('uno'), $media('dos'), $media('tres')];
        $attach = 'mutation($id: ID!, $m: ID!) { attachProductMedia(id: $id, mediaId: $m) { gallery { id status } } }';

        foreach ([$one, $two, $three] as $m) {
            $this->a($attach, ['id' => $id, 'm' => $m])->assertJsonMissingPath('errors');
        }
        $gallery = fn () => array_column($this->a('query($id: ID!) { adminProduct(id: $id) { gallery { id } } }', ['id' => $id])->json('data.adminProduct.gallery'), 'id');
        $this->assertSame([$one, $two, $three], $gallery());

        $this->a('mutation($id: ID!, $m: [ID!]!) { reorderProductMedia(id: $id, mediaIds: $m) { id } }', ['id' => $id, 'm' => [$three, $one, $two]])->assertJsonMissingPath('errors');
        $this->assertSame([$three, $one, $two], $gallery());

        $this->a('mutation($id: ID!, $m: ID!) { detachProductMedia(id: $id, mediaId: $m) { id } }', ['id' => $id, 'm' => $one]);
        $this->assertSame([$three, $two], $gallery());
        $this->assertNotNull(DB::table('media')->where('id', $one)->whereNull('deleted_at')->whereNull('model_id')->first(), 'a detached photo returns to the library');

        $this->a('mutation($id: ID!) { publishProduct(id: $id) { id } }', ['id' => $id]);
        $image = $this->gql('{ product(slug: "liston-de-saten") { gallery { id thumbUrl url alt } } }')->json('data.product.gallery.0');
        $this->assertSame($three, $image['id']);
        $this->assertStringEndsWith('/conversions/thumb.webp', $image['thumbUrl']);
    }

    public function test_only_images_can_join_a_gallery_and_a_missing_product_is_reported(): void
    {
        $id = $this->create($this->ribbonInput());
        $pdf = $this->gqlUpload('mutation($file: Upload!) { uploadMedia(file: $file) { id } }', [], ['variables.file' => UploadedFile::fake()->createWithContent('a.pdf', "%PDF-1.4\n%%EOF")], $this->admin())->json('data.uploadMedia.id');

        $this->assertSame('INVALID_PRODUCT', $this->errorCode($this->a('mutation($id: ID!, $m: ID!) { attachProductMedia(id: $id, mediaId: $m) { id } }', ['id' => $id, 'm' => $pdf])));
        $this->assertSame('NOT_FOUND', $this->errorCode($this->a('mutation($m: ID!) { attachProductMedia(id: "00000000000000000000000000", mediaId: $m) { id } }', ['m' => $pdf])));
    }

    public function test_the_admin_list_filters_searches_and_paginates(): void
    {
        $cat = $this->a('mutation { createCategory(translations: [{locale: "es-MX", name: "Listones"}]) { id } }')->json('data.createCategory.id');
        $a = $this->create($this->ribbonInput(['sku' => 'AAA', 'translations' => [['locale' => 'es-MX', 'name' => 'Cinta azul']], 'categoryIds' => [$cat]]));
        $this->create($this->ribbonInput(['sku' => 'BBB', 'translations' => [['locale' => 'es-MX', 'name' => 'Cinta roja']]]));
        $this->create(['type' => 'SIMPLE', 'sku' => 'CCC', 'price' => '5', 'translations' => [['locale' => 'es-MX', 'name' => 'Moño']]]);
        $this->a('mutation($id: ID!) { publishProduct(id: $id) { id } }', ['id' => $a]);

        $list = 'query($s: String, $st: ProductStatus, $c: ID, $first: Int = 10) { adminProducts(first: $first, search: $s, status: $st, categoryId: $c) { pageInfo { total hasNextPage } edges { node { id sku name status price variationsCount } } } }';
        $skus = fn (array $vars) => array_column(array_column($this->a($list, $vars)->json('data.adminProducts.edges'), 'node'), 'sku');

        $this->assertEqualsCanonicalizing(['AAA', 'BBB', 'CCC'], $skus([]));
        $this->assertEqualsCanonicalizing(['AAA', 'BBB'], $skus(['s' => 'cinta']));
        $this->assertSame(['CCC'], $skus(['s' => 'ccc']), 'by SKU');
        $this->assertSame(['AAA'], $skus(['st' => 'PUBLISHED']));
        $this->assertSame(['AAA'], $skus(['c' => $cat]));
        $this->assertSame(2, $this->a($list, ['first' => 2])->json('data.adminProducts.pageInfo.total') - 1);
        $this->assertTrue($this->a($list, ['first' => 2])->json('data.adminProducts.pageInfo.hasNextPage'));
    }

    public function test_search_endpoint_is_cached_and_a_publish_is_visible_immediately(): void
    {
        $id = $this->create($this->ribbonInput());
        $query = '{ products(search: "satén") { total items { slug } } }';

        $this->gql($query)->assertJsonPath('data.products.total', 0);
        $this->a('mutation($id: ID!) { publishProduct(id: $id) { id } }', ['id' => $id]);
        $this->gql($query)->assertJsonPath('data.products.items.0.slug', 'liston-de-saten');
        $this->gql('{ products(search: "SATEN") { total } }')->assertJsonPath('data.products.total', 1);
        $this->assertNotNull(app(CatalogReader::class)->product('es-MX', 'liston-de-saten'));
    }
}
