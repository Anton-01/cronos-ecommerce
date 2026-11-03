<?php

declare(strict_types=1);

namespace Cronos\Contact\Interfaces\GraphQL;

use Cronos\Contact\Application\ContactService;
use Cronos\Shared\Interfaces\GraphQL\ResolvesClientContext;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

final class StorefrontContact
{
    use ResolvesClientContext;

    public function __construct(private readonly ContactService $contact) {}

    public function config(): array
    {
        $key = (string) config('human.turnstile.site_key');

        // The site key is only offered when the secret is set too: a widget without server-side checking would be decoration.
        return ['turnstileSiteKey' => $key !== '' && (string) config('human.turnstile.secret') !== '' ? $key : null, 'turnstileAction' => 'contact', 'topics' => $this->contact->topics()];
    }

    /** @param array<string, mixed> $args */
    public function submit(mixed $root, array $args, GraphQLContext $context): array
    {
        $in = $args['input'];
        $locale = in_array($args['locale'] ?? null, config('app.supported_locales'), true) ? $args['locale'] : (string) config('app.locale_default');
        $result = $this->contact->submit(
            ['name' => $in['name'], 'email' => $in['email'], 'phone' => $in['phone'] ?? null, 'topic' => $in['topic'] ?? 'general', 'order_number' => $in['orderNumber'] ?? null, 'message' => $in['message'], 'website' => $in['website'] ?? null, 'rendered_at' => $in['renderedAt'] ?? null],
            $args['humanToken'] ?? null, $this->clientContext($context), $context->user()?->getAuthIdentifier() === null ? null : (string) $context->user()->getAuthIdentifier(),
            isset($args['market']) ? strtoupper((string) $args['market']) : null, $locale,
        );

        return ['reference' => $result['reference']];
    }
}
