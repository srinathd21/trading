// Global State Variables
let CART = [];
let PRODUCTS = [];
let BARCODE_MAP = {};
let GLOBAL_PRICE_TYPE = 'retail';
let ACTIVE_PAYMENT_METHODS = new Set(['cash']);
let GST_TYPE = 'gst';
let CURRENT_CUSTOMER_ID = null;
let SELECTED_REFERRAL_ID = null;
let PENDING_OFFLINE_INVOICES = [];
let LOYALTY_POINTS_DISCOUNT = 0;
let POINTS_USED = 0;
let CUSTOMER_POINTS = {
    available_points: 0,
    total_points_earned: 0,
    total_points_redeemed: 0
};
let LOYALTY_SETTINGS = {
    points_per_amount: 0.01,
    redeem_value_per_point: 1.00,
    min_points_to_redeem: 50,
    is_active: true
};

// Initialize the POS
document.addEventListener('DOMContentLoaded', function() {
    initializePOS();
    setupEventListeners();
    loadInitialData();
    checkOfflineStatus();
});

function initializePOS() {
    // Set today's date
    const today = new Date().toISOString().split('T')[0];
    document.getElementById('invoice-date').value = today;
    
    // Generate invoice number
    generateInvoiceNumber();
    
    // Initialize Select2
    $('#search-product').select2({
        placeholder: 'Search product by name or code...',
        allowClear: true,
        width: '100%'
    });
    
    $('#customer-contact').select2({
        placeholder: 'Select or type phone...',
        tags: true,
        allowClear: true,
        width: '100%'
    });
    
    $('#referral').select2({
        placeholder: 'Select referral...',
        allowClear: true,
        width: '100%'
    });
    
    // Setup barcode input auto-focus
    document.getElementById('barcode-input').focus();
    
    // Initialize payment inputs
    initializePaymentInputs();
    
    // Update UI
    updateBillingSummary();
}

function setupEventListeners() {
    // Product related
    $('#search-product').on('change', handleProductSelection);
    document.getElementById('barcode-input').addEventListener('keydown', handleBarcodeScan);
    document.getElementById('product-add-button').addEventListener('click', addProductToCart);
    document.getElementById('unit-convert').addEventListener('click', toggleUnitConversion);
    document.getElementById('qty-input').addEventListener('input', validateQuantity);
    document.getElementById('discount').addEventListener('input', validateDiscount);
    document.getElementById('selling-price').addEventListener('input', validateSellingPrice);
    
    // Customer related
    $('#customer-contact').on('change', handleCustomerSelection);
    document.getElementById('customer-name').addEventListener('input', updateCustomer);
    
    // Price type change
    document.getElementById('price-type').addEventListener('change', function() {
        GLOBAL_PRICE_TYPE = this.value;
        updateGlobalPriceType();
    });
    
    // Invoice type change
    document.getElementById('invoice-type').addEventListener('change', function() {
        GST_TYPE = this.value;
        generateInvoiceNumber();
        updateBillingSummary();
    });
    
    // Invoice date change
    document.getElementById('invoice-date').addEventListener('change', generateInvoiceNumber);
    
    // Referral selection
    $('#referral').on('change', function() {
        SELECTED_REFERRAL_ID = this.value ? parseInt(this.value) : null;
    });
    
    // Discount inputs
    document.getElementById('additional-dis').addEventListener('input', updateBillingSummary);
    document.getElementById('discount-type').addEventListener('change', updateBillingSummary);
    
    // Payment methods
    document.querySelectorAll('input[name="payment-method"]').forEach(checkbox => {
        checkbox.addEventListener('change', handlePaymentMethodCheckbox);
    });
    
    // Action buttons
    document.getElementById('btnHoldList').addEventListener('click', loadHoldList);
    document.getElementById('btnHold').addEventListener('click', holdInvoice);
    document.getElementById('btnQuotation').addEventListener('click', showQuotationModal);
    document.getElementById('btnClearCart').addEventListener('click', clearCart);
    document.getElementById('btnApplyToAll').addEventListener('click', applyGlobalPriceToAll);
    document.getElementById('btnShowPointsDetails').addEventListener('click', showPointsModal);
    document.getElementById('btnGenerateBill').addEventListener('click', generateBill);
    document.getElementById('btnAutoFillRemaining').addEventListener('click', autoFillRemainingAmount);
    document.getElementById('btnProfitAnalysis').addEventListener('click', showProfitAnalysis);
    document.getElementById('btnOfflineSync').addEventListener('click', syncOfflineInvoices);
    
    // Offline sync
    window.addEventListener('online', handleOnlineStatus);
    window.addEventListener('offline', handleOfflineStatus);
}

async function loadInitialData() {
    try {
        await Promise.all([
            loadProducts(),
            loadCustomers(),
            loadReferrals(),
            loadLoyaltySettings(),
            loadOfflineInvoices()
        ]);
        showToast('System loaded successfully', 'success');
    } catch (error) {
        console.error('Error loading initial data:', error);
        showToast('Some data failed to load', 'warning');
    }
}

// ==================== DATA LOADING FUNCTIONS ====================

async function loadProducts() {
    try {
        const response = await fetch('api/get_products.php');
        const data = await response.json();
        
        if (data.success) {
            PRODUCTS = data.products;
            BARCODE_MAP = data.barcode_map;
            
            const select = document.getElementById('search-product');
            select.innerHTML = '<option value="">-- Search product --</option>';
            
            data.products.forEach(product => {
                const option = document.createElement('option');
                option.value = product.id;
                
                // Show stock status
                const stockStatus = product.shop_stock > 10 ? 'in-stock' : 
                                  product.shop_stock > 0 ? 'low-stock' : 'out-of-stock';
                const stockText = stockStatus === 'in-stock' ? '' : 
                                ` (${product.shop_stock} left)`;
                
                option.textContent = `${product.name} [${product.code}] - ₹${product.retail_price}${stockText}`;
                option.dataset.product = JSON.stringify(product);
                
                select.appendChild(option);
            });
            
            $('#search-product').trigger('change.select2');
        }
    } catch (error) {
        console.error('Error loading products:', error);
        showToast('Failed to load products', 'danger');
    }
}

async function loadCustomers() {
    try {
        const response = await fetch('api/get_customers.php');
        const data = await response.json();
        
        if (data.success) {
            const select = document.getElementById('customer-contact');
            select.innerHTML = '<option value="">-- Select phone --</option>';
            
            data.customers.forEach(customer => {
                const option = document.createElement('option');
                option.value = customer.phone;
                option.textContent = `${customer.phone} - ${customer.name}`;
                option.dataset.customerId = customer.id;
                option.dataset.customer = JSON.stringify(customer);
                select.appendChild(option);
            });
            
            $('#customer-contact').trigger('change.select2');
        }
    } catch (error) {
        console.error('Error loading customers:', error);
    }
}

async function loadReferrals() {
    try {
        const response = await fetch('api/get_referrals.php');
        const data = await response.json();
        
        if (data.success) {
            const select = document.getElementById('referral');
            select.innerHTML = '<option value="">-- No referral --</option>';
            
            data.referrals.forEach(referral => {
                const option = document.createElement('option');
                option.value = referral.id;
                option.textContent = `${referral.full_name} (${referral.referral_code})`;
                select.appendChild(option);
            });
            
            $('#referral').trigger('change.select2');
        }
    } catch (error) {
        console.error('Error loading referrals:', error);
    }
}

async function loadLoyaltySettings() {
    try {
        const response = await fetch('api/get_loyalty_settings.php');
        const data = await response.json();
        
        if (data.success) {
            LOYALTY_SETTINGS = data.settings;
        }
    } catch (error) {
        console.error('Error loading loyalty settings:', error);
    }
}

// ==================== PRODUCT HANDLING FUNCTIONS ====================

function handleProductSelection() {
    const productId = this.value;
    if (!productId) {
        clearProductSelection();
        return;
    }
    
    const product = findProductById(parseInt(productId));
    if (product) {
        updateProductForm(product);
    }
}

