<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('email')->nullable(false);
            $table->timestampTz('email_verified_at')->nullable();
            $table->string('password')->nullable(); // null for social-only accounts
            $table->boolean('is_active')->default(true);
            $table->string('google_id')->nullable();
            $table->timestampTz('last_login_at')->nullable();
            $table->ipAddress('last_login_ip')->nullable();
            $table->rememberToken();
            $table->timestampsTz();
            $table->softDeletesTz();
        });
        DB::statement('ALTER TABLE users ALTER COLUMN email TYPE citext');
        DB::statement('CREATE UNIQUE INDEX users_email_unique ON users (email) WHERE deleted_at IS NULL');
        DB::statement('CREATE UNIQUE INDEX users_google_id_unique ON users (google_id) WHERE google_id IS NOT NULL AND deleted_at IS NULL');

        Schema::create('user_profiles', function (Blueprint $table) {
            $table->foreignUlid('user_id')->primary()->constrained('users')->restrictOnDelete();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('phone', 32)->nullable();
            $table->date('birthdate')->nullable();
            // Avatar is stored as a plain path until the Media Library lands in Phase 2.
            $table->string('avatar_path')->nullable();
            $table->string('locale', 10)->default('es-MX');
            $table->char('country_code', 2)->nullable();
            $table->boolean('marketing_opt_in')->default(false);
            $table->jsonb('preferences')->nullable();
            $table->timestampsTz();
        });

        Schema::create('user_addresses', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained('users')->restrictOnDelete();
            $table->string('type', 16); // billing | shipping
            $table->string('label')->nullable();
            $table->string('recipient_name');
            $table->string('phone', 32)->nullable();
            $table->string('street');
            $table->string('exterior_no', 32)->nullable();
            $table->string('interior_no', 32)->nullable();
            $table->string('neighborhood')->nullable();
            $table->string('city');
            $table->string('state');
            $table->char('country_code', 2);
            $table->string('zip', 16);
            $table->text('references')->nullable();
            $table->decimal('lat', 9, 6)->nullable();
            $table->decimal('lng', 9, 6)->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestampsTz();
            $table->softDeletesTz();
            $table->index(['user_id', 'type']);
        });
        DB::statement("ALTER TABLE user_addresses ADD CONSTRAINT user_addresses_type_check CHECK (type IN ('billing','shipping'))");

        Schema::create('social_accounts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained('users')->restrictOnDelete();
            $table->string('provider', 32);
            $table->string('provider_user_id');
            $table->string('provider_email')->nullable();
            $table->text('avatar_url')->nullable();
            $table->timestampsTz();
            $table->unique(['provider', 'provider_user_id']);
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token'); // hashed
            $table->timestampTz('created_at')->nullable();
        });

        // Append-only security trail (complements the model audit trail).
        Schema::create('security_events', function (Blueprint $table) {
            $table->id();
            $table->char('user_id', 26)->nullable()->index();
            $table->string('event', 64)->index();
            $table->string('email')->nullable();
            $table->ipAddress('ip_address')->nullable();
            $table->string('user_agent', 1023)->nullable();
            $table->jsonb('context')->nullable();
            $table->timestampTz('created_at')->useCurrent();
        });
        $this->makeAppendOnly('security_events');

        Schema::create('failed_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->text('connection');
            $table->text('queue');
            $table->longText('payload');
            $table->longText('exception');
            $table->timestampTz('failed_at')->useCurrent();
        });
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS security_events_append_only ON security_events');
        foreach (['failed_jobs', 'security_events', 'password_reset_tokens', 'social_accounts', 'user_addresses', 'user_profiles', 'users'] as $table) {
            Schema::dropIfExists($table);
        }
    }

    private function makeAppendOnly(string $table): void
    {
        DB::statement("CREATE TRIGGER {$table}_append_only BEFORE UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION prevent_mutation()");
        DB::statement("CREATE TRIGGER {$table}_no_truncate BEFORE TRUNCATE ON {$table} FOR EACH STATEMENT EXECUTE FUNCTION prevent_mutation()");
    }
};
