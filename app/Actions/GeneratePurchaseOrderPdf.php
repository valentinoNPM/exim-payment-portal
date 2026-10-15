<?php

namespace App\Actions;

use App\Models\PurchaseOrder;
use Barryvdh\DomPDF\Facade\Pdf;

class GeneratePurchaseOrderPdf
{
    public function execute(PurchaseOrder $purchaseOrder): \Barryvdh\DomPDF\PDF
    {
        $purchaseOrder->refresh()->load(['division', 'warehouse', 'supplier', 'creator', 'items.unit', 'items.item', 'taxes']);

        // F4 Indonesia: 210 x 330 mm, converted to PDF points.
        return Pdf::loadView('pdf.purchase-order', [
            'purchaseOrder' => $purchaseOrder,
        ])->setPaper([0, 0, 595.28, 935.43], 'portrait');
    }
}
