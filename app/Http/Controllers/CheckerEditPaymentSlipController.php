<?php

namespace App\Http\Controllers;

use App\Filament\Resources\PaymentSlips\PaymentSlipResource;
use App\Models\ChartOfAccount;
use App\Models\InvoiceItem;
use App\Models\PaymentSlip;
use App\Support\CheckerEditableFields;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class CheckerEditPaymentSlipController extends Controller
{
    public function show(PaymentSlip $paymentSlip): Response
    {
        $this->authorizeCheckerEdit($paymentSlip);

        $paymentSlip->load([
            'supplier',
            'buyer',
            'creator.division',
            'invoices.buyer',
            'invoices.documentFile',
            'invoices.items.chartOfAccount',
        ]);

        $html = view('payment-slips.checker-edit', [
            'paymentSlip' => $paymentSlip,
            'chartOfAccounts' => ChartOfAccount::query()->orderBy('code')->get(),
        ])->render();

        // Blade indentation is sizeable on slips with many rows. Removing only
        // whitespace between tags keeps the rendered page comfortably small.
        $html = preg_replace('/>\s+</', '><', $html) ?? $html;

        return response($html);
    }

    public function update(Request $request, PaymentSlip $paymentSlip): RedirectResponse
    {
        $this->authorizeCheckerEdit($paymentSlip);

        $validated = $request->validate([
            'invoices' => ['sometimes', 'array'],
            'invoices.*' => ['array'],
            'invoices.*.vat_invoice_number' => ['nullable', 'string', 'max:255'],
            'items' => ['sometimes', 'array'],
            'items.*' => ['array'],
            'items.*.vat_invoice_number' => ['nullable', 'string', 'max:255'],
            'items.*.coa_id' => ['nullable', 'integer', 'exists:chart_of_accounts,id'],
        ]);

        DB::transaction(function () use ($paymentSlip, $validated): void {
            $locked = PaymentSlip::query()->lockForUpdate()->findOrFail($paymentSlip->getKey());
            $this->authorizeCheckerEdit($locked);

            $oldValues = ['invoices' => [], 'items' => []];
            $newValues = ['invoices' => [], 'items' => []];

            foreach ($validated['invoices'] ?? [] as $invoiceId => $row) {
                $invoice = $locked->invoices()->findOrFail($invoiceId);
                if (! CheckerEditableFields::canEditInvoiceVat($locked)) {
                    continue;
                }

                $oldValues['invoices'][$invoice->getKey()] = [
                    'vat_invoice_number' => $invoice->vat_invoice_number,
                ];
                $invoice->vat_invoice_number = filled($row['vat_invoice_number'] ?? null)
                    ? trim($row['vat_invoice_number'])
                    : null;
                $invoice->save();
                $newValues['invoices'][$invoice->getKey()] = [
                    'vat_invoice_number' => $invoice->vat_invoice_number,
                ];
            }

            foreach ($validated['items'] ?? [] as $itemId => $row) {
                $item = InvoiceItem::query()
                    ->with('invoice')
                    ->whereKey($itemId)
                    ->whereHas('invoice', fn ($query) => $query->where('payment_slip_id', $locked->getKey()))
                    ->firstOrFail();

                $oldValues['items'][$item->getKey()] = [
                    'vat_invoice_number' => $item->vat_invoice_number,
                    'coa_id' => $item->coa_id,
                ];

                if (CheckerEditableFields::canEditItemVat($locked, $item->invoice)) {
                    $item->vat_invoice_number = filled($row['vat_invoice_number'] ?? null)
                        ? trim($row['vat_invoice_number'])
                        : null;
                }
                if (CheckerEditableFields::canEditItemCoa($locked)) {
                    $item->coa_id = filled($row['coa_id'] ?? null) ? (int) $row['coa_id'] : null;
                }

                $item->save();
                $newValues['items'][$item->getKey()] = [
                    'vat_invoice_number' => $item->vat_invoice_number,
                    'coa_id' => $item->coa_id,
                ];
            }

            $locked->audits()->create([
                'user_id' => auth()->id(),
                'event' => 'checker_edited',
                'old_values' => $oldValues,
                'new_values' => $newValues,
            ]);
        });

        return to_route('payment-slips.checker-edit', $paymentSlip)
            ->with('status', 'Checker fields saved successfully.');
    }

    private function authorizeCheckerEdit(PaymentSlip $paymentSlip): void
    {
        abort_unless(auth()->user()?->hasRole('checker'), 403);
        abort_unless($paymentSlip->status === 'submitted', 403);
        abort_unless(PaymentSlipResource::canEdit($paymentSlip), 403);
    }
}
