<?php

namespace Tests\Concerns;

use Cronos\Identity\Infrastructure\Persistence\Eloquent\UserModel;
use Cronos\Identity\Infrastructure\Persistence\Eloquent\UserProfileModel;
use Cronos\Shared\Interfaces\GraphQL\UseGraphQLSchema;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;

/** GraphQL calls and users with roles, for feature tests whose base class is a domain fixture rather than GraphQLTestCase. */
trait TalksGraphQL
{
    protected function gql(string $query, array $variables = [], ?string $token = null, string $uri = '/graphql'): TestResponse
    {
        $headers = ['User-Agent' => 'PHPUnit', 'Accept' => 'application/json'];
        $token === null || $headers['Authorization'] = 'Bearer '.$token;
        $this->app['auth']->forgetGuards();
        UseGraphQLSchema::reset(); // the schema served depends on the URI of the first request; every call here picks its own

        return $this->postJson($uri, ['query' => $query, 'variables' => $variables], $headers);
    }

    protected function adminGql(string $query, array $variables = [], ?string $token = null): TestResponse
    {
        return $this->gql($query, $variables, $token, '/admin/graphql');
    }

    protected function makeUser(string $email = 'ana@example.com', string $role = 'customer'): UserModel
    {
        $user = new UserModel(['email' => $email, 'password' => Hash::make('correct-horse-battery'), 'is_active' => true]);
        $user->email_verified_at = now();
        $user->save();
        $user->assignRole($role);
        UserProfileModel::query()->create(['user_id' => $user->id]);

        return $user;
    }

    protected function adminToken(string $email = 'boss@example.com', string $role = 'admin'): string
    {
        $this->makeUser($email, $role);

        $r = $this->adminGql('mutation($i: AdminLoginInput!) { adminLogin(input: $i) { token } }', ['i' => ['email' => $email, 'password' => 'correct-horse-battery']]);

        return $r->json('data.adminLogin.token');
    }

    protected function customerToken(string $email): string
    {
        return $this->gql('mutation($i: LoginInput!) { login(input: $i) { token } }', ['i' => ['email' => $email, 'password' => 'correct-horse-battery']])->json('data.login.token');
    }
}
