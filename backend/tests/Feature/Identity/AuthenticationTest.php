<?php

namespace Tests\Feature\Identity;

use Cronos\Identity\Domain\Ports\SocialIdentityProvider;
use Cronos\Identity\Domain\ValueObjects\SocialProfile;
use Cronos\Identity\Infrastructure\Mail\PasswordResetMail;
use Cronos\Identity\Infrastructure\Mail\VerifyEmailMail;
use Cronos\Identity\Infrastructure\Persistence\Eloquent\UserModel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Tests\GraphQLTestCase;

class AuthenticationTest extends GraphQLTestCase
{
    private const REGISTER = 'mutation($i: RegisterInput!) { register(input: $i) { token user { id email roles profile { firstName locale } } } }';

    private const LOGIN = 'mutation($i: LoginInput!) { login(input: $i) { token user { id } } }';

    private function register(array $overrides = []): TestResponse
    {
        return $this->gql(self::REGISTER, ['i' => array_merge([
            'email' => 'Ana@Example.com', 'password' => self::PASSWORD, 'firstName' => 'Ana',
        ], $overrides)]);
    }

    public function test_register_creates_a_ulid_user_with_profile_role_and_queues_verification_email(): void
    {
        Mail::fake();

        $response = $this->register()->assertOk();

        $response->assertJsonPath('data.register.user.email', 'ana@example.com')
            ->assertJsonPath('data.register.user.roles', ['customer'])
            ->assertJsonPath('data.register.user.profile.firstName', 'Ana');
        $this->assertSame(26, strlen($response->json('data.register.user.id')));
        $this->assertNotEmpty($response->json('data.register.token'));
        $this->assertDatabaseHas('security_events', ['event' => 'user.registered', 'ip_address' => '127.0.0.1']);

        Mail::assertSent(VerifyEmailMail::class, fn ($m) => $m->hasTo('ana@example.com'));
    }

    public function test_register_rejects_duplicate_email_and_weak_password(): void
    {
        $this->makeUser('ana@example.com');

        $this->assertSame('EMAIL_TAKEN', $this->errorCode($this->register()));
        $this->register(['email' => 'new@example.com', 'password' => 'short'])
            ->assertJsonPath('errors.0.extensions.validation', fn ($v) => isset($v['input.password']));
    }

    public function test_login_returns_a_working_token_and_rejects_bad_credentials(): void
    {
        $this->makeUser();

        $token = $this->gql(self::LOGIN, ['i' => ['email' => 'ana@example.com', 'password' => self::PASSWORD]])
            ->assertJsonPath('data.login.user.id', fn ($id) => strlen($id) === 26)
            ->json('data.login.token');

        $this->gql('{ me { email permissions } }', [], $token)->assertJsonPath('data.me.email', 'ana@example.com');

        $bad = $this->gql(self::LOGIN, ['i' => ['email' => 'ana@example.com', 'password' => 'wrong-password-123']]);
        $this->assertSame('INVALID_CREDENTIALS', $this->errorCode($bad));
        $unknown = $this->gql(self::LOGIN, ['i' => ['email' => 'ghost@example.com', 'password' => 'wrong-password-123']]);
        $this->assertSame('INVALID_CREDENTIALS', $this->errorCode($unknown));
        $this->assertDatabaseHas('security_events', ['event' => 'login.failed', 'email' => 'ana@example.com']);
    }

    public function test_disabled_accounts_cannot_log_in(): void
    {
        $this->makeUser(active: false);

        $response = $this->gql(self::LOGIN, ['i' => ['email' => 'ana@example.com', 'password' => self::PASSWORD]]);

        $this->assertSame('ACCOUNT_DISABLED', $this->errorCode($response));
    }

    public function test_me_requires_authentication(): void
    {
        $this->gql('{ me { id } }')->assertJsonPath('errors.0.message', 'Unauthenticated.');
    }

    public function test_logout_revokes_only_the_current_token(): void
    {
        $user = $this->makeUser();
        $login = fn () => $this->gql(self::LOGIN, ['i' => ['email' => 'ana@example.com', 'password' => self::PASSWORD]])->json('data.login.token');
        $first = $login();
        $second = $login();

        $this->gql('mutation { logout }', [], $first)->assertJsonPath('data.logout', true);

        $this->assertSame(1, $user->tokens()->count());
        $this->gql('{ me { id } }', [], $first)->assertJsonPath('errors.0.message', 'Unauthenticated.');
        $this->gql('{ me { id } }', [], $second)->assertJsonPath('data.me.id', $user->id);
    }

    public function test_tokens_expire(): void
    {
        $this->makeUser();
        $token = $this->gql(self::LOGIN, ['i' => ['email' => 'ana@example.com', 'password' => self::PASSWORD]])->json('data.login.token');

        $this->travel(61)->minutes();

        $this->gql('{ me { id } }', [], $token)->assertJsonPath('errors.0.message', 'Unauthenticated.');
    }

