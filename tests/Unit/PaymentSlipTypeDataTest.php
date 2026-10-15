<?php

namespace Tests\Unit;

use App\Models\PaymentSlip;
use App\Services\PaymentSlips\EximPaymentSlipData;
use App\Services\PaymentSlips\GeneralPaymentSlipData;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PaymentSlipTypeDataTest extends TestCase
{
    public function test_general_create_defaults_and_optional_po_handoff_do_not_change_manual_invoice(): void
    {
        $data = GeneralPaymentSlipData::prepareCreate([
            'currency' => PaymentSlip::CURRENCY_EUR,
            'invoice_receipt_number' => 'EXIM-only',
            'buyer_id' => 123,
        ]);

        $this->assertSame(PaymentSlip::TYPE_GENERAL, $data['transaction_type']);
        $this->assertSame(PaymentSlip::TAX_MODE_INVOICE_LEGACY, $data['tax_calculation_mode']);
        $this->assertSame(PaymentSlip::CURRENCY_EUR, $data['currency']);
        $this->assertArrayNotHasKey('invoice_receipt_number', $data);
        $this->assertArrayNotHasKey('buyer_id', $data);

        $manualInvoice = ['invoice_number' => 'NOTA-1'];
        $this->assertSame($manualInvoice, GeneralPaymentSlipData::withOptionalPurchaseOrder($manualInvoice, null));
        $this->assertSame(42, GeneralPaymentSlipData::withOptionalPurchaseOrder($manualInvoice, 42)['purchase_order_id']);
    }

    public function test_exim_create_is_idr_itemized_and_discards_general_only_fields(): void
    {
        $data = EximPaymentSlipData::prepareCreate([
            'transaction_type' => PaymentSlip::TYPE_IMPORT,
            'currency' => PaymentSlip::CURRENCY_USD,
            'customer_id' => 123,
            'transaction_description' => 'General-only',
        ]);

        $this->assertSame(PaymentSlip::TYPE_IMPORT, $data['transaction_type']);
        $this->assertSame(PaymentSlip::TAX_MODE_ITEMIZED, $data['tax_calculation_mode']);
        $this->assertSame(PaymentSlip::CURRENCY_IDR, $data['currency']);
        $this->assertArrayNotHasKey('customer_id', $data);
        $this->assertArrayNotHasKey('transaction_description', $data);
    }

    public function test_exim_create_rejects_general_type(): void
    {
        $this->expectException(ValidationException::class);

        EximPaymentSlipData::prepareCreate(['transaction_type' => PaymentSlip::TYPE_GENERAL]);
    }

    public function test_edit_normalizers_preserve_existing_tax_modes_and_record_type(): void
    {
        $general = GeneralPaymentSlipData::prepareEdit([
            'transaction_type' => PaymentSlip::TYPE_EXPORT,
            'tax_calculation_mode' => PaymentSlip::TAX_MODE_INVOICE_LEGACY,
            'currency' => PaymentSlip::CURRENCY_EUR,
            'invoice_receipt_number' => 'EXIM-only',
        ]);

        $this->assertSame(PaymentSlip::TYPE_GENERAL, $general['transaction_type']);
        $this->assertSame(PaymentSlip::TAX_MODE_INVOICE_LEGACY, $general['tax_calculation_mode']);
        $this->assertSame(PaymentSlip::CURRENCY_EUR, $general['currency']);
        $this->assertArrayNotHasKey('invoice_receipt_number', $general);

        $existingImport = new PaymentSlip(['transaction_type' => PaymentSlip::TYPE_IMPORT]);
        $exim = EximPaymentSlipData::prepareEdit([
            'transaction_type' => PaymentSlip::TYPE_GENERAL,
            'tax_calculation_mode' => PaymentSlip::TAX_MODE_INVOICE_LEGACY,
            'customer_id' => 14,
            'transaction_description' => 'General-only',
        ], $existingImport);

        $this->assertSame(PaymentSlip::TYPE_IMPORT, $exim['transaction_type']);
        $this->assertSame(PaymentSlip::TAX_MODE_INVOICE_LEGACY, $exim['tax_calculation_mode']);
        $this->assertArrayNotHasKey('customer_id', $exim);
        $this->assertArrayNotHasKey('transaction_description', $exim);
    }
}
