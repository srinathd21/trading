<?php
date_default_timezone_set('Asia/Kolkata');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/config/database.php';

if (!isset($pdo) || !($pdo instanceof PDO)) {
    die('Database connection not available.');
}

/* =========================================================
   HELPERS
========================================================= */
if (!function_exists('sf_h')) {
    function sf_h($value): string
    {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('sf_money')) {
    function sf_money($amount, string $symbol = '₹'): string
    {
        return $symbol . number_format((float)$amount, 2, '.', '');
    }
}

if (!function_exists('sf_trim_text')) {
    function sf_trim_text($text, int $limit = 100): string
    {
        $text = trim(strip_tags((string)($text ?? '')));
        if ($text === '') {
            return '';
        }
        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            if (mb_strlen($text) <= $limit) {
                return $text;
            }
            return rtrim(mb_substr($text, 0, $limit - 3)) . '...';
        }

        if (strlen($text) <= $limit) {
            return $text;
        }
        return rtrim(substr($text, 0, $limit - 3)) . '...';
    }
}

if (!function_exists('sf_normalize_image_path')) {
    function sf_normalize_image_path($path, string $fallback = ''): string
    {
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
        $path = ltrim($path, '/');

        return $path;
    }
}

if (!function_exists('sf_store_url')) {
    function sf_store_url(string $slug, string $page = 'index', array $extra = []): string
    {
        $params = array_merge([
            'slug' => $slug,
            'page' => $page,
        ], $extra);

        return 'storefront.php?' . http_build_query($params);
    }
}

/* =========================================================
   INPUT
========================================================= */
$slug = trim((string)($_GET['slug'] ?? ''));
$page = trim((string)($_GET['page'] ?? 'index'));

if ($slug === '') {
    die('Store slug missing.');
}

/* =========================================================
   LOAD STORE
========================================================= */
$storeSql = "
    SELECT
        b.id AS business_id,
        b.business_name,
        b.phone AS business_phone,
        b.email AS business_email,
        b.address AS business_address,
        b.logo_path AS business_logo,

        oss.display_name,
        oss.store_slug AS settings_slug,
        oss.tagline,
        oss.support_phone,
        oss.whatsapp_number,
        oss.support_email,
        oss.address,
        oss.logo_url,
        oss.banner_url,
        oss.theme_color,
        oss.currency_symbol,
        oss.enable_cod,
        oss.enable_online_payment,
        oss.delivery_charge,
        oss.free_delivery_above,
        oss.meta_title,
        oss.meta_description,
        oss.facebook_url,
        oss.instagram_url,
        oss.youtube_url,
        oss.about_us,
        oss.footer_text,
        oss.maintenance_mode,

        osu.store_slug AS setup_slug,
        osu.store_status,
        osu.store_title,
        osu.store_tagline,
        osu.logo_url AS setup_logo_url,
        osu.banner_url AS setup_banner_url,
        osu.favicon_url,
        osu.theme_color AS setup_theme_color,
        osu.secondary_color,
        osu.homepage_title,
        osu.homepage_description,
        osu.hero_title,
        osu.hero_subtitle,
        osu.hero_button_text,
        osu.hero_button_link,
        osu.featured_categories_title,
        osu.featured_products_title,
        osu.custom_css
    FROM businesses b
    LEFT JOIN online_store_settings oss ON oss.business_id = b.id
    LEFT JOIN online_store_setup osu ON osu.business_id = b.id
    WHERE (oss.store_slug = :slug1 OR osu.store_slug = :slug2)
    LIMIT 1
";

$stmt = $pdo->prepare($storeSql);
$stmt->execute([
    ':slug1' => $slug,
    ':slug2' => $slug,
]);
$storeRow = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$storeRow) {
    die('Store not found.');
}

$businessId      = (int)($storeRow['business_id'] ?? 0);
$storeStatus     = trim((string)($storeRow['store_status'] ?? 'draft'));
$maintenanceMode = (int)($storeRow['maintenance_mode'] ?? 0);

