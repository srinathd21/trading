<?php
// invoice_design_save.php - Save selected invoice design
// Place this file in: public_html/billing/trading/invoice_design_save.php

date_default_timezone_set('Asia/Kolkata');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$configLoaded = false;
$configFiles = [
    __DIR__ . '/config/database.php',
    __DIR__ . '/config/db.php',
    __DIR__ . '/includes/config.php',
    __DIR__ . '/../admin/config/db.php',
];

foreach ($configFiles as $file) {
    if (is_file($file)) {
        require_once $file;
        $configLoaded = true;
        break;
    }
}

if (!$configLoaded) {
    $_SESSION['error'] = 'Database config file not found.';
    header('Location: invoice_designs.php');
    exit;
}

$dbType = '';
$db = null;

if (isset($pdo) && $pdo instanceof PDO) {
    $dbType = 'pdo';
    $db = $pdo;
} elseif (isset($conn) && $conn instanceof mysqli) {
    $dbType = 'mysqli';
    $db = $conn;
    $db->set_charset('utf8mb4');
} elseif (isset($mysqli) && $mysqli instanceof mysqli) {
    $dbType = 'mysqli';
    $db = $mysqli;
    $db->set_charset('utf8mb4');
} else {
    $_SESSION['error'] = 'Database connection not found.';
    header('Location: invoice_designs.php');
    exit;
}

function runSelectOne($db, string $dbType, string $sql, array $params = []): ?array
{
    if ($dbType === 'pdo') {
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    $stmt = $db->prepare($sql);
    if (!$stmt) {
        throw new Exception('Prepare failed: ' . $db->error);
    }

    if (!empty($params)) {
        $types = '';
        $values = [];

        foreach ($params as $param) {
            $types .= is_int($param) ? 'i' : 's';
            $values[] = $param;
        }

        $stmt->bind_param($types, ...$values);
    }

    if (!$stmt->execute()) {
        throw new Exception('Execute failed: ' . $stmt->error);
    }

    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    return $row ?: null;
}

function runExecute($db, string $dbType, string $sql, array $params = []): void
{
    if ($dbType === 'pdo') {
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return;
    }

    $stmt = $db->prepare($sql);
    if (!$stmt) {
        throw new Exception('Prepare failed: ' . $db->error);
    }

    if (!empty($params)) {
        $types = '';
        $values = [];

        foreach ($params as $param) {
            if (is_int($param)) {
                $types .= 'i';
            } elseif (is_float($param)) {
                $types .= 'd';
            } else {
                $types .= 's';
            }
            $values[] = $param;
        }

        $stmt->bind_param($types, ...$values);
    }

    if (!$stmt->execute()) {
        throw new Exception('Execute failed: ' . $stmt->error);
    }

    $stmt->close();
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Invalid request.');
    }

    if (!isset($_SESSION['user_id'])) {
        throw new Exception('Session expired. Please login again.');
    }

    $role = (string)($_SESSION['role'] ?? '');

    if ($role !== 'admin') {
        throw new Exception('Access denied.');
    }

    $businessId = (int)(
        $_SESSION['current_business_id']
        ?? $_SESSION['business_id']
        ?? $_SESSION['selected_business_id']
        ?? 0
    );

    if ($businessId <= 0) {
        throw new Exception('Business is not selected.');
    }

    $csrf = (string)($_POST['csrf_token'] ?? '');
    $sessionCsrf = (string)($_SESSION['invoice_design_csrf'] ?? '');

    if ($csrf === '' || $sessionCsrf === '' || !hash_equals($sessionCsrf, $csrf)) {
        throw new Exception('Invalid request token. Refresh and try again.');
    }

    $designId = (int)($_POST['design_id'] ?? 0);

    if ($designId <= 0) {
        throw new Exception('Please select an invoice design.');
    }

    $design = runSelectOne(
        $db,
        $dbType,
        "SELECT id FROM new_invoice_designs WHERE id = ? AND is_active = 1 LIMIT 1",
        [$designId]
    );

    if (!$design) {
        throw new Exception('Selected invoice design is not available.');
    }

    $selectedBy = (int)($_SESSION['user_id'] ?? 0);

    runExecute(
        $db,
        $dbType,
        "
        INSERT INTO business_selected_invoice_design
        (
            business_id,
            design_id,
            selected_by,
            created_at,
            updated_at
        )
        VALUES
        (
            ?,
            ?,
            ?,
            CURRENT_TIMESTAMP,
            CURRENT_TIMESTAMP
        )
        ON DUPLICATE KEY UPDATE
            design_id = VALUES(design_id),
            selected_by = VALUES(selected_by),
            updated_at = CURRENT_TIMESTAMP
        ",
        [$businessId, $designId, $selectedBy]
    );

    $_SESSION['invoice_design_csrf'] = bin2hex(random_bytes(32));
    $_SESSION['success'] = 'Invoice design selected successfully.';
} catch (Throwable $e) {
    $_SESSION['error'] = $e->getMessage();
}

header('Location: invoice_designs.php');
exit;
