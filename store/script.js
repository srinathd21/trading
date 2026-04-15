// Products Data
const products = [
    {
        id: 1,
        name: "Premium Wireless Headphones",
        category: "Electronics",
        price: 299.99,
        originalPrice: 499.99,
        image: "https://images.unsplash.com/photo-1505740420928-5e560c06d30e?w=400",
        rating: 4.8,
        description: "High-quality wireless headphones with noise cancellation"
    },
    {
        id: 2,
        name: "Smart Luxury Watch",
        category: "Electronics",
        price: 399.99,
        originalPrice: 599.99,
        image: "https://images.unsplash.com/photo-1523275335684-37898b6baf30?w=400",
        rating: 4.9,
        description: "Premium smartwatch with health tracking"
    },
    {
        id: 3,
        name: "Designer Running Shoes",
        category: "Fashion",
        price: 159.99,
        originalPrice: 249.99,
        image: "https://images.unsplash.com/photo-1542291026-7eec264c27ff?w=400",
        rating: 4.7,
        description: "Comfortable designer running shoes"
    },
    {
        id: 4,
        name: "Premium Leather Backpack",
        category: "Accessories",
        price: 89.99,
        originalPrice: 149.99,
        image: "https://images.unsplash.com/photo-1553062407-98eeb64c6a62?w=400",
        rating: 4.6,
        description: "Genuine leather laptop backpack"
    },
    {
        id: 5,
        name: "Designer Sunglasses",
        category: "Accessories",
        price: 129.99,
        originalPrice: 199.99,
        image: "https://images.unsplash.com/photo-1572635196237-14b3f281503f?w=400",
        rating: 4.8,
        description: "Polarized UV protection sunglasses"
    },
    {
        id: 6,
        name: "Automatic Coffee Machine",
        category: "Home",
        price: 299.99,
        originalPrice: 449.99,
        image: "https://images.unsplash.com/photo-1517668808822-9ebb02f2a0e6?w=400",
        rating: 4.9,
        description: "Premium automatic coffee maker"
    },
    {
        id: 7,
        name: "Luxury Cotton T-Shirt",
        category: "Fashion",
        price: 49.99,
        originalPrice: 89.99,
        image: "https://images.unsplash.com/photo-1521572163474-6864f9cf17ab?w=400",
        rating: 4.5,
        description: "100% Egyptian cotton t-shirt"
    },
    {
        id: 8,
        name: "Premium Tablet",
        category: "Electronics",
        price: 599.99,
        originalPrice: 799.99,
        image: "https://images.unsplash.com/photo-1546868871-7041f2a55e12?w=400",
        rating: 4.9,
        description: "High-performance tablet with retina display"
    },
    {
        id: 9,
        name: "Designer Leather Jacket",
        category: "Fashion",
        price: 299.99,
        originalPrice: 499.99,
        image: "https://images.unsplash.com/photo-1551028719-00167b16eac5?w=400",
        rating: 4.8,
        description: "Premium genuine leather jacket"
    },
    {
        id: 10,
        name: "Smart Home Speaker",
        category: "Electronics",
        price: 199.99,
        originalPrice: 299.99,
        image: "https://images.unsplash.com/photo-1589003077984-894e133dabab?w=400",
        rating: 4.7,
        description: "Voice-controlled smart speaker"
    },
    {
        id: 11,
        name: "Minimalist Watch",
        category: "Accessories",
        price: 149.99,
        originalPrice: 249.99,
        image: "https://images.unsplash.com/photo-1524805444758-089113d48a6d?w=400",
        rating: 4.6,
        description: "Elegant minimalist design watch"
    },
    {
        id: 12,
        name: "Premium Yoga Mat",
        category: "Sports",
        price: 69.99,
        originalPrice: 99.99,
        image: "https://images.unsplash.com/photo-1592432678016-e910b452f9a2?w=400",
        rating: 4.7,
        description: "Eco-friendly non-slip yoga mat"
    }
];

// Shopping Cart Class
class ShoppingCart {
    constructor() {
        this.items = this.loadFromSession();
        if (document.getElementById('productsContainer')) {
            this.updateCartDisplay();
        }
    }

    loadFromSession() {
        const savedCart = sessionStorage.getItem('shoppingCart');
        return savedCart ? JSON.parse(savedCart) : [];
    }

    saveToSession() {
        sessionStorage.setItem('shoppingCart', JSON.stringify(this.items));
    }

