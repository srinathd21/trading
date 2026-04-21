<?php
if (!defined('STORE_FRONT')) {
    die('Direct access not allowed.');
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/database.php';

/* =========================
   SAFE FALLBACKS
========================= */
$slug            = $slug ?? '';
$displayName     = $displayName ?? 'Online Store';
$metaTitle       = $metaTitle ?? ('Customer Login | ' . $displayName);
$metaDescription = $metaDescription ?? ('Login to continue shopping at ' . $displayName);
$themeColor      = $themeColor ?? '#e11d48';
$secondaryColor  = $secondaryColor ?? '#111827';
$logoUrl         = $logoUrl ?? 'https://via.placeholder.com/200x80?text=Logo';
$faviconUrl      = $faviconUrl ?? $logoUrl;
$currencySymbol  = $currencySymbol ?? '₹';
$storeUrl        = $storeUrl ?? ('storefront.php?slug=' . urlencode($slug));
$storePageUrl    = $storePageUrl ?? ($storeUrl . '&page=store');
$contactPageUrl  = $contactPageUrl ?? ($storeUrl . '&page=contact');
$customCss       = $customCss ?? '';
$supportPhone    = $supportPhone ?? '';
$supportEmail    = $supportEmail ?? '';
$whatsappNumber  = $whatsappNumber ?? '';
$storeAddress    = $storeAddress ?? '';

/* =========================
   HELPERS
========================= */
if (!function_exists('sf_h')) {
    function sf_h($value): string {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('storefront_login_url')) {
    function storefront_login_url(string $slug, string $redirect = ''): string {
        $url = 'storefront.php?slug=' . urlencode($slug) . '&page=login';
        if ($redirect !== '') {
            $url .= '&redirect=' . urlencode($redirect);
        }
        return $url;
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
   LOGIN FLOW
========================= */
$error = '';
$success = '';
$loginInput = '';
$redirect = trim((string)($_GET['redirect'] ?? $_POST['redirect'] ?? ''));

if ($redirect === '') {
    $redirect = 'storefront.php?slug=' . urlencode((string)$slug) . '&page=store';
}

/* prevent redirect loop */
if (stripos($redirect, '&page=login') !== false) {
    $redirect = 'storefront.php?slug=' . urlencode((string)$slug) . '&page=store';
}

/* if already logged in */
if (!empty($_SESSION['customer_id']) || !empty($_SESSION['online_customer_id']) || !empty($_SESSION['storefront_customer_id'])) {
    header('Location: ' . $redirect);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['customer_login'])) {
    $loginInput = trim((string)($_POST['login'] ?? ''));
    $password   = (string)($_POST['password'] ?? '');

    if ($loginInput === '' || $password === '') {
        $error = 'Please enter email/phone and password.';
    } else {
        try {
            $stmt = $pdo->prepare("
                SELECT *
                FROM customers
                WHERE is_online_customer = 1
                  AND (
                    LOWER(email) = LOWER(?)
                    OR phone = ?
                    OR alt_phone = ?
                  )
                LIMIT 1
            ");
            $stmt->execute([$loginInput, $loginInput, $loginInput]);
            $customer = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$customer) {
                $error = 'Customer account not found.';
            } else {
                $storedPassword = (string)($customer['password'] ?? '');

                if ($storedPassword === '') {
                    $error = 'Password not set for this account.';
                } elseif (!password_verify($password, $storedPassword)) {
                    $error = 'Invalid password.';
                } else {
                    $_SESSION['customer_id'] = (int)$customer['id'];
                    $_SESSION['online_customer_id'] = (int)$customer['id'];
                    $_SESSION['storefront_customer_id'] = (int)$customer['id'];
                    $_SESSION['customer_name'] = (string)($customer['name'] ?? '');
                    $_SESSION['customer_email'] = (string)($customer['email'] ?? '');
                    $_SESSION['customer_phone'] = (string)($customer['phone'] ?? '');
                    $_SESSION['customer_is_online'] = 1;

                    header('Location: ' . $redirect);
                    exit();
                }
            }
        } catch (Exception $e) {
            $error = 'Login failed. Please try again.';
        }
    }
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

  <link rel="stylesheet" href="online-store/assets/main.css">
  <link rel="stylesheet" href="online-store/assets/menu-bar.css">
  <link rel="stylesheet" href="online-store/assets/cart.css">
  <link rel="stylesheet" href="online-store/assets/footer.css">

  <style>
    :root{
      --accent: <?php echo sf_h($themeColor); ?>;
      --primary-dark: <?php echo sf_h($secondaryColor); ?>;
    }

    .login-page-wrap{
      min-height: calc(100vh - 120px);
      background: linear-gradient(135deg, #f8fafc 0%, #eef2ff 100%);
      display:flex;
      align-items:center;
      padding:60px 0;
    }

    .login-card{
      background:#fff;
      border:1px solid #e5e7eb;
      box-shadow:0 15px 40px rgba(0,0,0,0.08);
      border-radius:18px;
      overflow:hidden;
    }

    .login-left{
      background: linear-gradient(135deg, var(--primary-dark) 0%, #1f2937 100%);
      color:#fff;
      padding:42px;
      height:100%;
    }

    .login-left h2{
      font-size:34px;
      font-weight:800;
      margin-bottom:14px;
    }

    .login-left p{
      color:#d1d5db;
      line-height:1.8;
      margin-bottom:24px;
    }

    .login-points{
      list-style:none;
      padding:0;
      margin:0;
    }

    .login-points li{
      display:flex;
      align-items:flex-start;
      gap:10px;
      margin-bottom:14px;
      color:#e5e7eb;
    }

    .login-points i{
      color:#fda4af;
      margin-top:2px;
    }

    .login-right{
      padding:42px 34px;
    }

    .login-logo{
      max-height:56px;
      margin-bottom:18px;
    }

    .login-title{
      font-size:30px;
      font-weight:800;
      color:#111827;
      margin-bottom:8px;
    }

    .login-subtitle{
      color:#6b7280;
      margin-bottom:28px;
    }

    .form-control{
      min-height:48px;
      border-radius:12px;
      border:1px solid #d1d5db;
      padding-left:14px;
      padding-right:14px;
    }

    .form-control:focus{
      border-color:var(--accent);
      box-shadow:0 0 0 0.15rem rgba(225,29,72,0.15);
    }

    .btn-login{
      background:var(--accent);
      color:#fff;
      border:none;
      min-height:48px;
      border-radius:12px;
      font-weight:700;
      width:100%;
    }

    .btn-login:hover{
      color:#fff;
      filter:brightness(0.95);
    }

    .small-link{
      color:var(--accent);
      text-decoration:none;
      font-weight:600;
    }

    .small-link:hover{
      text-decoration:underline;
      color:var(--accent);
    }

    @media (max-width: 991.98px){
      .login-left{
        padding:28px;
      }
      .login-right{
        padding:28px 22px;
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

<section class="login-page-wrap">
  <div class="container-fluid px-lg-5 px-3">
    <div class="row justify-content-center">
      <div class="col-xl-10 col-lg-11">
        <div class="login-card">
          <div class="row g-0">
            <div class="col-lg-6 d-none d-lg-block">
              <div class="login-left">
                <h2>Welcome Back</h2>
                <p>Login to continue shopping, track your orders, save cart items, and complete checkout smoothly.</p>

                <ul class="login-points">
                  <li><i class="bi bi-check-circle-fill"></i><span>Access your online customer account</span></li>
                  <li><i class="bi bi-check-circle-fill"></i><span>Track orders and purchase history</span></li>
                  <li><i class="bi bi-check-circle-fill"></i><span>Fast checkout for repeat customers</span></li>
                  <li><i class="bi bi-check-circle-fill"></i><span>Secure login using your account password</span></li>
                </ul>
              </div>
            </div>

            <div class="col-lg-6">
              <div class="login-right">
                <?php if ($logoUrl !== ''): ?>
                  <img src="<?php echo sf_h($logoUrl); ?>" alt="<?php echo sf_h($displayName); ?>" class="login-logo">
                <?php endif; ?>

                <div class="login-title">Customer Login</div>
                <div class="login-subtitle">Use your registered email or phone number to continue.</div>

                <?php if ($error !== ''): ?>
                  <div class="alert alert-danger"><?php echo sf_h($error); ?></div>
                <?php endif; ?>

                <?php if ($success !== ''): ?>
                  <div class="alert alert-success"><?php echo sf_h($success); ?></div>
                <?php endif; ?>

                <form method="POST" autocomplete="off">
                  <input type="hidden" name="redirect" value="<?php echo sf_h($redirect); ?>">

                  <div class="mb-3">
                    <label class="form-label">Email or Phone</label>
                    <input
                      type="text"
                      name="login"
                      class="form-control"
                      placeholder="Enter email or phone"
                      value="<?php echo sf_h($loginInput); ?>"
                      required
                    >
                  </div>

                  <div class="mb-3">
                    <label class="form-label">Password</label>
                    <input
                      type="password"
                      name="password"
                      class="form-control"
                      placeholder="Enter password"
                      required
                    >
                  </div>

                  <div class="mb-3 d-flex justify-content-between align-items-center">
                    <div class="text-muted small">
                      Only online customer accounts can login
                    </div>
                    <a href="<?php echo sf_h($contactPageUrl); ?>" class="small-link">Need help?</a>
                  </div>

                  <button type="submit" name="customer_login" class="btn btn-login">
                    Login
                  </button>
                </form>

                <div class="mt-4 text-center text-muted">
  Back to store?
  <a href="<?php echo sf_h($storePageUrl); ?>" class="small-link">Continue Shopping</a>
</div>

<div class="mt-3 text-center">
  <a href="storefront.php?slug=<?php echo urlencode($slug); ?>&page=signup&redirect=<?php echo urlencode($redirect); ?>" class="btn btn-outline-dark">
    Create Account
  </a>
</div>

                <?php if ($supportPhone !== '' || $supportEmail !== ''): ?>
                  <div class="mt-4 pt-3 border-top">
                    <div class="small text-muted mb-2">Support</div>
                    <?php if ($supportPhone !== ''): ?>
                      <div><i class="bi bi-telephone me-2"></i><?php echo sf_h($supportPhone); ?></div>
                    <?php endif; ?>
                    <?php if ($supportEmail !== ''): ?>
                      <div><i class="bi bi-envelope me-2"></i><?php echo sf_h($supportEmail); ?></div>
                    <?php endif; ?>
                  </div>
                <?php endif; ?>

              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>

<?php if (file_exists($footerFile)) include $footerFile; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="online-store/assets/menu.js"></script>
<script src="online-store/assets/cart.js"></script>
<script src="online-store/assets/main.js"></script>
</body>
</html>