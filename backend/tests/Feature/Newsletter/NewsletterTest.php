<?php

namespace Tests\Feature\Newsletter;

use Cronos\Markets\Application\MarketProfileManager;
use Cronos\Newsletter\Application\NewsletterTokens;
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

final class NewsletterTest extends GraphQLTestCase
{
    use RefreshDatabase;

    private const SUBSCRIBE = 'mutation($e: String!, $t: String, $s: String, $w: String, $r: String, $l: String) { subscribeToNewsletter(email: $e, humanToken: $t, source: $s, market: "MX", locale: $l, website: $w, renderedAt: $r) { status } }';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PricingSeeder::class);
        Cache::flush();
        Queue::fake();
        config(['human.turnstile.secret' => '', 'human.turnstile.site_key' => '']);
        app(MarketProfileManager::class)->update('MX', ['contact_email' => 'hola@example.com', 'trade_name' => 'Cronos MX']);
    }

    private function subscribe(string $email = 'ana@example.com', array $over = []): TestResponse
    {
        return $this->gql(self::SUBSCRIBE, ['e' => $email, 't' => $over['token'] ?? null, 's' => 'footer', 'w' => $over['website'] ?? null, 'r' => (string) ((time() - 20) * 1000), 'l' => $over['locale'] ?? 'es-MX']);
    }

    private function row(string $email = 'ana@example.com'): ?array
    {
        $r = DB::table('newsletter_subscribers')->whereRaw('lower(email) = ?', [$email])->first();

        return $r === null ? null : (array) $r;
    }

    private function sent(string $template): int
    {
        return DB::table('notification_log')->where('template', $template)->count();
    }

    private function confirmToken(string $id): string
    {
        return app(NewsletterTokens::class)->confirm($id);
    }

    public function test_signing_up_creates_a_pending_row_with_proof_of_consent_and_sends_only_the_confirmation(): void
    {
        $this->subscribe()->assertJsonMissingPath('errors')->assertJsonPath('data.subscribeToNewsletter.status', 'check_email');
        $row = $this->row();

        $this->assertSame(['pending', 'footer', 'es-MX', 'MX', '127.0.0.1'], [$row['status'], $row['source'], $row['locale'], $row['market_code'], $row['consent_ip']]);
        $this->assertNotNull($row['consented_at']);
        $this->assertNull($row['confirmed_at']);
        $this->assertSame(1, $this->sent('newsletter_confirm'));
        $this->assertSame(0, $this->sent('newsletter_welcome'));
    }

    public function test_only_following_the_link_confirms_and_then_the_welcome_with_the_unsubscribe_link_goes_out(): void
    {
        $this->subscribe();
        $id = $this->row()['id'];
        $confirm = 'mutation($t: String!) { confirmNewsletter(token: $t) { status } }';

        $this->gql($confirm, ['t' => $this->confirmToken($id)])->assertJsonPath('data.confirmNewsletter.status', 'confirmed');
        $row = $this->row();
        $this->assertSame('confirmed', $row['status']);
        $this->assertNotNull($row['confirmed_at']);
        $this->assertSame(1, $this->sent('newsletter_welcome'));

        $this->gql($confirm, ['t' => $this->confirmToken($id)])->assertJsonPath('data.confirmNewsletter.status', 'confirmed'); // a second click is harmless
        $this->assertSame(1, $this->sent('newsletter_welcome'), 'and does not send a second welcome');
    }

    public function test_the_answer_never_reveals_whether_an_address_is_already_on_the_list(): void
    {
        $first = $this->subscribe()->json();
        $this->gql('mutation($t: String!) { confirmNewsletter(token: $t) { status } }', ['t' => $this->confirmToken($this->row()['id'])]);
        $again = $this->subscribe()->json();

        $this->assertSame($first, $again);
        $this->assertSame(1, DB::table('newsletter_subscribers')->count());
        $this->assertSame(1, $this->sent('newsletter_confirm'), 'an already confirmed address gets nothing more');
    }

    public function test_a_pending_address_can_ask_for_the_link_again_but_not_over_and_over(): void
    {
        $this->subscribe();
        $this->subscribe();
        $this->assertSame(1, $this->sent('newsletter_confirm'), 'inside the window nothing is re-sent');

        DB::table('newsletter_subscribers')->update(['confirmation_sent_at' => now()->subMinutes(11)]);
        $this->subscribe();
        $this->assertSame(2, $this->sent('newsletter_confirm'), 'after a while one more is sent');
        $this->assertSame(1, DB::table('newsletter_subscribers')->count());
    }

    public function test_the_same_address_in_other_capitals_is_the_same_person(): void
    {
        $this->subscribe('ana@example.com');
        $this->subscribe('ANA@Example.com');

        $this->assertSame(1, DB::table('newsletter_subscribers')->count());
    }

    public function test_unsubscribing_works_from_any_old_link_and_coming_back_needs_a_new_confirmation(): void
    {
        $this->subscribe();
        $id = $this->row()['id'];
        $this->gql('mutation($t: String!) { confirmNewsletter(token: $t) { status } }', ['t' => $this->confirmToken($id)]);
        $unsub = app(NewsletterTokens::class)->unsubscribe($id);

        $this->travel(2)->years();
        $this->gql('mutation($t: String!) { unsubscribeNewsletter(token: $t) { status } }', ['t' => $unsub])->assertJsonPath('data.unsubscribeNewsletter.status', 'unsubscribed');
        $this->assertSame(['unsubscribed', 'subscriber'], [$this->row()['status'], $this->row()['unsubscribed_by']]);
        $this->gql('mutation($t: String!) { unsubscribeNewsletter(token: $t) { status } }', ['t' => $unsub])->assertJsonMissingPath('errors'); // idempotent

        $this->travelBack();
        $this->subscribe();
        $this->assertSame(['pending', null], [$this->row()['status'], $this->row()['unsubscribed_at']], 'asking again is a new consent: pending until they confirm');
        $this->assertSame(2, $this->sent('newsletter_confirm'));
    }

    public function test_links_that_are_forged_expired_or_for_another_purpose_are_refused(): void
    {
        $this->subscribe();
        $id = $this->row()['id'];
        $tokens = app(NewsletterTokens::class);
        $confirm = 'mutation($t: String!) { confirmNewsletter(token: $t) { status } }';

        $this->gql($confirm, ['t' => 'garbage'])->assertJsonPath('errors.0.extensions.code', 'INVALID_TOKEN');
        $this->gql($confirm, ['t' => $tokens->confirm($id).'x'])->assertJsonPath('errors.0.extensions.code', 'INVALID_TOKEN');
        $this->gql($confirm, ['t' => $tokens->unsubscribe($id)])->assertJsonPath('errors.0.extensions.code', 'INVALID_TOKEN'); // an unsubscribe link cannot confirm
        $this->gql('mutation($t: String!) { unsubscribeNewsletter(token: $t) { status } }', ['t' => $tokens->confirm($id)])->assertJsonPath('errors.0.extensions.code', 'INVALID_TOKEN');

        $fresh = $tokens->confirm($id);
        $this->travel(8)->days();
        $this->gql($confirm, ['t' => $fresh])->assertJsonPath('errors.0.extensions.code', 'EXPIRED_TOKEN');
        $this->assertSame('pending', $this->row()['status']);
    }

    public function test_a_bad_address_is_reported_and_nothing_is_stored(): void
    {
        $this->subscribe('not-an-email')->assertJsonPath('errors.0.extensions.code', 'INVALID_EMAIL');
        $this->assertSame(0, DB::table('newsletter_subscribers')->count());
    }

    public function test_the_hidden_field_and_a_too_fast_post_are_accepted_silently_and_do_nothing(): void
    {
        $this->subscribe('bot@example.com', ['website' => 'http://spam.example'])->assertJsonPath('data.subscribeToNewsletter.status', 'check_email');
        $this->gql(self::SUBSCRIBE, ['e' => 'fast@example.com', 't' => null, 's' => 'footer', 'w' => null, 'r' => (string) (time() * 1000), 'l' => 'es-MX'])->assertJsonPath('data.subscribeToNewsletter.status', 'check_email');

        $this->assertSame(0, DB::table('newsletter_subscribers')->count());
        $this->assertSame(0, $this->sent('newsletter_confirm'));
    }

    public function test_floods_are_refused_per_address_and_per_network(): void
    {
        config(['newsletter.max_per_email_per_day' => 2, 'newsletter.max_per_ip_per_hour' => 4]);
        $this->subscribe('a@example.com');
        $this->subscribe('a@example.com');
        $this->subscribe('a@example.com')->assertJsonPath('errors.0.extensions.code', 'RATE_LIMITED'); // someone mail-bombing one inbox
        $this->subscribe('b@example.com');
        $this->subscribe('c@example.com');
        $this->subscribe('d@example.com')->assertJsonPath('errors.0.extensions.code', 'RATE_LIMITED'); // the network used its hits
    }

    public function test_cloudflare_is_checked_for_the_newsletter_form_with_its_own_action(): void
    {
        config(['human.turnstile.secret' => 'sekret', 'human.turnstile.site_key' => 'site']);
        Http::fake(['challenges.cloudflare.com/*' => Http::sequence()->push(['success' => true, 'action' => 'newsletter'])->push(['success' => true, 'action' => 'contact'])]);

        $this->subscribe('ok@example.com', ['token' => 't1'])->assertJsonMissingPath('errors');
        $this->subscribe('foreign@example.com', ['token' => 't2'])->assertJsonPath('errors.0.extensions.code', 'HUMAN_CHECK_FAILED'); // a token from the contact form
        $this->subscribe('none@example.com')->assertJsonPath('errors.0.extensions.code', 'HUMAN_CHECK_FAILED');
        $this->assertSame(['ok@example.com'], DB::table('newsletter_subscribers')->pluck('email')->all());
        $this->assertSame(['site', 'newsletter'], array_values($this->gql('{ newsletterConfig { turnstileSiteKey turnstileAction } }')->json('data.newsletterConfig')));
    }

    public function test_the_mails_carry_a_working_link_in_the_visitors_language_and_reply_to_the_shop(): void
    {
        Mail::fake();
        $this->subscribe('john@example.com', ['locale' => 'en-US']);
        $id = $this->row('john@example.com')['id'];
        $this->gql('mutation($t: String!) { confirmNewsletter(token: $t) { status } }', ['t' => $this->confirmToken($id)]);
        foreach (DB::table('notification_log')->pluck('id') as $nid) {
            app()->call([new SendNotificationJob($nid), 'handle']);
        }

        Mail::assertSent(ShopMail::class, fn (ShopMail $m) => $m->hasTo('john@example.com') && $m->envelope()->subject === 'Confirm your subscription' && $m->hasReplyTo('hola@example.com')
            && str_contains($m->data['url'], '/en-us/newsletter/confirm?token='));
        Mail::assertSent(ShopMail::class, fn (ShopMail $m) => $m->envelope()->subject === 'You are on the list!' && str_contains($m->data['unsubscribe_url'], '/en-us/newsletter/unsubscribe?token='));
        // The link in the welcome really unsubscribes.
        $welcome = json_decode(DB::table('notification_log')->where('template', 'newsletter_welcome')->value('data'), true);
        parse_str((string) parse_url($welcome['unsubscribe_url'], PHP_URL_QUERY), $q);
        $this->gql('mutation($t: String!) { unsubscribeNewsletter(token: $t) { status } }', ['t' => $q['token']])->assertJsonPath('data.unsubscribeNewsletter.status', 'unsubscribed');
    }

    public function test_the_back_office_lists_counts_unsubscribes_and_erases_and_only_for_those_allowed(): void
    {
        foreach (['a@example.com', 'b@example.com'] as $e) {
            $this->subscribe($e);
        }
        $this->gql('mutation($t: String!) { confirmNewsletter(token: $t) { status } }', ['t' => $this->confirmToken($this->row('a@example.com')['id'])]);
        $admin = $this->adminToken();
        $support = $this->adminToken('help@example.com', 'support');
        $q = 'query($s: String, $q: String) { newsletterSubscribers(status: $s, search: $q) { total counts { pending confirmed unsubscribed } rows { id email status } } }';

        $all = $this->adminGql($q, ['s' => null, 'q' => null], $admin)->assertJsonMissingPath('errors')->json('data.newsletterSubscribers');
        $this->assertSame([1, 1, 0], array_values($all['counts']));
        $this->assertSame(['a@example.com'], array_column($this->adminGql($q, ['s' => 'confirmed', 'q' => null], $admin)->json('data.newsletterSubscribers.rows'), 'email'));
        $this->assertSame(['b@example.com'], array_column($this->adminGql($q, ['s' => null, 'q' => 'b@'], $admin)->json('data.newsletterSubscribers.rows'), 'email'));

        $id = $this->row('a@example.com')['id'];
        $this->adminGql('mutation($i: ID!) { unsubscribeNewsletterSubscriber(id: $i) }', ['i' => $id], $admin)->assertJsonPath('data.unsubscribeNewsletterSubscriber', true);
        $this->assertSame(['unsubscribed', 'admin'], [$this->row('a@example.com')['status'], $this->row('a@example.com')['unsubscribed_by']]);
        $this->adminGql('mutation($i: ID!) { eraseNewsletterSubscriber(id: $i) }', ['i' => $id], $admin)->assertJsonPath('data.eraseNewsletterSubscriber', true);
        $this->assertNull($this->row('a@example.com'), 'erasure removes the row and the proof of consent');
        $this->assertNotNull($this->adminGql($q, ['s' => null, 'q' => null], $support)->json('errors'));
    }
}
