<?php

// Isolated MySQL concurrency probe. Never runs migrations against the application database.
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

use App\Actions\GeneratePaymentSlipPdf;
use App\Models\Division;
use App\Models\Item;
use App\Models\PaymentSlip;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Services\PurchaseOrders\FinalizePurchaseOrder;
use App\Services\PurchaseOrders\IssueGeneralPaymentSlipFromPurchaseOrder;
use App\Services\PurchaseOrders\SavePurchaseOrder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Symfony\Component\Process\Process;

$database = 'exim_po_issuance_test_20261014';
$connection = config('database.connections.mysql');
if (! in_array($connection['host'], ['127.0.0.1', 'localhost'], true) || $connection['database'] === $database) {
    throw new RuntimeException('Probe requires local MySQL and a separate application database.');
}
$child = ($argv[1] ?? null) === 'child';
config(['database.connections.po_probe' => array_replace($connection, ['database' => $database]), 'database.default' => 'po_probe', 'cache.default' => 'array']);

if ($child) {
    $user = User::query()->firstOrFail();
    auth()->login($user);
    try {
        DB::transaction(function () use ($argv, $user): void {
            $po = PurchaseOrder::query()->lockForUpdate()->findOrFail((int) $argv[2]);
            usleep(350000); // Hold the same database row while the other process attempts to acquire it.
            if (($argv[3] ?? 'issue') === 'reopen') {
                app(FinalizePurchaseOrder::class)->execute($po, $user, true);
                echo 'reopened';
            } else {
                app(IssueGeneralPaymentSlipFromPurchaseOrder::class)->execute($po, $user, ['transaction_description' => 'Concurrency QA', 'invoice_number' => 'QA-INV', 'invoice_date' => '2026-10-14']);
                echo 'issued';
            }
        });
    } catch (ValidationException) {
        echo 'rejected';
    }
    exit;
}

$admin = DB::connection('mysql');
if ($admin->select('SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = ?', [$database])) {
    throw new RuntimeException('Test database already exists; refusing to overwrite.');
}
$admin->statement('CREATE DATABASE `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
try {
    if (Artisan::call('migrate', ['--database' => 'po_probe', '--force' => true]) !== 0) {
        throw new RuntimeException(Artisan::output());
    }
    $division = Division::create(['code' => 'GA', 'name' => 'QA GA', 'is_active' => true]);
    Role::create(['name' => 'maker']);
    $user = User::factory()->create(['name' => 'Synthetic QA Maker', 'email' => 'po-qa@example.test', 'password' => bcrypt('Local-QA-only-20261014'), 'division_id' => $division->id])->assignRole('maker');
    auth()->login($user);
    $supplier = Supplier::create(['code' => 'QA', 'name' => 'Synthetic QA Supplier', 'is_active' => true]);
    $unit = Unit::create(['code' => 'PCS', 'name' => 'Pieces', 'is_active' => true]);
    $item = Item::create(['name' => 'Synthetic long product name for purchase order payment slip integration verification', 'unit_id' => $unit->id, 'is_active' => true]);
    $makePo = fn (int $count = 1) => app(SavePurchaseOrder::class)->execute([
        'supplier_id' => $supplier->id, 'pic_user_id' => $user->id, 'po_date' => '2026-10-14', 'currency' => 'USD', 'status' => 'new', 'discount_amount' => 3.5, 'shipping_amount' => 7.25,
        'items' => array_fill(0, $count, ['item_id' => $item->id, 'specification' => '100 x 200 mm', 'unit_id' => $unit->id, 'quantity' => 2.5, 'unit_price_amount' => 12.55]),
    ], $user);
    $runRace = function (PurchaseOrder $po, string $second) use ($user): array {
        app(FinalizePurchaseOrder::class)->execute($po, $user);
        $a = new Process([PHP_BINARY, __FILE__, 'child', (string) $po->id, 'issue']);
        $b = new Process([PHP_BINARY, __FILE__, 'child', (string) $po->id, $second]);
        $a->start();
        $b->start();
        $a->wait();
        $b->wait();
        if (! $a->isSuccessful() || ! $b->isSuccessful()) {
            throw new RuntimeException($a->getErrorOutput().$b->getErrorOutput().$a->getOutput().$b->getOutput());
        }

        return [trim($a->getOutput()), trim($b->getOutput())];
    };
    $po = $makePo();
    $results = $runRace($po, 'issue');
    sort($results);
    if ($results !== ['issued', 'rejected'] || $po->invoices()->count() !== 1 || PaymentSlip::count() !== 1) {
        throw new RuntimeException('Duplicate issuance race failed: '.json_encode($results));
    }
    $secondPo = $makePo();
    $race = $runRace($secondPo, 'reopen');
    $count = $secondPo->invoices()->count();
    if (! (($race === ['issued', 'rejected'] && $count === 1 && $secondPo->fresh()->finalized_at) || ($race === ['rejected', 'reopened'] && $count === 0 && ! $secondPo->fresh()->finalized_at))) {
        throw new RuntimeException('Reopen/issue race failed: '.json_encode($race));
    }
    $largePo = $makePo(50);
    app(FinalizePurchaseOrder::class)->execute($largePo, $user);
    $slip = app(IssueGeneralPaymentSlipFromPurchaseOrder::class)->execute($largePo, $user, ['transaction_description' => 'Synthetic QA 50 items', 'invoice_number' => 'QA-LARGE-INV', 'invoice_date' => '2026-10-14']);
    $folder = base_path('output/qa-po-general-integration');
    if (! is_dir($folder)) {
        mkdir($folder, 0777, true);
    }
    file_put_contents($folder.'/general-50-items.pdf', app(GeneratePaymentSlipPdf::class)->execute($slip)->output());
    $previewPo = $makePo(2);
    app(FinalizePurchaseOrder::class)->execute($previewPo, $user);
    echo json_encode(['duplicate_race' => $results, 'reopen_race' => $race, 'large_slip_total' => $slip->grand_total_amount, 'pdf' => $folder.'/general-50-items.pdf'], JSON_PRETTY_PRINT).PHP_EOL;
} finally {
    DB::purge('po_probe');
    // Exact, newly-created disposable test database only; never the application database.
    if (! in_array('--keep-for-browser', $argv, true)) {
        $admin->statement('DROP DATABASE `exim_po_issuance_test_20261014`');
    }
}
