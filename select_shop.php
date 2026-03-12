<?php
require_once 'config/database.php';

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit();
}

// Get user's business_id from session
$user_business_id = $_SESSION['business_id'] ?? null;

// Get all businesses and shops the admin has access to
$user_id = $_SESSION['user_id'];

// Query to get businesses the admin can access
$query = "
    SELECT 
        b.id as business_id,
        b.business_name,
        b.business_code,
        b.cloud_subscription_status,
        b.cloud_expiry_date
    FROM businesses b
    WHERE b.is_active = 1
    AND b.id IN (
        SELECT business_id FROM users WHERE id = ? AND is_active = 1
    )
    ORDER BY b.business_name
";

$stmt = $pdo->prepare($query);
$stmt->execute([$user_id]);
$businesses = $stmt->fetchAll();

// Get shops for each business
$all_businesses = [];
foreach ($businesses as $business) {
    $shop_query = "
        SELECT 
            id as shop_id,
            shop_name,
            shop_code,
            address,
            is_warehouse,
            location_type
        FROM shops 
        WHERE business_id = ? AND is_active = 1
        ORDER BY shop_name
    ";
    
    $shop_stmt = $pdo->prepare($shop_query);
    $shop_stmt->execute([$business['business_id']]);
    $shops = $shop_stmt->fetchAll();
    
    $all_businesses[$business['business_id']] = [
        'business_name' => $business['business_name'],
        'business_code' => $business['business_code'],
        'cloud_status' => $business['cloud_subscription_status'],
        'cloud_expiry' => $business['cloud_expiry_date'],
        'shops' => $shops
    ];
}

// Check for remembered preferences
$default_business_id = $_COOKIE['default_business'] ?? ($user_business_id ?? null);
$default_shop_id = $_COOKIE['default_shop'] ?? null;