if ($maintenanceMode === 1 || $storeStatus === 'maintenance') {
    die('Store is under maintenance.');
}

if ($storeStatus !== 'live') {
    die('Store not live');
}

/* =========================================================
   COMMON STORE DATA
========================================================= */
$displayName = trim((string)(
    $storeRow['display_name']
    ?: $storeRow['store_title']
    ?: $storeRow['business_name']
    ?: 'Online Store'
));

$tagline = trim((string)(
    $storeRow['tagline']
    ?: $storeRow['store_tagline']
    ?: ''
));

$metaTitle = trim((string)(
    $storeRow['meta_title']
    ?: $storeRow['homepage_title']
    ?: $displayName
));

$metaDescription = trim((string)(
    $storeRow['meta_description']
    ?: $storeRow['homepage_description']
    ?: $tagline
));

$themeColor = trim((string)(
    $storeRow['theme_color']
    ?: $storeRow['setup_theme_color']
    ?: '#e11d48'
));

$secondaryColor = trim((string)(
    $storeRow['secondary_color']
    ?: '#111827'
));

$currencySymbol = trim((string)(
    $storeRow['currency_symbol']
    ?: '₹'
));

$heroTitle = trim((string)(
    $storeRow['hero_title']
    ?: $storeRow['homepage_title']
    ?: $displayName
));

$heroSubtitle = trim((string)(
    $storeRow['hero_subtitle']
    ?: $storeRow['homepage_description']
    ?: 'Discover quality products with fast delivery and dependable support.'
));

$heroButtonText = trim((string)(
    $storeRow['hero_button_text']
    ?: 'Explore Products'
));

$heroButtonLink = trim((string)(
    $storeRow['hero_button_link']
    ?: sf_store_url($slug, 'store')
));

$featuredCategoriesTitle = trim((string)(
    $storeRow['featured_categories_title']
    ?: 'Popular Product Categories'
));

$featuredProductsTitle = trim((string)(
    $storeRow['featured_products_title']
    ?: 'Featured Bestsellers'
));

$logoUrl = sf_normalize_image_path(
    $storeRow['logo_url'] ?: $storeRow['setup_logo_url'] ?: $storeRow['business_logo'],
    'https://via.placeholder.com/200x80?text=Logo'
);

$bannerUrl = sf_normalize_image_path(
    $storeRow['banner_url'] ?: $storeRow['setup_banner_url'],
    'https://images.unsplash.com/photo-1581092921461-eab62e97a780?auto=format&fit=crop&w=1600&q=80'
);

$faviconUrl = sf_normalize_image_path(
    $storeRow['favicon_url'] ?? '',
    $logoUrl
);

$supportPhone   = trim((string)($storeRow['support_phone'] ?: $storeRow['business_phone'] ?: ''));
$supportEmail   = trim((string)($storeRow['support_email'] ?: $storeRow['business_email'] ?: ''));
$whatsappNumber = trim((string)($storeRow['whatsapp_number'] ?: ''));
$storeAddress   = trim((string)($storeRow['address'] ?: $storeRow['business_address'] ?: ''));
$footerText     = trim((string)($storeRow['footer_text'] ?: ('© ' . date('Y') . ' ' . $displayName . '. All rights reserved.')));
$aboutUs        = trim((string)($storeRow['about_us'] ?: ''));
$customCss      = trim((string)($storeRow['custom_css'] ?? ''));

$enableCOD           = (int)($storeRow['enable_cod'] ?? 1);
$enableOnlinePayment = (int)($storeRow['enable_online_payment'] ?? 1);
$deliveryCharge      = (float)($storeRow['delivery_charge'] ?? 0);
$freeDeliveryAbove   = (float)($storeRow['free_delivery_above'] ?? 0);

