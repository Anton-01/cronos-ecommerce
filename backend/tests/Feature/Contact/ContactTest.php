<?php

namespace Tests\Feature\Contact;

use Cronos\Markets\Application\MarketProfileManager;
use Cronos\Notifications\Infrastructure\Jobs\SendNotificationJob;
use Cronos\Notifications\Infrastructure\Mail\ShopMail;
use Database\Seeders\PricingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\GraphQLTestCase;

final class ContactTest extends GraphQLTestCase
{
    use RefreshDatabase;

    private const MUTATION = 'mutation($i: ContactMessageInput!, $t: String, $m: String, $l: String) { submitContactMessage(input: $i, humanToken: $t, market: $m, locale: $l) { reference } }';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PricingSeeder::class);
        Cache::flush();
        Queue::fake();
        config(['contact.turnstile.secret' => '', 'contact.turnstile.site_key' => '', 'contact.min_fill_seconds' => 3]);
        app(MarketProfileManager::class)->update('MX', ['ops_email' => 'tienda@example.com', 'contact_email' => 'hola@example.com', 'support_hours' => 'Lun-Vie 9-18']);
    }

    /** A message typed by a person: form shown 30 s ago, nothing in the honeypot. */
    private function send(array $over = [], ?string $token = null, string $locale = 'es-MX'): TestResponse
    {
        return $this->gql(self::MUTATION, ['i' => $over + ['name' => 'Ana Pérez', 'email' => 'ana@example.com', 'topic' => 'order', 'orderNumber' => 'ord-1001', 'message' => 'Mi pedido no ha llegado todavía, ¿me ayudan?', 'renderedAt' => (string) ((time() - 30) * 1000)], 't' => $token, 'm' => 'MX', 'l' => $locale]);
    }

    private function stored(): array
    {
        return DB::table('contact_messages')->orderBy('created_at')->get()->map(fn ($r) => (array) $r)->all();
    }

    private function sent(string $template): int
    {
        return DB::table('notification_log')->where('template', $template)->count();
    }

    public function test_a_message_is_stored_and_both_the_shop_and_the_sender_are_told(): void
    {
        $r = $this->send()->assertJsonMissingPath('errors');
        $row = $this->stored()[0];

        $this->assertSame(strtoupper(substr($row['id'], -8)), $r->json('data.submitContactMessage.reference'));
        $this->assertSame(['new', 'ORD-1001', 'order', 'MX', 'skipped'], [$row['status'], $row['order_number'], $row['topic'], $row['market_code'], $row['human_check']]);
        $this->assertSame(1, $this->sent('contact_received'));
        $this->assertSame(1, $this->sent('contact_receipt'));
        $to = DB::table('notification_log')->pluck('email', 'template')->all();
        $this->assertSame(['contact_received' => 'tienda@example.com', 'contact_receipt' => 'ana@example.com'], $to);
    }

    public function test_the_shop_mail_replies_to_the_visitor_and_the_receipt_speaks_the_visitors_language(): void
    {
        Mail::fake();
        $this->send(['name' => 'John Smith', 'email' => 'john@example.com'], null, 'en-US')->assertJsonMissingPath('errors');
        foreach (DB::table('notification_log')->pluck('id') as $id) {
            app()->call([new SendNotificationJob($id), 'handle']);
        }

        Mail::assertSent(ShopMail::class, fn (ShopMail $m) => $m->hasTo('tienda@example.com') && $m->hasReplyTo('john@example.com') && str_contains($m->envelope()->subject, 'John Smith') && str_contains($m->envelope()->subject, 'Sobre un pedido')); // the shop's own staff read it in the store's language
        Mail::assertSent(ShopMail::class, fn (ShopMail $m) => $m->hasTo('john@example.com') && str_starts_with($m->envelope()->subject, 'We received your message') && $m->hasReplyTo('hola@example.com'));
    }

    public function test_what_the_visitor_wrote_cannot_inject_links_images_or_html_into_the_shop_mail(): void
    {
        $this->send(['name' => '[Click](https://evil.example)', 'message' => "Hola\n\n[Gana dinero](https://evil.example/x)\n\n![pixel](https://evil.example/p.gif)\n\n<script>alert(1)</script>\n\n# Título falso"])->assertJsonMissingPath('errors');
        $data = json_decode(DB::table('notification_log')->where('template', 'contact_received')->value('data'), true);
        $html = (new ShopMail('contact_received', 'es-MX', $data))->render();

        $this->assertStringNotContainsString('href="https://evil.example', $html);
        $this->assertStringNotContainsString('<img src="https://evil.example', $html);
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('<h1>Título falso', $html);
        $this->assertStringContainsString('Gana dinero', $html, 'the text itself is kept, readable');
    }

    public function test_invalid_fields_are_reported_one_by_one_and_nothing_is_stored(): void
    {
        $r = $this->send(['name' => 'A', 'email' => 'no-es-correo', 'message' => 'corto', 'topic' => 'hackeo']);
        $r->assertJsonPath('errors.0.extensions.code', 'INVALID_MESSAGE');
        $this->assertEqualsCanonicalizing(['name', 'email', 'message', 'topic'], array_keys($r->json('errors.0.extensions.fields')));
        $this->assertSame([], $this->stored());
    }

    public function test_the_honeypot_and_a_too_fast_post_are_kept_as_spam_without_telling_the_sender_or_mailing_the_shop(): void
    {
        $bot = $this->send(['website' => 'http://spam.example', 'email' => 'bot@example.com'])->assertJsonMissingPath('errors');
        $fast = $this->send(['renderedAt' => (string) (time() * 1000), 'email' => 'fast@example.com'])->assertJsonMissingPath('errors');

        $this->assertNotEmpty($bot->json('data.submitContactMessage.reference'), 'the bot is told it worked');
        $this->assertNotEmpty($fast->json('data.submitContactMessage.reference'));
        $rows = collect($this->stored());
        $this->assertSame(['spam', 'spam'], $rows->pluck('status')->all());
        $this->assertContains('honeypot', json_decode($rows[0]['spam_signals'], true));
        $this->assertContains('too_fast', json_decode($rows[1]['spam_signals'], true));
        $this->assertSame(0, $this->sent('contact_received') + $this->sent('contact_receipt'));
    }

    public function test_one_weak_signal_is_delivered_but_flagged_two_make_it_spam(): void
    {
        $this->send(['email' => 'a@example.com', 'message' => 'Mira estos productos: https://a.example/1 https://a.example/2 https://a.example/3 ¿tienen listón así?'])->assertJsonMissingPath('errors');
        $this->send(['email' => 'b@example.com', 'name' => 'https://seo.example', 'message' => 'Best SEO services and backlinks https://a.example https://b.example https://c.example'])->assertJsonMissingPath('errors');

        $rows = collect($this->stored());
        $this->assertSame(['new', 'spam'], $rows->pluck('status')->all());
        $this->assertSame(['many_links'], json_decode($rows[0]['spam_signals'], true));
        $this->assertSame(1, $this->sent('contact_received'), 'only the first reached the shop');
    }

    public function test_floods_are_refused_per_address_and_per_email(): void
    {
        config(['contact.max_per_ip_per_hour' => 3, 'contact.max_per_email_per_day' => 2]);
        foreach (['a', 'b'] as $i => $who) {
            $this->send(['email' => "{$who}@example.com", 'message' => "Mensaje número {$i} con texto suficiente"])->assertJsonMissingPath('errors');
        }
        // Third from the same e-mail today: refused, while a new e-mail still gets through (the IP has one left).
        $this->send(['email' => 'a@example.com', 'message' => 'Otro mensaje distinto con texto suficiente'])->assertJsonMissingPath('errors');
        $this->send(['email' => 'a@example.com', 'message' => 'Y otro más diferente con texto suficiente'])->assertJsonPath('errors.0.extensions.code', 'RATE_LIMITED');
        $this->send(['email' => 'c@example.com', 'message' => 'Otra persona con texto suficiente aquí'])->assertJsonPath('errors.0.extensions.code', 'RATE_LIMITED'); // the IP is now full
        $this->assertCount(3, $this->stored(), 'refused floods are not stored');
    }

    public function test_the_same_message_sent_twice_is_kept_once_as_a_duplicate(): void
    {
        $this->send()->assertJsonMissingPath('errors');
        $this->send()->assertJsonMissingPath('errors'); // double click

        $this->assertSame(['new', 'spam'], collect($this->stored())->pluck('status')->all());
        $this->assertSame(1, $this->sent('contact_received'));
    }

    public function test_cloudflare_turnstile_is_checked_with_the_secret_the_token_and_the_visitors_ip(): void
    {
        config(['contact.turnstile.secret' => 'sekret', 'contact.turnstile.site_key' => 'site']);
        Http::fake(['challenges.cloudflare.com/*' => Http::response(['success' => true, 'action' => 'contact', 'hostname' => 'tienda.example'])]);

        $this->send([], 'token-123')->assertJsonMissingPath('errors');

        Http::assertSent(fn ($req) => $req->url() === 'https://challenges.cloudflare.com/turnstile/v0/siteverify' && $req['secret'] === 'sekret' && $req['response'] === 'token-123' && $req['remoteip'] === '127.0.0.1');
        $this->assertSame('passed', $this->stored()[0]['human_check']);
    }

    public function test_a_failed_missing_or_foreign_token_is_refused_and_not_stored(): void
    {
        config(['contact.turnstile.secret' => 'sekret', 'contact.turnstile.site_key' => 'site']);
        Http::fake(['challenges.cloudflare.com/*' => Http::sequence()
            ->push(['success' => false, 'error-codes' => ['invalid-input-response']])
            ->push(['success' => true, 'action' => 'login'])]);

        $this->send([], 'bad-token')->assertJsonPath('errors.0.extensions.code', 'HUMAN_CHECK_FAILED');
        $this->send(['email' => 'o@example.com'], 'token-from-another-form')->assertJsonPath('errors.0.extensions.code', 'HUMAN_CHECK_FAILED');
        $this->send(['email' => 'p@example.com'], null)->assertJsonPath('errors.0.extensions.code', 'HUMAN_CHECK_FAILED');
        Http::assertSentCount(2); // no token → refused without calling Cloudflare
        $this->assertSame([], $this->stored());
    }

    public function test_when_cloudflare_is_down_the_message_goes_through_flagged_instead_of_blocking_real_customers(): void
    {
        config(['contact.turnstile.secret' => 'sekret', 'contact.turnstile.site_key' => 'site']);
        Http::fake(['challenges.cloudflare.com/*' => Http::response('oops', 503)]);

        $this->send([], 'token')->assertJsonMissingPath('errors');
        $row = $this->stored()[0];
        $this->assertSame(['new', 'unavailable'], [$row['status'], $row['human_check']]);
        $this->assertContains('human_check_unavailable', json_decode($row['spam_signals'], true));
    }

    public function test_the_widget_key_is_offered_only_when_the_server_can_check_it(): void
    {
        $q = fn () => $this->gql('{ contactConfig { turnstileSiteKey turnstileAction topics } }')->assertJsonMissingPath('errors')->json('data.contactConfig');
        config(['contact.turnstile.site_key' => 'site', 'contact.turnstile.secret' => '']);
        $this->assertNull($q()['turnstileSiteKey']);

        config(['contact.turnstile.secret' => 'sekret']);
        $this->assertSame(['site', 'contact'], [$q()['turnstileSiteKey'], $q()['turnstileAction']]);
        $this->assertContains('wholesale', $q()['topics']);
    }

    public function test_the_back_office_lists_filters_and_marks_messages_and_only_staff_can(): void
    {
        $this->send()->assertJsonMissingPath('errors');
        $this->send(['website' => 'x', 'email' => 'bot@example.com'])->assertJsonMissingPath('errors');
        $support = $this->adminToken('help@example.com', 'support');
        $this->makeUser('cust@example.com');
        $customer = $this->gql('mutation($i: LoginInput!) { login(input: $i) { token } }', ['i' => ['email' => 'cust@example.com', 'password' => 'correct-horse-battery']])->json('data.login.token');

        $list = fn (?string $status) => $this->adminGql('query($s: String) { contactMessages(status: $s) { total rows { id name status spamSignals humanCheck } } }', ['s' => $status], $support)->assertJsonMissingPath('errors')->json('data.contactMessages');
        $this->assertSame(2, $list(null)['total']);
        $this->assertSame(['spam'], array_column($list('spam')['rows'], 'status'));
        $this->assertSame(['honeypot'], $list('spam')['rows'][0]['spamSignals']);

        $id = $list('new')['rows'][0]['id'];
        $this->adminGql('mutation($i: ID!) { setContactMessageStatus(id: $i, status: "replied") { status handledAt } }', ['i' => $id], $support)->assertJsonPath('data.setContactMessageStatus.status', 'replied');
        $this->adminGql('mutation($i: ID!) { setContactMessageStatus(id: $i, status: "nope") { status } }', ['i' => $id], $support)->assertJsonPath('errors.0.extensions.code', 'INVALID_STATUS');
        $this->assertNotNull($this->adminGql('{ contactMessages { total } }', [], $customer)->json('errors'));
    }
}
