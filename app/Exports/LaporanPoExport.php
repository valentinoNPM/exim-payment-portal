<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

/**
 * Unduhan Excel Laporan PO — mengikuti susunan laporan ECOUNT
 * (Status Pesanan Pembelian / ESG016R): satu baris = satu barang.
 */
class LaporanPoExport implements FromQuery, ShouldAutoSize, WithHeadings, WithMapping
{
    public function __construct(private $query) {}

    public function query()
    {
        return $this->query;
    }

    public function headings(): array
    {
        return [
            'No. PO',
            'Tanggal-No.',
            'Pelanggan/Vendor',
            'Nama Barang',
            'Spesifikasi',
            'Kuantitas',
            'Harga',
            'Jumlah Sebelum Pajak',
            'Keterangan',
        ];
    }

    public function map($item): array
    {
        return [
            $item->purchaseOrder?->po_number,
            $item->purchaseOrder?->source_code,
            $item->purchaseOrder?->supplier?->name,
            $item->item_name_snapshot,
            $item->specification_snapshot,
            (float) $item->quantity,
            (float) $item->unit_price_amount,
            (float) $item->subtotal_amount,
            $item->notes,
        ];
    }
}
