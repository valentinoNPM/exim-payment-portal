<?php

namespace App\Http\Controllers;

use App\Filament\Resources\PaymentSlips\PaymentSlipResource;
use App\Http\Requests\CreatePaymentSlipRequest;
use App\Http\Requests\UpdatePaymentSlipRequest;
use App\Models\PaymentSlip;
use App\Services\PaymentSlips\CreatePaymentSlip;
use App\Services\PaymentSlips\SavePaymentSlipEdit;
use Filament\Notifications\Notification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;

class PaymentSlipEditorController extends Controller
{
    public function store(
        CreatePaymentSlipRequest $request,
        string $type,
        CreatePaymentSlip $createPaymentSlip,
        SavePaymentSlipEdit $savePaymentSlip,
    ): RedirectResponse {
        $type = $request->validated('transaction_type') ?? $type;
        $saved = $createPaymentSlip->execute($type, $request->validated(), $savePaymentSlip);

        Notification::make()->title('Payment Slip berhasil dibuat')->success()->send();

        if ($request->boolean('create_another')) {
            $page = match ($type) {
                PaymentSlip::TYPE_GENERAL => 'create-general',
                PaymentSlip::TYPE_IMPORT => 'create-import',
                default => 'create-export',
            };

            return redirect(PaymentSlipResource::getUrl($page));
        }

        return redirect(PaymentSlipResource::getUrl(
            'view',
            ['record' => $saved],
        ));
    }

    public function update(
        UpdatePaymentSlipRequest $request,
        PaymentSlip $paymentSlip,
        SavePaymentSlipEdit $savePaymentSlip,
    ): RedirectResponse {
        DB::transaction(function () use ($request, $paymentSlip, $savePaymentSlip): void {
            if ($request->boolean('submit_after_save')) {
                abort_unless(PaymentSlipResource::canSubmit($paymentSlip), 403);
            }
            $saved = $savePaymentSlip->execute($paymentSlip, $request->validated());
            if ($request->boolean('submit_after_save')) {
                $saved->update(['status' => 'submitted', 'submitted_at' => now()]);
            }
        });

        Notification::make()
            ->title('Payment Slip berhasil diperbarui')
            ->success()
            ->send();

        return redirect(PaymentSlipResource::getUrl(
            $request->boolean('submit_after_save') ? 'view' : 'edit',
            ['record' => $paymentSlip],
        ));
    }
}
