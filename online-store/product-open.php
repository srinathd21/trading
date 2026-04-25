<?php
if (!defined('STORE_FRONT')) {
    die('Direct access not allowed.');
}

/* =========================
   SAFE FALLBACKS
========================= */
$slug            = $slug ?? '';
$displayName     = $displayName ?? 'Online Store';
$metaTitle       = $metaTitle ?? ('Product | ' . $displayName);
$metaDescription = $metaDescription ?? ('View product details from ' . $displayName);
$themeColor      = $themeColor ?? '#e11d48';
$secondaryColor  = $secondaryColor ?? '#111827';
$logoUrl         = $logoUrl ?? 'https://via.placeholder.com/200x80?text=Logo';
$faviconUrl      = $faviconUrl ?? $logoUrl;
$currencySymbol  = $currencySymbol ?? '₹';
$storeUrl        = $storeUrl ?? ('storefront.php?slug=' . urlencode($slug) . '&page=index');
$storePageUrl    = $storePageUrl ?? ('storefront.php?slug=' . urlencode($slug) . '&page=store');
$categoryPageUrl = $categoryPageUrl ?? ('storefront.php?slug=' . urlencode($slug) . '&page=categories');
$contactPageUrl  = $contactPageUrl ?? ('storefront.php?slug=' . urlencode($slug) . '&page=contact');
$categories      = is_array($categories ?? null) ? $categories : [];
$products        = is_array($products ?? null) ? $products : [];
$customCss       = $customCss ?? '';
$supportPhone    = $supportPhone ?? '';
$supportEmail    = $supportEmail ?? '';
$whatsappNumber  = $whatsappNumber ?? '';
$storeAddress    = $storeAddress ?? '';

$currentProductId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

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
        return $symbol . number_format((float)$amount, 0, '.', ',');
    }
}

