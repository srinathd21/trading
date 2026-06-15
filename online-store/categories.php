<?php
if (!defined('STORE_FRONT')) {
    die('Direct access not allowed.');
}
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/*
|--------------------------------------------------------------------------
| Expected from storefront.php
|--------------------------------------------------------------------------
| $slug
| $displayName
| $metaTitle
| $metaDescription
| $themeColor
| $secondaryColor
| $bannerUrl
| $logoUrl
| $faviconUrl
| $categories
| $currencySymbol
| $storeUrl
| $storePageUrl
| $categoryPageUrl
| $contactPageUrl
| $supportPhone
| $supportEmail
| $whatsappNumber
| $storeAddress
| $footerText
| $customCss
*/
/* =========================
   CUSTOMER LOGIN CHECK
========================= */
$customerLoggedIn = false;

$possibleCustomerSessionKeys = [
    'customer_id',
    'online_customer_id',
    'storefront_customer_id',
    'store_customer_id',
    'web_customer_id'
];

foreach ($possibleCustomerSessionKeys as $sessionKey) {
    if (!empty($_SESSION[$sessionKey])) {
        $customerLoggedIn = true;
        break;
    }
}

if (!$customerLoggedIn) {
    $redirectUrl = 'storefront.php?slug=' . urlencode((string)$slug) . '&page=store';

    if (!empty($_SERVER['QUERY_STRING'])) {
        $redirectUrl = 'storefront.php?' . $_SERVER['QUERY_STRING'];
    }

    header('Location: storefront.php?slug=' . urlencode((string)$slug) . '&page=login&redirect=' . urlencode($redirectUrl));
    exit();
}


$slug            = $slug ?? '';
$displayName     = $displayName ?? 'Online Store';
$metaTitle       = 'Categories | ' . $displayName;
$metaDescription = !empty($metaDescription)
    ? $metaDescription
    : ('Browse product categories from ' . $displayName . '.');
$themeColor      = $themeColor ?? '#e11d48';
$secondaryColor  = $secondaryColor ?? '#111827';
$bannerUrl       = $bannerUrl ?? 'https://images.unsplash.com/photo-1518770660439-4636190af475?auto=format&fit=crop&w=1600&q=80';
$logoUrl         = $logoUrl ?? 'https://via.placeholder.com/200x80?text=Logo';
$faviconUrl      = $faviconUrl ?? $logoUrl;
$categories      = is_array($categories ?? null) ? $categories : [];
$currencySymbol  = $currencySymbol ?? '₹';
$storeUrl        = $storeUrl ?? ('storefront.php?slug=' . urlencode($slug));
$storePageUrl    = $storePageUrl ?? ($storeUrl . '&page=store');
$categoryPageUrl = $categoryPageUrl ?? ($storeUrl . '&page=categories');
$contactPageUrl  = $contactPageUrl ?? ($storeUrl . '&page=contact');
$supportPhone    = $supportPhone ?? '';
$supportEmail    = $supportEmail ?? '';
$whatsappNumber  = $whatsappNumber ?? '';
$storeAddress    = $storeAddress ?? '';
$footerText      = $footerText ?? ('© ' . date('Y') . ' ' . $displayName . '. All rights reserved.');
$customCss       = $customCss ?? '';

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

$topStripFile   = __DIR__ . '/includes/top-strip.php';
$navFile        = __DIR__ . '/includes/nav.php';
$mobileNavFile  = __DIR__ . '/includes/mobile-nav.php';
$footerFile     = __DIR__ . '/includes/footer.php';

$categoryInfoBoxes = [
    [
        'icon' => 'bi bi-truck',
        'title' => 'Fast Delivery',
        'text' => 'Quick and reliable delivery support for all major product categories.'
    ],
    [
        'icon' => 'bi bi-patch-check',
        'title' => 'Quality Assured',
        'text' => 'Products chosen for real utility, consistent quality, and dependable use.'
    ],
    [
        'icon' => 'bi bi-shield-lock',
        'title' => 'Secure Shopping',
        'text' => 'Safe purchase flow with customer-first confidence and support.'
    ],
    [
        'icon' => 'bi bi-headset',
        'title' => 'Expert Help',
        'text' => 'Guidance to help customers pick the right category and product faster.'
    ],
];

