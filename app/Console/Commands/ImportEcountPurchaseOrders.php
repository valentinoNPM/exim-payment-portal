<?php

namespace App\Console\Commands;

use App\Models\Division;
use App\Models\Item;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\Tax;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Impor PO lama dari ekspor ECOUNT (HANSOLL).
 *
 * Selalu jalankan tanpa --write dulu: perintah akan melaporkan hasil pemetaan
 * vendor, barang, dan penomoran tanpa menyentuh basis data.
 *
 * Sumber berkas (hasil olah dari penyusunan ulang, lihat skill ecount-erp-extraction):
 *   --daftar   dokumen : hasil/<tahun>-header.csv (boleh beberapa berkas, pisah koma)
 *   --laporan  baris item: hasil/<tahun>-item.csv
 *   --barang   master barang ECOUNT -> 07-master-barang-ecount.csv
 *
 * Identitas dokumen = KUNCI ECOUNT (kolom kunci_ecount, mis. "01/09/2026 -4"), bukan nomor PO:
 * nomor PO di ECOUNT tidak ditegakkan unik (48 nomor dipakai dua dokumen pada 2024-2026).
 *
 * PPN: pada 1.028 dokumen, "Total Jumlah Pesanan" di ECOUNT sudah termasuk PPN 11% sementara nilai
 * barisnya "Jumlah Sebelum Pajak". Dokumen seperti itu dikenali dari rasio total/baris = 1,11 lalu
 * pajak PPN dipasang ke tiap barisnya, dan total dokumen ditulis apa adanya dari ECOUNT supaya PO
 * tercetak sama dengan dokumen aslinya.
 */
class ImportEcountPurchaseOrders extends Command
{
    protected $signature = 'po:import-ecount
        {--daftar=C:/Users/valentino/Documents/work/hansoll-ecount/hansoll-periode/hasil/2024-header.csv,C:/Users/valentino/Documents/work/hansoll-ecount/hansoll-periode/hasil/2025-header.csv,C:/Users/valentino/Documents/work/hansoll-ecount/hansoll-periode/hasil/2026-header.csv : CSV dokumen PO (boleh beberapa, pisah koma)}
        {--laporan=C:/Users/valentino/Documents/work/hansoll-ecount/hansoll-periode/hasil/2024-item.csv,C:/Users/valentino/Documents/work/hansoll-ecount/hansoll-periode/hasil/2025-item.csv,C:/Users/valentino/Documents/work/hansoll-ecount/hansoll-periode/hasil/2026-item.csv : CSV baris item (boleh beberapa, pisah koma)}
        {--barang=C:/Users/valentino/Documents/work/hansoll-ecount/07-master-barang-ecount.csv : CSV master barang ECOUNT}
        {--write : Tulis ke basis data (tanpa ini hanya uji coba)}
        {--master-saja : Hanya memuat master barang ECOUNT ke tabel items (tanpa PO)}
        {--limit=0 : Batasi jumlah PO yang diproses (0 = semua)}';

    protected $description = 'Impor PO lama dari ekspor ECOUNT (uji coba dulu, tulis hanya dengan --write)';

    private const COMPANY_CODE = 'HIJ';

    private const STATUS_MAP = [
        'selesai' => PurchaseOrder::STATUS_COMPLETED,
        'dalam proses' => PurchaseOrder::STATUS_IN_PROGRESS,
        // Dokumen yang belum dikonfirmasi ECOUNT: catat sebagai PO baru (belum dikirim).
        'belum dikonfirmasi' => PurchaseOrder::STATUS_NEW,
    ];

