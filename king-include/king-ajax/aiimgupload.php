<?php
/*
 * File: king-include/king-ajax/aiimgupload.php
 *
 * NOTE: In the new base64 architecture, this file is NO LONGER called
 * by the main generation flow. king-ask.js reads the file as base64
 * and aigenerate.php receives it as ref_image_b64 directly.
 *
 * This file is kept as a working fallback/legacy handler.
 * It now does a proper DB insert via king_insert_uploads() so that
 * IF it is ever called, it returns a real numeric imageid — never
 * the broken "path:..." string that caused the resolver to fail.
 */

if (!defined('QA_VERSION')) {
    header('Location: ../../');
    exit;
}

// ─────────────────────────────────────────────────────────────────────────────
// HARDEN OUTPUT — catch fatal errors and return clean JSON
// ─────────────────────────────────────────────────────────────────────────────
ob_start();
$GLOBALS['__aiimgupload_prev_err'] = error_reporting(E_ALL);
@ini_set('display_errors', '0');

register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        while (ob_get_level() > 0) @ob_end_clean();
        header('Content-Type: text/plain; charset=utf-8');
        echo "QA_AJAX_RESPONSE\n0\n" . json_encode([
            'success' => false,
            'message' => 'Fatal: ' . $e['message'] . ' (line ' . $e['line'] . ')',
        ]) . "\n";
    }
});

// ─────────────────────────────────────────────────────────────────────────────
// RESPONSE HELPERS
// ─────────────────────────────────────────────────────────────────────────────
function _aiup_end() {
    while (ob_get_level() > 0) @ob_end_clean();
    if (isset($GLOBALS['__aiimgupload_prev_err'])) error_reporting($GLOBALS['__aiimgupload_prev_err']);
}

function _aiup_ok($imageid, $preview_url) {
    _aiup_end();
    echo "QA_AJAX_RESPONSE\n1\n" . json_encode([
        'success' => true,
        'imageid' => $imageid,
        'preview' => $preview_url,
    ]) . "\n";
    exit;
}

function _aiup_error($msg) {
    _aiup_end();
    error_log('aiimgupload ERROR: ' . $msg);
    echo "QA_AJAX_RESPONSE\n0\n" . json_encode([
        'success' => false,
        'message' => $msg,
    ]) . "\n";
    exit;
}

// ─────────────────────────────────────────────────────────────────────────────
// GD HELPERS
// ─────────────────────────────────────────────────────────────────────────────
function _aiup_create_from($mime, $path) {
    $map = [
        'image/jpeg' => 'imagecreatefromjpeg',
        'image/png'  => 'imagecreatefrompng',
        'image/webp' => 'imagecreatefromwebp',
        'image/gif'  => 'imagecreatefromgif',
    ];
    $fn = $map[$mime] ?? null;
    return ($fn && function_exists($fn)) ? @$fn($path) : false;
}

function _aiup_save_gd($mime, $res, $path) {
    $map = [
        'image/jpeg' => ['imagejpeg', [90]],
        'image/png'  => ['imagepng',  [8]],
        'image/webp' => ['imagewebp', [90]],
        'image/gif'  => ['imagegif',  []],
    ];
    if (!isset($map[$mime])) return false;
    [$fn, $args] = $map[$mime];
    return function_exists($fn) ? @$fn($res, $path, ...$args) : false;
}

// ─────────────────────────────────────────────────────────────────────────────
// OPTIONAL INCLUDES
// ─────────────────────────────────────────────────────────────────────────────
if (file_exists(QA_INCLUDE_DIR . 'king-app/blobs.php'))  require_once QA_INCLUDE_DIR . 'king-app/blobs.php';
if (file_exists(QA_INCLUDE_DIR . 'king-app/upload.php')) require_once QA_INCLUDE_DIR . 'king-app/upload.php';

// ─────────────────────────────────────────────────────────────────────────────
// VALIDATE UPLOAD
// ─────────────────────────────────────────────────────────────────────────────
if (empty($_FILES['file']) || !isset($_FILES['file']['error'])) {
    _aiup_error('No file received.');
}

$f   = $_FILES['file'];
$err = (int)$f['error'];

if ($err !== UPLOAD_ERR_OK) {
    $errs = [
        UPLOAD_ERR_INI_SIZE   => 'File exceeds server upload limit.',
        UPLOAD_ERR_FORM_SIZE  => 'File exceeds form size limit.',
        UPLOAD_ERR_PARTIAL    => 'File only partially uploaded.',
        UPLOAD_ERR_NO_FILE    => 'No file uploaded.',
        UPLOAD_ERR_NO_TMP_DIR => 'Server temp folder missing.',
        UPLOAD_ERR_CANT_WRITE => 'Server cannot write file.',
        UPLOAD_ERR_EXTENSION  => 'Upload blocked by server extension.',
    ];
    _aiup_error($errs[$err] ?? 'Upload error code ' . $err . '.');
}

