<?php

declare(strict_types=1);

namespace Cronos\Payments\Domain\Ports;

interface ProviderRepository
{
    /** @return array{code: string, name: string, is_enabled: bool, mode: string, markets: list<string>, min_amount: ?string, max_amount: ?string, live_since: ?string, credentials: array{sandbox: array<string, string>, live: array<string, string>}}|null */
    public function find(string $code): ?array;

    /** @return list<array<string, mixed>> */
    public function all(): array;

    /** @param array<string, mixed> $data scalar columns */
    public function update(string $code, array $data): void;

    /** @param array<string, string> $credentials replaces the credentials of one mode */
    public function saveCredentials(string $code, string $mode, array $credentials): void;
}
