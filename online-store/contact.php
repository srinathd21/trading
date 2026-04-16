<?php
if (!defined('STORE_FRONT')) {
    die('Direct access not allowed.');
}

/*
|--------------------------------------------------------------------------
| Expected from storefront.php
|--------------------------------------------------------------------------
| $slug
| $displayName
| $tagline
| $metaTitle
| $metaDescription
| $themeColor
| $secondaryColor
| $bannerUrl
| $logoUrl
| $faviconUrl
| $supportPhone
| $supportEmail
| $whatsappNumber
| $storeAddress
| $footerText
| $currencySymbol
| $storeUrl
| $storePageUrl
| $categoryPageUrl
| $contactPageUrl
| $customCss
*/

$slug            = $slug ?? '';
$displayName     = $displayName ?? 'Online Store';
$metaTitle       = !empty($metaTitle) ? ('Contact Us | ' . $displayName) : ('Contact Us | ' . $displayName);
$metaDescription = $metaDescription ?? ('Contact ' . $displayName . ' for support, orders, enquiries, and assistance.');
$themeColor      = $themeColor ?? '#e11d48';
$secondaryColor  = $secondaryColor ?? '#111827';
$faviconUrl      = $faviconUrl ?? $logoUrl ?? 'https://via.placeholder.com/64x64?text=Logo';
$supportPhone    = $supportPhone ?? '';
$supportEmail    = $supportEmail ?? '';
$whatsappNumber  = $whatsappNumber ?? '';
$storeAddress    = $storeAddress ?? '';
$footerText      = $footerText ?? ('© ' . date('Y') . ' ' . $displayName . '. All rights reserved.');
$currencySymbol  = $currencySymbol ?? '₹';
$storeUrl        = $storeUrl ?? ('storefront.php?slug=' . urlencode($slug));
$storePageUrl    = $storePageUrl ?? ($storeUrl . '&page=store');
$categoryPageUrl = $categoryPageUrl ?? ($storeUrl . '&page=categories');
$contactPageUrl  = $contactPageUrl ?? ($storeUrl . '&page=contact');
$customCss       = $customCss ?? '';

$storefront_slug             = $slug;
$storefront_display_name     = $displayName;
$storefront_logo             = $logoUrl ?? '';
$storefront_phone            = $supportPhone;
$storefront_email            = $supportEmail;
$storefront_whatsapp         = $whatsappNumber;
$storefront_address          = $storeAddress;
$storefront_currency         = $currencySymbol;
$storefront_store_url        = $storeUrl;
$storefront_store_page_url   = $storePageUrl;
$storefront_contact_page_url = $contactPageUrl;

$contact_success = '';
$contact_error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $full_name = trim($_POST['full_name'] ?? '');
    $phone     = trim($_POST['phone'] ?? '');
    $email     = trim($_POST['email'] ?? '');
    $subject   = trim($_POST['subject'] ?? '');
    $message   = trim($_POST['message'] ?? '');

    if ($full_name === '' || $phone === '' || $message === '') {
        $contact_error = 'Name, phone number, and message are required.';
    } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $contact_error = 'Invalid email address.';
    } else {
        // Right now only frontend confirmation.
        // Later you can store in DB or send mail from here.
        $contact_success = 'Your enquiry has been submitted successfully.';
    }
}

$topStripFile   = __DIR__ . '/includes/top-strip.php';
$navFile        = __DIR__ . '/includes/nav.php';
$mobileNavFile  = __DIR__ . '/includes/mobile-nav.php';
$footerFile     = __DIR__ . '/includes/footer.php';

