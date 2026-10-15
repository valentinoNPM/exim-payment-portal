# Rencana Refactor Payment Slip

> **Review 15 Oktober 2026:** dokumen ini menyimpan kontrak/baseline refactor dan
> catatan historis, bukan status selesai terbaru. Revisi aktif Checker General,
> batas aman ekspor General, keputusan diskon, dan permintaan **Supplier Pendukung
> pada EXIM Export** ada di `po-to-general-payment-slip-implementation-plan.md`,
> bagian "Revisi aktif — 15 Oktober 2026". Perubahan fitur tersebut dikerjakan
> sebagai paket terpisah dari refactor; larangan redesign/ubah requirement di bawah
> tidak membatalkan revisi eksplisit user. ERP EXIM tetap wajib identik; jangan
> mengartikan form supplier pendukung sebagai izin mengubah supplier jurnal.

## 1. Tujuan

Memisahkan implementasi Payment Slip General dan Payment Slip EXIM pada lapisan aplikasi, form, halaman, validasi, kalkulasi, dan PDF tanpa memisahkan atau memigrasikan struktur database yang sudah ada.

Refactor ini ditujukan untuk:

- mengurangi percabangan tipe transaksi di satu schema Filament yang besar;
- mencegah perubahan General menimbulkan regresi pada EXIM, dan sebaliknya;
- mengurangi komponen Livewire yang dibangun untuk field yang tidak dipakai;
- membuat aturan bisnis dapat diuji tanpa bergantung pada Filament;
- mempertahankan seluruh data, URL penting, workflow, audit, dan hasil ERP yang sudah berjalan.

Refactor ini juga menyiapkan **kontrak input opsional di sisi Payment Slip General** untuk menerima referensi PO melalui `invoices.purchase_order_id` yang sudah ada. Kontrak tersebut tidak mengubah halaman atau alur pengguna. Adapter PO, prefill, selector, dan aktivasi fitur dibahas di `docs/payment-slip-po-integration-plan.md`.

## 2. Keputusan yang dikunci

### Tetap bersama

- Tabel `payment_slips`, `invoices`, dan `invoice_items` beserta seluruh relasinya.
- Model Eloquent dan ID record yang sudah ada.
- Penomoran slip.
- Status dan workflow Maker, Checker, Verified, dan Exported.
- Audit trail, hak akses record, dan hubungan ERP Export.
- Relasi `invoices.purchase_order_id` yang sudah tersedia. Struktur dan data relasi ini tetap; kontrak General menerima referensi tersebut secara opsional tanpa mewajibkannya bagi slip lama atau slip manual.
- Halaman daftar Payment Slip dan widget ringkasan, selama filter tipe tetap tersedia.
- Kontrak jumlah pada database: subtotal, penambahan pajak, pengurangan pajak, dan grand total.

### Dipisahkan

- Schema form General dan EXIM.
- Halaman create serta edit General dan EXIM.
- Implementasi detail/read-only General dan EXIM, dengan tampilan pengguna yang sama seperti sekarang.
- Validator dan normalizer input berdasarkan tipe slip.
- Kalkulasi atau orkestrasi khusus tipe yang saat ini berada di schema form.
- Template PDF General dan EXIM.
- Tes perilaku General dan EXIM, dengan fixture bersama hanya untuk data yang benar-benar sama.

### Batasan

- Tidak membuat tabel `general_payment_slips` atau `exim_payment_slips`.
- Tidak mengubah arti `transaction_type`.
- Tidak mengubah data lama agar menyesuaikan struktur class baru.
- Tidak mengubah format ERP, aturan COA, pembulatan, ataupun nominal tersimpan selama refactor.
- Tidak mengubah form, PDF, status, workflow, authorization, atau proses bisnis modul Purchase Order yang sudah divalidasi klien.
- Tidak menambah tracking outstanding kuantitas parsial sebagai bagian refactor Payment Slip.
- Tidak menaikkan versi Laravel, Filament, Livewire, atau dependency lain.
- Tidak melakukan redesign UI atau perubahan requirement bisnis dalam commit refactor.
- Tidak mengubah label, urutan field, tata letak, tombol, warna, posisi panel PDF, validasi yang terlihat pengguna, maupun langkah create/edit/check/verify/export.

## 3. Batas integrasi PO ke General Payment Slip

Modul Purchase Order beserta prosesnya sudah divalidasi klien dan dianggap sebagai sistem sumber yang stabil. Refactor ini **tidak menilai ulang atau mengubah proses PO**, dan tidak mengubah satu berkas pun di modul PO.

Relasi `invoices.purchase_order_id` yang sudah tersedia menjadi titik sambung antara PO dan invoice slip: satu PO dapat terkait ke beberapa invoice, dan invoice dalam satu General Payment Slip dapat menunjuk PO. Struktur dan isi relasi ini dibiarkan apa adanya selama refactor.

Pada refactor ini, jalur penyimpanan General harus menerima **referensi PO opsional pada invoice** melalui kontrak input yang dapat diuji tanpa UI PO. Tanpa referensi itu, hasilnya identik dengan alur manual sekarang. DTO konteks PO, adapter read-only, validator pemilihan PO, prefill, selector, dan feature flag implementasi integrasi berada di rencana terpisah `docs/payment-slip-po-integration-plan.md`; semuanya tetap tidak terlihat sampai fase aktivasi disetujui.

## 4. Target arsitektur

Struktur final yang disarankan:

