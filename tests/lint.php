<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
);
$failed = [];
$count = 0;
foreach ($iterator as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'php') {
        continue;
    }
    $path = $file->getPathname();
    if (str_contains($path, DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR)) {
        continue;
    }
    $count++;
    exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($path), $output, $code);
    if ($code !== 0) {
        $failed[] = $path;
    }
    $output = [];
}

if ($failed) {
    fwrite(STDERR, "Lint gagal:\n" . implode("\n", $failed) . "\n");
    exit(1);
}
echo "Lint OK: {$count} file PHP.\n";
