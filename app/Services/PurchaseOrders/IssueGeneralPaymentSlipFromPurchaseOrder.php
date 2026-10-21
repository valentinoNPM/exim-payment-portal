<?php

namespace App\Services\PurchaseOrders;

use App\Models\PaymentSlip;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Services\PaymentSlips\CreatePaymentSlip;
use App\Services\PaymentSlips\SavePaymentSlipEdit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class IssueGeneralPaymentSlipFromPurchaseOrder
{
    public function execute(PurchaseOrder $purchaseOrder, User $actor, array $data): PaymentSlip
    {
        $save = app(SavePaymentSlipEdit::class);
        try {
            return DB::transaction(function () use ($purchaseOrder, $actor, $data, $save): PaymentSlip {
                $po = PurchaseOrder::query()->lockForUpdate()->findOrFail($purchaseOrder->id);
                FinalizePurchaseOrder::authorize($po, $actor);
                if (! $po->isPaymentSlipEligibleSource() || ! $po->finalized_at || $po->hasIssuedPaymentSlip()) {
                    throw ValidationException::withMessages(['purchase_order' => 'PO harus Final, berasal dari aplikasi, dan belum pernah diterbitkan menjadi payment slip.']);
                }
                FinalizePurchaseOrder::validateSource($po);
                $items = $po->items->map(function ($item): array {
                    return [
                        'item_name' => trim($item->item_name_snapshot ?: $item->item_name).(filled($item->specification) ? ' ['.$item->specification.']' : ''),
                        'quantity' => $item->quantity,
                        'unit_id' => $item->unit_id,
                        'unit_price_amount' => $item->unit_price_amount,
                        'ppn_tax_id' => $item->taxes->firstWhere('calculation_type_snapshot', 'addition')?->tax_id,
                        'pph_tax_id' => $item->taxes->firstWhere('calculation_type_snapshot', 'deduction')?->tax_id,
                    ];
                })->all();
                $slip = app(CreatePaymentSlip::class)->execute(PaymentSlip::TYPE_GENERAL, [
                    'supplier_id' => $po->supplier_id, 'currency' => $po->currency,
                    'transaction_description' => $data['transaction_description'],
                    'approval_incharge' => $data['approval_incharge'] ?? null,
                    'approval_passed_1' => $data['approval_passed_1'] ?? null,
                    'approval_passed_2' => $data['approval_passed_2'] ?? null,
                    'approval_passed_3' => $data['approval_passed_3'] ?? null,
                    'approval_director' => $data['approval_director'] ?? null,
                    'invoices' => [[
                        'invoice_number' => $data['invoice_number'], 'invoice_date' => $data['invoice_date'],
                        'document_pdf' => $data['document_pdf'] ?? null,
                        'tax_calculation_mode' => PaymentSlip::TAX_MODE_ITEMIZED, 'items' => $items,
                    ]],
                ], $save);
                $invoice = $slip->invoices()->firstOrFail();
                $invoice->fill(['discount_amount' => $po->discount_amount, 'shipping_amount' => $po->shipping_amount, 'purchase_order_id' => $po->id])->save();
                foreach (['subtotal_amount', 'tax_addition_amount', 'tax_deduction_amount', 'grand_total_amount'] as $field) {
                    if (abs((float) $invoice->{$field} - (float) $po->{$field}) > 0.001) {
                        throw ValidationException::withMessages(['purchase_order' => 'Nominal PO dan invoice tidak cocok. Penerbitan dibatalkan; periksa sumber PO.']);
                    }
                }
                $snapshot = [
                    'version' => 1, 'payment_slip_id' => $slip->id, 'invoice_id' => $invoice->id,
                    'purchase_order' => $po->getAttributes(),
                    'items' => $po->items->values()->map(fn ($item, $index) => [
                        'source' => $item->getAttributes(), 'taxes' => $item->taxes->toArray(),
                        'unit_name' => $item->unit?->name, 'invoice_item_id' => $invoice->items->values()[$index]->id,
                    ])->all(),
                ];
                $po->audits()->create(['user_id' => $actor->id, 'event' => 'payment_slip_issued', 'new_values' => $snapshot]);
                $slip->audits()->create(['user_id' => $actor->id, 'event' => 'purchase_order_issued', 'new_values' => $snapshot]);

                return $slip->fresh('invoices.items');
            });
        } catch (\Throwable $exception) {
            $save->discardRolledBackUploads();
            throw $exception;
        }
    }
}
