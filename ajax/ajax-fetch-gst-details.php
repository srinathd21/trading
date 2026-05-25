<?php
// /ajax/ajax-fetch-gst-details.php
// Debug-friendly GST Verify API endpoint.
// Based on your working create-buyer.php flow. API key and URL are written directly in this code.
// PHP 7.2 compatible.

date_default_timezone_set('Asia/Kolkata');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/business_features.php';

header('Content-Type: application/json; charset=utf-8');

function gst_json($data, $statusCode = 200)
{
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }

    http_response_code((int)$statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (!isset($pdo) || !($pdo instanceof PDO)) {
    gst_json([
        'ok' => false,
        'success' => false,
        'message' => 'Database connection not available. config/database.php must create $pdo.'
    ], 500);
}

function gst_table_exists(PDO $pdo, $table)
{
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM information_schema.tables
            WHERE table_schema = DATABASE()
              AND table_name = ?
        ");
        $stmt->execute([$table]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (Exception $e) {
        return false;
    }
}

function gst_ensure_tables(PDO $pdo)
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS business_feature_master (
            id INT AUTO_INCREMENT PRIMARY KEY,
            feature_key VARCHAR(100) NOT NULL UNIQUE,
            feature_name VARCHAR(150) NOT NULL,
            feature_group VARCHAR(100) DEFAULT NULL,
            description TEXT DEFAULT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            sort_order INT DEFAULT 0,
            created_by INT DEFAULT NULL,
            created_by_name VARCHAR(150) DEFAULT NULL,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS business_feature_access (
            id INT AUTO_INCREMENT PRIMARY KEY,
            business_id INT NOT NULL,
            feature_id INT NOT NULL,
            is_enabled TINYINT(1) NOT NULL DEFAULT 0,
            enabled_by INT DEFAULT NULL,
            enabled_at DATETIME DEFAULT NULL,
            expires_at DATE DEFAULT NULL,
            notes VARCHAR(255) DEFAULT NULL,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_business_feature (business_id, feature_id),
            INDEX idx_business_id (business_id),
            INDEX idx_feature_id (feature_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $stmt = $pdo->prepare("
        INSERT INTO business_feature_master
            (feature_key, feature_name, feature_group, description, is_active, sort_order, created_by_name)
        VALUES
            ('gst_api_fetch', 'GST API Fetch Details', 'GST & Tax', 'Allow business to fetch GSTIN details using paid GST API.', 1, 1, 'System Default')
        ON DUPLICATE KEY UPDATE
            feature_name = VALUES(feature_name),
            feature_group = VALUES(feature_group),
            description = VALUES(description),
            is_active = 1
    ");
    $stmt->execute();
}

function gst_setting(PDO $pdo, $businessId, $key)
{
    if (!gst_table_exists($pdo, 'api_settings')) {
        return '';
    }

    $stmt = $pdo->prepare("
        SELECT setting_value
        FROM api_settings
        WHERE business_id = ?
          AND setting_key = ?
          AND is_active = 1
        LIMIT 1
    ");
    $stmt->execute([(int)$businessId, $key]);
    $value = $stmt->fetchColumn();

    if ($value !== false && trim((string)$value) !== '') {
        return trim((string)$value);
    }

    $stmt = $pdo->prepare("
        SELECT setting_value
        FROM api_settings
        WHERE business_id IS NULL
          AND setting_key = ?
          AND is_active = 1
        LIMIT 1
    ");
    $stmt->execute([$key]);
    $value = $stmt->fetchColumn();

    if ($value !== false && trim((string)$value) !== '') {
        return trim((string)$value);
    }

    return '';
}

function gst_api_config(PDO $pdo, $businessId)
{
    /*
        Direct hard-coded GSTVerify API configuration.
        No api_settings DB value is used here.

        Keep trailing slash in API URL.
        Final URL becomes:
        https://gstverify.co.in/api/v1/verify/33ABWFM1387R1Z8
    */
    $apiKey = 'gstv_3969b43a50d2a825da7dc4191065ce0f9a41f7d326246ed3';
    $apiUrl = 'https://gstverify.co.in/api/v1/verify/';

    return [$apiKey, $apiUrl];
}

function gst_business_id()
{
    if (!empty($_SESSION['current_business_id'])) {
        return (int)$_SESSION['current_business_id'];
    }

    if (!empty($_SESSION['business_id'])) {
        return (int)$_SESSION['business_id'];
    }

    return 0;
}

function extractPinFromAddress($address)
{
    if (preg_match('/\b([1-9][0-9]{5})\b/', (string)$address, $m)) {
        return $m[1];
    }

    return '';
}

function extractCityFromAddress($address, $state = '')
{
    $address = trim((string)$address);

    if ($address === '') {
        return '';
    }

    $parts = preg_split('/[,—-]+/', $address);
    $parts = array_values(array_filter(array_map('trim', $parts)));

    if (empty($parts)) {
        return '';
    }

    $cleanParts = [];

    foreach ($parts as $part) {
        $partClean = trim(preg_replace('/\b[1-9][0-9]{5}\b/', '', $part));

        if ($state !== '' && strcasecmp($partClean, $state) === 0) {
            continue;
        }

        if ($partClean !== '') {
            $cleanParts[] = $partClean;
        }
    }

    if (empty($cleanParts)) {
        return '';
    }

    return end($cleanParts);
}

function build_gst_url($apiUrl, $gstin)
{
    $apiUrl = trim((string)$apiUrl);

    if ($apiUrl === '') {
        $apiUrl = 'https://gstverify.co.in/api/v1/verify/';
    }

    if (strpos($apiUrl, '{GSTIN}') !== false) {
        return str_replace('{GSTIN}', rawurlencode($gstin), $apiUrl);
    }

    return rtrim($apiUrl, '/') . '/' . rawurlencode($gstin);
}

function mask_key($key)
{
    $key = (string)$key;
    $len = strlen($key);

    if ($len <= 10) {
        return str_repeat('*', $len);
    }

    return substr($key, 0, 6) . str_repeat('*', max(4, $len - 12)) . substr($key, -6);
}

function api_error_response($message, $debugData = [])
{
    $debug = isset($_GET['debug']) && (string)$_GET['debug'] === '1';

    $response = [
        'ok' => false,
        'success' => false,
        'message' => $message
    ];

    if ($debug) {
        $response['debug'] = $debugData;
    }

    return $response;
}

function verifyGstinFromApi($gstin, $apiKey, $apiUrl)
{
    if ($apiKey === '') {
        return [
            'ok' => false,
            'message' => 'GST API key is not configured in code.'
        ];
    }

    if (!preg_match('/^[0-9A-Z]{15}$/', $gstin)) {
        return [
            'ok' => false,
            'message' => 'Invalid GSTIN format.'
        ];
    }

    if (!function_exists('curl_init')) {
        return [
            'ok' => false,
            'message' => 'cURL is not enabled on server.'
        ];
    }

    $url = build_gst_url($apiUrl, $gstin);

    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_HTTPHEADER => [
            'X-API-Key: ' . $apiKey,
            'Accept: application/json'
        ],
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2
    ]);

    $raw = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $contentType = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);

    if ($raw === false || $curlError !== '') {
        return [
            'ok' => false,
            'message' => 'Unable to connect GST Verify API. ' . $curlError,
            'debug' => [
                'url' => $url,
                'http_code' => $httpCode,
                'curl_error' => $curlError
            ]
        ];
    }

    $rawHeaders = substr($raw, 0, $headerSize);
    $body = substr($raw, $headerSize);
    $bodyTrimmed = trim((string)$body);
    $decoded = json_decode($bodyTrimmed, true);

    $preview = trim(strip_tags(substr($bodyTrimmed, 0, 600)));
    if ($preview === '') {
        $preview = substr($bodyTrimmed, 0, 600);
    }

    $debugData = [
        'url' => $url,
        'http_code' => $httpCode,
        'content_type' => $contentType,
        'api_key_masked' => mask_key($apiKey),
        'response_preview' => $preview,
        'response_headers' => substr($rawHeaders, 0, 1200)
    ];

    if (!is_array($decoded)) {
        return api_error_response(
            'GST Verify returned non-JSON response. Check API URL/key or open debug details.',
            $debugData
        );
    }

    if ($httpCode === 401) {
        return api_error_response('Missing or invalid GST Verify API key.', $debugData);
    }

    if ($httpCode === 402) {
        return api_error_response('GST Verify credits are over. Recharge your account.', $debugData);
    }

    if ($httpCode === 422) {
        return api_error_response('Invalid GSTIN format.', $debugData);
    }

    if ($httpCode === 429) {
        return api_error_response('GST Verify rate limit exceeded. Try again after some time.', $debugData);
    }

    if ($httpCode === 502) {
        return api_error_response('GST upstream service is unavailable. Try again later.', $debugData);
    }

    if ($httpCode < 200 || $httpCode >= 300) {
        return api_error_response(
            $decoded['message'] ?? ('GST Verify API error. HTTP ' . $httpCode),
            $debugData
        );
    }

    if (empty($decoded['success']) || empty($decoded['data']) || !is_array($decoded['data'])) {
        return api_error_response(
            $decoded['message'] ?? 'GSTIN verification failed.',
            array_merge($debugData, ['decoded' => $decoded])
        );
    }

    $data = $decoded['data'];

    $address = trim((string)($data['address'] ?? ''));
    $state = trim((string)($data['state'] ?? ''));
    $pin = extractPinFromAddress($address);
    $city = extractCityFromAddress($address, $state);

    $tradeName = trim((string)($data['trade_name'] ?? ''));
    $legalName = trim((string)($data['legal_name'] ?? ''));
    $companyName = $tradeName !== '' ? $tradeName : $legalName;

    return [
        'ok' => true,
        'success' => true,
        'message' => 'GSTIN verified successfully.',
        'credits_remaining' => $decoded['credits_remaining'] ?? null,
        'cached' => $decoded['cached'] ?? null,
        'data' => [
            'gstn' => strtoupper((string)($data['gstin'] ?? $gstin)),
            'gstin' => strtoupper((string)($data['gstin'] ?? $gstin)),
            'name' => $companyName,
            'company_name' => $companyName,
            'customer_type' => 'wholesale',
            'legal_name' => $legalName,
            'trade_name' => $tradeName,
            'status' => trim((string)($data['status'] ?? '')),
            'constitution' => trim((string)($data['constitution'] ?? '')),
            'taxpayer_type' => trim((string)($data['taxpayer_type'] ?? '')),
            'registration_date' => trim((string)($data['registration_date'] ?? '')),
            'state' => $state,
            'pan' => trim((string)($data['pan'] ?? '')),
            'address' => $address,
            'pin_code' => $pin,
            'city' => $city,
            'nature_of_business' => $data['nature_of_business'] ?? []
        ]
    ];
}

try {
    gst_ensure_tables($pdo);

    $businessId = gst_business_id();

    if ($businessId <= 0) {
        gst_json([
            'ok' => false,
            'success' => false,
            'message' => 'Business not selected.'
        ], 400);
    }

    if (!function_exists('hasBusinessFeature')) {
        gst_json([
            'ok' => false,
            'success' => false,
            'message' => 'Business feature helper not loaded.'
        ], 500);
    }

    if (!hasBusinessFeature($pdo, $businessId, 'gst_api_fetch')) {
        gst_json([
            'ok' => false,
            'success' => false,
            'message' => 'GST API Fetch is not enabled for this business. Please enter details manually.'
        ], 403);
    }

    $gstin = strtoupper(trim((string)($_GET['gstin'] ?? $_POST['gstin'] ?? '')));
    $gstin = preg_replace('/[^0-9A-Z]/', '', $gstin);

    if ($gstin === '') {
        gst_json([
            'ok' => false,
            'success' => false,
            'message' => 'Enter GSTIN number.'
        ], 400);
    }

    if (!preg_match('/^[0-9A-Z]{15}$/', $gstin)) {
        gst_json([
            'ok' => false,
            'success' => false,
            'message' => 'Invalid GSTIN format.'
        ], 422);
    }

    [$apiKey, $apiUrl] = gst_api_config($pdo, $businessId);
    $result = verifyGstinFromApi($gstin, $apiKey, $apiUrl);

    if (empty($result['ok'])) {
        gst_json($result, 400);
    }

    gst_json($result, 200);

} catch (Throwable $e) {
    error_log('[GST_VERIFY_FATAL] ' . $e->getMessage());

    gst_json([
        'ok' => false,
        'success' => false,
        'message' => 'Server error while verifying GSTIN: ' . $e->getMessage()
    ], 500);
}
