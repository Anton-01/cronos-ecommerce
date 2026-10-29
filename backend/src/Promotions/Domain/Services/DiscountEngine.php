<?php

declare(strict_types=1);

namespace Cronos\Promotions\Domain\Services;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Cronos\Pricing\Domain\ValueObjects\Money;
use Cronos\Promotions\Domain\ValueObjects\Cart;
use Cronos\Promotions\Domain\ValueObjects\CouponRule;
use Cronos\Promotions\Domain\ValueObjects\DiscountResult;
use Cronos\Promotions\Domain\ValueObjects\PromotionRule;
use Cronos\Promotions\Domain\ValueObjects\Restrictions;
use DateTimeImmutable;

/**
 * Automatic promotions first (by priority; a non-stackable one stands alone), then the coupon on what is left.
 * A coupon that is not stackable competes with the promotions instead: the shopper gets whichever saves more.
 * A discount never exceeds what it applies to, so a total can never go negative.
 */
final class DiscountEngine
{
    /**
     * @param  list<PromotionRule>  $promotions
     * @param  ?CouponRule  $coupon  already checked with CouponPolicy
     */
    public function evaluate(Cart $cart, array $promotions, ?CouponRule $coupon, DateTimeImmutable $at): DiscountResult
    {
        $withPromotions = $this->applyPromotions($this->fresh($cart), $cart, $promotions, $at);
        if ($coupon === null) {
            return $this->result($cart, $withPromotions);
        }

        if ($coupon->isStackable) {
            return $this->result($cart, $this->applyCoupon($withPromotions, $cart, $coupon));
        }

        $couponOnly = $this->applyCoupon($this->fresh($cart), $cart, $coupon);
        $a = $this->result($cart, $withPromotions);
        $b = $this->result($cart, $couponOnly);

        return $b->totalDiscount()->isGreaterThan($a->totalDiscount()) ? $b : $a;
    }

    /** @return array{remaining: array<string, Money>, discounts: array<string, Money>, shipping: Money, applied: list<array<string, mixed>>} */
    private function fresh(Cart $cart): array
    {
        $zero = Money::zero($cart->currency, $cart->decimals);
        $remaining = [];
        $discounts = [];
        foreach ($cart->lines as $line) {
            $remaining[$line->id] = $line->total;
            $discounts[$line->id] = $zero;
        }

        return ['remaining' => $remaining, 'discounts' => $discounts, 'shipping' => $zero, 'applied' => []];
    }

    /**
     * @param  array<string, mixed>  $state
     * @param  list<PromotionRule>  $promotions
     * @return array<string, mixed>
     */
    private function applyPromotions(array $state, Cart $cart, array $promotions, DateTimeImmutable $at): array
    {
        usort($promotions, static fn (PromotionRule $a, PromotionRule $b): int => $a->priority <=> $b->priority);
        $subtotal = $cart->subtotal();

        foreach ($promotions as $p) {
            $active = $p->isActive && ($p->startsAt === null || $p->startsAt <= $at) && ($p->endsAt === null || $at < $p->endsAt);
            $c = $p->conditions;
            if (! $active || ! $c->appliesToMarket($cart->market) || ($c->firstOrderOnly && ! $cart->isFirstOrder)) {
                continue;
            }
            if ($c->minSubtotal !== null && $subtotal->amount->isLessThan($c->minSubtotal)) {
                continue;
            }
            if (! $p->isStackable && $state['applied'] !== []) {
                continue;
            }

            $before = $this->total($state);
            $state = $this->applyAction($state, $cart, $p->action, $p->conditions, $p->name, 'promotion', $p->id);
            if ($this->total($state)->isGreaterThan($before) && ! $p->isStackable) {
                break;
            }
        }

        return $state;
    }

    /** @param array<string, mixed> $state @return array<string, mixed> */
    private function applyCoupon(array $state, Cart $cart, CouponRule $coupon): array
    {
        $action = match ($coupon->type) {
            'percentage' => ['type' => 'percentage_off', 'value' => $coupon->value, 'cap' => $coupon->maxDiscount],
            'fixed' => ['type' => 'fixed_off', 'value' => $coupon->value, 'currency' => $coupon->currency, 'cap' => $coupon->maxDiscount],
            default => ['type' => 'free_shipping'],
        };

        return $this->applyAction($state, $cart, $action, $coupon->restrictions, $coupon->name, 'coupon', $coupon->id);
    }

