<?php

declare(strict_types=1);

namespace Cronos\Newsletter\Interfaces\GraphQL;

use Cronos\Newsletter\Application\NewsletterService;
use Cronos\Shared\Interfaces\GraphQL\ResolvesClientContext;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

final class StorefrontNewsletter
{
    use ResolvesClientContext;

    public function __construct(private readonly NewsletterService $newsletter) {}

    public function config(): array
    {
        $key = (string) config('human.turnstile.site_key');

        return ['turnstileSiteKey' => $key !== '' && (string) config('human.turnstile.secret') !== '' ? $key : null, 'turnstileAction' => 'newsletter'];
    }

    /** @param array<string, mixed> $args */
    public function subscribe(mixed $root, array $args, GraphQLContext $context): array
    {
        $locale = in_array($args['locale'] ?? null, config('app.supported_locales'), true) ? $args['locale'] : (string) config('app.locale_default');
        $uid = $context->user()?->getAuthIdentifier();
        $this->newsletter->subscribe(
            ['email' => $args['email'], 'source' => $args['source'] ?? 'footer', 'website' => $args['website'] ?? null, 'rendered_at' => $args['renderedAt'] ?? null],
            $args['humanToken'] ?? null, $this->clientContext($context), $uid === null ? null : (string) $uid, isset($args['market']) ? strtoupper((string) $args['market']) : null, $locale,
        );

        return ['status' => 'check_email'];
    }

    /** @param array<string, mixed> $args */
    public function confirm(mixed $root, array $args, GraphQLContext $context): array
    {
        $this->newsletter->confirm($args['token'], $this->clientContext($context));

        return ['status' => 'confirmed'];
    }

    /** @param array<string, mixed> $args */
    public function unsubscribe(mixed $root, array $args): array
    {
        $this->newsletter->unsubscribe($args['token']);

        return ['status' => 'unsubscribed'];
    }
}
