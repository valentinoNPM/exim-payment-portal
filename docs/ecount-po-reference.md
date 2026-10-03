# ECOUNT ERP — modul Pesanan Pembelian (referensi untuk modul PO exim)

Hasil penelusuran langsung **akun demo ECOUNT** (bukan dari dokumentasi pemasaran), 2 Okt 2026.
Tujuan: jadi acuan membangun modul Purchase Order di `exim-payment-portal`, karena HANSOLL
berencana berhenti berlangganan ECOUNT dan hanya meminta modul PO digantikan.

Cara masuk demo (untuk mengulang kapan saja):
- Halaman masuk demo: `https://www.ecount.com/id/ECK/ECK004M.aspx` — hanya perlu **email + bahasa**,
  tanpa password, tanpa verifikasi.
- Setelah email dikirim, muncul form data diri (Nama + Kontak) → isi apa saja → "Konfirmasi" →
  "Mulai Demo". ERP demo terbuka di tab baru: `https://loginia.ecount.com/ec56/view/erp`.
- Perusahaan demo = "ECOUNT INC" dengan **data contoh** (vendor "Supplier/vendor4", barang
  "Bahan mentah 5"). Jadi **nama menu & field otentik, isi datanya palsu**.
- Catatan: form data diri itu penangkapan calon pelanggan — teksnya menyebut sales akan menghubungi.
  Pada percobaan ini diisi nama/kontak placeholder agar tidak ada nomor asli yang dihubungi.

Tanggal akses: 2 Okt 2026. Salinan mentah tangkapan ada di workspace browser
(`ecount_catatan.txt`, `ecount_po_form_dump.txt`).

## 1. Peta menu pembelian (kode program ECOUNT)

Navigasi menu memakai hash: `#menuType=<kode>&menuSeq=<kode>&groupSeq=<kode>&prgId=<KODE_PROGRAM>`.

| Grup | Program | Kode |
|---|---|---|
| Permintaan Pembelian | Daftar / Baru / Status | C000075 → E040315 / E040314 / E040318 |
| Rencana Pembelian | Daftar / Baru / Status | C000076 → E040317 / E040316 / E041015 |
| **Pesanan Pembelian** | **Daftar / Baru / Status** | **C000077 → E040302 / E040301 / E040306** |
| Pembelian (penerimaan) | Daftar / Baru / Status | C000078 → E040304 / E040303 / E040305 |
| Pembelian (lain-lain) | Ubah Harga Beli Massal / Status Diskon / Status Pra-Faktur / A/P per Vendor / Faktur Kolektif / Faktur Pembelian (Stok) | E040311 / E040313 / E040319 / E040309 / E040312 / N000125, N000127 |

Urutan alaminya: **Permintaan Pembelian → Rencana Pembelian → Pesanan Pembelian → Pembelian
(penerimaan barang) → A/P → Faktur**. HANSOLL memakai tiga yang bertanda tebal.

## 2. Form "Pesanan Pembelian Baru" (E040301)

### Field header yang benar-benar ada
- Tanggal dokumen (input tanggal terpisah)
- **Pelanggan/Vendor**
- **PIC**
- **Lokasi-Masuk** (lokasi penerimaan)
- **Mata uang**
- **Cc.** (cost center)
- **Proyek**
- **Nomor Pendaftaran**
- **Nomor B.C**
- **Nomor Pembelian** (nomor pesanan dari pihak pemesan/pelanggan)
- **No. Pesanan Pembelian** (nomor PO sistem)
- **Judul**
- Lampiran berkas (input file)
- Checkbox: **Ubah Harga** (`priceChange`) dan **Sesuaikan** (`adjustment`)
- Sekitar **30 field tambahan yang bisa dinamai sendiri** oleh perusahaan: Tipe Teks Tambahan
  1–10, Tipe Angka Tambahan 1–5, Tipe Teks Panjang Tambahan 1–3, Tipe Tanggal Tambahan 1–3,
  Tipe Kode Tambahan 1–3 (kode + nama).
  Di perusahaan asli field ini biasanya sudah dinamai sesuai kebutuhan (mis. nomor SJ, departemen).
  **Ini alasan pentingnya ekspor/konfigurasi dari akun HANSOLL** — nama aslinya hanya ada di akun mereka.

