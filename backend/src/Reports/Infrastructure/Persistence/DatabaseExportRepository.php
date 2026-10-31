<?php

declare(strict_types=1);

namespace Cronos\Reports\Infrastructure\Persistence;

use Cronos\Reports\Domain\Ports\ExportRepository;
use Illuminate\Support\Facades\DB;

final class DatabaseExportRepository implements ExportRepository
{
    public function insert(array $job): void
    {
        DB::table('export_jobs')->insert(['filters' => json_encode($job['filters'], JSON_THROW_ON_ERROR)] + $job + ['created_at' => now(), 'updated_at' => now()]);
    }

    public function find(string $id): ?array
    {
        $r = DB::table('export_jobs')->where('id', $id)->first();

        return $r === null ? null : $this->row($r);
    }

    public function update(string $id, array $changes): void
    {
        DB::table('export_jobs')->where('id', $id)->update($changes + ['updated_at' => now()]);
    }

    public function findLive(string $userId, string $type, string $filtersHash): ?array
    {
        $r = DB::table('export_jobs')->where(['requested_by' => $userId, 'type' => $type, 'filters_hash' => $filtersHash])->whereIn('status', ['queued', 'running'])->orderByDesc('created_at')->first();

        return $r === null ? null : $this->row($r);
    }

    public function countLive(string $userId): int
    {
        return DB::table('export_jobs')->where('requested_by', $userId)->whereIn('status', ['queued', 'running'])->count();
    }

    public function forUser(string $userId, int $limit): array
    {
        return DB::table('export_jobs')->where('requested_by', $userId)->orderByDesc('created_at')->limit($limit)->get()->map(fn ($r) => $this->row($r))->all();
    }

    public function expired(\DateTimeImmutable $now, int $limit): array
    {
        return DB::table('export_jobs')->where('status', 'ready')->where('expires_at', '<=', $now->format('Y-m-d H:i:sP'))->limit($limit)->get()->map(fn ($r) => $this->row($r))->all();
    }

    public function stuck(\DateTimeImmutable $before, int $limit): array
    {
        return DB::table('export_jobs')->whereIn('status', ['queued', 'running'])->where('updated_at', '<', $before->format('Y-m-d H:i:sP'))->limit($limit)->get()->map(fn ($r) => $this->row($r))->all();
    }

    /** @return array<string, mixed> */
    private function row(object $r): array
    {
        $a = (array) $r;
        $a['filters'] = json_decode((string) $a['filters'], true) ?? [];
        $a['truncated'] = (bool) $a['truncated'];

        return $a;
    }
}
