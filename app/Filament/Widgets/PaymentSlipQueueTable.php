<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\PaymentSlips\PaymentSlipResource;
use App\Models\PaymentSlip;
use Filament\Actions\Action;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

class PaymentSlipQueueTable extends BaseWidget
{
    protected static ?string $heading = 'Action Required';

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        $user = auth()->user();

        $query = PaymentSlip::query()->latest();

        if (! $user) {
            $query->whereRaw('1=0');
        } elseif ($user->hasRole('maker')) {
            $query->where('created_by', $user->id)
                ->where('status', 'draft');
        } elseif ($user->hasRole('checker')) {
            $query->where('status', 'submitted');
        } elseif ($user->hasRole('approver')) {
            $query->where('status', 'pending_approval');
        } else {
            $query->whereRaw('1=0');
        }

        return $table
            ->query($query)
            ->columns([
                Tables\Columns\TextColumn::make('slip_number')
                    ->label('Slip Number')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('transaction_type')
                    ->label('Type')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'import' => 'info',
                        'export' => 'success',
                    })
                    ->formatStateUsing(fn (string $state): string => ucfirst($state)),
                Tables\Columns\TextColumn::make('supplier.name')
                    ->label('Supplier')
                    ->sortable(),
                Tables\Columns\TextColumn::make('creator.name')
                    ->label('Maker'),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'draft' => 'gray',
                        'submitted' => 'info',
                        'pending_approval' => 'warning',
                        'approved' => 'success',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => ucfirst(str_replace('_', ' ', $state))),
                Tables\Columns\TextColumn::make('grand_total_amount')
                    ->label('Total Amount')
                    ->money('IDR')
                    ->sortable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Created At')
                    ->dateTime('d M Y H:i')
                    ->sortable(),
            ])
            ->recordActions([
                Action::make('view_or_edit')
                    ->label(fn (PaymentSlip $record): string => $record->status === 'draft' && auth()->user()->hasRole('maker') ? 'Edit' : 'Review'
                    )
                    ->icon(fn (PaymentSlip $record): string => $record->status === 'draft' && auth()->user()->hasRole('maker') ? 'heroicon-o-pencil' : 'heroicon-o-eye'
                    )
                    ->url(fn (PaymentSlip $record): string => PaymentSlipResource::getUrl('edit', ['record' => $record])
                    ),
            ]);
    }
}
