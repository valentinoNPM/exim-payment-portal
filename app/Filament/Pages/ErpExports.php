<?php

namespace App\Filament\Pages;

use App\Actions\ExportPaymentSlipToErp;
use App\Filament\Resources\PaymentSlips\PaymentSlipResource;
use App\Models\ErpExportBatch;
use App\Models\PaymentSlip;
use App\Services\Erp\ErpJournalBuilder;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\View;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use UnitEnum;

class ErpExports extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $title = 'ERP Export';

    protected static string|UnitEnum|null $navigationGroup = 'Payment';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowDownTray;

    protected string $view = 'filament.pages.erp-exports';

    public string $activeTab = 'ready';

    public static function canAccess(): bool
    {
        return auth()->user()?->hasRole('checker') ?? false;
    }

    public function updatedActiveTab(): void
    {
        $this->resetTable();
    }

    public function table(Table $table): Table
    {
        $history = $this->activeTab === 'history';

        return $table
            ->query(fn (): Builder => PaymentSlip::query()
                ->when(! static::canAccess(), fn (Builder $query) => $query->whereRaw('1 = 0'))
                ->with(['supplier', 'erpExportItem.exportBatch.creator'])->withCount('invoices')
                ->when($history, fn (Builder $query) => $query->whereHas('erpExportItem'), fn (Builder $query) => $query->where('status', 'approved')->whereDoesntHave('erpExportItem')))
            ->columns([
                TextColumn::make('slip_number')->label('Slip Number')->searchable()->sortable()->url(fn (PaymentSlip $record): string => PaymentSlipResource::getUrl('view', ['record' => $record])),
                TextColumn::make('supplier.name')->label('Supplier')->searchable()->sortable(),
                TextColumn::make('transaction_type')->label('Import/Export')->badge()->sortable(),
                TextColumn::make('invoices_count')->label('Invoice count')->visible(! $history),
                TextColumn::make('grand_total_amount')->label('Amount')->money('IDR')->sortable(),
                TextColumn::make('approved_at')->label('Verified at')->dateTime()->sortable()->visible(! $history),
                TextColumn::make('erpExportItem.exportBatch.creator.name')->label('Exported by')->visible($history),
                TextColumn::make('erpExportItem.exportBatch.exported_at')->label('Exported at')->dateTime()->sortable()->visible($history),
            ])
            ->defaultSort(fn (Builder $query): Builder => $history
                ? $query->orderByDesc(ErpExportBatch::query()->select('exported_at')
                    ->join('erp_export_items', 'erp_export_items.erp_export_batch_id', '=', 'erp_export_batches.id')
                    ->whereColumn('erp_export_items.payment_slip_id', 'payment_slips.id')->limit(1))
                : $query->orderByDesc('approved_at'))
            ->filters([
                SelectFilter::make('transaction_type')->label('Import/Export')->options(['import' => 'Import', 'export' => 'Export']),
                Filter::make('approval_date')->schema([DatePicker::make('from'), DatePicker::make('until')])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $query, $date) => $query->whereDate('approved_at', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $query, $date) => $query->whereDate('approved_at', '<=', $date))),
            ])
            ->recordActions([
                Action::make('prepare')->label('Prepare file')->visible(! $history)->authorize(fn (): bool => static::canAccess())
                    ->slideOver()->modalWidth('7xl')->modalHeading(fn (PaymentSlip $record): string => 'Prepare ERP file - '.$record->slip_number)
                    ->modalSubmitActionLabel('Export XLSX')
                    ->schema(function (PaymentSlip $record): array {
                        $record->loadMissing(['invoices.items.chartOfAccount', 'supplier', 'buyer']);
                        $fields = [View::make('filament.erp.summary')->viewData(['slip' => $record])];
                        foreach ($record->invoices->sortBy('id') as $invoice) {
                            $fields[] = Section::make($invoice->invoice_number.' · '.$invoice->invoice_date?->format('d M Y'))
                                ->components([TextInput::make('vat_numbers.'.$invoice->id)->label('VAT Invoice No.')
                                    ->helperText('Optional. Fill only when a tax invoice number is available.')
                                    ->default($invoice->vat_invoice_number)
                                    ->maxLength(255)->live(debounce: 400)]);
                        }
                        $fields[] = View::make('filament.erp.preview')->viewData(fn (Get $get): array => $this->preview($record, $get('vat_numbers') ?? []));

                        return $fields;
                    })
                    ->action(function (PaymentSlip $record, array $data, Action $action): ?StreamedResponse {
                        abort_unless(static::canAccess(), 403);
                        try {
                            $batch = app(ExportPaymentSlipToErp::class)->execute($record, auth()->user(), $data['vat_numbers'] ?? []);
                        } catch (ValidationException $e) {
                            Notification::make()->title('Export blocked')->body(implode(' ', $e->validator->errors()->all()))->danger()->persistent()->send();
                            $action->halt();

                            return null;
                        }
                        Notification::make()->title('ERP file exported')->success()->send();
                        $this->activeTab = 'history';
                        $this->resetTable();

                        return Storage::disk('local')->download($batch->file_path, basename($batch->file_path));
                    }),
                Action::make('download')->label('Download again')->visible(fn (PaymentSlip $record): bool => $history && filled($record->erpExportItem?->exportBatch?->file_path) && Storage::disk('local')->exists($record->erpExportItem->exportBatch->file_path))
                    ->authorize(fn (): bool => static::canAccess())
                    ->url(fn (PaymentSlip $record): string => route('erp-exports.download', $record->erpExportItem->exportBatch)),
            ])
            ->emptyStateHeading($history ? 'No export history yet.' : 'No payment slips are ready to export.')
            ->emptyStateDescription($history ? 'Exported files will appear here for download.' : 'Verified payment slips will appear here.');
    }

    private function preview(PaymentSlip $slip, array $vatNumbers): array
    {
        try {
            $rows = app(ErpJournalBuilder::class)->build($slip, array_map(fn ($value) => $value ?? '', $vatNumbers));

            return ['rows' => $rows, 'error' => null, 'slip' => $slip];
        } catch (ValidationException $e) {
            return ['rows' => [], 'error' => implode(' ', $e->validator->errors()->all()), 'slip' => $slip];
        }
    }
}
