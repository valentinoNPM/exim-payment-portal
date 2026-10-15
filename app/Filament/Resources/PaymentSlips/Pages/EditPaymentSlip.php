<?php

namespace App\Filament\Resources\PaymentSlips\Pages;

use App\Actions\VerifyPaymentSlip;
use App\Filament\Resources\PaymentSlips\PaymentSlipResource;
use App\Models\DocumentFile;
use App\Models\PaymentSlip;
use App\Services\PaymentSlips\EximPaymentSlipData;
use App\Services\PaymentSlips\GeneralPaymentSlipData;
use App\Services\PaymentSlips\InvoiceDraftCalculator;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Storage;
use Throwable;

class EditPaymentSlip extends EditRecord
{
    protected ?bool $hasDatabaseTransactions = true;

    protected static string $resource = PaymentSlipResource::class;

    protected string $view = 'filament.pages.split-payment-slip';

    public ?string $activePdfUrl = null;

    public function hydrate(): void
    {
        if (PaymentSlipResource::canEdit($this->getRecord())) {
            return;
        }

        throw new AuthorizationException('Payment slip edit access has been revoked.');
    }

    public function exception(Throwable $e, Closure $stopPropagation): void
    {
        if (! $e instanceof AuthorizationException) {
            return;
        }

        $stopPropagation();

        Notification::make()
            ->title('Slip tidak dapat diedit lagi')
            ->body('Status atau izin slip sudah berubah. Perubahan terakhir tidak disimpan.')
            ->warning()
            ->send();

        $url = PaymentSlipResource::getUrl('view', ['record' => $this->getRecord()]);

        $this->redirect($url, navigate: true);
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $record = $this->getRecord();

        return $record->transaction_type === PaymentSlip::TYPE_GENERAL
            ? GeneralPaymentSlipData::prepareEdit($data)
            : EximPaymentSlipData::prepareEdit($data, $record);
    }

    public function updated(string $property): void
    {
        if (! preg_match('/^data\.invoices\.[^.]+\.items\.[^.]+(?:\.(?:quantity|unit_price_amount|ppn_tax_id|pph_tax_id))?$/', $property)) {
            return;
        }

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
        if ($doc && Storage::disk($doc->disk)->exists($doc->path)) {
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
                    $this->save();
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
                        ->title($this->getRecord()->transaction_type === PaymentSlip::TYPE_GENERAL ? 'Payment Slip General berhasil diverifikasi.' : 'Payment Slip verified and ready for ERP export.')
                        ->success()
                        ->send();
                }),
            Action::make('preview_pdf')
                ->label('Preview PDF')
                ->icon('heroicon-o-eye')
                ->url(fn (): string => route('payment-slips.pdf.preview', $this->getRecord()))
                ->openUrlInNewTab(),
            ViewAction::make(),
            DeleteAction::make()
                ->visible(fn (PaymentSlip $record): bool => PaymentSlipResource::canDelete($record)),
        ];
    }
}
