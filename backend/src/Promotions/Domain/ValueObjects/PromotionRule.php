<?php

declare(strict_types=1);

namespace Cronos\Promotions\Domain\ValueObjects;

use Cronos\Promotions\Domain\Exceptions\InvalidPromotion;
use DateTimeImmutable;

/**
 * An automatic discount. `action` is one of
 *   {type: percentage_off, value}          % off the matching lines
 *   {type: fixed_off, value, currency}     amount off the matching lines (only in that currency)
 *   {type: free_shipping}
 *   {type: buy_x_get_y, buy, get, percent} buy X units, get Y more at `percent` % off (100 = free)
 */
final class PromotionRule
{
    public const TYPES = ['percentage_off', 'fixed_off', 'free_shipping', 'buy_x_get_y'];

    /** @param array<string, mixed> $action */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly int $priority,
        public readonly bool $isStackable,
        public readonly bool $isActive,
        public readonly ?DateTimeImmutable $startsAt,
        public readonly ?DateTimeImmutable $endsAt,
        public readonly Restrictions $conditions,
        public readonly array $action,
    ) {}

    /** @param array<string, mixed> $action @return array<string, mixed> normalised action */
    public static function validateAction(array $action): array
    {
        $type = $action['type'] ?? null;
        in_array($type, self::TYPES, true) || throw new InvalidPromotion('Unknown promotion action.');

        $positive = static fn (mixed $v): bool => is_numeric($v) && (float) $v > 0;

        return match ($type) {
            'percentage_off' => $positive($action['value'] ?? null) && (float) $action['value'] <= 100
                ? ['type' => $type, 'value' => (string) $action['value']] : throw new InvalidPromotion('A percentage must be above 0 and at most 100.'),
            'fixed_off' => $positive($action['value'] ?? null) && preg_match('/^[A-Za-z]{3}$/', (string) ($action['currency'] ?? ''))
                ? ['type' => $type, 'value' => (string) $action['value'], 'currency' => strtoupper((string) $action['currency'])] : throw new InvalidPromotion('A fixed discount needs an amount and a currency.'),
            'free_shipping' => ['type' => $type],
            'buy_x_get_y' => (int) ($action['buy'] ?? 0) >= 1 && (int) ($action['get'] ?? 0) >= 1 && $positive($action['percent'] ?? null) && (float) $action['percent'] <= 100
                ? ['type' => $type, 'buy' => (int) $action['buy'], 'get' => (int) $action['get'], 'percent' => (string) $action['percent']] : throw new InvalidPromotion('Buy X get Y needs buy >= 1, get >= 1 and a discount between 0 and 100 %.'),
        };
    }
}
