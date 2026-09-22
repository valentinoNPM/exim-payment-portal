<?php

namespace App\Filament\Resources\PaymentSlips\Tables;

use App\Actions\GeneratePaymentSlipPdf;
use App\Actions\VerifyPaymentSlip;
use App\Filament\Resources\PaymentSlips\PaymentSlipResource;
use App\Models\PaymentSlip;
use App\Support\CurrencyFormatter;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Support\Enums\Alignment;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class PaymentSlipsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['buyer', 'invoices.buyer']))
            ->columns([
                TextColumn::make('slip_number')
                    ->label('Slip Number')
                    ->fontFamily('mono')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('transaction_type')
                    ->label('Type')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        PaymentSlip::TYPE_IMPORT => 'info',
                        PaymentSlip::TYPE_EXPORT => 'success',
                        PaymentSlip::TYPE_GENERAL => 'warning',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => PaymentSlip::TRANSACTION_TYPE_LABELS[$state] ?? ucfirst($state))
                    ->sortable(),
                TextColumn::make('supplier.name')
                    ->label('Supplier')
                    ->description(function (PaymentSlip $record): string {
                        $buyers = $record->invoices
                            ->map(fn ($invoice) => $invoice->buyer?->name ?: $record->buyer?->name)
                            ->filter()
                            ->unique()
                            ->implode(', ');

                        return 'Buyer: '.($buyers ?: $record->buyer?->name ?: '-');
                    })
                    ->sortable()
                    ->searchable(),
                TextColumn::make('creator.name')
                    ->label('Author')
                    ->description(fn (PaymentSlip $record): string => $record->creator?->division?->name ?? '-')
                    ->sortable()
                    ->searchable()
                    ->visible(fn () => auth()->user()->hasRole('checker') || auth()->user()->hasRole('approver')),
                TextColumn::make('grand_total_amount')
                    ->label('Amount')
                    ->formatStateUsing(fn ($state, PaymentSlip $record): string => CurrencyFormatter::format($state, $record->currency ?? 'IDR'))
                    ->fontFamily('mono')
                    ->alignment(Alignment::End)
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'draft' => 'gray',
                        'submitted' => 'info',
                        'pending_approval' => 'warning',
                        'approved', 'exported' => 'success',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'approved' => 'Verified',
                        'pending_approval' => 'Pending Approval (legacy)',
                        default => ucfirst(str_replace('_', ' ', $state)),
                    })
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label('Date Created')
                    ->dateTime('d M Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: false),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                //
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                Action::make('submit_slip')
                    ->label('Submit')
                    ->icon('heroicon-o-paper-airplane')
                    ->color('success')
                    ->visible(fn (PaymentSlip $record): bool => PaymentSlipResource::canSubmit($record))
                    ->action(function (PaymentSlip $record) {
                        abort_unless(PaymentSlipResource::canSubmit($record), 403);
                        $record->update([
                            'status' => 'submitted',
                            'submitted_at' => now(),
                        ]);
                        Notification::make()
                            ->title('Payment Slip submitted to Accounting.')
                            ->success()
                            ->send();
                    }),
                Action::make('verify_slip')
                    ->label('Verify')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (PaymentSlip $record) => $record->status === 'submitted' && auth()->user()->hasRole('checker'))
                    ->action(function (PaymentSlip $record) {
                        app(VerifyPaymentSlip::class)->execute($record, auth()->user());
                        Notification::make()
                            ->title('Payment Slip verified and ready for ERP export.')
                            ->success()
                            ->send();
                    }),
                Action::make('download_pdf')
                    ->label('PDF')
                    ->icon('heroicon-o-document-arrow-down')
                    ->color('info')
                    ->action(fn (PaymentSlip $record) => response()->streamDownload(
                        fn () => print (app(GeneratePaymentSlipPdf::class)->execute($record)->output()),
                        "payment-slip-{$record->slip_number}.pdf"
                    )),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->visible(fn (): bool => PaymentSlipResource::canDeleteAny())
                        ->authorizeIndividualRecords(fn (PaymentSlip $record): bool => PaymentSlipResource::canDelete($record)),
                ]),
            ]);
    }
}
