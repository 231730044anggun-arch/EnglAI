# EnglAI Phase 0

Phase 0 mempertahankan native PHP, MySQL, tampilan game, upload RPP, dan integrasi Gemini existing.

## Setup

1. Salin `.env.example` menjadi `.env`.
2. Isi koneksi database, `GEMINI_API_KEY`, dan model.
3. Buat password hash admin:

   ```powershell
   php -r "echo password_hash('ganti-password-ini', PASSWORD_DEFAULT), PHP_EOL;"
   ```

4. Masukkan hasilnya ke `ADMIN_PASSWORD_HASH`.
5. Jalankan:

   ```powershell
   composer install
   composer dump-autoload
   php scripts/migrate.php
   composer test
   composer lint
   php artisan serve
   ```

## Security baseline

- Admin RPP memerlukan login.
- Semua mutation admin menggunakan CSRF token.
- Session cookie menggunakan HttpOnly dan SameSite=Lax.
- Upload diperiksa berdasarkan extension, MIME, size, dan struktur DOCX.
- Output AI dan input nama kelompok di-escape sebelum masuk HTML.
- Endpoint AI memiliki rate limit lokal, timeout, retry terbatas, dan error aman.
- API key tetap server-side.

## Batas Phase 0

Phase ini belum menambahkan classroom, user/student domain, content bank, atau multiplayer. `rpps.is_selected` masih dipertahankan agar prototype kompatibel sampai migrasi Phase 1–2.

## Scoring prototype

Quiz objective:

```text
correct = false → 0
correct = true  → difficulty base + speed bonus + streak bonus

difficulty base: easy 100, medium 150, hard 200
speed bonus: round((server-independent browser time left / 30) × 50)
streak bonus: (min(streak, 5) - 1) × 25
```

Skip bernilai 0. Satu ronde hanya dapat diselesaikan satu kali melalui round guard, sehingga double submission dan race antara jawaban/timer ditolak.

Speaking memakai kecocokan transcript yang dinormalisasi untuk kapitalisasi, punctuation, Unicode, dan whitespace. Skor 0–100 dikalikan menjadi poin `round(score × 1.8)`. Nilai transcript kosong adalah 0 dan tidak ada minimum 30 poin. `correct` hanya bertambah jika similarity minimal 70.

Tie-break leaderboard:

1. total score lebih tinggi;
2. correct answers lebih banyak;
3. average response time lebih cepat jika tersedia;
4. nama secara alfabetis;
5. urutan awal sebagai fallback terakhir.

## Release

`release/exclude.txt` adalah manifest pengecualian. Setelah seluruh verification test lulus, release dapat dibuat di luar document root:

```powershell
powershell -ExecutionPolicy Bypass -File scripts/build_release.ps1
```

Script tidak memasukkan `.git`, `.agents`, `.env`, `vendor`, runtime storage, upload privat, SQL dump baseline yang berisi RPP, backup, atau script asing. Release ZIP belum dibuat pada Phase 0 Verification.
