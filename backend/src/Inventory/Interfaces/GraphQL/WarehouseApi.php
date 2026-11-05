<?php

declare(strict_types=1);

namespace Cronos\Inventory\Interfaces\GraphQL;

use Cronos\Inventory\Application\Actor;
use Cronos\Inventory\Application\AdjustmentRequests;
use Cronos\Inventory\Application\AlertSubscriptions;
use Cronos\Inventory\Application\CountSessions;
use Cronos\Inventory\Application\LabelStyles;
use Cronos\Inventory\Application\LocationLedger;
use Cronos\Inventory\Application\LocationManager;
use Cronos\Inventory\Application\LocationReconciler;
use Cronos\Inventory\Application\PickLists;
use Cronos\Inventory\Application\StockThresholds;
use Cronos\Inventory\Application\WarehouseOperations;
use Cronos\Inventory\Application\WarehousePolicies;
use Cronos\Inventory\Domain\Exceptions\InvalidMovement;
use Cronos\Inventory\Domain\Exceptions\NotFound;
use Cronos\Inventory\Domain\Ports\AlertStore;
use Cronos\Inventory\Domain\Ports\LocationRepository;
use Cronos\Inventory\Domain\Ports\WarehouseQueries;
use Cronos\Inventory\Domain\Ports\WarehouseRepository;
use Cronos\Inventory\Domain\ValueObjects\StockCode;
use Cronos\Shared\Interfaces\GraphQL\ResolvesClientContext;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * The warehouse section of the admin. Every operation is guarded in the schema by a warehouse.* permission; this class only translates between
 * GraphQL and the application services, so the rules live in one place whatever the client (phone, scanner, desktop).
 */
final class WarehouseApi
{
    use ResolvesClientContext;

    private const MAX_PAGE = 200;

    public function __construct(
        private readonly WarehouseQueries $queries,
        private readonly WarehouseRepository $warehouses,
        private readonly LocationRepository $locations,
        private readonly LocationManager $locationManager,
        private readonly WarehouseOperations $operations,
        private readonly LocationLedger $ledger,
        private readonly PickLists $picks,
        private readonly AdjustmentRequests $adjustments,
        private readonly CountSessions $counts,
        private readonly StockThresholds $thresholds,
        private readonly WarehousePolicies $policies,
        private readonly AlertSubscriptions $subscriptions,
        private readonly AlertStore $alerts,
        private readonly LocationReconciler $reconciler,
        private readonly LabelStyles $labelStyles,
    ) {}

    // ------------------------------------------------------------------ reading

    public function warehouses(): array
    {
        return array_map(Present::warehouse(...), $this->warehouses->all());
    }

    /** @param array<string, mixed> $a */
    public function locations(mixed $root, array $a): array
    {
        return array_map(fn (array $l) => $this->shapeLocation($l), $this->queries->locations((int) $a['warehouseId'], (bool) ($a['includeInactive'] ?? false)));
    }

    /** @param array<string, mixed> $a */
    public function location(mixed $root, array $a = []): ?array
    {
        $row = isset($a['id']) ? $this->locations->find($a['id']) : null;

        return $row === null ? null : $this->detail($row);
    }

    /** @param array<string, mixed> $a */
    public function locationByCode(mixed $root, array $a): ?array
    {
        $found = $this->queries->locationByCode($a['code'], isset($a['warehouseId']) ? (int) $a['warehouseId'] : null);

        return $found === null ? null : $this->detail($this->locations->find($found['id']));
    }

