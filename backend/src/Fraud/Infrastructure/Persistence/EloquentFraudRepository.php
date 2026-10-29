<?php

declare(strict_types=1);

namespace Cronos\Fraud\Infrastructure\Persistence;

use Cronos\Fraud\Domain\Ports\FraudRepository;
use Cronos\Fraud\Domain\ValueObjects\FraudContext;
use Cronos\Fraud\Domain\ValueObjects\FraudDecision;
use Cronos\Fraud\Infrastructure\Persistence\Eloquent\FraudRuleModel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class EloquentFraudRepository implements FraudRepository
{
    public function rules(): array
    {
        return FraudRuleModel::query()->orderByDesc('score')->orderBy('name')->get()->map(fn ($m) => $this->rule($m))->all();
    }

    public function findRule(string $id): ?array
    {
        $m = FraudRuleModel::query()->find($id);

        return $m ? $this->rule($m) : null;
    }

    public function createRule(array $data): string
    {
        return FraudRuleModel::query()->create($data)->id;
    }

    public function updateRule(string $id, array $data): void
    {
        FraudRuleModel::query()->findOrFail($id)->update($data);
    }

    public function deleteRule(string $id): void
    {
        FraudRuleModel::query()->findOrFail($id)->delete();
    }

    public function recordCheck(?string $orderId, FraudContext $ctx, FraudDecision $decision): string
    {
        $id = strtolower((string) Str::ulid());
        DB::table('fraud_checks')->insert([
            'id' => $id, 'order_id' => $orderId, 'email' => $ctx->email === null ? null : strtolower($ctx->email), 'ip' => $ctx->ip,
            'score' => $decision->score, 'decision' => $decision->decision, 'reasons' => json_encode($decision->reasons, JSON_THROW_ON_ERROR),
        ]);

        return $id;
    }

    public function checks(?string $orderId, ?string $decision, int $limit = 50): array
    {
        return DB::table('fraud_checks')
            ->when($orderId, fn ($q) => $q->where('order_id', $orderId))->when($decision, fn ($q) => $q->where('decision', $decision))
            ->orderByDesc('id')->limit(min($limit, 200))->get()
            ->map(fn ($r) => ['id' => $r->id, 'order_id' => $r->order_id, 'email' => $r->email, 'ip' => $r->ip, 'score' => (int) $r->score, 'decision' => $r->decision, 'reasons' => json_decode($r->reasons, true), 'created_at' => Carbon::parse($r->created_at)->utc()->format('Y-m-d H:i:s')])->all();
    }

    /** @return array<string, mixed> */
    private function rule(FraudRuleModel $m): array
    {
        return ['id' => $m->id, 'name' => $m->name, 'type' => $m->type, 'params' => $m->params ?? [], 'score' => $m->score, 'is_active' => $m->is_active];
    }
}
