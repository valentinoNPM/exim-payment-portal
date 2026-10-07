<?php

namespace App\Filament\Pages;

use App\Exports\LaporanPoExport;
use App\Filament\Resources\PurchaseOrders\PurchaseOrderResource;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Supplier;
use BackedEnum;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Page;
use Filament\Support\Enums\Alignment;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Facades\Excel;
use UnitEnum;

/**
 * Laporan PO — meniru laporan ECOUNT "Status Pesanan Pembelian" (ESG016R):
 * satu baris = satu barang dalam PO, nilainya sebelum pajak, ada Total di bawah.
 * Kolom "No. PO" ditambahkan sesuai permintaan (di ECOUNT hanya ada Tanggal-No.).
 */
class PoReport extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $title = 'Laporan PO';

    protected static ?string $navigationLabel = 'Laporan PO';

    protected static string|UnitEnum|null $navigationGroup = 'Purchase Order';

    protected static ?int $navigationSort = 4;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentChartBar;

    protected string $view = 'filament.pages.po-report';

    public static function canAccess(): bool
    {
        return PurchaseOrderResource::canAccess();
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => PurchaseOrderItem::query()
                ->whereHas('purchaseOrder', fn (Builder $query) => $query->forGa())
                ->with(['purchaseOrder.supplier']))
            ->columns([
                TextColumn::make('purchaseOrder.po_number')
                    ->label('No. PO')
                    ->fontFamily('mono')
                    ->searchable()
                    ->wrap()
                    ->sortable(query: fn (Builder $query, string $direction) => $query->orderBy(
                        PurchaseOrder::select('po_number')->whereColumn('purchase_orders.id', 'purchase_order_items.purchase_order_id'),
                        $direction,
                    )),
                TextColumn::make('purchaseOrder.source_code')
                    ->label('Tanggal-No.')
                    ->fontFamily('mono')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('purchaseOrder.supplier.name')
                    ->label('Pelanggan/Vendor')
                    ->searchable()
                    ->sortable()
                    ->placeholder('—'),
                TextColumn::make('nama_barang')
                    ->label('Nama Barang [Spec.]')
                    ->state(fn (PurchaseOrderItem $record): string => trim(
                        (string) $record->item_name_snapshot
                        .($record->specification_snapshot ? ' ['.$record->specification_snapshot.']' : '')
                    ))
                    ->searchable(['item_name_snapshot', 'specification_snapshot'])
                    ->wrap(),
                TextColumn::make('quantity')
                    ->label('Kuantitas')
                    ->numeric(decimalPlaces: 4)
                    ->alignment(Alignment::End)
                    ->sortable()
                    ->summarize(Sum::make()->label('Total')),
                TextColumn::make('unit_price_amount')
                    ->label('Harga')
                    ->money(fn (PurchaseOrderItem $record): string => $record->purchaseOrder?->currency ?? PurchaseOrder::CURRENCY_IDR, locale: 'id')
                    ->alignment(Alignment::End),
                TextColumn::make('subtotal_amount')
                    ->label('Jumlah Sebelum Pajak')
                    ->money(fn (PurchaseOrderItem $record): string => $record->purchaseOrder?->currency ?? PurchaseOrder::CURRENCY_IDR, locale: 'id')
                    ->alignment(Alignment::End)
                    ->sortable()
                    ->summarize(Sum::make()->label('Total')),
                TextColumn::make('notes')
                    ->label('Keterangan')
                    ->wrap()
                    ->limit(70)
                    ->placeholder('—')
                    ->tooltip(fn (PurchaseOrderItem $record): ?string => $record->notes),
            ])
            ->filters([
                Filter::make('periode')
                    ->label('Periode')
                    ->schema([
                        Select::make('pintasan')
                            ->label('Pintasan')
                            ->native(false)
                            ->options([
                                'hari_ini' => 'Hari Ini',
                                'minggu_ini' => 'Minggu ini(~Hari Ini)',
                                'bulan_ini' => 'Bulan ini(~Hari Ini)',
                                'bulan_lalu' => 'Bulan Sebelumnya',
                                'tahun_ini' => 'Tahun Ini',
                                'tahun_lalu' => 'Tahun Sebelumnya',
                            ]),
                        DatePicker::make('dari')->label('Tanggal dari'),
                        DatePicker::make('sampai')->label('sampai'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        [$dari, $sampai] = $this->rentangTanggal($data['pintasan'] ?? null, $data['dari'] ?? null, $data['sampai'] ?? null);

                        return $query->whereHas('purchaseOrder', fn (Builder $q) => $q
                            ->when($dari, fn (Builder $q, $tanggal) => $q->whereDate('po_date', '>=', $tanggal))
                            ->when($sampai, fn (Builder $q, $tanggal) => $q->whereDate('po_date', '<=', $tanggal)));
                    }),
                Filter::make('no_po')
                    ->label('No PO')
                    ->schema([
                        TextInput::make('nilai')
                            ->label('No PO')
                            ->placeholder('PO/HIJ/… atau 31/08/2026 -6'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        filled($data['nilai'] ?? null),
                        fn (Builder $q, $cari) => $q->whereHas('purchaseOrder', fn (Builder $p) => $p
                            ->where('po_number', 'like', "%{$cari}%")
                            ->orWhere('source_code', 'like', "%{$cari}%"))
                    )),
                Filter::make('barang')
                    ->label('Barang')
                    ->schema([TextInput::make('nilai')->label('Nama barang')])
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        filled($data['nilai'] ?? null),
                        fn (Builder $q, $cari) => $q->where('item_name_snapshot', 'like', "%{$cari}%")
                    )),
                SelectFilter::make('vendor')
                    ->label('Pelanggan/Vendor')
                    ->searchable()
                    ->options(fn (): array => Supplier::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        filled($data['value'] ?? null),
                        fn (Builder $q, $id) => $q->whereHas('purchaseOrder', fn (Builder $p) => $p->where('supplier_id', $id))
                    )),
                SelectFilter::make('mata_uang')
                    ->label('Domestik/Asing')
                    ->options([
                        'IDR' => 'Semua Domestik (IDR)',
                        'USD' => 'Semua Asing (USD)',
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        filled($data['value'] ?? null),
                        fn (Builder $q, $mataUang) => $q->whereHas('purchaseOrder', fn (Builder $p) => $p->where('currency', $mataUang))
                    )),
            ])
            ->defaultSort('purchase_order_id')
            ->paginated([25, 50, 100])
            ->emptyStateHeading('Tidak ada baris pada penyaring ini')
            ->headerActions([
                Action::make('cetak')
                    ->label('Cetak')
                    ->icon('heroicon-o-printer')
                    ->color('gray')
                    ->action(fn () => $this->js('window.print()')),
                Action::make('excel')
                    ->label('Excel')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('success')
                    ->action(fn () => Excel::download(
                        new LaporanPoExport($this->getFilteredTableQuery()),
                        'laporan-po-'.now()->format('Ymd-Hi').'.xlsx'
                    )),
            ]);
    }

    /** Nama perusahaan pada kepala laporan. */
    public function namaPerusahaan(): string
    {
        return (string) config('app.company_name', 'PT HANSOLL INDO JAVA');
    }

    /** Periode yang sedang tersaring, untuk kepala laporan. */
    public function labelPeriode(): string
    {
        $data = $this->tableFilters['periode'] ?? [];
        [$dari, $sampai] = $this->rentangTanggal($data['pintasan'] ?? null, $data['dari'] ?? null, $data['sampai'] ?? null);

        if (! $dari && ! $sampai) {
            return 'Semua periode';
        }

        return ($dari ? Carbon::parse($dari)->format('d/m/Y') : '…')
            .' ~ '
            .($sampai ? Carbon::parse($sampai)->format('d/m/Y') : '…');
    }

    /** @return array{0: ?string, 1: ?string} */
    private function rentangTanggal(?string $pintasan, ?string $dari, ?string $sampai): array
    {
        if ($pintasan) {
            $hariIni = Carbon::today();

            [$mulai, $akhir] = match ($pintasan) {
                'hari_ini' => [$hariIni->copy(), $hariIni->copy()],
                'minggu_ini' => [$hariIni->copy()->startOfWeek(), $hariIni->copy()],
                'bulan_ini' => [$hariIni->copy()->startOfMonth(), $hariIni->copy()],
                'bulan_lalu' => [$hariIni->copy()->subMonthNoOverflow()->startOfMonth(), $hariIni->copy()->subMonthNoOverflow()->endOfMonth()],
                'tahun_ini' => [$hariIni->copy()->startOfYear(), $hariIni->copy()],
                'tahun_lalu' => [$hariIni->copy()->subYear()->startOfYear(), $hariIni->copy()->subYear()->endOfYear()],
                default => [null, null],
            };

            return [$mulai?->toDateString(), $akhir?->toDateString()];
        }

        return [$dari, $sampai];
    }
}
