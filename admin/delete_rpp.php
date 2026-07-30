<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/koneksi.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: index.php'); exit; }
$id = (int) ($_POST['id'] ?? 0);
try {
    $statement = db()->prepare('SELECT stored_name FROM rpps WHERE id = ?');
    $statement->execute([$id]);
    $rpp = $statement->fetch();
    if ($rpp) {
        db()->prepare('DELETE FROM rpps WHERE id = ?')->execute([$id]);
        $path = __DIR__ . '/../uploads/' . basename($rpp['stored_name']);
        if (is_file($path)) unlink($path);
    }
    $message = 'RPP berhasil dihapus.';
} catch (Throwable $e) { $message = 'RPP gagal dihapus.'; }
header('Location: index.php?message=' . rawurlencode($message));
exit;
