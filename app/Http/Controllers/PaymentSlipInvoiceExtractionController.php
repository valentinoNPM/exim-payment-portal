<?php

namespace App\Http\Controllers;

use App\Filament\Resources\PaymentSlips\PaymentSlipResource;
use App\Models\PaymentSlip;
use App\Services\PaymentSlips\ExtractInvoicesIntoPaymentSlip;
use Filament\Notifications\Notification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class PaymentSlipInvoiceExtractionController extends Controller
{
    public function __invoke(
        Request $request,
        PaymentSlip $paymentSlip,
        ExtractInvoicesIntoPaymentSlip $extractor,
    ): RedirectResponse {
        abort_unless(PaymentSlipResource::canEdit($paymentSlip), 403);
        abort_unless($paymentSlip->status === 'draft' && $paymentSlip->transaction_type !== PaymentSlip::TYPE_GENERAL, 404);

        $validated = $request->validate([
            'raw_pdf_files' => ['required', 'array', 'min:1'],
            'raw_pdf_files.*' => ['required', 'file', 'mimetypes:application/pdf', 'max:102400'],
        ]);

        $result = $extractor->execute($paymentSlip, $validated['raw_pdf_files']);
        $summary = $result['successful_files'].' file berhasil, '.$result['failed_files'].' file gagal. '
            .$result['invoice_count'].' invoice dan '.$result['item_count'].' item ditemukan.';

        $notification = Notification::make()->body($summary);
        if ($result['failed_files'] === 0) {
            $notification->title('Ekstraksi berhasil')->success();
        } elseif ($result['successful_files'] > 0) {
            $notification->title('Ekstraksi selesai sebagian')->warning()->persistent();
        } else {
            $notification->title('Ekstraksi AI gagal')->danger()->persistent();
        }
        $notification->send();

        return redirect(PaymentSlipResource::getUrl('edit', ['record' => $paymentSlip]));
    }
}
