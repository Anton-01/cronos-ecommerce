<?php

declare(strict_types=1);

namespace Cronos\Catalog\Domain\Services;

/**
 * Every field is in one of three states: it has a value, it does not apply, or it is still pending (the default of an empty field).
 * A value always wins over a stored state, so a field can never be "pending" and filled at once.
 */
final class SpecStates
{
    public const VALUE = 'value';

    public const PENDING = 'pending';

    public const NOT_APPLICABLE = 'not_applicable';

    /** @param array<string, mixed> $specs @param array<string, string> $states */
    public static function of(string $field, array $specs, array $states): string
    {
        if (! SpecFields::isEmpty($specs[$field] ?? null)) {
            return self::VALUE;
        }

        return ($states[$field] ?? null) === self::NOT_APPLICABLE ? self::NOT_APPLICABLE : self::PENDING;
    }

    /**
     * Required fields that are still pending ("not applicable" is an answer; "pending" is not).
     *
     * @param  list<string>  $required
     * @param  array<string, mixed>  $specs
     * @param  array<string, string>  $states
     * @return list<string>
     */
    public static function missing(array $required, array $specs, array $states): array
    {
        return array_values(array_filter($required, fn (string $f) => self::of($f, $specs, $states) === self::PENDING));
    }
}
