<?php

namespace App\Filament\Resources\PaymentSlips\Pages;

use App\Filament\Resources\PaymentSlips\PaymentSlipResource;
use Filament\Resources\Pages\CreateRecord;

class CreatePaymentSlip extends CreateRecord
{
    protected static string $resource = PaymentSlipResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['slip_number'] = 'SLIP-' . date('YmdHis') . '-' . rand(1000, 9999);
        $data['created_by'] = auth()->id();

        return $data;
    }
}
