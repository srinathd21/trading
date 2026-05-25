<?php
date_default_timezone_set('Asia/Kolkata');
session_start();

require_once 'config/database.php';
require_once 'includes/number_format_helper.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

if ($_SESSION['role'] !== 'admin') {
    header('Location: dashboard.php');
    exit();
}

$business_id = $_SESSION['business_id'] ?? 1;
$message = '';
$message_type = '';

/* ===============================
   FETCH SHOPS
================================ */
$shops_stmt = $pdo->prepare("
    SELECT id, shop_name, shop_code
    FROM shops
    WHERE business_id = ?
      AND is_active = 1
    ORDER BY shop_name
");
$shops_stmt->execute([$business_id]);
$shops = $shops_stmt->fetchAll(PDO::FETCH_ASSOC);

/* ===============================
   SELECTED SHOP
================================ */
$selected_shop_id = $_GET['shop_id'] ?? 0;
$selected_shop_id = (int)$selected_shop_id;

if ($selected_shop_id <= 0) {
    $selected_shop_id = null;
}

if ($selected_shop_id) {
    $check_shop = $pdo->prepare("
        SELECT id
        FROM shops
        WHERE id = ?
          AND business_id = ?
        LIMIT 1
    ");
    $check_shop->execute([$selected_shop_id, $business_id]);

    if (!$check_shop->fetch()) {
        $selected_shop_id = null;
    }
}

/* ===============================
   DOCUMENT TYPES
================================ */
$document_types = [
    'invoice_gst' => [
        'label' => 'GST Invoice',
        'icon' => 'fas fa-file-invoice',
        'default_prefix' => 'INV',
        'sample' => 'INV202604-0009'
    ],
    'invoice_non_gst' => [
        'label' => 'Non-GST Invoice',
        'icon' => 'fas fa-receipt',
        'default_prefix' => 'NGST',
        'sample' => 'NGST202604-0009'
    ],
    'purchase' => [
        'label' => 'Purchase Order',
        'icon' => 'fas fa-shopping-cart',
        'default_prefix' => 'PO',
        'sample' => 'PO202604-0009'
    ],
    'quotation' => [
        'label' => 'Quotation',
        'icon' => 'fas fa-file-contract',
        'default_prefix' => 'QT',
        'sample' => 'QT202604-0009'
    ]
];

/* ===============================
   SAVE NUMBER FORMATS
================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_number_formats'])) {
    try {
        $post_shop_id = $_POST['shop_id'] ?? 0;
        $post_shop_id = (int)$post_shop_id;
        $save_shop_id = $post_shop_id > 0 ? $post_shop_id : null;

        if ($save_shop_id) {
            $check_shop = $pdo->prepare("
                SELECT id
                FROM shops
                WHERE id = ?
                  AND business_id = ?
                LIMIT 1
            ");
            $check_shop->execute([$save_shop_id, $business_id]);

            if (!$check_shop->fetch()) {
                throw new Exception('Invalid shop selected.');
            }
        }

        if (!isset($_POST['number_format']) || !is_array($_POST['number_format'])) {
            throw new Exception('No number format data received.');
        }

        $allowed_middle = ['year_month', 'financial_year', 'year', 'none'];
        $allowed_reset = ['financial_year', 'year', 'month', 'never'];

        foreach ($_POST['number_format'] as $document_type => $format) {
            if (!isset($document_types[$document_type])) {
                continue;
            }

            $prefix = strtoupper(trim($format['prefix'] ?? nf_default_prefix($document_type)));
            $middle_format = $format['middle_format'] ?? 'year_month';
            $format_separator = trim($format['format_separator'] ?? '-');
            $number_length = (int)($format['number_length'] ?? 4);
            $reset_period = $format['reset_period'] ?? 'financial_year';

            if ($prefix === '') {
                $prefix = nf_default_prefix($document_type);
            }

            if (!in_array($middle_format, $allowed_middle, true)) {
                $middle_format = 'year_month';
            }

            if (!in_array($reset_period, $allowed_reset, true)) {
                $reset_period = 'financial_year';
            }

            if ($number_length <= 0) {
                $number_length = 4;
            }

            if ($number_length > 10) {
                $number_length = 10;
            }

            if ($format_separator === '') {
                $format_separator = '-';
            }

            /*
                IMPORTANT:
                Do not use ON DUPLICATE KEY UPDATE here.
                MySQL/MariaDB allows multiple NULL values in unique keys.
                So business default rows with shop_id NULL can duplicate.
                We manually check first, then update or insert.
            */

            if ($save_shop_id === null) {
                $checkStmt = $pdo->prepare("
                    SELECT id
                    FROM number_format_settings
                    WHERE business_id = ?
                      AND shop_id IS NULL
                      AND document_type = ?
                    LIMIT 1
                ");
                $checkStmt->execute([$business_id, $document_type]);
            } else {
                $checkStmt = $pdo->prepare("
                    SELECT id
                    FROM number_format_settings
                    WHERE business_id = ?
                      AND shop_id = ?
                      AND document_type = ?
                    LIMIT 1
                ");
                $checkStmt->execute([$business_id, $save_shop_id, $document_type]);
            }

            $existingFormat = $checkStmt->fetch(PDO::FETCH_ASSOC);

            if ($existingFormat) {
                $updateStmt = $pdo->prepare("
                    UPDATE number_format_settings
                    SET
                        prefix = ?,
                        middle_format = ?,
                        format_separator = ?,
                        number_length = ?,
                        reset_period = ?,
                        is_active = 1,
                        updated_at = NOW()
                    WHERE id = ?
                      AND business_id = ?
                ");

                $updateStmt->execute([
                    $prefix,
                    $middle_format,
                    $format_separator,
                    $number_length,
                    $reset_period,
                    $existingFormat['id'],
                    $business_id
                ]);
            } else {
                $insertStmt = $pdo->prepare("
                    INSERT INTO number_format_settings
                    (
                        business_id,
                        shop_id,
                        document_type,
                        prefix,
                        middle_format,
                        format_separator,
                        number_length,
                        reset_period,
                        is_active
                    )
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)
                ");

                $insertStmt->execute([
                    $business_id,
                    $save_shop_id,
                    $document_type,
                    $prefix,
                    $middle_format,
                    $format_separator,
                    $number_length,
                    $reset_period
                ]);
            }
        }

        $message = 'Number format settings saved successfully!';
        $message_type = 'success';
        $selected_shop_id = $save_shop_id;

    } catch (Exception $e) {
        $message = 'Error: ' . $e->getMessage();
        $message_type = 'danger';
    }
}

/* ===============================
   LOAD CURRENT SETTINGS
================================ */
$number_formats = [];

foreach (array_keys($document_types) as $doc_type) {
    $number_formats[$doc_type] = nf_load_setting($pdo, $business_id, $selected_shop_id, $doc_type);
}

/* ===============================
   CURRENT SHOP NAME
================================ */
$current_shop_name = 'Business Default';

if ($selected_shop_id) {
    foreach ($shops as $shop) {
        if ((int)$shop['id'] === (int)$selected_shop_id) {
            $current_shop_name = $shop['shop_name'] . ' (' . $shop['shop_code'] . ')';
            break;
        }
    }
}

function nf_selected($current, $value)
{
    return $current === $value ? 'selected' : '';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Number Format Settings</title>
    <?php include('includes/head.php'); ?>

    <style>
        .format-card {
            border-left: 4px solid #0d6efd;
            border-radius: 10px;
            transition: all .2s ease;
        }

        .format-card:hover {
            box-shadow: 0 6px 18px rgba(0,0,0,.08);
        }

        .format-preview {
            background: #f8f9fa;
            border: 1px dashed #ced4da;
            padding: 10px 12px;
            border-radius: 8px;
            font-family: monospace;
            font-size: 16px;
            font-weight: 700;
            color: #0d6efd;
        }

        .shop-selector {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            border-left: 4px solid #0d6efd;
        }

        .shop-tab {
            display: inline-block;
            padding: 8px 15px;
            margin-right: 8px;
            margin-bottom: 8px;
            background: #e9ecef;
            border-radius: 5px;
            text-decoration: none;
            color: #495057;
            font-weight: 600;
        }

        .shop-tab.active {
            background: #0d6efd;
            color: #fff;
        }

        .shop-tab:hover {
            text-decoration: none;
            background: #dce3ea;
            color: #212529;
        }

        .help-box {
            background: #fff8e1;
            border-left: 4px solid #ffc107;
            padding: 12px 15px;
            border-radius: 8px;
        }

        .form-label {
            font-weight: 600;
        }
    </style>
</head>

<body data-sidebar="dark">

<div id="layout-wrapper">

    <?php include('includes/topbar.php'); ?>

    <div class="vertical-menu">
        <div data-simplebar class="h-100">
            <?php include('includes/sidebar.php'); ?>
        </div>
    </div>

    <div class="main-content">
        <div class="page-content">
            <div class="container-fluid">

                <!-- PAGE TITLE -->
                <div class="row">
                    <div class="col-12">
                        <div class="page-title-box d-flex align-items-center justify-content-between">
                            <h4 class="mb-0">Document Number Format Settings</h4>

                            <div class="page-title-right">
                                <ol class="breadcrumb m-0">
                                    <li class="breadcrumb-item">
                                        <a href="dashboard.php">Dashboard</a>
                                    </li>
                                    <li class="breadcrumb-item active">Number Format Settings</li>
                                </ol>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- MESSAGE -->
                <?php if (!empty($message)): ?>
                    <div class="alert alert-<?php echo htmlspecialchars($message_type); ?> alert-dismissible fade show">
                        <?php echo htmlspecialchars($message); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <!-- SHOP SELECTOR -->
                <div class="row mb-3">
                    <div class="col-12">
                        <div class="shop-selector">
                            <h5 class="mb-3">
                                <i class="fas fa-store me-2"></i>Select Shop / Store
                            </h5>

                            <a href="?shop_id=0"
                               class="shop-tab <?php echo !$selected_shop_id ? 'active' : ''; ?>">
                                <i class="fas fa-building"></i> Business Default
                            </a>

                            <?php foreach ($shops as $shop): ?>
                                <a href="?shop_id=<?php echo (int)$shop['id']; ?>"
                                   class="shop-tab <?php echo ((int)$selected_shop_id === (int)$shop['id']) ? 'active' : ''; ?>">
                                    <i class="fas fa-store"></i>
                                    <?php echo htmlspecialchars($shop['shop_name']); ?>
                                    (<?php echo htmlspecialchars($shop['shop_code']); ?>)
                                </a>
                            <?php endforeach; ?>

                            <div class="mt-2">
                                <small class="text-muted">
                                    Business default applies to all shops unless shop-specific settings are saved.
                                </small>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- FORM -->
                <form method="POST">
                    <input type="hidden" name="shop_id" value="<?php echo $selected_shop_id ? (int)$selected_shop_id : 0; ?>">

                    <div class="row mb-4">
                        <div class="col-12">
                            <div class="card">
                                <div class="card-body">

                                    <h5 class="card-title">
                                        Current Settings For:
                                        <span class="text-primary">
                                            <?php echo htmlspecialchars($current_shop_name); ?>
                                        </span>
                                    </h5>

                                    <div class="help-box mb-3">
                                        <strong>Format:</strong>
                                        Prefix + Middle Format + Separator + Last Digit
                                        <br>
                                        <strong>Example:</strong>
                                        INV + 202604 + - + 0009 =
                                        <strong>INV202604-0009</strong>
                                    </div>

                                    <div class="row">
                                        <?php foreach ($document_types as $doc_key => $doc): ?>
                                            <?php
                                            $fmt = $number_formats[$doc_key];
                                            $preview = nf_preview_number($fmt, date('Y-m-d'), 9);
                                            ?>

                                            <div class="col-xl-6 col-lg-6 col-md-12 mb-4">
                                                <div class="card format-card h-100">
                                                    <div class="card-body">

                                                        <h5 class="mb-3">
                                                            <i class="<?php echo htmlspecialchars($doc['icon']); ?> me-2 text-primary"></i>
                                                            <?php echo htmlspecialchars($doc['label']); ?>
                                                        </h5>

                                                        <div class="row">

                                                            <!-- PREFIX -->
                                                            <div class="col-md-6 mb-3">
                                                                <label class="form-label">Prefix</label>
                                                                <input type="text"
                                                                       class="form-control nf-input"
                                                                       name="number_format[<?php echo $doc_key; ?>][prefix]"
                                                                       value="<?php echo htmlspecialchars($fmt['prefix'] ?? $doc['default_prefix']); ?>"
                                                                       data-doc="<?php echo $doc_key; ?>">

                                                                <small class="text-muted">
                                                                    Example: <?php echo htmlspecialchars($doc['default_prefix']); ?>
                                                                </small>
                                                            </div>

                                                            <!-- MIDDLE FORMAT -->
                                                            <div class="col-md-6 mb-3">
                                                                <label class="form-label">Middle Format</label>
                                                                <select class="form-control nf-input"
                                                                        name="number_format[<?php echo $doc_key; ?>][middle_format]"
                                                                        data-doc="<?php echo $doc_key; ?>">

                                                                    <option value="year_month"
                                                                        <?php echo nf_selected($fmt['middle_format'] ?? 'year_month', 'year_month'); ?>>
                                                                        Year Month - 202604
                                                                    </option>

                                                                    <option value="financial_year"
                                                                        <?php echo nf_selected($fmt['middle_format'] ?? '', 'financial_year'); ?>>
                                                                        Financial Year - 2026-2027
                                                                    </option>

                                                                    <option value="year"
                                                                        <?php echo nf_selected($fmt['middle_format'] ?? '', 'year'); ?>>
                                                                        Year - 2026
                                                                    </option>

                                                                    <option value="none"
                                                                        <?php echo nf_selected($fmt['middle_format'] ?? '', 'none'); ?>>
                                                                        None
                                                                    </option>

                                                                </select>
                                                            </div>

                                                            <!-- SEPARATOR -->
                                                            <div class="col-md-4 mb-3">
                                                                <label class="form-label">Separator</label>
                                                                <input type="text"
                                                                       class="form-control nf-input"
                                                                       name="number_format[<?php echo $doc_key; ?>][format_separator]"
                                                                       value="<?php echo htmlspecialchars($fmt['format_separator'] ?? '-'); ?>"
                                                                       data-doc="<?php echo $doc_key; ?>">

                                                                <small class="text-muted">Example: - or /</small>
                                                            </div>

                                                            <!-- LAST DIGIT LENGTH -->
                                                            <div class="col-md-4 mb-3">
                                                                <label class="form-label">Last Digit Length</label>
                                                                <input type="number"
                                                                       class="form-control nf-input"
                                                                       name="number_format[<?php echo $doc_key; ?>][number_length]"
                                                                       min="1"
                                                                       max="10"
                                                                       value="<?php echo htmlspecialchars($fmt['number_length'] ?? 4); ?>"
                                                                       data-doc="<?php echo $doc_key; ?>">

                                                                <small class="text-muted">4 = 0009</small>
                                                            </div>

                                                            <!-- RESET PERIOD -->
                                                            <div class="col-md-4 mb-3">
                                                                <label class="form-label">Reset Series</label>
                                                                <select class="form-control nf-input"
                                                                        name="number_format[<?php echo $doc_key; ?>][reset_period]"
                                                                        data-doc="<?php echo $doc_key; ?>">

                                                                    <option value="financial_year"
                                                                        <?php echo nf_selected($fmt['reset_period'] ?? 'financial_year', 'financial_year'); ?>>
                                                                        Financial Year
                                                                    </option>

                                                                    <option value="year"
                                                                        <?php echo nf_selected($fmt['reset_period'] ?? '', 'year'); ?>>
                                                                        Year
                                                                    </option>

                                                                    <option value="month"
                                                                        <?php echo nf_selected($fmt['reset_period'] ?? '', 'month'); ?>>
                                                                        Month
                                                                    </option>

                                                                    <option value="never"
                                                                        <?php echo nf_selected($fmt['reset_period'] ?? '', 'never'); ?>>
                                                                        Never
                                                                    </option>

                                                                </select>
                                                            </div>

                                                            <!-- PREVIEW -->
                                                            <div class="col-12">
                                                                <label class="form-label">Preview</label>

                                                                <div class="format-preview" id="preview_<?php echo $doc_key; ?>">
                                                                    <?php echo htmlspecialchars($preview); ?>
                                                                </div>

                                                                <small class="text-muted">
                                                                    Sample old format:
                                                                    <?php echo htmlspecialchars($doc['sample']); ?>
                                                                </small>
                                                            </div>

                                                        </div>

                                                    </div>
                                                </div>
                                            </div>

                                        <?php endforeach; ?>
                                    </div>

                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- BUTTONS -->
                    <div class="row mb-4">
                        <div class="col-12">
                            <div class="card">
                                <div class="card-body d-flex gap-2 flex-wrap">

                                    <button type="submit" name="save_number_formats" class="btn btn-success btn-lg">
                                        <i class="fas fa-save me-1"></i>
                                        Save Number Format Settings
                                    </button>

                                    <a href="invoice-settings.php?shop_id=<?php echo $selected_shop_id ? (int)$selected_shop_id : 0; ?>"
                                       class="btn btn-secondary btn-lg">
                                        <i class="fas fa-arrow-left me-1"></i>
                                        Back to Invoice Settings
                                    </a>

                                </div>
                            </div>
                        </div>
                    </div>

                </form>

            </div>
        </div>

        <?php include('includes/footer.php'); ?>
    </div>
</div>

<?php include('includes/rightbar.php'); ?>
<?php include('includes/scripts.php'); ?>

<script>
function getMiddleValue(format) {
    const now = new Date();
    const year = now.getFullYear();
    const month = now.getMonth() + 1;
    const mm = String(month).padStart(2, '0');

    if (format === 'year_month') {
        return `${year}${mm}`;
    }

    if (format === 'financial_year') {
        let startYear = month >= 4 ? year : year - 1;
        return `${startYear}-${startYear + 1}`;
    }

    if (format === 'year') {
        return `${year}`;
    }

    return '';
}

function updatePreview(docKey) {
    const prefixInput = document.querySelector(`[name="number_format[${docKey}][prefix]"]`);
    const middleInput = document.querySelector(`[name="number_format[${docKey}][middle_format]"]`);
    const separatorInput = document.querySelector(`[name="number_format[${docKey}][format_separator]"]`);
    const lengthInput = document.querySelector(`[name="number_format[${docKey}][number_length]"]`);
    const preview = document.getElementById(`preview_${docKey}`);

    if (!prefixInput || !middleInput || !separatorInput || !lengthInput || !preview) {
        return;
    }

    const prefix = prefixInput.value || '';
    const middle = getMiddleValue(middleInput.value);
    const separator = separatorInput.value || '';
    let length = parseInt(lengthInput.value || '4', 10);

    if (!length || length <= 0) {
        length = 4;
    }

    const last = String(9).padStart(length, '0');

    preview.textContent = prefix + middle + separator + last;
}

document.querySelectorAll('.nf-input').forEach(function(input) {
    input.addEventListener('input', function() {
        updatePreview(this.dataset.doc);
    });

    input.addEventListener('change', function() {
        updatePreview(this.dataset.doc);
    });
});
</script>

</body>
</html>