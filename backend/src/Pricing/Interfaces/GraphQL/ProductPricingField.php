<?php

declare(strict_types=1);

namespace Cronos\Pricing\Interfaces\GraphQL;

use Cronos\Pricing\Application\PriceQuoter;
use GraphQL\Deferred;

/**
 * `pricing` on a list of products would otherwise quote one product at a time. Every product asked for the same
 * (market, region) in one request is collected first and quoted together, so a page of 48 costs two queries.
 */
final class ProductPricingField
{
    /** @var array<string, array<string, true>> */
    private array $pending = [];

    /** @var array<string, array<string, array<string, mixed>|null>> */
    private array $quoted = [];

    public function __construct(private readonly PriceQuoter $quoter) {}

    /** @param array<string, mixed> $root @param array{market: string, region?: ?string} $args */
    public function __invoke(array $root, array $args): Deferred
    {
        $key = strtoupper($args['market']).'|'.strtoupper((string) ($args['region'] ?? ''));
        $id = (string) $root['id'];
        $this->pending[$key][$id] = true;

        return new Deferred(function () use ($key, $id, $args): ?array {
            if (! array_key_exists($id, $this->quoted[$key] ?? [])) {
                $ids = array_keys(array_diff_key($this->pending[$key], $this->quoted[$key] ?? []));
                $this->quoted[$key] = ($this->quoted[$key] ?? []) + $this->quoter->quote($ids, $args['market'], $args['region'] ?? null);
            }
            $quote = $this->quoted[$key][$id];

            return $quote === null ? null : Present::pricing($quote);
        });
    }
}
