<?php

namespace App\Filament\Resources\PaymentSlips\Schemas;

use App\Models\PaymentSlip;
use Filament\Schemas\Schema;

class PaymentSlipInfolist
{
    public static function configure(Schema $schema): Schema
    {
        $slip = $schema->getRecord();

        if (! $slip instanceof PaymentSlip) {
            return $schema->components([]);
        }

        return $slip->transaction_type === PaymentSlip::TYPE_GENERAL
            ? GeneralPaymentSlipView::configure($schema, $slip)
            : EximPaymentSlipView::configure($schema, $slip);
    }
}
