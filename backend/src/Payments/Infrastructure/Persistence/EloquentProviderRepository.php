<?php

declare(strict_types=1);

namespace Cronos\Payments\Infrastructure\Persistence;

use Cronos\Payments\Domain\Ports\ProviderRepository;
use Cronos\Payments\Infrastructure\Persistence\Eloquent\PaymentProviderModel;
use Cronos\Shared\Infrastructure\Geo\CurrentClient;
use Illuminate\Support\Facades\DB;

final class EloquentProviderRepository implements ProviderRepository
{
    public function find(string $code): ?array
    {
        $m = PaymentProviderModel::query()->where('code', $code)->first();

        return $m === null ? null : $this->row($m);
    }

    public function all(): array
    {
        return PaymentProviderModel::query()->orderBy('id')->get()->map(fn ($m) => $this->row($m))->all();
    }

    public function update(string $code, array $data): void
    {
        PaymentProviderModel::query()->where('code', $code)->firstOrFail()->update($data);
    }

    public function saveCredentials(string $code, string $mode, array $credentials): void
    {
        $model = PaymentProviderModel::query()->where('code', $code)->firstOrFail();
        // `credentials_*` are excluded from the audit values, so an audit row is still needed to say that they changed.
        $model->update(["credentials_{$mode}" => $credentials, 'updated_at' => now()]);
        DB::table('security_events')->insert([
            'user_id' => auth()->id(), 'event' => 'payment_credentials_changed', 'context' => json_encode(['provider' => $code, 'mode' => $mode, 'fields' => array_keys($credentials)], JSON_THROW_ON_ERROR),
            'ip_address' => request()->ip(), 'country_code' => app(CurrentClient::class)->location()->country, 'timezone' => app(CurrentClient::class)->location()->timezone, 'created_at' => now(),
        ]);
    }

    /** @return array<string, mixed> */
    private function row(PaymentProviderModel $m): array
    {
        return [
            'code' => $m->code, 'name' => $m->name, 'is_enabled' => $m->is_enabled, 'mode' => $m->mode, 'markets' => $m->markets ?? [],
            'min_amount' => $m->min_amount === null ? null : (string) $m->min_amount, 'max_amount' => $m->max_amount === null ? null : (string) $m->max_amount,
            'live_since' => $m->live_since?->toDateTimeString(), 'live_enabled_by' => $m->live_enabled_by,
            'credentials' => ['sandbox' => $m->credentials_sandbox ?? [], 'live' => $m->credentials_live ?? []],
        ];
    }
}
