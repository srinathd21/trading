<?php
if (!function_exists('store_h')) {
    function store_h($value) {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$currentSlug = trim((string)($_GET['slug'] ?? ''));

$baseStoreUrl = 'storefront.php';
if ($currentSlug !== '') {
    $baseStoreUrl .= '?slug=' . urlencode($currentSlug);
}

function store_page_url(string $page = '', array $extra = []): string
{
    $slug = trim((string)($_GET['slug'] ?? ''));
    $params = [];

    if ($slug !== '') {
        $params['slug'] = $slug;
    }

    if ($page !== '') {
        $params['page'] = $page;
    }

    foreach ($extra as $key => $value) {
        if ($value !== null && $value !== '') {
            $params[$key] = $value;
        }
    }

    return 'storefront.php' . (!empty($params) ? '?' . http_build_query($params) : '');
}

$currentPage = trim((string)($_GET['page'] ?? 'home'));
if ($currentPage === '') {
    $currentPage = 'home';
}

$storeBrandName = '';
if (isset($displayName) && trim((string)$displayName) !== '') {
    $storeBrandName = trim((string)$displayName);
} elseif (isset($store['display_name']) && trim((string)$store['display_name']) !== '') {
    $storeBrandName = trim((string)$store['display_name']);
} elseif (isset($store['store_title']) && trim((string)$store['store_title']) !== '') {
    $storeBrandName = trim((string)$store['store_title']);
} else {
    $storeBrandName = 'ONLINE STORE';
}

/* customer login session */
$isCustomerLoggedIn = false;
$customerName = '';

if (!empty($_SESSION['customer_id']) || !empty($_SESSION['online_customer_id']) || !empty($_SESSION['storefront_customer_id'])) {
    $isCustomerLoggedIn = true;
    $customerName = trim((string)($_SESSION['customer_name'] ?? 'Customer'));
}

$homeUrl       = store_page_url();
$shopUrl       = store_page_url('store');
$categoriesUrl = store_page_url('categories');
$loginUrl      = store_page_url('login', ['redirect' => store_page_url($currentPage, $_GET)]);
$logoutUrl     = store_page_url('logout');
?>

<nav class="navbar main-navbar sticky-top">
  <div class="container-fluid px-lg-5 px-3">
    <a class="navbar-brand" href="<?php echo store_h($homeUrl); ?>">
      <?php echo store_h(strtoupper($storeBrandName)); ?>
    </a>

    <div class="mobile-nav-actions ms-auto">
      <button
        type="button"
        id="mobileCartBtn"
        data-bs-toggle="offcanvas"
        data-bs-target="#sideCart"
        aria-controls="sideCart"
      >
        <i class="bi bi-cart3"></i>
        <span class="cart-count-badge" id="mobileCartCount">0</span>
      </button>

      <button
        class="menu-toggle-btn border-0 shadow-none"
        type="button"
        data-bs-toggle="offcanvas"
        data-bs-target="#mobileMenu"
        aria-controls="mobileMenu"
        aria-label="Open menu"
      >
        <i class="bi bi-list fs-5"></i>
      </button>
    </div>

    <div class="desktop-nav-wrapper">
      <ul class="navbar-nav mx-auto mb-0 flex-row">
        <li class="nav-item">
          <a class="nav-link <?php echo ($currentPage === 'home' || $currentPage === 'index') ? 'active' : ''; ?>" href="<?php echo store_h($homeUrl); ?>">
            Home
          </a>
        </li>
        <li class="nav-item">
          <a class="nav-link <?php echo $currentPage === 'store' ? 'active' : ''; ?>" href="<?php echo store_h($shopUrl); ?>">
            Shop
          </a>
        </li>
        <li class="nav-item">
          <a class="nav-link <?php echo $currentPage === 'categories' ? 'active' : ''; ?>" href="<?php echo store_h($categoriesUrl); ?>">
            Categories
          </a>
        </li>
      </ul>

      <div class="nav-icons d-flex align-items-center ms-auto">
        <?php if ($isCustomerLoggedIn): ?>
          <div class="d-flex align-items-center ms-2">
            <span class="me-2 fw-semibold text-dark">
              <i class="bi bi-person-circle me-1"></i><?php echo store_h($customerName); ?>
            </span>
            <a href="<?php echo store_h($logoutUrl); ?>" class="btn btn-sm btn-outline-dark">
              Logout
            </a>
          </div>
        <?php else: ?>
          <div class="d-flex align-items-center ms-2 gap-2">
    <a href="<?php echo store_h($loginUrl); ?>" class="btn btn-sm btn-outline-dark">Login</a>
    <a href="<?php echo store_h(store_page_url('signup')); ?>" class="btn btn-sm btn-dark">Sign Up</a>
</div>
        <?php endif; ?>

        <button
          type="button"
          id="openCartBtn"
          data-bs-toggle="offcanvas"
          data-bs-target="#sideCart"
          aria-controls="#sideCart"
          aria-label="Open cart"
        >
          <i class="bi bi-cart3"></i>
          <span class="cart-count-badge" id="cartCount">0</span>
        </button>
      </div>
    </div>
  </div>
</nav>