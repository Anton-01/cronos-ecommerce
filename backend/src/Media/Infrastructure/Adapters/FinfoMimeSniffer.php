<?php

declare(strict_types=1);

namespace Cronos\Media\Infrastructure\Adapters;

use Cronos\Media\Domain\Ports\MimeSniffer;

final class FinfoMimeSniffer implements MimeSniffer
{
    public function sniff(string $contents): string
    {
        return (string) (new \finfo(FILEINFO_MIME_TYPE))->buffer($contents);
    }
}