    public function handle(): int
    {
        $tulis = (bool) $this->option('write');
        $batas = (int) $this->option('limit');

        $this->info($tulis ? 'MODE TULIS — data akan dimasukkan ke basis data' : 'MODE UJI — tidak ada yang ditulis');

        $masterBarang = $this->bacaMasterBarang((string) $this->option('barang'));

        // Mode khusus: hanya memuat master barang, tanpa PO. Sebaiknya dijalankan lebih dulu
        // supaya baris PO menempel ke barang master, bukan membuat barang baru satu per satu.
        if ($this->option('master-saja')) {
            return $this->muatMasterBarang($tulis, $masterBarang);
        }

        $daftar = $this->bacaDaftar($this->berkasOpsi('daftar'));
        $itemPerPo = $this->bacaLaporan($this->berkasOpsi('laporan'));

        // Pajak PPN (penambahan) yang dipakai dokumen ECOUNT bertotal termasuk PPN.
        $ppn = Tax::query()->where('calculation_type', 'addition')->where('rate', 11)->first();
        $ppnDokumen = 0;

        if ($daftar === [] || $itemPerPo === []) {
            $this->error('Berkas sumber tidak terbaca atau kosong.');

            return self::FAILURE;
        }

        // --- indeks tujuan ---
        $supplierByNama = [];
        $supplierByKode = [];
        foreach (Supplier::query()->get(['id', 'code', 'name']) as $s) {
            $supplierByNama[$this->normalkan((string) $s->name)] = $s;
            $supplierByKode[mb_strtoupper((string) $s->code)] = $s;
        }

        $itemByKode = [];
        $itemByNama = [];
        $itemByEcount = [];
        foreach (Item::query()->get(['id', 'code', 'name', 'source_code']) as $i) {
            $itemByKode[mb_strtoupper((string) $i->code)] = $i;
            $itemByNama[$this->normalkan((string) $i->name)] = $i;
            // kode asli ECOUNT tersimpan di source_code, bukan di code (kode exim dibuat otomatis)
            if (filled($i->source_code)) {
                $itemByEcount[mb_strtoupper((string) $i->source_code)] = $i;
            }
        }

        // Identitas dokumen = kunci ECOUNT. Jangan pakai po_number: satu nomor bisa dipakai dua
        // dokumen berbeda, sehingga dokumen kedua akan salah dianggap "sudah ada" lalu dilewati.
        $sudahAda = [];
        foreach (PurchaseOrder::query()->where('source', 'ecount')->whereNotNull('source_code')->pluck('source_code') as $kunci) {
            $sudahAda[trim((string) $kunci)] = true;
        }

        // barang ECOUNT: nama ternormalisasi -> [kode, nama, spesifikasi].
        // Indeks kedua memakai "nama + spesifikasi": di master ECOUNT banyak nama sudah memuat
        // spesifikasi ("Wipol 750ml"), sedangkan di slip PO nama dan spesifikasi ditulis terpisah
        // ("WIPOL" + "750ml"). Tanpa indeks ini 289 baris tidak tersambung ke barangnya.
        $barangByNama = [];
        $barangByNamaSpek = [];
        foreach ($masterBarang as $b) {
            $barangByNama[$this->normalkan($b['nama'])] = $b;
            if ($b['spesifikasi'] !== '') {
                $barangByNamaSpek[$this->normalkan($b['nama'].' '.$b['spesifikasi'])] = $b;
            }
        }

        $divisiGa = Division::query()->whereRaw('UPPER(code) = ?', ['GA'])->value('id');
        $pembuat = (int) (User::query()->orderBy('id')->value('id') ?? 0);

        if ($tulis && (! $divisiGa || ! $pembuat)) {
            $this->error('Divisi GA atau pengguna pembuat tidak ditemukan.');

            return self::FAILURE;
        }

        // --- hitung pemetaan ---
        $vendorCocok = $vendorTakCocok = 0;
        $vendorLewatKode = 0;
        $vendorDibuat = 0;
        $vendorTurunan = 0;
        $vendorTakCocokDaftar = [];
        $barisCocok = $barisTakCocok = 0;
        $barisPakaiKodeEcount = $barisTanpaKode = $barisTanpaKodeTersambung = 0;
        $barangBaru = [];
        $poSiap = 0;
        $poLewat = 0;
        $dupBaris = 0;
        $selisihTotal = 0;

        $daftarVendor = [];
        $daftarPic = [];

        $noUrut = 0;

        foreach ($daftar as $kunci => $dok) {
            $noPo = (string) $dok['no_po'];
            if ($batas > 0 && $noUrut >= $batas) {
                break;
            }
            $noUrut++;

            if (isset($sudahAda[$kunci])) {
                $poLewat++;

                continue;
            }

            // Supplier: utamakan KODE dari peta supplier di berkas olah (kode_supplier).
            [$supplier, $caraVendor] = $this->cariSupplier($dok, $supplierByKode, $supplierByNama);
            if ($supplier) {
                if ($caraVendor === 'kode') {
                    $vendorLewatKode++;
                } else {
                    $vendorCocok++;
                }
            } elseif (trim((string) $dok['kode_supplier']) !== '') {
                // Peta sudah menetapkan kodenya, baris supplier-nya belum ada di exim -> dibuat saat menulis.
                $vendorDibuat++;
                if ($tulis) {
                    $supplier = Supplier::query()->firstOrCreate(
                        ['code' => $dok['kode_supplier']],
                        [
                            'name' => $dok['nama_supplier'] !== '' ? $dok['nama_supplier'] : $dok['vendor'],
                            'is_active' => true,
                        ],
                    );
                    $supplierByKode[mb_strtoupper((string) $supplier->code)] = $supplier;
                }
            } else {
                // Sisa dokumen: peta supplier tidak memuatnya (13 dokumen). Kalau ECOUNT masih
                // mencantumkan nama vendornya, buat supplier baru dengan kode turunan nama
                // (mis. ALFAMART -> EC-ALFAMART). Dokumen yang sama sekali tanpa nama vendor tidak
                // bisa dipastikan pemiliknya -> dilewati dan dilaporkan ke pengguna.
                $namaVendor = trim((string) $dok['vendor']);

                if ($namaVendor !== '') {
                    $vendorTurunan++;
                    if ($tulis) {
                        $supplier = Supplier::query()->firstOrCreate(
                            ['code' => $this->kodeSupplierTurunan($namaVendor)],
                            ['name' => $namaVendor, 'is_active' => true],
                        );
                        $supplierByKode[mb_strtoupper((string) $supplier->code)] = $supplier;
                        $supplierByNama[$this->normalkan($namaVendor)] = $supplier;
                    }
                } else {
                    $vendorTakCocok++;
                    $vendorTakCocokDaftar['(tanpa nama vendor)'] = ($vendorTakCocokDaftar['(tanpa nama vendor)'] ?? 0) + 1;
                }
            }

            $daftarVendor[$dok['vendor']] = ($daftarVendor[$dok['vendor']] ?? 0) + 1;
            $daftarPic[$dok['pic'] ?: '(kosong)'] = ($daftarPic[$dok['pic'] ?: '(kosong)'] ?? 0) + 1;

            $baris = $itemPerPo[$kunci] ?? [];
            $jumlahBaris = 0;
            $subtotal = 0.0;

            foreach ($baris as $b) {
                $jumlahBaris++;
                $subtotal += $b['jumlah'];

                [$namaBersih, $spekBracket] = $this->pisahNama($b['item']);
                $kodeEcount = $this->kodeBaris($b, $namaBersih, $spekBracket, $barangByNama, $barangByNamaSpek);
                $adaItem = ($kodeEcount !== null && isset($itemByEcount[$kodeEcount]))
                    || isset($itemByNama[$this->normalkan($namaBersih)])
                    || ($spekBracket !== '' && isset($itemByNama[$this->normalkan($namaBersih.' '.$spekBracket)]));

                if ($adaItem) {
                    $barisCocok++;
                } else {
                    $barisTakCocok++;
                    $barangBaru[$this->normalkan($namaBersih)] = $namaBersih;
                }

                if ($kodeEcount) {
                    $barisPakaiKodeEcount++;
                } else {
                    $barisTanpaKode++;

                    // baris tanpa kode di berkas, tapi tetap tersambung ke barang ECOUNT lewat
                    // pencocokan nama + spesifikasi (mis. "WIPOL" + "750ml" -> barang "Wipol 750ml")
                    if ($adaItem) {
                        $barisTanpaKodeTersambung++;
                    }
                }
            }

            if ($jumlahBaris === 0) {
                $dupBaris++;

                continue;
            }

            // Total dokumen di ECOUNT sering sudah termasuk PPN 11% sementara jumlah barisnya belum,
            // jadi rasio ~1,11 itu wajar — bukan selisih. Hanya rasio di luar 1,00/1,11 yang dihitung.
            $rasioTotal = $subtotal > 0 ? (float) $dok['total'] / $subtotal : 0.0;
            if ((float) $dok['total'] > 0 && $subtotal > 0
                && abs($rasioTotal - 1.0) > 0.02
                && abs($rasioTotal - 1.11) > 0.02) {
                $selisihTotal++;
            }

            // PPN: dokumen ECOUNT yang "Total Jumlah Pesanan"-nya termasuk PPN 11%.
            // Dihitung di luar blok tulis supaya uji kering juga melaporkannya.
            $pakaiPpn = $ppn !== null && $this->pakaiPpn((string) $dok['total'], $subtotal);
            if ($pakaiPpn) {
                $ppnDokumen++;
            }

            if ($tulis && $supplier) {
                DB::transaction(function () use ($dok, $noPo, $supplier, $baris, $divisiGa, $pembuat, $jumlahBaris, $pakaiPpn, $ppn, $barangByNama, $barangByNamaSpek, &$itemByKode, &$itemByNama, &$itemByEcount): void {
                    $po = PurchaseOrder::query()->create([
                        'po_number' => $noPo,
                        'company_code' => self::COMPANY_CODE,
                        // Urutan harian diambil dari KUNCI ECOUNT, bukan dari nomor PO: nomor PO
                        // bisa sama untuk dua dokumen (dan sesudah titik itu nomornya tertinggal
                        // satu dari urutan dokumen), sehingga memakai nomor akan menabrak
                        // indeks unik (company_code, po_date, sequence_number).
                        'sequence_number' => $this->urutkanKunci((string) $dok['kunci']),
                        'po_date' => $this->tanggal($dok['kunci']),
                        'division_id' => $divisiGa,
                        'supplier_id' => $supplier->id,
                        'pic_name' => $dok['pic'] ?: '-',
                        'currency' => PurchaseOrder::CURRENCY_IDR,
                        'delivery_date' => $this->tanggalKirim((string) $dok['kirim']),
                        'status' => self::STATUS_MAP[mb_strtolower($dok['status'])] ?? PurchaseOrder::STATUS_SENT_TO_VENDOR,
                        'source' => 'ecount',
                        'source_code' => $dok['kunci'],
                        'created_by' => $pembuat,
                    ]);

                    $nomor = 0;
                    $barisPo = [];
                    foreach ($baris as $b) {
                        $nomor++;
                        [$namaBersih, $spekBracket] = $this->pisahNama($b['item']);
                        $kunciSpek = $this->normalkan($namaBersih.' '.$spekBracket);
                        $master = $barangByNama[$this->normalkan($namaBersih)]
                            ?? ($spekBracket !== '' ? ($barangByNamaSpek[$kunciSpek] ?? null) : null);
                        $kodeEcount = $this->kodeBaris($b, $namaBersih, $spekBracket, $barangByNama, $barangByNamaSpek);
                        $item = ($kodeEcount !== null ? ($itemByEcount[$kodeEcount] ?? null) : null)
                            ?? ($itemByNama[$this->normalkan($namaBersih)] ?? null)
                            ?? ($spekBracket !== '' ? ($itemByNama[$kunciSpek] ?? null) : null);

                        if (! $item && $kodeEcount !== null) {
                            // Pengaman: kalau kode ECOUNT-nya sudah ada di basis data tapi tidak
                            // tertangkap indeks di awal (mis. indeks dibangun sebelum barang itu
                            // masuk), pakai barang yang ada — jangan bikin barang kembar berkode sama.
                            $item = Item::query()->whereRaw('UPPER(source_code) = ?', [$kodeEcount])->first();
                            if ($item) {
                                $itemByEcount[$kodeEcount] = $item;
                                $itemByNama[$this->normalkan((string) $item->name)] = $item;
                            }
                        }

                        if (! $item) {
                            $item = Item::query()->create([
                                'code' => $kodeEcount ?: $this->kodeBaru($namaBersih),
                                'name' => mb_substr($namaBersih, 0, 255),
                                'specification' => ($master['spesifikasi'] ?? '') !== '' ? $master['spesifikasi'] : $spekBracket,
                                'source' => 'ecount',
                                'source_code' => $kodeEcount,
                                'is_active' => true,
                            ]);
                            $itemByKode[mb_strtoupper((string) $item->code)] = $item;
                            $itemByNama[$this->normalkan((string) $item->name)] = $item;
                            if ($kodeEcount !== null) {
                                $itemByEcount[$kodeEcount] = $item;
                            }
                        }

                        $poItem = $po->items()->create([
                            'line_number' => $nomor,
                            'item_id' => $item->id,
                            'item_code_snapshot' => $item->code,
                            'item_name_snapshot' => $item->name,
                            'item_code' => $item->code,
                            'item_name' => $namaBersih,
                            'specification' => $spekBracket !== '' ? $spekBracket : $item->specification,
                            'quantity' => $b['qty'],
                            'unit_price_amount' => $b['harga'],
                            'subtotal_amount' => $b['jumlah'],
                            'total_amount' => $b['jumlah'],
                            'notes' => ($b['keterangan'] ?? '') !== '' ? $b['keterangan'] : null,
                        ]);
                        $barisPo[] = $poItem;
                    }

                    // Dokumen yang totalnya termasuk PPN: pasang PPN ke setiap barisnya.
                    // Pajak dihitung model dari subtotal_amount baris (decimal 2 angka).
                    if ($pakaiPpn) {
                        foreach ($barisPo as $barisItem) {
                            $barisItem->taxes()->create(['tax_id' => $ppn->id]);
                        }
                    }

                    $po->recalculateTotals();

                    // Total dokumen ditulis apa adanya dari ECOUNT supaya PO tercetak = dokumen asli.
                    if ($jumlahBaris > 0 && (float) $dok['total'] > 0) {
                        $po->forceFill(['grand_total_amount' => (float) $dok['total']])->saveQuietly();
                    } elseif ($jumlahBaris === 0 && (float) $dok['total'] > 0) {
                        // Dokumen tanpa baris item tapi bertotal (cacat di ECOUNT) — catat, jangan dikarang.
                        $po->forceFill(['notes' => 'Di ECOUNT total dokumen Rp '.number_format((float) $dok['total'], 2, ',', '.').' tetapi tidak ada baris item.'])->saveQuietly();
                    }

                });
            }

            $poSiap++;
        }

        // --- laporan ---
        $nomorGandaJumlah = 0;
        $nomorGandaDokumen = 0;
        foreach (array_count_values(array_filter(array_column($daftar, 'no_po'))) as $jml) {
            if ($jml > 1) {
                $nomorGandaJumlah++;
                $nomorGandaDokumen += $jml;
            }
        }

        $this->newLine();
        $this->table(['Pemeriksaan', 'Hasil'], [
            ['Berkas dokumen terbaca', number_format(count($daftar), 0, ',', '.').' dokumen'],
            ['Berkas item terbaca', number_format(array_sum(array_map('count', $itemPerPo)), 0, ',', '.').' baris untuk '.number_format(count($itemPerPo), 0, ',', '.').' PO'],
            ['Master barang ECOUNT', number_format(count($masterBarang), 0, ',', '.').' barang'],
            ['PO sudah ada di exim (dilewati)', number_format($poLewat, 0, ',', '.')],
            ['PO siap diproses', number_format($poSiap, 0, ',', '.')],
            ['PO tanpa baris item', number_format($dupBaris, 0, ',', '.')],
            ['Nomor PO dipakai >1 dokumen (label, bukan identitas)', number_format($nomorGandaJumlah, 0, ',', '.').' nomor / '.number_format($nomorGandaDokumen, 0, ',', '.').' dokumen'],
            ['Dokumen bertotal termasuk PPN 11% (pajak PPN dipasang)', number_format($ppnDokumen, 0, ',', '.').($ppn ? '' : ' — PAJAK PPN 11% TIDAK DITEMUKAN DI TABEL TAXES')],
            ['Vendor cocok lewat kode peta', number_format($vendorLewatKode, 0, ',', '.')],
            ['Vendor cocok lewat nama', number_format($vendorCocok, 0, ',', '.')],
            ['Vendor dibuat dari kode peta', number_format($vendorDibuat, 0, ',', '.')],
            ['Vendor tanpa peta, dibuat kode turunan (EC-…)', number_format($vendorTurunan, 0, ',', '.')],
            ['Tanpa nama vendor (PO dilewati)', number_format($vendorTakCocok, 0, ',', '.')],
            ['Baris item sudah ada di master exim', number_format($barisCocok, 0, ',', '.')],
            ['Baris item perlu barang baru', number_format($barisTakCocok, 0, ',', '.').' ('.number_format(count($barangBaru), 0, ',', '.').' nama unik)'],
            ['  di antaranya dapat kode asli ECOUNT', number_format($barisPakaiKodeEcount, 0, ',', '.')],
            ['  tanpa kode ECOUNT (dibuat kode EC-…)', number_format($barisTanpaKode, 0, ',', '.')],
            ['    tetapi tersambung lewat nama + spesifikasi', number_format($barisTanpaKodeTersambung, 0, ',', '.')],
            ['Selisih total dokumen vs baris (di luar PPN 11%)', number_format($selisihTotal, 0, ',', '.')],
        ]);

        if ($vendorTakCocokDaftar !== []) {
            arsort($vendorTakCocokDaftar);
            $this->newLine();
            $this->warn('Vendor belum cocok (10 terbanyak):');
            foreach (array_slice($vendorTakCocokDaftar, 0, 10, true) as $nama => $jml) {
                $this->line('  - '.$nama.' ('.$jml.' PO)');
            }
        }

        $this->newLine();
        $this->line('Status yang akan dipakai: '.json_encode(self::STATUS_MAP));
        $this->line('Company code: '.self::COMPANY_CODE.' | PIC: nama dari ECOUNT, tanpa pengguna');

        if (! $tulis) {
            $this->newLine();
            $this->info('Ini baru uji coba. Jalankan ulang dengan --write untuk benar-benar mengimpor.');
        } else {
            $this->newLine();
            $this->info('Selesai menulis '.number_format($poSiap, 0, ',', '.').' PO.');
        }

        return self::SUCCESS;
    }

