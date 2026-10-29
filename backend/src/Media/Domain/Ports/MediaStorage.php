<?php

declare(strict_types=1);

namespace Cronos\Media\Domain\Ports;

interface MediaStorage
{
    public function put(string $disk, string $path, string $contents): void;

    public function get(string $disk, string $path): string;

    public function delete(string $disk, string $path): void;

    public function url(string $disk, string $path): string;
}