```text
app/Filament/Resources/PaymentSlips/
├── PaymentSlipResource.php
├── Pages/
│   ├── CreateGeneralPaymentSlip.php
│   ├── CreateEximPaymentSlip.php
│   ├── EditGeneralPaymentSlip.php
│   ├── EditEximPaymentSlip.php
│   ├── ViewGeneralPaymentSlip.php
│   └── ViewEximPaymentSlip.php
├── Schemas/
│   ├── GeneralPaymentSlipForm.php
│   ├── EximPaymentSlipForm.php
│   ├── GeneralPaymentSlipView.php
│   ├── EximPaymentSlipView.php
│   └── Concerns/ atau Components/
│       └── komponen kecil yang benar-benar bersama
└── Tables/
    └── PaymentSlipsTable.php

app/Domain/PaymentSlips/ atau app/Services/PaymentSlips/
├── GeneralPaymentSlipData.php
├── EximPaymentSlipData.php
├── GeneralPaymentSlipCalculator.php
├── EximPaymentSlipCalculator.php
├── GeneralPaymentSlipValidator.php
└── EximPaymentSlipValidator.php

resources/views/pdf/payment-slips/
├── general.blade.php
├── exim.blade.php
└── partials/
    ├── header.blade.php
    └── footer.blade.php
```

Nama class dapat disesuaikan saat implementasi, tetapi batas tanggung jawabnya harus dipertahankan. Komponen bersama tidak boleh kembali menjadi schema monolitik yang dipenuhi kondisi `transaction_type`.

Import dan Export tetap berada dalam keluarga EXIM. Perbedaan kecil keduanya boleh ditangani oleh komponen atau policy EXIM, bukan dengan menduplikasi seluruh form.

Kontrak referensi PO opsional menjadi bagian dari `GeneralPaymentSlipData`; folder `Integrations/` untuk membaca dan memetakan PO ditetapkan pada rencana integrasi terpisah.

## 5. Prinsip implementasi

1. **Perilaku lebih dulu, pemindahan kode sesudahnya.** Tambahkan characterization test sebelum memindahkan logika.
2. **Satu milestone tidak boleh sekaligus mengubah requirement.** Hasil nominal, status, PDF, dan ERP harus identik sebelum dan sesudah refactor.
3. **Schema yang tidak digunakan tidak boleh dibangun.** Memisahkan file saja tidak cukup jika halaman General masih menginstansiasi komponen EXIM yang kemudian disembunyikan.
4. **Aturan bisnis tidak diletakkan di closure UI.** Filament hanya mengumpulkan dan menampilkan state; kalkulasi dan normalisasi berada di class aplikasi/domain.
5. **Model tetap menjaga invariant penting.** Otorisasi, currency yang valid, konsistensi total, dan identitas tipe tidak boleh hanya bergantung pada tampilan.
6. **Setiap milestone dapat dirilis sendiri.** Tidak boleh ada fase antara yang membutuhkan migrasi database atau membuat record lama tidak dapat dibuka.
7. **Kontrak tampilan dan alur tidak berubah.** File, class, dan jumlah komponen internal boleh berubah, tetapi layar dan tindakan yang dialami Maker, Checker, dan Approver harus setara dengan baseline.
8. **Kesiapan PO tidak menampilkan fitur.** Kontrak input General dapat menerima referensi PO opsional; selector, prefill, dan entry point mengikuti rencana integrasi terpisah.

### Kontrak UI/UX yang wajib dipertahankan

- Route lama, navigasi, bookmark, label, urutan field, placeholder, bantuan field, serta pilihan dan default yang terlihat pengguna.
- Tata letak form, pembagian section, posisi tombol, warna action, tabel daftar, badge status, dan urutan action per baris; termasuk tombol **Edit** dan **Check** yang keduanya tersedia bagi Checker sesuai statusnya.
- Workspace Form/PDF dua panel, tab pada lebar kecil, tombol Preview PDF per invoice, dan perilaku membuka PDF di tab baru. General saat ini juga memakai workspace tersebut; pemisahan schema tidak boleh menghilangkannya.
- Validasi yang terlihat, isi pesan error, kapan input dapat diedit, dan hasil setiap tindakan Create, Edit, Submit, Check, Verify, Preview PDF, Delete, serta ERP Export.
- PDF mempertahankan isi, label, format nilai, ukuran, posisi, pembagian halaman, dan tampilan cetak yang sudah disetujui.
- Halaman view dapat memakai teks read-only sesuai pedoman proyek, tetapi pengelompokan, urutan, label, dan tampilan nilai tetap setara secara visual dengan halaman sekarang; perubahan desain memerlukan pekerjaan terpisah.

## 6. Milestone

## Milestone 0 — Baseline dan characterization tests

### Pekerjaan

- Catat route, permission, status transition, dan jumlah query utama yang berjalan saat ini.
- Kumpulkan baseline yang sudah ada sebelum mengukur ulang: `docs/checker-edit-brief.md` (20.262 elemen DOM pada halaman edit slip, JS heap, biaya `Preview PDF`) dan `docs/audit-modul-po-fase1.md` (1,7 MB HTML untuk 50 baris item). Pastikan angkanya masih berlaku pada kode sekarang, jangan mengukur dari kosong.
- Buat fixture representatif untuk:
  - General IDR, USD, dan EUR;
  - General dengan customer kosong dan terisi;
  - EXIM Import dengan supplier asal, VAT, COA, dan pajak item;
  - EXIM Export dengan satu dan beberapa invoice;
  - status draft, submitted, approved, dan exported.
