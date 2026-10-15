# Rencana Integrasi PO → General Payment Slip

> **Arsip rencana awal — bukan acuan implementasi aktif.** Keputusan penerbitan
> PO Final, sumber terkunci, satu PO–satu slip, dan pengecualian ECOUNT berada di
> `po-to-general-payment-slip-implementation-plan.md`. Untuk keputusan terbaru,
> gunakan bagian "Revisi aktif — 15 Oktober 2026" di dokumen tersebut. Prefill
> editable, penerbitan berulang, dan scope awal di bawah tidak mengalahkan keputusan
> terbaru. Revisi dokumen tidak memberikan izin perubahan kode/deploy.

> Rencana ini **terpisah** dari refactor Payment Slip (`docs/payment-slip-refactor-plan.md`).
> Refactor itu berkontrak "hasil dan tampilan identik dengan baseline" serta menyiapkan kontrak
> referensi PO opsional di sisi General. Rencana ini memakai kontrak tersebut untuk prefill dan
> aktivasi kelak. Perubahan UI integrasi memiliki milestone dan persetujuan tersendiri.

## 1. Tujuan

Menyiapkan Payment Slip General sebagai dokumen lanjutan dari Purchase Order: slip General dapat menerima konteks sebuah PO sebagai nilai awal, sementara nomor invoice, tanggal, kuantitas tertagih, harga, pajak, dan nominal pembayaran tetap dikonfirmasi dari invoice aktual.

Integrasi hanya ditujukan ke **Payment Slip General**. EXIM tidak menerima konteks PO pada fase ini.

## 2. Prasyarat

Rencana ini baru boleh mulai setelah seluruh syarat berikut terpenuhi:

- refactor Payment Slip tuntas: halaman General dan EXIM sudah terpisah, hasil perhitungan untuk mode itemized dan invoice_legacy tetap sama, dan kontrak referensi PO opsional tersedia pada General;
- modul Purchase Order tetap dalam keadaan yang sudah divalidasi klien — tidak ada perubahan berkas PO yang diizinkan di rencana ini;
- pekerjaan transisi ECOUNT (`docs/rencana-transisi-ecount.md`) untuk data PO sudah jelas statusnya, sehingga PO yang menjadi sumber konteks bukan data yang masih akan disusun ulang.

## 3. Keputusan dan batasan

- General Payment Slip harus memiliki kontrak input yang dapat menerima sebuah PO, tanpa menjadikan form atau service General bergantung langsung pada UI PO.
- PO dan slip tetap dua aggregate berbeda. Setelah slip disimpan, perubahan PO tidak boleh mengubah histori maupun nominal slip.
- Relasi yang sudah ada, `invoices.purchase_order_id`, menjadi titik sambung awal. Satu PO dapat terkait ke beberapa invoice, dan invoice dalam satu General Payment Slip dapat menunjuk PO.
- Supplier dan currency PO dapat digunakan untuk prefill dan validasi konteks.
- Feature flag server-side, default nonaktif. Saat nonaktif, UI dan endpoint integrasi tidak boleh tersedia melalui alur normal, tetapi kontrak integrasinya tetap memiliki automated test.
- Sebelum aktivasi, semua halaman, label, tombol, susunan form, panel PDF, dan alur pengguna tetap seperti baseline Payment Slip yang sudah disetujui.
- Kode modul PO tidak boleh diubah untuk memenuhi milestone di rencana ini.
- Relasi invoice-level belum menyelesaikan outstanding kuantitas per item untuk pengiriman atau penagihan parsial. Kemampuan itu fase lanjutan tersendiri, bukan bagian rencana ini.

## 4. Target arsitektur

```text
app/Services/PaymentSlips/Integrations/
├── GeneralPaymentSlipSource.php
├── PurchaseOrderContext.php
├── PurchaseOrderContextAdapter.php
└── ValidatePurchaseOrderContext.php
```

