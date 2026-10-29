<?php

namespace Tests\Feature\Audit;

use Cronos\Audit\Infrastructure\Persistence\AuditPartitioner;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class AuditPartitionsTest extends TestCase
{
    use RefreshDatabase;

    private function insert(string $at): void
    {
        DB::table('audits')->insert(['event' => 'created', 'auditable_type' => 'x', 'auditable_id' => str_repeat('a', 26), 'created_at' => $at]);
    }

    private function partitionOf(string $at): string
    {
        return DB::selectOne('SELECT tableoid::regclass::text AS t FROM audits WHERE created_at = ?::timestamptz', [$at])->t;
    }

    public function test_the_migration_pre_creates_a_year_of_partitions_and_rows_are_routed_to_them(): void
    {
        $month = (new \DateTimeImmutable('today UTC'))->format('Y_m');
        $this->assertNotNull(DB::selectOne('SELECT to_regclass(?) AS t', ["audits_p{$month}"])->t);

        $now = now()->utc()->format('Y-m-d H:i:s').'+00';
        $this->insert($now);
        $this->assertSame("audits_p{$month}", $this->partitionOf($now));
    }

    public function test_the_command_is_idempotent_and_extends_the_window(): void
    {
        $this->artisan('audit:partitions', ['--months' => 24])->assertSuccessful();
        $first = DB::scalar("SELECT count(*) FROM pg_inherits WHERE inhparent = 'audits'::regclass");
        $this->assertSame(25, (int) $first, '24 monthly partitions + the default');

        $this->artisan('audit:partitions', ['--months' => 24])->assertSuccessful();
        $this->assertSame($first, DB::scalar("SELECT count(*) FROM pg_inherits WHERE inhparent = 'audits'::regclass"));
    }

    public function test_a_month_that_already_has_rows_in_the_default_partition_is_skipped_not_broken(): void
    {
        $this->insert('2020-03-15 10:00:00+00');
        $this->assertSame('audits_default', $this->partitionOf('2020-03-15 10:00:00+00'));

        $report = (new AuditPartitioner)->ensure(new \DateTimeImmutable('2020-03-01'), 2);

        $this->assertSame(['audits_p2020_03'], $report['skipped']);
        $this->assertSame(['audits_p2020_04'], $report['created']);
    }

    public function test_partitions_keep_the_trail_append_only(): void
    {
        $now = now()->utc()->format('Y-m-d H:i:s').'+00';
        $this->insert($now);

        $this->expectException(QueryException::class);
        DB::table('audits')->where('created_at', $now)->update(['event' => 'tampered']);
    }
}
