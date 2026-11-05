<?php

declare(strict_types=1);

use Cronos\Inventory\Domain\ValueObjects\StockCode;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        // Every stockable product gets its permanent code; every warehouse gets its receiving area. Opening balances are placed by
        // `warehouse:assign-opening-stock` (an operator decision), not here.
        $taken = array_flip(DB::table('stock_codes_issued')->pluck('code')->all());
        DB::table('products')->whereNull('stock_code')->whereIn('type', ['simple', 'variation'])->orderBy('id')->select('id')->chunkById(500, function ($rows) use (&$taken): void {
            foreach ($rows as $row) {
                do {
                    $code = StockCode::generate()->value;
                } while (isset($taken[$code]));
                $taken[$code] = true;
                DB::table('products')->where('id', $row->id)->update(['stock_code' => $code]);
            }
        }, 'id');

        foreach (DB::table('warehouses')->pluck('id') as $warehouseId) {
            if (DB::table('warehouse_locations')->where(['warehouse_id' => $warehouseId, 'code' => 'RECEPCION'])->exists()) {
                continue;
            }
            $id = strtoupper((string) Str::ulid());
            DB::table('warehouse_locations')->insert([
                'id' => $id, 'warehouse_id' => $warehouseId, 'code' => 'RECEPCION', 'name' => 'Recepción', 'type' => 'receiving', 'depth' => 1, 'path' => $id,
                'is_pickable' => false, 'is_system' => true, 'version' => 1, 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('warehouse_location_versions')->insert(['location_id' => $id, 'version' => 1, 'snapshot' => json_encode(['code' => 'RECEPCION', 'name' => 'Recepción', 'type' => 'receiving', 'is_system' => true]), 'changed_at' => now()]);
        }
    }

    public function down(): void
    {
        // Codes are permanent and locations are history: nothing to undo.
    }
};
