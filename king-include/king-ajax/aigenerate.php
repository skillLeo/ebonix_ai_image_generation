<?php
use CURLFile;
/*
 * File: king-include/king-ajax/aigenerate.php
 *
 * ARCHITECTURE — Unified base64 reference image flow:
 *
 * king-ask.js reads the chosen file as a base64 data-URI and posts it as
 * ref_image_b64. This file decodes it immediately into $ref_binary so every
 * downstream provider (Fal, Gemini, DALL-E, Luma, Decart, KingStudio) gets
 * the raw image bytes without any DB lookup, file-path resolution, or HTTP
 * download. The imageid field is intentionally empty in all new requests.
 *
 * Legacy imageid flow is still supported as a fallback for regenerate/reuse
 * requests that already have a numeric or file: prefixed id stored in postmeta.
 */

if (!defined('QA_VERSION')) {
    header('Location: ../../');
    exit;
}

set_time_limit(660);
ini_set('max_execution_time', 660);
ini_set('memory_limit', '512M');

require_once QA_INCLUDE_DIR . 'king-app/users.php';
require_once QA_INCLUDE_DIR . 'king-app/limits.php';
require_once QA_INCLUDE_DIR . 'king-db/selects.php';
require_once QA_INCLUDE_DIR . 'king-app-video.php';
require_once QA_INCLUDE_DIR . 'king-app/cookies.php';
require_once QA_INCLUDE_DIR . 'king-db/metas.php';
require_once QA_INCLUDE_DIR . 'king-app/gateway.php';
require_once QA_INCLUDE_DIR . 'king-app/blobs.php';
require_once QA_INCLUDE_DIR . 'king-app/post-create.php';

// ============================================================
// HELPER: Resolve upload paths (numeric id OR file: prefix)
// ============================================================
if (!function_exists('king_resolve_upload_paths')) {
    function king_resolve_upload_paths($imageid) {
        if (empty($imageid)) return ['abs_path' => '', 'pub_url' => ''];

        $abs_path = '';
        $pub_url  = '';

        // ── NEW: handle file: prefix returned by aiimgupload.php fallback ──
        if (strpos((string)$imageid, 'file:') === 0) {
            $rel   = substr((string)$imageid, 5); // strip "file:"
            $clean = ltrim(str_replace('\\', '/', $rel), '/');
            $candidate = rtrim(QA_INCLUDE_DIR, '/') . '/' . $clean;
            if (@file_exists($candidate) && @filesize($candidate) > 0) {
                $abs_path = $candidate;
            }
            $pub_url = rtrim(qa_opt('site_url'), '/') . '/king-include/' . $clean;
            error_log("king_resolve_upload_paths: file: prefix abs='{$abs_path}' url='{$pub_url}'");
            return ['abs_path' => $abs_path, 'pub_url' => $pub_url];
        }

        // ── Legacy path: prefix (old broken format — kept for safety) ──────
        if (strpos((string)$imageid, 'path:') === 0) {
            $rel   = substr((string)$imageid, 5);
            $clean = ltrim(str_replace('\\', '/', $rel), '/');
            $candidate = rtrim(QA_INCLUDE_DIR, '/') . '/' . $clean;
            if (@file_exists($candidate) && @filesize($candidate) > 0) {
                $abs_path = $candidate;
            }
            $pub_url = rtrim(qa_opt('site_url'), '/') . '/king-include/' . $clean;
            error_log("king_resolve_upload_paths: path: prefix abs='{$abs_path}' url='{$pub_url}'");
            return ['abs_path' => $abs_path, 'pub_url' => $pub_url];
        }

        // ── Standard: numeric DB id ──────────────────────────────────────────
        $info = [];
        if (function_exists('king_get_uploads')) {
            $row = king_get_uploads($imageid);
            if (is_array($row) && !empty($row)) $info = $row;
        }
        if (empty($info)) {
            try {
                $db_row = qa_db_read_one_assoc(
                    qa_db_query_sub('SELECT * FROM ^uploads WHERE id=#', (int)$imageid), true
                );
                if (is_array($db_row)) $info = $db_row;
            } catch (Exception $e) {
                error_log("king_resolve_upload_paths DB error: " . $e->getMessage());
            }
        }
        if (empty($info)) {
            error_log("king_resolve_upload_paths: no record for imageid={$imageid}");
            return ['abs_path' => '', 'pub_url' => ''];
        }

        $stored = '';
        foreach (['path', 'url', 'filepath', 'filename', 'file'] as $k) {
            if (!empty($info[$k])) { $stored = (string)$info[$k]; break; }
        }
        $furl = '';
        foreach (['furl', 'cloudurl', 'aws_url', 'cdn_url', 'remote_url'] as $k) {
            if (!empty($info[$k])) { $furl = (string)$info[$k]; break; }
        }

        if (!empty($furl) && filter_var($furl, FILTER_VALIDATE_URL)) {
            $pub_url = $furl;
        } elseif (!empty($stored) && filter_var($stored, FILTER_VALIDATE_URL)) {
            $pub_url = $stored;
        } elseif (!empty($stored)) {
            $clean = ltrim(str_replace('\\', '/', $stored), '/');
            $bases = array_filter([
                QA_INCLUDE_DIR,
                defined('QA_BASE_DIR') ? QA_BASE_DIR : '',
                dirname(QA_INCLUDE_DIR) . '/',
                isset($_SERVER['DOCUMENT_ROOT']) ? rtrim($_SERVER['DOCUMENT_ROOT'], '/') . '/' : '',
            ]);
            foreach ($bases as $base) {
                $candidate = rtrim($base, '/') . '/' . $clean;
                if (@file_exists($candidate) && @filesize($candidate) > 0) {
                    $abs_path = $candidate;
                    break;
                }
            }
            $pub_url = rtrim(qa_opt('site_url'), '/') . '/king-include/' . $clean;
        }

        error_log("king_resolve_upload_paths: imageid={$imageid} abs='{$abs_path}' url='{$pub_url}'");
        return ['abs_path' => $abs_path, 'pub_url' => $pub_url];
    }
}

// ============================================================
// HELPER: Get reference image binary from abs path or public URL
// ============================================================
if (!function_exists('king_get_ref_binary')) {
    function king_get_ref_binary($abs_path, $pub_url) {
        if (!empty($abs_path) && @file_exists($abs_path)) {
            $data = @file_get_contents($abs_path);
            if ($data !== false && strlen($data) > 100) {
                error_log("king_get_ref_binary: loaded from abs_path (" . strlen($data) . " bytes)");
                return $data;
            }
        }
        if (!empty($pub_url) && filter_var($pub_url, FILTER_VALIDATE_URL)) {
            $ch = curl_init($pub_url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_USERAGENT, 'KingAI/1.0');
            $data  = curl_exec($ch);
            $code  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $c_err = curl_error($ch);
            curl_close($ch);
            if (empty($c_err) && $code === 200 && $data !== false && strlen($data) > 100) {
                error_log("king_get_ref_binary: downloaded from pub_url ({$code}, " . strlen($data) . " bytes)");
                return $data;
            }
            error_log("king_get_ref_binary: pub_url failed code={$code} err={$c_err}");
        }
        error_log("king_get_ref_binary: FAILED — both abs_path and pub_url empty or unreachable");
        return false;
    }
}