- Tambahkan salinan record nyata yang dianonimkan sebagai fixture, bukan hanya data buatan. Audit sebelumnya mencatat 204 slip dan 446 invoice; verifikasi ulang jumlah serta kombinasi tipe/mode pada database lokal sebelum menjadikannya baseline (lihat Milestone 3).
- Tambahkan tes snapshot perilaku untuk create, edit, view, checker edit, PDF, dan ERP.
- Ambil bukti tampilan sebelum perubahan pada role Maker, Checker, dan Approver: navigasi, daftar, create, edit, detail, halaman Check, workspace Form/PDF, modal/action, serta PDF General dan EXIM. Catat label, urutan field, tombol, status, dan ukuran viewport agar perbandingan sesudahnya sepadan.
- Ukur HTML create/edit pada panel yang sudah login: ukuran response dan jumlah elemen DOM, **pada jumlah item tetap (10, 50, dan 100 baris)** supaya angkanya sebanding dengan baseline PO di `docs/audit-modul-po-fase1.md`.
- Catat waktu render serta konsumsi memori browser sebagai baseline pembanding, bukan sebagai target absolut tunggal.

### Kriteria selesai

- Semua perilaku penting saat ini memiliki tes sebelum file dipisahkan.
- Test suite penuh lulus tanpa perubahan database.
- Tersedia laporan baseline General dan EXIM, termasuk angka per jumlah item.
- Tersedia acuan visual dan alur yang dapat dibandingkan pada setiap milestone.
- Tidak ada perubahan output atau tampilan pada milestone ini.

## Milestone 1 — Pisahkan dispatcher tipe dan kontrak data

### Pekerjaan

- Tambahkan helper tipe yang eksplisit seperti `isGeneral()` dan `isExim()` pada model atau object tipe, tanpa mengubah kolom database.
- Definisikan kontrak data/normalizer terpisah untuk General dan EXIM.
- Pindahkan pemilihan default currency, tax mode, dan field yang diizinkan keluar dari page closure ke class khusus tipe.
- Buat resolver yang menentukan page/schema berdasarkan `transaction_type` untuk record lama.
- Pertahankan path dan nama route yang dipakai pengguna (`create`, `create-general`, `create-import`, `create-export`, `edit`, `view`, checker edit, dan preview PDF). Dispatcher boleh memilih implementasi internal sesuai tipe, tanpa memindahkan pengguna ke URL baru.
- Pertahankan identitas tipe sebagaimana tampak di UI edit sekarang. Penguncian tambahan di level model dan penghapusan hook pada `PaymentSlip` merupakan perubahan perilaku di luar refactor ini; kaji terpisah setelah seluruh pemanggil dan data historis diaudit.
- Perjelas perilaku rute `create` yang sekarang melayani dua tipe: rute `create` mengarah ke `CreatePaymentSlip`, dan `getFixedTransactionType()` memilih EXPORT untuk pembuat dari divisi EXIM serta GENERAL untuk pembuat lain. Setelah dipisah, tentukan eksplisit ke mana pembuat non-EXIM diarahkan, dan pastikan bookmark lama tidak berubah menjadi halaman kosong atau 403 yang membingungkan.

### Tes

- Semua record lama diarahkan ke halaman yang tepat.
- User yang tidak berhak tetap mendapat `403` atau `404` sesuai perilaku sebelumnya.
- Tipe record tetap tidak dapat diubah melalui form edit seperti baseline; perilaku pemanggilan model di luar UI tidak diubah pada milestone ini.
- Nilai tersimpan lama tidak berubah; bandingkan himpunan record yang sama sebelum dan sesudah, bukan hanya jumlah agregat.
- Default General dan EXIM tetap sama seperti baseline.

### Kriteria selesai

- Pemilihan implementasi General atau EXIM berada di satu dispatcher yang jelas.
- Tidak ada perubahan schema database, nomor slip, atau status.
- URL yang telah digunakan user tetap valid.
- Tidak ada perubahan hasil dari model event yang berjalan sekarang.

## Milestone 2 — Pisahkan PDF General dan EXIM

### Pekerjaan

- Ubah `GeneratePaymentSlipPdf` menjadi dispatcher berdasarkan tipe slip.
- Pindahkan markup ke `general.blade.php` dan `exim.blade.php`.
- Gunakan partial hanya untuk header/footer atau elemen yang secara visual memang sama.
- Hilangkan percabangan General-versus-EXIM dari masing-masing template final.
- Pertahankan page-break, header tabel berulang, ukuran kertas, format uang, dan nama file.

### Tes

- PDF General IDR, USD, dan EUR mempertahankan format nilai.
- Customer hanya tampil ketika terisi.
- PDF EXIM mempertahankan buyer, invoice, VAT, supplier asal, dan data terkait.
- Dokumen satu halaman dan multi-halaman tidak terpotong.
- Preview tetap inline dan mengikuti permission yang ada.
- Bandingkan hasil cetak pada data dan pengaturan render yang sama: posisi, font, kolom, pagination, dan isi tidak berubah.

### Kriteria selesai

- Setiap tipe memakai template mandiri.
- Output bisnis setara dengan baseline dan lulus inspeksi visual.
- Tidak ada kondisi General di template EXIM dan sebaliknya.

## Milestone 3 — Ekstrak kalkulasi dan normalisasi dari Filament

### Aturan pajak yang harus direproduksi

Pada mode **itemized**, pajak dihitung pada item, diakumulasi ke invoice, lalu dijumlahkan ke slip. Aplikasi juga masih memiliki mode pajak **invoice_legacy** pada invoice tertentu, termasuk shortcut pajak invoice pada form EXIM Export yang diuji di `ErpExportTest`. Kedua jalur ini harus tetap menghasilkan nilai dan tampilan yang sama seperti baseline.

Konsekuensi untuk implementasi:

- Untuk mode itemized, nominal invoice berasal dari item; nominal slip berasal dari invoice. Untuk mode invoice_legacy, kalkulasi pajak invoice mengikuti perilaku kode saat ini.
- Slip Export lama dengan `invoice_legacy` tetap dibaca seperti sekarang. Shortcut pajak invoice pada EXIM Export draft tetap terlihat dan dapat digunakan dalam kondisi yang sama seperti baseline.
- Default mode pada record baru mengikuti kode saat ini. Refactor tidak menghapus shortcut, mengubah default, atau menormalkan mode pada data lama.

