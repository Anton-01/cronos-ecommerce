<?php

declare(strict_types=1);

namespace Cronos\Promotions\Interfaces\GraphQL;

use Cronos\Promotions\Application\CouponManager;
use Cronos\Promotions\Application\PromotionManager;
use Cronos\Promotions\Domain\Ports\CouponRepository;
use Cronos\Promotions\Domain\Ports\PromotionRepository;

final class AdminPromotionsMutations
{
    public function __construct(
        private readonly CouponManager $coupons,
        private readonly CouponRepository $couponRepo,
        private readonly PromotionManager $promotions,
        private readonly PromotionRepository $promotionRepo,
    ) {}

    /** @param array<string, mixed> $args */
    public function createCoupon(mixed $root, array $args): array
    {
        return Present::coupon($this->couponRepo->find($this->coupons->create($this->coupon($args['input']))));
    }

    /** @param array<string, mixed> $args */
    public function updateCoupon(mixed $root, array $args): array
    {
        $this->coupons->update($args['id'], $this->coupon($args['input']));

        return Present::coupon($this->couponRepo->find($args['id']));
    }

    /** @param array<string, mixed> $args */
    public function deleteCoupon(mixed $root, array $args): bool
    {
        $this->coupons->delete($args['id']);

        return true;
    }

    /** @param array<string, mixed> $args */
    public function createPromotion(mixed $root, array $args): array
    {
        return Present::promotion($this->promotionRepo->find($this->promotions->create($this->promotion($args['input']))));
    }

    /** @param array<string, mixed> $args */
    public function updatePromotion(mixed $root, array $args): array
    {
        $this->promotions->update($args['id'], $this->promotion($args['input']));

        return Present::promotion($this->promotionRepo->find($args['id']));
    }

    /** @param array<string, mixed> $args */
    public function deletePromotion(mixed $root, array $args): bool
    {
        $this->promotions->delete($args['id']);

        return true;
    }

    /** @param array<string, mixed> $in @return array<string, mixed> */
    private function coupon(array $in): array
    {
        return [
            'code' => $in['code'], 'name' => $in['name'], 'type' => $in['type'], 'value' => $in['value'] ?? null, 'currency' => $in['currency'] ?? null,
            'min_spend' => $in['minSpend'] ?? null, 'max_discount' => $in['maxDiscount'] ?? null, 'max_uses' => $in['maxUses'] ?? null,
            'max_uses_per_user' => $in['maxUsesPerUser'] ?? null, 'is_stackable' => $in['isStackable'] ?? false, 'is_active' => $in['isActive'] ?? true,
            'starts_at' => $in['startsAt'] ?? null, 'expires_at' => $in['expiresAt'] ?? null, 'restrictions' => Present::restrictionsInput($in['restrictions'] ?? null),
        ];
    }

    /** @param array<string, mixed> $in @return array<string, mixed> */
    private function promotion(array $in): array
    {
        $a = $in['action'];

        return [
            'name' => $in['name'], 'description' => $in['description'] ?? null, 'priority' => $in['priority'] ?? 0, 'is_stackable' => $in['isStackable'] ?? true,
            'is_active' => $in['isActive'] ?? true, 'starts_at' => $in['startsAt'] ?? null, 'ends_at' => $in['endsAt'] ?? null,
            'conditions' => Present::restrictionsInput($in['conditions'] ?? null),
            'action' => array_filter(['type' => $a['type'], 'value' => $a['value'] ?? null, 'currency' => $a['currency'] ?? null, 'buy' => $a['buy'] ?? null, 'get' => $a['get'] ?? null, 'percent' => $a['percent'] ?? null], fn ($v) => $v !== null),
        ];
    }
}
