<?php

declare(strict_types=1);

namespace Cronos\Payments\Domain\ValueObjects;

final readonly class RefundResult
{
    /** @param 'succeeded'|'pending'|'failed' $status @param array<string, mixed> $raw */
    public function __construct(public string $reference, public string $status, public array $raw = [], public ?string $failure = null) {}
}
