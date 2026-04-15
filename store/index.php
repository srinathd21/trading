<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>LuxuryStore — Premium Shopping Experience</title>

    <!-- Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,600;1,300;1,400&family=DM+Sans:wght@300;400;500&display=swap" rel="stylesheet">

    <!-- Bootstrap -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">

    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <!-- AOS -->
    <link href="https://cdn.jsdelivr.net/npm/aos@2.3.4/dist/aos.css" rel="stylesheet">

    <style>
        /* ── Reset & Base ── */
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
            --blue-soft: rgba(29,78,216,.08);

            --font-display: 'Cormorant Garamond', Georgia, serif;
            --font-body:    'DM Sans', sans-serif;

            --radius: 14px;
            --shadow: 0 6px 40px rgba(0,0,0,.08);
            --shadow-hover: 0 16px 50px rgba(0,0,0,.15);
        }

        html { scroll-behavior: smooth; }

        body {
            font-family: var(--font-body);
            color: var(--ink);
            background: var(--light);
            overflow-x: hidden;
        }

        /* ── Noise texture overlay ── */
        body::before {
            content: '';
            position: fixed;
            inset: 0;
            background-image: url("data:image/svg+xml,%3Csvg viewBox='0 0 512 512' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='.9' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)' opacity='.03'/%3E%3C/svg%3E");
            pointer-events: none;
            z-index: 9999;
            opacity: .5;
        }

        /* ── Typography helpers ── */
        .display-serif {
            font-family: var(--font-display);
            font-weight: 300;
            letter-spacing: -.02em;
            line-height: 1.1;
        }

        .label {
            font-size: .72rem;
            font-weight: 500;
            letter-spacing: .18em;
            text-transform: uppercase;
            color: var(--gold);
        }

        /* ──────────────────────────────────────────
           NAVBAR
        ────────────────────────────────────────── */
        .navbar {
            padding: 3px 0;
            background: rgba(248,247,244,.92);
            backdrop-filter: blur(18px);
            -webkit-backdrop-filter: blur(18px);
            border-bottom: 1px solid rgba(201,168,76,.18);
            transition: padding .3s, box-shadow .3s;
        }
        .navbar.scrolled {
            padding: .8rem 0;
            box-shadow: 0 4px 30px rgba(0,0,0,.07);
        }
        .navbar-brand {
            font-family: var(--font-display);
            font-size: 1.55rem;
            font-weight: 600;
            color: var(--navy) !important;
            letter-spacing: .04em;
        }
        .navbar-brand span { color: var(--gold); }

        .nav-link {
            font-size: .82rem;
            font-weight: 500;
            letter-spacing: .08em;
            text-transform: uppercase;
            color: var(--mid) !important;
            padding: .5rem 1.1rem !important;
            transition: color .25s;
        }
        .nav-link:hover { color: var(--navy) !important; }

        .nav-cta {
            background: var(--navy);
            color: var(--white) !important;
            border-radius: 50px;
            padding: .5rem 1.4rem !important;
            transition: background .25s, transform .2s;
        }
        .nav-cta:hover {
            background: var(--gold);
            color: var(--navy) !important;
            transform: translateY(-1px);
        }

        /* ──────────────────────────────────────────
           HERO
        ────────────────────────────────────────── */
        .hero {
            min-height: 100vh;
            display: flex;
            align-items: center;
            position: relative;
            overflow: hidden;
            background: var(--navy);
            padding-top: 80px;
        }

        /* Layered background */
        .hero::before {
            content: '';
            position: absolute;
            inset: 0;
            background:
                radial-gradient(ellipse 70% 60% at 70% 50%, rgba(201,168,76,.12) 0%, transparent 70%),
                radial-gradient(ellipse 40% 40% at 20% 80%, rgba(29,78,216,.15) 0%, transparent 60%);
        }

        .hero-eyebrow {
            display: inline-flex;
            align-items: center;
            gap: .6rem;
            padding: .4rem 1rem .4rem .4rem;
            background: rgba(201,168,76,.12);
            border: 1px solid rgba(201,168,76,.25);
            border-radius: 50px;
            margin-bottom: 2rem;
        }
        .hero-eyebrow-dot {
            width: 6px; height: 6px;
            background: var(--gold);
            border-radius: 50%;
            animation: pulse 2s infinite;
        }
        @keyframes pulse {
            0%,100% { opacity:1; transform:scale(1); }
            50% { opacity:.5; transform:scale(1.4); }
        }

        .hero h1 {
            font-size: clamp(3.5rem, 8vw, 7rem);
            color: var(--white);
            margin-bottom: 1.6rem;
        }
        .hero h1 em {
            font-style: italic;
            color: var(--gold);
        }

        .hero-lead {
            font-size: 1.05rem;
            color: rgba(255,255,255,.6);
            max-width: 440px;
            line-height: 1.7;
            margin-bottom: 2.5rem;
        }

        .btn-hero {
            display: inline-flex;
            align-items: center;
            gap: .7rem;
            background: var(--gold);
            color: var(--navy);
            border: none;
            border-radius: 50px;
            padding: .9rem 2.2rem;
            font-size: .88rem;
            font-weight: 500;
            letter-spacing: .06em;
            text-transform: uppercase;
            cursor: pointer;
            transition: all .3s;
            text-decoration: none;
        }
        .btn-hero:hover {
            background: var(--gold2);
            transform: translateY(-3px);
            box-shadow: 0 12px 35px rgba(201,168,76,.35);
            color: var(--navy);
        }
        .btn-hero-ghost {
            background: transparent;
            color: rgba(255,255,255,.75);
            border: 1px solid rgba(255,255,255,.2);
        }
        .btn-hero-ghost:hover {
            background: rgba(255,255,255,.08);
            color: var(--white);
            box-shadow: none;
        }

        /* Stats row */
        .hero-stats {
            margin-top: 4rem;
            padding-top: 3rem;
            border-top: 1px solid rgba(255,255,255,.08);
        }
        .stat-num {
            font-family: var(--font-display);
            font-size: 2.6rem;
            font-weight: 300;
            color: var(--white);
            line-height: 1;
        }
        .stat-label {
            font-size: .75rem;
            color: rgba(255,255,255,.45);
            letter-spacing: .1em;
            text-transform: uppercase;
            margin-top: .25rem;
        }
        .stat-divider {
            width: 1px;
            height: 50px;
            background: rgba(255,255,255,.1);
        }

        /* Hero image card */
        .hero-visual {
            position: relative;
        }
        .hero-img-wrap {
            border-radius: 24px;
            overflow: hidden;
            aspect-ratio: 4/5;
            max-height: 640px;
            position: relative;
        }
        .hero-img-wrap img {
            width: 100%; height: 100%;
            object-fit: cover;
            filter: brightness(.9);
        }
        .hero-img-badge {
            position: absolute;
            bottom: 2rem; left: 2rem;
            background: rgba(10,15,30,.85);
            backdrop-filter: blur(14px);
            border: 1px solid rgba(201,168,76,.25);
            border-radius: 14px;
            padding: 1rem 1.4rem;
            color: var(--white);
        }
        .hero-img-badge .badge-title {
            font-family: var(--font-display);
            font-size: 1.1rem;
            font-weight: 300;
        }
        .hero-img-badge .badge-sub {
            font-size: .72rem;
            color: var(--gold);
            letter-spacing: .1em;
            text-transform: uppercase;
        }

        /* Floating accent */
        .hero-accent-circle {
            position: absolute;
            width: 380px; height: 380px;
            border-radius: 50%;
            border: 1px solid rgba(201,168,76,.08);
            top: -60px; right: -60px;
            pointer-events: none;
        }
        .hero-accent-circle::after {
            content: '';
            position: absolute;
            inset: 40px;
            border-radius: 50%;
            border: 1px solid rgba(201,168,76,.06);
        }

        /* ──────────────────────────────────────────
           MARQUEE STRIP
        ────────────────────────────────────────── */
        .marquee-strip {
            background: var(--gold);
            padding: .9rem 0;
            overflow: hidden;
        }
        .marquee-track {
            display: flex;
            gap: 3rem;
            animation: marquee 22s linear infinite;
            white-space: nowrap;
        }
        .marquee-item {
            display: flex;
            align-items: center;
            gap: .6rem;
            font-size: .8rem;
            font-weight: 500;
            letter-spacing: .12em;
            text-transform: uppercase;
            color: var(--navy);
            flex-shrink: 0;
        }
        @keyframes marquee {
            0% { transform: translateX(0); }
            100% { transform: translateX(-50%); }
        }

        /* ──────────────────────────────────────────
           SECTION COMMON
        ────────────────────────────────────────── */
        section { padding: 7rem 0; }

        .section-tag {
            display: inline-block;
            font-size: .72rem;
            font-weight: 500;
            letter-spacing: .2em;
            text-transform: uppercase;
            color: var(--gold);
            margin-bottom: 1rem;
        }

        .section-title {
            font-family: var(--font-display);
            font-size: clamp(2.2rem, 5vw, 3.6rem);
            font-weight: 300;
            line-height: 1.15;
            color: var(--navy);
        }

        .section-lead {
            color: var(--mid);
            font-size: .95rem;
            line-height: 1.7;
            max-width: 480px;
        }

        /* ──────────────────────────────────────────
           FEATURES
        ────────────────────────────────────────── */
        .features-section { background: var(--white); }

        .feature-card {
            padding: 2.5rem 2rem;
            border-radius: var(--radius);
            border: 1px solid rgba(0,0,0,.06);
            background: var(--light);
            transition: all .35s;
            height: 100%;
        }
        .feature-card:hover {
            transform: translateY(-6px);
            box-shadow: var(--shadow-hover);
            border-color: rgba(201,168,76,.3);
        }

        .feature-icon {
            width: 54px; height: 54px;
            border-radius: 14px;
            background: var(--navy);
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 1.5rem;
            color: var(--gold);
            font-size: 1.2rem;
            transition: background .3s;
        }
        .feature-card:hover .feature-icon {
            background: var(--gold);
            color: var(--navy);
        }

        .feature-title {
            font-family: var(--font-display);
            font-size: 1.35rem;
            font-weight: 400;
            color: var(--navy);
            margin-bottom: .6rem;
        }

        .feature-text {
            font-size: .88rem;
            color: var(--mid);
            line-height: 1.7;
        }

        /* ──────────────────────────────────────────
           FEATURED PRODUCTS
        ────────────────────────────────────────── */
        .products-section { background: var(--light); }

        .product-card {
            background: var(--white);
            border-radius: var(--radius);
            overflow: hidden;
            border: 1px solid rgba(0,0,0,.05);
            transition: all .35s;
            display: flex;
            flex-direction: column;
            height: 100%;
        }
        .product-card:hover {
            transform: translateY(-8px);
            box-shadow: var(--shadow-hover);
        }

        .product-img {
            aspect-ratio: 1/1;
            overflow: hidden;
            position: relative;
        }
        .product-img img {
            width: 100%; height: 100%;
            object-fit: cover;
            transition: transform .5s ease;
        }
        .product-card:hover .product-img img { transform: scale(1.07); }

        .product-label {
            position: absolute;
            top: 1rem; left: 1rem;
            background: var(--gold);
            color: var(--navy);
            font-size: .68rem;
            font-weight: 600;
            letter-spacing: .1em;
            text-transform: uppercase;
            padding: .25rem .7rem;
            border-radius: 50px;
        }

        .product-body {
            padding: 1.5rem;
            flex: 1;
            display: flex;
            flex-direction: column;
        }

        .product-cat {
            font-size: .72rem;
            letter-spacing: .12em;
            text-transform: uppercase;
            color: var(--gold);
            margin-bottom: .4rem;
        }

        .product-name {
            font-family: var(--font-display);
            font-size: 1.3rem;
            font-weight: 400;
            color: var(--navy);
            line-height: 1.3;
            margin-bottom: .6rem;
        }

        .product-rating { color: var(--gold); font-size: .82rem; }

        .product-footer {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-top: auto;
            padding-top: 1rem;
        }
        .product-price {
            font-family: var(--font-display);
            font-size: 1.45rem;
            color: var(--navy);
            font-weight: 600;
        }
        .product-price-old {
            font-size: .85rem;
            color: #9ca3af;
            text-decoration: line-through;
            margin-right: .3rem;
        }

        .btn-cart {
            width: 42px; height: 42px;
            border-radius: 50%;
            background: var(--navy);
            border: none;
            color: var(--white);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: .95rem;
            cursor: pointer;
            transition: all .3s;
            flex-shrink: 0;
        }
        .btn-cart:hover {
            background: var(--gold);
            color: var(--navy);
            transform: scale(1.12);
        }

        .btn-outline-gold {
            border: 1px solid var(--gold);
            color: var(--navy);
            background: transparent;
            border-radius: 50px;
            padding: .75rem 2.5rem;
            font-size: .82rem;
            font-weight: 500;
            letter-spacing: .1em;
            text-transform: uppercase;
            transition: all .3s;
            cursor: pointer;
        }
        .btn-outline-gold:hover {
            background: var(--gold);
            border-color: var(--gold);
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(201,168,76,.3);
        }

        /* ──────────────────────────────────────────
           BANNER (mid-page)
        ────────────────────────────────────────── */
        .banner-section {
            background: var(--navy);
            padding: 6rem 0;
            position: relative;
            overflow: hidden;
        }
        .banner-section::before {
            content: '';
            position: absolute;
            inset: 0;
            background: radial-gradient(ellipse 60% 80% at 80% 50%, rgba(201,168,76,.1) 0%, transparent 70%);
        }
        .banner-section h2 {
            font-family: var(--font-display);
            font-size: clamp(2.5rem, 6vw, 4.5rem);
            font-weight: 300;
            color: var(--white);
            line-height: 1.15;
        }
        .banner-section h2 em { color: var(--gold); }
        .banner-section p { color: rgba(255,255,255,.55); font-size: .95rem; line-height: 1.7; }

        /* ──────────────────────────────────────────
           TESTIMONIALS
        ────────────────────────────────────────── */
        .testimonials-section { background: var(--white); }

        .testimonial-card {
            padding: 2.5rem;
            border-radius: var(--radius);
            background: var(--light);
            border: 1px solid rgba(0,0,0,.05);
            height: 100%;
            transition: all .35s;
            position: relative;
        }
        .testimonial-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-hover);
            border-color: rgba(201,168,76,.25);
        }

        .quote-mark {
            font-family: var(--font-display);
            font-size: 5rem;
            line-height: .8;
            color: var(--gold);
            opacity: .3;
            position: absolute;
            top: 1.5rem; right: 2rem;
        }

        .testimonial-text {
            font-size: .95rem;
            color: var(--ink);
            line-height: 1.75;
            margin-bottom: 1.5rem;
            font-style: italic;
            font-family: var(--font-display);
            font-size: 1.05rem;
        }

        .reviewer-img {
            width: 48px; height: 48px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid rgba(201,168,76,.3);
        }

        .reviewer-name {
            font-weight: 500;
            font-size: .9rem;
            color: var(--navy);
            margin-bottom: .1rem;
        }
        .reviewer-role { font-size: .75rem; color: var(--mid); }
        .review-stars { color: var(--gold); font-size: .8rem; }

        /* ──────────────────────────────────────────
           CONTACT
        ────────────────────────────────────────── */
        .contact-section { background: var(--light); }

        .contact-card {
            background: var(--white);
            border-radius: 24px;
            padding: 3.5rem;
            border: 1px solid rgba(0,0,0,.06);
            box-shadow: var(--shadow);
        }

        .form-control, .form-select {
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            padding: .85rem 1.1rem;
            font-family: var(--font-body);
            font-size: .9rem;
            background: var(--light);
            color: var(--navy);
            transition: border-color .25s, box-shadow .25s;
        }
        .form-control:focus {
            border-color: var(--gold);
            box-shadow: 0 0 0 3px rgba(201,168,76,.12);
            background: var(--white);
            outline: none;
        }
        textarea.form-control { resize: none; }

        .btn-submit {
            width: 100%;
            padding: 1rem;
            background: var(--navy);
            color: var(--white);
            border: none;
            border-radius: 10px;
            font-family: var(--font-body);
            font-size: .88rem;
            font-weight: 500;
            letter-spacing: .08em;
            text-transform: uppercase;
            cursor: pointer;
            transition: all .3s;
        }
        .btn-submit:hover {
            background: var(--gold);
            color: var(--navy);
            transform: translateY(-2px);
            box-shadow: 0 10px 30px rgba(201,168,76,.3);
        }

        /* ──────────────────────────────────────────
           FOOTER
        ────────────────────────────────────────── */
        footer {
            background: var(--navy);
            color: rgba(255,255,255,.5);
            padding: 5rem 0 2rem;
        }

        .footer-brand {
            font-family: var(--font-display);
            font-size: 1.6rem;
            color: var(--white);
            margin-bottom: 1rem;
        }
        .footer-brand span { color: var(--gold); }

        .footer-text {
            font-size: .85rem;
            line-height: 1.7;
            max-width: 280px;
        }

        .footer-heading {
            font-size: .72rem;
            font-weight: 600;
            letter-spacing: .2em;
            text-transform: uppercase;
            color: var(--white);
            margin-bottom: 1.3rem;
        }

        .footer-link {
            display: block;
            font-size: .85rem;
            color: rgba(255,255,255,.45);
            text-decoration: none;
            margin-bottom: .55rem;
            transition: color .2s;
        }
        .footer-link:hover { color: var(--gold); }

        .social-btn {
            width: 38px; height: 38px;
            border-radius: 50%;
            border: 1px solid rgba(255,255,255,.12);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: rgba(255,255,255,.45);
            font-size: .85rem;
            text-decoration: none;
            transition: all .25s;
            margin-right: .4rem;
        }
        .social-btn:hover {
            border-color: var(--gold);
            color: var(--gold);
            transform: translateY(-2px);
        }

        .newsletter-input {
            display: flex;
            gap: .5rem;
        }
        .newsletter-input input {
            flex: 1;
            background: rgba(255,255,255,.07);
            border: 1px solid rgba(255,255,255,.1);
            border-radius: 8px;
            padding: .7rem 1rem;
            color: var(--white);
            font-size: .85rem;
            font-family: var(--font-body);
        }
        .newsletter-input input::placeholder { color: rgba(255,255,255,.3); }
        .newsletter-input input:focus { outline: none; border-color: var(--gold); }
        .newsletter-btn {
            background: var(--gold);
            border: none;
            border-radius: 8px;
            padding: .7rem 1.2rem;
            color: var(--navy);
            font-size: .78rem;
            font-weight: 600;
            cursor: pointer;
            transition: background .25s;
        }
        .newsletter-btn:hover { background: var(--gold2); }

        .footer-bottom {
            margin-top: 4rem;
            padding-top: 1.5rem;
            border-top: 1px solid rgba(255,255,255,.07);
            font-size: .78rem;
            text-align: center;
        }

        /* ──────────────────────────────────────────
           SCROLL TO TOP
        ────────────────────────────────────────── */
        .scroll-top {
            position: fixed;
            bottom: 2rem; right: 2rem;
            width: 46px; height: 46px;
            background: var(--gold);
            color: var(--navy);
            border: none;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            opacity: 0;
            transform: translateY(20px);
            transition: all .35s;
            z-index: 500;
            font-size: 1rem;
        }
        .scroll-top.visible {
            opacity: 1;
            transform: translateY(0);
        }
        .scroll-top:hover { background: var(--gold2); transform: translateY(-3px); }

        /* ──────────────────────────────────────────
           RESPONSIVE
        ────────────────────────────────────────── */
        @media (max-width: 991px) {
            .hero { text-align: center; }
            .hero-lead { margin: 0 auto 2.5rem; }
            .hero-stats { justify-content: center; }
        }
        @media (max-width: 767px) {
            section { padding: 5rem 0; }
            .contact-card { padding: 2rem; }
        }
    </style>