    /**
     * Muat master barang ECOUNT ke tabel `items` (tanpa PO).
     *
     * Kode barang exim dibuat otomatis oleh ItemCodeGenerator, jadi kode asli ECOUNT disimpan di
     * `source_code` — itulah yang dipakai baris PO dan pencarian untuk menyambung ke barang ECOUNT.
     * Satuan tidak diisi: ekspor ECOUNT tidak memuat satuan (keputusan val, 6 Okt 2026).
     *
     * @param  list<array{kode:string, nama:string, spesifikasi:string, aktif:string, grup:string}>  $masterBarang
     */
    private function muatMasterBarang(bool $tulis, array $masterBarang): int
    {
        if ($masterBarang === []) {
            $this->error('Berkas master barang tidak terbaca atau kosong.');

            return self::FAILURE;
        }

        $adaKode = [];
        $adaNama = [];
        foreach (Item::query()->get(['id', 'code', 'source_code', 'name']) as $i) {
            if (filled($i->source_code)) {
                $adaKode[mb_strtoupper((string) $i->source_code)] = $i;
            }
            $adaNama[$this->normalkan((string) $i->name)] = $i;
        }

        $sudahAda = 0;
        $akanDibuat = 0;
        $nonaktif = 0;
        $namaSama = 0;
        $dibuat = 0;

        foreach ($masterBarang as $b) {
            $kode = mb_strtoupper($b['kode']);

            // Identitas barang = KODE ECOUNT, bukan nama: di master ECOUNT ada 127 nama yang dipakai
            // beberapa baris dengan spesifikasi berbeda (mis. Gunting Bordir 3" dan 5", Hotel berbeda),
            // jadi menyaring lewat nama akan menghapus barang yang sebenarnya berbeda.
            if ($kode !== '' && isset($adaKode[$kode])) {
                $sudahAda++;

                continue;
            }

            if (isset($adaNama[$this->normalkan($b['nama'])])) {
                $namaSama++;
            }

            $akanDibuat++;

            if ($b['aktif'] !== 'YES') {
                $nonaktif++;
            }

            if ($tulis) {
                $item = Item::query()->create([
                    'name' => mb_substr($b['nama'], 0, 255),
                    'specification' => $b['spesifikasi'],
                    'source' => 'ecount',
                    'source_code' => $b['kode'] ?: null,
                    'is_active' => $b['aktif'] === 'YES',
                ]);
                $adaKode[$kode] = $item;
                $adaNama[$this->normalkan((string) $item->name)] = $item;
                $dibuat++;
            }
        }

        $this->table(['Pemeriksaan', 'Hasil'], [
            ['Berkas master barang terbaca', number_format(count($masterBarang), 0, ',', '.').' barang'],
            ['Sudah ada di exim (kode ECOUNT sama, dilewati)', number_format($sudahAda, 0, ',', '.')],
            ['Barang baru', number_format($akanDibuat, 0, ',', '.').($tulis ? ' (dibuat: '.number_format($dibuat, 0, ',', '.').')' : '')],
            ['  di antaranya nonaktif di ECOUNT', number_format($nonaktif, 0, ',', '.')],
            ['  di antaranya namanya sama dengan barang lain (spesifikasi pembeda)', number_format($namaSama, 0, ',', '.')],
            ['Satuan', 'tidak diisi — ekspor ECOUNT tidak memuat satuan'],
        ]);

        $this->newLine();
        $this->line($tulis
            ? 'Selesai. Master barang sudah dimuat; lanjutkan impor PO dengan perintah yang sama tanpa --master-saja.'
            : 'Ini baru uji coba. Jalankan ulang dengan --write untuk benar-benar memuat master barang.');

        return self::SUCCESS;
    }

