<?php

namespace Tests\Feature\Promotions;

use Cronos\Catalog\Application\ProductManager;
use Database\Seeders\FraudRulesSeeder;
use Database\Seeders\PricingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\GraphQLTestCase;

final class PromotionsApiTest extends GraphQLTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PricingSeeder::class, FraudRulesSeeder::class]);
        Cache::flush();
    }

    private function bow(string $price = '100.00'): string
    {
        $id = app(ProductManager::class)->create(['type' => 'simple', 'sku' => 'B-'.uniqid(), 'price' => $price, 'translations' => ['es-MX' => ['name' => 'Moño '.uniqid()]]]);
        app(ProductManager::class)->publish($id);

        return $id;
    }

    public function test_admin_creates_a_coupon_and_the_storefront_previews_it(): void
    {
        $token = $this->adminToken();
        $create = 'mutation($i: CouponInput!) { createCoupon(input: $i) { id code type value restrictions { marketCodes excludeSaleItems } usesCount } }';

        $this->adminGql($create, ['i' => ['code' => 'verano15', 'name' => 'Verano', 'type' => 'PERCENTAGE', 'value' => '15', 'restrictions' => ['marketCodes' => ['mx']]]], $token)
            ->assertJsonMissingPath('errors')->assertJsonPath('data.createCoupon.code', 'VERANO15')->assertJsonPath('data.createCoupon.restrictions.marketCodes', ['MX'])->assertJsonPath('data.createCoupon.usesCount', 0);
        $this->adminGql($create, ['i' => ['code' => 'VERANO15', 'name' => 'dup', 'type' => 'PERCENTAGE', 'value' => '5']], $token)->assertJsonPath('errors.0.extensions.code', 'COUPON_CODE_TAKEN');

        $id = $this->bow();
        $preview = 'query($i: PreviewDiscountsInput!) { previewDiscounts(input: $i) { currency subtotal { amount } totalDiscount { amount } couponApplied applied { kind name amount { amount } } lines { index total { amount } discount { amount } } } }';
        $input = fn (string $market, ?string $code) => ['i' => ['market' => $market, 'couponCode' => $code, 'items' => [['productId' => $id, 'quantity' => '2']]]];

        $this->gql($preview, $input('MX', 'verano15'))->assertJsonMissingPath('errors')
            ->assertJsonPath('data.previewDiscounts.subtotal.amount', '200.00')->assertJsonPath('data.previewDiscounts.totalDiscount.amount', '30.00')
            ->assertJsonPath('data.previewDiscounts.couponApplied', true)->assertJsonPath('data.previewDiscounts.applied.0.kind', 'coupon')->assertJsonPath('data.previewDiscounts.lines.0.discount.amount', '30.00');

        $this->gql($preview, $input('US', 'VERANO15'))->assertJsonPath('errors.0.extensions.code', 'COUPON_MARKET');
        $this->gql($preview, $input('MX', 'NOPE'))->assertJsonPath('errors.0.extensions.code', 'COUPON_NOT_FOUND');
        $this->gql($preview, $input('MX', null))->assertJsonPath('data.previewDiscounts.totalDiscount.amount', '0.00');
    }

    public function test_previewing_validates_the_basket(): void
    {
        $id = $this->bow();
        $q = 'query($i: PreviewDiscountsInput!) { previewDiscounts(input: $i) { currency } }';

        $empty = $this->gql($q, ['i' => ['market' => 'MX', 'items' => []]]);
        $this->assertSame('EMPTY_CART', $empty->json('errors.0.extensions.code'));
        $many = array_fill(0, 51, ['productId' => $id, 'quantity' => '1']);
        $this->gql($q, ['i' => ['market' => 'MX', 'items' => $many]])->assertJsonPath('errors.0.extensions.code', 'TOO_MANY_ITEMS');
        $this->gql($q, ['i' => ['market' => 'MX', 'items' => [['productId' => $id, 'quantity' => '-1']]]])->assertJsonPath('errors.0.extensions.code', 'INVALID_QUANTITY');
        $this->gql($q, ['i' => ['market' => 'MX', 'items' => [['productId' => $id, 'quantity' => '1', 'byRoll' => true]]]])->assertJsonPath('errors.0.extensions.code', 'NO_ROLL_PRICE');
    }

    public function test_admin_manages_promotions_and_they_apply_without_a_code(): void
    {
        $token = $this->adminToken();
        $create = 'mutation($i: PromotionInput!) { createPromotion(input: $i) { id name priority action { type buy get percent } conditions { minSubtotal } } }';

        $this->adminGql($create, ['i' => ['name' => '2x1', 'priority' => 1, 'action' => ['type' => 'BUY_X_GET_Y', 'buy' => 1, 'get' => 1, 'percent' => '100']]], $token)
            ->assertJsonMissingPath('errors')->assertJsonPath('data.createPromotion.action.type', 'BUY_X_GET_Y')->assertJsonPath('data.createPromotion.action.get', 1);
        $this->adminGql($create, ['i' => ['name' => 'bad', 'action' => ['type' => 'PERCENTAGE_OFF', 'value' => '150']]], $token)->assertJsonPath('errors.0.extensions.code', 'INVALID_PROMOTION');

        $id = $this->bow();
        $this->gql('query($i: PreviewDiscountsInput!) { previewDiscounts(input: $i) { totalDiscount { amount } applied { kind name } } }', ['i' => ['market' => 'MX', 'items' => [['productId' => $id, 'quantity' => '2']]]])
            ->assertJsonPath('data.previewDiscounts.totalDiscount.amount', '100.00')->assertJsonPath('data.previewDiscounts.applied.0.name', '2x1');

        $this->adminGql('{ adminPromotions { name isStackable } adminCoupons { code } }', [], $token)->assertJsonPath('data.adminPromotions.0.name', '2x1');
    }

    public function test_admin_manages_fraud_rules_and_reads_the_log(): void
    {
        $token = $this->adminToken();
        $this->adminGql('{ adminFraudRules { name type score params } }', [], $token)->assertJsonCount(5, 'data.adminFraudRules')->assertJsonPath('data.adminFraudRules.0.type', 'FAILED_PAYMENTS');

        $create = 'mutation($i: FraudRuleInput!) { createFraudRule(input: $i) { id type params isActive } }';
        $this->adminGql($create, ['i' => ['name' => 'Riesgo', 'type' => 'HIGH_RISK_COUNTRY', 'params' => ['countries' => ['ru']], 'score' => 90]], $token)
            ->assertJsonPath('data.createFraudRule.params.countries', ['RU']);
        $this->adminGql($create, ['i' => ['name' => 'Mal', 'type' => 'VELOCITY_IP', 'params' => ['max' => 0], 'score' => 10]], $token)->assertJsonPath('errors.0.extensions.code', 'INVALID_FRAUD_RULE');

        $this->adminGql('{ adminFraudChecks { id } }', [], $token)->assertJsonPath('data.adminFraudChecks', []);
    }

    public function test_support_can_read_but_not_change_promotions_and_cannot_see_fraud_rules(): void
    {
        $support = $this->adminToken('help@example.com', 'support');

        $this->adminGql('{ adminCoupons { code } }', [], $support)->assertJsonPath('errors.0.message', 'This action is unauthorized.');
        $this->adminGql('{ adminFraudRules { name } }', [], $support)->assertJsonPath('errors.0.message', 'This action is unauthorized.');
    }
}
