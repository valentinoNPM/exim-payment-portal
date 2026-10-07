<?php

namespace App\Console\Commands;

use App\Models\Supplier;
use Illuminate\Console\Command;

/**
 * Mengisi detail supplier (email, telepon, fax, alamat 2, kode ECOUNT) dari hasil tarikan
 * layar "Daftarkan Pelanggan/Vendor" ECOUNT.
 *
 * Pencocokan memakai NAMA yang dinormalkan (huruf/angka saja, huruf besar), karena kode
 * supplier di exim berasal dari modul pembayaran dan tidak sama dengan kode ECOUNT.
 *
 * Tanpa --apply hanya menghitung (dry-run). Tanpa --buat-baru, 33 vendor ECOUNT yang belum
 * ada di exim dilewati. Tanpa --timpa-alamat, alamat exim yang sudah terisi tidak diubah.
 */
class ImportSupplierDetails extends Command
{
    protected $signature = 'supplier:import-detail
        {--berkas= : lokasi CSV (bawaan: berkas hasil tarikan)}
        {--apply : benar-benar menulis ke basis data}
        {--buat-baru : buat supplier baru bagi vendor ECOUNT yang belum ada}
        {--timpa-alamat : timpa alamat exim yang sudah ada dengan alamat ECOUNT}';

    protected $description = 'Isi detail supplier (email, telepon, fax, alamat 2, kode ECOUNT) dari hasil tarikan ECOUNT';

    public function handle(): int
    {
        $berkas = $this->option('berkas')
            ?: 'C:/Users/valentino/Documents/work/hansoll-ecount/supplier-detail/supplier-detail-gabungan.csv';

        if (! is_file($berkas)) {
            $this->error("Berkas tidak ditemukan: {$berkas}");

            return self::FAILURE;
        }

        $baris = $this->bacaCsv($berkas);
        $this->info('Baris di berkas: '.count($baris));

        // peta nama -> supplier exim
        $peta = [];
        $kembar = [];
        foreach (Supplier::orderBy('id')->get() as $s) {
            $k = $this->norm($s->name);
            if (isset($peta[$k])) {
                $kembar[$k] = true;

                continue;
            }
            $peta[$k] = $s;
        }

        $siap = [];
        $dilewatiKembar = [];
        $belumAda = [];

        foreach ($baris as $r) {
            $k = $this->norm($r['nama'] ?? '');
            if ($k === '') {
                continue;
            }
            if (isset($kembar[$k])) {
                $dilewatiKembar[] = "{$r['kode']} {$r['nama']}";

                continue;
            }
            if (! isset($peta[$k])) {
                $belumAda[] = $r;

                continue;
            }
            $siap[] = [$peta[$k], $r];
        }

        $this->line('cocok            : '.count($siap));
        $this->line('nama kembar      : '.count($dilewatiKembar).' (dilewati, perlu keputusan manual)');
        $this->line('belum ada di exim: '.count($belumAda));

        $rencana = ['address' => 0, 'address_2' => 0, 'email' => 0, 'phone' => 0, 'fax' => 0, 'source_code' => 0];
        $bentrok = [];

        foreach ($siap as [$s, $r]) {
            $a1 = trim($r['alamat_1'] ?? '');
            if ($a1 !== '') {
                if (! trim((string) $s->address)) {
                    $rencana['address']++;
                } elseif (! $this->option('timpa-alamat') && mb_strtoupper(trim($s->address)) !== mb_strtoupper($a1)) {
                    $bentrok[] = "{$r['kode']} {$r['nama']}";
                }
            }
            foreach (['address_2' => 'alamat_2', 'email' => 'email', 'fax' => 'fax'] as $kolom => $sumber) {
                if (trim($r[$sumber] ?? '') !== '') {
                    $rencana[$kolom]++;
                }
            }
            $teleponAda = trim($r['mobile'] ?? '') !== '' || trim($r['telepon'] ?? '') !== '';
            if ($teleponAda) {
                $rencana['phone']++;
            }
            if (trim($r['kode'] ?? '') !== '') {
                $rencana['source_code']++;
            }
        }

        foreach ($rencana as $kolom => $n) {
            $this->line("  akan diisi {$kolom}: {$n}");
        }
        $this->line('alamat bentrok (tidak ditimpa): '.count($bentrok));
        if ($bentrok && $this->option('timpa-alamat') === false && $this->output->isVerbose()) {
            foreach ($bentrok as $b) {
                $this->line('   '.$b);
            }
        }

        if (! $this->option('apply')) {
            $this->warn('DRY-RUN — tidak ada yang ditulis. Tambahkan --apply untuk menyimpan.');

            return self::SUCCESS;
        }

        $diubah = 0;
        \DB::transaction(function () use ($siap, &$diubah) {
            foreach ($siap as [$s, $r]) {
                $isi = [];
                $a1 = trim($r['alamat_1'] ?? '');
                if ($a1 !== '' && (trim((string) $s->address) === '' || $this->option('timpa-alamat'))) {
                    $isi['address'] = $a1;
                }
                foreach (['address_2' => 'alamat_2', 'email' => 'email', 'fax' => 'fax'] as $kolom => $sumber) {
                    $v = trim($r[$sumber] ?? '');
                    if ($v !== '') {
                        $isi[$kolom] = $v;
                    }
                }
                $telepon = trim($r['mobile'] ?? '') ?: trim($r['telepon'] ?? '');
                if ($telepon !== '') {
                    $isi['phone'] = $telepon;
                    $digit = preg_replace('/\D+/', '', $telepon);
                    if (strlen($digit) >= 8) {
                        $isi['phone_digit'] = $digit;
                    }
                }
                if (trim($r['kode'] ?? '') !== '') {
                    $isi['source_code'] = trim($r['kode']);
                }

                if ($isi) {
                    $s->fill($isi)->save();
                    $diubah++;
                }
            }
        });

        $this->info("SELESAI — {$diubah} supplier diperbarui.");

        if ($this->option('buat-baru') && $belumAda) {
            $dibuat = 0;
            \DB::transaction(function () use ($belumAda, &$dibuat) {
                foreach ($belumAda as $r) {
                    Supplier::create([
                        'code' => 'EC-'.$r['kode'],
                        'source_code' => $r['kode'],
                        'name' => mb_substr(trim($r['nama']), 0, 255),
                        'address' => trim($r['alamat_1'] ?? '') ?: null,
                        'address_2' => trim($r['alamat_2'] ?? '') ?: null,
                        'email' => trim($r['email'] ?? '') ?: null,
                        'phone' => (trim($r['mobile'] ?? '') ?: trim($r['telepon'] ?? '')) ?: null,
                        'fax' => trim($r['fax'] ?? '') ?: null,
                        'is_active' => true,
                    ]);
                    $dibuat++;
                }
            });
            $this->info("Supplier baru dibuat: {$dibuat}");
        }

        return self::SUCCESS;
    }

    private function bacaCsv(string $berkas): array
    {
        $isi = [];
        if (($fh = fopen($berkas, 'r')) === false) {
            return $isi;
        }
        $tajuk = null;
        while (($r = fgetcsv($fh)) !== false) {
            if ($tajuk === null) {
                $tajuk = array_map(fn ($h) => trim((string) $h, "\u{FEFF} \t\n\r"), $r);

                continue;
            }
            $isi[] = array_combine($tajuk, array_pad($r, count($tajuk), ''));
        }
        fclose($fh);

        return $isi;
    }

    private function norm(?string $s): string
    {
        return preg_replace('/[^A-Z0-9]/', '', mb_strtoupper((string) $s)) ?? '';
    }
}