    /** @param array<string, mixed> $a */
    public function scan(mixed $root, array $a): ?array
    {
        $code = StockCode::fromScan($a['code']) ?? throw new InvalidMovement('That is not a valid stock code. Check the label or type the code again.', 'INVALID_STOCK_CODE');
        $scan = $this->queries->scan($code);
        if ($scan === null) {
            return null;
        }
        $pending = '0';
        foreach ($this->picks->pending(null, 1000) as $line) {
            $line['product_id'] === $scan['product_id'] && $pending = (string) round((float) $pending + (float) $line['pending'], 3);
        }

        return [
            'productId' => $scan['product_id'], 'sku' => $scan['sku'], 'name' => $scan['name'], 'type' => $scan['type'], 'status' => $scan['status'], 'removed' => $scan['removed'],
            'stockCode' => $scan['stock_code'], 'stockCodeFormatted' => StockCode::parse($scan['stock_code'])->formatted(), 'unit' => $scan['unit'],
            'places' => array_map(fn (array $p) => ['locationId' => $p['location_id'], 'code' => $p['code'], 'name' => $p['name'], 'isActive' => $p['is_active'], 'isPickable' => $p['is_pickable'], 'warehouseId' => $p['warehouse_id'], 'warehouseCode' => $p['warehouse_code'], 'quantity' => $p['quantity'], 'version' => $p['version']], $scan['places']),
            'books' => array_map(fn (array $b) => ['warehouseId' => $b['warehouse_id'], 'warehouseCode' => $b['warehouse_code'], 'onHand' => $b['on_hand'], 'reserved' => $b['reserved'], 'available' => $b['available']], $scan['books']),
            'thresholds' => array_map(fn (array $t) => ['warehouseId' => $t['warehouse_id'], 'minimum' => $t['minimum'], 'critical' => $t['critical']], $scan['thresholds']),
            'pendingToPick' => number_format((float) $pending, 3, '.', ''),
            'recent' => array_map($this->movement(...), $scan['recent']),
        ];
    }

    /** @param array<string, mixed> $a */
    public function searchProducts(mixed $root, array $a): array
    {
        return array_map(fn (array $p) => ['productId' => $p['product_id'], 'sku' => $p['sku'], 'name' => $p['name'], 'stockCode' => $p['stock_code'], 'status' => $p['status']], $this->queries->searchProducts($a['search'], $this->page($a['first'] ?? 20)));
    }

    /** @param array<string, mixed> $a */
    public function trail(mixed $root, array $a): array
    {
        $limit = $this->page($a['first'] ?? 50);
        $rows = $this->queries->trail([
            'productId' => $a['productId'] ?? null, 'locationId' => $a['locationId'] ?? null, 'warehouseId' => isset($a['warehouseId']) ? (int) $a['warehouseId'] : null, 'userId' => $a['userId'] ?? null,
            'type' => $a['type'] ?? null, 'from' => isset($a['from']) ? $a['from']->utc()->format('Y-m-d H:i:s.uP') : null, 'to' => isset($a['to']) ? $a['to']->utc()->format('Y-m-d H:i:s.uP') : null,
            'before' => isset($a['before']) ? (int) $a['before'] : null,
        ], $limit + 1);
        $page = array_slice($rows, 0, $limit);

        return ['entries' => array_map($this->movement(...), $page), 'nextBefore' => count($rows) > $limit ? (string) end($page)['id'] : null];
    }

    /** @param array<string, mixed> $a */
    public function verifyChain(mixed $root, array $a): array
    {
        $broken = $this->reconciler->verify($a['productId'], $a['locationId']);

        return ['ok' => $broken === null, 'brokenAt' => $broken];
    }

    public function integrity(): array
    {
        $r = $this->reconciler->check();

        return ['ok' => $r['ok'], 'stockMismatches' => $r['stock_mismatches'], 'brokenChains' => $r['broken_chains'], 'untrackedCodes' => $r['untracked_codes'], 'variance' => $r['variance']];
    }

    /** @param array<string, mixed> $a */
    public function pickLines(mixed $root, array $a): array
    {
        $lines = $this->queries->describePickLines($this->picks->pending(isset($a['warehouseId']) ? (int) $a['warehouseId'] : null, 300));

        return array_map(fn (array $l) => [
            'orderId' => $l['order_id'], 'orderNumber' => $l['order_number'], 'productId' => $l['product_id'], 'sku' => $l['sku'], 'name' => $l['name'], 'stockCode' => $l['stock_code'],
            'warehouseId' => $l['warehouse_id'], 'pending' => $l['pending'],
            'shelves' => array_map(fn (array $s) => ['locationId' => $s['location_id'], 'code' => $s['code'], 'isPickable' => $s['is_pickable'], 'quantity' => $s['quantity'], 'version' => $s['version']], $l['shelves']),
        ], $lines);
    }

    /** @param array<string, mixed> $a */
    public function countSessions(mixed $root, array $a): array
    {
        $rows = $this->queries->countSessions(isset($a['warehouseId']) ? (int) $a['warehouseId'] : null, $a['status'] ?? null, 100);
        $names = $this->queries->userNames(array_column($rows, 'created_by'));

        return array_map(fn (array $s) => ['id' => $s['id'], 'name' => $s['name'], 'status' => $s['status'], 'warehouseId' => $s['warehouse_id'], 'warehouseCode' => $s['warehouse_code'], 'scopeCode' => $s['scope_code'],
            'createdByName' => $names[$s['created_by']] ?? null, 'createdAt' => $s['created_at'], 'closedAt' => $s['closed_at'], 'entries' => $s['entries'], 'inReview' => $s['in_review']], $rows);
    }

