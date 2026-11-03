<?php

declare(strict_types=1);

namespace Cronos\Newsletter\Infrastructure\Persistence;

use Cronos\Newsletter\Domain\Ports\CampaignRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class DatabaseCampaignRepository implements CampaignRepository
{
    public function all(?string $status): array
    {
        $rows = DB::table('newsletter_campaigns')->when($status, fn ($q) => $q->where('status', $status))->orderByDesc('created_at')->orderByDesc('id')->get();

        return $rows->map(fn ($r) => $this->shape($r))->all();
    }

    public function find(string $id): ?array
    {
        $r = DB::table('newsletter_campaigns')->where('id', $id)->first();

        return $r === null ? null : $this->shape($r);
    }

    public function create(array $row): string
    {
        $id = (string) Str::ulid();
        DB::table('newsletter_campaigns')->insert($row + ['id' => $id, 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    public function update(string $id, array $changes): void
    {
        DB::table('newsletter_campaigns')->where('id', $id)->update($changes + ['updated_at' => now()]);
    }

    public function delete(string $id): void
    {
        DB::table('newsletter_campaigns')->where('id', $id)->delete();
    }

    public function dueIds(): array
    {
        return DB::table('newsletter_campaigns')->where('status', 'scheduled')->where('scheduled_at', '<=', now())->orderBy('scheduled_at')->pluck('id')->all();
    }

    public function snapshotRecipients(string $campaignId, array $audience, int $batchSize): int
    {
        DB::transaction(function () use ($campaignId, $audience, $batchSize): void {
            $already = DB::table('newsletter_deliveries')->where('campaign_id', $campaignId)->count();
            if ($already > 0) {
                return; // the snapshot exists: launching twice must not add or reorder anybody
            }
            $q = DB::table('newsletter_subscribers')->where('status', 'confirmed');
            $audience['markets'] === [] || $q->whereIn('market_code', $audience['markets']);
            $audience['locales'] === [] || $q->whereIn('locale', $audience['locales']);
            $n = 0;
            foreach ($q->orderBy('id')->get(['id', 'email', 'locale'])->chunk(1000) as $chunk) {
                $rows = [];
                foreach ($chunk as $s) {
                    $rows[] = ['campaign_id' => $campaignId, 'subscriber_id' => $s->id, 'email' => $s->email, 'locale' => $s->locale, 'batch' => intdiv($n++, $batchSize) + 1, 'status' => 'queued', 'created_at' => now(), 'updated_at' => now()];
                }
                DB::table('newsletter_deliveries')->insertOrIgnore($rows);
            }
        });

        return DB::table('newsletter_deliveries')->where('campaign_id', $campaignId)->count();
    }

    public function pendingBatches(string $campaignId): array
    {
        return DB::table('newsletter_deliveries')->where('campaign_id', $campaignId)->where('status', 'queued')->distinct()->orderBy('batch')->pluck('batch')->map(fn ($b) => (int) $b)->all();
    }

    public function queuedInBatch(string $campaignId, int $batch): array
    {
        return DB::table('newsletter_deliveries as d')->join('newsletter_subscribers as s', 's.id', '=', 'd.subscriber_id')
            ->where('d.campaign_id', $campaignId)->where('d.batch', $batch)->where('d.status', 'queued')
            // A person who left (or bounced) between the snapshot and now is not written to: their row simply stays unsent.
            ->where('s.status', 'confirmed')->orderBy('d.id')->get(['d.id', 'd.subscriber_id', 'd.email', 'd.locale', 's.market_code'])->map(fn ($r) => (array) $r)->all();
    }

    public function markSent(array $deliveryIds, array $providerIds): void
    {
        foreach ($deliveryIds as $i => $id) {
            DB::table('newsletter_deliveries')->where('id', $id)->where('status', 'queued')->update(['status' => 'sent', 'provider_id' => $providerIds[$i] ?? null, 'sent_at' => now(), 'updated_at' => now()]);
        }
    }

    public function markFailed(array $deliveryIds, string $error): void
    {
        DB::table('newsletter_deliveries')->whereIn('id', $deliveryIds)->where('status', 'queued')->update(['status' => 'failed', 'error' => $error, 'updated_at' => now()]);
    }

    public function setStatusByProviderId(string $providerId, string $status): ?array
    {
        $row = DB::table('newsletter_deliveries')->where('provider_id', $providerId)->first();
        if ($row === null) {
            return null;
        }
        // Webhooks can arrive out of order: "delivered" must never overwrite "bounced" / "complained".
        $final = in_array($row->status, ['bounced', 'complained'], true) && $status === 'delivered';
        $final || DB::table('newsletter_deliveries')->where('id', $row->id)->update(['status' => $status, 'updated_at' => now()]);

        return (array) $row;
    }

    public function counts(string $campaignId): array
    {
        $by = DB::table('newsletter_deliveries')->where('campaign_id', $campaignId)->selectRaw('status, count(*) n')->groupBy('status')->pluck('n', 'status');
        $sent = (int) ($by['sent'] ?? 0) + (int) ($by['delivered'] ?? 0) + (int) ($by['bounced'] ?? 0) + (int) ($by['complained'] ?? 0);

        return ['queued' => (int) ($by['queued'] ?? 0), 'sent' => $sent, 'delivered' => (int) ($by['delivered'] ?? 0), 'bounced' => (int) ($by['bounced'] ?? 0), 'complained' => (int) ($by['complained'] ?? 0), 'failed' => (int) ($by['failed'] ?? 0)];
    }

    /** @return array<string, mixed> */
    private function shape(object $r): array
    {
        $a = (array) $r;
        $a['audience'] = json_decode((string) $r->audience, true) ?: ['markets' => [], 'locales' => []];
        $a['translations'] = json_decode((string) $r->translations, true) ?: [];
        $a['counts'] = $this->counts($r->id);

        return $a;
    }
}
