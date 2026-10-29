<?php

declare(strict_types=1);

namespace Cronos\Fraud\Interfaces\GraphQL;

use Cronos\Fraud\Application\FraudRuleManager;
use Cronos\Fraud\Domain\Ports\FraudRepository;

final class AdminFraudMutations
{
    public function __construct(private readonly FraudRuleManager $rules, private readonly FraudRepository $repository) {}

    /** @param array<string, mixed> $args */
    public function create(mixed $root, array $args): array
    {
        return AdminFraudQuery::rule($this->repository->findRule($this->rules->create($this->input($args['input']))));
    }

    /** @param array<string, mixed> $args */
    public function update(mixed $root, array $args): array
    {
        $this->rules->update($args['id'], $this->input($args['input']));

        return AdminFraudQuery::rule($this->repository->findRule($args['id']));
    }

    /** @param array<string, mixed> $args */
    public function delete(mixed $root, array $args): bool
    {
        $this->rules->delete($args['id']);

        return true;
    }

    /** @param array<string, mixed> $in @return array<string, mixed> */
    private function input(array $in): array
    {
        return ['name' => $in['name'], 'type' => $in['type'], 'params' => $in['params'] ?? [], 'score' => $in['score'], 'is_active' => $in['isActive'] ?? true];
    }
}
