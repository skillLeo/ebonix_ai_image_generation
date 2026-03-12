<?php
/**
 * Ebonix Gateway Integration
 * File: king-include/king-app/gateway.php
 */

if (!defined('QA_VERSION')) {
    header('Location: ../../');
    exit;
}

class Ebonix_Gateway {

    public static function enabled() {
        return qa_opt('gateway_enabled') == '1' && !empty(qa_opt('gateway_url'));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Representation rules (read from admin settings)
    // ─────────────────────────────────────────────────────────────────────────
    private static function get_representation_rules() {
        $rules = [];

        if (qa_opt('black_rep_enabled') == '1') {
            $skin_tone    = qa_opt('black_rep_skin_tone');
            $hair_texture = qa_opt('black_rep_hair_texture');

            if (!empty($skin_tone))    $rules['skin_tone']    = $skin_tone;
            if (!empty($hair_texture)) $rules['hair_texture'] = $hair_texture;
            if (qa_opt('black_rep_prevent_whitewashing') == '1') $rules['prevent_whitewashing']    = true;
            if (qa_opt('black_rep_keep_consistent')      == '1') $rules['keep_features_consistent'] = true;
        }

        if (empty($rules)) {
            $rules['default_representation'] = 'diverse_black';
        }

        return $rules;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // IMAGE GENERATION
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * @param string      $prompt
     * @param string      $model
     * @param string      $size
     * @param string      $style
     * @param string      $negative_prompt
     * @param array|null  $image_data   Keys: base64, mime_type, furl, imageid
     * @return array  ['success' => bool, 'image_url' => string] | ['success' => false, 'error' => string]
     */
    public static function generate_image($prompt, $model, $size, $style, $negative_prompt, $image_data = null) {
        if (!self::enabled()) {
            return ['success' => false, 'error' => 'Gateway not enabled'];
        }

        $gateway_url   = rtrim(qa_opt('gateway_url'), '/');
        $gateway_token = qa_opt('gateway_token'); // ✅ same key as video — use one setting

        $payload = [
            'type'                 => 'image',
            'prompt'               => trim($prompt),
            'model'                => $model,
            'size'                 => $size,
            'style'                => $style,
            'negative_prompt'      => $negative_prompt,
            'representation_rules' => self::get_representation_rules(),
        ];

        // ✅ Forward reference image to gateway
        if (!empty($image_data)) {
            if (!empty($image_data['base64'])) {
                $payload['reference_image'] = [
                    'data'      => $image_data['base64'],
                    'mime_type' => $image_data['mime_type'] ?? 'image/jpeg',
                ];
            } elseif (!empty($image_data['furl'])) {
                $payload['reference_image'] = ['url' => $image_data['furl']];
            }
            if (!empty($image_data['imageid'])) {
                $payload['image_id'] = $image_data['imageid'];
            }
        }

        error_log("Gateway IMAGE: model={$model} size={$size} has_ref_image=" . (!empty($payload['reference_image']) ? 'yes' : 'no'));

        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
        ];
        // ✅ Add auth header if token is configured
        if (!empty($gateway_token)) {
            $headers[] = 'Authorization: Bearer ' . $gateway_token;
        }

        $ch = curl_init($gateway_url . '/generate/image');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, 300);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);

        $raw      = curl_exec($ch);
        $http     = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_err = curl_error($ch);
        curl_close($ch);

        if (!empty($curl_err)) {
            error_log("Gateway IMAGE CURL error: {$curl_err}");
            return ['success' => false, 'error' => 'Gateway connection failed: ' . $curl_err];
        }

        if ($http !== 200) {
            error_log("Gateway IMAGE HTTP {$http}: " . substr($raw, 0, 500));
            $err = @json_decode($raw, true);
            return ['success' => false, 'error' => $err['error'] ?? "Gateway HTTP {$http}"];
        }

        $result = @json_decode($raw, true);

        if (empty($result['success']) || empty($result['image_url'])) {
            error_log("Gateway IMAGE: no image in response. Raw: " . substr($raw, 0, 500));
            return ['success' => false, 'error' => $result['error'] ?? 'Gateway returned no image'];
        }

        error_log("Gateway IMAGE: success");
        return $result;
    }


    // ─────────────────────────────────────────────────────────────────────────────
