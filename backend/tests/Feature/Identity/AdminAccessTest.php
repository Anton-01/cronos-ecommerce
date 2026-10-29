<?php

namespace Tests\Feature\Identity;

use Tests\GraphQLTestCase;

class AdminAccessTest extends GraphQLTestCase
{
    private const ADMIN_LOGIN = 'mutation($i: AdminLoginInput!) { adminLogin(input: $i) { token } }';

    private function adminToken(string $email, string $role): string
    {
        $this->makeUser($email, $role);

        return $this->adminGql(self::ADMIN_LOGIN, ['i' => ['email' => $email, 'password' => self::PASSWORD]])->json('data.adminLogin.token');
    }

    public function test_customers_cannot_obtain_an_admin_token(): void
    {
        $this->makeUser('ana@example.com', 'customer');

        $response = $this->adminGql(self::ADMIN_LOGIN, ['i' => ['email' => 'ana@example.com', 'password' => self::PASSWORD]]);

        $this->assertSame('INVALID_CREDENTIALS', $this->errorCode($response));
        $this->assertDatabaseHas('security_events', ['event' => 'login.admin_denied']);
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
}
