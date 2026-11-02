<?php

declare(strict_types=1);

namespace Cronos\Appearance\Domain\Ports;

interface SvgSanitizer
{
    /** Returns a safe copy of the SVG (no scripts, no external references) or throws ThemeError when it cannot be made safe. */
    public function sanitize(string $svg): string;
}
