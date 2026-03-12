<script>
    // ==================== GLOBAL STATE ====================
    let CART = [];
    let PRODUCTS = [];
    let BARCODE_MAP = {};
    let CUSTOMERS = [];
    let REFERRALS = [];
    let LOYALTY_SETTINGS = {};
    let CUSTOMER_POINTS = {
        available_points: 0,
        total_points_earned: 0,
        total_points_redeemed: 0
    };

    let GLOBAL_PRICE_TYPE = 'retail';
    let GST_TYPE = 'gst';
    let ACTIVE_PAYMENT_METHODS = new Set(['cash']);
    let SELECTED_REFERRAL_ID = null;
    let CURRENT_CUSTOMER_ID = null;
    let LOYALTY_POINTS_DISCOUNT = 0;
    let POINTS_USED = 0;
    let PENDING_CONFIRMATION = null;
    let IS_INITIALIZED = false;
    let CURRENT_PRODUCT = null;
    let CURRENT_UNIT_IS_SECONDARY = false;
    let IS_CART_LOADED = false;
    // ==================== INITIALIZATION ====================
    document.addEventListener('DOMContentLoaded', function () {
        console.log('POS System: Initializing...');
        try {
            initializeApp();
            setupEventListeners();
            loadInitialData();
            loadCartFromSession();

            // Add profit button after everything loads
            setTimeout(() => {
                addProfitButtonToFixedBottom();
            }, 500);
        } catch (error) {
            console.error('POS System: Initialization failed:', error);
            showToast('System initialization failed. Please refresh the page.', 'danger');
        }
    });
    const style = document.createElement('style');
    style.textContent = `
    /* Category and Subcategory styling */
    .category-badge {
        display: inline-block;
        padding: 2px 8px;
        border-radius: 12px;
        font-size: 0.8em;
        font-weight: 600;
        margin-right: 4px;
    }
    
    .subcategory-badge {
        display: inline-block;
        padding: 2px 8px;
        border-radius: 12px;
        font-size: 0.8em;
        font-weight: 500;
        background-color: #e3f2fd;
        color: #0d47a1;
    }
    
    /* Stock indicators */
    .stock-high {
        background-color: #c8e6c9;
        color: #1b5e20;
        padding: 2px 8px;
        border-radius: 12px;
        font-weight: 600;
    }
    
    .stock-medium {
        background-color: #e8f5e9;
        color: #2e7d32;
        padding: 2px 8px;
        border-radius: 12px;
        font-weight: 600;
    }
    
    .stock-low {
        background-color: #fff3e0;
        color: #ef6c00;
        padding: 2px 8px;
        border-radius: 12px;
        font-weight: 600;
    }
    
    .stock-out {
        background-color: #ffebee;
        color: #c62828;
        padding: 2px 8px;
        border-radius: 12px;
        font-weight: 600;
    }
    
    /* Price tag */
    .price-tag {
        background-color: #e8f5e9;
        color: #2e7d32;
        padding: 2px 8px;
        border-radius: 12px;
        font-weight: 700;
    }
    
    /* Product code */
    .product-code {
        color: #6c757d;
        font-size: 0.85em;
        font-family: monospace;
    }
    
    /* Cart row styles */
    .cart-category-badge {
        font-size: 0.75em;
        padding: 1px 6px;
        border-radius: 10px;
        background-color: #f8f9fa;
        color: #495057;
    }
`;
    document.head.appendChild(style);

    // Configure SweetAlert2 defaults
    const Toast = Swal.mixin({
        toast: true,
        position: 'top-end',
        showConfirmButton: false,
        timer: 1000,
        timerProgressBar: true,
        didOpen: (toast) => {
            toast.addEventListener('mouseenter', Swal.stopTimer)
            toast.addEventListener('mouseleave', Swal.resumeTimer)
        }
    });

    // Success Toast
    function showSuccessToast(message) {
        Toast.fire({
            icon: 'success',
            title: message
        });
    }

    // Error Toast
    function showErrorToast(message) {
        Toast.fire({
            icon: 'error',
            title: message
        });
    }

    // Warning Toast
    function showWarningToast(message) {
        Toast.fire({
            icon: 'warning',
            title: message
        });
    }

    // Info Toast
    function showInfoToast(message) {
        Toast.fire({
            icon: 'info',
            title: message
        });
    }


    async function initializeApp() {
        console.log('POS System: Initializing...');

        try {
            // Check for common errors first
            if (typeof $ === 'undefined') {
                throw new Error('jQuery not loaded');
            }

            if (typeof bootstrap === 'undefined') {
                throw new Error('Bootstrap not loaded');
            }

            // Set today's date
            const today = new Date().toISOString().split('T')[0];
            document.getElementById('date').value = today;

            // Set quotation valid until (15 days from now)
            const quotationDate = new Date();
            quotationDate.setDate(quotationDate.getDate() + 15);
            document.getElementById('quotationValidUntil').value = quotationDate.toISOString().split('T')[0];

            // Initialize Select2
            try {
                $('#search-product').select2({
                    placeholder: 'Search product...',
                    allowClear: true,
                    width: '100%'
                });

                $('#customer-contact').select2({
                    placeholder: 'Select or type phone',
                    tags: true,
                    allowClear: true,
                    width: '100%'
                });

                $('#referral').select2({
                    placeholder: 'Select referral...',
                    allowClear: true,
                    width: '100%'
                });
            } catch (select2Error) {
                console.warn('Select2 initialization warning:', select2Error);
            }

            // Generate invoice number
            generateInvoiceNumber();

            IS_INITIALIZED = true;
            console.log('POS System: Application initialized successfully');

        } catch (error) {
            console.error('POS System: Application initialization error:', error);
            showToast(`Initialization error: ${error.message}. Some features may not work.`, 'danger');
        }
    }

    async function loadInitialData() {
        console.log('POS System: Loading initial data...');

        if (!IS_INITIALIZED) {
            showToast('System not initialized properly. Please refresh.', 'danger');
            return;
        }

        try {
            // Load products once
            await loadProducts();

            // Pre-populate the dropdown with first 10 products
            populateProductDropdownFromSearch();

            // Load other data in background
            setTimeout(() => {
                loadCustomers().catch(() => console.warn('Customers load failed'));
                loadReferrals().catch(() => console.warn('Referrals load failed'));
                loadLoyaltySettings().catch(() => console.warn('Loyalty settings load failed'));
            }, 1000);

            console.log(`POS System: Ready with ${PRODUCTS.length} products loaded locally`);

            // Only show toast if not already shown
            if (!IS_CART_LOADED) {
                showToast('System ready! Products loaded locally.', 'success');
            }

        } catch (error) {
            console.error('POS System: Initial data loading failed:', error);
            showToast('Could not load products. Please check connection.', 'danger');
        }
    }

    // ==================== SESSION/CART STORAGE ====================
    function saveCartToSession() {
        try {
            const cartData = JSON.stringify(CART);
            sessionStorage.setItem('pos_cart', cartData);
            console.log('Cart saved to session:', CART.length, 'items');
        } catch (error) {
            console.error('Error saving cart to session:', error);
        }
    }

    function loadCartFromSession() {
        try {
            const cartData = sessionStorage.getItem('pos_cart');
            if (cartData) {
                const parsedCart = JSON.parse(cartData);
                if (Array.isArray(parsedCart) && parsedCart.length > 0) {
                    CART = parsedCart;
                    console.log('Cart loaded from session:', CART.length, 'items');
                    renderCart();
                    updateBillingSummary();
                    updateButtonStates();

                    // Show toast only once and only if there are items
                    if (!IS_CART_LOADED && CART.length > 0) {
                        showToast(`Loaded ${CART.length} items from previous session`, 'info');
                        IS_CART_LOADED = true;
                    }
                }
            }
        } catch (error) {
            console.error('Error loading cart from session:', error);
            sessionStorage.removeItem('pos_cart');
            CART = [];
        }
    }

    function clearCartFromSession() {
        try {
            sessionStorage.removeItem('pos_cart');
            console.log('Cart cleared from session');
        } catch (error) {
            console.error('Error clearing cart from session:', error);
        }
    }

    // ==================== DATA LOADING FUNCTIONS ====================
    async function loadProducts() {
        console.log('POS System: Loading products...');

        try {
            const response = await fetchWithTimeout('api/products.php?action=list', {
                timeout: 8000,
                retries: 2,
                credentials: 'include'
            });

            if (!response.ok) {
                console.error('API Response not OK:', response.status, response.statusText);
                throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            }

            const data = await response.json();
            console.log('API Response data received - First product sample:', data.products ? data.products[0] : 'No products');

            if (!data.success) {
                console.error('API returned success=false:', data.message);
                throw new Error(data.message || 'Unknown server error');
            }

            PRODUCTS = data.products || [];
            BARCODE_MAP = data.barcode_map || {};

            // Normalize stock fields for all products
            PRODUCTS.forEach((product, index) => {
                // Debug log for first few products
                if (index < 3) {
                    console.log(`Processing product ${index}:`, {
                        name: product.product_name,
                        availableFields: Object.keys(product),
                        shop_stock: product.shop_stock,
                        shop_stock_primary: product.shop_stock_primary,
                        shop_stock_secondary: product.shop_stock_secondary
                    });
                }

                // Determine primary stock value
                let primaryStock = 0;

                // Check various possible field names
                if (product.shop_stock_primary !== undefined) {
                    primaryStock = parseFloat(product.shop_stock_primary) || 0;
                } else if (product.shop_stock !== undefined) {
                    primaryStock = parseFloat(product.shop_stock) || 0;
                    // Add the new field for consistency
                    product.shop_stock_primary = primaryStock;
                } else if (product.stock !== undefined) {
                    primaryStock = parseFloat(product.stock) || 0;
                    product.shop_stock_primary = primaryStock;
                }

                // Ensure the field exists
                product.shop_stock_primary = primaryStock;

                // Calculate secondary stock if applicable
                if (product.secondary_unit && product.sec_unit_conversion) {
                    const conversion = parseFloat(product.sec_unit_conversion) || 1;
                    product.shop_stock_secondary = primaryStock * conversion;
                } else {
                    product.shop_stock_secondary = 0;
                }

                // For backward compatibility, also keep the old field
                if (product.shop_stock === undefined) {
                    product.shop_stock = primaryStock;
                }
            });

            console.log(`POS System: Loaded ${PRODUCTS.length} products, ${Object.keys(BARCODE_MAP).length} barcodes`);

            // Log sample of processed products
            console.log('Sample processed products:', PRODUCTS.slice(0, 3).map(p => ({
                name: p.product_name,
                primary_stock: p.shop_stock_primary,
                secondary_stock: p.shop_stock_secondary,
                unit: p.unit_of_measure
            })));

            return true;

        } catch (error) {
            console.error('POS System: Product loading failed:', error);
            throw error;
        }
    }
    async function checkAndGenerateInvoiceNumber() {
        try {
            const response = await fetchWithTimeout('api/invoices.php?action=get_next_invoice_number', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json'
                },
                body: JSON.stringify({
                    prefix: GST_TYPE === 'gst' ? 'INV' : 'INVNG',
                    year_month: new Date().toISOString().substring(0, 7).replace('-', ''),
                    invoice_type: GST_TYPE
                }),
                timeout: 5000
            });

            if (!response.ok) {
                throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            }

            const data = await response.json();

            if (data.success) {
                document.getElementById('invoice-number').value = data.invoice_number;
                console.log('Generated invoice number:', data.invoice_number);
                return data.invoice_number;
            } else {
                console.warn('Could not generate invoice number:', data.message);
                return null;
            }
        } catch (error) {
            console.warn('Error generating invoice number:', error);
            return null;
        }
    }
    async function fetchWithTimeout(url, options = {}) {
        const { timeout = 10000, retries = 1, ...fetchOptions } = options;

        // Always include credentials for session-based auth
        fetchOptions.credentials = fetchOptions.credentials || 'include';

        for (let attempt = 1; attempt <= retries + 1; attempt++) {
            try {
                const controller = new AbortController();
                const timeoutId = setTimeout(() => controller.abort(), timeout);

                const response = await fetch(url, {
                    ...fetchOptions,
                    signal: controller.signal
                });

                clearTimeout(timeoutId);
                return response;

            } catch (error) {
                if (attempt > retries) {
                    if (error.name === 'AbortError') {
                        throw new Error(`Request timeout after ${timeout}ms`);
                    }
                    throw error;
                }
                console.warn(`POS System: Fetch attempt ${attempt} failed, retrying...`, error);
                await new Promise(resolve => setTimeout(resolve, 1000 * attempt));
            }
        }
    }

    // ==================== PRODUCT SEARCH FUNCTIONS ====================
    function searchProductsLocally(searchTerm) {
        if (!searchTerm || searchTerm.length < 2) {
            return [];
        }

        const term = searchTerm.toLowerCase().trim();

        return PRODUCTS.filter(product => {
            return (
                (product.product_name && product.product_name.toLowerCase().includes(term)) ||
                (product.product_code && product.product_code.toLowerCase().includes(term)) ||
                (product.barcode && product.barcode.toLowerCase().includes(term))
            );
        }).slice(0, 20); // Limit to 20 results
    }

    function populateProductDropdownFromSearch(searchTerm = '') {
    const select = document.getElementById('search-product');
    if (!select) return;

    // Clear existing options
    select.innerHTML = '<option value="">-- Search product --</option>';

    let productsToShow = [];

    if (!searchTerm || searchTerm.trim().length < 2) {
        // ✅ SHOW ALL PRODUCTS - removed the .slice(0, 10) limit
        productsToShow = PRODUCTS; // Now shows ALL 13 products
    } else {
        productsToShow = searchProductsLocally(searchTerm);
    }

    productsToShow.forEach((product, index) => {
        try {
            const option = document.createElement('option');
            option.value = product.id;

            // COLORS FOR CATEGORY AND SUBCATEGORY
            const categoryColor = getCategoryColor(product.category_name || '');
            const subcategoryColor = getSubcategoryColor(product.subcategory_name || '');

            // Build display text with COLORED category and subcategory
            let displayText = `${escapeHtml(product.product_name)}`;

            // Add colored category and subcategory
            if (product.category_name) {
                displayText += ` <span style="color: ${categoryColor}; font-weight: 500;">[${escapeHtml(product.category_name)}`;
                if (product.subcategory_name) {
                    displayText += ` <span style="color: ${subcategoryColor};">→ ${escapeHtml(product.subcategory_name)}</span>`;
                }
                displayText += ']</span>';
            }

            if (product.product_code) {
                displayText += ` <span style="color: #6c757d; font-size: 0.9em;">${escapeHtml(product.product_code)}</span>`;
            }

            // Get stock value
            const shopStockPrimary = parseFloat(product.shop_stock_primary) || 0;
            const shopStockSecondary = parseFloat(product.shop_stock_secondary) || 0;

            // COLOR-CODED STOCK BADGES
            const stockColor = getStockColor(shopStockPrimary);
            const stockBgColor = getStockBackgroundColor(shopStockPrimary);

            // Add stock badges with colors
            if (shopStockPrimary > 0) {
                if (product.secondary_unit && product.sec_unit_conversion) {
                    // Show both primary and secondary stock with colors
                    displayText += ` <span style="
                        background-color: ${stockBgColor};
                        color: ${stockColor};
                        padding: 2px 8px;
                        border-radius: 12px;
                        font-size: 0.85em;
                        font-weight: 600;
                        display: inline-block;
                        margin: 2px 0;
                    ">📦 ${Math.round(shopStockPrimary)} ${product.unit_of_measure}</span>`;

                    displayText += ` <span style="
                        background-color: #e3f2fd;
                        color: #0d47a1;
                        padding: 2px 8px;
                        border-radius: 12px;
                        font-size: 0.85em;
                        margin: 2px 0;
                    ">↳ ${Math.round(shopStockSecondary)} ${product.secondary_unit}</span>`;
                } else {
                    displayText += ` <span style="
                        background-color: ${stockBgColor};
                        color: ${stockColor};
                        padding: 2px 8px;
                        border-radius: 12px;
                        font-size: 0.85em;
                        font-weight: 600;
                    ">📦 ${Math.round(shopStockPrimary)} ${product.unit_of_measure}</span>`;
                }
            } else {
                displayText += ` <span style="
                    background-color: #ffebee;
                    color: #c62828;
                    padding: 2px 8px;
                    border-radius: 12px;
                    font-size: 0.85em;
                    font-weight: 600;
                ">⛔ Out of stock</span>`;
            }

            // Add price with color
            const price = GLOBAL_PRICE_TYPE === 'wholesale' ?
                (product.wholesale_price || product.retail_price || 0) :
                (product.retail_price || 0);
            displayText += ` <span style="
                color: #2e7d32;
                font-weight: 700;
                background-color: #e8f5e9;
                padding: 2px 8px;
                border-radius: 12px;
            ">₹${Math.round(price)}</span>`;

            option.innerHTML = displayText;

            // Store data as attributes
            option.dataset.productId = product.id;
            option.dataset.productName = product.product_name;
            option.dataset.productCode = product.product_code || '';
            option.dataset.retail = product.retail_price || 0;
            option.dataset.wholesale = product.wholesale_price || 0;
            option.dataset.mrp = product.mrp || 0;
            option.dataset.shopStockPrimary = shopStockPrimary;
            option.dataset.shopStockSecondary = shopStockSecondary;
            option.dataset.unit = product.unit_of_measure || 'PCS';
            option.dataset.hsn = product.hsn_code || '';
            option.dataset.cgst = product.cgst_rate || 0;
            option.dataset.sgst = product.sgst_rate || 0;
            option.dataset.igst = product.igst_rate || 0;
            option.dataset.referral = product.referral_enabled || 0;
            option.dataset.secondary = product.secondary_unit || '';
            option.dataset.conversion = product.sec_unit_conversion || 1;
            option.dataset.extraCharge = product.sec_unit_extra_charge || 0;
            option.dataset.extraChargeType = product.sec_unit_price_type || 'fixed';
            option.dataset.stockPrice = product.stock_price || 0;
            option.dataset.categoryName = product.category_name || '';
            option.dataset.subcategoryName = product.subcategory_name || '';

            select.appendChild(option);

        } catch (productError) {
            console.warn('Error processing product:', productError);
        }
    });

    // Refresh Select2
    if (typeof $.fn.select2 !== 'undefined') {
        try {
            $('#search-product').trigger('change.select2');
        } catch (select2Error) {
            console.warn('Select2 refresh error:', select2Error);
        }
    }
}
    function getCategoryColor(categoryName) {
        const colorMap = {
            'Electronics': '#0d47a1',
            'Fashion': '#ad1457',
            'Groceries': '#2e7d32',
            'Furniture': '#bf360c',
            'Books': '#4a148c',
            'Sports': '#b45309',
            'Toys': '#7b1fa2',
            'Beauty': '#c2185b',
            'Automotive': '#37474f',
            'Health': '#00695c',
            'default': '#455a64'
        };

        return colorMap[categoryName] || colorMap['default'];
    }

    function getSubcategoryColor(subcategoryName) {
        const colorMap = {
            'Mobile': '#1565c0',
            'Laptop': '#283593',
            'Men': '#6d4c41',
            'Women': '#c2185b',
            'Kids': '#f57c00',
            'Vegetables': '#2e7d32',
            'Fruits': '#ef6c00',
            'Dairy': '#0d47a1',
            'default': '#546e7a'
        };

        return colorMap[subcategoryName] || colorMap['default'];
    }

    function getStockColor(stock) {
        if (stock <= 0) return '#c62828';
        if (stock < 10) return '#ef6c00';
        if (stock < 50) return '#2e7d32';
        return '#1b5e20';
    }

    function getStockBackgroundColor(stock) {
        if (stock <= 0) return '#ffebee';
        if (stock < 10) return '#fff3e0';
        if (stock < 50) return '#e8f5e9';
        return '#c8e6c9';
    }
    // ==================== EVENT LISTENERS SETUP ====================
    function setupEventListeners() {
        console.log('POS System: Setting up event listeners...');

        try {
            // Product selection - USE A DIFFERENT APPROACH TO AVOID RECURSION
            $('#search-product').on('change.select2', function (e) {
                // Use a flag to prevent recursion
                if (window.isHandlingProductChange) return;
                window.isHandlingProductChange = true;

                try {
                    handleProductSelection.call(this);
                } finally {
                    setTimeout(() => {
                        window.isHandlingProductChange = false;
                    }, 100);
                }
            });

            // Product search input
            document.getElementById('search-product').addEventListener('input', function (e) {
                const searchTerm = e.target.value;
                if (searchTerm.length >= 2) {
                    populateProductDropdownFromSearch(searchTerm);
                } else {
                    populateProductDropdownFromSearch();
                }
            });

            document.getElementById('barcode-input').addEventListener('keydown', handleBarcodeScan);
            document.getElementById('product-add-button').addEventListener('click', addProductToCart);
            document.getElementById('unit-convert').addEventListener('click', toggleUnitConversion);

            // Customer selection
            $('#customer-contact').on('change', handleCustomerSelection);

            // Price type change
            document.getElementById('price-type').addEventListener('change', function () {
                GLOBAL_PRICE_TYPE = this.value;
                if (CURRENT_PRODUCT) {
                    updateProductForm(CURRENT_PRODUCT);
                }
                updateCartPriceTypes();
                populateProductDropdownFromSearch();
            });

            // Invoice type change
            document.getElementById('invoice-type').addEventListener('change', async function () {
                GST_TYPE = this.value;
                await checkAndGenerateInvoiceNumber();
                updateBillingSummary();
            });

            // Referral selection
            $('#referral').on('change', function () {
                SELECTED_REFERRAL_ID = this.value ? parseInt(this.value) : null;
                updateBillingSummary();
            });

            // Product discount input
            document.getElementById('discount').addEventListener('input', updateProductPriceDisplay);
            document.getElementById('discount-type').addEventListener('change', updateProductPriceDisplay);

            // Action buttons
            document.getElementById('btnHoldList').addEventListener('click', loadHoldList);
            document.getElementById('btnHold').addEventListener('click', holdInvoice);
            document.getElementById('btnQuotation').addEventListener('click', showQuotationModal);
            document.getElementById('btnClearCart').addEventListener('click', clearCart);
            document.getElementById('btnResetForm').addEventListener('click', resetForm);
            document.getElementById('btnShowPointsDetails').addEventListener('click', showPointsModal);
            document.getElementById('btnGenerateBill').addEventListener('click', generateBill);
            document.getElementById('btnPrintBill').addEventListener('click', printBill);
            document.getElementById('btnAutoFillRemaining').addEventListener('click', autoFillRemainingAmount);

            // Payment methods
            document.querySelectorAll('input[name="payment-method"]').forEach(checkbox => {
                checkbox.addEventListener('change', handlePaymentMethodCheckbox);
            });

            // Payment amount inputs
            document.getElementById('cash-amount').addEventListener('input', updatePaymentSummary);
            document.getElementById('upi-amount').addEventListener('input', updatePaymentSummary);
            document.getElementById('bank-amount').addEventListener('input', updatePaymentSummary);
            document.getElementById('cheque-amount').addEventListener('input', updatePaymentSummary);
            document.getElementById('credit-amount').addEventListener('input', updatePaymentSummary);

            // Discount inputs
            document.getElementById('additional-dis').addEventListener('input', updateBillingSummary);
            document.getElementById('discount-type').addEventListener('change', updateBillingSummary);

            // Selling price input
            document.getElementById('selling-price').addEventListener('input', function () {
                const discountInput = document.getElementById('discount');
                // Only reset discount if it's currently 0 or if selling price is manually changed
                if (parseFloat(discountInput.value) === 0) {
                    updateProductPriceDisplay();
                }
            });

            // Quantity input
            document.getElementById('qty-input').addEventListener('input', function () {
                if (CURRENT_PRODUCT && CURRENT_UNIT_IS_SECONDARY) {
                    updateSecondaryUnitPrice();
                }
            });

            // Modal buttons
            document.getElementById('confirmHold').addEventListener('click', saveHoldInvoice);
            document.getElementById('saveQuotationBtn').addEventListener('click', saveQuotation);
            document.getElementById('btnUseMaxPoints').addEventListener('click', useMaxPoints);
            document.getElementById('btnApplyPointsDiscount').addEventListener('click', applyPointsDiscount);
            document.getElementById('confirmActionBtn').addEventListener('click', executePendingConfirmation);
            document.getElementById('pointsToRedeem').addEventListener('input', updatePointsDiscountPreview);

            // Add Enter key functionality for product search
            document.getElementById('search-product').addEventListener('keydown', function (e) {
                if (e.key === 'Enter' && this.value) {
                    e.preventDefault();
                    const productId = this.value;
                    if (productId) {
                        const product = findProductById(productId);
                        if (product) {
                            updateProductForm(product);
                            // Auto-add to cart after 100ms
                            setTimeout(() => {
                                addProductToCart();
                            }, 100);
                        }
                    }
                }
            });

            // Add keyboard shortcuts
            document.addEventListener('keydown', function (e) {
                // Ctrl+Enter to add product
                if (e.ctrlKey && e.key === 'Enter') {
                    e.preventDefault();
                    if (CURRENT_PRODUCT) {
                        addProductToCart();
                    }
                }

                // F1 for help
                if (e.key === 'F1') {
                    e.preventDefault();
                    showInfoModal('Keyboard Shortcuts',
                        '• Enter: Add product to cart<br>' +
                        '• Ctrl+Enter: Add product quickly<br>' +
                        '• Esc: Go to invoices page<br>' +
                        '• F1: Show this help'
                    );
                }

                // Escape key handler to go to invoices.php - NO ALERT
                if (e.key === 'Escape') {
                    e.preventDefault();
                    window.location.href = 'invoices.php';
                }
            });

            // Form reset on page unload warning
            window.addEventListener('beforeunload', function (e) {
                if (CART.length > 0) {
                    e.preventDefault();
                    e.returnValue = 'You have unsaved items in your cart. Are you sure you want to leave?';
                    return e.returnValue;
                }
            });
            console.log('POS System: Event listeners setup complete');

        } catch (error) {
            console.error('POS System: Error setting up event listeners:', error);

        }
    }

    // ==================== PRODUCT HANDLING ====================
    function handleProductSelection() {
        try {
            const productId = this.value;
            console.log('Product selected:', productId);

            if (!productId) {
                clearProductSelection();
                return;
            }

            // Find the product in PRODUCTS array
            const product = PRODUCTS.find(p => p.id == productId);
            if (product) {
                console.log('Found product:', product.product_name);
                updateProductForm(product);
            } else {
                showToast('Selected product not found. Please refresh the product list.', 'warning');
                clearProductSelection();
            }
        } catch (error) {
            console.error('POS System: Error handling product selection:', error);
            showToast('Error selecting product. Please try again.', 'danger');
        }
    }

    function handleBarcodeScan(event) {
        if (event.key === 'Enter') {
            event.preventDefault();

            try {
                const barcodeInput = document.getElementById('barcode-input');
                const barcode = barcodeInput.value.trim();

                if (!barcode) {
                    showToast('Please enter a barcode first', 'warning');
                    return;
                }

                const product = findProductByBarcode(barcode);
                if (!product) {
                    showToast(`Product not found for barcode: ${barcode}`, 'danger');
                    barcodeInput.value = '';
                    barcodeInput.focus();
                    return;
                }

                // Select the product in dropdown
                $('#search-product').val(product.id).trigger('change');
                updateProductForm(product);

                // Auto-add to cart
                setTimeout(() => {
                    addProductToCart();
                }, 100);

                barcodeInput.value = '';
                barcodeInput.focus();

            } catch (error) {
                console.error('POS System: Error handling barcode scan:', error);
                showToast('Error processing barcode. Please try again.', 'danger');
            }
        }
    }

    function findProductById(id) {
        if (!id || isNaN(id)) {
            console.warn('POS System: Invalid product ID:', id);
            return null;
        }

        const product = PRODUCTS.find(p => p.id == id);
        if (!product) {
            console.warn('POS System: Product not found with ID:', id);
            return null;
        }

        // Ensure stock values are properly set
        // First, try to get stock from various possible fields
        let shopStockPrimary = 0;

        if (product.shop_stock_primary !== undefined) {
            shopStockPrimary = parseFloat(product.shop_stock_primary) || 0;
        } else if (product.shop_stock !== undefined) {
            shopStockPrimary = parseFloat(product.shop_stock) || 0;
            // Update the product object for consistency
            product.shop_stock_primary = shopStockPrimary;
        } else if (product.shop_stock_primary_display !== undefined) {
            shopStockPrimary = parseFloat(product.shop_stock_primary_display) || 0;
        }

        // Ensure the field exists
        product.shop_stock_primary = shopStockPrimary;

        // Calculate secondary stock if needed
        if (product.secondary_unit && product.sec_unit_conversion) {
            const conversion = parseFloat(product.sec_unit_conversion) || 1;
            product.shop_stock_secondary = shopStockPrimary * conversion;
        } else {
            product.shop_stock_secondary = 0;
        }

        console.log('findProductById returning:', {
            id: product.id,
            name: product.product_name,
            shop_stock_primary: product.shop_stock_primary,
            shop_stock_secondary: product.shop_stock_secondary,
            unit_of_measure: product.unit_of_measure,
            secondary_unit: product.secondary_unit
        });

        return product;
    }

    function findProductByBarcode(code) {
        if (!code || typeof code !== 'string') {
            console.warn('POS System: Invalid barcode:', code);
            return null;
        }

        const cleanCode = String(code).trim();

        // Check barcode map first
        const prodId = BARCODE_MAP[cleanCode];
        if (prodId) {
            const product = findProductById(prodId);
            if (product) return product;
        }

        // Fallback search
        return PRODUCTS.find(p =>
            (p.barcode && p.barcode === cleanCode) ||
            (p.product_code && p.product_code === cleanCode)
        );
    }

    function updateProductForm(product) {
        try {
            if (!product) {
                console.error('POS System: Cannot update form with null product');
                return;
            }

            console.log('POS System: Updating product form for:', {
                id: product.id,
                name: product.product_name,
                stock_primary: product.shop_stock_primary,
                stock_secondary: product.shop_stock_secondary,
                unit_of_measure: product.unit_of_measure,
                secondary_unit: product.secondary_unit,
                sec_unit_conversion: product.sec_unit_conversion
            });

            CURRENT_PRODUCT = product;
            CURRENT_UNIT_IS_SECONDARY = false;

            // Calculate GST exclusive price
            const cgstRate = parseFloat(product.cgst_rate) || 0;
            const sgstRate = parseFloat(product.sgst_rate) || 0;
            const igstRate = parseFloat(product.igst_rate) || 0;

            let sellingPrice = 0;
            if (GLOBAL_PRICE_TYPE === 'wholesale') {
                sellingPrice = parseFloat(product.wholesale_price) || parseFloat(product.retail_price) || 0;
            } else {
                sellingPrice = parseFloat(product.retail_price) || 0;
            }

            // Set form values
            document.getElementById('mrp').value = Math.round(parseFloat(product.mrp) || 0);
            document.getElementById('selling-price').value = Math.round(sellingPrice);
            document.getElementById('qty-unit').textContent = product.unit_of_measure || 'PCS';
            document.getElementById('qty-input').value = '1';

            // Reset discount
            document.getElementById('discount').value = '0';
            document.getElementById('discount-type').value = 'percentage';

            // Enable/disable convert button
            const convertBtn = document.getElementById('unit-convert');
            if (product.secondary_unit && product.secondary_unit.trim() &&
                product.sec_unit_conversion && product.sec_unit_conversion > 0) {
                convertBtn.disabled = false;
                convertBtn.title = `Convert to ${product.secondary_unit}`;
                convertBtn.innerHTML = `<i class="fas fa-exchange-alt me-1"></i> `;
            } else {
                convertBtn.disabled = true;
                convertBtn.title = 'No secondary unit available';
                convertBtn.innerHTML = `<i class="fas fa-exchange-alt me-1"></i>`;
            }

            // Enable add button
            const addBtn = document.getElementById('product-add-button');
            addBtn.disabled = false;
            addBtn.title = 'Add to cart';

            // Update price display
            updateProductPriceDisplay();

            // Auto-focus on quantity and select all text
            setTimeout(() => {
                const qtyInput = document.getElementById('qty-input');
                qtyInput.focus();
                qtyInput.select();
            }, 100);

        } catch (error) {
            console.error('POS System: Error updating product form:', error);
            showToast('Error loading product details. Please try again.', 'danger');
        }
    }

    // ==================== PRODUCT HANDLING ====================
    function handleProductSelection() {
        try {
            const productId = this.value;
            console.log('Product selected:', productId);

            if (!productId) {
                clearProductSelection();
                return;
            }

            // Find the product in PRODUCTS array
            const product = PRODUCTS.find(p => p.id == productId);
            if (product) {
                console.log('Found product with stock:', {
                    name: product.product_name,
                    shop_stock_primary: product.shop_stock_primary,
                    shop_stock_secondary: product.shop_stock_secondary,
                    total_stock_primary: product.total_stock_primary,
                    total_stock_secondary: product.total_stock_secondary
                });

                // If the product doesn't have the calculated stock fields, calculate them
                if (!product.shop_stock_primary && product.shop_stock !== undefined) {
                    product.shop_stock_primary = product.shop_stock;
                }

                updateProductForm(product);
            } else {
                showToast('Selected product not found. Please refresh the product list.', 'warning');
                clearProductSelection();
            }
        } catch (error) {
            console.error('POS System: Error handling product selection:', error);
            showToast('Error selecting product. Please try again.', 'danger');
        }
    }

    function clearProductSelection() {
        try {
            // REMOVE THE TRIGGER EVENT - THIS WAS CAUSING THE INFINITE LOOP
            // Instead, just clear the values directly
            document.getElementById('search-product').value = '';

            // If Select2 is initialized, update it without triggering change
            if (typeof $.fn.select2 !== 'undefined') {
                $('#search-product').val('').trigger('change.select2');
            }

            document.getElementById('barcode-input').value = '';
            document.getElementById('mrp').value = '';
            document.getElementById('selling-price').value = '';
            document.getElementById('discount').value = '0';
            document.getElementById('qty-input').value = '1';
            document.getElementById('qty-unit').textContent = 'PCS';


            const convertBtn = document.getElementById('unit-convert');
            convertBtn.disabled = true;
            convertBtn.title = 'Select a product first';
            convertBtn.innerHTML = '<i class="fas fa-exchange-alt me-1"></i>';

            const addBtn = document.getElementById('product-add-button');
            addBtn.disabled = true;
            addBtn.title = 'Select a product first';

            CURRENT_PRODUCT = null;
            CURRENT_UNIT_IS_SECONDARY = false;

            // Focus on barcode input for next scan
            setTimeout(() => {
                document.getElementById('barcode-input').focus();
            }, 100);

        } catch (error) {
            console.error('POS System: Error clearing product selection:', error);
            // Show a more informative error
            showToast('Error clearing product form. Please try again.', 'warning');
        }
    }

    // ==================== UPDATE THE EVENT LISTENER SETUP ====================
    function setupEventListeners() {
        console.log('POS System: Setting up event listeners...');

        try {
            // Product selection - USE A DIFFERENT APPROACH TO AVOID RECURSION
            $('#search-product').on('change.select2', function (e) {
                // Use a flag to prevent recursion
                if (window.isHandlingProductChange) return;
                window.isHandlingProductChange = true;

                try {
                    handleProductSelection.call(this);
                } finally {
                    setTimeout(() => {
                        window.isHandlingProductChange = false;
                    }, 100);
                }
            });

            // Product search input
            document.getElementById('search-product').addEventListener('input', function (e) {
                const searchTerm = e.target.value;
                if (searchTerm.length >= 2) {
                    populateProductDropdownFromSearch(searchTerm);
                } else {
                    populateProductDropdownFromSearch();
                }
            });

            document.getElementById('barcode-input').addEventListener('keydown', handleBarcodeScan);
            document.getElementById('product-add-button').addEventListener('click', addProductToCart);
            document.getElementById('unit-convert').addEventListener('click', toggleUnitConversion);

            // Customer selection
            $('#customer-contact').on('change', handleCustomerSelection);

            // Price type change
            document.getElementById('price-type').addEventListener('change', function () {
                GLOBAL_PRICE_TYPE = this.value;
                if (CURRENT_PRODUCT) {
                    updateProductForm(CURRENT_PRODUCT);
                }
                updateCartPriceTypes();
                populateProductDropdownFromSearch();
            });
            // Add this to setupEventListeners() function:
            document.getElementById('search-product').addEventListener('keydown', function (e) {
                if (e.key === 'Enter' && this.value) {
                    e.preventDefault();
                    const productId = this.value;
                    if (productId) {
                        const product = findProductById(productId);
                        if (product) {
                            updateProductForm(product);
                            // Auto-add to cart after 100ms
                            setTimeout(() => {
                                addProductToCart();
                            }, 100);
                        }
                    }
                }
            });
            // Add keyboard shortcuts
            document.addEventListener('keydown', function (e) {
                // Ctrl+Enter to add product
                if (e.ctrlKey && e.key === 'Enter') {
                    e.preventDefault();
                    if (CURRENT_PRODUCT) {
                        addProductToCart();
                    }
                }

                // F1 for help
                if (e.key === 'F1') {
                    e.preventDefault();
                    showToast('Keyboard Shortcuts: Enter = Add, Esc = Invoices, Ctrl+Enter = Add Product', 'info');
                }
            });

            // Add keyboard shortcuts
document.addEventListener('keydown', function(e) {
    // Ctrl+Enter to add product
    if (e.ctrlKey && e.key === 'Enter') {
        e.preventDefault();
        if (CURRENT_PRODUCT) {
            addProductToCart();
        }
    }
    
    // F1 for help
    if (e.key === 'F1') {
        e.preventDefault();
        showInfoModal('Keyboard Shortcuts', 
            'Ctrl+Enter: Add product quickly, ' +
            'Esc: Go to invoices page, ' +
            'F1: Show this help'
        );
    }
    
    // Escape key handler to go to invoices.php - NO ALERT
    if (e.key === 'Escape') {
        e.preventDefault();
        window.location.href = 'invoices.php';
    }
});
            // Invoice type change
            document.getElementById('invoice-type').addEventListener('change', async function () {
                GST_TYPE = this.value;
                await checkAndGenerateInvoiceNumber();
                updateBillingSummary();
            });

            // Referral selection
            $('#referral').on('change', function () {
                SELECTED_REFERRAL_ID = this.value ? parseInt(this.value) : null;
                updateBillingSummary();
            });

            // Product discount input
            document.getElementById('discount').addEventListener('input', updateProductPriceDisplay);
            document.getElementById('overall-discount-type').addEventListener('change', updateBillingSummary);
            // In setupEventListeners() function, add this line:
            document.getElementById('btnQuotationList').addEventListener('click', loadQuotationList);
            // Action buttons
            document.getElementById('btnHoldList').addEventListener('click', loadHoldList);
            document.getElementById('btnHold').addEventListener('click', holdInvoice);
            document.getElementById('btnQuotation').addEventListener('click', showQuotationModal);
            document.getElementById('btnClearCart').addEventListener('click', clearCart);

            document.getElementById('btnShowPointsDetails').addEventListener('click', showPointsModal);
            document.getElementById('btnGenerateBill').addEventListener('click', generateBill);
            document.getElementById('btnPrintBill').addEventListener('click', printBill);
            document.getElementById('btnAutoFillRemaining').addEventListener('click', autoFillRemainingAmount);

            // Payment methods
            document.querySelectorAll('input[name="payment-method"]').forEach(checkbox => {
                checkbox.addEventListener('change', handlePaymentMethodCheckbox);
            });

            // Payment amount inputs
            document.getElementById('cash-amount').addEventListener('input', updatePaymentSummary);
            document.getElementById('upi-amount').addEventListener('input', updatePaymentSummary);
            document.getElementById('bank-amount').addEventListener('input', updatePaymentSummary);
            document.getElementById('cheque-amount').addEventListener('input', updatePaymentSummary);
            document.getElementById('credit-amount').addEventListener('input', updatePaymentSummary);

            // Discount inputs
            document.getElementById('additional-dis').addEventListener('input', updateBillingSummary);
            document.getElementById('discount-type').addEventListener('change', updateBillingSummary);

            // Selling price input
            document.getElementById('selling-price').addEventListener('input', function () {
                const discountInput = document.getElementById('discount');
                // Only reset discount if it's currently 0 or if selling price is manually changed
                if (parseFloat(discountInput.value) === 0) {
                    updateProductPriceDisplay();
                }
            });

            // Quantity input
            document.getElementById('qty-input').addEventListener('input', function () {
                if (CURRENT_PRODUCT && CURRENT_UNIT_IS_SECONDARY) {
                    updateSecondaryUnitPrice();
                }
            });

            // Modal buttons
            document.getElementById('confirmHold').addEventListener('click', saveHoldInvoice);
            document.getElementById('saveQuotationBtn').addEventListener('click', saveQuotation);
            document.getElementById('btnUseMaxPoints').addEventListener('click', useMaxPoints);
            document.getElementById('btnApplyPointsDiscount').addEventListener('click', applyPointsDiscount);
            document.getElementById('confirmActionBtn').addEventListener('click', executePendingConfirmation);
            document.getElementById('pointsToRedeem').addEventListener('input', updatePointsDiscountPreview);

            // Add reset event listener
            document.getElementById('btnResetForm').addEventListener('click', resetForm);

            console.log('POS System: Event listeners setup complete');

        } catch (error) {
            console.error('POS System: Error setting up event listeners:', error);

        }
    }

    // ==================== ADD PRODUCT TO CART FUNCTION - FIXED ====================
    function addProductToCart() {
        try {
            if (!CURRENT_PRODUCT) {
                showToast('Please select a product first', 'warning');
                return;
            }

            const product = CURRENT_PRODUCT;

            // Get quantity and validate
            const qtyInput = document.getElementById('qty-input');
            let qty = parseFloat(qtyInput.value) || 1;

            // For secondary units, round to whole number
            if (CURRENT_UNIT_IS_SECONDARY) {
                qty = Math.round(qty);
            }

            if (qty <= 0) {
                showToast('Please enter a valid quantity (greater than 0)', 'warning');
                qtyInput.focus();
                qtyInput.select();
                return;
            }

            if (isNaN(qty)) {
                showToast('Invalid quantity entered. Please enter a number.', 'danger');
                qtyInput.value = '1';
                qtyInput.focus();
                qtyInput.select();
                return;
            }

            // Check stock - convert to primary unit if needed
            const shopStock = product.shop_stock || 0;
            let stockToCheck = shopStock;

            if (CURRENT_UNIT_IS_SECONDARY && product.sec_unit_conversion) {
                // Convert secondary unit quantity to primary unit for stock check
                const qtyInPrimary = qty / product.sec_unit_conversion;
                if (qtyInPrimary > shopStock) {
                    showToast(`Insufficient stock! Available: ${shopStock} ${product.unit_of_measure} (≈${Math.round(shopStock * product.sec_unit_conversion)} ${product.secondary_unit})`, 'warning');
                    qtyInput.value = Math.round(shopStock * product.sec_unit_conversion);
                    qtyInput.focus();
                    qtyInput.select();
                    return;
                }
                stockToCheck = qtyInPrimary;
            } else if (qty > shopStock) {
                showToast(`Insufficient stock! Available: ${shopStock}`, 'warning');
                qtyInput.value = shopStock.toString();
                qtyInput.focus();
                qtyInput.select();
                return;
            }

            // Get values from form
            const mrp = parseFloat(document.getElementById('mrp').value) || 0;
            let sellingPrice = parseFloat(document.getElementById('selling-price').value) || 0;
            const discount = parseFloat(document.getElementById('discount').value) || 0;
            const discountType = document.getElementById('discount-type').value;

            const unit = document.getElementById('qty-unit').textContent;
            const isSecondaryUnit = CURRENT_UNIT_IS_SECONDARY;

            // Round selling price
            sellingPrice = Math.round(sellingPrice);

            // Calculate price with discount
            let finalPrice = sellingPrice;
            let discountValue = 0;

            if (discount > 0) {
                if (discountType === 'percentage') {
                    discountValue = sellingPrice * (discount / 100);
                } else {
                    discountValue = discount;
                }
                finalPrice = sellingPrice - discountValue;
            }

            if (finalPrice < 0) {
                showToast('Discount cannot make price negative', 'warning');
                return;
            }

            // Round final price
            finalPrice = Math.round(finalPrice);

            // Calculate GST rates
            const cgstRate = parseFloat(product.cgst_rate) || 0;
            const sgstRate = parseFloat(product.sgst_rate) || 0;
            const igstRate = parseFloat(product.igst_rate) || 0;
            const totalGSTRate = cgstRate + sgstRate + igstRate;

            // Calculate referral commission
            let referralCommission = 0;
            if (product.referral_enabled == 1 && SELECTED_REFERRAL_ID) {
                const referralType = product.referral_type || 'percentage';
                const referralValue = parseFloat(product.referral_value) || 0;

                if (referralType === 'percentage') {
                    referralCommission = finalPrice * (referralValue / 100);
                } else {
                    referralCommission = referralValue * qty;
                }
            }

            // Generate cart item ID
            const cartItemId = `${product.id}-${unit}-${finalPrice.toFixed(0)}-${discountType}`;

            // Create cart item
            const cartItem = {
                id: cartItemId,
                product_id: product.id,
                name: product.product_name,
                code: product.product_code || product.id.toString(),
                mrp: mrp,
                base_price: sellingPrice,
                price: finalPrice,
                price_type: GLOBAL_PRICE_TYPE,
                quantity: qty,
                unit: unit,

                is_secondary_unit: isSecondaryUnit,
                discount_value: discount,
                discount_type: discountType,
                discount_amount: discountValue,
                shop_stock: shopStock,
                hsn_code: product.hsn_code || '',
                cgst_rate: cgstRate,
                sgst_rate: sgstRate,
                igst_rate: igstRate,
                total_gst_rate: totalGSTRate,
                referral_enabled: product.referral_enabled || 0,
                referral_type: product.referral_type || 'percentage',
                referral_value: parseFloat(product.referral_value) || 0,
                referral_commission: referralCommission,
                secondary_unit: product.secondary_unit || '',
                sec_unit_conversion: parseFloat(product.sec_unit_conversion) || 1,
                stock_price: parseFloat(product.stock_price) || 0,
                retail_price: parseFloat(product.retail_price) || 0,
                wholesale_price: parseFloat(product.wholesale_price) || 0,
                unit_of_measure: product.unit_of_measure || 'PCS',
                added_at: new Date().toISOString(),
                total: finalPrice * qty
            };

            console.log('Creating cart item:', cartItem);

            // Check if item already exists in cart with same product, unit, price, and discount type
            const existingIndex = CART.findIndex(item =>
                item.product_id === cartItem.product_id &&
                item.unit === cartItem.unit &&

                Math.abs(item.price - cartItem.price) < 1 &&
                item.discount_type === cartItem.discount_type
            );

            if (existingIndex >= 0) {
                // Update quantity
                const newQty = CART[existingIndex].quantity + qty;

                // Check stock again
                if (shopStock < newQty) {
                    showToast(`Insufficient stock for additional quantity. Available: ${shopStock - CART[existingIndex].quantity}`, 'warning');
                    return;
                }

                CART[existingIndex].quantity = newQty;
                CART[existingIndex].total = CART[existingIndex].price * newQty;

                showToast(`${product.product_name} quantity updated to ${newQty}`, 'info');

            } else {
                // Add new item
                CART.push(cartItem);
                showToast(`${product.product_name} added to cart`, 'success');
            }

            // Update UI and clear form
            renderCart();
            saveCartToSession();

            // Clear product form WITHOUT triggering change event
            clearProductFormSilently();

            updateBillingSummary();
            updateButtonStates();

        } catch (error) {
            console.error('Error adding product to cart:', error);
            showToast('Error adding product to cart. Please try again.', 'danger');
        }
    }

    // ==================== NEW FUNCTION: CLEAR PRODUCT FORM SILENTLY ====================
    function clearProductFormSilently() {
        try {
            // Clear form values without triggering events
            document.getElementById('mrp').value = '';
            document.getElementById('selling-price').value = '';
            document.getElementById('discount').value = '0';
            document.getElementById('qty-input').value = '1';
            document.getElementById('qty-unit').textContent = 'PCS';


            const convertBtn = document.getElementById('unit-convert');
            convertBtn.disabled = true;
            convertBtn.title = 'Select a product first';
            convertBtn.innerHTML = '<i class="fas fa-exchange-alt me-1"></i>';

            const addBtn = document.getElementById('product-add-button');
            addBtn.disabled = true;
            addBtn.title = 'Select a product first';

            CURRENT_PRODUCT = null;
            CURRENT_UNIT_IS_SECONDARY = false;

            // Focus on barcode input for next scan
            setTimeout(() => {
                document.getElementById('barcode-input').focus();
            }, 100);

        } catch (error) {
            console.error('Error clearing product form silently:', error);
        }
    }

    // ==================== FIX THE CART RENDERING ISSUE ====================
    function loadCartFromSession() {
        try {
            const cartData = sessionStorage.getItem('pos_cart');
            if (cartData) {
                const parsedCart = JSON.parse(cartData);
                if (Array.isArray(parsedCart)) {
                    CART = parsedCart;
                    console.log('Cart loaded from session:', CART.length, 'items');

                    // Force render the cart
                    renderCart();
                    updateBillingSummary();
                    updateButtonStates();

                    // Show toast only if there are items
                    if (CART.length > 0) {
                        showToast(`Loaded ${CART.length} items from previous session`, 'info');
                    }
                }
            }
        } catch (error) {
            console.error('Error loading cart from session:', error);
            // Clear invalid cart data
            sessionStorage.removeItem('pos_cart');
            CART = []; // Reset cart array
        }
    }

    // ==================== IMPROVE RENDER CART FUNCTION ====================
    function renderCart() {
        try {
            const tbody = document.getElementById('cartBody');
            const emptyRow = document.getElementById('emptyCartRow');

            if (!tbody) {
                console.error('Cart table body not found');
                return;
            }

            // Clear the table body
            tbody.innerHTML = '';

            if (CART.length === 0) {
                if (!emptyRow) {
                    // Create empty row if it doesn't exist
                    const newEmptyRow = document.createElement('tr');
                    newEmptyRow.id = 'emptyCartRow';
                    newEmptyRow.innerHTML = '<td colspan="10" class="cart-empty">No items in cart</td>';
                    tbody.appendChild(newEmptyRow);
                } else {
                    tbody.appendChild(emptyRow);
                }
                return;
            }

            // Hide the empty row if it exists
            if (emptyRow) {
                emptyRow.style.display = 'none';
            }

            CART.forEach((item, index) => {
                try {
                    const row = document.createElement('tr');
                    row.id = `cart-row-${index}`;

                    // Calculate item totals with GST included
                    const gstRate = (item.cgst_rate || 0) + (item.sgst_rate || 0) + (item.igst_rate || 0);
                    const priceWithoutGST = gstRate > 0 ? item.price / (1 + (gstRate / 100)) : item.price;
                    const gstAmountPerUnit = item.price - priceWithoutGST;
                    const itemTotal = item.price * item.quantity;
                    const totalGST = gstAmountPerUnit * item.quantity;
                    const totalWithoutGST = priceWithoutGST * item.quantity;

                    // Format quantity display (round for secondary units)
                    let quantityDisplay = item.quantity;
                    let primaryQuantityDisplay = '';

                    if (item.is_secondary_unit && item.sec_unit_conversion && item.sec_unit_conversion > 0) {
                        // For secondary units, show rounded value
                        quantityDisplay = Math.round(item.quantity);
                        const primaryQty = item.quantity * item.sec_unit_conversion;
                        primaryQuantityDisplay = `(${parseFloat(primaryQty).toFixed(2)} ${item.unit_of_measure || 'PCS'})`;
                    }

                    row.innerHTML = `
                    <td>${index + 1}</td>
                    <td class="text-start">
                        <strong>${escapeHtml(item.name)}</strong><br>
                        <small class="text-muted">${escapeHtml(item.code)}</small>
                       
                    </td>
                    <td>
                        <div class="d-flex align-items-center gap-1">
                            <button class="btn btn-sm btn-outline-secondary py-0 px-2" 
                                    onclick="cartItemDecrement(${index})">
                                <i class="fas fa-minus"></i>
                            </button>
                            <input type="number" class="form-control form-control-sm text-center cart-qty-input" 
                                   value="${quantityDisplay}" 
                                   data-index="${index}"
                                   min="${item.is_secondary_unit ? 1 : 1}" 
                                   step="${item.is_secondary_unit ? 1 : 1}"
                                   style="width: 80px;">
                            <button class="btn btn-sm btn-outline-secondary py-0 px-2" 
                                    onclick="cartItemIncrement(${index})">
                                <i class="fas fa-plus"></i>
                            </button>
                        </div>
                        ${primaryQuantityDisplay ? `<small class="text-muted d-block mt-1">${primaryQuantityDisplay}</small>` : ''}
                    </td>
                    <td>${escapeHtml(item.unit)}</td>
                    <td>
                        <select class="form-select form-select-sm cart-price-type-select" data-index="${index}">
                            <option value="retail" ${item.price_type === 'retail' ? 'selected' : ''}>Retail</option>
                            <option value="wholesale" ${item.price_type === 'wholesale' ? 'selected' : ''}>Wholesale</option>
                        </select>
                    </td>
                    <td>
                        <div class="d-flex align-items-center gap-1">
                            <input type="number" class="form-control form-control-sm text-center cart-discount-value" 
                                   value="${item.discount_value || 0}" 
                                   data-index="${index}"
                                   min="0" ${item.discount_type === 'percentage' ? 'max="100"' : ''} step="0.01"
                                   style="width: 70px;">
                            <select class="form-select form-select-sm cart-discount-type" data-index="${index}" style="width: 70px;">
                                <option value="percentage" ${item.discount_type === 'percentage' ? 'selected' : ''}>%</option>
                                <option value="fixed" ${item.discount_type === 'fixed' ? 'selected' : ''}>₹</option>
                            </select>
                        </div>
                    </td>
                    <td class="text-end">
                        ₹${item.price.toFixed(0)}<br>
                        <small class="text-muted">Ex-GST: ₹${Math.round(priceWithoutGST)}</small>
                    </td>
                    <td class="text-end">${gstRate.toFixed(2)}%</td>
                    <td class="text-end">₹${Math.round(itemTotal)}</td>
                    <td>
                        <div class="cart-actions">
                            <button class="btn btn-sm btn-outline-danger" onclick="removeCartItem(${index})" title="Remove item">
                                <i class="fas fa-trash"></i>
                            </button>
                        </div>
                    </td>
                `;

                    tbody.appendChild(row);
                } catch (rowError) {
                    console.warn('Error rendering cart row:', rowError);
                }
            });

            // Add live event listeners
            setTimeout(() => {
                // Quantity inputs - live update
                document.querySelectorAll('.cart-qty-input').forEach(input => {
                    input.addEventListener('input', debounce(function () {
                        const index = parseInt(this.dataset.index);
                        const value = parseFloat(this.value) || 1;
                        liveUpdateCartItemQuantity(index, value);
                    }, 300));

                    input.addEventListener('change', function () {
                        const index = parseInt(this.dataset.index);
                        const value = parseFloat(this.value) || 1;
                        updateCartItemQuantity(index, value);
                    });
                });

                // Price type selects
                document.querySelectorAll('.cart-price-type-select').forEach(select => {
                    select.addEventListener('change', function () {
                        const index = parseInt(this.dataset.index);
                        updateCartItemPriceType(index, this.value);
                    });
                });

                // Discount inputs - live update
                document.querySelectorAll('.cart-discount-value').forEach(input => {
                    input.addEventListener('input', debounce(function () {
                        const index = parseInt(this.dataset.index);
                        const value = parseFloat(this.value) || 0;
                        liveUpdateCartItemDiscount(index, value);
                    }, 300));

                    input.addEventListener('change', function () {
                        const index = parseInt(this.dataset.index);
                        const value = parseFloat(this.value) || 0;
                        updateCartItemDiscount(index, value);
                    });
                });

                // Discount type selects
                document.querySelectorAll('.cart-discount-type').forEach(select => {
                    select.addEventListener('change', function () {
                        const index = parseInt(this.dataset.index);
                        updateCartItemDiscountType(index, this.value);
                    });
                });
            }, 10);

        } catch (error) {
            console.error('Error rendering cart:', error);
            showToast('Error displaying cart. Please refresh page.', 'danger');
        }
    }

    function updateProductPriceDisplay() {
        try {
            console.log('Updating product price display...');

            if (!CURRENT_PRODUCT) {
                console.log('No current product');
                return;
            }

            const sellingPriceInput = document.getElementById('selling-price');
            const discountInput = document.getElementById('discount');
            const discountTypeSelect = document.getElementById('discount-type');

            if (!sellingPriceInput || !discountInput || !discountTypeSelect) {
                console.log('Required elements not found');
                return;
            }

            // Get values
            let sellingPrice = parseFloat(sellingPriceInput.value);
            const discount = parseFloat(discountInput.value) || 0;
            const discountType = discountTypeSelect.value;

            console.log('Input values:', {
                sellingPrice,
                discount,
                discountType
            });

            // Validate selling price
            if (isNaN(sellingPrice)) {
                console.log('Selling price is NaN, resetting...');

                // Get original price based on product
                let basePrice = 0;
                if (GLOBAL_PRICE_TYPE === 'wholesale') {
                    basePrice = parseFloat(CURRENT_PRODUCT.wholesale_price) || parseFloat(CURRENT_PRODUCT.retail_price) || 0;
                } else {
                    basePrice = parseFloat(CURRENT_PRODUCT.retail_price) || 0;
                }

                // Apply conversion if needed
                if (CURRENT_UNIT_IS_SECONDARY && CURRENT_PRODUCT.sec_unit_conversion > 0) {
                    const conversion = parseFloat(CURRENT_PRODUCT.sec_unit_conversion) || 1;
                    const extraCharge = parseFloat(CURRENT_PRODUCT.sec_unit_extra_charge) || 0;
                    const priceType = CURRENT_PRODUCT.sec_unit_price_type || 'fixed';

                    if (priceType === 'percentage') {
                        const extraAmount = basePrice * (extraCharge / 100);
                        sellingPrice = (basePrice + extraAmount) / conversion;
                    } else {
                        sellingPrice = (basePrice + extraCharge) / conversion;
                    }
                    sellingPrice = parseFloat(sellingPrice.toFixed(2));
                } else {
                    sellingPrice = Math.round(basePrice);
                }

                sellingPriceInput.value = sellingPrice;
                console.log('Reset selling price to:', sellingPrice);
            }

            // Calculate discounted price
            let finalPrice = sellingPrice;
            let discountAmount = 0;

            if (discount > 0) {
                if (discountType === 'percentage') {
                    discountAmount = sellingPrice * (discount / 100);
                    finalPrice = sellingPrice - discountAmount;
                } else {
                    discountAmount = discount;
                    finalPrice = sellingPrice - discount;
                }
            }

            // Ensure price doesn't go negative
            if (finalPrice < 0) {
                finalPrice = 0;
            }

            // Format final price
            if (CURRENT_UNIT_IS_SECONDARY) {
                finalPrice = parseFloat(finalPrice.toFixed(2));
            } else {
                finalPrice = Math.round(finalPrice);
            }

            console.log('Calculated final price:', finalPrice);

        } catch (error) {
            console.error('Error updating product price display:', error);
        }
    }

    function toggleUnitConversion() {
        try {
            if (!CURRENT_PRODUCT) {
                showToast('Please select a product first', 'warning');
                return;
            }

            const product = CURRENT_PRODUCT;

            // Debug info
            console.log('Toggling unit conversion for product:', {
                id: product.id,
                name: product.product_name,
                unit_of_measure: product.unit_of_measure,
                secondary_unit: product.secondary_unit,
                sec_unit_conversion: product.sec_unit_conversion,
                retail_price: product.retail_price,
                wholesale_price: product.wholesale_price
            });

            if (!product.secondary_unit || !product.sec_unit_conversion || product.sec_unit_conversion <= 0) {
                showToast('This product has no valid secondary unit configuration', 'info');
                return;
            }

            const currentUnit = document.getElementById('qty-unit').textContent;
            const qtyInput = document.getElementById('qty-input');
            const sellingPriceInput = document.getElementById('selling-price');
            const discountInput = document.getElementById('discount');

            if (currentUnit === product.unit_of_measure) {
                // Switch FROM primary unit TO secondary unit
                console.log('Switching to secondary unit');
                CURRENT_UNIT_IS_SECONDARY = true;
                document.getElementById('qty-unit').textContent = product.secondary_unit;
                document.getElementById('unit-convert').innerHTML = '<i class="fas fa-undo me-1"></i> ';
                document.getElementById('unit-convert').title = `Convert to ${product.unit_of_measure}`;

                // Get base price
                let basePrice = 0;
                if (GLOBAL_PRICE_TYPE === 'wholesale') {
                    basePrice = parseFloat(product.wholesale_price) || parseFloat(product.retail_price) || 0;
                } else {
                    basePrice = parseFloat(product.retail_price) || 0;
                }

                console.log('Base price for conversion:', basePrice);

                // Convert quantity: primary to secondary
                const currentQty = parseFloat(qtyInput.value) || 1;
                const conversion = parseFloat(product.sec_unit_conversion) || 1;
                const convertedQty = currentQty * conversion;
                qtyInput.value = convertedQty.toFixed(2);

                // Reset discount when switching units
                discountInput.value = '0';

                // Update secondary unit price
                updateSecondaryUnitPrice();

                showToast(`Converted to ${product.secondary_unit} (1 ${product.unit_of_measure} = ${product.sec_unit_conversion} ${product.secondary_unit})`, 'info');

            } else {
                // Switch FROM secondary unit TO primary unit
                console.log('Switching to primary unit');
                CURRENT_UNIT_IS_SECONDARY = false;
                document.getElementById('qty-unit').textContent = product.unit_of_measure;
                document.getElementById('unit-convert').innerHTML = '<i class="fas fa-exchange-alt me-1"></i> ';
                document.getElementById('unit-convert').title = `Convert to ${product.secondary_unit}`;

                // Convert quantity: secondary to primary
                const currentQty = parseFloat(qtyInput.value) || 1;
                const conversion = parseFloat(product.sec_unit_conversion) || 1;
                const convertedQty = currentQty / conversion;
                qtyInput.value = convertedQty.toFixed(3);

                // Reset discount
                discountInput.value = '0';

                // Get original price based on price type
                const originalPrice = GLOBAL_PRICE_TYPE === 'wholesale' ?
                    (parseFloat(product.wholesale_price) || 0) : (parseFloat(product.retail_price) || 0);
                sellingPriceInput.value = Math.round(originalPrice);

                showToast(`Converted to ${product.unit_of_measure}`, 'info');
            }

            // Always update the price display after conversion
            updateProductPriceDisplay();

        } catch (error) {
            console.error('POS System: Error toggling unit conversion:', error);
            showToast('Error converting unit. Please try again.', 'danger');
        }
    }
    function updateSecondaryUnitPrice() {
        try {
            console.log('Updating secondary unit price...', {
                CURRENT_PRODUCT: CURRENT_PRODUCT,
                CURRENT_UNIT_IS_SECONDARY: CURRENT_UNIT_IS_SECONDARY,
                product: CURRENT_PRODUCT ? {
                    id: CURRENT_PRODUCT.id,
                    name: CURRENT_PRODUCT.product_name,
                    sec_unit_price_type: CURRENT_PRODUCT.sec_unit_price_type,
                    sec_unit_extra_charge: CURRENT_PRODUCT.sec_unit_extra_charge,
                    sec_unit_conversion: CURRENT_PRODUCT.sec_unit_conversion
                } : null
            });

            if (!CURRENT_PRODUCT || !CURRENT_UNIT_IS_SECONDARY) {
                console.log('No current product or not secondary unit');
                return;
            }

            const product = CURRENT_PRODUCT;

            // Get base price based on current price type
            let basePrice = 0;
            if (GLOBAL_PRICE_TYPE === 'wholesale') {
                basePrice = parseFloat(product.wholesale_price) || parseFloat(product.retail_price) || 0;
            } else {
                basePrice = parseFloat(product.retail_price) || 0;
            }

            console.log('Base price:', basePrice, 'Price type:', GLOBAL_PRICE_TYPE);

            // Default to product properties if not set
            const secUnitPriceType = product.sec_unit_price_type || 'fixed';
            const secUnitExtraCharge = parseFloat(product.sec_unit_extra_charge) || 0;
            const secUnitConversion = parseFloat(product.sec_unit_conversion) || 1;

            console.log('Secondary unit details:', {
                secUnitPriceType,
                secUnitExtraCharge,
                secUnitConversion
            });

            if (secUnitConversion <= 0) {
                console.error('Invalid conversion factor:', secUnitConversion);
                showToast('Invalid unit conversion factor', 'danger');
                return;
            }

            let sellingPrice = basePrice;

            if (secUnitPriceType === 'percentage') {
                // Percentage extra charge on base price, then divide by conversion
                const extraAmount = basePrice * (secUnitExtraCharge / 100);
                sellingPrice = (basePrice + extraAmount) / secUnitConversion;
            } else {
                // Fixed extra charge, then divide by conversion
                const extraAmount = secUnitExtraCharge || 0;
                sellingPrice = (basePrice + extraAmount) / secUnitConversion;
            }

            console.log('Calculated selling price:', sellingPrice);

            // Format to 2 decimal places
            sellingPrice = parseFloat(sellingPrice.toFixed(2));

            // Update the selling price input
            const sellingPriceInput = document.getElementById('selling-price');
            if (sellingPriceInput) {
                sellingPriceInput.value = sellingPrice;
                console.log('Updated selling price input:', sellingPriceInput.value);
            }

            // Update discount display
            updateProductPriceDisplay();

        } catch (error) {
            console.error('Error updating secondary unit price:', error);
            showToast('Error calculating secondary unit price. Please check product configuration.', 'danger');
        }
    }


    // ==================== UPDATE ADD TO CART FOR SECONDARY UNITS ====================
    // ==================== UPDATE ADD TO CART LOGIC ====================
    function addProductToCart() {
        try {
            if (!CURRENT_PRODUCT) {
                showToast('Please select a product first', 'warning');
                return;
            }

            const product = CURRENT_PRODUCT;

            // Get quantity and validate
            const qtyInput = document.getElementById('qty-input');
            let qty = parseFloat(qtyInput.value) || 1;

            if (qty <= 0) {
                showToast('Please enter a valid quantity (greater than 0)', 'warning');
                qtyInput.focus();
                qtyInput.select();
                return;
            }

            if (isNaN(qty)) {
                showToast('Invalid quantity entered. Please enter a number.', 'danger');
                qtyInput.value = '1';
                qtyInput.focus();
                qtyInput.select();
                return;
            }

            // Get stock information - FIXED: Use the correct stock fields
            // Note: The API returns shop_stock_primary and shop_stock_secondary
            const shopStockPrimary = parseFloat(product.shop_stock_primary) || 0;
            const shopStockSecondary = parseFloat(product.shop_stock_secondary) || 0;
            const secUnitConversion = parseFloat(product.sec_unit_conversion) || 1;

            console.log('Stock check details:', {
                productName: product.product_name,
                shopStockPrimary: shopStockPrimary,
                shopStockSecondary: shopStockSecondary,
                secUnitConversion: secUnitConversion,
                qtyRequested: qty,
                isSecondaryUnit: CURRENT_UNIT_IS_SECONDARY,
                unit: document.getElementById('qty-unit').textContent
            });

            // Stock validation logic
            if (CURRENT_UNIT_IS_SECONDARY && secUnitConversion > 0) {
                // For secondary units, we need to check stock in primary units
                // Convert secondary quantity to primary units
                const qtyInPrimary = qty / secUnitConversion;

                console.log('Secondary unit check:', {
                    qtyInPrimary: qtyInPrimary,
                    shopStockPrimary: shopStockPrimary,
                    comparison: qtyInPrimary > shopStockPrimary
                });

                if (qtyInPrimary > shopStockPrimary) {
                    // Calculate available secondary units
                    const availableSecondary = Math.floor(shopStockPrimary * secUnitConversion);
                    showToast(
                        `Insufficient stock! Available: ${shopStockPrimary} ${product.unit_of_measure} (≈${availableSecondary} ${product.secondary_unit}), Required: ${qty} ${product.secondary_unit}`,
                        'warning'
                    );

                    // Set maximum available quantity
                    qtyInput.value = availableSecondary.toString();
                    qtyInput.focus();
                    qtyInput.select();
                    return;
                }
            } else {
                // For primary units
                const shopStock = shopStockPrimary; // Use primary stock for primary units

                console.log('Primary unit check:', {
                    shopStock: shopStock,
                    qty: qty,
                    comparison: qty > shopStock
                });

                if (qty > shopStock) {
                    showToast(
                        `Insufficient stock! Available: ${shopStock} ${product.unit_of_measure}`,
                        'warning'
                    );
                    qtyInput.value = shopStock.toString();
                    qtyInput.focus();
                    qtyInput.select();
                    return;
                }
            }

            // Get values from form
            const mrp = parseFloat(document.getElementById('mrp').value) || 0;
            let sellingPrice = parseFloat(document.getElementById('selling-price').value) || 0;
            const discount = parseFloat(document.getElementById('discount').value) || 0;
            const discountType = document.getElementById('discount-type').value;

            const unit = document.getElementById('qty-unit').textContent;
            const isSecondaryUnit = CURRENT_UNIT_IS_SECONDARY;

            // For secondary units, round price to 2 decimal places
            if (isSecondaryUnit) {
                sellingPrice = parseFloat(sellingPrice.toFixed(2));
            } else {
                sellingPrice = Math.round(sellingPrice);
            }

            // Calculate price with discount
            let finalPrice = sellingPrice;
            let discountValue = 0;

            if (discount > 0) {
                if (discountType === 'percentage') {
                    discountValue = sellingPrice * (discount / 100);
                } else {
                    discountValue = discount;
                }
                finalPrice = sellingPrice - discountValue;
            }

            if (finalPrice < 0) {
                showToast('Discount cannot make price negative', 'warning');
                return;
            }

            // Round final price appropriately
            if (isSecondaryUnit) {
                finalPrice = parseFloat(finalPrice.toFixed(2));
            } else {
                finalPrice = Math.round(finalPrice);
            }

            // Calculate quantity in primary units for stock tracking
            let quantityForStock = qty;
            let quantityInPrimary = qty;

            if (isSecondaryUnit && secUnitConversion > 0) {
                quantityInPrimary = qty / secUnitConversion;
            }

            // Create cart item ID with all relevant info
            const cartItemId = `${product.id}-${unit}-${finalPrice.toFixed(2)}-${discountType}-${isSecondaryUnit}`;

            // Show product name with category and subcategory
            const productName = product.product_name;
            const categoryName = product.category_name || '';
            const subcategoryName = product.subcategory_name || '';

            let displayName = productName;
            if (categoryName) {
                displayName += ` (${categoryName}`;
                if (subcategoryName) {
                    displayName += ` - ${subcategoryName}`;
                }
                displayName += ')';
            }

            const cartItem = {
                id: cartItemId,
                product_id: product.id,
                name: displayName,
                code: product.product_code || product.id.toString(),
                mrp: mrp,
                base_price: sellingPrice,
                price: finalPrice,
                price_type: GLOBAL_PRICE_TYPE,
                quantity: qty,
                unit: unit,
                is_secondary_unit: isSecondaryUnit,
                discount_value: discount,
                discount_type: discountType,
                discount_amount: discountValue,
                shop_stock: shopStockPrimary, // Store primary stock for reference
                hsn_code: product.hsn_code || '',
                cgst_rate: parseFloat(product.cgst_rate) || 0,
                sgst_rate: parseFloat(product.sgst_rate) || 0,
                igst_rate: parseFloat(product.igst_rate) || 0,
                referral_enabled: product.referral_enabled || 0,
                referral_type: product.referral_type || 'percentage',
                referral_value: parseFloat(product.referral_value) || 0,
                referral_commission: 0,
                secondary_unit: product.secondary_unit || '',
                sec_unit_conversion: secUnitConversion,
                stock_price: parseFloat(product.stock_price) || 0,
                retail_price: parseFloat(product.retail_price) || 0,
                wholesale_price: parseFloat(product.wholesale_price) || 0,
                unit_of_measure: product.unit_of_measure || 'PCS',
                quantity_in_primary: quantityInPrimary, // Store quantity in primary units
                added_at: new Date().toISOString(),
                total: finalPrice * qty,
                // Store original names for reference
                original_product_name: product.product_name,
                category_name: categoryName,
                subcategory_name: subcategoryName
            };

            // Calculate referral commission
            if (cartItem.referral_enabled == 1 && SELECTED_REFERRAL_ID) {
                const referralType = cartItem.referral_type || 'percentage';
                const referralValue = cartItem.referral_value || 0;

                if (referralType === 'percentage') {
                    cartItem.referral_commission = cartItem.total * (referralValue / 100);
                } else {
                    cartItem.referral_commission = referralValue * cartItem.quantity;
                }
            }

            // Check if item already exists in cart
            const existingIndex = CART.findIndex(item =>
                item.product_id === cartItem.product_id &&
                item.unit === cartItem.unit &&
                Math.abs(item.price - cartItem.price) < 0.01 &&
                item.discount_type === cartItem.discount_type &&
                item.is_secondary_unit === cartItem.is_secondary_unit
            );

            if (existingIndex >= 0) {
                // Update quantity - check combined stock
                const newQty = CART[existingIndex].quantity + qty;
                const newQtyInPrimary = CART[existingIndex].quantity_in_primary + quantityInPrimary;

                // Check stock again
                if (newQtyInPrimary > shopStockPrimary) {
                    const availableQty = isSecondaryUnit ?
                        Math.floor((shopStockPrimary - CART[existingIndex].quantity_in_primary) * secUnitConversion) :
                        (shopStockPrimary - CART[existingIndex].quantity_in_primary);

                    showToast(
                        `Insufficient stock for additional quantity. Available: ${Math.floor(availableQty)} ${unit}`,
                        'warning'
                    );
                    return;
                }

                CART[existingIndex].quantity = newQty;
                CART[existingIndex].quantity_in_primary = newQtyInPrimary;
                CART[existingIndex].total = CART[existingIndex].price * newQty;

                showToast(`${product.product_name} quantity updated to ${newQty} ${unit}`, 'info');

            } else {
                // Add new item
                CART.push(cartItem);
                showToast(`${displayName} added to cart`, 'success');
            }

            // Update UI and clear form
            renderCart();
            saveCartToSession();
            clearProductFormSilently();
            updateBillingSummary();
            updateButtonStates();

        } catch (error) {
            console.error('Error adding product to cart:', error);
            showToast('Error adding product to cart. Please try again.', 'danger');
        }
    }
    // ==================== UPDATE CART DISPLAY FOR SECONDARY UNITS ====================
    function renderCart() {
        try {
            const tbody = document.getElementById('cartBody');
            const emptyRow = document.getElementById('emptyCartRow');

            if (!tbody) {
                console.error('Cart table body not found');
                return;
            }

            // Clear the table body
            tbody.innerHTML = '';

            if (CART.length === 0) {
                if (!emptyRow) {
                    const newEmptyRow = document.createElement('tr');
                    newEmptyRow.id = 'emptyCartRow';
                    newEmptyRow.innerHTML = '<td colspan="10" class="cart-empty">No items in cart</td>';
                    tbody.appendChild(newEmptyRow);
                } else {
                    tbody.appendChild(emptyRow);
                }
                return;
            }

            // Hide the empty row if it exists
            if (emptyRow) {
                emptyRow.style.display = 'none';
            }

            CART.forEach((item, index) => {
                try {
                    const row = document.createElement('tr');
                    row.id = `cart-row-${index}`;

                    // Calculate primary unit equivalent
                    let primaryUnitDisplay = '';
                    if (item.is_secondary_unit && item.sec_unit_conversion > 0) {
                        const primaryQty = item.quantity / item.sec_unit_conversion;
                        primaryUnitDisplay = `${primaryQty.toFixed(2)} ${item.unit_of_measure}`;
                    }

                    // Format quantity display
                    let quantityDisplay = item.quantity;
                    if (item.is_secondary_unit) {
                        // Show secondary unit quantity with 2 decimal places
                        quantityDisplay = parseFloat(item.quantity.toFixed(2));
                    }

                    // Calculate item totals
                    const itemTotal = item.price * item.quantity;

                    row.innerHTML = `
                    <td>${index + 1}</td>
                    <td class="text-start">
                        <strong>${escapeHtml(item.name)}</strong><br>
                        <small class="text-muted">${escapeHtml(item.code)}</small>
                       
                        ${primaryUnitDisplay ? `<br><small class="text-muted">${primaryUnitDisplay}</small>` : ''}
                    </td>
                    <td>
                        <div class="d-flex align-items-center gap-1">
                            <button class="btn btn-sm btn-outline-secondary py-0 px-2" 
                                    onclick="cartItemDecrement(${index})">
                                <i class="fas fa-minus"></i>
                            </button>
                            <input type="number" class="form-control form-control-sm text-center cart-qty-input" 
                                   value="${quantityDisplay}" 
                                   data-index="${index}"
                                   min="${item.is_secondary_unit ? 0.01 : 1}" 
                                   step="${item.is_secondary_unit ? 0.01 : 1}"
                                   style="width: 80px;">
                            <button class="btn btn-sm btn-outline-secondary py-0 px-2" 
                                    onclick="cartItemIncrement(${index})">
                                <i class="fas fa-plus"></i>
                            </button>
                        </div>
                    </td>
                    <td>${escapeHtml(item.unit)}</td>
                    <td>
                        <select class="form-select form-select-sm cart-price-type-select" data-index="${index}">
                            <option value="retail" ${item.price_type === 'retail' ? 'selected' : ''}>Retail</option>
                            <option value="wholesale" ${item.price_type === 'wholesale' ? 'selected' : ''}>Wholesale</option>
                        </select>
                    </td>
                    <td>
                        <div class="d-flex align-items-center gap-1">
                            <input type="number" class="form-control form-control-sm text-center cart-discount-value" 
                                   value="${item.discount_value || 0}" 
                                   data-index="${index}"
                                   min="0" ${item.discount_type === 'percentage' ? 'max="100"' : ''} step="0.01"
                                   style="width: 70px;">
                            <select class="form-select form-select-sm cart-discount-type" data-index="${index}" style="width: 70px;">
                                <option value="percentage" ${item.discount_type === 'percentage' ? 'selected' : ''}>%</option>
                                <option value="fixed" ${item.discount_type === 'fixed' ? 'selected' : ''}>₹</option>
                            </select>
                        </div>
                    </td>
                    <td class="text-end">
                        ₹${item.price.toFixed(item.is_secondary_unit ? 2 : 0)}<br>
                        <small class="text-muted">Per ${item.unit}</small>
                    </td>
                    <td class="text-end">${((item.cgst_rate || 0) + (item.sgst_rate || 0) + (item.igst_rate || 0)).toFixed(2)}%</td>
                    <td class="text-end">₹${item.is_secondary_unit ? itemTotal.toFixed(2) : Math.round(itemTotal)}</td>
                    <td>
                        <div class="cart-actions">
                            <button class="btn btn-sm btn-outline-danger" onclick="removeCartItem(${index})" title="Remove item">
                                <i class="fas fa-trash"></i>
                            </button>
                        </div>
                    </td>
                `;

                    tbody.appendChild(row);
                } catch (rowError) {
                    console.warn('Error rendering cart row:', rowError);
                }
            });

            // Add live event listeners
            setTimeout(() => {
                // Quantity inputs
                document.querySelectorAll('.cart-qty-input').forEach(input => {
                    input.addEventListener('input', debounce(function () {
                        const index = parseInt(this.dataset.index);
                        const value = parseFloat(this.value) || (CART[index].is_secondary_unit ? 0.01 : 1);
                        liveUpdateCartItemQuantity(index, value);
                    }, 300));

                    input.addEventListener('change', function () {
                        const index = parseInt(this.dataset.index);
                        const value = parseFloat(this.value) || (CART[index].is_secondary_unit ? 0.01 : 1);
                        updateCartItemQuantity(index, value);
                    });
                });

                // Price type selects
                document.querySelectorAll('.cart-price-type-select').forEach(select => {
                    select.addEventListener('change', function () {
                        const index = parseInt(this.dataset.index);
                        updateCartItemPriceType(index, this.value);
                    });
                });

                // Discount inputs
                document.querySelectorAll('.cart-discount-value').forEach(input => {
                    input.addEventListener('input', debounce(function () {
                        const index = parseInt(this.dataset.index);
                        const value = parseFloat(this.value) || 0;
                        liveUpdateCartItemDiscount(index, value);
                    }, 300));

                    input.addEventListener('change', function () {
                        const index = parseInt(this.dataset.index);
                        const value = parseFloat(this.value) || 0;
                        updateCartItemDiscount(index, value);
                    });
                });

                // Discount type selects
                document.querySelectorAll('.cart-discount-type').forEach(select => {
                    select.addEventListener('change', function () {
                        const index = parseInt(this.dataset.index);
                        updateCartItemDiscountType(index, this.value);
                    });
                });
            }, 10);

        } catch (error) {
            console.error('Error rendering cart:', error);
            showToast('Error displaying cart. Please refresh page.', 'danger');
        }
    }

    // Debounce function for live updates
    function debounce(func, wait) {
        let timeout;
        return function executedFunction(...args) {
            const later = () => {
                clearTimeout(timeout);
                func(...args);
            };
            clearTimeout(timeout);
            timeout = setTimeout(later, wait);
        };
    }

    // Live update functions
    function liveUpdateCartItemQuantity(index, newQty) {
        try {
            if (!CART[index]) return;

            const item = CART[index];
            const product = findProductById(item.product_id);

            // Check stock
            if (product && newQty > product.shop_stock) {
                return;
            }

            // Update display only (not saving to array yet)
            const row = document.querySelector(`#cart-row-${index}`);
            if (row) {
                const totalCell = row.querySelector('td:nth-child(9)');
                if (totalCell) {
                    const gstRate = item.cgst_rate + item.sgst_rate + item.igst_rate;
                    const priceWithoutGST = item.price / (1 + (gstRate / 100));
                    const gstAmountPerUnit = item.price - priceWithoutGST;
                    const itemTotal = item.price * newQty;

                    // Update the displayed total
                    totalCell.innerHTML = `₹${Math.round(itemTotal)}`;

                    // Update price display if needed
                    const priceCell = row.querySelector('td:nth-child(7)');
                    if (priceCell) {
                        priceCell.innerHTML = `₹${item.price.toFixed(0)}<br><small class="text-muted">Ex-GST: ₹${Math.round(priceWithoutGST)}</small>`;
                    }
                }
            }

        } catch (error) {
            console.error('Error in live quantity update:', error);
        }
    }

    function liveUpdateCartItemDiscount(index, discountValue) {
        try {
            if (!CART[index]) return;

            const item = CART[index];
            const discount = parseFloat(discountValue) || 0;

            // Get the discount type
            const discountTypeElement = document.querySelector(`.cart-discount-type[data-index="${index}"]`);
            const discountType = discountTypeElement ? discountTypeElement.value : 'percentage';

            // Validate discount
            if (discountType === 'percentage' && discount > 100) {
                return;
            }

            // Calculate new price
            const product = findProductById(item.product_id);
            if (!product) return;

            let basePrice = item.price_type === 'wholesale' ?
                parseFloat(product.wholesale_price) : parseFloat(product.retail_price);

            let finalPrice = basePrice;

            if (discountType === 'percentage' && discount > 0) {
                finalPrice = basePrice * (1 - (discount / 100));
            } else if (discountType === 'fixed' && discount > 0) {
                finalPrice = basePrice - discount;
            }

            if (finalPrice < 0) finalPrice = 0;
            finalPrice = Math.round(finalPrice);

            // Update display
            const row = document.querySelector(`#cart-row-${index}`);
            if (row) {
                const priceCell = row.querySelector('td:nth-child(7)');
                const totalCell = row.querySelector('td:nth-child(9)');

                if (priceCell) {
                    const gstRate = item.cgst_rate + item.sgst_rate + item.igst_rate;
                    const priceWithoutGST = finalPrice / (1 + (gstRate / 100));
                    priceCell.innerHTML = `₹${finalPrice}<br><small class="text-muted">Ex-GST: ₹${Math.round(priceWithoutGST)}</small>`;
                }

                if (totalCell) {
                    const total = finalPrice * item.quantity;
                    totalCell.textContent = `₹${Math.round(total)}`;
                }
            }

        } catch (error) {
            console.error('Error in live discount update:', error);
        }
    }

    // ==================== CART ITEM FUNCTIONS ====================
    function cartItemDecrement(index) {
        const item = CART[index];
        if (!item) return;

        const decrementValue = item.is_secondary_unit ? 1 : 1;
        const newQty = Math.max(item.is_secondary_unit ? 1 : 1, item.quantity - decrementValue);
        updateCartItemQuantity(index, newQty);
    }

    function cartItemIncrement(index) {
        const item = CART[index];
        if (!item) return;

        const incrementValue = item.is_secondary_unit ? 1 : 1;
        const newQty = item.quantity + incrementValue;
        updateCartItemQuantity(index, newQty);
    }

    // ==================== UPDATE CART QUANTITY FUNCTIONS FOR SECONDARY UNITS ====================
    function updateCartItemQuantity(index, newQty) {
        try {
            if (!CART[index]) {
                console.error('Cart item not found at index:', index);
                return;
            }

            const item = CART[index];
            const product = findProductById(item.product_id);

            if (!product) {
                console.error('Product not found for cart item');
                return;
            }

            // Validate quantity
            if (isNaN(newQty) || newQty <= 0) {
                if (item.is_secondary_unit) {
                    newQty = 1; // Minimum 1 for secondary units
                } else {
                    // Remove item if quantity is 0 or invalid for primary unit
                    removeCartItem(index);
                    return;
                }
            }

            // Get stock information
            const shopStockPrimary = parseFloat(product.shop_stock_primary) || 0;
            const secUnitConversion = parseFloat(item.sec_unit_conversion) || 1;

            // Stock validation
            if (item.is_secondary_unit && secUnitConversion > 0) {
                // Convert secondary quantity to primary units
                const newQtyInPrimary = newQty / secUnitConversion;

                // Check against available stock (including other cart items)
                let totalQtyInCart = newQtyInPrimary;

                // Sum quantities of same product in cart (excluding current index)
                CART.forEach((cartItem, idx) => {
                    if (idx !== index && cartItem.product_id === item.product_id) {
                        totalQtyInCart += cartItem.quantity_in_primary;
                    }
                });

                if (totalQtyInCart > shopStockPrimary) {
                    // Calculate available secondary units
                    let availableForThisItem = shopStockPrimary;

                    // Subtract quantities of other cart items
                    CART.forEach((cartItem, idx) => {
                        if (idx !== index && cartItem.product_id === item.product_id) {
                            availableForThisItem -= cartItem.quantity_in_primary;
                        }
                    });

                    const availableSecondary = Math.floor(availableForThisItem * secUnitConversion);
                    showToast(
                        `Insufficient stock. Available: ${shopStockPrimary} ${product.unit_of_measure} (≈${availableSecondary} ${item.secondary_unit})`,
                        'warning'
                    );
                    return;
                }

                // Update quantity
                CART[index].quantity = Math.floor(newQty); // Round down for secondary units
                CART[index].quantity_in_primary = newQtyInPrimary;

            } else {
                // For primary units - check total quantity in cart
                let totalQtyInCart = newQty;

                // Sum quantities of same product in cart (excluding current index)
                CART.forEach((cartItem, idx) => {
                    if (idx !== index && cartItem.product_id === item.product_id) {
                        totalQtyInCart += cartItem.quantity;
                    }
                });

                if (totalQtyInCart > shopStockPrimary) {
                    // Calculate available for this item
                    let availableForThisItem = shopStockPrimary;

                    // Subtract quantities of other cart items
                    CART.forEach((cartItem, idx) => {
                        if (idx !== index && cartItem.product_id === item.product_id) {
                            availableForThisItem -= cartItem.quantity;
                        }
                    });

                    showToast(
                        `Insufficient stock. Available: ${shopStockPrimary} ${product.unit_of_measure}`,
                        'warning'
                    );
                    return;
                }

                // Update quantity
                CART[index].quantity = newQty;
                CART[index].quantity_in_primary = newQty;
            }

            CART[index].total = CART[index].price * CART[index].quantity;

            // Update UI and save to session
            renderCart();
            saveCartToSession();
            updateBillingSummary();

        } catch (error) {
            console.error('Error updating cart item quantity:', error);
            showToast('Error updating quantity. Please try again.', 'danger');
        }
    }
    function updateCartItemPriceType(index, priceType) {
        try {
            if (!CART[index]) {
                console.error('Cart item not found at index:', index);
                return;
            }

            const item = CART[index];
            const product = findProductById(item.product_id);

            if (!product) {
                console.error('Product not found for cart item');
                return;
            }

            // Get base price based on new price type
            let basePrice = 0;
            if (priceType === 'wholesale') {
                basePrice = parseFloat(product.wholesale_price) || parseFloat(product.retail_price) || 0;
            } else {
                basePrice = parseFloat(product.retail_price) || 0;
            }

            // Apply existing discount to new base price
            let finalPrice = basePrice;
            if (item.discount_type === 'percentage' && item.discount_value > 0) {
                const discountAmount = basePrice * (item.discount_value / 100);
                finalPrice = basePrice - discountAmount;
            } else if (item.discount_type === 'fixed' && item.discount_value > 0) {
                finalPrice = basePrice - item.discount_value;
            }

            if (finalPrice < 0) {
                finalPrice = 0;
            }

            finalPrice = Math.round(finalPrice);

            // Update the item
            CART[index].price_type = priceType;
            CART[index].base_price = basePrice;
            CART[index].price = finalPrice;
            CART[index].total = finalPrice * item.quantity;

            // Re-render cart and update totals
            renderCart();
            saveCartToSession();
            updateBillingSummary();

            showToast(`${item.name} price updated to ${priceType} pricing`, 'info');

        } catch (error) {
            console.error('Error updating cart item price type:', error);
            showToast('Error updating price type. Please try again.', 'danger');
        }
    }

    function updateCartItemDiscount(index, discountValue) {
        try {
            if (!CART[index]) {
                console.error('Cart item not found at index:', index);
                return;
            }

            const discount = parseFloat(discountValue) || 0;
            const item = CART[index];

            // Get the discount type
            const discountTypeElement = document.querySelector(`.cart-discount-type[data-index="${index}"]`);
            const discountType = discountTypeElement ? discountTypeElement.value : 'percentage';

            // Validate discount based on type
            if (discountType === 'percentage' && (discount < 0 || discount > 100)) {
                showToast('Percentage discount must be between 0 and 100%', 'warning');
                return;
            }

            if (discountType === 'fixed' && discount < 0) {
                showToast('Fixed discount cannot be negative', 'warning');
                return;
            }

            // Update item
            item.discount_value = discount;
            item.discount_type = discountType;

            // Recalculate price
            updateCartItemPrice(index);

            // Save to session and update UI
            saveCartToSession();
            updateBillingSummary();

            showToast(`${item.name} discount updated`, 'info');

        } catch (error) {
            console.error('Error updating cart item discount:', error);
            showToast('Error updating discount. Please try again.', 'danger');
        }
    }

    function updateCartItemDiscountType(index, discountType) {
        try {
            if (!CART[index]) {
                console.error('Cart item not found at index:', index);
                return;
            }

            const item = CART[index];
            item.discount_type = discountType;

            // Recalculate price
            updateCartItemPrice(index);

            // Save to session and update UI
            saveCartToSession();
            updateBillingSummary();

            showToast(`${item.name} discount type updated to ${discountType}`, 'info');

        } catch (error) {
            console.error('Error updating cart item discount type:', error);
            showToast('Error updating discount type. Please try again.', 'danger');
        }
    }

    function updateCartItemPrice(index) {
        try {
            const item = CART[index];
            if (!item) return;

            const product = findProductById(item.product_id);
            if (!product) return;

            // Get base price based on current price type
            let basePrice = 0;
            if (item.price_type === 'wholesale') {
                basePrice = parseFloat(product.wholesale_price) || parseFloat(product.retail_price) || 0;
            } else {
                basePrice = parseFloat(product.retail_price) || 0;
            }

            // Apply discount to base price
            let finalPrice = basePrice;
            let discountAmount = 0;

            if (item.discount_type === 'percentage' && item.discount_value > 0) {
                discountAmount = basePrice * (item.discount_value / 100);
                finalPrice = basePrice - discountAmount;
            } else if (item.discount_type === 'fixed' && item.discount_value > 0) {
                discountAmount = item.discount_value;
                finalPrice = basePrice - discountAmount;
            }

            if (finalPrice < 0) {
                finalPrice = 0;
            }

            finalPrice = Math.round(finalPrice);

            // Update item
            item.base_price = basePrice;
            item.price = finalPrice;
            item.discount_amount = discountAmount;
            item.total = finalPrice * item.quantity;

            // Update the displayed price in the table
            const priceCell = document.querySelector(`#cart-row-${index} td:nth-child(7)`);
            const totalCell = document.querySelector(`#cart-row-${index} td:nth-child(9)`);

            if (priceCell) {
                const gstRate = item.cgst_rate + item.sgst_rate + item.igst_rate;
                const priceWithoutGST = finalPrice / (1 + (gstRate / 100));
                priceCell.innerHTML = `₹${finalPrice}<br><small class="text-muted">Ex-GST: ₹${Math.round(priceWithoutGST)}</small>`;
            }

            if (totalCell) {
                totalCell.textContent = `₹${Math.round(item.total)}`;
            }

        } catch (error) {
            console.error('Error updating cart item price:', error);
        }
    }

    function removeCartItem(index) {
        try {
            if (!CART[index]) {
                console.error('Cart item not found at index:', index);
                return;
            }

            const itemName = CART[index].name;

            showDeleteConfirmation(itemName, function () {
                CART.splice(index, 1);

                renderCart();
                saveCartToSession();
                updateBillingSummary();
                updateButtonStates();

                showSuccessToast(`${itemName} removed from cart`);
            });

        } catch (error) {
            console.error('Error removing cart item:', error);
            showErrorToast('Error removing item. Please try again.');
        }
    }

    function clearCart() {
        if (CART.length === 0) {
            showToast('Cart is already empty', 'info');
            return;
        }

        showClearCartConfirmation(CART.length, function () {
            CART = [];
            renderCart();
            clearCartFromSession();
            updateBillingSummary();
            updateButtonStates();

            showSuccessToast('Cart cleared successfully');
        });
    }

    function updateCartPriceTypes() {
        try {
            CART.forEach((item, index) => {
                const product = findProductById(item.product_id);
                if (product) {
                    let basePrice = GLOBAL_PRICE_TYPE === 'wholesale' ?
                        (product.wholesale_price || product.retail_price || 0) :
                        (product.retail_price || 0);

                    // Apply existing discount to new base price
                    let finalPrice = basePrice;
                    if (item.discount_type === 'percentage' && item.discount_value > 0) {
                        const discountAmount = basePrice * (item.discount_value / 100);
                        finalPrice = basePrice - discountAmount;
                    } else if (item.discount_type === 'fixed' && item.discount_value > 0) {
                        finalPrice = basePrice - item.discount_value;
                    }

                    if (finalPrice < 0) {
                        finalPrice = 0;
                    }

                    finalPrice = Math.round(finalPrice);

                    item.price_type = GLOBAL_PRICE_TYPE;
                    item.base_price = basePrice;
                    item.price = finalPrice;
                    item.total = finalPrice * item.quantity;
                }
            });

            renderCart();
            saveCartToSession();
            updateBillingSummary();
            showToast(`Applied ${GLOBAL_PRICE_TYPE} pricing to all items`, 'success');

        } catch (error) {
            console.error('Error updating cart price types:', error);
            showToast('Error updating prices. Please try again.', 'danger');
        }
    }


    // ==================== CALCULATION FUNCTIONS ====================
    function calculateItemGST(item) {
        try {
            if (GST_TYPE === 'non-gst' || (item.cgst_rate + item.sgst_rate + item.igst_rate) <= 0) {
                return {
                    taxable: item.price * item.quantity,
                    cgst: 0,
                    sgst: 0,
                    igst: 0,
                    total: 0
                };
            }

            const itemTotal = item.price * item.quantity;
            const totalGSTRate = item.cgst_rate + item.sgst_rate + item.igst_rate;

            // GST is included in the price
            // Calculate the taxable value (excluding GST)
            const taxableValue = itemTotal / (1 + (totalGSTRate / 100));
            const gstAmount = itemTotal - taxableValue;

            // Distribute GST among components
            let cgst = 0, sgst = 0, igst = 0;
            if (totalGSTRate > 0) {
                cgst = gstAmount * (item.cgst_rate / totalGSTRate);
                sgst = gstAmount * (item.sgst_rate / totalGSTRate);
                igst = gstAmount * (item.igst_rate / totalGSTRate);
            }

            return {
                taxable: taxableValue,
                cgst: cgst,
                sgst: sgst,
                igst: igst,
                total: gstAmount
            };
        } catch (error) {
            console.error('Error calculating item GST:', error);
            return { taxable: 0, cgst: 0, sgst: 0, igst: 0, total: 0 };
        }
    }

    function calculateItemReferralCommission(item) {
        try {
            if (!item.referral_enabled || !SELECTED_REFERRAL_ID) {
                return 0;
            }

            const itemTotal = item.price * item.quantity;

            if (item.referral_type === 'percentage') {
                return itemTotal * (item.referral_value / 100);
            } else {
                return item.referral_value * item.quantity;
            }
        } catch (error) {
            console.error('Error calculating referral commission:', error);
            return 0;
        }
    }

    function calculateTotals() {
        try {
            let subtotal = 0;
            let totalItemDiscount = 0;
            let totalTaxable = 0;
            let totalCGST = 0;
            let totalSGST = 0;
            let totalIGST = 0;
            let totalReferralCommission = 0;

            CART.forEach(item => {
                const itemTotal = item.price * item.quantity;
                const itemGST = calculateItemGST(item);
                const itemReferralCommission = calculateItemReferralCommission(item);

                subtotal += itemTotal;
                totalTaxable += itemGST.taxable || 0;
                totalCGST += itemGST.cgst;
                totalSGST += itemGST.sgst;
                totalIGST += itemGST.igst;
                totalReferralCommission += itemReferralCommission;

                // Calculate item discount
                const product = findProductById(item.product_id);
                if (product && item.discount_value > 0) {
                    let basePrice = item.price_type === 'wholesale' ?
                        parseFloat(product.wholesale_price) : parseFloat(product.retail_price);

                    if (item.discount_type === 'percentage') {
                        totalItemDiscount += (basePrice * (item.discount_value / 100)) * item.quantity;
                    } else {
                        totalItemDiscount += item.discount_value * item.quantity;
                    }
                }
            });

            const subtotalAfterItems = subtotal - totalItemDiscount;

            // ==== FIX STARTS HERE ====
            // Overall discount - CORRECTED to handle both percentage and rupees
            const overallDiscVal = parseFloat(document.getElementById('additional-dis').value) || 0;
            const overallDiscType = document.getElementById('overall-discount-type').value; // Changed from 'discount-type'
            let overallDiscount = 0;

            if (overallDiscVal > 0) {
                if (overallDiscType === 'percentage') {
                    overallDiscount = subtotalAfterItems * (overallDiscVal / 100);
                } else {
                    // Fixed rupees discount - ensure it doesn't exceed total
                    overallDiscount = Math.min(overallDiscVal, subtotalAfterItems);
                }
            }
            // ==== FIX ENDS HERE ====

            const totalBeforePoints = Math.max(0, subtotalAfterItems - overallDiscount);

            // Loyalty points discount
            const pointsDiscount = LOYALTY_POINTS_DISCOUNT > totalBeforePoints ?
                totalBeforePoints : LOYALTY_POINTS_DISCOUNT;

            // GST total
            const totalGST = GST_TYPE === 'gst' ? (totalCGST + totalSGST + totalIGST) : 0;

            // Grand total
            const grandTotal = Math.max(0, totalBeforePoints - pointsDiscount);

            return {
                subtotal: parseFloat(subtotal.toFixed(2)),
                totalItemDiscount: parseFloat(totalItemDiscount.toFixed(2)),
                overallDiscount: parseFloat(overallDiscount.toFixed(2)),
                pointsDiscount: parseFloat(pointsDiscount.toFixed(2)),
                totalTaxable: parseFloat(totalTaxable.toFixed(2)),
                totalCGST: parseFloat(totalCGST.toFixed(2)),
                totalSGST: parseFloat(totalSGST.toFixed(2)),
                totalIGST: parseFloat(totalIGST.toFixed(2)),
                totalGST: parseFloat(totalGST.toFixed(2)),
                totalReferralCommission: parseFloat(totalReferralCommission.toFixed(2)),
                grandTotal: parseFloat(grandTotal.toFixed(2)),
                subtotalAfterItems: parseFloat(subtotalAfterItems.toFixed(2))
            };

        } catch (error) {
            console.error('Error calculating totals:', error);
            return {
                subtotal: 0,
                totalItemDiscount: 0,
                overallDiscount: 0,
                pointsDiscount: 0,
                totalTaxable: 0,
                totalCGST: 0,
                totalSGST: 0,
                totalIGST: 0,
                totalGST: 0,
                totalReferralCommission: 0,
                grandTotal: 0,
                subtotalAfterItems: 0
            };
        }
    }
    function updateBillingSummary() {
        try {
            const totals = calculateTotals();

            // Update displays (round to whole numbers)
            document.getElementById('subtotal-display').textContent = `₹ ${Math.round(totals.subtotal)}`;
            document.getElementById('item-discount-display').textContent = `₹ ${Math.round(totals.totalItemDiscount)}`;
            document.getElementById('overall-discount-display').textContent = `₹ ${Math.round(totals.overallDiscount)}`;
            document.getElementById('points-discount-display').textContent = `₹ ${Math.round(totals.pointsDiscount)}`;

            // Add this line for taxable value
            document.getElementById('taxable-display').textContent = `₹ ${Math.round(totals.totalTaxable)}`;

            document.getElementById('cgst-display').textContent = `₹ ${Math.round(totals.totalCGST)}`;
            document.getElementById('sgst-display').textContent = `₹ ${Math.round(totals.totalSGST)}`;
            document.getElementById('igst-display').textContent = `₹ ${Math.round(totals.totalIGST)}`;
            document.getElementById('grand-total-display').textContent = `₹ ${Math.round(totals.grandTotal)}`;

            // Show/hide rows
            document.getElementById('item-discount-row').style.display = totals.totalItemDiscount > 0 ? '' : 'none';
            document.getElementById('overall-discount-row').style.display = totals.overallDiscount > 0 ? '' : 'none';
            document.getElementById('points-discount-row').style.display = totals.pointsDiscount > 0 ? '' : 'none';

            // Add this line to show/hide taxable row
            document.getElementById('taxable-row').style.display = GST_TYPE === 'gst' && totals.totalTaxable > 0 ? '' : 'none';

            document.getElementById('cgst-row').style.display = GST_TYPE === 'gst' && totals.totalCGST > 0 ? '' : 'none';
            document.getElementById('sgst-row').style.display = GST_TYPE === 'gst' && totals.totalSGST > 0 ? '' : 'none';
            document.getElementById('igst-row').style.display = GST_TYPE === 'gst' && totals.totalIGST > 0 ? '' : 'none';

            // Update payment summary
            updatePaymentSummary();
            updateButtonStates();

        } catch (error) {
            console.error('Error updating billing summary:', error);
            showToast('Error updating bill summary. Please refresh page.', 'danger');
        }
    }

    // ==================== PAYMENT FUNCTIONS ====================
    function handlePaymentMethodCheckbox(event) {
        try {
            const method = event.target.value;
            const isChecked = event.target.checked;
            const cardId = `${method}-input-card`;
            const cardElement = document.getElementById(cardId);

            if (isChecked) {
                ACTIVE_PAYMENT_METHODS.add(method);
                if (cardElement) {
                    cardElement.classList.add('active');
                    setTimeout(() => {
                        const amountInput = cardElement.querySelector('input[type="number"]');
                        if (amountInput) {
                            amountInput.focus();
                            amountInput.select();
                        }
                    }, 10);
                }
            } else {
                ACTIVE_PAYMENT_METHODS.delete(method);
                if (cardElement) {
                    cardElement.classList.remove('active');
                    const amountInput = cardElement.querySelector('input[type="number"]');
                    if (amountInput) {
                        amountInput.value = '0';
                    }
                }
            }

            updatePaymentSummary();

        } catch (error) {
            console.error('Error handling payment method checkbox:', error);
            showToast('Error updating payment method. Please try again.', 'danger');
        }
    }

    function updatePaymentSummary() {
        try {
            const totals = calculateTotals();
            const grandTotal = totals.grandTotal;

            // Get payment amounts
            const cashAmount = ACTIVE_PAYMENT_METHODS.has('cash') ? parseFloat(document.getElementById('cash-amount').value) || 0 : 0;
            const upiAmount = ACTIVE_PAYMENT_METHODS.has('upi') ? parseFloat(document.getElementById('upi-amount').value) || 0 : 0;
            const bankAmount = ACTIVE_PAYMENT_METHODS.has('bank') ? parseFloat(document.getElementById('bank-amount').value) || 0 : 0;
            const chequeAmount = ACTIVE_PAYMENT_METHODS.has('cheque') ? parseFloat(document.getElementById('cheque-amount').value) || 0 : 0;
            const creditAmount = ACTIVE_PAYMENT_METHODS.has('credit') ? parseFloat(document.getElementById('credit-amount').value) || 0 : 0;

            const totalPaid = cashAmount + upiAmount + bankAmount + chequeAmount + creditAmount;
            const changeGiven = totalPaid > grandTotal ? totalPaid - grandTotal : 0;
            const pendingAmount = totalPaid < grandTotal ? grandTotal - totalPaid : 0;

            // Update displays
            document.getElementById('total-paid').value = `₹ ${Math.round(totalPaid)}`;
            document.getElementById('change-given').value = `₹ ${Math.round(changeGiven)}`;
            document.getElementById('pending-amount').value = `₹ ${Math.round(pendingAmount)}`;

            // Show payment distribution
            showPaymentDistribution({
                cash: cashAmount,
                upi: upiAmount,
                bank: bankAmount,
                cheque: chequeAmount,
                credit: creditAmount,
                totalPaid: totalPaid,
                grandTotal: grandTotal,
                change: changeGiven,
                pending: pendingAmount
            });

            // Update generate bill button state
            updateGenerateBillButton(pendingAmount, totalPaid);

        } catch (error) {
            console.error('Error updating payment summary:', error);
            showToast('Error updating payment summary. Please check amounts.', 'danger');
        }
    }

    function updateGenerateBillButton(pendingAmount, totalPaid) {
        try {
            const generateBillBtn = document.getElementById('btnGenerateBill');
            if (!generateBillBtn) return;

            if (pendingAmount === 0 && totalPaid > 0) {
                generateBillBtn.disabled = false;
                generateBillBtn.title = 'Click to generate and save bill';
                generateBillBtn.classList.remove('btn-secondary');
                generateBillBtn.classList.add('btn-primary');
            } else if (pendingAmount > 0) {
                generateBillBtn.disabled = true;
                generateBillBtn.title = `Cannot generate bill. Pending amount: ₹${pendingAmount.toFixed(2)}`;
                generateBillBtn.classList.remove('btn-primary');
                generateBillBtn.classList.add('btn-secondary');
            } else {
                generateBillBtn.disabled = true;
                generateBillBtn.title = 'Please enter payment amounts';
                generateBillBtn.classList.remove('btn-primary');
                generateBillBtn.classList.add('btn-secondary');
            }
        } catch (error) {
            console.error('Error updating generate bill button:', error);
        }
    }

    function showPaymentDistribution(paymentData) {
        try {
            let distributionHTML = `
            <div class="amount-distribution">
                <h6><i class="fas fa-money-bill-wave me-1"></i> Payment Distribution</h6>
        `;

            // Show active payment methods
            if (paymentData.cash > 0) {
                distributionHTML += `
                <div class="amount-distribution-row">
                    <span><i class="fas fa-money-bill-wave me-1"></i> Cash:</span>
                    <span>₹ ${Math.round(paymentData.cash)}</span>
                </div>
            `;
            }

            if (paymentData.upi > 0) {
                distributionHTML += `
                <div class="amount-distribution-row">
                    <span><i class="fas fa-mobile-alt me-1"></i> UPI:</span>
                    <span>₹ ${Math.round(paymentData.upi)}</span>
                </div>
            `;
            }

            if (paymentData.bank > 0) {
                distributionHTML += `
                <div class="amount-distribution-row">
                    <span><i class="fas fa-university me-1"></i> Bank:</span>
                    <span>₹ ${Math.round(paymentData.bank)}</span>
                </div>
            `;
            }

            if (paymentData.cheque > 0) {
                distributionHTML += `
                <div class="amount-distribution-row">
                    <span><i class="fas fa-money-check me-1"></i> Cheque:</span>
                    <span>₹ ${Math.round(paymentData.cheque)}</span>
                </div>
            `;
            }

            if (paymentData.credit > 0) {
                distributionHTML += `
                <div class="amount-distribution-row">
                    <span><i class="fas fa-credit-card me-1"></i> Credit:</span>
                    <span>₹ ${Math.round(paymentData.credit)}</span>
                </div>
            `;
            }

            distributionHTML += `
                <hr style="margin: 5px 0;">
                <div class="amount-distribution-row" style="font-weight: bold;">
                    <span>Total Paid:</span>
                    <span style="color: #0d6efd;">₹ ${Math.round(paymentData.totalPaid)}</span>
                </div>
                <div class="amount-distribution-row">
                    <span>Bill Amount:</span>
                    <span>₹ ${Math.round(paymentData.grandTotal)}</span>
                </div>
        `;

            if (paymentData.change > 0) {
                distributionHTML += `
                <div class="amount-distribution-row" style="color: #28a745;">
                    <span><i class="fas fa-hand-holding-usd me-1"></i> Change to Give:</span>
                    <span>₹ ${Math.round(paymentData.change)}</span>
                </div>
            `;
            }

            if (paymentData.pending > 0) {
                distributionHTML += `
                <div class="amount-distribution-row" style="color: #fd7e14;">
                    <span><i class="fas fa-exclamation-triangle me-1"></i> Pending Amount:</span>
                    <span>₹ ${Math.round(paymentData.pending)}</span>
                </div>
            `;
            }

            distributionHTML += `</div>`;

            // Update or create distribution display
            let distributionContainer = document.getElementById('paymentDistribution');
            if (!distributionContainer) {
                distributionContainer = document.createElement('div');
                distributionContainer.id = 'paymentDistribution';
                const paymentGrid = document.querySelector('.payment-inputs-grid');
                if (paymentGrid) {
                    paymentGrid.after(distributionContainer);
                }
            }
            distributionContainer.innerHTML = distributionHTML;

        } catch (error) {
            console.error('Error showing payment distribution:', error);
        }
    }

    function autoFillRemainingAmount() {
        try {
            const totals = calculateTotals();
            const grandTotal = totals.grandTotal;

            if (grandTotal === 0) {
                showToast('No bill amount to fill. Add items to cart first.', 'warning');
                return;
            }

            // Get current payment amounts
            const cashAmount = ACTIVE_PAYMENT_METHODS.has('cash') ? parseFloat(document.getElementById('cash-amount').value) || 0 : 0;
            const upiAmount = ACTIVE_PAYMENT_METHODS.has('upi') ? parseFloat(document.getElementById('upi-amount').value) || 0 : 0;
            const bankAmount = ACTIVE_PAYMENT_METHODS.has('bank') ? parseFloat(document.getElementById('bank-amount').value) || 0 : 0;
            const chequeAmount = ACTIVE_PAYMENT_METHODS.has('cheque') ? parseFloat(document.getElementById('cheque-amount').value) || 0 : 0;
            const creditAmount = ACTIVE_PAYMENT_METHODS.has('credit') ? parseFloat(document.getElementById('credit-amount').value) || 0 : 0;

            const totalPaid = cashAmount + upiAmount + bankAmount + chequeAmount + creditAmount;
            const remaining = grandTotal - totalPaid;

            if (remaining <= 0) {
                showToast('Payment already complete or exceeded', 'info');
                return;
            }

            // Find first active payment method with zero amount
            const methods = ['cash', 'upi', 'bank', 'cheque', 'credit'];
            for (const method of methods) {
                if (ACTIVE_PAYMENT_METHODS.has(method)) {
                    const amountInput = document.getElementById(`${method}-amount`);
                    if (parseFloat(amountInput.value) === 0) {
                        amountInput.value = Math.round(remaining);
                        amountInput.dispatchEvent(new Event('input'));
                        showToast(`Auto-filled ₹${Math.round(remaining)} to ${method.toUpperCase()}`, 'info');
                        return;
                    }
                }
            }

            // If all active methods already have amounts, add to the first active method
            const firstMethod = Array.from(ACTIVE_PAYMENT_METHODS)[0];
            if (firstMethod) {
                const amountInput = document.getElementById(`${firstMethod}-amount`);
                const current = parseFloat(amountInput.value) || 0;
                amountInput.value = Math.round(current + remaining);
                amountInput.dispatchEvent(new Event('input'));
                showToast(`Added ₹${Math.round(remaining)} to ${firstMethod.toUpperCase()}`, 'info');
            } else {
                showToast('Please select at least one payment method', 'warning');
            }

        } catch (error) {
            console.error('Error auto-filling remaining amount:', error);
            showToast('Error auto-filling amount. Please enter manually.', 'danger');
        }
    }

    function collectPaymentData() {
        try {
            const cashAmount = ACTIVE_PAYMENT_METHODS.has('cash') ? parseFloat(document.getElementById('cash-amount').value) || 0 : 0;
            const upiAmount = ACTIVE_PAYMENT_METHODS.has('upi') ? parseFloat(document.getElementById('upi-amount').value) || 0 : 0;
            const bankAmount = ACTIVE_PAYMENT_METHODS.has('bank') ? parseFloat(document.getElementById('bank-amount').value) || 0 : 0;
            const chequeAmount = ACTIVE_PAYMENT_METHODS.has('cheque') ? parseFloat(document.getElementById('cheque-amount').value) || 0 : 0;
            const creditAmount = ACTIVE_PAYMENT_METHODS.has('credit') ? parseFloat(document.getElementById('credit-amount').value) || 0 : 0;

            return {
                cash: cashAmount,
                upi: upiAmount,
                bank: bankAmount,
                cheque: chequeAmount,
                credit: creditAmount,
                totalPaid: cashAmount + upiAmount + bankAmount + chequeAmount + creditAmount,
                upi_reference: document.getElementById('upi-reference').value || '',
                bank_reference: document.getElementById('bank-reference').value || '',
                cheque_number: document.getElementById('cheque-number').value || '',
                credit_reference: document.getElementById('credit-reference').value || ''
            };
        } catch (error) {
            console.error('Error collecting payment data:', error);
            return {
                cash: 0,
                upi: 0,
                bank: 0,
                cheque: 0,
                credit: 0,
                totalPaid: 0,
                upi_reference: '',
                bank_reference: '',
                cheque_number: '',
                credit_reference: ''
            };
        }
    }

    // ==================== LOYALTY POINTS FUNCTIONS ====================
    function handleCustomerSelection() {
        try {
            const selectedOption = this.options[this.selectedIndex];
            if (selectedOption && selectedOption.value) {
                // Update customer name
                const customerName = selectedOption.dataset.name;
                if (customerName) {
                    document.getElementById('customer-name').value = customerName;
                }

                // Update other fields
                const address = selectedOption.dataset.address;
                const gstin = selectedOption.dataset.gstin;
                const creditLimit = selectedOption.dataset.creditLimit;
                const outstanding = selectedOption.dataset.outstanding;

                if (address) document.getElementById('customer-address').value = address;
                if (gstin) document.getElementById('customer-gstin').value = gstin;

                // Show credit info if available
                if (creditLimit && creditLimit > 0) {
                    showCustomerCreditInfo(customerName, creditLimit, outstanding);
                }

                // Load loyalty points
                const customerId = selectedOption.dataset.customerId;
                if (customerId) {
                    CURRENT_CUSTOMER_ID = customerId;
                    loadCustomerPoints(customerId);
                } else {
                    hideLoyaltyPoints();
                }
            } else {
                hideCustomerCreditInfo();
                hideLoyaltyPoints();
            }
        } catch (error) {
            console.error('Error handling customer selection:', error);
            hideCustomerCreditInfo();
            hideLoyaltyPoints();
        }
    }

    function showCustomerCreditInfo(name, limit, outstanding) {
        try {
            const available = Math.max(0, limit - outstanding);

            // Create or update credit info display
            let creditInfo = document.getElementById('customer-credit-info');
            if (!creditInfo) {
                creditInfo = document.createElement('div');
                creditInfo.id = 'customer-credit-info';
                creditInfo.className = 'customer-credit-info alert alert-info mt-2';
                const customerSection = document.querySelector('.customer-section');
                if (customerSection) {
                    customerSection.appendChild(creditInfo);
                }
            }

            creditInfo.innerHTML = `
            <div class="d-flex justify-content-between align-items-center">
                <small><strong>Credit Limit:</strong> ₹${Math.round(limit)}</small>
                <small><strong>Outstanding:</strong> ₹${Math.round(outstanding)}</small>
                <small><strong>Available:</strong> <span class="${available < 1000 ? 'text-danger' : 'text-success'}">₹${Math.round(available)}</span></small>
            </div>
        `;

            creditInfo.style.display = 'block';

        } catch (error) {
            console.error('Error showing credit info:', error);
        }
    }

    function hideCustomerCreditInfo() {
        try {
            const creditInfo = document.getElementById('customer-credit-info');
            if (creditInfo) {
                creditInfo.style.display = 'none';
            }
        } catch (error) {
            console.error('Error hiding credit info:', error);
        }
    }

    async function loadCustomers() {
        console.log('POS System: Loading customers...');

        try {
            const response = await fetchWithTimeout('api/customers.php?action=list', {
                timeout: 5000
            });

            if (!response.ok) {
                throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            }

            const data = await response.json();

            if (data.success) {
                CUSTOMERS = data.customers || [];
                populateCustomerDropdown();
                console.log(`POS System: Loaded ${CUSTOMERS.length} customers`);
                return true;
            } else {
                console.warn('POS System: Customer load unsuccessful:', data.message);
                return false;
            }

        } catch (error) {
            console.error('POS System: Customer loading failed:', error);
            throw new Error(`Cannot load customers: ${error.message}`);
        }
    }

    function populateCustomerDropdown() {
        const select = document.getElementById('customer-contact');
        if (!select) return;

        try {
            select.innerHTML = '<option value="">-- Select phone --</option>';

            CUSTOMERS.forEach(customer => {
                if (!customer.phone || !customer.name) return;

                const option = document.createElement('option');
                option.value = customer.phone;
                option.textContent = `${customer.phone} - ${customer.name}`;
                option.dataset.customerId = customer.id || '';
                option.dataset.name = customer.name;
                option.dataset.address = customer.address || '';
                option.dataset.gstin = customer.gstin || '';
                option.dataset.creditLimit = customer.credit_limit || 0;
                option.dataset.outstanding = customer.outstanding_amount || 0;
                select.appendChild(option);
            });

            $('#customer-contact').trigger('change.select2');

        } catch (error) {
            console.error('POS System: Error populating customer dropdown:', error);
        }
    }
    async function loadReferrals() {
        console.log('POS System: Loading referrals...');

        try {
            const response = await fetchWithTimeout('api/referrals.php?action=list', {
                timeout: 5000
            });

            if (!response.ok) {
                return false;
            }

            const data = await response.json();

            if (data.success) {
                REFERRALS = data.referrals || [];
                populateReferralDropdown();
                console.log(`POS System: Loaded ${REFERRALS.length} referrals`);
                return true;
            }
            return false;

        } catch (error) {
            console.warn('POS System: Referral loading failed:', error);
            return false;
        }
    }

    function populateReferralDropdown() {
        const select = document.getElementById('referral');
        if (!select) return;

        try {
            select.innerHTML = '<option value="">-- No referral --</option>';

            REFERRALS.forEach(referral => {
                if (!referral.id || !referral.full_name) return;

                const option = document.createElement('option');
                option.value = referral.id;
                option.textContent = `${referral.full_name} (${referral.referral_code || 'No Code'})`;
                select.appendChild(option);
            });

            $('#referral').trigger('change.select2');

        } catch (error) {
            console.error('POS System: Error populating referral dropdown:', error);
        }
    }

    async function loadLoyaltySettings() {
        console.log('POS System: Loading loyalty settings...');

        try {
            const response = await fetchWithTimeout('api/loyalty.php?action=settings', {
                timeout: 5000
            });

            if (!response.ok) {
                LOYALTY_SETTINGS = getDefaultLoyaltySettings();
                return true;
            }

            const data = await response.json();

            if (data.success) {
                LOYALTY_SETTINGS = data.settings || getDefaultLoyaltySettings();
                console.log('POS System: Loyalty settings loaded');
                return true;
            } else {
                LOYALTY_SETTINGS = getDefaultLoyaltySettings();
                return true;
            }

        } catch (error) {
            console.warn('POS System: Loyalty settings loading failed:', error);
            LOYALTY_SETTINGS = getDefaultLoyaltySettings();
            return true;
        }
    }

    function getDefaultLoyaltySettings() {
        return {
            points_per_amount: 0.01,
            amount_per_point: 100.00,
            redeem_value_per_point: 1.00,
            min_points_to_redeem: 50,
            expiry_months: null,
            is_active: 1
        };
    }

    async function loadCustomerPoints(customerId) {
        try {
            if (!customerId) {
                hideLoyaltyPoints();
                return;
            }

            const response = await fetchWithTimeout(`api/loyalty.php?action=customer_points&customer_id=${customerId}`, {
                timeout: 5000
            });

            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }

            const data = await response.json();

            if (data.success) {
                CUSTOMER_POINTS = data.points || {
                    available_points: 0,
                    total_points_earned: 0,
                    total_points_redeemed: 0
                };

                showLoyaltyPoints();
                updateLoyaltyPointsDisplay();

                // Enable/disable apply points button
                const applyBtn = document.getElementById('btnShowPointsDetails');
                if (CUSTOMER_POINTS.available_points >= LOYALTY_SETTINGS.min_points_to_redeem) {
                    applyBtn.disabled = false;
                    applyBtn.title = 'Apply loyalty points discount';
                } else {
                    applyBtn.disabled = true;
                    applyBtn.title = `Minimum ${LOYALTY_SETTINGS.min_points_to_redeem} points required`;
                }

            } else {
                hideLoyaltyPoints();
            }

        } catch (error) {
            console.warn('Error loading customer points:', error);
            hideLoyaltyPoints();
        }
    }

    function showLoyaltyPoints() {
        try {
            const loyaltySection = document.querySelector('.loyalty-point');
            if (loyaltySection) {
                loyaltySection.style.display = 'flex';
            }
        } catch (error) {
            console.error('Error showing loyalty points:', error);
        }
    }

    function hideLoyaltyPoints() {
        try {
            const loyaltySection = document.querySelector('.loyalty-point');
            if (loyaltySection) {
                loyaltySection.style.display = 'none';
            }

            CUSTOMER_POINTS = { available_points: 0, total_points_earned: 0, total_points_redeemed: 0 };
            LOYALTY_POINTS_DISCOUNT = 0;
            POINTS_USED = 0;
            CURRENT_CUSTOMER_ID = null;

            updateBillingSummary();
        } catch (error) {
            console.error('Error hiding loyalty points:', error);
        }
    }

    function updateLoyaltyPointsDisplay() {
        try {
            document.getElementById('customerPointsDisplay').textContent = CUSTOMER_POINTS.available_points;
        } catch (error) {
            console.error('Error updating loyalty points display:', error);
        }
    }

    function showPointsModal() {
        try {
            if (CUSTOMER_POINTS.available_points < LOYALTY_SETTINGS.min_points_to_redeem) {
                showWarningToast(`Minimum ${LOYALTY_SETTINGS.min_points_to_redeem} points required to redeem`);
                return;
            }

            // Update modal content
            document.getElementById('modalPointsValue').textContent = CUSTOMER_POINTS.available_points;
            document.getElementById('modalTotalEarned').textContent = CUSTOMER_POINTS.total_points_earned;
            document.getElementById('modalTotalRedeemed').textContent = CUSTOMER_POINTS.total_points_redeemed;
            document.getElementById('redeemValuePerPoint').textContent = LOYALTY_SETTINGS.redeem_value_per_point.toFixed(2);

            // Calculate max points that can be used
            const totals = calculateTotals();
            const maxPoints = Math.min(
                CUSTOMER_POINTS.available_points,
                Math.floor(totals.grandTotal / LOYALTY_SETTINGS.redeem_value_per_point)
            );

            // Set input values
            const pointsInput = document.getElementById('pointsToRedeem');
            pointsInput.max = maxPoints;
            pointsInput.value = POINTS_USED;

            updatePointsDiscountPreview();

            // Show modal
            const modal = new bootstrap.Modal(document.getElementById('pointsModal'));
            modal.show();

        } catch (error) {
            console.error('Error showing points modal:', error);
            showErrorToast('Error loading points information. Please try again.');
        }
    }
    function updatePointsDiscountPreview() {
        try {
            let points = parseInt(document.getElementById('pointsToRedeem').value) || 0;
            const maxPoints = parseInt(document.getElementById('pointsToRedeem').max) || 0;

            // Validate points
            if (points < 0) points = 0;
            if (points > maxPoints) {
                points = maxPoints;
                document.getElementById('pointsToRedeem').value = maxPoints;
            }

            const discount = points * LOYALTY_SETTINGS.redeem_value_per_point;
            document.getElementById('modalPointsDiscount').textContent = Math.round(discount);

        } catch (error) {
            console.error('Error updating points discount preview:', error);
        }
    }

    function useMaxPoints() {
        try {
            const maxPoints = parseInt(document.getElementById('pointsToRedeem').max) || 0;
            document.getElementById('pointsToRedeem').value = maxPoints;
            updatePointsDiscountPreview();
        } catch (error) {
            console.error('Error using max points:', error);
        }
    }

    function applyPointsDiscount() {
        try {
            const points = parseInt(document.getElementById('pointsToRedeem').value) || 0;

            if (points < 1) {
                showToast('Please enter points to redeem', 'warning');
                return;
            }

            if (points > CUSTOMER_POINTS.available_points) {
                showToast('Cannot redeem more points than available', 'danger');
                return;
            }

            const discount = points * LOYALTY_SETTINGS.redeem_value_per_point;
            const totals = calculateTotals();

            if (discount > totals.grandTotal) {
                showToast('Discount cannot exceed grand total', 'warning');
                return;
            }

            POINTS_USED = points;
            LOYALTY_POINTS_DISCOUNT = discount;

            // Close modal
            const modal = bootstrap.Modal.getInstance(document.getElementById('pointsModal'));
            if (modal) {
                modal.hide();
            }

            updateBillingSummary();
            showToast(`Applied ${points} points for ₹${Math.round(discount)} discount`, 'success');

        } catch (error) {
            console.error('Error applying points discount:', error);
            showToast('Error applying points discount. Please try again.', 'danger');
        }
    }

    // ==================== INVOICE FUNCTIONS ====================
    function generateInvoiceNumber() {
        try {
            const prefix = GST_TYPE === 'gst' ? 'INV' : 'INVNG';
            const now = new Date();
            const year = now.getFullYear();
            const month = (now.getMonth() + 1).toString().padStart(2, '0');
            const yearMonth = year.toString() + month;

            // Generate invoice number (this will be updated after fetching latest from DB)
            const tempNumber = `${prefix}${yearMonth}-9999`;

            const invoiceInput = document.getElementById('invoice-number');
            if (invoiceInput) {
                invoiceInput.value = tempNumber;
            }

            // Fetch the latest invoice number from database
            fetchLatestInvoiceNumber(prefix, yearMonth);

            return tempNumber;
        } catch (error) {
            console.error('POS System: Error generating invoice number:', error);
            return 'INV-ERROR-' + Date.now();
        }
    }

    async function fetchLatestInvoiceNumber(prefix, yearMonth) {
        try {
            const response = await fetchWithTimeout('api/invoices.php?action=get_next_invoice_number', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json'
                },
                body: JSON.stringify({
                    prefix: prefix,
                    year_month: yearMonth,
                    invoice_type: GST_TYPE
                }),
                timeout: 5000
            });

            if (!response.ok) {
                console.warn('Could not fetch latest invoice number from server');
                return;
            }

            const data = await response.json();

            if (data.success && data.invoice_number) {
                document.getElementById('invoice-number').value = data.invoice_number;
                console.log('Updated invoice number to:', data.invoice_number);
            }
        } catch (error) {
            console.warn('Error fetching latest invoice number:', error);
            // Continue with locally generated number
        }
    }
    let isGeneratingBill = false;
    async function generateBill() {
        try {
            // Prevent double-clicks
            if (isGeneratingBill) {
                console.log('Generate Bill already in progress, skipping...');
                return;
            }
            isGeneratingBill = true;

            console.log('🟢 GENERATE BILL - STARTED ====================');

            // Validate cart
            if (CART.length === 0) {
                console.warn('❌ Generate Bill Failed: Empty cart');
                showWarningToast('Please add items to cart first');
                return;
            }

            // Validate customer
            const customerName = document.getElementById('customer-name').value.trim();
            if (!customerName) {
                console.warn('❌ Generate Bill Failed: Customer name required');
                showWarningModal('Customer Required', 'Customer name is required to generate bill');
                document.getElementById('customer-name').focus();
                document.getElementById('customer-name').select();
                return;
            }

            // Generate fresh invoice number to avoid duplicates
            await checkAndGenerateInvoiceNumber();

            const currentInvoiceNumber = document.getElementById('invoice-number').value;
            console.log('Using invoice number:', currentInvoiceNumber);

            // Check customer credit limit if customer exists
            if (CURRENT_CUSTOMER_ID) {
                console.log('💰 Checking credit limit for customer ID:', CURRENT_CUSTOMER_ID);
                const creditCheck = await checkCustomerCreditLimit();
                if (!creditCheck.allowed) {
                    console.warn('❌ Generate Bill Failed: Credit limit exceeded', creditCheck);
                    showWarningModal('Credit Limit Exceeded', creditCheck.message);
                    return;
                }
                console.log('✅ Credit check passed');
            }

            // Validate stock
            console.log('📊 Stock Validation:');
            for (const item of CART) {
                const product = findProductById(item.product_id);

                if (!product) {
                    console.warn('❌ Generate Bill Failed: Product not found for item', item);
                    showErrorToast(`Product not found for ${item.name}`);
                    return;
                }

                let availableStock = product.shop_stock_primary || product.shop_stock || 0;
                let quantityToCheck = item.quantity_in_primary || item.quantity;

                if (quantityToCheck > availableStock) {
                    console.warn('❌ Generate Bill Failed: Insufficient stock for item', {
                        item_name: item.name,
                        requested_qty: item.quantity,
                        requested_in_primary: quantityToCheck,
                        available_stock: availableStock
                    });

                    let errorMessage = `Insufficient stock for ${item.name}. Available: ${availableStock} ${product.unit_of_measure}`;

                    if (item.is_secondary_unit && product.secondary_unit && product.sec_unit_conversion) {
                        const availableSecondary = Math.floor(availableStock * product.sec_unit_conversion);
                        errorMessage = `Insufficient stock for ${item.name}. Available: ${availableStock} ${product.unit_of_measure} (≈${availableSecondary} ${product.secondary_unit})`;
                    }

                    showWarningModal('Stock Insufficient', errorMessage);
                    return;
                }
            }
            console.log('✅ All items have sufficient stock');

            const totals = calculateTotals();
            const paymentData = collectPaymentData();

            // Validate payment
            if (paymentData.totalPaid === 0) {
                console.warn('❌ Generate Bill Failed: No payment entered');
                showWarningToast('Please enter payment amounts');
                return;
            }

            if (paymentData.totalPaid < totals.grandTotal) {
                const pending = totals.grandTotal - paymentData.totalPaid;
                console.warn('❌ Generate Bill Failed: Insufficient payment', {
                    grand_total: totals.grandTotal,
                    total_paid: paymentData.totalPaid,
                    pending_amount: pending
                });
                showWarningModal('Insufficient Payment', `Pending amount: ₹${Math.round(pending)}`);
                return;
            }

            // Prepare invoice data
            const invoiceData = {
                customer_name: customerName,
                customer_phone: document.getElementById('customer-contact').value || '',
                customer_address: document.getElementById('customer-address').value || '',
                customer_gstin: document.getElementById('customer-gstin').value || '',
                customer_id: CURRENT_CUSTOMER_ID,
                invoice_number: document.getElementById('invoice-number').value,
                invoice_type: GST_TYPE,
                date: document.getElementById('date').value,
                price_type: GLOBAL_PRICE_TYPE,
                referral_id: SELECTED_REFERRAL_ID,
                points_used: POINTS_USED,
                points_discount: totals.pointsDiscount,
                subtotal: totals.subtotal,
                discount: document.getElementById('additional-dis').value,
                discount_type: document.getElementById('overall-discount-type').value,
                overall_discount: totals.overallDiscount,
                total_cgst: totals.totalCGST,
                total_sgst: totals.totalSGST,
                total_igst: totals.totalIGST,
                total_taxable: totals.totalTaxable,
                total_gst: totals.totalGST,
                grand_total: totals.grandTotal,
                referral_commission: totals.totalReferralCommission,
                items: CART.map(item => ({
                    product_id: item.product_id,
                    name: item.name,
                    code: item.code,
                    quantity: item.quantity,
                    unit: item.unit,
                    price: item.price,
                    price_type: item.price_type,
                    discount_value: item.discount_value,
                    discount_type: item.discount_type,
                    total: item.price * item.quantity,
                    hsn_code: item.hsn_code,
                    cgst_rate: item.cgst_rate,
                    sgst_rate: item.sgst_rate,
                    igst_rate: item.igst_rate,
                    taxable_value: calculateItemGST(item).taxable,
                    cgst_amount: calculateItemGST(item).cgst,
                    sgst_amount: calculateItemGST(item).sgst,
                    igst_amount: calculateItemGST(item).igst,
                    stock_price: item.stock_price,
                    referral_enabled: item.referral_enabled,
                    referral_type: item.referral_type,
                    referral_value: item.referral_value,
                    referral_commission: calculateItemReferralCommission(item),
                    is_secondary_unit: item.is_secondary_unit,
                    sec_unit_conversion: item.sec_unit_conversion,
                    quantity_in_primary: item.quantity_in_primary || (item.is_secondary_unit ? (item.quantity / item.sec_unit_conversion) : item.quantity)
                })),
                payment_method: Array.from(ACTIVE_PAYMENT_METHODS).join('+'),
                payment_details: paymentData,
                pending_amount: paymentData.totalPaid < totals.grandTotal ? totals.grandTotal - paymentData.totalPaid : 0
            };

            console.log('📤 Invoice Data to be saved:', JSON.stringify(invoiceData, null, 2));

            // Show loading modal
            showLoading('Saving invoice...');

            try {
                console.log('🌐 Sending invoice data to server...');

                const response = await fetchWithTimeout('api/invoices.php?action=save', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json'
                    },
                    body: JSON.stringify(invoiceData),
                    timeout: 15000
                });

                console.log('📥 Server Response Status:', response.status, response.statusText);

                if (!response.ok) {
                    console.error('❌ Server Response Error:', {
                        status: response.status,
                        statusText: response.statusText
                    });
                    throw new Error(`HTTP ${response.status}: ${response.statusText}`);
                }

                const data = await response.json();
                console.log('📊 Server Response Data:', data);

                if (data.success) {
                    const invoiceId = data.invoice_id || invoiceData.invoice_number;
                    console.log('✅ Invoice Saved Successfully!', {
                        invoice_number: invoiceData.invoice_number,
                        invoice_id: invoiceId,
                        timestamp: new Date().toISOString()
                    });

                    hideLoading();

                    showSuccessModal(
                        'Invoice Saved Successfully!',
                        `Invoice #${invoiceData.invoice_number} has been saved.`,
                        function () {
                            // Clear cart and session after successful save
                            CART = [];
                            clearCartFromSession();

                            // Reset form
                            resetForm();
                        }
                    );

                } else {
                    console.error('❌ Server returned error:', {
                        success: data.success,
                        message: data.message,
                        data: data
                    });
                    hideLoading();
                    throw new Error(data.message || 'Unknown server error');
                }

            } catch (fetchError) {
                console.error('❌ Invoice Save Error:', {
                    error: fetchError,
                    error_message: fetchError.message,
                    stack: fetchError.stack,
                    timestamp: new Date().toISOString()
                });
                hideLoading();
                showErrorModal('Failed to Save Invoice', fetchError.message);
            }

            console.log('🟢 GENERATE BILL - COMPLETED ====================');

        } catch (error) {
            console.error('❌ Generate Bill - Unhandled Error:', {
                error: error,
                error_message: error.message,
                stack: error.stack,
                timestamp: new Date().toISOString()
            });
            hideLoading();
            showErrorModal('Error', `Error generating bill: ${error.message}`);
        } finally {
            setTimeout(() => {
                isGeneratingBill = false;
            }, 1000);
        }
    }

    async function checkCustomerCreditLimit() {
        try {
            if (!CURRENT_CUSTOMER_ID) {
                return { allowed: true, message: '' };
            }

            const response = await fetchWithTimeout(`api/customers.php?action=credit_check&customer_id=${CURRENT_CUSTOMER_ID}`, {
                timeout: 5000
            });

            if (!response.ok) {
                return { allowed: true, message: 'Unable to check credit limit' };
            }

            const data = await response.json();

            if (data.success && data.has_credit_limit) {
                const totals = calculateTotals();
                const pendingAmount = totals.grandTotal - collectPaymentData().totalPaid;

                if (pendingAmount > 0 && data.available_credit < pendingAmount) {
                    return {
                        allowed: false,
                        message: `Credit limit exceeded! Available: ₹${data.available_credit}, Required: ₹${pendingAmount}`
                    };
                }
            }

            return { allowed: true, message: '' };

        } catch (error) {
            console.warn('Credit check error:', error);
            return { allowed: true, message: 'Credit check failed' };
        }
    }

    async function printBill() {
        try {
            if (isGeneratingBill) {
                console.log('Print Bill already in progress, skipping...');
                return;
            }
            isGeneratingBill = true;

            console.log('🟢 PRINT BILL - STARTED ====================');

            if (CART.length === 0) {
                console.warn('❌ Print Bill Failed: Empty cart');
                showWarningToast('Please add items to cart first');
                isGeneratingBill = false;
                return;
            }

            const customerName = document.getElementById('customer-name').value.trim();
            if (!customerName) {
                console.warn('❌ Print Bill Failed: Customer name required');
                showWarningModal('Customer Required', 'Customer name is required to print bill');
                document.getElementById('customer-name').focus();
                document.getElementById('customer-name').select();
                isGeneratingBill = false;
                return;
            }

            await checkAndGenerateInvoiceNumber();
            const currentInvoiceNumber = document.getElementById('invoice-number').value;
            console.log('Using invoice number for print:', currentInvoiceNumber);

            // Show loading modal
            showLoading('Preparing invoice for print...');

            const totals = calculateTotals();
            const paymentData = collectPaymentData();

            const invoiceData = {
                action: 'print',
                customer_name: customerName,
                customer_phone: document.getElementById('customer-contact').value || '',
                customer_address: document.getElementById('customer-address').value || '',
                customer_gstin: document.getElementById('customer-gstin').value || '',
                customer_id: CURRENT_CUSTOMER_ID,
                invoice_number: currentInvoiceNumber,
                invoice_type: GST_TYPE,
                date: document.getElementById('date').value,
                price_type: GLOBAL_PRICE_TYPE,
                referral_id: SELECTED_REFERRAL_ID,
                points_used: POINTS_USED,
                points_discount: totals.pointsDiscount,
                subtotal: totals.subtotal,
                discount: document.getElementById('additional-dis').value,
                discount_type: document.getElementById('overall-discount-type').value,
                overall_discount: totals.overallDiscount,
                total_cgst: totals.totalCGST,
                total_sgst: totals.totalSGST,
                total_igst: totals.totalIGST,
                total_taxable: totals.totalTaxable,
                total_gst: totals.totalGST,
                grand_total: totals.grandTotal,
                referral_commission: totals.totalReferralCommission,
                items: CART.map(item => ({
                    product_id: item.product_id,
                    name: item.name,
                    code: item.code,
                    quantity: item.quantity,
                    unit: item.unit,
                    price: item.price,
                    price_type: item.price_type,
                    discount_value: item.discount_value,
                    discount_type: item.discount_type,
                    total: item.price * item.quantity,
                    hsn_code: item.hsn_code,
                    cgst_rate: item.cgst_rate,
                    sgst_rate: item.sgst_rate,
                    igst_rate: item.igst_rate,
                    taxable_value: calculateItemGST(item).taxable,
                    cgst_amount: calculateItemGST(item).cgst,
                    sgst_amount: calculateItemGST(item).sgst,
                    igst_amount: calculateItemGST(item).igst,
                    stock_price: item.stock_price,
                    referral_enabled: item.referral_enabled,
                    referral_type: item.referral_type,
                    referral_value: item.referral_value,
                    referral_commission: calculateItemReferralCommission(item),
                    is_secondary_unit: item.is_secondary_unit,
                    sec_unit_conversion: item.sec_unit_conversion,
                    quantity_in_primary: item.quantity_in_primary || (item.is_secondary_unit ? (item.quantity / item.sec_unit_conversion) : item.quantity)
                })),
                payment_method: Array.from(ACTIVE_PAYMENT_METHODS).join('+'),
                payment_details: paymentData,
                pending_amount: paymentData.totalPaid < totals.grandTotal ? totals.grandTotal - paymentData.totalPaid : 0
            };

            console.log('📤 Sending invoice data for print...', invoiceData);

            try {
                const response = await fetchWithTimeout('api/invoices.php?action=save_for_print', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json'
                    },
                    body: JSON.stringify(invoiceData),
                    timeout: 15000
                });

                console.log('📥 Print Save Response:', response.status, response.statusText);

                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}: ${response.statusText}`);
                }

                const data = await response.json();
                console.log('📊 Print Save Response Data:', data);

                if (data.success) {
                    const invoiceId = data.invoice_id;
                    console.log('✅ Invoice Saved for Print! Invoice ID:', invoiceId);

                    hideLoading();

                    // Clear cart and session after successful save
                    CART = [];
                    clearCartFromSession();

                    showSuccessModal(
                        'Invoice Saved Successfully!',
                        'Opening print preview...',
                        function () {
                            if (data.print_url) {
                                console.log('Opening print URL:', data.print_url);
                                const printWindow = window.open(data.print_url, '_blank', 'width=900,height=700');
                                if (!printWindow) {
                                    showWarningToast('Popup blocked. Please allow popups for print preview.');
                                } else {
                                    printWindow.focus();
                                }
                            } else {
                                const defaultPrintUrl = `invoice_print.php?invoice_id=${invoiceId}`;
                                const printWindow = window.open(defaultPrintUrl, '_blank', 'width=900,height=700');

                                if (!printWindow) {
                                    showWarningToast('Popup blocked. Please allow popups for print preview.');
                                } else {
                                    printWindow.focus();
                                }
                            }

                            // Reset form after delay
                            setTimeout(() => {
                                resetForm();
                            }, 500);
                        }
                    );

                } else {
                    console.error('❌ Print Save Error from server:', data.message);
                    hideLoading();
                    throw new Error(data.message || 'Unknown server error');
                }

            } catch (fetchError) {
                console.error('❌ Print Bill Save Error:', {
                    error: fetchError,
                    error_message: fetchError.message,
                    stack: fetchError.stack
                });
                hideLoading();
                showErrorModal('Failed to Save Invoice', fetchError.message);
            }

            console.log('🟢 PRINT BILL - COMPLETED ====================');

        } catch (error) {
            console.error('❌ Print Bill - Unhandled Error:', error);
            hideLoading();
            showErrorModal('Error', `Error processing print: ${error.message}`);

            isGeneratingBill = false;
            const printBtn = document.getElementById('btnPrintBill');
            if (printBtn) {
                printBtn.disabled = false;
                printBtn.innerHTML = '<i class="fas fa-print me-1"></i> Print Bill';
            }
        }
    }
    // ==================== FORM RESET ====================
    function resetForm() {
        try {
            console.log('Resetting form...');

            // Clear cart
            CART = [];
            renderCart();

            // Reset customer form
            document.getElementById('customer-name').value = 'Walk-in Customer';
            $('#customer-contact').val('').trigger('change');
            document.getElementById('customer-address').value = '';
            document.getElementById('customer-gstin').value = '';

            // Reset referral
            $('#referral').val('').trigger('change');
            SELECTED_REFERRAL_ID = null;

            // Reset payment checkboxes
            document.querySelectorAll('input[name="payment-method"]').forEach(checkbox => {
                checkbox.checked = checkbox.value === 'cash';
            });

            // Reset payment amounts
            document.getElementById('cash-amount').value = '0';
            document.getElementById('upi-amount').value = '0';
            document.getElementById('bank-amount').value = '0';
            document.getElementById('cheque-amount').value = '0';
            document.getElementById('credit-amount').value = '0';
            document.getElementById('upi-reference').value = '';
            document.getElementById('bank-reference').value = '';
            document.getElementById('cheque-number').value = '';
            document.getElementById('credit-reference').value = '';

            // Hide all payment cards except cash
            document.querySelectorAll('.payment-input-card').forEach(card => {
                card.classList.remove('active');
            });
            document.getElementById('cash-input-card').classList.add('active');

            ACTIVE_PAYMENT_METHODS = new Set(['cash']);

            // Reset discount
            document.getElementById('additional-dis').value = '0';
            document.getElementById('discount-type').value = 'percentage';

            // Reset loyalty points
            hideLoyaltyPoints();

            // Remove payment distribution display
            const distributionContainer = document.getElementById('paymentDistribution');
            if (distributionContainer) {
                distributionContainer.remove();
            }

            // Generate new invoice number
            generateInvoiceNumber();

            // Update billing summary
            updateBillingSummary();

            // Clear product selection
            clearProductSelection();

            // Focus on barcode input
            document.getElementById('barcode-input').focus();

            showToast('Form reset successfully. Ready for next sale!', 'success');

        } catch (error) {
            console.error('Error resetting form:', error);
            showToast('Error resetting form. Please refresh page.', 'danger');
        }
    }

    // ==================== HELPER FUNCTIONS ====================
    function escapeHtml(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    function showToast(message, type = 'info') {
        try {
            const toastContainer = document.getElementById('toastContainer');

            // If we have the container, use Bootstrap toast as fallback
            if (toastContainer) {
                const toastId = 'toast-' + Date.now() + '-' + Math.random().toString(36).substr(2, 9);

                const iconMap = {
                    'success': 'fas fa-check-circle text-success',
                    'info': 'fas fa-info-circle text-info',
                    'warning': 'fas fa-exclamation-triangle text-warning',
                    'danger': 'fas fa-exclamation-circle text-danger'
                };

                const iconClass = iconMap[type] || iconMap['info'];

                const toastHTML = `
                <div id="${toastId}" class="toast custom-toast align-items-center border-0 bg-white shadow-sm mb-2" role="alert" aria-live="assertive" aria-atomic="true">
                    <div class="d-flex">
                        <div class="toast-body d-flex align-items-center">
                            <i class="${iconClass} me-2 fs-5"></i>
                            <span class="flex-grow-1">${escapeHtml(message)}</span>
                            <button type="button" class="btn-close btn-close-sm ms-2" data-bs-dismiss="toast" aria-label="Close"></button>
                        </div>
                    </div>
                </div>
            `;

                toastContainer.insertAdjacentHTML('afterbegin', toastHTML);

                const toastElement = document.getElementById(toastId);
                const toast = new bootstrap.Toast(toastElement, {
                    autohide: true,
                    delay: type === 'danger' ? 5000 : 3000
                });

                toast.show();

                toastElement.addEventListener('hidden.bs.toast', function () {
                    if (toastElement.parentNode) {
                        toastElement.remove();
                    }
                });
            } else {
                // Use SweetAlert2 Toast as primary
                switch (type) {
                    case 'success':
                        showSuccessToast(message);
                        break;
                    case 'danger':
                    case 'error':
                        showErrorToast(message);
                        break;
                    case 'warning':
                        showWarningToast(message);
                        break;
                    default:
                        showInfoToast(message);
                }
            }
        } catch (error) {
            console.error('Error showing toast:', error);
            // Ultimate fallback
            alert(message);
        }
    }


    function showConfirmation(title, message, callback) {
        Swal.fire({
            title: title,
            text: message,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            cancelButtonColor: '#3085d6',
            confirmButtonText: 'Yes, proceed!',
            cancelButtonText: 'Cancel',
            reverseButtons: true,
            focusCancel: true
        }).then((result) => {
            if (result.isConfirmed && callback && typeof callback === 'function') {
                callback();
            }
        });
    }
    function showDeleteConfirmation(itemName, callback) {
        Swal.fire({
            title: 'Delete Item?',
            html: `Are you sure you want to delete <strong>${escapeHtml(itemName)}</strong>?`,
            icon: 'error',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            cancelButtonColor: '#3085d6',
            confirmButtonText: 'Yes, delete it!',
            cancelButtonText: 'Cancel'
        }).then((result) => {
            if (result.isConfirmed && callback && typeof callback === 'function') {
                callback();
            }
        });
    }

    // ==================== CLEAR CART CONFIRMATION ====================
    function showClearCartConfirmation(itemCount, callback) {
        Swal.fire({
            title: 'Clear Cart?',
            html: `Are you sure you want to clear all <strong>${itemCount}</strong> items from the cart?`,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            cancelButtonColor: '#3085d6',
            confirmButtonText: 'Yes, clear cart!',
            cancelButtonText: 'Cancel'
        }).then((result) => {
            if (result.isConfirmed && callback && typeof callback === 'function') {
                callback();
            }
        });
    }
    function executePendingConfirmation() {
        try {
            if (PENDING_CONFIRMATION && typeof PENDING_CONFIRMATION === 'function') {
                PENDING_CONFIRMATION();
            }

            PENDING_CONFIRMATION = null;

            const modal = bootstrap.Modal.getInstance(document.getElementById('confirmationModal'));
            if (modal) {
                modal.hide();
            }

        } catch (error) {
            console.error('Error executing confirmation:', error);
            showToast('Error executing action. Please try again.', 'danger');
        }
    }
    function showSuccessModal(title, message, callback) {
        Swal.fire({
            title: title,
            text: message,
            icon: 'success',
            confirmButtonColor: '#3085d6',
            confirmButtonText: 'OK'
        }).then((result) => {
            if (result.isConfirmed && callback && typeof callback === 'function') {
                callback();
            }
        });
    }

    // ==================== ERROR MODAL ====================
    function showErrorModal(title, message) {
        Swal.fire({
            title: title,
            text: message,
            icon: 'error',
            confirmButtonColor: '#3085d6',
            confirmButtonText: 'OK'
        });
    }

    // ==================== WARNING MODAL ====================
    function showWarningModal(title, message) {
        Swal.fire({
            title: title,
            text: message,
            icon: 'warning',
            confirmButtonColor: '#3085d6',
            confirmButtonText: 'OK'
        });
    }

    // ==================== INFO MODAL ====================
    function showInfoModal(title, message) {
        Swal.fire({
            title: title,
            text: message,
            icon: 'info',
            confirmButtonColor: '#3085d6',
            confirmButtonText: 'OK'
        });
    }

    // ==================== CUSTOM INPUT MODAL ====================
    function showPromptModal(title, inputLabel, inputPlaceholder, callback, defaultValue = '') {
        Swal.fire({
            title: title,
            input: 'text',
            inputLabel: inputLabel,
            inputValue: defaultValue,
            inputPlaceholder: inputPlaceholder,
            showCancelButton: true,
            confirmButtonColor: '#3085d6',
            cancelButtonColor: '#d33',
            confirmButtonText: 'Submit',
            cancelButtonText: 'Cancel',
            inputValidator: (value) => {
                if (!value) {
                    return 'This field is required!';
                }
            }
        }).then((result) => {
            if (result.isConfirmed && callback && typeof callback === 'function') {
                callback(result.value);
            }
        });
    }

    // ==================== LOADING MODAL ====================
    let loadingSwal = null;

    function showLoading(message = 'Processing...') {
        loadingSwal = Swal.fire({
            title: message,
            allowOutsideClick: false,
            allowEscapeKey: false,
            showConfirmButton: false,
            didOpen: () => {
                Swal.showLoading();
            }
        });
    }

    function hideLoading() {
        if (loadingSwal) {
            Swal.close();
            loadingSwal = null;
        }
    }

    function updateButtonStates() {
        try {
            const cartCount = CART.length;
            const hasCartItems = cartCount > 0;
            const totals = calculateTotals();
            const hasGrandTotal = totals.grandTotal > 0;

            // Hold button
            const holdBtn = document.getElementById('btnHold');
            if (holdBtn) {
                holdBtn.disabled = !hasCartItems;
                holdBtn.title = hasCartItems ? 'Hold current invoice' : 'Add items to cart first';
            }

            // Quotation button
            const quotationBtn = document.getElementById('btnQuotation');
            if (quotationBtn) {
                quotationBtn.disabled = !hasCartItems;
                quotationBtn.title = hasCartItems ? 'Save as quotation' : 'Add items to cart first';
            }

            // Clear cart button
            const clearBtn = document.getElementById('btnClearCart');
            if (clearBtn) {
                clearBtn.disabled = !hasCartItems;
                clearBtn.title = hasCartItems ? `Clear ${cartCount} items` : 'Cart is empty';
            }

            // Apply to all button

            // Print bill button
            const printBtn = document.getElementById('btnPrintBill');
            if (printBtn) {
                printBtn.disabled = !hasCartItems;
                printBtn.title = hasCartItems ? 'Print bill preview' : 'Add items to cart first';
            }

            // Auto-fill button
            const autoFillBtn = document.getElementById('btnAutoFillRemaining');
            if (autoFillBtn) {
                autoFillBtn.disabled = !hasGrandTotal;
                autoFillBtn.title = hasGrandTotal ? 'Auto-fill remaining amount' : 'Calculate bill amount first';
            }

        } catch (error) {
            console.error('Error updating button states:', error);
        }
    }

    // ==================== HOLD INVOICE FUNCTIONS ====================
    // ==================== HOLD INVOICE FUNCTIONS ====================
    async function loadHoldList() {
        try {
            console.log('Loading hold list...');

            const response = await fetchWithTimeout('api/holds.php?action=list', {
                timeout: 5000
            });

            if (!response.ok) {
                throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            }

            const data = await response.json();

            if (data.success) {
                const tbody = document.getElementById('holdListBody');
                tbody.innerHTML = '';

                if (data.holds && data.holds.length > 0) {
                    data.holds.forEach((hold, index) => {
                        const cartItems = JSON.parse(hold.cart_items || '[]');
                        const itemCount = cartItems.length;
                        const cartJson = JSON.parse(hold.cart_json || '{}');

                        const row = document.createElement('tr');
                        row.innerHTML = `
                        <td>${index + 1}</td>
                        <td>
                            ${new Date(hold.created_at).toLocaleString()}<br>
                            <small class="text-muted">Expires: ${new Date(hold.expiry_at).toLocaleString()}</small>
                        </td>
                        <td>
                            <strong>${hold.hold_number}</strong><br>
                            <small>${hold.reference || 'No reference'}</small>
                        </td>
                        <td>
                            ${hold.customer_name || 'Walk-in'}<br>
                            <small>${hold.customer_phone || ''}</small>
                        </td>
                        <td>${itemCount} items</td>
                        <td>₹ ${Math.round(parseFloat(hold.total) || 0)}</td>
                        <td>
                            <button class="btn btn-sm btn-success" onclick="retrieveHold(${hold.id})">
                                <i class="fas fa-download me-1"></i> Retrieve
                            </button>
                            <button class="btn btn-sm btn-danger mt-1" onclick="deleteHold(${hold.id})">
                                <i class="fas fa-trash me-1"></i> Delete
                            </button>
                        </td>
                    `;
                        tbody.appendChild(row);
                    });
                } else {
                    tbody.innerHTML = '<tr><td colspan="7" class="text-center">No holds found</td></tr>';
                }

                const modal = new bootstrap.Modal(document.getElementById('holdListModal'));
                modal.show();

                showToast(`Loaded ${data.holds?.length || 0} held invoices`, 'success');

            } else {
                throw new Error(data.message || 'Failed to load holds');
            }

        } catch (error) {
            console.error('Error loading hold list:', error);
            showToast('Error loading holds: ' + error.message, 'danger');
        }
    }

    async function holdInvoice() {
        if (CART.length === 0) {
            showWarningToast('Please add items to cart first');
            return;
        }

        try {
            // Show prompt for reference
            showPromptModal(
                'Hold Invoice',
                'Enter reference note:',
                'e.g., Waiting for customer confirmation',
                async function (reference) {
                    const expiryHours = 48; // Default expiry hours

                    const totals = calculateTotals();

                    // Prepare hold data
                    const holdData = {
                        hold_number: document.getElementById('invoice-number').value.replace('INV', 'HOLD'),
                        reference: reference,
                        customer_name: document.getElementById('customer-name').value,
                        customer_phone: document.getElementById('customer-contact').value || '',
                        customer_gstin: document.getElementById('customer-gstin').value || '',
                        subtotal: totals.subtotal,
                        total: totals.grandTotal,
                        cart_items: CART,
                        cart_json: {
                            customer_name: document.getElementById('customer-name').value,
                            customer_phone: document.getElementById('customer-contact').value,
                            customer_address: document.getElementById('customer-address').value,
                            customer_gstin: document.getElementById('customer-gstin').value,
                            invoice_type: GST_TYPE,
                            price_type: GLOBAL_PRICE_TYPE,
                            referral_id: SELECTED_REFERRAL_ID,
                            discount: document.getElementById('additional-dis').value,
                            discount_type: document.getElementById('overall-discount-type').value
                        }
                    };

                    showLoading('Saving hold...');

                    // Save hold
                    const response = await fetchWithTimeout('api/holds.php?action=save', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json'
                        },
                        body: JSON.stringify(holdData),
                        timeout: 10000
                    });

                    if (!response.ok) {
                        throw new Error(`HTTP ${response.status}: ${response.statusText}`);
                    }

                    const data = await response.json();

                    if (data.success) {
                        // Clear cart
                        CART = [];
                        clearCartFromSession();
                        renderCart();
                        updateBillingSummary();

                        hideLoading();

                        showSuccessModal(
                            'Invoice Held Successfully!',
                            `Hold #: ${holdData.hold_number}<br>Reference: ${reference}<br>Expires in ${expiryHours} hours`,
                            function () {
                                resetForm();
                            }
                        );

                    } else {
                        hideLoading();
                        throw new Error(data.message || 'Failed to save hold');
                    }
                }
            );

        } catch (error) {
            console.error('Error holding invoice:', error);
            hideLoading();
            showErrorModal('Error', `Error holding invoice: ${error.message}`);
        }
    }

    async function saveHoldInvoice() {
        try {
            const reference = document.getElementById('holdReference').value.trim();
            const expiryHours = parseInt(document.getElementById('holdExpiry').value);

            if (!reference) {
                showToast('Please enter a reference note', 'warning');
                return;
            }

            const totals = calculateTotals();

            // Prepare hold data
            const holdData = {
                hold_number: document.getElementById('invoice-number').value.replace('INV', 'HOLD'),
                reference: reference,
                customer_name: document.getElementById('customer-name').value,
                customer_phone: document.getElementById('customer-contact').value || '',
                customer_gstin: document.getElementById('customer-gstin').value || '',
                subtotal: totals.subtotal,
                total: totals.grandTotal,
                cart_items: CART,
                cart_json: {
                    customer_name: document.getElementById('customer-name').value,
                    customer_phone: document.getElementById('customer-contact').value,
                    customer_address: document.getElementById('customer-address').value,
                    customer_gstin: document.getElementById('customer-gstin').value,
                    invoice_type: GST_TYPE,
                    price_type: GLOBAL_PRICE_TYPE,
                    referral_id: SELECTED_REFERRAL_ID,
                    discount: document.getElementById('additional-dis').value,
                    discount_type: document.getElementById('discount-type').value
                }
            };

            // Show loading state
            const confirmBtn = document.getElementById('confirmHold');
            const originalText = confirmBtn.innerHTML;
            confirmBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> Saving...';
            confirmBtn.disabled = true;

            // Save hold
            const response = await fetchWithTimeout('api/holds.php?action=save', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json'
                },
                body: JSON.stringify(holdData),
                timeout: 10000
            });

            if (!response.ok) {
                throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            }

            const data = await response.json();

            if (data.success) {
                // Clear cart
                CART = [];
                clearCartFromSession();
                renderCart();
                updateBillingSummary();

                // Close modal
                const modal = bootstrap.Modal.getInstance(document.getElementById('holdInvoiceModal'));
                if (modal) {
                    modal.hide();
                }

                showToast(`Invoice held successfully! Hold #: ${holdData.hold_number}`, 'success');

                // Reset form after 1 second
                setTimeout(() => {
                    resetForm();
                }, 1000);

            } else {
                throw new Error(data.message || 'Failed to save hold');
            }

        } catch (error) {
            console.error('Error saving hold invoice:', error);
            showToast('Error saving hold: ' + error.message, 'danger');
        } finally {
            const confirmBtn = document.getElementById('confirmHold');
            if (confirmBtn) {
                confirmBtn.innerHTML = 'Save Hold';
                confirmBtn.disabled = false;
            }
        }
    }
    // ==================== PROFIT CALCULATION FUNCTIONS ====================
    function calculateItemProfit(item) {
        try {
            // Use actual selling price WITH GST
            const itemTotalWithGST = item.price * item.quantity; // This is the actual amount customer pays

            // Stock price (cost price) is always without GST
            const stockPrice = parseFloat(item.stock_price) || 0;

            // For profit calculation, we need to compare:
            // 1. What we sold it for (with GST) vs 2. What we bought it for (without GST)
            // This is the actual profit including the GST margin

            // Calculate profit per unit (selling price with GST - cost price)
            const profitPerUnit = item.price - stockPrice;

            // Calculate total profit
            const totalProfit = profitPerUnit * item.quantity;

            // Calculate margin percentage based on cost price
            const marginPercentage = stockPrice > 0 ? (profitPerUnit / stockPrice) * 100 : 0;

            return {
                profitPerUnit: parseFloat(profitPerUnit.toFixed(2)),
                totalProfit: parseFloat(totalProfit.toFixed(2)),
                marginPercentage: parseFloat(marginPercentage.toFixed(2)),
                sellingPriceWithGST: parseFloat(item.price.toFixed(2)),
                stockPrice: stockPrice
            };
        } catch (error) {
            console.error('Error calculating item profit:', error);
            return {
                profitPerUnit: 0,
                totalProfit: 0,
                marginPercentage: 0,
                sellingPriceWithGST: 0,
                stockPrice: 0
            };
        }
    }

    function calculateTotalProfit() {
        try {
            let totalProfit = 0;
            let totalStockValue = 0;
            let totalSellingValueWithGST = 0;

            CART.forEach(item => {
                const profit = calculateItemProfit(item);
                totalProfit += profit.totalProfit;

                const stockPrice = parseFloat(item.stock_price) || 0;
                totalStockValue += stockPrice * item.quantity;

                // Total selling value WITH GST (actual customer payment)
                totalSellingValueWithGST += item.price * item.quantity;
            });

            // Calculate margin based on cost
            const overallMarginPercentage = totalStockValue > 0 ? (totalProfit / totalStockValue) * 100 : 0;

            return {
                totalProfit: parseFloat(totalProfit.toFixed(2)),
                totalStockValue: parseFloat(totalStockValue.toFixed(2)),
                totalSellingValueWithGST: parseFloat(totalSellingValueWithGST.toFixed(2)),
                overallMarginPercentage: parseFloat(overallMarginPercentage.toFixed(2))
            };
        } catch (error) {
            console.error('Error calculating total profit:', error);
            return {
                totalProfit: 0,
                totalStockValue: 0,
                totalSellingValueWithGST: 0,
                overallMarginPercentage: 0
            };
        }
    }

    // ==================== PROFIT MODAL FUNCTIONS ====================
    function showProfitModal() {
        try {
            if (CART.length === 0) {
                showToast('No items in cart to calculate profit', 'warning');
                return;
            }

            // Check if modal exists, if not create it
            let profitModalElement = document.getElementById('profitModal');

            if (!profitModalElement) {
                // Create profit modal HTML
                const profitModalHTML = `
                <div class="modal fade" id="profitModal" tabindex="-1" aria-hidden="true">
                    <div class="modal-dialog modal-lg">
                        <div class="modal-content">
                            <div class="modal-header bg-primary text-white">
                                <h5 class="modal-title">
                                    <i class="fas fa-chart-line me-2"></i> Profit Analysis
                                </h5>
                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <div class="modal-body">
                                <!-- Summary Cards -->
                                <div class="row mb-4">
                                    <div class="col-md-4">
                                        <div class="card bg-success text-white">
                                            <div class="card-body py-2">
                                                <h6 class="card-title"><i class="fas fa-rupee-sign me-1"></i> Total Profit</h6>
                                                <h3 class="mb-0" id="profitTotalAmount">₹ 0</h3>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="card bg-info text-white">
                                            <div class="card-body py-2">
                                                <h6 class="card-title"><i class="fas fa-percentage me-1"></i> Margin</h6>
                                                <h3 class="mb-0" id="profitOverallMargin">0%</h3>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="card bg-warning text-white">
                                            <div class="card-body py-2">
                                                <h6 class="card-title"><i class="fas fa-boxes me-1"></i> Items</h6>
                                                <h3 class="mb-0" id="profitTotalItems">0</h3>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Profit Table -->
                                <div class="table-responsive" style="max-height: 400px; overflow-y: auto;">
                                    <table class="table table-sm table-striped table-hover">
                                        <thead class="sticky-top bg-light">
                                            <tr>
                                                <th>#</th>
                                                <th>Product</th>
                                                <th>Qty</th>
                                                <th>Unit</th>
                                                <th>Stock Price</th>
                                                <th>Selling Price (Ex-GST)</th>
                                                <th>Profit/Unit</th>
                                                <th>Total Profit</th>
                                                <th>Margin %</th>
                                            </tr>
                                        </thead>
                                        <tbody id="profitTableBody">
                                            <!-- Profit items will be inserted here -->
                                        </tbody>
                                    </table>
                                </div>
                                
                                <!-- Detailed Summary -->
                                <div class="mt-4 p-3 bg-light rounded">
                                    <h6 class="mb-3"><i class="fas fa-calculator me-2"></i>Detailed Summary</h6>
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="d-flex justify-content-between mb-2">
                                                <span class="fw-bold">Total Selling Value (Ex-GST):</span>
                                                <span id="profitTotalSellingValue" class="text-primary fw-bold">₹ 0</span>
                                            </div>
                                            <div class="d-flex justify-content-between mb-2">
                                                <span class="fw-bold">Total Stock Value:</span>
                                                <span id="profitTotalStockValue" class="text-danger fw-bold">₹ 0</span>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="d-flex justify-content-between mb-2">
                                                <span class="fw-bold">Gross Profit:</span>
                                                <span id="profitGrossProfit" class="text-success fw-bold">₹ 0</span>
                                            </div>
                                            <div class="d-flex justify-content-between">
                                                <span class="fw-bold">Profit Margin:</span>
                                                <span id="profitMarginPercent" class="text-info fw-bold">0%</span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                                    <i class="fas fa-times me-1"></i> Close
                                </button>
                                <button type="button" class="btn btn-primary" onclick="printProfitReport()">
                                    <i class="fas fa-print me-1"></i> Print Report
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            `;

                // Add modal to body
                document.body.insertAdjacentHTML('beforeend', profitModalHTML);
                profitModalElement = document.getElementById('profitModal');
            }

            // Calculate totals
            const totals = calculateTotalProfit();
            const totalItems = CART.reduce((sum, item) => sum + item.quantity, 0);


            // Update summary cards
            document.getElementById('profitTotalAmount').innerHTML = `₹ ${Math.round(totals.totalProfit)}`;
            document.getElementById('profitOverallMargin').innerHTML = `${totals.overallMarginPercentage}%`;

            // Update detailed summary
            document.getElementById('profitTotalSellingValue').innerHTML = `₹ ${Math.round(totals.totalSellingValueWithGST)} (Inc. GST)`;
            document.getElementById('profitTotalStockValue').innerHTML = `₹ ${Math.round(totals.totalStockValue)}`;
            document.getElementById('profitGrossProfit').innerHTML = `₹ ${Math.round(totals.totalProfit)}`;
            document.getElementById('profitMarginPercent').innerHTML = `${totals.overallMarginPercentage}%`;

            // Populate profit table
            const tbody = document.getElementById('profitTableBody');
            tbody.innerHTML = '';

            CART.forEach((item, index) => {
                const profit = calculateItemProfit(item);

                // Determine margin class
                let marginClass = 'text-success';
                if (profit.marginPercentage < 10) marginClass = 'text-danger';
                else if (profit.marginPercentage < 20) marginClass = 'text-warning';

                const row = document.createElement('tr');
                row.innerHTML = `
    <td>${index + 1}</td>
    <td>
        <strong>${escapeHtml(item.name)}</strong><br>
        <small class="text-muted">${escapeHtml(item.code)}</small>
    </td>
    <td>${item.quantity}</td>
    <td>${escapeHtml(item.unit)}</td>
    <td class="text-end">₹ ${Math.round(profit.stockPrice)}</td>
    <td class="text-end">₹ ${Math.round(profit.sellingPriceWithGST)}</td>
    <td class="text-end ${profit.profitPerUnit >= 0 ? 'text-success' : 'text-danger'}">
        ₹ ${Math.round(profit.profitPerUnit)}
    </td>
    <td class="text-end ${profit.totalProfit >= 0 ? 'text-success' : 'text-danger'} fw-bold">
        ₹ ${Math.round(profit.totalProfit)}
    </td>
    <td class="text-end ${marginClass} fw-bold">
        ${profit.marginPercentage}%
    </td>
`;
                tbody.appendChild(row);
            });

            // Show the modal
            const profitModal = new bootstrap.Modal(profitModalElement);
            profitModal.show();

        } catch (error) {
            console.error('Error showing profit modal:', error);
            showToast('Error calculating profit. Please try again.', 'danger');
        }
    }

    // ==================== PRINT PROFIT REPORT ====================
    function printProfitReport() {
        try {
            // Create a printable version of the profit report
            const printWindow = window.open('', '_blank', 'width=800,height=600');

            if (!printWindow) {
                showToast('Popup blocked. Please allow popups to print.', 'warning');
                return;
            }

            const totals = calculateTotalProfit();
            const date = new Date().toLocaleString();
            const invoiceNumber = document.getElementById('invoice-number').value;
            const customerName = document.getElementById('customer-name').value;

            let itemsHTML = '';
            CART.forEach((item, index) => {
                const profit = calculateItemProfit(item);
                itemsHTML += `
                <tr>
                    <td>${index + 1}</td>
                    <td>${escapeHtml(item.name)}</td>
                    <td>${item.quantity} ${escapeHtml(item.unit)}</td>
                    <td class="text-end">₹ ${Math.round(profit.stockPrice)}</td>
                    <td class="text-end">₹ ${Math.round(profit.sellingPriceExGST)}</td>
                    <td class="text-end">₹ ${Math.round(profit.profitPerUnit)}</td>
                    <td class="text-end">₹ ${Math.round(profit.totalProfit)}</td>
                    <td class="text-end">${profit.marginPercentage}%</td>
                </tr>
            `;
            });

            printWindow.document.write(`
            <!DOCTYPE html>
            <html>
            <head>
                <title>Profit Report - ${invoiceNumber}</title>
                <style>
                    body { font-family: Arial, sans-serif; margin: 20px; }
                    .header { text-align: center; margin-bottom: 30px; }
                    .header h1 { color: #0d6efd; margin-bottom: 5px; }
                    .header h3 { color: #6c757d; margin-top: 0; }
                    .summary { display: flex; justify-content: space-between; margin-bottom: 30px; }
                    .summary-box { 
                        background: #f8f9fa; 
                        border: 1px solid #dee2e6;
                        border-radius: 5px;
                        padding: 15px;
                        width: 30%;
                    }
                    .summary-box h4 { margin-top: 0; margin-bottom: 10px; color: #495057; }
                    .summary-box .amount { font-size: 24px; font-weight: bold; color: #28a745; }
                    table { width: 100%; border-collapse: collapse; margin-top: 20px; }
                    th, td { border: 1px solid #dee2e6; padding: 8px; text-align: left; }
                    th { background-color: #e9ecef; }
                    .text-end { text-align: right; }
                    .text-success { color: #28a745; }
                    .text-danger { color: #dc3545; }
                    .footer { margin-top: 30px; text-align: right; font-size: 12px; color: #6c757d; }
                </style>
            </head>
            <body>
                <div class="header">
                    <h1>Profit Analysis Report</h1>
                    <h3>Invoice #: ${invoiceNumber}</h3>
                    <p>Date: ${date}</p>
                    <p>Customer: ${escapeHtml(customerName)}</p>
                </div>
                
                <div class="summary">
                    <div class="summary-box">
                        <h4>Total Profit</h4>
                        <div class="amount">₹ ${Math.round(totals.totalProfit)}</div>
                    </div>
                    <div class="summary-box">
                        <h4>Profit Margin</h4>
                        <div class="amount">${totals.overallMarginPercentage}%</div>
                    </div>
                    <div class="summary-box">
                        <h4>Total Items</h4>
                        <div class="amount">${CART.reduce((sum, item) => sum + item.quantity, 0)}</div>
                    </div>
                </div>
                
                <h3>Product-wise Profit Details</h3>
                <table>
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Product</th>
                            <th>Quantity</th>
                            <th>Stock Price</th>
                            <th>Selling Price</th>
                            <th>Profit/Unit</th>
                            <th>Total Profit</th>
                            <th>Margin %</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${itemsHTML}
                    </tbody>
                    <tfoot>
                        <tr>
                            <th colspan="7" class="text-end">Total Profit:</th>
                            <th class="text-end text-success">₹ ${Math.round(totals.totalProfit)}</th>
                            <th class="text-end">${totals.overallMarginPercentage}%</th>
                        </tr>
                    </tfoot>
                </table>
                
                <div class="footer">
                    <p>Generated on: ${new Date().toLocaleString()}</p>
                    <p>This is a computer generated report - no signature required</p>
                </div>
            </body>
            </html>
        `);

            printWindow.document.close();
            printWindow.focus();
            printWindow.print();

        } catch (error) {
            console.error('Error printing profit report:', error);
            showToast('Error printing report. Please try again.', 'danger');
        }
    }

    // ==================== ADD PROFIT BUTTON TO FIXED BOTTOM BUTTONS ====================
    // Add this function to modify the fixed bottom buttons
    function addProfitButtonToFixedBottom() {
        try {
            const fixedBottomDiv = document.querySelector('.fixed-bottom-buttons');

            if (fixedBottomDiv) {
                // Check if profit button already exists
                if (!document.getElementById('btnShowProfit')) {
                    const profitButton = document.createElement('button');
                    profitButton.id = 'btnShowProfit';
                    profitButton.className = 'btn btn-warning';
                    profitButton.innerHTML = '<i class="fas fa-chart-line me-1"></i> Profit';
                    profitButton.title = 'View product-wise profit analysis';
                    profitButton.onclick = showProfitModal;

                    // Insert before the first button or at the beginning
                    if (fixedBottomDiv.firstChild) {
                        fixedBottomDiv.insertBefore(profitButton, fixedBottomDiv.firstChild);
                    } else {
                        fixedBottomDiv.appendChild(profitButton);
                    }

                    console.log('Profit button added to fixed bottom section');
                }
            } else {
                console.warn('Fixed bottom buttons container not found');
            }
        } catch (error) {
            console.error('Error adding profit button:', error);
        }
    }

    // ==================== MODIFY EXISTING FUNCTIONS ====================
    // Add profit button initialization to DOMContentLoaded
    // Modify the existing DOMContentLoaded event listener in your script
    // Replace or add to your existing DOMContentLoaded event listener:

    document.addEventListener('DOMContentLoaded', function () {
        console.log('POS System: Initializing with profit feature...');

        // Call existing initialization
        initializeApp();
        setupEventListeners();
        // Add profit button to fixed bottom section
        setTimeout(() => {
            addProfitButtonToFixedBottom();
        }, 100);
    });

    // ==================== UPDATE BUTTON STATES TO INCLUDE PROFIT BUTTON ====================
    // Add to existing updateButtonStates function
    const originalUpdateButtonStates = updateButtonStates;
    updateButtonStates = function () {
        // Call original function
        if (originalUpdateButtonStates) {
            originalUpdateButtonStates();
        }

        try {
            const cartCount = CART.length;
            const profitBtn = document.getElementById('btnShowProfit');

            if (profitBtn) {
                profitBtn.disabled = cartCount === 0;
                profitBtn.title = cartCount > 0 ? 'View profit analysis' : 'Add items to cart first';
            }
        } catch (error) {
            console.error('Error updating profit button state:', error);
        }
    };
    async function retrieveHold(holdId) {
        try {
            console.log('Retrieving hold:', holdId);

            // Get hold details - use the proper API endpoint
            const response = await fetchWithTimeout(`api/holds.php?action=get&hold_id=${holdId}`, {
                timeout: 5000
            });

            if (!response.ok) {
                throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            }

            const data = await response.json();
            console.log('Hold retrieve response:', data);

            if (data.success && data.hold) {
                const hold = data.hold;

                // Parse cart items - handle both string and object formats
                let cartItems = [];
                try {
                    if (typeof hold.cart_items === 'string') {
                        cartItems = JSON.parse(hold.cart_items);
                    } else if (Array.isArray(hold.cart_items)) {
                        cartItems = hold.cart_items;
                    } else {
                        console.error('Invalid cart_items format:', hold.cart_items);
                        throw new Error('Invalid cart data format');
                    }
                } catch (parseError) {
                    console.error('Error parsing cart items:', parseError);
                    showToast('Error parsing cart data. The hold may be corrupted.', 'danger');
                    return;
                }

                // Clear current cart
                CART = [];

                // Load cart items from hold
                cartItems.forEach(item => {
                    // Ensure all required fields are present
                    const cartItem = {
                        id: `${item.product_id}-${item.unit || 'PCS'}-${item.price || 0}`,
                        product_id: item.product_id || item.id,
                        name: item.name || 'Unknown Product',
                        code: item.code || (item.product_id || item.id).toString(),
                        mrp: item.mrp || 0,
                        base_price: item.base_price || item.price || 0,
                        price: item.price || 0,
                        price_type: item.price_type || 'retail',
                        quantity: item.quantity || 1,
                        unit: item.unit || 'PCS',
                        is_secondary_unit: item.is_secondary_unit || false,
                        discount_value: item.discount_value || 0,
                        discount_type: item.discount_type || 'percentage',
                        discount_amount: item.discount_amount || 0,
                        shop_stock: item.shop_stock || 0,
                        hsn_code: item.hsn_code || '',
                        cgst_rate: item.cgst_rate || 0,
                        sgst_rate: item.sgst_rate || 0,
                        igst_rate: item.igst_rate || 0,
                        total: (item.price || 0) * (item.quantity || 1),
                        stock_price: item.stock_price || 0,
                        retail_price: item.retail_price || 0,
                        wholesale_price: item.wholesale_price || 0,
                        unit_of_measure: item.unit_of_measure || 'PCS',
                        added_at: new Date().toISOString(),
                        quantity_in_primary: item.quantity_in_primary || (item.quantity || 1)
                    };

                    CART.push(cartItem);
                });

                // Parse cart_json for additional settings
                let cartJson = {};
                try {
                    if (hold.cart_json) {
                        if (typeof hold.cart_json === 'string') {
                            cartJson = JSON.parse(hold.cart_json);
                        } else {
                            cartJson = hold.cart_json;
                        }
                    }
                } catch (jsonError) {
                    console.warn('Error parsing cart_json:', jsonError);
                    cartJson = {};
                }

                // Restore customer details from cart_json
                document.getElementById('customer-name').value = cartJson.customer_name || hold.customer_name || 'Walk-in Customer';

                if (cartJson.customer_phone || hold.customer_phone) {
                    $('#customer-contact').val(cartJson.customer_phone || hold.customer_phone).trigger('change');
                }

                document.getElementById('customer-address').value = cartJson.customer_address || '';
                document.getElementById('customer-gstin').value = cartJson.customer_gstin || hold.customer_gstin || '';

                // Restore other settings
                if (cartJson.invoice_type) {
                    document.getElementById('invoice-type').value = cartJson.invoice_type;
                    GST_TYPE = cartJson.invoice_type;
                }

                if (cartJson.price_type) {
                    document.getElementById('price-type').value = cartJson.price_type;
                    GLOBAL_PRICE_TYPE = cartJson.price_type;
                }

                if (cartJson.referral_id) {
                    $('#referral').val(cartJson.referral_id).trigger('change');
                    SELECTED_REFERRAL_ID = cartJson.referral_id;
                }

                if (cartJson.discount !== undefined) {
                    document.getElementById('additional-dis').value = cartJson.discount;
                }

                if (cartJson.discount_type) {
                    document.getElementById('overall-discount-type').value = cartJson.discount_type;
                }

                // Update UI
                renderCart();
                saveCartToSession();
                updateBillingSummary();
                updateButtonStates();

                // Close modal
                const modal = bootstrap.Modal.getInstance(document.getElementById('holdListModal'));
                if (modal) {
                    modal.hide();
                }

                // Delete the hold after successful retrieval (optional)
                try {
                    await deleteHold(holdId, false); // Don't show alert for auto-delete
                } catch (deleteError) {
                    console.warn('Could not auto-delete hold after retrieval:', deleteError);
                }

                showToast(`Hold #${hold.hold_number} retrieved successfully`, 'success');

            } else {
                throw new Error(data.message || 'Failed to retrieve hold');
            }

        } catch (error) {
            console.error('Error retrieving hold:', error);
            showToast('Error retrieving hold: ' + error.message, 'danger');
        }
    }

    async function deleteHold(holdId, showAlert = true) {
        try {
            showConfirmation(
                'Delete Hold',
                'Are you sure you want to delete this held invoice? This action cannot be undone.',
                async function () {
                    showLoading('Deleting hold...');

                    const response = await fetchWithTimeout('api/holds.php?action=delete', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json'
                        },
                        body: JSON.stringify({ hold_id: holdId }),
                        timeout: 5000
                    });

                    if (!response.ok) {
                        throw new Error(`HTTP ${response.status}: ${response.statusText}`);
                    }

                    const data = await response.json();

                    if (data.success) {
                        hideLoading();
                        if (showAlert) {
                            showSuccessToast('Hold deleted successfully');
                        }
                        // Refresh hold list
                        loadHoldList();
                    } else {
                        hideLoading();
                        throw new Error(data.message || 'Failed to delete hold');
                    }
                }
            );

        } catch (error) {
            console.error('Error deleting hold:', error);
            hideLoading();
            if (showAlert) {
                showErrorModal('Error', `Error deleting hold: ${error.message}`);
            }
        }
    }

    // ==================== QUOTATION FUNCTIONS ====================
    async function showQuotationModal() {
        if (CART.length === 0) {
            showToast('Please add items to cart first', 'warning');
            return;
        }

        try {
            // Generate quotation number
            const response = await fetchWithTimeout('api/quotations.php?action=get_next_number', {
                timeout: 5000
            });

            if (!response.ok) {
                throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            }

            const data = await response.json();

            if (data.success) {
                document.getElementById('quotationNumber').value = data.quotation_number;

                const modal = new bootstrap.Modal(document.getElementById('quotationModal'));
                modal.show();

            } else {
                throw new Error(data.message || 'Failed to generate quotation number');
            }

        } catch (error) {
            console.error('Error preparing quotation:', error);
            showToast('Error: ' + error.message, 'danger');
        }
    }

    async function saveQuotation() {
        try {
            const quotationNumber = document.getElementById('quotationNumber').value;
            const validUntil = document.getElementById('quotationValidUntil').value;
            const notes = document.getElementById('quotationNotes').value.trim();

            if (!quotationNumber) {
                showWarningToast('Please generate a quotation number first');
                return;
            }

            if (!validUntil) {
                showWarningModal('Date Required', 'Please select a valid until date');
                document.getElementById('quotationValidUntil').focus();
                return;
            }

            showConfirmation(
                'Save Quotation?',
                'Are you sure you want to save this as a quotation?',
                async function () {
                    const totals = calculateTotals();
                    const today = new Date().toISOString().split('T')[0];

                    // Prepare quotation data
                    const quotationData = {
                        quotation_number: quotationNumber,
                        quotation_date: today,
                        valid_until: validUntil,
                        customer_name: document.getElementById('customer-name').value,
                        customer_phone: document.getElementById('customer-contact').value || '',
                        customer_email: '',
                        customer_address: document.getElementById('customer-address').value || '',
                        customer_gstin: document.getElementById('customer-gstin').value || '',
                        subtotal: totals.subtotal,
                        total_discount: totals.totalItemDiscount + totals.overallDiscount,
                        total_tax: totals.totalGST,
                        grand_total: totals.grandTotal,
                        notes: notes,
                        items: CART.map(item => ({
                            product_id: item.product_id,
                            product_name: item.name,
                            quantity: item.quantity,
                            unit_price: item.price,
                            discount_amount: item.discount_amount || 0,
                            discount_type: item.discount_type,
                            total_price: item.total,
                            hsn_code: item.hsn_code,
                            cgst_rate: item.cgst_rate,
                            sgst_rate: item.sgst_rate,
                            igst_rate: item.igst_rate,
                            tax_amount: calculateItemGST(item).total,
                            price_type: item.price_type
                        }))
                    };

                    showLoading('Saving quotation...');

                    // Save quotation
                    const response = await fetchWithTimeout('api/quotations.php?action=save', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json'
                        },
                        body: JSON.stringify(quotationData),
                        timeout: 10000
                    });

                    if (!response.ok) {
                        throw new Error(`HTTP ${response.status}: ${response.statusText}`);
                    }

                    const data = await response.json();

                    if (data.success) {
                        // Clear cart
                        CART = [];
                        clearCartFromSession();
                        renderCart();
                        updateBillingSummary();

                        // Close modal
                        const modal = bootstrap.Modal.getInstance(document.getElementById('quotationModal'));
                        if (modal) {
                            modal.hide();
                        }

                        hideLoading();

                        showSuccessModal(
                            'Quotation Saved!',
                            `Quotation #${quotationNumber} saved successfully.`,
                            function () {
                                resetForm();
                            }
                        );

                    } else {
                        hideLoading();
                        throw new Error(data.message || 'Failed to save quotation');
                    }
                }
            );

        } catch (error) {
            console.error('Error saving quotation:', error);
            hideLoading();
            showErrorModal('Error', `Error saving quotation: ${error.message}`);
        }
    }
    // ==================== QUOTATION LIST FUNCTIONS ====================
    async function loadQuotationList() {
        try {
            console.log('Loading quotation list...');

            const response = await fetchWithTimeout('api/quotations.php?action=list', {
                timeout: 5000
            });

            if (!response.ok) {
                throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            }

            const data = await response.json();

            if (data.success) {
                // Create modal HTML if it doesn't exist
                let modalElement = document.getElementById('quotationListModal');

                if (!modalElement) {
                    // Create modal HTML
                    const modalHTML = `
                    <div class="modal fade" id="quotationListModal" tabindex="-1" aria-hidden="true">
                        <div class="modal-dialog modal-lg">
                            <div class="modal-content">
                                <div class="modal-header">
                                    <h5 class="modal-title">Saved Quotations</h5>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                </div>
                                <div class="modal-body">
                                    <div class="table-responsive">
                                        <table class="table table-sm">
                                            <thead>
                                                <tr>
                                                    <th>#</th>
                                                    <th>Date</th>
                                                    <th>Quotation #</th>
                                                    <th>Customer</th>
                                                    <th>Valid Until</th>
                                                    <th>Amount</th>
                                                    <th>Status</th>
                                                    <th>Actions</th>
                                                </tr>
                                            </thead>
                                            <tbody id="quotationListBody">
                                                <!-- Data will be loaded here -->
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                                </div>
                            </div>
                        </div>
                    </div>
                `;

                    // Add modal to body
                    document.body.insertAdjacentHTML('beforeend', modalHTML);
                    modalElement = document.getElementById('quotationListModal');
                }

                // Populate the table body
                const tbody = document.getElementById('quotationListBody');
                tbody.innerHTML = '';

                if (data.quotations && data.quotations.length > 0) {
                    data.quotations.forEach((quote, index) => {
                        const statusClass = {
                            'active': 'badge bg-success',
                            'expired': 'badge bg-danger',
                            'accepted': 'badge bg-info',
                            'rejected': 'badge bg-warning'
                        }[quote.status || 'active'] || 'badge bg-secondary';

                        const row = document.createElement('tr');
                        row.innerHTML = `
                        <td>${index + 1}</td>
                        <td>${quote.formatted_date || quote.quotation_date}</td>
                        <td><strong>${quote.quotation_number}</strong></td>
                        <td>
                            ${quote.customer_name || 'Walk-in'}<br>
                            <small>${quote.customer_phone || ''}</small>
                        </td>
                        <td>${quote.formatted_valid_until || quote.valid_until}</td>
                        <td>₹ ${Math.round(parseFloat(quote.grand_total) || 0)}</td>
                        <td><span class="${statusClass}">${quote.status || 'active'}</span></td>
                        <td>
                            <button class="btn btn-sm btn-success" onclick="retrieveQuotation(${quote.id})">
                                <i class="fas fa-download me-1"></i> Retrieve
                            </button>
                            <button class="btn btn-sm btn-danger mt-1" onclick="deleteQuotation(${quote.id})">
                                <i class="fas fa-trash me-1"></i> Delete
                            </button>
                        </td>
                    `;
                        tbody.appendChild(row);
                    });
                } else {
                    tbody.innerHTML = '<tr><td colspan="8" class="text-center">No quotations found</td></tr>';
                }

                // Show modal
                const modal = new bootstrap.Modal(modalElement);
                modal.show();

                showToast(`Loaded ${data.quotations?.length || 0} quotations`, 'success');

            } else {
                throw new Error(data.message || 'Failed to load quotations');
            }

        } catch (error) {
            console.error('Error loading quotation list:', error);
            showToast('Error loading quotations: ' + error.message, 'danger');
        }
    }
    async function retrieveQuotation(quotationId) {
        try {
            console.log('Retrieving quotation:', quotationId);

            // Get quotation details
            const quoteResponse = await fetchWithTimeout(`api/quotations.php?action=get_items&quotation_id=${quotationId}`, {
                timeout: 5000
            });

            if (!quoteResponse.ok) {
                throw new Error(`HTTP ${quoteResponse.status}: ${quoteResponse.statusText}`);
            }

            const quoteData = await quoteResponse.json();

            if (quoteData.success && quoteData.items) {
                // Get quotation header info
                const listResponse = await fetchWithTimeout('api/quotations.php?action=list', {
                    timeout: 5000
                });

                if (listResponse.ok) {
                    const listData = await listResponse.json();
                    const quotation = listData.quotations?.find(q => q.id == quotationId);

                    if (quotation) {
                        // Clear current cart
                        CART = [];

                        // Load items from quotation
                        quoteData.items.forEach((item, index) => {
                            // Find product details
                            const product = findProductById(item.product_id);
                            if (product) {
                                // Determine if this is secondary unit - check if unit matches secondary unit
                                const isSecondaryUnit = product.secondary_unit &&
                                    item.unit &&
                                    item.unit === product.secondary_unit;

                                // Calculate quantity in primary units
                                let quantityInPrimary = item.quantity;
                                let unit = item.unit || product.unit_of_measure;

                                if (isSecondaryUnit && product.sec_unit_conversion) {
                                    quantityInPrimary = item.quantity / product.sec_unit_conversion;
                                } else if (!isSecondaryUnit) {
                                    quantityInPrimary = item.quantity;
                                    unit = product.unit_of_measure;
                                }

                                // Calculate price with discount
                                let price = parseFloat(item.unit_price) || 0;
                                let discountValue = 0;
                                let discountAmount = 0;

                                if (item.discount_amount > 0) {
                                    discountAmount = parseFloat(item.discount_amount) || 0;
                                    if (item.discount_type === 'percentage') {
                                        discountValue = (discountAmount / price) * 100;
                                    } else {
                                        discountValue = discountAmount;
                                    }
                                }

                                // Generate unique cart item ID
                                const cartItemId = `${item.product_id}-${unit}-${price}-${item.discount_type}-${isSecondaryUnit}`;

                                const cartItem = {
                                    id: cartItemId,
                                    product_id: item.product_id,
                                    name: product.product_name,
                                    original_product_name: product.product_name,
                                    code: product.product_code || item.product_id.toString(),
                                    mrp: product.mrp || 0,
                                    base_price: price,
                                    price: price,
                                    price_type: item.price_type || GLOBAL_PRICE_TYPE || 'retail',
                                    quantity: parseFloat(item.quantity) || 1,
                                    unit: unit,
                                    is_secondary_unit: isSecondaryUnit,
                                    discount_value: parseFloat(discountValue.toFixed(2)) || 0,
                                    discount_type: item.discount_type || 'percentage',
                                    discount_amount: parseFloat(discountAmount.toFixed(2)) || 0,
                                    shop_stock: product.shop_stock_primary || 0,
                                    hsn_code: item.hsn_code || product.hsn_code || '',
                                    cgst_rate: parseFloat(item.cgst_rate) || parseFloat(product.cgst_rate) || 0,
                                    sgst_rate: parseFloat(item.sgst_rate) || parseFloat(product.sgst_rate) || 0,
                                    igst_rate: parseFloat(item.igst_rate) || parseFloat(product.igst_rate) || 0,
                                    total_gst_rate: (parseFloat(item.cgst_rate) || parseFloat(product.cgst_rate) || 0) +
                                        (parseFloat(item.sgst_rate) || parseFloat(product.sgst_rate) || 0) +
                                        (parseFloat(item.igst_rate) || parseFloat(product.igst_rate) || 0),
                                    total: price * (parseFloat(item.quantity) || 1),
                                    referral_enabled: product.referral_enabled || 0,
                                    referral_type: product.referral_type || 'percentage',
                                    referral_value: parseFloat(product.referral_value) || 0,
                                    referral_commission: 0,
                                    secondary_unit: product.secondary_unit || '',
                                    sec_unit_conversion: parseFloat(product.sec_unit_conversion) || 1,
                                    stock_price: parseFloat(product.stock_price) || 0,
                                    retail_price: parseFloat(product.retail_price) || 0,
                                    wholesale_price: parseFloat(product.wholesale_price) || 0,
                                    unit_of_measure: product.unit_of_measure || 'PCS',
                                    quantity_in_primary: parseFloat(quantityInPrimary.toFixed(3)) || 1,
                                    added_at: new Date().toISOString(),
                                    category_name: product.category_name || '',
                                    subcategory_name: product.subcategory_name || ''
                                };

                                CART.push(cartItem);
                            } else {
                                console.warn('Product not found for ID:', item.product_id);

                                // Create minimal cart item even if product not found
                                const cartItem = {
                                    id: `${item.product_id}-${item.unit}-${item.unit_price}`,
                                    product_id: item.product_id,
                                    name: item.product_name || 'Unknown Product',
                                    code: item.product_id.toString(),
                                    mrp: 0,
                                    base_price: parseFloat(item.unit_price) || 0,
                                    price: parseFloat(item.unit_price) || 0,
                                    price_type: item.price_type || 'retail',
                                    quantity: parseFloat(item.quantity) || 1,
                                    unit: item.unit || 'PCS',
                                    is_secondary_unit: false,
                                    discount_value: 0,
                                    discount_type: 'percentage',
                                    discount_amount: 0,
                                    shop_stock: 0,
                                    hsn_code: item.hsn_code || '',
                                    cgst_rate: parseFloat(item.cgst_rate) || 0,
                                    sgst_rate: parseFloat(item.sgst_rate) || 0,
                                    igst_rate: parseFloat(item.igst_rate) || 0,
                                    total_gst_rate: (parseFloat(item.cgst_rate) || 0) +
                                        (parseFloat(item.sgst_rate) || 0) +
                                        (parseFloat(item.igst_rate) || 0),
                                    total: (parseFloat(item.unit_price) || 0) * (parseFloat(item.quantity) || 1),
                                    referral_enabled: 0,
                                    referral_type: 'percentage',
                                    referral_value: 0,
                                    referral_commission: 0,
                                    secondary_unit: '',
                                    sec_unit_conversion: 1,
                                    stock_price: 0,
                                    retail_price: 0,
                                    wholesale_price: 0,
                                    unit_of_measure: 'PCS',
                                    quantity_in_primary: parseFloat(item.quantity) || 1,
                                    added_at: new Date().toISOString(),
                                    category_name: '',
                                    subcategory_name: ''
                                };

                                CART.push(cartItem);
                            }
                        });

                        console.log('Loaded cart items from quotation:', CART);

                        // Restore customer details
                        document.getElementById('customer-name').value = quotation.customer_name || 'Walk-in Customer';
                        if (quotation.customer_phone) {
                            $('#customer-contact').val(quotation.customer_phone).trigger('change');
                        }
                        document.getElementById('customer-address').value = quotation.customer_address || '';
                        document.getElementById('customer-gstin').value = quotation.customer_gstin || '';

                        // Restore invoice type and price type if present in quotation
                        if (quotation.invoice_type) {
                            document.getElementById('invoice-type').value = quotation.invoice_type;
                            GST_TYPE = quotation.invoice_type;
                        }

                        if (quotation.price_type) {
                            document.getElementById('price-type').value = quotation.price_type;
                            GLOBAL_PRICE_TYPE = quotation.price_type;
                        }

                        // Clear the session storage first to avoid conflicts
                        clearCartFromSession();

                        // Force re-render of cart
                        renderCart();

                        // Save to session
                        saveCartToSession();

                        // Update all calculations
                        updateBillingSummary();
                        updateButtonStates();

                        // Close modal
                        const modal = bootstrap.Modal.getInstance(document.getElementById('quotationListModal'));
                        if (modal) {
                            modal.hide();
                        }

                        showToast(`Quotation #${quotation.quotation_number} retrieved successfully with ${CART.length} items`, 'success');

                    } else {
                        throw new Error('Quotation not found');
                    }
                } else {
                    throw new Error('Failed to load quotation details');
                }
            } else {
                throw new Error(quoteData.message || 'Failed to retrieve quotation items');
            }

        } catch (error) {
            console.error('Error retrieving quotation:', error);
            showToast('Error retrieving quotation: ' + error.message, 'danger');
        }
    }
    async function deleteQuotation(quotationId) {
        showConfirmation(
            'Delete Quotation',
            'Are you sure you want to delete this quotation? This action cannot be undone.',
            async function () {
                try {
                    showLoading('Deleting quotation...');

                    const response = await fetchWithTimeout('api/quotations.php?action=delete', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json'
                        },
                        body: JSON.stringify({ quotation_id: quotationId }),
                        timeout: 5000
                    });

                    if (!response.ok) {
                        throw new Error(`HTTP ${response.status}: ${response.statusText}`);
                    }

                    const data = await response.json();

                    if (data.success) {
                        hideLoading();
                        showSuccessToast('Quotation deleted successfully');
                        // Refresh quotation list
                        loadQuotationList();
                    } else {
                        hideLoading();
                        throw new Error(data.message || 'Failed to delete quotation');
                    }
                } catch (error) {
                    console.error('Error deleting quotation:', error);
                    hideLoading();
                    showErrorModal('Error', `Error deleting quotation: ${error.message}`);
                }
            }
        );
    }

    // ==================== INITIALIZE GLOBAL FUNCTIONS ====================
    // Make functions available globally for inline event handlers
    window.updateCartItemQuantity = updateCartItemQuantity;
    window.updateCartItemPriceType = updateCartItemPriceType;
    window.updateCartItemDiscount = updateCartItemDiscount;
    window.removeCartItem = removeCartItem;
    window.cartItemDecrement = cartItemDecrement;
    window.cartItemIncrement = cartItemIncrement;

    console.log('POS System: Script loaded successfully');
</script>