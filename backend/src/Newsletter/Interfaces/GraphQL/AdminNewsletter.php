<?php

declare(strict_types=1);

namespace Cronos\Newsletter\Interfaces\GraphQL;

use Cronos\Newsletter\Application\NewsletterService;

final class AdminNewsletter
{
    public function __construct(private readonly NewsletterService $newsletter) {}

    /** @param array<string, mixed> $args */
    public function list(mixed $root, array $args): array
    {
        $page = $this->newsletter->search($args['status'] ?? null, $args['search'] ?? null, (int) ($args['first'] ?? 50), (int) ($args['offset'] ?? 0));

        return ['total' => $page['total'], 'counts' => $page['counts'], 'rows' => array_map(static fn (array $r) => [
            'id' => $r['id'], 'email' => $r['email'], 'status' => $r['status'], 'locale' => $r['locale'], 'marketCode' => $r['market_code'], 'source' => $r['source'], 'consentedAt' => $r['consented_at'],
            'consentCountry' => $r['consent_country'], 'confirmedAt' => $r['confirmed_at'], 'unsubscribedAt' => $r['unsubscribed_at'], 'unsubscribedBy' => $r['unsubscribed_by'], 'createdAt' => $r['created_at'],
        ], $page['rows'])];
    }

    /** @param array<string, mixed> $args */
    public function unsubscribe(mixed $root, array $args): bool
    {
        $this->newsletter->removeByAdmin($args['id']);

        return true;
    }

    /** @param array<string, mixed> $args */
    public function erase(mixed $root, array $args): bool
    {
        $this->newsletter->erase($args['id']);

        return true;
    }
}
