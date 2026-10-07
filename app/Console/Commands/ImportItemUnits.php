<?php

namespace App\Console\Commands;

use App\Models\Item;
use App\Models\Unit;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Mengisi satuan barang (items.unit_id) dari ekspor ECOUNT "per satuan":
 * tiap berkas Excel dinamai menurut satuannya, isinya daftar barang.
 *
 * Sumber sudah diubah lebih dulu menjadi CSV (kolom: kode, nama, satuan, berkas),
 * hasil pembacaan 19 berkas dari folder "download ecount\satuan".
 *
 * Aman dijalankan berulang (idempoten). Tanpa --apply hanya melaporkan rencana.
 */
class ImportItemUnits extends Command
{
    protected $signature = 'item:import-satuan
        {--berkas= : Berkas CSV sumber (kode,nama,satuan,berkas)}
        {--apply : Tulis ke basis data (tanpa ini hanya laporan)}
        {--tutup-kembar : Isi juga kode yang muncul di dua satuan (bawaan: dilewati)}
        {--kembar-ke= : Untuk kode kembar, pakai baris dari satuan ini saja (mis. carton)}';

    protected $description = 'Mengisi satuan barang dari ekspor ECOUNT per satuan';

    private const BERKAS_BAWAAN = 'C:\Users\valentino\Documents\work\hansoll-ecount\satuan-per-item.csv';

    private function normNama(string $nama): string
    {
        return preg_replace('/[^A-Z0-9]/', '', strtoupper($nama)) ?? '';
    }

    public function handle(): int
    {
        $berkas = $this->option('berkas') ?: self::BERKAS_BAWAAN;

        if (! is_file($berkas)) {
            $this->error("Berkas tidak ditemukan: {$berkas}");

            return self::FAILURE;
        }

        $handle = fopen($berkas, 'r');
        $tajuk = fgetcsv($handle);
        $baris = [];

        while (($r = fgetcsv($handle)) !== false) {
            if (count($r) < 4) {
                continue;
            }
            [$kode, $nama, $satuan] = [trim((string) $r[0]), trim((string) $r[1]), trim((string) $r[2])];

            // buang baris kaki ekspor (cap waktu cetak) yang tidak punya nama barang
            if ($nama === '' || $kode === '' || preg_match('#^\d{2}/\d{2}/\d{4}#', $kode)) {
                continue;
            }
            $baris[] = ['kode' => $kode, 'nama' => $nama, 'satuan' => $satuan];
        }
        fclose($handle);

        $this->line('Baris bersih  : '.count($baris));

        // kode yang muncul di lebih dari satu satuan -> dilewati (keputusan pemilik data)
        $satuanPerKode = [];
        foreach ($baris as $b) {
            $satuanPerKode[strtoupper($b['kode'])][$b['satuan']] = true;
        }
        $kembar = array_keys(array_filter($satuanPerKode, fn ($s) => count($s) > 1));
        $this->line('Kode kembar   : '.count($kembar).($kembar ? ' ('.implode(', ', array_slice($kembar, 0, 12)).')' : ''));

        // satuan yang dibutuhkan dari berkas
        $satuanDibutuhkan = collect($baris)->pluck('satuan')->unique()->sort()->values();
        $this->line('Satuan dipakai: '.$satuanDibutuhkan->count().' — '.$satuanDibutuhkan->implode(', '));

        $ada = Unit::query()->pluck('id', 'code')->all();
        $adaKecil = [];
        foreach (Unit::query()->get(['id', 'code', 'name']) as $u) {
            $adaKecil[strtolower((string) $u->code)] = $u->id;
            $adaKecil[strtolower((string) $u->name)] = $u->id;
        }
        $satuanBaru = $satuanDibutuhkan->reject(fn ($s) => isset($adaKecil[strtolower($s)]))->values();
        $this->line('Satuan belum ada di master: '.$satuanBaru->count().($satuanBaru->count() ? ' — '.$satuanBaru->implode(', ') : ''));

        // peta barang: cocokkan lewat source_code (kode ECOUNT) lalu code
        $petaBarang = [];
        $petaNama = [];
        foreach (Item::query()->get(['id', 'code', 'source_code', 'name']) as $it) {
            foreach ([$it->source_code, $it->code] as $k) {
                $k = strtoupper(trim((string) $k));
                if ($k !== '' && ! isset($petaBarang[$k])) {
                    $petaBarang[$k] = $it;
                }
            }
            $petaNama[$this->normNama($it->name)][] = $it;
        }

        $rencana = [];
        $takBertemu = 0;
        $sudahSama = 0;
        foreach ($baris as $b) {
            $kode = strtoupper($b['kode']);
            $kembarIni = isset($satuanPerKode[$kode]) && count($satuanPerKode[$kode]) > 1;
            $pilihKembar = (string) ($this->option('kembar-ke') ?? '');
            if ($kembarIni && ! $this->option('tutup-kembar')
                && ($pilihKembar === '' || strcasecmp($b['satuan'], $pilihKembar) !== 0)) {
                continue;
            }
            $item = $petaBarang[$kode] ?? null;
            if (! $item) {
                // cadangan: cocokkan lewat nama (hanya bila tunggal) — kode ECOUNT sering tidak tersimpan
                $kand = $petaNama[$this->normNama($b['nama'])] ?? [];
                if (count($kand) === 1) {
                    $item = $kand[0];
                }
            }
            if (! $item) {
                $takBertemu++;

                continue;
            }
            if ($item->unit_id && isset($ada[$b['satuan']]) && (int) $ada[$b['satuan']] === (int) $item->unit_id) {
                $sudahSama++;

                continue;
            }
            $rencana[] = ['item' => $item, 'satuan' => $b['satuan']];
        }

        $this->line('Tidak bertemu : '.$takBertemu);
        $this->line('Sudah sesuai  : '.$sudahSama);
        $this->line('Akan diisi    : '.count($rencana));

        if (! $this->option('apply')) {
            $this->newLine();
            $this->warn('DRY-RUN — tidak ada yang ditulis. Tambahkan --apply untuk menyimpan.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($satuanBaru, $rencana): void {
            foreach ($satuanBaru as $nama) {
                Unit::query()->create(['code' => $nama, 'name' => $nama, 'is_active' => true]);
            }

            $idSatuan = [];
            foreach (Unit::query()->get(['id', 'code', 'name']) as $u) {
                $idSatuan[strtolower((string) $u->code)] = $u->id;
                $idSatuan[strtolower((string) $u->name)] = $u->id;
            }

            foreach ($rencana as $r) {
                Item::query()->whereKey($r['item']->id)->update(['unit_id' => $idSatuan[strtolower($r['satuan'])]]);
            }
        });

        $this->newLine();
        $this->info('SELESAI — '.count($rencana).' barang diperbarui.');
        $this->info('Satuan baru dibuat: '.$satuanBaru->count());
        $this->line('Total satuan di master: '.Unit::query()->count());
        $this->line('Barang sudah punya satuan: '.Item::query()->whereNotNull('unit_id')->count().' dari '.Item::query()->count());

        return self::SUCCESS;
    }
}
