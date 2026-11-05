<?php

declare(strict_types=1);

namespace Cronos\Inventory\Application;

use Brick\Math\BigDecimal;
use Cronos\Inventory\Domain\Exceptions\Conflict;
use Cronos\Inventory\Domain\Exceptions\InvalidMovement;
use Cronos\Inventory\Domain\Exceptions\NotFound;
use Cronos\Inventory\Domain\Ports\LocationRepository;
use Cronos\Inventory\Domain\Ports\LocationStore;
use Cronos\Inventory\Domain\Ports\OperationsStore;
use Cronos\Inventory\Domain\Ports\ProductValuation;
use Cronos\Inventory\Domain\Ports\StockStore;
use Cronos\Inventory\Domain\Services\ApprovalPolicy;
use Cronos\Inventory\Domain\Services\Quantities;
use Cronos\Inventory\Domain\ValueObjects\AlertProcess;
use Cronos\Inventory\Domain\ValueObjects\LocationMovementType as L;

/**
 * "I look at this shelf and there are N". The person sends what they saw (quantity and version) and what they found; a small difference is
 * applied at once, a bigger one waits for a lead. Either way the request stays as the record of who asked, who decided and why.
 */
final class AdjustmentRequests
{
    public function __construct(
        private readonly OperationsStore $store,
        private readonly LocationStore $stock,
        private readonly StockStore $books,
        private readonly LocationRepository $locations,
        private readonly WarehouseOperations $operations,
        private readonly WarehousePolicies $policies,
        private readonly ProductValuation $valuation,
        private readonly AlertRaiser $alerts,
    ) {}

    /**
     * @return array{id: string, status: string} status is approved (applied now) or pending (a lead decides)
     */
    public function request(string $productId, string $locationId, string|int|float $target, int $seenVersion, string $reason, Actor $actor, ?string $idempotencyKey = null): array
    {
        trim($reason) !== '' || throw new InvalidMovement('Say what you found (a reason is required).', 'REASON_REQUIRED');
        $target = Quantities::of((string) $target);
        $target->isNegative() && throw new InvalidMovement('A quantity cannot be negative.', 'INVALID_QUANTITY');

        $result = $this->store->atomically(function () use ($productId, $locationId, $target, $seenVersion, $reason, $actor, $idempotencyKey): array {
            if ($idempotencyKey !== null) {
                $this->store->advisoryLock('idem:'.$idempotencyKey);
                if (($seen = $this->store->requestByKey($idempotencyKey)) !== null) {
                    return ['id' => $seen['id'], 'status' => $seen['status']];
                }
            }
            $location = $this->locations->find($locationId) ?? throw new NotFound('The location');
            // Same order as every other job: the warehouse books first, then the shelf (a request may be applied right here).
            $this->books->lockLevel($productId, $location['warehouse_id']);
            $current = $this->stock->lockStock($productId, $locationId);
            $current['version'] === $seenVersion || throw new Conflict("The shelf changed while you were counting: it holds {$current['quantity']} now. Look again.", 'STALE_VERSION');
            $delta = $target->minus($current['quantity']);
            $delta->isZero() && throw new InvalidMovement('That is what the system already says.', 'NO_CHANGE');

            $id = $this->store->createRequest([
                'product_id' => $productId, 'warehouse_id' => $location['warehouse_id'], 'location_id' => $locationId, 'seen_quantity' => $current['quantity'], 'seen_version' => $seenVersion,
                'target_quantity' => (string) $target, 'delta' => (string) $delta, 'reason' => mb_substr($reason, 0, 500), 'idempotency_key' => $idempotencyKey,
                'requested_by' => $actor->userId, 'requested_at' => now(),
            ]);
            $unit = $this->valuation->unitValue($productId);
            if (ApprovalPolicy::needsApproval($delta, BigDecimal::of($current['quantity']), $unit === null ? null : BigDecimal::of($unit), $this->policies->current())) {
                return ['id' => $id, 'status' => 'pending', 'notify' => [$productId, $location['warehouse_id'], $locationId, (string) $delta, $current['quantity'], $reason]];
            }
            $ulid = $this->apply($id, $productId, $locationId, $delta, $actor, $reason);
            $this->store->decideRequest($id, 'approved', $actor->userId, 'Within the automatic limit', $ulid);

            return ['id' => $id, 'status' => 'approved'];
        });

        if (isset($result['notify'])) {
            [$productId, $warehouseId, $locationId, $delta, $was, $why] = $result['notify'];
            $this->alerts->raise(AlertProcess::ADJUSTMENT_REQUESTED, 'Ajuste por aprobar', "Se pidió un cambio de {$delta} (había {$was}). Motivo: {$why}", 'adjreq:'.$result['id'], $productId, $warehouseId, $locationId, ['request' => $result['id'], 'delta' => $delta]);
            unset($result['notify']);
        }

        return $result;
    }

    /** A lead says yes: the difference the person reported is applied now (against what the shelf holds today). */
    public function approve(string $requestId, Actor $lead, ?string $note = null): string
    {
        return $this->store->atomically(function () use ($requestId, $lead, $note): string {
            $r = $this->pending($requestId);
            ($r['requested_by'] === null || $r['requested_by'] !== $lead->userId) || throw new Conflict('Someone else has to approve your own request.', 'SELF_APPROVAL');
            $ulid = $this->apply($requestId, $r['product_id'], $r['location_id'], BigDecimal::of($r['delta']), $lead, $r['reason']);
            $this->store->decideRequest($requestId, 'approved', $lead->userId, $note, $ulid);
            $this->told($r, 'aprobado', $note);

            return $ulid;
        });
    }

    public function reject(string $requestId, Actor $lead, string $note): void
    {
        trim($note) !== '' || throw new InvalidMovement('Say why it is rejected.', 'REASON_REQUIRED');
        $this->store->atomically(function () use ($requestId, $lead, $note): void {
            $r = $this->pending($requestId);
            $this->store->decideRequest($requestId, 'rejected', $lead->userId, mb_substr($note, 0, 500), null);
            $this->told($r, 'rechazado', $note);
        });
    }

    /** @param array<string, mixed> $r */
    private function told(array $r, string $outcome, ?string $note): void
    {
        $this->alerts->raise(AlertProcess::ADJUSTMENT_DECIDED, "Ajuste {$outcome}", "El ajuste de {$r['delta']} fue {$outcome}.".($note ? " Nota: {$note}" : ''), 'adjdec:'.$r['id'], $r['product_id'], (int) $r['warehouse_id'], $r['location_id'], ['request' => $r['id']], $r['requested_by'] === null ? [] : [$r['requested_by']]);
    }

    /** @return array<string, mixed> */
    private function pending(string $id): array
    {
        $r = $this->store->findRequest($id, lock: true) ?? throw new NotFound('The request');
        $r['status'] === 'pending' || throw new Conflict('That request was already decided.', 'ALREADY_DECIDED');

        return $r;
    }

    private function apply(string $requestId, string $productId, string $locationId, BigDecimal $delta, Actor $actor, string $reason): string
    {
        return $this->operations->adjust($productId, $locationId, $delta, $actor, L::ADJUST, 'adjustment_request', $requestId, 'adjreq:'.$requestId, $reason);
    }
}