// ============================================================
// HELPER: Detect MIME type
// ============================================================
if (!function_exists('king_detect_mime')) {
    function king_detect_mime($abs_path, $data = null) {
        if (!empty($abs_path) && @file_exists($abs_path) && function_exists('mime_content_type')) {
            $m = @mime_content_type($abs_path);
            if ($m) return $m;
        }
        if (!empty($data)) {
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $m = $finfo->buffer($data);
            if ($m) return $m;
        }
        return 'image/jpeg';
    }
}

// ============================================================
// HELPER: Resize binary to max dimension (avoids huge Fal payloads)
// ============================================================
if (!function_exists('king_resize_binary')) {
    function king_resize_binary($binary, &$mime, $max_px = 1536) {
        if (!extension_loaded('gd')) return $binary;
        if (strlen($binary) < 1.5 * 1024 * 1024) return $binary; // skip if already small

        $tmp = tempnam(sys_get_temp_dir(), 'krsz_');
        file_put_contents($tmp, $binary);
        $si = @getimagesize($tmp);
        if (!$si || ($si[0] <= $max_px && $si[1] <= $max_px)) {
            @unlink($tmp);
            return $binary;
        }

        $fn_map = [
            IMAGETYPE_JPEG => 'imagecreatefromjpeg',
            IMAGETYPE_PNG  => 'imagecreatefrompng',
            IMAGETYPE_WEBP => 'imagecreatefromwebp',
            IMAGETYPE_GIF  => 'imagecreatefromgif',
        ];
        $fn  = $fn_map[$si[2]] ?? null;
        if (!$fn) { @unlink($tmp); return $binary; }

        $src = @$fn($tmp);
        @unlink($tmp);
        if (!$src) return $binary;

        $scale = min($max_px / $si[0], $max_px / $si[1]);
        $nw    = max(1, (int)($si[0] * $scale));
        $nh    = max(1, (int)($si[1] * $scale));
        $dst   = imagecreatetruecolor($nw, $nh);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $si[0], $si[1]);
        imagedestroy($src);

        $out = tempnam(sys_get_temp_dir(), 'krsz_out_');
        imagejpeg($dst, $out, 88);
        imagedestroy($dst);

        $result = file_get_contents($out);
        @unlink($out);

        if ($result && strlen($result) > 100) {
            $mime = 'image/jpeg';
            error_log("king_resize_binary: resized {$si[0]}x{$si[1]} → {$nw}x{$nh}");
            return $result;
        }
        return $binary;
    }
}

// ============================================================
// FAL AI HELPERS
// ============================================================
if (!function_exists('king_fal_upload_storage')) {
    function king_fal_upload_storage($binary_data, $mime_type, $fal_api_key) {
        $ch = curl_init('https://rest.alpha.fal.ai/storage/upload/data');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $binary_data);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Key ' . $fal_api_key,
            'Content-Type: ' . $mime_type,
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 90);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 20);
        $response = curl_exec($ch);
        $code     = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $c_err    = curl_error($ch);
        curl_close($ch);
        if (!empty($c_err) || $code !== 200) {
            error_log("king_fal_upload_storage: FAILED code={$code} err={$c_err} body=" . substr((string)$response, 0, 300));
            return '';
        }
        $data = json_decode($response, true);
        $url  = $data['access_url'] ?? ($data['url'] ?? '');
        error_log("king_fal_upload_storage: OK url={$url}");
        return (string)$url;
    }
}

if (!function_exists('king_fal_queue_submit')) {
    function king_fal_queue_submit($endpoint, $payload, $fal_api_key) {
        $ch = curl_init('https://queue.fal.run/' . $endpoint);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Key ' . $fal_api_key,
            'Content-Type: application/json',
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 20);
        $response = curl_exec($ch);
        $code     = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $c_err    = curl_error($ch);
        curl_close($ch);
        if (!empty($c_err)) return ['error' => 'Fal submit CURL: ' . $c_err];
        if ($code !== 200 && $code !== 201) {
            return ['error' => 'Fal submit HTTP ' . $code . ': ' . substr((string)$response, 0, 200)];
        }
        $data = json_decode($response, true);
        $rid  = $data['request_id'] ?? '';
        if (empty($rid)) return ['error' => 'Fal returned no request_id. Body: ' . substr((string)$response, 0, 200)];
        return ['request_id' => $rid];
    }
}

