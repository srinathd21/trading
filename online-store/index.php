<?php
if (!defined('STORE_FRONT')) {
    die('Direct access not allowed.');
}

/* Safe fallbacks */
$slug                    = $slug ?? '';
$displayName             = $displayName ?? 'Online Store';
$tagline                 = $tagline ?? '';
$metaTitle               = $metaTitle ?? $displayName;
$metaDescription         = $metaDescription ?? '';
$themeColor              = $themeColor ?? '#e11d48';
$secondaryColor          = $secondaryColor ?? '#111827';
$bannerUrl               = $bannerUrl ?? 'https://images.unsplash.com/photo-1581092921461-eab62e97a780?auto=format&fit=crop&w=1600&q=80';
$logoUrl                 = $logoUrl ?? 'https://via.placeholder.com/200x80?text=Logo';
$faviconUrl              = $faviconUrl ?? $logoUrl;
$heroTitle               = $heroTitle ?? $displayName;
$heroSubtitle            = $heroSubtitle ?? 'Discover quality products with fast delivery and dependable support.';
$heroButtonText          = $heroButtonText ?? 'Explore Products';
$heroButtonLink          = $heroButtonLink ?? ('storefront.php?slug=' . urlencode($slug) . '&page=store');
$featuredCategoriesTitle = $featuredCategoriesTitle ?? 'Popular Product Categories';
$featuredProductsTitle   = $featuredProductsTitle ?? 'Featured Bestsellers';
$categories              = is_array($categories ?? null) ? $categories : [];
$products                = is_array($products ?? null) ? $products : [];
$totalProducts           = (int)($totalProducts ?? 0);
$totalCategories         = (int)($totalCategories ?? 0);
$enableCOD               = (int)($enableCOD ?? 1);
$supportPhone            = $supportPhone ?? '';
$supportEmail            = $supportEmail ?? '';
$whatsappNumber          = $whatsappNumber ?? '';
$storeAddress            = $storeAddress ?? '';
$footerText              = $footerText ?? ('© ' . date('Y') . ' ' . $displayName . '. All rights reserved.');
$aboutUs                 = $aboutUs ?? '';
$currencySymbol          = $currencySymbol ?? '₹';
$storeUrl                = $storeUrl ?? ('storefront.php?slug=' . urlencode($slug) . '&page=index');
$storePageUrl            = $storePageUrl ?? ('storefront.php?slug=' . urlencode($slug) . '&page=store');
$categoryPageUrl         = $categoryPageUrl ?? ('storefront.php?slug=' . urlencode($slug) . '&page=categories');
$contactPageUrl          = $contactPageUrl ?? ('storefront.php?slug=' . urlencode($slug) . '&page=contact');
$customCss               = $customCss ?? '';

$staticFeatures = is_array($staticFeatures ?? null) ? $staticFeatures : [
    ['icon' => 'bi-patch-check', 'title' => 'Genuine Quality', 'text' => 'Reliable products with consistent quality and practical value.'],
    ['icon' => 'bi-truck', 'title' => 'Fast Delivery', 'text' => 'Quick dispatch and dependable delivery support.'],
    ['icon' => 'bi-shield-lock', 'title' => 'Secure Checkout', 'text' => 'Simple and secure payment experience.'],
    ['icon' => 'bi-headset', 'title' => 'Expert Support', 'text' => 'Help choosing the right product for your needs.'],
];

$testimonials = is_array($testimonials ?? null) ? $testimonials : [
    ['name' => 'Customer', 'role' => 'Buyer', 'text' => 'Good pricing and clean shopping experience.']
];

$faqs = is_array($faqs ?? null) ? $faqs : [
    ['q' => 'Are your products genuine?', 'a' => 'Yes, we focus on trusted products and practical support.']
];

/* Variables for include files */
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

