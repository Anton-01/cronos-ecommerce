<?php

declare(strict_types=1);

namespace Cronos\CustomOrders\Application;

use Cronos\CustomOrders\Domain\Events\CustomOrderSubmitted;
use Cronos\CustomOrders\Domain\Events\CustomOrderUpdated;
use Cronos\CustomOrders\Domain\Events\QuoteSent;
use Cronos\CustomOrders\Domain\Exceptions\CustomOrderError;
use Cronos\CustomOrders\Domain\Ports\CustomOrderRepository;
use Cronos\Orders\Application\CustomOrderPlacer;
use Cronos\Pricing\Domain\Ports\PricingCatalog;
use Cronos\Shared\Domain\ClientContext;
use Cronos\Shared\Domain\DomainEventDispatcher;
use DateTimeImmutable;
use Illuminate\Support\Str;

/**
 * Made-to-measure orders: the customer describes what they need, staff discuss it and quote it, the customer accepts and pays. The quote
 * becomes a normal order (type "custom") that waits for payment like any other.
 */
final class CustomOrderService
{
    private const MOVES = [
        'submitted' => ['in_review', 'quoted', 'rejected', 'expired'],
        'in_review' => ['quoted', 'rejected', 'expired'],
        'quoted' => ['in_review', 'converted', 'rejected', 'expired'],
        'converted' => [],
        'rejected' => [],
        'expired' => [],
    ];

    public function __construct(
        private readonly CustomOrderRepository $requests,
        private readonly QuoteCalculator $calculator,
        private readonly PricingCatalog $pricing,
        private readonly CustomOrderPlacer $orders,
        private readonly DomainEventDispatcher $events,
    ) {}

    /** @param array<string, mixed> $specifications @return array<string, mixed> */
    public function submit(string $userId, string $email, string $marketCode, string $title, string $description, array $specifications, ?string $desiredDate, ?string $budget): array
    {
        $title = trim($title);
        $description = trim($description);
        (mb_strlen($title) >= 3 && mb_strlen($title) <= 160) || throw new CustomOrderError('Give the request a short title (3 to 160 characters).', 'INVALID_TITLE');
        (mb_strlen($description) >= 10 && mb_strlen($description) <= 5000) || throw new CustomOrderError('Describe what you need (10 to 5000 characters).', 'INVALID_DESCRIPTION');
        $budget === null || (is_numeric($budget) && (float) $budget >= 0) || throw new CustomOrderError('The budget must be zero or more.', 'INVALID_BUDGET');
        $desiredDate === null || (strtotime($desiredDate) !== false && strtotime($desiredDate) >= strtotime('today')) || throw new CustomOrderError('The desired date cannot be in the past.', 'INVALID_DATE');
        strlen((string) json_encode($specifications)) <= 20000 || throw new CustomOrderError('The specifications are too long.', 'INVALID_SPECIFICATIONS');
        $this->market($marketCode);

        $id = (string) Str::ulid();
        $number = $this->requests->nextNumber();
        $this->requests->atomically(function () use ($id, $number, $userId, $email, $marketCode, $title, $description, $specifications, $desiredDate, $budget): void {
            $this->requests->insert([
                'id' => $id, 'number' => $number, 'user_id' => $userId, 'email' => strtolower(trim($email)), 'market_code' => strtoupper($marketCode), 'title' => $title,
                'description' => $description, 'specifications' => $specifications, 'desired_date' => $desiredDate, 'budget' => $budget,
            ]);
            $this->requests->addMessage($id, ['author_type' => 'customer', 'author_id' => $userId, 'body' => $description]);
        });
        $this->events->dispatch(new CustomOrderSubmitted($id, $number, strtolower(trim($email)), $title));

        return $this->requests->find($id);
    }

    public function customerMessage(string $requestId, string $userId, string $body): void
    {
        $r = $this->owned($requestId, $userId);
        in_array($r['status'], ['rejected', 'expired', 'converted'], true) && throw new CustomOrderError('This request is closed.', 'REQUEST_CLOSED');
        $this->requests->addMessage($requestId, ['author_type' => 'customer', 'author_id' => $userId, 'body' => $this->body($body)]);
    }