    /**
     * Baca berkas dokumen (hasil olah: no_po, kunci_ecount, vendor_di_po, pic_ecount, ...).
     *
     * Diindeks dengan KUNCI ECOUNT karena itulah identitas dokumen — nomor PO hanya label dan
     * bisa dipakai dua dokumen berbeda, jadi memakai nomor sebagai key akan menimpa dokumen.
     *
     * @param  list<string>  $paths
     * @return array<string, array{kunci:string, no_po:string, vendor:string, pic:string, kirim:string, total:string, status:string}>
     */
    private function bacaDaftar(array $paths): array
    {
        $out = [];
        foreach ($this->berkasDari($paths) as $r) {
            $kunci = $this->nilai($r, ['kunci_ecount', 'tanggal_no', 'kunci']);
            if (! preg_match('#^\d{2}/\d{2}/\d{4}\s*-\d+$#', $kunci)) {
                continue;
            }
            $out[$kunci] ??= [
                'kunci' => $kunci,
                'no_po' => $this->nilai($r, ['no_po']),
                'vendor' => $this->nilai($r, ['vendor_di_po', 'vendor']),
                // peta supplier sudah disiapkan di berkas olah: kode_supplier = kode supplier exim
                'kode_supplier' => $this->nilai($r, ['kode_supplier']),
                'nama_supplier' => $this->nilai($r, ['nama_supplier']),
                'pic' => $this->nilai($r, ['pic_ecount', 'pic']),
                'kirim' => $this->nilai($r, ['tanggal_kirim', 'tgl_kirim']),
                'total' => $this->nilai($r, ['total_dokumen', 'total']) ?: '0',
                'status' => $this->nilai($r, ['status_po', 'status']),
            ];
        }

        return $out;
    }

