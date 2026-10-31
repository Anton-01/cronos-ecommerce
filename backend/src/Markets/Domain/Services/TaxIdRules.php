<?php

declare(strict_types=1);

namespace Cronos\Markets\Domain\Services;

use Cronos\Markets\Domain\Exceptions\MarketError;

/** The fiscal identifier of the seller, checked by the rules of the market's country (RFC in Mexico, EIN in the US). */
final class TaxIdRules
{
    /** @return string the identifier in its canonical form */
    public static function normalize(string $country, string $taxId): string
    {
        $id = strtoupper(trim(preg_replace('/\s+/', '', $taxId) ?? ''));

        switch (strtoupper($country)) {
            case 'MX':
                // Moral (12) or física (13) person; the homoclave closes it.
                preg_match('/^[A-ZÑ&]{3,4}\d{6}[A-Z0-9]{3}$/u', $id) === 1 || throw new MarketError('That RFC does not look right: 12 characters for a company, 13 for a person.', 'INVALID_TAX_ID');

                return $id;
            case 'US':
                $digits = str_replace('-', '', $id);
                preg_match('/^\d{9}$/', $digits) === 1 || throw new MarketError('An EIN has 9 digits (NN-NNNNNNN).', 'INVALID_TAX_ID');

                return substr($digits, 0, 2).'-'.substr($digits, 2);
            default:
                preg_match('/^[A-Z0-9][A-Z0-9\-.\/]{3,29}$/', $id) === 1 || throw new MarketError('The tax identifier has 4 to 30 letters, digits, dashes or dots.', 'INVALID_TAX_ID');

                return $id;
        }
    }
}