    addItem(product, quantity = 1) {
        const existingItem = this.items.find(item => item.id === product.id);
        
        if (existingItem) {
            existingItem.quantity += quantity;
        } else {
            this.items.push({
                ...product,
                quantity: quantity
            });
        }
        
        this.saveToSession();
        this.updateCartDisplay();
        this.showToast(`${product.name} added to cart!`);
    }

    removeItem(productId) {
        this.items = this.items.filter(item => item.id !== productId);
        this.saveToSession();
        this.updateCartDisplay();
        this.showToast('Item removed from cart');
    }

    updateQuantity(productId, newQuantity) {
        const item = this.items.find(item => item.id === productId);
        if (item) {
            if (newQuantity <= 0) {
                this.removeItem(productId);
            } else {
                item.quantity = newQuantity;
                this.saveToSession();
                this.updateCartDisplay();
            }
        }
    }

    getTotalItems() {
        return this.items.reduce((total, item) => total + item.quantity, 0);
    }

    getTotalPrice() {
        return this.items.reduce((total, item) => total + (item.price * item.quantity), 0);
    }

    clearCart() {
        this.items = [];
        this.saveToSession();
        this.updateCartDisplay();
        this.showToast('Cart cleared');
    }

    updateCartDisplay() {
        const cartCount = document.getElementById('cartCount');
        if (cartCount) {
            const totalItems = this.getTotalItems();
            cartCount.textContent = totalItems;
            
            cartCount.classList.add('cart-count-update');
            setTimeout(() => cartCount.classList.remove('cart-count-update'), 300);
        }
        
        this.renderCartItems();
        
        const cartTotal = document.getElementById('cartTotal');
        if (cartTotal) {
            cartTotal.textContent = `$${this.getTotalPrice().toFixed(2)}`;
        }
    }

    renderCartItems() {
        const cartBody = document.getElementById('cartBody');
        if (!cartBody) return;
        
        if (this.items.length === 0) {
            cartBody.innerHTML = `
                <div class="empty-cart text-center py-5">
                    <i class="fas fa-shopping-cart fa-4x text-muted mb-3"></i>
                    <p>Your cart is empty</p>
                    <button class="btn btn-primary" onclick="document.getElementById('closeCartBtn')?.click()">
                        Continue Shopping
                    </button>
                </div>
            `;
            return;
        }
        
        cartBody.innerHTML = this.items.map(item => `
            <div class="cart-item" data-id="${item.id}">
                <img src="${item.image}" alt="${item.name}" class="cart-item-image">
                <div class="cart-item-details">
                    <div class="cart-item-title">${item.name}</div>
                    <div class="cart-item-price">$${item.price.toFixed(2)}</div>
                    <div class="cart-item-quantity">
                        <button class="quantity-btn" onclick="cart.decreaseQuantity(${item.id})">-</button>
                        <span>${item.quantity}</span>
                        <button class="quantity-btn" onclick="cart.increaseQuantity(${item.id})">+</button>
                    </div>
                    <div class="remove-item" onclick="cart.removeItem(${item.id})">
                        <i class="fas fa-trash"></i> Remove
                    </div>
                </div>
            </div>
        `).join('');
    }

    decreaseQuantity(productId) {
        const item = this.items.find(item => item.id === productId);
        if (item) {
            this.updateQuantity(productId, item.quantity - 1);
        }
    }

    increaseQuantity(productId) {
        const item = this.items.find(item => item.id === productId);
        if (item) {
            this.updateQuantity(productId, item.quantity + 1);
        }
    }

    showToast(message) {
        const toastEl = document.getElementById('toast');
        const toastMessage = document.getElementById('toastMessage');
        if (toastEl && toastMessage) {
            toastMessage.textContent = message;
            const toast = new bootstrap.Toast(toastEl, { delay: 2000 });
            toast.show();
        }
    }
}

// Initialize cart
const cart = new ShoppingCart();

