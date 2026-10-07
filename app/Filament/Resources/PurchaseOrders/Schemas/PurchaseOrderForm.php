<?php

namespace App\Filament\Resources\PurchaseOrders\Schemas;

use App\Filament\Resources\PurchaseOrders\Tables\PurchaseOrderItemPickerTable;
use App\Models\Item;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Tax;
use App\Models\Unit;
use App\Services\PurchaseOrders\PurchaseOrderNumberGenerator;
use App\Support\CurrencyFormatter;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\ModalTableSelect;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;

class PurchaseOrderForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make('Data PO')
                    ->description('Data utama dan informasi pengiriman PO.')
                    ->schema([
                        TextInput::make('po_number')
                            ->label('Nomor PO')
                            ->disabled()
                            ->dehydrated(false)
                            ->placeholder('Otomatis')
                            ->hiddenOn('create'),
                        TextInput::make('source_code')
                            ->label('Kunci ECOUNT')
                            ->disabled()
                            ->dehydrated(false)
                            ->visible(fn (?PurchaseOrder $record): bool => filled($record?->source_code))
                            ->helperText('Tanggal + urutan harian di ECOUNT (mis. 01/09/2026 -4) — identitas dokumen aslinya. Nomor PO bisa dipakai dua PO; kunci ini yang membedakan.'),
                        Placeholder::make('po_number_draft')
                            ->label('Nomor PO')
                            ->visibleOn('create')
                            ->content(fn (Get $get): string => app(PurchaseOrderNumberGenerator::class)
                                ->preview(Carbon::parse($get('po_date') ?: now()))['po_number'])
                            ->helperText('Nomor sementara (draf) — belum paten. Nomor final dipesan sistem saat PO disimpan, supaya tidak bertabrakan bila ada yang membuat PO bersamaan.'),
                        DatePicker::make('po_date')
                            ->label('Tanggal PO')
                            ->default(today())
                            ->required()
                            ->disabledOn('edit')
                            ->native(false)
                            ->live()
                            ->afterStateUpdated(function (Get $get, Set $set, ?string $state): void {
                                // Tanggal kirim mengikuti tanggal PO selama belum diisi manual
                                // (95% PO HANSOLL 2024-2026 memang begitu).
                                if (blank($get('delivery_date')) && filled($state)) {
                                    $set('delivery_date', $state);
                                }
                            }),
                        Select::make('supplier_id')
                            ->label('Vendor')
                            ->relationship('supplier', 'name', modifyQueryUsing: fn ($query) => $query->where('is_active', true))
                            ->searchable(['code', 'name'])
                            ->preload()
                            ->createOptionForm([
                                TextInput::make('code')->required()->unique('suppliers', 'code'),
                                TextInput::make('name')->required(),
                                Textarea::make('address')->columnSpanFull(),
                                Hidden::make('is_active')->default(true),
                            ])
                            ->required(),
                        Select::make('pic_user_id')
                            ->label('PIC')
                            ->relationship('picUser', 'name')
                            // Dokumen hasil impor ECOUNT belum punya PIC sistem (pic_user_id kosong).
                            // Tanpa isian awal, menyimpan perubahan PO impor gagal validasi "wajib diisi".
                            ->afterStateHydrated(function (Set $set, ?PurchaseOrder $record): void {
                                $set('pic_user_id', $record?->pic_user_id ?? auth()->id());
                            })
                            ->default(fn (): ?int => auth()->id())
                            ->searchable()
                            ->preload()
                            ->required()
                            ->helperText('PIC adalah pengguna sistem; namanya disalin ke PO saat disimpan.'),
                        Select::make('currency')
                            ->label('Mata Uang')
                            ->options([
                                PurchaseOrder::CURRENCY_IDR => 'IDR - Rupiah',
                                PurchaseOrder::CURRENCY_USD => 'USD - US Dollar',
                            ])
                            ->default(PurchaseOrder::CURRENCY_IDR)
                            ->required()
                            ->live(),
                        Select::make('addition_tax_id')
                            ->label('PPN (penambahan)')
                            ->options(fn (): array => Tax::query()
                                ->where('calculation_type', 'addition')
                                ->where('is_active', true)
                                ->orderBy('name')
                                ->get()
                                ->mapWithKeys(fn (Tax $tax): array => [$tax->id => self::taxLabel($tax)])
                                ->all())
                            ->placeholder('Tanpa PPN')
                            ->searchable()
                            ->preload()
                            ->dehydrated(false)
                            ->live()
                            ->afterStateHydrated(function (Set $set, ?PurchaseOrder $record): void {
                                $tax = $record?->taxes()
                                    ->where('calculation_type_snapshot', 'addition')
                                    ->first(['tax_id', 'rate_snapshot']);

                                $set('addition_tax_id', $tax?->tax_id);
                                $set('addition_tax_rate', $tax?->rate_snapshot);
                            })
                            ->afterStateUpdated(fn (mixed $state, Get $get, Set $set) => self::applyAdditionTaxToAllItems($state, $get, $set))
                            ->helperText('Berlaku langsung ke seluruh baris item.'),
                        Hidden::make('addition_tax_rate')
                            ->dehydrated(false),
                        Select::make('deduction_tax_id')
                            ->label('PPh (potongan)')
                            ->options(fn (): array => Tax::query()
                                ->where('calculation_type', 'deduction')
                                ->where('is_active', true)
                                ->orderBy('name')
                                ->get()
                                ->mapWithKeys(fn (Tax $tax): array => [$tax->id => self::taxLabel($tax)])
                                ->all())
                            ->placeholder('Tanpa PPh')
                            ->searchable()
                            ->preload()
                            ->dehydrated(false)
                            ->live()
                            ->afterStateHydrated(function (Set $set, ?PurchaseOrder $record): void {
                                $tax = $record?->taxes()
                                    ->where('calculation_type_snapshot', 'deduction')
                                    ->first(['tax_id', 'rate_snapshot']);

                                $set('deduction_tax_id', $tax?->tax_id);
                                $set('deduction_tax_rate', $tax?->rate_snapshot);
                            })
                            ->afterStateUpdated(fn (mixed $state, Get $get, Set $set) => self::applyDeductionTaxToAllItems($state, $get, $set))
                            ->helperText('Berlaku langsung ke seluruh baris item.'),
                        Hidden::make('deduction_tax_rate')
                            ->dehydrated(false),
                        TextInput::make('discount_amount')
                            ->label('Diskon')
                            ->numeric()
                            ->minValue(0)
                            ->default(0)
                            ->prefix(fn (Get $get): string => CurrencyFormatter::prefix($get('currency') ?? PurchaseOrder::CURRENCY_IDR))
                            ->live(onBlur: true),
                        TextInput::make('shipping_amount')
                            ->label('Biaya Kirim')
                            ->numeric()
                            ->minValue(0)
                            ->default(0)
                            ->prefix(fn (Get $get): string => CurrencyFormatter::prefix($get('currency') ?? PurchaseOrder::CURRENCY_IDR))
                            ->live(onBlur: true),
                        Select::make('status')
                            ->label('Status')
                            ->options(PurchaseOrder::STATUS_LABELS)
                            ->default(PurchaseOrder::STATUS_NEW)
                            ->required()
                            ->helperText('Status ini hanya pencatatan progres, bukan alur approval sistem.'),
                        DatePicker::make('delivery_date')
                            ->label('Tanggal Selesai')
                            ->native(false)
                            ->default(fn (Get $get) => $get('po_date') ?? today())
                            ->minDate(fn (Get $get) => $get('po_date')),
                        Textarea::make('delivery_location')
                            ->label('Lokasi')
                            ->rows(2),
                        Textarea::make('notes')
                            ->label('Keterangan')
                            ->rows(2),
                    ])
                    ->columns(2)
                    ->maxWidth(Width::FourExtraLarge)
                    ->columnSpanFull(),

                Section::make('Item PO')
                    ->description('Isi kuantitas dan harga, nilai baris dihitung otomatis.')
                    ->schema([
                        Repeater::make('items')
                            ->hiddenLabel()
                            ->relationship()
                            ->collapsed(fn (?PurchaseOrder $record): bool => ($record?->items()->count() ?? 0) > 20)
                            ->table([
                                TableColumn::make('Nama Barang')->width('480px')->markAsRequired(),
                                TableColumn::make('Spesifikasi')->width('170px'),
                                TableColumn::make('Kuantitas')->width('90px')->markAsRequired(),
                                TableColumn::make('Satuan')->width('110px'),
                                TableColumn::make('Harga')->width('150px')->markAsRequired(),
                                TableColumn::make('Jumlah Sebelum Pajak')->width('150px'),
                                TableColumn::make('Dasar Pajak')->width('120px'),
                                TableColumn::make('Pajak')->width('120px'),
                            ])
                            ->schema([
                                Grid::make(1)->schema([
                                    Hidden::make('item_name'),
                                    ModalTableSelect::make('item_id')
                                        ->hiddenLabel()
                                        ->placeholder('Pilih barang')
                                        ->relationship(
                                            name: 'item',
                                            titleAttribute: 'name',
                                            modifyQueryUsing: fn ($query) => $query->where('is_active', true),
                                        )
                                        ->tableConfiguration(PurchaseOrderItemPickerTable::class)
                                        ->getOptionLabelFromRecordUsing(fn (Item $record): string => $record->code.' - '.$record->name)
                                        ->selectAction(fn (Action $action): Action => $action
                                            ->label('Pilih barang')
                                            ->icon(Heroicon::Plus)
                                            ->iconButton()
                                            ->tooltip('Pilih barang')
                                            ->modalHeading('Pilih Barang')
                                            ->modalWidth(Width::FiveExtraLarge))
                                        ->live()
                                        ->afterStateUpdated(function (mixed $state, Set $set): void {
                                            $item = Item::query()->find($state);

                                            if (! $item) {
                                                return;
                                            }

                                            $set('item_name', $item->name);
                                            $set('specification', $item->specification);
                                            $set('unit_id', $item->unit_id);
                                        }),
                                ]),
                                TextInput::make('specification')
                                    ->label('Spesifikasi')
                                    ->maxLength(500),
                                TextInput::make('quantity')
                                    ->label('Kuantitas')
                                    ->numeric()
                                    // Beberapa dokumen historis ECOUNT memakai baris keterangan
                                    // dengan kuantitas nol. Nilai itu harus tetap dapat disimpan.
                                    ->minValue(0)
                                    ->default(1)
                                    ->required()
                                    ->live(onBlur: true)
                                    ->afterStateUpdated(fn (Get $get, Set $set) => self::updateLineTotal($get, $set)),
                                Select::make('unit_id')
                                    ->label('Satuan')
                                    ->relationship(
                                        name: 'unit',
                                        titleAttribute: 'name',
                                        modifyQueryUsing: fn ($query) => $query
                                            ->where('is_active', true)
                                            ->orderBy('name'),
                                    )
                                    ->getOptionLabelFromRecordUsing(fn (Unit $record): string => $record->code.' - '.$record->name)
                                    ->searchable(['code', 'name'])
                                    ->required(fn (Get $get, ?PurchaseOrderItem $record): bool => $record === null && filled($get('item_id')))
                                    ->placeholder('Pilih satuan'),
                                TextInput::make('unit_price_amount')
                                    ->label('Harga')
                                    ->numeric()
                                    // ECOUNT merepresentasikan diskon sebagai baris harga negatif.
                                    ->default(0)
                                    ->required()
                                    ->prefix(fn (Get $get): string => CurrencyFormatter::prefix($get('../../currency') ?? PurchaseOrder::CURRENCY_IDR))
                                    ->live(onBlur: true)
                                    ->afterStateUpdated(fn (Get $get, Set $set) => self::updateLineTotal($get, $set)),
                                Placeholder::make('line_total_preview')
                                    ->label('Subtotal')
                                    ->content(fn (Get $get): string => CurrencyFormatter::format(
                                        self::money($get('quantity')) * self::money($get('unit_price_amount')),
                                        $get('../../currency') ?? PurchaseOrder::CURRENCY_IDR,
                                    )),
                                Placeholder::make('taxable_base_preview')
                                    ->label('Dasar Pajak')
                                    ->content(fn (Get $get): string => CurrencyFormatter::format(
                                        self::money($get('quantity')) * self::money($get('unit_price_amount')),
                                        $get('../../currency') ?? PurchaseOrder::CURRENCY_IDR,
                                    )),
                                Placeholder::make('line_tax_preview')
                                    ->label('Nilai Pajak')
                                    ->content(fn (Get $get): string => CurrencyFormatter::format(
                                        self::lineTax(
                                            $get('quantity'),
                                            $get('unit_price_amount'),
                                            $get('../../addition_tax_rate'),
                                        ) - self::lineTax(
                                            $get('quantity'),
                                            $get('unit_price_amount'),
                                            $get('../../deduction_tax_rate'),
                                        ),
                                        $get('../../currency') ?? PurchaseOrder::CURRENCY_IDR,
                                    )),
                            ])
                            ->orderColumn('line_number')
                            ->defaultItems(1)
                            ->minItems(1)
                            ->addActionLabel('Tambah Item')
                            ->columnSpanFull(),
                    ])
                    ->columnSpanFull(),

                Section::make('Invoice Tertaut')
                    ->schema([
                        Placeholder::make('linked_invoices')
                            ->hiddenLabel()
                            ->content(fn (?PurchaseOrder $record) => view('filament.purchase-orders.linked-invoices', [
                                'invoices' => $record?->invoices()
                                    ->with('paymentSlip')
                                    ->orderBy('invoice_date')
                                    ->orderBy('invoice_number')
                                    ->get() ?? collect(),
                            ])),
                    ])
                    ->visibleOn('view')
                    ->columnSpanFull(),

                Section::make('Ringkasan')
                    ->schema([
                        Grid::make(3)->schema([
                            Placeholder::make('subtotal_preview')
                                ->label('Subtotal')
                                ->content(fn (Get $get): string => CurrencyFormatter::format(
                                    self::itemsSubtotal($get('items')),
                                    $get('currency') ?? PurchaseOrder::CURRENCY_IDR,
                                )),
                            Placeholder::make('addition_tax_preview')
                                ->label('PPN (penambahan)')
                                ->content(fn (Get $get): string => CurrencyFormatter::format(
                                    self::itemsTax($get('items'), $get('addition_tax_rate')),
                                    $get('currency') ?? PurchaseOrder::CURRENCY_IDR,
                                )),
                            Placeholder::make('deduction_tax_preview')
                                ->label('PPh (potongan)')
                                ->content(fn (Get $get): string => CurrencyFormatter::format(
                                    self::itemsTax($get('items'), $get('deduction_tax_rate')),
                                    $get('currency') ?? PurchaseOrder::CURRENCY_IDR,
                                )),
                            Placeholder::make('discount_preview')
                                ->label('Diskon')
                                ->content(fn (Get $get): string => CurrencyFormatter::format(
                                    self::money($get('discount_amount')),
                                    $get('currency') ?? PurchaseOrder::CURRENCY_IDR,
                                )),
                            Placeholder::make('shipping_preview')
                                ->label('Biaya Kirim')
                                ->content(fn (Get $get): string => CurrencyFormatter::format(
                                    self::money($get('shipping_amount')),
                                    $get('currency') ?? PurchaseOrder::CURRENCY_IDR,
                                )),
                            Placeholder::make('grand_total_preview')
                                ->label('Total')
                                ->content(fn (Get $get): string => CurrencyFormatter::format(
                                    self::documentTotal(
                                        $get('items'),
                                        $get('addition_tax_rate'),
                                        $get('deduction_tax_rate'),
                                        $get('discount_amount'),
                                        $get('shipping_amount'),
                                    ),
                                    $get('currency') ?? PurchaseOrder::CURRENCY_IDR,
                                )),
                        ])->columnSpanFull(),
                    ])
                    ->columnSpanFull(),
            ]);
    }

    private static function updateLineTotal(Get $get, Set $set): void
    {
        $set('subtotal_amount', self::money($get('quantity')) * self::money($get('unit_price_amount')));
    }

    private static function applyAdditionTaxToAllItems(mixed $state, Get $get, Set $set): void
    {
        $taxId = Tax::query()
            ->whereKey($state)
            ->where('calculation_type', 'addition')
            ->where('is_active', true)
            ->value('id');
        $set('addition_tax_rate', $taxId ? Tax::query()->whereKey($taxId)->value('rate') : null);
    }

    private static function applyDeductionTaxToAllItems(mixed $state, Get $get, Set $set): void
    {
        $taxId = Tax::query()
            ->whereKey($state)
            ->where('calculation_type', 'deduction')
            ->where('is_active', true)
            ->value('id');
        $set('deduction_tax_rate', $taxId ? Tax::query()->whereKey($taxId)->value('rate') : null);
    }

    private static function itemsSubtotal(mixed $items): float
    {
        return collect(is_array($items) ? $items : [])->sum(
            fn (array $item): float => self::money($item['quantity'] ?? 0) * self::money($item['unit_price_amount'] ?? 0),
        );
    }

    private static function itemsTax(mixed $items, mixed $rate): float
    {
        return collect(is_array($items) ? $items : [])->sum(
            fn (array $item): float => self::lineTax(
                $item['quantity'] ?? 0,
                $item['unit_price_amount'] ?? 0,
                $rate,
            ),
        );
    }

    private static function lineTax(mixed $quantity, mixed $unitPrice, mixed $rate): float
    {
        return self::money($quantity) * self::money($unitPrice) * (self::money($rate) / 100);
    }

    /**
     * Total dokumen = subtotal + penambahan pajak - potongan pajak - diskon + biaya kirim.
     */
    private static function documentTotal(
        mixed $items,
        mixed $additionRate,
        mixed $deductionRate,
        mixed $discount,
        mixed $shipping,
    ): float {
        return self::itemsSubtotal($items)
            + self::itemsTax($items, $additionRate)
            - self::itemsTax($items, $deductionRate)
            - self::money($discount)
            + self::money($shipping);
    }

    private static function taxLabel(Tax $tax): string
    {
        return sprintf('%s (%s%%)', $tax->name, rtrim(rtrim(number_format((float) $tax->rate, 4, '.', ''), '0'), '.'));
    }

    private static function money(mixed $value): float
    {
        if (is_numeric($value)) {
            return (float) $value;
        }

        $normalized = preg_replace('/[^\d,.-]/', '', (string) $value) ?? '0';

        if (str_contains($normalized, ',') && str_contains($normalized, '.')) {
            $normalized = strrpos($normalized, ',') > strrpos($normalized, '.')
                ? str_replace(['.', ','], ['', '.'], $normalized)
                : str_replace(',', '', $normalized);
        } elseif (str_contains($normalized, ',')) {
            $normalized = str_replace(',', '.', $normalized);
        }

        return (float) $normalized;
    }
}
