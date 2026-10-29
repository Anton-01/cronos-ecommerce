<?php

namespace Database\Seeders;

use Cronos\Payments\Application\ProviderCatalog;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/** The three gateways, disabled and in sandbox until someone configures them. Idempotent. */
class PaymentProvidersSeeder extends Seeder
{
    public function run(): void
    {
        foreach (ProviderCatalog::NAMES as $code => $name) {
            DB::table('payment_providers')->insertOrIgnore(['code' => $code, 'name' => $name, 'is_enabled' => false, 'mode' => 'sandbox', 'markets' => '[]', 'created_at' => now(), 'updated_at' => now()]);
        }
    }
}
