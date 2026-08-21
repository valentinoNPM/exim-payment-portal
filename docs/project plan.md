Berikut adalah dokumentasi detail dan komprehensif dari seluruh perencanaan sistem yang telah kita diskusikan. Dokumen ini dapat Anda jadikan sebagai pegangan resmi (SOP/PRD) sebelum memulai proses *coding* di Laravel.

---

# DOKUMENTASI PERENCANAAN SISTEM

**Nama Aplikasi:** EXIM Payment Slip & Reimbursement Portal
**Tujuan:** Mendigitalisasi pengajuan pembayaran *invoice* dari *supplier* (Ekspor/Impor), perhitungan pajak dalam Rupiah, persetujuan manajerial, dan pengeksporan data ke sistem ERP pusat.
**Tech Stack Utama:** Laravel, Livewire / Alpine.js (untuk reaktivitas UI), MySQL, `maatwebsite/excel`.

---

### 1. Hak Akses Pengguna (Role-Based Access Control)

Sistem menggunakan 3 tingkatan otorisasi:

* **Maker (Staf EXIM):** Pihak yang menginisiasi pengajuan, melakukan *upload* data mentah dari AI, dan melampirkan bukti fisik (PDF).
* **Checker (Staf Accounting):** Pihak yang melakukan verifikasi data, menetapkan kode pembukuan (COA), menghitung pajak (PPh/PPN), dan mengekspor data ke ERP.
* **Approver (General Manager):** Pihak yang meninjau total tagihan secara *high-level* dan berhak memberikan persetujuan akhir (*Approve*) atau menolak dokumen (*Reject*).

---

### 2. Alur Proses Bisnis Terpadu (Workflow)

Alur kerja dirancang menggunakan pendekatan **Hybrid AI** untuk menekan biaya server namun tetap mendapatkan efisiensi maksimal.

#### Tahap 1: Pra-Sistem (Ekstraksi AI Eksternal)

1. Staf EXIM menerima 5 hingga 20 *file* PDF *Invoice* dari satu *Supplier*.
2. Staf EXIM mengunggah PDF tersebut ke model AI (Claude) menggunakan *prompt* standar.
3. AI mengekstrak data *invoice* dan menghasilkan tabel dengan format kolom baku (No_Invoice, Tanggal_Invoice, Nama_Item, Qty, Harga_Satuan).
4. Staf EXIM menyalin hasil tabel tersebut ke dalam *file* Excel kosong (Template Baku).

#### Tahap 2: Inisialisasi & Input (Divisi EXIM)

1. Staf EXIM *login* ke aplikasi dan menekan **"Buat Payment Slip Baru"**.
2. Memilih Jenis Transaksi (**Ekspor** atau **Impor**). Jika **Impor** dipilih, form tambahan untuk menginput data **Buyer** akan muncul.
3. Memilih nama **Supplier** (semua transaksi menggunakan Rupiah/IDR).
4. **Data Ingestion:** Staf EXIM mengunggah *file* Excel hasil olahan Claude. Sistem Laravel (`maatwebsite/excel`) akan membaca baris Excel tersebut dan secara dinamis membuat form daftar *Invoice* beserta rincian *Item*-nya di layar.
5. **Pemetaan Dokumen:** Di sebelah setiap baris *Invoice* yang terbentuk, staf EXIM mengunggah *file* PDF asli milik *invoice* tersebut (*Drag & Drop*).
6. Menekan **Submit** untuk meneruskan dokumen ke Akunting.

#### Tahap 3: Verifikasi & Pajak (Divisi Accounting)

1. Staf Accounting membuka *Payment Slip* berstatus *Submitted*.
2. **Split-Screen UI:** Layar terbagi dua; sebelah kiri menampilkan *PDF Viewer* interaktif, sebelah kanan menampilkan rincian data. Jika staf mengklik baris "Invoice B", PDF yang tampil di kiri otomatis berubah menjadi PDF Invoice B.
3. **Perhitungan Otomatis:** Sistem secara otomatis menghitung nilai subtotal, potongan pajak, dan total akhir seluruh invoice dalam satuan Rupiah (IDR).
4. **Alokasi Pembukuan:** Staf Accounting memilih **COA** (Akun Biaya) dan menambahkan potongan **Pajak** (PPh/PPN) per **Invoice** (dirangkum).
5. Menekan **Submit to GM** setelah total bersih (*Grand Total*) didapatkan.

#### Tahap 4: Persetujuan (General Manager)