if (!function_exists('king_fal_queue_poll')) {
    function king_fal_queue_poll($endpoint, $request_id, $fal_api_key, $max_attempts = 90, $sleep_sec = 5) {
        $base       = 'https://queue.fal.run/' . $endpoint . '/requests/' . $request_id;
        $status_url = $base . '/status';
        $headers    = ['Authorization: Key ' . $fal_api_key];

        for ($i = 0; $i < $max_attempts; $i++) {
            sleep($sleep_sec);
            $ch = curl_init($status_url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            $raw  = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($code !== 200 || empty($raw)) continue;

            $status = json_decode($raw, true);
            $state  = strtoupper($status['status'] ?? '');
            error_log("king_fal_queue_poll: attempt={$i} state={$state} rid={$request_id}");

            if ($state === 'COMPLETED') {
                $ch = curl_init($base);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                curl_setopt($ch, CURLOPT_TIMEOUT, 30);
                $res_raw  = curl_exec($ch);
                $res_code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                if ($res_code !== 200) return ['error' => 'Fal result fetch HTTP ' . $res_code];
                $result = json_decode($res_raw, true);
                $images = $result['images'] ?? [];
                if (empty($images)) return ['error' => 'Fal completed but returned no images'];
                $urls = [];
                foreach ($images as $img) {
                    if (!empty($img['url'])) $urls[] = $img['url'];
                }
                error_log("king_fal_queue_poll: COMPLETED with " . count($urls) . " image(s)");
                return ['urls' => $urls];
            }
            if ($state === 'FAILED') {
                $err = $status['error'] ?? ($status['detail'] ?? 'Unknown Fal error');
                return ['error' => 'Fal generation failed: ' . $err];
            }
        }
        return ['error' => 'Fal timed out after ' . ($max_attempts * $sleep_sec) . 's'];
    }
}

// ============================================================
// LUMA HELPERS (unchanged)
// ============================================================
if (!function_exists('king_luma_clean_key')) {
    function king_luma_clean_key($key) {
        $key = trim((string)$key);
        $key = preg_replace('~^Bearer\s+~i', '', $key);
        return trim($key);
    }
}
if (!function_exists('king_luma_pick_aspect_ratio')) {
    function king_luma_pick_aspect_ratio($imsize) {
        $supported = ['1:1'=>1.0,'3:4'=>3/4,'4:3'=>4/3,'9:16'=>9/16,'16:9'=>16/9,'9:21'=>9/21,'21:9'=>21/9];
        $s = trim((string)$imsize);
        if (preg_match('~(\d+\s*:\s*\d+)~', $s, $m)) {
            $ratio = str_replace(' ', '', $m[1]);
            return isset($supported[$ratio]) ? $ratio : '16:9';
        }
        if (preg_match('~^(\d+)x(\d+)$~', $s, $m)) {
            $w = $m[1]; $h = $m[2];
            if ($w > 0 && $h > 0) {
                $r = $w / $h; $bk = '16:9'; $bd = PHP_FLOAT_MAX;
                foreach ($supported as $k => $v) { $d = abs($r - $v); if ($d < $bd) { $bd = $d; $bk = $k; } }
                return $bk;
            }
        }
        return '16:9';
    }
}
if (!function_exists('king_luma_request_json')) {
    function king_luma_request_json($method, $url, $apiKey, $payload = null, &$http = 0, &$raw = '', &$curlErr = '') {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 20);
        $headers = ["Authorization: Bearer {$apiKey}", "Accept: application/json"];
        $method  = strtoupper($method);
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            $headers[] = "Content-Type: application/json";
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        } elseif ($method !== 'GET') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        $raw  = curl_exec($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if (curl_errno($ch)) $curlErr = curl_error($ch);
        curl_close($ch);
        $json = @json_decode((string)$raw, true);
        return is_array($json) ? $json : null;
    }
}
if (!function_exists('king_luma_download_file')) {
    function king_luma_download_file($url, $destPath, &$err = '') {
        $fp = @fopen($destPath, 'w');
        if (!$fp) { $err = 'Failed to create file.'; return false; }
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_FILE, $fp);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 180);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_USERAGENT, 'KingAI/1.0');
        $ok = curl_exec($ch); $ce = curl_error($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch); fclose($fp);
        if (!$ok || !empty($ce) || $code >= 400) {
            @unlink($destPath);
            $fp = @fopen($destPath, 'w'); if (!$fp) { $err = 'Retry open fail'; return false; }
            $ch = curl_init($url); curl_setopt($ch, CURLOPT_FILE, $fp);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true); curl_setopt($ch, CURLOPT_TIMEOUT, 180);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); curl_setopt($ch, CURLOPT_USERAGENT, 'KingAI/1.0');
            $ok = curl_exec($ch); $ce = curl_error($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch); fclose($fp);
        }
        if (!$ok || !empty($ce) || $code >= 400) { @unlink($destPath); $err = "Download failed. HTTP {$code}. {$ce}"; return false; }
        if (!file_exists($destPath) || filesize($destPath) < 5000) { @unlink($destPath); $err = 'Downloaded file too small.'; return false; }
        return true;
    }
}
if (!function_exists('king_convert_to_dalle_png')) {
    function king_convert_to_dalle_png($sourcePath) {
        if (!extension_loaded('gd')) return false;
        $info = @getimagesize($sourcePath);
        if (!$info) return false;
        list($srcW, $srcH, $type) = $info;
        switch ($type) {
            case IMAGETYPE_JPEG: $src = @imagecreatefromjpeg($sourcePath); break;
            case IMAGETYPE_PNG:  $src = @imagecreatefrompng($sourcePath);  break;
            case IMAGETYPE_WEBP: $src = @imagecreatefromwebp($sourcePath); break;
            case IMAGETYPE_GIF:  $src = @imagecreatefromgif($sourcePath);  break;
            default: return false;
        }
        if (!$src) return false;
        $dim    = 1024;
        $canvas = imagecreatetruecolor($dim, $dim);
        imagesavealpha($canvas, true);
        imagefill($canvas, 0, 0, imagecolorallocatealpha($canvas, 0, 0, 0, 127));
        $scale = min($dim / $srcW, $dim / $srcH);
        $nw    = max(1, (int)($srcW * $scale));
        $nh    = max(1, (int)($srcH * $scale));
        $ox    = (int)(($dim - $nw) / 2);
        $oy    = (int)(($dim - $nh) / 2);
        imagecopyresampled($canvas, $src, $ox, $oy, 0, 0, $nw, $nh, $srcW, $srcH);
        imagedestroy($src);
        $tmpPath = sys_get_temp_dir() . '/dalle_edit_' . time() . mt_rand(100, 999) . '.png';
        $ok      = imagepng($canvas, $tmpPath);
        imagedestroy($canvas);
        if (!$ok || !file_exists($tmpPath) || filesize($tmpPath) > 4 * 1024 * 1024) {
            @unlink($tmpPath);
            return false;
        }
        return $tmpPath;
    }
}

// ============================================================
// GET USER INPUT
// ============================================================
$is_logged_in = qa_is_logged_in();
$userid   = $is_logged_in ? qa_get_logged_in_userid() : qa_remote_ip_address();
$input    = trim((string)qa_post_text('input'));
$aiselect = trim((string)qa_post_text('selectElement'));
$imsize   = trim((string)qa_post_text('radioBut')) ?: '1024x1024';
$imageid  = trim((string)qa_post_text('imageid'));   // may be empty in new flow
$npvalue  = trim((string)qa_post_text('npvalue'));
$aistyle  = trim((string)qa_post_text('aistyle'));

// ── NEW: base64 reference image sent directly by king-ask.js ────────────────
// Format: "data:image/jpeg;base64,/9j/..."
// We decode this immediately so all downstream code uses $ref_binary directly.
$ref_image_b64_raw = trim((string)qa_post_text('ref_image_b64'));
$ref_binary        = false;   // will hold raw image bytes when available
$ref_mime          = 'image/jpeg';

if (!empty($ref_image_b64_raw)) {
    // Strip the data URI prefix: "data:image/jpeg;base64,"
    if (strpos($ref_image_b64_raw, ',') !== false) {
        $parts = explode(',', $ref_image_b64_raw, 2);
        // Extract MIME from "data:image/jpeg;base64"
        if (preg_match('~data:([^;]+);~', $parts[0], $m)) {
            $ref_mime = $m[1]; // e.g. "image/jpeg"
        }
        $b64_clean = $parts[1];
    } else {
        $b64_clean = $ref_image_b64_raw;
    }

    $decoded = base64_decode($b64_clean, true);
    if ($decoded !== false && strlen($decoded) > 100) {
        $ref_binary = $decoded;
        error_log("aigenerate: ref_image_b64 decoded OK — " . strlen($ref_binary) . " bytes, mime={$ref_mime}");
    } else {
        error_log("aigenerate: ref_image_b64 decode FAILED");
    }
}

error_log("aigenerate: aiselect={$aiselect} imageid={$imageid} has_b64=" . ($ref_binary !== false ? 'yes' : 'no') . " imsize={$imsize} aistyle={$aistyle}");

// ============================================================
// CHECK CREDITS / LIMITS
// ============================================================
$chkk  = true;
$error = '';

if (qa_opt('enable_membership') && (qa_opt('ailimits') || qa_opt('ulimits')) && qa_get_logged_in_level() <= QA_USER_LEVEL_ADMIN) {
    $chkk = kingai_check();
}
if ($chkk && qa_opt('enable_credits') && qa_opt('post_ai')) {
    $chkk = king_spend_credit(qa_opt('post_ai'));
}

// Selfie mode: prompt is optional
$effective_input = ($aiselect === 'fluxkon_selfie')
    ? ($input ?: 'enhance this photo with a beautiful stylised look')
    : $input;

if (!$effective_input || !$chkk) {
    echo "QA_AJAX_RESPONSE\n0\n" . json_encode(['success' => false, 'message' => qa_lang_html('misc/nocredits')]) . "\n";
    exit;
}

// ============================================================
// INITIALIZE
// ============================================================
$imagen           = (int)qa_opt('kingai_imgn') ?: 1;
$image_urls       = [];
$uploaded_images  = [];
$thumbs           = [];
$gemini_processed = false;
$style_preset     = $aistyle;

