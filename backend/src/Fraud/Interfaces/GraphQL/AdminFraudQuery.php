<?php

declare(strict_types=1);

namespace Cronos\Fraud\Interfaces\GraphQL;

use Cronos\Fraud\Domain\Ports\FraudRepository;
use Illuminate\Support\Str;

final class AdminFraudQuery
{
    public function __construct(private readonly FraudRepository $repository) {}

    public function rules(): array
    {
        return array_map(self::rule(...), $this->repository->rules());
    }

    /** @param array<string, mixed> $args */
    public function checks(mixed $root, array $args): array
    {
        return array_map(function (array $c): array {
            $out = [];
            foreach ($c as $k => $v) {
                $out[Str::camel($k)] = $v;
            }

            return $out;
        }, $this->repository->checks($args['orderId'] ?? null, $args['decision'] ?? null, (int) ($args['first'] ?? 50)));
    }

    /** @param array<string, mixed> $r @return array<string, mixed> */
    public static function rule(array $r): array
    {
        return ['id' => $r['id'], 'name' => $r['name'], 'type' => $r['type'], 'params' => $r['params'], 'score' => $r['score'], 'isActive' => $r['is_active']];
    }
}