    public function staffMessage(string $requestId, string $staffId, string $body, bool $internal): void
    {
        $r = $this->requests->find($requestId) ?? throw new CustomOrderError('That request does not exist.', 'REQUEST_NOT_FOUND');
        $body = $this->body($body);
        $this->requests->addMessage($requestId, ['author_type' => 'staff', 'author_id' => $staffId, 'body' => $body, 'is_internal' => $internal]);
        $internal || $this->events->dispatch(new CustomOrderUpdated($requestId, $r['number'], $r['email'], $r['user_id'], $r['market_code'], 'message', $body, 'msg:'.$requestId.':'.hash('sha256', $body.microtime())));
    }

    public function assign(string $requestId, ?string $staffId): void
    {
        $this->requests->find($requestId) ?? throw new CustomOrderError('That request does not exist.', 'REQUEST_NOT_FOUND');
        $this->requests->update($requestId, ['assigned_to' => $staffId]);
    }

    /** Staff-driven moves: in_review, or rejected with a reason the customer will read. */
    public function setStatus(string $requestId, string $to, ?string $reason, string $staffId): array
    {
        $this->requests->atomically(function () use ($requestId, $to, $reason, $staffId): void {
            $r = $this->requests->lock($requestId) ?? throw new CustomOrderError('That request does not exist.', 'REQUEST_NOT_FOUND');
            in_array($to, ['in_review', 'rejected'], true) || throw new CustomOrderError('Staff can move a request to in_review or rejected; quoting and converting have their own actions.', 'INVALID_TRANSITION');
            $this->assertMove($r['status'], $to);
            $to !== 'rejected' || ($reason !== null && trim($reason) !== '') || throw new CustomOrderError('Tell the customer why the request is rejected.', 'REASON_REQUIRED');
            $this->requests->update($requestId, ['status' => $to] + ($to === 'rejected' ? ['rejection_reason' => trim((string) $reason)] : []));
            if ($to === 'rejected') {
                foreach ($this->requests->quotes($requestId, true) as $q) {
                    in_array($q['status'], ['draft', 'sent'], true) && $this->requests->updateQuote($q['id'], ['status' => 'superseded']);
                }
                $this->requests->addMessage($requestId, ['author_type' => 'system', 'author_id' => $staffId, 'body' => 'Rejected: '.trim((string) $reason)]);
                $this->events->dispatch(new CustomOrderUpdated($requestId, $r['number'], $r['email'], $r['user_id'], $r['market_code'], 'rejected', trim((string) $reason), 'rejected:'.$requestId));
            }
        });

        return $this->requests->find($requestId);
    }

    /**
     * @param  list<array{description: string, unit: string, quantity: string, unit_price: string, tax_rate?: ?string, product_id?: ?string}>  $lines
     * @return array<string, mixed> the draft quote
     */
    public function createQuote(string $requestId, array $lines, string $shipping, ?string $validUntil, ?string $terms, string $staffId): array
    {
        $id = (string) Str::ulid();
        $this->requests->atomically(function () use ($requestId, $lines, $shipping, $validUntil, $terms, $staffId, $id): void {
            $r = $this->requests->lock($requestId) ?? throw new CustomOrderError('That request does not exist.', 'REQUEST_NOT_FOUND');
            in_array($r['status'], ['submitted', 'in_review', 'quoted'], true) || throw new CustomOrderError('This request cannot be quoted any more.', 'REQUEST_CLOSED');
            $validUntil === null || (strtotime($validUntil) !== false && strtotime($validUntil) >= strtotime('today')) || throw new CustomOrderError('The validity date cannot be in the past.', 'INVALID_DATE');

            ['market' => $market, 'currency' => $currency, 'rate' => $defaultRate] = $this->market($r['market_code']);
            $lines = array_map(static fn (array $l) => $l + ['tax_rate' => null], $lines);
            foreach ($lines as &$l) {
                $l['tax_rate'] ??= $defaultRate;
            }
            unset($l);
            $calc = $this->calculator->calculate($lines, $shipping, (bool) $market['prices_include_tax'], $currency['code'], (int) $currency['decimals']);

            $this->requests->insertQuote([
                'id' => $id, 'request_id' => $requestId, 'version' => $this->requests->nextQuoteVersion($requestId), 'currency' => $currency['code'], 'tax_included' => (bool) $market['prices_include_tax'],
                'subtotal' => $calc['subtotal'], 'tax_total' => $calc['tax_total'], 'shipping_total' => $calc['shipping_total'], 'grand_total' => $calc['grand_total'],
                'valid_until' => $validUntil ?? (new DateTimeImmutable('+15 days'))->format('Y-m-d'), 'terms' => $terms, 'created_by' => $staffId,
            ], $calc['lines']);
        });

        return $this->requests->quote($id);
    }

