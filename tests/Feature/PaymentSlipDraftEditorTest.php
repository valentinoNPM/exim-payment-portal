<?php

namespace Tests\Feature;

use App\Filament\Resources\PaymentSlips\Pages\EditPaymentSlip;
use App\Filament\Resources\PaymentSlips\PaymentSlipResource;
use App\Models\Buyer;
use App\Models\Division;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\PaymentSlip;
use App\Models\Supplier;
use App\Models\Tax;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PaymentSlipDraftEditorTest extends TestCase
{
    use RefreshDatabase;

    private User $maker;

    private Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        Role::create(['name' => 'maker']);
        $division = Division::create(['code' => 'EXIM', 'name' => 'EXIM']);
        $this->maker = User::factory()->create(['division_id' => $division->id])->assignRole('maker');
        $this->supplier = Supplier::create(['code' => 'SUP-EDITOR', 'name' => 'Supplier Editor']);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($this->maker);
    }

    public function test_draft_editor_saves_item_changes_and_recalculates_all_totals_for_general_import_and_export_slips(): void
    {
        $ppn = Tax::create([
            'code' => 'PPN-EDITOR-10',
            'name' => 'PPN 10%',
            'rate' => 10,
            'calculation_type' => 'addition',
            'is_active' => true,
        ]);
        $pph = Tax::create([
            'code' => 'PPH-EDITOR-2',
            'name' => 'PPh 2%',
            'rate' => 2,
            'calculation_type' => 'deduction',
            'is_active' => true,
        ]);

        foreach ([PaymentSlip::TYPE_GENERAL, PaymentSlip::TYPE_IMPORT, PaymentSlip::TYPE_EXPORT] as $type) {
            [$slip, $firstInvoice, $firstItem, $secondInvoice] = $this->draftSlip($type);
            $buyer = Buyer::create(['code' => 'BUYER-EDITOR-'.$type, 'name' => 'Buyer Editor']);

            $response = $this->from(PaymentSlipResource::getUrl('edit', ['record' => $slip]))
                ->put(route('payment-slips.editor.update', $slip), [
                    'currency' => $type === PaymentSlip::TYPE_GENERAL ? PaymentSlip::CURRENCY_IDR : null,
                    'transaction_description' => $type === PaymentSlip::TYPE_GENERAL ? 'Biaya editor' : null,
                    'invoice_receipt_number' => null,
                    'supplier_id' => $this->supplier->id,
                    'invoices' => [
                        [
                            'id' => $firstInvoice->id,
                            'buyer_id' => $buyer->id,
                            'invoice_number' => $firstInvoice->invoice_number,
                            'invoice_date' => $firstInvoice->invoice_date->toDateString(),
                            'tax_calculation_mode' => PaymentSlip::TAX_MODE_ITEMIZED,
                            'items' => [
                                [
                                    'id' => $firstItem->id,
                                    'item_name' => $firstItem->item_name,
                                    'quantity' => 2,
                                    'unit_price_amount' => 125,
                                    'ppn_tax_id' => $ppn->id,
                                    'pph_tax_id' => $pph->id,
                                ],
                                [
                                    'id' => $firstInvoice->items[1]->id,
                                    'item_name' => $firstInvoice->items[1]->item_name,
                                    'quantity' => 1,
                                    'unit_price_amount' => 50,
                                ],
                            ],
                        ],
                        [
                            'id' => $secondInvoice->id,
                            'buyer_id' => $buyer->id,
                            'invoice_number' => $secondInvoice->invoice_number,
                            'invoice_date' => $secondInvoice->invoice_date->toDateString(),
                            'tax_calculation_mode' => PaymentSlip::TAX_MODE_ITEMIZED,
                            'items' => [[
                                'id' => $secondInvoice->items[0]->id,
                                'item_name' => $secondInvoice->items[0]->item_name,
                                'quantity' => 3,
                                'unit_price_amount' => 40,
                            ]],
                        ],
                    ],
                ]);

            $response
                ->assertRedirect(PaymentSlipResource::getUrl('edit', ['record' => $slip]))
                ->assertSessionHasNoErrors();

            $this->assertSame('2.0000', $firstItem->fresh()->quantity, "Quantity was not saved for {$type}.");
            $this->assertSame('250.00', $firstItem->fresh()->subtotal_amount, "Item subtotal was not recalculated for {$type}.");
            $this->assertSame('270.00', $firstItem->fresh()->net_amount, "Item net amount was not recalculated for {$type}.");
            $this->assertSame('300.00', $firstInvoice->fresh()->subtotal_amount, "Invoice subtotal was not recalculated for {$type}.");
            $this->assertSame('320.00', $firstInvoice->fresh()->grand_total_amount, "Invoice total was not recalculated for {$type}.");
            $this->assertSame('420.00', $slip->fresh()->subtotal_amount, "Slip subtotal was not recalculated for {$type}.");
            $this->assertSame('440.00', $slip->fresh()->grand_total_amount, "Slip total was not recalculated for {$type}.");
        }
    }

    public function test_filament_editor_validation_failure_does_not_change_exim_item(): void
    {
        [$slip, $invoice, $item] = $this->draftSlip(PaymentSlip::TYPE_EXPORT);
        $component = Livewire::test(EditPaymentSlip::class, ['record' => $slip->getRouteKey()]);
        $invoiceKey = array_key_first($component->get('data.invoices'));
        $itemKey = array_key_first($component->get("data.invoices.{$invoiceKey}.items"));

        $component
            ->set("data.invoices.{$invoiceKey}.items.{$itemKey}.quantity", null)
            ->call('save')
            ->assertHasErrors(["data.invoices.{$invoiceKey}.items.{$itemKey}.quantity"]);

        $this->assertSame('1.0000', $item->fresh()->quantity);
        $this->assertSame('100.00', $item->fresh()->subtotal_amount);
        $this->assertSame('250.00', $slip->fresh()->grand_total_amount);
    }

    public function test_submit_header_action_saves_and_submits_the_slip(): void
    {
        [$slip] = $this->draftSlip(PaymentSlip::TYPE_EXPORT);
        $buyer = Buyer::create(['code' => 'BUYER-SUBMIT', 'name' => 'Buyer Submit']);
        $slip->invoices()->update(['buyer_id' => $buyer->id]);

        Livewire::test(EditPaymentSlip::class, ['record' => $slip->getRouteKey()])
            ->callAction('submit_slip')
            ->assertHasNoActionErrors();

        $this->assertSame('submitted', $slip->fresh()->status);
        $this->assertNotNull($slip->fresh()->submitted_at);
    }

    /** @return array{PaymentSlip, Invoice, InvoiceItem, Invoice} */
    private function draftSlip(string $type): array
    {
        $slip = PaymentSlip::create([
            'slip_number' => 'PS-EDITOR-'.strtoupper($type).'-'.uniqid(),
            'transaction_type' => $type,
            'transaction_description' => $type === PaymentSlip::TYPE_GENERAL ? 'Biaya awal' : null,
            'invoice_receipt_number' => null,
            'currency' => PaymentSlip::CURRENCY_IDR,
            'tax_calculation_mode' => PaymentSlip::TAX_MODE_ITEMIZED,
            'supplier_id' => $this->supplier->id,
            'created_by' => $this->maker->id,
            'status' => 'draft',
        ]);
        $firstInvoice = Invoice::create([
            'payment_slip_id' => $slip->id,
            'invoice_number' => 'INV-FIRST-'.$type,
            'invoice_date' => today(),
            'tax_calculation_mode' => PaymentSlip::TAX_MODE_ITEMIZED,
        ]);
        $firstItem = InvoiceItem::create([
            'invoice_id' => $firstInvoice->id,
            'item_name' => 'First item',
            'quantity' => 1,
            'unit_price_amount' => 100,
        ]);
        InvoiceItem::create([
            'invoice_id' => $firstInvoice->id,
            'item_name' => 'Second item',
            'quantity' => 1,
            'unit_price_amount' => 50,
        ]);
        $secondInvoice = Invoice::create([
            'payment_slip_id' => $slip->id,
            'invoice_number' => 'INV-SECOND-'.$type,
            'invoice_date' => today(),
            'tax_calculation_mode' => PaymentSlip::TAX_MODE_ITEMIZED,
        ]);
        InvoiceItem::create([
            'invoice_id' => $secondInvoice->id,
            'item_name' => 'Third item',
            'quantity' => 1,
            'unit_price_amount' => 100,
        ]);

        return [$slip->fresh(), $firstInvoice->fresh('items'), $firstItem->fresh(), $secondInvoice->fresh('items')];
    }
}
