<?php

declare(strict_types=1);

namespace Cronos\Catalog\Domain\Ports;

interface PreviewTokenStore
{
    /** Creates a single product's preview token and returns the plain secret (only its hash is stored). */
    public function issue(string $productId, int $ttlMinutes, ?string $createdBy): string;

    /** @return string|null the product id when the token is valid and not expired */
    public function resolve(string $plainToken): ?string;
}