### Kolom grid item (demo mengaktifkan 59 kolom; inti yang selalu ada)
| Kolom | Catatan |
|---|---|
| Kode Barang | kode item |
| Nama Barang | nama item |
| Spesifikasi | deskripsi/ukuran (di PO HANSOLL: "HD70… 250 X 0 X 0.035") |
| Kuantitas | jumlah dipesan |
| Satuan | satuan |
| Kuantitas Lokasi | kuantitas per lokasi |
| Total Kuantitas | total antar lokasi |
| Kuantitas Tambahan | kolom tambahan |
| Harga | harga satuan |
| Harga (Termasuk Pajak) | harga termasuk pajak |
| Jumlah Sebelum Pajak | subtotal baris |
| Pajak | pajak baris |
| Tanggal Pengiriman | tanggal kirim per baris |
| Keterangan | catatan baris |
| Rencana Pembelian | tautan ke rencana pembelian |
| No. / Jumlah1 / Jumlah 2 / Keterangan 1–3 / No.Seri-Lot | kolom opsional |
| Item Pengelolaan | penanda pengelolaan item |
| Tipe Teks/Angka/Tanggal/Kode Tambahan | field bebas yang bisa dinamai |

### Opsi pengiriman dokumen (dipakai tombol "Kirim")
`E-mail`, `Messenger`, `Pesan`, `Push APP`, `Lampirkan Tautan` — jadi PO bisa dikirim ke vendor
dari dalam sistem, dengan lampiran tautan.

## 3. Daftar Pesanan Pembelian (E040302)

Kolom: `Tanggal-No.`, `Nomor Pendaftaran`, `Nama Pelanggan/Vendor`, `Nama PIC`,
`Nama Barang [Nama Spesifikasi]`, `Tanggal Pengiriman`, `Total Jumlah Pesanan Pembelian`,
`Status Perkembangan`, `Slip yang Dibuat`, `Cetak`.

Contoh baris (data sampel): `20/10/2026 -1 | Supplier/vendor4 | Bahan mentah 5 [Green] dan 1 lebih |
20/10/2026 | 44.400.000 | e-Approval | Lihat | Cetak`.

Dua kolom yang paling penting untuk desain:
- **Status Perkembangan** — status alur PO (di contoh: `e-Approval`).
- **Slip yang Dibuat** — PO jadi sumber slip pembelian/penerimaan; ada tombol
  **"Buat Slip Lanjutan"**. Artinya PO tidak berdiri sendiri: dari PO dibuat slip, dan progres
  per-PO dilacak (inilah "PO Outstanding").

Filter pencarian: rentang tanggal, `Lokasi`, `Proyek`, `Pelanggan/Vendor`, `Barang`, mata uang
asing/domestik, dan beberapa opsi lain. Tombol cari berlabel `Cari (F8)` (di versi HANSOLL
terbaca `Cari (F3)`).

## 4. Yang BELUM terekam (perlu sesi lanjutan atau ekspor dari akun HANSOLL)

1. **Daftar nilai status** dan transisinya (mis. Baru → Proses → Selesai/Batal) beserta siapa yang
   berhak mengubah. Di demo, status hanya terlihat sebagai kolom, bukan daftar pilihannya.
2. **Aturan penomoran PO** (mis. `PO/HIJ/24092026-000006`) — `HIJ` sudah dikonfirmasi sebagai kode
   perusahaan Hansoll Indo Java; yang belum diketahui adalah aturan reset urutan dan apakah bisa diubah.
3. **Tata letak cetak/PDF PO** untuk vendor (dokumen yang dipegang vendor).
4. **Aturan pajak** yang berlaku untuk PO (PPN/PPh) dan apakah tercermin di baris atau header.
5. **e-Approval**: jumlah level, syarat, dan notifikasi.
6. Daftar **field tambahan yang sudah dinamai** di akun HANSOLL (hanya ada di akun mereka).
7. Master **barang** dan **vendor** HANSOLL, plus **riwayat PO** dan **PO outstanding**.

## 5. Implikasi untuk modul PO di exim

- Yang sudah ada dan bisa dipakai ulang: `suppliers` (code/name/address/is_active), `units`,
  `divisions`, `taxes`, `chart_of_accounts`, `roles`+`model_has_roles`, pola audit
  (`payment_slip_audits`), dan tata letak Filament v5.
