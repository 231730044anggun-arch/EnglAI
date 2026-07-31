#!/usr/bin/env php
<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/koneksi.php';

$pdo = db();
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS schema_migrations (
        migration VARCHAR(191) NOT NULL,
        applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (migration)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

$applied = array_fill_keys(
    $pdo->query('SELECT migration FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN),
    true
);
$files = glob(dirname(__DIR__) . '/database/migrations/*.php') ?: [];
sort($files, SORT_STRING);

$count = 0;
foreach ($files as $file) {
    $name = basename($file);
    if (isset($applied[$name])) {
        echo "SKIP {$name}\n";
        continue;
    }

    $migration = require $file;
    if (!$migration instanceof Closure) {
        throw new RuntimeException("Migration {$name} harus mengembalikan Closure.");
    }

    $pdo->beginTransaction();
    try {
        $migration($pdo);
        $statement = $pdo->prepare('INSERT INTO schema_migrations (migration) VALUES (?)');
        $statement->execute([$name]);
        // MySQL implicitly commits DDL statements. Commit only when the
        // migration left the explicit transaction active.
        if ($pdo->inTransaction()) {
            $pdo->commit();
        }
        $count++;
        echo "APPLIED {$name}\n";
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

echo "DONE applied={$count}\n";
