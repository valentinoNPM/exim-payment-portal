# Modul Purchase Order divisi GA — alur, integrasi slip, dan migrasi dari ECOUNT

**Objektif:** memindahkan proses Purchase Order divisi GA dari ECOUNT ke `exim-payment-portal`,
sedekat mungkin dengan alur dan data yang dipakai sekarang, tersambung dengan proses payment slip yang
sudah berjalan, **termasuk data PO lama dari ECOUNT**.

Dokumen pendamping: `docs/ecount-po-reference.md` (hasil penelusuran modul PO ECOUNT di akun demo).

## 0. Keputusan yang sudah ditetapkan (dari val, 3 Okt 2026)

1. **Approval TIDAK dibangun di dalam sistem.** Ikuti alur klien: cetak → tanda tangan basah
   (Manager, Direktur, Manager Umum) → scan → kirim. Alasan: val sudah pernah membuat approval
   by system (pengalaman ~10 tahun pada sistem sejenis) dan **tidak dipakai** — pengguna tingkat
   manajerial menolak. Jangan mengulang kegagalan yang sama.
   → Konsekuensi desain: modul **tidak punya gerbang persetujuan yang memblokir apa pun**. Yang ada hanya
   **status informasi** + tempat menyimpan hasil scan bertanda tangan sebagai bukti.
   Catatan terpisah: alur slip pembayaran yang sudah ada tetap memakai approval GM seperti sekarang —
   ini dua dokumen dengan aktor berbeda, jadi tidak perlu diseragamkan.
2. Fokus kerja: **migrasi PO dari ECOUNT ke exim**, alur dan data semirip mungkin.
3. Harus **tersambung dengan proses payment slip yang sudah ada** (jangan membuat alur pembayaran kedua).
4. **Data PO lama dari ECOUNT harus masuk** ke sistem baru (permintaan manager HANSOLL).
5. Cakupan pengguna awal adalah **divisi GA**. GA membuat PO dan, untuk fase awal, GA juga membuat
   payment slip yang terkait. Perluasan ke divisi lain dibahas kemudian.
6. Mata uang PO fase awal mendukung **IDR dan USD**, mengikuti kemampuan Payment Slip General.
7. Segmen **`HIJ`** pada nomor PO adalah kode perusahaan **Hansoll Indo Java**. Contoh yang terlihat:
   `PO/HIJ/24092026-000006`. Kode disimpan sebagai konfigurasi perusahaan, bukan nilai tersebar di kode.
8. Dokumen ini adalah keputusan utama. Jika `docs/ecount-po-reference.md` masih menyebut approval atau
   master barang sebagai kebutuhan baru, keputusan di sini yang berlaku: **approval PO tidak dibangun**
   dan **master barang ditunda**; item versi pertama tetap berupa teks nama + spesifikasi.

## 1. Alur as-is (dari staf GA) — dan siapa yang mengerjakan setelah pindah ke exim

| # | Langkah | Sekarang | Setelah di exim |
|---|---|---|---|
| 1 | Pengajuan dari bawah (PR) | di luar sistem (lisan/kertas/WA) | **tetap di luar sistem** (keputusan: fokus PO) |
| 2 | Input pengajuan → keluar PO | ECOUNT | **exim** — form PO, nomor otomatis |
| 3 | Download PDF PO | ECOUNT (Cetak) | **exim** — PDF dari sistem (dompdf sudah dipakai) |
| 4 | Kirim PDF ke admin | manual (email/WA) | **exim** — tombol kirim email + log; WA tetap manual |
| 5 | Admin mencetak | manual | **tetap manual** (kertas) |
| 6 | Tanda tangan Manager + Direktur | kertas | **tetap kertas** (keputusan val) |
| 7 | Kembali ke staf GA | kertas | tetap kertas |
| 8 | Tanda tangan Manager Umum | kertas | **tetap kertas** |
| 9 | Scan dokumen bertanda tangan | manual | **unggah ke exim** — tersimpan pada PO sebagai bukti |
| 10 | Kirim ke supplier | manual (email) | **exim** — kirim + log, atau tetap manual lalu ditandai terkirim |
| 11 | Terima barang + invoice | campuran | invoice tetap dibuat di exim seperti sekarang, **ditautkan ke PO** |
| 12 | Slip payment keluar | exim (sudah ada) | **tidak diubah** — hanya mendapat konteks PO |