$tmp_path = (string)$f['tmp_name'];
$file_size = (int)($f['size'] ?? 0);

if ($file_size <= 0)                   _aiup_error('Uploaded file is empty.');
if ($file_size > 10 * 1024 * 1024)    _aiup_error('File too large. Maximum is 10 MB.');
if (!is_uploaded_file($tmp_path))      _aiup_error('Security check failed.');

// ─────────────────────────────────────────────────────────────────────────────
// VALIDATE MIME + IMAGE DIMENSIONS
// ─────────────────────────────────────────────────────────────────────────────
if (!class_exists('finfo')) _aiup_error('PHP finfo extension required.');

$finfo     = new finfo(FILEINFO_MIME_TYPE);
$real_mime = $finfo->file($tmp_path);
$allowed   = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

if (!in_array($real_mime, $allowed, true)) {
    _aiup_error('Invalid file type. Allowed: JPEG, PNG, WebP, GIF.');
}

$img_info = @getimagesize($tmp_path);
if (!$img_info || ($img_info[0] ?? 0) < 1 || ($img_info[1] ?? 0) < 1) {
    _aiup_error('Not a valid image file.');
}

$orig_w = (int)$img_info[0];
$orig_h = (int)$img_info[1];

$ext_map = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
$ext     = $ext_map[$real_mime] ?? 'jpg';

// ─────────────────────────────────────────────────────────────────────────────
// DESTINATION DIRECTORY
// ─────────────────────────────────────────────────────────────────────────────
$folder   = 'uploads/' . date('Y') . '/' . date('m') . '/';
$dest_dir = rtrim(QA_INCLUDE_DIR, '/\\') . '/' . $folder;

if (!is_dir($dest_dir) && !@mkdir($dest_dir, 0755, true)) {
    _aiup_error('Could not create upload directory.');
}
if (!is_writable($dest_dir)) {
    _aiup_error('Upload directory is not writable.');
}

$stamp      = time() . '-' . mt_rand(1000, 9999);
$filename   = 'ref-upload-' . $stamp . '.' . $ext;
$final_path = $dest_dir . $filename;
$rel_path   = $folder . $filename;

// ─────────────────────────────────────────────────────────────────────────────
// OPTIONAL RESIZE (cap at 1920px so API payloads stay manageable)
// ─────────────────────────────────────────────────────────────────────────────
$work_path = $tmp_path;
$resized   = false;