function sf_category_image(array $category): string
{
    /*
     * First priority: uploaded category image from categories.category_image.
     * storefront.php should fetch c.category_image and may also prepare category_image_url.
     */
    $uploadedImage = trim((string)(
        $category['category_image_url']
        ?? $category['image_url']
        ?? $category['image_path']
        ?? $category['image']
        ?? $category['thumbnail']
        ?? $category['category_image']
        ?? ''
    ));

    if ($uploadedImage !== '') {
        if (
            stripos($uploadedImage, 'http://') === 0 ||
            stripos($uploadedImage, 'https://') === 0 ||
            stripos($uploadedImage, '//') === 0 ||
            stripos($uploadedImage, 'data:') === 0
        ) {
            return $uploadedImage;
        }

        $uploadedImage = ltrim(str_replace('\\', '/', $uploadedImage), '/');
        return $uploadedImage;
    }

    /*
     * Fallback images only if no uploaded category image is available.
     */
    $name = strtolower(trim((string)($category['category_name'] ?? '')));

    if (strpos($name, 'light') !== false) {
        return 'https://images.unsplash.com/photo-1517677208171-0bc6725a3e60?auto=format&fit=crop&w=900&q=80';
    }
    if (strpos($name, 'kitchen') !== false) {
        return 'https://images.unsplash.com/photo-1580894894513-541e068a3e2b?auto=format&fit=crop&w=900&q=80';
    }
    if (strpos($name, 'gadget') !== false || strpos($name, 'smart') !== false) {
        return 'https://images.unsplash.com/photo-1593642532871-8b12e02d091c?auto=format&fit=crop&w=900&q=80';
    }
    if (strpos($name, 'tool') !== false || strpos($name, 'power') !== false) {
        return 'https://images.unsplash.com/photo-1518770660439-4636190af475?auto=format&fit=crop&w=900&q=80';
    }
    if (strpos($name, 'accessor') !== false || strpos($name, 'wire') !== false || strpos($name, 'plug') !== false) {
        return 'https://images.unsplash.com/photo-1563013544-824ae1b704d3?auto=format&fit=crop&w=900&q=80';
    }
    if (strpos($name, 'appliance') !== false || strpos($name, 'home') !== false) {
        return 'https://images.unsplash.com/photo-1585771724684-38269d6639fd?auto=format&fit=crop&w=900&q=80';
    }

    return 'https://images.unsplash.com/photo-1585771724684-38269d6639fd?auto=format&fit=crop&w=900&q=80';
}

