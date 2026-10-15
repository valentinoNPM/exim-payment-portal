<?php

namespace App\Filament\Resources\PaymentSlips\Schemas;

use App\Filament\Resources\PaymentSlips\Schemas\Concerns\EximInvoiceTaxActions;
use App\Models\DocumentFile;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\PaymentSlip;
use App\Models\Supplier;
use App\Models\Tax;
use App\Services\DocumentFileRegistrar;
use App\Services\GeminiInvoiceExtractor;
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
use Throwable;

class EximPaymentSlipForm extends PaymentSlipFormSupport
{
    use EximInvoiceTaxActions;

    protected static function isItemizedMode(Get $get): bool
    {
        return $get('../../tax_calculation_mode') !== PaymentSlip::TAX_MODE_INVOICE_LEGACY;
    }

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
                        ->options([
                            PaymentSlip::TYPE_EXPORT => 'Export',
                            PaymentSlip::TYPE_IMPORT => 'Import',
                        ])
                        ->required()
                        ->live()
                        ->disabled(fn (?object $record): bool => $record !== null)
                        ->dehydrated(),
                    Hidden::make('tax_calculation_mode')
                        ->default(PaymentSlip::TAX_MODE_ITEMIZED),
                    TextInput::make('invoice_receipt_number')
                        ->label('Nomor Tanda Terima Invoice')
                        ->placeholder('HIJ / 001 / VIII / 2026')
                        ->maxLength(100)
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
                            ->hidden(fn (string $operation, ?object $record): bool => $operation === 'view'
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
                                $processedFileCount = 0;

                                foreach ($report['successful'] as $successful) {
                                    $storedPath = collect($files)->first(fn (string $path): bool => Storage::disk('local')->path($path) === $successful['path']);
                                    if (! $storedPath) {
                                        continue;
                                    }

                                    try {
                                        $documentIds = app(DocumentFileRegistrar::class)
                                            ->registerLocalUploads([$storedPath], $fileNames);
                                        $mapper = app(InvoiceExtractionDataMapper::class);
                                        $mappedInvoices = $mapper->toRepeaterState(
                                            $successful['invoices'],
                                            $documentIds,
                                            preferBaseAmount: $get('transaction_type') === PaymentSlip::TYPE_IMPORT
                                                || ($get('transaction_type') === PaymentSlip::TYPE_EXPORT && $get('tax_calculation_mode') === PaymentSlip::TAX_MODE_ITEMIZED),
                                        );
                                    } catch (Throwable $exception) {
                                        $report['failed'][] = [
                                            'original_name' => $successful['original_name'],
                                            'message' => 'Registrasi dokumen gagal: '.$exception->getMessage(),
                                        ];

                                        continue;
                                    }

                                    $processedFileCount++;
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
                                $summary = $processedFileCount.' file berhasil, '.count($report['failed']).' file gagal. ';
                                $summary .= $invoiceCount.' invoice dan '.$itemCount.' item ditemukan.';

                                if ($report['failed'] === []) {
                                    Notification::make()->title('Ekstraksi berhasil')->body($summary)->success()->send();
                                } elseif ($processedFileCount > 0) {
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
                            ->live(condition: fn (string $operation): bool => $operation !== 'edit')
                            ->required()
                            ->minItems(1)
                            ->addable(fn (?object $record) => ! $record || $record->status === 'draft')
                            ->deletable(fn (?object $record) => ! $record || $record->status === 'draft')
                            ->mutateRelationshipDataBeforeCreateUsing(fn (array $data, Get $get): array => self::prepareInvoiceTaxMode($data, $get))
                            ->mutateRelationshipDataBeforeSaveUsing(fn (array $data, Get $get): array => self::prepareInvoiceTaxMode($data, $get))
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
                                    ->label('Invoice number')
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
}
