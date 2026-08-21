<?php

namespace App\Filament\Resources\PaymentSlips\Schemas;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\DatePicker;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class PaymentSlipForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Transaction Details')->schema([
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
                        ->required()
                        ->disabled(fn (?object $record) => $record && $record->status !== 'draft'),
                    Select::make('buyer_id')
                        ->relationship('buyer', 'name')
                        ->visible(fn (\Filament\Schemas\Components\Utilities\Get $get) => $get('transaction_type') === 'import')
                        ->required(fn (\Filament\Schemas\Components\Utilities\Get $get) => $get('transaction_type') === 'import')
                        ->disabled(fn (?object $record) => $record && $record->status !== 'draft'),
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
                            ->action(function (array $data, \Filament\Schemas\Components\Utilities\Set $set, \Filament\Schemas\Components\Utilities\Get $get) {
                                $files = $data['raw_pdf_files'] ?? [];
                                if (empty($files)) return;

                                $extractor = app(\App\Services\GeminiInvoiceExtractor::class);
                                
                                // Generate full storage paths
                                $filePaths = array_map(function($path) {
                                    return \Illuminate\Support\Facades\Storage::disk('local')->path($path);
                                }, array_values($files));

                                $extractedInvoices = $extractor->extract($filePaths);

                                $currentInvoices = $get('invoices') ?? [];

                                $docIds = [];
                                foreach (array_values($files) as $path) {
                                    $fullPath = \Illuminate\Support\Facades\Storage::disk('local')->path($path);
                                    $doc = \App\Models\DocumentFile::create([
                                        'disk' => 'local',
                                        'path' => $path,
                                        'original_name' => basename($path),
                                        'mime_type' => 'application/pdf',
                                        'size_bytes' => \Illuminate\Support\Facades\Storage::disk('local')->size($path),
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

                                    $currentInvoices[(string) str()->uuid()] = $newItem;
                                }

                                $set('invoices', $currentInvoices);
                            }),
                    ])
                    ->schema([
                        Repeater::make('invoices')
                            ->relationship('invoices')
                            ->addable(fn (?object $record) => !$record || $record->status === 'draft')
                            ->deletable(fn (?object $record) => !$record || $record->status === 'draft')
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

                                \Filament\Schemas\Components\Actions::make([
                                    \Filament\Actions\Action::make('preview_pdf')
                                        ->label('Preview PDF')
                                        ->icon('heroicon-o-eye')
                                        ->color('info')
                                        ->action(function (\Filament\Schemas\Components\Utilities\Get $get, $livewire) {
                                            $docId = $get('document_file_id');
                                            if ($docId && method_exists($livewire, 'setActivePdf')) {
                                                $livewire->setActivePdf($docId);
                                            }
                                        })
                                ]),

                                TextInput::make('subtotal_amount')
                                    ->label('Total Amount')
                                    ->prefix('Rp')
                                    ->formatStateUsing(fn ($state) => number_format((float) $state, 0, ',', '.'))
                                    ->dehydrateStateUsing(fn ($state) => (float) str_replace(['.', ','], ['', '.'], $state))
                                    ->live(debounce: 500)
                                    ->afterStateUpdated(function (\Filament\Schemas\Components\Utilities\Get $get, \Filament\Schemas\Components\Utilities\Set $set, $state) {
                                        $rawSubtotal = str_replace(['.', ','], ['', '.'], $state);
                                        $subtotal = (float) $rawSubtotal;
                                        $addition = 0;
                                        $deduction = 0;
                                        
                                        $selectedTaxes = $get('selectedTaxes') ?? [];
                                        if (is_array($selectedTaxes) && count($selectedTaxes) > 0) {
                                            $taxes = \App\Models\Tax::whereIn('id', $selectedTaxes)->get();
                                            foreach ($taxes as $tax) {
                                                if ($tax->calculation_type === 'addition') {
                                                    $addition += $subtotal * ($tax->rate / 100);
                                                } else {
                                                    $deduction += $subtotal * ($tax->rate / 100);
                                                }
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
                                    ->disabled(fn (?Invoice $record) => 
                                        !auth()->user()->hasRole('checker') || 
                                        !$record || 
                                        !$record->paymentSlip || 
                                        $record->paymentSlip->status !== 'submitted'
                                    ),

                                Select::make('selectedTaxes')
                                    ->relationship('selectedTaxes', 'name')
                                    ->multiple()
                                    ->preload()
                                    ->label('Taxes')
                                    ->hidden(fn () => auth()->user()->hasRole('maker'))
                                    ->disabled(fn (?Invoice $record) => 
                                        !auth()->user()->hasRole('checker') || 
                                        !$record || 
                                        !$record->paymentSlip || 
                                        $record->paymentSlip->status !== 'submitted'
                                    )
                                    ->live(debounce: 500)
                                    ->afterStateUpdated(function (\Filament\Schemas\Components\Utilities\Get $get, \Filament\Schemas\Components\Utilities\Set $set, $state) {
                                        $rawSubtotal = str_replace(['.', ','], ['', '.'], $get('subtotal_amount'));
                                        $subtotal = (float) $rawSubtotal;
                                        $addition = 0;
                                        $deduction = 0;
                                        
                                        if (is_array($state) && count($state) > 0) {
                                            $taxes = \App\Models\Tax::whereIn('id', $state)->get();
                                            foreach ($taxes as $tax) {
                                                if ($tax->calculation_type === 'addition') {
                                                    $addition += $subtotal * ($tax->rate / 100);
                                                } else {
                                                    $deduction += $subtotal * ($tax->rate / 100);
                                                }
                                            }
                                        }
                                        
                                        $set('tax_addition_amount', number_format($addition, 0, ',', '.'));
                                        $set('tax_deduction_amount', number_format($deduction, 0, ',', '.'));
                                        $set('grand_total_amount', number_format($subtotal + $addition - $deduction, 0, ',', '.'));
                                    }),
                                    
                                TextInput::make('tax_addition_amount')
                                    ->label('Penambahan Pajak')
                                    ->prefix('Rp')
                                    ->formatStateUsing(fn ($state) => number_format((float) $state, 0, ',', '.'))
                                    ->dehydrateStateUsing(fn ($state) => (float) str_replace(['.', ','], ['', '.'], $state))
                                    ->readOnly()
                                    ->hidden(fn (\Filament\Schemas\Components\Utilities\Get $get) => auth()->user()->hasRole('maker') || (float) str_replace(['.', ','], ['', '.'], $get('tax_addition_amount')) <= 0),

                                TextInput::make('tax_deduction_amount')
                                    ->label('Pengurangan Pajak')
                                    ->prefix('Rp')
                                    ->formatStateUsing(fn ($state) => number_format((float) $state, 0, ',', '.'))
                                    ->dehydrateStateUsing(fn ($state) => (float) str_replace(['.', ','], ['', '.'], $state))
                                    ->readOnly()
                                    ->hidden(fn (\Filament\Schemas\Components\Utilities\Get $get) => auth()->user()->hasRole('maker') || (float) str_replace(['.', ','], ['', '.'], $get('tax_deduction_amount')) <= 0),

                                TextInput::make('grand_total_amount')
                                    ->label('Amount Dibayar')
                                    ->prefix('Rp')
                                    ->formatStateUsing(fn ($state) => number_format((float) $state, 0, ',', '.'))
                                    ->dehydrateStateUsing(fn ($state) => (float) str_replace(['.', ','], ['', '.'], $state))
                                    ->hidden(fn () => auth()->user()->hasRole('maker'))
                                    ->readOnly(),

                                Repeater::make('items')
                                    ->relationship('items')
                                    ->addable(fn (?Invoice $record) => !$record || $record->paymentSlip?->status === 'draft')
                                    ->deletable(fn (?Invoice $record) => !$record || $record->paymentSlip?->status === 'draft')
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
                                    ->columns(3)
                            ])
                            ->defaultItems(0)
                    ]),

                Section::make('Overview')
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('grand_total_amount')
                            ->label('Amount')
                            ->prefix('Rp')
                            ->formatStateUsing(fn ($state) => number_format((float) $state, 2, ',', '.'))
                            ->disabled(),
                        TextInput::make('slip_number')
                            ->label('Slip Number')
                            ->disabled()
                            ->visible(fn (string $operation) => $operation !== 'create'),
                        Select::make('status')
                            ->options([
                                'draft' => 'Draft',
                                'submitted' => 'Submitted',
                                'pending_approval' => 'Pending Approval',
                                'approved' => 'Approved',
                                'exported' => 'Exported',
                            ])
                            ->disabled(),
                    ])->columns(3),
            ]);
    }
}
