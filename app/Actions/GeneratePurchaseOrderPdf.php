<?php

namespace App\Actions;

use App\Models\PurchaseOrder;
use Barryvdh\DomPDF\Facade\Pdf;

class GeneratePurchaseOrderPdf
{
    public function execute(PurchaseOrder $purchaseOrder): \Barryvdh\DomPDF\PDF
    {
        $purchaseOrder->refresh()->load(['division', 'warehouse', 'supplier', 'creator', 'items.unit', 'items.item', 'taxes']);

        return Pdf::loadView('pdf.purchase-order', [
            'purchaseOrder' => $purchaseOrder,
        ])->setPaper('a4', 'portrait');
    }
}
