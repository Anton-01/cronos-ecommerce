<?php

declare(strict_types=1);

namespace Cronos\Pricing\Infrastructure\Persistence;

use Cronos\Pricing\Domain\Ports\CurrencyStore;
use Cronos\Pricing\Infrastructure\Persistence\Eloquent\CurrencyModel;
use Illuminate\Support\Facades\DB;

final class EloquentCurrencyStore implements CurrencyStore
{
    public function find(int $id): ?array
    {
        return $this->row(CurrencyModel::query()->find($id));
    }

    public function findByCode(string $code): ?array
    {
        return $this->row(CurrencyModel::query()->where('code', strtoupper($code))->first());
    }

    public function all(): array
    {
        return CurrencyModel::query()->orderByDesc('is_default')->orderBy('code')->get()->map(fn ($m) => $this->row($m))->all();
    }

    public function create(array $data): int
    {
        return DB::transaction(function () use ($data): int {
            $currency = CurrencyModel::query()->create($data + ['rate_updated_at' => now()]);
            $this->record($currency->id, (string) $currency->exchange_rate, 'initial');

            return $currency->id;
        });
    }

    public function update(int $id, array $data): void
    {
        CurrencyModel::query()->findOrFail($id)->update($data);
    }

    public function delete(int $id): void
    {
        CurrencyModel::query()->findOrFail($id)->delete();
    }

    public function inUse(int $id): bool
    {
        return DB::table('markets')->where('currency_id', $id)->exists()
            || DB::table('product_prices')->where('currency_id', $id)->exists()
            || DB::table('coupons')->where('currency_id', $id)->exists();
    }

    public function setRate(int $id, string $rate, string $source): void
    {
        DB::transaction(function () use ($id, $rate, $source): void {
            CurrencyModel::query()->findOrFail($id)->update(['exchange_rate' => $rate, 'rate_updated_at' => now()]);
            $this->record($id, $rate, $source);
        });
    }

    public function history(int $id, int $limit = 30): array
    {
        return DB::table('exchange_rate_history')->where('currency_id', $id)->orderByDesc('id')->limit($limit)
            ->get(['rate', 'source', 'created_at'])->map(fn ($r) => (array) $r)->all();
    }

    private function record(int $id, string $rate, string $source): void
    {
        DB::table('exchange_rate_history')->insert(['currency_id' => $id, 'rate' => $rate, 'source' => $source]);
    }

    private function row(?CurrencyModel $m): ?array
    {
        return $m === null ? null : [
            'id' => $m->id, 'code' => $m->code, 'name' => $m->name, 'symbol' => $m->symbol, 'decimals' => $m->decimals,
            'exchange_rate' => (string) $m->exchange_rate, 'is_default' => $m->is_default, 'is_active' => $m->is_active,
            'rate_updated_at' => $m->rate_updated_at?->toDateTimeString(),
        ];
    }
}
