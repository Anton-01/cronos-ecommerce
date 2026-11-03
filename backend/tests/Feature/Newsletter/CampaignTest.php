<?php

namespace Tests\Feature\Newsletter;

use Cronos\Markets\Application\MarketProfileManager;
use Cronos\Newsletter\Application\NewsletterTokens;
use Cronos\Newsletter\Infrastructure\Jobs\SendCampaignBatchJob;
use Database\Seeders\PricingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\GraphQLTestCase;

final class CampaignTest extends GraphQLTestCase
{
    use RefreshDatabase;

    private const WEBHOOK_SECRET = 'whsec_'.'c2VjcmV0LWtleS1mb3ItdGVzdHM=';

    private string $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PricingSeeder::class);
        Cache::flush();
        config([
            'newsletter.resend.api_key' => 're_test', 'newsletter.resend.from' => 'Cronos <news@cronos.test>', 'newsletter.resend.reply_to' => 'hola@cronos.test',
            'newsletter.resend.webhook_secret' => self::WEBHOOK_SECRET, 'newsletter.batch_size' => 2, 'app.url' => 'https://api.cronos.test', 'app.storefront_url' => 'https://tienda.cronos.test',
        ]);
        app(MarketProfileManager::class)->update('MX', ['trade_name' => 'Cronos MX', 'fiscal_address' => ['street' => 'Calle 1 #10', 'city' => 'CDMX', 'state' => 'CMX', 'zip' => '01000', 'country_code' => 'MX']]);
        $this->admin = $this->adminToken();
    }

    private function subscriber(string $email, string $status = 'confirmed', string $locale = 'es-MX', string $market = 'MX'): string
    {
        $id = (string) Str::ulid();
        DB::table('newsletter_subscribers')->insert(['id' => $id, 'email' => $email, 'status' => $status, 'locale' => $locale, 'market_code' => $market, 'source' => 'footer', 'consented_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    private function campaign(array $over = []): string
    {
        $r = $this->adminGql('mutation($n: String!, $a: CampaignAudienceInput, $t: [CampaignTranslationInput!]) { createCampaign(name: $n, audience: $a, translations: $t) { id status } }', [
            'n' => 'Navidad', 'a' => $over['audience'] ?? null,
            't' => $over['translations'] ?? [
                ['locale' => 'es-MX', 'subject' => 'Ya llegó la Navidad', 'preheader' => 'Listones nuevos', 'bodyMd' => "## Hola\n\nMira los **listones** nuevos: [ver](https://tienda.cronos.test/es-mx)"],
                ['locale' => 'en-US', 'subject' => 'Christmas is here', 'bodyMd' => 'Look at the **new** ribbons.'],
            ],
        ], $this->admin);
        $r->assertJsonMissingPath('errors');

        return $r->json('data.createCampaign.id');
    }

    private function send(string $id, ?string $token = null, ?string $at = null): TestResponse
    {
        return $this->adminGql('mutation($i: ID!, $at: DateTime) { sendCampaign(id: $i, at: $at) { id status total counts { queued sent failed } } }', ['i' => $id, 'at' => $at], $token ?? $this->admin);
    }

    /** Runs every queued batch job the way a worker would. */
    private function runBatches(): void
    {
        Queue::assertPushed(SendCampaignBatchJob::class);
        foreach (Queue::pushed(SendCampaignBatchJob::class) as $job) {
            app()->call([$job, 'handle']);
        }
    }

    private function resend(?array $ids = null): void
    {
        Http::fake(['api.resend.com/*' => fn (Request $r) => Http::response(['data' => array_map(fn () => ['id' => 'msg_'.Str::random(8)], (array) $r->data())])]);
    }

    private function campaignRow(string $id): array
    {
        return (array) DB::table('newsletter_campaigns')->where('id', $id)->first();
    }

    public function test_the_preview_shows_the_message_as_a_subscriber_gets_it_with_the_unsubscribe_link_and_the_postal_address(): void
    {
        $id = $this->campaign();
        $p = $this->adminGql('query($i: ID!, $l: String) { campaignPreview(id: $i, locale: $l) { subject locale html } }', ['i' => $id, 'l' => 'es-MX'], $this->admin)->assertJsonMissingPath('errors')->json('data.campaignPreview');

        $this->assertSame('Ya llegó la Navidad', $p['subject']);
        $this->assertStringContainsString('<h2>Hola</h2>', $p['html']);
        $this->assertStringContainsString('<strong>listones</strong>', $p['html']);
        $this->assertStringContainsString('/es-mx/newsletter/unsubscribe?token=', $p['html']);
        $this->assertStringContainsString('Listones nuevos', $p['html'], 'preheader');
    }

    public function test_what_the_editor_writes_cannot_run_a_script_in_the_subscribers_mail(): void
    {
        $id = $this->campaign(['translations' => [['locale' => 'es-MX', 'subject' => "Asunto\r\nBcc: x@evil.test", 'bodyMd' => "<script>alert(1)</script>\n\n[x](javascript:alert(1))\n\nTexto"]]]);
        $p = $this->adminGql('query($i: ID!) { campaignPreview(id: $i) { subject html } }', ['i' => $id], $this->admin)->json('data.campaignPreview');

        $this->assertStringNotContainsString('<script', $p['html']);
        $this->assertStringNotContainsString('javascript:', $p['html']);
        $this->assertStringNotContainsString("\n", $p['subject'], 'a line break in the subject would inject headers');
    }

    public function test_only_confirmed_subscribers_of_the_audience_get_it_in_their_own_language_in_batches_with_the_headers_mail_apps_need(): void
    {
        Queue::fake();
        $this->resend();
        foreach (['a@x.com', 'b@x.com'] as $e) {
            $this->subscriber($e);
        }
        $this->subscriber('c@x.com', 'confirmed', 'en-US', 'US');
        $this->subscriber('pending@x.com', 'pending');
        $this->subscriber('gone@x.com', 'unsubscribed');
        $id = $this->campaign();

        $r = $this->send($id)->assertJsonMissingPath('errors');
        $this->assertSame(['sending', 3], [$r->json('data.sendCampaign.status'), $r->json('data.sendCampaign.total')]);
        $this->assertSame(2, collect(Queue::pushed(SendCampaignBatchJob::class))->count(), '3 recipients in batches of 2');
        $this->runBatches();

        $sent = collect(Http::recorded())->map(fn ($pair) => $pair[0]);
        $sent->each(fn (Request $q) => $this->assertSame('Bearer re_test', $q->header('Authorization')[0]));
        $keys = $sent->map(fn (Request $q) => $q->header('Idempotency-Key')[0])->all();
        $this->assertSame(["campaign:{$id}:batch:1", "campaign:{$id}:batch:2"], $keys);
        $messages = $sent->flatMap(fn (Request $q) => $q->data())->keyBy(fn ($m) => $m['to'][0]);
        $this->assertSame(['a@x.com', 'b@x.com', 'c@x.com'], $messages->keys()->sort()->values()->all());
        $this->assertSame('Ya llegó la Navidad', $messages['a@x.com']['subject']);
        $this->assertSame('Christmas is here', $messages['c@x.com']['subject']);
        $this->assertSame(['Cronos <news@cronos.test>', 'hola@cronos.test'], [$messages['a@x.com']['from'], $messages['a@x.com']['reply_to']]);
        $this->assertMatchesRegularExpression('~^<https://api\.cronos\.test/newsletter/unsubscribe\?token=[^>]+>$~', $messages['a@x.com']['headers']['List-Unsubscribe']);
        $this->assertSame('List-Unsubscribe=One-Click', $messages['a@x.com']['headers']['List-Unsubscribe-Post']);
        $this->assertStringContainsString('Calle 1 #10, CDMX', $messages['a@x.com']['html'], 'the store\'s postal address is in the footer');
        $this->assertStringContainsString('Unsubscribe', $messages['c@x.com']['text']);

        $this->assertSame(3, DB::table('newsletter_deliveries')->where('status', 'sent')->whereNotNull('provider_id')->count());
        $this->assertSame('sent', $this->campaignRow($id)['status']);
    }

    public function test_the_audience_can_be_narrowed_to_a_store_and_a_language(): void
    {
        Queue::fake();
        $this->subscriber('mx-es@x.com');
        $this->subscriber('mx-en@x.com', 'confirmed', 'en-US', 'MX');
        $this->subscriber('us-en@x.com', 'confirmed', 'en-US', 'US');
        $id = $this->campaign(['audience' => ['markets' => ['mx'], 'locales' => ['es-MX']]]);

        $this->assertSame(1, $this->send($id)->json('data.sendCampaign.total'));
        $this->assertSame(['mx-es@x.com'], DB::table('newsletter_deliveries')->pluck('email')->all());
    }

    public function test_sending_twice_or_rerunning_a_batch_never_mails_anyone_twice(): void
    {
        Queue::fake();
        $this->resend();
        $this->subscriber('a@x.com');
        $id = $this->campaign();
        $this->send($id);
        $this->runBatches();
        Http::assertSentCount(1);

        $this->runBatches(); // a worker that crashed and re-ran the same job
        $this->send($id)->assertJsonPath('errors.0.extensions.code', 'CAMPAIGN_LOCKED');
        Http::assertSentCount(1);
        $this->assertSame(1, DB::table('newsletter_deliveries')->count());
    }

    public function test_rate_limits_and_provider_errors_are_retried_and_a_refused_batch_does_not_stop_the_rest(): void
    {
        Queue::fake();
        foreach (['a@x.com', 'b@x.com', 'c@x.com', 'd@x.com'] as $e) {
            $this->subscriber($e);
        }
        $id = $this->campaign();
        $this->send($id);
        $jobs = Queue::pushed(SendCampaignBatchJob::class)->values();

        Http::fake(['api.resend.com/*' => Http::sequence()->push(['message' => 'Too many requests'], 429)->push(['message' => 'Invalid `to` field'], 422)->push(['data' => [['id' => 'm1'], ['id' => 'm2']]])]);
        try {
            app()->call([$jobs[0], 'handle']);
            $this->fail('a 429 must be thrown so the queue retries the batch');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('429', $e->getMessage());
        }
        $this->assertSame(4, DB::table('newsletter_deliveries')->where('status', 'queued')->count(), 'nothing was lost');

        app()->call([$jobs[0], 'handle']); // batch 1 refused for good
        app()->call([$jobs[1], 'handle']); // batch 2 goes out
        $this->assertEquals(['failed' => 2, 'sent' => 2], DB::table('newsletter_deliveries')->selectRaw('status, count(*) n')->groupBy('status')->pluck('n', 'status')->map(fn ($n) => (int) $n)->all());
        $this->assertSame('sent', $this->campaignRow($id)['status'], 'the campaign finishes');
    }

    public function test_a_rejected_api_key_leaves_the_messages_queued_instead_of_failing_them(): void
    {
        Queue::fake();
        $this->subscriber('a@x.com');
        $id = $this->campaign();
        $this->send($id);
        Http::fake(['api.resend.com/*' => Http::response(['name' => 'restricted_api_key', 'message' => 'This API key is restricted'], 403)]);

        try {
            $this->runBatches();
            $this->fail('the batch must be retried later');
        } catch (\RuntimeException) {
            $this->assertSame(['queued'], DB::table('newsletter_deliveries')->pluck('status')->all());
        }
    }

    public function test_someone_who_left_after_the_recipients_were_frozen_is_not_mailed(): void
    {
        Queue::fake();
        $this->resend();
        $this->subscriber('stay@x.com');
        $leaver = $this->subscriber('leave@x.com');
        $id = $this->campaign();
        $this->send($id);
        DB::table('newsletter_subscribers')->where('id', $leaver)->update(['status' => 'unsubscribed']);
        $this->runBatches();

        $to = collect(Http::recorded())->flatMap(fn ($p) => $p[0]->data())->pluck('to.0')->all();
        $this->assertSame(['stay@x.com'], $to);
    }

    public function test_a_cancelled_campaign_sends_nothing_more(): void
    {
        Queue::fake();
        $this->resend();
        $this->subscriber('a@x.com');
        $id = $this->campaign();
        $this->send($id);
        $this->adminGql('mutation($i: ID!) { cancelCampaign(id: $i) { status } }', ['i' => $id], $this->admin)->assertJsonPath('data.cancelCampaign.status', 'cancelled');
        $this->runBatches();

        Http::assertNothingSent();
    }

    public function test_a_scheduled_campaign_goes_out_when_its_time_comes_and_not_before(): void
    {
        Queue::fake();
        $this->subscriber('a@x.com');
        $id = $this->campaign();
        $this->send($id, null, now()->addHours(2)->toIso8601String())->assertJsonPath('data.sendCampaign.status', 'scheduled');

        Artisan::call('newsletter:launch-due');
        $this->assertSame('scheduled', $this->campaignRow($id)['status']);
        Queue::assertNothingPushed();

        $this->travel(3)->hours();
        Artisan::call('newsletter:launch-due');
        $this->assertSame('sending', $this->campaignRow($id)['status']);
        Queue::assertPushed(SendCampaignBatchJob::class, 1);
    }

    public function test_nothing_is_sent_until_resend_is_configured_and_only_people_with_the_send_permission_can_send(): void
    {
        Queue::fake();
        $this->subscriber('a@x.com');
        $id = $this->campaign();
        $support = $this->adminToken('help@example.com', 'support');
        $this->assertNotNull($this->send($id, $support)->json('errors'));

        config(['newsletter.resend.api_key' => '']);
        $this->send($id)->assertJsonPath('errors.0.extensions.code', 'MAILER_NOT_CONFIGURED');
        $this->assertSame('draft', $this->campaignRow($id)['status']);
        $this->assertSame(0, DB::table('newsletter_deliveries')->count());
        $this->assertFalse($this->adminGql('{ newsletterSending { configured } }', [], $this->admin)->json('data.newsletterSending.configured'));
    }

    public function test_a_test_email_goes_to_one_address_marked_as_test_and_touches_nothing_else(): void
    {
        $this->resend();
        $this->subscriber('a@x.com');
        $id = $this->campaign();

        $this->adminGql('mutation($i: ID!, $e: String!) { sendCampaignTest(id: $i, email: $e) }', ['i' => $id, 'e' => 'editor@cronos.test'], $this->admin)->assertJsonPath('data.sendCampaignTest', true);

        Http::assertSent(fn (Request $q) => $q->data()[0]['to'] === ['editor@cronos.test'] && str_starts_with($q->data()[0]['subject'], '[TEST] '));
        $this->assertSame(0, DB::table('newsletter_deliveries')->count());
        $this->assertSame('draft', $this->campaignRow($id)['status']);
    }

    public function test_only_drafts_can_be_edited_or_deleted_once_it_went_out_it_is_a_record(): void
    {
        Queue::fake();
        $this->subscriber('a@x.com');
        $draft = $this->campaign();
        $sent = $this->campaign();
        $this->send($sent);

        $this->adminGql('mutation($i: ID!) { updateCampaign(id: $i, name: "Otro") { name } }', ['i' => $draft], $this->admin)->assertJsonPath('data.updateCampaign.name', 'Otro');
        $this->adminGql('mutation($i: ID!) { updateCampaign(id: $i, name: "Otro") { name } }', ['i' => $sent], $this->admin)->assertJsonPath('errors.0.extensions.code', 'CAMPAIGN_LOCKED');
        $this->adminGql('mutation($i: ID!) { deleteCampaign(id: $i) }', ['i' => $sent], $this->admin)->assertJsonPath('errors.0.extensions.code', 'CAMPAIGN_LOCKED');
        $this->adminGql('mutation($i: ID!) { deleteCampaign(id: $i) }', ['i' => $draft], $this->admin)->assertJsonPath('data.deleteCampaign', true);
    }

    private function webhook(array $event, ?string $secret = null, ?int $at = null, bool $tamper = false): TestResponse
    {
        $body = json_encode($event);
        $id = 'msg_'.Str::random(6);
        $ts = (string) ($at ?? now()->getTimestamp());
        $key = base64_decode(substr($secret ?? self::WEBHOOK_SECRET, 6));
        $sig = base64_encode(hash_hmac('sha256', "{$id}.{$ts}.{$body}", $key, true));

        return $this->call('POST', '/webhooks/resend', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_SVIX_ID' => $id, 'HTTP_SVIX_TIMESTAMP' => $ts, 'HTTP_SVIX_SIGNATURE' => 'v1,'.($tamper ? 'AAAA' : $sig)], $body);
    }

    public function test_hard_bounces_and_complaints_take_the_address_off_the_list_but_a_soft_bounce_does_not(): void
    {
        Queue::fake();
        $this->resend();
        $ids = [];
        foreach (['hard', 'soft', 'spam', 'ok'] as $n) {
            $ids[$n] = $this->subscriber("{$n}@x.com");
        }
        $this->send($this->campaign());
        $this->runBatches();
        $provider = fn (string $n) => DB::table('newsletter_deliveries')->where('subscriber_id', $ids[$n])->value('provider_id');

        $this->webhook(['type' => 'email.bounced', 'data' => ['email_id' => $provider('hard'), 'bounce' => ['type' => 'Permanent']]])->assertOk();
        $this->webhook(['type' => 'email.bounced', 'data' => ['email_id' => $provider('soft'), 'bounce' => ['type' => 'Transient']]])->assertOk();
        $this->webhook(['type' => 'email.complained', 'data' => ['email_id' => $provider('spam')]])->assertOk();
        $this->webhook(['type' => 'email.delivered', 'data' => ['email_id' => $provider('ok')]])->assertOk();
        $this->webhook(['type' => 'email.delivered', 'data' => ['email_id' => $provider('hard')]])->assertOk(); // out of order: must not undo the bounce

        $status = fn (string $n) => DB::table('newsletter_subscribers')->where('id', $ids[$n])->value('status');
        $this->assertSame(['unsubscribed', 'confirmed', 'unsubscribed', 'confirmed'], [$status('hard'), $status('soft'), $status('spam'), $status('ok')]);
        $this->assertSame(['bounce', 'complaint'], [DB::table('newsletter_subscribers')->where('id', $ids['hard'])->value('unsubscribed_by'), DB::table('newsletter_subscribers')->where('id', $ids['spam'])->value('unsubscribed_by')]);
        $this->assertSame('bounced', DB::table('newsletter_deliveries')->where('subscriber_id', $ids['hard'])->value('status'));
        $this->assertSame('delivered', DB::table('newsletter_deliveries')->where('subscriber_id', $ids['ok'])->value('status'));
    }

    public function test_webhooks_that_are_forged_stale_or_unsigned_are_refused(): void
    {
        $event = ['type' => 'email.complained', 'data' => ['email_id' => 'x']];
        $this->webhook($event, null, null, true)->assertStatus(400);
        $this->webhook($event, null, now()->subMinutes(10)->getTimestamp())->assertStatus(400);
        $this->webhook($event, 'whsec_'.base64_encode('another-secret'))->assertStatus(400);
        $this->postJson('/webhooks/resend', $event)->assertStatus(400);
        config(['newsletter.resend.webhook_secret' => '']);
        $this->webhook($event)->assertStatus(400);
    }

    public function test_the_one_click_unsubscribe_that_mail_apps_post_works_and_a_plain_visit_does_nothing(): void
    {
        $id = $this->subscriber('a@x.com');
        $token = app(NewsletterTokens::class)->unsubscribe($id);

        $this->get('/newsletter/unsubscribe?token='.urlencode($token))->assertStatus(405);
        $this->assertSame('confirmed', DB::table('newsletter_subscribers')->where('id', $id)->value('status'));
        $this->post('/newsletter/unsubscribe?token='.urlencode($token), ['List-Unsubscribe' => 'One-Click'])->assertOk();
        $this->assertSame('unsubscribed', DB::table('newsletter_subscribers')->where('id', $id)->value('status'));
        $this->post('/newsletter/unsubscribe?token=forged')->assertStatus(400);
    }
}
