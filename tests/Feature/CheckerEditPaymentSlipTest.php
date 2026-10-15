<?php

namespace Tests\Feature;

use App\Filament\Widgets\PaymentSlipQueueTable;
use App\Models\ChartOfAccount;
use App\Models\DocumentFile;
use App\Models\InvoiceItem;
use App\Models\PaymentSlip;
use App\Models\PaymentSlipAudit;
use App\Models\User;
use App\Support\CheckerEditableFields;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\Fixtures\ErpPaymentSlip;
use Tests\TestCase;

class CheckerEditPaymentSlipTest extends TestCase
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
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_guest_checker_page_redirects_to_panel_login(): void
    {
        $slip = $this->submittedSlip();
        auth()->logout();
        $this->get(route('payment-slips.checker-edit', $slip))
            ->assertRedirect(route('filament.admin.auth.login'));
    }

    public function test_dashboard_queue_uses_the_checker_page_for_submitted_slips(): void
    {
        $slip = $this->submittedSlip();

        $this->actingAs($this->checker);

        Livewire::test(PaymentSlipQueueTable::class)
            ->assertSee(route('payment-slips.checker-edit', $slip), false);
    }

    public function test_checker_can_save_invoice_vat_item_vat_and_coa_with_snapshots(): void
    {
        $slip = $this->submittedSlip();
        $invoice = $slip->invoices()->firstOrFail();
        $item = $invoice->items()->firstOrFail();
        $coa = ChartOfAccount::create(['code' => '55500001', 'name' => 'Checker selected COA', 'is_active' => true]);

        $this->actingAs($this->checker)
            ->post(route('payment-slips.checker-edit.update', $slip), [
                'invoices' => [$invoice->id => ['vat_invoice_number' => 'VAT-MAIN-001']],
                'items' => [$item->id => ['vat_invoice_number' => 'VAT-ITEM-001', 'coa_id' => $coa->id]],
            ])
            ->assertRedirect(route('payment-slips.checker-edit', $slip))
            ->assertSessionHasNoErrors();

        $this->assertSame('VAT-MAIN-001', $invoice->fresh()->vat_invoice_number);
        $this->assertSame('VAT-ITEM-001', $item->fresh()->vat_invoice_number);
        $this->assertSame($coa->id, $item->fresh()->coa_id);
        $this->assertSame('55500001', $item->fresh()->coa_code_snapshot);
        $this->assertSame('Checker selected COA', $item->fresh()->coa_name_snapshot);
    }

    public function test_checker_edit_creates_an_audit_entry(): void
    {
        $slip = $this->submittedSlip();
        $invoice = $slip->invoices()->firstOrFail();

        $this->actingAs($this->checker)
            ->post(route('payment-slips.checker-edit.update', $slip), [
                'invoices' => [$invoice->id => ['vat_invoice_number' => 'VAT-AUDITED']],
            ])
            ->assertRedirect();

        $audit = PaymentSlipAudit::query()->where('event', 'checker_edited')->sole();
        $this->assertSame($slip->id, $audit->payment_slip_id);
        $this->assertSame($this->checker->id, $audit->user_id);
        $this->assertNull($audit->old_values['invoices'][$invoice->id]['vat_invoice_number']);
        $this->assertSame('VAT-AUDITED', $audit->new_values['invoices'][$invoice->id]['vat_invoice_number']);
    }

    public function test_maker_cannot_open_or_submit_the_checker_page(): void
    {
        $slip = $this->submittedSlip();
        $invoice = $slip->invoices()->firstOrFail();
        $maker = User::factory()->create()->assignRole('maker');

        $this->actingAs($maker)
            ->get(route('payment-slips.checker-edit', $slip))
            ->assertForbidden();

        $this->post(route('payment-slips.checker-edit.update', $slip), [
            'invoices' => [$invoice->id => ['vat_invoice_number' => 'FORBIDDEN']],
        ])->assertForbidden();

        $this->assertNull($invoice->fresh()->vat_invoice_number);
    }

    public function test_checker_cannot_edit_approved_or_exported_slips(): void
    {
        $slip = $this->submittedSlip();
        $this->actingAs($this->checker);

        foreach (['approved', 'exported'] as $status) {
            $slip->update(['status' => $status]);

            $this->get(route('payment-slips.checker-edit', $slip))->assertForbidden();
            $this->post(route('payment-slips.checker-edit.update', $slip), [])->assertForbidden();
        }
    }

    public function test_item_from_another_slip_is_rejected_without_changes(): void
    {
        $slip = $this->submittedSlip();
        $otherItem = $this->itemForAnotherSubmittedSlip($slip);
        $originalVat = $otherItem->vat_invoice_number;
        $originalCoa = $otherItem->coa_id;

        $this->actingAs($this->checker)
            ->post(route('payment-slips.checker-edit.update', $slip), [
                'items' => [$otherItem->id => ['vat_invoice_number' => 'TAMPERED', 'coa_id' => null]],
            ])
            ->assertNotFound();

        $this->assertSame($originalVat, $otherItem->fresh()->vat_invoice_number);
        $this->assertSame($originalCoa, $otherItem->fresh()->coa_id);
        $this->assertDatabaseMissing('payment_slip_audits', ['payment_slip_id' => $slip->id, 'event' => 'checker_edited']);
    }

    public function test_fields_outside_the_checker_allowlist_are_ignored(): void
    {
        $slip = $this->submittedSlip();
        $item = $slip->invoices()->firstOrFail()->items()->firstOrFail();
        $originalQuantity = $item->quantity;

        $this->actingAs($this->checker)
            ->post(route('payment-slips.checker-edit.update', $slip), [
                'items' => [$item->id => [
                    'quantity' => 999,
                    'vat_invoice_number' => 'VAT-ALLOWED',
                    'coa_id' => $item->coa_id,
                ]],
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame($originalQuantity, $item->fresh()->quantity);
        $this->assertSame('VAT-ALLOWED', $item->fresh()->vat_invoice_number);
    }

    public function test_page_is_plain_blade_without_livewire_alpine_or_filament_form_components(): void
    {
        $slip = $this->submittedSlip();

        $response = $this->actingAs($this->checker)
            ->get(route('payment-slips.checker-edit', $slip))
            ->assertOk()
            ->assertSee('Accounting verification')
            ->assertSee('VAT Invoice No. Utama')
            ->assertSee('VAT Invoice No. (Item)')
            ->assertSee('data-checker-mode="split"', false)
            ->assertSee('data-checker-divider', false)
            ->assertSee('Overview')->assertSee('No. Invoice:')
            ->assertDontSee('data-ps-extract', false)
            ->assertSee('Save checker fields');

        $html = $response->getContent();
        $this->assertStringNotContainsString('wire:', $html);
        $this->assertStringNotContainsString('x-data', $html);
        $this->assertSame(1, substr_count($html, '<form'));
    }

    public function test_checker_preview_is_available_only_for_an_existing_invoice_document(): void
    {
        Storage::fake('local');
        $slip = $this->submittedSlip();
        $invoice = $slip->invoices()->firstOrFail();
        $document = DocumentFile::create([
            'disk' => 'local', 'path' => 'qa/invoice.pdf', 'original_name' => 'invoice.pdf',
            'mime_type' => 'application/pdf', 'size_bytes' => 10, 'uploaded_at' => now(),
        ]);
        $invoice->update(['document_file_id' => $document->id]);
        $this->actingAs($this->checker)->get(route('payment-slips.checker-edit', $slip))
            ->assertOk()->assertDontSee('data-checker-preview=', false);
        Storage::disk('local')->put('qa/invoice.pdf', '%PDF-1.4');
        $this->get(route('payment-slips.checker-edit', $slip))
            ->assertOk()->assertSee('data-checker-preview=', false)
            ->assertSee(route('document-files.view', $document), false);
    }

    public function test_type_specific_checker_policy_preserves_general_import_and_legacy_export_item_vat_rules(): void
    {
        $this->actingAs($this->checker);

        $slip = $this->submittedSlip();
        $slip->update(['transaction_type' => PaymentSlip::TYPE_GENERAL]);
        $invoice = $slip->invoices()->firstOrFail();
        $this->assertTrue(CheckerEditableFields::canEditInvoiceVat($slip));
        $this->assertTrue(CheckerEditableFields::canEditItemCoa($slip));
        $this->assertFalse(CheckerEditableFields::canEditItemVat($slip, $invoice));

        $slip->update(['transaction_type' => PaymentSlip::TYPE_IMPORT]);
        $this->assertTrue(CheckerEditableFields::canEditItemVat($slip, $invoice));

        $slip->update(['transaction_type' => PaymentSlip::TYPE_EXPORT]);
        $invoice->update(['tax_calculation_mode' => PaymentSlip::TAX_MODE_INVOICE_LEGACY]);
        $this->assertFalse(CheckerEditableFields::canEditItemVat($slip, $invoice->fresh()));

        $invoice->update(['tax_calculation_mode' => PaymentSlip::TAX_MODE_ITEMIZED]);
        $this->assertTrue(CheckerEditableFields::canEditItemVat($slip, $invoice->fresh()));
    }

    private function submittedSlip(): PaymentSlip
    {
        $slip = ErpPaymentSlip::create($this->checker, PaymentSlip::TYPE_IMPORT);
        $slip->update(['status' => 'submitted', 'approved_by' => null, 'approved_at' => null]);

        return $slip->fresh();
    }

    private function itemForAnotherSubmittedSlip(PaymentSlip $firstSlip): InvoiceItem
    {
        return DB::transaction(function () use ($firstSlip): InvoiceItem {
            $otherSlipId = DB::table('payment_slips')->insertGetId([
                'slip_number' => 'PS-OTHER-CHECKER',
                'transaction_type' => PaymentSlip::TYPE_IMPORT,
                'currency' => PaymentSlip::CURRENCY_IDR,
                'tax_calculation_mode' => PaymentSlip::TAX_MODE_ITEMIZED,
                'supplier_id' => $firstSlip->supplier_id,
                'buyer_id' => $firstSlip->buyer_id,
                'status' => 'submitted',
                'subtotal_amount' => 10,
                'tax_addition_amount' => 0,
                'tax_deduction_amount' => 0,
                'grand_total_amount' => 10,
                'created_by' => $this->checker->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $invoiceId = DB::table('invoices')->insertGetId([
                'payment_slip_id' => $otherSlipId,
                'invoice_number' => 'OTHER-INV-001',
                'invoice_date' => '2026-09-25',
                'tax_calculation_mode' => PaymentSlip::TAX_MODE_ITEMIZED,
                'subtotal_amount' => 10,
                'tax_addition_amount' => 0,
                'tax_deduction_amount' => 0,
                'grand_total_amount' => 10,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $itemId = DB::table('invoice_items')->insertGetId([
                'invoice_id' => $invoiceId,
                'line_number' => 1,
                'item_name' => 'Other item',
                'quantity' => 1,
                'unit_price_amount' => 10,
                'subtotal_amount' => 10,
                'coa_id' => null,
                'tax_addition_amount' => 0,
                'tax_deduction_amount' => 0,
                'net_amount' => 10,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return InvoiceItem::findOrFail($itemId);
        });
    }
}
