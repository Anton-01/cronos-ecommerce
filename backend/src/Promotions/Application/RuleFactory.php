<?php

declare(strict_types=1);

namespace Cronos\Promotions\Application;

use Cronos\Promotions\Domain\ValueObjects\CouponRule;
use Cronos\Promotions\Domain\ValueObjects\PromotionRule;
use Cronos\Promotions\Domain\ValueObjects\Restrictions;
use DateTimeImmutable;

/** Stored rows -> the immutable rules the domain reasons about. */
final class RuleFactory
{
    /** @param array<string, mixed> $r */
    public static function coupon(array $r): CouponRule
    {
        return new CouponRule(
            $r['id'], strtoupper($r['code']), $r['name'], $r['type'], (string) $r['value'], $r['currency'], $r['min_spend'], $r['max_discount'], $r['max_uses'], $r['max_uses_per_user'],
            (int) $r['uses_count'], (bool) $r['is_stackable'], (bool) $r['is_active'], self::date($r['starts_at']), self::date($r['expires_at']), Restrictions::fromArray($r['restrictions']),
        );
    }

    /** @param array<string, mixed> $r */
    public static function promotion(array $r): PromotionRule
    {
        return new PromotionRule(
            $r['id'], $r['name'], (int) $r['priority'], (bool) $r['is_stackable'], (bool) $r['is_active'],
            self::date($r['starts_at']), self::date($r['ends_at']), Restrictions::fromArray($r['conditions']), $r['action'],
        );
    }

    private static function date(?string $v): ?DateTimeImmutable
    {
        return $v === null ? null : new DateTimeImmutable($v);
    }
}
