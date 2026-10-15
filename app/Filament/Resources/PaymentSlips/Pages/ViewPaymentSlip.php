<?php

namespace App\Filament\Resources\PaymentSlips\Pages;

use App\Actions\VerifyPaymentSlip;
use App\Filament\Resources\PaymentSlips\PaymentSlipResource;
use App\Models\DocumentFile;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\Storage;

class ViewPaymentSlip extends ViewRecord
{
    protected static string $resource = PaymentSlipResource::class;

    protected string $view = 'filament.pages.split-payment-slip';

    public ?string $activePdfUrl = null;

    public function mount(int|string $record): void
    {
        parent::mount($record);

        $slip = $this->getRecord()->loadMissing([
            'supplier',
            'customer',
            'buyer',
            'creator.division',
            'invoices.buyer',
            'invoices.documentFile',
            'invoices.ppnTax',
            'invoices.pphTax',
            'invoices.items.unit',
            'invoices.items.ppnTax',
            'invoices.items.pphTax',
            'invoices.items.chartOfAccount',
        ]);

        $firstDocument = $slip->invoices
            ->pluck('documentFile')
            ->filter()
            ->first(fn (DocumentFile $document): bool => Storage::disk($document->disk)->exists($document->path));
        if ($firstDocument) {
            $this->activePdfUrl = route('document-files.view', $firstDocument);
        }
    }

    public function setActivePdf(int $documentFileId): void
    {
        $doc = DocumentFile::find($documentFileId);
        if ($doc) {
            $this->activePdfUrl = route('document-files.view', $doc);
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('submit_slip')
                ->label('Submit')
                ->icon('heroicon-o-paper-airplane')
                ->color('success')
                ->visible(fn (): bool => PaymentSlipResource::canSubmit($this->getRecord()))
                ->action(function () {
                    abort_unless(PaymentSlipResource::canSubmit($this->getRecord()), 403);
                    $this->getRecord()->update([
                        'status' => 'submitted',
                        'submitted_at' => now(),
                    ]);
                    Notification::make()
                        ->title('Payment Slip submitted to Accounting.')
                        ->success()
                        ->send();
                }),
            Action::make('verify_slip')
                ->label('Verify')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->visible(fn () => $this->getRecord()->status === 'submitted' && auth()->user()->hasRole('checker'))
                ->action(function () {
                    app(VerifyPaymentSlip::class)->execute($this->getRecord(), auth()->user());
                    Notification::make()
                        ->title($this->getRecord()->transaction_type === PaymentSlip::TYPE_GENERAL ? 'Payment Slip General berhasil diverifikasi.' : 'Payment Slip verified and ready for ERP export.')
                        ->success()
                        ->send();
                }),
            Action::make('preview_pdf')
                ->label('Preview PDF')
                ->icon('heroicon-o-eye')
                ->url(fn (): string => route('payment-slips.pdf.preview', $this->getRecord()))
                ->openUrlInNewTab(),
            Action::make('check_slip')
                ->label('Check')
                ->icon('heroicon-o-clipboard-document-check')
                ->color('warning')
                ->visible(fn (): bool => auth()->user()?->hasRole('checker')
                    && $this->getRecord()->status === 'submitted'
                    && PaymentSlipResource::canEdit($this->getRecord()))
                ->url(fn (): string => route('payment-slips.checker-edit', $this->getRecord())),
            EditAction::make()
                ->visible(fn (): bool => PaymentSlipResource::canEdit($this->getRecord())),
            DeleteAction::make()
                ->visible(fn (): bool => PaymentSlipResource::canDelete($this->getRecord())),
        ];
    }
}