Integrasi masuk melalui contract/service General, bukan sebagai kondisi baru pada schema bersama, dan bukan dengan mengubah service penyimpanan PO. Dengan demikian wiring dapat diaktifkan kemudian tanpa menyatukan kembali form General dan EXIM.

## 5. Prinsip implementasi

1. **Data PO adalah sumber bantu, bukan nominal pembayaran final.** Invoice aktual tetap sumber input pembayaran, dan nilai hasil konfirmasi disimpan pada invoice/item slip.
2. **Feature hidden berarti tidak terpapar server-side.** Tombol yang disembunyikan secara visual saja tidak dianggap cukup.
3. **PO tidak pernah ditulis.** Adapter hanya membaca; tidak ada prefill yang menyimpan atau mengubah record PO.
4. **Pembuatan slip tetap lewat satu jalur.** Slip dengan maupun tanpa konteks PO melewati service create General yang sama; tidak ada jalur penyimpanan kedua khusus PO.
5. **Konsekuensi satu arah.** Setelah slip tersimpan, PO tidak lagi memengaruhi slip.

## 6. Milestone

## Milestone P0 — Feature flag dan konteks PO

### Pekerjaan

- Tambahkan feature flag/config internal, default `false`, untuk integrasi PO → General Payment Slip.
- Definisikan contract sumber General Payment Slip dan DTO `PurchaseOrderContext` agar form/service General dapat menerima data PO tanpa bergantung pada halaman atau controller PO.
- Buat adapter read-only dari model PO ke contract tersebut; adapter tidak boleh menyimpan atau mengubah PO.
- Buat validator konteks yang memastikan:
  - PO dapat diakses user sesuai divisi/role;
  - supplier tersedia dan cocok dengan slip;
  - currency PO didukung General dan tidak dicampur dalam satu slip;
  - PO tidak batal;
  - PO historis yang datanya tidak lengkap menghasilkan pesan yang jelas, bukan error teknis.

### Tes

- Feature flag nonaktif: tombol/selector tidak dirender dan endpoint khusus tidak dapat dipakai dari UI normal.
- Adapter PO menghasilkan `PurchaseOrderContext` yang valid tanpa mengubah record PO.
- PO batal, tidak berhak diakses, supplier tidak cocok, dan currency tidak cocok ditolak dengan pesan yang spesifik.

### Kriteria selesai

- Contract dan validator dapat diuji tanpa Filament dan tanpa mengaktifkan flag.
- Tidak ada perubahan schema database.
- Tidak ada route/action tersembunyi yang melewati authorization.

## Milestone P1 — Prefill pada General Payment Slip

### Pekerjaan

- Buat prefill builder pada sisi General yang memetakan context tanpa langsung menyimpan:
  - `purchase_order_id` pada invoice;
  - supplier dan currency;
  - nomor PO sebagai konteks read-only;
  - kandidat baris item, quantity, unit, price, dan tax sebagai nilai awal yang tetap dapat dikonfirmasi user.
- Siapkan titik penerimaan context dan selector opsional pada invoice General di balik feature flag. Jangan menambahkan action atau mengubah halaman PO pada milestone ini.
- Pastikan pembuatan slip tetap melewati service create General yang sama.
- Tampilkan asal PO pada edit/view General dan PDF hanya setelah fitur diaktifkan. Saat flag nonaktif, record yang sudah memiliki relasi tetap harus dapat dibuka tanpa kehilangan data.
- Dokumentasikan contract yang kelak dapat dipanggil entry point PO tanpa memerlukan refactor ulang pada General Payment Slip.

### Tes

- Feature flag aktif: General Payment Slip dapat menerima context PO dan membentuk draft.
- Supplier, currency, dan referensi PO terisi dari PO yang dipilih.
- Nilai invoice/item dapat dikoreksi sebelum simpan dan tidak mengubah PO.
- Menyimpan draft mengisi `invoices.purchase_order_id` yang sudah tersedia.
- Satu PO dapat ditautkan ke lebih dari satu invoice tanpa duplikasi payment slip otomatis.
- Perubahan PO setelah slip tersimpan tidak mengubah nominal maupun snapshot invoice/item slip.
- Record lama tanpa PO tetap berfungsi identik.
- EXIM tidak menampilkan atau menerima integrasi PO ini.