    /** @param array<string, mixed> $a */
    public function countEntries(mixed $root, array $a): array
    {
        $rows = $this->queries->countEntries($a['sessionId'], $a['status'] ?? null, 500);
        $names = $this->queries->userNames([...array_column($rows, 'counted_by'), ...array_column($rows, 'reviewed_by')]);

        return array_map(fn (array $e) => ['id' => $e['id'], 'productId' => $e['product_id'], 'sku' => $e['sku'], 'name' => $e['name'], 'locationId' => $e['location_id'], 'locationCode' => $e['location_code'], 'counted' => $e['counted'],
            'systemAtStart' => $e['system_at_start'], 'status' => $e['status'], 'countedByName' => $names[$e['counted_by']] ?? null, 'countedAt' => $e['counted_at'], 'note' => $e['note'],
            'reviewedByName' => $names[$e['reviewed_by']] ?? null, 'reviewNote' => $e['review_note'], 'movementId' => $e['movement_ulid']], $rows);
    }

    /** @param array<string, mixed> $a */
    public function adjustmentRequests(mixed $root, array $a): array
    {
        $rows = $this->queries->adjustmentRequests($a['status'] ?? null, isset($a['warehouseId']) ? (int) $a['warehouseId'] : null, 200);
        $names = $this->queries->userNames([...array_column($rows, 'requested_by'), ...array_column($rows, 'decided_by')]);

        return array_map(fn (array $r) => ['id' => $r['id'], 'productId' => $r['product_id'], 'sku' => $r['sku'], 'name' => $r['name'], 'warehouseId' => $r['warehouse_id'], 'locationId' => $r['location_id'], 'locationCode' => $r['location_code'],
            'seenQuantity' => $r['seen_quantity'], 'targetQuantity' => $r['target_quantity'], 'delta' => $r['delta'], 'reason' => $r['reason'], 'status' => $r['status'], 'requestedByName' => $names[$r['requested_by']] ?? null,
            'requestedAt' => $r['requested_at'], 'decidedByName' => $names[$r['decided_by']] ?? null, 'decidedAt' => $r['decided_at'], 'decisionNote' => $r['decision_note'], 'movementId' => $r['movement_ulid']], $rows);
    }

    /** @param array<string, mixed> $a */
    public function thresholds(mixed $root, array $a): array
    {
        $rows = $this->queries->thresholds(isset($a['warehouseId']) ? (int) $a['warehouseId'] : null, 500);
        $names = $this->queries->userNames(array_column($rows, 'changed_by'));

        return array_map(fn (array $t) => ['productId' => $t['product_id'], 'sku' => $t['sku'], 'name' => $t['name'], 'warehouseId' => $t['warehouse_id'], 'locationId' => $t['location_id'], 'locationCode' => $t['location_code'],
            'minimum' => $t['minimum'], 'critical' => $t['critical'], 'changedByName' => $names[$t['changed_by']] ?? null, 'changedAt' => $t['changed_at']], $rows);
    }

    /** @param array<string, mixed> $a */
    public function inbox(mixed $root, array $a, GraphQLContext $context): array
    {
        $me = (string) $context->user()?->getAuthIdentifier();
        $items = $this->alerts->inbox($me, (bool) ($a['unreadOnly'] ?? false), $this->page($a['first'] ?? 50));

        return ['unread' => $this->alerts->unreadCount($me), 'items' => array_map(fn (array $i) => [
            'id' => $i['id'], 'process' => $i['process'], 'severity' => $i['severity'], 'title' => $i['title'], 'detail' => $i['detail'], 'productId' => $i['product_id'], 'warehouseId' => $i['warehouse_id'],
            'locationId' => $i['location_id'], 'createdAt' => (string) $i['created_at'], 'read' => $i['read_at'] !== null,
        ], $items)];
    }

    public function alertProcesses(): array
    {
        return $this->processes();
    }

    public function alertCandidates(): array
    {
        return $this->subscriptions->candidates();
    }

