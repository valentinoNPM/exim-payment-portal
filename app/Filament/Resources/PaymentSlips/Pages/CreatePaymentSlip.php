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
        $lastSlip = \App\Models\PaymentSlip::where('slip_number', 'like', 'PS-%-HANSOLL-%')
            ->lockForUpdate()
            ->orderBy('id', 'desc')
            ->first();

        $nextSequence = 1;

        if ($lastSlip) {
            $parts = explode('-', $lastSlip->slip_number);
            if (count($parts) >= 2) {
                $nextSequence = ((int) $parts[1]) + 1;
            }
        }

        $paddedSequence = str_pad($nextSequence, 5, '0', STR_PAD_LEFT);
        $currentYear = date('Y');

        $data['slip_number'] = "PS-{$paddedSequence}-HANSOLL-{$currentYear}";
        $data['created_by'] = auth()->id();

        return $data;
    }
}