// Render products on products page
function renderProducts(productsToRender = products) {
    const productsContainer = document.getElementById('productsContainer');
    const loadingSpinner = document.getElementById('loadingSpinner');
    
    if (!productsContainer) return;
    
    if (loadingSpinner) loadingSpinner.style.display = 'none';
    
    if (productsToRender.length === 0) {
        productsContainer.innerHTML = `
            <div class="col-12 text-center py-5">
                <i class="fas fa-search fa-4x text-muted mb-3"></i>
                <h4>No products found</h4>
                <p>Try adjusting your filters</p>
            </div>
        `;
        return;
    }
    
    productsContainer.innerHTML = productsToRender.map(product => `
        <div class="col-md-6 col-lg-4 fade-in">
            <div class="product-card">
                <div class="product-image">
                    <img src="${product.image}" alt="${product.name}" loading="lazy">
                    <div class="product-badge">-${Math.round((1 - product.price/product.originalPrice) * 100)}%</div>
                </div>
                <div class="product-body">
                    <div class="product-category">${product.category}</div>
                    <div class="product-title">${product.name}</div>
                    <div class="product-price">
                        $${product.price.toFixed(2)}
                        <span class="product-original-price">$${product.originalPrice.toFixed(2)}</span>
                    </div>
                    <div class="product-rating">
                        ${generateStarRating(product.rating)}
                        <span class="text-muted">(${product.rating})</span>
                    </div>
                    <button class="btn btn-primary add-to-cart-btn" onclick="cart.addItem(${JSON.stringify(product).replace(/"/g, '&quot;')})">
                        <i class="fas fa-shopping-cart me-2"></i>Add to Cart
                    </button>
                </div>
            </div>
        </div>
    `).join('');
}

// Render featured products on homepage
function renderFeaturedProducts() {
    const featuredContainer = document.getElementById('featuredProducts');
    if (!featuredContainer) return;
    
    const featuredProducts = products.slice(0, 4);
    
    featuredContainer.innerHTML = featuredProducts.map(product => `
        <div class="col-md-6 col-lg-3">
            <div class="product-card">
                <div class="product-image">
                    <img src="${product.image}" alt="${product.name}" style="height: 200px;">
                </div>
                <div class="product-body">
                    <div class="product-title">${product.name}</div>
                    <div class="product-price">$${product.price.toFixed(2)}</div>
                    <button class="btn btn-sm btn-primary w-100" onclick="window.location.href='products.html'">
                        Shop Now
                    </button>
                </div>
            </div>
        </div>
    `).join('');
}

function generateStarRating(rating) {
    const fullStars = Math.floor(rating);
    const hasHalfStar = rating % 1 >= 0.5;
    let stars = '';
    
    for (let i = 0; i < fullStars; i++) {
        stars += '<i class="fas fa-star"></i>';
    }
    if (hasHalfStar) {
        stars += '<i class="fas fa-star-half-alt"></i>';
    }
    const emptyStars = 5 - Math.ceil(rating);
    for (let i = 0; i < emptyStars; i++) {
        stars += '<i class="far fa-star"></i>';
    }
    
    return stars;
}

// Filter and sort functions
function filterAndSortProducts() {
    const searchTerm = document.getElementById('searchInput')?.value.toLowerCase() || '';
    const category = document.getElementById('categoryFilter')?.value || 'all';
    const sortBy = document.getElementById('sortFilter')?.value || 'default';
    
    let filtered = products.filter(product => {
        const matchesSearch = product.name.toLowerCase().includes(searchTerm) || 
                             product.description.toLowerCase().includes(searchTerm);
        const matchesCategory = category === 'all' || product.category === category;
        return matchesSearch && matchesCategory;
    });
    
    // Sort products
    switch(sortBy) {
        case 'price-asc':
            filtered.sort((a, b) => a.price - b.price);
            break;
        case 'price-desc':
            filtered.sort((a, b) => b.price - a.price);
            break;
        case 'rating':
            filtered.sort((a, b) => b.rating - a.rating);
            break;
        default:
            // Keep original order
            break;
    }
    
    renderProducts(filtered);
}

// Cart UI functions
function toggleCart() {
    const cartSidebar = document.getElementById('cartSidebar');
    const overlay = document.getElementById('overlay');
    if (cartSidebar && overlay) {
        cartSidebar.classList.toggle('open');
        overlay.classList.toggle('show');
    }
}

