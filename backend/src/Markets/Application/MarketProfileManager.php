<?php

declare(strict_types=1);

namespace Cronos\Markets\Application;

use Cronos\Markets\Domain\Exceptions\MarketError;
use Cronos\Markets\Domain\Ports\MarketProfileRepository;
use Cronos\Markets\Domain\Services\TaxIdRules;

final class MarketProfileManager
{
    public function __construct(private readonly MarketProfileRepository $profiles) {}

    /**
     * @param  array<string, mixed>  $in  legal_name, trade_name, tax_id, tax_id_label, tax_regime, fiscal_address, invoice_series, contact_email,
     *                                    contact_phone, support_hours, ops_email, shipping_flat, shipping_free_over (only the keys given change)
     * @return array<string, mixed> the profile
     */
    public function update(string $marketCode, array $in): array
    {
        $current = $this->profiles->profile($marketCode) ?? throw new MarketError('That market does not exist.', 'MARKET_NOT_FOUND');
        $changes = [];

        foreach (['legal_name' => 160, 'trade_name' => 120, 'tax_regime' => 120, 'tax_id_label' => 20, 'contact_phone' => 40, 'support_hours' => 120] as $field => $max) {
            if (array_key_exists($field, $in)) {
                $v = $this->text($in[$field]);
                $v === null || mb_strlen($v) <= $max || throw new MarketError("{$field} is too long (max {$max}).", 'INVALID_PROFILE');
                $changes[$field] = $v;
            }
        }
        if (array_key_exists('tax_id', $in)) {
            $v = $this->text($in['tax_id']);
            $changes['tax_id'] = $v === null ? null : TaxIdRules::normalize($current['country_code'], $v);
        }
        if (array_key_exists('invoice_series', $in)) {
            $v = $this->text($in['invoice_series']);
            $v === null || preg_match('/^[A-Za-z0-9\-]{1,12}$/', $v) === 1 || throw new MarketError('The invoice series is up to 12 letters, digits or dashes.', 'INVALID_PROFILE');
            $changes['invoice_series'] = $v === null ? null : strtoupper($v);
        }
        foreach (['contact_email', 'ops_email'] as $field) {
            if (array_key_exists($field, $in)) {
                $v = $this->text($in[$field]);
                $v === null || filter_var($v, FILTER_VALIDATE_EMAIL) !== false || throw new MarketError('That e-mail address is not valid.', 'INVALID_EMAIL');
                $changes[$field] = $v === null ? null : strtolower($v);
            }
        }
        foreach (['shipping_flat', 'shipping_free_over'] as $field) {
            if (array_key_exists($field, $in)) {
                $v = $this->text($in[$field]);
                $v === null || (is_numeric($v) && (float) $v >= 0 && (float) $v < 1_000_000) || throw new MarketError('Shipping amounts are zero or more.', 'INVALID_SHIPPING');
                $changes[$field] = $v;
            }
        }
        if (array_key_exists('fiscal_address', $in)) {
            $a = is_array($in['fiscal_address']) ? $in['fiscal_address'] : throw new MarketError('The fiscal address is an object.', 'INVALID_PROFILE');
            $clean = [];
            foreach (['street', 'exterior_no', 'interior_no', 'neighborhood', 'city', 'state', 'zip', 'country_code'] as $k) {
                $v = $this->text($a[$k] ?? null);
                $v === null || mb_strlen($v) <= 120 || throw new MarketError("Fiscal address {$k} is too long.", 'INVALID_PROFILE');
                $v === null || $clean[$k] = $v;
            }
            $changes['fiscal_address'] = $clean;
        }

        $changes === [] || $this->profiles->save((int) $current['market_id'], $changes);

        return $this->profiles->profile($marketCode);
    }

    /**
     * Which countries land on this market's store. A country can belong to one market only; the market's own country must stay.
     *
     * @param  list<string>  $countries
     */
    public function setCountries(string $marketCode, array $countries): array
    {
        $market = $this->profiles->profile($marketCode) ?? throw new MarketError('That market does not exist.', 'MARKET_NOT_FOUND');
        $countries = array_values(array_unique(array_map(static fn (string $c) => strtoupper(trim($c)), $countries)));
        foreach ($countries as $c) {
            preg_match('/^[A-Z]{2}$/', $c) === 1 || throw new MarketError("[{$c}] is not a two-letter country code.", 'INVALID_COUNTRY');
            $owner = $this->profiles->countryOwner($c);
            ($owner === null || $owner['market_id'] === (int) $market['market_id']) || throw new MarketError("{$c} already lands on the {$owner['code']} store: remove it there first.", 'COUNTRY_TAKEN');
        }
        in_array($market['country_code'], $countries, true) || throw new MarketError("The market's own country ({$market['country_code']}) must stay on its store.", 'OWN_COUNTRY_REQUIRED');
        $this->profiles->setCountries((int) $market['market_id'], $countries);

        return $this->profiles->profile($marketCode);
    }

    public function setFallback(string $marketCode): array
    {
        $market = $this->profiles->profile($marketCode) ?? throw new MarketError('That market does not exist.', 'MARKET_NOT_FOUND');
        $market['is_active'] || throw new MarketError('An inactive market cannot be the default store.', 'MARKET_INACTIVE');
        $this->profiles->setFallback((int) $market['market_id']);

        return $this->profiles->profile($marketCode);
    }

    private function text(mixed $v): ?string
    {
        if ($v === null) {
            return null;
        }
        $t = trim((string) $v);

        return $t === '' ? null : $t;
    }
}