Tiga penulis berbeda pernah mengisi kolom mode dari arah berbeda (`Invoice::creating()` yang memaksa itemized untuk induk General, migrasi 17 Sep yang menurunkan mode dari tipe slip, dan `PaymentSlip::usesItemizedTaxes()` yang mengabaikan mode tersimpan untuk `import`). Dari **446 invoice**, **209 (47%)** nilai kolom modenya berbeda dari mode slip induknya:

| Tipe slip | Mode slip | Mode invoice | Jumlah | Dibuat |
| --- | --- | --- | --- | --- |
| general | invoice_legacy | **itemized** | 148 | 22 Sep – 2 Okt |
| general | invoice_legacy | invoice_legacy | 18 | 16 Sep – 22 Sep |
| import | itemized | itemized | 77 | 18 Sep – 30 Sep |
| export | invoice_legacy | invoice_legacy | 72 | 2 Sep – 17 Sep |
| export | itemized | itemized | 70 | 17 Sep – 1 Okt |
| export | itemized | **invoice_legacy** | 32 | 17 Sep – 2 Okt |
| import | invoice_legacy | **itemized** | 27 | 7 Sep – 16 Sep |
| import | itemized | **invoice_legacy** | 2 | 18 Sep |

Perbedaan mode slip dan invoice pada tabel tersebut adalah fakta data yang perlu dikarakterisasi. Angka ini merupakan snapshot lokal saat audit; hitung ulang dan dokumentasikan tanggal serta sumbernya pada M0 sebelum dipakai sebagai dasar keputusan implementasi.

Kedelapan kelompok harus menghasilkan nominal yang persis sama sebelum dan sesudah refactor. Nilai kolom yang berbeda dari induknya dibiarkan apa adanya. Aturan penulisan mode untuk record baru boleh dipindah ke class yang lebih jelas hanya jika hasil tersimpan dan tindakan yang tersedia tetap sama.

### Pekerjaan

- Pindahkan kalkulasi item, invoice, pajak, dan total dari method schema ke service tipe yang dapat diuji langsung.
- Jadikan `InvoiceAmountCalculator` sebagai komponen dasar bersama bila rumusnya benar-benar sama.
- Pisahkan aturan currency General dari aturan IDR EXIM.
- Pisahkan aturan pajak itemized General/Import/Export dari jalur invoice_legacy Export yang masih digunakan.
- Konsolidasikan kalkulasi hanya sesudah urutan event dan hasil untuk kedua mode dibuktikan setara; jangan menghapus event sebagai cleanup otomatis.

### Tes

- Nilai tersimpan sebelum dan sesudah refactor identik untuk semua fixture.
- Pembulatan IDR, USD, dan EUR tetap identik.
- Perubahan quantity, unit price, PPN, dan PPh menghasilkan total yang sama pada create dan edit.
- Saving melalui service, Filament, dan checker tidak menghasilkan perbedaan.
- ERP builder menerima nominal yang sama persis seperti baseline.
- Perilaku untuk **kedelapan kelompok mode pajak pada tabel di atas** direproduksi persis oleh kode hasil refactor, termasuk kelompok yang tidak konsisten — inilah jaring karakterisasi yang mencegah "perbaikan" tak sengaja.

### Kriteria selesai

- Kalkulasi dapat diuji tanpa Livewire atau Filament.
- Schema form tidak lagi menjadi sumber aturan bisnis utama.
- Tidak ada perubahan nominal pada data lama maupun fixture.
- Kedua mode pajak tetap berfungsi dan kedelapan kelompok data lama direproduksi persis tanpa dinormalkan.

## Milestone 4 — Pisahkan create form General dan EXIM

### Pekerjaan

- Bangun `GeneralPaymentSlipForm` hanya dengan field General.
- Bangun `EximPaymentSlipForm` hanya dengan field Import/Export.
- Pertahankan pemilihan Import/Export di dalam konteks EXIM.
- Pindahkan ekstraksi Gemini hanya ke EXIM (sekarang berada di `PaymentSlipForm`: `Action::make('extract_via_gemini')` beserta pemanggilan `GeminiInvoiceExtractor`).
- Gunakan komponen bersama kecil untuk supplier, dokumen, atau status bila tidak membutuhkan percabangan tipe.
- Pastikan halaman General tidak membangun repeater/field EXIM tersembunyi dan sebaliknya.
- Definisikan `GeneralPaymentSlipData` dengan referensi `purchase_order_id` opsional di tingkat invoice, menggunakan kolom yang sudah ada. Jalur create manual tidak mengisi referensi ini dan tetap menghasilkan state maupun tampilan yang sama. Adapter dan pemilihan PO dijalankan dalam rencana integrasi terpisah.

### Tes

- Create General mencakup customer, deskripsi, currency, quantity, satuan, harga, dan pajak item.
- Create EXIM mencakup receipt, buyer, invoice, VAT, dokumen, dan ekstraksi yang sesuai.
- Payload field dari tipe lain ditolak atau dibuang dengan aman.
- Transaction database dan rollback ketika item gagal tetap bekerja.
- Permission Maker divisi tetap sama.
- General dapat menerima referensi PO opsional melalui kontrak internal; create manual tanpa PO, termasuk data lama, tetap identik. EXIM tidak menerima input PO melalui formnya.

### Kriteria selesai

