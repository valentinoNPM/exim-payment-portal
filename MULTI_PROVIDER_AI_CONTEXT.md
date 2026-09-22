# Multi-Provider AI Invoice Extraction — Catatan Riset

> **Status:** Belum diimplementasikan. Eksperimen multi-provider sebelumnya di-rollback pada 21 September 2026 karena asumsi provider, model, fallback, dan throttling belum tervalidasi.

## Baseline yang Dipertahankan

- Invoice extraction tetap menggunakan `GeminiInvoiceExtractor` dengan Gemini.
- Model yang sudah berjalan baik di sistem adalah `gemini-3.5-flash-lite` melalui `GEMINI_API_MODEL`.
- Konfigurasi tetap berada di `config/services.php`.
- Retry Gemini yang sudah ada tetap dipertahankan untuk rate limit, server error, dan gangguan koneksi.
- Tidak ada provider baru yang aktif dan dokumen invoice tidak dikirim ke pihak ketiga tambahan.

## Masalah yang Sebenarnya Ingin Diselesaikan

Load harian relatif kecil: sekitar 5 pengguna, 5–10 payment slip per hari, dan 1–10 invoice per slip. Risiko utamanya bukan total pemakaian harian, melainkan beberapa upload yang berjalan bersamaan dan menghasilkan burst request dalam satu menit.

Karena itu, solusi pertama yang perlu diteliti adalah queue dan rate limiter yang atomic untuk Gemini, bukan langsung menambah banyak provider.

## Mengapa Eksperimen Sebelumnya Di-rollback

1. Provider dipilih tanpa matriks kemampuan yang tervalidasi. Groq dan Cerebras pada konfigurasi tersebut hanya text-based, sedangkan sebagian PDF membutuhkan pemrosesan multimodal/PDF.
2. Default `gemini-2.0-flash` sudah shut down, padahal aplikasi sudah berjalan dengan `gemini-3.5-flash-lite`.
3. Default `llama-3.3-70b-versatile` di Groq sudah dihentikan pada 16 Agustus 2026.
4. Default `llama-3.3-70b` di Cerebras sudah deprecated dan tidak lagi tercantum sebagai model publik aktif.
5. Fallback hanya menangani HTTP 429 dan 5xx. Error model tidak tersedia seperti 400/404 justru menghentikan seluruh rantai fallback.
6. Timeout dan connection error Laravel tidak tertangkap oleh handler `RuntimeException`, sehingga tidak memicu fallback.
7. Throttle berbasis `Cache::get()` lalu `usleep()` tidak atomic. Request bersamaan dapat bangun dan mengirim request pada waktu yang sama.
8. Prompt meminta top-level JSON array, sedangkan driver OpenAI-compatible meminta `response_format: json_object`. Kontrak output belum konsisten.
9. Test retry lama dihapus tanpa diganti test khusus manager, fallback, cooldown, timeout, concurrency, dan kontrak output setiap provider.
10. Menambah provider berarti data invoice dapat dikirim ke pihak ketiga lain. Hal ini memerlukan keputusan privasi dan tata kelola data secara eksplisit.

## Arah Implementasi yang Direkomendasikan

### Tahap 1 — Stabilkan Gemini

Gunakan Gemini 3.5 Flash-Lite sebagai provider tunggal dan tambahkan mekanisme berikut bila burst benar-benar terbukti menjadi masalah:

1. Proses extraction melalui Laravel queue.
2. Gunakan lock/rate limiter atomic yang berlaku lintas worker.
3. Batasi concurrency dan beri jarak request sesuai rate limit aktual model/project.
4. Retry untuk 429, 5xx, timeout, dan connection error dengan backoff serta jitter.
5. Berikan status processing yang jelas kepada pengguna.
6. Tambahkan observability: provider/model, durasi, jumlah retry, status HTTP, dan request ID tanpa mencatat isi invoice atau API key.

### Tahap 2 — Evaluasi Kebutuhan Fallback

Multi-provider hanya dilanjutkan jika data operasional menunjukkan Gemini masih menjadi titik kegagalan setelah queue/rate limiter diterapkan.

Pisahkan jalur berdasarkan kemampuan:

```text
PDF dengan teks lokal yang usable
  -> provider text yang telah diuji

PDF scan/image atau layout kompleks
  -> provider multimodal/PDF native yang telah diuji
```

Provider text-only tidak boleh dipakai untuk scan/image kecuali ada OCR lokal yang tervalidasi terlebih dahulu.

## Checklist Evaluasi Provider

Sebelum provider ditambahkan, verifikasi seluruh poin berikut dengan dokumentasi resmi dan integration test:

- Model ID masih aktif dan memiliki lifecycle yang memadai.
- Mendukung input text, image, dan/atau PDF sesuai jalur yang dituju.
- Mendukung ukuran PDF dan context window yang dibutuhkan.
- Dapat menghasilkan struktur JSON yang sama dengan kontrak aplikasi.
- Perilaku untuk 400, 401, 403, 404, 408, 422, 429, 5xx, timeout, dan connection error sudah ditentukan.
- Rate limit aktual sesuai tier akun, bukan hanya angka perkiraan.
- Kebijakan penyimpanan data, training data, lokasi pemrosesan, dan biaya telah disetujui.
- Akurasi telah diuji memakai sampel invoice nyata yang sudah dianonimkan.
- Ada test untuk text extraction, PDF multimodal, fallback, cooldown, concurrency, dan respons malformed.

## Kontrak Arsitektur Jika Multi-Provider Dilanjutkan

- Orchestrator bisnis tetap terpisah dari driver provider.
- Capability provider harus eksplisit, minimal `text`, `image`, `pdf`, dan `structured_output`.
- Pemilihan provider berdasarkan capability dan health, bukan hanya angka priority.
- Rate limiter harus atomic dan idealnya per provider/model.
- Fallback hanya dilakukan bila aman; error autentikasi atau konfigurasi harus terlihat jelas dan tidak disamarkan.
- Output provider dinormalisasi ke satu DTO/schema sebelum masuk ke validator bisnis.
- Provider baru nonaktif secara default sampai integration test dan persetujuan privasi selesai.

## Referensi yang Perlu Dicek Ulang Saat Implementasi

- Gemini models: https://ai.google.dev/gemini-api/docs/models
- Gemini deprecations: https://ai.google.dev/gemini-api/docs/deprecations
- Groq models: https://console.groq.com/docs/models
- Groq deprecations: https://console.groq.com/docs/deprecations
- OpenRouter PDF inputs dan structured outputs: https://openrouter.ai/docs
- Cerebras supported models: https://inference-docs.cerebras.ai/models/overview

Dokumentasi provider berubah cepat. Model dan limit harus selalu diperiksa kembali pada tanggal implementasi, bukan diasumsikan dari catatan ini.
