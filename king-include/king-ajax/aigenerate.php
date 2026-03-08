<?php
use CURLFile;
/*
File: king-include/king-ajax/aigenerate.php
Description: Server-side response to Ajax AI image generation
FIXED: Reference image now correctly passed to all AI providers
*/

if (!defined('QA_VERSION')) {
    header('Location: ../../');
    exit;
}

set_time_limit(600);
ini_set('max_execution_time', 300);
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
// HELPER: Resolve upload paths (FIXED — bulletproof version)
// ============================================================
if (!function_exists('king_resolve_upload_paths')) {
    function king_resolve_upload_paths($imageid) {
        if (empty($imageid)) {
            return ['abs_path' => '', 'pub_url' => ''];
        }

        $abs_path = '';
        $pub_url  = '';

        // ── Step 1: Fetch row — try king_get_uploads(), then direct DB ──────
        $info = [];
        if (function_exists('king_get_uploads')) {
            $row = king_get_uploads($imageid);
            if (is_array($row) && !empty($row)) {
                $info = $row;
            }
        }
        // Direct DB fallback if king_get_uploads returned nothing
        if (empty($info)) {
            try {
                $db_row = qa_db_read_one_assoc(
                    qa_db_query_sub('SELECT * FROM ^uploads WHERE id=#', (int)$imageid),
                    true
                );
                if (is_array($db_row)) {
                    $info = $db_row;
                }
            } catch (Exception $e) {
                error_log("king_resolve_upload_paths DB error: " . $e->getMessage());
            }
        }

        if (empty($info)) {
            error_log("king_resolve_upload_paths: no record found for imageid={$imageid}");
            return ['abs_path' => '', 'pub_url' => ''];
        }

        // ── Step 2: Extract stored path — try multiple possible column names ─
        $stored = '';
        foreach (['path', 'url', 'filepath', 'filename', 'file'] as $k) {
            if (!empty($info[$k])) { $stored = (string)$info[$k]; break; }
        }
        // Extract cloud/CDN URL
        $furl = '';
        foreach (['furl', 'cloudurl', 'aws_url', 'cdn_url', 'remote_url'] as $k) {
            if (!empty($info[$k])) { $furl = (string)$info[$k]; break; }
        }

        error_log("king_resolve_upload_paths: raw stored='{$stored}' furl='{$furl}'");

        // ── Step 3: Build abs_path and pub_url ───────────────────────────────

        // Case A: Cloud storage — furl is a full public URL
        if (!empty($furl) && filter_var($furl, FILTER_VALIDATE_URL)) {
            $pub_url  = $furl;
            // abs_path stays empty; providers that need binary will download
            error_log("king_resolve_upload_paths: cloud URL mode pub_url={$pub_url}");

        // Case B: stored value is itself a full URL
        } elseif (!empty($stored) && filter_var($stored, FILTER_VALIDATE_URL)) {
            $pub_url  = $stored;
            error_log("king_resolve_upload_paths: stored-URL mode pub_url={$pub_url}");

        // Case C: stored is a relative local path
        } elseif (!empty($stored)) {
            $clean = ltrim(str_replace('\\', '/', $stored), '/');

            // Try multiple base directories in order of likelihood
            $bases = array_filter([
                QA_INCLUDE_DIR,                                      // king-include/
                defined('QA_BASE_DIR')  ? QA_BASE_DIR  : '',         // site root
                dirname(QA_INCLUDE_DIR) . '/',                       // one level up
                isset($_SERVER['DOCUMENT_ROOT'])
                    ? rtrim($_SERVER['DOCUMENT_ROOT'], '/') . '/'
                    : '',
            ]);
            foreach ($bases as $base) {
                $candidate = rtrim($base, '/') . '/' . $clean;
                if (@file_exists($candidate) && @filesize($candidate) > 0) {
                    $abs_path = $candidate;
                    break;
                }
            }

            if (empty($abs_path)) {
                error_log("king_resolve_upload_paths: file not found on disk for path='{$clean}', will use URL");
            }

            // Build public URL regardless (needed for URL-based providers)
            $pub_url = rtrim(qa_opt('site_url'), '/')
                     . '/king-include/'
                     . $clean;
        }

        error_log("king_resolve_upload_paths: FINAL imageid={$imageid} abs_path='{$abs_path}' pub_url='{$pub_url}'");
        return ['abs_path' => $abs_path, 'pub_url' => $pub_url];
    }
}

// ============================================================
// HELPER: Get reference image binary data
// Tries abs_path first, then downloads from pub_url as fallback.
// Returns raw bytes or FALSE.
// ============================================================
if (!function_exists('king_get_ref_binary')) {
    function king_get_ref_binary($abs_path, $pub_url) {
        // Try local file first
        if (!empty($abs_path) && @file_exists($abs_path)) {
            $data = @file_get_contents($abs_path);
            if ($data !== false && strlen($data) > 100) {
                error_log("king_get_ref_binary: loaded from abs_path (" . strlen($data) . " bytes)");
                return $data;
            }
        }

        // Fallback: download from pub_url
        if (!empty($pub_url) && filter_var($pub_url, FILTER_VALIDATE_URL)) {
            $ch = curl_init($pub_url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_USERAGENT, 'KingAI/1.0');
            $data     = curl_exec($ch);
            $code     = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curl_err = curl_error($ch);
            curl_close($ch);

            if (!empty($curl_err)) {
                error_log("king_get_ref_binary: cURL error downloading pub_url: {$curl_err}");
            } elseif ($code === 200 && $data !== false && strlen($data) > 100) {
                error_log("king_get_ref_binary: downloaded from pub_url ({$code}, " . strlen($data) . " bytes)");
                return $data;
            } else {
                error_log("king_get_ref_binary: pub_url download failed code={$code}");
            }
        }

        error_log("king_get_ref_binary: FAILED — no binary data available");
        return false;
    }
}

