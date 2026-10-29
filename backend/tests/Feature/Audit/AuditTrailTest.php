<?php

namespace Tests\Feature\Audit;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\GraphQLTestCase;

class AuditTrailTest extends GraphQLTestCase
{
    public function test_model_changes_record_actor_ip_user_agent_and_deltas_without_secrets(): void
    {
        $user = $this->makeUser('ana@example.com');
        $token = $this->gql('mutation { login(input: {email: "ana@example.com", password: "'.self::PASSWORD.'"}) { token } }')->json('data.login.token');

        $this->gql('mutation { updateMyProfile(input: {firstName: "Anita"}) { profile { firstName } } }', [], $token)
            ->assertJsonPath('data.updateMyProfile.profile.firstName', 'Anita');

        $audit = DB::table('audits')->where('auditable_type', 'like', '%UserProfileModel')->where('event', 'updated')->first();
        $this->assertNotNull($audit);
        $this->assertSame($user->id, $audit->user_id);
        $this->assertSame('PHPUnit', $audit->user_agent);
        $this->assertSame('127.0.0.1', $audit->ip_address);
        $this->assertSame(['first_name' => null], json_decode($audit->old_values, true));
        $this->assertSame(['first_name' => 'Anita'], json_decode($audit->new_values, true));

        $created = DB::table('audits')->where('auditable_id', $user->id)->where('event', 'created')->first();
        $this->assertStringNotContainsString('password', (string) $created->new_values);
        $this->assertSame(26, strlen($created->auditable_id));
    }

    public function test_soft_deleting_a_user_is_audited_and_keeps_the_row(): void
    {
        $user = $this->makeUser('ana@example.com');
        $user->delete();

        $this->assertSoftDeleted('users', ['id' => $user->id]);
        $this->assertDatabaseHas('audits', ['auditable_id' => $user->id, 'event' => 'deleted']);
    }

    public function test_the_audit_trail_is_append_only_at_database_level(): void
    {
        $this->makeUser('ana@example.com');
        DB::table('security_events')->insert(['event' => 'seed']);

        foreach (['UPDATE audits SET event = \'x\'', 'DELETE FROM audits', 'TRUNCATE audits', 'UPDATE security_events SET event = \'x\'', 'DELETE FROM security_events'] as $sql) {
            try {
                DB::transaction(fn () => DB::statement($sql));
                $this->fail("Expected append-only violation for: {$sql}");
            } catch (QueryException $e) {
                $this->assertStringContainsString('append-only', $e->getMessage());
            }
        }
    }
}
