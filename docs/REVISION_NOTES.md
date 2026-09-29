# Catatan Revisi

## 2026-09-29 — Payment Slip General

- Menambahkan field **Keterangan Transaksi** khusus transaksi General. Field wajib diisi saat membuat atau mengubah draft dan ditampilkan pada PDF sebagai `CHARGE [KETERANGAN]`.
- Data General lama yang belum memiliki keterangan tetap ditampilkan sebagai `CHARGE LAIN-LAIN` agar kompatibel dengan data sebelumnya.
- Memisahkan quantity item dari kolom Detail menjadi kolom **Quantity** tersendiri pada tabel PDF Payment Slip General.
- Layout tabel PDF Import dan Export tidak diubah.