// Event Listeners
document.addEventListener('DOMContentLoaded', () => {
    // Check which page we're on
    if (document.getElementById('productsContainer')) {
        // Products page
        renderProducts();
        
        // Setup filters
        const searchInput = document.getElementById('searchInput');
        const categoryFilter = document.getElementById('categoryFilter');
        const sortFilter = document.getElementById('sortFilter');
        const resetBtn = document.getElementById('resetFiltersBtn');
        
        if (searchInput) searchInput.addEventListener('input', filterAndSortProducts);
        if (categoryFilter) categoryFilter.addEventListener('change', filterAndSortProducts);
        if (sortFilter) sortFilter.addEventListener('change', filterAndSortProducts);
        if (resetBtn) {
            resetBtn.addEventListener('click', () => {
                if (searchInput) searchInput.value = '';
                if (categoryFilter) categoryFilter.value = 'all';
                if (sortFilter) sortFilter.value = 'default';
                filterAndSortProducts();
            });
        }
        
        // Cart buttons
        const cartBtn = document.getElementById('cartBtn');
        const closeCartBtn = document.getElementById('closeCartBtn');
        const overlay = document.getElementById('overlay');
        const clearCartBtn = document.getElementById('clearCartBtn');
        const checkoutBtn = document.getElementById('checkoutBtn');
        
        if (cartBtn) cartBtn.addEventListener('click', toggleCart);
        if (closeCartBtn) closeCartBtn.addEventListener('click', toggleCart);
        if (overlay) overlay.addEventListener('click', toggleCart);
        
        if (clearCartBtn) {
            clearCartBtn.addEventListener('click', () => {
                if (confirm('Are you sure you want to clear your cart?')) {
                    cart.clearCart();
                }
            });
        }
        
        if (checkoutBtn) {
            checkoutBtn.addEventListener('click', () => {
                if (cart.getTotalItems() === 0) {
                    cart.showToast('Your cart is empty!');
                    return;
                }
                const checkoutModal = new bootstrap.Modal(document.getElementById('checkoutModal'));
                checkoutModal.show();
            });
        }
        
        // Confirm order
        const confirmOrderBtn = document.getElementById('confirmOrderBtn');
        if (confirmOrderBtn) {
            confirmOrderBtn.addEventListener('click', () => {
                const fullName = document.getElementById('fullName')?.value;
                const email = document.getElementById('email')?.value;
                const address = document.getElementById('address')?.value;
                const phone = document.getElementById('phone')?.value;
                
                if (!fullName || !email || !address || !phone) {
                    cart.showToast('Please fill all required fields!');
                    return;
                }
                
                const orderDetails = {
                    orderId: 'ORD' + Date.now(),
                    customer: fullName,
                    email: email,
                    address: address,
                    phone: phone,
                    paymentMethod: document.getElementById('paymentMethod')?.value,
                    items: cart.items,
                    total: cart.getTotalPrice(),
                    orderDate: new Date().toLocaleString()
                };
                
                // Save order
                const orders = JSON.parse(sessionStorage.getItem('orders') || '[]');
                orders.push(orderDetails);
                sessionStorage.setItem('orders', JSON.stringify(orders));
                
                cart.showToast(`Order placed successfully! Order ID: ${orderDetails.orderId}`);
                cart.clearCart();
                
                const modal = bootstrap.Modal.getInstance(document.getElementById('checkoutModal'));
                if (modal) modal.hide();
                
                // Clear form
                ['fullName', 'email', 'address', 'phone'].forEach(id => {
                    const field = document.getElementById(id);
                    if (field) field.value = '';
                });
                
                toggleCart();
            });
        }
    } else {
        // Home page
        renderFeaturedProducts();
        
        // View products buttons
        const viewProductsBtn = document.getElementById('viewProductsBtn');
        const viewAllProductsBtn = document.getElementById('viewAllProductsBtn');
        
        if (viewProductsBtn) {
            viewProductsBtn.addEventListener('click', () => {
                window.location.href = 'products.html';
            });
        }
        
        if (viewAllProductsBtn) {
            viewAllProductsBtn.addEventListener('click', () => {
                window.location.href = 'products.html';
            });
        }
        
        // Contact form
        const contactForm = document.getElementById('contactForm');
        if (contactForm) {
            contactForm.addEventListener('submit', (e) => {
                e.preventDefault();
                alert('Thank you for your message! We\'ll get back to you soon.');
                contactForm.reset();
            });
        }
    }
    
    // Smooth scrolling for anchor links
    document.querySelectorAll('a[href^="#"]').forEach(anchor => {
        anchor.addEventListener('click', function (e) {
            e.preventDefault();
            const target = document.querySelector(this.getAttribute('href'));
            if (target) {
                target.scrollIntoView({ behavior: 'smooth' });
            }
        });
    });
});

// Make cart accessible globally
window.cart = cart;