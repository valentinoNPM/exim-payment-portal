<?php

namespace App\Http\Controllers;

use App\Actions\GeneratePurchaseOrderPdf;
use App\Filament\Resources\PurchaseOrders\PurchaseOrderResource;
use App\Models\PurchaseOrder;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class PreviewPurchaseOrderPdfController extends Controller
{
    public function __invoke(PurchaseOrder $purchaseOrder, GeneratePurchaseOrderPdf $generatePurchaseOrderPdf): Response
    {
        abort_unless(PurchaseOrderResource::canView($purchaseOrder), 403);

        $filename = 'purchase-order-'.Str::replace(['/', '\\'], '-', $purchaseOrder->po_number).'.pdf';

        return $generatePurchaseOrderPdf
            ->execute($purchaseOrder)
            ->stream($filename);
    }
}