    /**
     * Baca berkas baris item (hasil olah), dikelompokkan per KUNCI ECOUNT.
     *
     * @param  list<string>  $paths
     * @return array<string, list<array{item:string, qty:float, harga:float, jumlah:float}>>
     */
    private function bacaLaporan(array $paths): array
    {
        $out = [];
        foreach ($this->berkasDari($paths) as $r) {
            $kunci = $this->nilai($r, ['kunci_ecount', 'tanggal_no', 'kunci']);
            $nama = $this->nilai($r, ['nama_barang', 'item']);
            if ($kunci === '' || $nama === '') {
                continue;
            }

            // Berkas hasil olah memisahkan nama dan spesifikasi ke dua kolom, sedangkan slip/laporan
            // ECOUNT menulisnya menyatu sebagai "NAMA [SPESIFIKASI]". Gabungkan lagi supaya
            // pisahNama() dan pencocokan "nama + spesifikasi" di master barang bekerja.
            $spek = $this->nilai($r, ['spesifikasi', 'spec']);
            if ($spek !== '' && ! str_contains($nama, '[')) {
                $nama .= ' ['.$spek.']';
            }

            $out[$kunci][] = [
                'item' => $nama,
                // kode barang asli ECOUNT kalau ada (kolom kode_barang_ecount di berkas olah)
                'kode' => $this->nilai($r, ['kode_barang_ecount', 'kode']),
                'qty' => $this->angka($this->nilai($r, ['kuantitas', 'qty'])),
                'harga' => $this->angka($this->nilai($r, ['harga_satuan', 'harga'])),
                'jumlah' => $this->angka($this->nilai($r, ['jumlah', 'subtotal'])),
                // Keterangan per baris dipakai di kolom Keterangan pada cetakan PO.
                'keterangan' => trim((string) $this->nilai($r, ['keterangan', 'notes', 'catatan'])),
            ];
        }

        return $out;
    }

