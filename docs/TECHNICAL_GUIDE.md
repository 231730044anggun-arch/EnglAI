# Technical Guide

EnglAI 1.0.0 uses native PHP 8.1+, MySQL 8, Composer autoloading, server sessions, prepared PDO queries, migration scripts, HTTP polling, and backend-only Gemini integration.

## Local / Laragon

1. Point the virtual host document root to the project directory.
2. Copy `.env.example` to `.env`, configure MySQL, and run `composer install`.
3. Run `php scripts/migrate.php`.
4. Create the first permanent Teacher with `php scripts/create_teacher.php teacher@example.com "Teacher Name"`.
5. Check `/api/health.php` and `/api/readiness.php`.

## Apache/VPS/shared hosting

- Use HTTPS and PHP 8.1+ with PDO MySQL, mbstring, fileinfo, zip, and required document parsers.
- Copy `.env.production.example` to a private `.env`; never commit it.
- Run `composer install --no-dev --optimize-autoloader`.
- Run `php scripts/validate_production.php` and `php scripts/migrate.php`.
- Grant write access only to runtime storage and uploads. Deny direct execution from upload storage.
- Schedule `php scripts/cleanup_quizzes.php`, log rotation, database backup, and off-site retention.

## AI

Set `GEMINI_API_KEY`, `GEMINI_MODEL`, timeout, retry, and rate-limit values. Fallback remains available. Provider keys and raw errors never belong in HTML, JavaScript, or public logs.

## Backup / recovery

- Dump MySQL with `mysqldump --single-transaction`.
- Run `scripts/backup.ps1` with a destination outside document root.
- Restore the database to a clean schema, run forward migrations, then use `scripts/restore_uploads.ps1`.
- Recommended retention: 7 daily, 4 weekly, and 6 monthly encrypted/off-site backups.
- Prefer forward-fix migrations. Restore only after testing the backup and documenting the recovery point.

## Initial capacity target

The polling design targets a local/demo exercise with up to 40 participants, but this is a target rather than a production capacity claim. Load-test the actual hosting environment before committing to capacity.
