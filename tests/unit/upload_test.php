<?php
declare(strict_types=1);

use EnglAI\Upload\RppUploadValidator;
use EnglAI\Upload\UploadValidationException;

$directory = dirname(__DIR__, 2) . '/storage/cache/upload-tests-' . bin2hex(random_bytes(4));
mkdir($directory, 0770, true);
$validator = new RppUploadValidator();
$created = [];

$makeFile = static function (string $name, string $content) use ($directory, &$created): string {
    $path = $directory . '/' . $name;
    file_put_contents($path, $content);
    $created[] = $path;
    return $path;
};
$fileArray = static fn(string $path, string $name, ?int $size = null): array => ['error'=>UPLOAD_ERR_OK,'size'=>$size ?? filesize($path),'tmp_name'=>$path,'name'=>$name];
$rejects = static function (array $file) use ($validator): bool {
    try { $validator->validate($file); return false; } catch (UploadValidationException) { return true; }
};

$pdf = $makeFile('valid.pdf', "%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF");
check($validator->validate($fileArray($pdf, 'valid.pdf'))['extension'] === 'pdf', 'PDF valid harus diterima validator.');

$phpAsPdf = $makeFile('evil.pdf', '<?php echo "owned";');
check($rejects($fileArray($phpAsPdf, 'evil.pdf')), 'PHP yang diganti menjadi PDF harus ditolak.');

$docx = $directory . '/valid.docx';
$zip = new ZipArchive();
$zip->open($docx, ZipArchive::CREATE);
$zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"></Types>');
$zip->addFromString('word/document.xml', '<?xml version="1.0"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>Test</w:t></w:r></w:p></w:body></w:document>');
$zip->close();
$created[] = $docx;
check($validator->validate($fileArray($docx, 'valid.docx'))['extension'] === 'docx', 'DOCX valid harus diterima validator.');

$pptx = $directory . '/valid.pptx';
$zip = new ZipArchive();
$zip->open($pptx, ZipArchive::CREATE);
$zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"></Types>');
$zip->addFromString('ppt/presentation.xml', '<?xml version="1.0"?><p:presentation xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main"></p:presentation>');
$zip->close();
$created[] = $pptx;
check($validator->validate($fileArray($pptx, 'valid.pptx'))['extension'] === 'pptx', 'PPTX valid harus diterima validator.');

$plainZip = $directory . '/fake.docx';
$zip = new ZipArchive();
$zip->open($plainZip, ZipArchive::CREATE);
$zip->addFromString('readme.txt', 'not a docx');
$zip->close();
$created[] = $plainZip;
check($rejects($fileArray($plainZip, 'fake.docx')), 'ZIP yang diganti menjadi DOCX harus ditolak.');

$plainZipPptx = $directory . '/fake.pptx';
$zip = new ZipArchive();
$zip->open($plainZipPptx, ZipArchive::CREATE);
$zip->addFromString('readme.txt', 'not a pptx');
$zip->close();
$created[] = $plainZipPptx;
check($rejects($fileArray($plainZipPptx, 'fake.pptx')), 'ZIP yang diganti menjadi PPTX harus ditolak.');

$corrupt = $makeFile('corrupt.docx', 'PK broken archive');
check($rejects($fileArray($corrupt, 'corrupt.docx')), 'DOCX rusak harus ditolak.');

$corruptPptx = $makeFile('corrupt.pptx', 'PK broken archive');
check($rejects($fileArray($corruptPptx, 'corrupt.pptx')), 'PPTX rusak harus ditolak.');

check($rejects($fileArray($pdf, 'oversize.pdf', RppUploadValidator::MAX_BYTES + 1)), 'File melebihi batas harus ditolak.');
check($rejects($fileArray($pdf, 'wrong.docx')), 'MIME/extension yang tidak sesuai harus ditolak.');

$htmlName = '<img src=x onerror=alert(1)>.pdf';
$validated = $validator->validate($fileArray($pdf, $htmlName));
check($validated['original_name'] === $htmlName, 'Nama file HTML harus tetap data literal untuk kemudian di-escape saat render.');
check(htmlspecialchars($validated['original_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') === '&lt;img src=x onerror=alert(1)&gt;.pdf', 'Nama file harus dapat dirender sebagai teks aman.');

foreach ($created as $path) {
    if (is_file($path)) unlink($path);
}
rmdir($directory);
