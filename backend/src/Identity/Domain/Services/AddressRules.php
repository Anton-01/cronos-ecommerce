<?php

declare(strict_types=1);

namespace Cronos\Identity\Domain\Services;

use Cronos\Identity\Domain\Exceptions\AddressError;

/** What a saved address must look like. Pure. */
final class AddressRules
{
    public const MAX_PER_PERSON = 20;

    public const TYPES = ['shipping', 'billing'];

    private const REQUIRED = ['recipient_name' => 160, 'street' => 160, 'city' => 100, 'state' => 100, 'zip' => 16];

    private const OPTIONAL = ['label' => 60, 'phone' => 32, 'exterior_no' => 32, 'interior_no' => 32, 'neighborhood' => 120, 'references' => 500, 'business_name' => 160];

    /**
     * @param  array<string, mixed>  $in  snake_case keys; only keys present are checked when $partial
     * @return array<string, mixed> clean values ready to store
     */
    public static function normalize(array $in, bool $partial = false): array
    {
        $out = [];
        $errors = [];
        foreach (self::REQUIRED as $field => $max) {
            if ($partial && ! array_key_exists($field, $in)) {
                continue;
            }
            $v = trim((string) ($in[$field] ?? ''));
            $v === '' && $errors[$field] = 'Obligatorio.';
            mb_strlen($v) > $max && $errors[$field] = "Máximo {$max} caracteres.";
            $out[$field] = $v;
        }
        foreach (self::OPTIONAL as $field => $max) {
            if (! array_key_exists($field, $in)) {
                continue;
            }
            $v = trim((string) ($in[$field] ?? ''));
            mb_strlen($v) > $max && $errors[$field] = "Máximo {$max} caracteres.";
            $out[$field] = $v === '' ? null : $v;
        }
        if (! $partial || array_key_exists('country_code', $in)) {
            $country = strtoupper(trim((string) ($in['country_code'] ?? '')));
            preg_match('/^[A-Z]{2}$/', $country) === 1 || $errors['country_code'] = 'Código de país de dos letras.';
            $out['country_code'] = $country;
        }
        if (array_key_exists('tax_id', $in)) {
            $tax = strtoupper(preg_replace('/\s+/', '', (string) ($in['tax_id'] ?? '')) ?? '');
            preg_match('/^[A-Z0-9&\-]{0,20}$/', $tax) === 1 || $errors['tax_id'] = 'Identificador fiscal no válido.';
            $out['tax_id'] = $tax === '' ? null : $tax;
        }
        if (! $partial || array_key_exists('type', $in)) {
            $type = (string) ($in['type'] ?? 'shipping');
            in_array($type, self::TYPES, true) || $errors['type'] = 'shipping o billing.';
            $out['type'] = $type;
        }
        $errors === [] || throw new AddressError('The address is not valid.', 'INVALID_ADDRESS', $errors);

        return $out;
    }
}