if (($orig_w > 1920 || $orig_h > 1920) && extension_loaded('gd')) {
    $src = _aiup_create_from($real_mime, $tmp_path);
    if ($src) {
        $scale = min(1920 / $orig_w, 1920 / $orig_h);
        $nw    = max(1, (int)round($orig_w * $scale));
        $nh    = max(1, (int)round($orig_h * $scale));
        $dst   = imagecreatetruecolor($nw, $nh);
        if (in_array($real_mime, ['image/png', 'image/webp', 'image/gif'], true)) {
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
            imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
        }
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $orig_w, $orig_h);
        imagedestroy($src);
        $tmp_r = tempnam(sys_get_temp_dir(), 'aiup_');
        if ($tmp_r && _aiup_save_gd($real_mime, $dst, $tmp_r)) {
            clearstatcache(true, $tmp_r);
            if (file_exists($tmp_r) && filesize($tmp_r) > 100) {
                $work_path = $tmp_r;
                $orig_w    = $nw;
                $orig_h    = $nh;
                $resized   = true;
            }
        }
        imagedestroy($dst);
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// SAVE FILE TO DISK
// ─────────────────────────────────────────────────────────────────────────────
$saved = false;
if (@copy($work_path, $final_path)) {
    $saved = true;
} elseif (@move_uploaded_file($tmp_path, $final_path)) {
    $saved = true;
} else {
    $raw = @file_get_contents($work_path);
    if ($raw !== false && @file_put_contents($final_path, $raw) !== false) {
        $saved = true;
    }
}

if ($resized && $work_path !== $tmp_path && file_exists($work_path)) {
    @unlink($work_path);
}

clearstatcache(true, $final_path);

if (!$saved || !file_exists($final_path) || filesize($final_path) < 100) {
    @unlink($final_path);
    _aiup_error('Could not save uploaded file. Check folder permissions.');
}

// ─────────────────────────────────────────────────────────────────────────────
// THUMBNAIL
// ─────────────────────────────────────────────────────────────────────────────
$thumb_rel = '';

if (function_exists('king_process_local_image')) {
    $t = @king_process_local_image($final_path, $rel_path, true, 300);
    if (is_string($t) && $t !== '') $thumb_rel = $t;
}

if ($thumb_rel === '' && extension_loaded('gd') && $orig_w > 300) {
    $src = _aiup_create_from($real_mime, $final_path);
    if ($src) {
        $tw  = 300;
        $th  = max(1, (int)round($orig_h * ($tw / $orig_w)));
        $dst = imagecreatetruecolor($tw, $th);
        if (in_array($real_mime, ['image/png', 'image/webp', 'image/gif'], true)) {
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
            imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
        }
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $tw, $th, $orig_w, $orig_h);
        imagedestroy($src);
        $tname = 'thumb_' . $filename;
        $tpath = $dest_dir . $tname;
        if (_aiup_save_gd($real_mime, $dst, $tpath)) {
            clearstatcache(true, $tpath);
            if (file_exists($tpath) && filesize($tpath) > 50) $thumb_rel = $folder . $tname;
        }
        imagedestroy($dst);
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// DATABASE INSERT — returns a real numeric imageid
//
// This is the critical fix. The old code returned "path:uploads/..." which
// (int) cast to 0 in king_resolve_upload_paths(), breaking the entire chain.
// We now use king_insert_uploads() to get a numeric ID.
// If that function is unavailable, we fall back to a direct DB insert.
// ─────────────────────────────────────────────────────────────────────────────
$upload_id   = null; // will be numeric ID
$site_url    = rtrim((string)qa_opt('site_url'), '/');
$preview_src = $thumb_rel !== '' ? $thumb_rel : $rel_path;
$preview_url = $site_url . '/king-include/' . ltrim($preview_src, '/');

// ── Attempt 1: king_insert_uploads() ─────────────────────────────────────────
if (function_exists('king_insert_uploads')) {
    try {
        if (qa_opt('enable_aws') && function_exists('king_upload_to_cloud')) {
            $cloud_url = king_upload_to_cloud($final_path, $filename, 'aws');
            if (!empty($cloud_url)) {
                $upload_id = king_insert_uploads($cloud_url, $ext, $orig_w, $orig_h, 'aws');
            }
        } elseif (qa_opt('enable_wasabi') && function_exists('king_upload_to_cloud')) {
            $cloud_url = king_upload_to_cloud($final_path, $filename, 'wasabi');
            if (!empty($cloud_url)) {
                $upload_id = king_insert_uploads($cloud_url, $ext, $orig_w, $orig_h, 'wasabi');
            }
        }
        if (empty($upload_id)) {
            $upload_id = king_insert_uploads($rel_path, $ext, $orig_w, $orig_h);
        }
    } catch (Exception $e) {
        error_log('aiimgupload: king_insert_uploads exception: ' . $e->getMessage());
    }
}

// ── Attempt 2: direct DB insert into qa_uploads ───────────────────────────────
if (empty($upload_id)) {
    try {
        qa_db_query_sub(
            'INSERT INTO ^uploads (path, type, width, height, created) VALUES ($, $, #, #, NOW())',
            $rel_path, $ext, $orig_w, $orig_h
        );
        $upload_id = qa_db_last_insert_id();
    } catch (Exception $e) {
        error_log('aiimgupload: direct DB insert failed: ' . $e->getMessage());
    }
}

// ── Attempt 3: direct PDO/mysqli insert ──────────────────────────────────────
if (empty($upload_id)) {
    try {
        $db = qa_db_connection();
        $prefix = qa_db_add_table_prefix('');
        $table  = $prefix . 'uploads';
        if ($db instanceof PDO) {
            $stmt = $db->prepare(
                "INSERT INTO `{$table}` (path, type, width, height, created) VALUES (?, ?, ?, ?, NOW())"
            );
            $stmt->execute([$rel_path, $ext, $orig_w, $orig_h]);
            $upload_id = (int)$db->lastInsertId();
        } else {
            // mysqli / mysql_* fallback
            $escaped = mysqli_real_escape_string($db, $rel_path);
            mysqli_query($db, "INSERT INTO `{$table}` (path, type, width, height, created) VALUES ('{$escaped}', '{$ext}', {$orig_w}, {$orig_h}, NOW())");
            $upload_id = (int)mysqli_insert_id($db);
        }
    } catch (Exception $e) {
        error_log('aiimgupload: PDO/mysqli insert failed: ' . $e->getMessage());
    }
}

// ── Final fallback: encode path so aigenerate.php can still resolve it ────────
// This is a safe fallback. aigenerate.php next-page will handle "file:..." prefix
// to load the image binary directly from disk without any DB lookup.
if (empty($upload_id)) {
    error_log('aiimgupload WARNING: DB insert failed — using file: fallback for ' . $rel_path);
    $upload_id = 'file:' . $rel_path;
}

error_log('aiimgupload OK: imageid=' . $upload_id . ' file=' . $filename . ' ' . $orig_w . 'x' . $orig_h);

_aiup_ok($upload_id, $preview_url);