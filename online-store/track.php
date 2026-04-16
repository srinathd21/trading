<?php
if (!defined('STORE_FRONT')) {
    die('Direct access not allowed.');
}

/* =========================
   SAFE FALLBACKS
========================= */
$slug            = $slug ?? '';
$displayName     = $displayName ?? 'Online Store';
$metaTitle       = $metaTitle ?? ('Track Order | ' . $displayName);
$metaDescription = $metaDescription ?? ('Track your order at ' . $displayName);
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

if (!function_exists('track_status_label')) {
    function track_status_label(string $status): string {
        $status = strtolower(trim($status));

        $map = [
            'pending' => 'Pending',
            'confirmed' => 'Confirmed',
            'processing' => 'Processing',
            'packed' => 'Packed',
            'shipped' => 'Shipped',
            'out_for_delivery' => 'Out for Delivery',
            'delivered' => 'Delivered',
            'cancelled' => 'Cancelled',
            'returned' => 'Returned',
            'failed' => 'Failed',
        ];

        return $map[$status] ?? ucwords(str_replace('_', ' ', $status));
    }
}

if (!function_exists('track_payment_label')) {
    function track_payment_label(string $method): string {
        $method = strtolower(trim($method));

        if ($method === 'upi') return 'UPI / Online Payment';
        if ($method === 'bank') return 'Bank Transfer';
        return 'Cash on Delivery';
    }
}

