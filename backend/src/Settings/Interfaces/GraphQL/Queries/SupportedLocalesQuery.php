<?php

declare(strict_types=1);

namespace Cronos\Settings\Interfaces\GraphQL\Queries;

final class SupportedLocalesQuery
{
    /** @return list<array{code: string, isDefault: bool}> */
    public function __invoke(): array
    {
        $default = (string) config('app.locale_default');

        return array_map(fn (string $code) => ['code' => $code, 'isDefault' => $code === $default], (array) config('app.supported_locales'));
    }
}