    /** @param array<string, mixed> $a */
    public function subscriptionLog(mixed $root, array $a): array
    {
        $rows = $this->subscriptions->log($a['process'] ?? null, 200);
        $names = $this->queries->userNames([...array_column($rows, 'user_id'), ...array_column($rows, 'done_by')]);

        return array_map(fn (array $r) => ['process' => $r['process'], 'userId' => $r['user_id'], 'userName' => $names[$r['user_id']] ?? null, 'action' => $r['action'], 'doneByName' => $names[$r['done_by']] ?? null, 'doneAt' => (string) $r['done_at']], $rows);
    }

    public function policies(): array
    {
        $p = $this->policies->current();

        return ['approvalUnits' => $p['approval_units'], 'approvalPercent' => $p['approval_percent'], 'approvalAmount' => $p['approval_amount'], 'recoveryMarginPercent' => $p['recovery_margin_percent']];
    }

    public function policyHistory(): array
    {
        $rows = $this->policies->history(100);
        $names = $this->queries->userNames(array_column($rows, 'changed_by'));

        return array_map(fn (array $r) => ['key' => $r['key'], 'value' => $r['value'], 'changedByName' => $names[$r['changed_by']] ?? null, 'changedAt' => (string) $r['changed_at']], $rows);
    }

    public function labelStyle(): array
    {
        return $this->labelStyles->current();
    }

    public function labelStyleHistory(): array
    {
        $rows = $this->labelStyles->history(50);
        $names = $this->queries->userNames(array_column($rows, 'changed_by'));

        return array_map(fn (array $r) => ['style' => $r['style'], 'changedByName' => $names[$r['changed_by']] ?? null, 'changedAt' => $r['changed_at']], $rows);
    }

    /** @param array<string, mixed> $a */
    public function updateLabelStyle(mixed $root, array $a, GraphQLContext $c): array
    {
        return $this->labelStyles->update($a['input'], $this->actor($c)->userId);
    }

    // ------------------------------------------------------------------ the warehouse team's work

    /** @param array<string, mixed> $a */
    public function receive(mixed $root, array $a, GraphQLContext $c): string
    {
        $i = $a['input'];

        return $this->operations->receive($i['productId'], (int) $i['warehouseId'], $i['quantity'], $this->actor($c), $i['locationId'] ?? null, $i['unitCost'] ?? null, $i['idempotencyKey'], $i['note'] ?? null);
    }

    /** @param array<string, mixed> $a */
    public function move(mixed $root, array $a, GraphQLContext $c): array
    {
        $i = $a['input'];
        $r = $this->ledger->move($i['productId'], $i['fromLocationId'], $i['toLocationId'], $i['quantity'], $this->actor($c), $i['idempotencyKey'], $i['expectedVersion'] ?? null, $i['note'] ?? null);

        return ['outId' => $r['out'], 'inId' => $r['in']];
    }

    /** @param array<string, mixed> $a */
    public function pick(mixed $root, array $a, GraphQLContext $c): string
    {
        $i = $a['input'];

        return $this->picks->pick($i['orderId'], $i['productId'], $i['locationId'], $i['quantity'], $this->actor($c), $i['idempotencyKey']);
    }

    /** @param array<string, mixed> $a */
    public function returnToShelf(mixed $root, array $a, GraphQLContext $c): string
    {
        $i = $a['input'];

        return $this->operations->returnToShelf($i['productId'], $i['locationId'], $i['quantity'], $this->actor($c), $i['orderId'] ?? null, $i['idempotencyKey'], $i['note'] ?? null);
    }

    /** @param array<string, mixed> $a */
    public function writeOff(mixed $root, array $a, GraphQLContext $c): string
    {
        $i = $a['input'];

        return $this->operations->writeOff($i['productId'], $i['locationId'], $i['quantity'], $this->actor($c), $i['reason'], $i['idempotencyKey'], $i['expectedVersion'] ?? null);
    }

    /** @param array<string, mixed> $a */
    public function requestAdjustment(mixed $root, array $a, GraphQLContext $c): array
    {
        $i = $a['input'];

        return $this->adjustments->request($i['productId'], $i['locationId'], $i['targetQuantity'], (int) $i['seenVersion'], $i['reason'], $this->actor($c), $i['idempotencyKey']);
    }

    /** @param array<string, mixed> $a */
    public function submitCount(mixed $root, array $a, GraphQLContext $c): array
    {
        $i = $a['input'];
        $r = $this->counts->submit($i['sessionId'], $i['productId'], $i['locationId'], $i['counted'], $this->actor($c), $i['idempotencyKey'], $i['note'] ?? null);

        return ['id' => $r['id'], 'status' => $r['status'], 'movementId' => $r['movement']];
    }

