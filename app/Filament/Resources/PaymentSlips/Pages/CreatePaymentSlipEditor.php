<?php

namespace App\Filament\Resources\PaymentSlips\Pages;

use App\Filament\Resources\PaymentSlips\PaymentSlipResource;
use App\Models\PaymentSlip;
use App\Support\PaymentSlipEditorData;
use Filament\Resources\Pages\Page;

class CreatePaymentSlipEditor extends Page
{
    protected static string $resource = PaymentSlipResource::class;

    protected string $view = 'filament.payment-slips.editor';

    protected ?string $fixedTransactionType = null;

    public function mount(): void
    {
        abort_unless(PaymentSlipResource::canCreateType(auth()->user(), $this->getFixedTransactionType()), 403);
    }

    public function getFixedTransactionType(): string
    {
        return $this->fixedTransactionType
            ?? (auth()->user()?->isEximDivision() ? PaymentSlip::TYPE_EXPORT : PaymentSlip::TYPE_GENERAL);
    }

    public function getTitle(): string
    {
        return $this->getFixedTransactionType() === PaymentSlip::TYPE_GENERAL
            ? 'Create General Payment Slip'
            : 'Create EXIM Payment Slip';
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        return app(PaymentSlipEditorData::class)->for($this->getFixedTransactionType());
    }
}
