<?php

declare(strict_types=1);

namespace Cronos\Promotions\Application;

use Cronos\Pricing\Domain\Ports\CurrencyStore;
use Cronos\Promotions\Domain\Exceptions\InvalidPromotion;
use Cronos\Promotions\Domain\Exceptions\NotFound;
use Cronos\Promotions\Domain\Ports\CouponRepository;
use Cronos\Promotions\Domain\ValueObjects\Restrictions;

final class CouponManager
{
    public function __construct(private readonly CouponRepository $coupons, private readonly CurrencyStore $currencies) {}

    /** @param array<string, mixed> $input */
    public function create(array $input): string
    {
        $data = $this->validated($input);
        ! $this->coupons->codeTaken($data['code']) || throw new InvalidPromotion("The code {$data['code']} is already taken.", 'COUPON_CODE_TAKEN');

        return $this->coupons->create($data + ['uses_count' => 0]);
    }

    /** @param array<string, mixed> $input */
    public function update(string $id, array $input): void
    {
        $current = $this->coupons->find($id) ?? throw new NotFound('The coupon');
        $data = $this->validated($input + $current);
        ! $this->coupons->codeTaken($data['code'], $id) || throw new InvalidPromotion("The code {$data['code']} is already taken.", 'COUPON_CODE_TAKEN');
        ($data['max_uses'] === null || $data['max_uses'] >= $current['uses_count']) || throw new InvalidPromotion('The use limit cannot be below the uses already redeemed.', 'LIMIT_BELOW_USES');

        $this->coupons->update($id, $data);
    }

    public function delete(string $id): void
    {
        $this->coupons->find($id) ?? throw new NotFound('The coupon');

        $this->coupons->delete($id);
    }

    /** @param array<string, mixed> $in @return array<string, mixed> */
    private function validated(array $in): array
    {
        $code = strtoupper(trim((string) $in['code']));
        preg_match('/^[A-Z0-9_-]{3,40}$/', $code) || throw new InvalidPromotion('A coupon code is 3-40 letters, digits, dash or underscore.', 'INVALID_COUPON_CODE');
        $type = (string) $in['type'];
        in_array($type, ['fixed', 'percentage', 'free_shipping'], true) || throw new InvalidPromotion('Unknown coupon type.');

        $value = $type === 'free_shipping' ? '0' : (string) ($in['value'] ?? '');
        if ($type !== 'free_shipping') {
            (is_numeric($value) && (float) $value > 0) || throw new InvalidPromotion('The discount must be greater than zero.');
            $type !== 'percentage' || (float) $value <= 100 || throw new InvalidPromotion('A percentage cannot exceed 100.');
        }

        $currency = $in['currency'] ?? null;
        if ($type === 'fixed') {
            $currency !== null && $this->currencies->findByCode((string) $currency) !== null || throw new InvalidPromotion('A fixed coupon needs one of the store currencies.', 'CURRENCY_REQUIRED');
            $currency = strtoupper((string) $currency);
        } else {
            $currency = null;
        }

        foreach (['min_spend', 'max_discount'] as $money) {
            $v = $in[$money] ?? null;
            $v === null || $v === '' || (is_numeric($v) && (float) $v >= 0) || throw new InvalidPromotion("{$money} must be zero or more.");
        }
        foreach (['max_uses', 'max_uses_per_user'] as $limit) {
            ($in[$limit] ?? null) === null || (int) $in[$limit] >= 1 || throw new InvalidPromotion('Limits must be at least 1.');
        }
        $starts = $in['starts_at'] ?? null;
        $expires = $in['expires_at'] ?? null;
        ($starts === null || $expires === null || strtotime((string) $starts) < strtotime((string) $expires)) || throw new InvalidPromotion('The coupon must expire after it starts.', 'INVALID_WINDOW');

        $restrictions = $in['restrictions'] ?? [];

        return [
            'code' => $code, 'name' => trim((string) $in['name']), 'type' => $type, 'value' => $value, 'currency' => $currency,
            'min_spend' => ($in['min_spend'] ?? '') === '' ? null : $in['min_spend'], 'max_discount' => ($in['max_discount'] ?? '') === '' ? null : $in['max_discount'],
            'max_uses' => isset($in['max_uses']) ? (int) $in['max_uses'] : null, 'max_uses_per_user' => isset($in['max_uses_per_user']) ? (int) $in['max_uses_per_user'] : null,
            'is_stackable' => (bool) ($in['is_stackable'] ?? false), 'is_active' => (bool) ($in['is_active'] ?? true),
            'starts_at' => $starts, 'expires_at' => $expires,
            'restrictions' => ($restrictions instanceof Restrictions ? $restrictions : Restrictions::fromArray($restrictions))->toArray(),
        ];
    }
}
