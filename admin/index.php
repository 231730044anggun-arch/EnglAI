<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/koneksi.php';

$message = (string) ($_GET['message'] ?? '');
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['select_rpp'])) {
    $id = (int) $_POST['select_rpp'];
    try {
        $pdo = db();
        $pdo->beginTransaction();
        $pdo->exec('UPDATE rpps SET is_selected = 0');
        $statement = $pdo->prepare('UPDATE rpps SET is_selected = 1 WHERE id = ?');
        $statement->execute([$id]);
        $pdo->commit();
        $message = $statement->rowCount() ? 'RPP aktif berhasil dipilih.' : 'RPP tidak ditemukan.';
    } catch (Throwable $e) {
        if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
        $message = 'Gagal memilih RPP.';
    }
}
try { $rpps = db()->query('SELECT id, original_name, file_type, is_selected, uploaded_at, CHAR_LENGTH(extracted_text) AS text_length FROM rpps ORDER BY uploaded_at DESC')->fetchAll(); }
catch (Throwable $e) { $rpps = []; $message = $message ?: 'Database belum siap. Impor database/englai.sql terlebih dahulu.'; }
?>
<!doctype html><html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Admin RPP — EnglAI</title>
<style>body{font-family:Arial,sans-serif;max-width:960px;margin:40px auto;padding:0 20px;color:#1f2937;background:#f8fafc}h1{color:#312e81}.box{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:24px;margin:18px 0}table{width:100%;border-collapse:collapse}th,td{padding:12px;text-align:left;border-bottom:1px solid #e5e7eb}button,.btn{border:0;border-radius:7px;padding:9px 13px;background:#4f46e5;color:white;text-decoration:none;cursor:pointer}.danger{background:#dc2626}.active{color:#047857;font-weight:bold}.notice{padding:12px;background:#eef2ff;border-radius:8px}</style></head><body>
<h1>EnglAI — Manajemen RPP</h1><p><a href="../index.php">← Buka game</a></p>
<?php if ($message): ?><p class="notice"><?= htmlspecialchars($message) ?></p><?php endif; ?>
<section class="box"><h2>Upload RPP</h2><p>Format yang didukung: PDF dan DOCX.</p><form action="upload_rpp.php" method="post" enctype="multipart/form-data"><input type="file" name="rpp_file" accept=".pdf,.docx,application/pdf,application/vnd.openxmlformats-officedocument.wordprocessingml.document" required> <button type="submit">Upload RPP</button></form></section>
<section class="box"><h2>Daftar RPP</h2><table><thead><tr><th>Nama file</th><th>Jenis</th><th>Isi terbaca</th><th>Diunggah</th><th>Status / aksi</th></tr></thead><tbody>
<?php foreach ($rpps as $rpp): ?><tr><td><?= htmlspecialchars($rpp['original_name']) ?></td><td><?= strtoupper(htmlspecialchars($rpp['file_type'])) ?></td><td><?= number_format((int)$rpp['text_length']) ?> karakter</td><td><?= htmlspecialchars($rpp['uploaded_at']) ?></td><td><?php if ($rpp['is_selected']): ?><span class="active">● RPP aktif</span><?php else: ?><form method="post" style="display:inline"><input type="hidden" name="select_rpp" value="<?= (int)$rpp['id'] ?>"><button type="submit">Pilih</button></form><?php endif; ?> <form action="delete_rpp.php" method="post" style="display:inline" onsubmit="return confirm('Hapus RPP ini?')"><input type="hidden" name="id" value="<?= (int)$rpp['id'] ?>"><button class="danger" type="submit">Hapus</button></form></td></tr>
<?php endforeach; ?><?php if (!$rpps): ?><tr><td colspan="5">Belum ada RPP.</td></tr><?php endif; ?></tbody></table></section></body></html>
