<?php
declare(strict_types=1);
require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/LessonPlan/RppTextCleaner.php';

use EnglAI\LessonPlan\RppTextCleaner;
use EnglAI\Security\Csrf;
use EnglAI\Upload\RppUploadValidator;
use EnglAI\Upload\UploadValidationException;

require_admin();

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
function read_pptx(string $path): string {
    $archive = new \ZipArchive();
    if ($archive->open($path) !== true) {
        throw new \RuntimeException('Gagal membuka file PPTX.');
    }
    $text = '';
    $slideFiles = [];
    for ($i = 0; $i < $archive->numFiles; $i++) {
        $name = $archive->getNameIndex($i);
        if (preg_match('/^ppt\/slides\/slide\d+\.xml$/i', $name)) {
            $slideFiles[] = $name;
        }
    }
    natsort($slideFiles);
    foreach ($slideFiles as $slideFile) {
        $content = $archive->getFromName($slideFile);
        if ($content === false) {
            continue;
        }
        $dom = new \DOMDocument();
        $oldErrors = libxml_use_internal_errors(true);
        if ($dom->loadXML($content)) {
            $paragraphs = $dom->getElementsByTagNameNS('http://schemas.openxmlformats.org/drawingml/2006/main', 't');
            foreach ($paragraphs as $paragraph) {
                $text .= ' ' . $paragraph->nodeValue;
            }
        }
        libxml_use_internal_errors($oldErrors);
    }
    $archive->close();
    return trim($text);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('Gunakan form upload untuk mengirim RPP.');
Csrf::requireValid($_POST['csrf_token'] ?? null);
if (!isset($_FILES['rpp_file'])) redirect('Pilih file RPP terlebih dahulu.');
$file = $_FILES['rpp_file'];
try {
    $validated = (new RppUploadValidator())->validate($file);
} catch (UploadValidationException $e) {
    redirect($e->getMessage());
}
$extension = $validated['extension'];
$stored = bin2hex(random_bytes(16)) . '.' . $extension;
$target = __DIR__ . '/../uploads/' . $stored;
if (!move_uploaded_file($file['tmp_name'], $target)) redirect('File tidak dapat disimpan.');
try {
    if ($extension === 'pdf') {
        $text = (new \Smalot\PdfParser\Parser())->parseFile($target)->getText();
    } elseif ($extension === 'pptx') {
        $text = read_pptx($target);
    } else {
        $text = read_docx($target);
    }
    $text = RppTextCleaner::clean($text);
    if ($text === '') throw new RuntimeException('Dokumen tidak memiliki teks yang dapat dibaca.');
    $pdo = db();
    $pdo->beginTransaction();
    $pdo->exec('UPDATE rpps SET is_selected = 0');
    $statement = $pdo->prepare('INSERT INTO rpps (original_name, stored_name, file_type, extracted_text, is_selected) VALUES (?, ?, ?, ?, 1)');
    $statement->execute([$validated['original_name'], $stored, $extension, $text]);
    $pdo->commit();
    redirect('Materi berhasil diunggah dan otomatis menjadi materi aktif.');
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    if (is_file($target)) unlink($target);
    app_log('error', 'RPP upload failed', ['request_id' => request_id(), 'exception' => get_class($e), 'message' => $e->getMessage()]);
    redirect('Isi RPP tidak dapat dibaca. ID laporan: ' . request_id());
}
