<?php

namespace App\Console\Commands;

use App\Models\Division;
use App\Models\Item;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Impor tambahan PO dari ECOUNT (dokumen setelah 05/10/2026).
 *
 * Sumber: berkas CSV hasil pembacaan layar ECOUNT — kepala dokumen dari "Daftar Pesanan
 * Pembelian" dan barisnya dari laporan "Status Pesanan Pembelian".
 *
 * Bawaan = uji kering (tidak menulis apa pun). Pakai --apply untuk menulis, satu transaksi.
 *   php artisan po:import-tambahan
 *   php artisan po:import-tambahan --apply
 */
class ImportPoTambahan extends Command
{
    protected $signature = 'po:import-tambahan
        {--berkas= : Berkas CSV hasil susunan (bawaan: topup-okt/hasil/import-tambahan.csv)}
        {--apply : Benar-benar menulis ke basis data}';

    protected $description = 'Impor PO tambahan dari ECOUNT (setelah 05/10/2026) — uji kering secara bawaan';

    private array $supplierByNama = [];

    private array $itemByNama = [];

    public function handle(): int
    {
        $berkas = $this->option('berkas')
            ?: 'C:/Users/valentino/Documents/work/hansoll-ecount/topup-okt/hasil/import-tambahan.csv';
        $apply = (bool) $this->option('apply');

        if (! is_file($berkas)) {
            $this->error("Berkas tidak ada: {$berkas}");

            return self::FAILURE;
        }

        $dokumen = $this->bacaBerkas($berkas);

        $this->info('Berkas : '.$berkas);
        $this->info('Mode   : '.($apply ? 'MENULIS (--apply)' : 'UJI KERING (tidak menulis)'));
        $this->newLine();

        $this->siapkanIndeks();

        $ga = Division::query()->whereRaw('UPPER(code) = ?', ['GA'])->first();
        if (! $ga) {
            $this->error('Divisi GA tidak ditemukan — impor dibatalkan.');

            return self::FAILURE;
        }

        $creatorId = (int) (User::query()->orderBy('id')->value('id') ?? 0);
        if ($apply && $creatorId === 0) {
            $this->error('Pengguna pembuat tidak ditemukan — impor dibatalkan.');

            return self::FAILURE;
        }

        $rencana = [];
        $masalah = [];

        foreach ($dokumen as $kunci => $d) {
            $aksi = $d['aksi'];
            $adaPo = PurchaseOrder::query()->where('source_code', $d['tanggal_no'])->first();

            if ($aksi === 'baru' && $adaPo) {
                $masalah[] = "{$d['tanggal_no']}: sudah ada di exim ({$adaPo->po_number}) — dilewati.";

                continue;
            }

            if ($aksi === 'segarkan' && ! $adaPo) {
                $masalah[] = "{$d['tanggal_no']}: dokumen belum ada di exim — tidak bisa disegarkan.";

                continue;
            }

            $supplierId = null;
            if ($aksi === 'baru') {
                $supplierId = $this->supplierByNama[$this->normNama($d['vendor'])] ?? null;
                if (! $supplierId) {
                    $masalah[] = "{$d['tanggal_no']}: supplier '{$d['vendor']}' tidak ketemu di exim — dilewati.";

                    continue;
                }
            }

            $subtotal = 0.0;
            foreach ($d['baris'] as $b) {
                $subtotal += (float) ($b['jumlah'] !== '' ? $b['jumlah'] : (float) $b['kuantitas'] * (float) $b['harga']);
            }

            $rencana[] = [
                'aksi' => $aksi,
                'tanggal_no' => $d['tanggal_no'],
                'po_number' => $d['po_number'],
                'supplier_id' => $supplierId,
                'subtotal' => $subtotal,
                'baris' => count($d['baris']),
                'po' => $adaPo,
                'data' => $d,
            ];
        }

        $this->tampilkanRencana($rencana);

        if ($masalah) {
            $this->newLine();
            $this->warn('Dilewati / bermasalah:');
            foreach ($masalah as $m) {
                $this->line('  - '.$m);
            }
        }

        if (! array_filter($rencana, fn ($r) => $r['aksi'] === 'baru')) {
            $this->newLine();
            $this->warn('Tidak ada dokumen baru untuk diimpor.');

            return self::SUCCESS;
        }

        if (! $apply) {
            $this->newLine();
            $this->comment('Uji kering selesai. Belum ada yang ditulis. Jalankan dengan --apply untuk menulis.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($rencana, $ga, $creatorId) {
            foreach ($rencana as $r) {
                if ($r['aksi'] === 'baru') {
                    $this->buatDokumen($r, $ga->id, $creatorId);
                } else {
                    $this->segarkanDokumen($r);
                }
            }
        });

        $this->newLine();
        $this->info('Selesai ditulis dalam satu transaksi.');

        return self::SUCCESS;
    }