// ============================================================
// RESOLVE LEGACY REFERENCE IMAGE (imageid path — fallback only)
//
// In the new flow ref_binary is already set from ref_image_b64.
// This block only runs for regenerate/reuse requests that have
// a stored numeric or file: imageid in postmeta.
// ============================================================
$ref_abs_path = '';
$ref_pub_url  = '';

if ($ref_binary === false && !empty($imageid)) {
    $resolved     = king_resolve_upload_paths($imageid);
    $ref_abs_path = $resolved['abs_path'];
    $ref_pub_url  = $resolved['pub_url'];

    if (empty($ref_abs_path) && empty($ref_pub_url)) {
        error_log("aigenerate WARNING: could not resolve imageid={$imageid}");
    } else {
        // Try to load binary now so all provider branches have it
        $loaded = king_get_ref_binary($ref_abs_path, $ref_pub_url);
        if ($loaded !== false) {
            $ref_binary = $loaded;
            $ref_mime   = king_detect_mime($ref_abs_path, $ref_binary);
            error_log("aigenerate: legacy imageid resolved — " . strlen($ref_binary) . " bytes");
        }
    }
}

// ============================================================
// TRY GATEWAY FIRST (non-selfie models only)
// ============================================================
$use_gateway = (qa_opt('gateway_enabled') == '1' && !empty(qa_opt('gateway_url')));

if ($use_gateway && $aiselect !== 'fluxkon_selfie' && class_exists('Ebonix_Gateway') && Ebonix_Gateway::enabled()) {
    error_log("Gateway: attempting image generation. model={$aiselect}");
    try {
        $gateway_image_data = null;

        // Pass ref image to gateway if we have binary
        if ($ref_binary !== false) {
            $gateway_image_data = [
                'base64'    => base64_encode($ref_binary),
                'mime_type' => $ref_mime,
                'imageid'   => $imageid,
                'furl'      => $ref_pub_url,
            ];
        } elseif (!empty($ref_pub_url)) {
            $gateway_image_data = ['furl' => $ref_pub_url, 'imageid' => $imageid];
        }

        $gateway_result = Ebonix_Gateway::generate_image(
            $effective_input, $aiselect ?: 'auto', $imsize, $aistyle, $npvalue, $gateway_image_data
        );

        if (!empty($gateway_result['success'])) {
            $image_data = $gateway_result['image_url'];

            if (strpos($image_data, 'data:image') === 0) {
                $parts = explode(',', $image_data, 2);
                if (count($parts) == 2) {
                    $image_binary = base64_decode($parts[1]);
                    $folder   = 'uploads/' . date('Y') . '/' . date('m') . '/';
                    $destDir  = QA_INCLUDE_DIR . $folder;
                    if (!is_dir($destDir)) mkdir($destDir, 0755, true);
                    $filename    = 'gateway-img-' . time() . '-' . mt_rand(1000, 9999) . '.webp';
                    $upload_path = $destDir . $filename;
                    $tmp_file    = tempnam(sys_get_temp_dir(), 'ebonix_');
                    file_put_contents($tmp_file, $image_binary);
                    rename($tmp_file, $upload_path);
                    $imageInfo  = @getimagesize($upload_path);
                    $img_width  = $imageInfo ? $imageInfo[0] : 1024;
                    $img_height = $imageInfo ? $imageInfo[1] : 1024;
                    $thumb_result = king_process_local_image($upload_path, $folder . $filename, true, 600);
                    if (qa_opt('enable_aws')) {
                        $aws_url     = king_upload_to_cloud($upload_path, $filename, 'aws');
                        $full_result = king_insert_uploads($aws_url, 'webp', $img_width, $img_height, 'aws');
                    } elseif (qa_opt('enable_wasabi')) {
                        $wasabi_url  = king_upload_to_cloud($upload_path, $filename, 'wasabi');
                        $full_result = king_insert_uploads($wasabi_url, 'webp', $img_width, $img_height, 'wasabi');
                    } else {
                        $full_result = king_insert_uploads($folder . $filename, 'webp', $img_width, $img_height);
                    }
                    if ($thumb_result && $full_result) {
                        $uploaded_images[] = $full_result;
                        $thumbs[]          = $thumb_result;
                        $gemini_processed  = true;
                        error_log("✅ GATEWAY SUCCESS (base64)");
                    }
                }
            } elseif (filter_var($image_data, FILTER_VALIDATE_URL)) {
                $image_urls = [$image_data];
                error_log("✅ GATEWAY SUCCESS (url)");
            }
        } else {
            error_log("⚠️ GATEWAY FAILED: " . ($gateway_result['error'] ?? 'unknown'));
        }
    } catch (Exception $e) {
        error_log("Gateway exception: " . $e->getMessage());
    }
}

