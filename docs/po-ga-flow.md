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
5. **Jangan meniru ECOUNT secara mendalam.** Ruang lingkup dibatasi **hanya pada alur yang
   dideskripsikan staf GA**. Fitur ECOUNT di luar itu — walau ada dan berfungsi — **tidak dibangun**.
   Alasan: sebagian besar tidak berguna untuk kasus ini dan menambah beban perawatan tanpa manfaat.
6. Cakupan pengguna awal adalah **divisi GA**. GA membuat PO dan, untuk fase awal, GA juga membuat
   payment slip yang terkait. Perluasan ke divisi lain dibahas kemudian.
7. Mata uang PO fase awal mendukung **IDR dan USD**, mengikuti kemampuan Payment Slip General.
8. Segmen **`HIJ`** pada nomor PO adalah kode perusahaan **Hansoll Indo Java**. Contoh yang terlihat:
   `PO/HIJ/24092026-000006`. Kode disimpan sebagai konfigurasi perusahaan, bukan nilai tersebar di kode.
9. Dokumen ini adalah keputusan utama. Jika `docs/ecount-po-reference.md` masih menyebut approval atau
   master barang sebagai kebutuhan baru, keputusan di sini yang berlaku: **approval PO tidak dibangun**
   sedangkan **master barang sudah diterapkan** untuk mendukung pemetaan hasil impor ECOUNT;
   snapshot nama, kode, spesifikasi, dan satuan tetap disimpan pada baris PO agar cetakan lama stabil.

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

Temuan penting soal "slip": dari popup **`Slip yang Dibuat`** (dibuka dari sel di Daftar PO), terlihat
bahwa "slip" = **dokumen pembelian lanjutan yang dibuat DARI PO**, dan pelacakannya **berbasis kuantitas
per item**: setiap baris menampilkan `Kode Barang`, `Nama Barang`, `Spesifikasi`, `Kuantitas` (dipesan),
`Saldo` (sisa yang belum menjadi slip), `Slip yang Dibuat`, dan `Slip yang Dibuat Tanggal-No.`
Jadi "PO outstanding" di ECOUNT bersifat **per item**, bukan hanya per dokumen.
→ Implikasi: kalau klien menerima kiriman bertahap, tautan PO ↔ dokumen turun harus sampai **level item**
(kuantitas), bukan cukup `invoices.purchase_order_id` di level dokumen.

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
**kode barang** (teks, opsional) · nama barang + **spesifikasi** (teks) · kuantitas · **satuan** (`units`) · harga · **pajak** (`taxes`) ·
subtotal · **COA** (`chart_of_accounts`) · keterangan · (opsional) tanggal kirim per baris

Catatan: exim kini **sudah punya master barang**, sementara baris PO tetap menyimpan snapshot nama +
spesifikasi. Data teks lama tetap didukung; master barang juga menjadi dasar laporan per barang tanpa
mengubah tampilan dokumen historis.

### Status (informasi, bukan gerbang)
`baru → dikirim ke admin → ditandatangani → dikirim ke vendor → diterima sebagian → selesai` ·
cabang: `batal`. Perubahan status hanya **mencatat**; tidak ada langkah yang terblokir.

Sebagai acuan, **nilai status di ECOUNT** (terverifikasi dari dropdown filter "Status Perkembangan",
3 Okt 2026) adalah: `e-Approval`, `Belum Dikonfirmasi`, `Dikonfirmasi`, `Dalam Proses`, `Selesai`.
`e-Approval` tidak kita pakai (keputusan §0 nomor 1); sisanya bisa jadi acuan penamaan kalau klien
lebih nyaman dengan istilah yang sudah mereka kenal.

### Integrasi ke payment slip (titik sambung ke sistem yang sudah jalan)
- Tambah kolom nullable pada `invoices`: **`purchase_order_id`** — satu PO bisa menghasilkan lebih dari
  satu invoice (mis. pengiriman bertahap). Tidak ada perubahan perilaku pada slip yang sudah berjalan.
- Manfaat: **PO outstanding = PO yang belum punya invoice tertaut**, dan slip bisa menampilkan nomor PO
  sebagai konteks. Ini menggantikan fungsi kolom "Slip yang Dibuat" di ECOUNT.