/* Correct routed targets through storefront.php */
$categoryOpenBase = 'storefront.php?slug=' . urlencode($slug) . '&page=category-open';
$productOpenBase  = 'storefront.php?slug=' . urlencode($slug) . '&page=product-open';
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
  <link rel="stylesheet" href="online-store/assets/cart.css">
  <link rel="stylesheet" href="online-store/assets/footer.css">

  <style>
    :root{
      --accent: <?php echo sf_h($themeColor); ?>;
      --primary-dark: <?php echo sf_h($secondaryColor); ?>;
    }

    .landing-hero{
      min-height: 88vh;
      display:flex;
      align-items:center;
      background:
        linear-gradient(90deg, rgba(17,24,39,0.88) 0%, rgba(17,24,39,0.68) 45%, rgba(17,24,39,0.35) 100%),
        url('<?php echo sf_h($bannerUrl); ?>') center/cover no-repeat;
      color:#fff;
    }

    .landing-hero .hero-badge{
      display:inline-flex;
      align-items:center;
      gap:8px;
      background:rgba(255,255,255,0.08);
      border:1px solid rgba(255,255,255,0.12);
      color:#fff;
      padding:8px 14px;
      font-size:13px;
      font-weight:600;
      margin-bottom:18px;
    }

    .landing-hero h1{
      font-size:58px;
      line-height:1.08;
      font-weight:800;
      margin-bottom:18px;
      max-width:760px;
    }

    .landing-hero p{
      font-size:17px;
      line-height:1.8;
      color:#e5e7eb;
      max-width:650px;
      margin-bottom:28px;
    }

    .hero-stats{
      margin-top:28px;
    }

    .hero-stat-box{
      background:#fff;
      border:1px solid #e5e7eb;
      padding:22px 18px;
      text-align:center;
      height:100%;
    }

    .hero-stat-box h3{
      font-size:28px;
      font-weight:800;
      color:#111827;
      margin-bottom:6px;
    }

    .hero-stat-box p{
      margin:0;
      color:#6b7280;
      font-size:14px;
      line-height:1.5;
    }

    .btn-landing-primary{
      background:var(--accent);
      color:#fff;
      border:none;
      padding:14px 28px;
      font-size:15px;
      font-weight:700;
      transition:0.3s ease;
      text-decoration:none;
      display:inline-block;
    }

    .btn-landing-primary:hover{
      filter:brightness(0.92);
      color:#fff;
    }

    .btn-landing-outline{
      background:transparent;
      color:#fff;
      border:1px solid rgba(255,255,255,0.7);
      padding:14px 28px;
      font-size:15px;
      font-weight:700;
      transition:0.3s ease;
      text-decoration:none;
      display:inline-block;
    }

    .btn-landing-outline:hover{
      background:#fff;
      color:#111827;
    }

    .section-block{
      padding:80px 0;
    }

    .section-heading{
      text-align:center;
      margin-bottom:42px;
    }

    .section-heading h2{
      font-size:38px;
      font-weight:800;
      color:#111827;
      margin-bottom:10px;
    }

    .section-heading p{
      color:#6b7280;
      margin:0 auto;
      max-width:720px;
      line-height:1.7;
    }

    .value-card{
      border:1px solid #e5e7eb;
      background:#fff;
      padding:28px 24px;
      height:100%;
      transition:0.3s ease;
    }

    .value-card:hover{
      transform:translateY(-4px);
      box-shadow:0 12px 35px rgba(0,0,0,0.08);
    }

    .value-icon{
      width:58px;
      height:58px;
      display:flex;
      align-items:center;
      justify-content:center;
      background:#111827;
      color:#fff;
      font-size:22px;
      margin-bottom:18px;
    }

    .value-card h4{
      font-size:20px;
      font-weight:700;
      color:#111827;
      margin-bottom:10px;
    }

    .value-card p{
      margin:0;
      color:#6b7280;
      line-height:1.7;
      font-size:14px;
    }

    .category-landing-card{
      position:relative;
      overflow:hidden;
      min-height:340px;
      display:flex;
      align-items:flex-end;
      background:#f3f4f6;
      text-decoration:none;
    }

    .category-landing-card img{
      position:absolute;
      inset:0;
      width:100%;
      height:100%;
      object-fit:cover;
      transition:transform 0.45s ease;
    }

    .category-landing-card:hover img{
      transform:scale(1.06);
    }

    .category-landing-overlay{
      position:relative;
      z-index:2;
      width:100%;
      padding:24px;
      background:linear-gradient(to top, rgba(17,24,39,0.88), rgba(17,24,39,0.2));
      color:#fff;
    }

    .category-landing-overlay h4{
      font-size:24px;
      font-weight:700;
      margin-bottom:8px;
    }

    .category-landing-overlay p{
      margin:0;
      color:#e5e7eb;
      line-height:1.6;
      font-size:14px;
    }

    .why-strip{
      background:#111827;
      color:#fff;
    }

    .why-item{
      text-align:center;
      padding:10px 16px;
    }

    .why-item i{
      font-size:30px;
      margin-bottom:12px;
      color:#fda4af;
    }

    .why-item h5{
      font-size:18px;
      font-weight:700;
      margin-bottom:8px;
    }

    .why-item p{
      margin:0;
      color:#d1d5db;
      font-size:14px;
      line-height:1.7;
    }

    .product-showcase{
      border:1px solid #e5e7eb;
      background:#fff;
      overflow:hidden;
      height:100%;
      text-decoration:none;
      display:block;
    }

    .product-showcase img{
      width:100%;
      height:260px;
      object-fit:cover;
    }

    .product-showcase-body{
      padding:20px;
    }

    .product-showcase-body h5{
      font-size:19px;
      font-weight:700;
      color:#111827;
      margin-bottom:8px;
    }

    .product-showcase-body p{
      color:#6b7280;
      font-size:14px;
      line-height:1.7;
      margin-bottom:12px;
    }

    .product-price-line{
      font-size:20px;
      font-weight:800;
      color:#111827;
    }

    .product-price-line span{
      color:#9ca3af;
      font-size:14px;
      text-decoration:line-through;
      margin-left:8px;
      font-weight:500;
    }

    .trust-box{
      border:1px solid #e5e7eb;
      background:#fff;
      padding:26px 22px;
      text-align:center;
      height:100%;
    }

    .trust-box h3{
      font-size:30px;
      font-weight:800;
      color:#111827;
      margin-bottom:6px;
    }

    .trust-box p{
      margin:0;
      color:#6b7280;
      font-size:14px;
    }

    .cta-section{
      background:
        linear-gradient(rgba(17,24,39,0.86), rgba(17,24,39,0.86)),
        url('<?php echo sf_h($bannerUrl); ?>') center/cover no-repeat;
      color:#fff;
      text-align:center;
      padding:90px 0;
    }

    .cta-section h2{
      font-size:42px;
      font-weight:800;
      margin-bottom:14px;
    }

    .cta-section p{
      max-width:760px;
      margin:0 auto 26px;
      color:#d1d5db;
      line-height:1.8;
    }

    .testimonial-card{
      border:1px solid #e5e7eb;
      background:#fff;
      padding:28px 24px;
      height:100%;
    }

    .testimonial-card .stars{
      color:#f59e0b;
      margin-bottom:14px;
    }

    .testimonial-card p{
      color:#4b5563;
      line-height:1.8;
      font-size:14px;
      margin-bottom:18px;
    }

    .testimonial-card h6{
      margin:0;
      font-size:16px;
      font-weight:700;
      color:#111827;
    }

    .testimonial-card small{
      color:#6b7280;
    }

    .faq-item{
      border:1px solid #e5e7eb;
      background:#fff;
      padding:22px 20px;
      margin-bottom:16px;
    }

    .faq-item h5{
      font-size:18px;
      font-weight:700;
      color:#111827;
      margin-bottom:10px;
    }

    .faq-item p{
      margin:0;
      color:#6b7280;
      line-height:1.7;
      font-size:14px;
    }

    @media (max-width: 991.98px){
      .landing-hero h1{
        font-size:44px;
      }
    }

    @media (max-width: 767.98px){
      .landing-hero{
        min-height:auto;
        padding:80px 0;
      }

      .landing-hero h1{
        font-size:34px;
      }

      .section-heading h2{
        font-size:30px;
      }

      .cta-section h2{
        font-size:32px;
      }
    }

    @media (max-width: 575.98px){
      .landing-hero h1{
        font-size:28px;
      }
    }
  </style>

  <?php if ($customCss !== ''): ?>
    <style><?php echo $customCss; ?></style>
  <?php endif; ?>
