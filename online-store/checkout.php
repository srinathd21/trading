<?php
if (!defined('STORE_FRONT')) {
    die('Direct access not allowed.');
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/* =========================
   SAFE FALLBACKS
========================= */
$slug            = $slug ?? '';
$displayName     = $displayName ?? 'Online Store';
$metaTitle       = $metaTitle ?? ('Checkout | ' . $displayName);
$metaDescription = $metaDescription ?? ('Complete your order at ' . $displayName);
$themeColor      = $themeColor ?? '#e11d48';
$secondaryColor  = $secondaryColor ?? '#111827';
$logoUrl         = $logoUrl ?? 'https://via.placeholder.com/200x80?text=Logo';
$faviconUrl      = $faviconUrl ?? $logoUrl;
$currencySymbol  = $currencySymbol ?? '₹';
$storeUrl        = $storeUrl ?? ('storefront.php?slug=' . urlencode($slug));
$storePageUrl    = $storePageUrl ?? ($storeUrl . '&page=store');
$categoryPageUrl = $categoryPageUrl ?? ($storeUrl . '&page=categories');
$contactPageUrl  = $contactPageUrl ?? ($storeUrl . '&page=contact');
$customCss       = $customCss ?? '';
$supportPhone    = $supportPhone ?? '';
$supportEmail    = $supportEmail ?? '';
$whatsappNumber  = $whatsappNumber ?? '';
$storeAddress    = $storeAddress ?? '';
$enableCOD       = 1;
$businessId      = (int)($businessId ?? ($store['business_id'] ?? ($storeRow['business_id'] ?? 0)));

/* =========================
   DB CHECK
========================= */
if (!isset($pdo) || !($pdo instanceof PDO)) {
    die('Database connection not available.');
}

/* =========================
   HELPERS
========================= */
if (!function_exists('sf_h')) {
    function sf_h($value): string {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('sf_money')) {
    function sf_money($amount, string $symbol = '₹'): string {
        return $symbol . number_format((float)$amount, 2, '.', ',');
    }
}

if (!function_exists('sf_normalize_image_path')) {
    function sf_normalize_image_path($path, string $fallback = ''): string {
        $path = trim((string)($path ?? ''));

        if ($path === '') {
            return $fallback;
        }

        if (
            stripos($path, 'http://') === 0 ||
            stripos($path, 'https://') === 0 ||
            stripos($path, '//') === 0 ||
            stripos($path, 'data:') === 0
        ) {
            return $path;
        }

        $path = str_replace('\\', '/', $path);
        return ltrim($path, '/');
    }
}

if (!function_exists('sf_generate_order_number')) {
    function sf_generate_order_number(): string {
        return 'ORD' . date('YmdHis') . rand(100, 999);
    }
}

if (!function_exists('sf_table_has_column')) {
    function sf_table_has_column(PDO $pdo, string $table, string $column): bool {
        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = :table_name
              AND COLUMN_NAME = :column_name
        ");
        $stmt->execute([
            ':table_name'  => $table,
            ':column_name' => $column
        ]);
        return (int)$stmt->fetchColumn() > 0;
    }
}

/* =========================
   INCLUDE VARIABLES
========================= */
$storefront_slug             = $slug;
$storefront_display_name     = $displayName;
$storefront_logo             = $logoUrl;
$storefront_currency         = $currencySymbol;
$storefront_store_url        = $storeUrl;
$storefront_store_page_url   = $storePageUrl;
$storefront_contact_page_url = $contactPageUrl;
$storefront_phone            = $supportPhone;
$storefront_email            = $supportEmail;
$storefront_whatsapp         = $whatsappNumber;
$storefront_address          = $storeAddress;

$confirmationPageBaseUrl = 'storefront.php?slug=' . urlencode($slug) . '&page=confirmation';
$cartPageUrl             = 'storefront.php?slug=' . urlencode($slug) . '&page=cart';

/* =========================
   LOGGED IN CUSTOMER
========================= */
$loggedInCustomerId = 0;
$loggedInCustomer = null;

$possibleCustomerIds = [
    (int)($_SESSION['customer_id'] ?? 0),
    (int)($_SESSION['online_customer_id'] ?? 0),
    (int)($_SESSION['storefront_customer_id'] ?? 0),
    (int)($_SESSION['store_customer_id'] ?? 0),
    (int)($_SESSION['web_customer_id'] ?? 0),
];

foreach ($possibleCustomerIds as $cid) {
    if ($cid > 0) {
        $loggedInCustomerId = $cid;
        break;
    }
}

if ($loggedInCustomerId > 0) {
    try {
        $customerStmt = $pdo->prepare("
            SELECT id, business_id, name, phone, alt_phone, email, address
            FROM customers
            WHERE id = :id
              AND business_id = :business_id
            LIMIT 1
        ");
        $customerStmt->execute([
            ':id' => $loggedInCustomerId,
            ':business_id' => $businessId
        ]);
        $loggedInCustomer = $customerStmt->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) {
        $loggedInCustomer = null;
    }
}

/* =========================
   FORM STATE
========================= */
$addressRaw = trim((string)($loggedInCustomer['address'] ?? ''));
$formError = '';
$formData = [
    'full_name'      => trim((string)($loggedInCustomer['name'] ?? ($_SESSION['customer_name'] ?? ''))),
    'phone'          => trim((string)($loggedInCustomer['phone'] ?? ($_SESSION['customer_phone'] ?? ''))),
    'email'          => trim((string)($loggedInCustomer['email'] ?? ($_SESSION['customer_email'] ?? ''))),
    'alt_phone'      => trim((string)($loggedInCustomer['alt_phone'] ?? '')),
    'address_line'   => $addressRaw,
    'city'           => '',
    'state'          => '',
    'pincode'        => '',
    'landmark'       => '',
    'payment_method' => 'cod',
    'order_note'     => '',
];

/* =========================
   HANDLE ORDER SUBMIT
========================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $formData['full_name']      = trim((string)($_POST['full_name'] ?? ''));
    $formData['phone']          = trim((string)($_POST['phone'] ?? ''));
    $formData['email']          = trim((string)($_POST['email'] ?? ''));
    $formData['alt_phone']      = trim((string)($_POST['alt_phone'] ?? ''));
    $formData['address_line']   = trim((string)($_POST['address_line'] ?? ''));
    $formData['city']           = trim((string)($_POST['city'] ?? ''));
    $formData['state']          = trim((string)($_POST['state'] ?? ''));
    $formData['pincode']        = trim((string)($_POST['pincode'] ?? ''));
    $formData['landmark']       = trim((string)($_POST['landmark'] ?? ''));
    $formData['payment_method'] = 'cod';
    $formData['order_note']     = trim((string)($_POST['order_note'] ?? ''));

    $cartJson = (string)($_POST['cart_json'] ?? '[]');
    $postedCartTotal = (float)($_POST['cart_total'] ?? 0);

    $cartItems = json_decode($cartJson, true);
    if (!is_array($cartItems)) {
        $cartItems = [];
    }

    if (
        $formData['full_name'] === '' ||
        $formData['phone'] === '' ||
        $formData['address_line'] === '' ||
        $formData['city'] === '' ||
        $formData['state'] === '' ||
        $formData['pincode'] === ''
    ) {
        $formError = 'Please fill all required fields.';
    }

    $normalizedItems = [];
    $subtotal = 0.00;
    $totalItems = 0;

    foreach ($cartItems as $item) {
        $productId    = isset($item['id']) ? (int)$item['id'] : (isset($item['product_id']) ? (int)$item['product_id'] : null);
        $productName  = trim((string)($item['name'] ?? $item['product_name'] ?? ''));
        $productImage = trim((string)($item['image'] ?? $item['product_image'] ?? ''));
        $unitPrice    = (float)($item['price'] ?? 0);
        $quantity     = (int)($item['qty'] ?? $item['quantity'] ?? 0);

        if ($productName === '' || $unitPrice < 0 || $quantity <= 0) {
            continue;
        }

        $lineTotal = $unitPrice * $quantity;
        $subtotal += $lineTotal;
        $totalItems += $quantity;

        $normalizedItems[] = [
            'product_id'    => $productId,
            'product_name'  => $productName,
            'product_image' => $productImage,
            'unit_price'    => $unitPrice,
            'quantity'      => $quantity,
            'line_total'    => $lineTotal
        ];
    }

    if (empty($normalizedItems)) {
        $formError = 'Your cart is empty.';
    }

    $deliveryCharge = 0.00;
    $discountAmount = 0.00;
    $grandTotal = $subtotal + $deliveryCharge - $discountAmount;

    if ($grandTotal <= 0) {
        $formError = 'Invalid order total.';
    }

    if ($formError === '') {
        try {
            $pdo->beginTransaction();

            $orderNumber = sf_generate_order_number();

            $orderHasCustomerId = sf_table_has_column($pdo, 'online_store_orders', 'customer_id');

            if ($orderHasCustomerId) {
                $orderSql = "
                    INSERT INTO online_store_orders (
                        business_id,
                        customer_id,
                        store_slug,
                        order_number,
                        customer_name,
                        phone,
                        email,
                        alt_phone,
                        address_line,
                        city,
                        state,
                        pincode,
                        landmark,
                        payment_method,
                        order_note,
                        subtotal,
                        delivery_charge,
                        discount_amount,
                        grand_total,
                        total_items,
                        order_status,
                        payment_status,
                        created_at,
                        updated_at
                    ) VALUES (
                        :business_id,
                        :customer_id,
                        :store_slug,
                        :order_number,
                        :customer_name,
                        :phone,
                        :email,
                        :alt_phone,
                        :address_line,
                        :city,
                        :state,
                        :pincode,
                        :landmark,
                        :payment_method,
                        :order_note,
                        :subtotal,
                        :delivery_charge,
                        :discount_amount,
                        :grand_total,
                        :total_items,
                        :order_status,
                        :payment_status,
                        NOW(),
                        NOW()
                    )
                ";
            } else {
                $orderSql = "
                    INSERT INTO online_store_orders (
                        business_id,
                        store_slug,
                        order_number,
                        customer_name,
                        phone,
                        email,
                        alt_phone,
                        address_line,
                        city,
                        state,
                        pincode,
                        landmark,
                        payment_method,
                        order_note,
                        subtotal,
                        delivery_charge,
                        discount_amount,
                        grand_total,
                        total_items,
                        order_status,
                        payment_status,
                        created_at,
                        updated_at
                    ) VALUES (
                        :business_id,
                        :store_slug,
                        :order_number,
                        :customer_name,
                        :phone,
                        :email,
                        :alt_phone,
                        :address_line,
                        :city,
                        :state,
                        :pincode,
                        :landmark,
                        :payment_method,
                        :order_note,
                        :subtotal,
                        :delivery_charge,
                        :discount_amount,
                        :grand_total,
                        :total_items,
                        :order_status,
                        :payment_status,
                        NOW(),
                        NOW()
                    )
                ";
            }

            $orderStmt = $pdo->prepare($orderSql);

            $orderParams = [
                ':business_id'     => $businessId,
                ':store_slug'      => $slug,
                ':order_number'    => $orderNumber,
                ':customer_name'   => $formData['full_name'],
                ':phone'           => $formData['phone'],
                ':email'           => $formData['email'] !== '' ? $formData['email'] : null,
                ':alt_phone'       => $formData['alt_phone'] !== '' ? $formData['alt_phone'] : null,
                ':address_line'    => $formData['address_line'],
                ':city'            => $formData['city'],
                ':state'           => $formData['state'],
                ':pincode'         => $formData['pincode'],
                ':landmark'        => $formData['landmark'] !== '' ? $formData['landmark'] : null,
                ':payment_method'  => 'cod',
                ':order_note'      => $formData['order_note'] !== '' ? $formData['order_note'] : null,
                ':subtotal'        => $subtotal,
                ':delivery_charge' => $deliveryCharge,
                ':discount_amount' => $discountAmount,
                ':grand_total'     => $grandTotal,
                ':total_items'     => $totalItems,
                ':order_status'    => 'pending',
                ':payment_status'  => 'pending'
            ];

            if ($orderHasCustomerId) {
                $orderParams[':customer_id'] = $loggedInCustomerId > 0 ? $loggedInCustomerId : null;
            }

            $orderStmt->execute($orderParams);

            $orderId = (int)$pdo->lastInsertId();

            $itemStmt = $pdo->prepare("
                INSERT INTO online_store_order_items (
                    order_id,
                    product_id,
                    product_name,
                    product_image,
                    unit_price,
                    quantity,
                    line_total,
                    created_at
                ) VALUES (
                    :order_id,
                    :product_id,
                    :product_name,
                    :product_image,
                    :unit_price,
                    :quantity,
                    :line_total,
                    NOW()
                )
            ");

            foreach ($normalizedItems as $item) {
                $itemStmt->execute([
                    ':order_id'      => $orderId,
                    ':product_id'    => $item['product_id'] ?: null,
                    ':product_name'  => $item['product_name'],
                    ':product_image' => $item['product_image'] !== '' ? $item['product_image'] : null,
                    ':unit_price'    => $item['unit_price'],
                    ':quantity'      => $item['quantity'],
                    ':line_total'    => $item['line_total']
                ]);
            }

            $pdo->commit();

            header('Location: ' . $confirmationPageBaseUrl . '&order_id=' . $orderId);
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $formError = 'Failed to place order. ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title><?php echo sf_h('Checkout | ' . $displayName); ?></title>
  <meta name="description" content="<?php echo sf_h($metaDescription); ?>">
  <link rel="icon" href="<?php echo sf_h($faviconUrl); ?>">

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">

  <link rel="stylesheet" href="online-store/assets/main.css">
  <link rel="stylesheet" href="online-store/assets/menu-bar.css">
  <link rel="stylesheet" href="online-store/assets/cart.css">
  <link rel="stylesheet" href="online-store/assets/footer.css">

  <style>
    :root{
      --accent: <?php echo sf_h($themeColor); ?>;
      --primary-dark: <?php echo sf_h($secondaryColor); ?>;
    }

    .checkout-hero{
      background: linear-gradient(rgba(17,24,39,0.88), rgba(17,24,39,0.78));
      color:#fff;
      padding:70px 0 50px;
    }

    .checkout-hero h1{
      font-size:40px;
      font-weight:800;
      margin-bottom:10px;
    }

    .checkout-hero p{
      margin:0;
      color:#d1d5db;
      font-size:15px;
      line-height:1.8;
    }

    .breadcrumb-wrap{
      background:#f8f9fa;
      border-bottom:1px solid #e5e7eb;
    }

    .breadcrumb-custom{
      margin:0;
      padding:14px 0;
      font-size:14px;
    }

    .breadcrumb-custom a{
      color:#6b7280;
      text-decoration:none;
    }

    .breadcrumb-custom .active{
      color:#111827;
      font-weight:600;
    }

    .checkout-card,
    .summary-card{
      background:#fff;
      border:1px solid #e5e7eb;
      height:100%;
    }

    .checkout-card{
      padding:28px 24px;
    }

    .summary-card{
      padding:24px 22px;
      position:sticky;
      top:100px;
    }

    .checkout-card h3,
    .summary-card h3{
      font-size:26px;
      font-weight:800;
      color:#111827;
      margin-bottom:10px;
    }

    .section-subtitle{
      font-size:14px;
      color:#6b7280;
      margin-bottom:24px;
      line-height:1.7;
    }

    .checkout-block{
      margin-bottom:28px;
      padding-bottom:24px;
      border-bottom:1px solid #e5e7eb;
    }

    .checkout-block:last-child{
      margin-bottom:0;
      padding-bottom:0;
      border-bottom:none;
    }

    .checkout-block h5{
      font-size:18px;
      font-weight:700;
      color:#111827;
      margin-bottom:16px;
    }

    .form-label{
      font-size:14px;
      font-weight:700;
      color:#111827;
      margin-bottom:8px;
    }

    .form-control,
    .form-select{
      min-height:48px;
      border-radius:0;
      border:1px solid #d1d5db;
      box-shadow:none !important;
      font-size:14px;
    }

    .form-control:focus,
    .form-select:focus{
      border-color:#111827;
    }

    textarea.form-control{
      min-height:110px;
      resize:none;
    }

    .payment-option{
      border:1px solid #e5e7eb;
      padding:14px 16px;
      margin-bottom:12px;
      display:flex;
      align-items:center;
      gap:12px;
    }

    .payment-option input{
      margin-top:0;
    }

    .payment-option label{
      margin:0;
      font-size:14px;
      font-weight:600;
      color:#111827;
      width:100%;
      cursor:pointer;
    }

    .order-item{
      display:flex;
      gap:12px;
      margin-bottom:16px;
      padding-bottom:16px;
      border-bottom:1px solid #eef2f7;
    }

    .order-item:last-child{
      margin-bottom:0;
      padding-bottom:0;
      border-bottom:none;
    }

    .order-item img{
      width:78px;
      height:78px;
      object-fit:cover;
      border:1px solid #e5e7eb;
      background:#fff;
      flex-shrink:0;
    }

    .order-item-details{
      flex:1;
      min-width:0;
    }

    .order-item h6{
      font-size:15px;
      font-weight:700;
      color:#111827;
      margin-bottom:4px;
    }

    .order-item p{
      margin:0;
      color:#6b7280;
      font-size:13px;
      line-height:1.6;
    }

    .order-item-price{
      font-size:14px;
      font-weight:700;
      color:#111827;
      margin-top:4px;
    }

    .qty-controls{
      display:flex;
      align-items:center;
      gap:8px;
      margin-top:10px;
      flex-wrap:wrap;
    }

    .qty-btn{
      width:32px;
      height:32px;
      border:1px solid #d1d5db;
      background:#fff;
      color:#111827;
      font-size:16px;
      font-weight:700;
      display:flex;
      align-items:center;
      justify-content:center;
      cursor:pointer;
      padding:0;
      line-height:1;
    }

    .qty-btn:hover{
      background:#f8f9fa;
    }

    .qty-value{
      min-width:28px;
      text-align:center;
      font-size:14px;
      font-weight:700;
      color:#111827;
    }

    .remove-item-btn{
      border:none;
      background:none;
      color:#dc3545;
      font-size:13px;
      font-weight:600;
      padding:0;
      cursor:pointer;
    }

    .remove-item-btn:hover{
      text-decoration:underline;
    }

    .summary-row{
      display:flex;
      justify-content:space-between;
      align-items:center;
      font-size:14px;
      margin-bottom:12px;
      color:#4b5563;
    }

    .summary-row.total{
      border-top:1px solid #e5e7eb;
      padding-top:14px;
      margin-top:14px;
      font-size:18px;
      font-weight:800;
      color:#111827;
    }

    .place-order-btn{
      width:100%;
      background:var(--accent);
      color:#fff;
      border:none;
      min-height:50px;
      font-size:15px;
      font-weight:700;
      transition:0.3s ease;
    }

    .place-order-btn:hover{
      filter:brightness(0.92);
      color:#fff;
    }

    .place-order-btn:disabled{
      opacity:0.65;
      cursor:not-allowed;
    }

    .secondary-checkout-btn{
      width:100%;
      background:#fff;
      color:#111827;
      border:1px solid #d1d5db;
      min-height:46px;
      font-size:14px;
      font-weight:700;
      text-decoration:none;
      display:flex;
      align-items:center;
      justify-content:center;
    }

    .secondary-checkout-btn:hover{
      color:#111827;
      background:#f9fafb;
    }

    .support-note{
      margin-top:18px;
      padding:14px;
      background:#f8f9fa;
      border:1px solid #e5e7eb;
      font-size:13px;
      color:#6b7280;
      line-height:1.7;
    }

    .empty-cart-box{
      text-align:center;
      padding:24px 10px 8px;
    }

    .empty-cart-box i{
      font-size:40px;
      color:#cbd5e1;
      display:block;
      margin-bottom:10px;
    }

    .empty-cart-box h6{
      font-size:18px;
      font-weight:700;
      color:#111827;
      margin-bottom:8px;
    }

    .empty-cart-box p{
      margin:0;
      font-size:13px;
      color:#6b7280;
      line-height:1.7;
    }

    @media (max-width: 991.98px){
      .summary-card{
        position:static;
      }

      .checkout-hero h1{
        font-size:32px;
      }
    }
  </style>

  <?php if ($customCss !== ''): ?>
    <style><?php echo $customCss; ?></style>
  <?php endif; ?>
</head>
<body>

<?php
$topStripFile  = __DIR__ . '/includes/top-strip.php';
$navFile       = __DIR__ . '/includes/nav.php';
$mobileNavFile = __DIR__ . '/includes/mobile-nav.php';
$footerFile    = __DIR__ . '/includes/footer.php';

if (file_exists($topStripFile)) include $topStripFile;
if (file_exists($navFile)) include $navFile;
if (file_exists($mobileNavFile)) include $mobileNavFile;
?>

<section class="checkout-hero">
  <div class="container-fluid px-lg-5 px-3">
    <h1>Checkout</h1>
    <p>Complete the order with correct details. Bad input creates delivery problems, payment problems, and support problems.</p>
  </div>
</section>

<section class="breadcrumb-wrap">
  <div class="container-fluid px-lg-5 px-3">
    <nav aria-label="breadcrumb">
      <ol class="breadcrumb breadcrumb-custom">
        <li class="breadcrumb-item"><a href="<?php echo sf_h($storeUrl); ?>">Home</a></li>
        <li class="breadcrumb-item"><a href="<?php echo sf_h($storePageUrl); ?>">Shop</a></li>
        <li class="breadcrumb-item active" aria-current="page">Checkout</li>
      </ol>
    </nav>
  </div>
</section>

<section class="section-space">
  <div class="container-fluid px-lg-5 px-3">
    <div class="row g-4">
      <div class="col-lg-8">
        <div class="checkout-card">
          <h3>Billing & Shipping Details</h3>
          <p class="section-subtitle">
            <?php echo $loggedInCustomerId > 0 ? 'Your saved customer details are loaded below.' : 'Fill your order details below.'; ?>
          </p>

          <?php if ($formError !== ''): ?>
            <div class="alert alert-danger"><?php echo sf_h($formError); ?></div>
          <?php endif; ?>

          <form action="" method="post" id="checkoutForm">
            <input type="hidden" name="cart_json" id="cart_json" value="">
            <input type="hidden" name="cart_total" id="cart_total" value="0">

            <div class="checkout-block">
              <h5>Customer Information</h5>
              <div class="row g-3">
                <div class="col-md-6">
                  <label class="form-label" for="full_name">Full Name</label>
                  <input type="text" class="form-control" id="full_name" name="full_name" placeholder="Enter full name" required value="<?php echo sf_h($formData['full_name']); ?>">
                </div>

                <div class="col-md-6">
                  <label class="form-label" for="phone">Phone Number</label>
                  <input type="text" class="form-control" id="phone" name="phone" placeholder="Enter phone number" required value="<?php echo sf_h($formData['phone']); ?>">
                </div>

                <div class="col-md-6">
                  <label class="form-label" for="email">Email Address</label>
                  <input type="email" class="form-control" id="email" name="email" placeholder="Enter email address" value="<?php echo sf_h($formData['email']); ?>">
                </div>

                <div class="col-md-6">
                  <label class="form-label" for="alt_phone">Alternate Phone</label>
                  <input type="text" class="form-control" id="alt_phone" name="alt_phone" placeholder="Optional" value="<?php echo sf_h($formData['alt_phone']); ?>">
                </div>
              </div>
            </div>

            <div class="checkout-block">
              <h5>Delivery Address</h5>
              <div class="row g-3">
                <div class="col-12">
                  <label class="form-label" for="address_line">Address</label>
                  <textarea class="form-control" id="address_line" name="address_line" placeholder="House / street / area" required><?php echo sf_h($formData['address_line']); ?></textarea>
                </div>

                <div class="col-md-4">
                  <label class="form-label" for="city">City</label>
                  <input type="text" class="form-control" id="city" name="city" placeholder="City" required value="<?php echo sf_h($formData['city']); ?>">
                </div>

                <div class="col-md-4">
                  <label class="form-label" for="state">State</label>
                  <input type="text" class="form-control" id="state" name="state" placeholder="State" required value="<?php echo sf_h($formData['state']); ?>">
                </div>

                <div class="col-md-4">
                  <label class="form-label" for="pincode">Pincode</label>
                  <input type="text" class="form-control" id="pincode" name="pincode" placeholder="Pincode" required value="<?php echo sf_h($formData['pincode']); ?>">
                </div>

                <div class="col-12">
                  <label class="form-label" for="landmark">Landmark</label>
                  <input type="text" class="form-control" id="landmark" name="landmark" placeholder="Nearby landmark" value="<?php echo sf_h($formData['landmark']); ?>">
                </div>
              </div>
            </div>

            <div class="checkout-block">
              <h5>Payment Method</h5>

              <div class="payment-option">
                <input class="form-check-input" type="radio" name="payment_method" id="payment_cod" value="cod" checked>
                <label for="payment_cod">Cash on Delivery</label>
              </div>
            </div>

            <div class="checkout-block">
              <h5>Order Note</h5>
              <div class="row g-3">
                <div class="col-12">
                  <label class="form-label" for="order_note">Additional Instructions</label>
                  <textarea class="form-control" id="order_note" name="order_note" placeholder="Write useful delivery or order instructions only"><?php echo sf_h($formData['order_note']); ?></textarea>
                </div>
              </div>
            </div>

            <div class="d-flex flex-column flex-md-row gap-3">
              <a href="<?php echo sf_h($cartPageUrl); ?>" class="secondary-checkout-btn">Back to Cart</a>
              <button type="submit" class="place-order-btn" id="placeOrderBtn">Place Order</button>
            </div>
          </form>
        </div>
      </div>

      <div class="col-lg-4">
        <div class="summary-card">
          <h3>Order Summary</h3>
          <p class="section-subtitle">
            You can increase quantity, decrease quantity, and remove items here.
          </p>

          <div id="checkoutOrderItems"></div>

          <div class="mt-4" id="checkoutSummaryTotals">
            <div class="summary-row">
              <span>Subtotal</span>
              <strong id="checkoutSubtotal"><?php echo sf_h(sf_money(0, $currencySymbol)); ?></strong>
            </div>
            <div class="summary-row">
              <span>Delivery</span>
              <strong id="checkoutDelivery"><?php echo sf_h(sf_money(0, $currencySymbol)); ?></strong>
            </div>
            <div class="summary-row">
              <span>Discount</span>
              <strong id="checkoutDiscount"><?php echo sf_h(sf_money(0, $currencySymbol)); ?></strong>
            </div>
            <div class="summary-row total">
              <span>Total</span>
              <span id="checkoutGrandTotal"><?php echo sf_h(sf_money(0, $currencySymbol)); ?></span>
            </div>
          </div>

          <div class="support-note">
            <?php if ($supportPhone !== ''): ?>
              <div><strong>Phone:</strong> <?php echo sf_h($supportPhone); ?></div>
            <?php endif; ?>
            <?php if ($supportEmail !== ''): ?>
              <div><strong>Email:</strong> <?php echo sf_h($supportEmail); ?></div>
            <?php endif; ?>
            <?php if ($whatsappNumber !== ''): ?>
              <div><strong>WhatsApp:</strong> <?php echo sf_h($whatsappNumber); ?></div>
            <?php endif; ?>
            <?php if ($storeAddress !== ''): ?>
              <div class="mt-2"><strong>Store Address:</strong> <?php echo sf_h($storeAddress); ?></div>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>

<?php if (file_exists($footerFile)) include $footerFile; ?>

<div class="offcanvas offcanvas-end" tabindex="-1" id="sideCart" aria-labelledby="sideCartLabel">
  <div class="offcanvas-header cart-offcanvas-header">
    <h5 class="cart-offcanvas-title" id="sideCartLabel">Shopping Cart</h5>
    <button type="button" class="btn-close shadow-none" data-bs-dismiss="offcanvas" aria-label="Close"></button>
  </div>

  <div class="offcanvas-body d-flex flex-column">
    <div id="cartContent" class="flex-grow-1"></div>

    <div class="cart-summary">
      <div class="cart-summary-row">
        <span>Subtotal</span>
        <strong id="cartSubtotal"><?php echo sf_h($currencySymbol); ?>0.00</strong>
      </div>
      <div class="d-grid gap-2">
        <a href="<?php echo sf_h($storeUrl . '&page=checkout'); ?>" class="checkout-btn text-center text-decoration-none">Proceed to Checkout</a>
        <button class="continue-btn" data-bs-dismiss="offcanvas" type="button">Continue Shopping</button>
      </div>
    </div>
  </div>
</div>

<script>
window.STORE_DATA = {
  slug: <?php echo json_encode($slug); ?>,
  baseUrl: <?php echo json_encode($storeUrl); ?>,
  storeUrl: <?php echo json_encode($storePageUrl); ?>,
  contactUrl: <?php echo json_encode($contactPageUrl); ?>,
  currency: <?php echo json_encode($currencySymbol); ?>,
  phone: <?php echo json_encode($supportPhone); ?>,
  whatsapp: <?php echo json_encode($whatsappNumber); ?>
};
</script>

<script>
(function () {
  const CART_STORAGE_KEY = 'electromart_cart';
  const currency = window.STORE_DATA?.currency || '₹';

  const orderItemsEl = document.getElementById('checkoutOrderItems');
  const subtotalEl = document.getElementById('checkoutSubtotal');
  const deliveryEl = document.getElementById('checkoutDelivery');
  const discountEl = document.getElementById('checkoutDiscount');
  const grandTotalEl = document.getElementById('checkoutGrandTotal');
  const cartJsonEl = document.getElementById('cart_json');
  const cartTotalEl = document.getElementById('cart_total');
  const placeOrderBtn = document.getElementById('placeOrderBtn');
  const checkoutForm = document.getElementById('checkoutForm');

  let checkoutCart = [];

  function formatMoney(value) {
    const num = Number(value || 0);
    return currency + num.toLocaleString('en-IN', {
      minimumFractionDigits: 2,
      maximumFractionDigits: 2
    });
  }

  function escapeHtml(str) {
    return String(str ?? '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  function loadCart() {
    try {
      const storedCart = localStorage.getItem(CART_STORAGE_KEY);
      if (!storedCart) return [];
      const parsedCart = JSON.parse(storedCart);
      return Array.isArray(parsedCart) ? parsedCart : [];
    } catch (error) {
      console.error('Failed to load cart from localStorage:', error);
      return [];
    }
  }

  function saveCart() {
    try {
      localStorage.setItem(CART_STORAGE_KEY, JSON.stringify(checkoutCart));
    } catch (error) {
      console.error('Failed to save cart to localStorage:', error);
    }
  }

  function getTotals() {
    let subtotal = 0;
    let totalQty = 0;

    checkoutCart.forEach(item => {
      const qty = Number(item.qty || 0);
      const price = Number(item.price || 0);
      subtotal += qty * price;
      totalQty += qty;
    });

    const delivery = 0;
    const discount = 0;
    const grandTotal = subtotal + delivery - discount;

    return { subtotal, totalQty, delivery, discount, grandTotal };
  }

  function updateSummary() {
    const totals = getTotals();

    subtotalEl.textContent = formatMoney(totals.subtotal);
    deliveryEl.textContent = formatMoney(totals.delivery);
    discountEl.textContent = formatMoney(totals.discount);
    grandTotalEl.textContent = formatMoney(totals.grandTotal);

    cartJsonEl.value = JSON.stringify(checkoutCart);
    cartTotalEl.value = totals.grandTotal;

    if (placeOrderBtn) {
      placeOrderBtn.disabled = checkoutCart.length === 0;
    }
  }

  function renderCheckoutItems() {
    if (!orderItemsEl) return;

    if (checkoutCart.length === 0) {
      orderItemsEl.innerHTML = `
        <div class="empty-cart-box">
          <i class="bi bi-cart-x"></i>
          <h6>Your cart is empty</h6>
          <p>Add products before continuing to checkout.</p>
        </div>
      `;
      updateSummary();
      return;
    }

    let html = '';

    checkoutCart.forEach((item, index) => {
      const name = item.name || 'Product';
      const image = item.image || 'https://via.placeholder.com/120x120?text=Item';
      const price = Number(item.price || 0);
      const qty = Number(item.qty || 1);
      const lineTotal = price * qty;

      html += `
        <div class="order-item">
          <img src="${escapeHtml(image)}" alt="${escapeHtml(name)}">
          <div class="order-item-details">
            <h6>${escapeHtml(name)}</h6>
            <p>Unit Price: ${formatMoney(price)}</p>
            <div class="order-item-price">Line Total: ${formatMoney(lineTotal)}</div>

            <div class="qty-controls">
              <button type="button" class="qty-btn" onclick="decreaseCheckoutQty(${index})">−</button>
              <span class="qty-value">${qty}</span>
              <button type="button" class="qty-btn" onclick="increaseCheckoutQty(${index})">+</button>
              <button type="button" class="remove-item-btn" onclick="removeCheckoutItem(${index})">Remove</button>
            </div>
          </div>
        </div>
      `;
    });

    orderItemsEl.innerHTML = html;
    updateSummary();
  }

  window.increaseCheckoutQty = function (index) {
    if (!checkoutCart[index]) return;
    checkoutCart[index].qty = Number(checkoutCart[index].qty || 1) + 1;
    saveCart();
    renderCheckoutItems();
    syncSideCart();
  };

  window.decreaseCheckoutQty = function (index) {
    if (!checkoutCart[index]) return;

    checkoutCart[index].qty = Number(checkoutCart[index].qty || 1) - 1;

    if (checkoutCart[index].qty <= 0) {
      checkoutCart.splice(index, 1);
    }

    saveCart();
    renderCheckoutItems();
    syncSideCart();
  };

  window.removeCheckoutItem = function (index) {
    if (!checkoutCart[index]) return;
    checkoutCart.splice(index, 1);
    saveCart();
    renderCheckoutItems();
    syncSideCart();
  };

  function syncSideCart() {
    if (typeof cart !== 'undefined') {
      cart = loadCart();
    }

    if (typeof renderCart === 'function') {
      renderCart();
    }
  }

  checkoutForm.addEventListener('submit', function (e) {
    if (checkoutCart.length === 0) {
      e.preventDefault();
      alert('Your cart is empty.');
      return;
    }

    cartJsonEl.value = JSON.stringify(checkoutCart);
    cartTotalEl.value = getTotals().grandTotal;
  });

  checkoutCart = loadCart();
  renderCheckoutItems();
})();
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="online-store/assets/menu.js"></script>
<script src="online-store/assets/cart.js"></script>
<script src="online-store/assets/main.js"></script>
</body>
</html>