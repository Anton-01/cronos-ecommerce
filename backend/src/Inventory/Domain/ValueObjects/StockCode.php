<?php

declare(strict_types=1);

namespace Cronos\Inventory\Domain\ValueObjects;

use Cronos\Inventory\Domain\Exceptions\InvalidMovement;

/**
 * The permanent identity of a stock-keeping item for the warehouse (what the QR label carries). Ten random characters plus one check character:
 * `7K3M9Q2XA4-6`. It is NOT derived from the name, the SKU or the slug, so renaming or re-coding a product never invalidates a printed label,
 * and it is never reused.
 *
 * The alphabet is Crockford's base32 (no I, L, O, U): nothing that is mistaken for a digit when read from a label. The check character catches a mistyped
 * character and most swapped neighbours before the database is asked.
 */
final readonly class StockCode
{
    private const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    private const BODY = 10;

    private function __construct(public string $value) {}

    public static function generate(): self
    {
        $body = '';
        // 5 bits per character, taken from a CSPRNG; rejection sampling is unnecessary because 256 is a multiple of 32.
        foreach (str_split(random_bytes(self::BODY)) as $byte) {
            $body .= self::ALPHABET[ord($byte) & 31];
        }

        return new self($body.self::check($body));
    }

    /** Accepts what a person or a scanner may hand over: lower case, spaces, the hyphen, and Crockford's look-alikes (I, L → 1; O → 0). */
    public static function normalize(string $input): ?string
    {
        $t = strtoupper(trim($input));
        $t = str_replace(['-', ' ', '_'], '', $t);
        $t = strtr($t, ['I' => '1', 'L' => '1', 'O' => '0']);
        if (strlen($t) !== self::BODY + 1 || strspn($t, self::ALPHABET) !== strlen($t)) {
            return null;
        }

        return self::check(substr($t, 0, self::BODY)) === $t[self::BODY] ? $t : null;
    }

    public static function parse(string $input): self
    {
        $code = self::normalize($input) ?? throw new InvalidMovement('That is not a valid stock code.', 'INVALID_STOCK_CODE');

        return new self($code);
    }

    public static function isValid(string $input): bool
    {
        return self::normalize($input) !== null;
    }

    /** `7K3M9Q2XA4-6`: how it is printed under the QR and typed by hand. */
    public function formatted(): string
    {
        return substr($this->value, 0, self::BODY).'-'.substr($this->value, self::BODY);
    }

    /** The scanner may read the QR's whole URL (`https://app/w/<code>`) or just the code: returns the normalized code either way. */
    public static function fromScan(string $scanned): ?string
    {
        if (preg_match('~/w/([A-Za-z0-9\-]{11,13})(?:[/?#]|$)~', trim($scanned), $m) === 1) {
            return self::normalize($m[1]);
        }

        return self::normalize($scanned);
    }

    private static function check(string $body): string
    {
        $sum = 0;
        foreach (str_split($body) as $i => $char) {
            $sum += (strpos(self::ALPHABET, $char) ?: 0) * ($i + 1);
        }

        return self::ALPHABET[$sum % 32];
    }
}