function handleBarcodeScan(event) {
    if (event.key === 'Enter') {
        event.preventDefault();
        const barcode = this.value.trim();
        
        if (!barcode) {
            showToast('Enter barcode first', 'warning');
            return;
        }
        
        const product = findProductByBarcode(barcode);
        if (!product) {
            showToast(`No product found for barcode: ${barcode}`, 'danger');
            this.value = '';
            this.focus();
            return;
        }
        
        // Select the product in dropdown
        $('#search-product').val(product.id).trigger('change');
        updateProductForm(product);
        
        // Add to cart automatically if price is valid
        const sellingPrice = parseFloat(document.getElementById('selling-price').value);
        if (sellingPrice > 0) {
            addProductToCart();
        }
        
        this.value = '';
        this.focus();
    }
}

function updateProductForm(product) {
    // Set MRP and default selling price based on global price type
    document.getElementById('mrp').value = product.mrp || '';
    
    const sellingPrice = GLOBAL_PRICE_TYPE === 'wholesale' ? 
        product.wholesale_price : product.retail_price;
    document.getElementById('selling-price').value = sellingPrice;
    
    // Set unit and enable/disable convert button
    document.getElementById('qty-unit').textContent = product.unit_of_measure || 'PCS';
    const convertBtn = document.getElementById('unit-convert');
    
    if (product.secondary_unit && product.sec_unit_conversion) {
        convertBtn.disabled = false;
        convertBtn.title = `Convert to ${product.secondary_unit} (${product.sec_unit_conversion} ${product.unit_of_measure} = 1 ${product.secondary_unit})`;
        convertBtn.dataset.product = JSON.stringify(product);
    } else {
        convertBtn.disabled = true;
        convertBtn.title = 'No secondary unit available';
    }
    
    // Enable add button if price is valid
    const addButton = document.getElementById('product-add-button');
    addButton.disabled = false;
    
    // Set batch field
    document.getElementById('batch').value = '';
    
    // Update stock display in product search dropdown
    updateProductStockDisplay(product.id, product.shop_stock);
}

function clearProductSelection() {
    $('#search-product').val('').trigger('change');
    document.getElementById('barcode-input').value = '';
    document.getElementById('mrp').value = '';
    document.getElementById('selling-price').value = '';
    document.getElementById('discount').value = '0';
    document.getElementById('qty-input').value = '1';
    document.getElementById('qty-unit').textContent = 'PCS';
    document.getElementById('unit-convert').disabled = true;
    document.getElementById('product-add-button').disabled = true;
    document.getElementById('batch').value = '';
}

function toggleUnitConversion() {
    const productData = JSON.parse(this.dataset.product);
    if (!productData || !productData.secondary_unit) return;
    
    const currentUnit = document.getElementById('qty-unit').textContent;
    const isSecondary = currentUnit === productData.secondary_unit;
    
    if (isSecondary) {
        // Convert back to primary unit
        document.getElementById('qty-unit').textContent = productData.unit_of_measure;
        this.innerHTML = '<i class="fas fa-exchange-alt"></i>';
        this.title = `Convert to ${productData.secondary_unit}`;
        
        // Update selling price if needed
        const currentPrice = parseFloat(document.getElementById('selling-price').value);
        if (currentPrice && productData.sec_unit_price_type === 'fixed') {
            const newPrice = currentPrice - productData.sec_unit_extra_charge;
            document.getElementById('selling-price').value = Math.max(0, newPrice);
        }
    } else {
        // Convert to secondary unit
        document.getElementById('qty-unit').textContent = productData.secondary_unit;
        this.innerHTML = '<i class="fas fa-undo"></i>';
        this.title = `Convert back to ${productData.unit_of_measure}`;
        
        // Update selling price if needed
        const currentPrice = parseFloat(document.getElementById('selling-price').value);
        if (currentPrice && productData.sec_unit_price_type === 'fixed') {
            const newPrice = currentPrice + productData.sec_unit_extra_charge;
            document.getElementById('selling-price').value = newPrice;
        } else if (currentPrice && productData.sec_unit_price_type === 'percentage') {
            const newPrice = currentPrice * (1 + productData.sec_unit_extra_charge / 100);
            document.getElementById('selling-price').value = newPrice.toFixed(2);
        }
    }
}

function validateQuantity() {
    const qty = parseFloat(this.value);
    const productId = document.getElementById('search-product').value;
    
    if (!productId || qty <= 0) {
        document.getElementById('product-add-button').disabled = true;
        return;
    }
    
    const product = findProductById(parseInt(productId));
    if (product && product.shop_stock < qty) {
        showToast(`Insufficient stock. Available: ${product.shop_stock}`, 'warning');
        this.value = product.shop_stock;
    }
    
    document.getElementById('product-add-button').disabled = false;
}

function validateDiscount() {
    const discount = parseFloat(this.value);
    const sellingPrice = parseFloat(document.getElementById('selling-price').value);
    
    if (discount < 0) {
        this.value = 0;
    } else if (discount > 100) {
        this.value = 100;
    }
}

function validateSellingPrice() {
    const price = parseFloat(this.value);
    const mrp = parseFloat(document.getElementById('mrp').value);
    
    if (price < 0) {
        this.value = 0;
    }
    
    // Enable/disable add button
    document.getElementById('product-add-button').disabled = price <= 0;
}

function addProductToCart() {
    const productId = document.getElementById('search-product').value;
    if (!productId) {
        showToast('Please select a product first', 'warning');
        return;
    }
    
    const product = findProductById(parseInt(productId));
    if (!product) {
        showToast('Product not found', 'danger');
        return;
    }
    
    // Get form values
    const qty = parseFloat(document.getElementById('qty-input').value) || 1;
    const unit = document.getElementById('qty-unit').textContent;
    const isSecondaryUnit = unit !== product.unit_of_measure;
    const discount = parseFloat(document.getElementById('discount').value) || 0;
    const sellingPrice = parseFloat(document.getElementById('selling-price').value) || 0;
    const batch = document.getElementById('batch').value.trim();
    const mrp = parseFloat(document.getElementById('mrp').value) || 0;
    
    // Validate
    if (qty <= 0) {
        showToast('Please enter a valid quantity', 'warning');
        return;
    }
    
    if (sellingPrice <= 0) {
        showToast('Please enter a valid selling price', 'warning');
        return;
    }
    
    // Calculate actual quantity based on unit
    let actualQty = qty;
    if (isSecondaryUnit && product.sec_unit_conversion) {
        actualQty = qty * product.sec_unit_conversion;
    }
    
    // Check stock
    if (product.shop_stock < actualQty) {
        showToast(`Insufficient stock. Available: ${product.shop_stock} ${product.unit_of_measure}`, 'warning');
        return;
    }
    
    // Create cart item
    const cartItem = {
        id: product.id,
        name: product.name,
        code: product.code,
        mrp: mrp,
        price: sellingPrice,
        price_type: GLOBAL_PRICE_TYPE,
        quantity: qty,
        actual_quantity: actualQty,
        unit: unit,
        is_secondary_unit: isSecondaryUnit,
        discount_value: discount,
        discount_type: 'percentage',
        shop_stock: product.shop_stock,
        hsn_code: product.hsn_code,
        cgst_rate: product.cgst_rate,
        sgst_rate: product.sgst_rate,
        igst_rate: product.igst_rate,
        stock_price: product.stock_price,
        retail_price: product.retail_price,
        wholesale_price: product.wholesale_price,
        batch: batch,
        secondary_unit: product.secondary_unit,
        sec_unit_conversion: product.sec_unit_conversion,
        sec_unit_price_type: product.sec_unit_price_type,
        sec_unit_extra_charge: product.sec_unit_extra_charge
    };
    
    // Check if item already exists in cart (same product, unit, and batch)
    const existingIndex = CART.findIndex(item => 
        item.id === cartItem.id && 
        item.unit === cartItem.unit &&
        item.batch === cartItem.batch
    );
    
    if (existingIndex >= 0) {
        // Update existing item
        CART[existingIndex].quantity += qty;
        CART[existingIndex].actual_quantity += actualQty;
        showToast(`${product.name} quantity updated`, 'info');
    } else {
        // Add new item
        CART.unshift(cartItem);
        showToast(`${product.name} added to cart`, 'success');
    }
    
    // Update UI
    renderCart();
    clearProductSelection();
    updateBillingSummary();
    
    // Focus back on barcode input
    document.getElementById('barcode-input').focus();
}

