<?php

namespace Tests\Feature\Fraud;

use Cronos\Fraud\Application\FraudRuleManager;
use Cronos\Fraud\Application\FraudService;
use Cronos\Fraud\Domain\Exceptions\InvalidFraudRule;
use Cronos\Fraud\Domain\Ports\FraudRepository;
use Cronos\Fraud\Domain\ValueObjects\FraudContext;
use Cronos\Fraud\Domain\ValueObjects\FraudDecision;
use Cronos\Pricing\Domain\ValueObjects\Money;
use Database\Seeders\FraudRulesSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class FraudTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->seed(FraudRulesSeeder::class);
    }

    private function ctx(string $total = '500', string $ip = '203.0.113.9', string $email = 'a@example.com', string $billing = 'MX', string $shipping = 'MX'): FraudContext
    {
        return new FraudContext($ip, $email, null, new Money($total, 'MXN'), $billing, $shipping);
    }

    public function test_a_normal_checkout_is_allowed_and_logged(): void
    {
        $d = app(FraudService::class)->assess($this->ctx(), 'order-1');

        $this->assertSame([FraudDecision::ALLOW, 0], [$d->decision, $d->score]);
        $this->assertSame('allow', app(FraudRepository::class)->checks('order-1', null)[0]['decision']);
    }

    public function test_a_big_order_shipped_to_another_country_is_held_for_review(): void
    {
        $d = app(FraudService::class)->assess($this->ctx('20000', billing: 'MX', shipping: 'US'));

        $this->assertSame([FraudDecision::REVIEW, 50], [$d->decision, $d->score]);
        $this->assertSame(['Monto alto', 'País de facturación distinto al de envío'], array_column($d->reasons, 'rule'));
    }

    public function test_repeated_attempts_from_one_ip_and_email_trip_the_velocity_rules_and_block(): void
    {
        $service = app(FraudService::class);
        foreach (range(1, 5) as $i) {
            $service->assess($this->ctx());
        }
        $service->recordFailedPayment('a@example.com', '203.0.113.9');
        $service->recordFailedPayment('a@example.com', '203.0.113.9');
        $service->recordFailedPayment('A@example.com', '203.0.113.9');

        $d = $service->assess($this->ctx());

        $this->assertSame(FraudDecision::BLOCK, $d->decision);
        $this->assertGreaterThanOrEqual(80, $d->score);
        $this->assertContains('Pagos rechazados repetidos', array_column($d->reasons, 'rule'));

        $other = $service->assess($this->ctx(ip: '198.51.100.1', email: 'b@example.com'));
        $this->assertSame(FraudDecision::ALLOW, $other->decision, 'another shopper is unaffected');
    }

    public function test_the_log_of_assessments_is_immutable(): void
    {
        app(FraudService::class)->assess($this->ctx());

        $this->expectException(QueryException::class);
        DB::table('fraud_checks')->update(['decision' => 'allow']);
    }

    public function test_rules_are_validated_and_inactive_rules_do_not_fire(): void
    {
        $rules = app(FraudRuleManager::class);
        foreach ([
            ['name' => 'x', 'type' => 'nope', 'params' => [], 'score' => 10],
            ['name' => 'x', 'type' => 'velocity_ip', 'params' => ['max' => 0, 'window_minutes' => 5], 'score' => 10],
            ['name' => 'x', 'type' => 'amount_over', 'params' => ['amounts' => ['MXN' => '-5']], 'score' => 10],
            ['name' => 'x', 'type' => 'high_risk_country', 'params' => ['countries' => ['MEX']], 'score' => 10],
            ['name' => 'x', 'type' => 'country_mismatch', 'params' => [], 'score' => 0],
        ] as $bad) {
            try {
                $rules->create($bad);
                $this->fail('expected '.json_encode($bad).' to be refused');
            } catch (InvalidFraudRule) {
                $this->addToAssertionCount(1);
            }
        }

        $id = $rules->create(['name' => 'Riesgo', 'type' => 'high_risk_country', 'params' => ['countries' => ['ru']], 'score' => 90]);
        $this->assertSame(FraudDecision::BLOCK, app(FraudService::class)->assess($this->ctx(shipping: 'RU'))->decision);

        $rules->update($id, ['is_active' => false]);
        $this->assertNotSame(FraudDecision::BLOCK, app(FraudService::class)->assess($this->ctx(shipping: 'RU'))->decision);
    }

    public function test_the_seeder_only_runs_on_an_empty_table(): void
    {
        $count = count(app(FraudRepository::class)->rules());
        $this->seed(FraudRulesSeeder::class);

        $this->assertSame(5, $count);
        $this->assertCount(5, app(FraudRepository::class)->rules());
    }
}
