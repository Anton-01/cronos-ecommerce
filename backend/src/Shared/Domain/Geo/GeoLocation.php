<?php

declare(strict_types=1);

namespace Cronos\Shared\Domain\Geo;

/** Where a caller is, as far as their IP tells: country (ISO-3166 alpha-2, null when unknown) and the IANA timezone used for their clock. */
final readonly class GeoLocation
{
    public const SOURCE_RANGES = 'ranges';

    public const SOURCE_HEADER = 'header';

    public const SOURCE_FALLBACK = 'fallback';

    public function __construct(
        public ?string $ip,
        public ?string $country,
        public string $timezone,
        public string $source,
    ) {}

    /** "+00:00" style offset of the timezone at the given instant. */
    public function offsetAt(\DateTimeInterface $at): string
    {
        return (new \DateTimeImmutable('@'.$at->getTimestamp()))->setTimezone(new \DateTimeZone($this->timezone))->format('P');
    }
}