    public function sendQuote(string $quoteId, string $staffId): array
    {
        $this->requests->atomically(function () use ($quoteId): void {
            $q = $this->requests->lockQuote($quoteId) ?? throw new CustomOrderError('That quote does not exist.', 'QUOTE_NOT_FOUND');
            $r = $this->requests->lock($q['request_id']);
            $q['status'] === 'draft' || throw new CustomOrderError('Only a draft quote can be sent.', 'QUOTE_NOT_DRAFT');
            in_array($r['status'], ['submitted', 'in_review', 'quoted'], true) || throw new CustomOrderError('This request cannot be quoted any more.', 'REQUEST_CLOSED');
            ($q['valid_until'] === null || strtotime($q['valid_until']) >= strtotime('today')) || throw new CustomOrderError('The quote has already expired: change its validity first.', 'QUOTE_EXPIRED');

            foreach ($this->requests->quotes($r['id'], false) as $old) {
                $old['status'] === 'sent' && $this->requests->updateQuote($old['id'], ['status' => 'superseded']);
            }
            $this->requests->updateQuote($quoteId, ['status' => 'sent', 'sent_at' => now()]);
            $this->requests->update($r['id'], ['status' => 'quoted']);
        });

        $q = $this->requests->quote($quoteId);
        $r = $this->requests->find($q['request_id']);
        $this->events->dispatch(new QuoteSent($r['id'], $q['id'], $r['number'], $r['email'], $r['user_id'], $r['market_code'], $q['grand_total'], $q['currency'], $q['valid_until']));

        return $q;
    }

    /**
     * The customer accepts: the quote becomes an order waiting for payment. Idempotent per quote (a double click returns the same order).
     *
     * @param  array<string, mixed>  $shipping
     * @param  array<string, mixed>|null  $billing
     * @return array<string, mixed> the order
     */
    public function accept(string $quoteId, string $userId, array $shipping, ?array $billing, ?ClientContext $client = null): array
    {
        $order = $this->requests->atomically(function () use ($quoteId, $userId, $shipping, $billing, $client): array {
            $q = $this->requests->lockQuote($quoteId) ?? throw new CustomOrderError('That quote does not exist.', 'QUOTE_NOT_FOUND');
            $r = $this->requests->lock($q['request_id']);
            $r['user_id'] === $userId || throw new CustomOrderError('That quote does not exist.', 'QUOTE_NOT_FOUND');
            if ($q['status'] === 'accepted') {
                return $this->orders->findForQuote($quoteId);
            }
            $q['status'] === 'sent' || throw new CustomOrderError('This quote is no longer open.', 'QUOTE_NOT_OPEN');
            ($q['valid_until'] === null || strtotime($q['valid_until']) >= strtotime('today')) || throw new CustomOrderError('This quote has expired. Ask us for a new one.', 'QUOTE_EXPIRED');
            $country = $this->market($r['market_code'])['market']['country_code'];
            strtoupper((string) ($shipping['country_code'] ?? '')) === $country || throw new CustomOrderError("We ship this market's orders to {$country} only.", 'SHIPPING_COUNTRY_MISMATCH');

            $quote = $this->requests->quote($quoteId);
            $order = $this->orders->place($r, $quote, $shipping, $billing ?? $shipping, $client);
            $this->requests->updateQuote($quoteId, ['status' => 'accepted', 'decided_at' => now()]);
            $this->requests->update($r['id'], ['status' => 'converted', 'order_id' => $order['id']]);
            $this->requests->addMessage($r['id'], ['author_type' => 'system', 'author_id' => $userId, 'body' => "Quote v{$q['version']} accepted: order {$order['order_number']} is waiting for payment."]);

            return $order;
        });

        return $order;
    }

