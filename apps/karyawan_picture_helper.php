<?php

function karyawan_picture_upload_error_message($errorCode)
{
    switch ((int) $errorCode) {
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
            return "Upload foto gagal. Ukuran foto melebihi batas server. Coba pilih foto yang lebih kecil atau kompres dulu.";
        case UPLOAD_ERR_PARTIAL:
            return "Upload foto gagal. File hanya terupload sebagian. Coba ulangi dengan koneksi yang lebih stabil.";
        case UPLOAD_ERR_NO_TMP_DIR:
            return "Upload foto gagal. Folder temporary server tidak tersedia.";
        case UPLOAD_ERR_CANT_WRITE:
            return "Upload foto gagal. Server tidak bisa menulis file upload.";
        case UPLOAD_ERR_EXTENSION:
            return "Upload foto gagal. Upload diblokir ekstensi PHP server.";
        default:
            return "Upload foto gagal. Silakan coba lagi.";
    }
}

function karyawan_picture_upload_any($fieldNames, &$errorMessage)
{
    $errorMessage = "";

    foreach ($fieldNames as $fieldName) {
        if (!isset($_FILES[$fieldName]) || $_FILES[$fieldName]['error'] === UPLOAD_ERR_NO_FILE) {
            continue;
        }

        return karyawan_picture_upload($fieldName, $errorMessage);
    }

    return null;
}

function karyawan_picture_upload($fieldName, &$errorMessage)
{
    $errorMessage = "";

    if (!isset($_FILES[$fieldName]) || $_FILES[$fieldName]['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    if ($_FILES[$fieldName]['error'] !== UPLOAD_ERR_OK) {
        $errorMessage = karyawan_picture_upload_error_message($_FILES[$fieldName]['error']);
        return null;
    }

    if ($_FILES[$fieldName]['size'] > 2 * 1024 * 1024) {
        $errorMessage = "Ukuran foto maksimal 2MB. Coba pilih foto yang lebih kecil atau kompres dulu.";
        return null;
    }

    $tmpPath = $_FILES[$fieldName]['tmp_name'];
    $mimeType = '';

    if (class_exists('finfo')) {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mimeType = (string) $finfo->file($tmpPath);
    } elseif (function_exists('mime_content_type')) {
        $mimeType = (string) mime_content_type($tmpPath);
    }

    $allowedMimeTypes = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    if (!isset($allowedMimeTypes[$mimeType])) {
        $errorMessage = "Format foto harus JPG, PNG, atau WebP. Jika dari iPhone tersimpan HEIC, ubah dulu ke JPG.";
        return null;
    }

    $datePath = date('Y/m');
    $baseDir = dirname(__DIR__) . '/uploads/karyawan/' . $datePath;

    if (!is_dir($baseDir) && !mkdir($baseDir, 0777, true) && !is_dir($baseDir)) {
        $errorMessage = "Folder upload foto tidak bisa dibuat.";
        return null;
    }

    $extension = $allowedMimeTypes[$mimeType];
    $fileName = 'karyawan_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $extension;
    $absolutePath = $baseDir . '/' . $fileName;

    if (!move_uploaded_file($tmpPath, $absolutePath)) {
        $errorMessage = "Foto gagal disimpan.";
        return null;
    }

    return 'uploads/karyawan/' . $datePath . '/' . $fileName;
}

function karyawan_picture_url($picture)
{
    $picture = trim((string) $picture);

    if ($picture === '') {
        return '';
    }

    if (preg_match('#^https?://#i', $picture)) {
        return $picture;
    }

    $relativePath = preg_replace('#^\.\./#', '', str_replace('\\', '/', $picture));

    return 'view_upload.php?file=' . rawurlencode($relativePath);
}

function karyawan_picture_delete($picture)
{
    $picture = trim((string) $picture);

    if ($picture === '' || preg_match('#^https?://#i', $picture)) {
        return;
    }

    $relativePath = preg_replace('#^\.\./#', '', str_replace('\\', '/', $picture));
    $absolutePath = dirname(__DIR__) . '/' . $relativePath;

    if (is_file($absolutePath)) {
        @unlink($absolutePath);
    }
}