// ============================================================
// DIRECT API CALLS
// ============================================================
if (!$gemini_processed && empty($image_urls)) {

    // ──────────────────────────────────────────────────────────────────────
    // FAL AI — FLUX.1 Kontext (Selfie / i2i transformation)
    // ──────────────────────────────────────────────────────────────────────
    if ($aiselect === 'fluxkon_selfie') {

        $fal_api_key = qa_opt('fal_api');

        if (empty($fal_api_key)) {
            $error = 'Fal AI API key not configured. Go to Admin → AI Settings and add your Fal API Key.';
        } elseif ($ref_binary === false) {
            // THE CRITICAL FIX: old code checked `empty($imageid)` here which
            // always failed in the new flow. We check $ref_binary instead.
            $error = 'Please attach a photo before generating.';
        } else {
            // ── Style preset → prompt ──────────────────────────────────────
            $style_prompt_map = [
                'selfie_luxury_editorial' => 'Transform this person into a luxury fashion editorial portrait. High-end designer fashion, dramatic studio lighting with deep shadows, magazine cover quality. Preserve the person\'s exact facial features, skin tone, and identity. No change to skin color.',
                'selfie_soft_glam'        => 'Transform this person into a soft glam beauty portrait. Natural glowing skin, warm golden hour light, soft bokeh background, effortless elegant styling. Preserve the person\'s exact facial features, skin tone, and identity.',
                'selfie_professional'     => 'Transform this person into a professional corporate headshot. Clean neutral background, business-professional attire, soft natural studio lighting. Preserve the person\'s exact facial features, skin tone, and identity.',
                'selfie_vacation'         => 'Transform this person into a vacation lifestyle photo. Bright golden hour setting, sun-kissed travel aesthetic, casual chic relaxed style. Preserve the person\'s exact facial features, skin tone, and identity.',
                'selfie_afro_futurist'    => 'Transform this person into an Afro-futurist aesthetic portrait. Bold colors, cultural African futurist styling, powerful presence, celebration of identity and heritage. Preserve the person\'s exact facial features, skin tone, and identity.',
            ];
            $base_prompt = $style_prompt_map[$aistyle]
                ?? 'Enhance and stylize this photo beautifully while preserving the person\'s exact identity, facial features, and skin tone.';
            if (!empty($input)) {
                $base_prompt .= ' Additional details: ' . $input;
            }

            error_log("Fal Kontext: style={$aistyle} prompt=" . substr($base_prompt, 0, 80) . '...');

            // ── Resize if too large ────────────────────────────────────────
            $fal_binary = king_resize_binary($ref_binary, $ref_mime, 1536);
            $fal_mime   = $ref_mime;

            // ── Upload to Fal storage ──────────────────────────────────────
            error_log("Fal: uploading " . strlen($fal_binary) . " bytes ({$fal_mime}) to Fal storage");
            $fal_image_url = king_fal_upload_storage($fal_binary, $fal_mime, $fal_api_key);

            if (empty($fal_image_url)) {
                // Fallback: use public URL if the site is publicly reachable
                if (!empty($ref_pub_url) && filter_var($ref_pub_url, FILTER_VALIDATE_URL)) {
                    $fal_image_url = $ref_pub_url;
                    error_log("Fal: storage upload failed — falling back to pub_url={$fal_image_url}");
                } else {
                    $error = 'Failed to upload your photo to Fal AI. Please try again.';
                }
            }

            if (!$error && !empty($fal_image_url)) {
                // ── Submit queue job ───────────────────────────────────────
                $num_imgs    = max(1, min(2, (int)$imagen));
                $fal_payload = [
                    'prompt'              => $base_prompt,
                    'image_url'           => $fal_image_url,
                    'num_images'          => $num_imgs,
                    'guidance_scale'      => 2.5,
                    'num_inference_steps' => 28,
                    'output_format'       => 'jpeg',
                    'safety_tolerance'    => '2',
                ];

                error_log("Fal: submitting job to fal-ai/flux-pro/kontext");
                $submit = king_fal_queue_submit('fal-ai/flux-pro/kontext', $fal_payload, $fal_api_key);

                if (!empty($submit['error'])) {
                    $error = $submit['error'];
                    error_log("Fal submit error: {$error}");
                } else {
                    $request_id = $submit['request_id'];
                    error_log("Fal: job submitted request_id={$request_id}");

                    // ── Poll ──────────────────────────────────────────────
                    $poll = king_fal_queue_poll('fal-ai/flux-pro/kontext', $request_id, $fal_api_key, 90, 5);

                    if (!empty($poll['error'])) {
                        $error = $poll['error'];
                        error_log("Fal poll error: {$error}");
                    } else {
                        $image_urls = $poll['urls'];
                        error_log("✅ Fal Kontext SUCCESS: " . count($image_urls) . " image(s)");
                    }
                }
            }
        }

    // ──────────────────────────────────────────────────────────────────────
    // OPENAI DALL-E
    // ──────────────────────────────────────────────────────────────────────
    } elseif ($aiselect === 'de' || $aiselect === 'de3') {
        $openaiapi = qa_opt('king_leo_api');
        if (empty($openaiapi)) {
            $error = 'OpenAI API key not configured';
        } else {
            // DALL-E 2 edit when a reference image is available
            if ($aiselect === 'de' && $ref_binary !== false) {
                $tmp_ref  = tempnam(sys_get_temp_dir(), 'dalle_ref_') . '.jpg';
                file_put_contents($tmp_ref, $ref_binary);
                $png_path = king_convert_to_dalle_png($tmp_ref);
                @unlink($tmp_ref);
                if ($png_path) {
                    $edit_size = in_array($imsize, ['256x256', '512x512', '1024x1024']) ? $imsize : '1024x1024';
                    $ch = curl_init('https://api.openai.com/v1/images/edits');
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch, CURLOPT_POST, true);
                    curl_setopt($ch, CURLOPT_TIMEOUT, 120);
                    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $openaiapi]);
                    curl_setopt($ch, CURLOPT_POSTFIELDS, [
                        'image'  => new CURLFile($png_path, 'image/png', 'image.png'),
                        'prompt' => $effective_input,
                        'n'      => 1,
                        'size'   => $edit_size,
                    ]);
                    $response_body = curl_exec($ch);
                    if (curl_errno($ch)) $error = 'DALL-E API Error: ' . curl_error($ch);
                    curl_close($ch);
                    @unlink($png_path);
                    if (!$error) {
                        $ro = json_decode($response_body, true);
                        if (isset($ro['data'])) {
                            foreach ($ro['data'] as $img) {
                                if (!empty($img['url'])) $image_urls[] = $img['url'];
                            }
                        } else {
                            error_log("DALL-E edit failed — falling back to generate. " . ($ro['error']['message'] ?? ''));
                        }
                    }
                }
            }
            // Text-to-image generation (or fallback)
            if (empty($image_urls) && empty($error)) {
                $params_gen = ($aiselect === 'de3') ? [
                    'model'  => 'dall-e-3',
                    'prompt' => $effective_input,
                    'n'      => 1,
                    'size'   => $imsize,
                ] : [
                    'prompt' => $effective_input,
                    'n'      => (int)$imagen,
                    'size'   => $imsize,
                ];
                $ch = curl_init('https://api.openai.com/v1/images/generations');
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($params_gen));
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'Authorization: Bearer ' . $openaiapi]);
                curl_setopt($ch, CURLOPT_TIMEOUT, 120);
                $response_body = curl_exec($ch);
                if (curl_errno($ch)) $error = 'API Error: ' . curl_error($ch);
                curl_close($ch);
                if (!$error) {
                    $ro = json_decode($response_body, true);
                    if (isset($ro['data'])) {
                        foreach ($ro['data'] as $img) {
                            if (!empty($img['url'])) $image_urls[] = $img['url'];
                        }
                    } else {
                        $error = $ro['error']['message'] ?? 'OpenAI returned no images';
                    }
                }
            }
        }

    // ──────────────────────────────────────────────────────────────────────
    // GOOGLE IMAGEN 4
    // ──────────────────────────────────────────────────────────────────────
    } elseif ($aiselect === 'imagen4') {
        $API_KEY = qa_opt('gemini_api');
        if (empty($API_KEY)) {
            $error = 'Gemini API key not configured';
        } else {
            $aspect_ratio = function_exists('aisize_ratio') ? aisize_ratio($imsize) : '1:1';
            $api_url      = "https://generativelanguage.googleapis.com/v1beta/models/imagen-4.0-generate-001:predict?key={$API_KEY}";
            $instance     = ['prompt' => $effective_input];

            if ($ref_binary !== false) {
                $instance['referenceImages'] = [[
                    'referenceType'  => 'REFERENCE_TYPE_STYLE',
                    'referenceId'    => 1,
                    'referenceImage' => [
                        'bytesBase64Encoded' => base64_encode($ref_binary),
                        'mimeType'           => $ref_mime,
                    ],
                ]];
            }

            $payload = [
                'instances'  => [$instance],
                'parameters' => ['sampleCount' => 1, 'aspectRatio' => $aspect_ratio, 'personGeneration' => 'ALLOW_ALL'],
            ];
            $ch = curl_init($api_url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
            curl_setopt($ch, CURLOPT_TIMEOUT, 120);
            $response = curl_exec($ch);
            if (curl_errno($ch)) $error = 'CURL ERROR: ' . curl_error($ch);
            curl_close($ch);
            if (!$error) {
                $data = json_decode($response, true);
                if (!isset($data['predictions'][0]['bytesBase64Encoded'])) {
                    $error = $data['error']['message'] ?? 'Imagen 4 did not return images';
                } else {
                    $image_binary = base64_decode($data['predictions'][0]['bytesBase64Encoded']);
                    $folder   = 'uploads/' . date('Y') . '/' . date('m') . '/';
                    $destDir  = QA_INCLUDE_DIR . $folder;
                    if (!file_exists($destDir)) mkdir($destDir, 0777, true);
                    $stamp = time() . '-' . mt_rand(1000, 9999);
                    $final = 'imagen4-' . $stamp . '.webp';
                    $tmp   = $destDir . 'temp_' . $final;
                    $full  = $destDir . $final;
                    file_put_contents($tmp, $image_binary);
                    $thumb_result = king_process_local_image($tmp, $folder . $final, true, 600);
                    if (copy($tmp, $full)) {
                        $ii = @getimagesize($full);
                        if ($ii) {
                            list($w, $h) = $ii;
                            if (qa_opt('enable_aws'))    { $u = king_upload_to_cloud($full, $final, 'aws');    $fr = king_insert_uploads($u, 'webp', $w, $h, 'aws'); }
                            elseif (qa_opt('enable_wasabi')) { $u = king_upload_to_cloud($full, $final, 'wasabi'); $fr = king_insert_uploads($u, 'webp', $w, $h, 'wasabi'); }
                            else                         { $fr = king_insert_uploads($folder . $final, 'webp', $w, $h); }
                            if ($thumb_result && $fr) { $uploaded_images[] = $fr; $thumbs[] = $thumb_result; $gemini_processed = true; }
                        }
                    }
                    @unlink($tmp);
                }
            }
        }

    // ──────────────────────────────────────────────────────────────────────
    // GEMINI BANANA — Black representation
    // ──────────────────────────────────────────────────────────────────────
    } elseif ($aiselect === 'banana') {
        $API_KEY = qa_opt('gemini_api');
        if (empty($API_KEY)) {
            $error = 'Gemini API key not configured';
        } else {
            $aspect_ratio    = function_exists('aisize_ratio') ? aisize_ratio($imsize) : '1:1';
            $enhanced_prompt = $effective_input;
            $person_keywords = ['person','people','human','girl','boy','child','kid','baby','woman','man','lady','guy','teen','teenager','adult','beautiful','handsome','cute','pretty','gorgeous','attractive','model','portrait','face'];
            $has_person      = false;
            $pl              = strtolower($enhanced_prompt);
            foreach ($person_keywords as $kw) {
                if (strpos($pl, $kw) !== false) { $has_person = true; break; }
            }
            if ($has_person) {
                $br = ['beautiful '=>'beautiful Black ','Beautiful '=>'Beautiful Black ','handsome '=>'handsome Black ','Handsome '=>'Handsome Black ','cute '=>'cute Black ','Cute '=>'Cute Black ','pretty '=>'pretty Black ','Pretty '=>'Pretty Black ','gorgeous '=>'gorgeous Black ','Gorgeous '=>'Gorgeous Black ','attractive '=>'attractive Black ','Attractive '=>'Attractive Black '];
                foreach ($br as $o => $r) $enhanced_prompt = str_replace($o, $r, $enhanced_prompt);
                if ($enhanced_prompt === $effective_input) {
                    foreach (['girl','boy','woman','man','person','people','child'] as $kw) {
                        if (stripos($enhanced_prompt, $kw) !== false) {
                            $enhanced_prompt = str_ireplace($kw, "Black {$kw}", $enhanced_prompt);
                            break;
                        }
                    }
                }
                $enhanced_prompt .= '. diverse Black skin tones ranging from light brown to deep ebony, natural Black hair with authentic curl patterns (3A-4C), authentic Black facial features, NO lightening or whitewashing, NO Eurocentric feature drift';
            }

            $parts = [['text' => $enhanced_prompt]];
            if ($ref_binary !== false) {
                $parts[] = ['inline_data' => ['mime_type' => $ref_mime, 'data' => base64_encode($ref_binary)]];
            }

            $api_url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-3-pro-image-preview:generateContent?key={$API_KEY}";
            $payload = ['contents' => [['parts' => $parts]], 'generationConfig' => ['imageConfig' => ['aspectRatio' => $aspect_ratio]]];
            $ch = curl_init($api_url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
            curl_setopt($ch, CURLOPT_TIMEOUT, 120);
            $response = curl_exec($ch);
            if (curl_errno($ch)) $error = 'CURL ERROR: ' . curl_error($ch);
            curl_close($ch);
            if (!$error) {
                $data = json_decode($response, true);
                $b64  = $data['candidates'][0]['content']['parts'][0]['inlineData']['data'] ?? '';
                if (empty($b64)) {
                    $error = $data['error']['message'] ?? 'Failed to generate image.';
                } else {
                    $image_binary = base64_decode($b64);
                    $folder   = 'uploads/' . date('Y') . '/' . date('m') . '/';
                    $destDir  = QA_INCLUDE_DIR . $folder;
                    if (!file_exists($destDir)) mkdir($destDir, 0777, true);
                    $stamp = time() . '-' . mt_rand(1000, 9999);
                    $final = 'gemini-image-' . $stamp . '.webp';
                    $tmp   = $destDir . 'temp_' . $final;
                    $full  = $destDir . $final;
                    file_put_contents($tmp, $image_binary);
                    $tr = king_process_local_image($tmp, $folder . $final, true, 600);
                    if (copy($tmp, $full)) {
                        $ii = @getimagesize($full);
                        if ($ii) {
                            list($w, $h) = $ii;
                            if (qa_opt('enable_aws'))    { $u = king_upload_to_cloud($full, $final, 'aws');    $fr = king_insert_uploads($u, 'webp', $w, $h, 'aws'); }
                            elseif (qa_opt('enable_wasabi')) { $u = king_upload_to_cloud($full, $final, 'wasabi'); $fr = king_insert_uploads($u, 'webp', $w, $h, 'wasabi'); }
                            else                         { $fr = king_insert_uploads($folder . $final, 'webp', $w, $h); }
                            if ($tr && $fr) { $uploaded_images[] = $fr; $thumbs[] = $tr; $gemini_processed = true; }
                        }
                    }
                    @unlink($tmp);
                }
            }
        }

    // ──────────────────────────────────────────────────────────────────────
    // DECART IMAGE
    // ──────────────────────────────────────────────────────────────────────
    } elseif ($aiselect === 'decart_img') {
        $API_KEY = qa_opt('decart_api');
        if (empty($API_KEY)) {
            $error = 'Decart API key not configured';
        } else {
            $use_i2i     = false;
            $api_url     = 'https://api.decart.ai/v1/generate/lucy-pro-t2i';
            $post_fields = ['prompt' => $effective_input];
            if (!empty($npvalue)) $post_fields['negative_prompt'] = $npvalue;

            if ($ref_binary !== false) {
                $ext_map = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
                $te      = $ext_map[$ref_mime] ?? 'jpg';
                $tr      = tempnam(sys_get_temp_dir(), 'decart_ref_') . '.' . $te;
                file_put_contents($tr, $ref_binary);
                $api_url                  = 'https://api.decart.ai/v1/generate/lucy-pro-i2i';
                $post_fields['data']      = new CURLFile($tr, $ref_mime, 'reference.' . $te);
                $use_i2i = true;
            }

            $ch = curl_init($api_url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ["X-API-KEY: $API_KEY"]);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $post_fields);
            curl_setopt($ch, CURLOPT_TIMEOUT, 120);
            $image_data = curl_exec($ch);
            $http_code  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            if (curl_errno($ch)) $error = 'Decart API Error: ' . curl_error($ch);
            curl_close($ch);
            if ($use_i2i && !empty($tr) && file_exists($tr)) @unlink($tr);

            if (!$error) {
                if ($http_code !== 200) {
                    $je    = @json_decode($image_data, true);
                    $error = 'Decart HTTP ' . $http_code . ': ' . ($je['error']['message'] ?? substr($image_data, 0, 200));
                } elseif (empty($image_data)) {
                    $error = 'Decart returned empty response';
                } else {
                    $folder  = 'uploads/' . date('Y') . '/' . date('m') . '/';
                    $destDir = QA_INCLUDE_DIR . $folder;
                    if (!file_exists($destDir)) mkdir($destDir, 0777, true);
                    $stamp = time() . '-' . mt_rand(1000, 9999);
                    $final = 'decart-img-' . $stamp . '.png';
                    $tmp   = $destDir . 'temp_' . $final;
                    $full  = $destDir . $final;
                    file_put_contents($tmp, $image_data);
                    $ii = @getimagesize($tmp);
                    if (!$ii) {
                        $error = 'Decart returned invalid image';
                        @unlink($tmp);
                    } else {
                        $tr = king_process_local_image($tmp, $folder . $final, true, 600);
                        if (copy($tmp, $full)) {
                            list($w, $h) = $ii;
                            if (qa_opt('enable_aws'))    { $u = king_upload_to_cloud($full, $final, 'aws');    $fr = king_insert_uploads($u, 'png', $w, $h, 'aws'); }
                            elseif (qa_opt('enable_wasabi')) { $u = king_upload_to_cloud($full, $final, 'wasabi'); $fr = king_insert_uploads($u, 'png', $w, $h, 'wasabi'); }
                            else                         { $fr = king_insert_uploads($folder . $final, 'png', $w, $h); }
                            if ($tr && $fr) { $uploaded_images[] = $fr; $thumbs[] = $tr; $gemini_processed = true; }
                        }
                        @unlink($tmp);
                    }
                }
            }
        }

    // ──────────────────────────────────────────────────────────────────────
    // LUMA IMAGE
    // ──────────────────────────────────────────────────────────────────────
    } elseif ($aiselect === 'luma_img') {
        $API_KEY = king_luma_clean_key(qa_opt('luma_api'));
        if (empty($API_KEY)) {
            $error = 'Luma API key not configured';
        } else {
            $api_url      = 'https://api.lumalabs.ai/dream-machine/v1/generations/image';
            $aspect_ratio = king_luma_pick_aspect_ratio($imsize);
            $prompt       = (string)$effective_input;
            if (!empty($npvalue)) $prompt .= "\n\nAvoid: " . trim((string)$npvalue);

            $models_to_try = ['photon-flash-1', 'photon-1'];
            $generation_id = null;
            $create_err    = '';

            foreach ($models_to_try as $try_model) {
                $payload = ['prompt' => $prompt, 'aspect_ratio' => $aspect_ratio, 'model' => $try_model];

                // Luma needs a public URL for the reference image.
                // If we got the image from base64 we need to save it to disk first
                // so we have a public URL to give Luma.
                if ($ref_binary !== false) {
                    // Save temp file, get pub URL
                    $luma_ref_folder  = 'uploads/' . date('Y') . '/' . date('m') . '/';
                    $luma_ref_dir     = QA_INCLUDE_DIR . $luma_ref_folder;
                    if (!is_dir($luma_ref_dir)) mkdir($luma_ref_dir, 0755, true);
                    $luma_ref_name    = 'luma-ref-' . time() . '-' . mt_rand(1000, 9999) . '.jpg';
                    $luma_ref_path    = $luma_ref_dir . $luma_ref_name;
                    file_put_contents($luma_ref_path, $ref_binary);
                    $luma_ref_puburl  = rtrim(qa_opt('site_url'), '/') . '/king-include/' . $luma_ref_folder . $luma_ref_name;
                    $payload['modify_image_ref'] = ['url' => $luma_ref_puburl, 'weight' => 1.0];
                } elseif (!empty($ref_pub_url) && filter_var($ref_pub_url, FILTER_VALIDATE_URL)) {
                    $payload['modify_image_ref'] = ['url' => $ref_pub_url, 'weight' => 1.0];
                }

                $http = 0; $raw = ''; $ce = '';
                $out  = king_luma_request_json('POST', $api_url, $API_KEY, $payload, $http, $raw, $ce);
                if (!empty($ce)) { $create_err = "Luma CURL: {$ce}"; continue; }
                if (($http === 200 || $http === 201) && !empty($out['id'])) { $generation_id = $out['id']; break; }
                $create_err = "Luma HTTP {$http}: " . substr($raw, 0, 250);
            }

            if (empty($generation_id)) {
                $error = $create_err ?: 'Failed to create Luma generation';
            } else {
                for ($attempt = 0; $attempt < 75; $attempt++) {
                    sleep(4);
                    $su   = "https://api.lumalabs.ai/dream-machine/v1/generations/{$generation_id}";
                    $http = 0; $raw = ''; $ce = '';
                    $status = king_luma_request_json('GET', $su, $API_KEY, null, $http, $raw, $ce);
                    if (!empty($ce) || $http >= 400 || !is_array($status)) continue;
                    $state = strtolower((string)($status['state'] ?? ''));
                    if ($state === 'completed') {
                        $img_url = $status['assets']['image'] ?? '';
                        if (empty($img_url)) { $error = 'Luma completed but no image url'; break; }
                        $folder  = 'uploads/' . date('Y') . '/' . date('m') . '/';
                        $destDir = QA_INCLUDE_DIR . $folder;
                        if (!file_exists($destDir)) mkdir($destDir, 0777, true);
                        $stamp = time() . '-' . mt_rand(1000, 9999);
                        $path  = parse_url($img_url, PHP_URL_PATH);
                        $ext   = strtolower(pathinfo($path ?: '', PATHINFO_EXTENSION)) ?: 'jpg';
                        if ($ext === 'jpeg') $ext = 'jpg';
                        $tmp = $destDir . "temp_luma_{$stamp}.{$ext}";
                        $fn  = "luma-img-{$stamp}.{$ext}";
                        $fp  = $destDir . $fn;
                        $dle = '';
                        if (!king_luma_download_file($img_url, $tmp, $dle)) { $error = "Failed to download Luma image: {$dle}"; break; }
                        $ii = @getimagesize($tmp);
                        if (!$ii) { @unlink($tmp); $error = 'Luma image invalid.'; break; }
                        list($w, $h) = $ii;
                        $tr = king_process_local_image($tmp, $folder . $fn, true, 600);
                        if (!copy($tmp, $fp)) { @unlink($tmp); $error = 'Failed to save Luma image.'; break; }
                        if (qa_opt('enable_aws'))    { $u = king_upload_to_cloud($fp, $fn, 'aws');    $fr = king_insert_uploads($u, $ext, $w, $h, 'aws'); }
                        elseif (qa_opt('enable_wasabi')) { $u = king_upload_to_cloud($fp, $fn, 'wasabi'); $fr = king_insert_uploads($u, $ext, $w, $h, 'wasabi'); }
                        else                         { $fr = king_insert_uploads($folder . $fn, $ext, $w, $h); }
                        @unlink($tmp);
                        if ($tr && $fr) { $uploaded_images[] = $fr; $thumbs[] = $tr; $gemini_processed = true; }
                        else            { $error = 'Failed to save Luma image to DB.'; }
                        // Clean up temp Luma ref file if we created one
                        if (isset($luma_ref_path) && file_exists($luma_ref_path)) @unlink($luma_ref_path);
                        break;
                    } elseif ($state === 'failed') {
                        $error = 'Luma generation failed: ' . ($status['failure_reason'] ?? 'Unknown');
                        break;
                    }
                }
                if (!$gemini_processed && empty($error)) $error = 'Luma image generation timed out';
            }
        }

    // ──────────────────────────────────────────────────────────────────────
    // KINGSTUDIO (sdn, flux, sdream, fluxkon, etc.)
    // ──────────────────────────────────────────────────────────────────────
    } else {
        $sdapi = qa_opt('king_sd_api');
        if (empty($sdapi)) {
            $error = 'KingStudio API key not configured';
        } else {
            $as = is_string(qa_post_text('aistyle')) ? trim(qa_post_text('aistyle')) : '';
            if ($as === 'none') $as = '';
            $style_preset = $as;
            $aisteps      = qa_opt('king_sd_steps');
            $request_data = [
                'prompt' => $effective_input . ($style_preset ? ', ' . $style_preset : ''),
                'size'   => (int)$imagen,
                'steps'  => (int)$aisteps,
                'aisize' => $imsize,
                'model'  => $aiselect,
                'nvalue' => $npvalue,
                'ennsfw' => (bool)qa_opt('ennsfw'),
                'sdnsfw' => (bool)qa_opt('sdnsfw'),
            ];

            // KingStudio expects a public URL for reference images
            if ($ref_binary !== false && in_array($aiselect, ['fluxkon', 'sdream'])) {
                // Save to disk, pass pub URL
                $ks_ref_folder = 'uploads/' . date('Y') . '/' . date('m') . '/';
                $ks_ref_dir    = QA_INCLUDE_DIR . $ks_ref_folder;
                if (!is_dir($ks_ref_dir)) mkdir($ks_ref_dir, 0755, true);
                $ks_ref_name   = 'ks-ref-' . time() . '-' . mt_rand(1000, 9999) . '.jpg';
                $ks_ref_path   = $ks_ref_dir . $ks_ref_name;
                file_put_contents($ks_ref_path, $ref_binary);
                $request_data['image'] = rtrim(qa_opt('site_url'), '/') . '/king-include/' . $ks_ref_folder . $ks_ref_name;
            } elseif (!empty($ref_pub_url) && in_array($aiselect, ['fluxkon', 'sdream'])) {
                $request_data['image'] = $ref_pub_url;
            }

            $ch = curl_init('https://kingstudio.io/api/king-text2img');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                "Authorization: Bearer $sdapi",
                'Accept: application/json',
                'Content-Type: application/json',
            ]);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($request_data));
            curl_setopt($ch, CURLOPT_TIMEOUT, 180);
            $response = curl_exec($ch);
            if (curl_errno($ch)) $error = 'API Error: ' . curl_error($ch);
            curl_close($ch);
            if (!$error) {
                $out = json_decode($response, true);
                if (isset($out['error'])) {
                    $error = $out['error'];
                } else {
                    $image_urls = $out['out'] ?? [];
                    if (empty($image_urls)) $error = 'KingStudio returned no images';
                }
            }
            // Clean up temp KingStudio ref file
            if (isset($ks_ref_path) && file_exists($ks_ref_path)) @unlink($ks_ref_path);
        }
    }
}

