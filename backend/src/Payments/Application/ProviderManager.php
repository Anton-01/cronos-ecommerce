<?php

declare(strict_types=1);

namespace Cronos\Payments\Application;

use Cronos\Payments\Domain\Exceptions\NotFound;
use Cronos\Payments\Domain\Exceptions\PaymentError;
use Cronos\Payments\Domain\Ports\GatewayFactory;
use Cronos\Payments\Domain\Ports\ProviderRepository;
use Cronos\Payments\Domain\ValueObjects\Provider;
use Cronos\Pricing\Domain\Ports\PricingCatalog;

/**
 * Configures the gateways from the back-office, including the switch between the test environment (sandbox) and real money (live).
 * Both credential sets are kept; `mode` chooses which one is used. Payments remember the mode they were created in.
 */
final class ProviderManager
{
    public const GO_LIVE_PHRASE = 'PASAR A PRODUCCION';

    public function __construct(
        private readonly ProviderRepository $providers,
        private readonly GatewayFactory $gateways,
        private readonly PricingCatalog $pricing,
    ) {}

    /**
     * @param  array{is_enabled?: bool, markets?: list<string>, min_amount?: ?string, max_amount?: ?string}  $settings
     */
    public function configure(string $code, array $settings): void
    {
        $current = $this->providers->find($code) ?? throw new NotFound('The provider');
        $changes = [];

        if (array_key_exists('markets', $settings)) {
            $known = array_column($this->pricing->snapshot()['markets'], 'code');
            $markets = array_values(array_unique(array_map('strtoupper', (array) $settings['markets'])));
            ($unknown = array_diff($markets, $known)) === [] || throw new PaymentError('Unknown market: '.implode(', ', $unknown).'.', 'UNKNOWN_MARKET');
            $changes['markets'] = $markets;
        }
        foreach (['min_amount', 'max_amount'] as $limit) {
            if (array_key_exists($limit, $settings)) {
                $v = $settings[$limit];
                ($v === null || $v === '' || (is_numeric($v) && (float) $v >= 0)) || throw new PaymentError('Limits must be zero or more.', 'INVALID_LIMIT');
                $changes[$limit] = $v === '' ? null : $v;
            }
        }
        $min = $changes['min_amount'] ?? $current['min_amount'];
        $max = $changes['max_amount'] ?? $current['max_amount'];
        ($min === null || $max === null || (float) $min <= (float) $max) || throw new PaymentError('The minimum cannot be above the maximum.', 'INVALID_LIMIT');

        if (isset($settings['is_enabled'])) {
            if ($settings['is_enabled'] === true) {
                $missing = $this->missing($current['credentials'][$current['mode']], $code);
                $missing === [] || throw new PaymentError('Cannot enable '.ProviderCatalog::NAMES[$code]." in {$current['mode']} mode: missing ".implode(', ', $missing).'.', 'CREDENTIALS_INCOMPLETE');
            }
            $changes['is_enabled'] = (bool) $settings['is_enabled'];
        }

        $changes === [] || $this->providers->update($code, $changes);
    }

    /**
     * Replaces some credentials of one mode. Fields not mentioned (or sent empty) keep their stored value, so the screen never
     * has to know a secret to save a change.
     *
     * @param  array<string, ?string>  $values
     */
    public function saveCredentials(string $code, string $mode, array $values): void
    {
        Provider::assertMode($mode);
        $current = $this->providers->find($code) ?? throw new NotFound('The provider');
        $fields = ProviderCatalog::FIELDS[$code];
        $merged = $current['credentials'][$mode];

        foreach ($values as $field => $value) {
            isset($fields[$field]) || throw new PaymentError("Unknown credential [{$field}] for ".ProviderCatalog::NAMES[$code].'.', 'UNKNOWN_CREDENTIAL');
            $value = $value === null ? '' : trim($value);
            if ($value === '') {
                continue;
            }
            $pattern = $fields[$field]['pattern'][$mode] ?? null;
            $pattern === null || preg_match($pattern, $value) === 1 || throw new PaymentError("{$fields[$field]['label']} does not look like a {$mode} value (wrong environment?).", 'CREDENTIAL_ENVIRONMENT_MISMATCH');
            $merged[$field] = $value;
        }

        $this->providers->saveCredentials($code, $mode, $merged);
    }

    /** Asks the provider something harmless with the credentials of a mode. @return string human-readable result */
    public function testConnection(string $code, string $mode): string
    {
        Provider::assertMode($mode);
        $this->providers->find($code) ?? throw new NotFound('The provider');

        return $this->gateways->make($code, $mode)->testConnection();
    }

    /** @param string|null $confirmation must be GO_LIVE_PHRASE when going live */
    public function switchMode(string $code, string $target, ?string $confirmation, ?string $userId): void
    {
        Provider::assertMode($target);
        $current = $this->providers->find($code) ?? throw new NotFound('The provider');
        if ($current['mode'] === $target) {
            return;
        }

        if ($target === Provider::LIVE) {
            self::normalise((string) $confirmation) === self::normalise(self::GO_LIVE_PHRASE) || throw new PaymentError('Type "'.self::GO_LIVE_PHRASE.'" to confirm real charges.', 'CONFIRMATION_REQUIRED');
            ($missing = $this->missing($current['credentials']['live'], $code)) === [] || throw new PaymentError('Live credentials are incomplete: '.implode(', ', $missing).'.', 'CREDENTIALS_INCOMPLETE');
            $this->testConnection($code, Provider::LIVE); // a wrong live key must fail here, not on the first customer
            $this->providers->update($code, ['mode' => Provider::LIVE, 'live_since' => now(), 'live_enabled_by' => $userId]);

            return;
        }

        $this->providers->update($code, ['mode' => Provider::SANDBOX]);
    }

    /** @return list<string> */
    private function missing(array $credentials, string $code): array
    {
        return array_values(array_filter(ProviderCatalog::requiredFields($code), fn (string $f) => ($credentials[$f] ?? '') === ''));
    }

    private static function normalise(string $text): string
    {
        return strtoupper(trim(preg_replace('/\s+/', ' ', strtr($text, ['Ó' => 'O', 'ó' => 'o', 'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ú' => 'U'])) ?? ''));
    }
}
