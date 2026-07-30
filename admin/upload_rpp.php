<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/koneksi.php';
require_once __DIR__ . '/../vendor/autoload.php';

function redirect(string $message): never { header('Location: index.php?message=' . rawurlencode($message)); exit; }
function element_text(mixed $element): string {
    $text = method_exists($element, 'getText') ? (string) $element->getText() : '';
    if (method_exists($element, 'getElements')) foreach ($element->getElements() as $child) $text .= ' ' . element_text($child);
    if (method_exists($element, 'getRows')) foreach ($element->getRows() as $row) $text .= ' ' . element_text($row);
    if (method_exists($element, 'getCells')) foreach ($element->getCells() as $cell) $text .= ' ' . element_text($cell);
    return $text;
}
function read_docx(string $path): string {
    $document = \PhpOffice\PhpWord\IOFactory::load($path);
    $text = '';
    foreach ($document->getSections() as $section) foreach ($section->getElements() as $element) $text .= ' ' . element_text($element);
    return $text;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_FILES['rpp_file'])) redirect('Pilih file RPP terlebih dahulu.');
$file = $_FILES['rpp_file'];
if ($file['error'] !== UPLOAD_ERR_OK || $file['size'] < 1) redirect('Upload file gagal.');
if ($file['size'] > 15 * 1024 * 1024) redirect('Ukuran file maksimal 15 MB.');
$extension = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
if (!in_array($extension, ['pdf', 'docx'], true)) redirect('Hanya file PDF atau DOCX yang diizinkan.');
$stored = bin2hex(random_bytes(16)) . '.' . $extension;
$target = __DIR__ . '/../uploads/' . $stored;
if (!move_uploaded_file($file['tmp_name'], $target)) redirect('File tidak dapat disimpan.');
try {
    $text = $extension === 'pdf' ? (new \Smalot\PdfParser\Parser())->parseFile($target)->getText() : read_docx($target);
    $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
    if ($text === '') throw new RuntimeException('Dokumen tidak memiliki teks yang dapat dibaca.');
    $statement = db()->prepare('INSERT INTO rpps (original_name, stored_name, file_type, extracted_text) VALUES (?, ?, ?, ?)');
    $statement->execute([basename((string) $file['name']), $stored, $extension, $text]);
    redirect('RPP berhasil diunggah. Pilih RPP tersebut sebagai RPP aktif.');
} catch (Throwable $e) {
    if (is_file($target)) unlink($target);
    redirect('Isi RPP tidak dapat dibaca: ' . $e->getMessage());
}