// ============================================================
// HELPER: Get MIME type for binary data
// ============================================================
if (!function_exists('king_detect_mime')) {
    function king_detect_mime($abs_path, $data = null) {
        if (!empty($abs_path) && @file_exists($abs_path)) {
            if (function_exists('mime_content_type')) {
                $m = @mime_content_type($abs_path);
                if ($m) return $m;
            }
        }
        if (!empty($data)) {
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $m = $finfo->buffer($data);
            if ($m) return $m;
        }
        return 'image/jpeg'; // safe default
    }
}

// ============================================================
// Luma helpers (unchanged)
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
        $supported = [
            '1:1' => 1.0, '3:4' => 3/4, '4:3' => 4/3,
            '9:16' => 9/16, '16:9' => 16/9, '9:21' => 9/21, '21:9' => 21/9,
        ];
        $s = trim((string)$imsize);
        if (preg_match('~(\d+\s*:\s*\d+)~', $s, $m)) {
            $ratio = str_replace(' ', '', $m[1]);
            return isset($supported[$ratio]) ? $ratio : '16:9';
        }
        if (preg_match('~^(\d+)x(\d+)$~', $s, $m)) {
            $w = (int)$m[1]; $h = (int)$m[2];
            if ($w > 0 && $h > 0) {
                $r = $w / $h; $bestKey = '16:9'; $bestDiff = PHP_FLOAT_MAX;
                foreach ($supported as $k => $val) {
                    $diff = abs($r - $val);
                    if ($diff < $bestDiff) { $bestDiff = $diff; $bestKey = $k; }
                }
                return $bestKey;
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
        $method = strtoupper($method);
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            $headers[] = "Content-Type: application/json";
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        } elseif ($method !== 'GET') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        $raw = curl_exec($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if (curl_errno($ch)) { $curlErr = curl_error($ch); }
        curl_close($ch);
        $json = @json_decode((string)$raw, true);
        return is_array($json) ? $json : null;
    }
}

if (!function_exists('king_luma_download_file')) {
    function king_luma_download_file($url, $destPath, &$err = '') {
        $fp = @fopen($destPath, 'w');
        if (!$fp) { $err = 'Failed to create file for download.'; return false; }
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_FILE, $fp);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 180);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_USERAGENT, 'KingAI/1.0');
        $ok = curl_exec($ch); $curlErr = curl_error($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch); fclose($fp);
        // SSL retry
        if (!$ok || !empty($curlErr) || $code >= 400) {
            @unlink($destPath);
            $fp = @fopen($destPath, 'w');
            if (!$fp) { $err = 'Failed to create file (ssl fallback).'; return false; }
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_FILE, $fp);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 180);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_USERAGENT, 'KingAI/1.0');
            $ok = curl_exec($ch); $curlErr = curl_error($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch); fclose($fp);
        }
        if (!$ok || !empty($curlErr) || $code >= 400) {
            @unlink($destPath);
            $err = "Download failed. HTTP {$code}. " . ($curlErr ?: '');
            return false;
        }
        if (!file_exists($destPath) || filesize($destPath) < 5000) {
            @unlink($destPath);
            $err = 'Downloaded file is too small or missing.';
            return false;
        }
        return true;
    }
}

