<?php

namespace App\Filament\Resources\PaymentSlips\Pages;

use App\Filament\Resources\PaymentSlips\PaymentSlipResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;

class ListPaymentSlips extends ListRecords
{
    protected static string $resource = PaymentSlipResource::class;

    protected function getHeaderActions(): array
    {
        $user = auth()->user();
        if (! $user?->hasRole('maker')) {
            return [];
        }

        if ($user->isEximDivision()) {
            return [
                Action::make('create_export')
                    ->label('Create Export')
                    ->icon('heroicon-o-arrow-up-tray')
                    ->url(PaymentSlipResource::getUrl('create-export')),
                Action::make('create_import')
                    ->label('Create Import')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->url(PaymentSlipResource::getUrl('create-import')),
                Action::make('create_general')
                    ->label('Create Lain-lain')
                    ->icon('heroicon-o-plus-circle')
                    ->url(PaymentSlipResource::getUrl('create-general')),
            ];
        }

        return [
            Action::make('create_general')
                ->label('Create Payment Slip')
                ->icon('heroicon-o-plus-circle')
                ->url(PaymentSlipResource::getUrl('create-general')),
        ];
    }
}