    public function test_email_verification_link_marks_the_email_verified_and_rejects_tampering(): void
    {
        Mail::fake();
        $this->register();
        Mail::assertSent(VerifyEmailMail::class, function (VerifyEmailMail $mail) {
            parse_str((string) parse_url($mail->url, PHP_URL_QUERY), $q);
            $query = 'mutation($i: VerifyEmailInput!) { verifyEmail(input: $i) }';
            $input = ['userId' => $q['id'], 'expires' => (int) $q['expires'], 'signature' => $q['signature']];

            $this->assertSame('INVALID_TOKEN', $this->errorCode($this->gql($query, ['i' => [...$input, 'signature' => str_repeat('0', 64)]])));
            $this->gql($query, ['i' => $input])->assertJsonPath('data.verifyEmail', true);
            $this->assertNotNull(UserModel::find($q['id'])->email_verified_at);

            return true;
        });
    }

    public function test_password_reset_flow_is_single_use_and_revokes_sessions(): void
    {
        Mail::fake();
        $user = $this->makeUser();
        $user->createToken('old-session');

        $forgot = 'mutation($e: String!) { forgotPassword(email: $e) }';
        $this->gql($forgot, ['e' => 'ghost@example.com'])->assertJsonPath('data.forgotPassword', true);
        Mail::assertNothingSent();

        $this->gql($forgot, ['e' => 'ana@example.com'])->assertJsonPath('data.forgotPassword', true);

        $token = null;
        Mail::assertSent(PasswordResetMail::class, function (PasswordResetMail $mail) use (&$token) {
            parse_str((string) parse_url($mail->url, PHP_URL_QUERY), $q);
            $token = $q['token'];

            return true;
        });
        $this->assertSame(64, strlen($token));
        $this->assertStringNotContainsString($token, (string) DB::table('password_reset_tokens')->value('token'));

        $reset = 'mutation($i: ResetPasswordInput!) { resetPassword(input: $i) }';
        $input = ['email' => 'ana@example.com', 'token' => $token, 'password' => 'a-brand-new-password'];

        $this->assertSame('INVALID_TOKEN', $this->errorCode($this->gql($reset, ['i' => [...$input, 'token' => 'nope']])));
        $this->gql($reset, ['i' => $input])->assertJsonPath('data.resetPassword', true);
        $this->assertSame('INVALID_TOKEN', $this->errorCode($this->gql($reset, ['i' => $input])), 'token must be single-use');

        $this->assertSame(0, $user->tokens()->count());
        $this->gql(self::LOGIN, ['i' => ['email' => 'ana@example.com', 'password' => 'a-brand-new-password']])
            ->assertJsonPath('data.login.user.id', $user->id);
    }

    public function test_login_is_rate_limited(): void
    {
        $attempt = fn () => $this->gql(self::LOGIN, ['i' => ['email' => 'ana@example.com', 'password' => 'wrong-password-123']]);

        foreach (range(1, 5) as $_) {
            $this->assertSame('INVALID_CREDENTIALS', $this->errorCode($attempt()));
        }

        $this->assertStringContainsString('Rate limit', (string) $attempt()->json('errors.0.message'));
    }

    private function fakeGoogle(bool $emailVerified): void
    {
        $profile = new SocialProfile('google', 'g-123', 'Ana@Example.com', $emailVerified, 'Ana', 'Perez', null);

        $this->app->instance(SocialIdentityProvider::class, new class($profile) implements SocialIdentityProvider
        {
            public function __construct(private $profile) {}

            public function profileFromAccessToken(string $accessToken): ?SocialProfile
            {
                return $this->profile;
            }
        });
    }

    private const GOOGLE = 'mutation { loginWithGoogle(accessToken: "tok") { token user { id email emailVerifiedAt } } }';

    public function test_google_login_rejects_an_unverified_provider_email(): void
    {
        $this->makeUser('ana@example.com');
        $this->fakeGoogle(false);

        $this->assertSame('SOCIAL_LOGIN_REJECTED', $this->errorCode($this->gql(self::GOOGLE)));
        $this->assertDatabaseMissing('social_accounts', ['provider_user_id' => 'g-123']);
    }

    public function test_google_login_links_an_existing_account_by_verified_email(): void
    {
        $existing = $this->makeUser('ana@example.com');
        $this->fakeGoogle(true);

        $this->gql(self::GOOGLE)->assertJsonPath('data.loginWithGoogle.user.id', $existing->id);

        $this->assertDatabaseHas('social_accounts', ['user_id' => $existing->id, 'provider_user_id' => 'g-123']);
        $this->assertSame('g-123', $existing->fresh()->google_id);
    }

    public function test_google_login_creates_a_verified_passwordless_account_for_new_users(): void
    {
        $this->fakeGoogle(true);

        $response = $this->gql(self::GOOGLE)->assertJsonPath('data.loginWithGoogle.user.email', 'ana@example.com');

        $user = UserModel::query()->where('email', 'ana@example.com')->firstOrFail();
        $this->assertNull($user->password);
        $this->assertNotNull($user->email_verified_at);
        $this->assertTrue($user->hasRole('customer'));
        $this->assertSame('Ana', $user->profile->first_name);
        $this->assertNotEmpty($response->json('data.loginWithGoogle.token'));
    }
}