// ==================== CART MANAGEMENT FUNCTIONS ====================

function renderCart() {
    const tbody = document.getElementById('cartBody');
    const emptyRow = document.getElementById('emptyCartRow');
    
    if (CART.length === 0) {
        emptyRow.style.display = '';
        return;
    }
    
    emptyRow.style.display = 'none';
    tbody.innerHTML = '';
    
    CART.forEach((item, index) => {
        const row = document.createElement('tr');
        const itemTotal = calculateItemTotal(item);
        const itemProfit = calculateItemProfit(item);
        
        row.innerHTML = `
            <td class="text-center">${index + 1}</td>
            <td>
                <strong>${item.name}</strong><br>
                <small class="text-muted">${item.code}</small>
                ${item.batch ? `<br><small class="text-muted">Batch: ${item.batch}</small>` : ''}
            </td>
            <td>
                <div class="input-group input-group-sm">
                    <button class="btn btn-outline-secondary" onclick="updateCartItemQuantity(${index}, -1)" type="button">
                        <i class="fas fa-minus"></i>
                    </button>
                    <input type="number" class="form-control text-center" 
                           value="${item.quantity.toFixed(2)}" 
                           onchange="updateCartItemQuantity(${index}, this.value)" 
                           min="0.01" step="0.01">
                    <button class="btn btn-outline-secondary" onclick="updateCartItemQuantity(${index}, 1)" type="button">
                        <i class="fas fa-plus"></i>
                    </button>
                </div>
            </td>
            <td class="text-center">${item.unit}</td>
            <td>
                <select class="form-select form-select-sm" onchange="updateCartItemPriceType(${index}, this.value)">
                    <option value="retail" ${item.price_type === 'retail' ? 'selected' : ''}>Retail</option>
                    <option value="wholesale" ${item.price_type === 'wholesale' ? 'selected' : ''}>Wholesale</option>
                </select>
            </td>
            <td>
                <input type="number" class="form-control form-control-sm" 
                       value="${item.discount_value || 0}" 
                       onchange="updateCartItemDiscount(${index}, this.value)" 
                       min="0" max="100" step="0.01">
            </td>
            <td class="text-end">₹${item.price.toFixed(2)}</td>
            <td class="text-center">${(item.cgst_rate + item.sgst_rate + item.igst_rate).toFixed(2)}%</td>
            <td class="text-end fw-bold">₹${itemTotal.toFixed(2)}</td>
            <td class="text-center">
                <button class="btn btn-sm btn-outline-danger" onclick="removeCartItem(${index})">
                    <i class="fas fa-trash"></i>
                </button>
            </td>
        `;
        
        tbody.appendChild(row);
    });
}

function updateCartItemQuantity(index, value) {
    if (!CART[index]) return;
    
    let newQty;
    if (typeof value === 'string') {
        newQty = parseFloat(value) || 0;
    } else {
        newQty = CART[index].quantity + value;
    }
    
    if (newQty <= 0) {
        removeCartItem(index);
        return;
    }
    
    const product = findProductById(CART[index].id);
    let actualQty = newQty;
    
    if (CART[index].is_secondary_unit && product.sec_unit_conversion) {
        actualQty = newQty * product.sec_unit_conversion;
    }
    
    // Check stock
    if (product.shop_stock < actualQty) {
        showToast(`Insufficient stock. Available: ${product.shop_stock} ${product.unit_of_measure}`, 'warning');
        return;
    }
    
    CART[index].quantity = newQty;
    CART[index].actual_quantity = actualQty;
    
    renderCart();
    updateBillingSummary();
}

function updateCartItemPriceType(index, priceType) {
    if (CART[index]) {
        CART[index].price_type = priceType;
        const product = findProductById(CART[index].id);
        
        if (product) {
            // Set price based on price type
            if (priceType === 'wholesale') {
                CART[index].price = product.wholesale_price;
            } else {
                CART[index].price = product.retail_price;
            }
        }
        
        renderCart();
        updateBillingSummary();
    }
}

function updateCartItemDiscount(index, discountValue) {
    if (CART[index]) {
        let discount = parseFloat(discountValue) || 0;
        discount = Math.min(100, Math.max(0, discount)); // Clamp between 0-100
        CART[index].discount_value = discount;
        renderCart();
        updateBillingSummary();
    }
}

function removeCartItem(index) {
    if (CART[index]) {
        const itemName = CART[index].name;
        CART.splice(index, 1);
        renderCart();
        updateBillingSummary();
        showToast(`${itemName} removed from cart`, 'info');
    }
}

function clearCart() {
    if (CART.length === 0) return;
    
    if (confirm(`Clear all ${CART.length} items from cart?`)) {
        CART = [];
        renderCart();
        updateBillingSummary();
        showToast('Cart cleared', 'info');
    }
}

function updateGlobalPriceType() {
    CART.forEach(item => {
        updateCartItemPriceType(CART.indexOf(item), GLOBAL_PRICE_TYPE);
    });
}

function applyGlobalPriceToAll() {
    if (CART.length === 0) {
        showToast('No items in cart', 'warning');
        return;
    }
    
    if (confirm(`Apply ${GLOBAL_PRICE_TYPE} pricing to all ${CART.length} items?`)) {
        updateGlobalPriceType();
        showToast(`Applied ${GLOBAL_PRICE_TYPE} pricing to all items`, 'success');
    }
}

// ==================== CALCULATION FUNCTIONS ====================

function calculateItemTotal(item) {
    const base = item.price * item.quantity;
    const discount = base * (item.discount_value / 100);
    return Math.max(0, base - discount);
}

function calculateItemGST(item) {
    if (GST_TYPE === 'non-gst') {
        return { cgst: 0, sgst: 0, igst: 0, total: 0 };
    }
    
    const itemTotal = calculateItemTotal(item);
    const cgst = itemTotal * (item.cgst_rate / 100);
    const sgst = itemTotal * (item.sgst_rate / 100);
    const igst = itemTotal * (item.igst_rate / 100);
    
    return {
        cgst: cgst,
        sgst: sgst,
        igst: igst,
        total: cgst + sgst + igst
    };
}

function calculateItemProfit(item) {
    const costPrice = item.stock_price || 0;
    const sellingPrice = item.price * (1 - item.discount_value / 100);
    const quantity = item.actual_quantity || item.quantity;
    
    return {
        actual: (sellingPrice - costPrice) * quantity,
        cost_price: costPrice,
        selling_price: sellingPrice
    };
}

function calculateTotals() {
    let subtotal = 0;
    let totalItemDiscount = 0;
    let totalCGST = 0;
    let totalSGST = 0;
    let totalIGST = 0;
    let totalActualProfit = 0;
    let totalMarketProfit = 0;
    
    CART.forEach(item => {
        const itemTotal = item.price * item.quantity;
        const itemDiscount = itemTotal * (item.discount_value / 100);
        const itemNet = Math.max(0, itemTotal - itemDiscount);
        const itemGST = calculateItemGST(item);
        const itemProfit = calculateItemProfit(item);
        
        subtotal += itemTotal;
        totalItemDiscount += itemDiscount;
        totalCGST += itemGST.cgst;
        totalSGST += itemGST.sgst;
        totalIGST += itemGST.igst;
        totalActualProfit += itemProfit.actual;
    });
    
    const subtotalAfterItems = subtotal - totalItemDiscount;
    
    // Overall discount
    const overallDiscVal = parseFloat(document.getElementById('additional-dis').value) || 0;
    const overallDiscType = document.getElementById('discount-type').value;
    const overallDiscount = overallDiscType === 'percentage' ?
        subtotalAfterItems * (overallDiscVal / 100) :
        Math.min(overallDiscVal, subtotalAfterItems);
    
    const totalBeforePoints = Math.max(0, subtotalAfterItems - overallDiscount);
    
    // Loyalty points discount
    const pointsDiscount = Math.min(LOYALTY_POINTS_DISCOUNT, totalBeforePoints);
    
    // GST total
    const totalGST = GST_TYPE === 'gst' ? (totalCGST + totalSGST + totalIGST) : 0;
    
    // Grand total
    const grandTotal = Math.max(0, totalBeforePoints - pointsDiscount + totalGST);
    
    return {
        subtotal: subtotal,
        totalItemDiscount: totalItemDiscount,
        overallDiscount: overallDiscount,
        pointsDiscount: pointsDiscount,
        totalCGST: totalCGST,
        totalSGST: totalSGST,
        totalIGST: totalIGST,
        totalGST: totalGST,
        grandTotal: grandTotal,
        totalActualProfit: totalActualProfit,
        totalBeforePoints: totalBeforePoints
    };
}