    /** @return list<string> */
    private function berkasOpsi(string $opsi): array
    {
        $daftar = [];
        foreach (explode(',', (string) $this->option($opsi)) as $p) {
            $p = trim($p);
            if ($p !== '') {
                $daftar[] = $p;
            }
        }

        return $daftar;
    }

    /**
     * Gabungkan isi beberapa berkas CSV jadi satu aliran baris.
     *
     * @param  list<string>  $paths
     * @return \Generator<array<string, string>>
     */
    private function berkasDari(array $paths): \Generator
    {
        foreach ($paths as $path) {
            yield from $this->baris($path);
        }
    }

    /**
     * Ambil nilai pertama yang ada dari beberapa kemungkinan nama kolom, supaya berkas hasil olah
     * (kunci_ecount, vendor_di_po, ...) maupun ekspor lama (tanggal_no, vendor, ...) sama-sama terbaca.
     *
     * @param  array<string, string>  $baris
     * @param  list<string>  $kandidat
     */
    private function nilai(array $baris, array $kandidat): string
    {
        foreach ($kandidat as $k) {
            if (isset($baris[$k]) && trim((string) $baris[$k]) !== '') {
                return trim((string) $baris[$k]);
            }
        }

        return '';
    }

    /** @return list<array{kode:string, nama:string, spesifikasi:string}> */
    private function bacaMasterBarang(string $path): array
    {
        if (! is_file($path)) {
            return [];
        }

        $out = [];
        foreach ($this->baris($path) as $r) {
            $nama = trim((string) ($r['nama'] ?? ''));
            if ($nama === '') {
                continue;
            }
            $out[] = [
                'kode' => trim((string) ($r['kode'] ?? '')),
                'nama' => $nama,
                'spesifikasi' => trim((string) ($r['spesifikasi'] ?? '')),
                'aktif' => mb_strtoupper(trim((string) ($r['aktif'] ?? 'YES'))),
                'grup' => trim((string) ($r['grup'] ?? '')),
            ];
        }

        return $out;
    }