$whatsappLink = '';
if (!empty($whatsappNumber)) {
    $wa = preg_replace('/\D+/', '', $whatsappNumber);
    if ($wa !== '') {
        if (strlen($wa) === 10) {
            $wa = '91' . $wa;
        }
        $whatsappLink = 'https://wa.me/' . $wa;
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
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">

  <link rel="stylesheet" href="online-store/assets/main.css">
  <link rel="stylesheet" href="online-store/assets/menu-bar.css">
  <link rel="stylesheet" href="online-store/assets/footer.css">

  <style>
    :root{
      --accent: <?php echo sf_h($themeColor); ?>;
      --primary-dark: <?php echo sf_h($secondaryColor); ?>;
    }

    .contact-hero{
      background:
        linear-gradient(rgba(17,24,39,0.84), rgba(17,24,39,0.72)),
        url('https://images.unsplash.com/photo-1516321318423-f06f85e504b3?auto=format&fit=crop&w=1600&q=80') center/cover no-repeat;
      color:#fff;
      padding:90px 0;
      text-align:center;
    }

    .contact-hero h1{
      font-size:48px;
      font-weight:800;
      margin-bottom:14px;
    }

    .contact-hero p{
      max-width:760px;
      margin:0 auto;
      color:#e5e7eb;
      line-height:1.8;
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

    .contact-card,
    .contact-form-card{
      border:1px solid #e5e7eb;
      background:#fff;
      height:100%;
    }

    .contact-card{
      padding:28px 24px;
    }

    .contact-card h3,
    .contact-form-card h3{
      font-size:28px;
      font-weight:800;
      color:#111827;
      margin-bottom:12px;
    }

    .contact-card > p,
    .contact-form-card > p{
      color:#6b7280;
      line-height:1.8;
      font-size:14px;
      margin-bottom:22px;
    }

    .contact-info-item{
      display:flex;
      gap:14px;
      align-items:flex-start;
      padding:16px 0;
      border-top:1px solid #e5e7eb;
    }

    .contact-info-item:first-of-type{
      border-top:none;
      padding-top:0;
    }

    .contact-icon{
      width:50px;
      height:50px;
      flex-shrink:0;
      display:flex;
      align-items:center;
      justify-content:center;
      background:#111827;
      color:#fff;
      font-size:20px;
    }

    .contact-info-item h5{
      font-size:17px;
      font-weight:700;
      color:#111827;
      margin-bottom:6px;
    }

    .contact-info-item p,
    .contact-info-item a{
      margin:0;
      color:#6b7280;
      font-size:14px;
      line-height:1.8;
      text-decoration:none;
    }

    .contact-info-item a:hover{
      color:var(--accent);
    }

    .contact-form-card{
      padding:30px 26px;
    }

    .form-label{
      font-size:14px;
      font-weight:700;
      color:#111827;
      margin-bottom:8px;
    }

    .form-control,
    .form-select{
      min-height:48px;
      border-radius:0;
      border:1px solid #d1d5db;
      box-shadow:none !important;
      font-size:14px;
    }

    textarea.form-control{
      min-height:140px;
      resize:none;
    }

    .form-control:focus,
    .form-select:focus{
      border-color:#111827;
    }

    .btn-contact-submit{
      background:var(--accent);
      color:#fff;
      border:none;
      padding:14px 28px;
      font-size:15px;
      font-weight:700;
      transition:0.3s ease;
    }

    .btn-contact-submit:hover{
      filter:brightness(0.92);
      color:#fff;
    }

    .map-card{
      border:1px solid #e5e7eb;
      background:#fff;
      overflow:hidden;
    }

    .map-placeholder{
      min-height:380px;
      background:
        linear-gradient(rgba(17,24,39,0.08), rgba(17,24,39,0.08)),
        url('https://images.unsplash.com/photo-1524661135-423995f22d0b?auto=format&fit=crop&w=1600&q=80') center/cover no-repeat;
      display:flex;
      align-items:center;
      justify-content:center;
      text-align:center;
      padding:30px;
    }

    .map-overlay-box{
      background:rgba(255,255,255,0.94);
      padding:26px 24px;
      max-width:460px;
      border:1px solid #e5e7eb;
    }

    .map-overlay-box h4{
      font-size:24px;
      font-weight:800;
      color:#111827;
      margin-bottom:10px;
    }

    .map-overlay-box p{
      margin:0;
      color:#6b7280;
      line-height:1.8;
      font-size:14px;
    }

    .contact-support-strip{
      background:#111827;
      color:#fff;
    }

    .support-box{
      text-align:center;
      padding:10px 18px;
    }

    .support-box i{
      font-size:30px;
      color:#fda4af;
      margin-bottom:12px;
    }

    .support-box h5{
      font-size:18px;
      font-weight:700;
      margin-bottom:8px;
    }

    .support-box p{
      margin:0;
      color:#d1d5db;
      font-size:14px;
      line-height:1.7;
    }

    .alert-contact{
      border-radius:0;
      font-size:14px;
    }

    @media (max-width: 991.98px){
      .contact-hero h1{
        font-size:38px;
      }
    }

    @media (max-width: 767.98px){
      .contact-hero{
        padding:70px 0;
      }

      .contact-hero h1{
        font-size:30px;
      }

      .contact-card h3,
      .contact-form-card h3{
        font-size:24px;
      }

      .map-placeholder{
        min-height:300px;
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

<section class="contact-hero">
  <div class="container-fluid px-lg-5 px-3">
    <h1>Contact <?php echo sf_h($displayName); ?></h1>
    <p>
      Need help with products, orders, support, or bulk enquiries? Reach out and get a direct response.
    </p>
  </div>
</section>

<section class="breadcrumb-wrap">
  <div class="container-fluid px-lg-5 px-3">
    <nav aria-label="breadcrumb">
      <ol class="breadcrumb breadcrumb-custom">
        <li class="breadcrumb-item"><a href="<?php echo sf_h($storeUrl); ?>">Home</a></li>
        <li class="breadcrumb-item active" aria-current="page">Contact</li>
      </ol>
    </nav>
  </div>
</section>

<section class="section-space">
  <div class="container-fluid px-lg-5 px-3">
    <div class="row g-4">
      <div class="col-lg-5">
        <div class="contact-card">
          <h3>Get in Touch</h3>
          <p>
            Contact us for product guidance, support requests, pricing questions, delivery issues, or business enquiries.
          </p>

          <?php if ($storeAddress !== ''): ?>
          <div class="contact-info-item">
            <div class="contact-icon">
              <i class="bi bi-geo-alt"></i>
            </div>
            <div>
              <h5>Store Address</h5>
              <p><?php echo nl2br(sf_h($storeAddress)); ?></p>
            </div>
          </div>
          <?php endif; ?>

          <?php if ($supportPhone !== ''): ?>
          <div class="contact-info-item">
            <div class="contact-icon">
              <i class="bi bi-telephone"></i>
            </div>
            <div>
              <h5>Phone Number</h5>
              <p><a href="tel:<?php echo sf_h($supportPhone); ?>"><?php echo sf_h($supportPhone); ?></a></p>
            </div>
          </div>
          <?php endif; ?>

          <?php if ($supportEmail !== ''): ?>
          <div class="contact-info-item">
            <div class="contact-icon">
              <i class="bi bi-envelope"></i>
            </div>
            <div>
              <h5>Email Address</h5>
              <p><a href="mailto:<?php echo sf_h($supportEmail); ?>"><?php echo sf_h($supportEmail); ?></a></p>
            </div>
          </div>
          <?php endif; ?>

          <?php if ($whatsappLink !== ''): ?>
          <div class="contact-info-item">
            <div class="contact-icon">
              <i class="bi bi-whatsapp"></i>
            </div>
            <div>
              <h5>WhatsApp</h5>
              <p><a href="<?php echo sf_h($whatsappLink); ?>" target="_blank"><?php echo sf_h($whatsappNumber); ?></a></p>
            </div>
          </div>
          <?php endif; ?>

          <div class="contact-info-item">
            <div class="contact-icon">
              <i class="bi bi-clock"></i>
            </div>
            <div>
              <h5>Working Hours</h5>
              <p>Monday - Saturday: 9:00 AM to 8:00 PM</p>
              <p>Sunday: 10:00 AM to 5:00 PM</p>
            </div>
          </div>
        </div>
      </div>

      <div class="col-lg-7">
        <div class="contact-form-card">
          <h3>Send Us a Message</h3>
          <p>
            Fill the form below. Keep the message specific. Vague submissions waste time.
          </p>

          <?php if ($contact_success !== ''): ?>
            <div class="alert alert-success alert-contact"><?php echo sf_h($contact_success); ?></div>
          <?php endif; ?>

          <?php if ($contact_error !== ''): ?>
            <div class="alert alert-danger alert-contact"><?php echo sf_h($contact_error); ?></div>
          <?php endif; ?>

          <form action="" method="post">
            <div class="row g-3">
              <div class="col-md-6">
                <label class="form-label" for="full_name">Full Name</label>
                <input type="text" class="form-control" id="full_name" name="full_name" placeholder="Enter your full name" value="<?php echo sf_h($_POST['full_name'] ?? ''); ?>">
              </div>

              <div class="col-md-6">
                <label class="form-label" for="phone">Phone Number</label>
                <input type="text" class="form-control" id="phone" name="phone" placeholder="Enter your phone number" value="<?php echo sf_h($_POST['phone'] ?? ''); ?>">
              </div>

              <div class="col-md-6">
                <label class="form-label" for="email">Email Address</label>
                <input type="email" class="form-control" id="email" name="email" placeholder="Enter your email address" value="<?php echo sf_h($_POST['email'] ?? ''); ?>">
              </div>

              <div class="col-md-6">
                <label class="form-label" for="subject">Subject</label>
                <select class="form-select" id="subject" name="subject">
                  <option value="">Select subject</option>
                  <option value="product-enquiry" <?php echo (($_POST['subject'] ?? '') === 'product-enquiry') ? 'selected' : ''; ?>>Product Enquiry</option>
                  <option value="order-support" <?php echo (($_POST['subject'] ?? '') === 'order-support') ? 'selected' : ''; ?>>Order Support</option>
                  <option value="delivery-question" <?php echo (($_POST['subject'] ?? '') === 'delivery-question') ? 'selected' : ''; ?>>Delivery Question</option>
                  <option value="bulk-enquiry" <?php echo (($_POST['subject'] ?? '') === 'bulk-enquiry') ? 'selected' : ''; ?>>Bulk Enquiry</option>
                  <option value="general-support" <?php echo (($_POST['subject'] ?? '') === 'general-support') ? 'selected' : ''; ?>>General Support</option>
                </select>
              </div>

              <div class="col-12">
                <label class="form-label" for="message">Message</label>
                <textarea class="form-control" id="message" name="message" placeholder="Write your message clearly"><?php echo sf_h($_POST['message'] ?? ''); ?></textarea>
              </div>

              <div class="col-12">
                <button type="submit" class="btn-contact-submit">Submit Enquiry</button>
              </div>
            </div>
          </form>
        </div>
      </div>
    </div>
  </div>
</section>

<section class="section-space pt-0">
  <div class="container-fluid px-lg-5 px-3">
    <div class="map-card">
      <div class="map-placeholder">
        <div class="map-overlay-box">
          <h4>Visit Our Store</h4>
          <p>
            <?php echo $storeAddress !== '' ? nl2br(sf_h($storeAddress)) : 'Add your actual store address here.'; ?>
          </p>
        </div>
      </div>
    </div>
  </div>
</section>

<section class="section-space contact-support-strip">
  <div class="container-fluid px-lg-5 px-3">
    <div class="row g-4">
      <div class="col-md-6 col-lg-3">
        <div class="support-box">
          <i class="bi bi-headset"></i>
          <h5>Customer Support</h5>
          <p>Fast support for enquiries, issues, and purchase assistance.</p>
        </div>
      </div>

      <div class="col-md-6 col-lg-3">
        <div class="support-box">
          <i class="bi bi-box-seam"></i>
          <h5>Order Guidance</h5>
          <p>Help with order-related questions, updates, and basic coordination.</p>
        </div>
      </div>

      <div class="col-md-6 col-lg-3">
        <div class="support-box">
          <i class="bi bi-truck"></i>
          <h5>Delivery Help</h5>
          <p>Support regarding dispatch, shipping flow, and delivery clarifications.</p>
        </div>
      </div>

      <div class="col-md-6 col-lg-3">
        <div class="support-box">
          <i class="bi bi-building"></i>
          <h5>Bulk Enquiries</h5>
          <p>Talk to us for business purchases and large quantity product needs.</p>
        </div>
      </div>
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