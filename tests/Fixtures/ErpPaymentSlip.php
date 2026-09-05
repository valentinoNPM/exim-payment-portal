<?php

namespace Tests\Fixtures;

use App\Models\Buyer;
use App\Models\ChartOfAccount;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\PaymentSlip;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class ErpPaymentSlip
{
    public static function create(User $user, string $type = 'export'): PaymentSlip
    {
        return Model::withoutEvents(function () use ($user, $type): PaymentSlip {
            $supplier = Supplier::create(['code' => '000123', 'name' => 'Fixture Supplier', 'is_active' => true]);
            $buyer = Buyer::create(['code' => 'QA-BUYER', 'name' => 'Fixture Buyer', 'is_active' => true]);
            $coa = ChartOfAccount::create(['code' => '43011011', 'name' => 'Handling', 'is_active' => true]);
            $slip = PaymentSlip::create(['slip_number' => 'PS-FIXTURE-001', 'transaction_type' => $type, 'supplier_id' => $supplier->id, 'buyer_id' => $buyer->id, 'status' => 'approved', 'created_by' => $user->id, 'approved_by' => $user->id, 'approved_at' => '2026-09-04 10:00:00', 'subtotal_amount' => '600.00', 'tax_addition_amount' => '66.01', 'tax_deduction_amount' => '12.01', 'grand_total_amount' => '654.00']);
            foreach ([['000045', '33.01', '6.01'], ['000046', '33.00', '6.00']] as [$number, $ppn, $pph]) {
                $invoice = Invoice::create(['payment_slip_id' => $slip->id, 'invoice_number' => $number, 'invoice_date' => '2026-08-31', 'subtotal_amount' => '300.00', 'tax_addition_amount' => $ppn, 'tax_deduction_amount' => $pph, 'grand_total_amount' => '327.00']);
                foreach ([100, 200] as $index => $amount) {
                    InvoiceItem::create(['invoice_id' => $invoice->id, 'line_number' => $index + 1, 'item_name' => 'Handling '.($index + 1), 'quantity' => 1, 'unit_price_amount' => $amount, 'subtotal_amount' => $amount, 'net_amount' => $amount, 'coa_id' => $coa->id, 'coa_code_snapshot' => $coa->code, 'coa_name_snapshot' => $coa->name]);
                }
            }

            return $slip->fresh();
        });
    }
}
