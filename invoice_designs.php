<?php
// invoice_designs.php - Trading Invoice Design Selection Page
// Place this file in: public_html/billing/trading/invoice_designs.php

date_default_timezone_set('Asia/Kolkata');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/*
|--------------------------------------------------------------------------
| DATABASE CONFIG
|--------------------------------------------------------------------------
| Your Trading project commonly uses config/database.php.
| Extra fallbacks are added only for safety.
*/
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
    die('Database config file not found. Please check invoice_designs.php config path.');
}

if (!function_exists('e')) {
    function e($value)
    {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

/*
|--------------------------------------------------------------------------
| DB CONNECTION
|--------------------------------------------------------------------------
| Supports PDO $pdo and MySQLi $conn / $mysqli.
*/
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
    die('Database connection not found. Expected $pdo, $conn, or $mysqli.');
}

function fetchAllRows($db, string $dbType, string $sql, array $params = []): array
{
    if ($dbType === 'pdo') {
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
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

    $result = $stmt->get_result();
    $rows = [];

    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
    }

    $stmt->close();
    return $rows;
}

function fetchOneRow($db, string $dbType, string $sql, array $params = []): ?array
{
    $rows = fetchAllRows($db, $dbType, $sql, $params);
    return $rows[0] ?? null;
}

/*
|--------------------------------------------------------------------------
| AUTH CHECK
|--------------------------------------------------------------------------
| Kept same as your template UI.
| If you do not want login check, comment these two checks.
*/
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$role = (string)($_SESSION['role'] ?? '');

if ($role !== 'admin') {
    http_response_code(403);
    exit('Access denied.');
}

/*
|--------------------------------------------------------------------------
| BUSINESS ID
|--------------------------------------------------------------------------
*/
$businessId = (int)(
    $_SESSION['current_business_id']
    ?? $_SESSION['business_id']
    ?? $_SESSION['selected_business_id']
    ?? 0
);

if ($businessId <= 0) {
    exit('Business is not selected.');
}

if (empty($_SESSION['invoice_design_csrf'])) {
    $_SESSION['invoice_design_csrf'] = bin2hex(random_bytes(32));
}

$currentBusinessName = (string)(
    $_SESSION['current_business_name']
    ?? $_SESSION['business_name']
    ?? 'Business'
);

$page_title = 'Invoice Designs';
$pageError = '';
$designs = [];

try {
    $designs = fetchAllRows(
        $db,
        $dbType,
        "
        SELECT
            d.id,
            d.design_name,
            d.design_code,
            d.design_file,
            d.preview_image,
            d.description,
            d.is_active,
            d.sort_order,
            CASE
                WHEN s.design_id = d.id THEN 1
                ELSE 0
            END AS is_selected
        FROM new_invoice_designs AS d
        LEFT JOIN business_selected_invoice_design AS s
            ON s.business_id = ?
           AND s.design_id = d.id
        WHERE d.is_active = 1
        ORDER BY d.sort_order ASC, d.id ASC
        ",
        [$businessId]
    );
} catch (Throwable $ex) {
    $pageError = 'Unable to load invoice designs: ' . $ex->getMessage();
}

function previewFileExists(string $previewImage): bool
{
    $previewImage = trim($previewImage);

    if ($previewImage === '') {
        return false;
    }

    if (preg_match('#^https?://#i', $previewImage)) {
        return true;
    }

    return is_file(__DIR__ . '/' . ltrim($previewImage, '/'));
}
?>
<!doctype html>
<html lang="en">
<?php include 'includes/head.php'; ?>

<body data-sidebar="dark">
    <div id="layout-wrapper">

        <?php include 'includes/topbar.php'; ?>

        <div class="vertical-menu">
            <div data-simplebar class="h-100">
                <?php include 'includes/sidebar.php'; ?>
            </div>
        </div>

        <div class="main-content">
            <div class="page-content">
                <div class="container-fluid">

                    <div class="row">
                        <div class="col-12">
                            <div class="page-title-box d-flex align-items-center justify-content-between flex-wrap gap-2">
                                <div>
                                    <h4 class="mb-1">
                                        <i class="bx bx-file me-2"></i>
                                        Invoice Designs
                                    </h4>

                                    <small class="text-muted">
                                        <i class="bx bx-buildings me-1"></i>
                                        <?= e($currentBusinessName) ?>
                                    </small>
                                </div>
                            </div>
                        </div>
                    </div>

                    <?php if (!empty($_SESSION['success'])): ?>
                        <div class="alert alert-success alert-dismissible fade show" role="alert">
                            <i class="bx bx-check-circle me-2"></i>
                            <?= e($_SESSION['success']) ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                        <?php unset($_SESSION['success']); ?>
                    <?php endif; ?>

                    <?php if (!empty($_SESSION['error'])): ?>
                        <div class="alert alert-danger alert-dismissible fade show" role="alert">
                            <i class="bx bx-error-circle me-2"></i>
                            <?= e($_SESSION['error']) ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                        <?php unset($_SESSION['error']); ?>
                    <?php endif; ?>

                    <?php if (!empty($pageError)): ?>
                        <div class="alert alert-danger">
                            <i class="bx bx-error-circle me-2"></i>
                            <?= e($pageError) ?>
                        </div>
                    <?php endif; ?>

                    <div class="card shadow-sm mb-4">
                        <div class="card-body">
                            <div class="d-flex align-items-start gap-3">
                                <div class="avatar-sm">
                                    <span class="avatar-title bg-primary bg-opacity-10 text-primary rounded-circle fs-4">
                                        <i class="bx bx-info-circle"></i>
                                    </span>
                                </div>

                                <div>
                                    <h5 class="mb-1">Select the default invoice design</h5>
                                    <p class="text-muted mb-0">
                                        The selected design will be used when invoices are printed from POS billing.
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <?php if (empty($designs) && empty($pageError)): ?>
                        <div class="alert alert-info">
                            <i class="bx bx-info-circle me-2"></i>
                            No active invoice designs are available.
                        </div>
                    <?php else: ?>

                        <form method="post" action="invoice_design_save.php" id="invoiceDesignForm">
                            <input type="hidden" name="csrf_token" value="<?= e($_SESSION['invoice_design_csrf']) ?>">

                            <div class="row g-4">
                                <?php foreach ($designs as $design): ?>
                                    <?php
                                    $designId = (int)$design['id'];
                                    $isSelected = (int)$design['is_selected'] === 1;
                                    $previewImage = trim((string)($design['preview_image'] ?? ''));
                                    $previewExists = previewFileExists($previewImage);
                                    ?>

                                    <div class="col-xl-4 col-lg-4 col-md-6">
                                        <label class="invoice-design-option w-100 h-100">

                                            <input type="radio" name="design_id" value="<?= $designId ?>" <?= $isSelected ? 'checked' : '' ?> required>

                                            <div class="card invoice-design-card h-100 shadow-sm">

                                                <div class="invoice-design-preview">
                                                    <?php if ($previewExists): ?>
                                                        <img src="<?= e($previewImage) ?>" alt="<?= e($design['design_name']) ?>" loading="lazy">
                                                    <?php else: ?>
                                                        <div class="preview-placeholder">
                                                            <i class="bx bx-image-alt"></i>
                                                            <span>Preview image not found</span>
                                                        </div>
                                                    <?php endif; ?>

                                                    <div class="selected-indicator">
                                                        <i class="bx bx-check"></i>
                                                    </div>
                                                </div>

                                                <div class="card-body">
                                                    <div class="d-flex align-items-start justify-content-between gap-2">
                                                        <div>
                                                            <h5 class="card-title mb-1">
                                                                <?= e($design['design_name']) ?>
                                                            </h5>

                                                            <p class="text-muted mb-2">
                                                                <?= e($design['description'] ?? '') ?>
                                                            </p>
                                                        </div>

                                                        <?php if ($isSelected): ?>
                                                            <span class="badge bg-success current-badge">
                                                                Current
                                                            </span>
                                                        <?php endif; ?>
                                                    </div>

                                                    <div class="d-flex align-items-center justify-content-between mt-3">
                                                        <small class="text-muted">
                                                            Code:
                                                            <?= e($design['design_code']) ?>
                                                        </small>

                                                        <span class="selection-text">
                                                            <?= $isSelected ? 'Selected' : 'Select design' ?>
                                                        </span>
                                                    </div>
                                                </div>
                                            </div>
                                        </label>
                                    </div>
                                <?php endforeach; ?>
                            </div>

                            <div class="card shadow-sm mt-4">
                                <div class="card-body d-flex align-items-center justify-content-between flex-wrap gap-3">
                                    <div>
                                        <h6 class="mb-1">Save selected invoice design</h6>
                                        <small class="text-muted">
                                            This setting applies only to the currently selected business.
                                        </small>
                                    </div>

                                    <button type="submit" class="btn btn-primary" id="saveDesignButton">
                                        <i class="bx bx-save me-1"></i>
                                        Save Invoice Design
                                    </button>
                                </div>
                            </div>
                        </form>

                    <?php endif; ?>

                </div>
            </div>

            <?php if (is_file(__DIR__ . '/includes/footer.php')): ?>
                <?php include 'includes/footer.php'; ?>
            <?php endif; ?>
        </div>
    </div>

    <style>
    .invoice-design-option {
        position: relative;
        display: block;
        cursor: pointer;
    }

    .invoice-design-option>input[type="radio"] {
        position: absolute;
        opacity: 0;
        pointer-events: none;
    }

    .invoice-design-card {
        overflow: hidden;
        border: 2px solid #e5e7eb;
        transition:
            border-color .2s ease,
            box-shadow .2s ease,
            transform .2s ease;
    }

    .invoice-design-option:hover .invoice-design-card {
        transform: translateY(-3px);
        box-shadow: 0 10px 26px rgba(15, 23, 42, .10) !important;
    }

    .invoice-design-option>input[type="radio"]:checked+.invoice-design-card {
        border-color: #556ee6;
        box-shadow: 0 0 0 4px rgba(85, 110, 230, .14) !important;
    }

    .invoice-design-preview {
        position: relative;
        height: 470px;
        padding: 14px;
        overflow: hidden;
        background: #f5f6f8;
        border-bottom: 1px solid #e5e7eb;
    }

    .invoice-design-preview img {
        display: block;
        width: 100%;
        height: 100%;
        object-fit: contain;
        background: #ffffff;
        border: 1px solid #e5e7eb;
    }

    .preview-placeholder {
        width: 100%;
        height: 100%;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        gap: 8px;
        color: #94a3b8;
        background: #ffffff;
        border: 1px dashed #cbd5e1;
    }

    .preview-placeholder i {
        font-size: 52px;
    }

    .selected-indicator {
        position: absolute;
        top: 22px;
        right: 22px;
        width: 38px;
        height: 38px;
        display: none;
        align-items: center;
        justify-content: center;
        border-radius: 50%;
        color: #ffffff;
        background: #556ee6;
        box-shadow: 0 4px 14px rgba(15, 23, 42, .22);
        font-size: 24px;
    }

    .invoice-design-option>input[type="radio"]:checked+.invoice-design-card .selected-indicator {
        display: flex;
    }

    .selection-text {
        font-size: 12px;
        font-weight: 600;
        color: #556ee6;
    }

    .current-badge {
        white-space: nowrap;
    }

    @media (max-width: 767.98px) {
        .invoice-design-preview {
            height: 380px;
        }
    }
    </style>

    <?php include 'includes/scripts.php'; ?>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const form = document.getElementById('invoiceDesignForm');
        const saveButton = document.getElementById('saveDesignButton');
        const designInputs = document.querySelectorAll('input[name="design_id"]');

        designInputs.forEach(function(input) {
            input.addEventListener('change', function() {
                document.querySelectorAll('.selection-text').forEach(function(element) {
                    element.textContent = 'Select design';
                });

                const card = input.nextElementSibling;
                const selectionText = card ? card.querySelector('.selection-text') : null;

                if (selectionText) {
                    selectionText.textContent = 'Selected';
                }
            });
        });

        if (form && saveButton) {
            form.addEventListener('submit', function() {
                saveButton.disabled = true;
                saveButton.innerHTML =
                    '<span class="spinner-border spinner-border-sm me-1"></span> Saving...';
            });
        }
    });
    </script>

</body>
</html>
