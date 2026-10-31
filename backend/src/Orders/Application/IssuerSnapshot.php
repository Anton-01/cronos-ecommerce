<?php

declare(strict_types=1);

namespace Cronos\Orders\Application;

use Cronos\Markets\Domain\Ports\MarketProfileRepository;

/** Who sold, as of the moment of the sale: the market's fiscal identity copied into the order so a later edit never rewrites an invoice. */
final class IssuerSnapshot
{
    public function __construct(private readonly MarketProfileRepository $profiles) {}

    /** @return array<string, mixed> */
    public function forMarket(string $marketCode): array
    {
        $p = $this->profiles->profile($marketCode);
        if ($p === null) {
            return [];
        }

        return [
            'market' => $p['code'], 'legal_name' => $p['legal_name'], 'trade_name' => $p['trade_name'], 'tax_id' => $p['tax_id'], 'tax_id_label' => $p['tax_id_label'],
            'tax_regime' => $p['tax_regime'], 'fiscal_address' => $p['fiscal_address'], 'invoice_series' => $p['invoice_series'],
            'contact_email' => $p['contact_email'], 'contact_phone' => $p['contact_phone'],
        ];
    }
}
