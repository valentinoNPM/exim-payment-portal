# Rancangan Database & ERD

**Produk:** EXIM Payment Slip & Reimbursement Portal  
**Versi:** 1.0  
**Database:** MySQL 8+

Dokumen ini menerjemahkan PRD ke struktur database untuk MVP. Semua nilai uang disimpan dalam **IDR**. Sistem tidak menyimpan kurs maupun konversi mata uang.

## 1. Prinsip Rancangan

* Satu **Payment Slip** adalah header pengajuan dan memiliki banyak **Invoice**.
* Satu **Invoice** berasal dari satu `No_Invoice` pada Excel dan memiliki satu atau lebih **Invoice Item**.
* Satu Invoice wajib memiliki tepat satu PDF saat dokumen disubmit. PDF boleh belum tersedia ketika status masih Draft.
* Pajak dan COA ditetapkan pada level Invoice Item.
* Nilai, pajak, dan total yang dipakai saat approval disimpan sebagai *snapshot* pada transaksi; perubahan master data tidak mengubah transaksi lama.
* Semua perubahan penting terhadap dokumen direkam dalam audit log.

## 2. ERD

```mermaid
erDiagram
    USERS ||--o{ PAYMENT_SLIPS : creates
    USERS ||--o{ PAYMENT_SLIPS : approves
    USERS ||--o{ PAYMENT_SLIP_AUDITS : performs
    USERS ||--o{ ERP_EXPORT_BATCHES : creates

    SUPPLIERS ||--o{ PAYMENT_SLIPS : supplies
    BUYERS o|--o{ PAYMENT_SLIPS : optional_for_import
    PAYMENT_SLIPS ||--|{ INVOICES : contains
    INVOICES ||--|{ INVOICE_ITEMS : contains
    INVOICES ||--o| DOCUMENT_FILES : has_pdf

    CHART_OF_ACCOUNTS ||--o{ INVOICES : assigned_to
    TAXES o|--o{ INVOICE_TAXES : defines
    INVOICES ||--o{ INVOICE_TAXES : has

    PAYMENT_SLIPS ||--o{ PAYMENT_SLIP_AUDITS : records
    ERP_EXPORT_BATCHES ||--o{ ERP_EXPORT_ITEMS : contains
    PAYMENT_SLIPS ||--o{ ERP_EXPORT_ITEMS : exported_in

    USERS {
        bigint id PK
        string name
        string email UK
        timestamp email_verified_at
        string password
        boolean is_active
        timestamps
    }

    SUPPLIERS {
        bigint id PK
        string code UK
        string name
        text address
        boolean is_active
        timestamps
    }

    BUYERS {
        bigint id PK
        string code UK
        string name
        text address
        boolean is_active
        timestamps
    }

    CHART_OF_ACCOUNTS {
        bigint id PK
        string code UK
        string name
        boolean is_active
        timestamps
    }

    TAXES {
        bigint id PK
        string code UK
        string name
        decimal rate
        enum calculation_type
        boolean is_active
        timestamps
    }

    PAYMENT_SLIPS {
        bigint id PK
        string slip_number UK
        enum transaction_type
        bigint supplier_id FK
        bigint buyer_id FK
        enum status
        decimal subtotal_amount
        decimal tax_addition_amount
        decimal tax_deduction_amount
        decimal grand_total_amount
        bigint created_by FK
        bigint approved_by FK
        timestamp submitted_at
        timestamp verified_at
        timestamp approved_at
        timestamps
    }

    INVOICES {
        bigint id PK
        bigint payment_slip_id FK
        string invoice_number
        date invoice_date
        decimal subtotal_amount
        decimal tax_addition_amount
        decimal tax_deduction_amount
        decimal grand_total_amount
        bigint document_file_id FK
        bigint coa_id FK
        string coa_code_snapshot
        string coa_name_snapshot
        timestamps
    }

    INVOICE_ITEMS {
        bigint id PK
        bigint invoice_id FK
        unsignedInteger line_number
        string item_name
        decimal quantity
        decimal unit_price_amount
        decimal subtotal_amount
        timestamps
    }

    INVOICE_TAXES {
        bigint id PK
        bigint invoice_id FK
        bigint tax_id FK
        string tax_code_snapshot
        string tax_name_snapshot
        decimal rate_snapshot
        enum calculation_type_snapshot
        decimal taxable_amount
        decimal tax_amount
        timestamps
    }

    DOCUMENT_FILES {
        bigint id PK
        string disk
        string path UK
        string original_name
        string mime_type
        unsignedBigInteger size_bytes
        string checksum
        timestamp uploaded_at
        timestamps
    }

    PAYMENT_SLIP_AUDITS {
        bigint id PK
        bigint payment_slip_id FK
        bigint user_id FK
        string event
        json old_values
        json new_values
        text notes
        timestamp created_at
    }

    ERP_EXPORT_BATCHES {
        bigint id PK
        string batch_number UK
        string format_code
        date approval_date_from
        date approval_date_to
        string file_path
        bigint created_by FK
        timestamp exported_at
        timestamps
    }

    ERP_EXPORT_ITEMS {
        bigint id PK
        bigint erp_export_batch_id FK
        bigint payment_slip_id FK
        timestamp created_at
    }
```

