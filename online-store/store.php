<?php
if (!defined('STORE_FRONT')) {
    die('Direct access not allowed.');
}

/* =========================
   SAFE FALLBACKS
========================= */
$slug            = $slug ?? '';
$displayName     = $displayName ?? 'Online Store';
$metaTitle       = $metaTitle ?? ('Shop | ' . $displayName);
$metaDescription = $metaDescription ?? ('Browse products from ' . $displayName);
$themeColor      = $themeColor ?? '#e11d48';
$secondaryColor  = $secondaryColor ?? '#111827';
$logoUrl         = $logoUrl ?? 'https://via.placeholder.com/200x80?text=Logo';
$faviconUrl      = $faviconUrl ?? $logoUrl;
$currencySymbol  = $currencySymbol ?? '₹';
$storeUrl        = $storeUrl ?? ('storefront.php?slug=' . urlencode($slug));
$storePageUrl    = $storePageUrl ?? ($storeUrl . '&page=store');
$categoryPageUrl = $categoryPageUrl ?? ($storeUrl . '&page=categories');
$contactPageUrl  = $contactPageUrl ?? ($storeUrl . '&page=contact');
$categories      = is_array($categories ?? null) ? $categories : [];
$products        = is_array($products ?? null) ? $products : [];
$customCss       = $customCss ?? '';
$supportPhone    = $supportPhone ?? '';
$supportEmail    = $supportEmail ?? '';
$whatsappNumber  = $whatsappNumber ?? '';
$storeAddress    = $storeAddress ?? '';

$currentCategoryId = isset($_GET['category_id']) ? (int)$_GET['category_id'] : 0;
$searchQuery       = trim((string)($_GET['q'] ?? ''));
$sortBy            = trim((string)($_GET['sort'] ?? 'latest'));

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

if (!function_exists('store_page_build_url')) {
    function store_page_build_url(array $params = []): string {
        global $slug;
        $base = 'storefront.php?slug=' . urlencode((string)$slug) . '&page=store';
        if (!empty($params)) {
            $base .= '&' . http_build_query($params);
        }
        return $base;
    }
}

/* IMPORTANT FIX:
   route through storefront.php, not direct files */
if (!function_exists('store_category_open_url')) {
    function store_category_open_url(int $categoryId): string {
        global $slug;
        return 'storefront.php?slug=' . urlencode((string)$slug) . '&page=category-open&id=' . $categoryId;
    }
}

if (!function_exists('store_product_open_url')) {
    function store_product_open_url(int $productId): string {
        global $slug;
        return 'storefront.php?slug=' . urlencode((string)$slug) . '&page=product-open&id=' . $productId;
    }
}

/* =========================
   CURRENT CATEGORY NAME
========================= */
$currentCategoryName = 'All Products';
foreach ($categories as $cat) {
    if ((int)($cat['id'] ?? 0) === $currentCategoryId) {
        $currentCategoryName = (string)($cat['category_name'] ?? 'Products');
        break;
    }
}

/* =========================
   FILTER PRODUCTS
========================= */
$filteredProducts = $products;

if ($currentCategoryId > 0) {
    $filteredProducts = array_filter($filteredProducts, function ($item) use ($currentCategoryId) {
        return (int)($item['category_id'] ?? 0) === $currentCategoryId;
    });
}

if ($searchQuery !== '') {
    $needle = mb_strtolower($searchQuery);
    $filteredProducts = array_filter($filteredProducts, function ($item) use ($needle) {
        $haystack = mb_strtolower(
            trim(
                (string)($item['product_name'] ?? '') . ' ' .
                (string)($item['description'] ?? '') . ' ' .
                (string)($item['category_name'] ?? '') . ' ' .
                (string)($item['subcategory_name'] ?? '') . ' ' .
                (string)($item['product_code'] ?? '')
            )
        );
        return strpos($haystack, $needle) !== false;
    });
}

$filteredProducts = array_values($filteredProducts);

