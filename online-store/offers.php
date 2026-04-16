<?php
$page_title = 'Offers | ElectroMart';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title><?php echo $page_title; ?></title>

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">

  <link rel="stylesheet" href="assets/main.css">
  <link rel="stylesheet" href="assets/menu-bar.css">
  <link rel="stylesheet" href="assets/footer.css">

  <style>
    .offers-hero{
      background:
        linear-gradient(rgba(17,24,39,0.85), rgba(17,24,39,0.72)),
        url('https://images.unsplash.com/photo-1483985988355-763728e1935b?auto=format&fit=crop&w=1600&q=80') center/cover no-repeat;
      color:#fff;
      padding:90px 0;
      text-align:center;
    }

    .offers-hero h1{
      font-size:48px;
      font-weight:800;
      margin-bottom:14px;
    }

    .offers-hero p{
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

    .offer-highlight-card{
      position:relative;
      overflow:hidden;
      min-height:320px;
      background:#111827;
      color:#fff;
      display:flex;
      align-items:flex-end;
    }

    .offer-highlight-card img{
      position:absolute;
      inset:0;
      width:100%;
      height:100%;
      object-fit:cover;
    }

    .offer-highlight-overlay{
      position:relative;
      z-index:2;
      width:100%;
      padding:28px 24px;
      background:linear-gradient(to top, rgba(17,24,39,0.92), rgba(17,24,39,0.25));
    }

    .offer-badge{
      display:inline-block;
      background:#e11d48;
      color:#fff;
      font-size:12px;
      font-weight:700;
      padding:7px 12px;
      margin-bottom:14px;
      letter-spacing:.3px;
    }

    .offer-highlight-overlay h3{
      font-size:30px;
      font-weight:800;
      margin-bottom:10px;
    }

    .offer-highlight-overlay p{
      color:#e5e7eb;
      margin-bottom:16px;
      line-height:1.8;
      font-size:14px;
    }

    .btn-offer{
      background:#e11d48;
      color:#fff;
      border:none;
      padding:12px 22px;
      font-size:14px;
      font-weight:700;
      text-decoration:none;
      display:inline-block;
      transition:.3s ease;
    }

    .btn-offer:hover{
      background:#be123c;
      color:#fff;
    }

    .offer-card{
      border:1px solid #e5e7eb;
      background:#fff;
      height:100%;
      overflow:hidden;
      transition:.3s ease;
    }

    .offer-card:hover{
      transform:translateY(-4px);
      box-shadow:0 12px 35px rgba(0,0,0,0.08);
    }

    .offer-card-image{
      position:relative;
      overflow:hidden;
      background:#f3f4f6;
    }

    .offer-card-image img{
      width:100%;
      height:240px;
      object-fit:cover;
      transition:transform .4s ease;
    }

    .offer-card:hover .offer-card-image img{
      transform:scale(1.05);
    }

    .offer-card-body{
      padding:22px 20px;
    }

    .offer-card-body h4{
      font-size:22px;
      font-weight:700;
      color:#111827;
      margin-bottom:10px;
    }

    .offer-card-body p{
      color:#6b7280;
      font-size:14px;
      line-height:1.8;
      margin-bottom:16px;
    }

    .offer-meta{
      display:flex;
      justify-content:space-between;
      align-items:center;
      flex-wrap:wrap;
      gap:10px;
      margin-bottom:16px;
    }

    .offer-discount{
      font-size:22px;
      font-weight:800;
      color:#111827;
    }

    .offer-validity{
      font-size:13px;
      color:#6b7280;
      font-weight:600;
    }

    .offer-tags{
      display:flex;
      flex-wrap:wrap;
      gap:8px;
      margin-bottom:16px;
    }

    .offer-tags span{
      background:#f3f4f6;
      color:#374151;
      font-size:12px;
      font-weight:600;
      padding:6px 10px;
    }

    .coupon-box{
      border:2px dashed #e11d48;
      background:#fff5f7;
      padding:26px 22px;
      text-align:center;
      height:100%;
    }

    .coupon-box h4{
      font-size:24px;
      font-weight:800;
      color:#111827;
      margin-bottom:10px;
    }

    .coupon-box p{
      color:#6b7280;
      font-size:14px;
      line-height:1.8;
      margin-bottom:14px;
    }

    .coupon-code{
      display:inline-block;
      background:#111827;
      color:#fff;
      padding:12px 18px;
      font-size:18px;
      font-weight:800;
      letter-spacing:1px;
    }

    .offer-info-strip{
      background:#111827;
      color:#fff;
    }

    .offer-info-box{
      text-align:center;
      padding:10px 18px;
    }

    .offer-info-box i{
      font-size:30px;
      color:#fda4af;
      margin-bottom:12px;
    }

    .offer-info-box h5{
      font-size:18px;
      font-weight:700;
      margin-bottom:8px;
    }

    .offer-info-box p{
      margin:0;
      color:#d1d5db;
      font-size:14px;
      line-height:1.7;
    }

    @media (max-width: 991.98px){
      .offers-hero h1{
        font-size:38px;
      }
    }

    @media (max-width: 767.98px){
      .offers-hero{
        padding:70px 0;
      }

      .offers-hero h1{
        font-size:30px;
      }

      .offer-highlight-overlay h3{
        font-size:24px;
      }

      .offer-card-image img{
        height:220px;
      }
    }
  </style>
</head>
<body>

<?php include('includes/top-strip.php'); ?>
<?php include('includes/nav.php'); ?>
<?php include('includes/mobile-nav.php'); ?>

<section class="offers-hero">
  <div class="container-fluid px-lg-5 px-3">
    <h1>Latest Offers & Deals</h1>
    <p>
      Explore active deals on electrical products, appliances, lighting, accessories, and more. Clear offers convert. Confusing ones get ignored.
    </p>
  </div>
</section>

<section class="breadcrumb-wrap">
  <div class="container-fluid px-lg-5 px-3">
    <nav aria-label="breadcrumb">
      <ol class="breadcrumb breadcrumb-custom">
        <li class="breadcrumb-item"><a href="index.php">Home</a></li>
        <li class="breadcrumb-item active" aria-current="page">Offers</li>
      </ol>
    </nav>
  </div>
</section>

<section class="section-space">
  <div class="container-fluid px-lg-5 px-3">
    <div class="row g-4">
      <div class="col-lg-8">
        <div class="offer-highlight-card">
          <img src="https://images.unsplash.com/photo-1518770660439-4636190af475?auto=format&fit=crop&w=1400&q=80" alt="Mega Electrical Sale">
          <div class="offer-highlight-overlay">
            <span class="offer-badge">LIMITED TIME OFFER</span>
            <h3>Mega Electrical Sale Up to 35% Off</h3>
            <p>
              Save on selected appliances, lighting solutions, extension boards, power tools, and home electrical essentials. Put the strongest offer first. That is what users notice.
            </p>
            <a href="store.php" class="btn-offer">Shop Now</a>
          </div>
        </div>
      </div>

      <div class="col-lg-4">
        <div class="coupon-box">
          <h4>Extra Coupon Offer</h4>
          <p>Use this code on qualifying orders and stack an extra discount where applicable.</p>
          <div class="coupon-code">SAVE10</div>
          <p class="mt-3 mb-0">Valid on selected products above ₹2,999.</p>
        </div>
      </div>
    </div>
  </div>
</section>

<section class="section-space pt-0">
  <div class="container-fluid px-lg-5 px-3">
    <div class="section-title">
      <h2>Active Offer Campaigns</h2>
      <p>Use offers that are easy to understand. Nobody reads vague promo clutter.</p>
    </div>

    <div class="row g-4">
      <div class="col-md-6 col-lg-4">
        <div class="offer-card">
          <div class="offer-card-image">
            <img src="https://images.unsplash.com/photo-1585771724684-38269d6639fd?auto=format&fit=crop&w=900&q=80" alt="Kitchen Appliance Offer">
          </div>
          <div class="offer-card-body">
            <h4>Kitchen Appliance Deal</h4>
            <p>Special price cuts on mixer grinders, kettles, and other daily-use kitchen electricals.</p>
            <div class="offer-meta">
              <div class="offer-discount">Up to 25% Off</div>
              <div class="offer-validity">Valid This Week</div>
            </div>
            <div class="offer-tags">
              <span>Mixer</span>
              <span>Kettle</span>
              <span>Kitchen Use</span>
            </div>
            <a href="store.php" class="btn-offer">View Offer</a>
          </div>
        </div>
      </div>

      <div class="col-md-6 col-lg-4">
        <div class="offer-card">
          <div class="offer-card-image">
            <img src="https://images.unsplash.com/photo-1517677208171-0bc6725a3e60?auto=format&fit=crop&w=900&q=80" alt="Lighting Deal">
          </div>
          <div class="offer-card-body">
            <h4>Lighting Festival Deal</h4>
            <p>Discounts on LED lights, ceiling fixtures, and decorative lighting for homes and offices.</p>
            <div class="offer-meta">
              <div class="offer-discount">Flat 20% Off</div>
              <div class="offer-validity">Ends Soon</div>
            </div>
            <div class="offer-tags">
              <span>LED</span>
              <span>Ceiling Lights</span>
              <span>Decor</span>
            </div>
            <a href="store.php" class="btn-offer">View Offer</a>
          </div>
        </div>
      </div>

      <div class="col-md-6 col-lg-4">
        <div class="offer-card">
          <div class="offer-card-image">
            <img src="https://images.unsplash.com/photo-1563013544-824ae1b704d3?auto=format&fit=crop&w=900&q=80" alt="Accessories Offer">
          </div>
          <div class="offer-card-body">
            <h4>Accessories Saver Pack</h4>
            <p>Buy electrical accessories and utility items with combined savings on bundled orders.</p>
            <div class="offer-meta">
              <div class="offer-discount">Save ₹500</div>
              <div class="offer-validity">Bundle Offer</div>
            </div>
            <div class="offer-tags">
              <span>Extension Boards</span>
              <span>Plugs</span>
              <span>Bundles</span>
            </div>
            <a href="store.php" class="btn-offer">View Offer</a>
          </div>
        </div>
      </div>

      <div class="col-md-6 col-lg-4">
        <div class="offer-card">
          <div class="offer-card-image">
            <img src="https://images.unsplash.com/photo-1581092921461-eab62e97a780?auto=format&fit=crop&w=900&q=80" alt="Cooling Products Offer">
          </div>
          <div class="offer-card-body">
            <h4>Cooling Products Special</h4>
            <p>Seasonal savings on table fans and selected cooling appliances for homes and offices.</p>
            <div class="offer-meta">
              <div class="offer-discount">Up to 18% Off</div>
              <div class="offer-validity">Seasonal Offer</div>
            </div>
            <div class="offer-tags">
              <span>Fans</span>
              <span>Cooling</span>
              <span>Summer</span>
            </div>
            <a href="store.php" class="btn-offer">View Offer</a>
          </div>
        </div>
      </div>

      <div class="col-md-6 col-lg-4">
        <div class="offer-card">
          <div class="offer-card-image">
            <img src="https://images.unsplash.com/photo-1518770660439-4636190af475?auto=format&fit=crop&w=900&q=80" alt="Power Tools Offer">
          </div>
          <div class="offer-card-body">
            <h4>Power Tools Discount</h4>
            <p>Reduced prices on selected drilling and workshop tools for repair and maintenance work.</p>
            <div class="offer-meta">
              <div class="offer-discount">Flat 15% Off</div>
              <div class="offer-validity">Limited Units</div>
            </div>
            <div class="offer-tags">
              <span>Drills</span>
              <span>Workshop</span>
              <span>Maintenance</span>
            </div>
            <a href="store.php" class="btn-offer">View Offer</a>
          </div>
        </div>
      </div>

      <div class="col-md-6 col-lg-4">
        <div class="offer-card">
          <div class="offer-card-image">
            <img src="https://images.unsplash.com/photo-1593642532871-8b12e02d091c?auto=format&fit=crop&w=900&q=80" alt="Gadget Offer">
          </div>
          <div class="offer-card-body">
            <h4>Smart Gadget Picks</h4>
            <p>Special pricing on selected gadgets and practical smart-use electrical items.</p>
            <div class="offer-meta">
              <div class="offer-discount">Up to 22% Off</div>
              <div class="offer-validity">Featured Deal</div>
            </div>
            <div class="offer-tags">
              <span>Speakers</span>
              <span>Smart Use</span>
              <span>Compact</span>
            </div>
            <a href="store.php" class="btn-offer">View Offer</a>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>

<section class="section-space offer-info-strip">
  <div class="container-fluid px-lg-5 px-3">
    <div class="row g-4">
      <div class="col-md-6 col-lg-3">
        <div class="offer-info-box">
          <i class="bi bi-tag"></i>
          <h5>Clear Discounts</h5>
          <p>Offers shown in direct terms so buyers understand the value instantly.</p>
        </div>
      </div>

      <div class="col-md-6 col-lg-3">
        <div class="offer-info-box">
          <i class="bi bi-lightning-charge"></i>
          <h5>Fast Access</h5>
          <p>Quick route from offer pages into the store without unnecessary friction.</p>
        </div>
      </div>

      <div class="col-md-6 col-lg-3">
        <div class="offer-info-box">
          <i class="bi bi-patch-check"></i>
          <h5>Quality Focused</h5>
          <p>Discounts are useful only when the products are actually worth buying.</p>
        </div>
      </div>

      <div class="col-md-6 col-lg-3">
        <div class="offer-info-box">
          <i class="bi bi-clock-history"></i>
          <h5>Limited-Time Deals</h5>
          <p>Time-bound campaigns encourage action instead of passive browsing.</p>
        </div>
      </div>
    </div>
  </div>
</section>

<?php include('includes/footer.php'); ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="assets/menu.js"></script>
<script src="assets/main.js"></script>
</body>
</html>