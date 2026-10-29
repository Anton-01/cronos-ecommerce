<?php

declare(strict_types=1);

namespace Cronos\Fraud\Application;

use Cronos\Fraud\Domain\Exceptions\InvalidFraudRule;
use Cronos\Fraud\Domain\Ports\FraudRepository;
use Cronos\Fraud\Domain\ValueObjects\FraudRule;

final class FraudRuleManager
{
    public function __construct(private readonly FraudRepository $repository) {}

    /** @param array<string, mixed> $input name, type, params, score, is_active */
    public function create(array $input): string
    {
        return $this->repository->createRule($this->validated($input));
    }

    /** @param array<string, mixed> $input */
    public function update(string $id, array $input): void
    {
        $current = $this->repository->findRule($id) ?? throw new InvalidFraudRule('The rule was not found.');

        $this->repository->updateRule($id, $this->validated($input + $current));
    }

    public function delete(string $id): void
    {
        $this->repository->findRule($id) ?? throw new InvalidFraudRule('The rule was not found.');

        $this->repository->deleteRule($id);
    }

    /** @param array<string, mixed> $in @return array<string, mixed> */
    private function validated(array $in): array
    {
        $name = trim((string) $in['name']);
        ($name !== '' && mb_strlen($name) <= 120) || throw new InvalidFraudRule('A rule needs a name (up to 120 characters).');
        $score = (int) $in['score'];
        ($score >= 1 && $score <= 100) || throw new InvalidFraudRule('The score is between 1 and 100.');

        return [
            'name' => $name, 'type' => (string) $in['type'], 'params' => FraudRule::validateParams((string) $in['type'], (array) ($in['params'] ?? [])),
            'score' => $score, 'is_active' => (bool) ($in['is_active'] ?? true),
        ];
    }
}