- Yang benar-benar baru: **master barang**, `purchase_orders` + `purchase_order_items`,
  penomoran, alur status + approval, cetak/kirim PO, dan laporan outstanding.
- Rantai yang disarankan di exim: **PO → penerimaan → invoice → slip pembayaran → verifikasi**
  (invoice & slip sudah ada; jangan duplikasi mesin pajak/COA/verifikasi yang sudah berjalan).
- **Jangan menyentuh jalur ekspor ERP** yang sedang produksi (`erp_export_batches`,
  format `LedgerJournalTrans` untuk jurnal umum Microsoft Dynamics 365 F&O). Modul PO tidak boleh
  mengubah perilaku berkas ekspor itu.
- Urutan kerja yang disarankan: panen data ECOUNT (barang, vendor, riwayat PO, outstanding) →
  rancang skema dari data nyata → bangun modul → uji paralel terhadap ECOUNT selama masih hidup →
  baru hentikan langganan.

## 6. Perbandingan: akun demo vs akun HANSOLL (dari screenshot klien)

**Kesimpulan: programnya SAMA, versi dan kustomisasinya BEDA.** Kapabilitas yang terekam di dokumen
ini tetap sah, tapi label, kolom, dan tata letak **harus diambil dari akun HANSOLL**, bukan dari demo.

| Aspek | Akun HANSOLL (screenshot) | Demo (yang ditelusuri) |
|---|---|---|
| URL/instance | `login.ecount.com/ec5/...` (`v_flag=1`) | `loginia.ecount.com/ec56/...` (`w_flag=1`) — instance demo, host terpisah |
| Navigasi | **MyPage tab**: Penagihan / Permintaan Pembelian / **PO** / Penerimaan Barang / Laporan Stok / Pemakaian Barang / Penyesuaian Stok + sidebar: **PO Baru / Slip PO / Laporan PO / PO Outstanding** | Menu standar lengkap: Stok I → Pembelian → Pesanan Pembelian (Daftar/Baru/Status) |
| Judul halaman | `Daftar Pesanan Pembelian` | `Daftar Pesanan Pembelian` (sama) |
| Kolom daftar | NO. PO(tgl) / Vendor / **kode divisi (mis. GA)** / Barang / **Tanggal Selesai** / Total / **Status (Proses)** | Tanggal-No. / Nomor Pendaftaran / Vendor / PIC / Nama Barang [Spesifikasi] / **Tanggal Pengiriman** / Total / **Status Perkembangan** / Slip yang Dibuat / Cetak |
| Filter | Panel inline: Tanggal Dasar (manual 01/01/2024~01/11/2026), No PO, Domestik/Asing (Semua/Domestik/Asing), Lokasi, Pelanggan/Vendor, Barang, **Status Pengiriman (Semua/Belum Dikirim/…)** + tombol periode cepat (Hari Ini, Hari Sebelumnya, Hari Terakhir, Minggu ini/sebelumnya, Bulan ini/sebelumnya) + `Cari (F8)` | Filter setara (Lokasi, Proyek, Pelanggan/Vendor, Barang, domestik/asing) lewat dialog pencarian |
| Nomor PO | `PO/HIJ/24092026-000006` — pola `PO/<kode perusahaan>/DDMMYYYY-NNNNNN`; `HIJ` = Hansoll Indo Java | data sampel (nomor berbeda) |
| Tombol aksi | Baru (F2), Email, Ubah Status, Kirim, Cetak, Barcode (Barang), Buat Slip Lanjutan, e-Approval, Excel, Lihat Semua, Riwayat | tombol setara; baris contoh menampilkan `e-Approval`, `Lihat`, `Cetak` |

Catatan penting dari perbandingan ini:

- Nama menu HANSOLL ("PO Baru", "Slip PO", "Laporan PO", "PO Outstanding") adalah **penamaan/kustomisasi
  mereka sendiri** — nama asli ECOUNT-nya Pesanan Pembelian (Daftar E040302 / Baru E040301 / Status E040306).
  "PO Outstanding" = program **Status Pesanan Pembelian**.
- Kemungkinan **label yang diganti**: `Tanggal Selesai` (klien) vs `Tanggal Pengiriman` (demo) — besar
  kemungkinan field yang sama. `Status` (klien, isi "Proses"/"Dalam Proses") vs `Status Perkembangan` (demo).
