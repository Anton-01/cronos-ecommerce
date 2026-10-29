<?php

declare(strict_types=1);

namespace Cronos\Inventory\Application;

use Cronos\Shared\Domain\ClientContext;

/** Who moved the stock and from where: kept on every Kardex row (user, IP, country and the clock of that place). */
final readonly class Actor
{
    public function __construct(public ?string $userId = null, public ?ClientContext $client = null) {}

    public static function system(): self
    {
        return new self;
    }
}