    /** @return \Generator<array<string, string>> */
    private function baris(string $path): \Generator
    {
        if (! is_file($path)) {
            $this->error('Berkas tidak ditemukan: '.$path);

            return;
        }

        $f = fopen($path, 'r');
        if (! $f) {
            return;
        }

        $kepala = fgetcsv($f, 0, ',', '"', '\\');
        if (! $kepala) {
            fclose($f);

            return;
        }
        $kepala[0] = preg_replace('/^\x{FEFF}/u', '', (string) $kepala[0]) ?? $kepala[0];

        while (($row = fgetcsv($f, 0, ',', '"', '\\')) !== false) {
            if (count($row) === 1 && trim((string) $row[0]) === '') {
                continue;
            }
            $isi = [];
            foreach ($kepala as $i => $nama) {
                $isi[trim((string) $nama)] = $row[$i] ?? '';
            }
            yield $isi;
        }

        fclose($f);
    }

    private function normalkan(string $teks): string
    {
        $t = mb_strtoupper(trim($teks));
        $t = preg_replace('/[\(\)\[\]\.,;:!?\-\/"\']/u', ' ', $t) ?? $t;
        $t = preg_replace('/\s+/u', ' ', $t) ?? $t;

        return trim((string) preg_replace('/^(PT|CV|UD|PD|TB|TOKO|FA|NV)\s+/u', '', trim($t)));
    }

    /**
     * Memisahkan spesifikasi yang menempel di nama barang ECOUNT.
     * "Nasuha [1 Inch]" -> ['Nasuha', '1 Inch'].
     *
     * @return array{0: string, 1: string}
     */
    private function pisahNama(string $teks): array
    {
        if (preg_match('/^(.*?)\s*\[(.*)\]$/u', trim($teks), $m)) {
            return [trim($m[1]), trim($m[2])];
        }

        return [trim($teks), ''];
    }

    /**
     * Kode supplier turunan dari nama vendor, untuk vendor yang tidak ada di peta supplier.
     * Contoh: "ALFAMART" -> "EC-ALFAMART", "CV. ADAM JAYA" -> "EC-CV-ADAM-JAYA".
     */
    private function kodeSupplierTurunan(string $nama): string
    {
        $slug = trim((string) preg_replace('/[^A-Z0-9]+/', '-', mb_strtoupper($nama)), '-');

        return 'EC-'.mb_substr($slug !== '' ? $slug : 'TANPA-NAMA', 0, 40);
    }

    /**
     * Cari supplier tujuan: utamakan KODE dari peta supplier di berkas olah (`kode_supplier`),
     * baru cocokkan nama. Peta itu sudah menyelesaikan 5.198 dari 5.211 dokumen
     * (3.792 nama persis, 222 diperiksa manual, sisanya kode baru yang belum ada di exim).
     *
     * @param  array<string, mixed>  $dok
     * @param  array<string, Supplier>  $supplierByKode
     * @param  array<string, Supplier>  $supplierByNama
     * @return array{0: ?Supplier, 1: string} supplier dan cara cocoknya: 'kode' / 'nama' / '-'
     */
    private function cariSupplier(array $dok, array $supplierByKode, array $supplierByNama): array
    {
        $kode = mb_strtoupper(trim((string) ($dok['kode_supplier'] ?? '')));
        if ($kode !== '' && isset($supplierByKode[$kode])) {
            return [$supplierByKode[$kode], 'kode'];
        }

        $nama = $this->normalkan((string) ($dok['vendor'] ?? ''));
        if ($nama !== '' && isset($supplierByNama[$nama])) {
            return [$supplierByNama[$nama], 'nama'];
        }

        return [null, '-'];
    }