if (!function_exists('sf_trim_text')) {
    function sf_trim_text($text, int $limit = 100): string {
        $text = trim(strip_tags((string)($text ?? '')));
        if ($text === '') {
            return '';
        }
        if (mb_strlen($text) <= $limit) {
            return $text;
        }
        return rtrim(mb_substr($text, 0, $limit - 3)) . '...';
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

if (!function_exists('store_product_open_url')) {
    function store_product_open_url(int $productId): string {
        global $slug;
        return 'storefront.php?slug=' . urlencode((string)$slug) . '&page=product-open&id=' . $productId;
    }
}

if (!function_exists('store_category_open_url')) {
    function store_category_open_url(int $categoryId): string {
        global $slug;
        return 'storefront.php?slug=' . urlencode((string)$slug) . '&page=category-open&id=' . $categoryId;
    }
}

/* =========================
   FIND PRODUCT
========================= */
$currentProduct = null;
foreach ($products as $item) {
    if ((int)($item['id'] ?? 0) === $currentProductId) {
        $currentProduct = $item;
        break;
    }
}

if (!$currentProduct) {
    echo '<div style="padding:40px;text-align:center;font-family:Arial,sans-serif;">Product not found.</div>';
    return;
}

$productId          = (int)($currentProduct['id'] ?? 0);
$productName        = (string)($currentProduct['product_name'] ?? 'Product');
$productDescription = (string)($currentProduct['description'] ?? 'No description available.');
$productImage       = sf_normalize_image_path($currentProduct['image_path'] ?? '', 'https://via.placeholder.com/700x700?text=Product');
$productPrice       = (float)($currentProduct['retail_price'] ?? 0);
$productMrp         = (float)($currentProduct['mrp'] ?? 0);
$productCode        = (string)($currentProduct['product_code'] ?? '');
$productCategoryId  = (int)($currentProduct['category_id'] ?? 0);
$productCategory    = (string)($currentProduct['category_name'] ?? 'Category');
$productSubCategory = (string)($currentProduct['subcategory_name'] ?? '');
$productUnit        = (string)($currentProduct['unit_of_measure'] ?? '');
$productStock       = isset($currentProduct['total_stock']) ? (float)$currentProduct['total_stock'] : null;
$productHsn         = (string)($currentProduct['hsn_code'] ?? '');

$pageTitleFinal = $productName . ' | ' . $displayName;
$pageDescriptionFinal = sf_trim_text($productDescription, 160);

/* =========================
   RELATED PRODUCTS
========================= */
$relatedProducts = array_values(array_filter($products, function ($item) use ($productId, $productCategoryId) {
    return (int)($item['id'] ?? 0) !== $productId
        && (int)($item['category_id'] ?? 0) === $productCategoryId;
}));

usort($relatedProducts, function ($a, $b) {
    return (int)($b['id'] ?? 0) <=> (int)($a['id'] ?? 0);
});

$relatedProducts = array_slice($relatedProducts, 0, 4);

/* =========================
   INCLUDE VARIABLES
========================= */
$storefront_slug             = $slug;
$storefront_display_name     = $displayName;
$storefront_logo             = $logoUrl;
$storefront_phone            = $supportPhone;
$storefront_email            = $supportEmail;
$storefront_whatsapp         = $whatsappNumber;
$storefront_address          = $storeAddress;
$storefront_currency         = $currencySymbol;
$storefront_store_url        = $storeUrl;
$storefront_store_page_url   = $storePageUrl;
$storefront_contact_page_url = $contactPageUrl;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title><?php echo sf_h($pageTitleFinal); ?></title>
  <meta name="description" content="<?php echo sf_h($pageDescriptionFinal); ?>">
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

    .product-page-wrap{
      padding: 50px 0 80px;
      background:#fff;
    }

    .product-gallery-card,
    .product-info-card,
    .product-desc-card{
      border:1px solid #e5e7eb;
      background:#fff;
    }

    .product-gallery-card{
      overflow:hidden;
      position:relative;
    }

    .product-gallery-card img{
      width:100%;
      height:560px;
      object-fit:cover;
      display:block;
      background:#f8f9fa;
    }

    .product-info-card{
      padding:28px;
      height:100%;
    }

    .product-breadcrumb{
      font-size:14px;
      color:#6b7280;
      margin-bottom:12px;
    }

    .product-breadcrumb a{
      color:#6b7280;
      text-decoration:none;
    }

    .product-breadcrumb a:hover{
      color:var(--accent);
    }

    .product-title-main{
      font-size:34px;
      font-weight:800;
      color:#111827;
      margin-bottom:14px;
      line-height:1.2;
    }

    .product-meta-line{
      display:flex;
      flex-wrap:wrap;
      gap:10px 18px;
      margin-bottom:18px;
      font-size:14px;
      color:#6b7280;
    }

    .product-price-main{
      font-size:34px;
      font-weight:800;
      color:#111827;
      margin-bottom:6px;
    }

    .product-price-main .old-price{
      font-size:18px;
      color:#9ca3af;
      text-decoration:line-through;
      margin-left:10px;
      font-weight:600;
    }

    .product-save-text{
      color:#16a34a;
      font-size:14px;
      font-weight:700;
      margin-bottom:18px;
    }

    .product-short-desc{
      font-size:15px;
      line-height:1.8;
      color:#4b5563;
      margin-bottom:24px;
    }

    .product-feature-list{
      margin:0 0 24px;
      padding:0;
      list-style:none;
    }

    .product-feature-list li{
      display:flex;
      align-items:flex-start;
      gap:10px;
      color:#374151;
      font-size:14px;
      margin-bottom:10px;
    }

    .product-feature-list i{
      color:var(--accent);
      margin-top:2px;
    }

    .product-qty-box{
      display:flex;
      align-items:center;
      gap:12px;
      margin-bottom:20px;
    }

    .qty-control{
      display:flex;
      align-items:center;
      border:1px solid #d1d5db;
      width:max-content;
    }

    .qty-control button{
      width:42px;
      height:42px;
      border:none;
      background:#fff;
      font-size:18px;
      font-weight:700;
    }

    .qty-control input{
      width:56px;
      height:42px;
      border:none;
      border-left:1px solid #d1d5db;
      border-right:1px solid #d1d5db;
      text-align:center;
      outline:none;
    }

    .product-action-row{
      display:flex;
      flex-wrap:wrap;
      gap:12px;
      margin-bottom:20px;
    }

    .btn-buy-now,
    .btn-add-cart,
    .btn-wishlist{
      min-height:48px;
      padding:12px 24px;
      font-weight:700;
      border:none;
      text-decoration:none;
      display:inline-flex;
      align-items:center;
      justify-content:center;
      gap:8px;
    }

    .btn-add-cart{
      background:var(--accent);
      color:#fff;
    }

    .btn-add-cart:hover{
      color:#fff;
      filter:brightness(0.94);
    }

    .btn-buy-now{
      background:#111827;
      color:#fff;
    }

    .btn-buy-now:hover{
      color:#fff;
      filter:brightness(1.05);
    }

    .btn-wishlist{
      background:#fff;
      color:#111827;
      border:1px solid #d1d5db;
    }

    .product-support-strip{
      display:grid;
      grid-template-columns:repeat(2,1fr);
      gap:12px;
      margin-top:10px;
    }

    .support-mini-box{
      border:1px solid #e5e7eb;
      padding:16px;
      text-align:center;
      background:#f9fafb;
    }

    .support-mini-box i{
      font-size:24px;
      color:var(--accent);
      margin-bottom:8px;
      display:block;
    }

    .support-mini-box h6{
      margin:0 0 6px;
      font-weight:700;
      color:#111827;
      font-size:15px;
    }

    .support-mini-box p{
      margin:0;
      font-size:13px;
      color:#6b7280;
      line-height:1.6;
    }

    .product-desc-card{
      padding:28px;
      margin-top:30px;
    }

    .product-desc-card h3{
      font-size:26px;
      font-weight:800;
      color:#111827;
      margin-bottom:16px;
    }

    .product-desc-card p{
      color:#4b5563;
      font-size:15px;
      line-height:1.9;
      margin-bottom:0;
      white-space:pre-line;
    }

    .related-title{
      font-size:30px;
      font-weight:800;
      color:#111827;
      margin-bottom:10px;
    }

    .related-subtitle{
      color:#6b7280;
      margin-bottom:28px;
    }

    .product-link-wrap{
      color:inherit;
      text-decoration:none;
      display:block;
    }

    .product-link-wrap:hover{
      color:inherit;
      text-decoration:none;
    }

    @media (max-width: 991.98px){
      .product-gallery-card img{
        height:420px;
      }
      .product-title-main{
        font-size:28px;
      }
    }

    @media (max-width: 767.98px){
      .product-gallery-card img{
        height:320px;
      }
      .product-info-card,
      .product-desc-card{
        padding:20px;
      }
      .product-title-main{
        font-size:24px;
      }
      .product-price-main{
        font-size:28px;
      }
      .product-support-strip{
        grid-template-columns:1fr;
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

<section class="product-page-wrap">
  <div class="container-fluid px-lg-5 px-3">
    <div class="row g-4 align-items-start">
      <div class="col-lg-6">
        <div class="product-gallery-card">
          <img src="<?php echo sf_h($productImage); ?>" alt="<?php echo sf_h($productName); ?>" style="object-fit:contain;">
        </div>
      </div>

      <div class="col-lg-6">
        <div class="product-info-card">
          <div class="product-breadcrumb">
            <a href="<?php echo sf_h($storeUrl); ?>">Home</a>
            <span class="mx-2">/</span>
            <a href="<?php echo sf_h($categoryPageUrl); ?>">Categories</a>
            <span class="mx-2">/</span>
            <a href="<?php echo sf_h(store_category_open_url($productCategoryId)); ?>"><?php echo sf_h($productCategory); ?></a>
            <span class="mx-2">/</span>
            <span><?php echo sf_h($productName); ?></span>
          </div>

          <h1 class="product-title-main"><?php echo sf_h($productName); ?></h1>

          <div class="product-meta-line">
            <?php if ($productCode !== ''): ?>
              <span><strong>Code:</strong> <?php echo sf_h($productCode); ?></span>
            <?php endif; ?>

            <span><strong>Category:</strong> <?php echo sf_h($productCategory); ?></span>

            <?php if ($productSubCategory !== ''): ?>
              <span><strong>Subcategory:</strong> <?php echo sf_h($productSubCategory); ?></span>
            <?php endif; ?>

            <?php if ($productUnit !== ''): ?>
              <span><strong>Unit:</strong> <?php echo sf_h($productUnit); ?></span>
            <?php endif; ?>

            <?php if ($productHsn !== ''): ?>
              <span><strong>HSN:</strong> <?php echo sf_h($productHsn); ?></span>
            <?php endif; ?>
          </div>

          <div class="product-price-main">
            <?php echo sf_h(sf_money($productPrice, $currencySymbol)); ?>
            <?php if ($productMrp > $productPrice && $productMrp > 0): ?>
              <span class="old-price"><?php echo sf_h(sf_money($productMrp, $currencySymbol)); ?></span>
            <?php endif; ?>
          </div>

          <?php if ($productMrp > $productPrice && $productMrp > 0): ?>
            <div class="product-save-text">
              You save <?php echo sf_h(sf_money($productMrp - $productPrice, $currencySymbol)); ?>
            </div>
          <?php endif; ?>

          <div class="product-short-desc">
            <?php echo sf_h(sf_trim_text($productDescription, 220)); ?>
          </div>

          <ul class="product-feature-list">
            <li><i class="bi bi-patch-check-fill"></i> Genuine quality product</li>
            <li><i class="bi bi-truck"></i> Fast dispatch and reliable delivery support</li>
            <li><i class="bi bi-shield-lock"></i> Safe purchase flow</li>
            <li><i class="bi bi-headset"></i> Support available for product clarification</li>
            <?php if ($productStock !== null): ?>
              <li><i class="bi bi-box-seam"></i> Available stock: <?php echo sf_h((string)$productStock); ?></li>
            <?php endif; ?>
          </ul>

          <div class="product-qty-box">
            <strong>Quantity:</strong>
            <div class="qty-control">
              <button type="button" onclick="changeQty(-1)">-</button>
              <input type="number" id="productQty" value="1" min="1">
              <button type="button" onclick="changeQty(1)">+</button>
            </div>
          </div>

          <div class="product-action-row">
            <button
              class="btn-add-cart add-to-cart-btn"
              type="button"
              data-id="<?php echo $productId; ?>"
              data-name="<?php echo sf_h($productName); ?>"
              data-price="<?php echo $productPrice; ?>"
              data-image="<?php echo sf_h($productImage); ?>">
              <i class="bi bi-cart3"></i> Add to Cart
            </button>

           
          </div>

          
        </div>
      </div>
    </div>

    <div class="product-desc-card">
      <h3>Product Description</h3>
      <p><?php echo sf_h($productDescription); ?></p>
    </div>
  </div>
</section>

<?php if (!empty($relatedProducts)): ?>
<section class="section-space pt-0">
  <div class="container-fluid px-lg-5 px-3">
    <h2 class="related-title">Related Products</h2>
    <p class="related-subtitle">More products from the same category</p>

    <div class="row g-4">
      <?php foreach ($relatedProducts as $product): ?>
        <?php
          $relId    = (int)($product['id'] ?? 0);
          $relName  = (string)($product['product_name'] ?? 'Product');
          $relDesc  = sf_trim_text($product['description'] ?? '', 85);
          $relImage = sf_normalize_image_path($product['image_path'] ?? '', 'https://via.placeholder.com/700x700?text=Product');
          $relPrice = (float)($product['retail_price'] ?? 0);
          $relMrp   = (float)($product['mrp'] ?? 0);
          $relUrl   = store_product_open_url($relId);
        ?>
        <div class="col-6 col-md-4 col-lg-3">
          <div class="product-item">
            <a href="<?php echo sf_h($relUrl); ?>" class="product-link-wrap">
              <div class="product-thumb">
                <?php if ($relMrp > 0 && $relMrp > $relPrice): ?>
                  <span class="product-badge">Sale</span>
                <?php endif; ?>
                <img src="<?php echo sf_h($relImage); ?>" alt="<?php echo sf_h($relName); ?>" style="object-fit:contain;">
              </div>
              <div class="product-info">
                <h5 class="product-title"><?php echo sf_h($relName); ?></h5>
                <p class="product-desc"><?php echo sf_h($relDesc !== '' ? $relDesc : 'View product details'); ?></p>
                <div class="product-price">
                  <?php echo sf_h(sf_money($relPrice, $currencySymbol)); ?>
                  <?php if ($relMrp > $relPrice && $relMrp > 0): ?>
                    <span class="old-price"><?php echo sf_h(sf_money($relMrp, $currencySymbol)); ?></span>
                  <?php endif; ?>
                </div>
              </div>
            </a>

            <div class="product-actions px-3 pb-3">
              <button class="btn-cart add-to-cart-btn"
                type="button"
                data-id="<?php echo $relId; ?>"
                data-name="<?php echo sf_h($relName); ?>"
                data-price="<?php echo $relPrice; ?>"
                data-image="<?php echo sf_h($relImage); ?>">
                Add to Cart
              </button>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>

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
        <a href="storefront.php?slug=<?php echo urlencode($slug); ?>&page=checkout" class="checkout-btn text-center text-decoration-none">Proceed to Checkout</a>
        <button class="continue-btn" data-bs-dismiss="offcanvas" type="button">Continue Shopping</button>
      </div>
    </div>
  </div>
</div>

<script>
function changeQty(diff) {
  var qtyInput = document.getElementById('productQty');
  var current = parseInt(qtyInput.value || '1', 10);
  current += diff;
  if (current < 1) current = 1;
  qtyInput.value = current;
}

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