</head>
<body>

<?php
$topStripFile = __DIR__ . '/includes/top-strip.php';
$navFile      = __DIR__ . '/includes/nav.php';
$mobileNavFile= __DIR__ . '/includes/mobile-nav.php';
$footerFile   = __DIR__ . '/includes/footer.php';

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

<section class="landing-hero">
  <div class="container-fluid px-lg-5 px-3">
    <div class="row align-items-center g-4">
      <div class="col-lg-7">
        <div class="hero-badge">
          <i class="bi bi-lightning-charge-fill"></i>
          <?php echo sf_h($tagline !== '' ? $tagline : ('Trusted Store for ' . $displayName)); ?>
        </div>
        <h1><?php echo sf_h($heroTitle); ?></h1>
        <p><?php echo sf_h($heroSubtitle); ?></p>
        <div class="d-flex flex-wrap gap-3">
          <a href="<?php echo sf_h($heroButtonLink); ?>" class="btn btn-landing-primary"><?php echo sf_h($heroButtonText); ?></a>
          <a href="#why-choose-us" class="btn btn-landing-outline">Why Choose Us</a>
        </div>
      </div>

      <div class="col-lg-5">
        <div class="row g-3 hero-stats">
          <div class="col-6">
            <div class="hero-stat-box">
              <h3><?php echo $totalProducts; ?>+</h3>
              <p>Products Available</p>
            </div>
          </div>
          <div class="col-6">
            <div class="hero-stat-box">
              <h3><?php echo $totalCategories; ?>+</h3>
              <p>Active Categories</p>
            </div>
          </div>
          <div class="col-6">
            <div class="hero-stat-box">
              <h3><?php echo $enableCOD === 1 ? 'COD' : 'Online'; ?></h3>
              <p>Payment Mode</p>
            </div>
          </div>
          <div class="col-6">
            <div class="hero-stat-box">
              <h3><?php echo $supportPhone !== '' ? 'Live' : 'Email'; ?></h3>
              <p>Customer Support</p>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>