    /** The customer wants changes: the request goes back to review with their reason. */
    public function rejectQuote(string $quoteId, string $userId, string $reason): void
    {
        $this->requests->atomically(function () use ($quoteId, $userId, $reason): void {
            $q = $this->requests->lockQuote($quoteId) ?? throw new CustomOrderError('That quote does not exist.', 'QUOTE_NOT_FOUND');
            $r = $this->requests->lock($q['request_id']);
            $r['user_id'] === $userId || throw new CustomOrderError('That quote does not exist.', 'QUOTE_NOT_FOUND');
            $q['status'] === 'sent' || throw new CustomOrderError('This quote is no longer open.', 'QUOTE_NOT_OPEN');
            $this->requests->updateQuote($quoteId, ['status' => 'rejected', 'decided_at' => now()]);
            $this->requests->update($r['id'], ['status' => 'in_review']);
            $this->requests->addMessage($r['id'], ['author_type' => 'customer', 'author_id' => $userId, 'body' => 'Quote declined: '.$this->body($reason)]);
        });
    }

    /** Sent quotes past their date expire, and the request with them (the customer can ask again with a message). */
    public function expireDue(?DateTimeImmutable $today = null): int
    {
        $n = 0;
        foreach ($this->requests->expiredQuotes($today ?? new DateTimeImmutable('today'), 200) as $q) {
            $this->requests->atomically(function () use ($q): void {
                $fresh = $this->requests->lockQuote($q['id']);
                if ($fresh === null || $fresh['status'] !== 'sent') {
                    return;
                }
                $this->requests->updateQuote($q['id'], ['status' => 'expired']);
                $this->requests->update($q['request_id'], ['status' => 'expired']);
                $this->requests->addMessage($q['request_id'], ['author_type' => 'system', 'author_id' => null, 'body' => "Quote v{$q['version']} expired."]);
            });
            $n++;
        }

        return $n;
    }

    /** @return array<string, mixed> */
    public function owned(string $requestId, string $userId): array
    {
        $r = $this->requests->find($requestId);
        ($r !== null && $r['user_id'] === $userId) || throw new CustomOrderError('That request does not exist.', 'REQUEST_NOT_FOUND');

        return $r;
    }

    private function assertMove(string $from, string $to): void
    {
        in_array($to, self::MOVES[$from] ?? [], true) || throw new CustomOrderError("A request that is {$from} cannot become {$to}.", 'INVALID_TRANSITION');
    }

    private function body(string $text): string
    {
        $text = trim($text);
        (mb_strlen($text) >= 1 && mb_strlen($text) <= 5000) || throw new CustomOrderError('A message has 1 to 5000 characters.', 'INVALID_MESSAGE');

        return $text;
    }

    /** @return array{market: array<string, mixed>, currency: array<string, mixed>, rate: string} */
    private function market(string $code): array
    {
        $snap = $this->pricing->snapshot();
        $market = collect($snap['markets'])->firstWhere('code', strtoupper($code));
        ($market !== null && $market['is_active']) || throw new CustomOrderError('That market does not exist.', 'UNKNOWN_MARKET');
        $currency = collect($snap['currencies'])->firstWhere('id', $market['currency_id']);
        $class = collect($snap['tax_classes'])->firstWhere('is_default', true);
        $rate = collect($snap['tax_rates'])->filter(fn ($t) => $t['market_id'] === $market['id'] && $t['tax_class_id'] === ($class['id'] ?? null) && $t['region'] === null)->sum(fn ($t) => (float) $t['rate']);

        return ['market' => $market, 'currency' => $currency, 'rate' => (string) $rate];
    }
}