1. GM membuka *Dashboard Approval*. Tampilan didesain *High-Level* (hanya menampilkan Nama Supplier, Total Bersih, Tipe Transaksi, dan Daftar PDF pendukung).
2. GM mengeklik **Approve** (Dokumen siap diekspor) atau **Reject**.
3. Jika *Reject*, sistem mewajibkan GM mengisi **Catatan Revisi**, lalu dokumen dikembalikan ke divisi terkait.

#### Tahap 5: Integrasi ERP (Divisi Accounting)

1. Staf Accounting memilih menu **Export Data**.
2. Memfilter rentang tanggal dokumen yang berstatus *Approved*.
3. Memilih **Format Template ERP** dari *dropdown* (misal: Microsoft AX, Format Lokal, dll).
4. Mengunduh *file* Excel dengan susunan kolom (*CurrencyCode* bernilai IDR, *AccountCode*, Debit/Kredit) yang siap di-*import* oleh kantor pusat Korea.

---

### 3. Logika Sistem Khusus & Master Data

Agar proses manual terminimalisir, sistem membutuhkan beberapa Modul Master Data:

* **Master COA & Pajak:** Menyimpan daftar akun dan aturan pajak (apakah menambah nilai seperti PPN, atau memotong nilai seperti PPh 23).
* **Semua dalam Rupiah (IDR):** Sistem dirancang khusus untuk mencatat dan memproses seluruh dokumen tagihan dalam Rupiah (IDR). Tidak ada modul pertukaran kurs mata uang asing atau pengelolaan kurs eksternal.

#### 3.1. Logika Ekstraksi AI & Pemetaan Dokumen (Gemini API)

Untuk memastikan keakuratan pembacaan berkas PDF invoice asli dari supplier oleh Gemini API, berikut adalah aturan dan logika pemrosesan yang didefinisikan secara resmi:

* **Penyaringan Halaman (Targeting Invoice Page):**
  * Gemini API diinstruksikan untuk memproses data dari halaman yang memuat tulisan "INVOICE" utama (seperti halaman 1 atau 2), serta mengabaikan halaman lampiran logistik pengiriman (*Sea Waybill*, *Forwarder's Cargo Receipt*, dll).
* **Ekstraksi Data Header:**
  * **Nomor Invoice (`invoice_number`):** Diekstrak dari label kotak `INVOICE NUMBER` (contoh: `E832772154`).
  * **Tanggal Invoice (`invoice_date`):** Diekstrak dari kotak label `INVOICE DATE` dan otomatis dikonversi dari format asal `DD/MM/YY` menjadi format standar database `YYYY-MM-DD` (contoh: `03/08/26` ➔ `2026-08-03`).
* **Ekstraksi Rincian Barang/Biaya (`invoice_items`):**
  * Mengambil semua deskripsi baris item biaya (seperti `CARRIER BL FEE`, `OM DOCUMENT ASSEMBLY`, `OM CFS CHARGES`, `VGM FEE`).
  * Setiap item di-set dengan **Quantity = 1** (angka `11` di samping item pada berkas Expeditors adalah referensi kategori PPN 11%, bukan kuantitas barang) dan **Price** diambil dari nilai nominal rupiahnya masing-masing.
  * Baris kalkulasi total seperti `SUB TOTAL`, `VAT` (PPN), dan `INVOICE TOTAL` **wajib diabaikan** dari daftar item agar tidak merusak perhitungan database.
* **Pemetaan Dokumen:**
  * Sistem secara otomatis menyimpan berkas PDF asli ke dalam tabel `document_files` dan menautkan `document_file_id` ke entitas `invoices` hasil ekstraksi yang bersangkutan secara dinamis di layar.

---

### 4. Arsitektur Basis Data Dasar (ERD)

Struktur tabel dipecah menjadi relasi *One-to-Many* secara berjenjang untuk mengakomodasi 1 *Payment Slip* yang berisi banyak *Invoice*:

| Nama Tabel | Deskripsi & Kolom Utama |
| --- | --- |
| `payment_slips` | **Header Transaksi:** `id`, `transaction_type` (import/export), `supplier_id`, `buyer_id`, `status`, `total_amount`, `ai_excel_file_path`, `created_by`, `approved_by`. |
| `invoices` | **Detail Dokumen:** `id`, `payment_slip_id`, `invoice_number`, `invoice_date`, `pdf_file_path` (lokasi file fisik di server), `coa_id`. |
| `invoice_items` | **Detail Rincian Barang:** `id`, `invoice_id`, `item_name`, `qty`, `original_price`. |
| `invoice_taxes` | **Detail Pajak Invoice:** `id`, `invoice_id`, `tax_id`, snapshots. |

Apakah Anda ingin kita mulai memecah dokumentasi ini menjadi spesifikasi teknis penulisan kode, misalnya dengan merancang *file* **Migration Laravel** beserta relasi antar modelnya?