<?php

declare(strict_types=1);

namespace Cronos\Inventory\Infrastructure\Persistence;

use Cronos\Inventory\Domain\Ports\AlertStore;
use Illuminate\Support\Facades\DB;

final class DatabaseAlertStore implements AlertStore
{
    public function atomically(callable $work): mixed
    {
        return DB::transaction($work);
    }

    private function scope(?string $locationId): string
    {
        return $locationId ?? 'WAREHOUSE';
    }

    public function threshold(string $productId, int $warehouseId, ?string $locationId): ?array
    {
        $row = DB::table('stock_threshold_versions')->where(['product_id' => $productId, 'warehouse_id' => $warehouseId, 'scope_key' => $this->scope($locationId)])->orderByDesc('id')->first();
        if ($row !== null) {
            return $row->removed ? null : ['minimum' => (string) $row->minimum, 'critical' => $row->critical === null ? null : (string) $row->critical];
        }
        if ($locationId !== null) {
            return null;
        }
        $own = DB::table('products')->where('id', $productId)->value('low_stock_threshold');

        return $own === null ? null : ['minimum' => (string) $own, 'critical' => null];
    }

    public function saveThreshold(string $productId, int $warehouseId, ?string $locationId, ?string $minimum, ?string $critical, ?string $userId): void
    {
        DB::table('stock_threshold_versions')->insert([
            'product_id' => $productId, 'warehouse_id' => $warehouseId, 'location_id' => $locationId, 'scope_key' => $this->scope($locationId),
            'minimum' => $minimum ?? '0', 'critical' => $critical, 'removed' => $minimum === null, 'changed_by' => $userId, 'changed_at' => now(),
        ]);
    }

    public function thresholds(?int $warehouseId, int $limit = 500): array
    {
        $rows = DB::select(<<<'SQL'
            SELECT t.product_id, t.warehouse_id, t.location_id, t.minimum, t.critical, t.changed_by, t.changed_at
            FROM stock_threshold_versions t
            WHERE t.id = (SELECT MAX(id) FROM stock_threshold_versions x WHERE x.product_id = t.product_id AND x.warehouse_id = t.warehouse_id AND x.scope_key = t.scope_key)
              AND NOT t.removed AND (?::int IS NULL OR t.warehouse_id = ?::int)
            ORDER BY t.product_id, t.warehouse_id LIMIT ?::int
        SQL, [$warehouseId, $warehouseId, $limit]);

        return array_map(fn ($r) => (array) $r, $rows);
    }

    public function lockState(string $productId, int $warehouseId, ?string $locationId): array
    {
        DB::statement('INSERT INTO stock_alert_states (product_id, warehouse_id, scope_key) VALUES (?, ?, ?) ON CONFLICT DO NOTHING', [$productId, $warehouseId, $this->scope($locationId)]);
        $row = DB::selectOne('SELECT state FROM stock_alert_states WHERE product_id = ? AND warehouse_id = ? AND scope_key = ? FOR UPDATE', [$productId, $warehouseId, $this->scope($locationId)]);

        return ['state' => $row->state];
    }

    public function saveState(string $productId, int $warehouseId, ?string $locationId, string $state, string $quantity): void
    {
        DB::table('stock_alert_states')->where(['product_id' => $productId, 'warehouse_id' => $warehouseId, 'scope_key' => $this->scope($locationId)])->update(['state' => $state, 'quantity' => $quantity, 'since' => now()]);
    }

    public function quantityAt(string $productId, int $warehouseId, ?string $locationId): string
    {
        $q = $locationId === null
            ? DB::table('stock_levels')->where(['product_id' => $productId, 'warehouse_id' => $warehouseId])->value('available')
            : DB::table('location_stock')->where(['product_id' => $productId, 'location_id' => $locationId])->value('quantity');

        return (string) ($q ?? '0');
    }

    public function warehousesOf(string $productId): array
    {
        return DB::table('stock_levels')->where('product_id', $productId)->pluck('warehouse_id')->map(fn ($v) => (int) $v)->all();
    }

    public function describeProduct(string $productId): array
    {
        $p = DB::table('products')->where('id', $productId)->first(['sku']);
        $name = DB::table('product_translations')->where('product_id', $productId)->orderBy('locale')->value('name');

        return ['sku' => $p->sku ?? null, 'name' => $name];
    }

    public function describeLocation(string $locationId): ?array
    {
        $l = DB::table('warehouse_locations')->where('id', $locationId)->first(['code', 'name']);

        return $l === null ? null : ['code' => $l->code, 'name' => $l->name];
    }

    public function warehouseName(int $warehouseId): ?string
    {
        return DB::table('warehouses')->where('id', $warehouseId)->value('name');
    }

