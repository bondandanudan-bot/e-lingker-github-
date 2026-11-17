# Persiapan upload publik E-LINGKER (Zenhosta/cPanel)

Paket ini memakai PHP + MySQL di hosting. Login, akun, catatan pengukuran, area, dan tempat master disimpan bersama di database. Admin membuat akun Paramedis dari aplikasi; Paramedis hanya menginput catatan baru dan mengekspor hasil. Semua perubahan master dan catatan tersedia lintas perangkat.

## 1. Siapkan hosting dan database

1. Pastikan paket hosting mengaktifkan PHP 8.0+ dengan ekstensi PDO MySQL, MariaDB/MySQL, dan Apache `mod_rewrite`/`mod_headers`.
2. Aktifkan SSL/HTTPS untuk domain melalui cPanel. Jangan publikasikan aplikasi melalui HTTP.
3. Buat satu database MySQL dan satu user database di cPanel. Berikan user hanya hak akses ke database aplikasi.
4. Buka phpMyAdmin, pilih database baru, lalu impor `master-schema.sql`. Jangan impor file tersebut dua kali.

## 2. Pasang konfigurasi privat

1. Buat folder `lingker-private` satu tingkat di atas `public_html` (contoh: `/home/USER/lingker-private`).
2. Salin `master-config.example.php` ke folder itu dengan nama `master-config.php`.
3. Isi `db_dsn`, `db_user`, dan `db_password` dengan detail database cPanel. Contoh DSN: `mysql:host=localhost;dbname=USER_NAMA;charset=utf8mb4`.
4. Buat kunci setup acak minimal 64 karakter. Cara membuatnya di terminal hosting:

   ```sh
   php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
   ```

   Masukkan hasilnya ke `setup_key`. Simpan kunci ini privat; masukkan sekali di layar setup aplikasi saat membuat Admin pertama.
5. Pastikan konfigurasi hanya dapat dibaca pemilik akun hosting. Kode API mencari file di `../lingker-private/master-config.php` relatif terhadap folder `public_html`.

## 3. Unggah file aplikasi

Unggah **hanya** file berikut ke `public_html`:

- `aplikasi_lingker_index_html.html`, lalu ubah namanya menjadi `index.html`
- `app-api.php`
- `.htaccess`
- `logo_e-lingker.png`
- `logo_amm.png`

Jangan unggah `master-config.php`, `master-config.example.php`, `master-schema.sql`, atau file dokumentasi ke folder publik. File model ergonomi dan pustaka pihak ketiga dimuat dari CDN sehingga perangkat pengguna memerlukan akses internet.

## 4. Buat Admin dan akun Paramedis

1. Buka domain melalui HTTPS. Layar setup pertama meminta kunci setup privat, username Admin, dan password kuat (minimal 12 karakter).
2. Setelah Admin pertama dibuat, setup publik otomatis terkunci. Login berikutnya memakai akun database server.
3. Dari akun Admin, buka menu Master Area & Tempat untuk membuat akun Paramedis. Password disimpan sebagai hash di database.
4. Uji dari jendela privat/perangkat lain: login Paramedis, input satu catatan uji, pastikan Admin dapat melihatnya, lalu hapus catatan uji. Perubahan master disinkronkan paling lama sekitar 15 detik.

## Keamanan dan batasan

- `.htaccess` memaksa HTTPS, menonaktifkan daftar folder, serta menolak akses web ke berkas konfigurasi contoh, SQL, dan dokumentasi.
- Jangan menaruh database password atau kunci setup dalam HTML/JavaScript dan jangan mengirimkannya melalui chat.
- Pemulihan username/password tanpa login hanya tersedia dalam mode file lokal. Reset mengganti seluruh akun yang tersimpan di browser dengan Admin baru, tetapi tidak menghapus catatan atau data master. Siapa pun yang dapat membuka aplikasi di browser tersebut dapat melakukan reset; pemulihan ini tidak berlaku untuk akun server.
- Jika migrasi dari mode lokal, data yang sudah tersimpan di browser **tidak otomatis diimpor** ke database. Ekspor/cadangkan dulu data lama sebelum beralih.
- Versi PHP atau aturan Apache di paket hosting bisa berbeda. Uji login dan simpan data setelah pemasangan; jika API melaporkan kesalahan server, periksa cPanel Error Log dan pastikan path konfigurasi benar.
- File `.htaccess` pada sebagian paket mungkin perlu disesuaikan jika aturan rewrite tidak diizinkan. SSL dan HTTPS tetap wajib.