// ============================================================
// HANDLE ERRORS
// ============================================================
if (!empty($error)) {
    error_log("aigenerate ERROR: model={$aiselect} error={$error}");
    echo "QA_AJAX_RESPONSE\n0\n" . json_encode(['success' => false, 'message' => $error]) . "\n";
    exit;
}

// ============================================================
// PROCESS URL-BASED RESULTS (Fal, DALL-E, KingStudio)
// ============================================================
if (!$gemini_processed && !empty($image_urls)) {
    foreach ($image_urls as $image_url) {
        $image_url = trim((string)$image_url);
        if ($image_url === '') continue;
        try {
            $thumb = king_urlupload($image_url, true, 600);
            if (!empty($thumb)) $thumbs[] = $thumb;
            $upload_response = king_urlupload($image_url);
            if (!empty($upload_response)) $uploaded_images[] = $upload_response;
        } catch (Exception $e) {
            error_log("Image upload failed: " . $e->getMessage());
        }
    }
}

if (empty($uploaded_images)) {
    echo "QA_AJAX_RESPONSE\n0\n" . json_encode(['success' => false, 'message' => 'Failed to save generated images']) . "\n";
    exit;
}

// ============================================================
// CREATE POST
// ============================================================
$extra    = serialize($uploaded_images);
$thumb    = end($thumbs);
$cookieid = $is_logged_in ? qa_cookie_get() : qa_cookie_get_create();

