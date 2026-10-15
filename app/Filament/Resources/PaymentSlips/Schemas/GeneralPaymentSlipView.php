<?php

namespace App\Filament\Resources\PaymentSlips\Schemas;

use App\Models\PaymentSlip;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;

class GeneralPaymentSlipView
{
    public static function configure(Schema $schema, PaymentSlip $slip): Schema
    {
        return $schema->components([
            View::make('filament.payment-slips.read-only.general')
                ->viewData(['slip' => $slip])
                ->columnSpanFull(),
        ]);
    }
}
