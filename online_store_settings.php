<?php
date_default_timezone_set('Asia/Kolkata');
session_start();
require_once 'config/database.php';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['admin', 'shop_manager'])) {
    header('Location: login.php');
    exit();
}

$user_id = (int)$_SESSION['user_id'];
$business_id = (int)($_SESSION['business_id'] ?? 1);
$success = '';
$error = '';

function h($value) {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function old($array, $key, $default = '') {
    return isset($array[$key]) ? $array[$key] : $default;
}

// Load settings
try {
    $stmt = $pdo->prepare("SELECT * FROM online_store_settings WHERE business_id = ? LIMIT 1");
    $stmt->execute([$business_id]);
    $settings = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$settings) {
        $settings = [
            'id' => null,
            'display_name' => '',
            'store_slug' => '',
            'tagline' => '',
            'support_phone' => '',
            'whatsapp_number' => '',
            'support_email' => '',
            'address' => '',
            'logo_url' => '',
            'banner_url' => '',
            'theme_color' => '#0d6efd',
            'currency_symbol' => '₹',
            'enable_cod' => 1,
            'enable_online_payment' => 1,
            'delivery_charge' => '0.00',
            'free_delivery_above' => '0.00',
            'meta_title' => '',
            'meta_description' => '',
            'facebook_url' => '',
            'instagram_url' => '',
            'youtube_url' => '',
            'about_us' => '',
            'footer_text' => '',
            'maintenance_mode' => 0
        ];
    }
    
    // Load Telegram IDs for this store
    $telegram_ids = [];
    if (!empty($settings['id'])) {
        $stmt = $pdo->prepare("SELECT id, telegram_id FROM business_telegram_ids WHERE store_id = ? ORDER BY id ASC");
        $stmt->execute([$settings['id']]);
        $telegram_ids = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
} catch (Exception $e) {
    $settings = [];
    $telegram_ids = [];
    $error = 'Failed to load online store settings: ' . $e->getMessage();
}

// Save settings
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $display_name = trim($_POST['display_name'] ?? '');
        $store_slug = trim($_POST['store_slug'] ?? '');
        $tagline = trim($_POST['tagline'] ?? '');
        $support_phone = trim($_POST['support_phone'] ?? '');
        $whatsapp_number = trim($_POST['whatsapp_number'] ?? '');
        $support_email = trim($_POST['support_email'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $logo_url = trim($_POST['logo_url'] ?? '');
        $banner_url = trim($_POST['banner_url'] ?? '');
        $theme_color = trim($_POST['theme_color'] ?? '#0d6efd');
        $currency_symbol = trim($_POST['currency_symbol'] ?? '₹');
        $enable_cod = isset($_POST['enable_cod']) ? 1 : 0;
        $enable_online_payment = isset($_POST['enable_online_payment']) ? 1 : 0;
        $delivery_charge = is_numeric($_POST['delivery_charge'] ?? null) ? $_POST['delivery_charge'] : '0.00';
        $free_delivery_above = is_numeric($_POST['free_delivery_above'] ?? null) ? $_POST['free_delivery_above'] : '0.00';
        $meta_title = trim($_POST['meta_title'] ?? '');
        $meta_description = trim($_POST['meta_description'] ?? '');
        $facebook_url = trim($_POST['facebook_url'] ?? '');
        $instagram_url = trim($_POST['instagram_url'] ?? '');
        $youtube_url = trim($_POST['youtube_url'] ?? '');
        $about_us = trim($_POST['about_us'] ?? '');
        $footer_text = trim($_POST['footer_text'] ?? '');
        $maintenance_mode = isset($_POST['maintenance_mode']) ? 1 : 0;
        
        // Telegram IDs from form
        $submitted_telegram_ids = $_POST['telegram_id'] ?? [];
        $telegram_ids_to_delete = $_POST['delete_telegram_id'] ?? [];

        if ($display_name === '') {
            throw new Exception('Display Name is required.');
        }

        // Only validate slug if it's being set for the first time OR if it's empty (allow empty)
        // But if slug already exists in DB, we should not allow changes
        $existing_slug = $settings['store_slug'] ?? '';
        if ($existing_slug !== '' && $store_slug !== $existing_slug) {
            throw new Exception('Store slug cannot be changed once it has been set.');
        }
        
        if ($store_slug !== '' && !preg_match('/^[a-z0-9\-]+$/', $store_slug)) {
            throw new Exception('Store slug must contain only lowercase letters, numbers, and hyphens.');
        }

        if ($support_email !== '' && !filter_var($support_email, FILTER_VALIDATE_EMAIL)) {
            throw new Exception('Invalid support email address.');
        }

        if ($theme_color !== '' && !preg_match('/^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$/', $theme_color)) {
            throw new Exception('Theme color must be a valid hex color like #0d6efd.');
        }
        
        // Validate Telegram IDs
        foreach ($submitted_telegram_ids as $tid) {
            $tid = trim($tid);
            if ($tid !== '' && !preg_match('/^\d+$/', $tid)) {
                throw new Exception('Telegram ID must contain only numbers.');
            }
        }

        $check = $pdo->prepare("SELECT id, store_slug FROM online_store_settings WHERE business_id = ? LIMIT 1");
        $check->execute([$business_id]);
        $existing = $check->fetch(PDO::FETCH_ASSOC);
        
        $store_settings_id = null;

        if ($existing) {
            $store_settings_id = $existing['id'];
            $stmt = $pdo->prepare("
                UPDATE online_store_settings SET
                    display_name = ?,
                    tagline = ?,
                    support_phone = ?,
                    whatsapp_number = ?,
                    support_email = ?,
                    address = ?,
                    logo_url = ?,
                    banner_url = ?,
                    theme_color = ?,
                    currency_symbol = ?,
                    enable_cod = ?,
                    enable_online_payment = ?,
                    delivery_charge = ?,
                    free_delivery_above = ?,
                    meta_title = ?,
                    meta_description = ?,
                    facebook_url = ?,
                    instagram_url = ?,
                    youtube_url = ?,
                    about_us = ?,
                    footer_text = ?,
                    maintenance_mode = ?,
                    updated_at = NOW()
                WHERE business_id = ?
            ");

            $stmt->execute([
                $display_name,
                $tagline,
                $support_phone,
                $whatsapp_number,
                $support_email,
                $address,
                $logo_url,
                $banner_url,
                $theme_color,
                $currency_symbol,
                $enable_cod,
                $enable_online_payment,
                $delivery_charge,
                $free_delivery_above,
                $meta_title,
                $meta_description,
                $facebook_url,
                $instagram_url,
                $youtube_url,
                $about_us,
                $footer_text,
                $maintenance_mode,
                $business_id
            ]);
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO online_store_settings (
                    business_id,
                    display_name,
                    store_slug,
                    tagline,
                    support_phone,
                    whatsapp_number,
                    support_email,
                    address,
                    logo_url,
                    banner_url,
                    theme_color,
                    currency_symbol,
                    enable_cod,
                    enable_online_payment,
                    delivery_charge,
                    free_delivery_above,
                    meta_title,
                    meta_description,
                    facebook_url,
                    instagram_url,
                    youtube_url,
                    about_us,
                    footer_text,
                    maintenance_mode,
                    created_at,
                    updated_at
                ) VALUES (
                    ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW()
                )
            ");

            $stmt->execute([
                $business_id,
                $display_name,
                $store_slug,
                $tagline,
                $support_phone,
                $whatsapp_number,
                $support_email,
                $address,
                $logo_url,
                $banner_url,
                $theme_color,
                $currency_symbol,
                $enable_cod,
                $enable_online_payment,
                $delivery_charge,
                $free_delivery_above,
                $meta_title,
                $meta_description,
                $facebook_url,
                $instagram_url,
                $youtube_url,
                $about_us,
                $footer_text,
                $maintenance_mode
            ]);
            
            // Get the newly inserted ID
            $store_settings_id = $pdo->lastInsertId();
        }
        
        // Handle Telegram IDs if we have a store settings ID
        if ($store_settings_id) {
            // Delete marked telegram IDs
            if (!empty($telegram_ids_to_delete)) {
                $placeholders = implode(',', array_fill(0, count($telegram_ids_to_delete), '?'));
                $deleteStmt = $pdo->prepare("DELETE FROM business_telegram_ids WHERE id IN ($placeholders) AND store_id = ?");
                $params = array_merge($telegram_ids_to_delete, [$store_settings_id]);
                $deleteStmt->execute($params);
            }
            
            // Insert or update telegram IDs
            foreach ($submitted_telegram_ids as $index => $tid) {
                $tid = trim($tid);
                if ($tid === '') continue;
                
                // Check if this entry has an ID (existing record)
                $record_id = $_POST['telegram_record_id'][$index] ?? null;
                
                if ($record_id && is_numeric($record_id)) {
                    // Update existing
                    $updateStmt = $pdo->prepare("UPDATE business_telegram_ids SET telegram_id = ? WHERE id = ? AND store_id = ?");
                    $updateStmt->execute([$tid, $record_id, $store_settings_id]);
                } else {
                    // Insert new
                    $insertStmt = $pdo->prepare("INSERT INTO business_telegram_ids (store_id, telegram_id) VALUES (?, ?)");
                    $insertStmt->execute([$store_settings_id, $tid]);
                }
            }
        }

        $_SESSION['flash_success'] = 'Online store settings saved successfully.';
        header('Location: online_store_settings.php');
        exit();

    } catch (Exception $e) {
        $error = $e->getMessage();

        // keep form values on error
        $settings = [
            'display_name' => $_POST['display_name'] ?? '',
            'store_slug' => $_POST['store_slug'] ?? '',
            'tagline' => $_POST['tagline'] ?? '',
            'support_phone' => $_POST['support_phone'] ?? '',
            'whatsapp_number' => $_POST['whatsapp_number'] ?? '',
            'support_email' => $_POST['support_email'] ?? '',
            'address' => $_POST['address'] ?? '',
            'logo_url' => $_POST['logo_url'] ?? '',
            'banner_url' => $_POST['banner_url'] ?? '',
            'theme_color' => $_POST['theme_color'] ?? '#0d6efd',
            'currency_symbol' => $_POST['currency_symbol'] ?? '₹',
            'enable_cod' => isset($_POST['enable_cod']) ? 1 : 0,
            'enable_online_payment' => isset($_POST['enable_online_payment']) ? 1 : 0,
            'delivery_charge' => $_POST['delivery_charge'] ?? '0.00',
            'free_delivery_above' => $_POST['free_delivery_above'] ?? '0.00',
            'meta_title' => $_POST['meta_title'] ?? '',
            'meta_description' => $_POST['meta_description'] ?? '',
            'facebook_url' => $_POST['facebook_url'] ?? '',
            'instagram_url' => $_POST['instagram_url'] ?? '',
            'youtube_url' => $_POST['youtube_url'] ?? '',
            'about_us' => $_POST['about_us'] ?? '',
            'footer_text' => $_POST['footer_text'] ?? '',
            'maintenance_mode' => isset($_POST['maintenance_mode']) ? 1 : 0
        ];
        
        // Re-populate telegram IDs from POST
        $telegram_ids = [];
        if (!empty($_POST['telegram_record_id'])) {
            foreach ($_POST['telegram_record_id'] as $idx => $rec_id) {
                $telegram_ids[] = [
                    'id' => $rec_id,
                    'telegram_id' => $_POST['telegram_id'][$idx] ?? ''
                ];
            }
        }
    }
}

if (!empty($_SESSION['flash_success'])) {
    $success = $_SESSION['flash_success'];
    unset($_SESSION['flash_success']);
}

// Determine if slug is locked (already has a value in DB)
$is_slug_locked = !empty($settings['store_slug']);
?>

<!doctype html>
<html lang="en">
<?php $page_title = "Online Store Settings"; include 'includes/head.php'; ?>
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

                <form method="POST" id="settingsForm">
                    <div class="row">
                        <div class="col-xl-8">
                            <div class="card shadow-sm mb-4">
                                <div class="card-header bg-white">
                                    <h5 class="mb-0">
                                        <i class="bx bx-cog me-2"></i> Basic Store Details
                                    </h5>
                                </div>
                                <div class="card-body">
                                    <div class="row g-3">
                                        <div class="col-md-6">
                                            <label class="form-label">Display Name <span class="text-danger">*</span></label>
                                            <input type="text" name="display_name" class="form-control" required
                                                   value="<?= h(old($settings, 'display_name')) ?>">
                                        </div>

                                        <div class="col-md-6">
                                            <label class="form-label">Store Slug</label>
                                            <input type="text" name="store_slug" class="form-control"
                                                   placeholder="my-online-store"
                                                   value="<?= h(old($settings, 'store_slug')) ?>"
                                                   <?= $is_slug_locked ? 'readonly disabled style="background-color:#e9ecef;"' : '' ?>>
                                            <?php if ($is_slug_locked): ?>
                                                <input type="hidden" name="store_slug" value="<?= h(old($settings, 'store_slug')) ?>">
                                                <small class="text-muted">Store slug cannot be changed after it has been set.</small>
                                            <?php else: ?>
                                                <small class="text-muted">Only lowercase letters, numbers, hyphens. This cannot be changed later.</small>
                                            <?php endif; ?>
                                        </div>

                                        <div class="col-12">
                                            <label class="form-label">Tagline</label>
                                            <input type="text" name="tagline" class="form-control"
                                                   placeholder="Best electronics at the best price"
                                                   value="<?= h(old($settings, 'tagline')) ?>">
                                        </div>

                                        <div class="col-md-6">
                                            <label class="form-label">Support Phone</label>
                                            <input type="text" name="support_phone" class="form-control"
                                                   value="<?= h(old($settings, 'support_phone')) ?>">
                                        </div>

                                        <div class="col-md-6">
                                            <label class="form-label">WhatsApp Number</label>
                                            <input type="text" name="whatsapp_number" class="form-control"
                                                   value="<?= h(old($settings, 'whatsapp_number')) ?>">
                                        </div>

                                        <div class="col-md-6">
                                            <label class="form-label">Support Email</label>
                                            <input type="email" name="support_email" class="form-control"
                                                   value="<?= h(old($settings, 'support_email')) ?>">
                                        </div>

                                        <div class="col-md-6">
                                            <label class="form-label">Currency Symbol</label>
                                            <input type="text" name="currency_symbol" class="form-control"
                                                   maxlength="10"
                                                   value="<?= h(old($settings, 'currency_symbol', '₹')) ?>">
                                        </div>

                                        <div class="col-12">
                                            <label class="form-label">Store Address</label>
                                            <textarea name="address" class="form-control" rows="3"><?= h(old($settings, 'address')) ?></textarea>
                                        </div>

                                        <div class="col-md-6">
                                            <label class="form-label">Logo URL</label>
                                            <input type="text" name="logo_url" class="form-control"
                                                   placeholder="https://example.com/logo.png"
                                                   value="<?= h(old($settings, 'logo_url')) ?>">
                                        </div>

                                        <div class="col-md-6">
                                            <label class="form-label">Banner URL</label>
                                            <input type="text" name="banner_url" class="form-control"
                                                   placeholder="https://example.com/banner.jpg"
                                                   value="<?= h(old($settings, 'banner_url')) ?>">
                                        </div>

                                        <div class="col-md-4">
                                            <label class="form-label">Theme Color</label>
                                            <input type="color" name="theme_color" class="form-control form-control-color"
                                                   value="<?= h(old($settings, 'theme_color', '#0d6efd')) ?>">
                                        </div>

                                        <div class="col-md-4">
                                            <label class="form-label">Delivery Charge</label>
                                            <input type="number" step="0.01" min="0" name="delivery_charge" class="form-control"
                                                   value="<?= h(old($settings, 'delivery_charge', '0.00')) ?>">
                                        </div>

                                        <div class="col-md-4">
                                            <label class="form-label">Free Delivery Above</label>
                                            <input type="number" step="0.01" min="0" name="free_delivery_above" class="form-control"
                                                   value="<?= h(old($settings, 'free_delivery_above', '0.00')) ?>">
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Telegram IDs Section -->
                            <div class="card shadow-sm mb-4">
                                <div class="card-header bg-white d-flex justify-content-between align-items-center">
                                    <h5 class="mb-0">
                                        <i class="bx bxl-telegram me-2"></i> Telegram IDs
                                    </h5>
                                    <button type="button" class="btn btn-sm btn-primary" id="addTelegramBtn">
                                        <i class="bx bx-plus me-1"></i> Add More
                                    </button>
                                </div>
                                <div class="card-body">
                                    <div id="telegramIdsContainer">
                                        <?php if (empty($telegram_ids)): ?>
                                            <div class="telegram-row mb-2">
                                                <div class="input-group">
                                                    <span class="input-group-text"><i class="bx bxl-telegram"></i></span>
                                                    <input type="text" name="telegram_id[]" class="form-control" placeholder="Enter Telegram ID (e.g., 123456789)">
                                                    <input type="hidden" name="telegram_record_id[]" value="">
                                                    <button type="button" class="btn btn-outline-danger remove-telegram-btn" style="display:none;">
                                                        <i class="bx bx-trash"></i>
                                                    </button>
                                                </div>
                                            </div>
                                        <?php else: ?>
                                            <?php foreach ($telegram_ids as $index => $tele): ?>
                                                <div class="telegram-row mb-2" data-id="<?= h($tele['id']) ?>">
                                                    <div class="input-group">
                                                        <span class="input-group-text"><i class="bx bxl-telegram"></i></span>
                                                        <input type="text" name="telegram_id[]" class="form-control" placeholder="Enter Telegram ID (e.g., 123456789)" value="<?= h($tele['telegram_id']) ?>">
                                                        <input type="hidden" name="telegram_record_id[]" value="<?= h($tele['id']) ?>">
                                                        <button type="button" class="btn btn-outline-danger remove-telegram-btn" data-id="<?= h($tele['id']) ?>">
                                                            <i class="bx bx-trash"></i>
                                                        </button>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </div>
                                    <div id="telegramIdsToDelete"></div>
                                    <small class="text-muted">Add Telegram IDs to receive order notifications. Only numbers allowed.</small>
                                </div>
                            </div>

                            <div class="card shadow-sm mb-4">
                                <div class="card-header bg-white">
                                    <h5 class="mb-0">
                                        <i class="bx bx-search-alt me-2"></i> SEO Settings
                                    </h5>
                                </div>
                                <div class="card-body">
                                    <div class="row g-3">
                                        <div class="col-12">
                                            <label class="form-label">Meta Title</label>
                                            <input type="text" name="meta_title" class="form-control"
                                                   value="<?= h(old($settings, 'meta_title')) ?>">
                                        </div>

                                        <div class="col-12">
                                            <label class="form-label">Meta Description</label>
                                            <textarea name="meta_description" class="form-control" rows="4"><?= h(old($settings, 'meta_description')) ?></textarea>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="card shadow-sm mb-4">
                                <div class="card-header bg-white">
                                    <h5 class="mb-0">
                                        <i class="bx bx-link-alt me-2"></i> Social Links
                                    </h5>
                                </div>
                                <div class="card-body">
                                    <div class="row g-3">
                                        <div class="col-md-4">
                                            <label class="form-label">Facebook URL</label>
                                            <input type="text" name="facebook_url" class="form-control"
                                                   value="<?= h(old($settings, 'facebook_url')) ?>">
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label">Instagram URL</label>
                                            <input type="text" name="instagram_url" class="form-control"
                                                   value="<?= h(old($settings, 'instagram_url')) ?>">
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label">YouTube URL</label>
                                            <input type="text" name="youtube_url" class="form-control"
                                                   value="<?= h(old($settings, 'youtube_url')) ?>">
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="card shadow-sm mb-4">
                                <div class="card-header bg-white">
                                    <h5 class="mb-0">
                                        <i class="bx bx-detail me-2"></i> Content Settings
                                    </h5>
                                </div>
                                <div class="card-body">
                                    <div class="row g-3">
                                        <div class="col-12">
                                            <label class="form-label">About Us</label>
                                            <textarea name="about_us" class="form-control" rows="5"><?= h(old($settings, 'about_us')) ?></textarea>
                                        </div>

                                        <div class="col-12">
                                            <label class="form-label">Footer Text</label>
                                            <input type="text" name="footer_text" class="form-control"
                                                   value="<?= h(old($settings, 'footer_text')) ?>">
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="col-xl-4">
                            <div class="card shadow-sm mb-4">
                                <div class="card-header bg-white">
                                    <h5 class="mb-0">
                                        <i class="bx bx-toggle-right me-2"></i> Store Controls
                                    </h5>
                                </div>
                                <div class="card-body">
                                    <div class="form-check form-switch mb-3">
                                        <input class="form-check-input" type="checkbox" id="enable_cod" name="enable_cod"
                                               <?= old($settings, 'enable_cod', 1) ? 'checked' : '' ?>>
                                        <label class="form-check-label" for="enable_cod">Enable Cash on Delivery</label>
                                    </div>

                                    <div class="form-check form-switch mb-3">
                                        <input class="form-check-input" type="checkbox" id="enable_online_payment" name="enable_online_payment"
                                               <?= old($settings, 'enable_online_payment', 1) ? 'checked' : '' ?>>
                                        <label class="form-check-label" for="enable_online_payment">Enable Online Payment</label>
                                    </div>

                                    <div class="form-check form-switch mb-3">
                                        <input class="form-check-input" type="checkbox" id="maintenance_mode" name="maintenance_mode"
                                               <?= old($settings, 'maintenance_mode', 0) ? 'checked' : '' ?>>
                                        <label class="form-check-label" for="maintenance_mode">Maintenance Mode</label>
                                    </div>

                                    <hr>

                                    <button type="submit" class="btn btn-primary w-100">
                                        <i class="bx bx-save me-2"></i> Save Settings
                                    </button>
                                </div>
                            </div>

                            <div class="card shadow-sm mb-4">
                                <div class="card-header bg-white">
                                    <h5 class="mb-0">
                                        <i class="bx bx-show me-2"></i> Preview
                                    </h5>
                                </div>
                                <div class="card-body">
                                    <div class="border rounded p-3">
                                        <div class="d-flex align-items-center mb-3">
                                            <div class="rounded-circle me-3"
                                                 style="width:48px;height:48px;background:<?= h(old($settings, 'theme_color', '#0d6efd')) ?>20;display:flex;align-items:center;justify-content:center;">
                                                <i class="bx bx-store fs-3" style="color:<?= h(old($settings, 'theme_color', '#0d6efd')) ?>"></i>
                                            </div>
                                            <div>
                                                <h5 class="mb-0"><?= h(old($settings, 'display_name', 'Store Name')) ?></h5>
                                                <small class="text-muted"><?= h(old($settings, 'tagline', 'Your store tagline')) ?></small>
                                            </div>
                                        </div>

                                        <p class="mb-1"><strong>Phone:</strong> <?= h(old($settings, 'support_phone', '-')) ?></p>
                                        <p class="mb-1"><strong>Email:</strong> <?= h(old($settings, 'support_email', '-')) ?></p>
                                        <p class="mb-1"><strong>Currency:</strong> <?= h(old($settings, 'currency_symbol', '₹')) ?></p>
                                        <p class="mb-0"><strong>Status:</strong>
                                            <?php if (old($settings, 'maintenance_mode', 0)): ?>
                                                <span class="badge bg-warning text-dark">Maintenance Mode</span>
                                            <?php else: ?>
                                                <span class="badge bg-success">Live</span>
                                            <?php endif; ?>
                                        </p>
                                    </div>
                                </div>
                            </div>

                            <div class="card shadow-sm">
                                <div class="card-header bg-white">
                                    <h5 class="mb-0">
                                        <i class="bx bx-info-circle me-2"></i> Notes
                                    </h5>
                                </div>
                                <div class="card-body">
                                    <ul class="mb-0 ps-3">
                                        <li>Use a clean display name. Do not stuff keywords.</li>
                                        <li>Slug should be stable. Changing it later can break links.</li>
                                        <li>Maintenance mode should block customer access on frontend.</li>
                                        <li>Logo and banner fields expect public image URLs.</li>
                                        <li>Telegram IDs: Add multiple IDs to receive order notifications.</li>
                                    </ul>
                                </div>
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
    setTimeout(function () {
        const alerts = document.querySelectorAll('.alert');
        alerts.forEach(function (alert) {
            try {
                const bsAlert = bootstrap.Alert.getOrCreateInstance(alert);
                bsAlert.close();
            } catch (e) {}
        });
    }, 5000);
    
    // Handle "Add More" button for Telegram IDs
    const addButton = document.getElementById('addTelegramBtn');
    const container = document.getElementById('telegramIdsContainer');
    
    function createTelegramRow(telegramId = '', recordId = '') {
        const rowDiv = document.createElement('div');
        rowDiv.className = 'telegram-row mb-2';
        
        const inputGroup = document.createElement('div');
        inputGroup.className = 'input-group';
        
        const iconSpan = document.createElement('span');
        iconSpan.className = 'input-group-text';
        iconSpan.innerHTML = '<i class="bx bxl-telegram"></i>';
        
        const input = document.createElement('input');
        input.type = 'text';
        input.name = 'telegram_id[]';
        input.className = 'form-control';
        input.placeholder = 'Enter Telegram ID (e.g., 123456789';
        input.value = telegramId;
        
        const hiddenInput = document.createElement('input');
        hiddenInput.type = 'hidden';
        hiddenInput.name = 'telegram_record_id[]';
        hiddenInput.value = recordId;
        
        const removeBtn = document.createElement('button');
        removeBtn.type = 'button';
        removeBtn.className = 'btn btn-outline-danger remove-telegram-btn';
        removeBtn.innerHTML = '<i class="bx bx-trash"></i>';
        
        removeBtn.addEventListener('click', function() {
            rowDiv.remove();
        });
        
        inputGroup.appendChild(iconSpan);
        inputGroup.appendChild(input);
        inputGroup.appendChild(hiddenInput);
        inputGroup.appendChild(removeBtn);
        rowDiv.appendChild(inputGroup);
        
        return rowDiv;
    }
    
    if (addButton) {
        addButton.addEventListener('click', function() {
            const newRow = createTelegramRow('', '');
            container.appendChild(newRow);
        });
    }
    
    // Handle removal of existing Telegram IDs (with actual DB records)
    const deleteContainer = document.getElementById('telegramIdsToDelete');
    
    document.querySelectorAll('.remove-telegram-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const row = this.closest('.telegram-row');
            const recordId = row.getAttribute('data-id');
            
            if (recordId) {
                // This is an existing record from DB, mark it for deletion
                const hiddenInput = document.createElement('input');
                hiddenInput.type = 'hidden';
                hiddenInput.name = 'delete_telegram_id[]';
                hiddenInput.value = recordId;
                deleteContainer.appendChild(hiddenInput);
            }
            
            row.remove();
        });
    });
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
.btn {
    border-radius: 10px;
}
.telegram-row .input-group .btn-outline-danger {
    border-top-right-radius: 10px;
    border-bottom-right-radius: 10px;
}
</style>
</body>
</html>