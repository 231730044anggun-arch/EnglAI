<?php
declare(strict_types=1);

namespace EnglAI\Upload;

final class RppUploadValidator
{
    public const MAX_BYTES = 15 * 1024 * 1024;

    /** @param array<string,mixed> $file @return array{extension:string,mime:string,original_name:string} */
    public function validate(array $file): array
    {
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        $size = (int) ($file['size'] ?? 0);
        $temporaryPath = (string) ($file['tmp_name'] ?? '');
        $originalName = basename((string) ($file['name'] ?? ''));
        if ($error !== UPLOAD_ERR_OK || $size < 1 || !is_file($temporaryPath)) {
            throw new UploadValidationException('Upload file gagal.');
        }
        if ($size > self::MAX_BYTES) {
            throw new UploadValidationException('Ukuran file maksimal 15 MB.');
        }
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if (!in_array($extension, ['pdf', 'docx'], true)) {
            throw new UploadValidationException('Hanya file PDF atau DOCX yang diizinkan.');
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($temporaryPath) ?: '';
        $allowed = [
            'pdf' => ['application/pdf'],
            'docx' => [
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'application/zip',
                'application/octet-stream',
            ],
        ];
        if (!in_array($mime, $allowed[$extension], true)) {
            throw new UploadValidationException('Tipe MIME file tidak sesuai dengan extension.');
        }
        if ($extension === 'pdf' && !$this->hasPdfSignature($temporaryPath)) {
            throw new UploadValidationException('Signature PDF tidak valid.');
        }
        if ($extension === 'docx') {
            $this->validateDocxStructure($temporaryPath);
        }
        return ['extension' => $extension, 'mime' => $mime, 'original_name' => $originalName];
    }

    private function hasPdfSignature(string $path): bool
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return false;
        }
        $signature = fread($handle, 5);
        fclose($handle);
        return $signature === '%PDF-';
    }

    private function validateDocxStructure(string $path): void
    {
        $archive = new \ZipArchive();
        $opened = $archive->open($path) === true;
        if (!$opened
            || $archive->locateName('[Content_Types].xml') === false
            || $archive->locateName('word/document.xml') === false) {
            if ($opened) {
                $archive->close();
            }
            throw new UploadValidationException('Struktur DOCX tidak valid.');
        }
        $archive->close();
    }
}