$postid = qa_question_create(
    null, $userid,
    $is_logged_in ? qa_get_logged_in_handle() : null,
    $cookieid, null, $thumb, '', null, null, null, null, null,
    $extra, 'NOTE', null, 'aimg', $effective_input, null
);

qa_db_postmeta_set($postid, 'wai',   true);
qa_db_postmeta_set($postid, 'model', $aiselect);
if (!empty($npvalue))      qa_db_postmeta_set($postid, 'nprompt', $npvalue);
if (!empty($style_preset)) qa_db_postmeta_set($postid, 'stle',    $style_preset);
if (!empty($imsize))       qa_db_postmeta_set($postid, 'asize',   $imsize);

// Store ref image association when one was used
$i2i_models_for_meta = ['fluxkon', 'sdream', 'banana', 'decart_img', 'luma_img', 'imagen4', 'de', 'fluxkon_selfie'];
if ($ref_binary !== false && in_array($aiselect, $i2i_models_for_meta)) {
    // imageid may be empty in new flow — store 'b64' as a marker so regenerate
    // knows a reference image was used (user will need to re-upload to regenerate)
    qa_db_postmeta_set($postid, 'pimage', !empty($imageid) ? $imageid : 'b64');
}

if (qa_opt('enable_membership') && (qa_opt('ailimits') || qa_opt('ulimits'))) {
    kingai_imagen($imagen);
}

