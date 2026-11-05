<?php

namespace Tests\Unit\Inventory;

use Brick\Math\BigDecimal as D;
use Cronos\Inventory\Domain\Services\ApprovalPolicy;
use Cronos\Inventory\Domain\ValueObjects\StockState as S;
use PHPUnit\Framework\TestCase;

final class StockStateTest extends TestCase
{
    public function test_a_quantity_is_ok_low_critical_or_out_by_its_thresholds(): void
    {
        $min = D::of(10);
        $crit = D::of(4);
        $this->assertSame(S::OK, S::raw(D::of(11), $min, $crit));
        $this->assertSame(S::LOW, S::raw(D::of(10), $min, $crit));
        $this->assertSame(S::CRITICAL, S::raw(D::of(4), $min, $crit));
        $this->assertSame(S::OUT, S::raw(D::of(0), $min, $crit));
        $this->assertSame(S::LOW, S::raw(D::of('0.5'), $min, null));
    }

    public function test_getting_worse_is_noticed_at_once_but_recovery_needs_a_margin(): void
    {
        $min = D::of(10);
        $this->assertSame(S::LOW, S::next(S::OK, D::of(10), $min, null, 10));
        // Back to exactly 11: above the minimum but inside the 10 % margin (needs > 11) -> still low, no message.
        $this->assertSame(S::LOW, S::next(S::LOW, D::of(11), $min, null, 10));
        $this->assertSame(S::OK, S::next(S::LOW, D::of('11.5'), $min, null, 10));
        // Zero margin = recovers as soon as it is above the threshold.
        $this->assertSame(S::OK, S::next(S::LOW, D::of(11), $min, null, 0));
    }

    public function test_out_recovers_into_the_state_the_quantity_deserves(): void
    {
        $this->assertSame(S::CRITICAL, S::next(S::OUT, D::of(2), D::of(10), D::of(4), 10));
        $this->assertSame(S::LOW, S::next(S::OUT, D::of(8), D::of(10), D::of(4), 10));
    }

    public function test_approval_needs_a_second_person_only_above_the_configured_limits(): void
    {
        $policy = ['approval_units' => 5, 'approval_percent' => 0, 'approval_amount' => 0];
        $this->assertFalse(ApprovalPolicy::needsApproval(D::of(-5), D::of(100), null, $policy));
        $this->assertTrue(ApprovalPolicy::needsApproval(D::of('5.5'), D::of(100), null, $policy));

        $percent = ['approval_units' => 0, 'approval_percent' => 10, 'approval_amount' => 0];
        $this->assertFalse(ApprovalPolicy::needsApproval(D::of(10), D::of(100), null, $percent));
        $this->assertTrue(ApprovalPolicy::needsApproval(D::of(11), D::of(100), null, $percent));
        $this->assertTrue(ApprovalPolicy::needsApproval(D::of(1), D::of(0), null, $percent), 'against an empty shelf any change is over the limit');

        $money = ['approval_units' => 0, 'approval_percent' => 0, 'approval_amount' => 100];
        $this->assertFalse(ApprovalPolicy::needsApproval(D::of(4), D::of(50), D::of(25), $money));
        $this->assertTrue(ApprovalPolicy::needsApproval(D::of(5), D::of(50), D::of(25), $money));
        $this->assertTrue(ApprovalPolicy::needsApproval(D::of(1), D::of(50), null, $money), 'unknown value cannot clear a money limit');
    }
}
