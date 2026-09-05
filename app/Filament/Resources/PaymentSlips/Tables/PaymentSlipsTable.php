<?php

namespace App\Filament\Resources\PaymentSlips\Tables;

use App\Actions\GeneratePaymentSlipPdf;
use App\Actions\VerifyPaymentSlip;
use App\Models\PaymentSlip;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class PaymentSlipsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')->sortable(),
                TextColumn::make('transaction_type')->badge()->sortable(),
                TextColumn::make('supplier.name')->sortable()->searchable(),
                TextColumn::make('creator.name')
                    ->label('Author')
                    ->description(fn (PaymentSlip $record): string => $record->creator?->division?->name ?? '-')
                    ->sortable()
                    ->searchable()
                    ->visible(fn () => auth()->user()->hasRole('checker') || auth()->user()->hasRole('approver')),
                TextColumn::make('grand_total_amount')
                    ->label('Amount')
                    ->formatStateUsing(fn ($state) => 'Rp '.number_format($state, 2, ',', '.'))
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
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
                    ->visible(fn (PaymentSlip $record) => $record->status === 'draft' && auth()->user()->hasRole('maker'))
                    ->action(function (PaymentSlip $record) {
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
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
