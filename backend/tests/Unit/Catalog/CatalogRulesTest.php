<?php

namespace Tests\Unit\Catalog;

use Cronos\Catalog\Domain\Exceptions\CannotPublish;
use Cronos\Catalog\Domain\Exceptions\InvalidProduct;
use Cronos\Catalog\Domain\Services\PublishingPolicy;
use Cronos\Catalog\Domain\Services\VariationCombination;
use Cronos\Catalog\Domain\Services\VariationInheritance;
use Cronos\Catalog\Domain\ValueObjects\Slug;
use PHPUnit\Framework\TestCase;

class CatalogRulesTest extends TestCase
{
    public function test_combination_identity_ignores_order_and_duplicates(): void
    {
        $this->assertSame(VariationCombination::hash(['b', 'a']), VariationCombination::hash(['a', 'b', 'a']));
        $this->assertNotSame(VariationCombination::hash(['a', 'b']), VariationCombination::hash(['a', 'c']));
    }

    public function test_cartesian_product_lists_every_combination_once(): void
    {
        $all = VariationCombination::cartesian(['color' => ['red', 'blue'], 'width' => ['10', '25', '40']]);

        $this->assertCount(6, $all);
        $this->assertContains(['blue', '25'], $all);
        $this->assertSame([], VariationCombination::cartesian([]));
    }

    public function test_a_variation_inherits_what_it_does_not_override(): void
    {
        $parent = ['price' => '10.00', 'weight' => '0.020', 'dimensions' => ['width' => 25], 'barcode' => 'P'];
        $variation = ['price' => '12.50', 'weight' => null, 'dimensions' => [], 'barcode' => null];

        $resolved = (new VariationInheritance)->resolve($parent, $variation);

        $this->assertSame('12.50', $resolved['values']['price']);
        $this->assertSame('0.020', $resolved['values']['weight']);
        $this->assertSame(['width' => 25], $resolved['values']['dimensions'], 'an empty override means inherit');
        $this->assertSame(['price'], $resolved['overridden']);
    }

    public function test_a_zero_price_is_an_override_not_a_missing_value(): void
    {
        $resolved = (new VariationInheritance)->resolve(['price' => '10.00'], ['price' => '0.00']);

        $this->assertSame('0.00', $resolved['values']['price']);
        $this->assertSame(['price'], $resolved['overridden']);
    }

    private function product(array $overrides = []): array
    {
        return array_merge([
            'type' => 'simple', 'sku' => 'LIST-1', 'price' => '10.00', 'locales' => ['es-MX'], 'default_locale' => 'es-MX',
            'translations' => ['es-MX' => ['name' => 'Listón', 'slug' => 'liston']],
            'published_variations' => 0, 'group_items' => 0, 'variation_attributes' => 0,
        ], $overrides);
    }

    public function test_publishing_rules_per_product_type(): void
    {
        $policy = new PublishingPolicy;

        $this->assertSame([], $policy->problems($this->product()));
        $this->assertCount(3, $policy->problems($this->product(['sku' => ' ', 'price' => null, 'translations' => ['es-MX' => ['name' => '', 'slug' => 'x']]])));
        $this->assertSame([], $policy->problems($this->product(['type' => 'variable', 'sku' => null, 'price' => null, 'variation_attributes' => 1, 'published_variations' => 2])));
        $this->assertCount(2, $policy->problems($this->product(['type' => 'variable', 'price' => null])));
        $this->assertCount(1, $policy->problems($this->product(['type' => 'grouped', 'sku' => null, 'price' => null])));
        $this->assertSame([], $policy->problems($this->product(['type' => 'grouped', 'group_items' => 3, 'sku' => null, 'price' => null])));
    }

    public function test_assert_publishable_lists_every_problem(): void
    {
        try {
            (new PublishingPolicy)->assertPublishable($this->product(['sku' => null, 'price' => null]));
            $this->fail('Expected CannotPublish');
        } catch (CannotPublish $e) {
            $this->assertSame('CANNOT_PUBLISH', $e->errorCode());
            $this->assertStringContainsString('SKU', $e->getMessage());
            $this->assertStringContainsString('price', $e->getMessage());
        }
    }

    public function test_slugs_drop_accents_and_symbols(): void
    {
        $this->assertSame('liston-de-saten-rojo-25-mm', (string) Slug::from('Listón de Satén Rojo — 25 mm!'));
        $this->assertSame('cafe-con-leche', (string) Slug::from('  Café   con leche '));
        $this->assertSame('ok-slug-1', (string) Slug::fromValid('ok-slug-1'));
    }

    public function test_hand_typed_slugs_must_already_be_canonical(): void
    {
        foreach (['Has Space', 'ünï', '-lead', 'trail-', 'a--b', '../etc/passwd', ''] as $bad) {
            try {
                Slug::fromValid($bad);
                $this->fail("[{$bad}] should be rejected");
            } catch (InvalidProduct) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