function updateBillingSummary() {
    const totals = calculateTotals();
    
    // Update displays
    document.getElementById('subtotal-display').textContent = `₹${totals.subtotal.toFixed(2)}`;
    document.getElementById('item-discount-display').textContent = `₹${totals.totalItemDiscount.toFixed(2)}`;
    document.getElementById('overall-discount-display').textContent = `₹${totals.overallDiscount.toFixed(2)}`;
    document.getElementById('points-discount-display').textContent = `₹${totals.pointsDiscount.toFixed(2)}`;
    document.getElementById('cgst-display').textContent = `₹${totals.totalCGST.toFixed(2)}`;
    document.getElementById('sgst-display').textContent = `₹${totals.totalSGST.toFixed(2)}`;
    document.getElementById('igst-display').textContent = `₹${totals.totalIGST.toFixed(2)}`;
    document.getElementById('grand-total-display').textContent = `₹${totals.grandTotal.toFixed(2)}`;
    
    // Show/hide rows
    document.getElementById('item-discount-row').style.display = totals.totalItemDiscount > 0 ? '' : 'none';
    document.getElementById('overall-discount-row').style.display = totals.overallDiscount > 0 ? '' : 'none';
    document.getElementById('points-discount-row').style.display = totals.pointsDiscount > 0 ? '' : 'none';
    document.getElementById('cgst-row').style.display = GST_TYPE === 'gst' && totals.totalCGST > 0 ? '' : 'none';
    document.getElementById('sgst-row').style.display = GST_TYPE === 'gst' && totals.totalSGST > 0 ? '' : 'none';
    document.getElementById('igst-row').style.display = GST_TYPE === 'gst' && totals.totalIGST > 0 ? '' : 'none';
    
    // Update payment summary
    updatePaymentSummary();
}

// ==================== PAYMENT FUNCTIONS ====================

function initializePaymentInputs() {
    const paymentGrid = document.getElementById('paymentInputsGrid');
    paymentGrid.innerHTML = '';
    
    const paymentMethods = [
        { id: 'cash', icon: 'money-bill-wave', label: 'Cash' },
        { id: 'upi', icon: 'mobile-alt', label: 'UPI' },
        { id: 'bank', icon: 'university', label: 'Bank' },
        { id: 'cheque', icon: 'money-check', label: 'Cheque' },
        { id: 'credit', icon: 'credit-card', label: 'Credit' },
        { id: 'points', icon: 'gift', label: 'Points' }
    ];
    
    paymentMethods.forEach(method => {
        const card = document.createElement('div');
        card.className = 'payment-input-card';
        card.id = `${method.id}-input-card`;
        
        if (method.id === 'cash') {
            card.classList.add('active');
        }
        
        if (method.id === 'points') {
            card.innerHTML = `
                <h6 class="small fw-bold mb-2">
                    <i class="fas fa-${method.icon} me-1 text-info"></i>${method.label}
                </h6>
                <div class="mb-2">
                    <label class="form-label small mb-1">Points Used</label>
                    <input type="number" class="form-control form-control-sm" 
                           id="points-amount" value="0" min="0" step="1">
                </div>
                <div>
                    <label class="form-label small mb-1">Discount Value</label>
                    <input type="text" class="form-control form-control-sm" 
                           id="points-discount-value" value="₹0.00" readonly>
                </div>
            `;
        } else {
            card.innerHTML = `
                <h6 class="small fw-bold mb-2">
                    <i class="fas fa-${method.icon} me-1 text-primary"></i>${method.label}
                </h6>
                <div class="mb-2">
                    <label class="form-label small mb-1">Amount (₹)</label>
                    <input type="number" class="form-control form-control-sm payment-amount" 
                           id="${method.id}-amount" value="0" min="0" step="0.01">
                </div>
                ${method.id !== 'cash' ? `
                <div>
                    <label class="form-label small mb-1">Reference</label>
                    <input type="text" class="form-control form-control-sm" 
                           id="${method.id}-reference" placeholder="${method.label} reference">
                </div>
                ` : ''}
            `;
        }
        
        paymentGrid.appendChild(card);
    });
    
    // Add event listeners to payment amount inputs
    document.querySelectorAll('.payment-amount').forEach(input => {
        input.addEventListener('input', updatePaymentSummary);
    });
    
    document.getElementById('points-amount').addEventListener('input', updatePointsPayment);
}

function handlePaymentMethodCheckbox(event) {
    const method = event.target.value;
    const isChecked = event.target.checked;
    const card = document.getElementById(`${method}-input-card`);
    
    if (isChecked) {
        ACTIVE_PAYMENT_METHODS.add(method);
        if (card) {
            card.classList.add('active');
            // Focus on amount input
            setTimeout(() => {
                const amountInput = card.querySelector('input[type="number"]');
                if (amountInput) amountInput.focus();
            }, 10);
        }
    } else {
        ACTIVE_PAYMENT_METHODS.delete(method);
        if (card) {
            card.classList.remove('active');
            // Reset amount
            const amountInput = card.querySelector('input[type="number"]');
            if (amountInput) amountInput.value = '0';
            
            // Reset reference if exists
            const refInput = card.querySelector('input[type="text"]');
            if (refInput && refInput.id.includes('reference')) {
                refInput.value = '';
            }
        }
    }
    
    updatePaymentSummary();
}

function updatePaymentSummary() {
    const totals = calculateTotals();
    const grandTotal = totals.grandTotal;
    
    // Get payment amounts from active methods
    let totalPaid = 0;
    const paymentData = {};
    
    ACTIVE_PAYMENT_METHODS.forEach(method => {
        const amountInput = document.getElementById(`${method}-amount`);
        if (amountInput) {
            const amount = parseFloat(amountInput.value) || 0;
            paymentData[method] = amount;
            totalPaid += amount;
        }
    });
    
    // Calculate points discount
    const pointsAmount = parseFloat(document.getElementById('points-amount').value) || 0;
    const pointsDiscount = pointsAmount * LOYALTY_SETTINGS.redeem_value_per_point;
    paymentData.points = pointsDiscount;
    totalPaid += pointsDiscount;
    
    const changeGiven = Math.max(0, totalPaid - grandTotal);
    const pendingAmount = Math.max(0, grandTotal - totalPaid);
    
    // Update display fields
    document.getElementById('total-paid').value = `₹${totalPaid.toFixed(2)}`;
    document.getElementById('change-given').value = `₹${changeGiven.toFixed(2)}`;
    document.getElementById('pending-amount').value = `₹${pendingAmount.toFixed(2)}`;
    
    // Update payment distribution display
    updatePaymentDistribution(paymentData, grandTotal, totalPaid, changeGiven, pendingAmount);
    
    // Enable/disable generate bill button
    const generateBillBtn = document.getElementById('btnGenerateBill');
    if (pendingAmount === 0 && totalPaid > 0) {
        generateBillBtn.disabled = false;
        generateBillBtn.classList.remove('btn-secondary');
        generateBillBtn.classList.add('btn-success');
    } else {
        generateBillBtn.disabled = true;
        generateBillBtn.classList.remove('btn-success');
        generateBillBtn.classList.add('btn-secondary');
    }
}

function updatePointsPayment() {
    const pointsInput = document.getElementById('points-amount');
    const points = parseInt(pointsInput.value) || 0;
    
    if (points < 0) {
        pointsInput.value = 0;
        return;
    }
    
    // Check available points
    if (points > CUSTOMER_POINTS.available_points) {
        showToast(`Only ${CUSTOMER_POINTS.available_points} points available`, 'warning');
        pointsInput.value = CUSTOMER_POINTS.available_points;
        points = CUSTOMER_POINTS.available_points;
    }
    
    // Calculate discount value
    const discount = points * LOYALTY_SETTINGS.redeem_value_per_point;
    document.getElementById('points-discount-value').value = `₹${discount.toFixed(2)}`;
    
    // Update points discount
    LOYALTY_POINTS_DISCOUNT = discount;
    POINTS_USED = points;
    
    updateBillingSummary();
    updatePaymentSummary();
}

