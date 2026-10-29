<?php

declare(strict_types=1);

namespace Cronos\Shipping\Interfaces\Http;

use Cronos\Shipping\Application\ShipmentService;
use Cronos\Shipping\Domain\Exceptions\ShippingError;
use DateTimeImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Throwable;

/**
 * Carrier tracking notifications in the shop's own neutral format:
 *   {"events": [{"event_id": "...", "tracking_number": "...", "status": "in_transit", "description": "...", "location": "...", "occurred_at": "2026-..."}]}
 * signed with HMAC-SHA256 of the raw body using the carrier's secret (header `X-Signature: sha256=<hex>`). A carrier-specific adapter
 * translates its own payload into this shape before calling the service. Replays are harmless: events are idempotent.
 */
final class ShippingWebhookController extends Controller
{
    public function __invoke(Request $request, string $carrier, ShipmentService $shipments): JsonResponse
    {
        $secret = (string) config("shipping.carriers.{$carrier}.webhook_secret", '');
        $given = (string) preg_replace('/^sha256=/', '', (string) $request->header('X-Signature', ''));
        if ($secret === '' || ! hash_equals(hash_hmac('sha256', $request->getContent(), $secret), $given)) {
            return new JsonResponse(['received' => false], 401); // unknown carrier and bad signature look the same
        }

        $payload = json_decode($request->getContent(), true);
        if (! is_array($payload) || ! is_array($payload['events'] ?? null) || count($payload['events']) > 100) {
            return new JsonResponse(['received' => false], 422);
        }

        $accepted = 0;
        foreach ($payload['events'] as $e) {
            try {
                $tracking = (string) ($e['tracking_number'] ?? '');
                $status = (string) ($e['status'] ?? '');
                $at = new DateTimeImmutable((string) ($e['occurred_at'] ?? 'now'));
                $key = (string) ($e['event_id'] ?? hash('sha256', $tracking.'|'.$status.'|'.$at->format('c')));
                $tracking !== '' && $shipments->ingest($carrier, $tracking, $key, $status, $e['description'] ?? null, $e['location'] ?? null, $at, $e) && $accepted++;
            } catch (ShippingError|\Exception $err) {
                report($err); // one bad event must not drop the rest of the batch
            } catch (Throwable $err) {
                return new JsonResponse(['received' => false], 500); // the carrier retries the whole batch; ingestion is idempotent
            }
        }

        return new JsonResponse(['received' => true, 'accepted' => $accepted]);
    }
}
