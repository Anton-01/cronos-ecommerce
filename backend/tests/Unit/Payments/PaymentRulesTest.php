<?php

declare(strict_types=1);

namespace Tests\Unit\Payments;

use Cronos\Payments\Domain\Services\MinorUnits;
use Cronos\Payments\Domain\Services\PaymentStateMachine;
use Cronos\Payments\Domain\Services\Scrubber;
use Cronos\Payments\Domain\ValueObjects\PaymentStatus as S;
use Cronos\Pricing\Domain\ValueObjects\Money;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PaymentRulesTest extends TestCase
{
    /** @return iterable<string, array{string, string, string}> */
    public static function transitions(): iterable
    {
        yield 'pending to requires_action' => [S::PENDING, S::REQUIRES_ACTION, 'apply'];
        yield 'requires_action to captured' => [S::REQUIRES_ACTION, S::CAPTURED, 'apply'];
        yield 'processing to failed' => [S::PROCESSING, S::FAILED, 'apply'];
        yield 'captured to partially refunded' => [S::CAPTURED, S::PARTIALLY_REFUNDED, 'apply'];
        yield 'partially refunded again' => [S::PARTIALLY_REFUNDED, S::PARTIALLY_REFUNDED, 'apply'];
        yield 'captured to refunded' => [S::CAPTURED, S::REFUNDED, 'apply'];
        yield 'captured to disputed' => [S::CAPTURED, S::DISPUTED, 'apply'];
        yield 'dispute won' => [S::DISPUTED, S::CAPTURED, 'apply'];
        yield 'the same status again is a no-op' => [S::CAPTURED, S::CAPTURED, 'noop'];
        yield 'a late delivery cannot move a payment back' => [S::CAPTURED, S::PROCESSING, 'reject'];
        yield 'nor reopen a refunded one' => [S::REFUNDED, S::CAPTURED, 'reject'];
        yield 'nor fail a captured one' => [S::CAPTURED, S::FAILED, 'reject'];
        yield 'money after a cancellation is accepted but flagged' => [S::CANCELLED, S::CAPTURED, 'anomaly'];
        yield 'money after a failure is accepted but flagged' => [S::FAILED, S::CAPTURED, 'anomaly'];
        yield 'cancelled stays cancelled otherwise' => [S::CANCELLED, S::PROCESSING, 'reject'];
    }

    #[DataProvider('transitions')]
    public function test_the_state_machine_decides_each_change(string $from, string $to, string $outcome): void
    {
        $this->assertSame($outcome, (new PaymentStateMachine)->decide($from, $to)['outcome']);
    }

    public function test_late_capture_names_its_anomaly(): void
    {
        $d = (new PaymentStateMachine)->decide(S::CANCELLED, S::CAPTURED);

        $this->assertSame([S::CAPTURED, 'late_capture'], [$d['status'], $d['anomaly']]);
    }

    public function test_amounts_convert_to_minor_units_exactly(): void
    {
        $this->assertSame(11600, MinorUnits::of(new Money('116.00', 'MXN')));
        $this->assertSame(1999, MinorUnits::of(new Money('19.99', 'USD')), '19.99 * 100 is 1998.9999999999998 in floats');
        $this->assertSame(1000, MinorUnits::of(new Money('1000', 'JPY', 0)));
        $this->assertSame('19.99', MinorUnits::toAmount(1999));
        $this->assertSame('0.05', MinorUnits::toAmount('5'));
    }

    public function test_the_scrubber_removes_secrets_and_card_data_at_any_depth(): void
    {
        $clean = Scrubber::scrub([
            'id' => 'pi_1', 'client_secret' => 'pi_1_secret_x', 'amount' => 100,
            'payment_method' => ['card' => ['number' => '4242424242424242', 'cvc' => '123', 'last4' => '4242', 'brand' => 'visa']],
            'headers' => ['Authorization' => 'Bearer abc'], 'access_token' => 'APP_USR-1', 'note' => str_repeat('x', 5000),
        ]);

        $this->assertSame('pi_1', $clean['id']);
        $this->assertSame(['[redacted]', '[redacted]', '[redacted]', '[redacted]'], [$clean['client_secret'], $clean['payment_method']['card']['number'], $clean['payment_method']['card']['cvc'], $clean['access_token']]);
        $this->assertSame(['4242', 'visa'], [$clean['payment_method']['card']['last4'], $clean['payment_method']['card']['brand']]);
        $this->assertSame('[redacted]', $clean['headers']['Authorization']);
        $this->assertLessThan(2100, mb_strlen($clean['note']));
    }
}
