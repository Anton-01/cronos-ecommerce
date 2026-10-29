<?php

declare(strict_types=1);

namespace Cronos\Promotions\Infrastructure\Persistence;

use Cronos\Promotions\Domain\Ports\CouponRepository;
use Cronos\Promotions\Infrastructure\Persistence\Eloquent\CouponModel;
use Illuminate\Support\Facades\DB;

final class EloquentCouponRepository implements CouponRepository
{
    public function find(string $id): ?array
    {
        return $this->row(CouponModel::query()->find($id));
    }

    public function findByCode(string $code): ?array
    {
        return $this->row(CouponModel::query()->where('code', $code)->first());
    }

    public function search(?string $term, int $limit = 100): array
    {
        return CouponModel::query()
            ->when($term !== null && trim($term) !== '', fn ($q) => $q->where(fn ($w) => $w->where('code', 'ilike', '%'.trim($term).'%')->orWhere('name', 'ilike', '%'.trim($term).'%')))
            ->orderByDesc('created_at')->limit($limit)->get()->map(fn ($m) => $this->row($m))->all();
    }

    public function create(array $data): string
    {
        return CouponModel::query()->create($this->columns($data))->id;
    }

    public function update(string $id, array $data): void
    {
        CouponModel::query()->findOrFail($id)->update($this->columns($data));
    }

    public function delete(string $id): void
    {
        CouponModel::query()->findOrFail($id)->delete();
    }

    public function codeTaken(string $code, ?string $exceptId = null): bool
    {
        return CouponModel::query()->where('code', $code)->when($exceptId, fn ($q) => $q->where('id', '<>', $exceptId))->exists();
    }

    public function timesUsedBy(string $couponId, ?string $userId): int
    {
        return $userId === null ? 0 : DB::table('coupon_usages')->where(['coupon_id' => $couponId, 'user_id' => $userId])->count();
    }

    public function redeem(string $couponId, ?string $userId, ?string $orderId, string $amount, string $currency): bool
    {
        return DB::transaction(function () use ($couponId, $userId, $orderId, $amount, $currency): bool {
            $coupon = DB::table('coupons')->where('id', $couponId)->whereNull('deleted_at')->lockForUpdate()->first();
            if ($coupon === null) {
                return false;
            }
            if ($orderId !== null && DB::table('coupon_usages')->where(['coupon_id' => $couponId, 'order_id' => $orderId])->exists()) {
                return true; // already redeemed for this order: retries must not burn a second use
            }
            if ($coupon->max_uses !== null && $coupon->uses_count >= $coupon->max_uses) {
                return false;
            }
            if ($coupon->max_uses_per_user !== null && ($userId === null || $this->timesUsedBy($couponId, $userId) >= $coupon->max_uses_per_user)) {
                return false;
            }

            DB::table('coupon_usages')->insert(['coupon_id' => $couponId, 'user_id' => $userId, 'order_id' => $orderId, 'discount_amount' => $amount, 'currency' => $currency]);
            DB::table('coupons')->where('id', $couponId)->increment('uses_count');

            return true;
        });
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function columns(array $data): array
    {
        if (array_key_exists('currency', $data)) {
            $data['currency_id'] = $data['currency'] === null ? null : DB::table('currencies')->where('code', $data['currency'])->value('id');
            unset($data['currency']);
        }

        return $data;
    }

    /** @return array<string, mixed>|null */
    private function row(?CouponModel $m): ?array
    {
        if ($m === null) {
            return null;
        }
        $trim = static fn (?string $v): ?string => $v === null ? null : (str_contains($v, '.') ? (rtrim(rtrim($v, '0'), '.') ?: '0') : $v);

        return [
            'id' => $m->id, 'code' => (string) $m->code, 'name' => $m->name, 'type' => $m->type, 'value' => $trim((string) $m->value),
            'currency' => $m->currency_id === null ? null : DB::table('currencies')->where('id', $m->currency_id)->value('code'),
            'min_spend' => $trim($m->min_spend), 'max_discount' => $trim($m->max_discount), 'max_uses' => $m->max_uses, 'max_uses_per_user' => $m->max_uses_per_user,
            'uses_count' => $m->uses_count, 'is_stackable' => $m->is_stackable, 'is_active' => $m->is_active,
            'starts_at' => $m->starts_at?->toDateTimeString(), 'expires_at' => $m->expires_at?->toDateTimeString(), 'restrictions' => $m->restrictions ?? [],
        ];
    }
}
