<?php

declare(strict_types=1);

namespace Cronos\Promotions\Application;

use Cronos\Promotions\Domain\Exceptions\InvalidPromotion;
use Cronos\Promotions\Domain\Exceptions\NotFound;
use Cronos\Promotions\Domain\Ports\PromotionRepository;
use Cronos\Promotions\Domain\ValueObjects\PromotionRule;
use Cronos\Promotions\Domain\ValueObjects\Restrictions;

final class PromotionManager
{
    public function __construct(private readonly PromotionRepository $promotions) {}

    /** @param array<string, mixed> $input name, description, priority, is_stackable, is_active, starts_at, ends_at, conditions, action */
    public function create(array $input): string
    {
        return $this->promotions->create($this->validated($input));
    }

    /** @param array<string, mixed> $input */
    public function update(string $id, array $input): void
    {
        $current = $this->promotions->find($id) ?? throw new NotFound('The promotion');

        $this->promotions->update($id, $this->validated($input + $current));
    }

    public function delete(string $id): void
    {
        $this->promotions->find($id) ?? throw new NotFound('The promotion');

        $this->promotions->delete($id);
    }

    /** @param array<string, mixed> $in @return array<string, mixed> */
    private function validated(array $in): array
    {
        $name = trim((string) $in['name']);
        ($name !== '' && mb_strlen($name) <= 120) || throw new InvalidPromotion('A promotion needs a name (up to 120 characters).');
        $starts = $in['starts_at'] ?? null;
        $ends = $in['ends_at'] ?? null;
        ($starts === null || $ends === null || strtotime((string) $starts) < strtotime((string) $ends)) || throw new InvalidPromotion('The promotion must end after it starts.', 'INVALID_WINDOW');
        $conditions = $in['conditions'] ?? [];

        return [
            'name' => $name, 'description' => $in['description'] ?? null, 'priority' => (int) ($in['priority'] ?? 0),
            'is_stackable' => (bool) ($in['is_stackable'] ?? true), 'is_active' => (bool) ($in['is_active'] ?? true), 'starts_at' => $starts, 'ends_at' => $ends,
            'conditions' => ($conditions instanceof Restrictions ? $conditions : Restrictions::fromArray($conditions))->toArray(),
            'actions' => PromotionRule::validateAction($in['action']),
        ];
    }
}
