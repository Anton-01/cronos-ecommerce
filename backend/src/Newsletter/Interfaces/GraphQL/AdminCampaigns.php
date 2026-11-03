<?php

declare(strict_types=1);

namespace Cronos\Newsletter\Interfaces\GraphQL;

use Cronos\Newsletter\Application\CampaignService;
use Cronos\Newsletter\Domain\Exceptions\NewsletterError;
use Cronos\Newsletter\Domain\Ports\CampaignMailer;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

final class AdminCampaigns
{
    public function __construct(private readonly CampaignService $campaigns, private readonly CampaignMailer $mailer) {}

    public function sending(): array
    {
        return ['configured' => $this->mailer->configured(), 'from' => $this->mailer->configured() ? (string) config('newsletter.resend.from') : null, 'webhookConfigured' => (string) config('newsletter.resend.webhook_secret') !== ''];
    }

    /** @param array<string, mixed> $args */
    public function list(mixed $root, array $args): array
    {
        return array_map($this->present(...), $this->campaigns->list($args['status'] ?? null));
    }

    /** @param array<string, mixed> $args */
    public function show(mixed $root, array $args): ?array
    {
        $c = $this->campaigns->find($args['id']);

        return $c === null ? null : $this->present($c);
    }

    /** @param array<string, mixed> $args */
    public function preview(mixed $root, array $args): array
    {
        return $this->campaigns->preview($args['id'], $args['locale'] ?? null);
    }

    /** @param array<string, mixed> $args */
    public function create(mixed $root, array $args, GraphQLContext $context): array
    {
        $id = $this->campaigns->create(['name' => $args['name'], 'audience' => $args['audience'] ?? [], 'translations' => $this->translations($args['translations'] ?? [])], $this->uid($context));

        return $this->present($this->campaigns->find($id));
    }

    /** @param array<string, mixed> $args */
    public function update(mixed $root, array $args, GraphQLContext $context): array
    {
        $in = [];
        array_key_exists('name', $args) && $in['name'] = $args['name'];
        array_key_exists('audience', $args) && $in['audience'] = $args['audience'] ?? [];
        array_key_exists('translations', $args) && $in['translations'] = $this->translations($args['translations'] ?? []);
        $this->campaigns->update($args['id'], $in, $this->uid($context));

        return $this->present($this->campaigns->find($args['id']));
    }

    /** @param array<string, mixed> $args */
    public function delete(mixed $root, array $args): bool
    {
        $this->campaigns->delete($args['id']);

        return true;
    }

    /** @param array<string, mixed> $args */
    public function test(mixed $root, array $args): bool
    {
        $this->campaigns->sendTest($args['id'], $args['email'], $args['locale'] ?? null);

        return true;
    }

    /** @param array<string, mixed> $args */
    public function send(mixed $root, array $args, GraphQLContext $context): array
    {
        $this->campaigns->send($args['id'], isset($args['at']) ? new \DateTimeImmutable((string) $args['at']) : null, $this->uid($context));

        return $this->present($this->campaigns->find($args['id']) ?? throw new NewsletterError('That campaign does not exist.', 'CAMPAIGN_NOT_FOUND'));
    }

    /** @param array<string, mixed> $args */
    public function cancel(mixed $root, array $args, GraphQLContext $context): array
    {
        $this->campaigns->cancel($args['id'], $this->uid($context));

        return $this->present($this->campaigns->find($args['id']));
    }

    /** @param list<array<string, mixed>> $list @return array<string, array<string, mixed>> */
    private function translations(array $list): array
    {
        $out = [];
        foreach ($list as $t) {
            $out[$t['locale']] = $t;
        }

        return $out;
    }

    /** @param array<string, mixed> $c @return array<string, mixed> */
    private function present(array $c): array
    {
        return [
            'id' => $c['id'], 'name' => $c['name'], 'status' => $c['status'], 'audience' => $c['audience'], 'scheduledAt' => $c['scheduled_at'], 'startedAt' => $c['started_at'], 'finishedAt' => $c['finished_at'],
            'total' => (int) $c['total'], 'counts' => $c['counts'], 'createdAt' => $c['created_at'],
            'translations' => array_map(static fn (string $l, array $t) => ['locale' => $l, 'subject' => $t['subject'], 'preheader' => $t['preheader'] ?? '', 'bodyMd' => $t['body_md']], array_keys($c['translations']), array_values($c['translations'])),
        ];
    }

    private function uid(GraphQLContext $context): ?string
    {
        $id = $context->user()?->getAuthIdentifier();

        return $id === null ? null : (string) $id;
    }
}