function updatePaymentDistribution(paymentData, grandTotal, totalPaid, changeGiven, pendingAmount) {
    const container = document.getElementById('paymentDistribution');
    let html = '';
    
    // Add payment method rows
    Object.entries(paymentData).forEach(([method, amount]) => {
        if (amount > 0) {
            const icons = {
                cash: 'money-bill-wave',
                upi: 'mobile-alt',
                bank: 'university',
                cheque: 'money-check',
                credit: 'credit-card',
                points: 'gift'
            };
            
            const colors = {
                cash: 'text-dark',
                upi: 'text-primary',
                bank: 'text-info',
                cheque: 'text-warning',
                credit: 'text-danger',
                points: 'text-success'
            };
            
            html += `
                <div class="distribution-row">
                    <span>
                        <i class="fas fa-${icons[method]} me-1 ${colors[method]}"></i>
                        ${method.charAt(0).toUpperCase() + method.slice(1)}:
                    </span>
                    <span class="fw-bold">₹${amount.toFixed(2)}</span>
                </div>
            `;
        }
    });
    
    // Add summary rows
    html += `
        <hr class="my-2">
        <div class="distribution-row fw-bold">
            <span>Total Paid:</span>
            <span class="text-primary">₹${totalPaid.toFixed(2)}</span>
        </div>
        <div class="distribution-row">
            <span>Bill Amount:</span>
            <span>₹${grandTotal.toFixed(2)}</span>
        </div>
    `;
    
    if (changeGiven > 0) {
        html += `
            <div class="distribution-row text-success">
                <span><i class="fas fa-hand-holding-usd me-1"></i>Change to Give:</span>
                <span class="fw-bold">₹${changeGiven.toFixed(2)}</span>
            </div>
        `;
    }
    
    if (pendingAmount > 0) {
        html += `
            <div class="distribution-row text-warning">
                <span><i class="fas fa-exclamation-triangle me-1"></i>Pending Amount:</span>
                <span class="fw-bold">₹${pendingAmount.toFixed(2)}</span>
            </div>
        `;
    }
    
    container.innerHTML = html;
}

function autoFillRemainingAmount() {
    const totals = calculateTotals();
    const grandTotal = totals.grandTotal;
    
    if (grandTotal === 0) {
        showToast('No bill amount to fill', 'warning');
        return;
    }
    
    // Calculate already paid amount
    let alreadyPaid = 0;
    ACTIVE_PAYMENT_METHODS.forEach(method => {
        const amountInput = document.getElementById(`${method}-amount`);
        if (amountInput) {
            alreadyPaid += parseFloat(amountInput.value) || 0;
        }
    });
    
    const remaining = grandTotal - alreadyPaid;
    
    if (remaining <= 0) {
        showToast('Payment already complete or exceeded', 'info');
        return;
    }
    
    // Try to fill the first active payment method
    for (const method of ACTIVE_PAYMENT_METHODS) {
        if (method !== 'points') { // Don't auto-fill points
            const amountInput = document.getElementById(`${method}-amount`);
            if (amountInput && parseFloat(amountInput.value) === 0) {
                amountInput.value = remaining.toFixed(2);
                amountInput.dispatchEvent(new Event('input'));
                showToast(`Auto-filled ₹${remaining.toFixed(2)} to ${method.toUpperCase()}`, 'info');
                return;
            }
        }
    }
    
    // If all active methods already have amounts, add to cash
    if (ACTIVE_PAYMENT_METHODS.has('cash')) {
        const cashInput = document.getElementById('cash-amount');
        const current = parseFloat(cashInput.value) || 0;
        cashInput.value = (current + remaining).toFixed(2);
        cashInput.dispatchEvent(new Event('input'));
        showToast(`Added ₹${remaining.toFixed(2)} to cash`, 'info');
    } else {
        showToast('Please enable cash payment method', 'warning');
    }
}

// ==================== CUSTOMER FUNCTIONS ====================

function handleCustomerSelection() {
    const selectedOption = this.options[this.selectedIndex];
    
    if (selectedOption.value) {
        try {
            const customerData = JSON.parse(selectedOption.dataset.customer);
            if (customerData) {
                // Update customer fields
                document.getElementById('customer-name').value = customerData.name || '';
                document.getElementById('customer-address').value = customerData.address || '';
                document.getElementById('customer-gstin').value = customerData.gstin || '';
                
                // Set current customer ID
                CURRENT_CUSTOMER_ID = customerData.id;
                
                // Load customer points
                loadCustomerPoints(customerData.id);
            }
        } catch (e) {
            console.error('Error parsing customer data:', e);
        }
    } else {
        // Reset to walk-in customer
        document.getElementById('customer-name').value = 'Walk-in Customer';
        document.getElementById('customer-address').value = '';
        document.getElementById('customer-gstin').value = '';
        CURRENT_CUSTOMER_ID = null;
        hideLoyaltyPoints();
    }
}

function updateCustomer() {
    const name = document.getElementById('customer-name').value.trim();
    const phone = document.getElementById('customer-contact').value.trim();
    
    if (name && phone && name !== 'Walk-in Customer') {
        // Check if customer exists, if not create new
        createOrUpdateCustomer(name, phone);
    }
}

async function createOrUpdateCustomer(name, phone) {
    try {
        const response = await fetch('api/save_customer.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                name: name,
                phone: phone,
                address: document.getElementById('customer-address').value,
                gstin: document.getElementById('customer-gstin').value
            })
        });
        
        const data = await response.json();
        if (data.success && data.customer_id) {
            CURRENT_CUSTOMER_ID = data.customer_id;
            showToast('Customer information saved', 'success');
            
            // Reload customers to include new one
            loadCustomers();
        }
    } catch (error) {
        console.error('Error saving customer:', error);
    }
}

async function loadCustomerPoints(customerId) {
    try {
        const response = await fetch(`api/get_customer_points.php?customer_id=${customerId}`);
        const data = await response.json();
        
        if (data.success) {
            CUSTOMER_POINTS = data.points;
            updateLoyaltyPointsDisplay();
        } else {
            hideLoyaltyPoints();
        }
    } catch (error) {
        console.error('Error loading customer points:', error);
        hideLoyaltyPoints();
    }
}

function updateLoyaltyPointsDisplay() {
    const pointsDisplay = document.getElementById('customerPointsDisplay');
    const applyButton = document.getElementById('btnShowPointsDetails');
    
    pointsDisplay.textContent = CUSTOMER_POINTS.available_points;
    
    if (CUSTOMER_POINTS.available_points >= LOYALTY_SETTINGS.min_points_to_redeem) {
        applyButton.disabled = false;
        applyButton.title = `Apply points (₹${(CUSTOMER_POINTS.available_points * LOYALTY_SETTINGS.redeem_value_per_point).toFixed(2)} discount available)`;
    } else {
        applyButton.disabled = true;
        applyButton.title = `Minimum ${LOYALTY_SETTINGS.min_points_to_redeem} points required`;
    }
}

function hideLoyaltyPoints() {
    CUSTOMER_POINTS = { available_points: 0, total_points_earned: 0, total_points_redeemed: 0 };
    LOYALTY_POINTS_DISCOUNT = 0;
    POINTS_USED = 0;
    
    document.getElementById('customerPointsDisplay').textContent = '0';
    document.getElementById('btnShowPointsDetails').disabled = true;
    document.getElementById('points-discount-value').value = '₹0.00';
    document.getElementById('points-amount').value = '0';
    
    updateBillingSummary();
}

// ==================== INVOICE FUNCTIONS ====================

function generateInvoiceNumber() {
    const prefix = GST_TYPE === 'gst' ? 'INV' : 'INVNG';
    const date = document.getElementById('invoice-date').value.replace(/-/g, '');
    const randomNum = Math.floor(1000 + Math.random() * 9000);
    const invoiceNumber = `${prefix}${date.slice(2)}${randomNum}`;
    document.getElementById('invoice-number').value = invoiceNumber;
}

