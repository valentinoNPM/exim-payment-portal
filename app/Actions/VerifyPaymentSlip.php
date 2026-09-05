<?php

namespace App\Actions;

use App\Models\PaymentSlip;
use App\Models\User;
use App\Services\Erp\AccountResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class VerifyPaymentSlip
{
    public function execute(PaymentSlip $slip, User $user): void
    {
        abort_unless($user->hasRole('checker'), 403);
        DB::transaction(function () use ($slip, $user): void {
            $record = PaymentSlip::query()->lockForUpdate()->findOrFail($slip->id);
            if ($record->status !== 'submitted') {
                throw ValidationException::withMessages(['status' => 'Only submitted payment slips can be verified.']);
            }
            $record->load('invoices.items.chartOfAccount');
            foreach ($record->invoices as $invoice) {
                foreach ($invoice->items as $item) {
                    app(AccountResolver::class)->resolve($item, $invoice);
                }
            }
            $verifiedAt = now();
            $record->update([
                'status' => 'approved',
                'verified_at' => $verifiedAt,
                'approved_at' => $verifiedAt,
                'approved_by' => $user->id,
            ]);
            $record->audits()->create([
                'user_id' => $user->id,
                'event' => 'verified',
                'old_values' => ['status' => 'submitted'],
                'new_values' => ['status' => 'approved'],
            ]);
        });
        $slip->refresh();
    }
}