<section class="section-block" id="why-choose-us">
  <div class="container-fluid px-lg-5 px-3">
    <div class="section-heading">
      <h2>Why Customers Choose <?php echo sf_h($displayName); ?></h2>
      <p>
        A landing page should sell trust and clarity, not just dump products. These are the reasons customers buy instead of leaving in ten seconds.
      </p>
    </div>

    <div class="row g-4">
      <?php foreach ($staticFeatures as $feature): ?>
      <div class="col-md-6 col-lg-3">
        <div class="value-card">
          <div class="value-icon"><i class="bi <?php echo sf_h($feature['icon']); ?>"></i></div>
          <h4><?php echo sf_h($feature['title']); ?></h4>
          <p><?php echo sf_h($feature['text']); ?></p>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<section class="section-block pt-0">
  <div class="container-fluid px-lg-5 px-3">
    <div class="section-heading">
      <h2><?php echo sf_h($featuredCategoriesTitle); ?></h2>
      <p>These categories drive the bulk of interest. Show the value fast and give the user a clean entry point.</p>
    </div>

    <div class="row g-4">
      <?php if (!empty($categories)): ?>
        <?php foreach ($categories as $category): ?>
          <div class="col-md-6 col-lg-4">
            <a href="<?php echo sf_h($categoryOpenBase . '&id=' . (int)$category['id']); ?>" class="category-landing-card">
              <img src="https://images.unsplash.com/photo-1585771724684-38269d6639fd?auto=format&fit=crop&w=900&q=80" alt="<?php echo sf_h($category['category_name']); ?>">
              <div class="category-landing-overlay">
                <h4><?php echo sf_h($category['category_name']); ?></h4>
                <p><?php echo sf_h(sf_trim_text($category['description'] ?? '', 90) ?: 'Explore products under this category.'); ?></p>
              </div>
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