    /**
     * @param  array<string, mixed>  $state
     * @param  array<string, mixed>  $action
     * @return array<string, mixed>
     */
    private function applyAction(array $state, Cart $cart, array $action, Restrictions $scope, string $name, string $kind, string $id): array
    {
        $zero = Money::zero($cart->currency, $cart->decimals);
        $eligible = [];
        foreach ($cart->lines as $line) {
            if ($scope->matches($line) && ! $state['remaining'][$line->id]->isZero()) {
                $eligible[$line->id] = $line;
            }
        }

        /** @var array<string, Money> $perLine */
        $perLine = [];
        $shipping = $zero;

        switch ($action['type']) {
            case 'percentage_off':
                foreach ($eligible as $lineId => $line) {
                    $perLine[$lineId] = $state['remaining'][$lineId]->percent((string) $action['value']);
                }
                if (isset($action['cap']) && $action['cap'] !== null) {
                    $perLine = $this->capTotal($perLine, new Money($action['cap'], $cart->currency, $cart->decimals));
                }
                break;
            case 'fixed_off':
                if (strtoupper((string) $action['currency']) !== $cart->currency || $eligible === []) {
                    return $state;
                }
                $pool = array_reduce(array_map(fn ($l) => $state['remaining'][$l->id], $eligible), static fn (Money $c, Money $m): Money => $c->plus($m), $zero);
                $amount = (new Money($action['value'], $cart->currency, $cart->decimals))->min($pool);
                if (isset($action['cap']) && $action['cap'] !== null) {
                    $amount = $amount->min(new Money($action['cap'], $cart->currency, $cart->decimals));
                }
                $perLine = $this->allocate($amount, array_map(fn ($l) => $state['remaining'][$l->id], $eligible));
                break;
            case 'free_shipping':
                $shipping = $cart->shipping->minus($state['shipping']);
                break;
            case 'buy_x_get_y':
                foreach ($eligible as $lineId => $line) {
                    $qty = BigDecimal::of($line->quantity)->toScale(0, RoundingMode::Down);
                    $group = (int) $action['buy'] + (int) $action['get'];
                    $freeUnits = $qty->dividedBy($group, 0, RoundingMode::Down)->multipliedBy((int) $action['get']);
                    if ($freeUnits->isZero()) {
                        continue;
                    }
                    $unit = $line->total->amount->dividedBy($line->quantity, $cart->decimals + 6, RoundingMode::HalfUp);
                    $off = (new Money($unit->multipliedBy($freeUnits), $cart->currency, $cart->decimals))->percent((string) $action['percent']);
                    $perLine[$lineId] = $off->min($state['remaining'][$lineId]);
                }
                break;
        }

        $applied = $zero;
        foreach ($perLine as $lineId => $discount) {
            if ($discount->isZero()) {
                continue;
            }
            $state['remaining'][$lineId] = $state['remaining'][$lineId]->minus($discount);
            $state['discounts'][$lineId] = $state['discounts'][$lineId]->plus($discount);
            $applied = $applied->plus($discount);
        }
        if (! $shipping->isZero()) {
            $state['shipping'] = $state['shipping']->plus($shipping);
            $applied = $applied->plus($shipping);
        }
        if (! $applied->isZero()) {
            $state['applied'][] = ['kind' => $kind, 'id' => $id, 'name' => $name, 'amount' => $applied];
        }

        return $state;
    }

    /**
     * Splits an amount across weights without losing a cent: floor shares, leftover cents go to the first lines.
     *
     * @param  array<string, Money>  $weights
     * @return array<string, Money>
     */
    private function allocate(Money $total, array $weights): array
    {
        $sum = array_reduce($weights, static fn (BigDecimal $c, Money $m): BigDecimal => $c->plus($m->amount), BigDecimal::zero());
        $unit = BigDecimal::of(1)->dividedBy(10 ** $total->decimals, $total->decimals, RoundingMode::Down);
        $shares = [];
        $given = BigDecimal::zero();
        foreach ($weights as $id => $w) {
            $share = $sum->isZero() ? BigDecimal::zero() : $total->amount->multipliedBy($w->amount)->dividedBy($sum, $total->decimals, RoundingMode::Down);
            $shares[$id] = $share;
            $given = $given->plus($share);
        }
        $left = $total->amount->minus($given);
        foreach ($shares as $id => $share) {
            if ($left->isLessThanOrEqualTo(0)) {
                break;
            }
            if ($share->isLessThan($weights[$id]->amount)) {
                $shares[$id] = $share->plus($unit);
                $left = $left->minus($unit);
            }
        }

        return array_map(fn (BigDecimal $s) => new Money($s, $total->currency, $total->decimals), $shares);
    }

    /** @param array<string, Money> $perLine @return array<string, Money> */
    private function capTotal(array $perLine, Money $cap): array
    {
        $sum = array_reduce($perLine, static fn (?Money $c, Money $m): Money => $c === null ? $m : $c->plus($m));
        if ($sum === null || ! $sum->isGreaterThan($cap)) {
            return $perLine;
        }

        return $this->allocate($cap, $perLine);
    }

    /** @param array<string, mixed> $state */
    private function total(array $state): Money
    {
        $sum = $state['shipping'];
        foreach ($state['discounts'] as $d) {
            $sum = $sum->plus($d);
        }

        return $sum;
    }

    /** @param array<string, mixed> $state */
    private function result(Cart $cart, array $state): DiscountResult
    {
        return new DiscountResult($cart->subtotal(), $state['discounts'], $state['shipping'], $state['applied']);
    }
}