> Implementasi role dan permission dianjurkan memakai `spatie/laravel-permission`. Tabel `roles`, `permissions`, `model_has_roles`, dan tabel relasinya dibuat oleh package tersebut sehingga tidak ditampilkan dalam ERD utama.

## 3. Detail Tabel

### 3.1. Identitas dan Master Data

| Tabel | Kolom utama | Catatan |
| --- | --- | --- |
| `users` | `name`, `email`, `password`, `is_active` | Pengguna internal. Role: `maker`, `checker`, `approver`. |
| `suppliers` | `code`, `name`, `address`, `is_active` | Supplier dipilih pada header Payment Slip. |
| `buyers` | `code`, `name`, `address`, `is_active` | Wajib untuk transaksi impor; opsional untuk ekspor. |
| `chart_of_accounts` | `code`, `name`, `is_active` | Referensi COA pada item. Jangan hapus data yang pernah dipakai; nonaktifkan saja. |
| `taxes` | `code`, `name`, `rate`, `calculation_type`, `is_active` | Pajak awal: PPN dan PPh 23. `calculation_type`: `addition` atau `deduction`. |

### 3.2. Transaksi

#### `payment_slips`

| Kolom | Tipe | Aturan |
| --- | --- | --- |
| `slip_number` | `varchar(30)` | Nomor unik, mis. `PS-202608-0001`. Dibuat sistem saat Draft pertama dibuat. |
| `transaction_type` | `enum('import','export')` | Wajib. |
| `supplier_id` | FK | Wajib. |
| `buyer_id` | FK nullable | Wajib bila `transaction_type = import`. |
| `status` | enum | `draft`, `submitted`, `pending_approval`, `approved`, `exported`. |
| `subtotal_amount` | `decimal(18,2)` | Total subtotal seluruh item dalam IDR. |
| `tax_addition_amount` | `decimal(18,2)` | Akumulasi pajak penambah. |
| `tax_deduction_amount` | `decimal(18,2)` | Akumulasi pajak pemotong. |
| `grand_total_amount` | `decimal(18,2)` | `subtotal + tax_addition - tax_deduction`. |
| `created_by` | FK `users` | Maker yang membuat Draft. |
| `approved_by` | FK `users`, nullable | GM yang menyetujui. |
| `submitted_at`, `verified_at`, `approved_at` | timestamp nullable | Penanda tahapan proses. |

#### `invoices`

| Kolom | Tipe | Aturan |
| --- | --- | --- |
| `payment_slip_id` | FK | Wajib. |
| `invoice_number` | `varchar(100)` | Unik dalam satu Payment Slip. Sumber pengelompokan baris Excel. |
| `invoice_date` | `date` | Wajib. |
| `document_file_id` | FK nullable | Wajib sebelum Submit. Mengarah ke PDF invoice. |
| kolom total | `decimal(18,2)` | Rekap dari seluruh item dan pajaknya. |

#### `invoice_items`

| Kolom | Tipe | Aturan |
| --- | --- | --- |
| `invoice_id` | FK | Wajib. |
| `line_number` | unsigned integer | Urutan item dalam invoice. |
| `item_name` | `varchar(255)` | Dari `Nama_Item` Excel. |
| `quantity` | `decimal(18,4)` | Dari `Qty` Excel; harus lebih dari 0. |
| `unit_price_amount` | `decimal(18,2)` | Dari `Harga_Satuan` Excel, dalam IDR. |
| `subtotal_amount` | `decimal(18,2)` | `quantity × unit_price_amount`. |
| `coa_id` | FK nullable | Wajib sebelum Accounting mengajukan ke GM. |
| `coa_*_snapshot` | string nullable | Nilai COA saat ditetapkan. |
| `tax_addition_amount`, `tax_deduction_amount`, `net_amount` | decimal | Nilai hasil kalkulasi. |

