<?php

declare(strict_types=1);

namespace Cronos\Payments\Domain\Services;

/** Removes secrets and card data from anything we are about to store or log. Defence in depth: gateways should not send them anyway. */
final class Scrubber
{
    private const SENSITIVE = '/(secret|token|authorization|password|card.?number|\bpan\b|cvv|cvc|security.?code|client_secret|api.?key|access_token|refresh_token|number$)/i';

    private const MAX_DEPTH = 8;

    private const MAX_STRING = 2000;

    /** @param array<mixed> $data @return array<mixed> */
    public static function scrub(array $data, int $depth = 0): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            if (is_string($key) && preg_match(self::SENSITIVE, $key) === 1) {
                $out[$key] = '[redacted]';
            } elseif (is_array($value)) {
                $out[$key] = $depth >= self::MAX_DEPTH ? '[truncated]' : self::scrub($value, $depth + 1);
            } elseif (is_string($value)) {
                $out[$key] = mb_strlen($value) > self::MAX_STRING ? mb_substr($value, 0, self::MAX_STRING).'…' : $value;
            } else {
                $out[$key] = $value;
            }
        }

        return $out;
    }
}
