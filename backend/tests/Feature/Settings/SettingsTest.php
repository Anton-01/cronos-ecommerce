<?php

namespace Tests\Feature\Settings;

use Cronos\Settings\Infrastructure\Persistence\Eloquent\SettingModel;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\GraphQLTestCase;

class SettingsTest extends GraphQLTestCase
{
    private const PUBLIC = 'query($l: String) { publicSettings(locale: $l) { group key type value } }';

    private const UPDATE = 'mutation($i: [SettingInput!]!) { updateSettings(input: $i) { group key value translations { locale value } } }';

    private ?string $token = null;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('media');
        Cache::flush();
    }

    private function admin(): string
    {
        return $this->token ??= $this->adminToken();
    }

    private function update(array $input, ?string $token = null): TestResponse
    {
        return $this->adminGql(self::UPDATE, ['i' => $input], $token ?? $this->admin());
    }

    /** @return array<string, mixed> "group.key" => value */
    private function publicMap(?string $locale = null): array
    {
        $rows = $this->gql(self::PUBLIC, ['l' => $locale])->assertJsonMissingPath('errors')->json('data.publicSettings');

        return collect($rows)->mapWithKeys(fn ($r) => ["{$r['group']}.{$r['key']}" => $r['value']])->all();
    }

    private function storeName(string $es, ?string $en = null): array
    {
        return ['group' => 'identity', 'key' => 'store_name', 'translations' => array_values(array_filter([
            ['locale' => 'es-MX', 'value' => $es], $en ? ['locale' => 'en-US', 'value' => $en] : null,
        ]))];
    }

    public function test_defaults_are_served_to_anonymous_visitors_and_private_keys_never_leak(): void
    {
        $public = $this->publicMap();

        $this->assertArrayHasKey('identity.store_name', $public);
        $this->assertNull($public['identity.store_name']);
        $this->assertArrayNotHasKey('notifications.order_alert_email', $public);

        $this->update([['group' => 'notifications', 'key' => 'order_alert_email', 'value' => 'ops@cronos.test']])->assertJsonMissingPath('errors');

        $this->assertArrayNotHasKey('notifications.order_alert_email', $this->publicMap());
        $this->assertStringNotContainsString('ops@cronos.test', json_encode($this->gql(self::PUBLIC)->json()));
        $this->adminGql('{ adminSettings { group key value } }', [], $this->admin())
            ->assertJsonFragment(['group' => 'notifications', 'key' => 'order_alert_email', 'value' => 'ops@cronos.test']);
    }

    public function test_translatable_values_follow_the_locale_with_default_fallback(): void
    {
        $this->update([$this->storeName('Listones Cronos', 'Cronos Ribbons'), ['group' => 'identity', 'key' => 'tagline', 'translations' => [['locale' => 'es-MX', 'value' => 'Hecho a mano']]]]);

        $this->assertSame('Listones Cronos', $this->publicMap('es-MX')['identity.store_name']);
        $this->assertSame('Cronos Ribbons', $this->publicMap('en-US')['identity.store_name']);
        $this->assertSame('Listones Cronos', $this->publicMap()['identity.store_name'], 'no locale = default');
        $this->assertSame('Listones Cronos', $this->publicMap('fr-FR')['identity.store_name'], 'unsupported locale = default');
        $this->assertSame('Hecho a mano', $this->publicMap('en-US')['identity.tagline'], 'missing translation falls back');
    }

    public function test_reads_come_from_the_cache_and_writes_refresh_it_without_extra_reads(): void
    {
        $this->update([$this->storeName('Tienda uno')]);
        $this->artisan('settings:warm')->assertSuccessful();

        DB::enableQueryLog();
        $this->assertSame('Tienda uno', $this->publicMap()['identity.store_name']);
        $this->assertSame('Tienda uno', $this->publicMap('en-US')['identity.store_name']);
        $touched = array_filter(array_column(DB::getQueryLog(), 'query'), fn ($q) => str_contains($q, '"settings"') || str_contains($q, 'setting_translations'));
        $this->assertSame([], array_values($touched), 'warm reads never hit the settings tables');
        DB::flushQueryLog();

        $this->update([$this->storeName('Tienda dos')]);
        DB::flushQueryLog();
        $this->assertSame('Tienda dos', $this->publicMap()['identity.store_name'], 'write-through: new value is served immediately');
        $this->assertSame([], array_values(array_filter(array_column(DB::getQueryLog(), 'query'), fn ($q) => str_contains($q, 'setting_translations'))));
    }

    public function test_a_write_that_bypasses_the_handler_still_purges_the_cache(): void
    {
        $this->artisan('settings:warm');
        $this->assertNull($this->publicMap()['contact.email']);

        SettingModel::create(['group' => 'contact', 'key' => 'email', 'value' => 'hola@cronos.test']);

        $this->assertSame('hola@cronos.test', $this->publicMap()['contact.email']);
    }

    public function test_a_cold_cache_is_rebuilt_transparently(): void
    {
        $this->update([$this->storeName('Tienda')]);
        Cache::flush();

        $this->assertSame('Tienda', $this->publicMap()['identity.store_name']);
    }

    public function test_values_are_validated_by_type_and_unsafe_urls_are_refused(): void
    {
        $bad = [
            [['group' => 'contact', 'key' => 'email', 'value' => 'not-an-email'], 'INVALID_SETTING'],
            [['group' => 'social', 'key' => 'facebook', 'value' => 'javascript:alert(1)'], 'INVALID_SETTING'],
            [['group' => 'social', 'key' => 'facebook', 'value' => 'data:text/html;base64,PHNjcmlwdD4='], 'INVALID_SETTING'],
            [['group' => 'contact', 'key' => 'phone', 'value' => 'call me maybe'], 'INVALID_SETTING'],
            [['group' => 'identity', 'key' => 'store_name', 'translations' => [['locale' => 'es-MX', 'value' => str_repeat('x', 256)]]], 'INVALID_SETTING'],
            [['group' => 'identity', 'key' => 'nope', 'value' => 'x'], 'UNKNOWN_SETTING'],
            [['group' => 'identity', 'key' => 'store_name', 'translations' => [['locale' => 'fr-FR', 'value' => 'x']]], 'UNKNOWN_LOCALE'],
            [['group' => 'identity', 'key' => 'logo', 'value' => str_repeat('0', 26)], 'INVALID_SETTING'],
        ];

        foreach ($bad as [$change, $code]) {
            $this->assertSame($code, $this->errorCode($this->update([$change])), json_encode($change));
        }

        $this->update([['group' => 'social', 'key' => 'facebook', 'value' => 'https://facebook.com/cronos']])->assertJsonMissingPath('errors');
        $this->assertSame('https://facebook.com/cronos', $this->publicMap()['social.facebook']);
    }

    public function test_a_batch_is_all_or_nothing(): void
    {
        $result = $this->update([
            ['group' => 'contact', 'key' => 'email', 'value' => 'ok@cronos.test'],
            ['group' => 'contact', 'key' => 'phone', 'value' => 'invalid!!'],
        ]);

        $this->assertSame('INVALID_SETTING', $this->errorCode($result));
        $this->assertSame(0, SettingModel::count(), 'the valid half was not persisted either');
        $this->assertNull($this->publicMap()['contact.email']);
    }

    public function test_sending_null_restores_the_default_and_drops_translations(): void
    {
        $this->update([$this->storeName('Tienda', 'Shop')]);
        $this->assertSame(2, DB::table('setting_translations')->count());

        $this->update([['group' => 'identity', 'key' => 'store_name', 'translations' => [['locale' => 'es-MX', 'value' => ''], ['locale' => 'en-US', 'value' => null]]]]);

        $this->assertSame(0, SettingModel::count());
        $this->assertSame(0, DB::table('setting_translations')->count());
        $this->assertNull($this->publicMap()['identity.store_name']);
    }

    public function test_logo_settings_resolve_to_media_urls_and_follow_media_changes(): void
    {
        $token = $this->admin();
        $upload = $this->gqlUpload('mutation($file: Upload!) { uploadMedia(file: $file) { id } }', [], ['variables.file' => UploadedFile::fake()->image('logo.png', 400, 200)], $token);
        $id = $upload->json('data.uploadMedia.id');

        $this->update([['group' => 'identity', 'key' => 'logo', 'value' => $id]])->assertJsonMissingPath('errors');
        $logo = $this->publicMap()['identity.logo'];
        $this->assertSame($id, $logo['id']);
        $this->assertStringEndsWith("{$id}/{$id}.png", $logo['url']);

        // Deleting the file from the library must not leave a dead logo in the cached snapshot.
        $this->adminGql('mutation($id: ID!) { deleteMedia(id: $id) }', ['id' => $id], $token)->assertJsonPath('data.deleteMedia', true);
        $this->assertNull($this->publicMap()['identity.logo']);

        // A PDF is not a valid logo.
        $pdf = $this->gqlUpload('mutation($file: Upload!) { uploadMedia(file: $file) { id } }', [], ['variables.file' => UploadedFile::fake()->createWithContent('a.pdf', "%PDF-1.4\n%%EOF")], $token)->json('data.uploadMedia.id');
        $this->assertSame('INVALID_SETTING', $this->errorCode($this->update([['group' => 'identity', 'key' => 'logo', 'value' => $pdf]])));
    }

    public function test_permissions_and_auditing(): void
    {
        $this->assertSame('Unauthenticated.', $this->adminGql(self::UPDATE, ['i' => []])->json('errors.0.message'));

        $support = $this->adminToken('help@example.com', 'support');
        $this->adminGql('{ adminSettings { key } }', [], $support)->assertJsonMissingPath('errors');
        $this->assertStringContainsString('unauthorized', strtolower($this->update([$this->storeName('x')], $support)->json('errors.0.message')));

        $this->update([$this->storeName('Auditada')]);
        $audit = DB::table('audits')->where('auditable_type', 'like', '%SettingModel')->where('event', 'created')->first();
        $this->assertNotNull($audit);
        $this->assertNotNull($audit->user_id);
    }

    public function test_the_public_endpoint_cannot_reach_admin_operations(): void
    {
        $this->gql('{ adminSettings { key } }')->assertJsonPath('errors.0.message', fn ($m) => str_contains($m, 'adminSettings'));
        $this->gql('{ supportedLocales { code isDefault } }')->assertJsonPath('data.supportedLocales.0', ['code' => 'es-MX', 'isDefault' => true]);
    }
}
