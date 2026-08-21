**PRODUCT REQUIREMENTS DOCUMENT (PRD)**

**Nama Produk:** EXIM Payment Slip & Reimbursement Portal
**Versi:** 1.1 (Minimum Viable Product)
**Platform:** Aplikasi Web Internal berbasis Laravel Filament

---

### 1. Deskripsi Produk (Product Overview)

Sistem ini adalah portal internal berbasis web yang dirancang untuk mendigitalisasi dan menyederhanakan proses pengajuan pembayaran tagihan (*payment slip* / *reimbursement*) dari aktivitas Ekspor dan Impor. Sistem ini menggabungkan alur kerja manusia dengan ekstraksi AI eksternal (Hybrid AI) untuk meminimalkan beban input manual, memastikan keakuratan perhitungan pajak dalam Rupiah, dan menyediakan fitur persetujuan manajerial sebelum data diekspor ke sistem ERP pusat.

### 2. Tujuan & Sasaran (Objectives)

* **Efisiensi Waktu:** Memangkas waktu input data *invoice* menggunakan fitur unggah Excel hasil olahan AI.
* **Akurasi Pajak:** Mencegah kesalahan perhitungan pajak melalui penerapan Master Pajak yang terpusat.
* **Jejak Audit (Audit Trail):** Mengikat setiap baris transaksi dengan dokumen fisik (PDF) asli yang dapat ditinjau secara berdampingan (*split-screen*).
* **Integrasi Seamless:** Menghasilkan format *file* yang langsung kompatibel dengan sistem ERP yang digunakan kantor pusat (seperti Microsoft AX).

---

### 3. Target Pengguna & Hak Akses (User Roles)

Sistem menggunakan *Role-Based Access Control* (RBAC) dengan 3 aktor utama:

| Peran (Role) | Tanggung Jawab Utama | Akses Modul |
| --- | --- | --- |
| **Staff EXIM** (Maker) | Menginisiasi *Payment Slip*, mengunggah *file* Excel dari AI, dan melampirkan PDF asli. | Transaksi (Input), Riwayat Pengajuan. |
| **Staff Accounting** (Checker) | Memverifikasi angka, menentukan COA & Pajak, dan mengekspor data ke ERP. | Transaksi (Verifikasi), Master Data, Export. |
| **General Manager** (Approver) | Meninjau ringkasan tagihan secara *high-level* dan memberikan persetujuan atau penolakan. | Dashboard Approval. |

#### 3.1. Status Dokumen & Hak Edit

| Status | Deskripsi | Pengguna yang dapat melakukan tindakan |
| --- | --- | --- |
| **Draft** | Dokumen baru dibuat atau dikembalikan untuk diperbaiki setelah penolakan. | Staff EXIM dan Staff Accounting dapat mengubah data dan lampiran. |
| **Submitted** | Dokumen telah diajukan EXIM untuk diverifikasi. | Staff Accounting melakukan verifikasi. |
| **Pending Approval** | Data, COA, pajak, dan total telah diverifikasi Accounting. | General Manager melakukan *approve* atau *reject*. |
| **Approved** | Dokumen disetujui dan siap diekspor ke ERP. | Staff Accounting mengekspor dokumen. |
| **Exported** | Dokumen telah masuk ke berkas ekspor ERP. | Bersifat riwayat; perubahan memerlukan proses revisi sesuai kebijakan berikutnya. |

Saat General Manager menolak dokumen, sistem wajib menyimpan catatan penolakan pada riwayat dokumen lalu mengubah statusnya kembali menjadi **Draft**.

---

### 4. Fitur Utama & Kebutuhan Fungsional (Functional Requirements)

#### 4.1. Modul Manajemen Master Data

* **Kebutuhan:** Sistem harus memiliki antarmuka CRUD (*Create, Read, Update, Delete*) untuk entitas referensi.
* **Komponen:**

---

### 4. Fitur Utama & Kebutuhan Fungsional (Functional Requirements)

#### 4.1. Modul Manajemen Master Data