async function generateBill() {
    if (CART.length === 0) {
        showToast('Add items to cart first', 'warning');
        return;
    }
    
    const customerName = document.getElementById('customer-name').value.trim();
    if (!customerName) {
        showToast('Customer name is required', 'warning');
        return;
    }
    
    // Validate stock
    for (const item of CART) {
        const product = findProductById(item.id);
        if (product && product.shop_stock < item.actual_quantity) {
            showToast(`Insufficient stock for ${item.name}. Available: ${product.shop_stock}`, 'warning');
            return;
        }
    }
    
    const totals = calculateTotals();
    
    // Collect payment data
    const paymentData = {};
    let totalPaid = 0;
    
    ACTIVE_PAYMENT_METHODS.forEach(method => {
        const amountInput = document.getElementById(`${method}-amount`);
        if (amountInput) {
            const amount = parseFloat(amountInput.value) || 0;
            paymentData[method] = amount;
            totalPaid += amount;
        }
    });
    
    // Add points payment
    const pointsDiscount = LOYALTY_POINTS_DISCOUNT;
    if (pointsDiscount > 0) {
        paymentData.points = pointsDiscount;
        totalPaid += pointsDiscount;
    }
    
    // Validate payment
    if (totalPaid < totals.grandTotal) {
        const pending = totals.grandTotal - totalPaid;
        showToast(`Insufficient payment. Pending: ₹${pending.toFixed(2)}`, 'warning');
        return;
    }
    
    // Prepare invoice data
    const invoiceData = {
        invoice_number: document.getElementById('invoice-number').value,
        invoice_type: GST_TYPE,
        invoice_date: document.getElementById('invoice-date').value,
        customer_id: CURRENT_CUSTOMER_ID,
        customer_name: customerName,
        customer_phone: document.getElementById('customer-contact').value,
        customer_address: document.getElementById('customer-address').value,
        customer_gstin: document.getElementById('customer-gstin').value,
        price_type: GLOBAL_PRICE_TYPE,
        referral_id: SELECTED_REFERRAL_ID,
        points_used: POINTS_USED,
        points_discount: LOYALTY_POINTS_DISCOUNT,
        items: CART.map(item => ({
            product_id: item.id,
            product_name: item.name,
            product_code: item.code,
            batch: item.batch,
            quantity: item.quantity,
            actual_quantity: item.actual_quantity,
            unit: item.unit,
            price: item.price,
            price_type: item.price_type,
            discount: item.discount_value,
            mrp: item.mrp,
            hsn_code: item.hsn_code,
            cgst_rate: item.cgst_rate,
            sgst_rate: item.sgst_rate,
            igst_rate: item.igst_rate,
            stock_price: item.stock_price,
            retail_price: item.retail_price,
            wholesale_price: item.wholesale_price
        })),
        totals: totals,
        payment: paymentData,
        additional_discount: {
            value: parseFloat(document.getElementById('additional-dis').value) || 0,
            type: document.getElementById('discount-type').value
        },
        payment_references: {
            upi_reference: document.getElementById('upi-reference').value,
            bank_reference: document.getElementById('bank-reference').value,
            cheque_number: document.getElementById('cheque-number').value,
            credit_reference: document.getElementById('credit-reference').value
        }
    };
    
    // Check if online
    if (navigator.onLine) {
        await saveInvoiceOnline(invoiceData);
    } else {
        await saveInvoiceOffline(invoiceData);
    }
}

async function saveInvoiceOnline(invoiceData) {
    try {
        const response = await fetch('api/save_invoice.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(invoiceData)
        });
        
        const data = await response.json();
        
        if (data.success) {
            // Update stock
            await updateStockAfterSale();
            
            // Print bill
            printBill(invoiceData, data.invoice_id);
            
            // Reset form
            resetForm();
            
            showToast('Invoice saved successfully!', 'success');
        } else {
            showToast('Error: ' + (data.message || 'Unknown error'), 'danger');
        }
    } catch (error) {
        console.error('Error saving invoice:', error);
        showToast('Failed to save invoice online. Saving offline...', 'warning');
        await saveInvoiceOffline(invoiceData);
    }
}

async function saveInvoiceOffline(invoiceData) {
    try {
        // Add offline ID and timestamp
        invoiceData.offline_id = 'offline_' + Date.now();
        invoiceData.created_at = new Date().toISOString();
        invoiceData.status = 'pending';
        
        // Save to local storage
        let pendingInvoices = JSON.parse(localStorage.getItem('pending_invoices') || '[]');
        pendingInvoices.push(invoiceData);
        localStorage.setItem('pending_invoices', JSON.stringify(pendingInvoices));
        
        // Update offline count
        updateOfflineCount();
        
        // Reset form
        resetForm();
        
        showToast('Invoice saved offline. Will sync when online.', 'info');
    } catch (error) {
        console.error('Error saving invoice offline:', error);
        showToast('Failed to save invoice', 'danger');
    }
}

async function updateStockAfterSale() {
    try {
        const stockUpdates = CART.map(item => ({
            product_id: item.id,
            quantity: item.actual_quantity
        }));
        
        const response = await fetch('api/update_stock.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ items: stockUpdates })
        });
        
        const data = await response.json();
        if (data.success) {
            // Reload products to update stock
            loadProducts();
        }
    } catch (error) {
        console.error('Error updating stock:', error);
    }
}

