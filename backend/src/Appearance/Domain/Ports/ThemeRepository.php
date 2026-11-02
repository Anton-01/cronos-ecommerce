<?php

declare(strict_types=1);

namespace Cronos\Appearance\Domain\Ports;

interface ThemeRepository
{
    public function atomically(callable $work): mixed;

    /** @return array<string, mixed>|null the live version */
    public function published(): ?array;

    /** @return array<string, mixed>|null */
    public function draft(): ?array;

    /** @return array<string, mixed>|null */
    public function find(string $id): ?array;

    /** @return list<array<string, mixed>> newest first, without the heavy documents */
    public function versions(int $limit): array;

    /** @param array<string, mixed> $content tokens, assets, navigation, home, features @return array<string, mixed> the new draft */
    public function createDraft(array $content, ?string $derivedFrom, ?string $userId, ?string $name): array;

    /** @param array<string, mixed> $changes */
    public function updateDraft(string $id, array $changes): void;

    public function deleteDraft(string $id): void;

    /** Makes the draft the live version and archives the previous one. */
    public function publish(string $draftId, ?string $userId, ?string $notes): void;
}