    // ------------------------------------------------------------------ decisions, shelves and rules (lead)

    /** @param array<string, mixed> $a */
    public function openCount(mixed $root, array $a, GraphQLContext $c): string
    {
        return $this->counts->open((int) $a['warehouseId'], $a['name'], $this->actor($c), $a['scopeLocationId'] ?? null);
    }

    /** @param array<string, mixed> $a */
    public function closeCount(mixed $root, array $a, GraphQLContext $c): bool
    {
        $this->counts->close($a['sessionId'], $this->actor($c));

        return true;
    }

    /** @param array<string, mixed> $a */
    public function reviewCount(mixed $root, array $a, GraphQLContext $c): ?string
    {
        return $this->counts->review($a['entryId'], (bool) $a['approve'], $this->actor($c), $a['note'] ?? null);
    }

    /** @param array<string, mixed> $a */
    public function approveAdjustment(mixed $root, array $a, GraphQLContext $c): string
    {
        return $this->adjustments->approve($a['id'], $this->actor($c), $a['note'] ?? null);
    }

    /** @param array<string, mixed> $a */
    public function rejectAdjustment(mixed $root, array $a, GraphQLContext $c): bool
    {
        $this->adjustments->reject($a['id'], $this->actor($c), $a['note']);

        return true;
    }

    /** @param array<string, mixed> $a */
    public function compensate(mixed $root, array $a, GraphQLContext $c): string
    {
        return $this->ledger->compensate($a['movementId'], $this->actor($c), $a['reason'], $a['idempotencyKey'] ?? null);
    }

    /** @param array<string, mixed> $a */
    public function createLocation(mixed $root, array $a, GraphQLContext $c): array
    {
        $in = $a['input'];
        $id = $this->locationManager->create((int) $a['warehouseId'], ['parent_id' => $in['parentId'] ?? null, 'code' => $in['code'], 'name' => $in['name'], 'type' => $in['type'] ?? 'shelf', 'capacity' => $in['capacity'] ?? null, 'is_pickable' => $in['isPickable'] ?? true, 'sort_order' => $in['sortOrder'] ?? 0], $this->actor($c)->userId);

        return $this->one($id);
    }

    /** @param array<string, mixed> $a */
    public function updateLocation(mixed $root, array $a, GraphQLContext $c): array
    {
        $in = $a['input'];
        $map = ['name' => 'name', 'type' => 'type', 'capacity' => 'capacity', 'isPickable' => 'is_pickable', 'isActive' => 'is_active', 'sortOrder' => 'sort_order'];
        $changes = [];
        foreach ($map as $graphql => $column) {
            array_key_exists($graphql, $in) && $changes[$column] = $in[$graphql];
        }
        $this->locationManager->update($a['id'], $changes, $this->actor($c)->userId);

        return $this->one($a['id']);
    }

    /** @param array<string, mixed> $a */
    public function moveLocation(mixed $root, array $a, GraphQLContext $c): array
    {
        $this->locationManager->move($a['id'], $a['parentId'] ?? null, $this->actor($c)->userId);

        return $this->one($a['id']);
    }

    /** @param array<string, mixed> $a */
    public function setThreshold(mixed $root, array $a, GraphQLContext $c): bool
    {
        $this->thresholds->set($a['productId'], (int) $a['warehouseId'], $a['locationId'] ?? null, $a['minimum'] ?? null, $a['critical'] ?? null, $this->actor($c)->userId);

        return true;
    }

    /** @param array<string, mixed> $a */
    public function updatePolicies(mixed $root, array $a, GraphQLContext $c): array
    {
        $map = ['approvalUnits' => 'approval_units', 'approvalPercent' => 'approval_percent', 'approvalAmount' => 'approval_amount', 'recoveryMarginPercent' => 'recovery_margin_percent'];
        $changes = [];
        foreach ($map as $graphql => $key) {
            isset($a['input'][$graphql]) && $changes[$key] = $a['input'][$graphql];
        }
        $this->policies->update($changes, $this->actor($c)->userId);

        return $this->policies();
    }

    /** @param array<string, mixed> $a */
    public function addSubscriber(mixed $root, array $a, GraphQLContext $c): array
    {
        $this->subscriptions->add($a['process'], $a['userId'], $this->actor($c)->userId, (bool) ($a['inbox'] ?? true), (bool) ($a['email'] ?? true));

        return $this->processes();
    }