function printBill(invoiceData, invoiceId = null) {
    const printWindow = window.open('', '_blank');
    
    // Format items for printing
    const itemsHtml = invoiceData.items.map((item, index) => `
        <tr>
            <td>${index + 1}</td>
            <td>${item.product_name} (${item.product_code})</td>
            <td class="text-center">${item.quantity} ${item.unit}</td>
            <td class="text-end">₹${item.price.toFixed(2)}</td>
            <td class="text-end">${item.discount}%</td>
            <td class="text-end">₹${(item.price * item.quantity * (1 - item.discount/100)).toFixed(2)}</td>
        </tr>
    `).join('');
    
    // Format payment details
    let paymentDetails = '';
    Object.entries(invoiceData.payment).forEach(([method, amount]) => {
        if (amount > 0) {
            paymentDetails += `
                <div class="payment-row">
                    <span>${method.charAt(0).toUpperCase() + method.slice(1)}:</span>
                    <span>₹${amount.toFixed(2)}</span>
                </div>
            `;
        }
    });
    
    printWindow.document.write(`
        <!DOCTYPE html>
        <html>
        <head>
            <title>Invoice ${invoiceData.invoice_number}</title>
            <style>
                body { font-family: Arial, sans-serif; padding: 20px; max-width: 800px; margin: 0 auto; }
                .header { text-align: center; margin-bottom: 30px; border-bottom: 2px solid #333; padding-bottom: 20px; }
                .company-info { margin-bottom: 10px; }
                .invoice-details { margin: 20px 0; }
                .customer-details { margin-bottom: 20px; background: #f8f9fa; padding: 15px; border-radius: 5px; }
                table { width: 100%; border-collapse: collapse; margin: 20px 0; }
                th, td { border: 1px solid #ddd; padding: 10px; text-align: left; }
                th { background-color: #f2f2f2; font-weight: bold; }
                .text-end { text-align: right; }
                .text-center { text-align: center; }
                .summary { float: right; width: 300px; margin-top: 20px; }
                .summary-row { display: flex; justify-content: space-between; padding: 5px 0; }
                .summary-row.total { font-weight: bold; font-size: 1.2em; border-top: 2px solid #333; margin-top: 10px; padding-top: 10px; }
                .payment-details { margin-top: 30px; background: #e9ecef; padding: 15px; border-radius: 5px; }
                .payment-row { display: flex; justify-content: space-between; padding: 3px 0; }
                .footer { margin-top: 50px; text-align: center; font-size: 0.9em; color: #666; }
                @media print {
                    .no-print { display: none; }
                    body { padding: 0; }
                }
            </style>
        </head>
        <body>
            <div class="header">
                <h1>INVOICE</h1>
                <div class="company-info">
                    <h3>Your Company Name</h3>
                    <p>Company Address • Phone: +91 XXXXX XXXXX • GSTIN: XXXXXXXX</p>
                </div>
            </div>
            
            <div class="invoice-details">
                <div class="row">
                    <div class="col">
                        <strong>Invoice No:</strong> ${invoiceData.invoice_number}<br>
                        <strong>Date:</strong> ${invoiceData.invoice_date}<br>
                        <strong>Invoice Type:</strong> ${invoiceData.invoice_type === 'gst' ? 'GST' : 'Non-GST'}
                    </div>
                    <div class="col text-end">
                        ${invoiceId ? `<strong>Invoice ID:</strong> ${invoiceId}<br>` : ''}
                        <strong>Generated:</strong> ${new Date().toLocaleString()}
                    </div>
                </div>
            </div>
            
            <div class="customer-details">
                <h4>Customer Details</h4>
                <p><strong>Name:</strong> ${invoiceData.customer_name}</p>
                ${invoiceData.customer_phone ? `<p><strong>Phone:</strong> ${invoiceData.customer_phone}</p>` : ''}
                ${invoiceData.customer_address ? `<p><strong>Address:</strong> ${invoiceData.customer_address}</p>` : ''}
                ${invoiceData.customer_gstin ? `<p><strong>GSTIN:</strong> ${invoiceData.customer_gstin}</p>` : ''}
            </div>
            
            <table>
                <thead>
                    <tr>
                        <th width="5%">#</th>
                        <th width="35%">Product Description</th>
                        <th width="10%" class="text-center">Qty</th>
                        <th width="10%" class="text-end">Rate</th>
                        <th width="10%" class="text-end">Disc%</th>
                        <th width="15%" class="text-end">Amount</th>
                    </tr>
                </thead>
                <tbody>
                    ${itemsHtml}
                </tbody>
            </table>
            
            <div class="summary">
                <div class="summary-row">
                    <span>Sub Total:</span>
                    <span>₹${invoiceData.totals.subtotal.toFixed(2)}</span>
                </div>
                ${invoiceData.totals.totalItemDiscount > 0 ? `
                <div class="summary-row">
                    <span>Item Discount:</span>
                    <span>-₹${invoiceData.totals.totalItemDiscount.toFixed(2)}</span>
                </div>
                ` : ''}
                ${invoiceData.totals.overallDiscount > 0 ? `
                <div class="summary-row">
                    <span>Overall Discount:</span>
                    <span>-₹${invoiceData.totals.overallDiscount.toFixed(2)}</span>
                </div>
                ` : ''}
                ${invoiceData.totals.pointsDiscount > 0 ? `
                <div class="summary-row">
                    <span>Points Discount:</span>
                    <span>-₹${invoiceData.totals.pointsDiscount.toFixed(2)}</span>
                </div>
                ` : ''}
                ${invoiceData.totals.totalGST > 0 ? `
                <div class="summary-row">
                    <span>GST:</span>
                    <span>₹${invoiceData.totals.totalGST.toFixed(2)}</span>
                </div>
                ` : ''}
                <div class="summary-row total">
                    <span>GRAND TOTAL:</span>
                    <span>₹${invoiceData.totals.grandTotal.toFixed(2)}</span>
                </div>
            </div>
            
            <div class="payment-details">
                <h4>Payment Details</h4>
                ${paymentDetails}
                <div class="payment-row" style="font-weight: bold; border-top: 1px solid #ccc; padding-top: 5px;">
                    <span>Total Paid:</span>
                    <span>₹${Object.values(invoiceData.payment).reduce((a, b) => a + b, 0).toFixed(2)}</span>
                </div>
                <div class="payment-row">
                    <span>Change Given:</span>
                    <span>₹${(Object.values(invoiceData.payment).reduce((a, b) => a + b, 0) - invoiceData.totals.grandTotal).toFixed(2)}</span>
                </div>
            </div>
            
            <div class="footer">
                <p>Thank you for your business!</p>
                <p>This is a computer generated invoice.</p>
            </div>
            
            <div class="no-print" style="margin-top: 20px; text-align: center;">
                <button onclick="window.print()" style="padding: 10px 20px; background: #007bff; color: white; border: none; border-radius: 5px; cursor: pointer;">
                    Print Invoice
                </button>
                <button onclick="window.close()" style="padding: 10px 20px; background: #6c757d; color: white; border: none; border-radius: 5px; cursor: pointer; margin-left: 10px;">
                    Close Window
                </button>
            </div>
            
            <script>
                window.onload = function() {
                    window.print();
                }
            </script>
        </body>
        </html>
    `);
    
    printWindow.document.close();
}

function resetForm() {
    // Clear cart
    CART = [];
    renderCart();
    
    // Reset customer to walk-in
    document.getElementById('customer-name').value = 'Walk-in Customer';
    $('#customer-contact').val('').trigger('change');
    document.getElementById('customer-address').value = '';
    document.getElementById('customer-gstin').value = '';
    
    // Reset discount
    document.getElementById('additional-dis').value = '0';
    
    // Reset payment methods (only cash checked)
    document.querySelectorAll('input[name="payment-method"]').forEach(checkbox => {
        checkbox.checked = checkbox.value === 'cash';
    });
    
    // Reset payment amounts
    document.querySelectorAll('.payment-amount').forEach(input => {
        input.value = '0';
    });
    
    document.getElementById('points-amount').value = '0';
    document.getElementById('points-discount-value').value = '₹0.00';
    
    // Reset payment references
    document.getElementById('upi-reference').value = '';
    document.getElementById('bank-reference').value = '';
    document.getElementById('cheque-number').value = '';
    document.getElementById('credit-reference').value = '';
    
    // Reset payment cards
    document.querySelectorAll('.payment-input-card').forEach(card => {
        card.classList.remove('active');
    });
    document.getElementById('cash-input-card').classList.add('active');
    
    ACTIVE_PAYMENT_METHODS = new Set(['cash']);
    
    // Reset loyalty points
    hideLoyaltyPoints();
    
    // Generate new invoice number
    generateInvoiceNumber();
    
    // Update billing summary
    updateBillingSummary();
    
    // Focus on barcode input
    document.getElementById('barcode-input').focus();
}

// ==================== OFFLINE SYNC FUNCTIONS ====================

function checkOfflineStatus() {
    if (!navigator.onLine) {
        showToast('You are offline. Invoices will be saved locally.', 'warning');
    }
    updateOfflineCount();
}

function handleOnlineStatus() {
    showToast('You are back online', 'success');
    syncOfflineInvoices();
}

function handleOfflineStatus() {
    showToast('You are offline. Invoices will be saved locally.', 'warning');
}

function updateOfflineCount() {
    const pendingInvoices = JSON.parse(localStorage.getItem('pending_invoices') || '[]');
    document.getElementById('offlineCount').textContent = pendingInvoices.length;
}

async function syncOfflineInvoices() {
    if (!navigator.onLine) {
        showToast('Cannot sync while offline', 'warning');
        return;
    }
    
    const pendingInvoices = JSON.parse(localStorage.getItem('pending_invoices') || '[]');
    if (pendingInvoices.length === 0) {
        showToast('No pending invoices to sync', 'info');
        return;
    }
    
    showToast(`Syncing ${pendingInvoices.length} pending invoices...`, 'info');
    
    let successCount = 0;
    let errorCount = 0;
    
    for (const invoice of pendingInvoices) {
        try {
            const response = await fetch('api/sync_invoice.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(invoice)
            });
            
            const data = await response.json();
            
            if (data.success) {
                successCount++;
                
                // Update stock for successful invoice
                await updateStockForSyncedInvoice(invoice);
            } else {
                errorCount++;
                console.error('Failed to sync invoice:', data.message);
            }
        } catch (error) {
            errorCount++;
            console.error('Error syncing invoice:', error);
        }
    }
    
    // Remove successfully synced invoices
    if (successCount > 0) {
        const remainingInvoices = pendingInvoices.slice(successCount);
        localStorage.setItem('pending_invoices', JSON.stringify(remainingInvoices));
        updateOfflineCount();
    }
    
    // Show summary
    if (successCount > 0) {
        showToast(`Successfully synced ${successCount} invoice(s)`, 'success');
    }
    if (errorCount > 0) {
        showToast(`Failed to sync ${errorCount} invoice(s)`, 'danger');
    }
}

