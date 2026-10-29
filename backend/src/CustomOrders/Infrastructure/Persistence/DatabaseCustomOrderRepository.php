<?php

declare(strict_types=1);

namespace Cronos\CustomOrders\Infrastructure\Persistence;

use Carbon\CarbonImmutable;
use Cronos\CustomOrders\Domain\Ports\CustomOrderRepository;
use Illuminate\Support\Facades\DB;

final class DatabaseCustomOrderRepository implements CustomOrderRepository
{
    public function atomically(callable $work): mixed
    {
        return DB::transaction($work);
    }

    public function nextNumber(): string
    {
        return 'PE-'.str_pad((string) DB::selectOne("SELECT nextval('custom_order_number_seq') AS n")->n, 6, '0', STR_PAD_LEFT);
    }

    public function find(string $id): ?array
    {
        return $this->request(DB::table('custom_order_requests')->where('id', $id)->whereNull('deleted_at')->first());
    }

    public function lock(string $id): ?array
    {
        return $this->request(DB::table('custom_order_requests')->where('id', $id)->whereNull('deleted_at')->lockForUpdate()->first());
    }

    public function findByNumber(string $number): ?array
    {
        return $this->request(DB::table('custom_order_requests')->where('number', strtoupper(trim($number)))->whereNull('deleted_at')->first());
    }

    public function insert(array $request): void
    {
        $request['specifications'] = json_encode($request['specifications'], JSON_THROW_ON_ERROR);
        DB::table('custom_order_requests')->insert($request + ['created_at' => now(), 'updated_at' => now()]);
    }

    public function update(string $id, array $changes): void
    {
        DB::table('custom_order_requests')->where('id', $id)->update($changes + ['updated_at' => now()]);
    }

    public function addMessage(string $requestId, array $message): void
    {
        DB::table('custom_order_messages')->insert($message + ['request_id' => $requestId, 'is_internal' => false]);
    }

    public function messages(string $requestId, bool $includeInternal): array
    {
        return DB::table('custom_order_messages')->where('request_id', $requestId)->when(! $includeInternal, fn ($q) => $q->where('is_internal', false))->orderBy('id')->get()->map(static fn ($m) => [
            'id' => (int) $m->id, 'author_type' => $m->author_type, 'author_id' => $m->author_id, 'body' => $m->body, 'is_internal' => (bool) $m->is_internal,
            'created_at' => CarbonImmutable::parse($m->created_at)->utc()->format('Y-m-d H:i:s'),
        ])->all();
    }

    public function insertQuote(array $quote, array $lines): void
    {
        DB::table('custom_order_quotes')->insert($quote + ['created_at' => now(), 'updated_at' => now()]);
        foreach ($lines as $l) {
            DB::table('custom_order_quote_lines')->insert(['quote_id' => $quote['id']] + $l);
        }
    }

    public function nextQuoteVersion(string $requestId): int
    {
        return ((int) DB::table('custom_order_quotes')->where('request_id', $requestId)->max('version')) + 1;
    }

    public function quote(string $quoteId): ?array
    {
        $q = DB::table('custom_order_quotes')->where('id', $quoteId)->first();

        return $q === null ? null : $this->quoteRow($q);
    }

    public function lockQuote(string $quoteId): ?array
    {
        $q = DB::table('custom_order_quotes')->where('id', $quoteId)->lockForUpdate()->first();

        return $q === null ? null : $this->quoteRow($q, false);
    }

    public function updateQuote(string $quoteId, array $changes): void
    {
        DB::table('custom_order_quotes')->where('id', $quoteId)->update($changes + ['updated_at' => now()]);
    }

    public function quotes(string $requestId, bool $includeDrafts): array
    {
        return DB::table('custom_order_quotes')->where('request_id', $requestId)->when(! $includeDrafts, fn ($q) => $q->where('status', '!=', 'draft'))->orderByDesc('version')->get()->map(fn ($q) => $this->quoteRow($q))->all();
    }

    public function expiredQuotes(\DateTimeImmutable $today, int $limit): array
    {
        return DB::table('custom_order_quotes')->where('status', 'sent')->where('valid_until', '<', $today->format('Y-m-d'))->limit($limit)->get()->map(fn ($q) => $this->quoteRow($q, false))->all();
    }

    public function forUser(string $userId, int $limit, int $offset): array
    {
        return DB::table('custom_order_requests')->where('user_id', $userId)->whereNull('deleted_at')->orderByDesc('created_at')->limit($limit)->offset($offset)->get()->map(fn ($r) => $this->request($r))->all();
    }

    public function countForUser(string $userId): int
    {
        return DB::table('custom_order_requests')->where('user_id', $userId)->whereNull('deleted_at')->count();
    }

    public function search(?string $status, ?string $term, ?string $assignedTo, int $limit, int $offset): array
    {
        $q = DB::table('custom_order_requests')->whereNull('deleted_at')->when($status, fn ($q) => $q->where('status', $status))->when($assignedTo, fn ($q) => $q->where('assigned_to', $assignedTo));
        if ($term !== null && trim($term) !== '') {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], trim($term)).'%';
            $q->where(fn ($w) => $w->where('number', 'ilike', $like)->orWhere('email', 'ilike', $like)->orWhere('title', 'ilike', $like));
        }

        return ['total' => (clone $q)->count(), 'rows' => $q->orderByDesc('created_at')->limit($limit)->offset($offset)->get()->map(fn ($r) => $this->request($r))->all()];
    }

    /** @return array<string, mixed>|null */
    private function request(?object $r): ?array
    {
        if ($r === null) {
            return null;
        }
        $a = (array) $r;
        $a['specifications'] = json_decode((string) $a['specifications'], true) ?? [];
        $a['budget'] = $a['budget'] === null ? null : (string) $a['budget'];

        return $a;
    }

    /** @return array<string, mixed> */
    private function quoteRow(object $q, bool $withLines = true): array
    {
        $a = (array) $q;
        $a['tax_included'] = (bool) $a['tax_included'];
        foreach (['subtotal', 'tax_total', 'shipping_total', 'grand_total'] as $k) {
            $a[$k] = (string) $a[$k];
        }
        if ($withLines) {
            $a['lines'] = DB::table('custom_order_quote_lines')->where('quote_id', $q->id)->orderBy('id')->get()->map(static fn ($l) => [
                'description' => $l->description, 'unit' => $l->unit, 'quantity' => rtrim(rtrim((string) $l->quantity, '0'), '.') ?: '0', 'unit_price' => (string) $l->unit_price,
                'tax_rate' => (string) $l->tax_rate, 'tax_amount' => (string) $l->tax_amount, 'total' => (string) $l->total, 'product_id' => $l->product_id,
            ])->all();
        }

        return $a;
    }
}
