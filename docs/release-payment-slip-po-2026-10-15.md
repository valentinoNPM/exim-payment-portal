# Persiapan rilis PO → Payment Slip dan refactor Payment Slip

Status saat dokumen disiapkan: **preflight; hasil eksekusi rilis dicatat terpisah**.
Daftar berkas runtime yang eksplisit ada di
`deploy/payment-slip-po-2026-10-15.manifest` (88 berkas). Daftar ini tidak
mencakup test, fixture, dokumen, `.env`, data ekspor, atau `public/images/logo-notra.png`
yang tidak direferensikan aplikasi.

## Temuan preflight 15 Oktober 2026

- Lokal: branch `main`, HEAD `1d263ce`; working tree memuat seluruh batch yang
  belum di-commit. Suite PHP pada preflight: 260 tes/2.032 assertion
  lulus; jalankan ulang tepat sebelum rilis. Tes JS: 4 lulus; `pint --dirty --test`
  lulus. UAT pengguna: PO → General serta EXIM Import/Export → ERP lulus.
- VPS: `/var/www/exim-payment-portal`, HEAD `f56a743`, **94 entri dirty**.
  Jangan `git pull`, `git checkout`, `git reset`, atau menyapu berkas untracked.
- Perbandingan checksum atas 88 target: 53 belum ada, 33 berbeda, 2 identik.
  Dari berkas berbeda, 32 masih sama dengan baseline lokal `HEAD`. Satu
  pengecualian ialah `GeneratePaymentSlipPdf.php`: VPS telah memakai F4,
  sedangkan baseline Git lokal masih A4. Versi lokal yang akan dirilis tetap F4
  dan menambah pemilihan template General/EXIM.
- Migrasi `2026_10_14_000001_add_general_purchase_order_issuance_fields.php`
  belum ada di VPS. Migrasi gudang `2026_10_08_000001_create_warehouses_table.php`
  sudah **Ran**; jangan jalankan ulang secara manual. Migrasi baru menambah
  `purchase_orders.finalized_at/finalized_by` yang nullable dan
  `invoices.discount_amount/shipping_amount` dengan default 0.
- VPS PHP 8.3.6, tersedia `mysqldump`, ruang kosong sekitar 50 GB.
- Tidak ada perubahan dependency/`composer.lock` pada batch ini. Jangan
  mengganti `APP_KEY` atau `.env`.

## Gerbang rilis

1. Bekukan perubahan lokal; cocokkan ulang manifest terhadap daftar berkas
   runtime Git dan pastikan semua path ada. Commit kode, migrasi, aset, tes,
   dan dokumen yang relevan sebagai batch terpilih; jangan ikutkan backup,
   dump, output, fixture QA sementara, atau logo tak terpakai. Push repo
   hanya setelah commit dan tes hijau. Push repo **bukan** mekanisme deploy VPS.
2. Tepat sebelum deploy, cek ulang `git status`/checksum target di VPS. Jika
   ada perubahan server baru di luar preflight ini, berhenti dan bandingkan
   dahulu. Simpan backup database konsisten (helper
   `deploy/backup-production-database.php`, yang membaca konfigurasi Laravel
   tanpa mencetak password) dan snapshot seluruh berkas
   target yang sudah ada, di luar webroot; verifikasi file backup dapat dibaca.
   Pertahankan file/metadata untuk rollback. Jangan menimpa `.env`, upload,
   `storage`, atau data master.
3. Saat jendela rilis, aktifkan maintenance singkat. Salin hanya berkas dalam
   manifest melalui staging + SCP lalu pasang ke path tujuan. Dua berkas lama
   yang hilang dari repo (`PaymentSlipForm.php` dan
   `resources/views/pdf/payment-slip.blade.php`) **jangan dihapus di VPS**
   dalam rilis ini; kode baru tidak merujuknya. Jangan salin direktori proyek
   secara rekursif ke atas checkout server.
4. Jalankan `php artisan migrate:status` setelah berkas masuk. Hanya migrasi
   14 Oktober di atas yang diharapkan pending. Jika ada migrasi lain pending,
   berhenti untuk audit. Bila sesuai, jalankan `php artisan migrate --force`
   **sekali**. Jika menjalankan `php artisan optimize:clear`, segera jalankan
   `php artisan config:cache` sebelum membuka maintenance. Pada VPS ini `.env`
   tidak dapat dibaca langsung oleh user PHP-FPM (`www-data`); aplikasi web
   bergantung pada `bootstrap/cache/config.php` yang dapat dibaca `www-data`.
   Pastikan fingerprint `APP_KEY` efektif sebelum/sesudah sama, tanpa mencetak
   nilainya. Jangan membuka maintenance jika config cache belum tersedia.
   Tidak ada seeding atau `key:generate`.
5. Sebelum keluar maintenance, periksa `route:list`, log error baru, dan
   halaman/PDF dengan record existing tanpa mengubah data. Sesudah dibuka,
   smoke test Maker General/EXIM, Checker, PO, PDF, dan ERP EXIM bersama user.
   Ekspor ERP General bukan syarat rilis; kombinasi yang belum didukung tetap
   harus ditolak aman di boundary ekspor.
6. Jika gagal, hentikan transaksi baru. Pulihkan snapshot kode/aset terlebih
   dahulu. Pemulihan database dari backup hanya diputuskan setelah melihat
   apakah ada transaksi bisnis baru pascarilis; jangan menjalankan rollback
   migrasi atau restore database otomatis pada data produksi.

## Hal yang masih perlu diverifikasi saat eksekusi

- Backup benar-benar selesai dan ukuran/aksesnya masuk akal.
- Checksum manifest lokal cocok dengan paket yang diterima di staging VPS.
- Migrasi baru berhasil, relasi dan jumlah data lama tidak berubah tidak terduga.
- PDF General tidak mengulang nomor invoice pada tabel item; header tetap ada.
- Asset `public/js/payment-slip-editor.js`, `public/js/checker-workspace.js`,
  `public/js/purchase-order-editor.js`, dan CSS panel ikut naik.
- Tidak ada HTTP 500, 419, atau error dekripsi; `APP_KEY` tidak berubah.

R5 (pemetaan diskon/ongkir/valas untuk ERP General) tetap ditunda dan bukan
bagian paket rilis ini.

## Catatan eksekusi 15 Oktober 2026

- Kode `df4efb7` telah di-push ke `origin/main` dan 88 berkas runtime dalam
  manifest dipasang ke VPS dengan SCP/staging tanpa `git pull`.
- Backup sebelum deploy: `/home/valentino24/release-backups/exim-payment-portal-20261015-201514/`
  berisi dump database dan arsip 37 berkas target yang sebelumnya ada.
- Migrasi 14 Oktober berhasil; migrasi gudang tidak diulang. Suite lokal:
  260 tes PHP/2.032 assertion dan 4 tes JS lulus. Render PDF produksi
  General, Export, Import, dan PO dengan record lama juga berhasil.
- `optimize:clear` sempat menghapus config cache dan menyebabkan HTTP 500
  sementara karena PHP-FPM tidak dapat membaca `.env`. Aplikasi dikembalikan
  ke maintenance, config cache dibangun ulang, fingerprint `APP_KEY` efektif
  diverifikasi tidak berubah, lalu dibuka kembali. Sesudah pemulihan,
  `/admin/login` merespons 200 dan rute admin terproteksi mengarah ke login.
