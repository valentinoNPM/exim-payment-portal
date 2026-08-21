<?php

namespace App\Filament\Resources\PaymentSlips\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
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
                TextColumn::make('grand_total_amount')
                    ->label('Amount')
                    ->formatStateUsing(fn ($state) => 'Rp ' . number_format($state, 2, ',', '.'))
                    ->sortable(),
                TextColumn::make('status')->badge()->sortable(),
                TextColumn::make('created_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->actions([
                ViewAction::make(),
                EditAction::make(),
                \Filament\Actions\Action::make('submit_slip')
                    ->label('Submit')
                    ->icon('heroicon-o-paper-airplane')
                    ->color('success')
                    ->visible(fn (\App\Models\PaymentSlip $record) => $record->status === 'draft' && auth()->user()->hasRole('maker'))
                    ->action(function (\App\Models\PaymentSlip $record) {
                        $record->update([
                            'status' => 'submitted',
                            'submitted_at' => now(),
                        ]);
                        \Filament\Notifications\Notification::make()
                            ->title('Payment Slip submitted to Accounting.')
                            ->success()
                            ->send();
                    }),
                \Filament\Actions\Action::make('verify_slip')
                    ->label('Verify')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (\App\Models\PaymentSlip $record) => $record->status === 'submitted' && auth()->user()->hasRole('checker'))
                    ->action(function (\App\Models\PaymentSlip $record) {
                        $record->update([
                            'status' => 'verified',
                            'verified_at' => now(),
                        ]);
                        \Filament\Notifications\Notification::make()
                            ->title('Payment Slip verified successfully.')
                            ->success()
                            ->send();
                    }),
                \Filament\Actions\Action::make('download_pdf')
                    ->label('PDF')
                    ->icon('heroicon-o-document-arrow-down')
                    ->color('info')
                    ->action(fn (\App\Models\PaymentSlip $record) => response()->streamDownload(
                        fn () => print(app(\App\Actions\GeneratePaymentSlipPdf::class)->execute($record)->output()),
                        "payment-slip-{$record->slip_number}.pdf"
                    )),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
