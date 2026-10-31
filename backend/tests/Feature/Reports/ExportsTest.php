<?php

namespace Tests\Feature\Reports;

use Cronos\Notifications\Infrastructure\Mail\ShopMail;
use Cronos\Reports\Application\ExportService;
use Cronos\Reports\Domain\Exceptions\ReportError;
use Cronos\Reports\Domain\Ports\ExportWriter;
use Cronos\Reports\Infrastructure\Jobs\RunExportJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use OpenSpout\Reader\XLSX\Reader;
use Tests\Feature\Orders\OrdersTestCase;

final class ExportsTest extends OrdersTestCase
{
    private string $admin;

    private string $adminId;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->makeUser('boss@example.com', 'admin');
        $this->adminId = (string) DB::table('users')->where('email', 'boss@example.com')->value('id');
        $this->admin = $this->adminToken('chief@example.com', 'super-admin');
        $this->adminId = (string) DB::table('users')->where('email', 'chief@example.com')->value('id');
    }

    private function service(): ExportService
    {
        return app(ExportService::class);
    }

    /** @return list<list<mixed>> every row of the first sheet */
    private function sheet(string $path): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'x').'.xlsx';
        file_put_contents($tmp, Storage::disk('local')->get($path));
        $reader = new Reader;
        $reader->open($tmp);
        $rows = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $rows[] = $row->toArray();
            }
            break;
        }
        $reader->close();
        unlink($tmp);

        return $rows;
    }

    private function build(string $type, array $filters = []): array
    {
        $job = $this->service()->request($this->adminId, $type, $filters, 'America/Mexico_City');
        $this->service()->run($job['id']);

        return $this->service()->get($job['id']);
    }

    public function test_an_orders_spreadsheet_is_built_in_the_background_with_local_time_and_exact_money(): void
    {
        $bow = $this->stocked($this->published($this->piece(price: '100.00')), 50);
        $order = $this->placeOrder($bow, '2');
        $job = $this->service()->request($this->adminId, 'orders', [], 'America/Mexico_City');
        Queue::assertPushed(RunExportJob::class, fn ($j) => $j->exportId === $job['id']);
        $this->assertSame('queued', $job['status']);

        $this->service()->run($job['id']);
        $done = $this->service()->get($job['id']);
        $this->assertSame(['ready', 1, false], [$done['status'], $done['row_count'], $done['truncated']]);
        $this->assertNotNull($done['expires_at']);

        [$header, $row] = $this->sheet($done['file_path']);
        $this->assertSame(['Pedido', 'Fecha (hora local)', 'Fecha (UTC)'], array_slice($header, 0, 3));
        $this->assertSame([$order['order_number'], 'MX', 'MXN', 'processing', 'paid'], [$row[0], $row[3], $row[4], $row[5], $row[6]]);
        $this->assertEquals([200.0, 150.0, 27.59, 350.0], [$row[9], $row[11], $row[12], $row[13]]);
        $utc = new \DateTimeImmutable($row[2], new \DateTimeZone('UTC'));
        $this->assertSame($utc->setTimezone(new \DateTimeZone('America/Mexico_City'))->format('Y-m-d H:i:s'), $row[1], 'the local column is the requester\'s clock');
    }

    public function test_filters_pick_the_store_dates_and_status_and_each_type_has_its_own_columns(): void
    {
        $bow = $this->stocked($this->published($this->piece(price: '100.00')), 50);
        $this->placeOrder($bow, '1', null, 'a');
        $b = $this->placeOrder($bow, '1', null, 'b');
        DB::table('orders')->where('id', $b['id'])->update(['created_at' => '2026-01-15 12:00:00', 'status' => 'shipped']);
        DB::table('orders')->insert(['id' => (string) Str::ulid(), 'order_number' => 'CR-US1', 'order_type' => 'standard', 'email' => 'bob@x.com', 'market_code' => 'US', 'currency' => 'USD', 'tax_included' => false,
            'status' => 'processing', 'payment_status' => 'paid', 'fulfillment_status' => 'unfulfilled', 'subtotal' => 10, 'discount_total' => 0, 'shipping_total' => 0, 'tax_total' => 0, 'grand_total' => 10, 'created_at' => now(), 'updated_at' => now()]);

        $this->assertSame(3, $this->build('orders')['row_count']);
        $this->assertSame(1, $this->build('orders', ['market' => 'us'])['row_count']);
        $this->assertSame(1, $this->build('orders', ['from' => '2026-01-01', 'to' => '2026-01-31'])['row_count']);
        $this->assertSame(1, $this->build('orders', ['status' => 'shipped'])['row_count']);

        $items = $this->sheet($this->build('order_items', ['market' => 'MX'])['file_path']);
        $this->assertSame(['SKU', 'Producto', 'Unidad', 'Cantidad'], array_slice($items[0], 4, 4));
        $this->assertSame(3, count($items), 'header + the two MX lines');
        $this->assertSame(2, $this->build('payments')['row_count']);
        $this->assertGreaterThan(0, $this->build('stock')['row_count']);
        $this->assertSame(0, $this->build('custom_orders')['row_count']);
    }

    public function test_text_that_looks_like_a_formula_is_neutralised(): void
    {
        $bow = $this->stocked($this->published($this->piece(price: '100.00')), 50);
        $order = $this->placeOrder($bow, '1');
        DB::table('orders')->where('id', $order['id'])->update(['email' => '=HYPERLINK("http://evil.test","x")', 'coupon_code' => '-2+3']);

        $row = $this->sheet($this->build('orders')['file_path'])[1];
        $this->assertSame('\'=HYPERLINK("http://evil.test","x")', $row[8]);
        $this->assertSame("'-2+3", $row[15]);
    }

    public function test_a_double_click_is_one_export_and_nobody_can_pile_up_more_than_three(): void
    {
        $a = $this->service()->request($this->adminId, 'orders', ['market' => 'MX'], 'UTC');
        $again = $this->service()->request($this->adminId, 'orders', ['market' => 'mx'], 'UTC');
        $this->assertSame($a['id'], $again['id']);
        Queue::assertPushedTimes(RunExportJob::class, 1);

        $this->service()->request($this->adminId, 'payments', [], 'UTC');
        $this->service()->request($this->adminId, 'stock', [], 'UTC');
        try {
            $this->service()->request($this->adminId, 'custom_orders', [], 'UTC');
            $this->fail('a fourth live export must be refused');
        } catch (ReportError $e) {
            $this->assertSame('TOO_MANY_EXPORTS', $e->errorCode());
        }
        foreach ([['nope', []], ['orders', ['from' => '2026-02-30']], ['orders', ['from' => '2026-03-01', 'to' => '2026-02-01']], ['orders', ['market' => 'x y']]] as [$type, $filters]) {
            try {
                $this->service()->request($this->adminId, $type, $filters, 'UTC');
                $this->fail('invalid export accepted');
            } catch (ReportError $e) {
                $this->assertSame('INVALID_EXPORT', $e->errorCode());
            }
        }
    }

    public function test_the_file_is_only_reachable_through_a_signed_link_which_is_counted_and_dies_with_the_file(): void
    {
        $this->placeOrder($this->stocked($this->published($this->piece()), 10), '1');
        $job = $this->build('orders');

        $this->get('/exports/'.$job['id'].'/download')->assertForbidden();
        $this->get(url()->temporarySignedRoute('exports.download', now()->subMinute(), ['export' => $job['id']]))->assertForbidden();

        $signed = url()->temporarySignedRoute('exports.download', now()->addHour(), ['export' => $job['id']]);
        $r = $this->get($signed)->assertOk();
        $this->assertStringContainsString('spreadsheetml', $r->headers->get('Content-Type'));
        $this->assertStringContainsString('orders-', $r->headers->get('Content-Disposition'));
        $this->assertSame(1, (int) DB::table('export_jobs')->where('id', $job['id'])->value('download_count'));

        DB::table('export_jobs')->where('id', $job['id'])->update(['expires_at' => now()->subMinute()]);
        $this->assertSame(1, $this->service()->purge());
        $this->assertFalse(Storage::disk('local')->exists($job['file_path']));
        $this->get($signed)->assertStatus(410);
        $this->assertSame('expired', DB::table('export_jobs')->where('id', $job['id'])->value('status'), 'the row stays: it records who exported what');
    }

    public function test_the_requester_is_e_mailed_the_link_once_and_a_failed_build_is_recorded(): void
    {
        $job = $this->build('stock');
        $mail = DB::table('notification_log')->where('template', 'export_ready')->first();
        $this->assertSame(['chief@example.com', $mail->status === 'sent' ? 'x' : 'queued'], [$mail->email, 'queued']);
        $data = json_decode($mail->data, true);
        $this->assertStringContainsString('/exports/'.$job['id'].'/download?', $data['url']);
        $this->assertStringContainsString('signature=', $data['url']);

        $this->service()->run($job['id']); // already ready: nothing happens, no second e-mail
        $this->assertSame(1, DB::table('notification_log')->where('template', 'export_ready')->count());
        $rendered = (new ShopMail('export_ready', 'es-MX', $data))->render();
        $this->assertStringContainsString('Existencias', $rendered);

        $this->app->bind(ExportWriter::class, fn () => new class implements ExportWriter
        {
            public function write(string $path, string $sheet, array $headers, iterable $rows, int $maxRows): array
            {
                throw new \RuntimeException('disk full');
            }
        });
        $bad = $this->service()->request($this->adminId, 'payments', [], 'UTC');
        try {
            app(ExportService::class)->run($bad['id']);
            $this->fail('the failure must surface to the queue');
        } catch (\RuntimeException) {
            $this->assertSame(['failed', 1], [DB::table('export_jobs')->where('id', $bad['id'])->value('status'), (int) DB::table('export_jobs')->where('id', $bad['id'])->value('attempts')]);
            $this->assertStringContainsString('disk full', (string) DB::table('export_jobs')->where('id', $bad['id'])->value('error'));
        }
    }

    public function test_a_worker_that_died_leaves_nothing_stuck_and_big_sheets_stop_at_the_limit(): void
    {
        $job = $this->service()->request($this->adminId, 'orders', [], 'UTC');
        DB::table('export_jobs')->where('id', $job['id'])->update(['status' => 'running', 'attempts' => 1, 'updated_at' => now()->subHour()]);
        Queue::fake();
        $this->assertSame(1, $this->service()->recoverStuck());
        $this->assertSame('queued', DB::table('export_jobs')->where('id', $job['id'])->value('status'));
        Queue::assertPushed(RunExportJob::class);

        DB::table('export_jobs')->where('id', $job['id'])->update(['status' => 'running', 'attempts' => 3, 'updated_at' => now()->subHour()]);
        $this->service()->recoverStuck();
        $this->assertSame('failed', DB::table('export_jobs')->where('id', $job['id'])->value('status'));

        $tmp = tempnam(sys_get_temp_dir(), 'w');
        $result = app(ExportWriter::class)->write($tmp, 'Test', ['a'], (function () {
            foreach (range(1, 10) as $i) {
                yield [$i];
            }
        })(), 4);
        unlink($tmp);
        $this->assertSame(['rows' => 4, 'truncated' => true], $result);
    }

    public function test_only_staff_who_may_export_can_ask_and_they_see_only_their_own_exports(): void
    {
        $q = 'mutation($t: ExportType!, $f: ExportFilters) { requestExport(type: $t, filters: $f) { id type status filters downloadUrl } }';
        $this->adminGql($q, ['t' => 'ORDERS', 'f' => ['market' => 'mx']], $this->admin)->assertJsonPath('data.requestExport.type', 'ORDERS')->assertJsonPath('data.requestExport.status', 'queued')->assertJsonPath('data.requestExport.downloadUrl', null);

        $support = $this->adminToken('help@example.com', 'support');
        $this->adminGql($q, ['t' => 'ORDERS'], $support)->assertJsonPath('errors.0.message', fn ($m) => str_contains($m, 'unauthorized'));
        $this->adminGql('{ adminExports { id } }', [], $support)->assertJsonPath('errors.0.message', fn ($m) => str_contains($m, 'unauthorized'));

        $other = $this->adminToken('other@example.com', 'super-admin');
        $this->adminGql('{ adminExports { id } }', [], $other)->assertJsonPath('data.adminExports', []);
        $mine = $this->adminGql('{ adminExports { id status } }', [], $this->admin)->json('data.adminExports');
        $this->assertCount(1, $mine);

        $this->service()->run($mine[0]['id']);
        $url = $this->adminGql('{ adminExports { downloadUrl rowCount } }', [], $this->admin)->json('data.adminExports.0.downloadUrl');
        $this->assertStringContainsString('signature=', $url);
        $this->get($url)->assertOk();
    }
}
