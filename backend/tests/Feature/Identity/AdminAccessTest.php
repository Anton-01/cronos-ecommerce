<?php

namespace Tests\Feature\Identity;

use Illuminate\Support\Facades\DB;
use Tests\GraphQLTestCase;

class AdminAccessTest extends GraphQLTestCase
{
    private const ADMIN_LOGIN = 'mutation($i: AdminLoginInput!) { adminLogin(input: $i) { token } }';

    public function test_customers_cannot_obtain_an_admin_token(): void
    {
        $this->makeUser('ana@example.com', 'customer');

        $response = $this->adminGql(self::ADMIN_LOGIN, ['i' => ['email' => 'ana@example.com', 'password' => self::PASSWORD]]);

        $this->assertSame('INVALID_CREDENTIALS', $this->errorCode($response));
        $this->assertDatabaseHas('security_events', ['event' => 'login.admin_denied']);
    }

    public function test_a_storefront_token_cannot_open_the_admin_session(): void
    {
        $this->makeUser('ana@example.com');
        $token = $this->gql('mutation { login(input: {email: "ana@example.com", password: "'.self::PASSWORD.'"}) { token } }')->json('data.login.token');

        $this->adminGql('{ adminMe { id } }', [], $token)->assertJsonPath('data.adminMe', null)->assertJsonPath('errors.0.message', fn ($m) => stripos($m, 'unauthorized') !== false);
        $this->assertNotEmpty($this->adminGql('{ adminMe { id } }', [], $this->adminToken('boss@example.com', 'admin'))->json('data.adminMe.id'));
    }

    public function test_the_two_schemas_are_separated(): void
    {
        $this->gql('mutation { adminLogin(input: {email: "a@b.co", password: "x"}) { token } }')
            ->assertJsonPath('errors.0.message', fn ($m) => str_contains($m, 'adminLogin'));
        $this->adminGql('mutation { login(input: {email: "a@b.co", password: "x"}) { token } }')
            ->assertJsonPath('errors.0.message', fn ($m) => str_contains($m, 'login'));
        // Interleave once more to prove the active schema is switched back correctly.
        $this->gql('{ me { id } }')->assertJsonPath('errors.0.message', 'Unauthenticated.');
    }

    public function test_user_listing_requires_the_users_view_permission(): void
    {
        $this->makeUser('ana@example.com');
        $query = '{ users(first: 10, search: "ana") { edges { node { email roles } } pageInfo { hasNextPage endCursor } } }';

        $this->adminGql($query)->assertJsonPath('errors.0.message', 'Unauthenticated.');

        $storefrontToken = $this->gql('mutation { login(input: {email: "ana@example.com", password: "'.self::PASSWORD.'"}) { token } }')->json('data.login.token');
        $this->adminGql($query, [], $storefrontToken)->assertJsonPath('errors.0.message', fn ($m) => str_contains($m, 'Unauthorized') || str_contains($m, 'This action is unauthorized'));

        $token = $this->adminToken('boss@example.com', 'admin');
        $this->adminGql($query, [], $token)
            ->assertJsonPath('data.users.edges.0.node.email', 'ana@example.com')
            ->assertJsonPath('data.users.pageInfo.hasNextPage', false);
    }

    public function test_support_role_is_read_only(): void
    {
        $customer = $this->makeUser('ana@example.com');
        $token = $this->adminToken('help@example.com', 'support');

        $this->adminGql('{ users(first: 5) { edges { node { id } } } }', [], $token)->assertJsonMissingPath('errors');
        $this->adminGql('mutation($id: ID!) { setUserActive(id: $id, active: false) { id } }', ['id' => $customer->id], $token)
            ->assertJsonPath('errors.0.message', fn ($m) => str_contains($m, 'unauthorized') || str_contains($m, 'Unauthorized'));
        $this->assertTrue($customer->fresh()->is_active);
    }

