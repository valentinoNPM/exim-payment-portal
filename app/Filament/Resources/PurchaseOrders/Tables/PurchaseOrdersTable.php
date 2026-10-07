<?php

namespace App\Filament\Resources\PurchaseOrders\Tables;

use App\Models\PurchaseOrder;
use App\Support\CurrencyFormatter;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Support\Enums\Alignment;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class PurchaseOrdersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['supplier', 'division', 'items.unit']))
            ->columns([
                TextColumn::make('po_number')
                    ->label('No. PO')
                    ->fontFamily('mono')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('source_code')
                    ->label('Kunci ECOUNT')
                    ->fontFamily('mono')
                    ->placeholder('-')
                    ->tooltip('Tanggal + urutan harian di ECOUNT (mis. 01/09/2026 -4). Inilah identitas dokumen; nomor PO hanya label dan bisa sama untuk dua PO.')
                    ->searchable()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('po_date')
                    ->label('Tanggal')
                    ->date('d M Y')
                    ->sortable(),
                TextColumn::make('supplier.name')
                    ->label('Vendor')
                    ->description(fn (PurchaseOrder $record): string => 'PIC: '.$record->pic_name)
                    ->searchable()
                    ->sortable(),
                TextColumn::make('division.code')
                    ->label('Divisi')
                    ->badge()
                    ->placeholder('-')
                    ->sortable(),
                TextColumn::make('items.item_name')
                    ->label('Barang')
                    ->listWithLineBreaks()
                    ->limitList(2)
                    ->expandableLimitedList()
                    ->wrap(),
                TextColumn::make('delivery_date')
                    ->label('Tanggal Selesai')
                    ->date('d M Y')
                    ->placeholder('-')
                    ->sortable(),
                TextColumn::make('grand_total_amount')
                    ->label('Total')
                    ->formatStateUsing(fn ($state, PurchaseOrder $record): string => CurrencyFormatter::format($state, $record->currency))
                    ->fontFamily('mono')
                    ->alignment(Alignment::End)
                    ->sortable(),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => PurchaseOrder::STATUS_LABELS[$state] ?? $state)
                    ->color(fn (string $state): string => match ($state) {
                        PurchaseOrder::STATUS_NEW => 'gray',
                        PurchaseOrder::STATUS_IN_PROGRESS => 'warning',
                        PurchaseOrder::STATUS_SENT_TO_VENDOR => 'info',
                        PurchaseOrder::STATUS_COMPLETED => 'success',
                        default => 'gray',
                    })
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label('Dibuat')
                    ->dateTime('d M Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('po_date', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options(PurchaseOrder::STATUS_LABELS),
                SelectFilter::make('currency')
                    ->label('Mata Uang')
                    ->options(PurchaseOrder::CURRENCY_LABELS),
                Filter::make('periode')
                    ->label('Periode')
                    ->schema([
                        DatePicker::make('dari')->label('Dari'),
                        DatePicker::make('sampai')->label('Sampai'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['dari'] ?? null, fn (Builder $q, $tanggal): Builder => $q->whereDate('po_date', '>=', $tanggal))
                        ->when($data['sampai'] ?? null, fn (Builder $q, $tanggal): Builder => $q->whereDate('po_date', '<=', $tanggal))),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                Action::make('preview_pdf')
                    ->label('Cetak')
                    ->icon('heroicon-o-document-magnifying-glass')
                    ->color('info')
                    ->url(fn (PurchaseOrder $record): string => route('purchase-orders.pdf.preview', $record))
                    ->openUrlInNewTab(),
            ])
            ->toolbarActions([]);
    }
}
