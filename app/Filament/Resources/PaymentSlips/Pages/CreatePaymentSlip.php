<?php

namespace App\Filament\Resources\PaymentSlips\Pages;

use App\Filament\Resources\PaymentSlips\PaymentSlipResource;
use App\Models\DocumentFile;
use App\Models\PaymentSlip;
use App\Services\PaymentSlips\EximPaymentSlipData;
use App\Services\PaymentSlips\GeneralPaymentSlipData;
use App\Services\PaymentSlips\InvoiceDraftCalculator;
use Filament\Resources\Pages\CreateRecord;

class CreatePaymentSlip extends CreateRecord
{
    protected ?bool $hasDatabaseTransactions = true;

    protected static string $resource = PaymentSlipResource::class;

    protected string $view = 'filament.pages.split-payment-slip';

    public ?string $activePdfUrl = null;

    protected ?string $fixedTransactionType = null;

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

    protected function authorizeAccess(): void
    {
        parent::authorizeAccess();

        abort_unless(
            PaymentSlipResource::canCreateType(auth()->user(), $this->getFixedTransactionType()),
            403,
        );
    }

    protected function afterFill(): void
    {
        $this->data['transaction_type'] = $this->getFixedTransactionType();
        $this->data = $this->getFixedTransactionType() === PaymentSlip::TYPE_GENERAL
            ? GeneralPaymentSlipData::prepareCreate($this->data)
            : EximPaymentSlipData::prepareCreate($this->data);
    }

    public function updated(string $property): void
    {
        InvoiceDraftCalculator::recalculateLiveItemizedInvoice($this->data, $property);

        // Force Livewire to detect the nested data mutation by re-assigning
        // the top-level key. Without this, changes made by reference inside
        // the recalculation method may not trigger a browser re-render.
        if (isset($this->data['invoices'])) {
            $this->data['invoices'] = $this->data['invoices'];
        }
    }

    public function setActivePdf(int $documentFileId): void
    {
        $doc = DocumentFile::find($documentFileId);
        if ($doc) {
            $this->activePdfUrl = route('document-files.view', $doc);
        }
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data = $this->getFixedTransactionType() === PaymentSlip::TYPE_GENERAL
            ? GeneralPaymentSlipData::prepareCreate($data)
            : EximPaymentSlipData::prepareCreate($data);
        abort_unless(PaymentSlipResource::canCreateType(auth()->user(), $data['transaction_type']), 403);
        $lastSlip = PaymentSlip::where('slip_number', 'like', 'PS-%-HANSOLL-%')
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