- Alur slip (draft → submitted → checker → approval GM → exported) **tidak disentuh**.

### Peran & audit
Maker (staf GA) input + unggah scan + tandai terkirim · Checker (Accounting) seperti sekarang ·
GM seperti sekarang. Audit memakai pola `payment_slip_audits`.

### Bentuk cetak PO (acuan isi ECOUNT, gaya dokumen exim)

Dokumen yang dikirim ke vendor, isinya sesungguhnya ringkas:

| Bagian | Isi |
|---|---|
| Kepala | logo + judul "Purchase Order"; blok perusahaan (nama, alamat, telepon); `Date` (DD/MM/YYYY); `Purchase Order No.` |
| Kotak Vendor | **hanya nama vendor** |
| Kotak Ship To | nama penerima + perusahaan + alamat + telepon |
| Tabel item | **Kode Barang** \| Nama Barang [Spec.] \| Kuantitas \| Harga \| Jumlah Sebelum Pajak |
| Kaki | **SUBTOTAL / TAX / TOTAL** (pajak satu baris di tingkat dokumen, bukan per baris) |

Temuan yang mempengaruhi rancangan:

1. **Kode barang dipakai di cetakan.** Karena itu baris item perlu kolom `kode barang` (teks, opsional) —
   tetap tanpa master barang, tapi cetakan tidak berubah bentuk.
2. **Tidak ada blok tanda tangan di cetakan ECOUNT**, padahal alur GA butuh tiga tanda tangan
   (Manager, Direktur, Manager Umum). PDF exim memakai kepala perusahaan yang ringkas seperti PDF
   Payment Slip, mempertahankan tabel ringkas ECOUNT, lalu menambahkan area tanda tangan.
3. **Pajak satu baris di tingkat dokumen ("TAX").** PDF menyiapkan baris ini, tetapi aturan pajak
   HANSOLL tetap menunggu pemeriksaan data ECOUNT pada sesi remote.
4. **`Purchase Order No.` kosong di akun demo** (penomoran belum diaktifkan). Di akun HANSOLL nomornya
   muncul (`PO/HIJ/...`). Nomor di cetakan harus sama dengan nomor di daftar.
5. Bahasa cetakan campur (judul Inggris, tabel Indonesia). Jangan ditiru mentah — putuskan satu bahasa.

## 4. Migrasi data PO lama dari ECOUNT

### Sumber data
ECOUNT bisa mengekspor ke Excel (di layar Daftar PO HANSOLL ada tombol **Excel**). Yang perlu diminta:
1. **Daftar/riwayat PO** (header: nomor, tanggal, vendor, total, status)
2. **Detail item per PO** (kode/nama barang, spesifikasi, kuantitas, satuan, harga, pajak)
3. **Master vendor** (untuk mencocokkan `supplier_id` di exim)
4. Master barang (kalau nanti dibuat)

### Jalur pengambilan data — status verifikasi (3 Okt 2026)

- **Ekspor Excel dari Daftar PO: HANYA level header — tidak sampai item.** Terverifikasi (val mencoba
  langsung di demo: tombol `Excel` di toolbar footer, hasilnya tidak lengkap).
- **Cetak/PDF per PO: PASTI memuat baris item** (`Kode Barang`, `Nama Barang [Spec.]`, `Kuantitas`,
  `Harga`, `Jumlah Sebelum Pajak`). Aman, tapi satu PO satu berkas.
- **Laporan `Status Pesanan Pembelian` [E040306]** dan `Ringkasan Penjualan/Pembelian` [E040725]:
  ada, tapi **belum terverifikasi** apakah barisnya per item dan apakah bisa diekspor ke Excel —
  halaman laporan perlu diisi filter lalu dijalankan dulu. Ini jalur termurah kalau benar.
- **ECOUNT Open API**: PO hanya mendukung **Search** (baca) menurut riset awal — jadi secara teori bisa
  menarik PO beserta itemnya secara terprogram. Butuh kredensial dari admin HANSOLL; belum dipastikan
  instance mereka mengaktifkan Open API.

