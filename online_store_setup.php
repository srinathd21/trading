<?php
date_default_timezone_set('Asia/Kolkata');
session_start();
require_once 'config/database.php';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['admin', 'shop_manager'])) {
    header('Location: login.php');
    exit();
}

$user_id = (int)($_SESSION['user_id'] ?? 0);
$business_id = (int)($_SESSION['business_id'] ?? 1);
$current_business_name = $_SESSION['current_business_name'] ?? 'Business';

$success = '';
$error = '';

function h($value) {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function normalize_slug(string $value): string {
    $value = strtolower(trim($value));
    $value = preg_replace('/[^a-z0-9\s\-]/', '', $value);
    $value = preg_replace('/[\s\-]+/', '-', $value);
    return trim((string)$value, '-');
}

function is_valid_hex_color(string $value): bool {
    return (bool)preg_match('/^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$/', $value);
}

function build_store_url(string $slug): string {
    $host = $_SERVER['HTTP_HOST'] ?? 'ecommer.in';
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    return $slug !== '' ? $scheme . '://' . $host . '/' . $slug : '';
}

function tableExists(PDO $pdo, string $table): bool {
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) 
            FROM information_schema.tables 
            WHERE table_schema = DATABASE() AND table_name = ?
        ");
        $stmt->execute([$table]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (Exception $e) {
        return false;
    }
}

function getTableColumns(PDO $pdo, string $table): array {
    try {
        $stmt = $pdo->prepare("
            SELECT COLUMN_NAME
            FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = ?
        ");
        $stmt->execute([$table]);
        $cols = $stmt->fetchAll(PDO::FETCH_COLUMN);
        return $cols ?: [];
    } catch (Exception $e) {
        return [];
    }
}

function rowExists(PDO $pdo, string $table, int $business_id): bool {
    $stmt = $pdo->prepare("SELECT id FROM `$table` WHERE business_id = ? LIMIT 1");
    $stmt->execute([$business_id]);
    return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
}

$setup = [
    'store_slug' => '',
    'store_status' => 'draft',
    'store_title' => '',
    'store_tagline' => '',
    'logo_url' => '',
    'banner_url' => '',
    'favicon_url' => '',
    'theme_color' => '#0d6efd',
    'secondary_color' => '#212529',
    'homepage_title' => '',
    'homepage_description' => '',
    'hero_title' => '',
    'hero_subtitle' => '',
    'hero_button_text' => '',
    'hero_button_link' => '',
    'featured_categories_title' => 'Shop by Category',
    'featured_products_title' => 'Featured Products',
    'whatsapp_number' => '',
    'support_phone' => '',
    'support_email' => '',
    'address' => '',
    'meta_title' => '',
    'meta_description' => '',
    'footer_text' => '',
    'custom_css' => ''
];

$setupTable = 'online_store_setup';
$settingsTable = 'online_store_settings';

$setupColumns = tableExists($pdo, $setupTable) ? getTableColumns($pdo, $setupTable) : [];
$settingsColumns = tableExists($pdo, $settingsTable) ? getTableColumns($pdo, $settingsTable) : [];

try {
    if (!empty($setupColumns)) {
        $stmt = $pdo->prepare("SELECT * FROM `$setupTable` WHERE business_id = ? LIMIT 1");
        $stmt->execute([$business_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $setup = array_merge($setup, $row);
        }
    }

    if (!empty($settingsColumns)) {
        $stmt = $pdo->prepare("SELECT * FROM `$settingsTable` WHERE business_id = ? LIMIT 1");
        $stmt->execute([$business_id]);
        $settingsRow = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($settingsRow) {
            foreach ($setup as $key => $defaultValue) {
                if (array_key_exists($key, $settingsRow) && trim((string)$settingsRow[$key]) !== '') {
                    $setup[$key] = $settingsRow[$key];
                }
            }

            // extra alias mapping in case column names differ
            $aliasMap = [
                'display_name'      => 'store_title',
                'site_title'        => 'homepage_title',
                'primary_color'     => 'theme_color',
                'contact_phone'     => 'support_phone',
                'contact_email'     => 'support_email',
                'seo_title'         => 'meta_title',
                'seo_description'   => 'meta_description',
            ];

            foreach ($aliasMap as $settingsCol => $setupKey) {
                if (isset($settingsRow[$settingsCol]) && trim((string)$settingsRow[$settingsCol]) !== '') {
                    $setup[$setupKey] = $settingsRow[$settingsCol];
                }
            }
        }
    }
} catch (Exception $e) {
    $error = 'Failed to load online store data: ' . $e->getMessage();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $store_slug = normalize_slug($_POST['store_slug'] ?? '');
        $store_status = trim($_POST['store_status'] ?? 'draft');
        $store_title = trim($_POST['store_title'] ?? '');
        $store_tagline = trim($_POST['store_tagline'] ?? '');
        $logo_url = trim($_POST['logo_url'] ?? '');
        $banner_url = trim($_POST['banner_url'] ?? '');
        $favicon_url = trim($_POST['favicon_url'] ?? '');
        $theme_color = trim($_POST['theme_color'] ?? '#0d6efd');
        $secondary_color = trim($_POST['secondary_color'] ?? '#212529');
        $homepage_title = trim($_POST['homepage_title'] ?? '');
        $homepage_description = trim($_POST['homepage_description'] ?? '');
        $hero_title = trim($_POST['hero_title'] ?? '');
        $hero_subtitle = trim($_POST['hero_subtitle'] ?? '');
        $hero_button_text = trim($_POST['hero_button_text'] ?? '');
        $hero_button_link = trim($_POST['hero_button_link'] ?? '');
        $featured_categories_title = trim($_POST['featured_categories_title'] ?? '');
        $featured_products_title = trim($_POST['featured_products_title'] ?? '');
        $whatsapp_number = trim($_POST['whatsapp_number'] ?? '');
        $support_phone = trim($_POST['support_phone'] ?? '');
        $support_email = trim($_POST['support_email'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $meta_title = trim($_POST['meta_title'] ?? '');
        $meta_description = trim($_POST['meta_description'] ?? '');
        $footer_text = trim($_POST['footer_text'] ?? '');
        $custom_css = trim($_POST['custom_css'] ?? '');

        if ($store_title === '') {
            throw new Exception('Store title is required.');
        }

        if ($store_slug === '') {
            throw new Exception('Store slug is required.');
        }

        if (!preg_match('/^[a-z0-9\-]+$/', $store_slug)) {
            throw new Exception('Store slug must contain only lowercase letters, numbers and hyphens.');
        }

        if (!in_array($store_status, ['draft', 'live', 'maintenance'], true)) {
            throw new Exception('Invalid store status.');
        }

        if (!is_valid_hex_color($theme_color)) {
            throw new Exception('Theme color is invalid.');
        }

        if (!is_valid_hex_color($secondary_color)) {
            throw new Exception('Secondary color is invalid.');
        }

        if ($support_email !== '' && !filter_var($support_email, FILTER_VALIDATE_EMAIL)) {
            throw new Exception('Support email is invalid.');
        }

        if (!empty($setupColumns)) {
            $slugCheck = $pdo->prepare("
                SELECT id, business_id 
                FROM `$setupTable`
                WHERE store_slug = ? AND business_id != ?
                LIMIT 1
            ");
            $slugCheck->execute([$store_slug, $business_id]);
            $slugExists = $slugCheck->fetch(PDO::FETCH_ASSOC);

            if ($slugExists) {
                throw new Exception('This store slug is already used by another business.');
            }
        } elseif (!empty($settingsColumns) && in_array('store_slug', $settingsColumns, true)) {
            $slugCheck = $pdo->prepare("
                SELECT id, business_id 
                FROM `$settingsTable`
                WHERE store_slug = ? AND business_id != ?
                LIMIT 1
            ");
            $slugCheck->execute([$store_slug, $business_id]);
            $slugExists = $slugCheck->fetch(PDO::FETCH_ASSOC);

            if ($slugExists) {
                throw new Exception('This store slug is already used by another business.');
            }
        }

        $formData = [
            'business_id' => $business_id,
            'store_slug' => $store_slug,
            'store_status' => $store_status,
            'store_title' => $store_title,
            'store_tagline' => $store_tagline,
            'logo_url' => $logo_url,
            'banner_url' => $banner_url,
            'favicon_url' => $favicon_url,
            'theme_color' => $theme_color,
            'secondary_color' => $secondary_color,
            'homepage_title' => $homepage_title,
            'homepage_description' => $homepage_description,
            'hero_title' => $hero_title,
            'hero_subtitle' => $hero_subtitle,
            'hero_button_text' => $hero_button_text,
            'hero_button_link' => $hero_button_link,
            'featured_categories_title' => $featured_categories_title,
            'featured_products_title' => $featured_products_title,
            'whatsapp_number' => $whatsapp_number,
            'support_phone' => $support_phone,
            'support_email' => $support_email,
            'address' => $address,
            'meta_title' => $meta_title,
            'meta_description' => $meta_description,
            'footer_text' => $footer_text,
            'custom_css' => $custom_css
        ];

        $pdo->beginTransaction();

        // save to online_store_setup
        if (!empty($setupColumns)) {
            $saveData = [];
            foreach ($formData as $key => $value) {
                if (in_array($key, $setupColumns, true)) {
                    $saveData[$key] = $value;
                }
            }

            if (in_array('updated_at', $setupColumns, true)) {
                $saveData['updated_at'] = date('Y-m-d H:i:s');
            }

            if (rowExists($pdo, $setupTable, $business_id)) {
                $setParts = [];
                $values = [];

                foreach ($saveData as $key => $value) {
                    if ($key === 'business_id') {
                        continue;
                    }
                    $setParts[] = "`$key` = ?";
                    $values[] = $value;
                }

                $values[] = $business_id;

                $sql = "UPDATE `$setupTable` SET " . implode(', ', $setParts) . " WHERE business_id = ?";
                $stmt = $pdo->prepare($sql);
                $stmt->execute($values);
            } else {
                if (in_array('created_at', $setupColumns, true)) {
                    $saveData['created_at'] = date('Y-m-d H:i:s');
                }

                $cols = array_keys($saveData);
                $placeholders = array_fill(0, count($cols), '?');
                $values = array_values($saveData);

                $sql = "INSERT INTO `$setupTable` (`" . implode('`,`', $cols) . "`) VALUES (" . implode(',', $placeholders) . ")";
                $stmt = $pdo->prepare($sql);
                $stmt->execute($values);
            }
        }

        // save overlapping fields to online_store_settings
        if (!empty($settingsColumns)) {
            $settingsData = [];

            foreach ($formData as $key => $value) {
                if (in_array($key, $settingsColumns, true)) {
                    $settingsData[$key] = $value;
                }
            }

            // alias mapping for settings table if names differ
            $settingsAliasData = [
                'display_name'    => $store_title,
                'site_title'      => $homepage_title,
                'primary_color'   => $theme_color,
                'contact_phone'   => $support_phone,
                'contact_email'   => $support_email,
                'seo_title'       => $meta_title,
                'seo_description' => $meta_description,
            ];

            foreach ($settingsAliasData as $col => $value) {
                if (in_array($col, $settingsColumns, true)) {
                    $settingsData[$col] = $value;
                }
            }

            if (in_array('business_id', $settingsColumns, true)) {
                $settingsData['business_id'] = $business_id;
            }

            if (in_array('updated_at', $settingsColumns, true)) {
                $settingsData['updated_at'] = date('Y-m-d H:i:s');
            }

            if (rowExists($pdo, $settingsTable, $business_id)) {
                $setParts = [];
                $values = [];

                foreach ($settingsData as $key => $value) {
                    if ($key === 'business_id') {
                        continue;
                    }
                    $setParts[] = "`$key` = ?";
                    $values[] = $value;
                }

                if (!empty($setParts)) {
                    $values[] = $business_id;
                    $sql = "UPDATE `$settingsTable` SET " . implode(', ', $setParts) . " WHERE business_id = ?";
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute($values);
                }
            } else {
                if (in_array('created_at', $settingsColumns, true)) {
                    $settingsData['created_at'] = date('Y-m-d H:i:s');
                }

                if (!empty($settingsData)) {
                    $cols = array_keys($settingsData);
                    $placeholders = array_fill(0, count($cols), '?');
                    $values = array_values($settingsData);

                    $sql = "INSERT INTO `$settingsTable` (`" . implode('`,`', $cols) . "`) VALUES (" . implode(',', $placeholders) . ")";
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute($values);
                }
            }
        }

        $pdo->commit();

        $_SESSION['flash_success'] = 'Online store setup saved successfully.';
        header('Location: online_store_setup.php');
        exit();

    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        $error = $e->getMessage();

        $setup = [
            'store_slug' => $_POST['store_slug'] ?? '',
            'store_status' => $_POST['store_status'] ?? 'draft',
            'store_title' => $_POST['store_title'] ?? '',
            'store_tagline' => $_POST['store_tagline'] ?? '',
            'logo_url' => $_POST['logo_url'] ?? '',
            'banner_url' => $_POST['banner_url'] ?? '',
            'favicon_url' => $_POST['favicon_url'] ?? '',
            'theme_color' => $_POST['theme_color'] ?? '#0d6efd',
            'secondary_color' => $_POST['secondary_color'] ?? '#212529',
            'homepage_title' => $_POST['homepage_title'] ?? '',
            'homepage_description' => $_POST['homepage_description'] ?? '',
            'hero_title' => $_POST['hero_title'] ?? '',
            'hero_subtitle' => $_POST['hero_subtitle'] ?? '',
            'hero_button_text' => $_POST['hero_button_text'] ?? '',
            'hero_button_link' => $_POST['hero_button_link'] ?? '',
            'featured_categories_title' => $_POST['featured_categories_title'] ?? '',
            'featured_products_title' => $_POST['featured_products_title'] ?? '',
            'whatsapp_number' => $_POST['whatsapp_number'] ?? '',
            'support_phone' => $_POST['support_phone'] ?? '',
            'support_email' => $_POST['support_email'] ?? '',
            'address' => $_POST['address'] ?? '',
            'meta_title' => $_POST['meta_title'] ?? '',
            'meta_description' => $_POST['meta_description'] ?? '',
            'footer_text' => $_POST['footer_text'] ?? '',
            'custom_css' => $_POST['custom_css'] ?? ''
        ];
    }
}

if (!empty($_SESSION['flash_success'])) {
    $success = $_SESSION['flash_success'];
    unset($_SESSION['flash_success']);
}

$previewUrl = (string)$setup['store_slug'];
?>
<!doctype html>
<html lang="en">
<?php $page_title = "Online Store Setup"; include 'includes/head.php'; ?>
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
                        <div class="page-title-box d-flex align-items-center justify-content-between">
                            <h4 class="mb-0">
                                <i class="bx bx-world me-2"></i> Online Store Setup
                                <small class="text-muted ms-2"><?= h($current_business_name) ?></small>
                            </h4>
                            <?php if ($previewUrl !== ''): ?>
                                <a href="<?= h($previewUrl) ?>" target="_blank" class="btn btn-outline-primary">
                                    <i class="bx bx-link-external me-1"></i> Open Store
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <?php if ($success): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="bx bx-check-circle me-2"></i><?= h($success) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>

                <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="bx bx-error-circle me-2"></i><?= h($error) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>

                <form method="POST">
                    <div class="row">
                        <div class="col-xl-8">
                            <div class="card shadow-sm mb-4">
                                <div class="card-header bg-white">
                                    <h5 class="mb-0"><i class="bx bx-cog me-2"></i>Store Identity</h5>
                                </div>
                                <div class="card-body">
                                    <div class="row g-3">
                                        <div class="col-md-6">
                                            <label class="form-label">Store Title <span class="text-danger">*</span></label>
                                            <input type="text" name="store_title" class="form-control" required value="<?= h($setup['store_title']) ?>">
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label">Store Slug <span class="text-danger">*</span></label>
                                            <input type="text" name="store_slug" id="store_slug" class="form-control" required value="<?= h($setup['store_slug']) ?>" placeholder="kesavan-traders">
                                            <small class="text-muted">Store URL: <span id="slugPreview"><?= h($previewUrl !== '' ? 'ecommer.in/billing/trading/'.$previewUrl : 'ecommer.in/your-store-slug') ?></span></small>
                                        </div>

                                        <div class="col-md-6">
                                            <label class="form-label">Store Status</label>
                                            <select name="store_status" class="form-select">
                                                <option value="draft" <?= $setup['store_status'] === 'draft' ? 'selected' : '' ?>>Draft</option>
                                                <option value="live" <?= $setup['store_status'] === 'live' ? 'selected' : '' ?>>Live</option>
                                                <option value="maintenance" <?= $setup['store_status'] === 'maintenance' ? 'selected' : '' ?>>Maintenance</option>
                                            </select>
                                        </div>

                                        <div class="col-md-6">
                                            <label class="form-label">Tagline</label>
                                            <input type="text" name="store_tagline" class="form-control" value="<?= h($setup['store_tagline']) ?>">
                                        </div>

                                        <div class="col-md-4">
                                            <label class="form-label">Theme Color</label>
                                            <input type="color" name="theme_color" class="form-control form-control-color" value="<?= h($setup['theme_color']) ?>">
                                        </div>

                                        <div class="col-md-4">
                                            <label class="form-label">Secondary Color</label>
                                            <input type="color" name="secondary_color" class="form-control form-control-color" value="<?= h($setup['secondary_color']) ?>">
                                        </div>

                                        <div class="col-md-4">
                                            <label class="form-label">Favicon URL</label>
                                            <input type="text" name="favicon_url" class="form-control" value="<?= h($setup['favicon_url']) ?>">
                                        </div>

                                        <div class="col-md-6">
                                            <label class="form-label">Logo URL</label>
                                            <input type="text" name="logo_url" class="form-control" value="<?= h($setup['logo_url']) ?>">
                                        </div>

                                        <div class="col-md-6">
                                            <label class="form-label">Banner URL</label>
                                            <input type="text" name="banner_url" class="form-control" value="<?= h($setup['banner_url']) ?>">
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="card shadow-sm mb-4">
                                <div class="card-header bg-white">
                                    <h5 class="mb-0"><i class="bx bx-layout me-2"></i>Homepage Setup</h5>
                                </div>
                                <div class="card-body">
                                    <div class="row g-3">
                                        <div class="col-12">
                                            <label class="form-label">Homepage Title</label>
                                            <input type="text" name="homepage_title" class="form-control" value="<?= h($setup['homepage_title']) ?>">
                                        </div>

                                        <div class="col-12">
                                            <label class="form-label">Homepage Description</label>
                                            <textarea name="homepage_description" class="form-control" rows="3"><?= h($setup['homepage_description']) ?></textarea>
                                        </div>

                                        <div class="col-md-6">
                                            <label class="form-label">Hero Title</label>
                                            <input type="text" name="hero_title" class="form-control" value="<?= h($setup['hero_title']) ?>">
                                        </div>

                                        <div class="col-md-6">
                                            <label class="form-label">Hero Button Text</label>
                                            <input type="text" name="hero_button_text" class="form-control" value="<?= h($setup['hero_button_text']) ?>">
                                        </div>

                                        <div class="col-12">
                                            <label class="form-label">Hero Subtitle</label>
                                            <textarea name="hero_subtitle" class="form-control" rows="3"><?= h($setup['hero_subtitle']) ?></textarea>
                                        </div>

                                        <div class="col-12">
                                            <label class="form-label">Hero Button Link</label>
                                            <input type="text" name="hero_button_link" class="form-control" value="<?= h($setup['hero_button_link']) ?>" placeholder="/products or /categories">
                                        </div>

                                        <div class="col-md-6">
                                            <label class="form-label">Featured Categories Title</label>
                                            <input type="text" name="featured_categories_title" class="form-control" value="<?= h($setup['featured_categories_title']) ?>">
                                        </div>

                                        <div class="col-md-6">
                                            <label class="form-label">Featured Products Title</label>
                                            <input type="text" name="featured_products_title" class="form-control" value="<?= h($setup['featured_products_title']) ?>">
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="card shadow-sm mb-4">
                                <div class="card-header bg-white">
                                    <h5 class="mb-0"><i class="bx bx-headphone me-2"></i>Contact & SEO</h5>
                                </div>
                                <div class="card-body">
                                    <div class="row g-3">
                                        <div class="col-md-4">
                                            <label class="form-label">WhatsApp Number</label>
                                            <input type="text" name="whatsapp_number" class="form-control" value="<?= h($setup['whatsapp_number']) ?>">
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label">Support Phone</label>
                                            <input type="text" name="support_phone" class="form-control" value="<?= h($setup['support_phone']) ?>">
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label">Support Email</label>
                                            <input type="email" name="support_email" class="form-control" value="<?= h($setup['support_email']) ?>">
                                        </div>

                                        <div class="col-12">
                                            <label class="form-label">Address</label>
                                            <textarea name="address" class="form-control" rows="3"><?= h($setup['address']) ?></textarea>
                                        </div>

                                        <div class="col-12">
                                            <label class="form-label">Meta Title</label>
                                            <input type="text" name="meta_title" class="form-control" value="<?= h($setup['meta_title']) ?>">
                                        </div>

                                        <div class="col-12">
                                            <label class="form-label">Meta Description</label>
                                            <textarea name="meta_description" class="form-control" rows="3"><?= h($setup['meta_description']) ?></textarea>
                                        </div>

                                        <div class="col-12">
                                            <label class="form-label">Footer Text</label>
                                            <input type="text" name="footer_text" class="form-control" value="<?= h($setup['footer_text']) ?>">
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="card shadow-sm mb-4">
                                <div class="card-header bg-white">
                                    <h5 class="mb-0"><i class="bx bx-code-alt me-2"></i>Custom CSS</h5>
                                </div>
                                <div class="card-body">
                                    <textarea name="custom_css" class="form-control font-monospace" rows="10" placeholder=".hero-title{font-size:42px;}"><?= h($setup['custom_css']) ?></textarea>
                                </div>
                            </div>
                        </div>

                        <div class="col-xl-4">
                            <div class="card shadow-sm mb-4">
                                <div class="card-header bg-white">
                                    <h5 class="mb-0"><i class="bx bx-link me-2"></i>Store URL</h5>
                                </div>
                                <div class="card-body">
                                    <p class="mb-2"><strong>Public URL</strong></p>
                                    <div class="border rounded p-3 bg-light break-word">
                                        <?= h($previewUrl !== '' ? 'ecommer.in/billing/trading/'.$previewUrl : 'ecommer.in/billing/trading/your-store-slug') ?>
                                    </div>
                                    <small class="text-muted d-block mt-2">
                                        Each business must have a unique slug. Example: <strong>ecommer.in/billing/trading/kesavan-traders</strong>
                                    </small>
                                </div>
                            </div>

                            <div class="card shadow-sm mb-4">
                                <div class="card-header bg-white">
                                    <h5 class="mb-0"><i class="bx bx-show me-2"></i>Quick Preview</h5>
                                </div>
                                <div class="card-body">
                                    <div class="border rounded p-3">
                                        <div class="d-flex align-items-center mb-3">
                                            <div class="rounded-circle me-3 d-flex align-items-center justify-content-center"
                                                 style="width:52px;height:52px;background:<?= h($setup['theme_color']) ?>22;color:<?= h($setup['theme_color']) ?>;">
                                                <i class="bx bx-store fs-3"></i>
                                            </div>
                                            <div>
                                                <h5 class="mb-0"><?= h($setup['store_title'] ?: 'Store Title') ?></h5>
                                                <small class="text-muted"><?= h($setup['store_tagline'] ?: 'Store tagline') ?></small>
                                            </div>
                                        </div>

                                        <p class="mb-1"><strong>Status:</strong>
                                            <?php if ($setup['store_status'] === 'live'): ?>
                                                <span class="badge bg-success">Live</span>
                                            <?php elseif ($setup['store_status'] === 'maintenance'): ?>
                                                <span class="badge bg-warning text-dark">Maintenance</span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary">Draft</span>
                                            <?php endif; ?>
                                        </p>
                                        <p class="mb-1"><strong>Slug:</strong> <?= h($setup['store_slug'] ?: '-') ?></p>
                                        <p class="mb-1"><strong>Phone:</strong> <?= h($setup['support_phone'] ?: '-') ?></p>
                                        <p class="mb-0"><strong>Email:</strong> <?= h($setup['support_email'] ?: '-') ?></p>
                                    </div>
                                </div>
                            </div>

                            <div class="card shadow-sm mb-4">
                                <div class="card-header bg-white">
                                    <h5 class="mb-0"><i class="bx bx-error-circle me-2"></i>Important</h5>
                                </div>
                                <div class="card-body">
                                    <ul class="mb-0 ps-3">
                                        <li>Slug must be globally unique.</li>
                                        <li>Changing slug changes public URL.</li>
                                        <li>Public storefront routing must resolve by slug, not session.</li>
                                        <li>Do not set store to live until storefront route is working.</li>
                                    </ul>
                                </div>
                            </div>

                            <div class="d-grid">
                                <button type="submit" class="btn btn-primary btn-lg">
                                    <i class="bx bx-save me-2"></i> Save Store Setup
                                </button>
                            </div>
                        </div>
                    </div>
                </form>

            </div>
        </div>
        <?php include 'includes/footer.php'; ?>
    </div>
</div>

<?php include 'includes/scripts.php'; ?>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const slugInput = document.getElementById('store_slug');
    const slugPreview = document.getElementById('slugPreview');

    function normalizeSlugJs(value) {
        return value
            .toLowerCase()
            .trim()
            .replace(/[^a-z0-9\s-]/g, '')
            .replace(/[\s-]+/g, '-')
            .replace(/^-+|-+$/g, '');
    }

    function updatePreview() {
        const slug = normalizeSlugJs(slugInput.value || '');
        const origin = window.location.origin || ('https://' + window.location.host);
        slugPreview.textContent = slug ? (origin + '/' + slug) : 'ecommer.in/your-store-slug';
    }

    if (slugInput) {
        slugInput.addEventListener('input', function () {
            this.value = normalizeSlugJs(this.value);
            updatePreview();
        });
        updatePreview();
    }

    setTimeout(function () {
        document.querySelectorAll('.alert').forEach(function (el) {
            try {
                const inst = bootstrap.Alert.getOrCreateInstance(el);
                inst.close();
            } catch (e) {}
        });
    }, 5000);
});
</script>

<style>
.card {
    border: 0;
}
.card-header {
    border-bottom: 1px solid #f1f3f5;
}
.form-label {
    font-weight: 600;
}
.form-control,
.form-select,
.btn {
    border-radius: 10px;
}
.break-word {
    word-break: break-word;
}
</style>
</body>
</html>