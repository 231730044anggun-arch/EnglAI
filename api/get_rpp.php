<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/koneksi.php';

try {
    $rows = db()->query('SELECT id, original_name, file_type, is_selected, uploaded_at FROM rpps ORDER BY uploaded_at DESC')->fetchAll();
    $selected = null;
    foreach ($rows as $row) {
        if ((int) $row['is_selected'] === 1) {
            $selected = (int) $row['id'];
            break;
        }
    }
    json_response(['success' => true, 'selected_id' => $selected, 'rpps' => $rows]);
} catch (Throwable $e) {
    json_response(['success' => false, 'message' => 'Daftar RPP belum tersedia.'], 500);
}
