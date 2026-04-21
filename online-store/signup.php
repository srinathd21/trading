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
$metaTitle       = $metaTitle ?? ('Customer Sign Up | ' . $displayName);
$metaDescription = $metaDescription ?? ('Create your customer account at ' . $displayName);
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

if (!function_exists('sf_only_digits')) {
    function sf_only_digits(string $value): string {
        return preg_replace('/\D+/', '', $value);
    }
}

if (!function_exists('sf_column_exists')) {
    function sf_column_exists(PDO $pdo, string $table, string $column): bool {
        $sql = "
            SELECT COUNT(*) 
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = :table_name
              AND COLUMN_NAME = :column_name
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':table_name'  => $table,
            ':column_name' => $column
        ]);
        return (int)$stmt->fetchColumn() > 0;
    }
}
/* =========================
   LOGIN CHECK
========================= */
$redirect = trim((string)($_GET['redirect'] ?? $_POST['redirect'] ?? ''));

if ($redirect === '') {
    $redirect = 'storefront.php?slug=' . urlencode((string)$slug) . '&page=store';
}

if (stripos($redirect, '&page=login') !== false || stripos($redirect, '&page=signup') !== false) {
    $redirect = 'storefront.php?slug=' . urlencode((string)$slug) . '&page=store';
}

if (!empty($_SESSION['customer_id']) || !empty($_SESSION['online_customer_id']) || !empty($_SESSION['storefront_customer_id'])) {
    header('Location: ' . $redirect);
    exit();
}

/* =========================
   FORM VALUES
========================= */
$error = '';
$success = '';

$form = [
    'name'          => '',
    'phone'         => '',
    'alt_phone'     => '',
    'email'         => '',
    'address'       => '',
    'customer_type' => 'retail'
];