- Kedua halaman create tidak berbagi schema monolitik.
- Tidak ada field lintas tipe di state/dehydrated payload.
- Kontrak input General siap untuk integrasi PO tanpa tombol, selector, label, atau langkah baru bagi pengguna.
- Bobot halaman diukur ulang pada 10, 50, dan 100 item memakai protokol M0, lalu dibandingkan dengan baseline pada jumlah item yang sama:
  - jumlah elemen DOM mengikuti target proyek di `docs/checker-edit-brief.md` (20.262 → di bawah 3.500 elemen);
  - ukuran HTML tidak melebihi baseline pada jumlah item yang sama, dan untuk halaman General harus turun karena komponen EXIM tidak lagi dibangun.
  - Target tidak dipatok sebagai satu angka tunggal (misalnya "1 MB"), karena besarnya HTML bergantung pada jumlah item: PO 50 baris saja sudah 1,7 MB menurut `docs/audit-modul-po-fase1.md`.

## Milestone 5 — Pisahkan edit dan view General/EXIM

### Pekerjaan

- Buat page edit dan view khusus General serta EXIM.
- Implementasikan halaman view sebagai teks read-only sesuai pedoman proyek, dengan gaya yang mempertahankan section, label, urutan, nilai, panel PDF, dan posisi action yang saat ini dilihat pengguna.
- Pertahankan tombol Submit, Verify, Preview PDF, Edit, dan Delete sesuai status dan role.
- Pertahankan workspace Form/PDF pada General dan EXIM sebagaimana sekarang, termasuk tab pada lebar kecil dan panel kosong ketika belum ada dokumen.
- Arahkan checker ke implementasi tipe yang tepat tanpa mengubah workflow.

### Tes

- Draft hanya dapat diedit oleh Maker pemilik atau role yang sudah diizinkan.
- Submitted hanya dapat diedit pada jalur Checker yang diizinkan.
- Approved/Exported tetap terkunci.
- Semua field lama tampil pada view tipe yang tepat.
- General tidak membangun field EXIM tersembunyi; panel PDF yang terlihat saat ini tetap ada.
- EXIM tidak memuat currency/customer/description khusus General.

### Kriteria selesai

- Create, edit, dan view sudah terpisah penuh pada presentation layer.
- Action dan permission identik dengan baseline.
- Perbandingan layar untuk role dan status yang sama menunjukkan label, susunan, tombol, dan perilaku panel tetap setara.
- Record lama dari seluruh status tetap dapat dibuka.

## Milestone 6 — Checker edit dan workflow integration

### Pekerjaan

- Audit halaman checker konvensional terhadap pemisahan tipe (`app/Http/Controllers/CheckerEditPaymentSlipController.php`, `app/Support/CheckerEditableFields.php`).
- Buat presenter atau handler per tipe bila field yang boleh diedit berbeda.
- Pertahankan audit `checker_edited`, validasi ownership item, dan pembatasan COA.
- Pastikan Verify dan ERP Export menggunakan service/kontrak hasil refactor, bukan schema Filament.

### Tes

- Checker hanya dapat mengubah field yang diizinkan pada slip yang tepat.
- Item milik slip lain tidak dapat disisipkan dalam request.
- Audit lama tetap lengkap dan tidak berubah format tanpa kebutuhan.
- Verify dan ERP export tetap menghasilkan status dan workbook yang sama.

### Kriteria selesai

- Tidak ada workflow yang bergantung pada class form lama.
- Jalur Maker, Checker, PDF, dan ERP menggunakan aturan bisnis yang konsisten.

## Milestone 7 — Hapus compatibility code dan optimalkan bobot halaman

### Pekerjaan

- Hapus `PaymentSlipForm` lama setelah semua route tidak lagi menggunakannya.
- Hapus helper, branch, dan compatibility adapter yang sudah tidak memiliki pemanggil.
- Audit eager loading, query N+1, state Livewire, dan repeater yang masih terlalu besar.
- Ukur ulang HTML, jumlah DOM, waktu render, dan memori browser pada data yang sama dengan baseline.
- Perbarui dokumentasi teknis dan inventaris file.

### Tes dan verifikasi

- Jalankan formatter dan seluruh test suite.
- Verifikasi create/edit/view General dan EXIM melalui browser pada panel login yang benar.
- Verifikasi PDF satu dan multi-halaman secara visual.
- Verifikasi workbook ERP secara struktural terhadap fixture yang sudah disetujui.
- Jalankan `git diff --check` dan pastikan tidak ada migration/dependency yang ikut.

### Kriteria selesai

- Tidak ada schema monolitik atau percabangan tipe besar yang tersisa.
- Setiap halaman memenuhi batas praktis bobot halaman proyek.
- Semua tes dan QA visual lulus.
- Tidak ada perubahan schema database atau perubahan data produksi.

## 7. Matriks pengujian minimum

| Alur | General | EXIM Import | EXIM Export |
| --- | --- | --- | --- |
| Create | IDR/USD/EUR, customer opsional | Invoice/item, supplier asal, VAT | Invoice/item, buyer, VAT |
| Edit draft | field General saja | field Import saja | field Export saja |
| View | read-only General | read-only Import | read-only Export |
| Submit | Maker pemilik | Maker EXIM pemilik | Maker EXIM pemilik |
| Checker edit | pajak/COA sesuai aturan | pajak/COA sesuai aturan | legacy/itemized sesuai data |
| Verify | nominal tidak berubah | nominal tidak berubah | nominal tidak berubah |
| PDF | format currency dan customer | data import lengkap | data export lengkap |
| ERP | jurnal tetap seimbang | cost center/import mapping | cost center/export mapping |
| Multi-page | item tidak terpotong | invoice/item tidak terpotong | invoice/item tidak terpotong |
| Kesiapan PO | kontrak referensi opsional, tanpa UI baru | tidak tersedia | tidak tersedia |