Pertanyaan yang menentukan beban kerja migrasi: **apakah manager butuh rincian item untuk SELURUH
riwayat, atau cukup header (nomor, tanggal, vendor, total, status) untuk arsip — dan rincian item hanya
untuk PO yang masih berjalan?** Kalau cukup header, hambatan ini hilang, karena ekspor daftar yang
sudah didapat sudah memenuhi.

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

## 6. Ruang lingkup — apa yang dibangun, apa yang TIDAK

Patokan: **hanya alur yang dideskripsikan staf GA.** Kalau sebuah fitur tidak muncul di alur itu,
jangan dibangun — walaupun ECOUNT punya.

### Masuk lingkup (versi awal)
1. **Input PO** — header ringkas (tanggal, vendor, divisi GA, PIC/penanggung jawab, keterangan) +
   baris item (kode barang opsional, nama + spesifikasi, kuantitas, satuan, harga, pajak)
2. **Nomor PO otomatis** mengikuti pola klien
3. **Daftar + detail PO**
4. **PDF PO** — kepala perusahaan mengikuti PDF Payment Slip yang ringkas, isi tabel mengikuti data
   cetakan ECOUNT, serta ada **area tanda tangan** (Manager / Direktur / Manager Umum)
5. **Status informasi** (mis. `baru` → `dikirim ke vendor` → `selesai`, plus `batal`) — tidak memblokir
6. **Tautan ke invoice** (`invoices.purchase_order_id`) supaya slip pembayaran bisa menampilkan konteks
   PO dan outstanding bisa dihitung
7. **Impor data PO lama** dari ekspor ECOUNT

### Di luar lingkup — jangan dibangun
- Permintaan Pembelian (PR), Rencana Pembelian, **RFQ** — semua tahap sebelum PO tetap di luar sistem
- **e-Approval / persetujuan berjenjang** (keputusan §0 nomor 1)
- Dokumen lanjutan ala ECOUNT (`Buat Slip Lain`: Pesanan Penjualan, Slip Penjualan) — di exim dokumen
  lanjutannya adalah **invoice yang sudah ada**; tidak perlu modul dokumen baru
- **Pelacakan `Saldo` per item** (kuantitas outstanding) — cukup di level dokumen, kecuali ternyata
  klien menerima kiriman bertahap (belum terjawab)
- **Master barang** — kode barang cukup teks di baris item
- Field ECOUNT yang tidak dipakai alur: Cost center (`Cc.`), Proyek, Nomor Pendaftaran, Nomor B.C,
  checkbox "Ubah Harga"/"Sesuaikan", opsi notifikasi (Messenger/Pesan/Push APP),
  Barcode, ekspor Excel daftar PO, kustomisasi kolom, template cetak berlapis
- Kirim email dari sistem **dan** unggah scan bertanda tangan — kuturunkan ke "nanti kalau perlu",
  karena alur klien menyebut kirim email/WA dan scan tetap manual. Cukup PDF yang bisa diunduh dulu.

### Urutan pengerjaan
1. **Fase 1** — skema + form PO + penomoran + daftar/detail + PDF (untuk GA)
2. **Fase 2** — tautan ke invoice + status informasi + laporan PO outstanding
3. **Fase 3** — impor data lama ECOUNT (setelah berkas ekspor tersedia)

Catatan urutan: **pengumpulan data lama adalah pekerjaan berbatas waktu** (hanya bisa selama ECOUNT
masih aktif), jadi permintaan ekspor ke HANSOLL sebaiknya berjalan **paralel dengan Fase 1**, bukan
menunggu Fase 3.

## 7. Catatan jujur tentang "mengamati ECOUNT yang sudah digunakan"

Yang bisa diamati dari luar: bentuk produknya (sudah dilakukan, ada di dokumen pendamping).
Yang **belum** bisa diamati: pemakaian nyata di akun HANSOLL — konfigurasi mereka, field yang sudah
dinamai, kebiasaan kerja. Itu hanya bisa dari sesi akun mereka, data ekspor, atau rekaman layar saat
staf GA bekerja.
