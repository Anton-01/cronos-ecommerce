<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_addresses', function (Blueprint $table) {
            // Billing addresses carry the tax id and the name an invoice is issued to (RFC in Mexico), like the checkout's billing address.
            $table->string('tax_id', 20)->nullable()->after('references');
            $table->string('business_name', 160)->nullable()->after('tax_id');
        });
        // One default per person and kind: enforced by the database, so two tabs cannot both win.
        DB::statement('CREATE UNIQUE INDEX user_addresses_one_default ON user_addresses (user_id, type) WHERE is_default AND deleted_at IS NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS user_addresses_one_default');
        Schema::table('user_addresses', fn (Blueprint $table) => $table->dropColumn(['tax_id', 'business_name']));
    }
};
