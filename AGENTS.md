# Panduan Coding Agent — Exim Payment Portal

## Stack yang harus dipertahankan

- PHP 8.3+, Laravel 13.26.1, Livewire 4.4.1.
- **Filament 5.7.6** terpasang saat ini (`filament/filament` dikunci di `composer.lock`; constraint proyek adalah `^5.7`).
- Semua fitur admin baru harus memakai API dan dokumentasi **Filament v5**, bukan v4 atau versi sebelumnya.
- Jangan mengubah versi paket Filament, Laravel, Livewire, atau dependensi terkait tanpa permintaan eksplisit.

## Panel dan penemuan otomatis

- Panel admin memiliki ID `admin` dan path `/admin`.
- Konfigurasinya ada di `app/Providers/Filament/AdminPanelProvider.php`.
- Resource, page, dan widget ditemukan otomatis dari `app/Filament/Resources`, `app/Filament/Pages`, dan `app/Filament/Widgets`. Tidak perlu mendaftarkannya secara manual ke panel selama berada pada namespace dan folder yang benar.

## Konvensi Resource Filament 5 di proyek ini

Saat membuat resource baru, ikuti struktur yang sudah ada, misalnya `Suppliers`:

```text
app/Filament/Resources/<Plural>/ <Singular>Resource.php
app/Filament/Resources/<Plural>/Pages/
app/Filament/Resources/<Plural>/Schemas/<Singular>Form.php
app/Filament/Resources/<Plural>/Tables/<Plural>Table.php
```

- Resource mewarisi `Filament\Resources\Resource` dan mengimpor `Filament\Schemas\Schema`.
- Pisahkan definisi form ke `Schemas/<Singular>Form.php` melalui `public static function configure(Schema $schema): Schema`.
- Pisahkan definisi table ke `Tables/<Plural>Table.php` melalui `public static function configure(Table $table): Table`.
- Di resource, delegasikan dengan pola berikut:

```php
public static function form(Schema $schema): Schema
{
    return ExampleForm::configure($schema);
}

public static function table(Table $table): Table
{
    return ExamplesTable::configure($table);
}
```

- Page CRUD mewarisi kelas v5 yang sesuai: `ListRecords`, `CreateRecord`, `EditRecord`, dan bila diperlukan `ViewRecord`.
- Gunakan `Filament\Actions\CreateAction`, `EditAction`, `ViewAction`, `DeleteAction`, dan `DeleteBulkAction` untuk action. Jangan memakai namespace action lama dari versi Filament terdahulu.
- Gunakan `Filament\Support\Icons\Heroicon` untuk icon navigasi, seperti resource yang ada.

## API Filament 5 yang wajib digunakan

- Form/schema: gunakan `Filament\Schemas\Schema`, `->components([...])`, dan komponen dari `Filament\Forms\Components` atau `Filament\Schemas\Components` sesuai contoh yang ada.
- Table: gunakan `->recordActions([...])` untuk aksi per baris dan `->toolbarActions([...])` untuk aksi toolbar/bulk. Jangan menambahkan API tabel lama seperti `->actions()` atau `->bulkActions()`.
- Untuk state reaktif schema, gunakan utility v5 `Filament\Schemas\Components\Utilities\Get` dan `Set`, bukan utility/forms namespace dari versi lama.
- Gunakan full type declaration dan import yang sesuai dengan kelas Filament v5. Jangan menyalin snippet yang mengimpor API Filament v3/v4 tanpa memverifikasi kelasnya tersedia di `vendor/filament`.
- Untuk halaman Blade Filament kustom, gunakan komponen panel yang telah dipakai proyek, misalnya `<x-filament-panels::page>` dan `<x-filament-panels::form>`.

## Cara mengerjakan fitur admin baru

