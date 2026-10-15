<?php

namespace App\Filament\Resources\PaymentSlips\Schemas;

use App\Models\DocumentFile;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\PaymentSlip;
use App\Models\Tax;
use App\Support\CurrencyFormatter;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ViewField;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;

class GeneralPaymentSlipForm extends PaymentSlipFormSupport
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                ViewField::make('status_progress')
                    ->view('filament.components.status-steps')
                    ->columnSpanFull()
                    ->hidden(fn (string $operation) => $operation === 'create'),

                Section::make('Transaction Details')->schema([
                    TextInput::make('slip_number')
                        ->label('Slip Number')
                        ->disabled()
                        ->visible(fn (string $operation) => $operation !== 'create'),
                    Select::make('transaction_type')
                        ->options([PaymentSlip::TYPE_GENERAL => 'Lain-lain'])
                        ->required()
                        ->disabled()
                        ->dehydrated(),
                    Hidden::make('tax_calculation_mode')
                        ->default(PaymentSlip::TAX_MODE_ITEMIZED),
                    Select::make('currency')
                        ->label('Currency')
                        ->options([
                            PaymentSlip::CURRENCY_IDR => 'IDR - Rupiah',
                            PaymentSlip::CURRENCY_USD => 'USD - US Dollar',
                            PaymentSlip::CURRENCY_EUR => 'EUR - Euro',
                        ])
                        ->default(PaymentSlip::CURRENCY_IDR)
                        ->required()
                        ->live()
                        ->disabled(fn (?object $record): bool => $record !== null
                            && $record->status !== 'draft'
                            && ! ($record->status === 'submitted' && auth()->user()?->hasRole('checker')))
                        ->dehydrated(),
                    TextInput::make('transaction_description')
                        ->label('Keterangan Transaksi')
                        ->placeholder('Contoh: biaya operasional kantor')
                        ->helperText('Tampil pada PDF sebagai “CHARGE [KETERANGAN]”.')
                        ->maxLength(255)
                        ->required()
                        ->disabled(fn (?object $record): bool => $record !== null && $record->status !== 'draft'),
                    Select::make('supplier_id')
                        ->relationship('supplier', 'name')
                        ->searchable()
                        ->required()
                        ->placeholder('Pilih Supplier')
                        ->disabled(fn (?object $record) => $record && $record->status !== 'draft')
                        ->createOptionForm([
                            TextInput::make('code')
                                ->label('Supplier Code')
                                ->required()
                                ->unique('suppliers', 'code')
                                ->placeholder('Contoh: SUP-001'),
                            TextInput::make('name')
                                ->label('Supplier Name')
                                ->required()
                                ->placeholder('Nama Perusahaan/Supplier'),
                            Textarea::make('address')
                                ->label('Address')
                                ->columnSpanFull()
                                ->placeholder('Alamat Lengkap'),
                        ]),
                    Select::make('customer_id')
                        ->label('Customer')
                        ->relationship('customer', 'name')
                        ->searchable()
                        ->placeholder('Pilih Customer (opsional)')
                        ->disabled(fn (?object $record): bool => $record !== null && $record->status !== 'draft')
                        ->createOptionForm([
                            TextInput::make('code')
                                ->label('Customer Code')
                                ->required()
                                ->unique('customers', 'code')
                                ->placeholder('Contoh: CUST-001'),
                            TextInput::make('name')
                                ->label('Customer Name')
                                ->required()
                                ->placeholder('Nama Customer'),
                            Textarea::make('address')
                                ->label('Address')
                                ->columnSpanFull()
                                ->placeholder('Alamat Lengkap'),
                        ]),
                    TextInput::make('maker_name')
                        ->label('Nama')
                        ->formatStateUsing(fn (?object $record) => $record?->creator?->name ?? '-')
                        ->disabled()
                        ->visible(fn (string $operation) => $operation === 'view'),
                    TextInput::make('division_name')
                        ->label('Division')
                        ->formatStateUsing(fn (?object $record) => $record?->creator?->division?->name ?? '-')
                        ->disabled()
                        ->visible(fn (string $operation) => $operation === 'view'),
                    TextInput::make('created_at_display')
                        ->label('Date')
                        ->formatStateUsing(fn (?object $record) => $record?->created_at ? Carbon::parse($record->created_at)->format('d/m/Y H:i') : '-')
                        ->disabled()
                        ->visible(fn (string $operation) => $operation === 'view'),
                ])->columns(2)->columnSpanFull(),

                Section::make('Invoices')
                    ->columnSpanFull()
                    ->schema([
                        Repeater::make('invoices')
                            ->relationship('invoices')
                            ->live(condition: fn (string $operation): bool => $operation !== 'edit')
                            ->required()
                            ->minItems(1)
                            ->addable(fn (?object $record) => ! $record || $record->status === 'draft')
                            ->deletable(fn (?object $record) => ! $record || $record->status === 'draft')
                            ->mutateRelationshipDataBeforeCreateUsing(fn (array $data, Get $get): array => self::prepareInvoiceTaxMode($data, $get))
                            ->mutateRelationshipDataBeforeSaveUsing(fn (array $data, Get $get): array => self::prepareInvoiceTaxMode($data, $get))
                            ->schema([
                                Placeholder::make('source_po_summary')
                                    ->label('Referensi PO')
                                    ->content(fn (?Invoice $record): string => $record?->purchaseOrder?->po_number ?? '—')
                                    ->visible(fn (?Invoice $record): bool => (bool) $record?->purchase_order_id),
                                Placeholder::make('discount_summary')
                                    ->label('Diskon (pengurang)')
                                    ->content(fn (?Invoice $record): string => CurrencyFormatter::format($record?->discount_amount ?? 0, $record?->paymentSlip?->currency ?? 'IDR'))
                                    ->visible(fn (?Invoice $record): bool => $record?->paymentSlip?->status === 'submitted'),
                                Placeholder::make('shipping_summary')
                                    ->label('Ongkir')
                                    ->content(fn (?Invoice $record): string => CurrencyFormatter::format($record?->shipping_amount ?? 0, $record?->paymentSlip?->currency ?? 'IDR'))
                                    ->visible(fn (?Invoice $record): bool => $record?->paymentSlip?->status === 'submitted'),
                                TextInput::make('invoice_number')
                                    ->label('Nomor Nota')
                                    ->required()
                                    ->disabled(fn (?Invoice $record) => $record && $record->paymentSlip?->status !== 'draft'),
                                DatePicker::make('invoice_date')
                                    ->required()
                                    ->disabled(fn (?Invoice $record) => $record && $record->paymentSlip?->status !== 'draft'),
                                TextInput::make('vat_invoice_number')
                                    ->label('VAT Invoice No. Utama')
                                    ->helperText('Nomor faktur pajak invoice utama; digunakan pada baris AP ERP.')
                                    ->maxLength(255)
                                    ->disabled(fn (?Invoice $record): bool => $record && ! (
                                        $record->paymentSlip?->status === 'draft'
                                        || (
                                            $record->paymentSlip?->status === 'submitted'
                                            && auth()->user()?->hasRole('checker')
                                        )
                                    )),
                                Hidden::make('tax_calculation_mode')
                                    ->default(PaymentSlip::TAX_MODE_ITEMIZED),
                                Select::make('document_file_id')
                                    ->label('Document file')
                                    ->options(fn (Select $component): array => self::selectedDocumentFileOption($component))
                                    ->disabled()
                                    ->dehydrated(),

                                Actions::make([
                                    Action::make('upload_manual_pdf')
                                        ->label(fn (Get $get) => $get('document_file_id') ? 'Ganti PDF' : 'Upload PDF')
                                        ->icon('heroicon-o-arrow-up-tray')
                                        ->color('success')
                                        ->hidden(function (string $operation, ?object $record): bool {
                                            $paymentSlip = self::paymentSlipForActionRecord($record);

                                            return $operation === 'view' || ($paymentSlip !== null && $paymentSlip->status !== 'draft');
                                        })
                                        ->form([
                                            FileUpload::make('manual_pdf')
                                                ->label('File PDF Invoice')
                                                ->disk('local')
                                                ->directory('invoice-uploads')
                                                ->acceptedFileTypes(['application/pdf'])
                                                ->maxSize(102400)
                                                ->required(),
                                        ])
                                        ->action(function (array $data, Set $set) {
                                            $path = $data['manual_pdf'];
                                            $fullPath = Storage::disk('local')->path($path);

                                            $doc = DocumentFile::create([
                                                'disk' => 'local',
                                                'path' => $path,
                                                'original_name' => basename($path),
                                                'mime_type' => 'application/pdf',
                                                'size_bytes' => Storage::disk('local')->size($path),
                                                'checksum' => md5_file($fullPath),
                                                'uploaded_at' => now(),
                                            ]);

                                            $set('document_file_id', $doc->id);
                                        }),

                                    Action::make('preview_pdf')
                                        ->label('Preview PDF')
                                        ->icon('heroicon-o-eye')
                                        ->color('info')
                                        ->visible(fn (Get $get) => filled($get('document_file_id')))
                                        ->action(function (Get $get, $livewire) {
                                            $docId = $get('document_file_id');
                                            if ($docId && method_exists($livewire, 'setActivePdf')) {
                                                $livewire->setActivePdf($docId);
                                            }
                                        }),
                                ]),

                                TextInput::make('subtotal_amount')
                                    ->label('Subtotal')
                                    ->prefix(fn (Get $get): string => CurrencyFormatter::prefix($get('../../currency') ?? 'IDR'))
                                    ->formatStateUsing(fn ($state, Get $get) => CurrencyFormatter::formatFormState($state, $get('../../currency') ?? 'IDR'))
                                    ->dehydrateStateUsing(fn ($state) => self::money($state))
                                    ->readOnly()
                                    ->live(debounce: 500)
                                    ->afterStateUpdated(function (Get $get, Set $set, $state) {
                                        $subtotal = self::money($state);
                                        self::setCalculatedAmounts($set, $subtotal, $get('ppn_tax_id'), $get('pph_tax_id'), $get('../../currency') ?? PaymentSlip::CURRENCY_IDR);
                                    }),

                                Select::make('ppn_tax_id')
                                    ->label('PPN (Penambahan)')
                                    ->options(fn () => Tax::where('calculation_type', 'addition')->where('is_active', true)->pluck('name', 'id'))
                                    ->preload()
                                    ->placeholder('Tanpa PPN')
                                    ->visible(fn (Get $get, ?Invoice $record): bool => ! self::isItemizedInvoice($get, $record))
                                    ->live()
                                    ->afterStateUpdated(function (Get $get, Set $set, $state) {
                                        $subtotal = self::money($get('subtotal_amount'));
                                        self::setCalculatedAmounts($set, $subtotal, $state, $get('pph_tax_id'), $get('../../currency') ?? PaymentSlip::CURRENCY_IDR);
                                    }),

                                Select::make('pph_tax_id')
                                    ->label('PPh (Pengurangan)')
                                    ->options(fn () => Tax::where('calculation_type', 'deduction')->where('is_active', true)->pluck('name', 'id'))
                                    ->preload()
                                    ->placeholder('Tanpa PPh')
                                    ->visible(fn (Get $get, ?Invoice $record): bool => ! self::isItemizedInvoice($get, $record))
                                    ->live()
                                    ->afterStateUpdated(function (Get $get, Set $set, $state) {
                                        $subtotal = self::money($get('subtotal_amount'));
                                        self::setCalculatedAmounts($set, $subtotal, $get('ppn_tax_id'), $state, $get('../../currency') ?? PaymentSlip::CURRENCY_IDR);
                                    }),

                                TextInput::make('tax_addition_amount')
                                    ->label('Penambahan Pajak (PPN)')
                                    ->prefix(fn (Get $get): string => CurrencyFormatter::prefix($get('../../currency') ?? 'IDR'))
                                    ->formatStateUsing(fn ($state, Get $get) => CurrencyFormatter::formatFormStateNoDecimals($state, $get('../../currency') ?? 'IDR'))
                                    ->dehydrateStateUsing(fn ($state) => self::money($state))
                                    ->readOnly()->dehydrated(false),

                                TextInput::make('tax_deduction_amount')
                                    ->label(fn (Get $get, ?Invoice $record): string => self::isItemizedInvoice($get, $record) ? 'Total PPh' : 'Pengurangan Pajak (PPh)')
                                    ->prefix(fn (Get $get): string => CurrencyFormatter::prefix($get('../../currency') ?? 'IDR'))
                                    ->formatStateUsing(fn ($state, Get $get) => CurrencyFormatter::formatFormStateNoDecimals($state, $get('../../currency') ?? 'IDR'))
                                    ->dehydrateStateUsing(fn ($state) => self::money($state))
                                    ->readOnly()
                                    ->dehydrated(false),

                                TextInput::make('grand_total_amount')
                                    ->label('Amount Dibayar')
                                    ->prefix(fn (Get $get): string => CurrencyFormatter::prefix($get('../../currency') ?? 'IDR'))
                                    ->formatStateUsing(fn ($state, Get $get) => CurrencyFormatter::formatFormStateNoDecimals($state, $get('../../currency') ?? 'IDR'))
                                    ->dehydrateStateUsing(fn ($state) => self::money($state))
                                    ->readOnly()->dehydrated(false),

                                Repeater::make('items')
                                    ->relationship('items')
                                    ->required()
                                    ->minItems(1)
                                    ->mutateRelationshipDataBeforeFillUsing(fn (array $data): array => auth()->user()?->hasRole('maker') && ! auth()->user()?->hasRole('checker') ? Arr::except($data, ['coa_id', 'coa_code_snapshot', 'coa_name_snapshot']) : $data)
                                    ->addable(fn (?Invoice $record) => ! $record || $record->paymentSlip?->status === 'draft')
                                    ->deletable(fn (?Invoice $record) => ! $record || $record->paymentSlip?->status === 'draft')
                                    ->schema([
                                        TextInput::make('item_name')
                                            ->required()
                                            ->disabled(fn (?InvoiceItem $record) => $record && $record->invoice?->paymentSlip?->status !== 'draft'),
                                        TextInput::make('quantity')
                                            ->numeric()
                                            ->default(1)
                                            ->required()
                                            ->live(onBlur: true)
                                            ->afterStateUpdated(fn (Get $get, Set $set) => self::recalculateInvoiceFromItem($get, $set))
                                            ->disabled(fn (?InvoiceItem $record) => $record && $record->invoice?->paymentSlip?->status !== 'draft'),
                                        Select::make('unit_id')
                                            ->label('Satuan')
                                            ->relationship('unit', 'name')
                                            ->searchable()
                                            ->preload()
                                            ->placeholder('Pilih Satuan')
                                            ->required(fn (?InvoiceItem $record): bool => $record === null)
                                            ->disabled(fn (?InvoiceItem $record): bool => $record && $record->invoice?->paymentSlip?->status !== 'draft')
                                            ->createOptionForm([
                                                TextInput::make('code')
                                                    ->label('Kode Satuan')
                                                    ->placeholder('Contoh: PCS')
                                                    ->required()
                                                    ->unique('units', 'code'),
                                                TextInput::make('name')
                                                    ->label('Nama Satuan')
                                                    ->placeholder('Contoh: Pieces')
                                                    ->required(),
                                            ]),
                                        TextInput::make('unit_price_amount')
                                            ->numeric()
                                            ->prefix(fn (Get $get): string => CurrencyFormatter::prefix($get('../../../../currency') ?? 'IDR'))
                                            ->required()
                                            ->live(onBlur: true)
                                            ->afterStateUpdated(fn (Get $get, Set $set) => self::recalculateInvoiceFromItem($get, $set))
                                            ->disabled(fn (?InvoiceItem $record) => $record && $record->invoice?->paymentSlip?->status !== 'draft'),
                                        Select::make('ppn_tax_id')
                                            ->label('PPN')
                                            ->options(fn (): array => Tax::query()
                                                ->where('calculation_type', 'addition')
                                                ->where('is_active', true)
                                                ->orderBy('name')
                                                ->pluck('name', 'id')
                                                ->all())
                                            ->placeholder('Tanpa PPN')
                                            ->preload()
                                            ->live()
                                            ->afterStateUpdated(fn (Get $get, Set $set) => self::recalculateInvoiceFromItem($get, $set, 'ppn'))
                                            ->visible(fn (Get $get, ?InvoiceItem $record): bool => self::isItemizedItem($get, $record))
                                            ->disabled(fn (?InvoiceItem $record) => $record && $record->invoice?->paymentSlip?->status !== 'draft'),
                                        Hidden::make('tax_addition_amount')
                                            ->default(0)
                                            ->dehydrated(false),
                                        Placeholder::make('ppn_amount_preview')
                                            ->label('PPN Nominal')
                                            ->content(fn (Get $get): string => CurrencyFormatter::format(self::money($get('tax_addition_amount')), $get('../../../../currency') ?? 'IDR'))
                                            ->visible(fn (Get $get, ?InvoiceItem $record): bool => self::isItemizedItem($get, $record)),
                                        Select::make('pph_tax_id')
                                            ->label('PPh')
                                            ->options(fn (): array => Tax::query()
                                                ->where('calculation_type', 'deduction')
                                                ->where('is_active', true)
                                                ->orderBy('name')
                                                ->pluck('name', 'id')
                                                ->all())
                                            ->placeholder('Tanpa PPh')
                                            ->preload()
                                            ->live()
                                            ->afterStateUpdated(fn (Get $get, Set $set) => self::recalculateInvoiceFromItem($get, $set, 'pph'))
                                            ->visible(fn (Get $get, ?InvoiceItem $record): bool => self::isItemizedItem($get, $record))
                                            ->disabled(fn (?InvoiceItem $record) => $record && $record->invoice?->paymentSlip?->status !== 'draft'),
                                        Hidden::make('tax_deduction_amount')
                                            ->default(0)
                                            ->dehydrated(false),
                                        Placeholder::make('pph_amount_preview')
                                            ->label('PPh Nominal')
                                            ->content(fn (Get $get): string => CurrencyFormatter::format(self::money($get('tax_deduction_amount')), $get('../../../../currency') ?? 'IDR'))
                                            ->visible(fn (Get $get, ?InvoiceItem $record): bool => self::isItemizedItem($get, $record)),
                                        Placeholder::make('net_amount_preview')
                                            ->label('Amount Dibayar')
                                            ->content(fn (Get $get): string => CurrencyFormatter::format(self::money($get('net_amount')), $get('../../../../currency') ?? 'IDR'))
                                            ->visible(fn (Get $get, ?InvoiceItem $record): bool => self::isItemizedItem($get, $record)),
                                        Select::make('coa_id')
                                            ->label('COA')
                                            ->relationship('chartOfAccount', 'name')
                                            ->getOptionLabelFromRecordUsing(fn ($record): string => $record->code.' - '.$record->name)
                                            ->searchable(['code', 'name'])
                                            ->preload()
                                            ->required(fn (?InvoiceItem $record): bool => auth()->user()?->hasRole('checker') && $record?->invoice?->paymentSlip?->status === 'submitted')
                                            ->hidden(fn (): bool => ! auth()->user()?->hasRole('checker'))
                                            ->disabled(fn (?InvoiceItem $record): bool => ! $record || $record->invoice?->paymentSlip?->status !== 'submitted'),
                                    ])
                                    ->columns(['default' => 1, 'md' => 2, '2xl' => 4]),
                            ])
                            ->defaultItems(0),
                    ]),

                Section::make('Overview')
                    ->columnSpanFull()
                    ->schema([
                        Placeholder::make('grand_total_amount_view')
                            ->label('Amount')
                            ->content(function (Get $get) {
                                $invoices = $get('invoices') ?? [];
                                $total = 0;
                                foreach ($invoices as $inv) {
                                    $total += self::money($inv['grand_total_amount'] ?? '0');
                                }

                                return CurrencyFormatter::format($total, $get('currency') ?? 'IDR');
                            }),
                        Select::make('status')
                            ->options([
                                'draft' => 'Draft',
                                'submitted' => 'Submitted',
                                'pending_approval' => 'Pending Approval (legacy)',
                                'approved' => 'Verified',
                                'exported' => 'Exported',
                            ])
                            ->disabled(),
                    ])->columns(2),
            ]);
    }

    protected static function isItemizedMode(Get $get): bool
    {
        return true;
    }

    protected static function isItemizedInvoice(Get $get, ?Invoice $record): bool
    {
        return true;
    }

    protected static function isItemizedItem(Get $get, ?InvoiceItem $record): bool
    {
        return true;
    }

    /** @param array<string, mixed> $data */
    protected static function prepareInvoiceTaxMode(array $data, Get $get): array
    {
        $data['tax_calculation_mode'] = PaymentSlip::TAX_MODE_ITEMIZED;
        $data['ppn_tax_id'] = null;
        $data['pph_tax_id'] = null;

        return $data;
    }
}