    /** @return array<string, array{aksi: string, tanggal_no: string, po_number: string, vendor: string, pic: string, tanggal: string, kirim: string, baris: array}> */
    private function bacaBerkas(string $berkas): array
    {
        $dokumen = [];
        $handle = fopen($berkas, 'r');
        $head = fgetcsv($handle, 0, ';');
        while (($kol = fgetcsv($handle, 0, ';')) !== false) {
            if (count($kol) < 5) {
                continue;
            }
            [$aksi, $tanggalNo, $noPo, $tanggal, $vendor, $pic, $kirim, $mata, $subtotalKol, $pajakKol, $grandKol, , $barisKe, $nama, $spec, $qty, $harga, $jumlah, $ket] = array_pad($kol, 19, '');

            $dokumen[$aksi.'|'.$tanggalNo] ??= [
                'aksi' => $aksi,
                'tanggal_no' => $tanggalNo,
                'po_number' => $noPo,
                'tanggal' => $tanggal,
                'vendor' => $vendor,
                'pic' => $pic,
                'kirim' => $kirim,
                'pajak' => $pajakKol,
                'grand' => $grandKol,
                'baris' => [],
            ];

            $dokumen[$aksi.'|'.$tanggalNo]['baris'][] = [
                'nama' => $nama,
                'spec' => $spec,
                'kuantitas' => $qty,
                'harga' => $harga,
                'jumlah' => $jumlah,
                'ket' => $ket,
            ];
        }
        fclose($handle);

        return $dokumen;
    }

    private function siapkanIndeks(): void
    {
        foreach (Supplier::query()->get(['id', 'name']) as $s) {
            $this->supplierByNama[$this->normNama($s->name)] ??= $s->id;
        }
        foreach (Item::query()->get(['id', 'name', 'source_code']) as $i) {
            $this->itemByNama[$this->normNama($i->name)] ??= $i;
        }
    }

    private function normNama(?string $nama): string
    {
        return preg_replace('/[^A-Z0-9]/', '', strtoupper((string) $nama)) ?? '';
    }

    private function tampilkanRencana(array $rencana): void
    {
        $this->info('Rencana:');
        $this->line(sprintf('  %-18s %-24s %-7s %6s %16s', 'Dokumen', 'No. PO', 'Aksi', 'Baris', 'Nilai sebelum pajak'));
        $totalBaris = 0;
        foreach ($rencana as $r) {
            $totalBaris += $r['baris'];
            $this->line(sprintf(
                '  %-18s %-24s %-7s %6d %16s',
                $r['tanggal_no'],
                $r['po_number'] ?: '-',
                $r['aksi'],
                $r['baris'],
                number_format($r['subtotal'], 0, ',', '.'),
            ));
        }
        $this->newLine();
        $this->line('  Total: '.count($rencana).' dokumen / '.$totalBaris.' baris');
    }

