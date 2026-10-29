<?php

declare(strict_types=1);

namespace Cronos\Shared\Interfaces\GraphQL;

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Cronos\Shared\Infrastructure\Geo\CurrentClient;
use GraphQL\Error\Error;
use GraphQL\Error\InvariantViolation;
use GraphQL\Language\AST\Node;
use GraphQL\Language\AST\StringValueNode;
use GraphQL\Type\Definition\ScalarType;

/**
 * Every instant is stored and computed in UTC; the API speaks the caller's clock, decided by their IP.
 *
 *   output  an ISO-8601 string with the caller's UTC offset: 2026-06-01T12:30:00-06:00
 *   input   with an offset (or Z) it is taken as that exact instant; without one ("2026-06-01 12:30:00" or
 *           "2026-06-01T12:30") it is the caller's local wall-clock time. Either way it reaches the code as UTC.
 */
final class ZonedDateTime extends ScalarType
{
    public ?string $description = 'An instant. Serialised in the caller\'s timezone (from their IP) as ISO-8601 with offset; naive input is read in that timezone.';

    public function serialize($value): string
    {
        return $this->toInstant($value)->setTimezone($this->timezone())->format('Y-m-d\TH:i:sP');
    }

    public function parseValue($value): Carbon
    {
        is_string($value) || throw new Error('A DateTime must be a string.');

        return $this->parse($value);
    }

    public function parseLiteral(Node $valueNode, ?array $variables = null): Carbon
    {
        $valueNode instanceof StringValueNode || throw new Error('A DateTime must be a string.', [$valueNode]);

        return $this->parse($valueNode->value);
    }

    private function parse(string $value): Carbon
    {
        $value = trim($value);
        $hasOffset = preg_match('/(Z|[+-]\d{2}(:?\d{2})?)$/', $value) === 1 && preg_match('/\d{2}:\d{2}/', $value) === 1;

        try {
            $parsed = $hasOffset ? CarbonImmutable::parse($value) : CarbonImmutable::parse($value, $this->timezone());
        } catch (\Throwable) {
            throw new Error("[{$value}] is not a valid date and time.");
        }

        return Carbon::instance($parsed->utc());
    }

    private function toInstant(mixed $value): CarbonImmutable
    {
        return match (true) {
            $value instanceof \DateTimeInterface => CarbonImmutable::instance($value),
            is_string($value) && preg_match('/(Z|[+-]\d{2}(:?\d{2})?)$/', $value) === 1 && str_contains($value, ':') => CarbonImmutable::parse($value),
            // Stored values are UTC: a naive string from the database is a UTC instant, not the caller's wall clock.
            is_string($value) => CarbonImmutable::parse($value, 'UTC'),
            default => throw new InvariantViolation('Cannot serialise '.get_debug_type($value).' as a DateTime.'),
        };
    }

    private function timezone(): string
    {
        return app(CurrentClient::class)->location()->timezone;
    }
}
