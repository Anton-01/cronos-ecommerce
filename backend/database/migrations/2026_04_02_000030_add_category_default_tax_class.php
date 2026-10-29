<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            // Products without their own tax class take their (primary) category's, then the store default.
            $table->foreignId('default_tax_class_id')->nullable()->constrained('tax_classes')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('categories', fn (Blueprint $t) => $t->dropConstrainedForeignId('default_tax_class_id'));
    }
};