1. Periksa resource paling mirip di `app/Filament/Resources` dan ikuti pola, penamaan, serta style-nya.
2. Buat atau ubah migration/model terlebih dahulu bila data baru diperlukan; pastikan mass assignment/casts dan relasi Eloquent benar.
3. Implementasikan form, table, page, action, policy/authorization, dan validasi yang diperlukan menggunakan API Filament 5.
4. Untuk perubahan pada data pembayaran atau status, pertahankan aturan bisnis yang ada dan jangan mengandalkan tampilan admin sebagai satu-satunya validasi.
5. Jalankan formatter dan tes yang relevan sebelum selesai: `vendor/bin/pint --dirty` dan `php artisan test` (atau tes yang lebih spesifik bila tersedia).

## Izin kerja rutin dan non-destruktif

Untuk pekerjaan coding yang sudah diminta user, agent memiliki izin eksplisit untuk langsung melakukan operasi rutin dan non-destruktif di dalam repository ini tanpa meminta konfirmasi tambahan. Jangan menghentikan pekerjaan hanya untuk menanyakan izin atas langkah implementasi normal.

Operasi yang boleh langsung dilakukan meliputi:

- Membaca, mencari, dan memeriksa file proyek, source package lokal, konfigurasi, log aplikasi, serta struktur database/migration.
- Menjalankan pemeriksaan Git yang hanya membaca keadaan repository, termasuk `git status`, `git diff`, `git log`, `git show`, dan `git branch --show-current`.
- Membuat file baru dan memperbarui file yang relevan dengan scope tugas, termasuk source code, test, konfigurasi, dokumentasi, view, dan migration baru yang memang diperlukan oleh fitur.
- Menjalankan formatter, linter, static analysis, build, dan test lokal, termasuk `vendor/bin/pint --dirty`, `php artisan test`, serta perintah test yang lebih spesifik.
- Menjalankan perintah Laravel lokal yang bersifat diagnostik atau membersihkan cache yang dapat dibuat ulang, termasuk `php artisan about`, `route:list`, `config:clear`, `cache:clear`, `view:clear`, dan `optimize:clear`.
- Membuat file sementara di dalam workspace untuk inspeksi atau pengujian dan membersihkan kembali file sementara yang dibuat sendiri oleh agent.
- Memperbaiki error format, test, atau build yang disebabkan langsung oleh perubahan dalam scope tugas.

Tetap minta persetujuan sebelum tindakan yang destruktif, sulit dipulihkan, berdampak ke luar repository, atau memperluas scope secara material, termasuk:

- Menghapus atau menimpa data user, file sumber yang tidak terkait, database non-test, upload, atau artifact penting.
- Menjalankan `git reset`, `git clean`, rebase, force push, menghapus branch, membuang perubahan lokal, commit, atau push jika user belum memintanya.
- Menjalankan migration yang mengubah database berisi data nyata, seeding yang dapat menimpa data, atau query data massal yang mutatif. Membuat file migration dan menjalankannya pada database test tetap diperbolehkan.
- Mengubah `.env`, credential, secret, konfigurasi produksi, layanan eksternal, atau mengirim data ke pihak luar.
- Menambah, menghapus, atau menaikkan versi dependency tanpa kebutuhan yang jelas dalam permintaan user.

Jika platform atau sandbox tetap menampilkan permintaan approval, ikuti mekanisme keamanan platform. Namun, jangan meminta konfirmasi percakapan tambahan untuk operasi yang sudah diizinkan di atas.

## Larangan kompatibilitas

- Jangan menghasilkan kode Filament 3/4 yang mengandalkan `Filament\Forms\Form`, `Filament\Tables\Actions\*`, `->actions()`, atau `->bulkActions()`.
- Jangan mencampurkan struktur resource monolitik lama dengan struktur `Schemas` dan `Tables` yang sudah menjadi standar proyek ini.
- Jangan mengedit `AdminPanelProvider` hanya untuk mendaftarkan resource/page/widget yang sudah dapat ditemukan otomatis.
- Bila ada keraguan terhadap API, cek versi terpasang di `composer.lock`, implementasi resource proyek yang serupa, lalu source package di `vendor/filament` sebelum menulis kode.
