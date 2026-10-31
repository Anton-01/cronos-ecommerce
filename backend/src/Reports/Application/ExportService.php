<?php

declare(strict_types=1);

namespace Cronos\Reports\Application;

use Cronos\Notifications\Application\NotificationService;
use Cronos\Reports\Domain\Exceptions\ReportError;
use Cronos\Reports\Domain\Ports\ExportQueue;
use Cronos\Reports\Domain\Ports\ExportRecipients;
use Cronos\Reports\Domain\Ports\ExportRepository;
use Cronos\Reports\Domain\Ports\ExportSource;
use Cronos\Reports\Domain\Ports\ExportWriter;
use Cronos\Shared\Domain\ClientContext;
use DateTimeImmutable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Spreadsheets of the business data, built in the background so a big export never ties up a web request. Whoever asks, when and with which
 * filters is recorded (the files hold customer data); the file lives a week and is handed out only through a signed link.
 */
final class ExportService
{
    public const TYPES = ['orders', 'order_items', 'payments', 'stock', 'custom_orders'];

    public const MAX_ROWS = 200_000;

    public const MAX_LIVE_PER_USER = 3;

    public const KEEP_DAYS = 7;

    public function __construct(
        private readonly ExportRepository $exports,
        private readonly ExportSource $source,
        private readonly ExportWriter $writer,
        private readonly NotificationService $notifications,
        private readonly ExportQueue $queue,
        private readonly ExportRecipients $recipients,
    ) {}

    /**
     * @param  array{from?: ?string, to?: ?string, market?: ?string, status?: ?string}  $filters
     * @return array<string, mixed> the export (queued, or the one already in progress with the same filters)
     */
    public function request(string $userId, string $type, array $filters, string $timezone, ?ClientContext $client = null): array
    {
        in_array($type, self::TYPES, true) || throw new ReportError("Unknown export type [{$type}].", 'INVALID_EXPORT');
        $filters = $this->clean($filters);
        $hash = hash('sha256', $type.json_encode($filters));

        if (($live = $this->exports->findLive($userId, $type, $hash)) !== null) {
            return $live;
        }
        $this->exports->countLive($userId) < self::MAX_LIVE_PER_USER || throw new ReportError('You already have '.self::MAX_LIVE_PER_USER.' exports in progress: wait for one to finish.', 'TOO_MANY_EXPORTS');

        $id = (string) Str::ulid();
        $this->exports->insert([
            'id' => $id, 'type' => $type, 'filters' => $filters, 'filters_hash' => $hash, 'requested_by' => $userId, 'timezone' => in_array($timezone, \DateTimeZone::listIdentifiers(), true) ? $timezone : 'UTC',
            'ip_address' => $client?->ip, 'country_code' => $client?->country,
        ]);
        $this->queue->push($id);

        return $this->exports->find($id);
    }

    /** Builds the file. Safe to run again after a crash: it starts over from the request. */
    public function run(string $id): void
    {
        $job = $this->exports->find($id);
        if ($job === null || in_array($job['status'], ['ready', 'expired'], true)) {
            return;
        }
        $this->exports->update($id, ['status' => 'running', 'started_at' => now(), 'attempts' => $job['attempts'] + 1, 'error' => null]);

        $path = "exports/{$id}.xlsx";
        $tmp = tempnam(sys_get_temp_dir(), 'export');
        try {
            $result = $this->writer->write($tmp, $job['type'], $this->source->headers($job['type']), $this->source->rows($job['type'], $job['filters'], $job['timezone']), self::MAX_ROWS);
            Storage::disk('local')->put($path, (string) file_get_contents($tmp));
        } catch (Throwable $e) {
            $this->exports->update($id, ['status' => 'failed', 'error' => mb_substr(class_basename($e).': '.$e->getMessage(), 0, 1000), 'finished_at' => now()]);
            throw $e;
        } finally {
            @unlink($tmp);
        }

        $this->exports->update($id, [
            'status' => 'ready', 'row_count' => $result['rows'], 'truncated' => $result['truncated'], 'file_path' => $path, 'file_size' => Storage::disk('local')->size($path),
            'finished_at' => now(), 'expires_at' => now()->addDays(self::KEEP_DAYS),
        ]);
        $this->tell($this->exports->find($id));
    }

