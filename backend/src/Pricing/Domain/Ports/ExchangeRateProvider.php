<?php

declare(strict_types=1);

namespace Cronos\Pricing\Domain\Ports;

interface ExchangeRateProvider
{
    public function name(): string;

    /**
     * @param  list<string>  $targets  ISO codes
     * @return array<string, string> code => units of that currency per 1 unit of $base
     */
    public function fetch(string $base, array $targets): array;
}