<section class="section-block why-strip">
  <div class="container-fluid px-lg-5 px-3">
    <div class="row g-4">
      <div class="col-md-6 col-lg-3">
        <div class="why-item">
          <i class="bi bi-lightning-charge"></i>
          <h5>Energy Efficient</h5>
          <p>Products selected for long-term efficiency and practical daily performance.</p>
        </div>
      </div>

      <div class="col-md-6 col-lg-3">
        <div class="why-item">
          <i class="bi bi-arrow-repeat"></i>
          <h5>Easy Replacement</h5>
          <p>Simple support for eligible products to reduce customer hesitation before buying.</p>
        </div>
      </div>

      <div class="col-md-6 col-lg-3">
        <div class="why-item">
          <i class="bi bi-wallet2"></i>
          <h5>Fair Pricing</h5>
          <p>Competitive pricing without forcing low-quality junk into the catalog.</p>
        </div>
      </div>

      <div class="col-md-6 col-lg-3">
        <div class="why-item">
          <i class="bi bi-clipboard-check"></i>
          <h5>Trusted Selection</h5>
          <p>Curated products that customers actually need instead of random catalog clutter.</p>
        </div>
      </div>
    </div>
  </div>
</section>

<section class="section-block" id="featured-products">
  <div class="container-fluid px-lg-5 px-3">
    <div class="section-heading">
      <h2><?php echo sf_h($featuredProductsTitle); ?></h2>
      <p>Keep this section focused. A landing page is not a marketplace dump. Show only strong products.</p>
    </div>

    <div class="row g-4">
      <?php if (!empty($products)): ?>
        <?php foreach (array_slice($products, 0, 4) as $product): ?>
          <?php
            $productImage = sf_normalize_image_path($product['image_path'] ?? '', 'https://via.placeholder.com/700x700?text=Product');
          ?>
          <div class="col-6 col-md-4 col-lg-3">
            <a href="<?php echo sf_h($productOpenBase . '&id=' . (int)$product['id']); ?>" class="product-showcase">
              <img src="<?php echo sf_h($productImage); ?>" alt="<?php echo sf_h($product['product_name'] ?? 'Product'); ?>">
              <div class="product-showcase-body">
                <h5><?php echo sf_h($product['product_name'] ?? 'Product'); ?></h5>
                <p><?php echo sf_h(sf_trim_text($product['description'] ?? '', 85) ?: ($product['category_name'] ?? 'View product details')); ?></p>
                <div class="mt-2">
                  <span class="btn btn-sm btn-outline-dark">View Product</span>
                </div>
              </div>
            </a>
          </div>
        <?php endforeach; ?>
      <?php else: ?>
        <div class="col-12 text-center">
          <p class="text-muted mb-0">No products available.</p>
        </div>
      <?php endif; ?>
    </div>

    <?php if (!empty($products) && count($products) > 4): ?>
      <div class="text-center mt-4">
        <a href="storefront.php?slug=kesavan-traders&page=store" class="btn btn-landing-primary">
          Show More
        </a>
      </div>
    <?php endif; ?>
  </div>
