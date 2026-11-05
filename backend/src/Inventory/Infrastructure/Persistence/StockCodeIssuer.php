<?php

declare(strict_types=1);

namespace Cronos\Inventory\Infrastructure\Persistence;

use Cronos\Inventory\Domain\ValueObjects\StockCode;
use Illuminate\Support\Facades\DB;

/** Gives every stockable product the permanent code its QR carries. Codes already issued are never touched. */
final class StockCodeIssuer
{
    /** @return int products that received a code */
    public function issueMissing(): int
    {
        $issued = 0;
        DB::table('products')->whereNull('stock_code')->whereNull('deleted_at')->whereIn('type', ['simple', 'variation'])->orderBy('id')->select('id')->chunkById(500, function ($rows) use (&$issued): void {
            foreach ($rows as $row) {
                DB::table('products')->where('id', $row->id)->whereNull('stock_code')->update(['stock_code' => $this->fresh()]);
                $issued++;
            }
        }, 'id');

        return $issued;
    }

    /** A code never issued before (the table keeps every one, even of removed products). */
    public function fresh(): string
    {
        do {
            $code = StockCode::generate()->value;
        } while (DB::table('stock_codes_issued')->where('code', $code)->exists());

        return $code;
    }
}
