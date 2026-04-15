<?php
session_start();

// Initialize cart if not exists
if (!isset($_SESSION['cart'])) {
    $_SESSION['cart'] = [];
}

// Product catalog (matches the 4 featured products from the UI)
$products = [
    1 => [
        'id' => 1,
        'name' => 'Meridian Timepiece',
        'category' => 'Accessories',
        'price' => 389.00,
        'old_price' => 520.00,
        'image' => 'https://images.unsplash.com/photo-1523275335684-37898b6baf30?w=500&q=80',
        'rating' => 5.0,
        'badge' => 'New',
        'description' => 'Elegant stainless steel timepiece with sapphire crystal and automatic movement. Water resistant up to 50m.'
    ],
    2 => [
        'id' => 2,
        'name' => 'Atelier Tote Bag',
        'category' => 'Handbags',
        'price' => 229.00,
        'old_price' => 310.00,
        'image' => 'https://images.unsplash.com/photo-1548036328-c9fa89d128fa?w=500&q=80',
        'rating' => 4.5,
        'badge' => 'Sale',
        'description' => 'Handcrafted genuine leather tote bag with premium suede lining. Spacious and durable for daily use.'
    ],
    3 => [
        'id' => 3,
        'name' => 'Prestige Runners',
        'category' => 'Footwear',
        'price' => 185.00,
        'old_price' => null,
        'image' => 'https://images.unsplash.com/photo-1491553895911-0055eca6402d?w=500&q=80',
        'rating' => 4.9,
        'badge' => null,
        'description' => 'Premium athletic sneakers with memory foam insole and breathable mesh upper. Perfect for active lifestyle.'
    ],
    4 => [
        'id' => 4,
        'name' => 'Noir Sillage Eau de Parfum',
        'category' => 'Fragrance',
        'price' => 215.00,
        'old_price' => 280.00,
        'image' => 'https://images.unsplash.com/photo-1561361058-c24cecae35ca?w=500&q=80',
        'rating' => 4.8,
        'badge' => 'Popular',
        'description' => 'A sophisticated blend of bergamot, leather, and amber notes. Long-lasting fragrance for the modern connoisseur.'
    ]
];

// Handle Add to Cart
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'add' && isset($_POST['product_id'])) {
        $product_id = (int)$_POST['product_id'];
        $quantity = isset($_POST['quantity']) ? (int)$_POST['quantity'] : 1;
        
        if (isset($products[$product_id])) {
            if (isset($_SESSION['cart'][$product_id])) {
                $_SESSION['cart'][$product_id]['quantity'] += $quantity;
            } else {
                $_SESSION['cart'][$product_id] = [
                    'id' => $product_id,
                    'name' => $products[$product_id]['name'],
                    'price' => $products[$product_id]['price'],
                    'image' => $products[$product_id]['image'],
                    'quantity' => $quantity
                ];
            }
        }
        header('Location: products.php?added=' . $product_id);
        exit;
    }
    
    // Handle Remove from Cart
    if ($_POST['action'] === 'remove' && isset($_POST['product_id'])) {
        $product_id = (int)$_POST['product_id'];
        unset($_SESSION['cart'][$product_id]);
        header('Location: products.php?removed=1');
        exit;
    }
    
    // Handle Update Quantity
    if ($_POST['action'] === 'update' && isset($_POST['product_id']) && isset($_POST['quantity'])) {
        $product_id = (int)$_POST['product_id'];
        $quantity = (int)$_POST['quantity'];
        if ($quantity > 0 && isset($_SESSION['cart'][$product_id])) {
            $_SESSION['cart'][$product_id]['quantity'] = $quantity;
        }
        header('Location: products.php#cart');
        exit;
    }
    
    // Handle Clear Cart
    if ($_POST['action'] === 'clear') {
        $_SESSION['cart'] = [];
        header('Location: products.php?cleared=1');
        exit;
    }
}

