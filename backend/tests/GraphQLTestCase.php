<?php

namespace Tests;

use Cronos\Identity\Infrastructure\Persistence\Eloquent\UserModel;
use Cronos\Identity\Infrastructure\Persistence\Eloquent\UserProfileModel;
use Cronos\Shared\Interfaces\GraphQL\UseGraphQLSchema;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
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

    /**
     * GraphQL multipart request spec: files are sent next to the operations and mapped to variables.
     *
     * @param  array<string, mixed>  $variables
     * @param  array<string, UploadedFile>  $files  variable path => file, e.g. 'variables.file'
     */
    protected function gqlUpload(string $query, array $variables, array $files, ?string $token, string $uri = '/admin/graphql'): TestResponse
    {
        $map = [];
        $uploads = [];
        foreach (array_values($files) as $i => $file) {
            $map[(string) $i] = [array_keys($files)[$i]];
            $uploads[(string) $i] = $file;
        }
        foreach (array_keys($files) as $path) {
            data_set($variables, str_replace('variables.', '', $path), null);
        }

        $this->app['auth']->forgetGuards();

        return $this->call('POST', $uri, ['operations' => json_encode(['query' => $query, 'variables' => $variables]), 'map' => json_encode($map)], [], $uploads, array_filter([
            'CONTENT_TYPE' => 'multipart/form-data',
            'HTTP_AUTHORIZATION' => $token ? 'Bearer '.$token : null,
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_USER_AGENT' => 'PHPUnit',
        ]));
    }

    protected function adminToken(string $email = 'boss@example.com', string $role = 'admin'): string
    {
        $this->makeUser($email, $role);

        return $this->adminGql('mutation($i: AdminLoginInput!) { adminLogin(input: $i) { token } }', ['i' => ['email' => $email, 'password' => self::PASSWORD]])->json('data.adminLogin.token');
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
