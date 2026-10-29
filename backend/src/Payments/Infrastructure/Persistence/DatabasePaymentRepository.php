<?php

declare(strict_types=1);

namespace Cronos\Payments\Infrastructure\Persistence;

use Carbon\CarbonImmutable;
use Cronos\Payments\Domain\Ports\PaymentRepository;
use Cronos\Shared\Infrastructure\Geo\CurrentClient;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class DatabasePaymentRepository implements PaymentRepository
{
    public function atomically(callable $work): mixed
    {
        return DB::transaction($work);
    }

    public function find(string $id): ?array
    {
        $r = DB::table('payments')->where('id', $id)->first();

        return $r === null ? null : $this->row($r);
    }

    public function lock(string $id): ?array
    {
        $r = DB::table('payments')->where('id', $id)->lockForUpdate()->first();

        return $r === null ? null : $this->row($r);
    }

    public function findByKey(string $idempotencyKey): ?array
    {
        $r = DB::table('payments')->where('idempotency_key', $idempotencyKey)->first();

        return $r === null ? null : $this->row($r);
    }

    public function findByReference(string $provider, string $reference): ?array
    {
        $r = DB::table('payments')->where('provider', $provider)->where(fn ($q) => $q->where('provider_reference', $reference)->orWhere('metadata->initial_reference', $reference))->orderByDesc('created_at')->first();

        return $r === null ? null : $this->row($r);
    }

    public function insert(array $payment): string
    {
        $id = strtolower((string) Str::ulid());
        try {
            DB::table('payments')->insert([
                'id' => $id, 'status' => 'pending', 'metadata' => json_encode($payment['metadata'] ?? [], JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now(),
            ] + array_diff_key($payment, ['metadata' => 1]));
        } catch (UniqueConstraintViolationException) {
            // Two requests with the same idempotency key at once: the loser simply uses the winner's payment.
            return $this->findByKey($payment['idempotency_key'])['id'];
        }

        return $id;
    }

    public function update(string $id, array $changes): void
    {
        if (array_key_exists('client_action', $changes)) {
            $changes['client_action'] = $changes['client_action'] === null ? null : Crypt::encryptString(json_encode($changes['client_action'], JSON_THROW_ON_ERROR));
        }
        if (array_key_exists('metadata', $changes)) {
            $changes['metadata'] = json_encode($changes['metadata'], JSON_THROW_ON_ERROR);
        }
        DB::table('payments')->where('id', $id)->update($changes + ['updated_at' => now()]);
    }

    public function addTransaction(array $transaction): bool
    {
        $transaction['raw_response'] = $transaction['raw_response'] === null || $transaction['raw_response'] === [] ? null : json_encode($transaction['raw_response'], JSON_THROW_ON_ERROR | JSON_PARTIAL_OUTPUT_ON_ERROR);
        $location = app(CurrentClient::class)->location();
        $transaction += ['country_code' => null, 'timezone' => null];
        $transaction['country_code'] ??= $location->country;
        $transaction['timezone'] ??= $location->timezone;

        // A replay of the same provider event hits the partial unique index: that is the idempotency, not an error.
        // The insert runs in a savepoint so the surrounding transaction survives the violation.
        try {
            DB::transaction(fn () => DB::table('payment_transactions')->insert($transaction));
        } catch (UniqueConstraintViolationException) {
            return false;
        }

        return true;
    }

    public function transactions(string $paymentId): array
    {
        return DB::table('payment_transactions')->where('payment_id', $paymentId)->orderBy('id')->get()->map(fn ($r) => [
            'id' => (int) $r->id, 'type' => $r->type, 'status' => $r->status, 'amount' => $r->amount === null ? null : (string) $r->amount, 'provider_reference' => $r->provider_reference,
            'external_event_id' => $r->external_event_id, 'request_id' => $r->request_id, 'raw_response' => $r->raw_response === null ? null : json_decode($r->raw_response, true), 'error' => $r->error,
            'duration_ms' => $r->duration_ms, 'created_by' => $r->created_by, 'country' => $r->country_code, 'timezone' => $r->timezone, 'created_at' => CarbonImmutable::parse($r->created_at)->utc(),
        ])->all();
    }

    public function forPayable(string $payableType, string $payableId): array
    {
        return DB::table('payments')->where(['payable_type' => $payableType, 'payable_id' => $payableId])->orderBy('created_at')->get()->map(fn ($r) => $this->row($r))->all();
    }

    public function openOlderThan(\DateTimeImmutable $cutoff, int $limit): array
    {
        return DB::table('payments')->whereIn('status', ['pending', 'requires_action', 'processing'])->where('updated_at', '<=', CarbonImmutable::instance($cutoff)->utc())
            ->orderBy('updated_at')->limit($limit)->get()->map(fn ($r) => $this->row($r))->all();
    }

    public function search(?string $status, ?string $provider, ?string $mode, ?string $term, bool $anomalies, int $limit, int $offset): array
    {
        $q = DB::table('payments')->when($status, fn ($q) => $q->where('status', $status))->when($provider, fn ($q) => $q->where('provider', $provider))->when($mode, fn ($q) => $q->where('mode', $mode))
            ->when($anomalies, fn ($q) => $q->whereNotNull('anomaly'))
            ->when($term !== null && trim($term) !== '', fn ($q) => $q->where(fn ($w) => $w->where('id', trim($term))->orWhere('provider_reference', trim($term))->orWhere('payable_id', trim($term))->orWhere('customer_email', 'ilike', '%'.addcslashes(trim($term), '%_\\').'%')));

        return ['total' => (clone $q)->count(), 'rows' => $q->orderByDesc('created_at')->limit($limit)->offset($offset)->get()->map(fn ($r) => $this->row($r))->all()];
    }

    public function findRefundByKey(string $key): ?array
    {
        $r = DB::table('refunds')->where('idempotency_key', $key)->first();

        return $r === null ? null : $this->refundRow($r);
    }

    public function insertRefund(array $refund): string
    {
        $id = strtolower((string) Str::ulid());
        DB::table('refunds')->insert(['id' => $id, 'created_at' => now(), 'updated_at' => now()] + $refund);

        return $id;
    }

    public function updateRefund(string $id, array $changes): void
    {
        DB::table('refunds')->where('id', $id)->update($changes + ['updated_at' => now()]);
    }

    public function refunds(string $paymentId): array
    {
        return DB::table('refunds')->where('payment_id', $paymentId)->orderBy('created_at')->get()->map(fn ($r) => $this->refundRow($r))->all();
    }

    public function committedRefunds(string $paymentId): string
    {
        return (string) DB::table('refunds')->where('payment_id', $paymentId)->whereIn('status', ['pending', 'succeeded'])->sum('amount');
    }

    public function adoptRefund(string $paymentId, string $providerReference, string $amount, string $currency): void
    {
        if (DB::table('refunds')->where('provider_reference', $providerReference)->exists()) {
            return;
        }
        $this->insertRefund(['payment_id' => $paymentId, 'amount' => $amount, 'currency' => $currency, 'status' => 'succeeded', 'reason' => 'Issued outside the shop (provider dashboard)', 'provider_reference' => $providerReference, 'idempotency_key' => 'external:'.$providerReference]);
    }

    /** @return array<string, mixed> */
    private function row(object $r): array
    {
        $action = $r->client_action === null ? null : json_decode(Crypt::decryptString($r->client_action), true);

        return [
            'id' => $r->id, 'provider' => $r->provider, 'mode' => $r->mode, 'payable_type' => $r->payable_type, 'payable_id' => $r->payable_id, 'status' => $r->status,
            'amount' => (string) $r->amount, 'currency' => $r->currency, 'captured_amount' => (string) $r->captured_amount, 'refunded_amount' => (string) $r->refunded_amount,
            'provider_reference' => $r->provider_reference, 'idempotency_key' => $r->idempotency_key, 'customer_email' => $r->customer_email, 'description' => $r->description,
            'client_action' => $action, 'failure_code' => $r->failure_code, 'failure_message' => $r->failure_message, 'anomaly' => $r->anomaly,
            'metadata' => json_decode($r->metadata, true) ?? [], 'ip_address' => $r->ip_address, 'country' => $r->country_code, 'timezone' => $r->timezone,
            'created_at' => CarbonImmutable::parse($r->created_at)->utc()->format('Y-m-d H:i:s'), 'updated_at' => CarbonImmutable::parse($r->updated_at)->utc()->format('Y-m-d H:i:s'),
        ];
    }

    /** @return array<string, mixed> */
    private function refundRow(object $r): array
    {
        return [
            'id' => $r->id, 'payment_id' => $r->payment_id, 'amount' => (string) $r->amount, 'currency' => $r->currency, 'status' => $r->status, 'reason' => $r->reason,
            'provider_reference' => $r->provider_reference, 'idempotency_key' => $r->idempotency_key, 'failure_message' => $r->failure_message, 'created_by' => $r->created_by,
            'created_at' => CarbonImmutable::parse($r->created_at)->utc()->format('Y-m-d H:i:s'),
        ];
    }
}