* **Kebutuhan:** Sistem harus memiliki antarmuka CRUD (*Create, Read, Update, Delete*) untuk entitas referensi.
* **Komponen:**
* **Master Supplier & Buyer:** Nama dan alamat.
* **Master COA (Chart of Accounts):** Kode dan nama akun buku besar.
* **Master Pajak:** Menyediakan pajak bawaan PPN dan PPh 23, serta memungkinkan Accounting menambahkan jenis pajak lain. Setiap pajak memiliki nama, persentase tarif, dan sifat (memotong/menambah nilai).



#### 4.2. Modul Pembuatan Payment Slip (Google Gemini API Ingestion)

* **Kebutuhan:** Mengotomatisasi ekstraksi data dari unggahan berkas PDF invoice langsung ke formulir di layar menggunakan Google Gemini API.
* **Komponen:**
  * **Pemilihan Metadata:** Tipe Transaksi (Impor/Ekspor), Supplier, dan Buyer (jika Impor). Seluruh nilai transaksi dicatat dalam IDR.
  * **Direct PDF Extractor:** Fitur unggah beberapa berkas PDF asli sekaligus. Saat pengguna menekan tombol "Extract Invoices via Gemini", sistem mengirimkan PDF tersebut beserta skema output JSON terstruktur ke Google Gemini API secara asinkron.
  * **Struktur Invoice dan Item:** Data hasil ekstraksi otomatis membuat entitas Invoice (lengkap dengan `No_Invoice`, `Tanggal_Invoice`) dan baris `Item` (nama item, qty, harga) dalam bentuk tabel input dinamis tanpa reload halaman.
  * **Pemetaan Dokumen Otomatis:** Sistem secara otomatis mengaitkan berkas PDF yang diunggah dengan entitas Invoice yang berhasil diekstrak.
  * **Validasi Format & Integritas:** Jika data yang diekstrak tidak lengkap atau tidak valid menurut skema, sistem akan menandainya agar dapat diperbaiki oleh pengguna secara manual di layar sebelum dikirim (*Submit*).



#### 4.3. Modul Manajemen Dokumen (PDF Mapping)

* **Kebutuhan:** Menyatukan data digital dengan bukti fisik *invoice*.
* **Komponen:**
* Fitur *Drag & Drop* untuk mengunggah *file* PDF pada masing-masing baris *Invoice* yang terbentuk dari Excel.
* Sistem memblokir pengiriman data (*Submit*) jika ada baris *Invoice* yang tidak memiliki lampiran PDF.



#### 4.4. Modul Verifikasi & Mesin Pajak (Split-Screen UI)

* **Kebutuhan:** Layar khusus untuk mempermudah tugas verifikasi dan kalkulasi pajak oleh divisi Akuntansi.
* **Komponen:**
* **Antarmuka Split-Screen:** Sebelah kiri menampilkan *Viewer* PDF interaktif, sebelah kanan menampilkan form rincian *item*.
* **Alokasi Pajak & COA:** Pilihan *dropdown* untuk menetapkan COA dan pajak per **Invoice** secara keseluruhan (dirangkum).
* **Kalkulasi Real-Time:** Sistem menghitung subtotal seluruh item di invoice, lalu menerapkan perhitungan pajak penambah (seperti PPN) dan pajak pemotong (seperti PPh 23) langsung pada subtotal invoice tersebut secara otomatis untuk menghasilkan *Grand Total* invoice. Jenis dan tarif pajak mengikuti Master Pajak.



#### 4.5. Modul Persetujuan (Approval Dashboard)

* **Kebutuhan:** Panel yang ringkas dan cepat bagi manajemen.
* **Komponen:**
* Menampilkan rincian *High-Level* (Nama Supplier, Tipe, Total Tagihan Bersih).
* Tombol **Approve** (Dokumen disetujui untuk diekspor).
* Tombol **Reject** (Wajib mengisi *field* Catatan Penolakan, status dokumen kembali ke *Draft*).



#### 4.6. Modul ERP Exporter

