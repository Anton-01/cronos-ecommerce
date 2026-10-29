<?php

declare(strict_types=1);

namespace Cronos\Fraud\Domain\ValueObjects;

use Cronos\Fraud\Domain\Exceptions\InvalidFraudRule;
use Cronos\Fraud\Domain\Ports\FraudSignals;

final class FraudRule
{
    public const TYPES = ['velocity_ip', 'velocity_email', 'failed_payments', 'amount_over', 'country_mismatch', 'high_risk_country'];

    /** @param array<string, mixed> $params */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $type,
        public readonly array $params,
        public readonly int $score,
        public readonly bool $isActive = true,
    ) {}

    /** @param array<string, mixed> $params @return array<string, mixed> normalised */
    public static function validateParams(string $type, array $params): array
    {
        in_array($type, self::TYPES, true) || throw new InvalidFraudRule('Unknown fraud rule type.');

        switch ($type) {
            case 'velocity_ip':
            case 'velocity_email':
            case 'failed_payments':
                $max = (int) ($params['max'] ?? 0);
                $window = (int) ($params['window_minutes'] ?? 0);
                ($max >= 1 && $window >= 1 && $window <= FraudSignals::MAX_WINDOW_MINUTES) || throw new InvalidFraudRule('Needs max >= 1 and a window between 1 and '.FraudSignals::MAX_WINDOW_MINUTES.' minutes.');

                return ['max' => $max, 'window_minutes' => $window];
            case 'amount_over':
                $amounts = $params['amounts'] ?? [];
                (is_array($amounts) && $amounts !== []) || throw new InvalidFraudRule('Needs a threshold per currency, e.g. {"MXN": "15000"}.');
                $clean = [];
                foreach ($amounts as $currency => $amount) {
                    (preg_match('/^[A-Za-z]{3}$/', (string) $currency) && is_numeric($amount) && (float) $amount > 0) || throw new InvalidFraudRule('Thresholds must be positive amounts keyed by currency code.');
                    $clean[strtoupper((string) $currency)] = (string) $amount;
                }

                return ['amounts' => $clean];
            case 'high_risk_country':
                $countries = array_values(array_unique(array_map(fn ($c) => strtoupper((string) $c), (array) ($params['countries'] ?? []))));
                ($countries !== [] && array_filter($countries, fn ($c) => ! preg_match('/^[A-Z]{2}$/', $c)) === []) || throw new InvalidFraudRule('Needs a list of two-letter country codes.');

                return ['countries' => $countries];
            default:
                return [];
        }
    }
}