/* =========================
   SIGNUP
========================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['customer_signup'])) {
    $form['name']          = trim((string)($_POST['name'] ?? ''));
    $form['phone']         = trim((string)($_POST['phone'] ?? ''));
    $form['alt_phone']     = trim((string)($_POST['alt_phone'] ?? ''));
    $form['email']         = trim((string)($_POST['email'] ?? ''));
    $form['address']       = trim((string)($_POST['address'] ?? ''));
    $form['customer_type'] = trim((string)($_POST['customer_type'] ?? 'retail'));

    $password         = (string)($_POST['password'] ?? '');
    $confirm_password = (string)($_POST['confirm_password'] ?? '');

    $phoneDigits = sf_only_digits($form['phone']);

    if ($form['name'] === '') {
        $error = 'Please enter customer name.';
    } elseif ($form['phone'] === '') {
        $error = 'Please enter phone number.';
    } elseif (strlen($phoneDigits) < 10) {
        $error = 'Please enter valid phone number.';
    } elseif ($form['email'] !== '' && !filter_var($form['email'], FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter valid email address.';
    } elseif (!in_array($form['customer_type'], ['retail', 'wholesale'], true)) {
        $error = 'Invalid customer type.';
    } elseif ($password === '') {
        $error = 'Please enter password.';
    } elseif (strlen($password) < 6) {
        $error = 'Password must be at least 6 characters.';
    } elseif ($confirm_password === '') {
        $error = 'Please confirm password.';
    } elseif ($password !== $confirm_password) {
        $error = 'Password and confirm password do not match.';
    } else {
        try {
            $businessIdValue = 0;

            if (isset($businessId) && (int)$businessId > 0) {
                $businessIdValue = (int)$businessId;
            } elseif (isset($storeRow['business_id']) && (int)$storeRow['business_id'] > 0) {
                $businessIdValue = (int)$storeRow['business_id'];
            }

            if ($businessIdValue <= 0) {
                throw new Exception('Business ID not found.');
            }

            if (!sf_column_exists($pdo, 'customers', 'password')) {
                throw new Exception("Column 'password' not found in customers table.");
            }

            if (!sf_column_exists($pdo, 'customers', 'is_online_customer')) {
                throw new Exception("Column 'is_online_customer' not found in customers table.");
            }

            $checkSql = "
                SELECT id, name, phone, email
                FROM customers
                WHERE phone = :phone
                   OR (:email <> '' AND LOWER(email) = LOWER(:email))
                LIMIT 1
            ";
            $checkStmt = $pdo->prepare($checkSql);
            $checkStmt->execute([
                ':phone' => $form['phone'],
                ':email' => $form['email']
            ]);
            $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);

            if ($existing) {
                if (!empty($existing['phone']) && sf_only_digits((string)$existing['phone']) === $phoneDigits) {
                    $error = 'This phone number is already registered.';
                } else {
                    $error = 'This email is already registered.';
                }
            } else {
                $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

                $insertSql = "
                    INSERT INTO customers (
                        business_id,
                        customer_type,
                        name,
                        phone,
                        alt_phone,
                        email,
                        password,
                        is_online_customer,
                        address,
                        outstanding_type,
                        outstanding_amount,
                        created_at
                    ) VALUES (
                        :business_id,
                        :customer_type,
                        :name,
                        :phone,
                        :alt_phone,
                        :email,
                        :password,
                        :is_online_customer,
                        :address,
                        'credit',
                        0.00,
                        NOW()
                    )
                ";

                $insertStmt = $pdo->prepare($insertSql);
                $insertStmt->execute([
                    ':business_id'        => $businessIdValue,
                    ':customer_type'      => $form['customer_type'],
                    ':name'               => $form['name'],
                    ':phone'              => $form['phone'],
                    ':alt_phone'          => $form['alt_phone'] !== '' ? $form['alt_phone'] : null,
                    ':email'              => $form['email'] !== '' ? $form['email'] : null,
                    ':password'           => $hashedPassword,
                    ':is_online_customer' => 1,
                    ':address'            => $form['address'] !== '' ? $form['address'] : null,
                ]);

                $newCustomerId = (int)$pdo->lastInsertId();

                $_SESSION['customer_id'] = $newCustomerId;
                $_SESSION['online_customer_id'] = $newCustomerId;
                $_SESSION['storefront_customer_id'] = $newCustomerId;
                $_SESSION['customer_name'] = $form['name'];
                $_SESSION['customer_email'] = $form['email'];
                $_SESSION['customer_phone'] = $form['phone'];
                $_SESSION['customer_is_online'] = 1;

                header('Location: ' . $redirect);
                exit();
            }
        } catch (Exception $e) {
            $error = 'Sign up failed: ' . $e->getMessage();
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

    .signup-page-wrap{
      min-height: calc(100vh - 120px);
      background: linear-gradient(135deg, #f8fafc 0%, #eef2ff 100%);
      display:flex;
      align-items:center;
      padding:60px 0;
    }

    .signup-card{
      background:#fff;
      border:1px solid #e5e7eb;
      box-shadow:0 15px 40px rgba(0,0,0,0.08);
      border-radius:18px;
      overflow:hidden;
    }

    .signup-left{
      background: linear-gradient(135deg, var(--primary-dark) 0%, #1f2937 100%);
      color:#fff;
      padding:42px;
      height:100%;
    }

    .signup-left h2{
      font-size:34px;
      font-weight:800;
      margin-bottom:14px;
    }

    .signup-left p{
      color:#d1d5db;
      line-height:1.8;
      margin-bottom:24px;
    }

    .signup-points{
      list-style:none;
      padding:0;
      margin:0;
    }

    .signup-points li{
      display:flex;
      align-items:flex-start;
      gap:10px;
      margin-bottom:14px;
      color:#e5e7eb;
    }

    .signup-points i{
      color:#86efac;
      margin-top:2px;
    }

    .signup-right{
      padding:42px 34px;
    }

    .signup-logo{
      max-height:56px;
      margin-bottom:18px;
    }

    .signup-title{
      font-size:30px;
      font-weight:800;
      color:#111827;
      margin-bottom:8px;
    }

    .signup-subtitle{
      color:#6b7280;
      margin-bottom:28px;
    }

    .form-control,
    .form-select{
      min-height:48px;
      border-radius:12px;
      border:1px solid #d1d5db;
      padding-left:14px;
      padding-right:14px;
    }

    .form-control:focus,
    .form-select:focus{
      border-color:var(--accent);
      box-shadow:0 0 0 0.15rem rgba(225,29,72,0.15);
    }

    textarea.form-control{
      min-height:90px;
      padding-top:12px;
    }

    .btn-signup{
      background:var(--accent);
      color:#fff;
      border:none;
      min-height:48px;
      border-radius:12px;
      font-weight:700;
      width:100%;
    }

    .btn-signup:hover{
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
      .signup-left{
        padding:28px;
      }
      .signup-right{
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

<section class="signup-page-wrap">
  <div class="container-fluid px-lg-5 px-3">
    <div class="row justify-content-center">
      <div class="col-xl-11 col-lg-12">
        <div class="signup-card">
          <div class="row g-0">
            <div class="col-lg-5 d-none d-lg-block">
              <div class="signup-left">
                <h2>Create Account</h2>
                <p>Register your online customer account to shop faster, manage orders, and continue checkout smoothly.</p>

                <ul class="signup-points">
                  <li><i class="bi bi-check-circle-fill"></i><span>Quick access to your storefront account</span></li>
                  <li><i class="bi bi-check-circle-fill"></i><span>Track orders and purchase history</span></li>
                  <li><i class="bi bi-check-circle-fill"></i><span>Easy checkout for repeat purchases</span></li>
                  <li><i class="bi bi-check-circle-fill"></i><span>Secure password protected customer login</span></li>
                </ul>
              </div>
            </div>

            <div class="col-lg-7">
              <div class="signup-right">
                <?php if ($logoUrl !== ''): ?>
                  <img src="<?php echo sf_h($logoUrl); ?>" alt="<?php echo sf_h($displayName); ?>" class="signup-logo">
                <?php endif; ?>

                <div class="signup-title">Customer Sign Up</div>
                <div class="signup-subtitle">Create your account to continue shopping.</div>

                <?php if ($error !== ''): ?>
                  <div class="alert alert-danger"><?php echo sf_h($error); ?></div>
                <?php endif; ?>

                <form method="POST" autocomplete="off">
                  <input type="hidden" name="redirect" value="<?php echo sf_h($redirect); ?>">

                  <div class="row g-3">
                    <div class="col-md-6">
                      <label class="form-label">Full Name <span class="text-danger">*</span></label>
                      <input type="text" name="name" class="form-control" value="<?php echo sf_h($form['name']); ?>" required>
                    </div>

                    <div class="col-md-6">
                      <label class="form-label">Customer Type</label>
                      <select name="customer_type" class="form-select">
                        <option value="retail" <?php echo $form['customer_type'] === 'retail' ? 'selected' : ''; ?>>Retail</option>
                        <option value="wholesale" <?php echo $form['customer_type'] === 'wholesale' ? 'selected' : ''; ?>>Wholesale</option>
                      </select>
                    </div>

                    <div class="col-md-6">
                      <label class="form-label">Phone <span class="text-danger">*</span></label>
                      <input type="text" name="phone" class="form-control" value="<?php echo sf_h($form['phone']); ?>" required>
                    </div>

                    <div class="col-md-6">
                      <label class="form-label">Alt Phone</label>
                      <input type="text" name="alt_phone" class="form-control" value="<?php echo sf_h($form['alt_phone']); ?>">
                    </div>

                    <div class="col-md-6">
                      <label class="form-label">Email</label>
                      <input type="email" name="email" class="form-control" value="<?php echo sf_h($form['email']); ?>">
                    </div>

                    <div class="col-md-6">
                      <label class="form-label">Password <span class="text-danger">*</span></label>
                      <input type="password" name="password" class="form-control" required>
                    </div>

                    <div class="col-md-6">
                      <label class="form-label">Confirm Password <span class="text-danger">*</span></label>
                      <input type="password" name="confirm_password" class="form-control" required>
                    </div>

                    <div class="col-12">
                      <label class="form-label">Address</label>
                      <textarea name="address" class="form-control"><?php echo sf_h($form['address']); ?></textarea>
                    </div>

                    <div class="col-12">
                      <button type="submit" name="customer_signup" class="btn btn-signup">
                        Create Account
                      </button>
                    </div>
                  </div>
                </form>

                <div class="mt-4 text-center text-muted">
                  Already have an account?
                  <a href="storefront.php?slug=<?php echo urlencode($slug); ?>&page=login&redirect=<?php echo urlencode($redirect); ?>" class="small-link">Login</a>
                </div>

                <div class="mt-2 text-center text-muted">
                  Back to store?
                  <a href="<?php echo sf_h($storePageUrl); ?>" class="small-link">Continue Shopping</a>
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