Setiap alur perlu mencakup record baru dan record lama yang sudah ada sebelum refactor.

## 8. Strategi delivery

Commit dan deployment sebaiknya mengikuti milestone, bukan satu perubahan besar:

1. Baseline tests dan laporan ukuran.
2. Dispatcher/kontrak tipe, dengan perilaku edit tipe yang tetap sama.
3. Split PDF.
4. Domain calculator dan validator, mempertahankan kedua mode pajak yang berjalan.
5. Split create.
6. Split edit/view.
7. Checker/workflow integration.
8. Cleanup dan performance QA.

Setiap slice harus:

- lulus tes spesifik milestone;
- lulus seluruh test suite sebelum deploy;
- dapat di-rollback dengan mengembalikan berkas aplikasi saja;
- tidak menjalankan migration;
- tidak mencampurkan perubahan PO, dependency, atau fitur baru.

### Verifikasi independen per slice

Setiap slice ditutup dengan verifikasi yang dikerjakan pihak selain pelaksana, bukan dengan membaca ringkasan pelaksana:

- jalankan sendiri seluruh test suite (jangan memakai angka yang dilaporkan) dan catat jumlah tes serta asersinya;
- render ulang **beberapa slip nyata** dari dataset lokal — bukan hanya fixture — lalu bandingkan isi dan hasil gambar tiap halaman pada konfigurasi render yang sama; hash file hanya dipakai bila metadata PDF terbukti deterministik;
- bandingkan screenshot layar per role, tipe, dan status pada viewport yang sama, termasuk navigasi, form, detail, action, dan workspace Form/PDF;
- bandingkan workbook ERP baris per baris terhadap fixture yang sudah disetujui;
- hitung jumlah elemen DOM dan ukuran HTML pada record dengan jumlah item yang sama dengan baseline;
- bandingkan jumlah baris dan nilai tersimpan tabel `payment_slips`, `invoices`, dan `invoice_items` sebelum dan sesudah setiap slice yang menyentuh kalkulasi.

## 9. Risiko dan mitigasi

| Risiko | Mitigasi |
| --- | --- |
| Nominal berubah akibat urutan kalkulasi baru | Characterization test nilai tersimpan dan ERP sebelum ekstraksi logic |
| Nominal berubah akibat "perbaikan" mode pajak yang tak sengaja | Karakterisasi seluruh kombinasi mode yang terverifikasi pada M0 dan jangan normalisasi selama refactor |
| Record lama tidak dapat diedit | Fixture legacy dan resolver berdasarkan `transaction_type` |
| Route/bookmark user rusak | Pertahankan path dan nama route lama; uji khusus untuk pembuat non-EXIM pada rute `create` |
| Permission melebar saat page dipecah | Satu policy/resource authorization bersama dan tes per role/status |
| Hidden component tetap membebani Livewire | Schema dipilih di level page/route, bukan sekadar `visible(false)` |
| PDF berubah tanpa sengaja | Tes konten dan perbandingan hasil render satu/multi-halaman; hash file hanya bila metadata deterministik |
| Duplikasi antara General dan EXIM muncul kembali | Hanya ekstrak komponen yang identik; jangan membuat base class besar penuh flag |
| ERP berubah karena refactor model | Golden fixture workbook dan journal-row comparison |
| Modul PO yang sudah divalidasi klien ikut tersentuh | Tidak ada berkas modul PO yang boleh diubah; integrasi PO berada di rencana terpisah |
| Integrasi PO tersembunyi dapat dipanggil langsung setelah diimplementasikan | Feature flag diperiksa server-side bersama authorization pada rencana integrasi |
| Invoice parsial dianggap sudah didukung penuh | Dokumentasikan keterbatasan relasi invoice-level; item-level outstanding menjadi fase terpisah |

## 10. Definition of done keseluruhan

Refactor dinyatakan selesai hanya jika:

- schema database dan data existing tidak berubah;
- General dan EXIM memiliki implementation path terpisah pada create, edit, view, validation, serta PDF;
- komponen bersama hanya memuat perilaku yang memang identik;
- identitas tipe slip dan model event mempertahankan perilaku baseline; hardening model dibahas sebagai pekerjaan terpisah;
- mode itemized dan invoice_legacy tetap menghasilkan nominal, pilihan UI, dan data tersimpan yang sama; data lama tidak dinormalkan;
- seluruh route lama tetap bekerja atau diarahkan secara aman;
- hasil nominal, workflow, audit, PDF, dan ERP sesuai baseline, diverifikasi ulang dengan record nyata dan bukan hanya fixture;
- label, urutan field, layout, tombol, panel PDF, navigasi, validasi yang terlihat, serta langkah pengguna tetap setara dengan baseline pada semua role dan tipe slip;
- kontrak General menerima referensi PO opsional melalui `invoices.purchase_order_id`, tetapi tidak menampilkan entry point integrasi selama refactor;
- halaman telah diukur pada jumlah item tetap dan memenuhi pedoman bobot proyek;
- modul PO yang sudah divalidasi klien tidak diubah oleh refactor ini;
- fitur integrasi PO yang kelak ditambahkan mengikuti pengamanan server-side pada rencana integrasi terpisah;
- formatter, seluruh test suite, browser QA, PDF QA, dan ERP fixture comparison lulus;
- tidak ada compatibility code mati atau branch tipe besar yang tertinggal.

## 11. Catatan implementasi lokal — 11 Oktober 2026

Baseline commit `1d263ce`; refactor setelah commit tersebut masih berada di working tree dan belum di-push/deploy. Schema General dan EXIM serta template PDF sudah dipisah. Suite lokal terakhir: 204 tes lulus, 1.235 assertion. Browser terautentikasi sebagai `Staff EXIM Maker` membuka create General/EXIM dengan susunan field dan workspace Form/PDF yang diharapkan. Akun tersebut tidak memiliki slip lokal, sehingga edit/view record nyata masih menunggu QA dengan akun yang berwenang.