// Calculate cart totals
$cart_total = 0;
$cart_count = 0;
foreach ($_SESSION['cart'] as $item) {
    $cart_total += $item['price'] * $item['quantity'];
    $cart_count += $item['quantity'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Products | LuxuryStore — Premium Shopping Experience</title>

    <!-- Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,600;1,300;1,400&family=DM+Sans:wght@300;400;500&display=swap" rel="stylesheet">

    <!-- Bootstrap -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">

    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <!-- AOS -->
    <link href="https://cdn.jsdelivr.net/npm/aos@2.3.4/dist/aos.css" rel="stylesheet">

    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        :root {
            --navy:   #0a0f1e;
            --ink:    #111827;
            --mid:    #6b7280;
            --light:  #f8f7f4;
            --white:  #ffffff;
            --gold:   #c9a84c;
            --gold2:  #e8c97a;
            --blue:   #1d4ed8;
            --radius: 14px;
            --shadow: 0 6px 40px rgba(0,0,0,.08);
            --shadow-hover: 0 16px 50px rgba(0,0,0,.15);
            --font-display: 'Cormorant Garamond', Georgia, serif;
            --font-body: 'DM Sans', sans-serif;
        }

        body {
            font-family: var(--font-body);
            color: var(--ink);
            background: var(--light);
            overflow-x: hidden;
        }

        body::before {
            content: '';
            position: fixed;
            inset: 0;
            background-image: url("data:image/svg+xml,%3Csvg viewBox='0 0 512 512' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='.9' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)' opacity='.03'/%3E%3C/svg%3E");
            pointer-events: none;
            z-index: 9999;
            opacity: .5;
        }

        .display-serif { font-family: var(--font-display); font-weight: 300; letter-spacing: -.02em; line-height: 1.1; }
        .label { font-size: .72rem; font-weight: 500; letter-spacing: .18em; text-transform: uppercase; color: var(--gold); }
        .section-tag { display: inline-block; font-size: .72rem; font-weight: 500; letter-spacing: .2em; text-transform: uppercase; color: var(--gold); margin-bottom: 1rem; }
        .section-title { font-family: var(--font-display); font-size: clamp(2rem, 4vw, 3rem); font-weight: 300; line-height: 1.15; color: var(--navy); }

        /* Navbar */
        .navbar { padding: 1.2rem 0; background: rgba(248,247,244,.92); backdrop-filter: blur(18px); border-bottom: 1px solid rgba(201,168,76,.18); transition: all .3s; }
        .navbar.scrolled { padding: .8rem 0; box-shadow: 0 4px 30px rgba(0,0,0,.07); }
        .navbar-brand { font-family: var(--font-display); font-size: 1.55rem; font-weight: 600; color: var(--navy) !important; }
        .navbar-brand span { color: var(--gold); }
        .nav-link { font-size: .82rem; font-weight: 500; letter-spacing: .08em; text-transform: uppercase; color: var(--mid) !important; padding: .5rem 1.1rem !important; }
        .nav-link:hover, .nav-link.active { color: var(--navy) !important; }
        .cart-badge { position: relative; }
        .cart-count { position: absolute; top: -8px; right: -8px; background: var(--gold); color: var(--navy); font-size: 10px; font-weight: bold; width: 18px; height: 18px; border-radius: 50%; display: flex; align-items: center; justify-content: center; }

        /* Product Card */
        .products-section { padding: 7rem 0; background: var(--light); }
        .product-card { background: var(--white); border-radius: var(--radius); overflow: hidden; border: 1px solid rgba(0,0,0,.05); transition: all .35s; height: 100%; display: flex; flex-direction: column; }
        .product-card:hover { transform: translateY(-8px); box-shadow: var(--shadow-hover); }
        .product-img { aspect-ratio: 1/1; overflow: hidden; position: relative; }
        .product-img img { width: 100%; height: 100%; object-fit: cover; transition: transform .5s ease; }
        .product-card:hover .product-img img { transform: scale(1.07); }
        .product-label { position: absolute; top: 1rem; left: 1rem; background: var(--gold); color: var(--navy); font-size: .68rem; font-weight: 600; letter-spacing: .1em; text-transform: uppercase; padding: .25rem .7rem; border-radius: 50px; }
        .product-body { padding: 1.5rem; flex: 1; display: flex; flex-direction: column; }
        .product-cat { font-size: .72rem; letter-spacing: .12em; text-transform: uppercase; color: var(--gold); margin-bottom: .4rem; }
        .product-name { font-family: var(--font-display); font-size: 1.3rem; font-weight: 400; color: var(--navy); margin-bottom: .6rem; }
        .product-rating { color: var(--gold); font-size: .82rem; margin-bottom: 1rem; }
        .product-footer { display: flex; align-items: center; justify-content: space-between; margin-top: auto; padding-top: 1rem; }
        .product-price { font-family: var(--font-display); font-size: 1.45rem; color: var(--navy); font-weight: 600; }
        .product-price-old { font-size: .85rem; color: #9ca3af; text-decoration: line-through; margin-right: .3rem; }
        .btn-cart { width: 42px; height: 42px; border-radius: 50%; background: var(--navy); border: none; color: var(--white); display: flex; align-items: center; justify-content: center; cursor: pointer; transition: all .3s; }
        .btn-cart:hover { background: var(--gold); color: var(--navy); transform: scale(1.12); }

        /* Cart Sidebar */
        .cart-sidebar { position: fixed; top: 0; right: -450px; width: 450px; height: 100vh; background: var(--white); box-shadow: -5px 0 30px rgba(0,0,0,.1); z-index: 10000; transition: right .4s cubic-bezier(0.2, 0.9, 0.4, 1.1); display: flex; flex-direction: column; }
        .cart-sidebar.open { right: 0; }
        .cart-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,.5); z-index: 9999; opacity: 0; visibility: hidden; transition: all .3s; }
        .cart-overlay.show { opacity: 1; visibility: visible; }
        .cart-header { padding: 1.5rem; border-bottom: 1px solid #eee; display: flex; justify-content: space-between; align-items: center; }
        .cart-header h4 { font-family: var(--font-display); font-size: 1.5rem; margin: 0; }
        .cart-close { background: none; border: none; font-size: 1.5rem; cursor: pointer; color: var(--mid); }
        .cart-items { flex: 1; overflow-y: auto; padding: 1rem; }
        .cart-item { display: flex; gap: 1rem; padding: 1rem 0; border-bottom: 1px solid #f0f0f0; }
        .cart-item-img { width: 70px; height: 70px; object-fit: cover; border-radius: 8px; }
        .cart-item-info { flex: 1; }
        .cart-item-name { font-weight: 500; margin-bottom: .25rem; }
        .cart-item-price { color: var(--gold); font-weight: 600; }
        .cart-item-quantity { display: flex; align-items: center; gap: .5rem; margin-top: .5rem; }
        .cart-item-quantity input { width: 50px; padding: .25rem; text-align: center; border: 1px solid #ddd; border-radius: 6px; }
        .cart-item-remove { background: none; border: none; color: #e74c3c; cursor: pointer; font-size: .8rem; }
        .cart-footer { padding: 1.5rem; border-top: 1px solid #eee; background: #fafafa; }
        .cart-total { display: flex; justify-content: space-between; font-size: 1.2rem; font-weight: 600; margin-bottom: 1rem; }
        .btn-checkout { width: 100%; background: var(--gold); border: none; padding: .9rem; font-weight: 600; text-transform: uppercase; letter-spacing: .1em; border-radius: 50px; transition: all .3s; }
        .btn-checkout:hover { background: var(--gold2); transform: translateY(-2px); }
        .btn-clear-cart { background: none; border: 1px solid #ddd; padding: .5rem; font-size: .8rem; border-radius: 8px; margin-top: .5rem; width: 100%; }
        .empty-cart { text-align: center; padding: 2rem; color: var(--mid); }

        /* Alert */
        .alert-toast { position: fixed; bottom: 2rem; left: 50%; transform: translateX(-50%); background: var(--navy); color: var(--white); padding: .8rem 1.5rem; border-radius: 50px; z-index: 10001; opacity: 0; transition: opacity .3s; pointer-events: none; font-size: .9rem; }
        .alert-toast.show { opacity: 1; }

        /* Footer */
        footer { background: var(--navy); color: rgba(255,255,255,.5); padding: 5rem 0 2rem; margin-top: 0; }
        .footer-brand { font-family: var(--font-display); font-size: 1.6rem; color: var(--white); margin-bottom: 1rem; }
        .footer-brand span { color: var(--gold); }
        .footer-link { display: block; font-size: .85rem; color: rgba(255,255,255,.45); text-decoration: none; margin-bottom: .55rem; transition: color .2s; }
        .footer-link:hover { color: var(--gold); }
        .social-btn { width: 38px; height: 38px; border-radius: 50%; border: 1px solid rgba(255,255,255,.12); display: inline-flex; align-items: center; justify-content: center; color: rgba(255,255,255,.45); transition: all .25s; margin-right: .4rem; text-decoration: none; }
        .social-btn:hover { border-color: var(--gold); color: var(--gold); transform: translateY(-2px); }
        .footer-bottom { margin-top: 4rem; padding-top: 1.5rem; border-top: 1px solid rgba(255,255,255,.07); font-size: .78rem; text-align: center; }

        @media (max-width: 576px) { .cart-sidebar { width: 100%; right: -100%; } }
    </style>
</head>
<body>

<!-- Navbar -->
<nav class="navbar navbar-expand-lg fixed-top" id="navbar">
    <div class="container">
        <a class="navbar-brand" href="index.html"><i class="fas fa-gem me-2" style="color:var(--gold)"></i>Luxury<span>Store</span></a>
        <button class="navbar-toggler border-0" type="button" data-bs-toggle="collapse" data-bs-target="#navMenu">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navMenu">
            <ul class="navbar-nav ms-auto align-items-center gap-1">
                <li class="nav-item"><a class="nav-link" href="index.html">Home</a></li>
                <li class="nav-item"><a class="nav-link active" href="products.php">Products</a></li>
                <li class="nav-item"><a class="nav-link" href="#footer">Contact</a></li>
                <li class="nav-item">
                    <a class="nav-link cart-badge" href="#" id="cartIcon">
                        <i class="fas fa-shopping-bag"></i>
                        <span class="cart-count" id="cartCountDisplay"><?php echo $cart_count; ?></span>
                    </a>
                </li>
            </ul>
        </div>
    </div>
</nav>

<!-- Products Section -->
<section class="products-section">
    <div class="container">
        <div class="text-center mb-5" data-aos="fade-up">
            <span class="section-tag">Our Collection</span>
            <h2 class="section-title mt-1">Premium Selections</h2>
            <p class="section-lead mx-auto mt-2" style="max-width: 500px;">Explore our curated collection of luxury goods, crafted with exceptional attention to detail.</p>
        </div>

        <?php if (isset($_GET['added'])): ?>
            <div class="alert alert-success text-center" style="border-radius: 50px; background: #e8f5e9; border: none; color: #2e7d32;" id="successAlert">
                <i class="fas fa-check-circle"></i> Product added to cart successfully!
            </div>
            <script>setTimeout(() => document.getElementById('successAlert')?.remove(), 3000);</script>
        <?php endif; ?>

        <div class="row g-4">
            <?php foreach ($products as $product): ?>
                <div class="col-sm-6 col-lg-3" data-aos="fade-up" data-aos-delay="0">
                    <div class="product-card">
                        <div class="product-img">
                            <img src="<?php echo htmlspecialchars($product['image']); ?>" alt="<?php echo htmlspecialchars($product['name']); ?>">
                            <?php if ($product['badge']): ?>
                                <span class="product-label"><?php echo htmlspecialchars($product['badge']); ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="product-body">
                            <div class="product-cat"><?php echo htmlspecialchars($product['category']); ?></div>
                            <div class="product-name"><?php echo htmlspecialchars($product['name']); ?></div>
                            <div class="product-rating">
                                <?php 
                                $full = floor($product['rating']);
                                $half = ($product['rating'] - $full) >= 0.5;
                                for ($i = 0; $i < $full; $i++) echo '<i class="fas fa-star"></i>';
                                if ($half) echo '<i class="fas fa-star-half-alt"></i>';
                                ?>
                                <span style="color:var(--mid);font-size:.75rem;margin-left:.3rem"><?php echo $product['rating']; ?></span>
                            </div>
                            <div class="product-footer">
                                <div>
                                    <?php if ($product['old_price']): ?>
                                        <span class="product-price-old">$<?php echo number_format($product['old_price'], 0); ?></span>
                                    <?php endif; ?>
                                    <span class="product-price">$<?php echo number_format($product['price'], 0); ?></span>
                                </div>
                                <form method="POST" action="" class="add-to-cart-form" style="margin:0;">
                                    <input type="hidden" name="action" value="add">
                                    <input type="hidden" name="product_id" value="<?php echo $product['id']; ?>">
                                    <input type="hidden" name="quantity" value="1">
                                    <button type="submit" class="btn-cart"><i class="fas fa-shopping-bag"></i></button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- Cart Sidebar -->
<div class="cart-overlay" id="cartOverlay"></div>
<div class="cart-sidebar" id="cartSidebar">
    <div class="cart-header">
        <h4><i class="fas fa-shopping-bag me-2"></i>Your Cart</h4>
        <button class="cart-close" id="closeCart"><i class="fas fa-times"></i></button>
    </div>
    <div class="cart-items" id="cartItems">
        <?php if (empty($_SESSION['cart'])): ?>
            <div class="empty-cart">
                <i class="fas fa-shopping-cart fa-3x mb-3" style="color: #ddd;"></i>
                <p>Your cart is empty</p>
                <small>Add some luxury items to get started</small>
            </div>
        <?php else: ?>
            <?php foreach ($_SESSION['cart'] as $id => $item): ?>
                <div class="cart-item" data-id="<?php echo $id; ?>">
                    <img src="<?php echo htmlspecialchars($item['image']); ?>" alt="<?php echo htmlspecialchars($item['name']); ?>" class="cart-item-img">
                    <div class="cart-item-info">
                        <div class="cart-item-name"><?php echo htmlspecialchars($item['name']); ?></div>
                        <div class="cart-item-price">$<?php echo number_format($item['price'], 2); ?></div>
                        <div class="cart-item-quantity">
                            <form method="POST" action="" class="d-flex align-items-center gap-2">
                                <input type="hidden" name="action" value="update">
                                <input type="hidden" name="product_id" value="<?php echo $id; ?>">
                                <input type="number" name="quantity" value="<?php echo $item['quantity']; ?>" min="1" class="quantity-input" style="width: 55px;">
                                <button type="submit" class="btn btn-sm btn-outline-secondary">Update</button>
                            </form>
                            <form method="POST" action="">
                                <input type="hidden" name="action" value="remove">
                                <input type="hidden" name="product_id" value="<?php echo $id; ?>">
                                <button type="submit" class="cart-item-remove"><i class="fas fa-trash-alt"></i></button>
                            </form>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
    <div class="cart-footer">
        <div class="cart-total">
            <span>Total:</span>
            <span>$<?php echo number_format($cart_total, 2); ?></span>
        </div>
        <form method="POST" action="">
            <input type="hidden" name="action" value="clear">
            <button type="submit" class="btn-clear-cart" <?php echo empty($_SESSION['cart']) ? 'disabled' : ''; ?>>
                <i class="fas fa-trash-alt"></i> Clear Cart
            </button>
        </form>
        <button class="btn-checkout mt-3" id="checkoutBtn">
            <i class="fas fa-credit-card"></i> Proceed to Checkout
        </button>
    </div>
</div>

<!-- Toast Alert -->
<div class="alert-toast" id="toastMsg"></div>

<!-- Footer -->
<footer id="footer">
    <div class="container">
        <div class="row g-5">
            <div class="col-lg-4">
                <div class="footer-brand"><i class="fas fa-gem me-2" style="color:var(--gold)"></i>Luxury<span>Store</span></div>
                <p class="footer-text mb-4">Redefining luxury shopping with premium quality products and exceptional service, delivered worldwide.</p>
                <a href="#" class="social-btn"><i class="fab fa-facebook-f"></i></a>
                <a href="#" class="social-btn"><i class="fab fa-instagram"></i></a>
                <a href="#" class="social-btn"><i class="fab fa-twitter"></i></a>
            </div>
            <div class="col-6 col-lg-2">
                <div class="footer-heading">Quick Links</div>
                <a class="footer-link" href="#">About Us</a>
                <a class="footer-link" href="products.php">Products</a>
                <a class="footer-link" href="#">Contact</a>
            </div>
            <div class="col-6 col-lg-2">
                <div class="footer-heading">Support</div>
                <a class="footer-link" href="#">FAQ</a>
                <a class="footer-link" href="#">Shipping</a>
                <a class="footer-link" href="#">Returns</a>
            </div>
            <div class="col-lg-4">
                <div class="footer-heading">Newsletter</div>
                <p class="footer-text mb-3">Subscribe for exclusive offers.</p>
                <div class="newsletter-input">
                    <input type="email" placeholder="Your email address" style="background:rgba(255,255,255,.07);border:1px solid rgba(255,255,255,.1);border-radius:8px;padding:.7rem 1rem;color:white;">
                    <button class="newsletter-btn" style="background:var(--gold);border:none;border-radius:8px;padding:.7rem 1.2rem;">Subscribe</button>
                </div>
            </div>
        </div>
        <div class="footer-bottom">
            &copy; 2024 LuxuryStore. All rights reserved. Crafted with <i class="fas fa-heart" style="color:var(--gold)"></i> for premium shoppers.
        </div>
    </div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/aos@2.3.4/dist/aos.js"></script>
<script>
    AOS.init({ duration: 750, easing: 'ease-out-cubic', once: true, offset: 60 });

    // Navbar scroll
    const navbar = document.getElementById('navbar');
    window.addEventListener('scroll', () => navbar.classList.toggle('scrolled', window.scrollY > 60));

    // Cart Sidebar Logic
    const cartIcon = document.getElementById('cartIcon');
    const cartSidebar = document.getElementById('cartSidebar');
    const cartOverlay = document.getElementById('cartOverlay');
    const closeCart = document.getElementById('closeCart');

    function openCart() { cartSidebar.classList.add('open'); cartOverlay.classList.add('show'); }
    function closeCartFunc() { cartSidebar.classList.remove('open'); cartOverlay.classList.remove('show'); }

    cartIcon?.addEventListener('click', (e) => { e.preventDefault(); openCart(); });
    closeCart?.addEventListener('click', closeCartFunc);
    cartOverlay?.addEventListener('click', closeCartFunc);

    // Update cart count display on any form submission
    const updateCartDisplay = () => {
        fetch('get_cart_count.php')
            .then(res => res.json())
            .then(data => {
                const countSpan = document.getElementById('cartCountDisplay');
                if (countSpan) countSpan.innerText = data.count;
            })
            .catch(err => console.log(err));
    };

    // Show toast message
    function showToast(message) {
        const toast = document.getElementById('toastMsg');
        toast.innerText = message;
        toast.classList.add('show');
        setTimeout(() => toast.classList.remove('show'), 2000);
    }

    // Handle add to cart forms with AJAX for better UX
    document.querySelectorAll('.add-to-cart-form').forEach(form => {
        form.addEventListener('submit', async (e) => {
            e.preventDefault();
            const formData = new FormData(form);
            try {
                const response = await fetch('add_to_cart_ajax.php', {
                    method: 'POST',
                    body: formData
                });
                const data = await response.json();
                if (data.success) {
                    showToast('✓ Product added to cart!');
                    const countSpan = document.getElementById('cartCountDisplay');
                    if (countSpan) countSpan.innerText = data.cart_count;
                } else {
                    showToast('Error adding product');
                }
            } catch (err) {
                showToast('Something went wrong');
            }
        });
    });

    // Optional: Update cart display when forms inside cart submit
    document.querySelectorAll('.cart-item-quantity form, .cart-item-remove form, .btn-clear-cart').forEach(form => {
        form?.addEventListener('submit', () => {
            setTimeout(() => location.reload(), 100);
        });
    });

    // Checkout button
    document.getElementById('checkoutBtn')?.addEventListener('click', () => {
        <?php if ($cart_count > 0): ?>
            alert('Proceeding to checkout. Total: $<?php echo number_format($cart_total, 2); ?>');
            // Redirect to checkout page if needed
            // window.location.href = 'checkout.php';
        <?php else: ?>
            alert('Your cart is empty. Add some items first!');
        <?php endif; ?>
    });
</script>
</body>
</html>