<?php

namespace App\Filament\Pages;

use App\Filament\Resources\PurchaseOrders\PurchaseOrderResource;
use App\Models\PurchaseOrder;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Pages\Page;
use Filament\Support\Enums\Alignment;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Slip PO — daftar PO yang masih menunggu proses/approval di ECOUNT, beserta keterangannya.
 * Isinya PO yang belum berstatus "Selesai", diurutkan dari yang paling lama menunggu.
 */
class PoSlips extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $title = 'Slip PO';

    protected static ?string $navigationLabel = 'Slip PO';

    protected static string|UnitEnum|null $navigationGroup = 'Purchase Order';

    protected static ?int $navigationSort = 3;

    /**
     * Disembunyikan dari menu: isinya sebenarnya sama dengan "Daftar PO".
     * Halaman tetap ada (bisa dibuka lewat URL) kalau nanti diperlukan lagi.
     */
    protected static bool $shouldRegisterNavigation = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected string $view = 'filament.pages.po-table';

    public static function canAccess(): bool
    {
        // halaman milik grup PO — pakai aturan yang sama dengan resource PO
        return PurchaseOrderResource::canAccess();
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => PurchaseOrder::query()
                ->forGa()
                ->where('status', '!=', PurchaseOrder::STATUS_COMPLETED)
                ->with('supplier')
                ->withCount('items'))
            ->columns([
                TextColumn::make('po_number')
                    ->label('No. PO')
                    ->fontFamily('mono')
                    ->searchable()
                    ->sortable()
                    ->url(fn (PurchaseOrder $record): string => PurchaseOrderResource::getUrl('view', ['record' => $record])),
                TextColumn::make('po_date')
                    ->label('Tanggal')
                    ->date('d M Y')
                    ->sortable(),
                TextColumn::make('supplier.name')
                    ->label('Supplier')
                    ->searchable()
                    ->sortable()
                    ->placeholder('—'),
                TextColumn::make('pic_name')
                    ->label('PIC')
                    ->searchable()
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('keterangan')
                    ->label('Keterangan')
                    ->state(fn (PurchaseOrder $record): string => $record->items
                        ->pluck('notes')
                        ->filter()
                        ->unique()
                        ->implode(' · ') ?: '—')
                    ->wrap()
                    ->limit(120)
                    ->tooltip(fn (PurchaseOrder $record): ?string => $record->items
                        ->pluck('notes')
                        ->filter()
                        ->unique()
                        ->implode("\n") ?: null),
                TextColumn::make('items_count')
                    ->label('Baris')
                    ->badge()
                    ->color('gray')
                    ->alignCenter(),
                TextColumn::make('grand_total_amount')
                    ->label('Total')
                    ->money(fn (PurchaseOrder $record): string => $record->currency ?? PurchaseOrder::CURRENCY_IDR, locale: 'id')
                    ->fontFamily('mono')
                    ->alignment(Alignment::End)
                    ->sortable(),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => PurchaseOrder::STATUS_LABELS[$state] ?? $state)
                    ->color(fn (string $state): string => match ($state) {
                        PurchaseOrder::STATUS_IN_PROGRESS => 'warning',
                        PurchaseOrder::STATUS_SENT_TO_VENDOR => 'info',
                        default => 'gray',
                    })
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options(array_diff_key(PurchaseOrder::STATUS_LABELS, [PurchaseOrder::STATUS_COMPLETED => true])),
                Filter::make('periode')
                    ->schema([
                        DatePicker::make('dari')->label('Tanggal dari'),
                        DatePicker::make('sampai')->label('sampai'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['dari'] ?? null, fn (Builder $q, $tanggal) => $q->whereDate('po_date', '>=', $tanggal))
                        ->when($data['sampai'] ?? null, fn (Builder $q, $tanggal) => $q->whereDate('po_date', '<=', $tanggal))),
                SelectFilter::make('supplier_id')
                    ->label('Supplier')
                    ->relationship('supplier', 'name')
                    ->searchable()
                    ->preload(),
            ])
            ->defaultSort('po_date', 'asc')
            ->emptyStateHeading('Tidak ada PO yang menunggu')
            ->emptyStateDescription('Semua PO sudah berstatus Selesai.');
    }
}
