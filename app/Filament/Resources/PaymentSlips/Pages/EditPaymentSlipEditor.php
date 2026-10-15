<?php

namespace App\Filament\Resources\PaymentSlips\Pages;

use App\Filament\Resources\PaymentSlips\PaymentSlipResource;
use App\Models\PaymentSlip;
use App\Support\PaymentSlipEditorData;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;

class EditPaymentSlipEditor extends Page
{
    use InteractsWithRecord;

    protected static string $resource = PaymentSlipResource::class;

    protected static ?string $title = 'Ubah Payment Slip';

    protected string $view = 'filament.payment-slips.editor';

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);

        abort_unless(PaymentSlipResource::canEdit($this->getRecord()), 403);

        if ($this->record->hasPurchaseOrderSource()) {
            $this->view = 'filament.payment-slips.po-source-editor';
        }

        if ($this->getRecord()->status === 'submitted') {
            $this->redirect(PaymentSlipResource::getUrl('edit-accounting', ['record' => $this->getRecord()]));
        }
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        /** @var PaymentSlip $record */
        $record = $this->getRecord();

        return app(PaymentSlipEditorData::class)->for($record->transaction_type, $record);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('preview_pdf')->label('Preview PDF')->icon('heroicon-o-eye')
                ->url(fn (): string => route('payment-slips.pdf.preview', $this->getRecord()))->openUrlInNewTab(),
            Action::make('view')->label('Lihat')->icon('heroicon-o-eye')
                ->url(fn (): string => PaymentSlipResource::getUrl('view', ['record' => $this->getRecord()])),
            DeleteAction::make()->record(fn () => $this->getRecord())
                ->authorize(fn (): bool => PaymentSlipResource::canDelete($this->getRecord()))
                ->successRedirectUrl(PaymentSlipResource::getUrl('index')),
        ];
    }
}
