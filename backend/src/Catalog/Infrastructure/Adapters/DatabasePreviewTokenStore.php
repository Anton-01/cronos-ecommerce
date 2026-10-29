<?php

declare(strict_types=1);

namespace Cronos\Catalog\Infrastructure\Adapters;

use Cronos\Catalog\Domain\Ports\PreviewTokenStore;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class DatabasePreviewTokenStore implements PreviewTokenStore
{
    public function issue(string $productId, int $ttlMinutes, ?string $createdBy): string
    {
        $plain = Str::random(48);

        DB::table('product_preview_tokens')->insert([
            'product_id' => $productId,
            'token_hash' => hash('sha256', $plain),
            'expires_at' => now()->addMinutes($ttlMinutes),
            'created_by' => $createdBy,
        ]);
        // Housekeeping: expired links are useless, drop them as we go.
        DB::table('product_preview_tokens')->where('expires_at', '<', now()->subDay())->delete();

        return $plain;
    }

    public function resolve(string $plainToken): ?string
    {
        $row = DB::table('product_preview_tokens')->where('token_hash', hash('sha256', $plainToken))->where('expires_at', '>', now())->first();

        return $row?->product_id;
    }
}
