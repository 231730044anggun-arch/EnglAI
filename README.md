# EnglAI — RPP-driven PHP application

Halaman game utama berada di `index.php`. Tampilan, animasi, timer, power-up, skor, leaderboard, dan pronunciation tetap dijalankan oleh JavaScript yang telah ada. Hanya sumber pembuatan materi dipindahkan dari browser ke backend PHP.

## Instalasi

1. Buat database dan tabel dengan mengimpor `database/englai.sql` ke MySQL.
2. Sesuaikan kredensial MySQL pada `config/koneksi.php`.
3. Instal library:

   ```bash
   composer install
   ```

4. Simpan API key Gemini sebagai environment variable server, bukan di berkas JavaScript atau PHP yang dilacak Git.

   PowerShell sesi saat ini:

   ```powershell
   $env:GEMINI_API_KEY = 'isi_api_key_anda'
   $env:GEMINI_MODEL = 'gemini-2.5-flash'
   ```

5. Jalankan lewat Apache/Laragon dengan document root proyek ini, lalu buka:
   - Game: `/index.php`
   - Guru/admin: `/admin/`

## Alur RPP

Guru mengunggah PDF/DOCX di halaman admin. Sistem mengekstrak teks dengan `smalot/pdfparser` atau `phpoffice/phpword`, menyimpan teks tersebut di MySQL, dan guru memilih satu RPP aktif. Saat game berjalan, browser meminta `api/generate_question.php`; backend mengambil hanya RPP aktif, memanggil Gemini, lalu mengembalikan JSON soal atau latihan pronunciation. API key tidak pernah dikirim ke browser.

Folder `uploads` tidak dilacak Git dan diberi aturan Apache untuk mencegah akses langsung ke dokumen RPP.