* **Kebutuhan:** Mengekstrak data yang disetujui menjadi *file* yang dapat dicerna oleh sistem pihak ketiga.
* **Komponen:**
* Filter pencarian berdasarkan periode tanggal persetujuan.
* *Dropdown* target format (contoh: Microsoft AX Template).
* Generator Excel (`.xlsx`) yang secara otomatis menyusun kolom-kolom khusus seperti *CurrencyCode* bernilai `IDR`, *AccountCode*, dan nominal Debit/Kredit. Kolom kurs tidak diperlukan pada MVP.



---

### 5. Alur Kerja Pengguna (User Journey)

1. **Unggah & Ekstraksi (Sistem):** Staff EXIM membuat *Payment Slip* baru, mengunggah seluruh berkas PDF Invoice asli, lalu mengeklik tombol ekstraksi. Sistem memanggil Google Gemini API untuk membaca data tagihan secara instan.
2. **Pembuatan & Penyesuaian (Sistem):** Data hasil ekstraksi langsung terisi di layar. Staff EXIM memeriksa keakuratan data, menyesuaikan jika ada kesalahan kecil, lalu mengirimkannya (*Submit*).
3. **Verifikasi (Sistem):** Staff Accounting membuka dokumen, melihat PDF bersandingan dengan data (*split-screen*), menetapkan COA, memilih beban pajak, dan memeriksa total transaksi dalam IDR.
4. **Persetujuan (Sistem):** General Manager mengecek ringkasan tagihan di *dashboard* dan menekan tombol *Approve*.
5. **Ekspor (Sistem):** Staff Accounting mengekspor kumpulan *Payment Slip* yang telah disetujui ke dalam format Excel standar ERP pusat.

---

### 6. Kebutuhan Non-Fungsional (Non-Functional Requirements)

* **Performa:** Proses impor data Excel (maksimal 50 baris per *upload*) dan *render* form dinamis harus selesai di bawah 3 detik.
* **Keamanan:** Dokumen PDF bersifat rahasia dan hanya dapat diakses melalui URL *signed* yang dikendalikan oleh sesi *login* pengguna yang sah (*middleware* otorisasi). File tidak boleh dapat diakses melalui pencarian publik.
* **Kompatibilitas:** Aplikasi dioptimalkan untuk *browser* *desktop* (Google Chrome, Microsoft Edge, Safari) resolusi standar (1080p) untuk memaksimalkan fitur *Split-Screen*.

---

### 7. Batasan Sistem (Out of Scope untuk Versi 1.0)

* Sistem tidak melakukan pembelajaran mandiri (*fine-tuning*) pada model AI lokal. Pengolahan PDF sepenuhnya didelegasikan ke Google Gemini API yang terkelola.
* Sistem tidak menyediakan fitur notifikasi *push* eksternal (Email, SMS, atau WhatsApp).
* API/Webhooks langsung ke dalam basis data sistem ERP. Proses integrasi sepenuhnya mengandalkan unggahan manual berbasis *file* Excel.

---

### 8. Batasan Teknis (Technical Constraints)

* **Framework aplikasi:** Laravel.
* **Fondasi antarmuka/admin panel:** Laravel Filament.
* **Interaktivitas halaman khusus:** Livewire dan Alpine.js, khususnya untuk integrasi PDF uploader dinamis, tombol pemanggilan API, dan tampilan *split-screen* PDF.
* **Integrasi AI:** Google Gemini API (`gemini-1.5-flash` atau `gemini-2.0-flash`) dengan Structured JSON Response.
* **Styling:** Tailwind CSS.
* **Basis data:** MySQL.
* **Pengolahan Excel:** Laravel Excel (`maatwebsite/excel`) (hanya digunakan untuk Ekspor ERP, bukan untuk impor).
* **Penyimpanan PDF:** *Private storage* dengan akses melalui otorisasi pengguna dan *signed URL*.

### 9. Keputusan yang Ditunda

* Struktur final template ERP (termasuk urutan kolom, aturan Debit/Kredit, dan format Microsoft AX) akan ditentukan setelah contoh template tervalidasi tersedia dari kantor pusat.