#### `invoice_item_taxes`

Tabel ini membuat satu item dapat memiliki lebih dari satu pajak, misalnya PPN dan PPh 23 sekaligus.

| Kolom | Tipe | Aturan |
| --- | --- | --- |
| `invoice_item_id` | FK | Wajib. |
| `tax_id` | FK | Referensi pajak aktif pada saat dipilih. |
| `*_snapshot` | string/decimal/enum | Nama, kode, tarif, dan sifat pajak saat transaksi ditetapkan. |
| `taxable_amount` | `decimal(18,2)` | Dasar pengenaan pajak. |
| `tax_amount` | `decimal(18,2)` | Nilai pajak terhitung. |

### 3.3. Dokumen, Audit, dan Ekspor

| Tabel | Tujuan | Aturan utama |
| --- | --- | --- |
| `document_files` | Metadata file PDF di private storage. | Simpan path, nama asli, MIME type, ukuran, dan checksum. Satu invoice memilih satu file PDF. |
| `payment_slip_audits` | Jejak perubahan. | Catat pembuatan, impor Excel, unggah/pemetaan PDF, perubahan data, submit, pengajuan ke GM, approval, penolakan, dan ekspor. `old_values`/`new_values` berbentuk JSON. |
| `erp_export_batches` | Header satu kali ekspor ERP. | Simpan periode approval, format, file hasil, pembuat, dan waktu ekspor. |
| `erp_export_items` | Isi batch ekspor. | Menghubungkan Payment Slip ke batch; mencegah suatu slip diekspor dua kali dalam batch yang berbeda. |

## 4. Aturan Integritas dan Validasi Database

1. Tambahkan unique index pada `payment_slips.slip_number`, `suppliers.code`, `buyers.code`, `chart_of_accounts.code`, dan `taxes.code`.
2. Tambahkan unique composite index pada `invoices(payment_slip_id, invoice_number)` dan `invoice_items(invoice_id, line_number)`.
3. Tambahkan unique composite index pada `invoice_item_taxes(invoice_item_id, tax_id)`.
4. Tambahkan unique index pada `erp_export_items(payment_slip_id)` agar satu Payment Slip tidak diekspor lebih dari sekali pada MVP.
5. Gunakan foreign key dengan `restrict` untuk master data yang sudah dipakai transaksi; gunakan `cascade` hanya untuk detail transaksi bila Payment Slip Draft dihapus.
6. Validasi aplikasi harus memastikan Buyer ada untuk transaksi impor, seluruh Invoice memiliki PDF saat Submit, dan setiap item memiliki COA sebelum status `pending_approval`.
7. Nilai total dihitung ulang di backend saat penyimpanan/submit; jangan mengandalkan hasil kalkulasi dari browser saja.

## 5. Alur Status di Database

```text
draft ──submit Maker──> submitted ──verifikasi Accounting──> pending_approval
  ^                                                           │
  └────────── reject GM + catatan ────────────────────────────┘
                                                              │
                                      approve GM               v
                              pending_approval ─────────> approved ──export──> exported
```

Penolakan tidak memerlukan status `rejected` permanen: catatan penolakan disimpan di `payment_slip_audits`, lalu status kembali ke `draft` agar EXIM atau Accounting dapat memperbaiki data.

## 6. Mapping dari Excel dan PDF

1. Sistem membaca satu file Excel yang berisi `No_Invoice`, `Tanggal_Invoice`, `Nama_Item`, `Qty`, dan `Harga_Satuan`.
2. Baris dengan `No_Invoice` yang sama dibuat sebagai satu record `invoices` dan banyak record `invoice_items`.
3. Maker mengunggah banyak PDF. Masing-masing disimpan terlebih dahulu di `document_files`.
4. Sistem mencoba memasangkan PDF dari nama file yang memuat nomor invoice. Maker dapat memilih ulang secara manual.
5. Saat Submit, setiap `invoices.document_file_id` harus terisi dan file terkait harus bertipe PDF.
