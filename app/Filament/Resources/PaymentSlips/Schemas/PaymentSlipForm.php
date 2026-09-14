<?php

namespace App\Filament\Resources\PaymentSlips\Schemas;

use App\Models\DocumentFile;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Tax;
use App\Services\DocumentFileRegistrar;
use App\Services\GeminiInvoiceExtractor;
use App\Services\InvoiceExtractionDataMapper;
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
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;

class PaymentSlipForm
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
                        ->options([
                            'import' => 'Import',
                            'export' => 'Export',
                        ])
                        ->required()
                        ->live()
                        ->disabled(fn (?object $record) => $record && $record->status !== 'draft'),
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
                        ->required()
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
                            ->hidden(fn (string $operation, ?object $record) => $operation === 'view' || ($record && $record->status !== 'draft'))
                            ->form([
                                FileUpload::make('raw_pdf_files')
                                    ->label('Upload PDF Invoices')
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

                                foreach ($files as $path) {
                                    $originalName = $storedNames[$path] ?? basename($path);
                                    $fileNames[$path] = $originalName;
                                    $extractionFiles[] = [
                                        'path' => Storage::disk('local')->path($path),
                                        'original_name' => $originalName,
                                    ];
                                }

                                $report = app(GeminiInvoiceExtractor::class)->extractWithReport($extractionFiles);
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
                                    $newInvoices = array_merge(
                                        $newInvoices,
                                        app(InvoiceExtractionDataMapper::class)->toRepeaterState($successful['invoices'], $documentIds),
                                    );
                                    $invoiceCount += count($successful['invoices']);
                                    $itemCount += array_sum(array_map(
                                        static fn (array $invoice): int => count($invoice['items']),
                                        $successful['invoices'],
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
                            ->addable(fn (?object $record) => ! $record || $record->status === 'draft')
                            ->deletable(fn (?object $record) => ! $record || $record->status === 'draft')
                            ->schema([
                                TextInput::make('invoice_number')
                                    ->required()
                                    ->disabled(fn (?Invoice $record) => $record && $record->paymentSlip?->status !== 'draft'),
                                DatePicker::make('invoice_date')
                                    ->required()
                                    ->disabled(fn (?Invoice $record) => $record && $record->paymentSlip?->status !== 'draft'),
                                TextInput::make('vat_invoice_number')
                                    ->label('VAT Invoice No.')
                                    ->helperText('Optional. Fill only when a tax invoice number is available.')
                                    ->maxLength(255)
                                    ->disabled(fn (?Invoice $record): bool => $record && ! in_array($record->paymentSlip?->status, ['draft', 'submitted'], true)),
                                Select::make('document_file_id')
                                    ->relationship('documentFile', 'original_name')
                                    ->disabled()
                                    ->dehydrated(),

                                Actions::make([
                                    Action::make('upload_manual_pdf')
                                        ->label(fn (Get $get) => $get('document_file_id') ? 'Ganti PDF' : 'Upload PDF')
                                        ->icon('heroicon-o-arrow-up-tray')
                                        ->color('success')
                                        ->hidden(fn (string $operation, ?Invoice $record) => $operation === 'view' || ($record && $record->paymentSlip?->status !== 'draft'))
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
                                    ->label('Total Amount')
                                    ->prefix('Rp')
                                    ->formatStateUsing(fn ($state) => number_format((float) $state, 2, ',', '.'))
                                    ->dehydrateStateUsing(fn ($state) => (float) str_replace(['.', ','], ['', '.'], $state))
                                    ->live(debounce: 500)
                                    ->afterStateUpdated(function (Get $get, Set $set, $state) {
                                        $rawSubtotal = str_replace(['.', ','], ['', '.'], $state);
                                        $subtotal = (float) $rawSubtotal;
                                        $addition = 0;
                                        $deduction = 0;

                                        $ppnTaxId = $get('ppn_tax_id');
                                        if ($ppnTaxId) {
                                            $ppnTax = Tax::find($ppnTaxId);
                                            if ($ppnTax) {
                                                $addition = $subtotal * ($ppnTax->rate / 100);
                                            }
                                        }

                                        $pphTaxId = $get('pph_tax_id');
                                        if ($pphTaxId) {
                                            $pphTax = Tax::find($pphTaxId);
                                            if ($pphTax) {
                                                $deduction = $subtotal * ($pphTax->rate / 100);
                                            }
                                        }

                                        $set('tax_addition_amount', number_format($addition, 0, ',', '.'));
                                        $set('tax_deduction_amount', number_format($deduction, 0, ',', '.'));
                                        $set('grand_total_amount', number_format($subtotal + $addition - $deduction, 0, ',', '.'));
                                    }),

                                Select::make('ppn_tax_id')
                                    ->label('PPN (Penambahan)')
                                    ->options(fn () => Tax::where('calculation_type', 'addition')->where('is_active', true)->pluck('name', 'id'))
                                    ->preload()
                                    ->placeholder('Tanpa PPN')
                                    ->live()
                                    ->afterStateUpdated(function (Get $get, Set $set, $state) {
                                        $rawSubtotal = str_replace(['.', ','], ['', '.'], $get('subtotal_amount'));
                                        $subtotal = (float) $rawSubtotal;
                                        $addition = 0;
                                        $deduction = 0;

                                        if ($state) {
                                            $ppnTax = Tax::find($state);
                                            if ($ppnTax) {
                                                $addition = $subtotal * ($ppnTax->rate / 100);
                                            }
                                        }

                                        $pphTaxId = $get('pph_tax_id');
                                        if ($pphTaxId) {
                                            $pphTax = Tax::find($pphTaxId);
                                            if ($pphTax) {
                                                $deduction = $subtotal * ($pphTax->rate / 100);
                                            }
                                        }

                                        $set('tax_addition_amount', number_format($addition, 0, ',', '.'));
                                        $set('tax_deduction_amount', number_format($deduction, 0, ',', '.'));
                                        $set('grand_total_amount', number_format($subtotal + $addition - $deduction, 0, ',', '.'));
                                    }),

                                Select::make('pph_tax_id')
                                    ->label('PPh (Pengurangan)')
                                    ->options(fn () => Tax::where('calculation_type', 'deduction')->where('is_active', true)->pluck('name', 'id'))
                                    ->preload()
                                    ->placeholder('Tanpa PPh')
                                    ->live()
                                    ->afterStateUpdated(function (Get $get, Set $set, $state) {
                                        $rawSubtotal = str_replace(['.', ','], ['', '.'], $get('subtotal_amount'));
                                        $subtotal = (float) $rawSubtotal;
                                        $addition = 0;
                                        $deduction = 0;

                                        $ppnTaxId = $get('ppn_tax_id');
                                        if ($ppnTaxId) {
                                            $ppnTax = Tax::find($ppnTaxId);
                                            if ($ppnTax) {
                                                $addition = $subtotal * ($ppnTax->rate / 100);
                                            }
                                        }

                                        if ($state) {
                                            $pphTax = Tax::find($state);
                                            if ($pphTax) {
                                                $deduction = $subtotal * ($pphTax->rate / 100);
                                            }
                                        }

                                        $set('tax_addition_amount', number_format($addition, 0, ',', '.'));
                                        $set('tax_deduction_amount', number_format($deduction, 0, ',', '.'));
                                        $set('grand_total_amount', number_format($subtotal + $addition - $deduction, 0, ',', '.'));
                                    }),

                                TextInput::make('tax_addition_amount')
                                    ->label('Penambahan Pajak (PPN)')
                                    ->prefix('Rp')
                                    ->formatStateUsing(fn ($state) => number_format((float) $state, 0, ',', '.'))
                                    ->dehydrateStateUsing(fn ($state) => (float) str_replace(['.', ','], ['', '.'], $state))
                                    ->readOnly()->dehydrated(false),

                                TextInput::make('tax_deduction_amount')
                                    ->label('Pengurangan Pajak (PPh)')
                                    ->prefix('Rp')
                                    ->formatStateUsing(fn ($state) => number_format((float) $state, 0, ',', '.'))
                                    ->dehydrateStateUsing(fn ($state) => (float) str_replace(['.', ','], ['', '.'], $state))
                                    ->readOnly()->dehydrated(false),

                                TextInput::make('grand_total_amount')
                                    ->label('Amount Dibayar')
                                    ->prefix('Rp')
                                    ->formatStateUsing(fn ($state) => number_format((float) $state, 0, ',', '.'))
                                    ->dehydrateStateUsing(fn ($state) => (float) str_replace(['.', ','], ['', '.'], $state))
                                    ->readOnly()->dehydrated(false),

                                Repeater::make('items')
                                    ->relationship('items')
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
                                            ->disabled(fn (?InvoiceItem $record) => $record && $record->invoice?->paymentSlip?->status !== 'draft'),
                                        TextInput::make('unit_price_amount')
                                            ->numeric()
                                            ->prefix('Rp')
                                            ->required()
                                            ->disabled(fn (?InvoiceItem $record) => $record && $record->invoice?->paymentSlip?->status !== 'draft'),
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
                                    ->columns(['default' => 1, 'lg' => 4]),
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
                                    $invTotal = str_replace(['.', ','], ['', '.'], $inv['grand_total_amount'] ?? '0');
                                    $total += (float) $invTotal;
                                }

                                return 'Rp '.number_format($total, 0, ',', '.');
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
