<?php

namespace App\Filament\Resources\PaymentSlips\Pages;

use App\Actions\GeneratePaymentSlipPdf;
use App\Filament\Resources\PaymentSlips\PaymentSlipResource;
use App\Models\DocumentFile;
use App\Models\PaymentSlipAudit;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewPaymentSlip extends ViewRecord
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
                    $this->getRecord()->update([
                        'status' => 'pending_approval',
                        'verified_at' => now(),
                    ]);
                    Notification::make()
                        ->title('Payment Slip verified. Awaiting GM approval.')
                        ->success()
                        ->send();
                }),
            Action::make('approve_slip')
                ->label('Approve')
                ->icon('heroicon-o-hand-thumb-up')
                ->color('success')
                ->visible(fn () => $this->getRecord()->status === 'pending_approval' && auth()->user()->hasRole('approver'))
                ->requiresConfirmation()
                ->action(function () {
                    $record = $this->getRecord();
                    $record->update([
                        'status' => 'approved',
                        'approved_by' => auth()->id(),
                        'approved_at' => now(),
                    ]);
                    PaymentSlipAudit::create([
                        'payment_slip_id' => $record->id,
                        'user_id' => auth()->id(),
                        'event' => 'approved',
                        'new_values' => ['status' => 'approved'],
                    ]);
                    Notification::make()
                        ->title('Payment Slip approved.')
                        ->success()
                        ->send();
                }),
            Action::make('reject_slip')
                ->label('Reject')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->visible(fn () => $this->getRecord()->status === 'pending_approval' && auth()->user()->hasRole('approver'))
                ->form([
                    Textarea::make('rejection_note')
                        ->label('Catatan Penolakan')
                        ->required(),
                ])
                ->action(function (array $data) {
                    $record = $this->getRecord();
                    $record->update([
                        'status' => 'draft',
                        'approved_by' => null,
                        'approved_at' => null,
                    ]);
                    PaymentSlipAudit::create([
                        'payment_slip_id' => $record->id,
                        'user_id' => auth()->id(),
                        'event' => 'rejected',
                        'notes' => $data['rejection_note'],
                        'new_values' => ['status' => 'draft'],
                    ]);
                    Notification::make()
                        ->title('Payment Slip rejected and returned to draft.')
                        ->warning()
                        ->send();
                }),
            Action::make('download_pdf')
                ->label('Download PDF')
                ->icon('heroicon-o-document-arrow-down')
                ->action(fn () => response()->streamDownload(
                    fn () => print (app(GeneratePaymentSlipPdf::class)->execute($this->getRecord())->output()),
                    "payment-slip-{$this->getRecord()->slip_number}.pdf"
                )),
            EditAction::make(),
        ];
    }
}