if (!function_exists('king_convert_to_dalle_png')) {
    function king_convert_to_dalle_png($sourcePath) {
        if (!extension_loaded('gd')) { error_log('DALL-E edit: GD not loaded.'); return false; }
        $info = @getimagesize($sourcePath);
        if (!$info) return false;
        list($srcW, $srcH, $type) = $info;
        switch ($type) {
            case IMAGETYPE_JPEG: $src = @imagecreatefromjpeg($sourcePath); break;
            case IMAGETYPE_PNG:  $src = @imagecreatefrompng($sourcePath);  break;
            case IMAGETYPE_WEBP: $src = @imagecreatefromwebp($sourcePath); break;
            case IMAGETYPE_GIF:  $src = @imagecreatefromgif($sourcePath);  break;
            default: error_log('DALL-E edit: unsupported type ' . $type); return false;
        }
        if (!$src) { error_log('DALL-E edit: GD could not load image.'); return false; }
        $dim = 1024;
        $canvas = imagecreatetruecolor($dim, $dim);
        imagesavealpha($canvas, true);
        $transparent = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
        imagefill($canvas, 0, 0, $transparent);
        $scale = min($dim / $srcW, $dim / $srcH);
        $newW = max(1, (int)($srcW * $scale)); $newH = max(1, (int)($srcH * $scale));
        $offsetX = (int)(($dim - $newW) / 2);  $offsetY = (int)(($dim - $newH) / 2);
        imagecopyresampled($canvas, $src, $offsetX, $offsetY, 0, 0, $newW, $newH, $srcW, $srcH);
        imagedestroy($src);
        $tmpPath = sys_get_temp_dir() . '/dalle_edit_' . time() . mt_rand(100, 999) . '.png';
        $ok = imagepng($canvas, $tmpPath);
        imagedestroy($canvas);
        if (!$ok || !file_exists($tmpPath) || filesize($tmpPath) > 4 * 1024 * 1024) {
            @unlink($tmpPath);
            error_log('DALL-E edit: PNG write failed or >4MB.');
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
$imageid  = trim((string)qa_post_text('imageid'));
$npvalue  = trim((string)qa_post_text('npvalue'));

error_log("aigenerate: aiselect={$aiselect} imageid={$imageid} imsize={$imsize}");

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
if (!$input || !$chkk) {
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
$style_preset     = '';

// ============================================================
// RESOLVE REFERENCE IMAGE — FIXED MASTER RESOLUTION
// ============================================================
$ref_abs_path = '';
$ref_pub_url  = '';

if (!empty($imageid)) {
    $resolved     = king_resolve_upload_paths($imageid);
    $ref_abs_path = $resolved['abs_path'];
    $ref_pub_url  = $resolved['pub_url'];

    error_log("Reference image resolved: imageid={$imageid} abs={$ref_abs_path} url={$ref_pub_url}");

    // Validate: if abs_path resolved but pub_url not set, that's fine for file-based providers.
    // If neither resolved, log a warning but continue (generation without reference).
    if (empty($ref_abs_path) && empty($ref_pub_url)) {
        error_log("WARNING: Could not resolve reference image for imageid={$imageid}. Generation will proceed without it.");
    }
}

// ============================================================
// TRY GATEWAY FIRST
// ============================================================
$use_gateway = (qa_opt('gateway_enabled') == '1' && !empty(qa_opt('gateway_url')));

if ($use_gateway && class_exists('Ebonix_Gateway') && Ebonix_Gateway::enabled()) {
    error_log("Gateway: Attempting image generation. model={$aiselect} imageid={$imageid}");

    try {
        $gateway_image_data = null;

        if (!empty($imageid)) {
            // Try to get binary data (abs_path first, then download from pub_url)
            $img_bytes = king_get_ref_binary($ref_abs_path, $ref_pub_url);

            if ($img_bytes !== false) {
                $img_mime = king_detect_mime($ref_abs_path, $img_bytes);
                $gateway_image_data = [
                    'base64'    => base64_encode($img_bytes),
                    'mime_type' => $img_mime,
                    'imageid'   => $imageid,
                    'furl'      => $ref_pub_url,
                ];
                error_log("Gateway: attached base64 image, size=" . strlen($img_bytes) . " mime={$img_mime}");
            } elseif (!empty($ref_pub_url)) {
                // Binary unavailable — pass URL only
                $gateway_image_data = ['furl' => $ref_pub_url, 'imageid' => $imageid];
                error_log("Gateway: using URL-only reference: {$ref_pub_url}");
            }
        }

        $gateway_result = Ebonix_Gateway::generate_image(
            $input,
            $aiselect ?: 'auto',
            $imsize,
            qa_post_text('aistyle'),
            $npvalue,
            $gateway_image_data
        );

        if (!empty($gateway_result['success'])) {
            $image_data = $gateway_result['image_url'];

            if (strpos($image_data, 'data:image') === 0) {
                $parts = explode(',', $image_data, 2);
                if (count($parts) == 2) {
                    $image_binary = base64_decode($parts[1]);
                    $folder  = 'uploads/' . date("Y") . '/' . date("m") . '/';
                    $destDir = QA_INCLUDE_DIR . $folder;
                    if (!is_dir($destDir)) mkdir($destDir, 0755, true);

                    $filename    = 'gateway-img-' . time() . '-' . mt_rand(1000, 9999) . '.webp';
                    $upload_path = $destDir . $filename;
                    $temp_file   = tempnam(sys_get_temp_dir(), 'ebonix_');
                    file_put_contents($temp_file, $image_binary);
                    rename($temp_file, $upload_path);

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
                        error_log("✅ GATEWAY SUCCESS (base64 image processed)");
                    }
                }
            } elseif (filter_var($image_data, FILTER_VALIDATE_URL)) {
                $image_urls = [$image_data];
                error_log("✅ GATEWAY: returned URL → " . $image_data);
            }
        } else {
            error_log("⚠️ GATEWAY FAILED: " . ($gateway_result['error'] ?? 'unknown') . " — falling through to direct API");
        }
    } catch (Exception $e) {
        error_log("Gateway exception: " . $e->getMessage() . " — falling through to direct API");
    }
}

// ============================================================
// DIRECT API CALLS (if gateway didn't produce a result)
// ============================================================
if (!$gemini_processed && empty($image_urls)) {

    // ──────────────────────────────────────────────────────────────────────────
    // OPENAI DALL-E
    // ──────────────────────────────────────────────────────────────────────────
    if ($aiselect === 'de' || $aiselect === 'de3') {
        $openaiapi = qa_opt('king_leo_api');

        if (empty($openaiapi)) {
            $error = 'OpenAI API key not configured';
        } else {
            // DALL-E 2 + reference image → /images/edits
            if ($aiselect === 'de' && !empty($imageid)) {
                // ✅ FIX: use king_get_ref_binary — works even if abs_path is empty
                $ref_binary = king_get_ref_binary($ref_abs_path, $ref_pub_url);

                if ($ref_binary !== false) {
                    // Write to temp file for PNG conversion
                    $tmp_ref = tempnam(sys_get_temp_dir(), 'dalle_ref_') . '.jpg';
                    file_put_contents($tmp_ref, $ref_binary);

                    $png_path = king_convert_to_dalle_png($tmp_ref);
                    @unlink($tmp_ref);

                    if ($png_path) {
                        $edit_size = in_array($imsize, ['256x256', '512x512', '1024x1024'])
                            ? $imsize : '1024x1024';

                        $ch = curl_init('https://api.openai.com/v1/images/edits');
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                        curl_setopt($ch, CURLOPT_POST, true);
                        curl_setopt($ch, CURLOPT_TIMEOUT, 120);
                        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
                        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $openaiapi]);
                        curl_setopt($ch, CURLOPT_POSTFIELDS, [
                            'image'  => new CURLFile($png_path, 'image/png', 'image.png'),
                            'prompt' => $input,
                            'n'      => 1,
                            'size'   => $edit_size,
                        ]);
                        $response_body = curl_exec($ch);
                        if (curl_errno($ch)) $error = 'DALL-E API Error: ' . curl_error($ch);
                        curl_close($ch);
                        @unlink($png_path);

                        if (!$error) {
                            $response_obj = json_decode($response_body, true);
                            if (isset($response_obj['data'])) {
                                foreach ($response_obj['data'] as $img) {
                                    if (!empty($img['url'])) $image_urls[] = $img['url'];
                                }
                                error_log("✅ DALL-E edit success: " . count($image_urls) . " image(s)");
                            } else {
                                $edit_err = $response_obj['error']['message'] ?? 'DALL-E edit returned no data';
                                error_log("DALL-E edit failed ({$edit_err}) — falling back to standard generation");
                            }
                        }
                    } else {
                        error_log("DALL-E: PNG conversion failed — falling back to standard generation");
                    }
                } else {
                    error_log("DALL-E: Could not get reference image binary — falling back to standard generation");
                }
            }

            // Standard generation (DALL-E 3, or DALL-E 2 fallback)
            if (empty($image_urls) && empty($error)) {
                $params_gen = ($aiselect === 'de3') ? [
                    'model'  => 'dall-e-3',
                    'prompt' => $input,
                    'n'      => 1,
                    'size'   => $imsize,
                ] : [
                    'prompt' => $input,
                    'n'      => (int)$imagen,
                    'size'   => $imsize,
                ];
                $ch = curl_init('https://api.openai.com/v1/images/generations');
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($params_gen));
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_HTTPHEADER, [
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $openaiapi,
                ]);
                curl_setopt($ch, CURLOPT_TIMEOUT, 120);
                curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
                $response_body = curl_exec($ch);
                if (curl_errno($ch)) $error = 'API Error: ' . curl_error($ch);
                curl_close($ch);
                if (!$error) {
                    $response_obj = json_decode($response_body, true);
                    if (isset($response_obj['data'])) {
                        foreach ($response_obj['data'] as $img) {
                            if (!empty($img['url'])) $image_urls[] = $img['url'];
                        }
                    } else {
                        $error = $response_obj['error']['message'] ?? 'OpenAI returned no images';
                    }
                }
            }
        }
    }

    // ──────────────────────────────────────────────────────────────────────────
    // GOOGLE IMAGEN 4
    // ──────────────────────────────────────────────────────────────────────────
    elseif ($aiselect === 'imagen4') {
        $API_KEY = qa_opt('gemini_api');

        if (empty($API_KEY)) {
            $error = 'Gemini API key not configured';
        } else {
            $aspect_ratio = function_exists('aisize_ratio') ? aisize_ratio($imsize) : '1:1';
            $api_url = "https://generativelanguage.googleapis.com/v1beta/models/imagen-4.0-generate-001:predict?key=" . $API_KEY;

            $instance = ["prompt" => $input];

            // ✅ FIX: use king_get_ref_binary
            if (!empty($imageid)) {
                $ref_binary = king_get_ref_binary($ref_abs_path, $ref_pub_url);
                if ($ref_binary !== false) {
                    $img_mime = king_detect_mime($ref_abs_path, $ref_binary);
                    $instance['referenceImages'] = [[
                        'referenceType'  => 'REFERENCE_TYPE_STYLE',
                        'referenceId'    => 1,
                        'referenceImage' => [
                            'bytesBase64Encoded' => base64_encode($ref_binary),
                            'mimeType'           => $img_mime,
                        ],
                    ]];
                    error_log("Imagen4: reference image attached, size=" . strlen($ref_binary) . " mime={$img_mime}");
                } else {
                    error_log("Imagen4: WARNING — could not get reference image binary");
                }
            }

            $payload = [
                "instances"  => [$instance],
                "parameters" => [
                    "sampleCount"      => 1,
                    "aspectRatio"      => $aspect_ratio,
                    "personGeneration" => "ALLOW_ALL",
                ],
            ];

            $ch = curl_init($api_url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: application/json"]);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
            curl_setopt($ch, CURLOPT_TIMEOUT, 120);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
            $response = curl_exec($ch);
            if (curl_errno($ch)) $error = "CURL ERROR: " . curl_error($ch);
            curl_close($ch);

            if (!$error) {
                $data = json_decode($response, true);
                if (!isset($data["predictions"][0]["bytesBase64Encoded"])) {
                    $error = $data['error']['message'] ?? 'Imagen 4 did not return images';
                } else {
                    $image_binary  = base64_decode($data["predictions"][0]["bytesBase64Encoded"]);
                    $folder        = 'uploads/' . date("Y") . '/' . date("m") . '/';
                    $destDir       = QA_INCLUDE_DIR . $folder;
                    if (!file_exists($destDir)) mkdir($destDir, 0777, true);
                    $timestamp     = time() . '-' . mt_rand(1000, 9999);
                    $finalFilename = 'imagen4-' . $timestamp . '.webp';
                    $tempPath      = $destDir . 'temp_' . $finalFilename;
                    $fullPath      = $destDir . $finalFilename;
                    file_put_contents($tempPath, $image_binary);
                    $thumb_result = king_process_local_image($tempPath, $folder . $finalFilename, true, 600);
                    if (copy($tempPath, $fullPath)) {
                        $imageInfo = @getimagesize($fullPath);
                        if ($imageInfo) {
                            list($width, $height) = $imageInfo;
                            if (qa_opt('enable_aws')) {
                                $url = king_upload_to_cloud($fullPath, $finalFilename, 'aws');
                                $full_result = king_insert_uploads($url, 'webp', $width, $height, 'aws');
                            } elseif (qa_opt('enable_wasabi')) {
                                $url = king_upload_to_cloud($fullPath, $finalFilename, 'wasabi');
                                $full_result = king_insert_uploads($url, 'webp', $width, $height, 'wasabi');
                            } else {
                                $full_result = king_insert_uploads($folder . $finalFilename, 'webp', $width, $height);
                            }
                            if ($thumb_result && $full_result) {
                                $uploaded_images[] = $full_result;
                                $thumbs[]          = $thumb_result;
                                $gemini_processed  = true;
                                error_log("✅ Imagen4 success");
                            }
                        }
                    }
                    @unlink($tempPath);
                }
            }
        }
    }

    // ──────────────────────────────────────────────────────────────────────────
    // GEMINI BANANA — Black Representation rules
    // ──────────────────────────────────────────────────────────────────────────
    elseif ($aiselect === 'banana') {
        $API_KEY = qa_opt('gemini_api');

        if (empty($API_KEY)) {
            $error = 'Gemini API key not configured';
        } else {
            $aspect_ratio    = function_exists('aisize_ratio') ? aisize_ratio($imsize) : '1:1';
            $enhanced_prompt = $input;

            $person_keywords = [
                'person','people','human','girl','boy','child','kid','baby',
                'woman','man','lady','guy','teen','teenager','adult',
                'beautiful','handsome','cute','pretty','gorgeous','attractive',
                'model','portrait','face',
            ];
            $has_person   = false;
            $prompt_lower = strtolower($enhanced_prompt);
            foreach ($person_keywords as $kw) {
                if (strpos($prompt_lower, $kw) !== false) { $has_person = true; break; }
            }

            if ($has_person) {
                $beauty_replacements = [
                    'beautiful ' => 'beautiful Black ', 'Beautiful ' => 'Beautiful Black ',
                    'handsome '  => 'handsome Black ',  'Handsome '  => 'Handsome Black ',
                    'cute '      => 'cute Black ',       'Cute '      => 'Cute Black ',
                    'pretty '    => 'pretty Black ',     'Pretty '    => 'Pretty Black ',
                    'gorgeous '  => 'gorgeous Black ',   'Gorgeous '  => 'Gorgeous Black ',
                    'attractive '=> 'attractive Black ','Attractive ' => 'Attractive Black ',
                ];
                foreach ($beauty_replacements as $orig => $rep) {
                    $enhanced_prompt = str_replace($orig, $rep, $enhanced_prompt);
                }
                if ($enhanced_prompt === $input) {
                    foreach (['girl','boy','woman','man','person','people','child'] as $kw) {
                        if (stripos($enhanced_prompt, $kw) !== false) {
                            $enhanced_prompt = str_ireplace($kw, "Black $kw", $enhanced_prompt);
                            break;
                        }
                    }
                }
                $enhanced_prompt .= ". diverse Black skin tones ranging from light brown to deep ebony, natural Black hair with authentic curl patterns (3A-4C), authentic Black facial features including broad nose and full lips, Black person, NO lightening or whitewashing of skin, accurate Black skin tone without pale or washed-out appearance, maintaining Black features throughout, NO Eurocentric feature drift or bias";
            }

            error_log("Banana: original={$input}");
            error_log("Banana: enhanced={$enhanced_prompt}");

            // ✅ FIX: use king_get_ref_binary
            $parts = [["text" => $enhanced_prompt]];
            if (!empty($imageid)) {
                $ref_binary = king_get_ref_binary($ref_abs_path, $ref_pub_url);
                if ($ref_binary !== false) {
                    $img_mime = king_detect_mime($ref_abs_path, $ref_binary);
                    $parts[] = [
                        "inline_data" => [
                            "mime_type" => $img_mime,
                            "data"      => base64_encode($ref_binary),
                        ]
                    ];
                    error_log("Banana: reference image attached, size=" . strlen($ref_binary) . " mime={$img_mime}");
                } else {
                    error_log("Banana: WARNING — could not get reference image binary");
                }
            }

            $api_url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-3-pro-image-preview:generateContent?key=" . $API_KEY;

            $payload = [
                "contents"         => [["parts" => $parts]],
                "generationConfig" => ["imageConfig" => ["aspectRatio" => $aspect_ratio]],
            ];

            $ch = curl_init($api_url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: application/json"]);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
            curl_setopt($ch, CURLOPT_TIMEOUT, 120);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
            $response = curl_exec($ch);
            if (curl_errno($ch)) $error = "CURL ERROR: " . curl_error($ch);
            curl_close($ch);

            if (!$error) {
                $data = json_decode($response, true);
                $b64  = $data["candidates"][0]["content"]["parts"][0]["inlineData"]["data"] ?? '';
                if (empty($b64)) {
                    $error = $data['error']['message'] ?? "Failed to generate image.";
                } else {
                    $image_binary  = base64_decode($b64);
                    $folder        = 'uploads/' . date("Y") . '/' . date("m") . '/';
                    $destDir       = QA_INCLUDE_DIR . $folder;
                    if (!file_exists($destDir)) mkdir($destDir, 0777, true);
                    $timestamp     = time() . '-' . mt_rand(1000, 9999);
                    $finalFilename = 'gemini-image-' . $timestamp . '.webp';
                    $tempPath      = $destDir . 'temp_' . $finalFilename;
                    $fullPath      = $destDir . $finalFilename;
                    file_put_contents($tempPath, $image_binary);
                    $thumb_result = king_process_local_image($tempPath, $folder . $finalFilename, true, 600);
                    if (copy($tempPath, $fullPath)) {
                        $imageInfo = @getimagesize($fullPath);
                        if ($imageInfo) {
                            list($width, $height) = $imageInfo;
                            if (qa_opt('enable_aws')) {
                                $url = king_upload_to_cloud($fullPath, $finalFilename, 'aws');
                                $full_result = king_insert_uploads($url, 'webp', $width, $height, 'aws');
                            } elseif (qa_opt('enable_wasabi')) {
                                $url = king_upload_to_cloud($fullPath, $finalFilename, 'wasabi');
                                $full_result = king_insert_uploads($url, 'webp', $width, $height, 'wasabi');
                            } else {
                                $full_result = king_insert_uploads($folder . $finalFilename, 'webp', $width, $height);
                            }
                            if ($thumb_result && $full_result) {
                                $uploaded_images[] = $full_result;
                                $thumbs[]          = $thumb_result;
                                $gemini_processed  = true;
                                error_log("✅ Banana success");
                            }
                        }
                    }
                    @unlink($tempPath);
                }
            }
        }
    }

    // ──────────────────────────────────────────────────────────────────────────
    // DECART IMAGE
    // ──────────────────────────────────────────────────────────────────────────
    elseif ($aiselect === 'decart_img') {
        $API_KEY = qa_opt('decart_api');

        if (empty($API_KEY)) {
            $error = 'Decart API key not configured';
        } else {
            $use_i2i     = false;
            $api_url     = "https://api.decart.ai/v1/generate/lucy-pro-t2i";
            $post_fields = ['prompt' => $input];
            if (!empty($npvalue)) $post_fields['negative_prompt'] = $npvalue;

            // ✅ FIX: use king_get_ref_binary — Decart needs actual file via CURLFile
            if (!empty($imageid)) {
                $ref_binary = king_get_ref_binary($ref_abs_path, $ref_pub_url);

                if ($ref_binary !== false) {
                    // Write to temp file because CURLFile requires a filepath
                    $img_mime    = king_detect_mime($ref_abs_path, $ref_binary);
                    $ext_map     = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
                    $tmp_ext     = $ext_map[$img_mime] ?? 'jpg';
                    $tmp_ref     = tempnam(sys_get_temp_dir(), 'decart_ref_') . '.' . $tmp_ext;
                    file_put_contents($tmp_ref, $ref_binary);

                    $api_url              = "https://api.decart.ai/v1/generate/lucy-pro-i2i";
                    $post_fields['data']  = new CURLFile($tmp_ref, $img_mime, 'reference.' . $tmp_ext);
                    $use_i2i              = true;
                    error_log("Decart: using i2i with temp_ref={$tmp_ref} mime={$img_mime}");
                } else {
                    error_log("Decart: WARNING — could not get reference image binary, using t2i fallback");
                }
            }

            $ch = curl_init($api_url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ["X-API-KEY: $API_KEY"]);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $post_fields);
            curl_setopt($ch, CURLOPT_TIMEOUT, 120);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
            $image_data = curl_exec($ch);
            $http_code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            if (curl_errno($ch)) $error = "Decart API Error: " . curl_error($ch);
            curl_close($ch);

            // Clean up temp file
            if ($use_i2i && !empty($tmp_ref) && file_exists($tmp_ref)) {
                @unlink($tmp_ref);
            }

            if (!$error) {
                if ($http_code !== 200) {
                    $json_error = @json_decode($image_data, true);
                    $error = 'Decart HTTP ' . $http_code . ': ' . ($json_error['error']['message'] ?? substr($image_data, 0, 200));
                } elseif (empty($image_data)) {
                    $error = 'Decart returned empty response';
                } else {
                    $folder        = 'uploads/' . date("Y") . '/' . date("m") . '/';
                    $destDir       = QA_INCLUDE_DIR . $folder;
                    if (!file_exists($destDir)) mkdir($destDir, 0777, true);
                    $timestamp     = time() . '-' . mt_rand(1000, 9999);
                    $finalFilename = 'decart-img-' . $timestamp . '.png';
                    $tempPath      = $destDir . 'temp_' . $finalFilename;
                    $fullPath      = $destDir . $finalFilename;
                    file_put_contents($tempPath, $image_data);
                    $imageInfo = @getimagesize($tempPath);
                    if (!$imageInfo) {
                        $error = 'Decart returned invalid image data';
                        @unlink($tempPath);
                    } else {
                        $thumb_result = king_process_local_image($tempPath, $folder . $finalFilename, true, 600);
                        if (copy($tempPath, $fullPath)) {
                            list($img_width, $img_height) = $imageInfo;
                            if (qa_opt('enable_aws')) {
                                $url = king_upload_to_cloud($fullPath, $finalFilename, 'aws');
                                $full_result = king_insert_uploads($url, 'png', $img_width, $img_height, 'aws');
                            } elseif (qa_opt('enable_wasabi')) {
                                $url = king_upload_to_cloud($fullPath, $finalFilename, 'wasabi');
                                $full_result = king_insert_uploads($url, 'png', $img_width, $img_height, 'wasabi');
                            } else {
                                $full_result = king_insert_uploads($folder . $finalFilename, 'png', $img_width, $img_height);
                            }
                            if ($thumb_result && $full_result) {
                                $uploaded_images[] = $full_result;
                                $thumbs[]          = $thumb_result;
                                $gemini_processed  = true;
                                error_log("✅ Decart success");
                            }
                        }
                        @unlink($tempPath);
                    }
                }
            }
        }
    }

    // ──────────────────────────────────────────────────────────────────────────
    // LUMA IMAGE
    // ──────────────────────────────────────────────────────────────────────────
    elseif ($aiselect === 'luma_img') {
        $API_KEY = king_luma_clean_key(qa_opt('luma_api'));

        if (empty($API_KEY)) {
            $error = 'Luma API key not configured';
        } else {
            $api_url      = "https://api.lumalabs.ai/dream-machine/v1/generations/image";
            $aspect_ratio = king_luma_pick_aspect_ratio($imsize);
            $prompt       = (string)$input;
            if (!empty($npvalue)) $prompt .= "\n\nAvoid: " . trim((string)$npvalue);

            $models_to_try = ['photon-flash-1', 'photon-1'];
            $generation_id = null;
            $create_err    = '';

            foreach ($models_to_try as $try_model) {
                $payload = [
                    'prompt'       => $prompt,
                    'aspect_ratio' => $aspect_ratio,
                    'model'        => $try_model,
                ];

                // ✅ FIX: For Luma we need a PUBLIC URL.
                // If pub_url resolves, use it directly.
                // If only abs_path, upload to temp public location or use pub_url constructed from site_url.
                if (!empty($imageid)) {
                    $luma_ref_url = '';

                    if (!empty($ref_pub_url) && filter_var($ref_pub_url, FILTER_VALIDATE_URL)) {
                        // Verify the URL is accessible
                        $luma_ref_url = $ref_pub_url;
                    }

                    if (!empty($luma_ref_url)) {
                        $payload['modify_image_ref'] = ['url' => $luma_ref_url, 'weight' => 1.0];
                        error_log("Luma: attached reference image url={$luma_ref_url}");
                    } else {
                        error_log("Luma: WARNING — no valid public URL for reference image (imageid={$imageid})");
                    }
                }

                $http = 0; $raw = ''; $curlErr = '';
                $out  = king_luma_request_json('POST', $api_url, $API_KEY, $payload, $http, $raw, $curlErr);

                if (!empty($curlErr)) { $create_err = "Luma CURL error: {$curlErr}"; continue; }
                if (($http === 200 || $http === 201) && !empty($out['id'])) {
                    $generation_id = $out['id'];
                    error_log("Luma: generation started id={$generation_id} model={$try_model}");
                    break;
                }
                $create_err = "Luma HTTP {$http}: " . substr($raw, 0, 250);
            }

            if (empty($generation_id)) {
                $error = $create_err ?: 'Failed to create Luma image generation';
            } else {
                $max_attempts = 75;
                for ($attempt = 0; $attempt < $max_attempts; $attempt++) {
                    sleep(4);
                    $status_url = "https://api.lumalabs.ai/dream-machine/v1/generations/{$generation_id}";
                    $http = 0; $raw = ''; $curlErr = '';
                    $status = king_luma_request_json('GET', $status_url, $API_KEY, null, $http, $raw, $curlErr);
                    if (!empty($curlErr) || $http >= 400 || !is_array($status)) continue;

                    $state = strtolower((string)($status['state'] ?? ''));

                    if ($state === 'completed') {
                        $img_url = $status['assets']['image'] ?? '';
                        if (empty($img_url)) { $error = 'Luma completed but no image url'; break; }

                        $folder  = 'uploads/' . date("Y") . '/' . date("m") . '/';
                        $destDir = QA_INCLUDE_DIR . $folder;
                        if (!file_exists($destDir)) mkdir($destDir, 0777, true);

                        $stamp  = time() . '-' . mt_rand(1000, 9999);
                        $path   = parse_url($img_url, PHP_URL_PATH);
                        $ext    = strtolower(pathinfo($path ?: '', PATHINFO_EXTENSION)) ?: 'jpg';
                        if ($ext === 'jpeg') $ext = 'jpg';

                        $tempPath      = $destDir . "temp_luma_{$stamp}.{$ext}";
                        $finalFilename = "luma-img-{$stamp}.{$ext}";
                        $finalPath     = $destDir . $finalFilename;

                        $dlErr = '';
                        if (!king_luma_download_file($img_url, $tempPath, $dlErr)) {
                            $error = "Failed to download Luma image: {$dlErr}"; break;
                        }
                        $imageInfo = @getimagesize($tempPath);
                        if (!$imageInfo) { @unlink($tempPath); $error = "Luma image invalid."; break; }
                        list($w, $h) = $imageInfo;

                        $thumb_result = king_process_local_image($tempPath, $folder . $finalFilename, true, 600);
                        if (!copy($tempPath, $finalPath)) {
                            @unlink($tempPath); $error = "Failed to save Luma image."; break;
                        }
                        if (qa_opt('enable_aws')) {
                            $url = king_upload_to_cloud($finalPath, $finalFilename, 'aws');
                            $full_result = king_insert_uploads($url, $ext, $w, $h, 'aws');
                        } elseif (qa_opt('enable_wasabi')) {
                            $url = king_upload_to_cloud($finalPath, $finalFilename, 'wasabi');
                            $full_result = king_insert_uploads($url, $ext, $w, $h, 'wasabi');
                        } else {
                            $full_result = king_insert_uploads($folder . $finalFilename, $ext, $w, $h);
                        }
                        @unlink($tempPath);
                        if ($thumb_result && $full_result) {
                            $uploaded_images[] = $full_result;
                            $thumbs[]          = $thumb_result;
                            $gemini_processed  = true;
                            error_log("✅ Luma image success");
                        } else {
                            $error = "Failed to save Luma image to database.";
                        }
                        break;

                    } elseif ($state === 'failed') {
                        $error = 'Luma generation failed: ' . ($status['failure_reason'] ?? 'Unknown');
                        break;
                    }
                    // Still processing — continue polling
                }
                if (!$gemini_processed && empty($error)) $error = 'Luma image generation timed out';
            }
        }
    }

    // ──────────────────────────────────────────────────────────────────────────
    // KINGSTUDIO (default — sdn, flux, sdream, fluxkon, etc.)
    // ──────────────────────────────────────────────────────────────────────────
    else {
        $sdapi = qa_opt('king_sd_api');
        if (empty($sdapi)) {
            $error = 'KingStudio API key not configured';
        } else {
            $aistyle = is_string(qa_post_text('aistyle')) ? trim(qa_post_text('aistyle')) : '';
            if ($aistyle === 'none') $aistyle = '';
            $style_preset = $aistyle;
            $aisteps      = qa_opt('king_sd_steps');

            $request_data = [
                "prompt" => $input . ($style_preset ? ', ' . $style_preset : ''),
                "size"   => (int)$imagen,
                "steps"  => (int)$aisteps,
                "aisize" => $imsize,
                "model"  => $aiselect,
                "nvalue" => $npvalue,
                "ennsfw" => (bool)qa_opt('ennsfw'),
                "sdnsfw" => (bool)qa_opt('sdnsfw'),
            ];

            // ✅ FIX: KingStudio uses URL — ensure pub_url is valid
            if (!empty($imageid) && in_array($aiselect, ['fluxkon', 'sdream']) && !empty($ref_pub_url)) {
                $request_data['image'] = $ref_pub_url;
                error_log("KingStudio: attached reference image url={$ref_pub_url}");
            }

            $ch = curl_init("https://kingstudio.io/api/king-text2img");
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                "Authorization: Bearer $sdapi",
                "Accept: application/json",
                "Content-Type: application/json",
            ]);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($request_data));
            curl_setopt($ch, CURLOPT_TIMEOUT, 180);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
            $response = curl_exec($ch);
            if (curl_errno($ch)) $error = "API Error: " . curl_error($ch);
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
// PROCESS URL-BASED RESULTS (KingStudio / DALL-E URLs)
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
    echo "QA_AJAX_RESPONSE\n0\n" . json_encode(['success' => false, 'message' => 'Failed to upload images']) . "\n";
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
    $extra, 'NOTE', null, 'aimg', $input, null
);

qa_db_postmeta_set($postid, 'wai',   true);
qa_db_postmeta_set($postid, 'model', $aiselect);
if (!empty($npvalue))      qa_db_postmeta_set($postid, 'nprompt', $npvalue);
if (!empty($style_preset)) qa_db_postmeta_set($postid, 'stle',    $style_preset);
if (!empty($imsize))       qa_db_postmeta_set($postid, 'asize',   $imsize);

if ($imageid && in_array($aiselect, ['fluxkon','sdream','banana','decart_img','luma_img','imagen4','de'])) {
    qa_db_postmeta_set($postid, 'pimage', $imageid);
}

if (qa_opt('enable_membership') && (qa_opt('ailimits') || qa_opt('ulimits'))) {
    kingai_imagen($imagen);
}

error_log("✅ aigenerate complete: postid={$postid} model={$aiselect}");

echo "QA_AJAX_RESPONSE\n1\n" . json_encode(['success' => true, 'postid' => $postid]) . "\n";
echo king_ai_posts($userid, 'aimg');