    public function createAlert(array $alert, array $recipients): bool
    {
        return DB::transaction(function () use ($alert, $recipients): bool {
            $inserted = DB::affectingStatement(
                'INSERT INTO warehouse_alerts (id, process, severity, title, detail, data, product_id, warehouse_id, location_id, dedupe_key) VALUES (?, ?, ?, ?, ?, ?::jsonb, ?, ?, ?, ?) ON CONFLICT (dedupe_key) DO NOTHING',
                [$alert['id'], $alert['process'], $alert['severity'], $alert['title'], $alert['detail'], json_encode($alert['data'] ?? [], JSON_THROW_ON_ERROR), $alert['product_id'], $alert['warehouse_id'], $alert['location_id'], $alert['dedupe_key']],
            );
            if ($inserted === 0) {
                return false;
            }
            foreach ($recipients as $r) {
                DB::table('warehouse_alert_recipients')->insert(['alert_id' => $alert['id'], 'user_id' => $r['user_id'], 'by_email' => $r['by_email']]);
            }

            return true;
        });
    }

    public function inbox(string $userId, bool $unreadOnly, int $limit = 50): array
    {
        return DB::table('warehouse_alert_recipients as r')->join('warehouse_alerts as a', 'a.id', '=', 'r.alert_id')->where('r.user_id', $userId)
            ->when($unreadOnly, fn ($q) => $q->whereNull('r.read_at'))->orderByDesc('a.created_at')->limit($limit)
            ->get(['a.id', 'a.process', 'a.severity', 'a.title', 'a.detail', 'a.product_id', 'a.warehouse_id', 'a.location_id', 'a.created_at', 'r.read_at'])->map(fn ($r) => (array) $r)->all();
    }

    public function unreadCount(string $userId): int
    {
        return DB::table('warehouse_alert_recipients')->where('user_id', $userId)->whereNull('read_at')->count();
    }

    public function markRead(string $userId, ?array $alertIds): int
    {
        return DB::table('warehouse_alert_recipients')->where('user_id', $userId)->whereNull('read_at')->when($alertIds !== null, fn ($q) => $q->whereIn('alert_id', $alertIds))->update(['read_at' => now()]);
    }

    public function subscribers(string $process): array
    {
        return DB::table('alert_subscriptions')->where('process', $process)->get()->map(fn ($r) => ['user_id' => $r->user_id, 'inbox' => (bool) $r->inbox, 'email' => (bool) $r->email])->all();
    }

    public function subscribe(string $process, string $userId, bool $inbox, bool $email, ?string $by): void
    {
        DB::transaction(function () use ($process, $userId, $inbox, $email, $by): void {
            $existing = DB::table('alert_subscriptions')->where(['process' => $process, 'user_id' => $userId])->first();
            if ($existing === null) {
                DB::table('alert_subscriptions')->insert(['process' => $process, 'user_id' => $userId, 'inbox' => $inbox, 'email' => $email, 'added_by' => $by, 'created_at' => now()]);
                $action = 'added';
            } elseif ((bool) $existing->inbox !== $inbox || (bool) $existing->email !== $email) {
                DB::table('alert_subscriptions')->where('id', $existing->id)->update(['inbox' => $inbox, 'email' => $email]);
                $action = 'changed';
            } else {
                return;
            }
            DB::table('alert_subscription_log')->insert(['process' => $process, 'user_id' => $userId, 'action' => $action, 'done_by' => $by, 'done_at' => now()]);
        });
    }

    public function unsubscribe(string $process, string $userId, ?string $by): void
    {
        DB::transaction(function () use ($process, $userId, $by): void {
            if (DB::table('alert_subscriptions')->where(['process' => $process, 'user_id' => $userId])->delete() > 0) {
                DB::table('alert_subscription_log')->insert(['process' => $process, 'user_id' => $userId, 'action' => 'removed', 'done_by' => $by, 'done_at' => now()]);
            }
        });
    }

    public function subscriptionLog(?string $process, int $limit = 100): array
    {
        return DB::table('alert_subscription_log')->when($process, fn ($q) => $q->where('process', $process))->orderByDesc('id')->limit($limit)->get()->map(fn ($r) => (array) $r)->all();
    }

    public function policyValues(): array
    {
        return DB::table('warehouse_policy_versions as p')->whereRaw('p.id = (SELECT MAX(id) FROM warehouse_policy_versions x WHERE x.key = p.key)')->pluck('value', 'key')->all();
    }

    public function savePolicy(string $key, string $value, ?string $by): void
    {
        DB::table('warehouse_policy_versions')->insert(['key' => $key, 'value' => $value, 'changed_by' => $by, 'changed_at' => now()]);
    }

    public function policyHistory(int $limit = 100): array
    {
        return DB::table('warehouse_policy_versions')->orderByDesc('id')->limit($limit)->get()->map(fn ($r) => (array) $r)->all();
    }

    public function digestFigures(): array
    {
        $by = DB::table('stock_alert_states')->groupBy('state')->selectRaw('state, COUNT(*) AS n')->pluck('n', 'state');

        return [
            'low' => (int) ($by['low'] ?? 0), 'critical' => (int) ($by['critical'] ?? 0), 'out' => (int) ($by['out'] ?? 0),
            'pending_requests' => DB::table('stock_adjustment_requests')->where('status', 'pending')->count(),
            'counts_in_review' => DB::table('count_entries')->where('status', 'in_review')->count(),
        ];
    }
}
