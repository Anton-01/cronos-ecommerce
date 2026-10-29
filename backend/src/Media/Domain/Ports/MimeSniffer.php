<?php

declare(strict_types=1);

namespace Cronos\Media\Domain\Ports;

interface MimeSniffer
{
    /** Detects the real type from the bytes, ignoring whatever the client claimed. */
    public function sniff(string $contents): string;
}
