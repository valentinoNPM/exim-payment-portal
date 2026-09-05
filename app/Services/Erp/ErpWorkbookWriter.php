<?php

namespace App\Services\Erp;

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;

class ErpWorkbookWriter
{
    public static function filename(string $slipNumber): string
    {
        return (preg_replace('/[<>:"\/\\\\|?*\x00-\x1F]/u', '-', $slipNumber) ?: 'payment-slip').'-ERP.xlsx';
    }

    public function write(array $rows, string $path): void
    {
        $workbook = IOFactory::load(config('erp-export.template'));
        try {
            if ($workbook->getSheetNames() !== ['LedgerJournalTrans', 'Costcenter', 'Sub'] || $workbook->getSheet(0)->getHighestColumn() !== 'BG') {
                throw new RuntimeException('ERP template sheet/column structure is invalid.');
            }
            $sheet = $workbook->getSheet(0);
            // XLSX serializes zero-length header strings as blank cells.
            $headers = $sheet->rangeToArray('A1:BG2', '', false, false);
            foreach (['A' => 'Seq', 'B' => 'Date', 'D' => 'Account', 'P' => 'Debit', 'Q' => 'Credit', 'AL' => 'Invoice', 'AN' => 'Document date', 'AV' => 'VAT inv. No.'] as $column => $header) {
                if ($sheet->getCell($column.'2')->getValue() !== $header) {
                    throw new RuntimeException('ERP template header mismatch: '.$column);
                }
            }
            $dateFormat = $sheet->getStyle('B3')->getNumberFormat()->getFormatCode();
            if ($dateFormat === 'General' || $dateFormat === '@') {
                $dateFormat = 'dd-mm-yyyy';
            }
            for ($r = 3, $last = max($sheet->getHighestRow(), count($rows) + 2); $r <= $last; $r++) {
                for ($c = 1; $c <= 59; $c++) {
                    $sheet->getCell([$c, $r])->setValueExplicit(null, DataType::TYPE_NULL);
                }
            }
            foreach ($rows as $index => $row) {
                $r = $index + 3;
                $sheet->duplicateStyle($sheet->getStyle('A3:BG3'), 'A'.$r.':BG'.$r);
                $values = ['C' => $row->accountType, 'D' => $row->account, 'E' => $row->costCenter, 'F' => $row->rowType === 'Expense' ? config('erp-export.company_dimension') : null,
                    'I' => $row->rowType === 'Supplier' ? $row->account : null, 'O' => $row->description, 'R' => config('erp-export.currency'),
                    'T' => $row->rowType === 'Supplier' ? config('erp-export.posting_profile') : null, 'U' => $row->rowType === 'PPN' ? config('erp-export.sales_tax_code') : null,
                    'X' => 'Ledger', 'AL' => $row->rowType === 'Supplier' ? $row->invoice : null, 'AV' => $row->vatInvoiceNumber];
                foreach ($values as $column => $value) {
                    if ($value !== null) {
                        $sheet->setCellValueExplicit($column.$r, $value, DataType::TYPE_STRING);
                        if (in_array($column, ['D', 'I', 'AL', 'AV'], true)) {
                            $sheet->getStyle($column.$r)->getNumberFormat()->setFormatCode('@');
                        }
                    }
                }
                $sheet->setCellValueExplicit('A'.$r, 1, DataType::TYPE_NUMERIC);
                foreach (['B', ...($row->rowType === 'Supplier' ? ['AN'] : [])] as $column) {
                    $sheet->setCellValueExplicit($column.$r, Date::PHPToExcel(new \DateTimeImmutable($row->date)), DataType::TYPE_NUMERIC);
                    $sheet->getStyle($column.$r)->getNumberFormat()->setFormatCode($dateFormat);
                }
                foreach (['P' => $row->debit, 'Q' => $row->credit] as $column => $amount) {
                    if ($amount !== null) {
                        $sheet->setCellValueExplicit($column.$r, $amount / 100, DataType::TYPE_NUMERIC);
                        $sheet->getStyle($column.$r)->getNumberFormat()->setFormatCode('#,##0.00');
                    }
                }
            }
            (new Xlsx($workbook))->save($path);
            $check = IOFactory::load($path);
            try {
                if ($check->getSheetNames() !== $workbook->getSheetNames() || $check->getSheet(0)->rangeToArray('A1:BG2', '', false, false) !== $headers || $check->getSheet(0)->getHighestColumn() !== 'BG') {
                    throw new RuntimeException('Generated workbook failed structural verification.');
                }
            } finally {
                $check->disconnectWorksheets();
            }
        } finally {
            $workbook->disconnectWorksheets();
        }
    }
}
