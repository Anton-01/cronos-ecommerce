<?php

declare(strict_types=1);

namespace Cronos\Catalog\Domain\Services;

use Cronos\Catalog\Domain\Exceptions\CannotPublish;

/** Decides whether a product is complete enough to be shown to customers. */
final class PublishingPolicy
{
    /**
     * @param  array{
     *     type: string, sku: ?string, price: mixed, locales: list<string>, default_locale: string,
     *     translations: array<string, array{name?: ?string, slug?: ?string}>,
     *     published_variations: int, group_items: int, variation_attributes: int
     * }  $product  $product['price'] is the already-resolved (inherited) price
     * @return list<string>
     */
    public function problems(array $product): array
    {
        $problems = [];
        $default = $product['default_locale'];
        $t = $product['translations'][$default] ?? [];

        if (trim((string) ($t['name'] ?? '')) === '') {
            $problems[] = "the name in {$default} is required";
        }
        if (trim((string) ($t['slug'] ?? '')) === '') {
            $problems[] = "the slug in {$default} is required";
        }

        switch ($product['type']) {
            case 'simple':
            case 'variation':
                trim((string) $product['sku']) !== '' || $problems[] = 'the SKU is required';
                $product['price'] !== null || $problems[] = 'a price is required';
                break;
            case 'variable':
                $product['variation_attributes'] > 0 || $problems[] = 'define at least one variation attribute';
                $product['published_variations'] > 0 || $problems[] = 'publish at least one variation with a price';
                break;
            case 'grouped':
                $product['group_items'] > 0 || $problems[] = 'add at least one product to the group';
                break;
        }

        return $problems;
    }

    /** @param array<string, mixed> $product */
    public function assertPublishable(array $product): void
    {
        $problems = $this->problems($product);

        $problems === [] || throw new CannotPublish($problems);
    }
}
