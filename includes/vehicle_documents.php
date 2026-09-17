<?php

$roadTaxUploadDir = __DIR__ . '/../assets/uploads/road_tax';
$roadTaxUploadUrl = 'assets/uploads/road_tax';
$roadTaxAllowedTypes = [
    'pdf'  => ['application/pdf'],
    'jpg'  => ['image/jpeg'],
    'jpeg' => ['image/jpeg'],
    'png'  => ['image/png'],
];

function validateRoadTaxUpload(array $file, string $uploadDir, array $allowedTypes): array {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Sila pilih dokumen Road Tax yang sah.');
    }
    if (!is_uploaded_file($file['tmp_name'] ?? '')) {
        throw new RuntimeException('Fail yang dimuat naik tidak sah.');
    }
    if (($file['size'] ?? 0) <= 0 || $file['size'] > 5 * 1024 * 1024) {
        throw new RuntimeException('Saiz dokumen Road Tax mestilah antara 1 bait dan 5MB.');
    }

    $extension = strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));
    if (!isset($allowedTypes[$extension])) {
        throw new RuntimeException('Format dokumen tidak disokong. Sila muat naik PDF, JPG, JPEG atau PNG.');
    }

    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    if (!in_array($mime, $allowedTypes[$extension], true)) {
        throw new RuntimeException('Jenis kandungan dokumen tidak sah.');
    }
    if (str_starts_with($mime, 'image/')) {
        set_error_handler(fn() => true);
        $imageInfo = getimagesize($file['tmp_name']);
        restore_error_handler();
        if ($imageInfo === false) {
            throw new RuntimeException('Fail imej yang dimuat naik tidak sah.');
        }
    }

    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
        throw new RuntimeException('Direktori muat naik tidak dapat disediakan.');
    }

    $filename = 'road_tax_' . bin2hex(random_bytes(16)) . '.' . $extension;
    $absolutePath = $uploadDir . DIRECTORY_SEPARATOR . $filename;
    if (!move_uploaded_file($file['tmp_name'], $absolutePath)) {
        throw new RuntimeException('Gagal menyimpan dokumen Road Tax.');
    }

    return [$filename, $absolutePath, $mime];
}

function roadTaxAbsolutePath(string $relativePath): ?string {
    $baseDir = realpath(dirname(__DIR__) . '/assets/uploads/road_tax');
    if (!$baseDir) {
        return null;
    }

    $filename = basename($relativePath);

    // Validate filename format
    if (!preg_match('/^road_tax_[a-f0-9]{32}\.(pdf|jpg|jpeg|png)$/', $filename)) {
        return null;
    }

    $absolutePath = $baseDir . DIRECTORY_SEPARATOR . $filename;
    $realPath = realpath($absolutePath);

    if ($realPath === false || strpos($realPath, $baseDir) !== 0) {
        return null;
    }

    return $realPath;
}