Pada form General dengan satu invoice, select PDF yang disabled sebelumnya memuat seluruh 279 opsi dokumen. Setelah opsi dibatasi pada dokumen terpilih, DOM turun dari 1.139 menjadi 860 elemen dan `outerHTML` dari 300.459 menjadi 234.488 karakter. Label `Document file` dan penyimpanan relasi PDF diverifikasi oleh tes.

Probe sementara pada database test (sudah dihapus) mengukur HTML edit dengan satu invoice. Angka ini adalah ukuran HTML server Livewire dan jumlah opening tag, bukan memori browser:

| Tipe | Item | HTML bytes | Opening tags |
| --- | ---: | ---: | ---: |
| General | 10 | 540.905 | 1.794 |
| General | 50 | 2.213.666 | 7.274 |
| General | 100 | 4.309.049 | 14.124 |
| EXIM Export | 10 | 330.864 | 1.083 |
| EXIM Export | 50 | 1.132.624 | 3.643 |
| EXIM Export | 100 | 2.134.828 | 6.843 |

Jadi pemisahan schema saja **belum** menyelesaikan bobot edit untuk slip besar. Sebelum rilis, lanjutkan pemisahan edit/view read-only, QA role/status, PDF multi-halaman, serta strategi render edit yang lebih ringan tanpa mengubah alur/label yang sudah disetujui. Jangan deploy working tree parsial ini.

QA browser tambahan dengan akun Maker pemilik slip (`sugiyanto`) membuka record EXIM nyata tanpa menyimpan perubahan. Akun ini memiliki 41 Export dan 9 Import, tetapi tidak memiliki General; karena itu QA edit/view General tetap memerlukan akun pemilik record General atau Checker. Angka di bawah adalah DOM browser yang terautentikasi, bukan ukuran server-rendered probe di atas:

| Halaman | Data | Elemen DOM | `outerHTML` karakter | Kontrol input |
| --- | --- | ---: | ---: | ---: |
| Edit EXIM Export | 11 invoice / 52 item | 6.489 | 1.691.866 | 399 |
| Detail EXIM Export | record sama | 5.509 | 1.419.290 | 402 |
| Edit EXIM Import | record nyata | 2.022 | 512.267 | 87 |
| Detail EXIM Import | record sama | 1.707 | 426.186 | 86 |

Detail masih merender komponen form/Livewire (`input`, `select`, dan `textarea`), bukan teks read-only sebagaimana target milestone 5 dan `docs/ui-guidelines.md`. Semua input yang terlihat pada sampel Import non-editable; input yang tetap enabled adalah field tersembunyi. Tombol Preview PDF invoice muncul, tetapi sampel lokal mengembalikan 404 karena file fisik dokumen terkait tidak ada di storage lokal (`DocumentFile` dan relasinya ada). Ini membatasi QA visual PDF invoice lokal dan bukan bukti regresi template Payment Slip. Tidak ada record yang disimpan atau diubah dalam QA browser ini.

Tahap berikutnya mengganti **halaman detail saja** menjadi tampilan teks read-only yang terpisah untuk General dan EXIM. Section, label, action header, tab Form/PDF, dan pemilih PDF per invoice tetap ada; halaman edit belum diganti. Setelah perubahan, pengukuran pada record EXIM yang sama di panel login menunjukkan:

| Halaman detail | Sebelum (elemen / karakter HTML / input) | Sesudah (elemen / karakter HTML / input) |
| --- | ---: | ---: |
| EXIM Import | 1.707 / 426.186 / 86 | 640 / 147.622 / 1 |
| EXIM Export (11 invoice, 52 item) | 5.509 / 1.419.290 / 402 | 1.151 / 227.182 / 1 |

Satu input tersisa berasal dari shell panel, bukan detail slip. Pergantian Preview PDF antar-invoice tetap mengubah URL iframe. Pada sampel lokal, isi iframe tetap 404 karena file fisik PDF tidak ada. Tes baru memastikan detail General dan EXIM menampilkan field tipe yang benar tanpa elemen input/select/textarea. Seluruh suite setelah perubahan: **205 tes lulus, 1.256 assertion**. Milestone 5 belum selesai: pemisahan editor, QA General dengan akun yang berwenang, dan perbandingan visual lintas status/role masih tersisa.

Lanjutan 11 Oktober: penyimpanan edit sekarang melewati normalizer General/EXIM yang berbeda tanpa mengganti URL `/{record}/edit`, identitas tipe, atau mode pajak historis. View dipisah ke presenter General/EXIM; Checker memakai kebijakan tipe terpisah untuk VAT item. Delapan kombinasi mode slip/invoice historis diuji dengan fixture database test yang sengaja tidak dinormalisasi. Snapshot database lokal tetap 204 slip dan 446 invoice, dengan distribusi delapan kelompok sama seperti tabel Milestone 3. Tidak ada query mutatif ke database lokal nyata.

Inspeksi PDF nyata: General ID 261 (10 invoice) dan EXIM Import ID 100 (9 invoice/95 item) masing-masing satu halaman; EXIM Export ID 232 (11 invoice/52 item) dua halaman. Pada baseline split-template, blok APPROVAL terbelah setelah header tabel di akhir halaman pertama. Blok APPROVAL, PAYMENT, dan Received/Confirmed kini diberi `page-break-inside: avoid` bersama; hasil render ulang memindahkan seluruh blok ke halaman kedua tanpa memotong baris. Isi dan nilai tidak berubah. QA visual dilakukan pada PNG setiap halaman. Karena file invoice scan fisik tidak tersedia di storage lokal, viewer invoice pada browser tetap 404; ini terpisah dari PDF Payment Slip yang berhasil dirender.

