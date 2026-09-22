<?php

namespace App\Filament\Resources\PaymentSlips\Schemas;

use App\Models\DocumentFile;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\PaymentSlip;
use App\Models\Supplier;
use App\Models\Tax;
use App\Services\DocumentFileRegistrar;
use App\Services\GeminiInvoiceExtractor;
use App\Services\InvoiceAmountCalculator;
use App\Services\InvoiceExtractionDataMapper;
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
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class PaymentSlipForm
{
    private const CLEAR_BULK_TAX = '__clear_tax__';

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
                        ->options(function ($livewire, ?object $record): array {
                            if ($record) {
                                return PaymentSlip::TRANSACTION_TYPE_LABELS;
                            }

                            return method_exists($livewire, 'getFixedTransactionType') && $livewire->getFixedTransactionType() === PaymentSlip::TYPE_GENERAL
                                ? [PaymentSlip::TYPE_GENERAL => 'Lain-lain']
                                : [PaymentSlip::TYPE_EXPORT => 'Export', PaymentSlip::TYPE_IMPORT => 'Import'];
                        })
                        ->required()
                        ->live()
                        ->disabled(fn (?object $record, $livewire): bool => $record !== null
                            || (method_exists($livewire, 'getFixedTransactionType') && $livewire->getFixedTransactionType() === PaymentSlip::TYPE_GENERAL))
                        ->dehydrated(),
                    Hidden::make('tax_calculation_mode')
                        ->default(PaymentSlip::TAX_MODE_ITEMIZED),
                    Select::make('currency')
                        ->label('Currency')
                        ->options([
                            PaymentSlip::CURRENCY_IDR => 'IDR - Rupiah',
                            PaymentSlip::CURRENCY_USD => 'USD - US Dollar',
                        ])
                        ->default(PaymentSlip::CURRENCY_IDR)
                        ->required()
                        ->live()
                        ->visible(fn (Get $get): bool => $get('transaction_type') === PaymentSlip::TYPE_GENERAL)
                        ->disabled(fn (?object $record): bool => $record !== null
                            && $record->status !== 'draft'
                            && ! ($record->status === 'submitted' && auth()->user()?->hasRole('checker')))
                        ->dehydrated(),
                    TextInput::make('invoice_receipt_number')
                        ->label('Nomor Tanda Terima Invoice')
                        ->placeholder('HIJ / 001 / VIII / 2026')
                        ->maxLength(100)
                        ->visible(fn (Get $get): bool => in_array($get('transaction_type'), [PaymentSlip::TYPE_IMPORT, PaymentSlip::TYPE_EXPORT], true))
                        ->disabled(fn (?object $record): bool => $record !== null
                            && $record->status !== 'draft'
                            && ! ($record->status === 'submitted' && auth()->user()?->hasRole('checker'))),
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
                    Select::make('buyer_id')
                        ->relationship('buyer', 'name')
                        ->searchable()
                        ->required(fn (Get $get, ?PaymentSlip $record): bool => $get('transaction_type') === PaymentSlip::TYPE_EXPORT && $record !== null && ! $record->usesItemizedTaxes())
                        ->visible(fn (Get $get, ?PaymentSlip $record): bool => $get('transaction_type') === PaymentSlip::TYPE_EXPORT && $record !== null && ! $record->usesItemizedTaxes())
                        ->placeholder('Pilih Buyer')
                        ->disabled(fn (?object $record) => $record && $record->status !== 'draft')
                        ->createOptionForm([
                            TextInput::make('code')
                                ->label('Buyer Code')
                                ->required()
                                ->unique('buyers', 'code')
                                ->placeholder('Contoh: BUY-001'),
                            TextInput::make('name')
                                ->label('Buyer Name')
                                ->required()
                                ->placeholder('Nama Perusahaan/Buyer'),
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
                    ->headerActions([
                        Action::make('extract_via_gemini')
                            ->label('Extract Invoices via Gemini')
                            ->icon('heroicon-o-sparkles')
                            ->hidden(fn (string $operation, ?object $record, Get $get): bool => $operation === 'view'
                                || $get('transaction_type') === PaymentSlip::TYPE_GENERAL
                                || ($record && $record->status !== 'draft'))
                            ->form([
                                FileUpload::make('raw_pdf_files')
                                    ->label('Upload PDF Invoice Bundles')
                                    ->helperText('Satu file PDF harus mewakili satu invoice utama. Faktur pajak, kuitansi, dan dokumen reimbursement boleh tetap dilampirkan di dalam file yang sama.')
                                    ->multiple()
                                    ->disk('local')
                                    ->directory('invoice-uploads')
                                    ->storeFileNamesIn('raw_pdf_file_names')
                                    ->acceptedFileTypes(['application/pdf'])
                                    ->maxSize(102400)
                                    ->required(),
                                Hidden::make('raw_pdf_file_names'),
                            ])
                            ->action(function (array $data, Set $set, Get $get) {
                                $files = array_values($data['raw_pdf_files'] ?? []);
                                if ($files === []) {
                                    return;
                                }

                                $storedNames = $data['raw_pdf_file_names'] ?? [];
                                $fileNames = [];
                                $extractionFiles = [];
                                $payableSupplierName = filled($get('supplier_id'))
                                    ? Supplier::query()->whereKey($get('supplier_id'))->value('name')
                                    : null;

                                foreach ($files as $path) {
                                    $originalName = $storedNames[$path] ?? basename($path);
                                    $fileNames[$path] = $originalName;
                                    $extractionFiles[] = [
                                        'path' => Storage::disk('local')->path($path),
                                        'original_name' => $originalName,
                                    ];
                                }

                                $report = app(GeminiInvoiceExtractor::class)->extractWithReport($extractionFiles, $payableSupplierName);
                                $newInvoices = [];
                                $invoiceCount = 0;
                                $itemCount = 0;

                                foreach ($report['successful'] as $successful) {
                                    $storedPath = collect($files)->first(fn (string $path): bool => Storage::disk('local')->path($path) === $successful['path']);
                                    if (! $storedPath) {
                                        continue;
                                    }

                                    $documentIds = app(DocumentFileRegistrar::class)
                                        ->registerLocalUploads([$storedPath], $fileNames);
                                    $mapper = app(InvoiceExtractionDataMapper::class);
                                    $mappedInvoices = $mapper->toRepeaterState(
                                        $successful['invoices'],
                                        $documentIds,
                                        preferBaseAmount: $get('transaction_type') === PaymentSlip::TYPE_IMPORT
                                            || ($get('transaction_type') === PaymentSlip::TYPE_EXPORT && $get('tax_calculation_mode') === PaymentSlip::TAX_MODE_ITEMIZED),
                                    );
                                    $newInvoices = array_merge($newInvoices, $mappedInvoices);
                                    $invoiceCount += count($mappedInvoices);
                                    $itemCount += array_sum(array_map(
                                        static fn (array $invoice): int => count($invoice['items']),
                                        $mappedInvoices,
                                    ));
                                }

                                if ($newInvoices !== []) {
                                    $set('invoices', array_merge($get('invoices') ?? [], $newInvoices));
                                }

                                $failedNames = array_column($report['failed'], 'original_name');
                                $summary = count($report['successful']).' file berhasil, '.count($report['failed']).' file gagal. ';
                                $summary .= $invoiceCount.' invoice dan '.$itemCount.' item ditemukan.';

                                if ($report['failed'] === []) {
                                    Notification::make()->title('Ekstraksi berhasil')->body($summary)->success()->send();
                                } elseif ($report['successful'] !== []) {
                                    Notification::make()
                                        ->title('Ekstraksi selesai sebagian')
                                        ->body($summary.' File gagal: '.implode(', ', $failedNames).'. Data dari file yang berhasil tetap dimasukkan.')
                                        ->warning()
                                        ->persistent()
                                        ->send();
                                } else {
                                    $failureDetails = collect($report['failed'])
                                        ->map(fn (array $failure): string => $failure['original_name'].': '.$failure['message'])
                                        ->implode(' ');
                                    Notification::make()
                                        ->title('Ekstraksi AI gagal')
                                        ->body($failureDetails.' Silakan input invoice secara manual.')
                                        ->danger()
                                        ->persistent()
                                        ->send();
                                }
                            }),
                    ])
                    ->schema([
                        Repeater::make('invoices')
                            ->relationship('invoices')
                            ->live()
                            ->required()
                            ->minItems(1)
                            ->addable(fn (?object $record) => ! $record || $record->status === 'draft')
                            ->deletable(fn (?object $record) => ! $record || $record->status === 'draft')
                            ->schema([
                                Select::make('buyer_id')
                                    ->label('Buyer')
                                    ->relationship('buyer', 'name')
                                    ->searchable()
                                    ->preload()
                                    ->required(fn (Get $get, ?Invoice $record): bool => self::isEximInvoice($get, $record))
                                    ->visible(fn (Get $get, ?Invoice $record): bool => self::isEximInvoice($get, $record))
                                    ->placeholder('Pilih Buyer untuk invoice ini')
                                    ->disabled(fn (?Invoice $record): bool => $record && $record->paymentSlip?->status !== 'draft'),
                                TextInput::make('invoice_number')
                                    ->label(fn (Get $get): string => $get('../../transaction_type') === PaymentSlip::TYPE_GENERAL ? 'Nomor Nota' : 'Invoice number')
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
                                    ->relationship('documentFile', 'original_name')
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

                                Section::make('Pajak untuk Seluruh Invoice')
                                    ->description('Klik Terapkan untuk menghitung pajak sekali dari subtotal invoice. Baris PPN/PPh ERP menjadi satu per invoice, bukan per item. Pajak item yang sudah diisi akan dihapus setelah konfirmasi. Jika ada supplier pendukung atau VAT per item, gunakan pajak per item.')
                                    ->schema([
                                        Group::make([
                                            Select::make('bulk_ppn_tax_id')
                                                ->label('PPN invoice')
                                                ->options(fn (): array => [self::CLEAR_BULK_TAX => 'Tanpa PPN'] + Tax::query()
                                                    ->where('calculation_type', 'addition')
                                                    ->where('is_active', true)
                                                    ->orderBy('name')
                                                    ->pluck('name', 'id')
                                                    ->all())
                                                ->placeholder('Pilih tarif PPN invoice')
                                                ->preload()
                                                ->live()
                                                ->dehydrated(false),
                                            Actions::make([
                                                Action::make('apply_ppn_to_items')
                                                    ->label('Terapkan PPN')
                                                    ->icon('heroicon-o-arrow-down-on-square-stack')
                                                    ->disabled(fn (Get $get): bool => blank($get('bulk_ppn_tax_id')) || blank($get('items')))
                                                    ->requiresConfirmation(fn (Get $get): bool => self::hasItemTaxConflict($get))
                                                    ->modalHidden(fn (Get $get): bool => ! self::hasItemTaxConflict($get))
                                                    ->modalHeading('Pindahkan perhitungan ke level invoice?')
                                                    ->modalDescription('PPN dan PPh yang diisi per item akan dihapus. Pajak dihitung sekali dari subtotal invoice.')
                                                    ->modalSubmitActionLabel('Ya, gunakan pajak invoice')
                                                    ->action(fn (Get $get, Set $set, ?object $record) => self::applyInvoiceTax($get, $set, $record, 'ppn_tax_id', 'bulk_ppn_tax_id', 'addition')),
                                            ])->key('bulk_ppn_actions'),
                                        ])->columns(1),
                                        Group::make([
                                            Select::make('bulk_pph_tax_id')
                                                ->label('PPh invoice')
                                                ->options(fn (): array => [self::CLEAR_BULK_TAX => 'Tanpa PPh'] + Tax::query()
                                                    ->where('calculation_type', 'deduction')
                                                    ->where('is_active', true)
                                                    ->orderBy('name')
                                                    ->pluck('name', 'id')
                                                    ->all())
                                                ->placeholder('Pilih tarif PPh invoice')
                                                ->preload()
                                                ->live()
                                                ->dehydrated(false),
                                            Actions::make([
                                                Action::make('apply_pph_to_items')
                                                    ->label('Terapkan PPh')
                                                    ->icon('heroicon-o-arrow-down-on-square-stack')
                                                    ->disabled(fn (Get $get): bool => blank($get('bulk_pph_tax_id')) || blank($get('items')))
                                                    ->requiresConfirmation(fn (Get $get): bool => self::hasItemTaxConflict($get))
                                                    ->modalHidden(fn (Get $get): bool => ! self::hasItemTaxConflict($get))
                                                    ->modalHeading('Pindahkan perhitungan ke level invoice?')
                                                    ->modalDescription('PPN dan PPh yang diisi per item akan dihapus. Pajak dihitung sekali dari subtotal invoice.')
                                                    ->modalSubmitActionLabel('Ya, gunakan pajak invoice')
                                                    ->action(fn (Get $get, Set $set, ?object $record) => self::applyInvoiceTax($get, $set, $record, 'pph_tax_id', 'bulk_pph_tax_id', 'deduction')),
                                            ])->key('bulk_pph_actions'),
                                        ])->columns(1),
                                    ])
                                    ->columns(['default' => 1, '2xl' => 2])
                                    ->visible(fn (Get $get, ?Invoice $record, string $operation): bool => $operation !== 'view'
                                        && self::isItemizedExim($get, $record)
                                        && (! $record || $record->paymentSlip?->status === 'draft')),

                                Actions::make([
                                    Action::make('use_item_taxes')
                                        ->label('Kembali ke pajak per item')
                                        ->color('gray')
                                        ->requiresConfirmation()
                                        ->modalDescription('Pajak invoice akan dihapus. Pilih kembali tarif pada item yang perlu dikenakan pajak.')
                                        ->action(function (Get $get, Set $set, ?object $record): void {
                                            $paymentSlip = self::paymentSlipForActionRecord($record);
                                            abort_unless(! $paymentSlip || $paymentSlip->status === 'draft', 403);
                                            $set('tax_calculation_mode', PaymentSlip::TAX_MODE_ITEMIZED);
                                            $set('ppn_tax_id', null);
                                            $set('pph_tax_id', null);
                                            $set('tax_addition_amount', 0);
                                            $set('tax_deduction_amount', 0);
                                            $set('grand_total_amount', $get('subtotal_amount') ?? 0);
                                        }),
                                ])->visible(fn (Get $get, ?Invoice $record, string $operation): bool => $operation !== 'view'
                                    && self::isEximInvoice($get, $record)
                                    && ! self::isItemizedExim($get, $record)
                                    && (! $record || $record->paymentSlip?->status === 'draft')),

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
                                    ->visible(fn (Get $get, ?Invoice $record): bool => ! self::isItemizedExim($get, $record))
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
                                    ->visible(fn (Get $get, ?Invoice $record): bool => ! self::isItemizedExim($get, $record))
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
                                    ->label(fn (Get $get, ?Invoice $record): string => self::isItemizedExim($get, $record) ? 'Total PPh' : 'Pengurangan Pajak (PPh)')
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
                                        TextInput::make('unit_price_amount')
                                            ->numeric()
                                            ->prefix(fn (Get $get): string => CurrencyFormatter::prefix($get('../../../../currency') ?? 'IDR'))
                                            ->required()
                                            ->live(onBlur: true)
                                            ->afterStateUpdated(fn (Get $get, Set $set) => self::recalculateInvoiceFromItem($get, $set))
                                            ->disabled(fn (?InvoiceItem $record) => $record && $record->invoice?->paymentSlip?->status !== 'draft'),
                                        TextInput::make('source_supplier_name')
                                            ->label('Supplier Pendukung')
                                            ->helperText('Supplier pada nota atau biaya reimbursement ini.')
                                            ->maxLength(255)
                                            ->live(onBlur: true)
                                            ->visible(fn (Get $get): bool => $get('../../../../transaction_type') === PaymentSlip::TYPE_IMPORT)
                                            ->disabled(fn (?InvoiceItem $record) => $record && $record->invoice?->paymentSlip?->status !== 'draft'),
                                        TextInput::make('vat_invoice_number')
                                            ->label('VAT Invoice No. (Item)')
                                            ->maxLength(255)
                                            ->visible(fn (Get $get, ?InvoiceItem $record): bool => $get('../../../../transaction_type') === PaymentSlip::TYPE_IMPORT
                                                || self::isItemizedItem($get, $record))
                                            ->disabled(function (Get $get, ?InvoiceItem $record): bool {
                                                $status = $get('../../../../status');
                                                if ($status) {
                                                    return ! ($status === 'draft' || ($status === 'submitted' && auth()->user()?->hasRole('checker')));
                                                }

                                                return $record && ! (
                                                    $record->invoice?->paymentSlip?->status === 'draft'
                                                    || (
                                                        $record->invoice?->paymentSlip?->status === 'submitted'
                                                        && auth()->user()?->hasRole('checker')
                                                    )
                                                );
                                            }),
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

    private static function setCalculatedAmounts(Set $set, float $subtotal, mixed $ppnTaxId, mixed $pphTaxId, string $currency = PaymentSlip::CURRENCY_IDR): void
    {
        $amounts = InvoiceAmountCalculator::calculateForCurrency(
            $subtotal,
            Tax::find($ppnTaxId)?->rate,
            Tax::find($pphTaxId)?->rate,
            $currency,
        );

        $set('tax_addition_amount', CurrencyFormatter::formatFormStateNoDecimals($amounts['tax_addition'], $currency));
        $set('tax_deduction_amount', CurrencyFormatter::formatFormStateNoDecimals($amounts['tax_deduction'], $currency));
        $set('grand_total_amount', CurrencyFormatter::formatFormStateNoDecimals($amounts['grand_total'], $currency));
    }

    private static function recalculateInvoiceFromItem(Get $get, Set $set, ?string $changedTax = null): void
    {
        $transactionType = $get('../../../../transaction_type');
        $itemized = $transactionType !== PaymentSlip::TYPE_GENERAL
            && $get('../../tax_calculation_mode') !== PaymentSlip::TAX_MODE_INVOICE_LEGACY;
        $subtotal = self::money($get('quantity')) * self::money($get('unit_price_amount'));

        // Compute fresh tax amounts for the *current* item so we can use them
        // both for setting the item fields and for the net_amount calculation.
        $currentPpnAmount = 0.0;
        $currentPphAmount = 0.0;
        $currency = $get('../../../../currency') ?? 'IDR';

        if ($itemized) {
            $taxAmounts = InvoiceAmountCalculator::calculate(
                $subtotal,
                Tax::find($get('ppn_tax_id'))?->rate,
                Tax::find($get('pph_tax_id'))?->rate,
            );

            $currentPpnAmount = (filled($get('ppn_tax_id')) || $changedTax === 'ppn')
                ? $taxAmounts['tax_addition']
                : self::money($get('tax_addition_amount'));
            $currentPphAmount = (filled($get('pph_tax_id')) || $changedTax === 'pph')
                ? $taxAmounts['tax_deduction']
                : self::money($get('tax_deduction_amount'));

            if (filled($get('ppn_tax_id')) || $changedTax === 'ppn') {
                $set('tax_addition_amount', CurrencyFormatter::formatFormStateNoDecimals($taxAmounts['tax_addition'], $currency));
            }
            if (filled($get('pph_tax_id')) || $changedTax === 'pph') {
                $set('tax_deduction_amount', CurrencyFormatter::formatFormStateNoDecimals($taxAmounts['tax_deduction'], $currency));
            }
        }

        $net = $itemized
            ? $subtotal + $currentPpnAmount - $currentPphAmount
            : $subtotal;
        $set('net_amount', CurrencyFormatter::formatFormState($net, $currency));

        // Aggregate invoice totals from all items.  Instead of reading
        // tax_addition_amount / tax_deduction_amount hidden fields (which may
        // still hold stale values because $set() is deferred), recalculate
        // each item's taxes from its ppn_tax_id / pph_tax_id on-the-fly.
        $taxCache = [];
        $invoiceSubtotal = 0.0;
        $invoicePpn = 0.0;
        $invoicePph = 0.0;
        foreach (($get('../../items') ?? []) as $item) {
            $itemSubtotal = self::money($item['quantity'] ?? 0) * self::money($item['unit_price_amount'] ?? 0);
            $invoiceSubtotal += $itemSubtotal;
            if ($itemized) {
                $ppnId = $item['ppn_tax_id'] ?? null;
                $pphId = $item['pph_tax_id'] ?? null;
                $ppnRate = null;
                $pphRate = null;
                if (filled($ppnId)) {
                    $ppnRate = $taxCache[$ppnId] ??= Tax::find($ppnId)?->rate;
                }
                if (filled($pphId)) {
                    $pphRate = $taxCache[$pphId] ??= Tax::find($pphId)?->rate;
                }
                $itemTax = InvoiceAmountCalculator::calculate($itemSubtotal, $ppnRate, $pphRate);
                $invoicePpn += $itemTax['tax_addition'];
                $invoicePph += $itemTax['tax_deduction'];
            }
        }

        if (! $itemized) {
            $amounts = InvoiceAmountCalculator::calculateForCurrency(
                $invoiceSubtotal,
                Tax::find($get('../../ppn_tax_id'))?->rate,
                Tax::find($get('../../pph_tax_id'))?->rate,
                $currency,
            );
            $invoicePpn = $amounts['tax_addition'];
            $invoicePph = $amounts['tax_deduction'];
        }

        $set('../../subtotal_amount', CurrencyFormatter::formatFormState($invoiceSubtotal, $currency));
        $set('../../tax_addition_amount', CurrencyFormatter::formatFormStateNoDecimals($invoicePpn, $currency));
        $set('../../tax_deduction_amount', CurrencyFormatter::formatFormStateNoDecimals($invoicePph, $currency));
        $set('../../grand_total_amount', CurrencyFormatter::formatFormStateNoDecimals($invoiceSubtotal + $invoicePpn - $invoicePph, $currency));
    }

    /**
     * Keep the full repeater state in sync after a browser-driven item update.
     * Nested Select updates can reach Livewire without running the component hook,
     * so the page lifecycle provides a second, deterministic calculation path.
     *
     * @param  array<string, mixed>  $data
     */
    public static function recalculateLiveItemizedInvoice(array &$data, string $property): void
    {
        // Pattern 1: field-level update (e.g. data.invoices.{key}.items.{key}.ppn_tax_id)
        if (preg_match('/^data\.invoices\.([^.]+)\.items\.([^.]+)\.(quantity|unit_price_amount|ppn_tax_id|pph_tax_id)$/', $property, $matches)) {
            $changedField = $matches[3];
            // Pattern 2: item-level update without field suffix (e.g. data.invoices.{key}.items.{key})
            // Livewire sends this when a Select inside a programmatically populated
            // repeater (e.g. from Gemini extraction) is changed by the user.
        } elseif (preg_match('/^data\.invoices\.([^.]+)\.items\.([^.]+)$/', $property, $matches)) {
            $changedField = null;
        } else {
            return;
        }

        if (! in_array($data['transaction_type'] ?? null, [PaymentSlip::TYPE_IMPORT, PaymentSlip::TYPE_EXPORT], true)) {
            return;
        }

        [$invoiceKey, $itemKey] = array_slice($matches, 1);
        if (! isset($data['invoices'][$invoiceKey]['items'][$itemKey])) {
            return;
        }

        $currency = $data['currency'] ?? 'IDR';

        $taxMode = $data['invoices'][$invoiceKey]['tax_calculation_mode'] ?? PaymentSlip::TAX_MODE_ITEMIZED;
        if ($taxMode === PaymentSlip::TAX_MODE_INVOICE_LEGACY) {
            self::recalculateInvoiceTaxState($data['invoices'][$invoiceKey], $currency);

            return;
        }

        self::recalculateItemizedInvoiceState($data['invoices'][$invoiceKey], $itemKey, $changedField, $currency);
    }

    private static function hasItemTaxConflict(Get $get): bool
    {
        foreach (($get('items') ?? []) as $item) {
            if (filled($item['ppn_tax_id'] ?? null)
                || filled($item['pph_tax_id'] ?? null)
                || self::money($item['tax_addition_amount'] ?? 0) !== 0.0
                || self::money($item['tax_deduction_amount'] ?? 0) !== 0.0) {
                return true;
            }
        }

        return false;
    }

    private static function applyInvoiceTax(
        Get $get,
        Set $set,
        ?object $record,
        string $taxField,
        string $selectionField,
        string $calculationType,
    ): void {
        $invoice = $record instanceof Invoice ? $record : null;
        $paymentSlip = self::paymentSlipForActionRecord($record);
        abort_unless(self::isItemizedExim($get, $invoice) && (! $paymentSlip || $paymentSlip->status === 'draft'), 403);

        $selection = $get($selectionField);
        if (blank($selection)) {
            return;
        }

        $taxId = null;
        if ($selection !== self::CLEAR_BULK_TAX) {
            $taxId = Tax::query()
                ->whereKey($selection)
                ->where('calculation_type', $calculationType)
                ->where('is_active', true)
                ->value('id');
            if (! $taxId) {
                throw ValidationException::withMessages([$selectionField => 'Tarif pajak yang dipilih tidak valid atau tidak aktif.']);
            }
        }

        $items = $get('items') ?? [];
        if ($items === []) {
            return;
        }

        $type = $get('../../transaction_type');
        $hasImportDetails = $type === PaymentSlip::TYPE_IMPORT && collect($items)->contains(fn (array $item): bool => filled($item['source_supplier_name'] ?? null));
        $hasVatDetails = in_array($type, [PaymentSlip::TYPE_IMPORT, PaymentSlip::TYPE_EXPORT], true) && collect($items)->contains(fn (array $item): bool => filled($item['vat_invoice_number'] ?? null));

        if ($hasImportDetails || $hasVatDetails) {
            throw ValidationException::withMessages([$selectionField => 'Invoice dengan supplier pendukung atau VAT per item harus memakai pajak per item agar detail ERP tidak hilang.']);
        }

        $invoice = [
            'items' => $items,
            'ppn_tax_id' => $taxField === 'ppn_tax_id' ? $taxId : null,
            'pph_tax_id' => $taxField === 'pph_tax_id' ? $taxId : null,
        ];
        $otherSelectionField = $taxField === 'ppn_tax_id' ? 'bulk_pph_tax_id' : 'bulk_ppn_tax_id';
        $otherSelection = $get($otherSelectionField);
        if (filled($otherSelection) && $otherSelection !== self::CLEAR_BULK_TAX) {
            $otherType = $taxField === 'ppn_tax_id' ? 'deduction' : 'addition';
            $otherTaxId = Tax::query()->whereKey($otherSelection)->where('calculation_type', $otherType)->where('is_active', true)->value('id');
            if (! $otherTaxId) {
                throw ValidationException::withMessages([$otherSelectionField => 'Tarif pajak yang dipilih tidak valid atau tidak aktif.']);
            }
            $invoice[$taxField === 'ppn_tax_id' ? 'pph_tax_id' : 'ppn_tax_id'] = $otherTaxId;
        }

        foreach ($invoice['items'] as &$item) {
            $item['ppn_tax_id'] = null;
            $item['pph_tax_id'] = null;
            $item['tax_addition_amount'] = 0;
            $item['tax_deduction_amount'] = 0;
        }
        unset($item);

        $currency = $get('../../currency') ?? 'IDR';
        self::recalculateInvoiceTaxState($invoice, $currency);
        $invoice['tax_calculation_mode'] = PaymentSlip::TAX_MODE_INVOICE_LEGACY;
        foreach (['items', 'tax_calculation_mode', 'ppn_tax_id', 'pph_tax_id', 'subtotal_amount', 'tax_addition_amount', 'tax_deduction_amount', 'grand_total_amount'] as $field) {
            $set($field, $invoice[$field]);
        }
    }

    private static function paymentSlipForActionRecord(?object $record): ?PaymentSlip
    {
        if ($record instanceof PaymentSlip) {
            return $record;
        }

        return $record instanceof Invoice ? $record->paymentSlip : null;
    }

    /** @param array<string, mixed> $invoice */
    private static function recalculateInvoiceTaxState(array &$invoice, string $currency = 'IDR'): void
    {
        $subtotal = 0.0;
        foreach ($invoice['items'] ?? [] as &$item) {
            $itemSubtotal = self::money($item['quantity'] ?? 0) * self::money($item['unit_price_amount'] ?? 0);
            $item['net_amount'] = CurrencyFormatter::formatFormState($itemSubtotal, $currency);
            $subtotal += $itemSubtotal;
        }
        unset($item);

        $amounts = InvoiceAmountCalculator::calculateForCurrency(
            $subtotal,
            Tax::find($invoice['ppn_tax_id'] ?? null)?->rate,
            Tax::find($invoice['pph_tax_id'] ?? null)?->rate,
            $currency,
        );
        $invoice['subtotal_amount'] = CurrencyFormatter::formatFormState($subtotal, $currency);
        $invoice['tax_addition_amount'] = CurrencyFormatter::formatFormStateNoDecimals($amounts['tax_addition'], $currency);
        $invoice['tax_deduction_amount'] = CurrencyFormatter::formatFormStateNoDecimals($amounts['tax_deduction'], $currency);
        $invoice['grand_total_amount'] = CurrencyFormatter::formatFormStateNoDecimals($amounts['grand_total'], $currency);
    }

    /** @param array<string, mixed> $invoice */
    private static function recalculateItemizedInvoiceState(array &$invoice, ?string $changedItemKey = null, ?string $changedField = null, string $currency = 'IDR'): void
    {
        $taxIds = collect($invoice['items'])
            ->flatMap(fn (array $item): array => [$item['ppn_tax_id'] ?? null, $item['pph_tax_id'] ?? null])
            ->filter()
            ->unique()
            ->all();
        $taxes = Tax::query()->whereIn('id', $taxIds)->get()->keyBy('id');
        $invoiceSubtotal = 0.0;
        $invoicePpn = 0.0;
        $invoicePph = 0.0;

        foreach ($invoice['items'] as $key => &$item) {
            $subtotal = self::money($item['quantity'] ?? 0) * self::money($item['unit_price_amount'] ?? 0);
            $ppn = $taxes->get($item['ppn_tax_id'] ?? null);
            $pph = $taxes->get($item['pph_tax_id'] ?? null);
            $calculated = InvoiceAmountCalculator::calculate(
                $subtotal,
                $ppn?->calculation_type === 'addition' ? (float) $ppn->rate : null,
                $pph?->calculation_type === 'deduction' ? (float) $pph->rate : null,
            );

            $addition = $ppn ? $calculated['tax_addition'] : self::money($item['tax_addition_amount'] ?? 0);
            $deduction = $pph ? $calculated['tax_deduction'] : self::money($item['tax_deduction_amount'] ?? 0);
            if ((! isset($item['id']) || ($key === $changedItemKey && $changedField === 'ppn_tax_id')) && blank($item['ppn_tax_id'] ?? null)) {
                $addition = 0.0;
            }
            if ((! isset($item['id']) || ($key === $changedItemKey && $changedField === 'pph_tax_id')) && blank($item['pph_tax_id'] ?? null)) {
                $deduction = 0.0;
            }

            $item['tax_addition_amount'] = CurrencyFormatter::formatFormStateNoDecimals($addition, $currency);
            $item['tax_deduction_amount'] = CurrencyFormatter::formatFormStateNoDecimals($deduction, $currency);
            $item['net_amount'] = CurrencyFormatter::formatFormState($subtotal + $addition - $deduction, $currency);
            $invoiceSubtotal += $subtotal;
            $invoicePpn += $addition;
            $invoicePph += $deduction;
        }
        unset($item);

        $invoice['subtotal_amount'] = CurrencyFormatter::formatFormState($invoiceSubtotal, $currency);
        $invoice['tax_addition_amount'] = CurrencyFormatter::formatFormStateNoDecimals($invoicePpn, $currency);
        $invoice['tax_deduction_amount'] = CurrencyFormatter::formatFormStateNoDecimals($invoicePph, $currency);
        $invoice['grand_total_amount'] = CurrencyFormatter::formatFormStateNoDecimals($invoiceSubtotal + $invoicePpn - $invoicePph, $currency);
    }

    private static function isItemizedExim(Get $get, ?Invoice $record): bool
    {
        return self::isEximInvoice($get, $record)
            && ($get('tax_calculation_mode') ?? ($record?->exists ? ($record->usesItemizedTaxes() ? PaymentSlip::TAX_MODE_ITEMIZED : PaymentSlip::TAX_MODE_INVOICE_LEGACY) : PaymentSlip::TAX_MODE_ITEMIZED)) === PaymentSlip::TAX_MODE_ITEMIZED;
    }

    private static function isItemizedItem(Get $get, ?InvoiceItem $record): bool
    {
        $type = $get('../../../../transaction_type');

        return in_array($type, [PaymentSlip::TYPE_IMPORT, PaymentSlip::TYPE_EXPORT], true)
            && ($get('../../tax_calculation_mode') ?? ($record?->exists ? ($record->invoice?->usesItemizedTaxes() ? PaymentSlip::TAX_MODE_ITEMIZED : PaymentSlip::TAX_MODE_INVOICE_LEGACY) : PaymentSlip::TAX_MODE_ITEMIZED)) === PaymentSlip::TAX_MODE_ITEMIZED;
    }

    private static function isEximInvoice(Get $get, ?Invoice $record = null): bool
    {
        $type = $get('../../transaction_type');

        return $type === PaymentSlip::TYPE_IMPORT
            || ($type === PaymentSlip::TYPE_EXPORT
                && ($record?->paymentSlip?->usesItemizedTaxes()
                    ?? $get('../../tax_calculation_mode') === PaymentSlip::TAX_MODE_ITEMIZED));
    }

    private static function money(mixed $value): float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        if (is_string($value)) {
            $value = trim($value);

            $hasComma = str_contains($value, ',');
            $hasDot = str_contains($value, '.');

            if ($hasDot && ! $hasComma) {
                // E.g. "11.000" or "1.500.000"
                if (preg_match('/^-?\d{1,3}(?:\.\d{3})+$/', $value)) {
                    return (float) str_replace('.', '', $value);
                }

                return (float) $value;
            }

            if ($hasComma && ! $hasDot) {
                // E.g. "11,000" or "1,500,000"
                if (preg_match('/^-?\d{1,3}(?:,\d{3})+$/', $value)) {
                    return (float) str_replace(',', '', $value);
                }

                return (float) str_replace(',', '.', $value);
            }

            if ($hasComma && $hasDot) {
                $lastComma = strrpos($value, ',');
                $lastDot = strrpos($value, '.');

                if ($lastDot > $lastComma) {
                    // USD style: 1,500.50
                    return (float) str_replace(',', '', $value);
                } else {
                    // IDR style: 1.500,50
                    return (float) str_replace(['.', ','], ['', '.'], $value);
                }
            }

            return (float) $value;
        }

        return (float) ($value ?? 0);
    }
}
