<?php
if (!defined('STORE_FRONT')) {
    die('Direct access not allowed.');
}

$storeName      = $storefront_display_name ?? 'ELECTROMART';
$storeLogo      = $storefront_logo ?? '';
$storePhone     = $storefront_phone ?? '';
$storeEmail     = $storefront_email ?? '';
$storeWhatsapp  = $storefront_whatsapp ?? '';
$storeAddress   = $storefront_address ?? '';
$storeCurrency  = $storefront_currency ?? '₹';
$storeBaseUrl   = $storefront_store_url ?? '#';
$storeShopUrl   = $storefront_store_page_url ?? '#';
$storeContactUrl= $storefront_contact_page_url ?? '#';

$categoryUrl    = $storeBaseUrl . '&page=categories';
$offersUrl      = $storeBaseUrl . '&page=offers';
$cartUrl        = $storeBaseUrl . '&page=cart';
$wishlistUrl    = $storeBaseUrl . '&page=wishlist';
$checkoutUrl    = $storeBaseUrl . '&page=checkout';
$trackUrl       = $storeBaseUrl . '&page=track';
$returnUrl      = $storeBaseUrl . '&page=returns';
$shippingUrl    = $storeBaseUrl . '&page=shipping-policy';

$facebookUrl  = $facebookUrl ?? '#';
$instagramUrl = $instagramUrl ?? '#';
$twitterUrl   = $twitterUrl ?? '#';
$youtubeUrl   = $youtubeUrl ?? '#';

$footerAbout = !empty($aboutUs)
    ? $aboutUs
    : 'A clean eCommerce storefront for electrical appliances, gadgets, lighting, and home utility products with a modern shopping experience.';
?>

<footer class="footer">
  <div class="container-fluid px-lg-5 px-3">
    <div class="row g-4">
      <div class="col-lg-4">
        <h4>
          <?php echo htmlspecialchars($storeName, ENT_QUOTES, 'UTF-8'); ?>
        </h4>
        <p>
          <?php echo htmlspecialchars($footerAbout, ENT_QUOTES, 'UTF-8'); ?>
        </p>

       
      </div>

      <div class="col-6 col-lg-2">
        <h5>Quick Links</h5>
        <ul>
          <li><a href="<?php echo htmlspecialchars($storeBaseUrl, ENT_QUOTES, 'UTF-8'); ?>">Home</a></li>
          <li><a href="<?php echo htmlspecialchars($storeShopUrl, ENT_QUOTES, 'UTF-8'); ?>">Shop</a></li>
          <li><a href="<?php echo htmlspecialchars($categoryUrl, ENT_QUOTES, 'UTF-8'); ?>">Categories</a></li>
          <!--<li><a href="<?php echo htmlspecialchars($offersUrl, ENT_QUOTES, 'UTF-8'); ?>">Offers</a></li>-->
        </ul>
      </div>

     

      <div class="col-lg-3">
        <h5>Contact</h5>
        <ul>
          <?php if (!empty($storeEmail)): ?>
            <li>
              Email:
              <a href="mailto:<?php echo htmlspecialchars($storeEmail, ENT_QUOTES, 'UTF-8'); ?>">
                <?php echo htmlspecialchars($storeEmail, ENT_QUOTES, 'UTF-8'); ?>
              </a>
            </li>
          <?php endif; ?>

          <?php if (!empty($storePhone)): ?>
            <li>
              Phone:
              <a href="tel:<?php echo htmlspecialchars(preg_replace('/\s+/', '', $storePhone), ENT_QUOTES, 'UTF-8'); ?>">
                <?php echo htmlspecialchars($storePhone, ENT_QUOTES, 'UTF-8'); ?>
              </a>
            </li>
          <?php endif; ?>

          <?php if (!empty($storeWhatsapp)): ?>
            <li>
              WhatsApp:
              <a href="https://wa.me/<?php echo htmlspecialchars(preg_replace('/\D+/', '', $storeWhatsapp), ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener">
                <?php echo htmlspecialchars($storeWhatsapp, ENT_QUOTES, 'UTF-8'); ?>
              </a>
            </li>
          <?php endif; ?>

          <?php if (!empty($storeAddress)): ?>
            <li>
              Address:
              <?php echo htmlspecialchars($storeAddress, ENT_QUOTES, 'UTF-8'); ?>
            </li>
          <?php endif; ?>
        </ul>
      </div>
    </div>

    <div class="footer-bottom">
      © <?php echo date('Y'); ?> <?php echo htmlspecialchars($storeName, ENT_QUOTES, 'UTF-8'); ?>. All Rights Reserved.
    </div>
  </div>
</footer>