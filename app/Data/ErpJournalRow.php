<?php

namespace App\Data;

final readonly class ErpJournalRow
{
    public function __construct(
        public string $invoice,
        public string $date,
        public string $rowType,
        public string $accountType,
        public string $account,
        public string $description,
        public ?int $debit,
        public ?int $credit,
        public ?string $costCenter = null,
        public ?string $vatInvoiceNumber = null,
    ) {}
}
