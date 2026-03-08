<?php
/*
 * File: king-include/king-ajax/aiimgupload.php
 * Description: Handles reference image upload for AI image-to-image generation.
 *              Called via AJAX from the submitai page dropzone.
 */

if (!defined('QA_VERSION')) {
    header('Location: ../../');
    exit;
}

require_once QA_INCLUDE_DIR . 'king-app/blobs.php';
require_once QA_INCLUDE_DIR . 'king-app/post-create.php';

// ─── Validate upload exists ─────────────────────────────────────────────────
if (
    empty($_FILES['file']['tmp_name']) ||
    !is_uploaded_file($_FILES['file']['tmp_name'])
) {
    echo "QA_AJAX_RESPONSE\n0\n" . json_encode([
        'success' => false,
        'message' => 'No file received. Please try again.',
    ]) . "\n";
    exit;
}

$file    = $_FILES['file'];
$tmpPath = $file['tmp_name'];

// ─── MIME check via finfo (client-supplied type is untrusted) ───────────────
if (!class_exists('finfo')) {
    echo "QA_AJAX_RESPONSE\n0\n" . json_encode([
        'success' => false,
        'message' => 'Server misconfiguration: finfo extension missing.',
    ]) . "\n";
    exit;
}

$finfo   = new finfo(FILEINFO_MIME_TYPE);
$mime    = $finfo->file($tmpPath);
$allowed = [
    'image/jpeg' => 'jpg',
    'image/jpg'  => 'jpg',
    'image/png'  => 'png',
    'image/webp' => 'webp',
    'image/gif'  => 'gif',
];

if (!array_key_exists($mime, $allowed)) {
    echo "QA_AJAX_RESPONSE\n0\n" . json_encode([
        'success' => false,
        'message' => 'Invalid file type. Allowed formats: JPG, PNG, WebP, GIF.',
    ]) . "\n";
    exit;
}

// ─── Size check (10 MB max) ─────────────────────────────────────────────────
if ($file['size'] > 10 * 1024 * 1024) {
    echo "QA_AJAX_RESPONSE\n0\n" . json_encode([
        'success' => false,
        'message' => 'File too large. Maximum allowed size is 10 MB.',
    ]) . "\n";
    exit;
}

// ─── Verify it is actually a valid image ────────────────────────────────────
$imageInfo = @getimagesize($tmpPath);
if (!$imageInfo || $imageInfo[0] <= 0 || $imageInfo[1] <= 0) {
    echo "QA_AJAX_RESPONSE\n0\n" . json_encode([
        'success' => false,
        'message' => 'Uploaded file is not a valid image.',
    ]) . "\n";
    exit;
}

list($imgW, $imgH) = $imageInfo;

// ─── Prepare destination ────────────────────────────────────────────────────
$ext     = $allowed[$mime];
$folder  = 'uploads/' . date('Y') . '/' . date('m') . '/';
$destDir = QA_INCLUDE_DIR . $folder;

if (!is_dir($destDir) && !mkdir($destDir, 0755, true)) {
    echo "QA_AJAX_RESPONSE\n0\n" . json_encode([
        'success' => false,
        'message' => 'Server error: could not create upload directory.',
    ]) . "\n";
    exit;
}

$filename = 'ref-img-' . time() . '-' . mt_rand(1000, 9999) . '.' . $ext;
$destPath = $destDir . $filename;

if (!move_uploaded_file($tmpPath, $destPath)) {
    echo "QA_AJAX_RESPONSE\n0\n" . json_encode([
        'success' => false,
        'message' => 'Failed to save uploaded file. Check server permissions.',
    ]) . "\n";
    exit;
}

// ─── Insert into database / cloud storage ───────────────────────────────────
$previewUrl = '';
$uploadId   = null;

if (qa_opt('enable_aws')) {
    $cloudUrl   = king_upload_to_cloud($destPath, $filename, 'aws');
    $uploadId   = king_insert_uploads($cloudUrl, $ext, $imgW, $imgH, 'aws');
    $previewUrl = $cloudUrl;
} elseif (qa_opt('enable_wasabi')) {
    $cloudUrl   = king_upload_to_cloud($destPath, $filename, 'wasabi');
    $uploadId   = king_insert_uploads($cloudUrl, $ext, $imgW, $imgH, 'wasabi');
    $previewUrl = $cloudUrl;
} else {
    $uploadId   = king_insert_uploads($folder . $filename, $ext, $imgW, $imgH);
    $previewUrl = rtrim(qa_opt('site_url'), '/') . '/king-include/' . $folder . $filename;
}

if (!$uploadId) {
    @unlink($destPath);
    echo "QA_AJAX_RESPONSE\n0\n" . json_encode([
        'success' => false,
        'message' => 'Upload succeeded but could not be registered. Please try again.',
    ]) . "\n";
    exit;
}

// ─── Success ─────────────────────────────────────────────────────────────────
echo "QA_AJAX_RESPONSE\n1\n" . json_encode([
    'success'  => true,
    'imageid'  => $uploadId,
    'preview'  => $previewUrl,
    'width'    => $imgW,
    'height'   => $imgH,
]) . "\n";
exit;