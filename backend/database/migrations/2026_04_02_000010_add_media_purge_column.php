<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media', function (Blueprint $table) {
            // Set when the files of a soft-deleted item have been physically removed from storage.
            $table->timestampTz('purged_at')->nullable();
            $table->index(['deleted_at', 'purged_at']);
        });
    }

    public function down(): void
    {
        Schema::table('media', function (Blueprint $table) {
            $table->dropIndex(['deleted_at', 'purged_at']);
            $table->dropColumn('purged_at');
        });
    }
};