Pengukuran browser terautentikasi untuk Edit EXIM Export ID 232 masih **6.489 elemen, 1.691.906 karakter HTML, 399 kontrol input, dan 579 node Alpine**. Satu item repeater rata-rata menghasilkan sekitar 37 KB HTML; pada record ini ada 63 instance repeater (11 invoice + 52 item). Form draft besar tetap melewati batas praktis. `collapsed()` hanya menyembunyikan dengan Alpine (`x-show`) dan tidak mengurangi DOM; penurunan ke bawah 3.000 elemen membutuhkan rendering bertahap/virtual atau editor konvensional yang akan mengubah cara interaksi item. Jangan mengklaim Milestone 7 selesai sebelum pendekatan itu dipilih dan diuji pada 10/50/100 item serta role General/EXIM.

Penyelesaian akhir 11 Oktober memakai editor draft konvensional di route Filament yang sama (`/{record}/edit`). Seluruh invoice/item tetap dirender terbuka sekaligus; tambah/hapus invoice, tambah/hapus item, upload/ganti PDF, Preview PDF, pajak per item, shortcut pajak seluruh invoice, kembali ke pajak per item, Save, Submit, serta workspace Form/PDF dipertahankan. Editor memakai request tervalidasi dan service transaksi tersendiri; submitted Checker tetap melalui jalur terproteksi yang sudah ada. Schema General dibersihkan dari branch EXIM dan schema EXIM tidak lagi membawa compatibility branch General.

Pengukuran response panel terautentikasi setelah editor konvensional (UTF-8 bytes; hitungan opening tag sebagai proksi DOM server) adalah:

| Halaman | Data | Elemen | HTML bytes | Kontrol |
| --- | --- | ---: | ---: | ---: |
| Edit EXIM Export ID 232 | 11 invoice / 52 item | 2.659 | 357.331 | 348 |
| Edit General ID 263 | 1 invoice / 2 item | 1.014 | 158.686 | 30 |
| Detail General ID 263 | 1 invoice / 2 item | 472 | 129.998 | 1 |
| Edit EXIM Import ID 47 | 4 invoice / 4 item | 1.344 | 190.092 | 84 |
| Detail EXIM Import ID 100 | 9 invoice / 95 item | 1.890 | 320.697 | 1 |

Fixture performance database-test 11 invoice / 52 item menghasilkan 2.535 opening tag, 283.684 byte HTML, dan 503 kontrol; tes regresi mengunci batas <3.500 elemen dan <1 MB. Semua invoice dan 52 item diverifikasi hadir di markup. Suite akhir setelah implementasi: 210 tes lulus, 1.344 assertion.

PDF nyata dirender ulang menjadi PNG: General ID 263 dan Import ID 100 masing-masing satu halaman; Export ID 232 dan Import ID 168 masing-masing dua halaman. Semua halaman terbaca tanpa teks/tabel terpotong. Pada kedua dokumen dua halaman, APPROVAL, PAYMENT, dan Received/Confirmed berada utuh bersama pada halaman kedua.

Perbandingan ERP menemukan artifact lokal `storage/app/private/erp-examples/PS-FIXTURE-002-ERP.xlsx` sudah tertinggal dari baseline commit: 60 baris worksheet diperiksa dan 12 sel berbeda (10 deskripsi jurnal serta pembulatan PPN/PPh invoice pertama). Source ERP tidak berubah terhadap baseline `1d263ce`, dan characterization suite ERP saat ini tetap lulus. Fixture lama tidak ditimpa karena instruksi QA meminta membandingkan terhadap fixture yang disetujui, bukan mengubahnya; drift ini dicatat sebagai temuan baseline, bukan regresi refactor.

Perbaikan editor draft 11 Oktober menutup kegagalan simpan item pada EXIM Export ID 232. Reproduksi browser terautentikasi menunjukkan Quantity pertama berubah `1.0000` menjadi `2` di DOM, tetapi form tidak mengirim request karena `invoice_receipt_number` lama bernilai kosong sementara input dipaksa `required`; tidak ada blok error dan database tetap `1.0000`. Ekspresi Alpine action Submit juga terpotong pada kutip selector HTML dan menghasilkan `SyntaxError: Invalid or unexpected token`. Edit sekarang menerima nomor tanda terima kosong untuk kompatibilitas record lama (aturan create tidak berubah), memakai validasi server sebagai sumber pesan error yang terlihat, dan action Submit mengirim event Alpine pendek ke handler editor sehingga markup tidak lagi terpotong.

QA browser sesudah perbaikan pada ID 232 berhasil menyimpan Quantity `1.0000` menjadi `2.0000`: subtotal item `183.000` menjadi `366.000`, subtotal/total invoice pertama `3.582.100`/`3.904.489` menjadi `3.765.100`/`4.103.959`, dan grand total slip `30.780.347` menjadi `30.979.817`. Console bersih. Quantity kemudian dikembalikan ke `1.0000`; seluruh nilai item, invoice, grand total slip, serta `payment_slips.updated_at` dikembalikan ke snapshot awal. ID 263 tidak diubah dan tetap grand total `1.100.000`. Tes baru `PaymentSlipDraftEditorTest` menyimpan perubahan melalui route editor untuk General, EXIM Import, dan EXIM Export dengan dua invoice, lalu memeriksa quantity, subtotal/net item, subtotal/total invoice, dan subtotal/grand total slip di database. Tes yang sama memastikan quantity kosong tidak mengubah database dan menghasilkan blok `Changes were not saved.`. Suite akhir: **213 tes lulus, 1.386 assertion**.