    /** @return array<string, mixed>|null */
    public function get(string $id): ?array
    {
        return $this->exports->find($id);
    }

    /** Called when a person downloads: counted, and refused once the file is gone. @return array<string, mixed> */
    public function openForDownload(string $id): array
    {
        $job = $this->exports->find($id);
        ($job !== null && $job['status'] === 'ready' && $job['file_path'] !== null && Storage::disk('local')->exists($job['file_path']) && new DateTimeImmutable($job['expires_at']) > new DateTimeImmutable)
            || throw new ReportError('That export is no longer available. Request it again.', 'EXPORT_GONE');
        $this->exports->update($id, ['download_count' => $job['download_count'] + 1, 'last_downloaded_at' => now()]);

        return $job;
    }

    /** Deletes the files of expired exports (the row stays: it is the record of who exported what). */
    public function purge(?DateTimeImmutable $now = null): int
    {
        $n = 0;
        foreach ($this->exports->expired($now ?? new DateTimeImmutable, 200) as $job) {
            $job['file_path'] === null || Storage::disk('local')->delete($job['file_path']);
            $this->exports->update($job['id'], ['status' => 'expired', 'file_path' => null]);
            $n++;
        }

        return $n;
    }

    /** Re-queues exports that were queued or running when a worker died. */
    public function recoverStuck(?DateTimeImmutable $now = null): int
    {
        $n = 0;
        foreach ($this->exports->stuck(($now ?? new DateTimeImmutable)->modify('-30 minutes'), 50) as $job) {
            if ($job['attempts'] >= 3) {
                $this->exports->update($job['id'], ['status' => 'failed', 'error' => 'Gave up after '.$job['attempts'].' attempts.', 'finished_at' => now()]);

                continue;
            }
            $this->exports->update($job['id'], ['status' => 'queued']);
            $this->queue->push($job['id']);
            $n++;
        }

        return $n;
    }

    /** @param array<string, mixed> $job */
    private function tell(array $job): void
    {
        $email = $this->recipients->emailOf($job['requested_by']);
        $email === null || $this->notifications->send('export_ready', 'export:'.$job['id'], $email, $job['requested_by'], null, [
            'type' => $job['type'], 'rows' => (string) $job['row_count'], 'truncated' => $job['truncated'] ? '1' : '', 'url' => url()->temporarySignedRoute('exports.download', now()->addDays(2), ['export' => $job['id']]),
            'expires' => (string) $job['expires_at'],
        ], 'export', $job['id']);
    }

    /**
     * @param  array<string, mixed>  $f
     * @return array{from: ?string, to: ?string, market: ?string, status: ?string}
     */
    private function clean(array $f): array
    {
        $date = function (?string $d): ?string {
            if ($d === null || $d === '') {
                return null;
            }
            preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) === 1 && checkdate((int) substr($d, 5, 2), (int) substr($d, 8, 2), (int) substr($d, 0, 4)) || throw new ReportError('Dates are YYYY-MM-DD.', 'INVALID_EXPORT');

            return $d;
        };
        $from = $date($f['from'] ?? null);
        $to = $date($f['to'] ?? null);
        ($from === null || $to === null || $from <= $to) || throw new ReportError('The start date must not be after the end date.', 'INVALID_EXPORT');
        $market = isset($f['market']) && $f['market'] !== '' ? strtoupper((string) $f['market']) : null;
        $market === null || preg_match('/^[A-Z0-9]{2,12}$/', $market) === 1 || throw new ReportError('Unknown market.', 'INVALID_EXPORT');
        $status = isset($f['status']) && $f['status'] !== '' ? strtolower((string) $f['status']) : null;
        $status === null || preg_match('/^[a-z_]{2,24}$/', $status) === 1 || throw new ReportError('Unknown status.', 'INVALID_EXPORT');

        return ['from' => $from, 'to' => $to, 'market' => $market, 'status' => $status];
    }
}
