<?php

namespace Tests;

use Cronos\Identity\Infrastructure\Persistence\Eloquent\UserModel;
use Cronos\Identity\Infrastructure\Persistence\Eloquent\UserProfileModel;
use Cronos\Shared\Interfaces\GraphQL\UseGraphQLSchema;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;

abstract class GraphQLTestCase extends TestCase
{
    use RefreshDatabase;

    public const PASSWORD = 'correct-horse-battery';

    protected function setUp(): void
    {
        parent::setUp();
        UseGraphQLSchema::reset();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    /** @param array<string, mixed> $variables */
    protected function gql(string $query, array $variables = [], ?string $token = null, string $uri = '/graphql'): TestResponse
    {
        $headers = ['User-Agent' => 'PHPUnit', 'Accept' => 'application/json'];
        if ($token !== null) {
            $headers['Authorization'] = 'Bearer '.$token;
        }

        // Sanctum caches the resolved user on the guard; start every request clean.
        $this->app['auth']->forgetGuards();

        return $this->postJson($uri, ['query' => $query, 'variables' => $variables], $headers);
    }

    protected function adminGql(string $query, array $variables = [], ?string $token = null): TestResponse
    {
        return $this->gql($query, $variables, $token, '/admin/graphql');
    }

    protected function makeUser(string $email = 'ana@example.com', string $role = 'customer', bool $active = true): UserModel
    {
        $user = new UserModel(['email' => $email, 'password' => Hash::make(self::PASSWORD), 'is_active' => $active]);
        $user->email_verified_at = now();
        $user->save();
        $user->assignRole($role);
        UserProfileModel::query()->create(['user_id' => $user->id]);

        return $user;
    }

    protected function errorCode(TestResponse $response): ?string
    {
        return $response->json('errors.0.extensions.code');
    }
}