### Kriteria selesai

- Form dan service General siap menerima konteks PO tanpa perubahan schema database.
- Tidak ada perubahan source code pada form, PDF, status, atau workflow modul PO.
- Feature tetap nonaktif di production sampai ada persetujuan aktivasi tersendiri.
- Perbandingan layar dan action pada flag nonaktif menunjukkan tidak ada perubahan yang terlihat oleh pengguna.
- Tidak ada perhitungan outstanding per item yang diklaim selesai pada milestone ini.

## Milestone P2 — Aktivasi (di luar lingkup dokumen ini sampai disetujui)

Wiring dari sisi PO (tombol atau halaman PO), aktivasi flag di production, dan pelatihan pemakai dikerjakan sebagai keputusan tersendiri setelah P0 dan P1 diterima. Pada titik itu modul PO boleh disentuh, dengan perubahan terpisah dan persetujuan pemilik produk.

## 7. Matriks pengujian

| Alur | Kondisi | Harapan |
| --- | --- | --- |
| Flag nonaktif | Akses UI normal | Selector tidak dirender; endpoint khusus tidak dapat dipakai |
| Flag nonaktif | Record lama tanpa relasi PO | Berfungsi identik seperti sebelum integrasi |
| Flag nonaktif | Record yang sudah punya relasi PO | Tetap dapat dibuka tanpa kehilangan data |
| Flag aktif | Pilih PO valid | Supplier, currency, nomor PO terisi; draft terbentuk |
| Flag aktif | Koreksi nilai sebelum simpan | Nilai tersimpan sesuai koreksi; PO tidak berubah |
| Flag aktif | PO batal / supplier tidak cocok / currency tidak cocok | Ditolak dengan pesan spesifik |
| Flag aktif | Satu PO ke beberapa invoice | Tidak membuat slip duplikat otomatis |
| Flag aktif | PO diubah setelah slip tersimpan | Nominal dan snapshot slip tidak berubah |
| Flag aktif | Slip EXIM | Tidak menampilkan atau menerima integrasi PO |

Setiap alur diuji pada flag aktif dan nonaktif.

## 8. Risiko dan mitigasi

| Risiko | Mitigasi |
| --- | --- |
| PO mengubah nominal slip secara tidak sengaja | PO hanya menghasilkan prefill; snapshot invoice/item menjadi data pembayaran setelah disimpan |
| Modul PO yang sudah divalidasi klien ikut tersentuh | Tidak ada berkas modul PO yang diubah sampai milestone aktivasi disetujui terpisah |
| Fitur hidden dapat dipanggil langsung | Feature flag diperiksa server-side bersama authorization, bukan hanya menyembunyikan tombol |
| Integrasi menarik kembali form General dan EXIM menjadi satu schema | Integrasi masuk lewat contract/service General, bukan kondisi baru pada schema bersama |
| Invoice parsial dianggap sudah didukung penuh | Dokumentasikan keterbatasan relasi invoice-level; item-level outstanding menjadi fase terpisah |
| PO sumber masih berubah karena transisi ECOUNT | Prasyarat bagian 2: status data PO dari transisi ECOUNT harus jelas sebelum mulai |

## 9. Definition of done

- Form dan service General dapat menerima konteks PO tanpa mengubah schema database.
- Feature flag default nonaktif, diperiksa server-side, dengan automated test untuk kedua kondisi.
- Tidak ada berkas modul PO yang berubah pada P0 dan P1.
- Contract integrasi terdokumentasi sehingga entry point dari sisi PO dapat ditambahkan kelak tanpa refactor ulang pada General Payment Slip.
- Tidak ada perhitungan outstanding per item yang diklaim selesai.