/* =========================
   SORT PRODUCTS
========================= */
usort($filteredProducts, function ($a, $b) use ($sortBy) {
    $aPrice = (float)($a['retail_price'] ?? 0);
    $bPrice = (float)($b['retail_price'] ?? 0);
    $aId    = (int)($a['id'] ?? 0);
    $bId    = (int)($b['id'] ?? 0);
    $aName  = (string)($a['product_name'] ?? '');
    $bName  = (string)($b['product_name'] ?? '');

    switch ($sortBy) {
        case 'price_low':
            return $aPrice <=> $bPrice;
        case 'price_high':
            return $bPrice <=> $aPrice;
        case 'name_asc':
            return strcasecmp($aName, $bName);
        case 'name_desc':
            return strcasecmp($bName, $aName);
        case 'latest':
        default:
            return $bId <=> $aId;
    }
});

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
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title><?php echo sf_h('Shop | ' . $displayName); ?></title>
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

    .shop-toolbar{
      background:#fff;
      border-bottom:1px solid #e5e7eb;
      padding:18px 0;
    }

    .shop-toolbar .form-control,
    .shop-toolbar .form-select{
      min-height:44px;
      border-radius:0;
    }

    .shop-info-bar{
      background:#f8f9fa;
      border-bottom:1px solid #e5e7eb;
      padding:16px 0;
    }

    .shop-info-bar h2{
      font-size:28px;
      font-weight:800;
      color:#111827;
      margin:0;
    }

    .shop-info-bar p{
      margin:6px 0 0;
      color:#6b7280;
      font-size:14px;
    }

    .empty-products{
      text-align:center;
      padding:60px 20px;
      background:#fff;
      border:1px solid #e5e7eb;
    }

    .empty-products i{
      font-size:48px;
      color:#cbd5e1;
      margin-bottom:14px;
      display:block;
    }

    .empty-products h4{
      font-size:24px;
      font-weight:700;
      color:#111827;
      margin-bottom:10px;
    }

    .empty-products p{
      color:#6b7280;
      margin-bottom:0;
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

    .category-item{
      color:inherit;
      text-decoration:none;
    }

    .category-item:hover{
      color:inherit;
      text-decoration:none;
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
$heroFile      = __DIR__ . '/includes/hero.php';
$footerFile    = __DIR__ . '/includes/footer.php';

if (file_exists($topStripFile)) include $topStripFile;
if (file_exists($navFile)) include $navFile;
if (file_exists($mobileNavFile)) include $mobileNavFile;
if (file_exists($heroFile)) include $heroFile;
?>

<section class="shop-toolbar">
  <div class="container-fluid px-lg-5 px-3">
    <form method="GET" action="storefront.php">
      <input type="hidden" name="slug" value="<?php echo sf_h($slug); ?>">
      <input type="hidden" name="page" value="store">

      <div class="row g-3 align-items-center">
        <div class="col-lg-4 col-md-6">
          <input
            type="text"
            name="q"
            class="form-control"
            placeholder="Search products..."
            value="<?php echo sf_h($searchQuery); ?>"
          >
        </div>

        <div class="col-lg-3 col-md-6">
          <select name="category_id" class="form-select">
            <option value="0">All Categories</option>
            <?php foreach ($categories as $cat): ?>
              <option value="<?php echo (int)$cat['id']; ?>" <?php echo $currentCategoryId === (int)$cat['id'] ? 'selected' : ''; ?>>
                <?php echo sf_h($cat['category_name'] ?? 'Category'); ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="col-lg-3 col-md-6">
          <select name="sort" class="form-select">
            <option value="latest" <?php echo $sortBy === 'latest' ? 'selected' : ''; ?>>Latest</option>
            <option value="price_low" <?php echo $sortBy === 'price_low' ? 'selected' : ''; ?>>Price: Low to High</option>
            <option value="price_high" <?php echo $sortBy === 'price_high' ? 'selected' : ''; ?>>Price: High to Low</option>
            <option value="name_asc" <?php echo $sortBy === 'name_asc' ? 'selected' : ''; ?>>Name: A to Z</option>
            <option value="name_desc" <?php echo $sortBy === 'name_desc' ? 'selected' : ''; ?>>Name: Z to A</option>
          </select>
        </div>

        <div class="col-lg-2 col-md-6">
          <button type="submit" class="btn btn-dark w-100" style="min-height:44px;">Apply</button>
        </div>
      </div>
    </form>
  </div>
</section>

<section class="shop-info-bar">
  <div class="container-fluid px-lg-5 px-3">
    <h2><?php echo sf_h($currentCategoryName); ?></h2>
    <p>Showing <?php echo count($filteredProducts); ?> product(s) from <?php echo sf_h($displayName); ?></p>
  </div>
</section>

<section class="section-space" id="categories">
  <div class="container-fluid px-lg-5 px-3">
    <div class="section-title">
      <h2>Shop by Categories</h2>
      <p>Browse products by category</p>
    </div>

    <div class="row g-4">
      <?php if (!empty($categories)): ?>
        <?php foreach ($categories as $category): ?>
          <?php $categoryId = (int)($category['id'] ?? 0); ?>
          <div class="col-6 col-md-4 col-lg-2">
            <a href="<?php echo sf_h(store_category_open_url($categoryId)); ?>" class="category-item d-block">
              <div class="category-image">
                <img src="https://images.unsplash.com/photo-1585771724684-38269d6639fd?auto=format&fit=crop&w=600&q=80" alt="<?php echo sf_h($category['category_name'] ?? 'Category'); ?>">
              </div>
              <div class="category-name"><?php echo sf_h($category['category_name'] ?? 'Category'); ?></div>
            </a>
          </div>
        <?php endforeach; ?>
      <?php else: ?>
        <div class="col-12 text-center">
          <p class="text-muted mb-0">No categories available.</p>
        </div>
      <?php endif; ?>
    </div>
  </div>
</section>

<section class="section-space pt-0" id="products">
  <div class="container-fluid px-lg-5 px-3">
    <div class="section-title">
      <h2>Featured Products</h2>
      <p>Top picks for your customers</p>
    </div>

    <?php if (!empty($filteredProducts)): ?>
      <div class="row g-4">
        <?php foreach ($filteredProducts as $product): ?>
          <?php
            $productId    = (int)($product['id'] ?? 0);
            $productName  = (string)($product['product_name'] ?? 'Product');
            $productDesc  = sf_trim_text($product['description'] ?? '', 85);
            $productImage = sf_normalize_image_path($product['image_path'] ?? '', 'https://via.placeholder.com/700x700?text=Product');
            $price        = (float)($product['retail_price'] ?? 0);
            $mrp          = (float)($product['mrp'] ?? 0);

            $badgeText = '';
            if ($mrp > 0 && $mrp > $price) {
                $badgeText = 'Sale';
            } else {
                $badgeText = 'New';
            }

            $productOpenUrl = store_product_open_url($productId);
          ?>
          <div class="col-6 col-md-4 col-lg-3">
            <div class="product-item">
              <a href="<?php echo sf_h($productOpenUrl); ?>" class="product-link-wrap">
                <div class="product-thumb">
                  <?php if ($badgeText !== ''): ?>
                    <span class="product-badge"><?php echo sf_h($badgeText); ?></span>
                  <?php endif; ?>

                  <button class="wishlist-btn" type="button" onclick="event.preventDefault(); event.stopPropagation();">
                    <i class="bi bi-heart"></i>
                  </button>

                  <img src="<?php echo sf_h($productImage); ?>" alt="<?php echo sf_h($productName); ?>">
                </div>

                <div class="product-info">
                  <h5 class="product-title"><?php echo sf_h($productName); ?></h5>
                  <p class="product-desc"><?php echo sf_h($productDesc !== '' ? $productDesc : 'View product details'); ?></p>

                  <div class="product-price">
                    <?php echo sf_h(sf_money($price, $currencySymbol)); ?>
                    <?php if ($mrp > $price && $mrp > 0): ?>
                      <span class="old-price"><?php echo sf_h(sf_money($mrp, $currencySymbol)); ?></span>
                    <?php endif; ?>
                  </div>
                </div>
              </a>

              <div class="product-actions px-3 pb-3">
                <button class="btn-cart add-to-cart-btn"
                  type="button"
                  data-id="<?php echo $productId; ?>"
                  data-name="<?php echo sf_h($productName); ?>"
                  data-price="<?php echo $price; ?>"
                  data-image="<?php echo sf_h($productImage); ?>">
                  Add to Cart
                </button>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php else: ?>
      <div class="empty-products">
        <i class="bi bi-bag-x"></i>
        <h4>No products found</h4>
        <p>There are no matching products for the selected filters.</p>
      </div>
    <?php endif; ?>
  </div>
</section>

<section class="section-space bg-light">
  <div class="container-fluid px-lg-5 px-3">
    <div class="row g-4">
      <div class="col-md-6 col-lg-3">
        <div class="feature-box">
          <i class="bi bi-truck"></i>
          <h5>Fast Delivery</h5>
          <p>Quick dispatch and reliable delivery for products.</p>
        </div>
      </div>

      <div class="col-md-6 col-lg-3">
        <div class="feature-box">
          <i class="bi bi-shield-lock"></i>
          <h5>Secure Payment</h5>
          <p>Trusted checkout and safe payment processing.</p>
        </div>
      </div>

      <div class="col-md-6 col-lg-3">
        <div class="feature-box">
          <i class="bi bi-patch-check"></i>
          <h5>Quality Products</h5>
          <p>Original products with dependable quality support.</p>
        </div>
      </div>

      <div class="col-md-6 col-lg-3">
        <div class="feature-box">
          <i class="bi bi-headset"></i>
          <h5>Expert Assistance</h5>
          <p>Get help choosing the right solution.</p>
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
        <a href="storefront.php?slug=<?php echo urlencode($slug); ?>&page=checkout" class="checkout-btn text-center text-decoration-none">Proceed to Checkout</a>
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
  currency: <?php echo json_encode($currencySymbol); ?>
};
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="online-store/assets/menu.js"></script>
<script src="online-store/assets/cart.js"></script>
<script src="online-store/assets/main.js"></script>
</body>
</html>