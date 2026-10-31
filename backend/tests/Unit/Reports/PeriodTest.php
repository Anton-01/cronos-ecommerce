<?php

namespace Tests\Unit\Reports;

use Cronos\Reports\Domain\Exceptions\ReportError;
use Cronos\Reports\Domain\ValueObjects\Period;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class PeriodTest extends TestCase
{
    private function at(string $utc): DateTimeImmutable
    {
        return new DateTimeImmutable($utc, new \DateTimeZone('UTC'));
    }

    public function test_today_is_the_viewers_calendar_day_expressed_in_utc(): void
    {
        // 03:30 UTC on 2 March is still 1 March 21:30 in Mexico City (UTC-6).
        $p = Period::preset('TODAY', 'America/Mexico_City', $this->at('2026-03-02 03:30:00'));
        $this->assertSame(['2026-03-01 06:00:00', '2026-03-02 06:00:00'], [$p->from->format('Y-m-d H:i:s'), $p->to->format('Y-m-d H:i:s')]);
        $this->assertSame(['2026-02-28 06:00:00', '2026-03-01 06:00:00'], [$p->previousFrom->format('Y-m-d H:i:s'), $p->previousTo->format('Y-m-d H:i:s')]);

        $utc = Period::preset('TODAY', 'UTC', $this->at('2026-03-02 03:30:00'));
        $this->assertSame('2026-03-02 00:00:00', $utc->from->format('Y-m-d H:i:s'));
    }

    public function test_rolling_windows_end_today_and_compare_with_the_same_number_of_days_before(): void
    {
        $p = Period::preset('LAST_7_DAYS', 'UTC', $this->at('2026-03-10 12:00:00'));
        $this->assertSame(['2026-03-04', '2026-03-11', '2026-02-25', '2026-03-04'], [$p->from->format('Y-m-d'), $p->to->format('Y-m-d'), $p->previousFrom->format('Y-m-d'), $p->previousTo->format('Y-m-d')]);
    }

    public function test_months_are_compared_with_the_month_before_not_with_thirty_days(): void
    {
        $last = Period::preset('LAST_MONTH', 'UTC', $this->at('2026-03-10 12:00:00'));
        $this->assertSame(['2026-02-01', '2026-03-01', '2026-01-01', '2026-02-01'], [$last->from->format('Y-m-d'), $last->to->format('Y-m-d'), $last->previousFrom->format('Y-m-d'), $last->previousTo->format('Y-m-d')]);

        $this->month = Period::preset('THIS_MONTH', 'UTC', $this->at('2026-03-10 12:00:00'));
        $this->assertSame(['2026-03-01', '2026-03-11', '2026-02-01', '2026-02-11'], [$this->month->from->format('Y-m-d'), $this->month->to->format('Y-m-d'), $this->month->previousFrom->format('Y-m-d'), $this->month->previousTo->format('Y-m-d')], 'the 10 days so far against the first 10 days of last month');
    }

    public function test_custom_windows_are_inclusive_and_validated(): void
    {
        $p = Period::preset('CUSTOM', 'UTC', null, '2026-01-01', '2026-01-31');
        $this->assertSame(['2026-01-01', '2026-02-01', '2025-12-01'], [$p->from->format('Y-m-d'), $p->to->format('Y-m-d'), $p->previousFrom->format('Y-m-d')]);

        foreach ([[null, null], ['2026-02-01', '2026-01-01'], ['2026-1-1', '2026-01-31'], ['2024-01-01', '2026-01-31'], ['garbage', 'x']] as [$from, $to]) {
            try {
                Period::preset('CUSTOM', 'UTC', null, $from, $to);
                $this->fail("expected a ReportError for {$from} - {$to}");
            } catch (ReportError) {
                $this->addToAssertionCount(1);
            }
        }
        $this->expectException(ReportError::class);
        Period::preset('FOREVER', 'UTC');
    }

    public function test_an_unknown_timezone_falls_back_to_utc_instead_of_failing(): void
    {
        $this->assertSame('UTC', Period::preset('TODAY', 'Mars/Olympus')->timezone);
    }

    private ?Period $month = null;
}