// SELFIE TRANSFORMATION
// Sends base64 image to Python gateway for:
//   1. Gemini Vision detection (Black vs non-Black)
//   2. Appropriate prompt construction
//   3. Fal FLUX.1 Kontext transformation
// Returns: ['success' => true,  'image_urls' => ['https://...']]
//       OR ['success' => false, 'error'      => 'message']
// ─────────────────────────────────────────────────────────────────────────────
public static function transform_selfie(
    string $image_b64,
    string $mime_type,
    string $style_preset,
    string $additional_prompt = ''
): array {

    if (!self::enabled()) {
        return ['success' => false, 'error' => 'Gateway not enabled'];
    }

    $gateway_url   = rtrim((string)qa_opt('gateway_url'), '/');
    $gateway_token = (string)qa_opt('gateway_token');

    $payload = [
        'image_b64'         => $image_b64,
        'mime_type'         => $mime_type,
        'style_preset'      => $style_preset,
        'additional_prompt' => trim($additional_prompt),
    ];

    error_log("Gateway::transform_selfie style={$style_preset} mime={$mime_type} b64_len=" . strlen($image_b64));

    $headers = ['Content-Type: application/json', 'Accept: application/json'];
    if (!empty($gateway_token)) {
        $headers[] = 'Authorization: Bearer ' . $gateway_token;
    }

    $ch = curl_init($gateway_url . '/transform_selfie');
    curl_setopt($ch, CURLOPT_POST,          true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS,    json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER,    $headers);
    curl_setopt($ch, CURLOPT_TIMEOUT,       660);  // 11 min — Fal polls up to 7.5 min
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);

    $raw      = curl_exec($ch);
    $http     = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_err = curl_error($ch);
    curl_close($ch);

    if (!empty($curl_err)) {
        error_log("Gateway::transform_selfie CURL error: {$curl_err}");
        return ['success' => false, 'error' => 'Gateway connection failed: ' . $curl_err];
    }

    if ($http !== 200) {
        error_log("Gateway::transform_selfie HTTP {$http}: " . substr((string)$raw, 0, 400));
        $decoded = @json_decode((string)$raw, true);
        return [
            'success' => false,
            'error'   => $decoded['error'] ?? $decoded['detail'] ?? "Gateway HTTP {$http}",
        ];
    }

    $result = @json_decode((string)$raw, true);

    if (empty($result['success'])) {
        $msg = $result['error'] ?? 'Gateway returned no result';
        error_log("Gateway::transform_selfie: failed — {$msg}");
        return ['success' => false, 'error' => $msg];
    }

    $count = count($result['image_urls'] ?? []);
    error_log("Gateway::transform_selfie: OK — {$count} image(s) detected_black=" . ($result['detected_black'] ? 'yes' : 'no'));
    return $result;
}

    // ─────────────────────────────────────────────────────────────────────────
    // VIDEO GENERATION
    // ─────────────────────────────────────────────────────────────────────────

    public static function generate_video($prompt, $model, $aspect_ratio = '16:9', $resolution = '540p', $image_url = null) {
        if (!self::enabled()) {
            return ['error' => 'Gateway not enabled'];
        }

        $gateway_url   = rtrim(qa_opt('gateway_url'), '/');
        $gateway_token = qa_opt('gateway_token');

        if (empty($gateway_token)) {
            return ['error' => 'Gateway token not configured'];
        }

        error_log("Gateway VIDEO: model={$model} prompt=" . substr($prompt, 0, 60));

        $payload = [
            'type'                 => 'video',
            'prompt'               => trim($prompt),
            'model'                => $model,
            'aspect_ratio'         => $aspect_ratio,
            'resolution'           => $resolution,
            'representation_rules' => self::get_representation_rules(),
        ];

        if ($image_url) {
            $payload['image_url'] = $image_url;
        }

        $ch = curl_init($gateway_url . '/generate_video');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $gateway_token,
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 600);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);

        $response   = curl_exec($ch);
        $http_code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        curl_close($ch);

        if (!empty($curl_error)) {
            error_log("Gateway VIDEO CURL error: {$curl_error}");
            return ['error' => 'Gateway connection failed: ' . $curl_error];
        }

        if ($http_code !== 200) {
            error_log("Gateway VIDEO HTTP {$http_code}: " . substr($response, 0, 500));
            return ['error' => 'Gateway returned HTTP ' . $http_code];
        }

        $data = json_decode($response, true);
        if (!$data) {
            error_log("Gateway VIDEO: invalid JSON response");
            return ['error' => 'Invalid gateway response'];
        }

        error_log("Gateway VIDEO: response received, success=" . ($data['success'] ? 'true' : 'false'));
        return $data;
    }
}