// If there's only one business and one shop, auto-select and redirect
if (count($all_businesses) === 1) {
    $business_id = array_key_first($all_businesses);
    $business = $all_businesses[$business_id];
    
    if (count($business['shops']) === 1) {
        $shop = $business['shops'][0];
        
        $_SESSION['current_business_id'] = $business_id;
        $_SESSION['current_business_name'] = $business['business_name'];
        $_SESSION['current_shop_id'] = $shop['shop_id'];
        $_SESSION['current_shop_name'] = $shop['shop_name'];
        
        header("Location: dashboard.php");
        exit();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $business_id = (int)($_POST['business_id'] ?? 0);
    $shop_id = (int)($_POST['shop_id'] ?? 0);
    $remember = isset($_POST['remember']);

    // Validate the business and shop belong together
    if (isset($all_businesses[$business_id])) {
        $shop_found = false;
        foreach ($all_businesses[$business_id]['shops'] as $shop) {
            if ($shop['shop_id'] == $shop_id) {
                $shop_found = true;
                $_SESSION['current_business_id'] = $business_id;
                $_SESSION['current_business_name'] = $all_businesses[$business_id]['business_name'];
                $_SESSION['current_shop_id'] = $shop_id;
                $_SESSION['current_shop_name'] = $shop['shop_name'];
                
                if ($remember) {
                    setcookie('default_business', $business_id, time() + (86400 * 30), "/");
                    setcookie('default_shop', $shop_id, time() + (86400 * 30), "/");
                } else {
                    setcookie('default_business', '', time() - 3600, "/");
                    setcookie('default_shop', '', time() - 3600, "/");
                }
                
                header("Location: dashboard.php");
                exit();
            }
        }
        
        if (!$shop_found) {
            $error = "Invalid shop selection.";
        }
    } else {
        $error = "Invalid business selection.";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Select Business & Shop • Business Management System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary-color: #4361ee;
            --primary-dark: #3651d4;
            --secondary-color: #7209b7;
            --accent-color: #f72585;
            --light-bg: #f8f9fa;
            --card-bg: #ffffff;
            --text-dark: #2b2d42;
            --text-gray: #6c757d;
            --border-color: #e2e8f0;
            --shadow: 0 10px 30px rgba(0, 0, 0, 0.08);
            --shadow-hover: 0 20px 40px rgba(67, 97, 238, 0.15);
            --radius: 16px;
            --transition: all 0.3s ease;
            --success-color: #10b981;
            --warning-color: #f59e0b;
            --danger-color: #ef4444;
        }
        
        body {
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
            background: linear-gradient(135deg, #f5f7fa 0%, #e3e8f8 100%);
            min-height: 100vh;
            color: var(--text-dark);
            padding: 20px;
            margin: 0;
        }
        
        .container-wrapper {
            max-width: 1200px;
            margin: 0 auto;
            animation: fadeIn 0.8s ease-out;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .header-card {
            background: linear-gradient(135deg, #4361ee 0%, #3a56d4 100%);
            color: white;
            border-radius: var(--radius);
            padding: 35px;
            margin-bottom: 30px;
            text-align: center;
            box-shadow: var(--shadow);
            position: relative;
            overflow: hidden;
        }
        
        .header-card::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255,255,255,0.1) 1px, transparent 1px);
            background-size: 20px 20px;
            opacity: 0.2;
        }
        
        .user-info {
            position: absolute;
            top: 20px;
            right: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
            background: rgba(255, 255, 255, 0.15);
            padding: 8px 15px;
            border-radius: 50px;
            backdrop-filter: blur(10px);
            z-index: 1;
        }
        
        .user-avatar {
            width: 36px;
            height: 36px;
            background: white;
            color: var(--primary-color);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
        }
        
        .welcome-text h1 {
            font-size: 2.5rem;
            font-weight: 700;
            margin-bottom: 10px;
            letter-spacing: -0.5px;
        }
        
        .welcome-text p {
            font-size: 1.1rem;
            opacity: 0.9;
            max-width: 700px;
            margin: 0 auto;
        }
        
        .business-tabs {
            display: flex;
            gap: 10px;
            margin-bottom: 30px;
            flex-wrap: wrap;
        }
        
        .business-tab {
            padding: 15px 25px;
            background: var(--card-bg);
            border: 2px solid var(--border-color);
            border-radius: 12px;
            cursor: pointer;
            transition: var(--transition);
            display: flex;
            align-items: center;
            gap: 10px;
            font-weight: 600;
            color: var(--text-dark);
        }
        
        .business-tab:hover {
            border-color: var(--primary-color);
            transform: translateY(-2px);
        }
        
        .business-tab.active {
            background: var(--primary-color);
            color: white;
            border-color: var(--primary-color);
            box-shadow: 0 5px 15px rgba(67, 97, 238, 0.2);
        }
        
        .business-tab.active .cloud-indicator {
            background: rgba(255, 255, 255, 0.3);
            color: white;
        }
        
        .cloud-indicator {
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            background: var(--light-bg);
            color: var(--text-gray);
        }
        
        .cloud-status-active {
            background: var(--success-color);
            color: white;
        }
        
        .cloud-status-trial {
            background: var(--warning-color);
            color: white;
        }
        
        .cloud-status-expired {
            background: var(--danger-color);
            color: white;
        }
        
        .shops-container {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 25px;
            margin-bottom: 30px;
        }
        
        .shop-card {
            background: var(--card-bg);
            border-radius: var(--radius);
            padding: 25px;
            border: 2px solid var(--border-color);
            transition: var(--transition);
            cursor: pointer;
            position: relative;
            overflow: hidden;
            opacity: 0.7;
            transform: scale(0.95);
        }
        
        .shop-card.active {
            opacity: 1;
            transform: scale(1);
        }
        
        .shop-card:hover {
            transform: translateY(-5px) scale(1);
            border-color: var(--primary-color);
            box-shadow: var(--shadow-hover);
        }
        
        .shop-card.selected {
            border-color: var(--primary-color);
            background: linear-gradient(135deg, rgba(67, 97, 238, 0.05) 0%, rgba(114, 9, 183, 0.05) 100%);
            position: relative;
        }
        
        .shop-card.selected::before {
            content: 'SELECTED';
            position: absolute;
            top: 10px;
            right: 10px;
            background: var(--primary-color);
            color: white;
            font-size: 0.7rem;
            padding: 4px 10px;
            border-radius: 20px;
            font-weight: 600;
        }
        
        .warehouse-badge {
            position: absolute;
            top: 10px;
            left: 10px;
            background: var(--secondary-color);
            color: white;
            font-size: 0.7rem;
            padding: 4px 10px;
            border-radius: 20px;
            font-weight: 600;
        }
        
        .shop-icon {
            width: 60px;
            height: 60px;
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 20px;
            color: white;
            font-size: 24px;
        }
        
        .shop-info h3 {
            font-size: 1.3rem;
            font-weight: 700;
            margin-bottom: 8px;
            color: var(--text-dark);
        }
        
        .shop-code {
            display: inline-block;
            background: var(--light-bg);
            color: var(--text-gray);
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
            margin-bottom: 12px;
        }
        
        .shop-address {
            color: var(--text-gray);
            font-size: 0.95rem;
            line-height: 1.5;
            margin-bottom: 0;
        }
        
        .shop-radio {
            position: absolute;
            opacity: 0;
        }
        
        .form-footer {
            background: var(--card-bg);
            border-radius: var(--radius);
            padding: 30px;
            box-shadow: var(--shadow);
        }
        
        .remember-section {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 25px;
            padding-bottom: 20px;
            border-bottom: 2px solid var(--border-color);
        }
        
        .checkbox-container {
            display: flex;
            align-items: center;
            gap: 10px;
            cursor: pointer;
        }
        
        .custom-checkbox {
            width: 22px;
            height: 22px;
            border: 2px solid var(--border-color);
            border-radius: 6px;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: var(--transition);
        }
        
        .custom-checkbox.checked {
            background: var(--primary-color);
            border-color: var(--primary-color);
        }
        
        .custom-checkbox.checked::after {
            content: '✓';
            color: white;
            font-size: 14px;
            font-weight: bold;
        }
        
        .continue-btn {
            width: 100%;
            padding: 18px;
            font-size: 1.1rem;
            font-weight: 600;
            background: linear-gradient(135deg, var(--primary-color), var(--primary-dark));
            border: none;
            border-radius: 12px;
            color: white;
            transition: var(--transition);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }
        
        .continue-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(67, 97, 238, 0.4);
        }
        
        .continue-btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none !important;
            box-shadow: none !important;
        }
        
        .stats-box {
            background: var(--light-bg);
            color: var(--text-gray);
            padding: 8px 16px;
            border-radius: 20px;
            font-size: 0.9rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .error-alert {
            background: rgba(239, 68, 68, 0.1);
            border: 2px solid var(--danger-color);
            color: var(--danger-color);
            padding: 15px;
            border-radius: var(--radius);
            margin-bottom: 20px;
            display: none;
        }
        
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: var(--text-gray);
        }
        
        .empty-state i {
            font-size: 3rem;
            margin-bottom: 20px;
            color: var(--border-color);
        }
        
        .loading-spinner {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 3px solid rgba(255, 255, 255, 0.3);
            border-radius: 50%;
            border-top-color: white;
            animation: spin 1s ease-in-out infinite;
        }
        
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        
        /* Responsive design */
        @media (max-width: 992px) {
            .shops-container {
                grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            }
        }
        
        @media (max-width: 768px) {
            .shops-container {
                grid-template-columns: 1fr;
            }
            
            .header-card {
                padding: 25px 20px;
            }
            
            .welcome-text h1 {
                font-size: 2rem;
            }
            
            .business-tabs {
                overflow-x: auto;
                flex-wrap: nowrap;
                padding-bottom: 10px;
            }
            
            .business-tab {
                white-space: nowrap;
            }
            
            .remember-section {
                flex-direction: column;
                gap: 15px;
                align-items: flex-start;
            }
        }
        
        @media (max-width: 576px) {
            body {
                padding: 15px;
            }
            
            .shop-card {
                padding: 20px;
            }
            
            .form-footer {
                padding: 20px;
            }
        }
    </style>
