<?php

declare(strict_types=1);

namespace Cronos\Contact\Interfaces\GraphQL;

use Cronos\Contact\Application\ContactService;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

final class AdminContact
{
    public function __construct(private readonly ContactService $contact) {}

    /** @param array<string, mixed> $args */
    public function list(mixed $root, array $args): array
    {
        $page = $this->contact->search($args['status'] ?? null, $args['search'] ?? null, (int) ($args['first'] ?? 25), (int) ($args['offset'] ?? 0));

        return ['total' => $page['total'], 'rows' => array_map($this->present(...), $page['rows'])];
    }

    /** @param array<string, mixed> $args */
    public function show(mixed $root, array $args): ?array
    {
        $m = $this->contact->find($args['id']);

        return $m === null ? null : $this->present($m);
    }

    /** @param array<string, mixed> $args */
    public function setStatus(mixed $root, array $args, GraphQLContext $context): array
    {
        $uid = $context->user()?->getAuthIdentifier();

        return $this->present($this->contact->setStatus($args['id'], $args['status'], $uid === null ? null : (string) $uid));
    }

    /** @param array<string, mixed> $m @return array<string, mixed> */
    private function present(array $m): array
    {
        return [
            'id' => $m['id'], 'marketCode' => $m['market_code'], 'locale' => $m['locale'], 'name' => $m['name'], 'email' => $m['email'], 'phone' => $m['phone'], 'topic' => $m['topic'], 'orderNumber' => $m['order_number'],
            'message' => $m['message'], 'status' => $m['status'], 'spamSignals' => $m['spam_signals'], 'humanCheck' => $m['human_check'], 'ip' => $m['ip'], 'country' => $m['country_code'], 'createdAt' => $m['created_at'], 'handledAt' => $m['handled_at'],
        ];
    }
}