    private function buatDokumen(array $r, int $divisionId, int $creatorId): void
    {
        $d = $r['data'];
        // Nomor urut mengikuti suffix "Tanggal-No." (mis. "05/10/2026 -12" -> 12),
        // sama seperti dokumen hasil impor sebelumnya. Kunci unik: (company_code, po_date, sequence_number).
        $companyCode = 'HIJ';
        $sequence = (int) trim((string) strrchr($d['tanggal_no'], '-')) ?: null;
        $sequence = $sequence ? abs($sequence) : null;

        $subtotal = $r['subtotal'];
        $pajak = max(0, (float) ($d['pajak'] ?? 0));
        $grand = (float) ($d['grand'] ?? 0) ?: ($subtotal + $pajak);

        $po = PurchaseOrder::query()->create([
            'po_number' => $d['po_number'],
            'company_code' => $companyCode,
            'sequence_number' => $sequence,
            'po_date' => $d['tanggal'],
            'division_id' => $divisionId,
            'supplier_id' => $r['supplier_id'],
            'pic_name' => $d['pic'] ?: 'GA',
            'currency' => PurchaseOrder::CURRENCY_IDR,
            'delivery_date' => $d['kirim'] ?: null,
            'title' => null,
            'notes' => null,
            'status' => PurchaseOrder::STATUS_IN_PROGRESS,
            'subtotal_amount' => $subtotal,
            'tax_amount' => $pajak,
            'tax_addition_amount' => $pajak,
            'tax_deduction_amount' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'grand_total_amount' => $subtotal + $pajak,
            'created_by' => $creatorId,
            'source' => 'ecount',
            'source_code' => $d['tanggal_no'],
        ]);

        foreach ($d['baris'] as $i => $b) {
            $item = $this->itemByNama[$this->normNama($b['nama'])] ?? null;
            $subtotalBaris = $b['jumlah'] !== ''
                ? (float) $b['jumlah']
                : round((float) $b['kuantitas'] * (float) $b['harga'], 2);

            PurchaseOrderItem::query()->create([
                'purchase_order_id' => $po->id,
                'line_number' => $i + 1,
                'item_id' => $item?->id,
                'item_code_snapshot' => $item?->source_code,
                'item_name_snapshot' => $b['nama'],
                'specification_snapshot' => $b['spec'] ?: null,
                'item_code' => $item?->source_code,
                'item_name' => $b['nama'],
                'specification' => $b['spec'] ?: null,
                'quantity' => (float) $b['kuantitas'],
                'unit_id' => $item?->unit_id,
                'unit_price_amount' => (float) $b['harga'],
                'subtotal_amount' => $subtotalBaris,
                'tax_amount' => 0,
                'total_amount' => $subtotalBaris,
                'notes' => $b['ket'] ?: null,
            ]);
        }
    }

    private function segarkanDokumen(array $r): void
    {
        $d = $r['data'];
        $po = $r['po'];

        $lamaSubtotal = (float) $po->subtotal_amount;
        $lamaPajak = (float) $po->tax_amount;
        $rasio = $lamaSubtotal > 0 ? $lamaPajak / $lamaSubtotal : 0;

        $po->items()->delete();

        $subtotal = 0.0;
        foreach ($d['baris'] as $i => $b) {
            $item = $this->itemByNama[$this->normNama($b['nama'])] ?? null;
            $subtotalBaris = $b['jumlah'] !== ''
                ? (float) $b['jumlah']
                : round((float) $b['kuantitas'] * (float) $b['harga'], 2);
            $subtotal += $subtotalBaris;

            PurchaseOrderItem::query()->create([
                'purchase_order_id' => $po->id,
                'line_number' => $i + 1,
                'item_id' => $item?->id,
                'item_code_snapshot' => $item?->source_code,
                'item_name_snapshot' => $b['nama'],
                'specification_snapshot' => $b['spec'] ?: null,
                'item_code' => $item?->source_code,
                'item_name' => $b['nama'],
                'specification' => $b['spec'] ?: null,
                'quantity' => (float) $b['kuantitas'],
                'unit_id' => $item?->unit_id,
                'unit_price_amount' => (float) $b['harga'],
                'subtotal_amount' => $subtotalBaris,
                'tax_amount' => 0,
                'total_amount' => $subtotalBaris,
                'notes' => $b['ket'] ?: null,
            ]);
        }

        $pajak = round($subtotal * $rasio, 2);

        $po->update([
            'subtotal_amount' => $subtotal,
            'tax_amount' => $pajak,
            'tax_addition_amount' => $pajak,
            'grand_total_amount' => $subtotal + $pajak,
        ]);
    }

    /** Nilai total dokumen (termasuk pajak) — diambil dari berkas, dicocokkan dengan subtotal. */
    private function nilaiTotalDariBerkas(array $d, float $subtotal): float
    {
        return $subtotal + (float) ($d['pajak'] ?? 0) + 0;
    }
}
