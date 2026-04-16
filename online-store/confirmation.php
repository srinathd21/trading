<?php
if (!defined('STORE_FRONT')) {
    die('Direct access not allowed.');
}

/* =========================
   SAFE FALLBACKS
========================= */
$slug            = $slug ?? '';
$displayName     = $displayName ?? 'Online Store';
$metaTitle       = $metaTitle ?? ('Order Confirmation | ' . $displayName);
$metaDescription = $metaDescription ?? ('Your order has been placed successfully at ' . $displayName);
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
$businessId      = (int)($businessId ?? ($store['business_id'] ?? 0));

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

/* =========================
   LOAD ORDER FROM DB
========================= */
$orderId = isset($_GET['order_id']) ? (int)$_GET['order_id'] : 0;

if ($orderId <= 0) {
    die('Invalid order ID.');
}

$orderStmt = $pdo->prepare("
    SELECT *
    FROM online_store_orders
    WHERE id = :id
      AND store_slug = :store_slug
      AND business_id = :business_id
    LIMIT 1
");
$orderStmt->execute([
    ':id' => $orderId,
    ':store_slug' => $slug,
    ':business_id' => $businessId
]);
$order = $orderStmt->fetch(PDO::FETCH_ASSOC);

if (!$order) {
    die('Order not found.');
}

$itemStmt = $pdo->prepare("
    SELECT *
    FROM online_store_order_items
    WHERE order_id = :order_id
    ORDER BY id ASC
");
$itemStmt->execute([':order_id' => $orderId]);
$orderItems = $itemStmt->fetchAll(PDO::FETCH_ASSOC);

/* =========================
   MAP ORDER DATA
========================= */
$orderNumber   = (string)($order['order_number'] ?? ('ORD-' . $orderId));
$orderDate     = !empty($order['created_at']) ? date('d M Y, h:i A', strtotime($order['created_at'])) : date('d M Y, h:i A');
$fullName      = (string)($order['customer_name'] ?? '');
$phone         = (string)($order['phone'] ?? '');
$email         = (string)($order['email'] ?? '');
$altPhone      = (string)($order['alt_phone'] ?? '');
$addressLine   = (string)($order['address_line'] ?? '');
$city          = (string)($order['city'] ?? '');
$state         = (string)($order['state'] ?? '');
$pincode       = (string)($order['pincode'] ?? '');
$landmark      = (string)($order['landmark'] ?? '');
$paymentMethod = (string)($order['payment_method'] ?? 'cod');
$orderNote     = (string)($order['order_note'] ?? '');
$subtotal      = (float)($order['subtotal'] ?? 0);
$deliveryCharge= (float)($order['delivery_charge'] ?? 0);
$discountAmount= (float)($order['discount_amount'] ?? 0);
$grandTotal    = (float)($order['grand_total'] ?? 0);
$orderStatus   = (string)($order['order_status'] ?? 'pending');
$paymentStatus = (string)($order['payment_status'] ?? 'pending');

$paymentLabel = 'Cash on Delivery';
if ($paymentMethod === 'upi') {
    $paymentLabel = 'UPI / Online Payment';
} elseif ($paymentMethod === 'bank') {
    $paymentLabel = 'Bank Transfer';
}

$trackUrl = 'storefront.php?slug=' . urlencode($slug) . '&page=track&order_id=' . urlencode((string)$orderId);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title><?php echo sf_h('Order Confirmation | ' . $displayName); ?></title>
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

    .confirmation-hero{
      background: linear-gradient(rgba(17,24,39,0.88), rgba(17,24,39,0.78));
      color:#fff;
      padding:70px 0 50px;
      text-align:center;
    }

    .confirmation-hero .success-icon{
      width:84px;
      height:84px;
      border-radius:50%;
      background:rgba(255,255,255,0.12);
      display:flex;
      align-items:center;
      justify-content:center;
      font-size:36px;
      margin:0 auto 18px;
    }

    .confirmation-hero h1{
      font-size:40px;
      font-weight:800;
      margin-bottom:10px;
    }

    .confirmation-hero p{
      margin:0 auto;
      max-width:760px;
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

    .confirm-card,
    .summary-card{
      background:#fff;
      border:1px solid #e5e7eb;
      height:100%;
    }

    .confirm-card{
      padding:28px 24px;
    }

    .summary-card{
      padding:24px 22px;
      position:sticky;
      top:100px;
    }

    .confirm-card h3,
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

    .info-box{
      border:1px solid #e5e7eb;
      background:#f9fafb;
      padding:18px 16px;
      margin-bottom:18px;
    }

    .info-box h5{
      font-size:17px;
      font-weight:700;
      color:#111827;
      margin-bottom:12px;
    }

    .info-row{
      display:flex;
      justify-content:space-between;
      gap:12px;
      font-size:14px;
      color:#4b5563;
      margin-bottom:10px;
      flex-wrap:wrap;
    }

    .info-row:last-child{
      margin-bottom:0;
    }

    .info-row strong{
      color:#111827;
    }

    .address-block{
      font-size:14px;
      color:#4b5563;
      line-height:1.8;
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

    .action-btn{
      width:100%;
      min-height:48px;
      font-size:15px;
      font-weight:700;
      display:flex;
      align-items:center;
      justify-content:center;
      text-decoration:none;
      border:none;
    }

    .action-btn.primary{
      background:var(--accent);
      color:#fff;
    }

    .action-btn.primary:hover{
      filter:brightness(0.92);
      color:#fff;
    }

    .action-btn.secondary{
      background:#fff;
      color:#111827;
      border:1px solid #d1d5db;
    }

    .action-btn.secondary:hover{
      background:#f9fafb;
      color:#111827;
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

    @media (max-width: 991.98px){
      .summary-card{
        position:static;
      }

      .confirmation-hero h1{
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

<section class="confirmation-hero">
  <div class="container-fluid px-lg-5 px-3">
    <div class="success-icon">
      <i class="bi bi-check2-circle"></i>
    </div>
    <h1>Order Placed Successfully</h1>
    <p>Your order is stored in the database. This page is reading the actual saved order, not fake GET data.</p>
  </div>
</section>

<section class="breadcrumb-wrap">
  <div class="container-fluid px-lg-5 px-3">
    <nav aria-label="breadcrumb">
      <ol class="breadcrumb breadcrumb-custom">
        <li class="breadcrumb-item"><a href="<?php echo sf_h($storeUrl); ?>">Home</a></li>
        <li class="breadcrumb-item"><a href="<?php echo sf_h($storePageUrl); ?>">Shop</a></li>
        <li class="breadcrumb-item"><a href="<?php echo sf_h('storefront.php?slug=' . urlencode($slug) . '&page=checkout'); ?>">Checkout</a></li>
        <li class="breadcrumb-item active" aria-current="page">Confirmation</li>
      </ol>
    </nav>
  </div>
</section>

<section class="section-space">
  <div class="container-fluid px-lg-5 px-3">
    <div class="row g-4">
      <div class="col-lg-8">
        <div class="confirm-card">
          <h3>Order Details</h3>
          <p class="section-subtitle">
            This confirmation is loaded from the database.
          </p>

          <div class="info-box">
            <h5>Order Information</h5>
            <div class="info-row">
              <span>Order Number</span>
              <strong><?php echo sf_h($orderNumber); ?></strong>
            </div>
            <div class="info-row">
              <span>Order Date</span>
              <strong><?php echo sf_h($orderDate); ?></strong>
            </div>
            <div class="info-row">
              <span>Order Status</span>
              <strong><?php echo sf_h(ucwords(str_replace('_', ' ', $orderStatus))); ?></strong>
            </div>
            <div class="info-row">
              <span>Payment Status</span>
              <strong><?php echo sf_h(ucwords(str_replace('_', ' ', $paymentStatus))); ?></strong>
            </div>
            <div class="info-row">
              <span>Payment Method</span>
              <strong><?php echo sf_h($paymentLabel); ?></strong>
            </div>
            <div class="info-row">
              <span>Total Amount</span>
              <strong><?php echo sf_h(sf_money($grandTotal, $currencySymbol)); ?></strong>
            </div>
          </div>

          <div class="info-box">
            <h5>Customer Information</h5>
            <div class="info-row">
              <span>Full Name</span>
              <strong><?php echo sf_h($fullName !== '' ? $fullName : '-'); ?></strong>
            </div>
            <div class="info-row">
              <span>Phone</span>
              <strong><?php echo sf_h($phone !== '' ? $phone : '-'); ?></strong>
            </div>
            <div class="info-row">
              <span>Email</span>
              <strong><?php echo sf_h($email !== '' ? $email : '-'); ?></strong>
            </div>
            <div class="info-row">
              <span>Alternate Phone</span>
              <strong><?php echo sf_h($altPhone !== '' ? $altPhone : '-'); ?></strong>
            </div>
          </div>

          <div class="info-box">
            <h5>Delivery Address</h5>
            <div class="address-block">
              <?php echo sf_h($addressLine !== '' ? $addressLine : '-'); ?><br>
              <?php echo sf_h($city); ?><?php echo $city !== '' ? ', ' : ''; ?><?php echo sf_h($state); ?><?php echo $state !== '' ? ' - ' : ''; ?><?php echo sf_h($pincode); ?><br>
              <?php if ($landmark !== ''): ?>
                Landmark: <?php echo sf_h($landmark); ?><br>
              <?php endif; ?>
            </div>
          </div>

          <?php if ($orderNote !== ''): ?>
          <div class="info-box">
            <h5>Order Note</h5>
            <div class="address-block">
              <?php echo nl2br(sf_h($orderNote)); ?>
            </div>
          </div>
          <?php endif; ?>

          <div class="d-flex flex-column flex-md-row gap-3 mt-4">
            <a href="<?php echo sf_h($storePageUrl); ?>" class="action-btn secondary">Continue Shopping</a>
            <a href="<?php echo sf_h($trackUrl); ?>" class="action-btn primary">Track Order</a>
          </div>
        </div>
      </div>

      <div class="col-lg-4">
        <div class="summary-card">
          <h3>Ordered Items</h3>
          <p class="section-subtitle">
            These items are loaded from `online_store_order_items`.
          </p>

          <div id="confirmedOrderItems">
            <?php if (!empty($orderItems)): ?>
              <?php foreach ($orderItems as $item): ?>
                <?php
                  $itemImage = sf_normalize_image_path(
                      $item['product_image'] ?? '',
                      'https://via.placeholder.com/120x120?text=Item'
                  );
                ?>
                <div class="order-item">
                  <img src="<?php echo sf_h($itemImage); ?>" alt="<?php echo sf_h($item['product_name'] ?? 'Item'); ?>">
                  <div class="order-item-details">
                    <h6><?php echo sf_h($item['product_name'] ?? 'Item'); ?></h6>
                    <p>Unit Price: <?php echo sf_h(sf_money((float)($item['unit_price'] ?? 0), $currencySymbol)); ?></p>
                    <p>Quantity: <?php echo (int)($item['quantity'] ?? 0); ?></p>
                    <div class="order-item-price">
                      Line Total: <?php echo sf_h(sf_money((float)($item['line_total'] ?? 0), $currencySymbol)); ?>
                    </div>
                  </div>
                </div>
              <?php endforeach; ?>
            <?php else: ?>
              <div class="info-box mb-0">
                <div class="address-block">No order items found.</div>
              </div>
            <?php endif; ?>
          </div>

          <div class="mt-4">
            <div class="summary-row">
              <span>Subtotal</span>
              <strong><?php echo sf_h(sf_money($subtotal, $currencySymbol)); ?></strong>
            </div>
            <div class="summary-row">
              <span>Delivery</span>
              <strong><?php echo sf_h(sf_money($deliveryCharge, $currencySymbol)); ?></strong>
            </div>
            <div class="summary-row">
              <span>Discount</span>
              <strong><?php echo sf_h(sf_money($discountAmount, $currencySymbol)); ?></strong>
            </div>
            <div class="summary-row total">
              <span>Total</span>
              <span><?php echo sf_h(sf_money($grandTotal, $currencySymbol)); ?></span>
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

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="online-store/assets/menu.js"></script>
<script src="online-store/assets/cart.js"></script>
<script src="online-store/assets/main.js"></script>
</body>
</html>