- Kolom **kode divisi** muncul di layar klien (contoh: `GA`). Divisi ini selaras dengan tabel `divisions`
  di exim (acc, exim, GM, HRD, GA, IT, CSR, MEKANIK) — menguntungkan untuk pemetaan data.
- **Koreksi (2 Okt 2026):** perbedaan `ec5` vs `ec56` **bukan** bukti versi lama vs baru seperti dugaan
  awal. Yang terverifikasi: kedua prefix itu sah dan diterima server (prefix ngawur seperti `ec999999`
  ditolak/404), dan keduanya berakhir di **halaman login yang sama** dengan versi aset identik
  (`version="5"`, `ver=20250724`). Model ECOUNT sendiri adalah satu produk web yang di-upgrade di sisi
  server setiap hari. Jadi `ec5`/`ec56` lebih tepat dibaca sebagai **prefix instance/klaster** tempat
  sebuah akun dilayani — bukan generasi produk yang berbeda. Konsekuensi praktisnya tetap sama:
  karena HANSOLL dan demo berada di instance berbeda dan tampilan HANSOLL dikustomisasi,
  **jangan menganggap fitur yang terlihat di demo otomatis ada di akun mereka**.
- Karena itu, sebelum membangun: minta **screenshot form "PO Baru" dan hasil cetak PO dari akun HANSOLL**,
  plus daftar field tambahan yang sudah mereka namai. Tanpa itu modul baru akan benar secara fungsi
  tetapi terasa asing bagi pemakainya.

## 7. Siapa yang boleh mengubah kustomisasi itu?

**Jawaban singkat: kustomisasi diatur di dalam akun perusahaan sendiri, oleh pengguna yang punya hak
(admin), bukan oleh teknisi ECOUNT.** Terverifikasi langsung di demo lewat menu `Kustomisasi Pengguna`
(prgId **C000008**), yang isinya:

- **Informasi** [C000659] — profil perusahaan
- **Pengaturan Pengguna** [C000114] — daftar pengguna dan haknya
- **Konfigurasi** [C000660] — pengaturan umum, negara & mata uang, dan seterusnya
- **Lainnya** [C000115]
- **Keamanan** [C000900] — pengelolaan login, pembatasan akses, blokir akses sementara, negara akses
- **Unduh** [C000661] — unduhan/backup

Di area yang sama ada **MyPage** dengan keterangan *"Tampilan menu dapat disesuaikan dengan kebutuhan
user"*, plus contoh template per industri (Manufaktur, Trading/Distribusi, Konstruksi, Finance/Accounting).
Jadi tata menu dan tab di layar HANSOLL (Penagihan / Permintaan Pembelian / PO / Penerimaan Barang /
Laporan Stok / Pemakaian Barang / Penyesuaian Stok) adalah **hasil kustomisasi MyPage mereka**, bukan
susunan bawaan produk.

Artinya: ada memang "super user" — administrator akun di sisi HANSOLL — yang bisa mengubah menu, kolom,
label, dan nama field tambahan. ECOUNT membantu pada masa awal berlangganan (termasuk pelatihan dan
penyiapan), tetapi tombolnya ada di akun pelanggan. Perlu dipastikan siapa nama admin ECOUNT HANSOLL.

**Batas kejujuran:** akun demo **membatasi** kustomisasi (tidak bisa mengubah layar/template, tidak bisa
mengatur otorisasi menu per pengguna), jadi penamaan field tambahan **belum bisa kudemonstrasikan
langsung** — yang bisa kupastikan adalah tempat dan alurnya. Definisi field tambahan milik HANSOLL hanya
bisa dibaca dari akun mereka sendiri.

**Konsekuensi untuk modul PO di exim:**

- Kalau HANSOLL hanya memakai field standar → implementasikan field itu secara eksplisit. Lebih sederhana
  dan UX-nya lebih baik daripada meniru sistem "30 field generik".
- Kalau mereka benar-benar menamai field tambahan **dan** ingin bisa menambah sendiri → baru perlu fitur
  custom field + halaman admin untuknya. Itu menambah biaya nyata; jangan dibangun tanpa bukti pemakaian.
- Saat mereka berhenti berlangganan, yang wajib diminta ekspornya bukan cuma data, tapi juga **definisi**:
  daftar field tambahan beserta namanya, template layar/daftar, template cetak, dan susunan menu — karena
  ekspor data biasa hanya membawa isinya, bukan definisinya.

