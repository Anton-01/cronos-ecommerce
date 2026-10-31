<?php

declare(strict_types=1);

namespace Cronos\Shared\Infrastructure\Console;

use Cronos\Shared\Domain\Geo\IpAddress;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Loads an IP-to-country CSV into geo_ip_ranges. Columns: from,to,country[,timezone[,region]], no header row required
 * (a first line that is not an IP is skipped). Compatible with the free "IP to Country" lite databases.
 */
final class ImportGeoRangesCommand extends Command
{
    protected $signature = 'geo:import {file : CSV file} {--append : Keep the existing ranges instead of replacing them}';

    protected $description = 'Load IP ranges (country and optional timezone) used to set each caller\'s clock and country';

    public function handle(): int
    {
        $path = (string) $this->argument('file');
        $handle = is_readable($path) ? fopen($path, 'rb') : false;
        if ($handle === false) {
            $this->error("Cannot read {$path}");

            return self::FAILURE;
        }

        $batch = [];
        $total = 0;
        $skipped = 0;

        DB::transaction(function () use ($handle, &$batch, &$total, &$skipped): void {
            $this->option('append') || DB::table('geo_ip_ranges')->delete();

            while (($row = fgetcsv($handle)) !== false) {
                // "from,to,country[,timezone[,region]]" or "cidr,country[,timezone[,region]]"; IPv4 and IPv6 alike.
                $isCidr = isset($row[0]) && str_contains((string) $row[0], '/');
                if ($isCidr) {
                    $range = IpAddress::cidrRange((string) $row[0]);
                    [$from, $to] = $range ?? [null, null];
                    $country = $row[1] ?? null;
                    $row = [$from, $to, $country, $row[2] ?? '', $row[3] ?? ''];
                } else {
                    $from = IpAddress::normalize($row[0] ?? null);
                    $to = IpAddress::normalize($row[1] ?? null);
                    $country = $row[2] ?? null;
                }
                // Both ends in the same family and in order: a reversed or mixed range would make the whole import fail on the CHECK.
                $valid = $from !== null && $to !== null && IpAddress::family($from) === IpAddress::family($to) && inet_pton($from) <= inet_pton($to) && preg_match('/^[A-Za-z]{2}$/', (string) $country) === 1;
                if (! $valid) {
                    $skipped++;

                    continue;
                }
                $batch[] = ['ip_from' => $from, 'ip_to' => $to, 'country_code' => strtoupper($country), 'timezone' => ($row[3] ?? '') !== '' ? $row[3] : null, 'region' => ($row[4] ?? '') !== '' ? $row[4] : null];
                if (count($batch) >= 1000) {
                    DB::table('geo_ip_ranges')->insert($batch);
                    $total += count($batch);
                    $batch = [];
                }
            }
            if ($batch !== []) {
                DB::table('geo_ip_ranges')->insert($batch);
                $total += count($batch);
            }
        });
        fclose($handle);

        // New generation number = every cached lookup is stale, without flushing unrelated cache entries.
        Cache::increment('geo:generation') || Cache::forever('geo:generation', 1);
        $this->info("Imported {$total} range(s); skipped {$skipped} line(s).");

        return self::SUCCESS;
    }
}