if (!function_exists('track_step_active')) {
    function track_step_active(string $currentStatus, array $allowed): bool {
        return in_array(strtolower(trim($currentStatus)), $allowed, true);
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
   INPUT
========================= */
$orderId = isset($_GET['order_id']) ? (int)$_GET['order_id'] : 0;

/* =========================
   LOAD ORDER
========================= */
$order = null;
$orderItems = [];
$orderNotFound = false;

if ($orderId > 0) {
    $stmt = $pdo->prepare("
        SELECT *
        FROM online_store_orders
        WHERE id = :id
          AND store_slug = :store_slug
          AND business_id = :business_id
        LIMIT 1
    ");
    $stmt->execute([
        ':id' => $orderId,
        ':store_slug' => $slug,
        ':business_id' => $businessId
    ]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($order) {
        $itemStmt = $pdo->prepare("
            SELECT *
            FROM online_store_order_items
            WHERE order_id = :order_id
            ORDER BY id ASC
        ");
        $itemStmt->execute([':order_id' => $orderId]);
        $orderItems = $itemStmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $orderNotFound = true;
    }
}

/* =========================
   ORDER DATA
========================= */
$orderNumber    = $order['order_number'] ?? '';
$orderDate      = !empty($order['created_at']) ? date('d M Y, h:i A', strtotime($order['created_at'])) : '';
$customerName   = $order['customer_name'] ?? '';
$phone          = $order['phone'] ?? '';
$email          = $order['email'] ?? '';
$addressLine    = $order['address_line'] ?? '';
$city           = $order['city'] ?? '';
$state          = $order['state'] ?? '';
$pincode        = $order['pincode'] ?? '';
$landmark       = $order['landmark'] ?? '';
$orderStatus    = strtolower((string)($order['order_status'] ?? 'pending'));
$paymentStatus  = strtolower((string)($order['payment_status'] ?? 'pending'));
$paymentMethod  = strtolower((string)($order['payment_method'] ?? 'cod'));
$subtotal       = (float)($order['subtotal'] ?? 0);
$deliveryCharge = (float)($order['delivery_charge'] ?? 0);
$discountAmount = (float)($order['discount_amount'] ?? 0);
$grandTotal     = (float)($order['grand_total'] ?? 0);
$orderNote      = $order['order_note'] ?? '';

$statusLabel = track_status_label($orderStatus);
$paymentLabel = track_payment_label($paymentMethod);

/* =========================
   TRACK STEPS
========================= */
$isPending = track_step_active($orderStatus, [
    'pending','confirmed','processing','packed','shipped','out_for_delivery','delivered'
]);

$isConfirmed = track_step_active($orderStatus, [
    'confirmed','processing','packed','shipped','out_for_delivery','delivered'
]);

$isShipped = track_step_active($orderStatus, [
    'shipped','out_for_delivery','delivered'
]);

$isDelivered = track_step_active($orderStatus, [
    'delivered'
]);

$isCancelled = in_array($orderStatus, ['cancelled','returned','failed'], true);

$backToConfirmationUrl = $orderId > 0
    ? 'storefront.php?slug=' . urlencode($slug) . '&page=confirmation&order_id=' . $orderId
    : $storePageUrl;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title><?php echo sf_h('Track Order | ' . $displayName); ?></title>
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

    .track-hero{
      background: linear-gradient(rgba(17,24,39,0.88), rgba(17,24,39,0.78));
      color:#fff;
      padding:70px 0 50px;
      text-align:center;
    }

    .track-hero h1{
      font-size:40px;
      font-weight:800;
      margin-bottom:10px;
    }

    .track-hero p{
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

    .track-card,
    .summary-card{
      background:#fff;
      border:1px solid #e5e7eb;
      height:100%;
    }

    .track-card{
      padding:28px 24px;
    }

    .summary-card{
      padding:24px 22px;
      position:sticky;
      top:100px;
    }

    .track-card h3,
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

    .track-search-box{
      border:1px solid #e5e7eb;
      background:#f9fafb;
      padding:18px 16px;
      margin-bottom:22px;
    }

    .track-search-box .form-control{
      min-height:48px;
      border-radius:0;
      box-shadow:none !important;
    }

    .track-search-box .btn{
      min-height:48px;
      border-radius:0;
      font-weight:700;
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

    .status-badge{
      display:inline-flex;
      align-items:center;
      padding:6px 12px;
      border:1px solid #e5e7eb;
      background:#fff;
      font-size:13px;
      font-weight:700;
      color:#111827;
    }

    .status-badge.success{
      color:#198754;
      border-color:#cde7d8;
      background:#f1fbf5;
    }

    .status-badge.warning{
      color:#b58105;
      border-color:#f1e0a6;
      background:#fff9e8;
    }

    .status-badge.danger{
      color:#dc3545;
      border-color:#f2c7cd;
      background:#fff5f6;
    }

    .timeline{
      margin-top:24px;
      position:relative;
    }

    .timeline::before{
      content:'';
      position:absolute;
      left:18px;
      top:0;
      bottom:0;
      width:2px;
      background:#e5e7eb;
    }

    .timeline-step{
      position:relative;
      padding-left:54px;
      margin-bottom:26px;
    }

    .timeline-step:last-child{
      margin-bottom:0;
    }

    .timeline-dot{
      position:absolute;
      left:8px;
      top:2px;
      width:22px;
      height:22px;
      border-radius:50%;
      border:2px solid #cbd5e1;
      background:#fff;
      z-index:2;
    }

    .timeline-step.active .timeline-dot{
      border-color:var(--accent);
      background:var(--accent);
    }

    .timeline-step.cancelled .timeline-dot{
      border-color:#dc3545;
      background:#dc3545;
    }

    .timeline-step h6{
      font-size:16px;
      font-weight:700;
      color:#111827;
      margin-bottom:5px;
    }

    .timeline-step p{
      margin:0;
      color:#6b7280;
      font-size:14px;
      line-height:1.7;
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

      .track-hero h1{
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

<section class="track-hero">
  <div class="container-fluid px-lg-5 px-3">
    <h1>Track Order</h1>
    <p>Use your order ID to see the real order status stored in the database.</p>
  </div>
</section>

<section class="breadcrumb-wrap">
  <div class="container-fluid px-lg-5 px-3">
    <nav aria-label="breadcrumb">
      <ol class="breadcrumb breadcrumb-custom">
        <li class="breadcrumb-item"><a href="<?php echo sf_h($storeUrl); ?>">Home</a></li>
        <li class="breadcrumb-item"><a href="<?php echo sf_h($storePageUrl); ?>">Shop</a></li>
        <li class="breadcrumb-item active" aria-current="page">Track Order</li>
      </ol>
    </nav>
  </div>
</section>

<section class="section-space">
  <div class="container-fluid px-lg-5 px-3">
    <div class="row g-4">
      <div class="col-lg-8">
        <div class="track-card">
          <h3>Order Tracking</h3>
          <p class="section-subtitle">
            Enter a valid order ID. This page reads the actual saved order record.
          </p>

          <div class="track-search-box">
            <form method="get" action="storefront.php">
              <input type="hidden" name="slug" value="<?php echo sf_h($slug); ?>">
              <input type="hidden" name="page" value="track">

              <div class="row g-3">
                <div class="col-md-8">
                  <input
                    type="number"
                    min="1"
                    name="order_id"
                    class="form-control"
                    placeholder="Enter order ID"
                    value="<?php echo $orderId > 0 ? (int)$orderId : ''; ?>"
                    required
                  >
                </div>
                <div class="col-md-4">
                  <button type="submit" class="btn btn-dark w-100">Track Now</button>
                </div>
              </div>
            </form>
          </div>

          <?php if ($orderNotFound): ?>
            <div class="info-box">
              <h5>Order Not Found</h5>
              <div class="address-block">
                No matching order was found for this store and order ID.
              </div>
            </div>
          <?php elseif ($order): ?>
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
                <strong>
                  <span class="status-badge <?php echo $isCancelled ? 'danger' : ($orderStatus === 'delivered' ? 'success' : 'warning'); ?>">
                    <?php echo sf_h($statusLabel); ?>
                  </span>
                </strong>
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
                <span>Total</span>
                <strong><?php echo sf_h(sf_money($grandTotal, $currencySymbol)); ?></strong>
              </div>
            </div>

            <div class="info-box">
              <h5>Customer & Delivery Details</h5>
              <div class="info-row">
                <span>Customer</span>
                <strong><?php echo sf_h($customerName !== '' ? $customerName : '-'); ?></strong>
              </div>
              <div class="info-row">
                <span>Phone</span>
                <strong><?php echo sf_h($phone !== '' ? $phone : '-'); ?></strong>
              </div>
              <div class="info-row">
                <span>Email</span>
                <strong><?php echo sf_h($email !== '' ? $email : '-'); ?></strong>
              </div>
              <div class="address-block mt-2">
                <?php echo sf_h($addressLine !== '' ? $addressLine : '-'); ?><br>
                <?php echo sf_h($city); ?><?php echo $city !== '' ? ', ' : ''; ?><?php echo sf_h($state); ?><?php echo $state !== '' ? ' - ' : ''; ?><?php echo sf_h($pincode); ?><br>
                <?php if ($landmark !== ''): ?>
                  Landmark: <?php echo sf_h($landmark); ?>
                <?php endif; ?>
              </div>
            </div>

            <div class="info-box">
              <h5>Status Timeline</h5>

              <div class="timeline">
                <?php if ($isCancelled): ?>
                  <div class="timeline-step active">
                    <div class="timeline-dot"></div>
                    <h6>Order Received</h6>
                    <p>Your order was created successfully.</p>
                  </div>

                  <div class="timeline-step cancelled">
                    <div class="timeline-dot"></div>
                    <h6><?php echo sf_h($statusLabel); ?></h6>
                    <p>This order is currently marked as <?php echo sf_h(strtolower($statusLabel)); ?>.</p>
                  </div>
                <?php else: ?>
                  <div class="timeline-step <?php echo $isPending ? 'active' : ''; ?>">
                    <div class="timeline-dot"></div>
                    <h6>Order Received</h6>
                    <p>Your order has been placed and recorded.</p>
                  </div>

                  <div class="timeline-step <?php echo $isConfirmed ? 'active' : ''; ?>">
                    <div class="timeline-dot"></div>
                    <h6>Confirmed / Processing</h6>
                    <p>The store has started processing your order.</p>
                  </div>

                  <div class="timeline-step <?php echo $isShipped ? 'active' : ''; ?>">
                    <div class="timeline-dot"></div>
                    <h6>Shipped / Out for Delivery</h6>
                    <p>Your order is on the way.</p>
                  </div>

                  <div class="timeline-step <?php echo $isDelivered ? 'active' : ''; ?>">
                    <div class="timeline-dot"></div>
                    <h6>Delivered</h6>
                    <p>Your order has been delivered successfully.</p>
                  </div>
                <?php endif; ?>
              </div>
            </div>

            <?php if ($orderNote !== ''): ?>
              <div class="info-box">
                <h5>Order Note</h5>
                <div class="address-block"><?php echo nl2br(sf_h($orderNote)); ?></div>
              </div>
            <?php endif; ?>

            <div class="d-flex flex-column flex-md-row gap-3 mt-4">
              <a href="<?php echo sf_h($storePageUrl); ?>" class="action-btn secondary">Continue Shopping</a>
              <a href="<?php echo sf_h($backToConfirmationUrl); ?>" class="action-btn primary">View Confirmation</a>
            </div>
          <?php else: ?>
            <div class="info-box">
              <h5>Track Your Order</h5>
              <div class="address-block">
                Enter your order ID above to view its status.
              </div>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <div class="col-lg-4">
        <div class="summary-card">
          <h3>Order Summary</h3>
          <p class="section-subtitle">
            <?php echo $order ? 'These items are loaded from the database.' : 'Order items will appear after you search.'; ?>
          </p>

          <?php if ($order && !empty($orderItems)): ?>
            <div id="trackedOrderItems">
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
          <?php else: ?>
            <div class="info-box mb-0">
              <div class="address-block">No order loaded.</div>
            </div>
          <?php endif; ?>

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