    public function test_admin_can_disable_a_user_which_revokes_their_sessions_and_is_audited(): void
    {
        $customer = $this->makeUser('ana@example.com');
        $customer->createToken('session');
        $token = $this->adminToken('boss@example.com', 'admin');

        $this->adminGql('mutation($id: ID!) { setUserActive(id: $id, active: false) { isActive } }', ['id' => $customer->id], $token)
            ->assertJsonPath('data.setUserActive.isActive', false);

        $this->assertSame(0, $customer->tokens()->count());
        $this->assertDatabaseHas('security_events', ['event' => 'user.disabled', 'user_id' => $customer->id]);
    }

    public function test_assigning_roles_validates_that_roles_exist(): void
    {
        $customer = $this->makeUser('ana@example.com');
        $token = $this->adminToken('boss@example.com', 'admin');
        $mutation = 'mutation($id: ID!, $r: [String!]!) { assignUserRoles(id: $id, roles: $r) { roles } }';

        $this->adminGql($mutation, ['id' => $customer->id, 'r' => ['nope']], $token)->assertJsonPath('errors.0.message', 'One or more roles do not exist.');
        $this->adminGql($mutation, ['id' => $customer->id, 'r' => ['support']], $token)->assertJsonPath('data.assignUserRoles.roles', ['support']);
    }

    public function test_pagination_is_stable_when_sort_values_tie(): void
    {
        // Every account shares the same created_at: without a tie-breaker pages overlap or skip rows.
        $this->travelTo(now()->startOfSecond());
        foreach (range(1, 12) as $i) {
            $this->makeUser(sprintf('same%02d@example.com', $i));
        }
        $token = $this->adminToken('boss@example.com', 'admin');
        $query = 'query($after: String, $col: QueryUsersOrderByColumn!) { users(first: 5, after: $after, search: "same", orderBy: [{column: $col, order: DESC}]) { pageInfo { hasNextPage endCursor total } edges { node { email } } } }';

        DB::enableQueryLog();

        foreach (['CREATED_AT', 'EMAIL'] as $column) {
            $seen = [];
            $after = null;
            do {
                $page = $this->adminGql($query, ['after' => $after, 'col' => $column], $token)->json('data.users');
                array_push($seen, ...array_column(array_column($page['edges'], 'node'), 'email'));
                $after = $page['pageInfo']['hasNextPage'] ? $page['pageInfo']['endCursor'] : null;
            } while ($after !== null);

            $this->assertCount(12, $seen, "{$column}: every row exactly once");
            $this->assertCount(12, array_unique($seen), "{$column}: no duplicates across pages");
        }

        // Ties are only guaranteed to be stable if the primary key is the final sort criterion.
        $selects = array_filter(array_column(DB::getQueryLog(), 'query'), fn ($q) => str_contains($q, 'from "users"') && str_contains($q, 'ilike') && str_contains($q, 'limit'));
        $this->assertNotEmpty($selects);
        foreach ($selects as $sql) {
            $this->assertMatchesRegularExpression('/order by .*"users"\."id" desc/', $sql);
        }
    }

    public function test_the_admin_ui_listing_query_fits_inside_the_complexity_limit_at_max_page_size(): void
    {
        $this->makeUser('ana@example.com');
        $token = $this->adminToken('boss@example.com', 'admin');
        // Mirrors the Users query of the admin SPA (UserRow fragment) at the largest allowed page.
        $query = 'query { users(first: 100, orderBy: [{column: CREATED_AT, order: DESC}]) { pageInfo { hasNextPage endCursor total }
            edges { cursor node { id email isActive emailVerifiedAt roles createdAt profile { firstName lastName phone locale countryCode } } } } }';

        $this->adminGql($query, [], $token)->assertJsonMissingPath('errors')->assertJsonPath('data.users.pageInfo.total', 2);
    }

    public function test_runaway_queries_are_rejected(): void
    {
        $token = $this->adminToken('boss@example.com', 'admin');
        $fields = str_repeat('roles permissions email id isActive createdAt emailVerifiedAt ', 12);

        $this->adminGql("query { users(first: 100) { edges { node { {$fields} profile { firstName lastName phone locale countryCode marketingOptIn } } } } }", [], $token)
            ->assertJsonPath('errors.0.message', fn ($m) => str_contains($m, 'complexity'));
    }
}