error_log("✅ aigenerate complete: postid={$postid} model={$aiselect}");

echo "QA_AJAX_RESPONSE\n1\n" . json_encode(['success' => true, 'postid' => $postid]) . "\n";

// king_ai_posts() can return relative src paths like "king-include/uploads/..."
// The page <base href> then doubles the king-include segment.
// Fix: rewrite every img/a src/href that has king-include/king-include/ → king-include/
// Also rewrite relative paths to absolute so the base href never interferes.
$posts_html = king_ai_posts($userid, 'aimg');

// ── Pass 1: remove any already-doubled segment ────────────────────────────
$posts_html = str_replace(
    '/king-include/king-include/',
    '/king-include/',
    $posts_html
);

// ── Pass 2: turn bare relative paths into absolute URLs ───────────────────
// Matches src="king-include/..." or href="king-include/..." (no leading slash,
// no http) and prepends the site root so the <base href> is irrelevant.
$site_root = rtrim((string)qa_opt('site_url'), '/');   // e.g. http://127.0.0.1:8000
$posts_html = preg_replace_callback(
    '/(src|href)=["\'](?!https?:\/\/|\/\/|\/)(king-include\/[^"\']*)["\']/',
    function ($m) use ($site_root) {
        return $m[1] . '="' . $site_root . '/' . $m[2] . '"';
    },
    $posts_html
);

echo $posts_html;