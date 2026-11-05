<?php

// One contender in the warehouse race test: php warehouse_race_worker.php '<json job>'
// Prints one JSON line with what it managed to do. Several of these run at the same time against one real database.
use Cronos\Inventory\Application\Actor;
use Cronos\Inventory\Application\AdjustmentRequests;
use Cronos\Inventory\Application\LocationLedger;
use Cronos\Inventory\Application\PickLists;
use Cronos\Inventory\Application\ReservationService;
use Cronos\Inventory\Application\WarehouseOperations;
use Cronos\Shared\Domain\DomainError;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$job = json_decode($argv[1], true, 512, JSON_THROW_ON_ERROR);
$me = $job['name'];
$actor = new Actor(str_pad($me, 26, '0', STR_PAD_LEFT));
$done = ['role' => $job['role'], 'ok' => 0, 'refused' => 0, 'deadlocks' => 0, 'other' => []];
$track = function (callable $f) use (&$done): void {
    try {
        $f();
        $done['ok']++;
    } catch (DomainError) {
        $done['refused']++;
    } catch (Throwable $e) {
        str_contains($e->getMessage(), 'deadlock') ? $done['deadlocks']++ : $done['other'][] = substr($e->getMessage(), 0, 200);
        str_contains($e->getMessage(), 'deadlock') && $done['detail'] = substr($e->getMessage(), 0, 600);
    }
};

for ($i = 0; $i < $job['rounds']; $i++) {
    switch ($job['role']) {
        case 'buyer': // the customer path: reserve, then pay (consume)
            $holder = "order-{$me}-{$i}";
            $track(function () use ($job, $holder, $actor) {
                app(ReservationService::class)->reserve('order', $holder, [['product_id' => $job['product'], 'quantity' => $job['qty'] ?? 1]], 15);
                app(ReservationService::class)->consume('order', $holder, $holder, $actor);
            });
            break;
        case 'receiver':
            $track(fn () => app(WarehouseOperations::class)->receive($job['product'], $job['warehouse'], 1, $actor, $job['shelves'][$i % count($job['shelves'])], null, "rcv-{$me}-{$i}"));
            break;
        case 'mover':
            [$from, $to] = $i % 2 === 0 ? $job['shelves'] : array_reverse($job['shelves']);
            $track(fn () => app(LocationLedger::class)->move($job['product'], $from, $to, 1, $actor, "mv-{$me}-{$i}"));
            break;
        case 'picker':
            foreach (app(PickLists::class)->pending($job['warehouse'], 5) as $line) {
                foreach ($job['shelves'] as $shelf) {
                    $track(fn () => app(PickLists::class)->pick($line['order_id'], $line['product_id'], $shelf, 1, $actor, "pk-{$me}-{$i}-{$line['order_id']}-{$shelf}"));
                }
            }
            break;
        case 'adjuster':
            $shelf = $job['shelves'][0];
            $row = DB::table('location_stock')->where(['product_id' => $job['product'], 'location_id' => $shelf])->first();
            $track(fn () => app(AdjustmentRequests::class)->request($job['product'], $shelf, (float) $row->quantity + ($i % 2 === 0 ? 1 : -1), (int) $row->version, 'race', $actor, "adj-{$me}-{$i}"));
            break;
    }
}
echo json_encode($done)."\n";