$facebookUrl  = trim((string)($storeRow['facebook_url'] ?? ''));
$instagramUrl = trim((string)($storeRow['instagram_url'] ?? ''));
$youtubeUrl   = trim((string)($storeRow['youtube_url'] ?? ''));

/* =========================================================
   URLS
========================================================= */
$storeUrl            = sf_store_url($slug, 'index');
$homePageUrl         = sf_store_url($slug, 'index');
$storePageUrl        = sf_store_url($slug, 'store');
$categoryPageUrl     = sf_store_url($slug, 'categories');
$contactPageUrl      = sf_store_url($slug, 'contact');
$offersPageUrl       = sf_store_url($slug, 'offers');
$categoryOpenPageUrl = sf_store_url($slug, 'category-open');
$productOpenPageUrl  = sf_store_url($slug, 'product-open');
$cartPageUrl         = sf_store_url($slug, 'cart');
$wishlistPageUrl     = sf_store_url($slug, 'wishlist');
$checkoutPageUrl     = sf_store_url($slug, 'checkout');
$confirmationPageUrl = sf_store_url($slug, 'confirmation');
$trackPageUrl        = sf_store_url($slug, 'track');
$loginPageUrl        = sf_store_url($slug, 'login');
$logoutPageUrl       = sf_store_url($slug, 'logout');

/* =========================================================
   LOAD CATEGORIES
========================================================= */
$categories = [];
$categorySql = "
    SELECT
        c.id,
        c.category_name,
        c.category_code,
        c.description,
        c.parent_id,
        c.category_type,
        c.status,
        (
            SELECT COUNT(*)
            FROM products p2
            WHERE p2.category_id = c.id
              AND p2.business_id = c.business_id
              AND p2.is_active = 1
        ) AS product_count
    FROM categories c
    WHERE c.business_id = :business_id
      AND c.status = 'active'
      AND c.parent_id IS NULL
    ORDER BY c.category_name ASC
";
$stmt = $pdo->prepare($categorySql);
$stmt->execute([':business_id' => $businessId]);
$categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* =========================================================
   LOAD PRODUCTS
========================================================= */
$products = [];
$productSql = "
    SELECT
        p.id,
        p.product_name,
        p.product_code,
        p.barcode,
        p.description,
        p.image_path,
        p.image_thumbnail_path,
        p.image_alt_text,
        p.retail_price,
        p.wholesale_price,
        p.stock_price,
        p.mrp,
        p.category_id,
        p.subcategory_id,
        p.unit_of_measure,
        p.is_active,
        p.created_at,
        p.updated_at,
        c.category_name,
        s.subcategory_name,
        COALESCE(ps.total_stock, 0) AS total_stock
    FROM products p
    LEFT JOIN categories c
        ON c.id = p.category_id
       AND c.business_id = p.business_id
    LEFT JOIN subcategories s
        ON s.id = p.subcategory_id
       AND s.business_id = p.business_id
    LEFT JOIN (
        SELECT
            product_id,
            business_id,
            SUM(quantity) AS total_stock
        FROM product_stocks
        GROUP BY product_id, business_id
    ) ps
        ON ps.product_id = p.id
       AND ps.business_id = p.business_id
    WHERE p.business_id = :business_id
      AND p.is_active = 1
    ORDER BY p.id DESC
";
$stmt = $pdo->prepare($productSql);
$stmt->execute([':business_id' => $businessId]);
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* =========================================================
   COUNTS
========================================================= */
$stmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM products
    WHERE business_id = :business_id
      AND is_active = 1
");
$stmt->execute([':business_id' => $businessId]);
$totalProducts = (int)$stmt->fetchColumn();

$stmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM categories
    WHERE business_id = :business_id
      AND status = 'active'
      AND parent_id IS NULL
");
$stmt->execute([':business_id' => $businessId]);
$totalCategories = (int)$stmt->fetchColumn();

