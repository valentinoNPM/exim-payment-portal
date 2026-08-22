<?php

namespace App\Filament\Resources\PaymentSlips\Pages;

use App\Filament\Resources\PaymentSlips\PaymentSlipResource;
use App\Models\DocumentFile;
use Filament\Resources\Pages\CreateRecord;

class CreatePaymentSlip extends CreateRecord
{
    protected static string $resource = PaymentSlipResource::class;

    protected string $view = 'filament.pages.split-payment-slip';

    public ?string $activePdfUrl = null;

    public function setActivePdf(int $documentFileId): void
    {
        $doc = DocumentFile::find($documentFileId);
        if ($doc) {
            $this->activePdfUrl = route('document-files.view', $doc);
        }
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['slip_number'] = 'SLIP-'.date('YmdHis').'-'.rand(1000, 9999);
        $data['created_by'] = auth()->id();

        return $data;
    }
}
