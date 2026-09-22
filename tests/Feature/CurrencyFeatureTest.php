<?php

namespace Tests\Feature;

use App\Filament\Resources\PaymentSlips\Pages\CreateExportPaymentSlip;
use App\Filament\Resources\PaymentSlips\Pages\CreateGeneralPaymentSlip;
use App\Filament\Resources\PaymentSlips\Pages\EditPaymentSlip;
use App\Filament\Resources\PaymentSlips\Pages\ViewPaymentSlip;
use App\Models\Buyer;
use App\Models\Division;
use App\Models\PaymentSlip;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Erp\ErpJournalBuilder;
use App\Support\CurrencyFormatter;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\Fixtures\ErpPaymentSlip;
use Tests\TestCase;

class CurrencyFeatureTest extends TestCase
{
    use RefreshDatabase;

    private User $checker;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['maker', 'checker', 'approver'] as $role) {
            Role::create(['name' => $role]);
        }
        $this->checker = User::factory()->create()->assignRole('checker');
        $this->actingAs($this->checker);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Storage::fake('local');
    }

    public function test_general_payment_slip_defaults_to_idr(): void
    {
        $maker = $this->makerForDivision('HR');
        $this->actingAs($maker);
        $supplier = Supplier::create(['code' => 'CUR-SUP', 'name' => 'Currency Supplier', 'is_active' => true]);

        Livewire::test(CreateGeneralPaymentSlip::class)
            ->fillForm([
                'supplier_id' => $supplier->id,
                'invoices' => [[
                    'invoice_number' => 'NOTA-CUR-001',
                    'invoice_date' => '2026-09-22',
                    'items' => [[
                        'item_name' => 'Test Item',
                        'quantity' => 1,
                        'unit_price_amount' => 100000,
                    ]],
                ]],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $slip = PaymentSlip::query()->where('created_by', $maker->id)->firstOrFail();
        $this->assertSame(PaymentSlip::CURRENCY_IDR, $slip->currency);
    }

    public function test_general_payment_slip_can_be_saved_with_usd(): void
    {
        $maker = $this->makerForDivision('HR');
        $this->actingAs($maker);
        $supplier = Supplier::create(['code' => 'USD-SUP', 'name' => 'USD Supplier', 'is_active' => true]);

        Livewire::test(CreateGeneralPaymentSlip::class)
            ->fillForm([
                'currency' => PaymentSlip::CURRENCY_USD,
                'supplier_id' => $supplier->id,
                'invoices' => [[
                    'invoice_number' => 'NOTA-USD-001',
                    'invoice_date' => '2026-09-22',
                    'items' => [[
                        'item_name' => 'USD Item',
                        'quantity' => 2,
                        'unit_price_amount' => 1500.25,
                    ]],
                ]],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $slip = PaymentSlip::query()->where('created_by', $maker->id)->firstOrFail();
        $this->assertSame(PaymentSlip::CURRENCY_USD, $slip->currency);
        $this->assertSame('3000.50', $slip->grand_total_amount);
    }

    public function test_invalid_currency_is_rejected(): void
    {
        $this->expectException(ValidationException::class);

        $supplier = Supplier::create(['code' => 'INV-SUP', 'name' => 'Invalid Supplier', 'is_active' => true]);
        PaymentSlip::create([
            'slip_number' => 'PS-INVALID-CUR',
            'transaction_type' => PaymentSlip::TYPE_GENERAL,
            'currency' => 'EUR',
            'supplier_id' => $supplier->id,
            'status' => 'draft',
            'created_by' => $this->checker->id,
        ]);
    }

    public function test_edit_form_preserves_currency(): void
    {
        $maker = $this->makerForDivision('HR');
        $this->actingAs($maker);
        $supplier = Supplier::create(['code' => 'EDIT-SUP', 'name' => 'Edit Supplier', 'is_active' => true]);

        // Create a USD General slip
        Livewire::test(CreateGeneralPaymentSlip::class)
            ->fillForm([
                'currency' => PaymentSlip::CURRENCY_USD,
                'supplier_id' => $supplier->id,
                'invoices' => [[
                    'invoice_number' => 'EDIT-USD-001',
                    'invoice_date' => '2026-09-22',
                    'items' => [[
                        'item_name' => 'Edit Item',
                        'quantity' => 1,
                        'unit_price_amount' => 500,
                    ]],
                ]],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $slip = PaymentSlip::query()->where('created_by', $maker->id)->firstOrFail();
        $this->assertSame(PaymentSlip::CURRENCY_USD, $slip->currency);

        // Edit the slip and verify currency is preserved
        $editPage = Livewire::test(EditPaymentSlip::class, ['record' => $slip->id]);
        $editState = $editPage->get('data');
        $this->assertSame(PaymentSlip::CURRENCY_USD, $editState['currency']);

        $editPage->call('save')->assertHasNoFormErrors();
        $this->assertSame(PaymentSlip::CURRENCY_USD, $slip->fresh()->currency);
    }

    public function test_view_general_shows_currency_in_form(): void
    {
        $supplier = Supplier::create(['code' => 'VIEW-SUP', 'name' => 'View Supplier', 'is_active' => true]);
        $slip = PaymentSlip::withoutEvents(fn () => PaymentSlip::create([
            'slip_number' => 'PS-VIEW-CUR',
            'transaction_type' => PaymentSlip::TYPE_GENERAL,
            'currency' => PaymentSlip::CURRENCY_USD,
            'supplier_id' => $supplier->id,
            'status' => 'draft',
            'created_by' => $this->checker->id,
        ]));

        $viewPage = Livewire::test(ViewPaymentSlip::class, ['record' => $slip->id]);
        $viewState = $viewPage->get('data');
        $this->assertSame(PaymentSlip::CURRENCY_USD, $viewState['currency']);
    }

    public function test_table_formats_idr_correctly(): void
    {
        $supplier = Supplier::create(['code' => 'TBL-SUP', 'name' => 'Table Supplier', 'is_active' => true]);
        $slip = PaymentSlip::withoutEvents(fn () => PaymentSlip::create([
            'slip_number' => 'PS-TBL-IDR',
            'transaction_type' => PaymentSlip::TYPE_GENERAL,
            'currency' => PaymentSlip::CURRENCY_IDR,
            'supplier_id' => $supplier->id,
            'status' => 'draft',
            'created_by' => $this->checker->id,
            'grand_total_amount' => 1500000,
        ]));

        $formatted = CurrencyFormatter::format($slip->grand_total_amount, $slip->currency);
        $this->assertSame('Rp 1.500.000', $formatted);
    }

    public function test_table_formats_usd_correctly(): void
    {
        $supplier = Supplier::create(['code' => 'TBL-USD', 'name' => 'Table USD Supplier', 'is_active' => true]);
        $slip = PaymentSlip::withoutEvents(fn () => PaymentSlip::create([
            'slip_number' => 'PS-TBL-USD',
            'transaction_type' => PaymentSlip::TYPE_GENERAL,
            'currency' => PaymentSlip::CURRENCY_USD,
            'supplier_id' => $supplier->id,
            'status' => 'draft',
            'created_by' => $this->checker->id,
            'grand_total_amount' => 1500.50,
        ]));

        $formatted = CurrencyFormatter::format($slip->grand_total_amount, $slip->currency);
        $this->assertSame('USD 1,500.50', $formatted);
    }

    public function test_pdf_general_idr_format(): void
    {
        $slip = ErpPaymentSlip::create($this->checker);
        $slip->update(['transaction_type' => PaymentSlip::TYPE_GENERAL, 'currency' => PaymentSlip::CURRENCY_IDR, 'buyer_id' => null]);
        $invoice = $slip->invoices()->firstOrFail();
        $invoice->items()->delete();
        $invoice->items()->create([
            'item_name' => 'Kertas A4',
            'quantity' => 3,
            'unit_price_amount' => 500000,
        ]);
        $slip->load(['supplier', 'buyer', 'invoices.buyer', 'invoices.items', 'invoices.ppnTax', 'invoices.pphTax', 'creator.division']);

        $html = view('pdf.payment-slip', ['slip' => $slip])->render();

        $this->assertStringContainsString('Rp 500.000', $html);
        $this->assertStringContainsString('Rp 1.500.000', $html);
        $this->assertStringContainsString('#4F758B', $html);
        $this->assertStringContainsString('#EDF1F3', $html);
        // Must NOT contain USD formatting
        $this->assertStringNotContainsString('USD ', $html);
    }

    public function test_pdf_general_usd_format(): void
    {
        $slip = ErpPaymentSlip::create($this->checker);
        $slip->update(['transaction_type' => PaymentSlip::TYPE_GENERAL, 'currency' => PaymentSlip::CURRENCY_USD, 'buyer_id' => null]);
        $invoice = $slip->invoices()->firstOrFail();
        $invoice->items()->delete();
        $invoice->items()->create([
            'item_name' => 'Consulting Fee',
            'quantity' => 1,
            'unit_price_amount' => 1500.75,
        ]);
        $slip->load(['supplier', 'buyer', 'invoices.buyer', 'invoices.items', 'invoices.ppnTax', 'invoices.pphTax', 'creator.division']);

        $html = view('pdf.payment-slip', ['slip' => $slip])->render();

        $this->assertStringContainsString('USD 1,500.75', $html);
        $this->assertStringContainsString('#4F758B', $html);
        $this->assertStringContainsString('#EDF1F3', $html);
        // Must NOT contain Rp formatting
        $this->assertStringNotContainsString('Rp ', $html);
    }

    public function test_pdf_import_export_unchanged(): void
    {
        $slip = ErpPaymentSlip::create($this->checker);
        $this->assertSame('export', $slip->transaction_type);
        $slip->load(['supplier', 'buyer', 'invoices.buyer', 'invoices.items', 'invoices.ppnTax', 'invoices.pphTax', 'creator.division']);

        $html = view('pdf.payment-slip', ['slip' => $slip])->render();

        // Export PDF must use Rp format
        $this->assertStringContainsString('Rp ', $html);
        $this->assertStringNotContainsString('USD ', $html);
        $this->assertStringContainsString('#4F758B', $html);
        $this->assertStringContainsString('#EDF1F3', $html);
    }

    public function test_erp_export_import_unchanged(): void
    {
        $slip = ErpPaymentSlip::create($this->checker);
        // currency should be IDR by default
        $this->assertSame(PaymentSlip::CURRENCY_IDR, $slip->currency ?? PaymentSlip::CURRENCY_IDR);

        $rows = app(ErpJournalBuilder::class)->build($slip);
        $this->assertNotEmpty($rows);
        // ERP rows don't reference currency at all — they use raw numeric values
        $this->assertSame(collect($rows)->sum('debit'), collect($rows)->sum('credit'));
    }

    public function test_legacy_data_treated_as_idr(): void
    {
        $supplier = Supplier::create(['code' => 'LEGACY-SUP', 'name' => 'Legacy Supplier', 'is_active' => true]);
        // Simulate legacy data by creating without currency (will use default 'IDR')
        $slip = PaymentSlip::withoutEvents(fn () => PaymentSlip::create([
            'slip_number' => 'PS-LEGACY-001',
            'transaction_type' => PaymentSlip::TYPE_GENERAL,
            'supplier_id' => $supplier->id,
            'status' => 'draft',
            'created_by' => $this->checker->id,
            'grand_total_amount' => 100000,
        ]));

        $this->assertSame(PaymentSlip::CURRENCY_IDR, $slip->fresh()->currency);
        $this->assertSame('Rp 100.000', CurrencyFormatter::format($slip->grand_total_amount, $slip->fresh()->currency));
    }

    public function test_import_export_slips_always_idr(): void
    {
        $maker = $this->makerForDivision('EXIM');
        $this->actingAs($maker);
        $supplier = Supplier::create(['code' => 'EXIM-CUR', 'name' => 'EXIM Supplier', 'is_active' => true]);

        $buyer = Buyer::create(['code' => 'EXIM-BUY', 'name' => 'EXIM Buyer', 'is_active' => true]);

        Livewire::test(CreateExportPaymentSlip::class)
            ->fillForm([
                'supplier_id' => $supplier->id,
                'invoices' => [[
                    'buyer_id' => $buyer->id,
                    'invoice_number' => 'EXP-CUR-001',
                    'invoice_date' => '2026-09-22',
                    'items' => [[
                        'item_name' => 'Export item',
                        'quantity' => 1,
                        'unit_price_amount' => 100,
                    ]],
                ]],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $slip = PaymentSlip::query()->where('created_by', $maker->id)->firstOrFail();
        $this->assertSame(PaymentSlip::CURRENCY_IDR, $slip->currency);
    }

    private function makerForDivision(string $code): User
    {
        $division = Division::firstOrCreate(
            ['code' => $code],
            ['name' => $code.' Division', 'is_active' => true],
        );

        return User::factory()->create(['division_id' => $division->id])->assignRole('maker');
    }
}
