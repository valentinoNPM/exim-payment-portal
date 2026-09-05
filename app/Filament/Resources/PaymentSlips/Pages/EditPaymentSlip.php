<?php

namespace App\Filament\Resources\PaymentSlips\Pages;

use App\Actions\GeneratePaymentSlipPdf;
use App\Actions\VerifyPaymentSlip;
use App\Filament\Resources\PaymentSlips\PaymentSlipResource;
use App\Models\DocumentFile;
use App\Models\PaymentSlip;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditPaymentSlip extends EditRecord
{
    protected static string $resource = PaymentSlipResource::class;

    protected string $view = 'filament.pages.split-payment-slip';

    public ?string $activePdfUrl = null;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $slip = $this->getRecord();
        $firstInvoice = $slip->invoices()->first();
        if ($firstInvoice && $firstInvoice->documentFile) {
            $this->activePdfUrl = route('document-files.view', $firstInvoice->documentFile);
        }

        return $data;
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
                ->visible(fn () => $this->getRecord()->status === 'draft' && auth()->user()->hasRole('maker'))
                ->action(function () {
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
                    $this->save();
                    app(VerifyPaymentSlip::class)->execute($this->getRecord(), auth()->user());
                    Notification::make()
                        ->title('Payment Slip verified and ready for ERP export.')
                        ->success()
                        ->send();
                }),
            Action::make('download_pdf')
                ->label('Download PDF')
                ->icon('heroicon-o-document-arrow-down')
                ->action(fn () => response()->streamDownload(
                    fn () => print (app(GeneratePaymentSlipPdf::class)->execute($this->getRecord())->output()),
                    "payment-slip-{$this->getRecord()->slip_number}.pdf"
                )),
            ViewAction::make(),
            DeleteAction::make()
                ->visible(fn (PaymentSlip $record) => $record->status === 'draft' && auth()->user()->hasRole('maker')),
        ];
    }
}
