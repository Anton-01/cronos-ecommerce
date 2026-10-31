<?php

declare(strict_types=1);

namespace Cronos\Markets\Infrastructure\Persistence;

use Cronos\Markets\Domain\Ports\MarketProfileRepository;
use Cronos\Markets\Infrastructure\Persistence\Eloquent\MarketProfileModel;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class DatabaseMarketProfileRepository implements MarketProfileRepository
{
    public function profile(string $marketCode): ?array
    {
        $m = $this->base()->where('m.code', strtoupper($marketCode))->first();

        return $m === null ? null : $this->row($m);
    }

    public function profiles(): array
    {
        return $this->base()->orderBy('m.id')->get()->map(fn ($m) => $this->row($m))->all();
    }

    public function save(int $marketId, array $changes): void
    {
        if (array_key_exists('fiscal_address', $changes)) {
            $changes['fiscal_address'] = json_encode($changes['fiscal_address'], JSON_THROW_ON_ERROR);
        }
        // Eloquent (not the query builder) so the change lands in the audit trail with who, from where and when.
        $model = MarketProfileModel::query()->find($marketId) ?? $this->fresh($marketId);
        $model->forceFill($changes)->save();
    }

    public function setCountries(int $marketId, array $countries): void
    {
        DB::transaction(function () use ($marketId, $countries): void {
            DB::table('market_countries')->where('market_id', $marketId)->whereNotIn('country_code', $countries)->delete();
            foreach ($countries as $c) {
                DB::table('market_countries')->insertOrIgnore(['market_id' => $marketId, 'country_code' => $c]);
            }
        });
    }

    public function countryOwner(string $country): ?array
    {
        $r = DB::table('market_countries as c')->join('markets as m', 'm.id', '=', 'c.market_id')->where('c.country_code', $country)->first(['m.id', 'm.code']);

        return $r === null ? null : ['market_id' => (int) $r->id, 'code' => $r->code];
    }

    public function setFallback(int $marketId): void
    {
        DB::transaction(function () use ($marketId): void {
            MarketProfileModel::query()->where('is_fallback', true)->where('market_id', '<>', $marketId)->get()->each->forceFill(['is_fallback' => false])->each->save();
            $model = MarketProfileModel::query()->find($marketId) ?? $this->fresh($marketId);
            $model->forceFill(['is_fallback' => true])->save();
        });
    }

    /** A profile created on first edit starts with the label customary in the market's country (RFC, EIN...). */
    private function fresh(int $marketId): MarketProfileModel
    {
        $country = DB::table('markets')->where('id', $marketId)->value('country_code');

        return new MarketProfileModel(['market_id' => $marketId, 'tax_id_label' => $country === 'MX' ? 'RFC' : ($country === 'US' ? 'EIN' : 'Tax ID')]);
    }

    public function marketForCountry(string $country): ?string
    {
        $linked = DB::table('market_countries as c')->join('markets as m', 'm.id', '=', 'c.market_id')->where('c.country_code', $country)->where('m.is_active', true)->value('m.code');

        return $linked ?? DB::table('markets')->where('country_code', $country)->where('is_active', true)->orderBy('id')->value('code');
    }

    public function fallbackMarket(): ?string
    {
        return DB::table('market_profiles as p')->join('markets as m', 'm.id', '=', 'p.market_id')->where('p.is_fallback', true)->where('m.is_active', true)->value('m.code')
            ?? DB::table('markets')->where('is_active', true)->orderBy('id')->value('code');
    }

    public function links(string $marketCode): array
    {
        $market = DB::table('markets')->where('code', strtoupper($marketCode))->first();
        if ($market === null) {
            return ['warehouses' => [], 'payment_providers' => [], 'tax_rates' => 0];
        }

        return [
            'warehouses' => DB::table('warehouses')->where('market_id', $market->id)->whereNull('deleted_at')->orderBy('code')->get(['id', 'code', 'name', 'is_active'])->map(fn ($w) => ['id' => (int) $w->id, 'code' => $w->code, 'name' => $w->name, 'is_active' => (bool) $w->is_active])->all(),
            'payment_providers' => DB::table('payment_providers')->where('is_enabled', true)->whereRaw('markets::jsonb @> ?::jsonb', [json_encode([strtoupper($marketCode)])])->orderBy('code')->pluck('code')->all(),
            'tax_rates' => DB::table('tax_rates')->where('market_id', $market->id)->count(),
        ];
    }

    private function base(): Builder
    {
        return DB::table('markets as m')->leftJoin('market_profiles as p', 'p.market_id', '=', 'm.id')
            ->select('m.id as market_id', 'm.code', 'm.name', 'm.country_code', 'm.default_locale', 'm.prices_include_tax', 'm.is_active', 'm.currency_id', 'p.*')
            ->selectRaw('m.id as market_id');
    }

    /** @return array<string, mixed> */
    private function row(object $m): array
    {
        $country = $m->country_code;
        $countries = DB::table('market_countries')->where('market_id', $m->market_id)->orderBy('country_code')->pluck('country_code')->all();

        return [
            'market_id' => (int) $m->market_id, 'code' => $m->code, 'name' => $m->name, 'country_code' => $country, 'default_locale' => $m->default_locale,
            'prices_include_tax' => (bool) $m->prices_include_tax, 'is_active' => (bool) $m->is_active, 'currency_id' => (int) $m->currency_id,
            'legal_name' => $m->legal_name ?? null, 'trade_name' => $m->trade_name ?? null, 'tax_id' => $m->tax_id ?? null,
            'tax_id_label' => $m->tax_id_label ?? ($country === 'MX' ? 'RFC' : ($country === 'US' ? 'EIN' : 'Tax ID')), 'tax_regime' => $m->tax_regime ?? null,
            'fiscal_address' => json_decode((string) ($m->fiscal_address ?? '{}'), true) ?: [], 'invoice_series' => $m->invoice_series ?? null,
            'contact_email' => $m->contact_email ?? null, 'contact_phone' => $m->contact_phone ?? null, 'support_hours' => $m->support_hours ?? null, 'ops_email' => $m->ops_email ?? null,
            'shipping_flat' => isset($m->shipping_flat) ? (string) $m->shipping_flat : null, 'shipping_free_over' => isset($m->shipping_free_over) ? (string) $m->shipping_free_over : null,
            'is_fallback' => $m->code === $this->fallbackMarket(), 'updated_at' => $m->updated_at ?? null,
            'countries' => $countries === [] ? [$country] : $countries,
        ];
    }
}
