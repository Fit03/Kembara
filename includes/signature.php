<?php
// includes/signature.php — Pembantu simpan/padam tandatangan digital pengguna
// Digunakan oleh profile.php (urus tandatangan sendiri) dan bookings.php (tandatangan pelulus semasa kelulusan)

if (!function_exists('signature_upload_dir')) {
    function signature_upload_dir(): string {
        return __DIR__ . '/../assets/uploads/signatures';
    }
}

if (!function_exists('signature_upload_url')) {
    function signature_upload_url(): string {
        return 'assets/uploads/signatures';
    }
}

if (!function_exists('signature_delete_old')) {
    function signature_delete_old(?string $relativePath): void {
        if ($relativePath && is_file(__DIR__ . '/../' . $relativePath)) {
            @unlink(__DIR__ . '/../' . $relativePath);
        }
    }
}

/**
 * Simpan tandatangan daripada fail dimuat naik (mis. $_FILES['signature']).
 * Pulangkan laluan relatif untuk disimpan dalam DB, atau lontar RuntimeException jika gagal.
 */
if (!function_exists('save_signature_upload')) {
    function save_signature_upload(array $file, int $userId, ?string $oldPath = null): string {
        if (empty($file['name']) || $file['error'] !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Sila pilih fail imej yang sah untuk muat naik.');
        }

        $allowedExt = ['jpg', 'jpeg', 'png'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowedExt, true)) {
            throw new RuntimeException('Format fail tidak disokong. Sila muat naik JPG atau PNG.');
        }
        if ($file['size'] > 1 * 1024 * 1024) {
            throw new RuntimeException('Saiz fail tandatangan melebihi had 1MB.');
        }
        if (@getimagesize($file['tmp_name']) === false) {
            throw new RuntimeException('Fail yang dimuat naik bukan imej yang sah.');
        }

        $dir = signature_upload_dir();
        if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
            throw new RuntimeException('Gagal mencipta direktori muat naik.');
        }

        $filename = 'sig_' . $userId . '_' . time() . '_' . bin2hex(random_bytes(3)) . '.' . $ext;
        $dest = $dir . '/' . $filename;
        if (!move_uploaded_file($file['tmp_name'], $dest)) {
            throw new RuntimeException('Gagal memuat naik tandatangan. Sila cuba lagi.');
        }

        signature_delete_old($oldPath);
        return signature_upload_url() . '/' . $filename;
    }
}

/**
 * Simpan tandatangan daripada data URI base64 PNG (dilukis pada kanvas).
 * Pulangkan laluan relatif untuk disimpan dalam DB, atau lontar RuntimeException jika gagal.
 */
if (!function_exists('save_signature_dataurl')) {
    function save_signature_dataurl(string $dataUrl, int $userId, ?string $oldPath = null): string {
        if (!preg_match('/^data:image\/png;base64,(.+)$/', trim($dataUrl), $m)) {
            throw new RuntimeException('Data tandatangan tidak sah.');
        }
        $binary = base64_decode($m[1], true);
        if ($binary === false || strlen($binary) < 100) {
            throw new RuntimeException('Tandatangan kosong atau tidak sah. Sila lukis semula.');
        }
        if (strlen($binary) > 1.5 * 1024 * 1024) {
            throw new RuntimeException('Saiz tandatangan terlalu besar.');
        }

        $dir = signature_upload_dir();
        if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
            throw new RuntimeException('Gagal mencipta direktori muat naik.');
        }

        $filename = 'sig_' . $userId . '_' . time() . '_' . bin2hex(random_bytes(3)) . '.png';
        $dest = $dir . '/' . $filename;
        if (file_put_contents($dest, $binary) === false) {
            throw new RuntimeException('Gagal menyimpan tandatangan. Sila cuba lagi.');
        }

        signature_delete_old($oldPath);
        return signature_upload_url() . '/' . $filename;
    }
}