</head>
<body>
    <div class="container-wrapper">
        <div class="header-card">
            <div class="user-info">
                <div class="user-avatar">
                    <?= strtoupper(substr($_SESSION['full_name'], 0, 1)) ?>
                </div>
                <span><?= htmlspecialchars($_SESSION['full_name']) ?></span>
            </div>
            
            <div class="welcome-text">
                <h1>Select Business & Location</h1>
                <p>Choose a business and shop location to manage inventory, sales, and operations. Your selection will determine the data you can access and modify.</p>
            </div>
        </div>

        <?php if (isset($error)): ?>
            <div class="error-alert" id="errorAlert">
                <i class="fas fa-exclamation-triangle me-2"></i>
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <form method="POST" id="selectionForm">
            <!-- Business Selection Tabs -->
            <div class="business-tabs" id="businessTabs">
                <?php foreach ($all_businesses as $business_id => $business): ?>
                    <div class="business-tab <?= ($default_business_id == $business_id) ? 'active' : '' ?>" 
                         data-business-id="<?= $business_id ?>">
                        <i class="fas fa-building"></i>
                        <?= htmlspecialchars($business['business_name']) ?>
                        <span class="cloud-indicator cloud-status-<?= $business['cloud_status'] ?>">
                            <?= strtoupper($business['cloud_status']) ?>
                        </span>
                    </div>
                <?php endforeach; ?>
                <input type="hidden" name="business_id" id="businessId" value="<?= $default_business_id ?>">
            </div>

            <!-- Shop Selection -->
            <div class="shops-container" id="shopsContainer">
                <?php if (empty($all_businesses)): ?>
                    <div class="empty-state">
                        <i class="fas fa-store-alt-slash"></i>
                        <h3>No Businesses Found</h3>
                        <p>You don't have access to any businesses yet.</p>
                        <a href="register_business.php" class="btn btn-primary mt-3">
                            <i class="fas fa-plus me-2"></i>Register New Business
                        </a>
                    </div>
                <?php else: ?>
                    <?php foreach ($all_businesses as $business_id => $business): ?>
                        <?php if (!empty($business['shops'])): ?>
                            <?php foreach ($business['shops'] as $shop): ?>
                                <label class="shop-card <?= ($default_business_id == $business_id) ? 'active' : '' ?>"
                                       data-business-id="<?= $business_id ?>">
                                    <?php if ($shop['is_warehouse']): ?>
                                        <span class="warehouse-badge">WAREHOUSE</span>
                                    <?php endif; ?>
                                    
                                    <input type="radio" 
                                           name="shop_id" 
                                           value="<?= $shop['shop_id'] ?>" 
                                           class="shop-radio" 
                                           data-business-id="<?= $business_id ?>"
                                           required
                                           <?= ($default_shop_id == $shop['shop_id']) ? 'checked' : '' ?>>
                                    
                                    <div class="shop-icon">
                                        <i class="<?= $shop['is_warehouse'] ? 'fas fa-warehouse' : 'fas fa-store' ?>"></i>
                                    </div>
                                    
                                    <div class="shop-info">
                                        <h3><?= htmlspecialchars($shop['shop_name']) ?></h3>
                                        <span class="shop-code"><?= $shop['shop_code'] ?></span>
                                        <?php if (!empty($shop['address'])): ?>
                                            <p class="shop-address">
                                                <i class="fas fa-map-marker-alt me-2"></i>
                                                <?= htmlspecialchars($shop['address']) ?>
                                            </p>
                                        <?php endif; ?>
                                    </div>
                                </label>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class="fas fa-store"></i>
                                <h3>No Shops Found for <?= htmlspecialchars($business['business_name']) ?></h3>
                                <p>This business doesn't have any active shops yet.</p>
                                <a href="add_shop.php?business_id=<?= $business_id ?>" class="btn btn-primary mt-3">
                                    <i class="fas fa-plus me-2"></i>Add New Shop
                                </a>
                            </div>
                        <?php endif; ?>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            
            <?php if (!empty($all_businesses)): ?>
                <div class="form-footer">
                    <div class="remember-section">
                        <div class="checkbox-container" id="rememberContainer">
                            <div class="custom-checkbox <?= isset($_COOKIE['default_business']) ? 'checked' : '' ?>" id="customCheckbox"></div>
                            <span>Remember my choice for 30 days</span>
                            <input type="checkbox" name="remember" id="remember" class="d-none" 
                                   <?= isset($_COOKIE['default_business']) ? 'checked' : '' ?>>
                        </div>
                        
                        <div class="stats-box">
                            <i class="fas fa-chart-bar"></i>
                            <span id="statsText">
                                <?= count($all_businesses) ?> Business<?= count($all_businesses) !== 1 ? 'es' : '' ?>, 
                                <?= array_sum(array_map(fn($b) => count($b['shops']), $all_businesses)) ?> Location<?= array_sum(array_map(fn($b) => count($b['shops']), $all_businesses)) !== 1 ? 's' : '' ?>
                            </span>
                        </div>
                    </div>
                    
                    <button type="submit" class="btn continue-btn" id="continueBtn" disabled>
                        <span id="btnText">
                            <i class="fas fa-arrow-right me-2"></i>
                            Continue to Dashboard
                        </span>
                        <span id="loading" style="display:none;">
                            <span class="loading-spinner"></span> Redirecting...
                        </span>
                    </button>
                </div>
            <?php endif; ?>
        </form>
    </div>
    
    <script>
        // Business selection handling
        const businessTabs = document.querySelectorAll('.business-tab');
        const businessIdInput = document.getElementById('businessId');
        const shopCards = document.querySelectorAll('.shop-card');
        const shopRadios = document.querySelectorAll('.shop-radio');
        const continueBtn = document.getElementById('continueBtn');
        const errorAlert = document.getElementById('errorAlert');
        const shopsContainer = document.getElementById('shopsContainer');

        // Initialize with default business
        let selectedBusinessId = businessIdInput.value;
        
        // Show/hide shops based on selected business
        function filterShopsByBusiness(businessId) {
            shopCards.forEach(card => {
                const cardBusinessId = card.getAttribute('data-business-id');
                if (businessId && cardBusinessId === businessId) {
                    card.classList.add('active');
                    card.style.display = 'block';
                } else {
                    card.classList.remove('active', 'selected');
                    card.style.display = 'none';
                }
            });
            
            // Uncheck shop radios from other businesses
            shopRadios.forEach(radio => {
                if (radio.getAttribute('data-business-id') !== businessId) {
                    radio.checked = false;
                }
            });
            
            // Enable/disable continue button
            const selectedShop = document.querySelector(`.shop-radio[data-business-id="${businessId}"]:checked`);
            continueBtn.disabled = !selectedShop;
            
            // Update stats
            const businessCount = document.querySelectorAll('.business-tab').length;
            const visibleShops = document.querySelectorAll(`.shop-card[data-business-id="${businessId}"]`).length;
            document.getElementById('statsText').textContent = 
                `${businessCount} Business${businessCount !== 1 ? 'es' : ''}, ${visibleShops} Location${visibleShops !== 1 ? 's' : ''}`;
        }

        // Business tab click handler
        businessTabs.forEach(tab => {
            tab.addEventListener('click', function() {
                // Update active tab
                businessTabs.forEach(t => t.classList.remove('active'));
                this.classList.add('active');
                
                // Update business ID
                selectedBusinessId = this.getAttribute('data-business-id');
                businessIdInput.value = selectedBusinessId;
                
                // Filter shops
                filterShopsByBusiness(selectedBusinessId);
                
                // Hide error if visible
                if (errorAlert) errorAlert.style.display = 'none';
            });
        });

        // Shop card click handler
        shopCards.forEach(card => {
            card.addEventListener('click', function() {
                const cardBusinessId = this.getAttribute('data-business-id');
                
                // Only allow selection if business is selected
                if (!selectedBusinessId || cardBusinessId !== selectedBusinessId) return;
                
                // Remove selected class from all cards
                shopCards.forEach(c => c.classList.remove('selected'));
                
                // Add selected class to clicked card
                this.classList.add('selected');
                
                // Check the radio button
                const radio = this.querySelector('.shop-radio');
                radio.checked = true;
                
                // Enable continue button
                continueBtn.disabled = false;
                
                // Hide error if visible
                if (errorAlert) errorAlert.style.display = 'none';
            });
        });

        // Remember checkbox custom styling
        const rememberContainer = document.getElementById('rememberContainer');
        const customCheckbox = document.getElementById('customCheckbox');
        const rememberCheckbox = document.getElementById('remember');
        
        if (rememberContainer) {
            rememberContainer.addEventListener('click', function(e) {
                e.preventDefault();
                const isChecked = rememberCheckbox.checked;
                rememberCheckbox.checked = !isChecked;
                customCheckbox.classList.toggle('checked', !isChecked);
            });
        }

        // Form submission handling
        const selectionForm = document.getElementById('selectionForm');
        const btnText = document.getElementById('btnText');
        const loading = document.getElementById('loading');
        
        if (selectionForm) {
            selectionForm.addEventListener('submit', function(e) {
                if (!selectedBusinessId) {
                    e.preventDefault();
                    showError('Please select a business first.');
                    return;
                }
                
                const selectedShop = document.querySelector(`.shop-radio[data-business-id="${selectedBusinessId}"]:checked`);
                
                if (!selectedShop) {
                    e.preventDefault();
                    showError('Please select a shop/location to continue.');
                    return;
                }
                
                // Show loading state
                continueBtn.disabled = true;
                btnText.style.display = 'none';
                loading.style.display = 'flex';
                loading.style.alignItems = 'center';
                loading.style.gap = '10px';
            });
        }

        function showError(message) {
            if (errorAlert) {
                errorAlert.innerHTML = `<i class="fas fa-exclamation-triangle me-2"></i>${message}`;
                errorAlert.style.display = 'block';
            } else {
                alert(message);
            }
        }

        // Auto-select default business on page load
        document.addEventListener('DOMContentLoaded', function() {
            if (selectedBusinessId) {
                const defaultBusinessTab = document.querySelector(`.business-tab[data-business-id="${selectedBusinessId}"]`);
                if (defaultBusinessTab) {
                    defaultBusinessTab.click();
                    
                    // Auto-select default shop
                    const defaultShopRadio = document.querySelector(`.shop-radio[value="${<?= $default_shop_id ?? 'null' ?>}"]`);
                    if (defaultShopRadio && defaultShopRadio.getAttribute('data-business-id') === selectedBusinessId) {
                        const defaultShopCard = defaultShopRadio.closest('.shop-card');
                        if (defaultShopCard) {
                            defaultShopCard.click();
                        }
                    }
                }
            } else if (businessTabs.length > 0) {
                // Select first business by default
                businessTabs[0].click();
            }
        });

        // Keyboard navigation
        document.addEventListener('keydown', function(e) {
            if (!selectedBusinessId) return;
            
            const visibleShopCards = Array.from(document.querySelectorAll(`.shop-card[data-business-id="${selectedBusinessId}"]`));
            const selectedCard = document.querySelector(`.shop-card[data-business-id="${selectedBusinessId}"].selected`);
            
            if (!selectedCard) return;
            
            const currentIndex = visibleShopCards.indexOf(selectedCard);
            
            if (e.key === 'ArrowRight' || e.key === 'ArrowDown') {
                e.preventDefault();
                const nextIndex = (currentIndex + 1) % visibleShopCards.length;
                visibleShopCards[nextIndex].click();
            } else if (e.key === 'ArrowLeft' || e.key === 'ArrowUp') {
                e.preventDefault();
                const prevIndex = (currentIndex - 1 + visibleShopCards.length) % visibleShopCards.length;
                visibleShopCards[prevIndex].click();
            }
        });
    </script>
</body>
</html>