/* =========================================================
   STATIC CONTENT
========================================================= */
$staticFeatures = [
    ['icon' => 'bi-patch-check', 'title' => 'Genuine Quality', 'text' => 'We focus on reliable products with consistent quality and practical value for everyday use.'],
    ['icon' => 'bi-truck', 'title' => 'Fast Delivery', 'text' => 'Quick dispatch and delivery support so customers get their essentials without unnecessary delay.'],
    ['icon' => 'bi-shield-lock', 'title' => 'Secure Checkout', 'text' => 'Simple, secure payment flow with customer-first confidence at every stage of purchase.'],
    ['icon' => 'bi-headset', 'title' => 'Expert Support', 'text' => 'We help customers choose the right product instead of making them guess and regret the purchase later.'],
];

$testimonials = [
    ['name' => 'Ramesh K', 'role' => 'Office Buyer', 'text' => 'Ordered products for office use. Delivery was quick and quality was better than expected for the price.'],
    ['name' => 'Priya S', 'role' => 'Home Customer', 'text' => 'Clean shopping experience and support answered product questions properly before purchase.'],
    ['name' => 'Arun V', 'role' => 'Repeat Buyer', 'text' => 'Good pricing, clean store experience, and packaging was solid. Better than random low-trust sellers.'],
];

$faqs = [
    ['q' => 'Are your products genuine?', 'a' => 'Yes. The storefront should clearly communicate product trust, warranty support, and quality assurance.'],
    ['q' => 'Do you provide delivery support?', 'a' => 'Yes. Fast dispatch and reliable delivery are part of the value proposition and should be visible early on the page.'],
    ['q' => 'Can I contact support before purchasing?', 'a' => 'Yes. Customers should be able to ask product questions before buying.'],
    ['q' => 'Do you offer replacement or after-sales help?', 'a' => 'Eligible products can include support or replacement guidance depending on store policy.'],
];

/* =========================================================
   SECURITY FLAG FOR TEMPLATE FILES
========================================================= */
if (!defined('STORE_FRONT')) {
    define('STORE_FRONT', true);
}

/* =========================================================
   LOGOUT ROUTE
========================================================= */
if ($page === 'logout') {
    unset($_SESSION['customer_id']);
    unset($_SESSION['online_customer_id']);
    unset($_SESSION['storefront_customer_id']);
    unset($_SESSION['store_customer_id']);
    unset($_SESSION['web_customer_id']);
    unset($_SESSION['customer_name']);
    unset($_SESSION['customer_email']);
    unset($_SESSION['customer_phone']);
    unset($_SESSION['customer_is_online']);

    header('Location: ' . sf_store_url($slug, 'login'));
    exit();
}

/* =========================================================
   PAGE ROUTING
========================================================= */
$templateMap = [
    'index'         => __DIR__ . '/online-store/index.php',
    'home'          => __DIR__ . '/online-store/index.php',
    'store'         => __DIR__ . '/online-store/store.php',
    'categories'    => __DIR__ . '/online-store/categories.php',
    'contact'       => __DIR__ . '/online-store/contact.php',
    'offers'        => __DIR__ . '/online-store/offers.php',
    'category-open' => __DIR__ . '/online-store/category-open.php',
    'product-open'  => __DIR__ . '/online-store/product-open.php',
    'cart'          => __DIR__ . '/online-store/cart.php',
    'wishlist'      => __DIR__ . '/online-store/wishlist.php',
    'checkout'      => __DIR__ . '/online-store/checkout.php',
    'confirmation'  => __DIR__ . '/online-store/confirmation.php',
    'track'         => __DIR__ . '/online-store/track.php',
    'login'         => __DIR__ . '/online-store/login.php',
];

/* default page fallback */
if ($page === '') {
    $page = 'index';
}

if (!isset($templateMap[$page])) {
    http_response_code(404);
    exit('Page not found.');
}

$templateFile = $templateMap[$page];

if (!file_exists($templateFile)) {
    http_response_code(404);
    exit('Template file not found.');
}

/* =========================================================
   LOAD PAGE
========================================================= */
require $templateFile;
exit;