async function updateStockForSyncedInvoice(invoice) {
    try {
        const stockUpdates = invoice.items.map(item => ({
            product_id: item.product_id,
            quantity: item.actual_quantity || item.quantity
        }));
        
        await fetch('api/update_stock.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ items: stockUpdates })
        });
        
        // Reload products to update stock display
        loadProducts();
    } catch (error) {
        console.error('Error updating stock for synced invoice:', error);
    }
}

function loadOfflineInvoices() {
    const pendingInvoices = JSON.parse(localStorage.getItem('pending_invoices') || '[]');
    PENDING_OFFLINE_INVOICES = pendingInvoices;
    updateOfflineCount();
}

// ==================== PROFIT ANALYSIS FUNCTIONS ====================

function showProfitAnalysis() {
    if (CART.length === 0) {
        showToast('Add items to cart to see profit analysis', 'warning');
        return;
    }
    
    const tbody = document.getElementById('profitAnalysisBody');
    tbody.innerHTML = '';
    
    let totalActualProfit = 0;
    let totalMarketProfit = 0;
    
    CART.forEach((item, index) => {
        const profit = calculateItemProfit(item);
        totalActualProfit += profit.actual;
        
        // Calculate market profit (based on current retail price)
        const marketProfit = (item.retail_price - item.stock_price) * item.actual_quantity;
        totalMarketProfit += marketProfit;
        
        const row = document.createElement('tr');
        row.innerHTML = `
            <td>${index + 1}</td>
            <td>
                <strong>${item.name}</strong><br>
                <small class="text-muted">${item.code}</small>
            </td>
            <td class="text-end">₹${item.stock_price.toFixed(2)}</td>
            <td class="text-end">₹${item.price.toFixed(2)}</td>
            <td class="text-center">${item.quantity} ${item.unit}</td>
            <td class="text-end ${profit.actual >= 0 ? 'text-success' : 'text-danger'}">
                ₹${profit.actual.toFixed(2)}
            </td>
            <td class="text-end ${marketProfit >= 0 ? 'text-success' : 'text-danger'}">
                ₹${marketProfit.toFixed(2)}
            </td>
            <td class="text-center">
                <span class="badge ${item.price_type === 'retail' ? 'bg-info' : 'bg-success'}">
                    ${item.price_type}
                </span>
            </td>
            <td class="text-center">
                <button class="btn btn-sm btn-outline-primary" onclick="changeItemPriceType(${index})">
                    <i class="fas fa-exchange-alt"></i>
                </button>
            </td>
        `;
        tbody.appendChild(row);
    });
    
    // Update totals
    document.getElementById('totalActualProfit').textContent = `₹${totalActualProfit.toFixed(2)}`;
    document.getElementById('totalMarketProfit').textContent = `₹${totalMarketProfit.toFixed(2)}`;
    document.getElementById('summaryActualProfit').textContent = `₹${totalActualProfit.toFixed(2)}`;
    document.getElementById('summaryMarketProfit').textContent = `₹${totalMarketProfit.toFixed(2)}`;
    
    // Show modal
    const modal = new bootstrap.Modal(document.getElementById('profitAnalysisModal'));
    modal.show();
}

function changeItemPriceType(index) {
    if (CART[index]) {
        const currentType = CART[index].price_type;
        const newType = currentType === 'retail' ? 'wholesale' : 'retail';
        
        if (confirm(`Change ${CART[index].name} from ${currentType} to ${newType} pricing?`)) {
            updateCartItemPriceType(index, newType);
            showProfitAnalysis(); // Refresh profit analysis
        }
    }
}

function downloadProfitReport() {
    const profitData = CART.map((item, index) => {
        const profit = calculateItemProfit(item);
        const marketProfit = (item.retail_price - item.stock_price) * item.actual_quantity;
        
        return {
            '#': index + 1,
            'Product': item.name,
            'Code': item.code,
            'Cost Price': `₹${item.stock_price.toFixed(2)}`,
            'Selling Price': `₹${item.price.toFixed(2)}`,
            'Quantity': `${item.quantity} ${item.unit}`,
            'Actual Profit': `₹${profit.actual.toFixed(2)}`,
            'Market Profit': `₹${marketProfit.toFixed(2)}`,
            'Price Type': item.price_type
        };
    });
    
    const totals = calculateTotals();
    const summary = {
        'Total Actual Profit': `₹${totals.totalActualProfit.toFixed(2)}`,
        'Total Market Profit': `₹${(totals.totalActualProfit * 1.1).toFixed(2)}`, // Example calculation
        'Grand Total': `₹${totals.grandTotal.toFixed(2)}`,
        'Items Count': CART.length
    };
    
    // Create CSV content
    let csv = 'Profit Analysis Report\n\n';
    csv += 'Item Details:\n';
    
    // Headers
    const headers = Object.keys(profitData[0] || {});
    csv += headers.join(',') + '\n';
    
    // Data rows
    profitData.forEach(row => {
        csv += headers.map(header => row[header]).join(',') + '\n';
    });
    
    csv += '\nSummary:\n';
    Object.entries(summary).forEach(([key, value]) => {
        csv += `${key},${value}\n`;
    });
    
    // Create download link
    const blob = new Blob([csv], { type: 'text/csv' });
    const url = window.URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `profit_report_${new Date().toISOString().slice(0, 10)}.csv`;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    window.URL.revokeObjectURL(url);
    
    showToast('Profit report downloaded', 'success');
}

// ==================== UTILITY FUNCTIONS ====================

function findProductById(id) {
    return PRODUCTS.find(p => p.id == id);
}

function findProductByBarcode(code) {
    const prodId = BARCODE_MAP[String(code).trim()];
    if (prodId) return findProductById(prodId);
    
    return PRODUCTS.find(p => 
        p.code === String(code).trim() || 
        p.barcode === String(code).trim()
    );
}

function updateProductStockDisplay(productId, stock) {
    const option = document.querySelector(`#search-product option[value="${productId}"]`);
    if (option) {
        const text = option.textContent;
        // Remove existing stock info
        const baseText = text.replace(/\([\d.]+ left\)/, '').trim();
        
        let newText = baseText;
        if (stock <= 0) {
            newText += ' (Out of stock)';
        } else if (stock <= 10) {
            newText += ` (${stock} left)`;
        }
        
        option.textContent = newText;
    }
}

function showToast(message, type = 'success') {
    const toastContainer = document.getElementById('toastContainer');
    const toastId = 'toast-' + Date.now();
    
    const iconMap = {
        'success': 'fas fa-check-circle text-success',
        'info': 'fas fa-info-circle text-info',
        'warning': 'fas fa-exclamation-triangle text-warning',
        'danger': 'fas fa-exclamation-circle text-danger'
    };
    
    const toastHTML = `
        <div id="${toastId}" class="toast custom-toast align-items-center border-0 bg-white shadow-sm mb-2" role="alert">
            <div class="d-flex">
                <div class="toast-body d-flex align-items-center">
                    <i class="${iconMap[type]} me-2 fs-5"></i>
                    <span class="flex-grow-1">${message}</span>
                    <button type="button" class="btn-close btn-close-sm ms-2" data-bs-dismiss="toast"></button>
                </div>
            </div>
        </div>
    `;
    
    toastContainer.insertAdjacentHTML('afterbegin', toastHTML);
    
    const toastElement = document.getElementById(toastId);
    const toast = new bootstrap.Toast(toastElement, {
        autohide: true,
        delay: 3000
    });
    toast.show();
    
    toastElement.querySelector('.btn-close').addEventListener('click', function() {
        toast.hide();
    });
    
    toastElement.addEventListener('hidden.bs.toast', function() {
        if (toastElement.parentNode) {
            toastElement.remove();
        }
    });
}

// Note: The following functions need to be implemented in your backend API:
// - get_products.php
// - get_customers.php
// - get_referrals.php
// - get_customer_points.php
// - get_loyalty_settings.php
// - save_customer.php
// - save_invoice.php
// - update_stock.php
// - sync_invoice.php
// - save_hold_invoice.php
// - get_hold_invoices.php
// - restore_invoice.php
// - delete_hold_invoice.php
// - save_quotation.php

// The modal functions (showPointsModal, holdInvoice, etc.) from the original template
// should be integrated similarly to the profit analysis modal.