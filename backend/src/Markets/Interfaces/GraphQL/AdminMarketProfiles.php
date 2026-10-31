<?php

declare(strict_types=1);

namespace Cronos\Markets\Interfaces\GraphQL;

use Cronos\Markets\Application\MarketProfileManager;
use Cronos\Markets\Domain\Ports\MarketProfileRepository;

final class AdminMarketProfiles
{
    public function __construct(private readonly MarketProfileManager $manager, private readonly MarketProfileRepository $profiles) {}

    public function list(): array
    {
        return array_map($this->present(...), $this->profiles->profiles());
    }

    /** @param array<string, mixed> $args */
    public function update(mixed $root, array $args): array
    {
        $in = $args['input'];
        $map = ['legalName' => 'legal_name', 'tradeName' => 'trade_name', 'taxId' => 'tax_id', 'taxIdLabel' => 'tax_id_label', 'taxRegime' => 'tax_regime', 'invoiceSeries' => 'invoice_series',
            'contactEmail' => 'contact_email', 'contactPhone' => 'contact_phone', 'supportHours' => 'support_hours', 'opsEmail' => 'ops_email', 'shippingFlat' => 'shipping_flat', 'shippingFreeOver' => 'shipping_free_over'];
        $data = [];
        foreach ($map as $graph => $column) {
            array_key_exists($graph, $in) && $data[$column] = $in[$graph];
        }
        if (array_key_exists('fiscalAddress', $in)) {
            $a = (array) $in['fiscalAddress'];
            $data['fiscal_address'] = ['street' => $a['street'] ?? null, 'exterior_no' => $a['exteriorNo'] ?? null, 'interior_no' => $a['interiorNo'] ?? null, 'neighborhood' => $a['neighborhood'] ?? null,
                'city' => $a['city'] ?? null, 'state' => $a['state'] ?? null, 'zip' => $a['zip'] ?? null, 'country_code' => $a['countryCode'] ?? null];
        }

        return $this->present($this->manager->update($args['code'], $data));
    }

    /** @param array<string, mixed> $args */
    public function countries(mixed $root, array $args): array
    {
        return $this->present($this->manager->setCountries($args['code'], $args['countries']));
    }

    /** @param array<string, mixed> $args */
    public function fallback(mixed $root, array $args): array
    {
        return $this->present($this->manager->setFallback($args['code']));
    }

    /** @param array<string, mixed> $p @return array<string, mixed> */
    private function present(array $p): array
    {
        $links = $this->profiles->links($p['code']);
        $a = $p['fiscal_address'];

        return [
            'marketId' => $p['market_id'], 'code' => $p['code'], 'name' => $p['name'], 'countryCode' => $p['country_code'], 'currencyId' => $p['currency_id'], 'defaultLocale' => $p['default_locale'],
            'pricesIncludeTax' => $p['prices_include_tax'], 'isActive' => $p['is_active'], 'legalName' => $p['legal_name'], 'tradeName' => $p['trade_name'], 'taxId' => $p['tax_id'],
            'taxIdLabel' => $p['tax_id_label'], 'taxRegime' => $p['tax_regime'], 'invoiceSeries' => $p['invoice_series'], 'contactEmail' => $p['contact_email'], 'contactPhone' => $p['contact_phone'],
            'supportHours' => $p['support_hours'], 'opsEmail' => $p['ops_email'], 'shippingFlat' => $p['shipping_flat'], 'shippingFreeOver' => $p['shipping_free_over'], 'isFallback' => $p['is_fallback'],
            'countries' => $p['countries'], 'fiscalAddress' => StorefrontMarkets::address($a),
            'warehouses' => array_map(static fn (array $w) => ['id' => $w['id'], 'code' => $w['code'], 'name' => $w['name'], 'isActive' => $w['is_active']], $links['warehouses']),
            'paymentProviders' => $links['payment_providers'], 'taxRateCount' => $links['tax_rates'],
        ];
    }
}