function sf_category_count_label(array $category): string
{
    $count = isset($category['product_count']) ? (int)$category['product_count'] : 0;
    return $count > 0 ? ($count . '+ Products') : 'View Products';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title><?php echo sf_h($metaTitle); ?></title>
  <meta name="description" content="<?php echo sf_h($metaDescription); ?>">
  <link rel="icon" href="<?php echo sf_h($faviconUrl); ?>">

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">

  <link rel="stylesheet" href="online-store/assets/main.css">
  <link rel="stylesheet" href="online-store/assets/menu-bar.css">
  <link rel="stylesheet" href="online-store/assets/footer.css">

  <style>
    :root{
      --accent: <?php echo sf_h($themeColor); ?>;
      --primary-dark: <?php echo sf_h($secondaryColor); ?>;
    }

    .categories-hero{
      background:
        linear-gradient(rgba(17,24,39,0.82), rgba(17,24,39,0.72)),
        url('<?php echo sf_h($bannerUrl); ?>') center/cover no-repeat;
      color:#fff;
      padding:90px 0;
    }

    .categories-hero h1{
      font-size:48px;
      font-weight:800;
      margin-bottom:14px;
    }

    .categories-hero p{
      max-width:760px;
      color:#e5e7eb;
      line-height:1.8;
      margin:0 auto;
      font-size:16px;
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

    .category-page-card{
      border:1px solid #e5e7eb;
      background:#fff;
      overflow:hidden;
      height:100%;
      transition:0.3s ease;
      text-decoration:none;
      display:block;
    }

    .category-page-card:hover{
      transform:translateY(-4px);
      box-shadow:0 12px 30px rgba(0,0,0,0.08);
    }

    .category-page-image{
      position:relative;
      overflow:hidden;
      background:#f3f4f6;
    }

    .category-page-image img{
      width:100%;
      height:175px;
      object-fit:cover;
      transition:transform 0.4s ease;
    }

    .category-page-card:hover .category-page-image img{
      transform:scale(1.05);
    }

    .category-page-body{
      padding:14px 14px;
    }

    .category-page-body h4{
      font-size:17px;
      font-weight:700;
      color:#111827;
      margin-bottom:6px;
    }

    .category-page-body p{
      font-size:13px;
      line-height:1.5;
      color:#6b7280;
      margin-bottom:10px;
      min-height:38px;
    }

    .category-meta{
      display:flex;
      justify-content:space-between;
      align-items:center;
      gap:10px;
      flex-wrap:wrap;
    }

    .category-count{
      font-size:12px;
      color:#6b7280;
      font-weight:600;
    }

    .category-link{
      color:var(--accent);
      font-size:12px;
      font-weight:700;
    }

    .category-info-strip{
      background:#111827;
      color:#fff;
    }

    .category-info-box{
      text-align:center;
      padding:10px 18px;
    }

    .category-info-box i{
      font-size:28px;
      color:#fda4af;
      margin-bottom:10px;
    }

    .category-info-box h5{
      font-size:18px;
      font-weight:700;
      margin-bottom:8px;
    }

    .category-info-box p{
      margin:0;
      color:#d1d5db;
      font-size:14px;
      line-height:1.7;
    }

    @media (max-width: 991.98px){
      .categories-hero h1{
        font-size:38px;
      }
    }

    @media (max-width: 767.98px){
      .categories-hero{
        padding:70px 0;
      }

      .categories-hero h1{
        font-size:30px;
      }

      .category-page-image img{
        height:160px;
      }
    }
  </style>

  <?php if ($customCss !== ''): ?>
    <style><?php echo $customCss; ?></style>
  <?php endif; ?>
</head>
<body>

<?php
if (file_exists($topStripFile)) {
    include $topStripFile;
}
if (file_exists($navFile)) {
    include $navFile;
}
if (file_exists($mobileNavFile)) {
    include $mobileNavFile;
}
?>

<section class="categories-hero text-center">
  <div class="container-fluid px-lg-5 px-3">
    <h1>Shop by Categories</h1>
    <p>
      Explore product categories from <?php echo sf_h($displayName); ?> and find what you need faster.
    </p>
  </div>
</section>

<section class="breadcrumb-wrap">
  <div class="container-fluid px-lg-5 px-3">
    <nav aria-label="breadcrumb">
      <ol class="breadcrumb breadcrumb-custom">
        <li class="breadcrumb-item"><a href="<?php echo sf_h($storeUrl); ?>">Home</a></li>
        <li class="breadcrumb-item active" aria-current="page">Categories</li>
      </ol>
    </nav>
  </div>
</section>

<section class="section-space">
  <div class="container-fluid px-lg-5 px-3">
    <div class="row g-4">

      <?php if (!empty($categories)): ?>
        <?php foreach ($categories as $category): ?>
          <?php
            $categoryId    = (int)($category['id'] ?? 0);
            $categoryName  = (string)($category['category_name'] ?? 'Category');
            $categoryDesc  = sf_trim_text((string)($category['description'] ?? ''), 60);
            $categoryImage = sf_category_image($category);
          ?>
          <div class="col-6 col-md-4 col-lg-3 col-xl-2">
            <a href="<?php echo sf_h($storePageUrl . '&category_id=' . $categoryId); ?>" class="category-page-card">
              <div class="category-page-image">
                <img src="<?php echo sf_h($categoryImage . (strpos($categoryImage, 'uploads/categories/') !== false ? '?v=' . time() : '')); ?>" alt="<?php echo sf_h($categoryName); ?>">
              </div>
              <div class="category-page-body">
                <h4><?php echo sf_h($categoryName); ?></h4>
                <p><?php echo sf_h($categoryDesc !== '' ? $categoryDesc : 'Browse products from this category.'); ?></p>
                <div class="category-meta">
                  <span class="category-count"><?php echo sf_h(sf_category_count_label($category)); ?></span>
                  <span class="category-link">View Products <i class="bi bi-arrow-right"></i></span>
                </div>
              </div>
            </a>
          </div>
        <?php endforeach; ?>
      <?php else: ?>
        <div class="col-12">
          <div class="text-center py-5">
            <h4 class="mb-2">No categories found</h4>
            <p class="text-muted mb-0">There are no active categories for this store yet.</p>
          </div>
        </div>
      <?php endif; ?>

    </div>
  </div>
</section>

<section class="section-space category-info-strip">
  <div class="container-fluid px-lg-5 px-3">
    <div class="row g-4">
      <?php foreach ($categoryInfoBoxes as $box): ?>
      <div class="col-md-6 col-lg-3">
        <div class="category-info-box">
          <i class="<?php echo sf_h($box['icon']); ?>"></i>
          <h5><?php echo sf_h($box['title']); ?></h5>
          <p><?php echo sf_h($box['text']); ?></p>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<?php
if (file_exists($footerFile)) {
    include $footerFile;
}
?>

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
<script src="online-store/assets/main.js"></script>
</body>
</html>