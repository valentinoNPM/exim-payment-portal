<?php

namespace App\Http\Controllers;

use App\Filament\Resources\PaymentSlips\PaymentSlipResource;
use App\Models\PurchaseOrder;
use App\Services\PurchaseOrders\IssueGeneralPaymentSlipFromPurchaseOrder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class PurchaseOrderPaymentSlipController extends Controller
{
    public function store(Request $request, PurchaseOrder $purchaseOrder, IssueGeneralPaymentSlipFromPurchaseOrder $issue): RedirectResponse
    {
        $data = $request->validate([
            'transaction_description' => ['required', 'string', 'max:255'],
            'approval_incharge' => ['nullable', 'string', 'max:255'],
            'approval_passed_1' => ['nullable', 'string', 'max:255'],
            'approval_passed_2' => ['nullable', 'string', 'max:255'],
            'approval_passed_3' => ['nullable', 'string', 'max:255'],
            'approval_director' => ['nullable', 'string', 'max:255'],
            'invoice_number' => ['required', 'string', 'max:255'],
            'invoice_date' => ['required', 'date'],
            'document_pdf' => ['nullable', 'file', 'mimetypes:application/pdf', 'max:102400'],
            'items' => ['prohibited'], 'invoices' => ['prohibited'],
            'supplier_id' => ['prohibited'], 'currency' => ['prohibited'],
            'discount_amount' => ['prohibited'], 'shipping_amount' => ['prohibited'],
        ]);
        $slip = $issue->execute($purchaseOrder, $request->user(), $data);

        return redirect(PaymentSlipResource::getUrl('view', ['record' => $slip]));
    }
}
