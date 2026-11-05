<?php

namespace Tests\Feature\Inventory;

use Cronos\Identity\Infrastructure\Persistence\Eloquent\UserModel;
use Cronos\Inventory\Application\Actor;
use Cronos\Inventory\Application\AdjustmentRequests;
use Cronos\Inventory\Application\AlertSubscriptions;
use Cronos\Inventory\Application\CountSessions;
use Cronos\Inventory\Application\DailyDigest;
use Cronos\Inventory\Application\LocationLedger;
use Cronos\Inventory\Application\LocationManager;
use Cronos\Inventory\Application\StockThresholds;
use Cronos\Inventory\Application\WarehouseOperations;
use Cronos\Inventory\Application\WarehousePolicies;
use Cronos\Inventory\Domain\Exceptions\InvalidMovement;
use Cronos\Inventory\Domain\Ports\AlertStore;
use Cronos\Inventory\Domain\ValueObjects\AlertProcess as P;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

final class WarehouseAlertsTest extends InventoryTestCase
{
    private string $lead;

    private string $operator;

    private string $shopper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->lead = $this->user('lead@example.test', 'admin');
        $this->operator = $this->user('worker@example.test', 'support');
        $this->shopper = $this->user('shopper@example.test', 'customer');
    }

    private function user(string $email, string $role): string
    {
        $u = UserModel::query()->create(['email' => $email, 'password' => 'x', 'is_active' => true]);
        $u->assignRole(Role::findByName($role, 'web'));

        return $u->id;
    }

    private function shelf(string $code): string
    {
        return app(LocationManager::class)->create($this->mx, ['code' => $code, 'name' => $code]);
    }

    private function alerts(?string $process = null): array
    {
        return DB::table('warehouse_alerts')->when($process, fn ($q) => $q->where('process', $process))->orderBy('created_at')->get()->all();
    }

    private function listen(string $process, string $user, bool $email = true): void
    {
        app(AlertSubscriptions::class)->add($process, $user, $this->lead, true, $email);
    }

    public function test_only_people_of_the_system_can_be_added_and_every_change_is_logged(): void
    {
        $subs = app(AlertSubscriptions::class);
        $subs->add(P::STOCK_LOW, $this->operator, $this->lead);

        try {
            $subs->add(P::STOCK_LOW, $this->shopper, $this->lead);
            $this->fail('A shopper is not a system user');
        } catch (InvalidMovement $e) {
            $this->assertSame('NOT_A_SYSTEM_USER', $e->errorCode());
        }
        $subs->add(P::STOCK_LOW, $this->operator, $this->lead, true, false); // changes the way
        $subs->remove(P::STOCK_LOW, $this->operator, $this->lead);

        $this->assertSame(['added', 'changed', 'removed'], array_column(array_reverse($subs->log(P::STOCK_LOW)), 'action'));
        $this->assertSame([], $subs->overview()[P::STOCK_LOW]['subscribers']);
        $this->assertEqualsCanonicalizing([$this->lead, $this->operator], array_column($subs->candidates(), 'id'), 'shoppers are not offered');

        $this->expectException(QueryException::class);
        DB::table('alert_subscription_log')->delete();
    }

    public function test_a_threshold_speaks_only_when_the_state_changes_and_recovery_has_a_margin(): void
    {
        $roll = $this->ribbon();
        $a = $this->shelf('A');
        $this->listen(P::STOCK_LOW, $this->operator);
        $this->listen(P::STOCK_OUT, $this->operator);
        $this->listen(P::STOCK_RECOVERED, $this->operator);
        $ops = app(WarehouseOperations::class);
        $ops->receive($roll, $this->mx, 30, $this->actor(), $a);
        app(StockThresholds::class)->set($roll, $this->mx, null, '10', '4', $this->lead);
        $this->assertSame([], $this->alerts(), 'plenty of stock: nothing to say');

        $ops->writeOff($roll, $a, 21, $this->actor(), 'Water damage');   // 9 left -> low
        $ops->writeOff($roll, $a, 1, $this->actor(), 'Frayed');          // 8 -> still low: silent
        $this->assertSame([P::STOCK_LOW], array_column($this->alerts(), 'process'));

        $ops->receive($roll, $this->mx, 2, $this->actor(), $a);          // 10 -> inside the margin: silent
        $this->assertCount(1, $this->alerts());
        $ops->receive($roll, $this->mx, 3, $this->actor(), $a);          // 13 -> recovered
        $this->assertSame([P::STOCK_LOW, P::STOCK_RECOVERED], array_column($this->alerts(), 'process'));

        $ops->writeOff($roll, $a, 13, $this->actor(), 'Sold off the shelf');
        $this->assertSame(P::STOCK_OUT, last($this->alerts())->process);

        $alert = last($this->alerts());
        $this->assertSame(1, DB::table('warehouse_alert_recipients')->where(['alert_id' => $alert->id, 'user_id' => $this->operator])->count());
        $this->assertSame(1, app(AlertStore::class)->unreadCount($this->operator) - (count($this->alerts()) - 1));
    }

    public function test_a_shelf_threshold_is_watched_apart_from_the_warehouse(): void
    {
        $roll = $this->ribbon();
        $a = $this->shelf('A');
        $b = $this->shelf('B');
        $this->listen(P::STOCK_LOW, $this->operator);
        $ops = app(WarehouseOperations::class);
        $ops->receive($roll, $this->mx, 20, $this->actor(), $a);
        $ops->receive($roll, $this->mx, 20, $this->actor(), $b);
        app(StockThresholds::class)->set($roll, $this->mx, $a, '15', null, $this->lead);

        app(LocationLedger::class)->move($roll, $a, $b, 8, $this->actor());
        $low = $this->alerts(P::STOCK_LOW);
        $this->assertCount(1, $low);
        $this->assertStringContainsString('A', $low[0]->detail);
        $this->assertSame($a, $low[0]->location_id);
    }

    public function test_every_threshold_change_is_kept_and_the_product_own_threshold_is_the_fallback(): void
    {
        $roll = $this->ribbon('Own', 'OWN-1', '10', ['low_stock_threshold' => '7']);
        $store = app(AlertStore::class);
        $this->assertSame('7.000', $store->threshold($roll, $this->mx, null)['minimum']);

        $t = app(StockThresholds::class);
        $t->set($roll, $this->mx, null, '12', '3', $this->lead);
        $t->set($roll, $this->mx, null, '15', '5', $this->operator);
        $this->assertSame('15.000', $store->threshold($roll, $this->mx, null)['minimum']);
        $this->assertSame(2, DB::table('stock_threshold_versions')->where('product_id', $roll)->count());

        $t->set($roll, $this->mx, null, null, null, $this->lead); // removed
        $this->assertNull($store->threshold($roll, $this->mx, null));
        $this->expectException(InvalidMovement::class);
        $t->set($roll, $this->mx, null, '5', '9', $this->lead);
    }

    public function test_the_approval_limits_are_editable_versioned_and_never_all_off(): void
    {
        $roll = $this->ribbon();
        $a = $this->shelf('A');
        app(WarehouseOperations::class)->receive($roll, $this->mx, 100, $this->actor(), $a);
        $policies = app(WarehousePolicies::class);
        $req = app(AdjustmentRequests::class);
        $version = fn () => (int) DB::table('location_stock')->where(['product_id' => $roll, 'location_id' => $a])->value('version');

        $this->assertSame('pending', $req->request($roll, $a, 90, $version(), 'ten missing', new Actor($this->operator))['status']);

        // The lead raises the limit to 15 units: the same correction now applies by itself, and the change is on record.
        $this->assertSame(['approval_units'], $policies->update(['approval_units' => 15], $this->lead));
        $this->assertSame('approved', $req->request($roll, $a, 90, $version(), 'ten missing', new Actor($this->operator))['status']);
        $this->assertSame('approval_units', DB::table('warehouse_policy_versions')->latest('id')->value('key'));

        // Percent limit instead (units off): 5 % of 90 is 4.5
        $policies->update(['approval_percent' => 5, 'approval_units' => 0], $this->lead);
        $this->assertSame('pending', $req->request($roll, $a, 80, $version(), 'ten more', new Actor($this->operator))['status']);

        $this->expectException(InvalidMovement::class);
        $policies->update(['approval_percent' => 0], $this->lead); // would leave no limit at all
    }

    public function test_a_pending_adjustment_alerts_approvers_and_the_decision_tells_the_requester(): void
    {
        $roll = $this->ribbon();
        $a = $this->shelf('A');
        app(WarehouseOperations::class)->receive($roll, $this->mx, 50, $this->actor(), $a);
        $this->listen(P::ADJUSTMENT_REQUESTED, $this->lead);
        $req = app(AdjustmentRequests::class);

        $r = $req->request($roll, $a, 30, (int) DB::table('location_stock')->where('location_id', $a)->value('version'), 'Roll cut by mistake', new Actor($this->operator));
        $this->assertSame('pending', $r['status']);
        $this->assertCount(1, $this->alerts(P::ADJUSTMENT_REQUESTED));
        $this->assertSame(1, DB::table('warehouse_alert_recipients')->where('user_id', $this->lead)->count());

        $req->approve($r['id'], new Actor($this->lead), 'Checked on the shelf');
        $decided = $this->alerts(P::ADJUSTMENT_DECIDED);
        $this->assertCount(1, $decided);
        $this->assertSame(1, DB::table('warehouse_alert_recipients')->where(['alert_id' => $decided[0]->id, 'user_id' => $this->operator])->count(), 'the requester is told even without a subscription');
    }

    public function test_a_count_that_needs_review_is_announced_once(): void
    {
        $roll = $this->ribbon();
        $a = $this->shelf('A');
        app(WarehouseOperations::class)->receive($roll, $this->mx, 10, $this->actor(), $a);
        $this->listen(P::COUNT_REVIEW, $this->lead);
        $counts = app(CountSessions::class);
        $s = $counts->open($this->mx, 'S', new Actor($this->operator));
        $counts->submit($s, $roll, $a, 9, new Actor($this->operator));
        $counts->submit($s, $roll, $a, 7, new Actor($this->lead));

        $this->assertCount(1, $this->alerts(P::COUNT_REVIEW));
    }

    public function test_the_emails_go_only_to_those_who_asked_and_only_once(): void
    {
        $roll = $this->ribbon();
        $a = $this->shelf('A');
        $this->listen(P::STOCK_OUT, $this->operator, true);
        $this->listen(P::STOCK_OUT, $this->lead, false); // inbox only
        $ops = app(WarehouseOperations::class);
        $ops->receive($roll, $this->mx, 5, $this->actor(), $a);
        app(StockThresholds::class)->set($roll, $this->mx, null, '3', null, $this->lead);
        $ops->writeOff($roll, $a, 5, $this->actor(), 'Gone');

        $mails = DB::table('notification_log')->where('template', 'warehouse_alert')->get();
        $this->assertCount(1, $mails);
        $this->assertSame('worker@example.test', $mails[0]->email);
        $this->assertSame(2, DB::table('warehouse_alert_recipients')->count(), 'both have it in the inbox');

        $unread = app(AlertStore::class)->inbox($this->lead, true);
        $this->assertCount(1, $unread);
        $this->assertSame(1, app(AlertStore::class)->markRead($this->lead, [$unread[0]['id']]));
        $this->assertSame(0, app(AlertStore::class)->unreadCount($this->lead));
    }

    public function test_the_daily_digest_is_sent_once_a_day(): void
    {
        $this->listen(P::DAILY_DIGEST, $this->lead);
        $digest = app(DailyDigest::class);
        $this->assertNotNull($digest->send('2026-11-10'));
        $this->assertNull($digest->send('2026-11-10'));
        $this->assertNotNull($digest->send('2026-11-11'));
        $this->assertSame(2, DB::table('warehouse_alerts')->where('process', P::DAILY_DIGEST)->count());
    }
}
