<?php
if (!defined('STORE_FRONT')) {
    die('Direct access not allowed.');
}

if (!function_exists('sf_h')) {
    function sf_h($value): string {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

$mobileStoreName   = $storefront_display_name ?? 'Online Store';
$mobileStoreUrl    = $storefront_store_url ?? '#';
$mobileShopUrl     = $storefront_store_page_url ?? '#';
$mobileCategoryUrl = $storefront_store_url ?? '#';
$mobileOffersUrl   = $storefront_store_url ?? '#';
$mobileContactUrl  = $storefront_contact_page_url ?? '#';

$mobilePhone     = trim((string)($storefront_phone ?? ''));
$mobileEmail     = trim((string)($storefront_email ?? ''));
$mobileWhatsapp  = trim((string)($storefront_whatsapp ?? ''));
$mobileAddress   = trim((string)($storefront_address ?? ''));
$mobileSlug      = trim((string)($storefront_slug ?? ''));

$mobileAllCategoriesUrl = $mobileStoreUrl . '&page=categories';
$mobileOffersUrl        = $mobileStoreUrl . '&page=offers';

$whatsappLink = '';
if ($mobileWhatsapp !== '') {
    $whatsappDigits = preg_replace('/\D+/', '', $mobileWhatsapp);
    if ($whatsappDigits !== '') {
        if (strpos($whatsappDigits, '91') !== 0 && strlen($whatsappDigits) === 10) {
            $whatsappDigits = '91' . $whatsappDigits;
        }
        $whatsappLink = 'https://wa.me/' . $whatsappDigits;
    }
}
?>

<div class="offcanvas offcanvas-start mobile-menu-offcanvas" tabindex="-1" id="mobileMenu" aria-labelledby="mobileMenuLabel">
  <div class="offcanvas-header mobile-menu-header">
    <h5 class="mobile-menu-title" id="mobileMenuLabel"><?php echo sf_h($mobileStoreName); ?></h5>
    <button type="button" class="btn-close shadow-none" data-bs-dismiss="offcanvas" aria-label="Close"></button>
  </div>

  <div class="offcanvas-body">
    <div class="mobile-menu-links">
      <a href="<?php echo sf_h($mobileStoreUrl); ?>">
        <span>Home</span>
        <i class="bi bi-chevron-right"></i>
      </a>

      <a href="<?php echo sf_h($mobileShopUrl); ?>">
        <span>Shop</span>
        <i class="bi bi-chevron-right"></i>
      </a>

      <a href="<?php echo sf_h($mobileAllCategoriesUrl); ?>">
        <span>Categories</span>
        <i class="bi bi-chevron-right"></i>
      </a>

      <a href="<?php echo sf_h($mobileOffersUrl); ?>">
        <span>Offers</span>
        <i class="bi bi-chevron-right"></i>
      </a>

      <a href="<?php echo sf_h($mobileContactUrl); ?>">
        <span>Contact</span>
        <i class="bi bi-chevron-right"></i>
      </a>
    </div>

    <div class="mobile-menu-contact">
      <h6>Contact Info</h6>

      <?php if ($mobileEmail !== ''): ?>
        <p>
          Email:
          <a href="mailto:<?php echo sf_h($mobileEmail); ?>">
            <?php echo sf_h($mobileEmail); ?>
          </a>
        </p>
      <?php endif; ?>

      <?php if ($mobilePhone !== ''): ?>
        <p>
          Phone:
          <a href="tel:<?php echo sf_h($mobilePhone); ?>">
            <?php echo sf_h($mobilePhone); ?>
          </a>
        </p>
      <?php endif; ?>

      <?php if ($whatsappLink !== ''): ?>
        <p>
          WhatsApp:
          <a href="<?php echo sf_h($whatsappLink); ?>" target="_blank">
            <?php echo sf_h($mobileWhatsapp); ?>
          </a>
        </p>
      <?php endif; ?>

      <?php if ($mobileAddress !== ''): ?>
        <p>Address: <?php echo sf_h($mobileAddress); ?></p>
      <?php endif; ?>

      <?php if ($mobileSlug !== ''): ?>
        <p class="mb-0">
          Store URL:
          <a href="<?php echo sf_h($mobileStoreUrl); ?>">
            <?php echo sf_h($mobileSlug); ?>
          </a>
        </p>
      <?php endif; ?>
    </div>
  </div>
</div>