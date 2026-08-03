# EnglAI — RPP-driven PHP application

Halaman game utama berada di `index.php`. Tampilan, animasi, timer, power-up, skor, leaderboard, dan pronunciation tetap dijalankan oleh JavaScript yang telah ada. Hanya sumber pembuatan materi dipindahkan dari browser ke backend PHP.

## Instalasi

1. Buat database dan tabel dengan mengimpor `database/englai.sql` ke MySQL.
2. Salin `.env.example` menjadi `.env`, lalu isi konfigurasi database, Gemini, dan login admin.
3. Instal library:

   ```bash
   composer install
   ```

4. Simpan API key Gemini sebagai environment variable server, bukan di berkas JavaScript atau PHP yang dilacak Git.

   PowerShell sesi saat ini:

   ```powershell
   $env:GEMINI_API_KEY = 'isi_api_key_anda'
   $env:GEMINI_MODEL = 'gemini-3.5-flash'
   ```

5. Jalankan migration dan test:

   ```powershell
   php scripts/migrate.php
   composer test
   composer lint
   ```

6. Jalankan lewat Apache/Laragon dengan document root proyek ini, lalu buka:
   - Game: `/index.php`
   - Guru/admin: `/admin/`

Detail Phase 0 dan cara membuat password hash admin tersedia di `docs/PHASE0.md`.
Hasil verification dan limitation terkini tersedia di `docs/PHASE0_VERIFICATION.md`.

## Competition MVP

Entry point `/index.php` sekarang adalah landing classroom; prototype lama tetap
tersedia di `/public_demo.php`. Alur utama: login guru, buat classroom, upload RPP,
generate dua content bank, siswa join dengan Classroom ID, Self Learning, lalu
Live Quiz dengan lobby dan leaderboard.

```powershell
composer install
php scripts\migrate.php
php -S 127.0.0.1:8000 router.php
```

Reading multiple-choice adalah skill MVP. Listening, Speaking, dan Writing sengaja
ditandai Coming Soon.

Skor Live Quiz untuk jawaban salah/tidak menjawab adalah `0`. Jawaban benar:

```text
1000 + round(500 × remaining milliseconds / 20000)
```

Deadline, response time, dan skor menggunakan waktu server/database. Tie-break:
total score, correct answers, average response time terendah, display name
alfabetis, lalu participant ID. Polling berjalan setiap 1,5 detik dan pertanyaan
disimpan sebagai snapshot agar generation berikutnya tidak mengubah sesi aktif.

Verification MVP:

```powershell
composer lint
composer test
node tests\unit\game_core_test.js
php tests\integration\mvp_services.php
powershell -ExecutionPolicy Bypass -File tests\integration\mvp_http.ps1
```

## Alur RPP

Guru login ke halaman admin, lalu mengunggah PDF/DOCX. Sistem memvalidasi extension, MIME, dan struktur file; mengekstrak serta membersihkan teks; menyimpannya di MySQL; lalu guru memilih satu RPP aktif. Saat game berjalan, browser meminta `api/generate_question.php`; backend mengambil RPP aktif dan memanggil Gemini. Jika AI tidak tersedia, game menggunakan materi fallback lokal. API key tidak pernah dikirim ke browser.

Folder `uploads` tidak dilacak Git dan diberi aturan Apache untuk mencegah akses langsung ke dokumen RPP.

## Full Product Phase 2

Self Learning Phase 2 menyimpan modules dan activities per Classroom, skill, dan
level. Teacher menjalankan AI Analysis, mengonfirmasi default level, lalu
melakukan generation Reading, Listening, Speaking, atau Writing pada level yang
dipilih. Tanpa Gemini key, schema-valid fallback content dan assessment digunakan.

Progress dihitung dari completed attempts. Rekomendasi memprioritaskan activity
yang belum selesai dan skill dengan average score terendah. Threshold rekomendasi
naik level adalah average `80%` setelah aktivitas level tersebut selesai
(`ProgressService::ADVANCE_THRESHOLD`).

Listening memakai browser Speech Synthesis dan label “Generated Listening
Audio”. Speaking adalah “AI Speaking Feedback” berbasis transcript, bukan
pronunciation assessment. Writing draft disimpan lokal per attempt dan
submission hanya dinilai melalui backend.
