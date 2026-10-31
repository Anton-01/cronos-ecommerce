<?php

declare(strict_types=1);

namespace Cronos\Reports\Domain\ValueObjects;

use Cronos\Reports\Domain\Exceptions\ReportError;
use DateTimeImmutable;
use DateTimeZone;

/**
 * A reporting window in the viewer's own calendar ("today" means today where they are) expressed as UTC instants, with the equally long
 * window right before it for comparison. `to` is exclusive.
 */
final readonly class Period
{
    public const MAX_DAYS = 400;

    public function __construct(
        public DateTimeImmutable $from,
        public DateTimeImmutable $to,
        public DateTimeImmutable $previousFrom,
        public DateTimeImmutable $previousTo,
        public string $timezone,
        public string $label,
    ) {}

    public static function preset(string $preset, string $timezone, ?DateTimeImmutable $now = null, ?string $fromDate = null, ?string $toDate = null): self
    {
        $tz = new DateTimeZone(in_array($timezone, DateTimeZone::listIdentifiers(), true) ? $timezone : 'UTC');
        $today = ($now ?? new DateTimeImmutable)->setTimezone($tz)->setTime(0, 0);

        [$from, $toExclusive] = match ($preset) {
            'TODAY' => [$today, $today->modify('+1 day')],
            'YESTERDAY' => [$today->modify('-1 day'), $today],
            'LAST_7_DAYS' => [$today->modify('-6 days'), $today->modify('+1 day')],
            'LAST_30_DAYS' => [$today->modify('-29 days'), $today->modify('+1 day')],
            'THIS_MONTH' => [$today->modify('first day of this month'), $today->modify('+1 day')],
            'LAST_MONTH' => [$today->modify('first day of last month'), $today->modify('first day of this month')],
            'CUSTOM' => self::custom($fromDate, $toDate, $tz),
            default => throw new ReportError("Unknown period [{$preset}].", 'INVALID_PERIOD'),
        };

        $days = max(1, (int) round(($toExclusive->getTimestamp() - $from->getTimestamp()) / 86400));
        $days <= self::MAX_DAYS || throw new ReportError('A report covers at most '.self::MAX_DAYS.' days.', 'PERIOD_TOO_LONG');

        $utc = new DateTimeZone('UTC');
        // The previous window is as many calendar days back, so a month is compared with the month before, not with 30 days.
        $prevFrom = $preset === 'LAST_MONTH' || $preset === 'THIS_MONTH' ? $from->modify('-1 month') : $from->modify("-{$days} days");
        $prevTo = $preset === 'THIS_MONTH' ? $prevFrom->modify('+'.$days.' days') : $from;

        return new self($from->setTimezone($utc), $toExclusive->setTimezone($utc), $prevFrom->setTimezone($utc), $prevTo->setTimezone($utc), $tz->getName(), $preset);
    }

    /** @return array{0: DateTimeImmutable, 1: DateTimeImmutable} */
    private static function custom(?string $from, ?string $to, DateTimeZone $tz): array
    {
        ($from !== null && $to !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) === 1 && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) === 1) || throw new ReportError('A custom period needs from and to dates (YYYY-MM-DD).', 'INVALID_PERIOD');
        $a = DateTimeImmutable::createFromFormat('!Y-m-d', $from, $tz);
        $b = DateTimeImmutable::createFromFormat('!Y-m-d', $to, $tz);
        ($a !== false && $b !== false && $a <= $b) || throw new ReportError('The start date must not be after the end date.', 'INVALID_PERIOD');

        return [$a, $b->modify('+1 day')];
    }

    public function cacheKey(): string
    {
        return $this->from->format('YmdHis').'-'.$this->to->format('YmdHis').'-'.$this->timezone;
    }
}