    /** @param array<string, mixed> $a */
    public function removeSubscriber(mixed $root, array $a, GraphQLContext $c): array
    {
        $this->subscriptions->remove($a['process'], $a['userId'], $this->actor($c)->userId);

        return $this->processes();
    }

    /** @param array<string, mixed> $a */
    public function markAlertsRead(mixed $root, array $a, GraphQLContext $c): int
    {
        return $this->alerts->markRead((string) $c->user()?->getAuthIdentifier(), $a['ids'] ?? null);
    }

    // ------------------------------------------------------------------ shaping

    private function actor(GraphQLContext $context): Actor
    {
        return new Actor((string) $context->user()?->getAuthIdentifier(), $this->clientContext($context));
    }

    private function page(mixed $n): int
    {
        return max(1, min((int) $n, self::MAX_PAGE));
    }

    /** @param array<string, mixed> $l @return array<string, mixed> */
    private function shapeLocation(array $l): array
    {
        return ['id' => $l['id'], 'warehouseId' => $l['warehouse_id'], 'parentId' => $l['parent_id'], 'code' => $l['code'], 'name' => $l['name'], 'type' => $l['type'], 'depth' => $l['depth'], 'capacity' => $l['capacity'],
            'isPickable' => $l['is_pickable'], 'isActive' => $l['is_active'], 'isSystem' => $l['is_system'], 'version' => $l['version'], 'products' => $l['products'] ?? 0, 'units' => $l['units'] ?? '0.000'];
    }

    /** @return array<string, mixed> */
    private function one(string $id): array
    {
        $row = $this->locations->find($id) ?? throw new NotFound('The location');
        $stats = collect($this->queries->locations($row['warehouse_id'], true))->firstWhere('id', $id) ?? $row;

        return $this->shapeLocation($stats + ['products' => 0, 'units' => '0.000']);
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function detail(array $row): array
    {
        return [
            'location' => $this->one($row['id']),
            'contents' => array_map(fn (array $c) => ['productId' => $c['product_id'], 'sku' => $c['sku'], 'stockCode' => $c['stock_code'], 'name' => $c['name'], 'quantity' => $c['quantity'], 'version' => $c['version']], $this->queries->locationContents($row['id'])),
            'versions' => array_map(fn (array $v) => ['version' => $v['version'], 'changedBy' => $v['changed_by'], 'changedAt' => (string) $v['changed_at'], 'snapshot' => $v['snapshot']], $this->locations->versions($row['id'])),
        ];
    }

    /** @param array<string, mixed> $m @return array<string, mixed> */
    private function movement(array $m): array
    {
        return [
            'id' => $m['ulid'], 'productId' => $m['product_id'], 'sku' => $m['sku'], 'productName' => $m['product_name'], 'warehouseId' => $m['warehouse_id'], 'locationId' => $m['location_id'], 'locationCode' => $m['location_code'],
            'locationName' => $m['location_name'], 'counterpartCode' => $m['counterpart_code'], 'type' => $m['type'], 'quantity' => $m['quantity'], 'balanceBefore' => number_format((float) $m['balance_before'], 3, '.', ''),
            'balanceAfter' => $m['balance_after'], 'referenceType' => $m['reference_type'], 'referenceId' => $m['reference_id'], 'note' => $m['note'], 'userId' => $m['user_id'], 'userName' => $m['user_name'],
            'userEmail' => $m['user_email'], 'ip' => $m['ip'], 'country' => $m['country'], 'timezone' => $m['timezone'], 'createdAt' => $m['created_at'], 'createdAtUtc' => $m['created_at']->utc()->format('Y-m-d\TH:i:s\Z'),
            'compensatedBy' => $m['compensated_by'],
        ];
    }

    /** @return list<array<string, mixed>> */
    private function processes(): array
    {
        $emails = array_column($this->subscriptions->candidates(), 'email', 'id');
        $out = [];
        foreach ($this->subscriptions->overview() as $code => $p) {
            $out[] = ['code' => $code, 'label' => $p['label'], 'description' => $p['description'], 'severity' => $p['severity'],
                'subscribers' => array_map(fn (array $s) => ['userId' => $s['user_id'], 'email' => $emails[$s['user_id']] ?? '', 'inbox' => $s['inbox'], 'emailEnabled' => $s['email']], $p['subscribers'])];
        }

        return $out;
    }
}
