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

## Larangan kompatibilitas

- Jangan menghasilkan kode Filament 3/4 yang mengandalkan `Filament\Forms\Form`, `Filament\Tables\Actions\*`, `->actions()`, atau `->bulkActions()`.
- Jangan mencampurkan struktur resource monolitik lama dengan struktur `Schemas` dan `Tables` yang sudah menjadi standar proyek ini.
- Jangan mengedit `AdminPanelProvider` hanya untuk mendaftarkan resource/page/widget yang sudah dapat ditemukan otomatis.
- Bila ada keraguan terhadap API, cek versi terpasang di `composer.lock`, implementasi resource proyek yang serupa, lalu source package di `vendor/filament` sebelum menulis kode.
