<?php

declare(strict_types=1);

namespace Cronos\Promotions\Interfaces\GraphQL;

use Cronos\Promotions\Domain\Ports\CouponRepository;
use Cronos\Promotions\Domain\Ports\PromotionRepository;

final class AdminPromotionsQuery
{
    public function __construct(private readonly CouponRepository $coupons, private readonly PromotionRepository $promotions) {}

    /** @param array<string, mixed> $args */
    public function coupons(mixed $root, array $args): array
    {
        return array_map(Present::coupon(...), $this->coupons->search($args['search'] ?? null));
    }

    public function promotions(): array
    {
        return array_map(Present::promotion(...), $this->promotions->all());
    }
}
