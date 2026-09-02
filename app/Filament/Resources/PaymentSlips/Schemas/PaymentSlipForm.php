<?php

namespace App\Filament\Resources\PaymentSlips\Schemas;

use App\Models\DocumentFile;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Tax;
use App\Services\GeminiInvoiceExtractor;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
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
use Illuminate\Support\Facades\Log;
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
                                    ->acceptedFileTypes(['application/pdf'])
                                    ->required(),
                            ])
                            ->action(function (array $data, Set $set, Get $get) {
                                try {
                                    $files = $data['raw_pdf_files'] ?? [];
                                    if (empty($files)) {
                                        return;
                                    }

                                    $extractor = app(GeminiInvoiceExtractor::class);

                                    // Generate full storage paths
                                    $filePaths = array_map(function ($path) {
                                        return Storage::disk('local')->path($path);
                                    }, array_values($files));

                                    $extractedInvoices = $extractor->extract($filePaths);

                                    $currentInvoices = $get('invoices') ?? [];

                                    $docIds = [];
                                    foreach (array_values($files) as $path) {
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
                                        $docIds[] = $doc->id;
                                    }

                                    foreach ($extractedInvoices as $index => $inv) {
                                        // Jika hanya 1 file, semua invoice menggunakan file tersebut.
                                        // Jika multi-file, sesuaikan dengan index atau gunakan file terakhir.
                                        $docId = count($docIds) === 1
                                            ? $docIds[0]
                                            : ($docIds[$index] ?? end($docIds));

                                        $newItem = [
                                            'invoice_number' => $inv['invoice_number'] ?? '',
                                            'invoice_date' => $inv['invoice_date'] ?? '',
                                            'document_file_id' => $docId,
                                            'ppn_tax_id' => null,
                                            'pph_tax_id' => null,
                                            'items' => [],
                                        ];

                                        $subtotal = 0;
                                        foreach ($inv['items'] as $item) {
                                            $qty = $item['qty'] ?? 1;
                                            $price = $item['original_price'] ?? 0;
                                            $subtotal += ($qty * $price);
                                            $newItem['items'][(string) str()->uuid()] = [
                                                'item_name' => $item['item_name'] ?? '',
                                                'quantity' => $qty,
                                                'unit_price_amount' => $price,
                                            ];
                                        }
                                        $newItem['subtotal_amount'] = number_format($subtotal, 0, ',', '.');
                                        $newItem['tax_addition_amount'] = '0';
                                        $newItem['tax_deduction_amount'] = '0';
                                        $newItem['grand_total_amount'] = number_format($subtotal, 0, ',', '.');

                                        $currentInvoices[(string) str()->uuid()] = $newItem;
                                    }

                                    $set('invoices', $currentInvoices);

                                    Notification::make()
                                        ->title('Ekstraksi Berhasil')
                                        ->success()
                                        ->send();

                                } catch (\Throwable $e) {
                                    Log::error('AI Extraction Fallback triggered: '.$e->getMessage());

                                    Notification::make()
                                        ->title('Ekstraksi AI Gagal')
                                        ->body('Gagal membaca PDF menggunakan Gemini: '.$e->getMessage().'. Silakan input invoice secara manual menggunakan tombol "Add Invoice".')
                                        ->danger()
                                        ->duration(10000)
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
                                    ->formatStateUsing(fn ($state) => number_format((float) $state, 0, ',', '.'))
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

                                Select::make('coa_id')
                                    ->relationship('chartOfAccount', 'name')
                                    ->label('COA')
                                    ->preload()
                                    ->hidden(fn () => auth()->user()->hasRole('maker'))
                                    ->disabled(fn (?Invoice $record) => ! auth()->user()->hasRole('checker') ||
                                        ! $record ||
                                        ! $record->paymentSlip ||
                                        $record->paymentSlip->status !== 'submitted'
                                    ),

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
                                    ->readOnly(),

                                TextInput::make('tax_deduction_amount')
                                    ->label('Pengurangan Pajak (PPh)')
                                    ->prefix('Rp')
                                    ->formatStateUsing(fn ($state) => number_format((float) $state, 0, ',', '.'))
                                    ->dehydrateStateUsing(fn ($state) => (float) str_replace(['.', ','], ['', '.'], $state))
                                    ->readOnly(),

                                TextInput::make('grand_total_amount')
                                    ->label('Amount Dibayar')
                                    ->prefix('Rp')
                                    ->formatStateUsing(fn ($state) => number_format((float) $state, 0, ',', '.'))
                                    ->dehydrateStateUsing(fn ($state) => (float) str_replace(['.', ','], ['', '.'], $state))
                                    ->readOnly(),

                                Repeater::make('items')
                                    ->relationship('items')
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
                                    ])
                                    ->columns(3),
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
                                return 'Rp ' . number_format($total, 0, ',', '.');
                            }),
                        Select::make('status')
                            ->options([
                                'draft' => 'Draft',
                                'submitted' => 'Submitted',
                                'pending_approval' => 'Pending Approval',
                                'approved' => 'Approved',
                                'exported' => 'Exported',
                            ])
                            ->disabled(),
                    ])->columns(2),
            ]);
    }
}