Yang **hilang** dari rantai manual: kirim manual ke admin, arsip scan yang terpisah dari datanya, dan
kirim ke vendor tanpa log. Yang **tetap** manual: kertas dan tanda tangan basah — ini keputusan sadar,
bukan kelalaian.

## 2. Fasilitas ECOUNT untuk alur itu (terverifikasi di demo)

| Kebutuhan alur | Fasilitas ECOUNT | Kode program |
|---|---|---|
| Catat pengajuan (PR) | Permintaan Pembelian: Baru / Daftar / Status | E040314 / E040315 / E040318 |
| Buat PO | Pesanan Pembelian Baru | E040301 |
| Daftar & lacak PO | Daftar Pesanan Pembelian (kolom Status Perkembangan + Slip yang Dibuat) | E040302 |
| Laporan outstanding | Status Pesanan Pembelian | E040306 |
| Keluarkan PDF | tombol Cetak | — |
| Kirim ke pihak lain | Kirim: E-mail, Messenger, Pesan, Push APP, Lampirkan Tautan | — |
| Persetujuan berjenjang | e-Approval (GW → e-Approval) — **tidak dipakai HANSOLL** | C000039 |

Temuan penting: ECOUNT **punya** modul persetujuan berjenjang, tetapi HANSOLL **tidak memakainya**.
Jadi mengganti sistem saja tidak menutup celah itu — **celahnya proses, bukan fitur.** Ini juga alasan
tambahan untuk tidak membangun approval baru di exim.

## 3. Rancangan data di exim

Prinsip: pakai ulang master yang sudah ada; jangan menduplikasi mesin pajak/COA/verifikasi/slip.

### Tabel baru
- `purchase_orders` — header
- `purchase_order_items` — rincian

### Header PO (memakai ulang master yang ada)
`po_number` (pola mengikuti ECOUNT, lihat §5) · `po_date` · `division_id` (GA) · `supplier_id`
(`suppliers`) · PIC · alamat/lokasi kirim · mata uang (`IDR`/`USD`) · judul/keterangan · tanggal butuh ·
**status informasi** · `created_by` · waktu & cara kirim ke vendor · `signed_scan_path` (scan bertanda
tangan) · `notes`

### Item PO
nama barang + **spesifikasi** (teks) · kuantitas · **satuan** (`units`) · harga · **pajak** (`taxes`) ·
subtotal · **COA** (`chart_of_accounts`) · keterangan · (opsional) tanggal kirim per baris

Catatan: exim **belum punya master barang**, dan PO HANSOLL sendiri menampilkan nama + spesifikasi.
Untuk versi pertama cukup item berbentuk teks; master barang menyusul kalau laporan per barang
memang dibutuhkan. Ini memangkas satu pekerjaan besar.

### Status (informasi, bukan gerbang)
`baru → dikirim ke admin → ditandatangani → dikirim ke vendor → diterima sebagian → selesai` ·
cabang: `batal`. Perubahan status hanya **mencatat**; tidak ada langkah yang terblokir.

### Integrasi ke payment slip (titik sambung ke sistem yang sudah jalan)
- Tambah kolom nullable pada `invoices`: **`purchase_order_id`** — satu PO bisa menghasilkan lebih dari
  satu invoice (mis. pengiriman bertahap). Tidak ada perubahan perilaku pada slip yang sudah berjalan.
- Manfaat: **PO outstanding = PO yang belum punya invoice tertaut**, dan slip bisa menampilkan nomor PO
  sebagai konteks. Ini menggantikan fungsi kolom "Slip yang Dibuat" di ECOUNT.
- Alur slip (draft → submitted → checker → approval GM → exported) **tidak disentuh**.

### Peran & audit
Maker (staf GA) input + unggah scan + tandai terkirim · Checker (Accounting) seperti sekarang ·
GM seperti sekarang. Audit memakai pola `payment_slip_audits`.

## 4. Migrasi data PO lama dari ECOUNT

### Sumber data
ECOUNT bisa mengekspor ke Excel (di layar Daftar PO HANSOLL ada tombol **Excel**). Yang perlu diminta:
1. **Daftar/riwayat PO** (header: nomor, tanggal, vendor, total, status)
2. **Detail item per PO** (kode/nama barang, spesifikasi, kuantitas, satuan, harga, pajak)
3. **Master vendor** (untuk mencocokkan `supplier_id` di exim)
4. Master barang (kalau nanti dibuat)

