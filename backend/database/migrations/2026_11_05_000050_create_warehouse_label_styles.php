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
        // How the printed QR labels look. Every change is a new row: the latest wins and the history says who changed the look and when.
        Schema::create('warehouse_label_style_versions', function (Blueprint $table) {
            $table->id();
            $table->jsonb('style');
            $table->char('changed_by', 26)->nullable();
            $table->timestampTz('changed_at', 6)->useCurrent();
        });
        DB::statement('CREATE TRIGGER warehouse_label_style_versions_append_only BEFORE UPDATE OR DELETE ON warehouse_label_style_versions FOR EACH ROW EXECUTE FUNCTION prevent_mutation()');
    }

    public function down(): void
    {
        Schema::dropIfExists('warehouse_label_style_versions');
    }
};