</section>

<section class="section-block bg-light">
  <div class="container-fluid px-lg-5 px-3">
    <div class="section-heading">
      <h2>Numbers That Build Trust</h2>
      <p>These trust signals matter more than decorative filler content.</p>
    </div>

    <div class="row g-4">
      <div class="col-6 col-md-3">
        <div class="trust-box">
          <h3><?php echo $totalProducts; ?>+</h3>
          <p>Active Products</p>
        </div>
      </div>

      <div class="col-6 col-md-3">
        <div class="trust-box">
          <h3><?php echo $totalCategories; ?>+</h3>
          <p>Product Categories</p>
        </div>
      </div>

      <div class="col-6 col-md-3">
        <div class="trust-box">
          <h3><?php echo $enableCOD === 1 ? 'COD' : 'Fast'; ?></h3>
          <p>Checkout Support</p>
        </div>
      </div>

      <div class="col-6 col-md-3">
        <div class="trust-box">
          <h3><?php echo $supportPhone !== '' ? '7 Days' : 'Email'; ?></h3>
          <p>Support Window</p>
        </div>
      </div>
    </div>
  </div>
</section>

<section class="cta-section">
  <div class="container-fluid px-lg-5 px-3">
    <h2>Need Reliable Products at the Right Price?</h2>
    <p>
      Push users toward action. Highlight quality, delivery, pricing, and support — then ask them to buy or contact you.
    </p>
    <div class="d-flex flex-wrap justify-content-center gap-3">
      <a href="<?php echo sf_h($storePageUrl); ?>" class="btn btn-landing-primary">Shop Bestsellers</a>
      <a href="#customer-reviews" class="btn btn-landing-outline">Read Reviews</a>
    </div>
  </div>
</section>

<section class="section-block" id="customer-reviews">
  <div class="container-fluid px-lg-5 px-3">
    <div class="section-heading">
      <h2>What Customers Say</h2>
      <p>Testimonials work when they sound credible, not fake and inflated.</p>
    </div>

    <div class="row g-4">
      <?php foreach ($testimonials as $item): ?>
      <div class="col-md-6 col-lg-4">
        <div class="testimonial-card">
          <div class="stars">
            <i class="bi bi-star-fill"></i>
            <i class="bi bi-star-fill"></i>
            <i class="bi bi-star-fill"></i>
            <i class="bi bi-star-fill"></i>
            <i class="bi bi-star-fill"></i>
          </div>
          <p><?php echo sf_h($item['text']); ?></p>
          <h6><?php echo sf_h($item['name']); ?></h6>
          <small><?php echo sf_h($item['role']); ?></small>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<section class="section-block bg-light">
  <div class="container-fluid px-lg-5 px-3">
    <div class="section-heading">
      <h2>Frequently Asked Questions</h2>
      <p>These are the objections people usually have before buying.</p>
    </div>

    <div class="row justify-content-center">
      <div class="col-lg-10">
        <?php foreach ($faqs as $faq): ?>
        <div class="faq-item">
          <h5><?php echo sf_h($faq['q']); ?></h5>
          <p><?php echo sf_h($faq['a']); ?></p>
        </div>
        <?php endforeach; ?>

        <?php if ($aboutUs !== ''): ?>
        <div class="faq-item">
          <h5>About Us</h5>
          <p><?php echo nl2br(sf_h($aboutUs)); ?></p>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</section>

<?php
if (file_exists($footerFile)) {
    include $footerFile;
}
?>

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
        <a href="<?php echo sf_h($storePageUrl); ?>" class="checkout-btn text-center text-decoration-none">Go to Store</a>
        <button class="continue-btn" data-bs-dismiss="offcanvas">Continue Browsing</button>
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

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="online-store/assets/menu.js"></script>
<script src="online-store/assets/cart.js"></script>
<script src="online-store/assets/main.js"></script>
</body>
</html>