### Peringatan penting — sampaikan ke manager sebelum menjanjikan apa pun
- **Ekspor Excel ECOUNT TIDAK membawa lampiran berkas.** PO lama beserta dokumen yang sudah
  ditandatangani **tidak otomatis ikut**. Kalau manager menginginkan lampirannya, PO lama harus diunduh
  satu per satu dari ECOUNT **sebelum langganan berhenti** — pekerjaan berbatas waktu, harus dikerjakan
  lebih dulu, bukan setelah sistem dimatikan.
- **Detail item mungkin tidak ada di ekspor daftar.** Daftar PO ECOUNT menampilkan ringkasan
  (`Nama Barang [Spesifikasi]`, total). Untuk rincian lengkap per baris mungkin perlu laporan/detail per
  PO. **Belum diverifikasi** — sebaiknya diuji di demo sebelum meminta berkas ke klien.
- **Pencocokan data lama ke invoice/slip yang sudah ada di exim tidak otomatis.** Perlu diputuskan:
  dihubungkan lewat nomor PO, atau PO lama dibiarkan berdiri sendiri sebagai arsip.

### Yang perlu diputuskan
- Rentang waktu data lama yang diminta manager (sejak kapan?)
- Butuh rincian item, atau cukup header (nomor, tanggal, vendor, total)?
- Perlu dihubungkan ke invoice/slip lama, atau arsip saja?
- Perlu berkas scan lama ikut? (kalau ya → unduh manual sebelum ECOUNT berhenti)

### Bentuk teknis
Impor lewat artisan command sekali jalan (bukan UI): baca Excel → validasi → masukkan dengan penanda
`import_source = 'ecount'`, dan **nomor PO lama dipertahankan apa adanya** supaya pencarian nomor lama
tetap cocok. Simpan berkas aslinya sebagai lampiran batch untuk jejak audit.

## 5. Yang belum pasti / perlu diverifikasi

1. **Aturan urutan nomor PO.** Format yang terlihat adalah `PO/HIJ/DDMMYYYY-NNNNNN`, dan `HIJ` sudah
   dikonfirmasi sebagai kode perusahaan Hansoll Indo Java. Yang belum diketahui: kapan urutan enam digit
   di-reset (harian/bulanan/tahunan/tidak pernah) dan apakah nomor dapat diubah manual.
2. **Tanggal pengiriman per baris item** — di ECOUNT ada; perlu diketahui apakah GA memakainya.
3. **Field tambahan HANSOLL** — nama aslinya hanya ada di akun mereka.
4. Apakah **divisi lain** akan memakai modul ini nanti (mempengaruhi desain izin per divisi).
5. Apakah **penerimaan barang** perlu dicatat (termasuk barang datang sebagian), atau cukup PO + invoice.

### Jadwal pengambilan data HANSOLL

Val dijadwalkan mendapat akses remote ke akun ECOUNT HANSOLL pada **Senin, 5 Oktober 2026** untuk
mengekspor data dan memeriksa konfigurasi nyata sebagai bahan migrasi/transisi ke sistem gabungan exim.
Sampai sesi tersebut selesai, pola data, field tambahan, aturan pajak PO, penerimaan parsial, dan bentuk
lampiran lama tetap **BELUM DIPUTUSKAN**.

## 6. Urutan kerja

1. **Fase 1** — skema + form PO + penomoran + PDF untuk GA (tanpa approval, tanpa blokir)
2. **Fase 2** — unggah scan bertanda tangan + kirim email + log + status informasi
3. **Fase 3** — tautan `invoices.purchase_order_id` + laporan PO outstanding
4. **Fase 4** — impor data lama ECOUNT (setelah berkas ekspor tersedia)
5. **Fase 5** — PR masuk sistem (hanya kalau nanti diminta)

Catatan urutan: **pengumpulan data lama adalah pekerjaan berbatas waktu** (hanya bisa selama ECOUNT
masih aktif), jadi permintaan ekspor ke HANSOLL sebaiknya berjalan **paralel dengan Fase 1**, bukan
menunggu Fase 4.

## 7. Catatan jujur tentang "mengamati ECOUNT yang sudah digunakan"

Yang bisa diamati dari luar: bentuk produknya (sudah dilakukan, ada di dokumen pendamping).
Yang **belum** bisa diamati: pemakaian nyata di akun HANSOLL — konfigurasi mereka, field yang sudah
dinamai, kebiasaan kerja. Itu hanya bisa dari sesi akun mereka, data ekspor, atau rekaman layar saat
staf GA bekerja.