    /**
     * Kode barang asli ECOUNT untuk satu baris: dari kolom `kode_barang_ecount` di berkas olah,
     * kalau kosong baru dicari lewat nama di master barang ECOUNT — mula-mula "nama saja", lalu
     * "nama + spesifikasi" karena di master ECOUNT spesifikasi sering menyatu dengan namanya
     * (contoh: baris "WIPOL" + "750ml" menunjuk barang "Wipol 750ml" berkode W02).
     *
     * @param  array<string, mixed>  $b
     * @param  array<string, array{kode:string, nama:string, spesifikasi:string}>  $barangByNama
     * @param  array<string, array{kode:string, nama:string, spesifikasi:string}>  $barangByNamaSpek
     */
    private function kodeBaris(array $b, string $namaBersih, string $spekBracket, array $barangByNama, array $barangByNamaSpek = []): ?string
    {
        $kode = mb_strtoupper(trim((string) ($b['kode'] ?? '')));
        if ($kode === '') {
            $kode = mb_strtoupper(trim((string) ($barangByNama[$this->normalkan($namaBersih)]['kode'] ?? '')));
        }
        if ($kode === '' && $spekBracket !== '') {
            $kode = mb_strtoupper(trim((string) ($barangByNamaSpek[$this->normalkan($namaBersih.' '.$spekBracket)]['kode'] ?? '')));
        }

        return $kode === '' ? null : $kode;
    }

    /**
     * Baca angka dari dua gaya berkas yang mungkin:
     *  - ekspor lama gaya Indonesia: "50.000" (titik = pemisah ribuan, koma = desimal);
     *  - berkas hasil olah: "843412.50" / "111314660.58" (titik = desimal, tanpa pemisah ribuan).
     *
     * Versi lama SELALU membuang titik, sehingga berkas hasil olah yang memuat pecahan akan
     * membengkak 10-100 kali (843412.50 -> 84341250).
     */
    private function angka(string $teks): float
    {
        $t = str_replace(' ', '', trim($teks));
        if ($t === '' || $t === '-') {
            return 0.0;
        }

        // gaya Indonesia: 50.000 / 1.234.567,89
        if (preg_match('#^-?\d{1,3}(\.\d{3})+(,\d+)?$#', $t)) {
            return (float) str_replace(['.', ','], ['', '.'], $t);
        }

        // ada koma -> koma adalah desimal, titik pemisah ribuan
        if (str_contains($t, ',')) {
            return (float) str_replace(',', '.', str_replace('.', '', $t));
        }

        // sisanya titik berarti desimal (843412.50) atau bilangan biasa
        return (float) $t;
    }

    /**
     * Dokumen ECOUNT memakai PPN 11% bila total dokumennya = jumlah baris sebelum pajak x 1,11.
     *
     * Bukti dari data 2024-2026: 1.028 dokumen rasionya tepat 1,1100 dan jumlah baris x 1,11 sama
     * persis dengan total ECOUNT (3 dokumen beda < Rp 0,10 karena pembulatan 2 desimal ECOUNT).
     * Sisanya 4.114 dokumen rasionya tepat 1,0000 -> tanpa pajak.
     */
    private function pakaiPpn(string $total, float $subtotal): bool
    {
        $total = $this->angka($total);
        if ($total <= 0 || $subtotal <= 0) {
            return false;
        }

        return abs(($total / $subtotal) - 1.11) < 0.002;
    }

    private function tanggal(string $kunci): ?string
    {
        if (! preg_match('#^(\d{2})/(\d{2})/(\d{4})#', trim($kunci), $m)) {
            return null;
        }

        return Carbon::createFromFormat('d/m/Y', "{$m[1]}/{$m[2]}/{$m[3]}")?->toDateString();
    }

    /**
     * Tanggal kirim dari berkas olah: ECOUNT menulisnya dd/mm/yyyy ("25/01/2024"), sedangkan kolom
     * `delivery_date` bertipe date — Carbon menolak format itu dan impor berhenti. 4.973 dari 5.211
     * dokumen memakai format ini, jadi jangan pernah menulisnya mentah-mentah.
     * Format tak dikenal -> null supaya impor lanjut, bukan gagal.
     */
    private function tanggalKirim(string $teks): ?string
    {
        $t = trim($teks);

        if (preg_match('#^(\d{2})/(\d{2})/(\d{4})$#', $t, $m)) {
            return "{$m[3]}-{$m[2]}-{$m[1]}";
        }

        if (preg_match('#^\d{4}-\d{2}-\d{2}#', $t)) {
            return substr($t, 0, 10);
        }

        return null;
    }

    /**
     * Urutan harian dokumen, diambil dari KUNCI ECOUNT ("01/09/2026 -4" -> 4).
     *
     * Jangan ambil dari nomor PO: nomor PO tidak unik, dan setelah kejadian nomor dobel seluruh
     * sisa hari itu nomornya tertinggal satu dari urutan dokumen (404 dokumen pada 2024-2026).
     */
    private function urutkanKunci(string $kunci): int
    {
        if (preg_match('#-\s*(\d+)\s*$#', trim($kunci), $m)) {
            return (int) $m[1];
        }

        return 0;
    }

    private function kodeBaru(string $nama): string
    {
        $slug = mb_strtoupper(preg_replace('/[^A-Za-z0-9]+/', '-', $nama) ?? 'ITEM');

        return 'EC-'.mb_substr(trim((string) $slug, '-'), 0, 24).'-'.mb_substr(md5($nama), 0, 4);
    }
}