</head>
<body>

    <!-- ── NAVBAR ── -->
    <nav class="navbar navbar-expand-lg fixed-top" id="navbar">
        <div class="container">
            <a class="navbar-brand" href="#">
                <i class="fas fa-gem me-2" style="color:var(--gold)"></i>Luxury<span>Store</span>
            </a>
            <button class="navbar-toggler border-0" type="button" data-bs-toggle="collapse" data-bs-target="#navMenu">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navMenu">
                <ul class="navbar-nav ms-auto align-items-center gap-1">
                    <li class="nav-item"><a class="nav-link" href="#home">Home</a></li>
                    <li class="nav-item"><a class="nav-link" href="#features">Features</a></li>
                    <li class="nav-item"><a class="nav-link" href="#products">Products</a></li>
                    <li class="nav-item"><a class="nav-link" href="#testimonials">Reviews</a></li>
                    <li class="nav-item"><a class="nav-link" href="#contact">Contact</a></li>
                    <li class="nav-item ms-2"><a class="nav-link nav-cta" href="#products">Shop Now</a></li>
                </ul>
            </div>
        </div>
    </nav>

    <!-- ── HERO ── -->
    <section id="home" class="hero">
        <div class="container position-relative">
            <div class="row align-items-center g-5">
                <div class="col-lg-6">
                    <div data-aos="fade-right" data-aos-duration="900">
                        <div class="hero-eyebrow">
                            <div class="hero-eyebrow-dot"></div>
                            <span class="label" style="color:var(--gold)">Premium Collection 2024</span>
                        </div>
                        <h1 class="display-serif">
                            Discover<br>Luxury <em>Redefined</em>
                        </h1>
                        <p class="hero-lead">
                            Experience the finest quality products with exceptional service. Elevate your lifestyle with our curated collection.
                        </p>
                        <div class="d-flex flex-wrap gap-3">
                            <a href="#products" class="btn-hero">
                                <i class="fas fa-shopping-bag"></i> Explore Collection
                            </a>
                            <a href="#features" class="btn-hero btn-hero-ghost">
                                Our Promise <i class="fas fa-arrow-right"></i>
                            </a>
                        </div>
                    </div>
                    <div class="hero-stats" data-aos="fade-up" data-aos-delay="300" data-aos-duration="800">
                        <div class="d-flex align-items-center gap-4 flex-wrap">
                            <div>
                                <div class="stat-num">10K+</div>
                                <div class="stat-label">Happy Customers</div>
                            </div>
                            <div class="stat-divider d-none d-sm-block"></div>
                            <div>
                                <div class="stat-num">500+</div>
                                <div class="stat-label">Products</div>
                            </div>
                            <div class="stat-divider d-none d-sm-block"></div>
                            <div>
                                <div class="stat-num">24/7</div>
                                <div class="stat-label">Support</div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-lg-6 d-none d-lg-block" data-aos="fade-left" data-aos-duration="1000" data-aos-delay="200">
                    <div class="hero-visual position-relative">
                        <div class="hero-accent-circle"></div>
                        <div class="hero-img-wrap">
                            <img src="https://images.unsplash.com/photo-1483985988355-763728e1935b?w=700&q=80" alt="Luxury Shopping">
                        </div>
                        <div class="hero-img-badge">
                            <div class="badge-sub mb-1">New Arrival</div>
                            <div class="badge-title">Spring Essentials</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- ── MARQUEE ── -->
    <div class="marquee-strip">
        <div class="marquee-track">
            <!-- duplicated for infinite loop -->
            <span class="marquee-item"><i class="fas fa-gem"></i> Free Worldwide Shipping</span>
            <span class="marquee-item"><i class="fas fa-shield-alt"></i> 100% Secure Checkout</span>
            <span class="marquee-item"><i class="fas fa-undo-alt"></i> 30-Day Returns</span>
            <span class="marquee-item"><i class="fas fa-headset"></i> 24/7 Customer Support</span>
            <span class="marquee-item"><i class="fas fa-star"></i> Certified Premium Quality</span>
            <span class="marquee-item"><i class="fas fa-gem"></i> Free Worldwide Shipping</span>
            <span class="marquee-item"><i class="fas fa-shield-alt"></i> 100% Secure Checkout</span>
            <span class="marquee-item"><i class="fas fa-undo-alt"></i> 30-Day Returns</span>
            <span class="marquee-item"><i class="fas fa-headset"></i> 24/7 Customer Support</span>
            <span class="marquee-item"><i class="fas fa-star"></i> Certified Premium Quality</span>
        </div>
    </div>

    <!-- ── FEATURES ── -->
    <section id="features" class="features-section">
        <div class="container">
            <div class="row align-items-end mb-5 g-4">
                <div class="col-lg-6" data-aos="fade-right">
                    <span class="section-tag">Why Choose Us</span>
                    <h2 class="section-title mt-1">Built Around<br>Your Experience</h2>
                </div>
                <div class="col-lg-6" data-aos="fade-left">
                    <p class="section-lead ms-lg-auto">Every feature we offer is designed to make your shopping journey seamless, secure, and truly memorable.</p>
                </div>
            </div>
            <div class="row g-4">
                <div class="col-md-6 col-lg-3" data-aos="fade-up" data-aos-delay="0">
                    <div class="feature-card">
                        <div class="feature-icon"><i class="fas fa-truck"></i></div>
                        <h3 class="feature-title">Free Shipping</h3>
                        <p class="feature-text">Complimentary worldwide shipping on all orders over $100, delivered to your door.</p>
                    </div>
                </div>
                <div class="col-md-6 col-lg-3" data-aos="fade-up" data-aos-delay="100">
                    <div class="feature-card">
                        <div class="feature-icon"><i class="fas fa-undo-alt"></i></div>
                        <h3 class="feature-title">30-Day Returns</h3>
                        <p class="feature-text">Not completely satisfied? Hassle-free returns within 30 days, no questions asked.</p>
                    </div>
                </div>
                <div class="col-md-6 col-lg-3" data-aos="fade-up" data-aos-delay="200">
                    <div class="feature-card">
                        <div class="feature-icon"><i class="fas fa-shield-alt"></i></div>
                        <h3 class="feature-title">Secure Payment</h3>
                        <p class="feature-text">Bank-grade encryption protects every transaction. Shop with complete confidence.</p>
                    </div>
                </div>
                <div class="col-md-6 col-lg-3" data-aos="fade-up" data-aos-delay="300">
                    <div class="feature-card">
                        <div class="feature-icon"><i class="fas fa-headset"></i></div>
                        <h3 class="feature-title">24/7 Support</h3>
                        <p class="feature-text">Our dedicated concierge team is always on standby, ready to assist you anytime.</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- ── PRODUCTS ── -->
    <section id="products" class="products-section">
        <div class="container">
            <div class="text-center mb-5" data-aos="fade-up">
                <span class="section-tag">Featured Collection</span>
                <h2 class="section-title mt-1">Best Sellers</h2>
                <p class="section-lead mx-auto mt-2">Our most coveted pieces, handpicked for exceptional quality and enduring style.</p>
            </div>
            <div class="row g-4" id="featuredProducts">

                <!-- Product 1 -->
                <div class="col-sm-6 col-lg-3" data-aos="fade-up" data-aos-delay="0">
                    <div class="product-card">
                        <div class="product-img">
                            <img src="https://images.unsplash.com/photo-1523275335684-37898b6baf30?w=500&q=80" alt="Watch">
                            <span class="product-label">New</span>
                        </div>
                        <div class="product-body">
                            <div class="product-cat">Accessories</div>
                            <div class="product-name">Meridian Timepiece</div>
                            <div class="review-stars mb-2">
                                <i class="fas fa-star"></i><i class="fas fa-star"></i><i class="fas fa-star"></i><i class="fas fa-star"></i><i class="fas fa-star"></i>
                                <span style="color:var(--mid);font-size:.75rem;margin-left:.3rem">5.0</span>
                            </div>
                            <div class="product-footer">
                                <div>
                                    <span class="product-price-old">$520</span>
                                    <span class="product-price">$389</span>
                                </div>
                                <button class="btn-cart"><i class="fas fa-shopping-bag"></i></button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Product 2 -->
                <div class="col-sm-6 col-lg-3" data-aos="fade-up" data-aos-delay="100">
                    <div class="product-card">
                        <div class="product-img">
                            <img src="https://images.unsplash.com/photo-1548036328-c9fa89d128fa?w=500&q=80" alt="Bag">
                            <span class="product-label">Sale</span>
                        </div>
                        <div class="product-body">
                            <div class="product-cat">Handbags</div>
                            <div class="product-name">Atelier Tote Bag</div>
                            <div class="review-stars mb-2">
                                <i class="fas fa-star"></i><i class="fas fa-star"></i><i class="fas fa-star"></i><i class="fas fa-star"></i><i class="fas fa-star-half-alt"></i>
                                <span style="color:var(--mid);font-size:.75rem;margin-left:.3rem">4.5</span>
                            </div>
                            <div class="product-footer">
                                <div>
                                    <span class="product-price-old">$310</span>
                                    <span class="product-price">$229</span>
                                </div>
                                <button class="btn-cart"><i class="fas fa-shopping-bag"></i></button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Product 3 -->
                <div class="col-sm-6 col-lg-3" data-aos="fade-up" data-aos-delay="200">
                    <div class="product-card">
                        <div class="product-img">
                            <img src="https://images.unsplash.com/photo-1491553895911-0055eca6402d?w=500&q=80" alt="Shoes">
                        </div>
                        <div class="product-body">
                            <div class="product-cat">Footwear</div>
                            <div class="product-name">Prestige Runners</div>
                            <div class="review-stars mb-2">
                                <i class="fas fa-star"></i><i class="fas fa-star"></i><i class="fas fa-star"></i><i class="fas fa-star"></i><i class="fas fa-star"></i>
                                <span style="color:var(--mid);font-size:.75rem;margin-left:.3rem">4.9</span>
                            </div>
                            <div class="product-footer">
                                <div>
                                    <span class="product-price">$185</span>
                                </div>
                                <button class="btn-cart"><i class="fas fa-shopping-bag"></i></button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Product 4 -->
                <div class="col-sm-6 col-lg-3" data-aos="fade-up" data-aos-delay="300">
                    <div class="product-card">
                        <div class="product-img">
                            <img src="https://images.unsplash.com/photo-1561361058-c24cecae35ca?w=500&q=80" alt="Perfume">
                            <span class="product-label">Popular</span>
                        </div>
                        <div class="product-body">
                            <div class="product-cat">Fragrance</div>
                            <div class="product-name">Noir Sillage Eau de Parfum</div>
                            <div class="review-stars mb-2">
                                <i class="fas fa-star"></i><i class="fas fa-star"></i><i class="fas fa-star"></i><i class="fas fa-star"></i><i class="fas fa-star"></i>
                                <span style="color:var(--mid);font-size:.75rem;margin-left:.3rem">4.8</span>
                            </div>
                            <div class="product-footer">
                                <div>
                                    <span class="product-price-old">$280</span>
                                    <span class="product-price">$215</span>
                                </div>
                                <button class="btn-cart"><i class="fas fa-shopping-bag"></i></button>
                            </div>
                        </div>
                    </div>
                </div>

            </div>
            <div class="text-center mt-5" data-aos="fade-up">
                <a href="products.php" class="btn-outline-gold">View All Products <i class="fas fa-arrow-right ms-2"></i></a>
            </div>
        </div>
    </section>

    <!-- ── MID BANNER ── -->
    <section class="banner-section">
        <div class="container position-relative">
            <div class="row align-items-center g-5">
                <div class="col-lg-6" data-aos="fade-right" data-aos-duration="900">
                    <span class="section-tag">Exclusive Offer</span>
                    <h2 class="mt-2">Up to <em>40% Off</em><br>This Season</h2>
                    <p class="mt-3 mb-4">Don't miss our biggest sale of the year. Handpicked luxury items at unbeatable prices, for a limited time only.</p>
                    <a href="#products" class="btn-hero">
                        <i class="fas fa-tag"></i> Shop the Sale
                    </a>
                </div>
                <div class="col-lg-6 d-none d-lg-block" data-aos="fade-left" data-aos-duration="900" data-aos-delay="150">
                    <div style="border-radius:20px;overflow:hidden;max-height:380px;">
                        <img src="https://images.unsplash.com/photo-1512436991641-6745cdb1723f?w=700&q=80" alt="Sale" style="width:100%;height:380px;object-fit:cover;filter:brightness(.8)">
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- ── TESTIMONIALS ── -->
    <section id="testimonials" class="testimonials-section">
        <div class="container">
            <div class="text-center mb-5" data-aos="fade-up">
                <span class="section-tag">Testimonials</span>
                <h2 class="section-title mt-1">Voices of Our Customers</h2>
                <p class="section-lead mx-auto mt-2">Real experiences from thousands of satisfied shoppers around the world.</p>
            </div>
            <div class="row g-4">

                <div class="col-md-4" data-aos="fade-up" data-aos-delay="0">
                    <div class="testimonial-card">
                        <div class="quote-mark">"</div>
                        <div class="review-stars mb-3">
                            <i class="fas fa-star"></i><i class="fas fa-star"></i><i class="fas fa-star"></i><i class="fas fa-star"></i><i class="fas fa-star"></i>
                        </div>
                        <p class="testimonial-text">Absolutely love shopping here! The quality is exceptional and the customer service team went above and beyond to help me find the perfect gift.</p>
                        <div class="d-flex align-items-center gap-3">
                            <img src="https://randomuser.me/api/portraits/women/1.jpg" alt="Sarah" class="reviewer-img">
                            <div>
                                <div class="reviewer-name">Sarah Johnson</div>
                                <div class="reviewer-role">Verified Buyer · New York</div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-md-4" data-aos="fade-up" data-aos-delay="150">
                    <div class="testimonial-card">
                        <div class="quote-mark">"</div>
                        <div class="review-stars mb-3">
                            <i class="fas fa-star"></i><i class="fas fa-star"></i><i class="fas fa-star"></i><i class="fas fa-star"></i><i class="fas fa-star"></i>
                        </div>
                        <p class="testimonial-text">Fast shipping, beautifully packaged, and the products are exactly as described. I'll definitely be a returning customer for years to come.</p>
                        <div class="d-flex align-items-center gap-3">
                            <img src="https://randomuser.me/api/portraits/men/1.jpg" alt="Michael" class="reviewer-img">
                            <div>
                                <div class="reviewer-name">Michael Chen</div>
                                <div class="reviewer-role">Verified Buyer · Singapore</div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-md-4" data-aos="fade-up" data-aos-delay="300">
                    <div class="testimonial-card">
                        <div class="quote-mark">"</div>
                        <div class="review-stars mb-3">
                            <i class="fas fa-star"></i><i class="fas fa-star"></i><i class="fas fa-star"></i><i class="fas fa-star"></i><i class="fas fa-star-half-alt"></i>
                        </div>
                        <p class="testimonial-text">Best online shopping experience I've had. The attention to detail in every product is remarkable. The packaging alone feels like a luxury gift.</p>
                        <div class="d-flex align-items-center gap-3">
                            <img src="https://randomuser.me/api/portraits/women/2.jpg" alt="Emily" class="reviewer-img">
                            <div>
                                <div class="reviewer-name">Emily Davis</div>
                                <div class="reviewer-role">Verified Buyer · London</div>
                            </div>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </section>

    <!-- ── CONTACT ── -->
    <section id="contact" class="contact-section">
        <div class="container">
            <div class="row justify-content-center">
                <div class="col-lg-8" data-aos="fade-up" data-aos-duration="900">
                    <div class="text-center mb-5">
                        <span class="section-tag">Get In Touch</span>
                        <h2 class="section-title mt-1">We'd Love to Hear<br>From You</h2>
                    </div>
                    <div class="contact-card">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <input type="text" class="form-control" placeholder="Your Name">
                            </div>
                            <div class="col-md-6">
                                <input type="email" class="form-control" placeholder="Your Email">
                            </div>
                            <div class="col-12">
                                <input type="text" class="form-control" placeholder="Subject">
                            </div>
                            <div class="col-12">
                                <textarea class="form-control" rows="5" placeholder="Your Message..."></textarea>
                            </div>
                            <div class="col-12">
                                <button class="btn-submit">
                                    Send Message &nbsp;<i class="fas fa-paper-plane"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- ── FOOTER ── -->
    <footer>
        <div class="container">
            <div class="row g-5">

                <div class="col-lg-4" data-aos="fade-up" data-aos-delay="0">
                    <div class="footer-brand"><i class="fas fa-gem me-2" style="color:var(--gold)"></i>Luxury<span>Store</span></div>
                    <p class="footer-text mb-4">Redefining luxury shopping with premium quality products and exceptional service, delivered worldwide.</p>
                    <a href="#" class="social-btn"><i class="fab fa-facebook-f"></i></a>
                    <a href="#" class="social-btn"><i class="fab fa-instagram"></i></a>
                    <a href="#" class="social-btn"><i class="fab fa-twitter"></i></a>
                    <a href="#" class="social-btn"><i class="fab fa-linkedin-in"></i></a>
                </div>

                <div class="col-6 col-lg-2" data-aos="fade-up" data-aos-delay="100">
                    <div class="footer-heading">Navigation</div>
                    <a class="footer-link" href="#home">Home</a>
                    <a class="footer-link" href="#features">Features</a>
                    <a class="footer-link" href="#products">Products</a>
                    <a class="footer-link" href="#testimonials">Reviews</a>
                    <a class="footer-link" href="#contact">Contact</a>
                </div>

                <div class="col-6 col-lg-2" data-aos="fade-up" data-aos-delay="200">
                    <div class="footer-heading">Support</div>
                    <a class="footer-link" href="#">FAQ</a>
                    <a class="footer-link" href="#">Shipping Policy</a>
                    <a class="footer-link" href="#">Returns</a>
                    <a class="footer-link" href="#">Track Order</a>
                    <a class="footer-link" href="#">Privacy Policy</a>
                </div>

                <div class="col-lg-4" data-aos="fade-up" data-aos-delay="300">
                    <div class="footer-heading">Newsletter</div>
                    <p class="footer-text mb-3">Subscribe for exclusive offers and early access to new collections.</p>
                    <div class="newsletter-input">
                        <input type="email" placeholder="Your email address">
                        <button class="newsletter-btn">Subscribe</button>
                    </div>
                    <p class="mt-3" style="font-size:.75rem;color:rgba(255,255,255,.25)">
                        <i class="fas fa-map-marker-alt me-2"></i>123 Luxury Ave, New York, NY 10001
                    </p>
                </div>

            </div>
            <div class="footer-bottom">
                &copy; 2024 LuxuryStore. All rights reserved. Crafted with <i class="fas fa-heart" style="color:var(--gold)"></i> for premium shoppers.
            </div>
        </div>
    </footer>

    <!-- Scroll to Top -->
    <button class="scroll-top" id="scrollTop" title="Back to top">
        <i class="fas fa-arrow-up"></i>
    </button>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <!-- AOS JS -->
    <script src="https://cdn.jsdelivr.net/npm/aos@2.3.4/dist/aos.js"></script>

    <script>
        // ── Init AOS ──
        AOS.init({
            duration: 750,
            easing: 'ease-out-cubic',
            once: true,
            offset: 60
        });

        // ── Navbar scroll state ──
        const navbar = document.getElementById('navbar');
        window.addEventListener('scroll', () => {
            navbar.classList.toggle('scrolled', window.scrollY > 60);
        });

        // ── Scroll to top ──
        const scrollBtn = document.getElementById('scrollTop');
        window.addEventListener('scroll', () => {
            scrollBtn.classList.toggle('visible', window.scrollY > 400);
        });
        scrollBtn.addEventListener('click', () => window.scrollTo({ top: 0, behavior: 'smooth' }));

        // ── Smooth active nav highlight ──
        const sections = document.querySelectorAll('section[id]');
        const navLinks = document.querySelectorAll('.nav-link');
        const observer = new IntersectionObserver(entries => {
            entries.forEach(e => {
                if (e.isIntersecting) {
                    navLinks.forEach(l => l.classList.remove('active'));
                    const active = document.querySelector(`.nav-link[href="#${e.target.id}"]`);
                    if (active) active.classList.add('active');
                }
            });
        }, { threshold: 0.4 });
        sections.forEach(s => observer.observe(s));

        // ── Cart button micro-interaction ──
        document.querySelectorAll('.btn-cart').forEach(btn => {
            btn.addEventListener('click', function () {
                const icon = this.querySelector('i');
                icon.className = 'fas fa-check';
                this.style.background = '#22c55e';
                setTimeout(() => {
                    icon.className = 'fas fa-shopping-bag';
                    this.style.background = '';
                }, 1500);
            });
        });
    </script>
</body>
</html>