<?php

declare(strict_types=1);

namespace Cronos\Payments\Domain\Ports;

interface GatewayFactory
{
    /** The gateway of a provider in a specific mode, built from the stored credentials of that mode. @throws \Cronos\Payments\Domain\Exceptions\PaymentError when not configured */
    public function make(string $provider, string $mode): PaymentGateway;
}
