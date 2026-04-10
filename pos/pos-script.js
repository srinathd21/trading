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
  total_points_redeemed: 0,
};

let GLOBAL_PRICE_TYPE = "retail";
let GST_TYPE = "gst";
let ACTIVE_PAYMENT_METHODS = new Set(["cash"]);
let SELECTED_REFERRAL_ID = null;
let CURRENT_CUSTOMER_ID = null;
let LOYALTY_POINTS_DISCOUNT = 0;
let POINTS_USED = 0;
let PENDING_CONFIRMATION = null;
let IS_INITIALIZED = false;
let CURRENT_PRODUCT = null;
let CURRENT_UNIT_IS_SECONDARY = false;
let IS_CART_LOADED = false;
let CREDIT_DUE_DATE = null;
let CREDIT_DUE_DAYS = 30;
let CURRENT_PRODUCT_FILTER = "all";
let TRANSPORT_DETAILS = {
  type: "",
  charge: 0,
};
let SHIPPING_DETAILS = {
  name: "",
  contact: "",
  gstin: "",
  address: "",
  vehicle_number: "",
  charges: 0,
};

// ==================== INITIALIZATION ====================
document.addEventListener("DOMContentLoaded", function () {
  console.log("POS System: Initializing...");
  try {
    initializeApp();
    setupEventListeners();
    loadInitialData();
    loadCartFromSession();
    loadShippingDetailsFromSession();
    loadTransportDetailsFromSession();

    setTimeout(() => {
      addProfitButtonToFixedBottom();
      initGstFilter();
      initTransportSection();
      initShippingModal();
    }, 500);
  } catch (error) {
    console.error("POS System: Initialization failed:", error);
    showToast(
      "System initialization failed. Please refresh the page.",
      "danger",
    );
  }

  const creditDueDateInput = document.getElementById("credit-due-date");
  if (creditDueDateInput) {
    creditDueDateInput.addEventListener("change", function () {
      CREDIT_DUE_DATE = this.value;
      updatePaymentSummary();
    });
  }

  loadCreditSettings();
});

// Add CSS for new features
const style = document.createElement("style");
style.textContent = `
    .category-badge { display: inline-block; padding: 2px 8px; border-radius: 12px; font-size: 0.8em; font-weight: 600; margin-right: 4px; }
    .subcategory-badge { display: inline-block; padding: 2px 8px; border-radius: 12px; font-size: 0.8em; font-weight: 500; background-color: #e3f2fd; color: #0d47a1; }
    .stock-high { background-color: #c8e6c9; color: #1b5e20; padding: 2px 8px; border-radius: 12px; font-weight: 600; }
    .stock-medium { background-color: #e8f5e9; color: #2e7d32; padding: 2px 8px; border-radius: 12px; font-weight: 600; }
    .stock-low { background-color: #fff3e0; color: #ef6c00; padding: 2px 8px; border-radius: 12px; font-weight: 600; }
    .stock-out { background-color: #ffebee; color: #c62828; padding: 2px 8px; border-radius: 12px; font-weight: 600; }
    .price-tag { background-color: #e8f5e9; color: #2e7d32; padding: 2px 8px; border-radius: 12px; font-weight: 700; }
    .product-code { color: #6c757d; font-size: 0.85em; font-family: monospace; }
    .cart-category-badge { font-size: 0.75em; padding: 1px 6px; border-radius: 10px; background-color: #f8f9fa; color: #495057; }
    .gst-filter-section { display: flex; align-items: center; gap: 10px; margin-bottom: 10px; padding: 8px 12px; background: #f8f9fa; border-radius: 8px; border: 1px solid #e9ecef; }
    .gst-filter-section label { margin-bottom: 0; font-size: 12px; font-weight: 600; color: #495057; }
    .toggle-switch { position: relative; display: inline-block; width: 50px; height: 24px; }
    .toggle-switch input { opacity: 0; width: 0; height: 0; }
    .toggle-slider { position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0; background-color: #ccc; transition: 0.3s; border-radius: 24px; }
    .toggle-slider:before { position: absolute; content: ""; height: 18px; width: 18px; left: 3px; bottom: 3px; background-color: white; transition: 0.3s; border-radius: 50%; }
    input:checked + .toggle-slider { background-color: #28a745; }
    input:checked + .toggle-slider:before { transform: translateX(26px); }
    .filter-badge { font-size: 11px; padding: 4px 10px; border-radius: 20px; background: #e9ecef; color: #495057; cursor: pointer; transition: all 0.2s; }
    .filter-badge.active-gst { background: #28a745; color: white; }
    .filter-badge.active-non-gst { background: #dc3545; color: white; }
    .filter-badge.all { background: #17a2b8; color: white; }
    .transport-section { background: #f8f9fa; border-radius: 8px; padding: 12px; margin-top: 10px; border: 1px solid #e9ecef; display: none; }
    .transport-section.show { display: block; animation: fadeIn 0.3s ease; }
    .transport-row { display: flex; flex-wrap: wrap; gap: 12px; align-items: flex-end; }
    .transport-row > div { flex: 1; min-width: 150px; }
    .transport-row label { font-size: 11px; font-weight: 600; margin-bottom: 4px; color: #495057; display: block; }
    .transport-row input { width: 100%; padding: 6px 10px; border: 1px solid #ced4da; border-radius: 4px; font-size: 12px; }
    .transport-badge { background: #17a2b8; color: white; padding: 3px 10px; border-radius: 20px; font-size: 11px; display: inline-flex; align-items: center; gap: 5px; }
    .stock-type-badge { font-size: 0.7rem; padding: 2px 6px; border-radius: 10px; margin-left: 5px; font-weight: 600; }
    .old-stock-badge { background-color: #f39c12; color: white; }
    .new-stock-badge { background-color: #3498db; color: white; }
    .stock-warning { border-left: 4px solid #f39c12; background-color: #fff3cd; padding: 10px; margin-bottom: 15px; border-radius: 4px; }
    @keyframes fadeIn { from { opacity: 0; transform: translateY(-10px); } to { opacity: 1; transform: translateY(0); } }
    `;
document.head.appendChild(style);

// Configure SweetAlert2 defaults
const Toast = Swal.mixin({
  toast: true,
  position: "top-end",
  showConfirmButton: false,
  timer: 1000,
  timerProgressBar: true,
  didOpen: (toast) => {
    toast.addEventListener("mouseenter", Swal.stopTimer);
    toast.addEventListener("mouseleave", Swal.resumeTimer);
  },
});

// Toast functions
function showSuccessToast(message) {
  Toast.fire({ icon: "success", title: message });
}
function showErrorToast(message) {
  Toast.fire({ icon: "error", title: message });
}
function showWarningToast(message) {
  Toast.fire({ icon: "warning", title: message });
}
function showInfoToast(message) {
  Toast.fire({ icon: "info", title: message });
}

// ==================== CREDIT SETTINGS FUNCTIONS ====================
async function loadCreditSettings() {
  try {
    const response = await fetchWithTimeout(
      "api/settings.php?action=get_credit_settings",
      { timeout: 5000 },
    );
    if (response.ok) {
      const data = await response.json();
      if (data.success && data.credit_period_days) {
        CREDIT_DUE_DAYS = parseInt(data.credit_period_days);
        const dueDateInput = document.getElementById("credit-due-date");
        if (dueDateInput) {
          const defaultDueDate = new Date();
          defaultDueDate.setDate(defaultDueDate.getDate() + CREDIT_DUE_DAYS);
          dueDateInput.placeholder = `Due: ${defaultDueDate.toISOString().split("T")[0]}`;
        }
      }
    }
  } catch (error) {
    console.warn("Could not load credit settings:", error);
  }
}

function setupDueDaysListener() {
  const dueDaysSelect = document.getElementById("due_days");
  const dueDateInput = document.getElementById("credit-due-date");
  if (dueDaysSelect && dueDateInput) {
    dueDaysSelect.removeEventListener("change", handleDueDaysChange);
    dueDaysSelect.addEventListener("change", handleDueDaysChange);
    if (dueDaysSelect.value)
      updateDueDateFromDays(parseInt(dueDaysSelect.value), dueDateInput);
  }
}

function handleDueDaysChange(event) {
  const days = parseInt(event.target.value);
  const dueDateInput = document.getElementById("credit-due-date");
  if (days && dueDateInput) {
    updateDueDateFromDays(days, dueDateInput);
    if (typeof showToast === "function")
      showToast(
        `Due date set to ${dueDateInput.value} (${days} days from today)`,
        "info",
      );
    const changeEvent = new Event("change", { bubbles: true });
    dueDateInput.dispatchEvent(changeEvent);
  }
}

function updateDueDateFromDays(days, dueDateInput) {
  if (!days || !dueDateInput) return;
  const today = new Date();
  const dueDate = new Date();
  dueDate.setDate(today.getDate() + days);
  const formattedDate = dueDate.toISOString().split("T")[0];
  dueDateInput.value = formattedDate;
  if (typeof CREDIT_DUE_DATE !== "undefined")
    window.CREDIT_DUE_DATE = formattedDate;
}

function updateCreditPaymentCard() {
  const creditCard = document.getElementById("credit-input-card");
  if (!creditCard) return;
  if (!document.getElementById("credit-due-date")) {
    const dueDateHTML = `<div class="due-date-field mt-2"><label for="credit-due-date" class="form-label" style="font-size: 11px;"><i class="fas fa-calendar-alt me-1"></i> Due Date</label><input type="date" id="credit-due-date" name="credit-due-date" class="form-control form-control-sm" style="font-size: 11px;"><small class="text-muted" style="font-size: 9px;">Payment due date for credit transaction</small></div>`;
    const creditReference = document.getElementById("credit-reference");
    if (creditReference && creditReference.parentNode) {
      creditReference.parentNode.insertAdjacentHTML("beforeend", dueDateHTML);
      const dueDateInput = document.getElementById("credit-due-date");
      if (dueDateInput) {
        const dueDaysSelect = document.getElementById("due_days");
        if (dueDaysSelect && dueDaysSelect.value)
          updateDueDateFromDays(parseInt(dueDaysSelect.value), dueDateInput);
        else {
          const defaultDueDate = new Date();
          defaultDueDate.setDate(defaultDueDate.getDate() + CREDIT_DUE_DAYS);
          dueDateInput.value = defaultDueDate.toISOString().split("T")[0];
        }
        if (typeof CREDIT_DUE_DATE !== "undefined")
          window.CREDIT_DUE_DATE = dueDateInput.value;
      }
      dueDateInput.addEventListener("change", function () {
        if (typeof CREDIT_DUE_DATE !== "undefined")
          window.CREDIT_DUE_DATE = this.value;
        updatePaymentSummary();
        showCreditDueDateWarning();
      });
    }
  }
  setupDueDaysListener();
}

function showCreditDueDateWarning() {
  const creditAmount =
    parseFloat(document.getElementById("credit-amount").value) || 0;
  const dueDate = document.getElementById("credit-due-date")?.value;
  if (creditAmount > 0 && dueDate) {
    const today = new Date();
    const due = new Date(dueDate);
    const daysUntilDue = Math.ceil((due - today) / (1000 * 60 * 60 * 24));
    let warningHTML = "";
    if (daysUntilDue < 0)
      warningHTML = `<div class="credit-due-warning"><i class="fas fa-exclamation-triangle"></i><strong>Overdue!</strong> Payment due date has passed.</div>`;
    else if (daysUntilDue <= 7)
      warningHTML = `<div class="credit-due-warning"><i class="fas fa-clock"></i><strong>Due in ${daysUntilDue} days!</strong> Payment is due soon.</div>`;
    const existingWarning = document.querySelector(
      "#credit-input-card .credit-due-warning",
    );
    if (existingWarning) existingWarning.remove();
    if (warningHTML) {
      const creditCard = document.getElementById("credit-input-card");
      const dueDateField = document.querySelector(
        "#credit-input-card .due-date-field",
      );
      if (dueDateField)
        dueDateField.insertAdjacentHTML("afterend", warningHTML);
    }
  }
}

// ==================== GST FILTER FUNCTIONS ====================
function initGstFilter() {
  const toggle = document.getElementById("gstFilterToggle");
  const filterAllBadge = document.getElementById("filterAllBadge");
  const filterGstBadge = document.getElementById("filterGstBadge");
  const filterNonGstBadge = document.getElementById("filterNonGstBadge");
  const filterStatusText = document.getElementById("filterStatusText");

  function updateFilterBadges(filter) {
    filterAllBadge.classList.remove("all", "active-gst", "active-non-gst");
    filterGstBadge.classList.remove("all", "active-gst", "active-non-gst");
    filterNonGstBadge.classList.remove("all", "active-gst", "active-non-gst");
    filterAllBadge.classList.add("filter-badge");
    filterGstBadge.classList.add("filter-badge");
    filterNonGstBadge.classList.add("filter-badge");
    switch (filter) {
      case "all":
        filterAllBadge.classList.add("all");
        filterStatusText.innerHTML = "All Products";
        break;
      case "gst":
        filterGstBadge.classList.add("active-gst");
        filterStatusText.innerHTML =
          'GST Products <i class="fas fa-file-invoice-dollar ms-1"></i>';
        break;
      case "non-gst":
        filterNonGstBadge.classList.add("active-non-gst");
        filterStatusText.innerHTML =
          'Non-GST Products <i class="fas fa-receipt ms-1"></i>';
        break;
    }
  }

  toggle.addEventListener("change", function () {
    CURRENT_PRODUCT_FILTER = this.checked ? "gst" : "all";
    updateFilterBadges(CURRENT_PRODUCT_FILTER);
    const searchTerm = document.getElementById("search-product").value;
    populateProductDropdownFromSearch(searchTerm);
  });

  filterAllBadge.addEventListener("click", function () {
    toggle.checked = false;
    CURRENT_PRODUCT_FILTER = "all";
    updateFilterBadges("all");
    const searchTerm = document.getElementById("search-product").value;
    populateProductDropdownFromSearch(searchTerm);
  });
  filterGstBadge.addEventListener("click", function () {
    toggle.checked = true;
    CURRENT_PRODUCT_FILTER = "gst";
    updateFilterBadges("gst");
    const searchTerm = document.getElementById("search-product").value;
    populateProductDropdownFromSearch(searchTerm);
  });
  filterNonGstBadge.addEventListener("click", function () {
    toggle.checked = false;
    CURRENT_PRODUCT_FILTER = "non-gst";
    updateFilterBadges("non-gst");
    const searchTerm = document.getElementById("search-product").value;
    populateProductDropdownFromSearch(searchTerm);
  });
}

function filterProductsByGstStatus(products) {
  if (CURRENT_PRODUCT_FILTER === "all") return products;
  return products.filter((product) => {
    const hasHsn = product.hsn_code && product.hsn_code.trim().length > 0;
    if (CURRENT_PRODUCT_FILTER === "gst") return hasHsn;
    if (CURRENT_PRODUCT_FILTER === "non-gst") return !hasHsn;
    return true;
  });
}

// ==================== TRANSPORT FUNCTIONS ====================
function initTransportSection() {
  const toggleBtn = document.getElementById("btnToggleTransport");
  const transportSection = document.getElementById("transportSection");
  const saveTransportBtn = document.getElementById("btnSaveTransport");
  const transportType = document.getElementById("transportType");
  const transportCharge = document.getElementById("transportCharge");

  if (toggleBtn)
    toggleBtn.addEventListener("click", function () {
      transportSection.classList.toggle("show");
    });
  if (saveTransportBtn)
    saveTransportBtn.addEventListener("click", function () {
      TRANSPORT_DETAILS = {
        type: transportType.value.trim(),
        charge: parseFloat(transportCharge.value) || 0,
      };
      saveTransportDetailsToSession();
      updateTransportDisplay();
      if (typeof showToast === "function")
        showToast("Transport details saved", "success");
      updateBillingSummary();
      transportSection.classList.remove("show");
    });
  loadTransportDetailsFromSession();
  updateTransportDisplay();
}

function saveTransportDetailsToSession() {
  try {
    sessionStorage.setItem(
      "pos_transport_details",
      JSON.stringify(TRANSPORT_DETAILS),
    );
  } catch (error) {
    console.error("Error saving transport details to session:", error);
  }
}
function loadTransportDetailsFromSession() {
  try {
    const saved = sessionStorage.getItem("pos_transport_details");
    if (saved) {
      TRANSPORT_DETAILS = JSON.parse(saved);
      document.getElementById("transportType").value =
        TRANSPORT_DETAILS.type || "";
      document.getElementById("transportCharge").value =
        TRANSPORT_DETAILS.charge || 0;
      updateTransportDisplay();
    }
  } catch (error) {
    console.error("Error loading transport details from session:", error);
  }
}

function updateTransportDisplay() {
  const container = document.getElementById("shippingDetailsHorizontal");
  if (!container) return;
  let transportBadge = document.getElementById("transport-badge");
  if (TRANSPORT_DETAILS.type || TRANSPORT_DETAILS.charge > 0) {
    if (!transportBadge) {
      transportBadge = document.createElement("div");
      transportBadge.id = "transport-badge";
      transportBadge.className = "shipping-badge-horizontal transport-badge";
      container.appendChild(transportBadge);
    }
    let transportHtml = '<i class="fas fa-truck-moving"></i>';
    if (TRANSPORT_DETAILS.type)
      transportHtml += ` <span class="badge-label">Transport:</span> <span class="badge-value">${escapeHtml(TRANSPORT_DETAILS.type)}</span>`;
    if (TRANSPORT_DETAILS.charge > 0)
      transportHtml += ` <span class="badge-label">Charge:</span> <span class="badge-value">₹ ${TRANSPORT_DETAILS.charge.toFixed(2)}</span>`;
    transportBadge.innerHTML = transportHtml;
  } else if (transportBadge) transportBadge.remove();
}

function clearTransportDetails() {
  TRANSPORT_DETAILS = { type: "", charge: 0 };
  document.getElementById("transportType").value = "";
  document.getElementById("transportCharge").value = "0";
  saveTransportDetailsToSession();
  updateTransportDisplay();
  updateBillingSummary();
  if (typeof showToast === "function")
    showToast("Transport details cleared", "info");
}

// ==================== INITIALIZE APP ====================
async function initializeApp() {
  console.log("POS System: Initializing...");
  try {
    if (typeof $ === "undefined") throw new Error("jQuery not loaded");
    if (typeof bootstrap === "undefined")
      throw new Error("Bootstrap not loaded");
    const today = new Date().toISOString().split("T")[0];
    document.getElementById("date").value = today;
    const quotationDate = new Date();
    quotationDate.setDate(quotationDate.getDate() + 15);
    document.getElementById("quotationValidUntil").value = quotationDate
      .toISOString()
      .split("T")[0];
    try {
      $("#search-product").select2({
        placeholder: "Search product...",
        allowClear: true,
        width: "100%",
      });
      $("#customer-contact").select2({
        placeholder: "Select or type phone",
        tags: true,
        allowClear: true,
        width: "100%",
      });
      $("#referral").select2({
        placeholder: "Select referral...",
        allowClear: true,
        width: "100%",
      });
    } catch (select2Error) {
      console.warn("Select2 initialization warning:", select2Error);
    }
    generateInvoiceNumber();
    IS_INITIALIZED = true;
    console.log("POS System: Application initialized successfully");
  } catch (error) {
    console.error("POS System: Application initialization error:", error);
    showToast(
      `Initialization error: ${error.message}. Some features may not work.`,
      "danger",
    );
  }
}

async function loadInitialData() {
  console.log("POS System: Loading initial data...");
  if (!IS_INITIALIZED) {
    showToast("System not initialized properly. Please refresh.", "danger");
    return;
  }
  try {
    await loadProducts();
    populateProductDropdownFromSearch();
    setTimeout(() => {
      loadCustomers().catch(() => console.warn("Customers load failed"));
      loadReferrals().catch(() => console.warn("Referrals load failed"));
      loadLoyaltySettings().catch(() =>
        console.warn("Loyalty settings load failed"),
      );
    }, 1000);
    console.log(
      `POS System: Ready with ${PRODUCTS.length} products loaded locally`,
    );
    if (!IS_CART_LOADED)
      showToast("System ready! Products loaded locally.", "success");
  } catch (error) {
    console.error("POS System: Initial data loading failed:", error);
    showToast("Could not load products. Please check connection.", "danger");
  }
}

// ==================== SESSION/CART STORAGE ====================
function saveCartToSession() {
  try {
    sessionStorage.setItem("pos_cart", JSON.stringify(CART));
    console.log("Cart saved to session:", CART.length, "items");
  } catch (error) {
    console.error("Error saving cart to session:", error);
  }
}
function clearCartFromSession() {
  try {
    sessionStorage.removeItem("pos_cart");
    console.log("Cart cleared from session");
  } catch (error) {
    console.error("Error clearing cart from session:", error);
  }
}

function loadCartFromSession() {
  try {
    const cartData = sessionStorage.getItem("pos_cart");
    if (cartData) {
      const parsedCart = JSON.parse(cartData);
      if (Array.isArray(parsedCart) && parsedCart.length > 0) {
        CART = parsedCart;
        console.log("Cart loaded from session:", CART.length, "items");
        renderCart();
        updateBillingSummary();
        updateButtonStates();
        if (!IS_CART_LOADED && CART.length > 0) {
          showToast(
            `Loaded ${CART.length} items from previous session`,
            "info",
          );
          IS_CART_LOADED = true;
        }
      }
    }
  } catch (error) {
    console.error("Error loading cart from session:", error);
    sessionStorage.removeItem("pos_cart");
    CART = [];
  }
}

// ==================== DATA LOADING FUNCTIONS ====================
async function loadProducts() {
  console.log("POS System: Loading products...");
  try {
    const response = await fetchWithTimeout("api/products.php?action=list", {
      timeout: 8000,
      retries: 2,
      credentials: "include",
    });
    if (!response.ok)
      throw new Error(`HTTP ${response.status}: ${response.statusText}`);
    const data = await response.json();
    if (!data.success) throw new Error(data.message || "Unknown server error");
    PRODUCTS = data.products || [];
    BARCODE_MAP = data.barcode_map || {};
    PRODUCTS.forEach((product) => {
      let primaryStock = 0;
      if (product.shop_stock_primary !== undefined)
        primaryStock = parseFloat(product.shop_stock_primary) || 0;
      else if (product.shop_stock !== undefined) {
        primaryStock = parseFloat(product.shop_stock) || 0;
        product.shop_stock_primary = primaryStock;
      } else if (product.stock !== undefined) {
        primaryStock = parseFloat(product.stock) || 0;
        product.shop_stock_primary = primaryStock;
      }
      product.shop_stock_primary = primaryStock;
      if (product.secondary_unit && product.sec_unit_conversion)
        product.shop_stock_secondary =
          primaryStock * (parseFloat(product.sec_unit_conversion) || 1);
      else product.shop_stock_secondary = 0;
      if (product.shop_stock === undefined) product.shop_stock = primaryStock;
    });
    console.log(
      `POS System: Loaded ${PRODUCTS.length} products, ${Object.keys(BARCODE_MAP).length} barcodes`,
    );
    return true;
  } catch (error) {
    console.error("POS System: Product loading failed:", error);
    throw error;
  }
}

async function checkAndGenerateInvoiceNumber() {
  try {
    const response = await fetchWithTimeout(
      "api/invoices.php?action=get_next_invoice_number",
      {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
          Accept: "application/json",
        },
        body: JSON.stringify({
          prefix: GST_TYPE === "gst" ? "INV" : "INVNG",
          year_month: new Date().toISOString().substring(0, 7).replace("-", ""),
          invoice_type: GST_TYPE,
        }),
        timeout: 5000,
      },
    );
    if (!response.ok)
      throw new Error(`HTTP ${response.status}: ${response.statusText}`);
    const data = await response.json();
    if (data.success) {
      document.getElementById("invoice-number").value = data.invoice_number;
      return data.invoice_number;
    } else return null;
  } catch (error) {
    console.warn("Error generating invoice number:", error);
    return null;
  }
}

async function fetchWithTimeout(url, options = {}) {
  const { timeout = 10000, retries = 1, ...fetchOptions } = options;
  fetchOptions.credentials = fetchOptions.credentials || "include";
  for (let attempt = 1; attempt <= retries + 1; attempt++) {
    try {
      const controller = new AbortController();
      const timeoutId = setTimeout(() => controller.abort(), timeout);
      const response = await fetch(url, {
        ...fetchOptions,
        signal: controller.signal,
      });
      clearTimeout(timeoutId);
      return response;
    } catch (error) {
      if (attempt > retries) throw error;
      console.warn(
        `POS System: Fetch attempt ${attempt} failed, retrying...`,
        error,
      );
      await new Promise((resolve) => setTimeout(resolve, 1000 * attempt));
    }
  }
}

function searchProductsLocally(searchTerm) {
  if (!searchTerm || searchTerm.length < 2)
    return filterProductsByGstStatus(PRODUCTS);
  const term = searchTerm.toLowerCase().trim();
  let filtered = PRODUCTS.filter(
    (product) =>
      (product.product_name &&
        product.product_name.toLowerCase().includes(term)) ||
      (product.product_code &&
        product.product_code.toLowerCase().includes(term)) ||
      (product.barcode && product.barcode.toLowerCase().includes(term)),
  );
  return filterProductsByGstStatus(filtered).slice(0, 20);
}

function populateProductDropdownFromSearch(searchTerm = "") {
  const select = document.getElementById("search-product");
  if (!select) return;
  select.innerHTML = '<option value="">-- Search product --</option>';
  let productsToShow = [];
  if (!searchTerm || searchTerm.trim().length < 2)
    productsToShow = filterProductsByGstStatus(PRODUCTS);
  else productsToShow = searchProductsLocally(searchTerm);
  productsToShow.sort((a, b) => {
    const aHasOldStock = (a.shop_old_qty || 0) > 0;
    const bHasOldStock = (b.shop_old_qty || 0) > 0;
    if (aHasOldStock && !bHasOldStock) return -1;
    if (!aHasOldStock && bHasOldStock) return 1;
    return (a.product_name || "").localeCompare(b.product_name || "");
  });
  productsToShow.forEach((product) => {
    try {
      const option = document.createElement("option");
      option.value = product.id;
      const shopStockPrimary = parseFloat(product.shop_stock_primary) || 0;
      const oldQty = parseFloat(product.shop_old_qty) || 0;
      const hasOldStock = oldQty > 0;
      const newStockQty = Math.max(0, shopStockPrimary - oldQty);
      const categoryColor = getCategoryColor(product.category_name || "");
      const subcategoryColor = getSubcategoryColor(
        product.subcategory_name || "",
      );
      let displayText = `${escapeHtml(product.product_name)}`;
      const hasHsn = product.hsn_code && product.hsn_code.trim().length > 0;
      if (hasHsn)
        displayText += ` <span style="background-color: #6f42c1; color: white; padding: 2px 8px; border-radius: 12px; font-size: 0.75em; font-weight: 600; margin-left: 5px;">GST</span>`;
      else
        displayText += ` <span style="background-color: #6c757d; color: white; padding: 2px 8px; border-radius: 12px; font-size: 0.75em; font-weight: 600; margin-left: 5px;">NON-GST</span>`;
      if (hasOldStock)
        displayText += ` <span style="background-color: #f39c12; color: white; padding: 2px 8px; border-radius: 12px; font-size: 0.8em; font-weight: 600; margin-left: 5px;">OLD STOCK</span>`;
      else if (shopStockPrimary > 0)
        displayText += ` <span style="background-color: #3498db; color: white; padding: 2px 8px; border-radius: 12px; font-size: 0.8em; font-weight: 600; margin-left: 5px;">NEW STOCK</span>`;
      if (product.category_name) {
        displayText += ` <span style="color: ${categoryColor}; font-weight: 500;">[${escapeHtml(product.category_name)}`;
        if (product.subcategory_name)
          displayText += ` <span style="color: ${subcategoryColor};">→ ${escapeHtml(product.subcategory_name)}</span>`;
        displayText += "]</span>";
      }
      if (product.product_code)
        displayText += ` <span style="color: #6c757d; font-size: 0.9em;">${escapeHtml(product.product_code)}</span>`;
      if (shopStockPrimary > 0) {
        if (oldQty > 0)
          displayText += ` <span style="background-color: #f39c12; color: white; padding: 2px 8px; border-radius: 12px; font-size: 0.85em; font-weight: 600; display: inline-block; margin: 2px 0;">📦 Old: ${Math.round(oldQty)} ${product.unit_of_measure}</span>`;
        if (newStockQty > 0)
          displayText += ` <span style="background-color: #3498db; color: white; padding: 2px 8px; border-radius: 12px; font-size: 0.85em; font-weight: 600; display: inline-block; margin: 2px 0;">🆕: ${Math.round(newStockQty)} ${product.unit_of_measure}</span>`;
        if (product.secondary_unit && product.sec_unit_conversion) {
          const oldSecondary = oldQty * product.sec_unit_conversion;
          const newSecondary = newStockQty * product.sec_unit_conversion;
          if (oldSecondary > 0)
            displayText += ` <span style="background-color: #f1c40f; color: #2c3e50; padding: 2px 8px; border-radius: 12px; font-size: 0.85em; margin: 2px 0;">↳ Old: ${Math.round(oldSecondary)} ${product.secondary_unit}</span>`;
          if (newSecondary > 0)
            displayText += ` <span style="background-color: #85c1e9; color: #1a5276; padding: 2px 8px; border-radius: 12px; font-size: 0.85em; margin: 2px 0;">↳ New: ${Math.round(newSecondary)} ${product.secondary_unit}</span>`;
        }
      } else
        displayText += ` <span style="background-color: #ffebee; color: #c62828; padding: 2px 8px; border-radius: 12px; font-size: 0.85em; font-weight: 600;">⛔ Out of stock</span>`;
      const price =
        GLOBAL_PRICE_TYPE === "wholesale"
          ? product.wholesale_price || product.retail_price || 0
          : product.retail_price || 0;
      displayText += ` <span style="color: #2e7d32; font-weight: 700; background-color: #e8f5e9; padding: 2px 8px; border-radius: 12px;">₹${Math.round(price)}</span>`;
      option.innerHTML = displayText;
      option.dataset.productId = product.id;
      option.dataset.productName = product.product_name;
      option.dataset.productCode = product.product_code || "";
      option.dataset.retail = product.retail_price || 0;
      option.dataset.wholesale = product.wholesale_price || 0;
      option.dataset.mrp = product.mrp || 0;
      option.dataset.shopStockPrimary = shopStockPrimary;
      option.dataset.oldQty = oldQty;
      option.dataset.newStockQty = newStockQty;
      option.dataset.unit = product.unit_of_measure || "PCS";
      option.dataset.hsn = product.hsn_code || "";
      option.dataset.cgst = product.cgst_rate || 0;
      option.dataset.sgst = product.sgst_rate || 0;
      option.dataset.igst = product.igst_rate || 0;
      option.dataset.referral = product.referral_enabled || 0;
      option.dataset.secondary = product.secondary_unit || "";
      option.dataset.conversion = product.sec_unit_conversion || 1;
      option.dataset.extraCharge = product.sec_unit_extra_charge || 0;
      option.dataset.extraChargeType = product.sec_unit_price_type || "fixed";
      option.dataset.stockPrice = product.stock_price || 0;
      option.dataset.categoryName = product.category_name || "";
      option.dataset.subcategoryName = product.subcategory_name || "";
      option.dataset.useBatchTracking = product.use_batch_tracking || 0;
      select.appendChild(option);
    } catch (productError) {
      console.warn("Error processing product:", productError);
    }
  });
  if (typeof $.fn.select2 !== "undefined") {
    try {
      $("#search-product").trigger("change.select2");
    } catch (select2Error) {
      console.warn("Select2 refresh error:", select2Error);
    }
  }
}

function getCategoryColor(categoryName) {
  const colorMap = {
    Electronics: "#0d47a1",
    Fashion: "#ad1457",
    Groceries: "#2e7d32",
    Furniture: "#bf360c",
    Books: "#4a148c",
    Sports: "#b45309",
    Toys: "#7b1fa2",
    Beauty: "#c2185b",
    Automotive: "#37474f",
    Health: "#00695c",
    default: "#455a64",
  };
  return colorMap[categoryName] || colorMap["default"];
}

function getSubcategoryColor(subcategoryName) {
  const colorMap = {
    Mobile: "#1565c0",
    Laptop: "#283593",
    Men: "#6d4c41",
    Women: "#c2185b",
    Kids: "#f57c00",
    Vegetables: "#2e7d32",
    Fruits: "#ef6c00",
    Dairy: "#0d47a1",
    default: "#546e7a",
  };
  return colorMap[subcategoryName] || colorMap["default"];
}

function getStockColor(stock) {
  if (stock <= 0) return "#c62828";
  if (stock < 10) return "#ef6c00";
  if (stock < 50) return "#2e7d32";
  return "#1b5e20";
}

function getStockBackgroundColor(stock) {
  if (stock <= 0) return "#ffebee";
  if (stock < 10) return "#fff3e0";
  if (stock < 50) return "#e8f5e9";
  return "#c8e6c9";
}

// ==================== EVENT LISTENERS SETUP ====================
function setupEventListeners() {
  console.log("POS System: Setting up event listeners...");
  try {
    $("#search-product").on("change.select2", function (e) {
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
    document
      .getElementById("search-product")
      .addEventListener("input", function (e) {
        const searchTerm = e.target.value;
        if (searchTerm.length >= 2)
          populateProductDropdownFromSearch(searchTerm);
        else populateProductDropdownFromSearch();
      });
    document
      .getElementById("barcode-input")
      .addEventListener("keydown", handleBarcodeScan);
    document
      .getElementById("product-add-button")
      .addEventListener("click", addProductToCart);
    document
      .getElementById("unit-convert")
      .addEventListener("click", toggleUnitConversion);
    $("#customer-contact").on("change", handleCustomerSelection);
    document
      .getElementById("price-type")
      .addEventListener("change", function () {
        GLOBAL_PRICE_TYPE = this.value;
        if (CURRENT_PRODUCT) updateProductForm(CURRENT_PRODUCT);
        updateCartPriceTypes();
        populateProductDropdownFromSearch();
      });
    document
      .getElementById("invoice-type")
      .addEventListener("change", async function () {
        GST_TYPE = this.value;
        await checkAndGenerateInvoiceNumber();
        updateBillingSummary();
      });
    $("#referral").on("change", function () {
      SELECTED_REFERRAL_ID = this.value ? parseInt(this.value) : null;
      updateBillingSummary();
    });
    document
      .getElementById("discount")
      .addEventListener("input", updateProductPriceDisplay);
    document
      .getElementById("discount-type")
      .addEventListener("change", updateProductPriceDisplay);
    document
      .getElementById("btnHoldList")
      .addEventListener("click", loadHoldList);
    document.getElementById("btnHold").addEventListener("click", holdInvoice);
    document
      .getElementById("btnQuotation")
      .addEventListener("click", showQuotationModal);
    document
      .getElementById("btnQuotationList")
      .addEventListener("click", loadQuotationList);
    document
      .getElementById("btnClearCart")
      .addEventListener("click", clearCart);
    document
      .getElementById("btnShowPointsDetails")
      .addEventListener("click", showPointsModal);
    document
      .getElementById("btnGenerateBill")
      .addEventListener("click", generateBill);
    document
      .getElementById("btnPrintBill")
      .addEventListener("click", printBill);
    document
      .getElementById("btnAutoFillRemaining")
      .addEventListener("click", autoFillRemainingAmount);
    document
      .querySelectorAll('input[name="payment-method"]')
      .forEach((checkbox) => {
        checkbox.addEventListener("change", handlePaymentMethodCheckbox);
      });
    document
      .getElementById("cash-amount")
      .addEventListener("input", updatePaymentSummary);
    document
      .getElementById("upi-amount")
      .addEventListener("input", updatePaymentSummary);
    document
      .getElementById("bank-amount")
      .addEventListener("input", updatePaymentSummary);
    document
      .getElementById("cheque-amount")
      .addEventListener("input", updatePaymentSummary);
    document
      .getElementById("credit-amount")
      .addEventListener("input", updatePaymentSummary);
    document
      .getElementById("additional-dis")
      .addEventListener("input", updateBillingSummary);
    document
      .getElementById("overall-discount-type")
      .addEventListener("change", updateBillingSummary);
    document
      .getElementById("selling-price")
      .addEventListener("input", function () {
        const discountInput = document.getElementById("discount");
        if (parseFloat(discountInput.value) === 0) updateProductPriceDisplay();
      });
    document.getElementById("qty-input").addEventListener("input", function () {
      if (CURRENT_PRODUCT && CURRENT_UNIT_IS_SECONDARY)
        updateSecondaryUnitPrice();
    });
    document
      .getElementById("confirmHold")
      .addEventListener("click", saveHoldInvoice);
    document
      .getElementById("saveQuotationBtn")
      .addEventListener("click", saveQuotation);
    document
      .getElementById("btnUseMaxPoints")
      .addEventListener("click", useMaxPoints);
    document
      .getElementById("btnApplyPointsDiscount")
      .addEventListener("click", applyPointsDiscount);
    document
      .getElementById("confirmActionBtn")
      .addEventListener("click", executePendingConfirmation);
    document
      .getElementById("pointsToRedeem")
      .addEventListener("input", updatePointsDiscountPreview);
    document
      .getElementById("search-product")
      .addEventListener("keydown", function (e) {
        if (e.key === "Enter" && this.value) {
          e.preventDefault();
          const productId = this.value;
          if (productId) {
            const product = findProductById(productId);
            if (product) {
              updateProductForm(product);
              setTimeout(() => {
                addProductToCart();
              }, 100);
            }
          }
        }
      });
    document.addEventListener("keydown", function (e) {
      if (e.ctrlKey && e.key === "Enter") {
        e.preventDefault();
        if (CURRENT_PRODUCT) addProductToCart();
      }
      if (e.key === "F1") {
        e.preventDefault();
        showInfoModal(
          "Keyboard Shortcuts",
          "• Enter: Add product to cart<br>• Ctrl+Enter: Add product quickly<br>• Esc: Go to invoices page<br>• F1: Show this help",
        );
      }
      if (e.key === "Escape") {
        e.preventDefault();
        window.location.href = "invoices.php";
      }
    });
    window.addEventListener("beforeunload", function (e) {
      if (CART.length > 0) {
        e.preventDefault();
        e.returnValue =
          "You have unsaved items in your cart. Are you sure you want to leave?";
        return e.returnValue;
      }
    });
    console.log("POS System: Event listeners setup complete");
  } catch (error) {
    console.error("POS System: Error setting up event listeners:", error);
  }
}

// ==================== PRODUCT HANDLING ====================
function handleProductSelection() {
  try {
    const productId = this.value;
    if (!productId) {
      clearProductSelection();
      return;
    }
    const product = PRODUCTS.find((p) => p.id == productId);
    if (product) updateProductForm(product);
    else {
      showToast(
        "Selected product not found. Please refresh the product list.",
        "warning",
      );
      clearProductSelection();
    }
  } catch (error) {
    console.error("POS System: Error handling product selection:", error);
    showToast("Error selecting product. Please try again.", "danger");
  }
}

function handleBarcodeScan(event) {
  if (event.key === "Enter") {
    event.preventDefault();
    try {
      const barcodeInput = document.getElementById("barcode-input");
      const barcode = barcodeInput.value.trim();
      if (!barcode) {
        showToast("Please enter a barcode first", "warning");
        return;
      }
      const product = findProductByBarcode(barcode);
      if (!product) {
        showToast(`Product not found for barcode: ${barcode}`, "danger");
        barcodeInput.value = "";
        barcodeInput.focus();
        return;
      }
      $("#search-product").val(product.id).trigger("change");
      updateProductForm(product);
      setTimeout(() => {
        addProductToCart();
      }, 100);
      barcodeInput.value = "";
      barcodeInput.focus();
    } catch (error) {
      console.error("POS System: Error handling barcode scan:", error);
      showToast("Error processing barcode. Please try again.", "danger");
    }
  }
}

function findProductById(id) {
  if (!id || isNaN(id)) return null;
  const product = PRODUCTS.find((p) => p.id == id);
  if (!product) return null;
  let shopStockPrimary = 0;
  if (product.shop_stock_primary !== undefined)
    shopStockPrimary = parseFloat(product.shop_stock_primary) || 0;
  else if (product.shop_stock !== undefined) {
    shopStockPrimary = parseFloat(product.shop_stock) || 0;
    product.shop_stock_primary = shopStockPrimary;
  }
  product.shop_stock_primary = shopStockPrimary;
  if (product.secondary_unit && product.sec_unit_conversion)
    product.shop_stock_secondary =
      shopStockPrimary * (parseFloat(product.sec_unit_conversion) || 1);
  else product.shop_stock_secondary = 0;
  return product;
}

function findProductByBarcode(code) {
  if (!code || typeof code !== "string") return null;
  const cleanCode = String(code).trim();
  const prodId = BARCODE_MAP[cleanCode];
  if (prodId) {
    const product = findProductById(prodId);
    if (product) return product;
  }
  return PRODUCTS.find(
    (p) =>
      (p.barcode && p.barcode === cleanCode) ||
      (p.product_code && p.product_code === cleanCode),
  );
}

function updateProductForm(product) {
  try {
    if (!product) {
      console.error("POS System: Cannot update form with null product");
      return;
    }
    CURRENT_PRODUCT = product;
    CURRENT_UNIT_IS_SECONDARY = false;
    const oldQty = parseFloat(product.shop_old_qty) || 0;
    const totalStock = parseFloat(product.shop_stock_primary) || 0;
    const newStockQty = Math.max(0, totalStock - oldQty);
    if (oldQty > 0) {
      const warningDiv = document.getElementById("stock-warning");
      if (!warningDiv) {
        const newWarning = document.createElement("div");
        newWarning.id = "stock-warning";
        newWarning.className = "alert alert-warning mt-2";
        newWarning.innerHTML = `<i class="fas fa-exclamation-triangle me-2"></i><strong>Old Stock Available:</strong> ${oldQty} ${product.unit_of_measure} (New Stock: ${newStockQty} ${product.unit_of_measure})<br><small>Old stock must be sold first before new stock can be sold.</small>`;
        const formSection = document.querySelector(".product-search-section");
        if (formSection) formSection.appendChild(newWarning);
      } else
        warningDiv.innerHTML = `<i class="fas fa-exclamation-triangle me-2"></i><strong>Old Stock Available:</strong> ${oldQty} ${product.unit_of_measure} (New Stock: ${newStockQty} ${product.unit_of_measure})<br><small>Old stock must be sold first before new stock can be sold.</small>`;
    } else {
      const warningDiv = document.getElementById("stock-warning");
      if (warningDiv) warningDiv.style.display = "none";
    }
    let sellingPrice = 0;
    if (GLOBAL_PRICE_TYPE === "wholesale")
      sellingPrice =
        parseFloat(product.wholesale_price) ||
        parseFloat(product.retail_price) ||
        0;
    else sellingPrice = parseFloat(product.retail_price) || 0;
    document.getElementById("mrp").value = Math.round(
      parseFloat(product.mrp) || 0,
    );
    document.getElementById("selling-price").value = Math.round(sellingPrice);
    document.getElementById("qty-unit").textContent =
      product.unit_of_measure || "PCS";
    document.getElementById("qty-input").value = "1";
    document.getElementById("discount").value = "0";
    document.getElementById("discount-type").value = "percentage";
    const convertBtn = document.getElementById("unit-convert");
    if (
      product.secondary_unit &&
      product.secondary_unit.trim() &&
      product.sec_unit_conversion &&
      product.sec_unit_conversion > 0
    ) {
      convertBtn.disabled = false;
      convertBtn.title = `Convert to ${product.secondary_unit}`;
      convertBtn.innerHTML = `<i class="fas fa-exchange-alt me-1"></i> `;
    } else {
      convertBtn.disabled = true;
      convertBtn.title = "No secondary unit available";
      convertBtn.innerHTML = `<i class="fas fa-exchange-alt me-1"></i>`;
    }
    const addBtn = document.getElementById("product-add-button");
    addBtn.disabled = false;
    addBtn.title = "Add to cart";
    updateProductPriceDisplay();
    setTimeout(() => {
      const qtyInput = document.getElementById("qty-input");
      qtyInput.focus();
      qtyInput.select();
    }, 100);
  } catch (error) {
    console.error("POS System: Error updating product form:", error);
    showToast("Error loading product details. Please try again.", "danger");
  }
}

function clearProductSelection() {
  try {
    document.getElementById("search-product").value = "";
    if (typeof $.fn.select2 !== "undefined")
      $("#search-product").val("").trigger("change.select2");
    document.getElementById("barcode-input").value = "";
    document.getElementById("mrp").value = "";
    document.getElementById("selling-price").value = "";
    document.getElementById("discount").value = "0";
    document.getElementById("qty-input").value = "1";
    document.getElementById("qty-unit").textContent = "PCS";
    const convertBtn = document.getElementById("unit-convert");
    convertBtn.disabled = true;
    convertBtn.title = "Select a product first";
    convertBtn.innerHTML = '<i class="fas fa-exchange-alt me-1"></i>';
    const addBtn = document.getElementById("product-add-button");
    addBtn.disabled = true;
    addBtn.title = "Select a product first";
    CURRENT_PRODUCT = null;
    CURRENT_UNIT_IS_SECONDARY = false;
    setTimeout(() => {
      document.getElementById("barcode-input").focus();
    }, 100);
  } catch (error) {
    console.error("POS System: Error clearing product selection:", error);
    showToast("Error clearing product form. Please try again.", "warning");
  }
}

function clearProductFormSilently() {
  try {
    document.getElementById("mrp").value = "";
    document.getElementById("selling-price").value = "";
    document.getElementById("discount").value = "0";
    document.getElementById("qty-input").value = "1";
    document.getElementById("qty-unit").textContent = "PCS";
    const convertBtn = document.getElementById("unit-convert");
    convertBtn.disabled = true;
    convertBtn.title = "Select a product first";
    convertBtn.innerHTML = '<i class="fas fa-exchange-alt me-1"></i>';
    const addBtn = document.getElementById("product-add-button");
    addBtn.disabled = true;
    addBtn.title = "Select a product first";
    CURRENT_PRODUCT = null;
    CURRENT_UNIT_IS_SECONDARY = false;
    setTimeout(() => {
      document.getElementById("barcode-input").focus();
    }, 100);
  } catch (error) {
    console.error("Error clearing product form silently:", error);
  }
}

function updateProductPriceDisplay() {
  try {
    if (!CURRENT_PRODUCT) return;
    const sellingPriceInput = document.getElementById("selling-price");
    const discountInput = document.getElementById("discount");
    const discountTypeSelect = document.getElementById("discount-type");
    if (!sellingPriceInput || !discountInput || !discountTypeSelect) return;
    let sellingPrice = parseFloat(sellingPriceInput.value);
    const discount = parseFloat(discountInput.value) || 0;
    const discountType = discountTypeSelect.value;
    if (isNaN(sellingPrice)) {
      let basePrice = 0;
      if (GLOBAL_PRICE_TYPE === "wholesale")
        basePrice =
          parseFloat(CURRENT_PRODUCT.wholesale_price) ||
          parseFloat(CURRENT_PRODUCT.retail_price) ||
          0;
      else basePrice = parseFloat(CURRENT_PRODUCT.retail_price) || 0;
      if (
        CURRENT_UNIT_IS_SECONDARY &&
        CURRENT_PRODUCT.sec_unit_conversion > 0
      ) {
        const conversion = parseFloat(CURRENT_PRODUCT.sec_unit_conversion) || 1;
        const extraCharge =
          parseFloat(CURRENT_PRODUCT.sec_unit_extra_charge) || 0;
        const priceType = CURRENT_PRODUCT.sec_unit_price_type || "fixed";
        if (priceType === "percentage") {
          const extraAmount = basePrice * (extraCharge / 100);
          sellingPrice = (basePrice + extraAmount) / conversion;
        } else sellingPrice = (basePrice + extraCharge) / conversion;
        sellingPrice = parseFloat(sellingPrice.toFixed(2));
      } else sellingPrice = Math.round(basePrice);
      sellingPriceInput.value = sellingPrice;
    }
    let finalPrice = sellingPrice;
    if (discount > 0) {
      if (discountType === "percentage")
        finalPrice = sellingPrice - sellingPrice * (discount / 100);
      else finalPrice = sellingPrice - discount;
    }
    if (finalPrice < 0) finalPrice = 0;
    if (CURRENT_UNIT_IS_SECONDARY)
      finalPrice = parseFloat(finalPrice.toFixed(2));
    else finalPrice = Math.round(finalPrice);
  } catch (error) {
    console.error("Error updating product price display:", error);
  }
}

function toggleUnitConversion() {
  try {
    if (!CURRENT_PRODUCT) {
      showToast("Please select a product first", "warning");
      return;
    }
    const product = CURRENT_PRODUCT;
    if (
      !product.secondary_unit ||
      !product.sec_unit_conversion ||
      product.sec_unit_conversion <= 0
    ) {
      showToast(
        "This product has no valid secondary unit configuration",
        "info",
      );
      return;
    }
    const currentUnit = document.getElementById("qty-unit").textContent;
    const qtyInput = document.getElementById("qty-input");
    const sellingPriceInput = document.getElementById("selling-price");
    const discountInput = document.getElementById("discount");
    if (currentUnit === product.unit_of_measure) {
      CURRENT_UNIT_IS_SECONDARY = true;
      document.getElementById("qty-unit").textContent = product.secondary_unit;
      document.getElementById("unit-convert").innerHTML =
        '<i class="fas fa-undo me-1"></i> ';
      const currentQty = parseFloat(qtyInput.value) || 1;
      const conversion = parseFloat(product.sec_unit_conversion) || 1;
      qtyInput.value = (currentQty * conversion).toFixed(2);
      discountInput.value = "0";
      updateSecondaryUnitPrice();
      showToast(
        `Converted to ${product.secondary_unit} (1 ${product.unit_of_measure} = ${product.sec_unit_conversion} ${product.secondary_unit})`,
        "info",
      );
    } else {
      CURRENT_UNIT_IS_SECONDARY = false;
      document.getElementById("qty-unit").textContent = product.unit_of_measure;
      document.getElementById("unit-convert").innerHTML =
        '<i class="fas fa-exchange-alt me-1"></i> ';
      const currentQty = parseFloat(qtyInput.value) || 1;
      const conversion = parseFloat(product.sec_unit_conversion) || 1;
      qtyInput.value = (currentQty / conversion).toFixed(3);
      discountInput.value = "0";
      const originalPrice =
        GLOBAL_PRICE_TYPE === "wholesale"
          ? parseFloat(product.wholesale_price) || 0
          : parseFloat(product.retail_price) || 0;
      sellingPriceInput.value = Math.round(originalPrice);
      showToast(`Converted to ${product.unit_of_measure}`, "info");
    }
    updateProductPriceDisplay();
  } catch (error) {
    console.error("POS System: Error toggling unit conversion:", error);
    showToast("Error converting unit. Please try again.", "danger");
  }
}

function updateSecondaryUnitPrice() {
  try {
    if (!CURRENT_PRODUCT || !CURRENT_UNIT_IS_SECONDARY) return;
    const product = CURRENT_PRODUCT;
    let basePrice = 0;
    if (GLOBAL_PRICE_TYPE === "wholesale")
      basePrice =
        parseFloat(product.wholesale_price) ||
        parseFloat(product.retail_price) ||
        0;
    else basePrice = parseFloat(product.retail_price) || 0;
    const secUnitPriceType = product.sec_unit_price_type || "fixed";
    const secUnitExtraCharge = parseFloat(product.sec_unit_extra_charge) || 0;
    const secUnitConversion = parseFloat(product.sec_unit_conversion) || 1;
    if (secUnitConversion <= 0) {
      console.error("Invalid conversion factor");
      return;
    }
    let pricePerSecondaryUnit = basePrice / secUnitConversion;
    let sellingPrice = pricePerSecondaryUnit;
    if (secUnitPriceType === "percentage")
      sellingPrice =
        pricePerSecondaryUnit +
        pricePerSecondaryUnit * (secUnitExtraCharge / 100);
    else sellingPrice = pricePerSecondaryUnit + secUnitExtraCharge;
    sellingPrice = parseFloat(sellingPrice.toFixed(2));
    const sellingPriceInput = document.getElementById("selling-price");
    if (sellingPriceInput) sellingPriceInput.value = sellingPrice;
    updateProductPriceDisplay();
  } catch (error) {
    console.error("Error updating secondary unit price:", error);
    showToast(
      "Error calculating secondary unit price. Please check product configuration.",
      "danger",
    );
  }
}

// ==================== ADD PRODUCT TO CART ====================
function addProductToCart() {
  try {
    if (!CURRENT_PRODUCT) {
      showToast("Please select a product first", "warning");
      return;
    }
    const product = CURRENT_PRODUCT;
    const qtyInput = document.getElementById("qty-input");
    let qty = parseFloat(qtyInput.value) || 1;
    if (qty <= 0) {
      showToast("Please enter a valid quantity (greater than 0)", "warning");
      qtyInput.focus();
      qtyInput.select();
      return;
    }
    if (isNaN(qty)) {
      showToast("Invalid quantity entered. Please enter a number.", "danger");
      qtyInput.value = "1";
      qtyInput.focus();
      qtyInput.select();
      return;
    }
    const shopStockPrimary = parseFloat(product.shop_stock_primary) || 0;
    const oldQty = parseFloat(product.shop_old_qty) || 0;
    const newStockQty = Math.max(0, shopStockPrimary - oldQty);
    const secUnitConversion = parseFloat(product.sec_unit_conversion) || 1;
    let qtyInPrimary = qty;
    if (CURRENT_UNIT_IS_SECONDARY && secUnitConversion > 0)
      qtyInPrimary = qty / secUnitConversion;
    if (qtyInPrimary > shopStockPrimary) {
      const availableSecondary = Math.floor(
        shopStockPrimary * secUnitConversion,
      );
      const unitText = CURRENT_UNIT_IS_SECONDARY
        ? `${availableSecondary} ${product.secondary_unit}`
        : `${shopStockPrimary} ${product.unit_of_measure}`;
      showToast(`Insufficient stock! Available: ${unitText}`, "warning");
      if (CURRENT_UNIT_IS_SECONDARY)
        qtyInput.value = availableSecondary.toString();
      else qtyInput.value = shopStockPrimary.toString();
      qtyInput.focus();
      qtyInput.select();
      return;
    }
    let totalOldStockInCart = 0;
    CART.forEach((item) => {
      if (item.product_id === product.id && item.is_from_old_stock)
        totalOldStockInCart += item.quantity_in_primary;
    });
    const remainingOldStock = oldQty - totalOldStockInCart;
    if (remainingOldStock > 0 && qtyInPrimary > remainingOldStock) {
      showToast(
        `Cannot sell new stock while old stock remains. Remaining old stock: ${Math.floor(remainingOldStock * 100) / 100} ${product.unit_of_measure}. Please reduce quantity to ${Math.floor(remainingOldStock * 100) / 100} or less to sell only old stock.`,
        "warning",
      );
      qtyInput.value = CURRENT_UNIT_IS_SECONDARY
        ? (remainingOldStock * secUnitConversion).toFixed(2)
        : remainingOldStock.toFixed(2);
      qtyInput.focus();
      qtyInput.select();
      return;
    }
    const isFromOldStock =
      remainingOldStock > 0 && qtyInPrimary <= remainingOldStock;
    const mrp = parseFloat(document.getElementById("mrp").value) || 0;
    let sellingPrice =
      parseFloat(document.getElementById("selling-price").value) || 0;
    const discount = parseFloat(document.getElementById("discount").value) || 0;
    const discountType = document.getElementById("discount-type").value;
    const unit = document.getElementById("qty-unit").textContent;
    const isSecondaryUnit = CURRENT_UNIT_IS_SECONDARY;
    if (isSecondaryUnit) sellingPrice = parseFloat(sellingPrice.toFixed(2));
    else sellingPrice = Math.round(sellingPrice);
    let finalPrice = sellingPrice;
    let discountValue = 0;
    if (discount > 0) {
      if (discountType === "percentage")
        discountValue = sellingPrice * (discount / 100);
      else discountValue = discount;
      finalPrice = sellingPrice - discountValue;
    }
    if (finalPrice < 0) {
      showToast("Discount cannot make price negative", "warning");
      return;
    }
    if (isSecondaryUnit) finalPrice = parseFloat(finalPrice.toFixed(2));
    else finalPrice = Math.round(finalPrice);
    const cartItemId = `${product.id}-${unit}-${finalPrice.toFixed(2)}-${discountType}-${isSecondaryUnit}-${isFromOldStock ? "old" : "new"}`;
    const productName = product.product_name;
    const categoryName = product.category_name || "";
    const subcategoryName = product.subcategory_name || "";
    let displayName = productName;
    if (categoryName) {
      displayName += ` (${categoryName}`;
      if (subcategoryName) displayName += ` - ${subcategoryName}`;
      displayName += ")";
    }
    if (isFromOldStock)
      displayName +=
        ' <span class="badge bg-warning text-dark">OLD STOCK</span>';
    else displayName += ' <span class="badge bg-info">NEW STOCK</span>';
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
      is_from_old_stock: isFromOldStock,
      discount_value: discount,
      discount_type: discountType,
      discount_amount: discountValue,
      shop_stock: shopStockPrimary,
      old_qty: oldQty,
      new_stock_qty: newStockQty,
      hsn_code: product.hsn_code || "",
      cgst_rate: parseFloat(product.cgst_rate) || 0,
      sgst_rate: parseFloat(product.sgst_rate) || 0,
      igst_rate: parseFloat(product.igst_rate) || 0,
      referral_enabled: product.referral_enabled || 0,
      referral_type: product.referral_type || "percentage",
      referral_value: parseFloat(product.referral_value) || 0,
      referral_commission: 0,
      secondary_unit: product.secondary_unit || "",
      sec_unit_conversion: secUnitConversion,
      stock_price: parseFloat(product.stock_price) || 0,
      retail_price: parseFloat(product.retail_price) || 0,
      wholesale_price: parseFloat(product.wholesale_price) || 0,
      unit_of_measure: product.unit_of_measure || "PCS",
      quantity_in_primary: qtyInPrimary,
      added_at: new Date().toISOString(),
      total: finalPrice * qty,
      original_product_name: product.product_name,
      category_name: categoryName,
      subcategory_name: subcategoryName,
      use_batch_tracking: product.use_batch_tracking || 0,
    };
    if (cartItem.referral_enabled == 1 && SELECTED_REFERRAL_ID) {
      const referralType = cartItem.referral_type || "percentage";
      const referralValue = cartItem.referral_value || 0;
      if (referralType === "percentage")
        cartItem.referral_commission = cartItem.total * (referralValue / 100);
      else cartItem.referral_commission = referralValue * cartItem.quantity;
    }
    const existingIndex = CART.findIndex(
      (item) =>
        item.product_id === cartItem.product_id &&
        item.unit === cartItem.unit &&
        Math.abs(item.price - cartItem.price) < 0.01 &&
        item.discount_type === cartItem.discount_type &&
        item.is_secondary_unit === cartItem.is_secondary_unit &&
        item.is_from_old_stock === cartItem.is_from_old_stock,
    );
    if (existingIndex >= 0) {
      const newQtyInPrimary =
        CART[existingIndex].quantity_in_primary + qtyInPrimary;
      if (isFromOldStock) {
        let totalOldInCart = 0;
        CART.forEach((item) => {
          if (item.product_id === product.id && item.is_from_old_stock)
            totalOldInCart += item.quantity_in_primary;
        });
        totalOldInCart += qtyInPrimary;
        if (totalOldInCart > oldQty) {
          const availableOld = oldQty - (totalOldInCart - qtyInPrimary);
          showToast(
            `Cannot add more old stock. Remaining old stock: ${Math.floor(availableOld * 100) / 100} ${product.unit_of_measure}`,
            "warning",
          );
          return;
        }
      } else {
        let totalNewInCart = 0;
        CART.forEach((item) => {
          if (item.product_id === product.id && !item.is_from_old_stock)
            totalNewInCart += item.quantity_in_primary;
        });
        totalNewInCart += qtyInPrimary;
        if (totalNewInCart > newStockQty) {
          const availableNew = newStockQty - (totalNewInCart - qtyInPrimary);
          showToast(
            `Cannot add more new stock. Remaining new stock: ${Math.floor(availableNew * 100) / 100} ${product.unit_of_measure}`,
            "warning",
          );
          return;
        }
      }
      CART[existingIndex].quantity += qty;
      CART[existingIndex].quantity_in_primary = newQtyInPrimary;
      CART[existingIndex].total =
        CART[existingIndex].price * CART[existingIndex].quantity;
      showToast(
        `${product.product_name} quantity updated to ${CART[existingIndex].quantity} ${unit} (${isFromOldStock ? "Old Stock" : "New Stock"})`,
        "info",
      );
    } else {
      CART.push(cartItem);
      showToast(`${displayName} added to cart`, "success");
    }
    renderCart();
    saveCartToSession();
    clearProductFormSilently();
    updateBillingSummary();
    updateButtonStates();
  } catch (error) {
    console.error("Error adding product to cart:", error);
    showToast("Error adding product to cart. Please try again.", "danger");
  }
}

// ==================== CART RENDERING ====================
function renderCart() {
  try {
    const tbody = document.getElementById("cartBody");
    const emptyRow = document.getElementById("emptyCartRow");
    if (!tbody) {
      console.error("Cart table body not found");
      return;
    }
    tbody.innerHTML = "";
    if (CART.length === 0) {
      if (!emptyRow) {
        const newEmptyRow = document.createElement("tr");
        newEmptyRow.id = "emptyCartRow";
        newEmptyRow.innerHTML =
          '<td colspan="10" class="cart-empty">No items in cart</td>';
        tbody.appendChild(newEmptyRow);
      } else tbody.appendChild(emptyRow);
      return;
    }
    if (emptyRow) emptyRow.style.display = "none";
    CART.forEach((item, index) => {
      try {
        const row = document.createElement("tr");
        row.id = `cart-row-${index}`;
        let primaryUnitDisplay = "";
        if (item.is_secondary_unit && item.sec_unit_conversion > 0) {
          const primaryQty = item.quantity / item.sec_unit_conversion;
          primaryUnitDisplay = `${primaryQty.toFixed(2)} ${item.unit_of_measure}`;
        }
        let quantityDisplay = item.quantity;
        if (item.is_secondary_unit)
          quantityDisplay = parseFloat(item.quantity.toFixed(2));
        const itemTotal = item.price * item.quantity;
        let stockTypeBadge = "";
        if (item.is_from_old_stock)
          stockTypeBadge =
            '<span class="badge bg-warning text-dark ms-1">OLD</span>';
        else stockTypeBadge = '<span class="badge bg-info ms-1">NEW</span>';
        row.innerHTML = `<td>${index + 1}</td><td class="text-start"><strong>${escapeHtml(item.name)} ${stockTypeBadge}</strong><br><small class="text-muted">${escapeHtml(item.code)}</small>${primaryUnitDisplay ? `<br><small class="text-muted">${primaryUnitDisplay}</small>` : ""}</td><td><div class="d-flex align-items-center gap-1"><button class="btn btn-sm btn-outline-secondary py-0 px-2" onclick="cartItemDecrement(${index})"><i class="fas fa-minus"></i></button><input type="number" class="form-control form-control-sm text-center cart-qty-input" value="${quantityDisplay}" data-index="${index}" min="${item.is_secondary_unit ? 0.01 : 1}" step="${item.is_secondary_unit ? 0.01 : 1}" style="width: 80px;"><button class="btn btn-sm btn-outline-secondary py-0 px-2" onclick="cartItemIncrement(${index})"><i class="fas fa-plus"></i></button></div></td><td>${escapeHtml(item.unit)}</td><td><select class="form-select form-select-sm cart-price-type-select" data-index="${index}"><option value="retail" ${item.price_type === "retail" ? "selected" : ""}>Retail</option><option value="wholesale" ${item.price_type === "wholesale" ? "selected" : ""}>Wholesale</option></select></td><td><div class="d-flex align-items-center gap-1"><input type="number" class="form-control form-control-sm text-center cart-discount-value" value="${item.discount_value || 0}" data-index="${index}" min="0" ${item.discount_type === "percentage" ? 'max="100"' : ""} step="0.01" style="width: 70px;"><select class="form-select form-select-sm cart-discount-type" data-index="${index}" style="width: 70px;"><option value="percentage" ${item.discount_type === "percentage" ? "selected" : ""}>%</option><option value="fixed" ${item.discount_type === "fixed" ? "selected" : ""}>₹</option></select></div></td><td class="text-end">₹${item.price.toFixed(item.is_secondary_unit ? 2 : 0)}<br><small class="text-muted">Per ${item.unit}</small></td><td class="text-end">${((item.cgst_rate || 0) + (item.sgst_rate || 0) + (item.igst_rate || 0)).toFixed(2)}%</td><td class="text-end">₹${item.is_secondary_unit ? itemTotal.toFixed(2) : Math.round(itemTotal)}</td><td><div class="cart-actions"><button class="btn btn-sm btn-outline-danger" onclick="removeCartItem(${index})" title="Remove item"><i class="fas fa-trash"></i></button></div></td>`;
        tbody.appendChild(row);
      } catch (rowError) {
        console.warn("Error rendering cart row:", rowError);
      }
    });
    setTimeout(() => {
      document.querySelectorAll(".cart-qty-input").forEach((input) => {
        input.addEventListener(
          "input",
          debounce(function () {
            const index = parseInt(this.dataset.index);
            const value =
              parseFloat(this.value) ||
              (CART[index].is_secondary_unit ? 0.01 : 1);
            liveUpdateCartItemQuantity(index, value);
          }, 300),
        );
        input.addEventListener("change", function () {
          const index = parseInt(this.dataset.index);
          const value =
            parseFloat(this.value) ||
            (CART[index].is_secondary_unit ? 0.01 : 1);
          updateCartItemQuantity(index, value);
        });
      });
      document.querySelectorAll(".cart-price-type-select").forEach((select) => {
        select.addEventListener("change", function () {
          const index = parseInt(this.dataset.index);
          updateCartItemPriceType(index, this.value);
        });
      });
      document.querySelectorAll(".cart-discount-value").forEach((input) => {
        input.addEventListener(
          "input",
          debounce(function () {
            const index = parseInt(this.dataset.index);
            const value = parseFloat(this.value) || 0;
            liveUpdateCartItemDiscount(index, value);
          }, 300),
        );
        input.addEventListener("change", function () {
          const index = parseInt(this.dataset.index);
          const value = parseFloat(this.value) || 0;
          updateCartItemDiscount(index, value);
        });
      });
      document.querySelectorAll(".cart-discount-type").forEach((select) => {
        select.addEventListener("change", function () {
          const index = parseInt(this.dataset.index);
          updateCartItemDiscountType(index, this.value);
        });
      });
    }, 10);
  } catch (error) {
    console.error("Error rendering cart:", error);
    showToast("Error displaying cart. Please refresh page.", "danger");
  }
}

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

function liveUpdateCartItemQuantity(index, newQty) {
  try {
    if (!CART[index]) return;
    const item = CART[index];
    const product = findProductById(item.product_id);
    if (product && newQty > product.shop_stock) return;
    const row = document.querySelector(`#cart-row-${index}`);
    if (row) {
      const totalCell = row.querySelector("td:nth-child(9)");
      if (totalCell) {
        const itemTotal = item.price * newQty;
        totalCell.innerHTML = `₹${Math.round(itemTotal)}`;
      }
    }
  } catch (error) {
    console.error("Error in live quantity update:", error);
  }
}

function liveUpdateCartItemDiscount(index, discountValue) {
  try {
    if (!CART[index]) return;
    const item = CART[index];
    const discount = parseFloat(discountValue) || 0;
    const discountTypeElement = document.querySelector(
      `.cart-discount-type[data-index="${index}"]`,
    );
    const discountType = discountTypeElement
      ? discountTypeElement.value
      : "percentage";
    if (discountType === "percentage" && discount > 100) return;
    const product = findProductById(item.product_id);
    if (!product) return;
    let basePrice =
      item.price_type === "wholesale"
        ? parseFloat(product.wholesale_price)
        : parseFloat(product.retail_price);
    let finalPrice = basePrice;
    if (discountType === "percentage" && discount > 0)
      finalPrice = basePrice * (1 - discount / 100);
    else if (discountType === "fixed" && discount > 0)
      finalPrice = basePrice - discount;
    if (finalPrice < 0) finalPrice = 0;
    finalPrice = Math.round(finalPrice);
    const row = document.querySelector(`#cart-row-${index}`);
    if (row) {
      const priceCell = row.querySelector("td:nth-child(7)");
      const totalCell = row.querySelector("td:nth-child(9)");
      if (priceCell)
        priceCell.innerHTML = `₹${finalPrice}<br><small class="text-muted">Ex-GST: ₹${Math.round(finalPrice / (1 + (item.cgst_rate + item.sgst_rate + item.igst_rate) / 100))}</small>`;
      if (totalCell)
        totalCell.textContent = `₹${Math.round(finalPrice * item.quantity)}`;
    }
  } catch (error) {
    console.error("Error in live discount update:", error);
  }
}

function cartItemDecrement(index) {
  const item = CART[index];
  if (!item) return;
  const newQty = Math.max(item.is_secondary_unit ? 1 : 1, item.quantity - 1);
  updateCartItemQuantity(index, newQty);
}
function cartItemIncrement(index) {
  const item = CART[index];
  if (!item) return;
  const newQty = item.quantity + 1;
  updateCartItemQuantity(index, newQty);
}

function updateCartItemQuantity(index, newQty) {
  try {
    if (!CART[index]) return;
    const item = CART[index];
    const product = findProductById(item.product_id);
    if (!product) return;
    if (isNaN(newQty) || newQty <= 0) {
      if (!item.is_secondary_unit) removeCartItem(index);
      return;
    }
    const shopStockPrimary = parseFloat(product.shop_stock_primary) || 0;
    const oldQty = parseFloat(product.shop_old_qty) || 0;
    const secUnitConversion = parseFloat(item.sec_unit_conversion) || 1;
    let newQtyInPrimary = newQty;
    if (item.is_secondary_unit && secUnitConversion > 0)
      newQtyInPrimary = newQty / secUnitConversion;
    if (item.is_from_old_stock) {
      let totalOldInCart = newQtyInPrimary;
      CART.forEach((cartItem, idx) => {
        if (
          idx !== index &&
          cartItem.product_id === item.product_id &&
          cartItem.is_from_old_stock
        )
          totalOldInCart += cartItem.quantity_in_primary;
      });
      if (totalOldInCart > oldQty) {
        let availableForThisItem = oldQty;
        CART.forEach((cartItem, idx) => {
          if (
            idx !== index &&
            cartItem.product_id === item.product_id &&
            cartItem.is_from_old_stock
          )
            availableForThisItem -= cartItem.quantity_in_primary;
        });
        if (availableForThisItem <= 0) {
          showToast(`No old stock available for this product`, "warning");
          return;
        }
        const availableQty = item.is_secondary_unit
          ? Math.floor(availableForThisItem * secUnitConversion * 100) / 100
          : Math.floor(availableForThisItem * 100) / 100;
        showToast(
          `Insufficient old stock. Available: ${availableQty} ${item.unit}`,
          "warning",
        );
        return;
      }
    } else {
      let totalNewInCart = newQtyInPrimary;
      CART.forEach((cartItem, idx) => {
        if (
          idx !== index &&
          cartItem.product_id === item.product_id &&
          !cartItem.is_from_old_stock
        )
          totalNewInCart += cartItem.quantity_in_primary;
      });
      const newStockQty = Math.max(0, shopStockPrimary - oldQty);
      if (totalNewInCart > newStockQty) {
        let availableForThisItem = newStockQty;
        CART.forEach((cartItem, idx) => {
          if (
            idx !== index &&
            cartItem.product_id === item.product_id &&
            !cartItem.is_from_old_stock
          )
            availableForThisItem -= cartItem.quantity_in_primary;
        });
        if (availableForThisItem <= 0) {
          showToast(`No new stock available for this product`, "warning");
          return;
        }
        const availableQty = item.is_secondary_unit
          ? Math.floor(availableForThisItem * secUnitConversion * 100) / 100
          : Math.floor(availableForThisItem * 100) / 100;
        showToast(
          `Insufficient new stock. Available: ${availableQty} ${item.unit}`,
          "warning",
        );
        return;
      }
    }
    CART[index].quantity = newQty;
    CART[index].quantity_in_primary = newQtyInPrimary;
    CART[index].total = CART[index].price * CART[index].quantity;
    renderCart();
    saveCartToSession();
    updateBillingSummary();
  } catch (error) {
    console.error("Error updating cart item quantity:", error);
    showToast("Error updating quantity. Please try again.", "danger");
  }
}

function updateCartItemPriceType(index, priceType) {
  try {
    if (!CART[index]) return;
    const item = CART[index];
    const product = findProductById(item.product_id);
    if (!product) return;
    let basePrice =
      priceType === "wholesale"
        ? parseFloat(product.wholesale_price) ||
          parseFloat(product.retail_price) ||
          0
        : parseFloat(product.retail_price) || 0;
    let finalPrice = basePrice;
    if (item.discount_type === "percentage" && item.discount_value > 0)
      finalPrice = basePrice - basePrice * (item.discount_value / 100);
    else if (item.discount_type === "fixed" && item.discount_value > 0)
      finalPrice = basePrice - item.discount_value;
    if (finalPrice < 0) finalPrice = 0;
    finalPrice = Math.round(finalPrice);
    CART[index].price_type = priceType;
    CART[index].base_price = basePrice;
    CART[index].price = finalPrice;
    CART[index].total = finalPrice * item.quantity;
    renderCart();
    saveCartToSession();
    updateBillingSummary();
    showToast(`${item.name} price updated to ${priceType} pricing`, "info");
  } catch (error) {
    console.error("Error updating cart item price type:", error);
    showToast("Error updating price type. Please try again.", "danger");
  }
}

function updateCartItemDiscount(index, discountValue) {
  try {
    if (!CART[index]) return;
    const discount = parseFloat(discountValue) || 0;
    const item = CART[index];
    const discountTypeElement = document.querySelector(
      `.cart-discount-type[data-index="${index}"]`,
    );
    const discountType = discountTypeElement
      ? discountTypeElement.value
      : "percentage";
    if (discountType === "percentage" && (discount < 0 || discount > 100)) {
      showToast("Percentage discount must be between 0 and 100%", "warning");
      return;
    }
    if (discountType === "fixed" && discount < 0) {
      showToast("Fixed discount cannot be negative", "warning");
      return;
    }
    item.discount_value = discount;
    item.discount_type = discountType;
    updateCartItemPrice(index);
    saveCartToSession();
    updateBillingSummary();
    showToast(`${item.name} discount updated`, "info");
  } catch (error) {
    console.error("Error updating cart item discount:", error);
    showToast("Error updating discount. Please try again.", "danger");
  }
}

function updateCartItemDiscountType(index, discountType) {
  try {
    if (!CART[index]) return;
    const item = CART[index];
    item.discount_type = discountType;
    updateCartItemPrice(index);
    saveCartToSession();
    updateBillingSummary();
    showToast(`${item.name} discount type updated to ${discountType}`, "info");
  } catch (error) {
    console.error("Error updating cart item discount type:", error);
    showToast("Error updating discount type. Please try again.", "danger");
  }
}

function updateCartItemPrice(index) {
  try {
    const item = CART[index];
    if (!item) return;
    const product = findProductById(item.product_id);
    if (!product) return;
    let basePrice =
      item.price_type === "wholesale"
        ? parseFloat(product.wholesale_price) ||
          parseFloat(product.retail_price) ||
          0
        : parseFloat(product.retail_price) || 0;
    let finalPrice = basePrice;
    if (item.discount_type === "percentage" && item.discount_value > 0)
      finalPrice = basePrice - basePrice * (item.discount_value / 100);
    else if (item.discount_type === "fixed" && item.discount_value > 0)
      finalPrice = basePrice - item.discount_value;
    if (finalPrice < 0) finalPrice = 0;
    finalPrice = Math.round(finalPrice);
    item.base_price = basePrice;
    item.price = finalPrice;
    item.total = finalPrice * item.quantity;
    const priceCell = document.querySelector(
      `#cart-row-${index} td:nth-child(7)`,
    );
    const totalCell = document.querySelector(
      `#cart-row-${index} td:nth-child(9)`,
    );
    if (priceCell) {
      const gstRate = item.cgst_rate + item.sgst_rate + item.igst_rate;
      const priceWithoutGST = finalPrice / (1 + gstRate / 100);
      priceCell.innerHTML = `₹${finalPrice}<br><small class="text-muted">Ex-GST: ₹${Math.round(priceWithoutGST)}</small>`;
    }
    if (totalCell) totalCell.textContent = `₹${Math.round(item.total)}`;
  } catch (error) {
    console.error("Error updating cart item price:", error);
  }
}

function removeCartItem(index) {
  try {
    if (!CART[index]) return;
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
    console.error("Error removing cart item:", error);
    showErrorToast("Error removing item. Please try again.");
  }
}

function clearCart() {
  if (CART.length === 0) {
    showToast("Cart is already empty", "info");
    return;
  }
  showClearCartConfirmation(CART.length, function () {
    CART = [];
    renderCart();
    clearCartFromSession();
    updateBillingSummary();
    updateButtonStates();
    showSuccessToast("Cart cleared successfully");
  });
}

function updateCartPriceTypes() {
  try {
    CART.forEach((item) => {
      const product = findProductById(item.product_id);
      if (product) {
        let basePrice =
          GLOBAL_PRICE_TYPE === "wholesale"
            ? product.wholesale_price || product.retail_price || 0
            : product.retail_price || 0;
        let finalPrice = basePrice;
        if (item.discount_type === "percentage" && item.discount_value > 0)
          finalPrice = basePrice - basePrice * (item.discount_value / 100);
        else if (item.discount_type === "fixed" && item.discount_value > 0)
          finalPrice = basePrice - item.discount_value;
        if (finalPrice < 0) finalPrice = 0;
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
    showToast(`Applied ${GLOBAL_PRICE_TYPE} pricing to all items`, "success");
  } catch (error) {
    console.error("Error updating cart price types:", error);
    showToast("Error updating prices. Please try again.", "danger");
  }
}

// ==================== CALCULATION FUNCTIONS ====================
function calculateItemGST(item) {
  try {
    if (
      GST_TYPE === "non-gst" ||
      item.cgst_rate + item.sgst_rate + item.igst_rate <= 0
    )
      return {
        taxable: item.price * item.quantity,
        cgst: 0,
        sgst: 0,
        igst: 0,
        total: 0,
      };
    const itemTotal = item.price * item.quantity;
    const totalGSTRate = item.cgst_rate + item.sgst_rate + item.igst_rate;
    const taxableValue = itemTotal / (1 + totalGSTRate / 100);
    const gstAmount = itemTotal - taxableValue;
    let cgst = 0,
      sgst = 0,
      igst = 0;
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
      total: gstAmount,
    };
  } catch (error) {
    console.error("Error calculating item GST:", error);
    return { taxable: 0, cgst: 0, sgst: 0, igst: 0, total: 0 };
  }
}

function calculateItemReferralCommission(item) {
  try {
    if (!item.referral_enabled || !SELECTED_REFERRAL_ID) return 0;
    const itemTotal = item.price * item.quantity;
    if (item.referral_type === "percentage")
      return itemTotal * (item.referral_value / 100);
    else return item.referral_value * item.quantity;
  } catch (error) {
    console.error("Error calculating referral commission:", error);
    return 0;
  }
}

function calculateTotals() {
  try {
    let subtotal = 0,
      totalItemDiscount = 0,
      totalTaxable = 0,
      totalCGST = 0,
      totalSGST = 0,
      totalIGST = 0,
      totalReferralCommission = 0;
    CART.forEach((item) => {
      const itemTotal = item.price * item.quantity;
      const itemGST = calculateItemGST(item);
      const itemReferralCommission = calculateItemReferralCommission(item);
      subtotal += itemTotal;
      totalTaxable += itemGST.taxable || 0;
      totalCGST += itemGST.cgst;
      totalSGST += itemGST.sgst;
      totalIGST += itemGST.igst;
      totalReferralCommission += itemReferralCommission;
      const product = findProductById(item.product_id);
      if (product && item.discount_value > 0) {
        let basePrice =
          item.price_type === "wholesale"
            ? parseFloat(product.wholesale_price)
            : parseFloat(product.retail_price);
        if (item.discount_type === "percentage")
          totalItemDiscount +=
            basePrice * (item.discount_value / 100) * item.quantity;
        else totalItemDiscount += item.discount_value * item.quantity;
      }
    });
    const subtotalAfterItems = subtotal - totalItemDiscount;
    const overallDiscVal =
      parseFloat(document.getElementById("additional-dis").value) || 0;
    const overallDiscType = document.getElementById(
      "overall-discount-type",
    ).value;
    let overallDiscount = 0;
    if (overallDiscVal > 0) {
      if (overallDiscType === "percentage")
        overallDiscount = subtotalAfterItems * (overallDiscVal / 100);
      else overallDiscount = Math.min(overallDiscVal, subtotalAfterItems);
    }
    const totalBeforePoints = Math.max(0, subtotalAfterItems - overallDiscount);
    const pointsDiscount =
      LOYALTY_POINTS_DISCOUNT > totalBeforePoints
        ? totalBeforePoints
        : LOYALTY_POINTS_DISCOUNT;
    const totalGST = GST_TYPE === "gst" ? totalCGST + totalSGST + totalIGST : 0;
    const grandTotal = Math.max(0, totalBeforePoints - pointsDiscount);
    const shippingCharges = SHIPPING_DETAILS.charges || 0;
    const transportCharge = TRANSPORT_DETAILS.charge || 0;
    const totalExtraCharges = shippingCharges + transportCharge;
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
      grandTotal: parseFloat((grandTotal + totalExtraCharges).toFixed(2)),
      subtotalAfterItems: parseFloat(subtotalAfterItems.toFixed(2)),
      shippingCharges: parseFloat(shippingCharges.toFixed(2)),
      transportCharge: parseFloat(transportCharge.toFixed(2)),
      totalExtraCharges: parseFloat(totalExtraCharges.toFixed(2)),
    };
  } catch (error) {
    console.error("Error calculating totals:", error);
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
      subtotalAfterItems: 0,
      shippingCharges: 0,
      transportCharge: 0,
      totalExtraCharges: 0,
    };
  }
}

function updateBillingSummary() {
  try {
    const totals = calculateTotals();
    document.getElementById("subtotal-display").textContent =
      `₹ ${Math.round(totals.subtotal)}`;
    document.getElementById("item-discount-display").textContent =
      `₹ ${Math.round(totals.totalItemDiscount)}`;
    document.getElementById("overall-discount-display").textContent =
      `₹ ${Math.round(totals.overallDiscount)}`;
    document.getElementById("points-discount-display").textContent =
      `₹ ${Math.round(totals.pointsDiscount)}`;
    document.getElementById("taxable-display").textContent =
      `₹ ${Math.round(totals.totalTaxable)}`;
    document.getElementById("cgst-display").textContent =
      `₹ ${Math.round(totals.totalCGST)}`;
    document.getElementById("sgst-display").textContent =
      `₹ ${Math.round(totals.totalSGST)}`;
    document.getElementById("igst-display").textContent =
      `₹ ${Math.round(totals.totalIGST)}`;
    document.getElementById("grand-total-display").textContent =
      `₹ ${Math.round(totals.grandTotal)}`;
    document.getElementById("item-discount-row").style.display =
      totals.totalItemDiscount > 0 ? "" : "none";
    document.getElementById("overall-discount-row").style.display =
      totals.overallDiscount > 0 ? "" : "none";
    document.getElementById("points-discount-row").style.display =
      totals.pointsDiscount > 0 ? "" : "none";
    document.getElementById("taxable-row").style.display =
      GST_TYPE === "gst" && totals.totalTaxable > 0 ? "" : "none";
    document.getElementById("cgst-row").style.display =
      GST_TYPE === "gst" && totals.totalCGST > 0 ? "" : "none";
    document.getElementById("sgst-row").style.display =
      GST_TYPE === "gst" && totals.totalSGST > 0 ? "" : "none";
    document.getElementById("igst-row").style.display =
      GST_TYPE === "gst" && totals.totalIGST > 0 ? "" : "none";
    let shippingRow = document.getElementById("shipping-charges-row");
    const summaryBox = document.querySelector(".total-summary-box");
    if (totals.shippingCharges > 0 || totals.transportCharge > 0) {
      if (!shippingRow && summaryBox) {
        const grandTotalRow = document.querySelector(
          ".total-summary-box .grand-total",
        );
        if (grandTotalRow) {
          shippingRow = document.createElement("div");
          shippingRow.id = "shipping-charges-row";
          shippingRow.className = "summary-row";
          grandTotalRow.parentNode.insertBefore(shippingRow, grandTotalRow);
        }
      }
      if (shippingRow) {
        let chargesHtml = "";
        if (totals.shippingCharges > 0)
          chargesHtml += `<div class="d-flex justify-content-between"><span><i class="fas fa-truck me-1"></i> Shipping:</span><span>₹ ${Math.round(totals.shippingCharges)}</span></div>`;
        if (totals.transportCharge > 0)
          chargesHtml += `<div class="d-flex justify-content-between mt-1"><span><i class="fas fa-truck-moving me-1"></i> Transport:</span><span>₹ ${Math.round(totals.transportCharge)}</span></div>`;
        shippingRow.innerHTML = chargesHtml;
        shippingRow.style.display = "";
      }
    } else if (shippingRow) shippingRow.style.display = "none";
    updatePaymentSummary();
    updateButtonStates();
  } catch (error) {
    console.error("Error updating billing summary:", error);
    showToast("Error updating bill summary. Please refresh page.", "danger");
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
        cardElement.classList.add("active");
        if (method === "credit")
          setTimeout(() => {
            updateCreditPaymentCard();
          }, 10);
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
        cardElement.classList.remove("active");
        const amountInput = cardElement.querySelector('input[type="number"]');
        if (amountInput) amountInput.value = "0";
        if (method === "credit") {
          const warning = document.querySelector(
            "#credit-input-card .credit-due-warning",
          );
          if (warning) warning.remove();
        }
      }
    }
    updatePaymentSummary();
  } catch (error) {
    console.error("Error handling payment method checkbox:", error);
    showToast("Error updating payment method. Please try again.", "danger");
  }
}

function updatePaymentSummary() {
  try {
    const totals = calculateTotals();
    const grandTotal = totals.grandTotal;
    const cashAmount = ACTIVE_PAYMENT_METHODS.has("cash")
      ? parseFloat(document.getElementById("cash-amount").value) || 0
      : 0;
    const upiAmount = ACTIVE_PAYMENT_METHODS.has("upi")
      ? parseFloat(document.getElementById("upi-amount").value) || 0
      : 0;
    const bankAmount = ACTIVE_PAYMENT_METHODS.has("bank")
      ? parseFloat(document.getElementById("bank-amount").value) || 0
      : 0;
    const chequeAmount = ACTIVE_PAYMENT_METHODS.has("cheque")
      ? parseFloat(document.getElementById("cheque-amount").value) || 0
      : 0;
    const creditAmount = ACTIVE_PAYMENT_METHODS.has("credit")
      ? parseFloat(document.getElementById("credit-amount").value) || 0
      : 0;
    const creditDueDate =
      document.getElementById("credit-due-date")?.value || null;
    const totalPaid =
      cashAmount + upiAmount + bankAmount + chequeAmount + creditAmount;
    const changeGiven = totalPaid > grandTotal ? totalPaid - grandTotal : 0;
    const pendingAmount = totalPaid < grandTotal ? grandTotal - totalPaid : 0;
    document.getElementById("total-paid").value = `₹ ${Math.round(totalPaid)}`;
    document.getElementById("change-given").value =
      `₹ ${Math.round(changeGiven)}`;
    document.getElementById("pending-amount").value =
      `₹ ${Math.round(pendingAmount)}`;
    showPaymentDistribution({
      cash: cashAmount,
      upi: upiAmount,
      bank: bankAmount,
      cheque: chequeAmount,
      credit: creditAmount,
      credit_due_date: creditDueDate,
      totalPaid: totalPaid,
      grandTotal: grandTotal,
      change: changeGiven,
      pending: pendingAmount,
    });
    updateGenerateBillButton(pendingAmount, totalPaid);
    if (creditAmount > 0) showCreditDueDateWarning();
  } catch (error) {
    console.error("Error updating payment summary:", error);
    showToast(
      "Error updating payment summary. Please check amounts.",
      "danger",
    );
  }
}

function updateGenerateBillButton(pendingAmount, totalPaid) {
  const generateBillBtn = document.getElementById("btnGenerateBill");
  if (!generateBillBtn) return;
  if (pendingAmount === 0 && totalPaid > 0) {
    generateBillBtn.disabled = false;
    generateBillBtn.title = "Click to generate and save bill";
    generateBillBtn.classList.remove("btn-secondary");
    generateBillBtn.classList.add("btn-primary");
  } else if (pendingAmount > 0) {
    generateBillBtn.disabled = true;
    generateBillBtn.title = `Cannot generate bill. Pending amount: ₹${pendingAmount.toFixed(2)}`;
    generateBillBtn.classList.remove("btn-primary");
    generateBillBtn.classList.add("btn-secondary");
  } else {
    generateBillBtn.disabled = true;
    generateBillBtn.title = "Please enter payment amounts";
    generateBillBtn.classList.remove("btn-primary");
    generateBillBtn.classList.add("btn-secondary");
  }
}

function showPaymentDistribution(paymentData) {
  try {
    let distributionHTML = `<div class="amount-distribution"><h6><i class="fas fa-money-bill-wave me-1"></i> Payment Distribution</h6>`;
    if (paymentData.cash > 0)
      distributionHTML += `<div class="amount-distribution-row"><span><i class="fas fa-money-bill-wave me-1"></i> Cash:</span><span>₹ ${Math.round(paymentData.cash)}</span></div>`;
    if (paymentData.upi > 0)
      distributionHTML += `<div class="amount-distribution-row"><span><i class="fas fa-mobile-alt me-1"></i> UPI:</span><span>₹ ${Math.round(paymentData.upi)}</span></div>`;
    if (paymentData.bank > 0)
      distributionHTML += `<div class="amount-distribution-row"><span><i class="fas fa-university me-1"></i> Bank:</span><span>₹ ${Math.round(paymentData.bank)}</span></div>`;
    if (paymentData.cheque > 0)
      distributionHTML += `<div class="amount-distribution-row"><span><i class="fas fa-money-check me-1"></i> Cheque:</span><span>₹ ${Math.round(paymentData.cheque)}</span></div>`;
    if (paymentData.credit > 0) {
      let dueDateInfo = "";
      if (paymentData.credit_due_date) {
        const today = new Date();
        const dueDate = new Date(paymentData.credit_due_date);
        const daysUntilDue = Math.ceil(
          (dueDate - today) / (1000 * 60 * 60 * 24),
        );
        if (daysUntilDue < 0)
          dueDateInfo = `<span class="due-date-badge overdue-badge" style="margin-left: 10px;"><i class="fas fa-exclamation-triangle me-1"></i> OVERDUE by ${Math.abs(daysUntilDue)} days</span>`;
        else if (daysUntilDue <= 7)
          dueDateInfo = `<span class="due-date-badge" style="margin-left: 10px; background-color: #ffc107;"><i class="fas fa-clock me-1"></i> Due in ${daysUntilDue} days</span>`;
        else
          dueDateInfo = `<span class="due-date-badge" style="margin-left: 10px;"><i class="fas fa-calendar-check me-1"></i> Due: ${paymentData.credit_due_date}</span>`;
      }
      distributionHTML += `<div class="amount-distribution-row"><span><i class="fas fa-credit-card me-1"></i> Credit:</span><span>₹ ${Math.round(paymentData.credit)} ${dueDateInfo}</span></div>`;
    }
    distributionHTML += `<hr style="margin: 5px 0;"><div class="amount-distribution-row" style="font-weight: bold;"><span>Total Paid:</span><span style="color: #0d6efd;">₹ ${Math.round(paymentData.totalPaid)}</span></div><div class="amount-distribution-row"><span>Bill Amount:</span><span>₹ ${Math.round(paymentData.grandTotal)}</span></div>`;
    if (paymentData.change > 0)
      distributionHTML += `<div class="amount-distribution-row" style="color: #28a745;"><span><i class="fas fa-hand-holding-usd me-1"></i> Change to Give:</span><span>₹ ${Math.round(paymentData.change)}</span></div>`;
    if (paymentData.pending > 0)
      distributionHTML += `<div class="amount-distribution-row" style="color: #fd7e14;"><span><i class="fas fa-exclamation-triangle me-1"></i> Pending Amount:</span><span>₹ ${Math.round(paymentData.pending)}</span></div>`;
    distributionHTML += `</div>`;
    let distributionContainer = document.getElementById("paymentDistribution");
    if (!distributionContainer) {
      distributionContainer = document.createElement("div");
      distributionContainer.id = "paymentDistribution";
      const paymentGrid = document.querySelector(".payment-inputs-grid");
      if (paymentGrid) paymentGrid.after(distributionContainer);
    }
    distributionContainer.innerHTML = distributionHTML;
  } catch (error) {
    console.error("Error showing payment distribution:", error);
  }
}

function autoFillRemainingAmount() {
  try {
    const totals = calculateTotals();
    const grandTotal = totals.grandTotal;
    if (grandTotal === 0) {
      showToast("No bill amount to fill. Add items to cart first.", "warning");
      return;
    }
    const cashAmount = ACTIVE_PAYMENT_METHODS.has("cash")
      ? parseFloat(document.getElementById("cash-amount").value) || 0
      : 0;
    const upiAmount = ACTIVE_PAYMENT_METHODS.has("upi")
      ? parseFloat(document.getElementById("upi-amount").value) || 0
      : 0;
    const bankAmount = ACTIVE_PAYMENT_METHODS.has("bank")
      ? parseFloat(document.getElementById("bank-amount").value) || 0
      : 0;
    const chequeAmount = ACTIVE_PAYMENT_METHODS.has("cheque")
      ? parseFloat(document.getElementById("cheque-amount").value) || 0
      : 0;
    const creditAmount = ACTIVE_PAYMENT_METHODS.has("credit")
      ? parseFloat(document.getElementById("credit-amount").value) || 0
      : 0;
    const totalPaid =
      cashAmount + upiAmount + bankAmount + chequeAmount + creditAmount;
    const remaining = grandTotal - totalPaid;
    if (remaining <= 0) {
      showToast("Payment already complete or exceeded", "info");
      return;
    }
    const methods = ["cash", "upi", "bank", "cheque", "credit"];
    for (const method of methods) {
      if (ACTIVE_PAYMENT_METHODS.has(method)) {
        const amountInput = document.getElementById(`${method}-amount`);
        if (parseFloat(amountInput.value) === 0) {
          amountInput.value = Math.round(remaining);
          amountInput.dispatchEvent(new Event("input"));
          showToast(
            `Auto-filled ₹${Math.round(remaining)} to ${method.toUpperCase()}`,
            "info",
          );
          return;
        }
      }
    }
    const firstMethod = Array.from(ACTIVE_PAYMENT_METHODS)[0];
    if (firstMethod) {
      const amountInput = document.getElementById(`${firstMethod}-amount`);
      const current = parseFloat(amountInput.value) || 0;
      amountInput.value = Math.round(current + remaining);
      amountInput.dispatchEvent(new Event("input"));
      showToast(
        `Added ₹${Math.round(remaining)} to ${firstMethod.toUpperCase()}`,
        "info",
      );
    } else showToast("Please select at least one payment method", "warning");
  } catch (error) {
    console.error("Error auto-filling remaining amount:", error);
    showToast("Error auto-filling amount. Please enter manually.", "danger");
  }
}

function collectPaymentData() {
  try {
    const cashAmount = ACTIVE_PAYMENT_METHODS.has("cash")
      ? parseFloat(document.getElementById("cash-amount").value) || 0
      : 0;
    const upiAmount = ACTIVE_PAYMENT_METHODS.has("upi")
      ? parseFloat(document.getElementById("upi-amount").value) || 0
      : 0;
    const bankAmount = ACTIVE_PAYMENT_METHODS.has("bank")
      ? parseFloat(document.getElementById("bank-amount").value) || 0
      : 0;
    const chequeAmount = ACTIVE_PAYMENT_METHODS.has("cheque")
      ? parseFloat(document.getElementById("cheque-amount").value) || 0
      : 0;
    const creditAmount = ACTIVE_PAYMENT_METHODS.has("credit")
      ? parseFloat(document.getElementById("credit-amount").value) || 0
      : 0;
    let creditDueDate = null;
    if (ACTIVE_PAYMENT_METHODS.has("credit") && creditAmount > 0)
      creditDueDate = document.getElementById("credit-due-date")?.value || null;
    return {
      cash: cashAmount,
      upi: upiAmount,
      bank: bankAmount,
      cheque: chequeAmount,
      credit: creditAmount,
      totalPaid:
        cashAmount + upiAmount + bankAmount + chequeAmount + creditAmount,
      upi_reference: document.getElementById("upi-reference").value || "",
      bank_reference: document.getElementById("bank-reference").value || "",
      cheque_number: document.getElementById("cheque-number").value || "",
      credit_reference: document.getElementById("credit-reference").value || "",
      credit_due_date: creditDueDate,
    };
  } catch (error) {
    console.error("Error collecting payment data:", error);
    return {
      cash: 0,
      upi: 0,
      bank: 0,
      cheque: 0,
      credit: 0,
      totalPaid: 0,
      upi_reference: "",
      bank_reference: "",
      cheque_number: "",
      credit_reference: "",
      credit_due_date: null,
    };
  }
}

// ==================== LOYALTY POINTS FUNCTIONS ====================
function handleCustomerSelection() {
  try {
    const selectedOption = this.options[this.selectedIndex];
    if (selectedOption && selectedOption.value) {
      const customerName = selectedOption.dataset.name;
      if (customerName)
        document.getElementById("customer-name").value = customerName;
      const address = selectedOption.dataset.address;
      const gstin = selectedOption.dataset.gstin;
      const creditLimit = selectedOption.dataset.creditLimit;
      const outstanding = selectedOption.dataset.outstanding;
      if (address) document.getElementById("customer-address").value = address;
      if (gstin) document.getElementById("customer-gstin").value = gstin;
      if (creditLimit && creditLimit > 0)
        showCustomerCreditInfo(customerName, creditLimit, outstanding);
      const customerId = selectedOption.dataset.customerId;
      if (customerId) {
        CURRENT_CUSTOMER_ID = customerId;
        loadCustomerPoints(customerId);
      } else hideLoyaltyPoints();
    } else {
      hideCustomerCreditInfo();
      hideLoyaltyPoints();
    }
  } catch (error) {
    console.error("Error handling customer selection:", error);
    hideCustomerCreditInfo();
    hideLoyaltyPoints();
  }
}

function showCustomerCreditInfo(name, limit, outstanding) {
  try {
    const available = Math.max(0, limit - outstanding);
    let creditInfo = document.getElementById("customer-credit-info");
    if (!creditInfo) {
      creditInfo = document.createElement("div");
      creditInfo.id = "customer-credit-info";
      creditInfo.className = "customer-credit-info alert alert-info mt-2";
      const customerSection = document.querySelector(".customer-section");
      if (customerSection) customerSection.appendChild(creditInfo);
    }
    creditInfo.innerHTML = `<div class="d-flex justify-content-between align-items-center"><small><strong>Credit Limit:</strong> ₹${Math.round(limit)}</small><small><strong>Outstanding:</strong> ₹${Math.round(outstanding)}</small><small><strong>Available:</strong> <span class="${available < 1000 ? "text-danger" : "text-success"}">₹${Math.round(available)}</span></small></div>`;
    creditInfo.style.display = "block";
  } catch (error) {
    console.error("Error showing credit info:", error);
  }
}

function hideCustomerCreditInfo() {
  try {
    const creditInfo = document.getElementById("customer-credit-info");
    if (creditInfo) creditInfo.style.display = "none";
  } catch (error) {
    console.error("Error hiding credit info:", error);
  }
}

async function loadCustomers() {
  console.log("POS System: Loading customers...");
  try {
    const response = await fetchWithTimeout("api/customers.php?action=list", {
      timeout: 5000,
    });
    if (!response.ok)
      throw new Error(`HTTP ${response.status}: ${response.statusText}`);
    const data = await response.json();
    if (data.success) {
      CUSTOMERS = data.customers || [];
      populateCustomerDropdown();
      console.log(`POS System: Loaded ${CUSTOMERS.length} customers`);
      return true;
    } else return false;
  } catch (error) {
    console.error("POS System: Customer loading failed:", error);
    throw new Error(`Cannot load customers: ${error.message}`);
  }
}

function populateCustomerDropdown() {
  const select = document.getElementById("customer-contact");
  if (!select) return;
  try {
    select.innerHTML = '<option value="">-- Select phone --</option>';
    CUSTOMERS.forEach((customer) => {
      if (!customer.phone || !customer.name) return;
      const option = document.createElement("option");
      option.value = customer.phone;
      option.textContent = `${customer.phone} - ${customer.name}`;
      option.dataset.customerId = customer.id || "";
      option.dataset.name = customer.name;
      option.dataset.address = customer.address || "";
      option.dataset.gstin = customer.gstin || "";
      option.dataset.creditLimit = customer.credit_limit || 0;
      option.dataset.outstanding = customer.outstanding_amount || 0;
      select.appendChild(option);
    });
    $("#customer-contact").trigger("change.select2");
  } catch (error) {
    console.error("POS System: Error populating customer dropdown:", error);
  }
}

async function loadReferrals() {
  console.log("POS System: Loading referrals...");
  try {
    const response = await fetchWithTimeout("api/referrals.php?action=list", {
      timeout: 5000,
    });
    if (!response.ok) return false;
    const data = await response.json();
    if (data.success) {
      REFERRALS = data.referrals || [];
      populateReferralDropdown();
      console.log(`POS System: Loaded ${REFERRALS.length} referrals`);
      return true;
    }
    return false;
  } catch (error) {
    console.warn("POS System: Referral loading failed:", error);
    return false;
  }
}

function populateReferralDropdown() {
  const select = document.getElementById("referral");
  if (!select) return;
  try {
    select.innerHTML = '<option value="">-- No referral --</option>';
    REFERRALS.forEach((referral) => {
      if (!referral.id || !referral.full_name) return;
      const option = document.createElement("option");
      option.value = referral.id;
      option.textContent = `${referral.full_name} (${referral.referral_code || "No Code"})`;
      select.appendChild(option);
    });
    $("#referral").trigger("change.select2");
  } catch (error) {
    console.error("POS System: Error populating referral dropdown:", error);
  }
}

async function loadLoyaltySettings() {
  console.log("POS System: Loading loyalty settings...");
  try {
    const response = await fetchWithTimeout("api/loyalty.php?action=settings", {
      timeout: 5000,
    });
    if (!response.ok) {
      LOYALTY_SETTINGS = getDefaultLoyaltySettings();
      return true;
    }
    const data = await response.json();
    if (data.success)
      LOYALTY_SETTINGS = data.settings || getDefaultLoyaltySettings();
    else LOYALTY_SETTINGS = getDefaultLoyaltySettings();
    return true;
  } catch (error) {
    console.warn("POS System: Loyalty settings loading failed:", error);
    LOYALTY_SETTINGS = getDefaultLoyaltySettings();
    return true;
  }
}

function getDefaultLoyaltySettings() {
  return {
    points_per_amount: 0.01,
    amount_per_point: 100.0,
    redeem_value_per_point: 1.0,
    min_points_to_redeem: 50,
    expiry_months: null,
    is_active: 1,
  };
}

async function loadCustomerPoints(customerId) {
  try {
    if (!customerId) {
      hideLoyaltyPoints();
      return;
    }
    const response = await fetchWithTimeout(
      `api/loyalty.php?action=customer_points&customer_id=${customerId}`,
      { timeout: 5000 },
    );
    if (!response.ok) throw new Error(`HTTP ${response.status}`);
    const data = await response.json();
    if (data.success) {
      CUSTOMER_POINTS = data.points || {
        available_points: 0,
        total_points_earned: 0,
        total_points_redeemed: 0,
      };
      showLoyaltyPoints();
      updateLoyaltyPointsDisplay();
      const applyBtn = document.getElementById("btnShowPointsDetails");
      if (
        CUSTOMER_POINTS.available_points >=
        LOYALTY_SETTINGS.min_points_to_redeem
      ) {
        applyBtn.disabled = false;
        applyBtn.title = "Apply loyalty points discount";
      } else {
        applyBtn.disabled = true;
        applyBtn.title = `Minimum ${LOYALTY_SETTINGS.min_points_to_redeem} points required`;
      }
    } else hideLoyaltyPoints();
  } catch (error) {
    console.warn("Error loading customer points:", error);
    hideLoyaltyPoints();
  }
}

function showLoyaltyPoints() {
  try {
    const loyaltySection = document.querySelector(".loyalty-point");
    if (loyaltySection) loyaltySection.style.display = "flex";
  } catch (error) {
    console.error("Error showing loyalty points:", error);
  }
}
function hideLoyaltyPoints() {
  try {
    const loyaltySection = document.querySelector(".loyalty-point");
    if (loyaltySection) loyaltySection.style.display = "none";
    CUSTOMER_POINTS = {
      available_points: 0,
      total_points_earned: 0,
      total_points_redeemed: 0,
    };
    LOYALTY_POINTS_DISCOUNT = 0;
    POINTS_USED = 0;
    CURRENT_CUSTOMER_ID = null;
    updateBillingSummary();
  } catch (error) {
    console.error("Error hiding loyalty points:", error);
  }
}
function updateLoyaltyPointsDisplay() {
  try {
    document.getElementById("customerPointsDisplay").textContent =
      CUSTOMER_POINTS.available_points;
  } catch (error) {
    console.error("Error updating loyalty points display:", error);
  }
}

function showPointsModal() {
  try {
    if (
      CUSTOMER_POINTS.available_points < LOYALTY_SETTINGS.min_points_to_redeem
    ) {
      showWarningToast(
        `Minimum ${LOYALTY_SETTINGS.min_points_to_redeem} points required to redeem`,
      );
      return;
    }
    document.getElementById("modalPointsValue").textContent =
      CUSTOMER_POINTS.available_points;
    document.getElementById("modalTotalEarned").textContent =
      CUSTOMER_POINTS.total_points_earned;
    document.getElementById("modalTotalRedeemed").textContent =
      CUSTOMER_POINTS.total_points_redeemed;
    document.getElementById("redeemValuePerPoint").textContent =
      LOYALTY_SETTINGS.redeem_value_per_point.toFixed(2);
    const totals = calculateTotals();
    const maxPoints = Math.min(
      CUSTOMER_POINTS.available_points,
      Math.floor(totals.grandTotal / LOYALTY_SETTINGS.redeem_value_per_point),
    );
    const pointsInput = document.getElementById("pointsToRedeem");
    pointsInput.max = maxPoints;
    pointsInput.value = POINTS_USED;
    updatePointsDiscountPreview();
    const modal = new bootstrap.Modal(document.getElementById("pointsModal"));
    modal.show();
  } catch (error) {
    console.error("Error showing points modal:", error);
    showErrorToast("Error loading points information. Please try again.");
  }
}

function updatePointsDiscountPreview() {
  try {
    let points = parseInt(document.getElementById("pointsToRedeem").value) || 0;
    const maxPoints =
      parseInt(document.getElementById("pointsToRedeem").max) || 0;
    if (points < 0) points = 0;
    if (points > maxPoints) {
      points = maxPoints;
      document.getElementById("pointsToRedeem").value = maxPoints;
    }
    const discount = points * LOYALTY_SETTINGS.redeem_value_per_point;
    document.getElementById("modalPointsDiscount").textContent =
      Math.round(discount);
  } catch (error) {
    console.error("Error updating points discount preview:", error);
  }
}

function useMaxPoints() {
  try {
    const maxPoints =
      parseInt(document.getElementById("pointsToRedeem").max) || 0;
    document.getElementById("pointsToRedeem").value = maxPoints;
    updatePointsDiscountPreview();
  } catch (error) {
    console.error("Error using max points:", error);
  }
}

function applyPointsDiscount() {
  try {
    const points =
      parseInt(document.getElementById("pointsToRedeem").value) || 0;
    if (points < 1) {
      showToast("Please enter points to redeem", "warning");
      return;
    }
    if (points > CUSTOMER_POINTS.available_points) {
      showToast("Cannot redeem more points than available", "danger");
      return;
    }
    const discount = points * LOYALTY_SETTINGS.redeem_value_per_point;
    const totals = calculateTotals();
    if (discount > totals.grandTotal) {
      showToast("Discount cannot exceed grand total", "warning");
      return;
    }
    POINTS_USED = points;
    LOYALTY_POINTS_DISCOUNT = discount;
    const modal = bootstrap.Modal.getInstance(
      document.getElementById("pointsModal"),
    );
    if (modal) modal.hide();
    updateBillingSummary();
    showToast(
      `Applied ${points} points for ₹${Math.round(discount)} discount`,
      "success",
    );
  } catch (error) {
    console.error("Error applying points discount:", error);
    showToast("Error applying points discount. Please try again.", "danger");
  }
}

// ==================== INVOICE FUNCTIONS ====================
function generateInvoiceNumber() {
  try {
    const prefix = GST_TYPE === "gst" ? "INV" : "INVNG";
    const now = new Date();
    const year = now.getFullYear();
    const month = (now.getMonth() + 1).toString().padStart(2, "0");
    const yearMonth = year.toString() + month;
    const tempNumber = `${prefix}${yearMonth}-9999`;
    const invoiceInput = document.getElementById("invoice-number");
    if (invoiceInput) invoiceInput.value = tempNumber;
    fetchLatestInvoiceNumber(prefix, yearMonth);
    return tempNumber;
  } catch (error) {
    console.error("POS System: Error generating invoice number:", error);
    return "INV-ERROR-" + Date.now();
  }
}

async function fetchLatestInvoiceNumber(prefix, yearMonth) {
  try {
    const response = await fetchWithTimeout(
      "api/invoices.php?action=get_next_invoice_number",
      {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
          Accept: "application/json",
        },
        body: JSON.stringify({
          prefix: prefix,
          year_month: yearMonth,
          invoice_type: GST_TYPE,
        }),
        timeout: 5000,
      },
    );
    if (!response.ok) {
      console.warn("Could not fetch latest invoice number from server");
      return;
    }
    const data = await response.json();
    if (data.success && data.invoice_number) {
      document.getElementById("invoice-number").value = data.invoice_number;
      console.log("Updated invoice number to:", data.invoice_number);
    }
  } catch (error) {
    console.warn("Error fetching latest invoice number:", error);
  }
}

let isGeneratingBill = false;

async function generateBill() {
  try {
    if (isGeneratingBill) {
      console.log("Generate Bill already in progress, skipping...");
      return;
    }
    isGeneratingBill = true;
    console.log("🟢 GENERATE BILL - STARTED ====================");
    if (CART.length === 0) {
      console.warn("❌ Generate Bill Failed: Empty cart");
      showWarningToast("Please add items to cart first");
      return;
    }
    const customerName = document.getElementById("customer-name").value.trim();
    if (!customerName) {
      console.warn("❌ Generate Bill Failed: Customer name required");
      showWarningModal(
        "Customer Required",
        "Customer name is required to generate bill",
      );
      document.getElementById("customer-name").focus();
      document.getElementById("customer-name").select();
      return;
    }
    await checkAndGenerateInvoiceNumber();
    const currentInvoiceNumber =
      document.getElementById("invoice-number").value;
    console.log("Using invoice number:", currentInvoiceNumber);
    if (CURRENT_CUSTOMER_ID) {
      console.log(
        "💰 Checking credit limit for customer ID:",
        CURRENT_CUSTOMER_ID,
      );
      const creditCheck = await checkCustomerCreditLimit();
      if (!creditCheck.allowed) {
        console.warn(
          "❌ Generate Bill Failed: Credit limit exceeded",
          creditCheck,
        );
        showWarningModal("Credit Limit Exceeded", creditCheck.message);
        return;
      }
      console.log("✅ Credit check passed");
    }
    console.log("📊 Stock Validation:");
    for (const item of CART) {
      const product = findProductById(item.product_id);
      if (!product) {
        console.warn(
          "❌ Generate Bill Failed: Product not found for item",
          item,
        );
        showErrorToast(`Product not found for ${item.name}`);
        return;
      }
      let availableStock =
        product.shop_stock_primary || product.shop_stock || 0;
      let quantityToCheck = item.quantity_in_primary || item.quantity;
      if (quantityToCheck > availableStock) {
        console.warn("❌ Generate Bill Failed: Insufficient stock for item", {
          item_name: item.name,
          requested_qty: item.quantity,
          requested_in_primary: quantityToCheck,
          available_stock: availableStock,
        });
        let errorMessage = `Insufficient stock for ${item.name}. Available: ${availableStock} ${product.unit_of_measure}`;
        if (
          item.is_secondary_unit &&
          product.secondary_unit &&
          product.sec_unit_conversion
        ) {
          const availableSecondary = Math.floor(
            availableStock * product.sec_unit_conversion,
          );
          errorMessage = `Insufficient stock for ${item.name}. Available: ${availableStock} ${product.unit_of_measure} (≈${availableSecondary} ${product.secondary_unit})`;
        }
        showWarningModal("Stock Insufficient", errorMessage);
        return;
      }
    }
    console.log("✅ All items have sufficient stock");
    const totals = calculateTotals();
    const paymentData = collectPaymentData();
    if (paymentData.totalPaid === 0) {
      console.warn("❌ Generate Bill Failed: No payment entered");
      showWarningToast("Please enter payment amounts");
      return;
    }
    if (paymentData.totalPaid < totals.grandTotal) {
      const pending = totals.grandTotal - paymentData.totalPaid;
      console.warn("❌ Generate Bill Failed: Insufficient payment", {
        grand_total: totals.grandTotal,
        total_paid: paymentData.totalPaid,
        pending_amount: pending,
      });
      showWarningModal(
        "Insufficient Payment",
        `Pending amount: ₹${Math.round(pending)}`,
      );
      return;
    }
    const invoiceData = {
      customer_name: customerName,
      customer_phone: document.getElementById("customer-contact").value || "",
      customer_address: document.getElementById("customer-address").value || "",
      customer_gstin: document.getElementById("customer-gstin").value || "",
      customer_id: CURRENT_CUSTOMER_ID,
      invoice_number: document.getElementById("invoice-number").value,
      invoice_type: GST_TYPE,
      date: document.getElementById("date").value,
      price_type: GLOBAL_PRICE_TYPE,
      referral_id: SELECTED_REFERRAL_ID,
      points_used: POINTS_USED,
      points_discount: totals.pointsDiscount,
      subtotal: totals.subtotal,
      discount: document.getElementById("additional-dis").value,
      discount_type: document.getElementById("overall-discount-type").value,
      overall_discount: totals.overallDiscount,
      total_cgst: totals.totalCGST,
      total_sgst: totals.totalSGST,
      total_igst: totals.totalIGST,
      total_taxable: totals.totalTaxable,
      total_gst: totals.totalGST,
      grand_total: totals.grandTotal,
      referral_commission: totals.totalReferralCommission,
      shipping_details: {
        name: SHIPPING_DETAILS.name,
        contact: SHIPPING_DETAILS.contact,
        gstin: SHIPPING_DETAILS.gstin,
        address: SHIPPING_DETAILS.address,
        vehicle_number: SHIPPING_DETAILS.vehicle_number,
        charges: SHIPPING_DETAILS.charges,
      },
      transport_details: {
        type: TRANSPORT_DETAILS.type,
        charge: TRANSPORT_DETAILS.charge,
      },
      items: CART.map((item) => ({
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
        quantity_in_primary:
          item.quantity_in_primary ||
          (item.is_secondary_unit
            ? item.quantity / item.sec_unit_conversion
            : item.quantity),
      })),
      payment_method: Array.from(ACTIVE_PAYMENT_METHODS).join("+"),
      payment_details: paymentData,
      pending_amount:
        paymentData.totalPaid < totals.grandTotal
          ? totals.grandTotal - paymentData.totalPaid
          : 0,
    };
    console.log(
      "📤 Invoice Data to be saved:",
      JSON.stringify(invoiceData, null, 2),
    );
    showLoading("Saving invoice...");
    try {
      console.log("🌐 Sending invoice data to server...");
      const response = await fetchWithTimeout("api/invoices.php?action=save", {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
          Accept: "application/json",
        },
        body: JSON.stringify(invoiceData),
        timeout: 15000,
      });
      console.log(
        "📥 Server Response Status:",
        response.status,
        response.statusText,
      );
      if (!response.ok)
        throw new Error(`HTTP ${response.status}: ${response.statusText}`);
      const data = await response.json();
      console.log("📊 Server Response Data:", data);
      if (data.success) {
        const invoiceId = data.invoice_id || invoiceData.invoice_number;
        console.log("✅ Invoice Saved Successfully!", {
          invoice_number: invoiceData.invoice_number,
          invoice_id: invoiceId,
          timestamp: new Date().toISOString(),
        });
        hideLoading();
        showSuccessModal(
          "Invoice Saved Successfully!",
          `Invoice #${invoiceData.invoice_number} has been saved.`,
          function () {
            CART = [];
            clearCartFromSession();
            SHIPPING_DETAILS = {
              name: "",
              contact: "",
              gstin: "",
              address: "",
              vehicle_number: "",
              charges: 0,
            };
            TRANSPORT_DETAILS = { type: "", charge: 0 };
            sessionStorage.removeItem("pos_shipping_details");
            sessionStorage.removeItem("pos_transport_details");
            updateShippingDetailsHorizontal();
            updateTransportDisplay();
            const shippingRow = document.getElementById("shipping-charges-row");
            if (shippingRow) shippingRow.style.display = "none";
            resetForm();
          },
        );
      } else {
        console.error("❌ Server returned error:", data);
        hideLoading();
        throw new Error(data.message || "Unknown server error");
      }
    } catch (fetchError) {
      console.error("❌ Invoice Save Error:", fetchError);
      hideLoading();
      showErrorModal("Failed to Save Invoice", fetchError.message);
    }
    console.log("🟢 GENERATE BILL - COMPLETED ====================");
  } catch (error) {
    console.error("❌ Generate Bill - Unhandled Error:", error);
    hideLoading();
    showErrorModal("Error", `Error generating bill: ${error.message}`);
  } finally {
    setTimeout(() => {
      isGeneratingBill = false;
    }, 1000);
  }
}

async function checkCustomerCreditLimit() {
  try {
    if (!CURRENT_CUSTOMER_ID) return { allowed: true, message: "" };
    const response = await fetchWithTimeout(
      `api/customers.php?action=credit_check&customer_id=${CURRENT_CUSTOMER_ID}`,
      { timeout: 5000 },
    );
    if (!response.ok)
      return { allowed: true, message: "Unable to check credit limit" };
    const data = await response.json();
    if (data.success && data.has_credit_limit) {
      const totals = calculateTotals();
      const pendingAmount = totals.grandTotal - collectPaymentData().totalPaid;
      if (pendingAmount > 0 && data.available_credit < pendingAmount)
        return {
          allowed: false,
          message: `Credit limit exceeded! Available: ₹${data.available_credit}, Required: ₹${pendingAmount}`,
        };
    }
    return { allowed: true, message: "" };
  } catch (error) {
    console.warn("Credit check error:", error);
    return { allowed: true, message: "Credit check failed" };
  }
}

async function printBill() {
  try {
    if (isGeneratingBill) {
      console.log("Print Bill already in progress, skipping...");
      return;
    }
    isGeneratingBill = true;
    console.log("🟢 PRINT BILL - STARTED ====================");
    if (CART.length === 0) {
      console.warn("❌ Print Bill Failed: Empty cart");
      showWarningToast("Please add items to cart first");
      isGeneratingBill = false;
      return;
    }
    const customerName = document.getElementById("customer-name").value.trim();
    if (!customerName) {
      console.warn("❌ Print Bill Failed: Customer name required");
      showWarningModal(
        "Customer Required",
        "Customer name is required to print bill",
      );
      document.getElementById("customer-name").focus();
      document.getElementById("customer-name").select();
      isGeneratingBill = false;
      return;
    }
    await checkAndGenerateInvoiceNumber();
    const currentInvoiceNumber =
      document.getElementById("invoice-number").value;
    console.log("Using invoice number for print:", currentInvoiceNumber);
    showLoading("Preparing invoice for print...");
    const totals = calculateTotals();
    const paymentData = collectPaymentData();
    const invoiceData = {
      action: "print",
      customer_name: customerName,
      customer_phone: document.getElementById("customer-contact").value || "",
      customer_address: document.getElementById("customer-address").value || "",
      customer_gstin: document.getElementById("customer-gstin").value || "",
      customer_id: CURRENT_CUSTOMER_ID,
      invoice_number: currentInvoiceNumber,
      invoice_type: GST_TYPE,
      date: document.getElementById("date").value,
      price_type: GLOBAL_PRICE_TYPE,
      referral_id: SELECTED_REFERRAL_ID,
      points_used: POINTS_USED,
      points_discount: totals.pointsDiscount,
      subtotal: totals.subtotal,
      discount: document.getElementById("additional-dis").value,
      discount_type: document.getElementById("overall-discount-type").value,
      overall_discount: totals.overallDiscount,
      total_cgst: totals.totalCGST,
      total_sgst: totals.totalSGST,
      total_igst: totals.totalIGST,
      total_taxable: totals.totalTaxable,
      total_gst: totals.totalGST,
      grand_total: totals.grandTotal,
      referral_commission: totals.totalReferralCommission,
      shipping_details: {
        name: SHIPPING_DETAILS.name,
        contact: SHIPPING_DETAILS.contact,
        gstin: SHIPPING_DETAILS.gstin,
        address: SHIPPING_DETAILS.address,
        vehicle_number: SHIPPING_DETAILS.vehicle_number,
        charges: SHIPPING_DETAILS.charges,
      },
      transport_details: {
        type: TRANSPORT_DETAILS.type,
        charge: TRANSPORT_DETAILS.charge,
      },
      items: CART.map((item) => ({
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
        quantity_in_primary:
          item.quantity_in_primary ||
          (item.is_secondary_unit
            ? item.quantity / item.sec_unit_conversion
            : item.quantity),
      })),
      payment_method: Array.from(ACTIVE_PAYMENT_METHODS).join("+"),
      payment_details: paymentData,
      pending_amount:
        paymentData.totalPaid < totals.grandTotal
          ? totals.grandTotal - paymentData.totalPaid
          : 0,
    };
    console.log("📤 Sending invoice data for print...", invoiceData);
    try {
      const response = await fetchWithTimeout(
        "api/invoices.php?action=save_for_print",
        {
          method: "POST",
          headers: {
            "Content-Type": "application/json",
            Accept: "application/json",
          },
          body: JSON.stringify(invoiceData),
          timeout: 15000,
        },
      );
      console.log(
        "📥 Print Save Response:",
        response.status,
        response.statusText,
      );
      if (!response.ok)
        throw new Error(`HTTP ${response.status}: ${response.statusText}`);
      const data = await response.json();
      console.log("📊 Print Save Response Data:", data);
      if (data.success) {
        const invoiceId = data.invoice_id;
        console.log("✅ Invoice Saved for Print! Invoice ID:", invoiceId);
        hideLoading();
        CART = [];
        clearCartFromSession();
        SHIPPING_DETAILS = {
          name: "",
          contact: "",
          gstin: "",
          address: "",
          vehicle_number: "",
          charges: 0,
        };
        TRANSPORT_DETAILS = { type: "", charge: 0 };
        sessionStorage.removeItem("pos_shipping_details");
        sessionStorage.removeItem("pos_transport_details");
        updateShippingDetailsHorizontal();
        updateTransportDisplay();
        showSuccessModal(
          "Invoice Saved Successfully!",
          "Opening print preview...",
          function () {
            if (data.print_url) {
              const printWindow = window.open(
                data.print_url,
                "_blank",
                "width=900,height=700",
              );
              if (!printWindow)
                showWarningToast(
                  "Popup blocked. Please allow popups for print preview.",
                );
              else printWindow.focus();
            } else {
              const defaultPrintUrl = `invoice_print.php?invoice_id=${invoiceId}`;
              const printWindow = window.open(
                defaultPrintUrl,
                "_blank",
                "width=900,height=700",
              );
              if (!printWindow)
                showWarningToast(
                  "Popup blocked. Please allow popups for print preview.",
                );
              else printWindow.focus();
            }
            setTimeout(() => {
              resetForm();
            }, 500);
          },
        );
      } else {
        console.error("❌ Print Save Error from server:", data.message);
        hideLoading();
        throw new Error(data.message || "Unknown server error");
      }
    } catch (fetchError) {
      console.error("❌ Print Bill Save Error:", fetchError);
      hideLoading();
      showErrorModal("Failed to Save Invoice", fetchError.message);
    }
    console.log("🟢 PRINT BILL - COMPLETED ====================");
  } catch (error) {
    console.error("❌ Print Bill - Unhandled Error:", error);
    hideLoading();
    showErrorModal("Error", `Error processing print: ${error.message}`);
    isGeneratingBill = false;
    const printBtn = document.getElementById("btnPrintBill");
    if (printBtn) {
      printBtn.disabled = false;
      printBtn.innerHTML = '<i class="fas fa-print me-1"></i> Print Bill';
    }
  }
}

function resetForm() {
  try {
    console.log("Resetting form...");
    CART = [];
    renderCart();
    document.getElementById("customer-name").value = "Walk-in Customer";
    $("#customer-contact").val("").trigger("change");
    document.getElementById("customer-address").value = "";
    document.getElementById("customer-gstin").value = "";
    $("#referral").val("").trigger("change");
    SELECTED_REFERRAL_ID = null;
    document
      .querySelectorAll('input[name="payment-method"]')
      .forEach((checkbox) => {
        checkbox.checked = checkbox.value === "cash";
      });
    document.getElementById("cash-amount").value = "0";
    document.getElementById("upi-amount").value = "0";
    document.getElementById("bank-amount").value = "0";
    document.getElementById("cheque-amount").value = "0";
    document.getElementById("credit-amount").value = "0";
    document.getElementById("upi-reference").value = "";
    document.getElementById("bank-reference").value = "";
    document.getElementById("cheque-number").value = "";
    document.getElementById("credit-reference").value = "";
    const dueDateInput = document.getElementById("credit-due-date");
    if (dueDateInput) {
      const defaultDueDate = new Date();
      defaultDueDate.setDate(defaultDueDate.getDate() + CREDIT_DUE_DAYS);
      dueDateInput.value = defaultDueDate.toISOString().split("T")[0];
      CREDIT_DUE_DATE = dueDateInput.value;
    }
    document.querySelectorAll(".payment-input-card").forEach((card) => {
      card.classList.remove("active");
    });
    document.getElementById("cash-input-card").classList.add("active");
    ACTIVE_PAYMENT_METHODS = new Set(["cash"]);
    document.getElementById("additional-dis").value = "0";
    document.getElementById("overall-discount-type").value = "percentage";
    hideLoyaltyPoints();
    const distributionContainer = document.getElementById(
      "paymentDistribution",
    );
    if (distributionContainer) distributionContainer.remove();
    SHIPPING_DETAILS = {
      name: "",
      contact: "",
      gstin: "",
      address: "",
      vehicle_number: "",
      charges: 0,
    };
    TRANSPORT_DETAILS = { type: "", charge: 0 };
    sessionStorage.removeItem("pos_shipping_details");
    sessionStorage.removeItem("pos_transport_details");
    updateShippingDetailsHorizontal();
    updateTransportDisplay();
    generateInvoiceNumber();
    updateBillingSummary();
    clearProductSelection();
    document.getElementById("barcode-input").focus();
    showToast("Form reset successfully. Ready for next sale!", "success");
  } catch (error) {
    console.error("Error resetting form:", error);
    showToast("Error resetting form. Please refresh page.", "danger");
  }
}

// ==================== HELPER FUNCTIONS ====================
function escapeHtml(text) {
  if (!text) return "";
  const div = document.createElement("div");
  div.textContent = text;
  return div.innerHTML;
}

function showToast(message, type = "info") {
  try {
    const toastContainer = document.getElementById("toastContainer");
    if (toastContainer) {
      const toastId =
        "toast-" + Date.now() + "-" + Math.random().toString(36).substr(2, 9);
      const iconMap = {
        success: "fas fa-check-circle text-success",
        info: "fas fa-info-circle text-info",
        warning: "fas fa-exclamation-triangle text-warning",
        danger: "fas fa-exclamation-circle text-danger",
      };
      const iconClass = iconMap[type] || iconMap["info"];
      const toastHTML = `<div id="${toastId}" class="toast custom-toast align-items-center border-0 bg-white shadow-sm mb-2" role="alert" aria-live="assertive" aria-atomic="true"><div class="d-flex"><div class="toast-body d-flex align-items-center"><i class="${iconClass} me-2 fs-5"></i><span class="flex-grow-1">${escapeHtml(message)}</span><button type="button" class="btn-close btn-close-sm ms-2" data-bs-dismiss="toast" aria-label="Close"></button></div></div></div>`;
      toastContainer.insertAdjacentHTML("afterbegin", toastHTML);
      const toastElement = document.getElementById(toastId);
      const toast = new bootstrap.Toast(toastElement, {
        autohide: true,
        delay: type === "danger" ? 5000 : 3000,
      });
      toast.show();
      toastElement.addEventListener("hidden.bs.toast", function () {
        if (toastElement.parentNode) toastElement.remove();
      });
    } else {
      switch (type) {
        case "success":
          showSuccessToast(message);
          break;
        case "danger":
        case "error":
          showErrorToast(message);
          break;
        case "warning":
          showWarningToast(message);
          break;
        default:
          showInfoToast(message);
      }
    }
  } catch (error) {
    console.error("Error showing toast:", error);
    alert(message);
  }
}

function showConfirmation(title, message, callback) {
  Swal.fire({
    title: title,
    text: message,
    icon: "warning",
    showCancelButton: true,
    confirmButtonColor: "#d33",
    cancelButtonColor: "#3085d6",
    confirmButtonText: "Yes, proceed!",
    cancelButtonText: "Cancel",
    reverseButtons: true,
    focusCancel: true,
  }).then((result) => {
    if (result.isConfirmed && callback) callback();
  });
}
function showDeleteConfirmation(itemName, callback) {
  Swal.fire({
    title: "Delete Item?",
    html: `Are you sure you want to delete <strong>${escapeHtml(itemName)}</strong>?`,
    icon: "error",
    showCancelButton: true,
    confirmButtonColor: "#d33",
    cancelButtonColor: "#3085d6",
    confirmButtonText: "Yes, delete it!",
    cancelButtonText: "Cancel",
  }).then((result) => {
    if (result.isConfirmed && callback) callback();
  });
}
function showClearCartConfirmation(itemCount, callback) {
  Swal.fire({
    title: "Clear Cart?",
    html: `Are you sure you want to clear all <strong>${itemCount}</strong> items from the cart?`,
    icon: "warning",
    showCancelButton: true,
    confirmButtonColor: "#d33",
    cancelButtonColor: "#3085d6",
    confirmButtonText: "Yes, clear cart!",
    cancelButtonText: "Cancel",
  }).then((result) => {
    if (result.isConfirmed && callback) callback();
  });
}
function executePendingConfirmation() {
  try {
    if (PENDING_CONFIRMATION) PENDING_CONFIRMATION();
    PENDING_CONFIRMATION = null;
    const modal = bootstrap.Modal.getInstance(
      document.getElementById("confirmationModal"),
    );
    if (modal) modal.hide();
  } catch (error) {
    console.error("Error executing confirmation:", error);
    showToast("Error executing action. Please try again.", "danger");
  }
}
function showSuccessModal(title, message, callback) {
  Swal.fire({
    title: title,
    text: message,
    icon: "success",
    confirmButtonColor: "#3085d6",
    confirmButtonText: "OK",
  }).then((result) => {
    if (result.isConfirmed && callback) callback();
  });
}
function showErrorModal(title, message) {
  Swal.fire({
    title: title,
    text: message,
    icon: "error",
    confirmButtonColor: "#3085d6",
    confirmButtonText: "OK",
  });
}
function showWarningModal(title, message) {
  Swal.fire({
    title: title,
    text: message,
    icon: "warning",
    confirmButtonColor: "#3085d6",
    confirmButtonText: "OK",
  });
}
function showInfoModal(title, message) {
  Swal.fire({
    title: title,
    text: message,
    icon: "info",
    confirmButtonColor: "#3085d6",
    confirmButtonText: "OK",
  });
}
function showPromptModal(
  title,
  inputLabel,
  inputPlaceholder,
  callback,
  defaultValue = "",
) {
  Swal.fire({
    title: title,
    input: "text",
    inputLabel: inputLabel,
    inputValue: defaultValue,
    inputPlaceholder: inputPlaceholder,
    showCancelButton: true,
    confirmButtonColor: "#3085d6",
    cancelButtonColor: "#d33",
    confirmButtonText: "Submit",
    cancelButtonText: "Cancel",
    inputValidator: (value) => {
      if (!value) return "This field is required!";
    },
  }).then((result) => {
    if (result.isConfirmed && callback) callback(result.value);
  });
}

let loadingSwal = null;
function showLoading(message = "Processing...") {
  loadingSwal = Swal.fire({
    title: message,
    allowOutsideClick: false,
    allowEscapeKey: false,
    showConfirmButton: false,
    didOpen: () => {
      Swal.showLoading();
    },
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
    const holdBtn = document.getElementById("btnHold");
    if (holdBtn) {
      holdBtn.disabled = !hasCartItems;
      holdBtn.title = hasCartItems
        ? "Hold current invoice"
        : "Add items to cart first";
    }
    const quotationBtn = document.getElementById("btnQuotation");
    if (quotationBtn) {
      quotationBtn.disabled = !hasCartItems;
      quotationBtn.title = hasCartItems
        ? "Save as quotation"
        : "Add items to cart first";
    }
    const clearBtn = document.getElementById("btnClearCart");
    if (clearBtn) {
      clearBtn.disabled = !hasCartItems;
      clearBtn.title = hasCartItems
        ? `Clear ${cartCount} items`
        : "Cart is empty";
    }
    const printBtn = document.getElementById("btnPrintBill");
    if (printBtn) {
      printBtn.disabled = !hasCartItems;
      printBtn.title = hasCartItems
        ? "Print bill preview"
        : "Add items to cart first";
    }
    const autoFillBtn = document.getElementById("btnAutoFillRemaining");
    if (autoFillBtn) {
      autoFillBtn.disabled = !hasGrandTotal;
      autoFillBtn.title = hasGrandTotal
        ? "Auto-fill remaining amount"
        : "Calculate bill amount first";
    }
    const profitBtn = document.getElementById("btnShowProfit");
    if (profitBtn) {
      profitBtn.disabled = cartCount === 0;
      profitBtn.title =
        cartCount > 0 ? "View profit analysis" : "Add items to cart first";
    }
  } catch (error) {
    console.error("Error updating button states:", error);
  }
}

// ==================== HOLD INVOICE FUNCTIONS ====================
async function loadHoldList() {
  try {
    console.log("Loading hold list...");
    const response = await fetchWithTimeout("api/holds.php?action=list", {
      timeout: 5000,
    });
    if (!response.ok)
      throw new Error(`HTTP ${response.status}: ${response.statusText}`);
    const data = await response.json();
    if (data.success) {
      const tbody = document.getElementById("holdListBody");
      tbody.innerHTML = "";
      if (data.holds && data.holds.length > 0) {
        data.holds.forEach((hold, index) => {
          const cartItems = JSON.parse(hold.cart_items || "[]");
          const itemCount = cartItems.length;
          const row = document.createElement("tr");
          row.innerHTML = `<td>${index + 1}</td><td>${new Date(hold.created_at).toLocaleString()}<br><small class="text-muted">Expires: ${new Date(hold.expiry_at).toLocaleString()}</small></td><td><strong>${hold.hold_number}</strong><br><small>${hold.reference || "No reference"}</small></td><td>${hold.customer_name || "Walk-in"}<br><small>${hold.customer_phone || ""}</small></td><td>${itemCount} items</td><td>₹ ${Math.round(parseFloat(hold.total) || 0)}</td><td><button class="btn btn-sm btn-success" onclick="retrieveHold(${hold.id})"><i class="fas fa-download me-1"></i> Retrieve</button><button class="btn btn-sm btn-danger mt-1" onclick="deleteHold(${hold.id})"><i class="fas fa-trash me-1"></i> Delete</button></td>`;
          tbody.appendChild(row);
        });
      } else
        tbody.innerHTML =
          '<tr><td colspan="7" class="text-center">No holds found</td></tr>';
      const modal = new bootstrap.Modal(
        document.getElementById("holdListModal"),
      );
      modal.show();
      showToast(`Loaded ${data.holds?.length || 0} held invoices`, "success");
    } else throw new Error(data.message || "Failed to load holds");
  } catch (error) {
    console.error("Error loading hold list:", error);
    showToast("Error loading holds: " + error.message, "danger");
  }
}

async function holdInvoice() {
  if (CART.length === 0) {
    showWarningToast("Please add items to cart first");
    return;
  }
  try {
    showPromptModal(
      "Hold Invoice",
      "Enter reference note:",
      "e.g., Waiting for customer confirmation",
      async function (reference) {
        const expiryHours = 48;
        const totals = calculateTotals();
        const holdData = {
          hold_number: document
            .getElementById("invoice-number")
            .value.replace("INV", "HOLD"),
          reference: reference,
          customer_name: document.getElementById("customer-name").value,
          customer_phone:
            document.getElementById("customer-contact").value || "",
          customer_gstin: document.getElementById("customer-gstin").value || "",
          subtotal: totals.subtotal,
          total: totals.grandTotal,
          cart_items: CART,
          cart_json: {
            customer_name: document.getElementById("customer-name").value,
            customer_phone: document.getElementById("customer-contact").value,
            customer_address: document.getElementById("customer-address").value,
            customer_gstin: document.getElementById("customer-gstin").value,
            invoice_type: GST_TYPE,
            price_type: GLOBAL_PRICE_TYPE,
            referral_id: SELECTED_REFERRAL_ID,
            discount: document.getElementById("additional-dis").value,
            discount_type: document.getElementById("overall-discount-type")
              .value,
          },
        };
        showLoading("Saving hold...");
        const response = await fetchWithTimeout("api/holds.php?action=save", {
          method: "POST",
          headers: {
            "Content-Type": "application/json",
            Accept: "application/json",
          },
          body: JSON.stringify(holdData),
          timeout: 10000,
        });
        if (!response.ok)
          throw new Error(`HTTP ${response.status}: ${response.statusText}`);
        const data = await response.json();
        if (data.success) {
          CART = [];
          clearCartFromSession();
          renderCart();
          updateBillingSummary();
          hideLoading();
          showSuccessModal(
            "Invoice Held Successfully!",
            `Hold #: ${holdData.hold_number}<br>Reference: ${reference}<br>Expires in ${expiryHours} hours`,
            function () {
              resetForm();
            },
          );
        } else {
          hideLoading();
          throw new Error(data.message || "Failed to save hold");
        }
      },
    );
  } catch (error) {
    console.error("Error holding invoice:", error);
    hideLoading();
    showErrorModal("Error", `Error holding invoice: ${error.message}`);
  }
}

async function saveHoldInvoice() {
  try {
    const reference = document.getElementById("holdReference").value.trim();
    const expiryHours = parseInt(document.getElementById("holdExpiry").value);
    if (!reference) {
      showToast("Please enter a reference note", "warning");
      return;
    }
    const totals = calculateTotals();
    const holdData = {
      hold_number: document
        .getElementById("invoice-number")
        .value.replace("INV", "HOLD"),
      reference: reference,
      customer_name: document.getElementById("customer-name").value,
      customer_phone: document.getElementById("customer-contact").value || "",
      customer_gstin: document.getElementById("customer-gstin").value || "",
      subtotal: totals.subtotal,
      total: totals.grandTotal,
      cart_items: CART,
      cart_json: {
        customer_name: document.getElementById("customer-name").value,
        customer_phone: document.getElementById("customer-contact").value,
        customer_address: document.getElementById("customer-address").value,
        customer_gstin: document.getElementById("customer-gstin").value,
        invoice_type: GST_TYPE,
        price_type: GLOBAL_PRICE_TYPE,
        referral_id: SELECTED_REFERRAL_ID,
        discount: document.getElementById("additional-dis").value,
        discount_type: document.getElementById("discount-type").value,
      },
    };
    const confirmBtn = document.getElementById("confirmHold");
    confirmBtn.innerHTML =
      '<i class="fas fa-spinner fa-spin me-1"></i> Saving...';
    confirmBtn.disabled = true;
    const response = await fetchWithTimeout("api/holds.php?action=save", {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        Accept: "application/json",
      },
      body: JSON.stringify(holdData),
      timeout: 10000,
    });
    if (!response.ok)
      throw new Error(`HTTP ${response.status}: ${response.statusText}`);
    const data = await response.json();
    if (data.success) {
      CART = [];
      clearCartFromSession();
      renderCart();
      updateBillingSummary();
      const modal = bootstrap.Modal.getInstance(
        document.getElementById("holdInvoiceModal"),
      );
      if (modal) modal.hide();
      showToast(
        `Invoice held successfully! Hold #: ${holdData.hold_number}`,
        "success",
      );
      setTimeout(() => {
        resetForm();
      }, 1000);
    } else throw new Error(data.message || "Failed to save hold");
  } catch (error) {
    console.error("Error saving hold invoice:", error);
    showToast("Error saving hold: " + error.message, "danger");
  } finally {
    const confirmBtn = document.getElementById("confirmHold");
    if (confirmBtn) {
      confirmBtn.innerHTML = "Save Hold";
      confirmBtn.disabled = false;
    }
  }
}

async function retrieveHold(holdId) {
  try {
    console.log("Retrieving hold:", holdId);
    const response = await fetchWithTimeout(
      `api/holds.php?action=get&hold_id=${holdId}`,
      { timeout: 5000 },
    );
    if (!response.ok)
      throw new Error(`HTTP ${response.status}: ${response.statusText}`);
    const data = await response.json();
    if (data.success && data.hold) {
      const hold = data.hold;
      let cartItems = [];
      try {
        if (typeof hold.cart_items === "string")
          cartItems = JSON.parse(hold.cart_items);
        else if (Array.isArray(hold.cart_items)) cartItems = hold.cart_items;
        else throw new Error("Invalid cart data format");
      } catch (parseError) {
        console.error("Error parsing cart items:", parseError);
        showToast(
          "Error parsing cart data. The hold may be corrupted.",
          "danger",
        );
        return;
      }
      CART = [];
      cartItems.forEach((item) => {
        const cartItem = {
          id: `${item.product_id}-${item.unit || "PCS"}-${item.price || 0}`,
          product_id: item.product_id || item.id,
          name: item.name || "Unknown Product",
          code: item.code || (item.product_id || item.id).toString(),
          mrp: item.mrp || 0,
          base_price: item.base_price || item.price || 0,
          price: item.price || 0,
          price_type: item.price_type || "retail",
          quantity: item.quantity || 1,
          unit: item.unit || "PCS",
          is_secondary_unit: item.is_secondary_unit || false,
          discount_value: item.discount_value || 0,
          discount_type: item.discount_type || "percentage",
          discount_amount: item.discount_amount || 0,
          shop_stock: item.shop_stock || 0,
          hsn_code: item.hsn_code || "",
          cgst_rate: item.cgst_rate || 0,
          sgst_rate: item.sgst_rate || 0,
          igst_rate: item.igst_rate || 0,
          total: (item.price || 0) * (item.quantity || 1),
          stock_price: item.stock_price || 0,
          retail_price: item.retail_price || 0,
          wholesale_price: item.wholesale_price || 0,
          unit_of_measure: item.unit_of_measure || "PCS",
          added_at: new Date().toISOString(),
          quantity_in_primary: item.quantity_in_primary || item.quantity || 1,
        };
        CART.push(cartItem);
      });
      let cartJson = {};
      try {
        if (hold.cart_json) {
          if (typeof hold.cart_json === "string")
            cartJson = JSON.parse(hold.cart_json);
          else cartJson = hold.cart_json;
        }
      } catch (jsonError) {
        console.warn("Error parsing cart_json:", jsonError);
        cartJson = {};
      }
      document.getElementById("customer-name").value =
        cartJson.customer_name || hold.customer_name || "Walk-in Customer";
      if (cartJson.customer_phone || hold.customer_phone)
        $("#customer-contact")
          .val(cartJson.customer_phone || hold.customer_phone)
          .trigger("change");
      document.getElementById("customer-address").value =
        cartJson.customer_address || "";
      document.getElementById("customer-gstin").value =
        cartJson.customer_gstin || hold.customer_gstin || "";
      if (cartJson.invoice_type) {
        document.getElementById("invoice-type").value = cartJson.invoice_type;
        GST_TYPE = cartJson.invoice_type;
      }
      if (cartJson.price_type) {
        document.getElementById("price-type").value = cartJson.price_type;
        GLOBAL_PRICE_TYPE = cartJson.price_type;
      }
      if (cartJson.referral_id) {
        $("#referral").val(cartJson.referral_id).trigger("change");
        SELECTED_REFERRAL_ID = cartJson.referral_id;
      }
      if (cartJson.discount !== undefined)
        document.getElementById("additional-dis").value = cartJson.discount;
      if (cartJson.discount_type)
        document.getElementById("overall-discount-type").value =
          cartJson.discount_type;
      renderCart();
      saveCartToSession();
      updateBillingSummary();
      updateButtonStates();
      const modal = bootstrap.Modal.getInstance(
        document.getElementById("holdListModal"),
      );
      if (modal) modal.hide();
      try {
        await deleteHold(holdId, false);
      } catch (deleteError) {
        console.warn(
          "Could not auto-delete hold after retrieval:",
          deleteError,
        );
      }
      showToast(`Hold #${hold.hold_number} retrieved successfully`, "success");
    } else throw new Error(data.message || "Failed to retrieve hold");
  } catch (error) {
    console.error("Error retrieving hold:", error);
    showToast("Error retrieving hold: " + error.message, "danger");
  }
}

async function deleteHold(holdId, showAlert = true) {
  showConfirmation(
    "Delete Hold",
    "Are you sure you want to delete this held invoice? This action cannot be undone.",
    async function () {
      showLoading("Deleting hold...");
      const response = await fetchWithTimeout("api/holds.php?action=delete", {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
          Accept: "application/json",
        },
        body: JSON.stringify({ hold_id: holdId }),
        timeout: 5000,
      });
      if (!response.ok)
        throw new Error(`HTTP ${response.status}: ${response.statusText}`);
      const data = await response.json();
      if (data.success) {
        hideLoading();
        if (showAlert) showSuccessToast("Hold deleted successfully");
        loadHoldList();
      } else {
        hideLoading();
        throw new Error(data.message || "Failed to delete hold");
      }
    },
  );
}

// ==================== QUOTATION FUNCTIONS ====================
async function showQuotationModal() {
  if (CART.length === 0) {
    showToast("Please add items to cart first", "warning");
    return;
  }
  try {
    const response = await fetchWithTimeout(
      "api/quotations.php?action=get_next_number",
      { timeout: 5000 },
    );
    if (!response.ok)
      throw new Error(`HTTP ${response.status}: ${response.statusText}`);
    const data = await response.json();
    if (data.success) {
      document.getElementById("quotationNumber").value = data.quotation_number;
      const modal = new bootstrap.Modal(
        document.getElementById("quotationModal"),
      );
      modal.show();
    } else
      throw new Error(data.message || "Failed to generate quotation number");
  } catch (error) {
    console.error("Error preparing quotation:", error);
    showToast("Error: " + error.message, "danger");
  }
}

async function saveQuotation() {
  try {
    const quotationNumber = document.getElementById("quotationNumber").value;
    const validUntil = document.getElementById("quotationValidUntil").value;
    const notes = document.getElementById("quotationNotes").value.trim();
    if (!quotationNumber) {
      showWarningToast("Please generate a quotation number first");
      return;
    }
    if (!validUntil) {
      showWarningModal("Date Required", "Please select a valid until date");
      document.getElementById("quotationValidUntil").focus();
      return;
    }
    showConfirmation(
      "Save Quotation?",
      "Are you sure you want to save this as a quotation?",
      async function () {
        const totals = calculateTotals();
        const today = new Date().toISOString().split("T")[0];
        const quotationData = {
          quotation_number: quotationNumber,
          quotation_date: today,
          valid_until: validUntil,
          customer_name: document.getElementById("customer-name").value,
          customer_phone:
            document.getElementById("customer-contact").value || "",
          customer_email: "",
          customer_address:
            document.getElementById("customer-address").value || "",
          customer_gstin: document.getElementById("customer-gstin").value || "",
          subtotal: totals.subtotal,
          total_discount: totals.totalItemDiscount + totals.overallDiscount,
          total_tax: totals.totalGST,
          grand_total: totals.grandTotal,
          notes: notes,
          items: CART.map((item) => ({
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
            price_type: item.price_type,
          })),
        };
        showLoading("Saving quotation...");
        const response = await fetchWithTimeout(
          "api/quotations.php?action=save",
          {
            method: "POST",
            headers: {
              "Content-Type": "application/json",
              Accept: "application/json",
            },
            body: JSON.stringify(quotationData),
            timeout: 10000,
          },
        );
        if (!response.ok)
          throw new Error(`HTTP ${response.status}: ${response.statusText}`);
        const data = await response.json();
        if (data.success) {
          CART = [];
          clearCartFromSession();
          renderCart();
          updateBillingSummary();
          const modal = bootstrap.Modal.getInstance(
            document.getElementById("quotationModal"),
          );
          if (modal) modal.hide();
          hideLoading();
          showSuccessModal(
            "Quotation Saved!",
            `Quotation #${quotationNumber} saved successfully.`,
            function () {
              resetForm();
            },
          );
        } else {
          hideLoading();
          throw new Error(data.message || "Failed to save quotation");
        }
      },
    );
  } catch (error) {
    console.error("Error saving quotation:", error);
    hideLoading();
    showErrorModal("Error", `Error saving quotation: ${error.message}`);
  }
}

async function loadQuotationList() {
  try {
    console.log("Loading quotation list...");
    const response = await fetchWithTimeout("api/quotations.php?action=list", {
      timeout: 5000,
    });
    if (!response.ok)
      throw new Error(`HTTP ${response.status}: ${response.statusText}`);
    const data = await response.json();
    if (data.success) {
      let modalElement = document.getElementById("quotationListModal");
      if (!modalElement) {
        const modalHTML = `<div class="modal fade" id="quotationListModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-lg"><div class="modal-content"><div class="modal-header"><h5 class="modal-title">Saved Quotations</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><div class="table-responsive"><table class="table table-sm"><thead><tr><th>#</th><th>Date</th><th>Quotation #</th><th>Customer</th><th>Valid Until</th><th>Amount</th><th>Status</th><th>Actions</th></tr></thead><tbody id="quotationListBody"></tbody></table></div></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button></div></div></div></div>`;
        document.body.insertAdjacentHTML("beforeend", modalHTML);
        modalElement = document.getElementById("quotationListModal");
      }
      const tbody = document.getElementById("quotationListBody");
      tbody.innerHTML = "";
      if (data.quotations && data.quotations.length > 0) {
        data.quotations.forEach((quote, index) => {
          const statusClass =
            {
              active: "badge bg-success",
              expired: "badge bg-danger",
              accepted: "badge bg-info",
              rejected: "badge bg-warning",
            }[quote.status || "active"] || "badge bg-secondary";
          const row = document.createElement("tr");
          row.innerHTML = `<td>${index + 1}</td><td>${quote.formatted_date || quote.quotation_date}</td><td><strong>${quote.quotation_number}</strong></td><td>${quote.customer_name || "Walk-in"}<br><small>${quote.customer_phone || ""}</small></td><td>${quote.formatted_valid_until || quote.valid_until}</td><td>₹ ${Math.round(parseFloat(quote.grand_total) || 0)}</td><td><span class="${statusClass}">${quote.status || "active"}</span></td><td><button class="btn btn-sm btn-success" onclick="retrieveQuotation(${quote.id})"><i class="fas fa-download me-1"></i> Retrieve</button><button class="btn btn-sm btn-danger mt-1" onclick="deleteQuotation(${quote.id})"><i class="fas fa-trash me-1"></i> Delete</button></td>`;
          tbody.appendChild(row);
        });
      } else
        tbody.innerHTML =
          '<tr><td colspan="8" class="text-center">No quotations found</td></tr>';
      const modal = new bootstrap.Modal(modalElement);
      modal.show();
      showToast(`Loaded ${data.quotations?.length || 0} quotations`, "success");
    } else throw new Error(data.message || "Failed to load quotations");
  } catch (error) {
    console.error("Error loading quotation list:", error);
    showToast("Error loading quotations: " + error.message, "danger");
  }
}

async function retrieveQuotation(quotationId) {
  try {
    console.log("Retrieving quotation:", quotationId);
    const quoteResponse = await fetchWithTimeout(
      `api/quotations.php?action=get_items&quotation_id=${quotationId}`,
      { timeout: 5000 },
    );
    if (!quoteResponse.ok)
      throw new Error(
        `HTTP ${quoteResponse.status}: ${quoteResponse.statusText}`,
      );
    const quoteData = await quoteResponse.json();
    if (quoteData.success && quoteData.items) {
      const listResponse = await fetchWithTimeout(
        "api/quotations.php?action=list",
        { timeout: 5000 },
      );
      if (listResponse.ok) {
        const listData = await listResponse.json();
        const quotation = listData.quotations?.find((q) => q.id == quotationId);
        if (quotation) {
          CART = [];
          quoteData.items.forEach((item) => {
            const product = findProductById(item.product_id);
            if (product) {
              const isSecondaryUnit =
                product.secondary_unit &&
                item.unit &&
                item.unit === product.secondary_unit;
              let quantityInPrimary = item.quantity;
              let unit = item.unit || product.unit_of_measure;
              if (isSecondaryUnit && product.sec_unit_conversion) {
                quantityInPrimary = item.quantity / product.sec_unit_conversion;
              } else if (!isSecondaryUnit) {
                quantityInPrimary = item.quantity;
                unit = product.unit_of_measure;
              }
              let price = parseFloat(item.unit_price) || 0;
              let discountValue = 0,
                discountAmount = 0;
              if (item.discount_amount > 0) {
                discountAmount = parseFloat(item.discount_amount) || 0;
                if (item.discount_type === "percentage")
                  discountValue = (discountAmount / price) * 100;
                else discountValue = discountAmount;
              }
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
                price_type: item.price_type || GLOBAL_PRICE_TYPE || "retail",
                quantity: parseFloat(item.quantity) || 1,
                unit: unit,
                is_secondary_unit: isSecondaryUnit,
                discount_value: parseFloat(discountValue.toFixed(2)) || 0,
                discount_type: item.discount_type || "percentage",
                discount_amount: parseFloat(discountAmount.toFixed(2)) || 0,
                shop_stock: product.shop_stock_primary || 0,
                hsn_code: item.hsn_code || product.hsn_code || "",
                cgst_rate:
                  parseFloat(item.cgst_rate) ||
                  parseFloat(product.cgst_rate) ||
                  0,
                sgst_rate:
                  parseFloat(item.sgst_rate) ||
                  parseFloat(product.sgst_rate) ||
                  0,
                igst_rate:
                  parseFloat(item.igst_rate) ||
                  parseFloat(product.igst_rate) ||
                  0,
                total: price * (parseFloat(item.quantity) || 1),
                referral_enabled: product.referral_enabled || 0,
                referral_type: product.referral_type || "percentage",
                referral_value: parseFloat(product.referral_value) || 0,
                referral_commission: 0,
                secondary_unit: product.secondary_unit || "",
                sec_unit_conversion:
                  parseFloat(product.sec_unit_conversion) || 1,
                stock_price: parseFloat(product.stock_price) || 0,
                retail_price: parseFloat(product.retail_price) || 0,
                wholesale_price: parseFloat(product.wholesale_price) || 0,
                unit_of_measure: product.unit_of_measure || "PCS",
                quantity_in_primary:
                  parseFloat(quantityInPrimary.toFixed(3)) || 1,
                added_at: new Date().toISOString(),
                category_name: product.category_name || "",
                subcategory_name: product.subcategory_name || "",
              };
              CART.push(cartItem);
            } else {
              const cartItem = {
                id: `${item.product_id}-${item.unit}-${item.unit_price}`,
                product_id: item.product_id,
                name: item.product_name || "Unknown Product",
                code: item.product_id.toString(),
                mrp: 0,
                base_price: parseFloat(item.unit_price) || 0,
                price: parseFloat(item.unit_price) || 0,
                price_type: item.price_type || "retail",
                quantity: parseFloat(item.quantity) || 1,
                unit: item.unit || "PCS",
                is_secondary_unit: false,
                discount_value: 0,
                discount_type: "percentage",
                discount_amount: 0,
                shop_stock: 0,
                hsn_code: item.hsn_code || "",
                cgst_rate: parseFloat(item.cgst_rate) || 0,
                sgst_rate: parseFloat(item.sgst_rate) || 0,
                igst_rate: parseFloat(item.igst_rate) || 0,
                total:
                  (parseFloat(item.unit_price) || 0) *
                  (parseFloat(item.quantity) || 1),
                referral_enabled: 0,
                referral_type: "percentage",
                referral_value: 0,
                referral_commission: 0,
                secondary_unit: "",
                sec_unit_conversion: 1,
                stock_price: 0,
                retail_price: 0,
                wholesale_price: 0,
                unit_of_measure: "PCS",
                quantity_in_primary: parseFloat(item.quantity) || 1,
                added_at: new Date().toISOString(),
                category_name: "",
                subcategory_name: "",
              };
              CART.push(cartItem);
            }
          });
          document.getElementById("customer-name").value =
            quotation.customer_name || "Walk-in Customer";
          if (quotation.customer_phone)
            $("#customer-contact")
              .val(quotation.customer_phone)
              .trigger("change");
          document.getElementById("customer-address").value =
            quotation.customer_address || "";
          document.getElementById("customer-gstin").value =
            quotation.customer_gstin || "";
          if (quotation.invoice_type) {
            document.getElementById("invoice-type").value =
              quotation.invoice_type;
            GST_TYPE = quotation.invoice_type;
          }
          if (quotation.price_type) {
            document.getElementById("price-type").value = quotation.price_type;
            GLOBAL_PRICE_TYPE = quotation.price_type;
          }
          clearCartFromSession();
          renderCart();
          saveCartToSession();
          updateBillingSummary();
          updateButtonStates();
          const modal = bootstrap.Modal.getInstance(
            document.getElementById("quotationListModal"),
          );
          if (modal) modal.hide();
          showToast(
            `Quotation #${quotation.quotation_number} retrieved successfully with ${CART.length} items`,
            "success",
          );
        } else throw new Error("Quotation not found");
      } else throw new Error("Failed to load quotation details");
    } else
      throw new Error(
        quoteData.message || "Failed to retrieve quotation items",
      );
  } catch (error) {
    console.error("Error retrieving quotation:", error);
    showToast("Error retrieving quotation: " + error.message, "danger");
  }
}

async function deleteQuotation(quotationId) {
  showConfirmation(
    "Delete Quotation",
    "Are you sure you want to delete this quotation? This action cannot be undone.",
    async function () {
      try {
        showLoading("Deleting quotation...");
        const response = await fetchWithTimeout(
          "api/quotations.php?action=delete",
          {
            method: "POST",
            headers: {
              "Content-Type": "application/json",
              Accept: "application/json",
            },
            body: JSON.stringify({ quotation_id: quotationId }),
            timeout: 5000,
          },
        );
        if (!response.ok)
          throw new Error(`HTTP ${response.status}: ${response.statusText}`);
        const data = await response.json();
        if (data.success) {
          hideLoading();
          showSuccessToast("Quotation deleted successfully");
          loadQuotationList();
        } else {
          hideLoading();
          throw new Error(data.message || "Failed to delete quotation");
        }
      } catch (error) {
        console.error("Error deleting quotation:", error);
        hideLoading();
        showErrorModal("Error", `Error deleting quotation: ${error.message}`);
      }
    },
  );
}

// ==================== PROFIT FUNCTIONS ====================
function calculateItemProfit(item) {
  try {
    const stockPrice = parseFloat(item.stock_price) || 0;
    const profitPerUnit = item.price - stockPrice;
    const totalProfit = profitPerUnit * item.quantity;
    const marginPercentage =
      stockPrice > 0 ? (profitPerUnit / stockPrice) * 100 : 0;
    return {
      profitPerUnit: parseFloat(profitPerUnit.toFixed(2)),
      totalProfit: parseFloat(totalProfit.toFixed(2)),
      marginPercentage: parseFloat(marginPercentage.toFixed(2)),
      sellingPriceWithGST: parseFloat(item.price.toFixed(2)),
      stockPrice: stockPrice,
    };
  } catch (error) {
    console.error("Error calculating item profit:", error);
    return {
      profitPerUnit: 0,
      totalProfit: 0,
      marginPercentage: 0,
      sellingPriceWithGST: 0,
      stockPrice: 0,
    };
  }
}

function calculateTotalProfit() {
  try {
    let totalProfit = 0,
      totalStockValue = 0,
      totalSellingValueWithGST = 0;
    CART.forEach((item) => {
      const profit = calculateItemProfit(item);
      totalProfit += profit.totalProfit;
      totalStockValue += (parseFloat(item.stock_price) || 0) * item.quantity;
      totalSellingValueWithGST += item.price * item.quantity;
    });
    const overallMarginPercentage =
      totalStockValue > 0 ? (totalProfit / totalStockValue) * 100 : 0;
    return {
      totalProfit: parseFloat(totalProfit.toFixed(2)),
      totalStockValue: parseFloat(totalStockValue.toFixed(2)),
      totalSellingValueWithGST: parseFloat(totalSellingValueWithGST.toFixed(2)),
      overallMarginPercentage: parseFloat(overallMarginPercentage.toFixed(2)),
    };
  } catch (error) {
    console.error("Error calculating total profit:", error);
    return {
      totalProfit: 0,
      totalStockValue: 0,
      totalSellingValueWithGST: 0,
      overallMarginPercentage: 0,
    };
  }
}

function showProfitModal() {
  try {
    if (CART.length === 0) {
      showToast("No items in cart to calculate profit", "warning");
      return;
    }
    let profitModalElement = document.getElementById("profitModal");
    if (!profitModalElement) {
      const profitModalHTML = `<div class="modal fade" id="profitModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-lg"><div class="modal-content"><div class="modal-header bg-primary text-white"><h5 class="modal-title"><i class="fas fa-chart-line me-2"></i> Profit Analysis</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><div class="modal-body"><div class="row mb-4"><div class="col-md-4"><div class="card bg-success text-white"><div class="card-body py-2"><h6 class="card-title">Total Profit</h6><h3 class="mb-0" id="profitTotalAmount">₹ 0</h3></div></div></div><div class="col-md-4"><div class="card bg-info text-white"><div class="card-body py-2"><h6 class="card-title">Margin</h6><h3 class="mb-0" id="profitOverallMargin">0%</h3></div></div></div><div class="col-md-4"><div class="card bg-warning text-white"><div class="card-body py-2"><h6 class="card-title">Items</h6><h3 class="mb-0" id="profitTotalItems">0</h3></div></div></div></div><div class="table-responsive" style="max-height: 400px; overflow-y: auto;"><table class="table table-sm table-striped table-hover"><thead class="sticky-top bg-light"><tr><th>#</th><th>Product</th><th>Qty</th><th>Unit</th><th>Stock Price</th><th>Selling Price</th><th>Profit/Unit</th><th>Total Profit</th><th>Margin %</th></tr></thead><tbody id="profitTableBody"></tbody></table></div><div class="mt-4 p-3 bg-light rounded"><h6 class="mb-3">Detailed Summary</h6><div class="row"><div class="col-md-6"><div class="d-flex justify-content-between mb-2"><span class="fw-bold">Total Selling Value</span><span id="profitTotalSellingValue" class="text-primary fw-bold">₹ 0</span></div><div class="d-flex justify-content-between mb-2"><span class="fw-bold">Total Stock Value:</span><span id="profitTotalStockValue" class="text-danger fw-bold">₹ 0</span></div></div><div class="col-md-6"><div class="d-flex justify-content-between mb-2"><span class="fw-bold">Gross Profit:</span><span id="profitGrossProfit" class="text-success fw-bold">₹ 0</span></div><div class="d-flex justify-content-between"><span class="fw-bold">Profit Margin:</span><span id="profitMarginPercent" class="text-info fw-bold">0%</span></div></div></div></div></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button><button type="button" class="btn btn-primary" onclick="printProfitReport()">Print Report</button></div></div></div></div>`;
      document.body.insertAdjacentHTML("beforeend", profitModalHTML);
      profitModalElement = document.getElementById("profitModal");
    }
    const totals = calculateTotalProfit();
    document.getElementById("profitTotalAmount").innerHTML =
      `₹ ${Math.round(totals.totalProfit)}`;
    document.getElementById("profitOverallMargin").innerHTML =
      `${totals.overallMarginPercentage}%`;
    document.getElementById("profitTotalSellingValue").innerHTML =
      `₹ ${Math.round(totals.totalSellingValueWithGST)} (Inc. GST)`;
    document.getElementById("profitTotalStockValue").innerHTML =
      `₹ ${Math.round(totals.totalStockValue)}`;
    document.getElementById("profitGrossProfit").innerHTML =
      `₹ ${Math.round(totals.totalProfit)}`;
    document.getElementById("profitMarginPercent").innerHTML =
      `${totals.overallMarginPercentage}%`;
    const tbody = document.getElementById("profitTableBody");
    tbody.innerHTML = "";
    CART.forEach((item, index) => {
      const profit = calculateItemProfit(item);
      let marginClass = "text-success";
      if (profit.marginPercentage < 10) marginClass = "text-danger";
      else if (profit.marginPercentage < 20) marginClass = "text-warning";
      const row = document.createElement("tr");
      row.innerHTML = `<td>${index + 1}</td><td><strong>${escapeHtml(item.name)}</strong><br><small class="text-muted">${escapeHtml(item.code)}</small></td><td>${item.quantity}</td><td>${escapeHtml(item.unit)}</td><td class="text-end">₹ ${Math.round(profit.stockPrice)}</td><td class="text-end">₹ ${Math.round(profit.sellingPriceWithGST)}</td><td class="text-end ${profit.profitPerUnit >= 0 ? "text-success" : "text-danger"}">₹ ${Math.round(profit.profitPerUnit)}</td><td class="text-end ${profit.totalProfit >= 0 ? "text-success" : "text-danger"} fw-bold">₹ ${Math.round(profit.totalProfit)}</td><td class="text-end ${marginClass} fw-bold">${profit.marginPercentage}%</td>`;
      tbody.appendChild(row);
    });
    const profitModal = new bootstrap.Modal(profitModalElement);
    profitModal.show();
  } catch (error) {
    console.error("Error showing profit modal:", error);
    showToast("Error calculating profit. Please try again.", "danger");
  }
}

function printProfitReport() {
  try {
    const printWindow = window.open("", "_blank", "width=800,height=600");
    if (!printWindow) {
      showToast("Popup blocked. Please allow popups to print.", "warning");
      return;
    }
    const totals = calculateTotalProfit();
    const date = new Date().toLocaleString();
    const invoiceNumber = document.getElementById("invoice-number").value;
    const customerName = document.getElementById("customer-name").value;
    let itemsHTML = "";
    CART.forEach((item, index) => {
      const profit = calculateItemProfit(item);
      itemsHTML += `
        <tr>
            <td>${index + 1}</td>
            <td>${escapeHtml(item.name)}</td>
            <td class="text-end">${item.quantity} ${escapeHtml(item.unit)}</td>
            <td class="text-end">₹ ${Math.round(profit.stockPrice)}</td>
            <td class="text-end">₹ ${Math.round(profit.sellingPriceWithGST)}</td>
            <td class="text-end">₹ ${Math.round(profit.profitPerUnit)}</td>
            <td class="text-end">₹ ${Math.round(profit.totalProfit)}</td>
            <td class="text-end">${profit.marginPercentage}%</td>
        </tr>
    `;
    });
    printWindow.document.write(
      `<!DOCTYPE html><html><head><title>Profit Report - ${invoiceNumber}</title><style>body{font-family:Arial,sans-serif;margin:20px}.header{text-align:center;margin-bottom:30px}.header h1{color:#0d6efd}.summary{display:flex;justify-content:space-between;margin-bottom:30px}.summary-box{background:#f8f9fa;border:1px solid #dee2e6;border-radius:5px;padding:15px;width:30%}.summary-box .amount{font-size:24px;font-weight:bold;color:#28a745}table{width:100%;border-collapse:collapse;margin-top:20px}th,td{border:1px solid #dee2e6;padding:8px;text-align:left}th{background-color:#e9ecef}.text-end{text-align:right}.footer{margin-top:30px;text-align:right;font-size:12px;color:#6c757d}</style></head><body><div class="header"><h1>Profit Analysis Report</h1><h3>Invoice #: ${invoiceNumber}</h3><p>Date: ${date}</p><p>Customer: ${escapeHtml(customerName)}</p></div><div class="summary"><div class="summary-box"><h4>Total Profit</h4><div class="amount">₹ ${Math.round(totals.totalProfit)}</div></div><div class="summary-box"><h4>Profit Margin</h4><div class="amount">${totals.overallMarginPercentage}%</div></div><div class="summary-box"><h4>Total Items</h4><div class="amount">${CART.reduce((sum, item) => sum + item.quantity, 0)}</div></div></div><h3>Product-wise Profit Details</h3><table><thead><tr><th>#</th><th>Product</th><th>Quantity</th><th>Stock Price</th><th>Selling Price</th><th>Profit/Unit</th><th>Total Profit</th><th>Margin %</th></tr></thead><tbody>${itemsHTML}</tbody><tfoot><tr><th colspan="6" class="text-end">Total Profit:</th><th class="text-end text-success">₹ ${Math.round(totals.totalProfit)}</th><th class="text-end">${totals.overallMarginPercentage}%</th></tr></tfoot></table><div class="footer"><p>Generated on: ${new Date().toLocaleString()}</p></div></body></html>`,
    );
    printWindow.document.close();
    printWindow.focus();
    printWindow.print();
  } catch (error) {
    console.error("Error printing profit report:", error);
    showToast("Error printing report. Please try again.", "danger");
  }
}

function addProfitButtonToFixedBottom() {
  try {
    const fixedBottomDiv = document.querySelector(".fixed-bottom-buttons");
    if (fixedBottomDiv && !document.getElementById("btnShowProfit")) {
      const profitButton = document.createElement("button");
      profitButton.id = "btnShowProfit";
      profitButton.className = "btn btn-warning";
      profitButton.innerHTML = '<i class="fas fa-chart-line me-1"></i> Profit';
      profitButton.title = "View product-wise profit analysis";
      profitButton.onclick = showProfitModal;
      if (fixedBottomDiv.firstChild)
        fixedBottomDiv.insertBefore(profitButton, fixedBottomDiv.firstChild);
      else fixedBottomDiv.appendChild(profitButton);
      console.log("Profit button added to fixed bottom section");
    }
  } catch (error) {
    console.error("Error adding profit button:", error);
  }
}

// ==================== SHIPPING FUNCTIONS ====================
function initShippingModal() {
  try {
    const shippingBtn = document.getElementById("btnShippingDetails");
    if (shippingBtn) {
      shippingBtn.removeEventListener("click", showShippingModal);
      shippingBtn.addEventListener("click", showShippingModal);
    }
    const saveShippingBtn = document.getElementById("btnSaveShipping");
    if (saveShippingBtn) {
      saveShippingBtn.removeEventListener("click", saveShippingDetails);
      saveShippingBtn.addEventListener("click", saveShippingDetails);
    }
    initShippingSectionButtons();
    loadShippingDetailsFromSession();
    createHorizontalShippingDisplay();
    updateShippingDetailsHorizontal();
  } catch (error) {
    console.error("Error initializing shipping modal:", error);
  }
}

function initShippingSectionButtons() {
  try {
    const editBtn = document.getElementById("btnEditShippingFromDiscount");
    if (editBtn) {
      editBtn.removeEventListener("click", showShippingModal);
      editBtn.addEventListener("click", showShippingModal);
    }
    const clearBtn = document.getElementById("btnClearShippingFromDiscount");
    if (clearBtn) {
      clearBtn.removeEventListener("click", clearShippingDetailsWithConfirm);
      clearBtn.addEventListener("click", clearShippingDetailsWithConfirm);
    }
  } catch (error) {
    console.error("Error initializing shipping section buttons:", error);
  }
}

function showShippingModal() {
  try {
    document.getElementById("shipping-name").value =
      SHIPPING_DETAILS.name || "";
    document.getElementById("shipping-contact").value =
      SHIPPING_DETAILS.contact || "";
    document.getElementById("shipping-gstin").value =
      SHIPPING_DETAILS.gstin || "";
    document.getElementById("shipping-address").value =
      SHIPPING_DETAILS.address || "";
    document.getElementById("shipping-vehicle").value =
      SHIPPING_DETAILS.vehicle_number || "";
    document.getElementById("shipping-charges").value =
      SHIPPING_DETAILS.charges || 0;
    const modal = new bootstrap.Modal(document.getElementById("shippingModal"));
    modal.show();
  } catch (error) {
    console.error("Error showing shipping modal:", error);
    if (typeof showToast === "function")
      showToast("Error opening shipping details", "danger");
  }
}

function saveShippingDetails() {
  try {
    const shippingData = {
      name: document.getElementById("shipping-name").value.trim(),
      contact: document.getElementById("shipping-contact").value.trim(),
      gstin: document
        .getElementById("shipping-gstin")
        .value.trim()
        .toUpperCase(),
      address: document.getElementById("shipping-address").value.trim(),
      vehicle_number: document.getElementById("shipping-vehicle").value.trim(),
      charges:
        parseFloat(document.getElementById("shipping-charges").value) || 0,
    };
    SHIPPING_DETAILS = shippingData;
    saveShippingDetailsToSession();
    updateShippingDetailsHorizontal();
    updateBillingSummary();
    const modal = bootstrap.Modal.getInstance(
      document.getElementById("shippingModal"),
    );
    if (modal) modal.hide();
    if (shippingData.charges > 0 && typeof showToast === "function")
      showToast(
        `Shipping charges ₹${shippingData.charges.toFixed(2)} added`,
        "info",
      );
    else if (shippingData.name && typeof showToast === "function")
      showToast("Shipping details saved successfully", "success");
  } catch (error) {
    console.error("Error saving shipping details:", error);
    if (typeof showToast === "function")
      showToast("Error saving shipping details", "danger");
  }
}

function saveShippingDetailsToSession() {
  try {
    sessionStorage.setItem(
      "pos_shipping_details",
      JSON.stringify(SHIPPING_DETAILS),
    );
  } catch (error) {
    console.error("Error saving shipping details to session:", error);
  }
}
function loadShippingDetailsFromSession() {
  try {
    const saved = sessionStorage.getItem("pos_shipping_details");
    if (saved) {
      SHIPPING_DETAILS = JSON.parse(saved);
      updateShippingDetailsHorizontal();
    }
  } catch (error) {
    console.error("Error loading shipping details from session:", error);
  }
}

function createHorizontalShippingDisplay() {
  try {
    let horizontalContainer = document.getElementById(
      "shippingDetailsHorizontal",
    );
    if (!horizontalContainer) {
      const shippingSection = document.getElementById("shippingInfoSection");
      if (shippingSection) {
        const detailsDiv = document.createElement("div");
        detailsDiv.id = "shippingDetailsHorizontal";
        detailsDiv.className = "shipping-details-horizontal";
        shippingSection.appendChild(detailsDiv);
      }
    }
  } catch (error) {
    console.error("Error creating horizontal shipping display:", error);
  }
}

function updateShippingDetailsHorizontal() {
  try {
    const container = document.getElementById("shippingDetailsHorizontal");
    if (!container) return;
    const hasShipping =
      SHIPPING_DETAILS.name ||
      SHIPPING_DETAILS.contact ||
      SHIPPING_DETAILS.address ||
      SHIPPING_DETAILS.vehicle_number ||
      SHIPPING_DETAILS.gstin ||
      SHIPPING_DETAILS.charges > 0;
    if (hasShipping) {
      let html = "";
      if (SHIPPING_DETAILS.name)
        html += `<div class="shipping-badge-horizontal"><i class="fas fa-user"></i><span class="badge-label">Receiver:</span><span class="badge-value">${escapeHtml(SHIPPING_DETAILS.name)}</span></div>`;
      if (SHIPPING_DETAILS.contact)
        html += `<div class="shipping-badge-horizontal"><i class="fas fa-phone"></i><span class="badge-value">${escapeHtml(SHIPPING_DETAILS.contact)}</span></div>`;
      if (SHIPPING_DETAILS.vehicle_number)
        html += `<div class="shipping-badge-horizontal"><i class="fas fa-truck"></i><span class="badge-value">${escapeHtml(SHIPPING_DETAILS.vehicle_number)}</span></div>`;
      if (SHIPPING_DETAILS.gstin)
        html += `<div class="shipping-badge-horizontal"><i class="fas fa-id-card"></i><span class="badge-value">${escapeHtml(SHIPPING_DETAILS.gstin)}</span></div>`;
      if (SHIPPING_DETAILS.address) {
        const shortAddress =
          SHIPPING_DETAILS.address.length > 40
            ? SHIPPING_DETAILS.address.substring(0, 40) + "..."
            : SHIPPING_DETAILS.address;
        html += `<div class="shipping-badge-horizontal" title="${escapeHtml(SHIPPING_DETAILS.address)}"><i class="fas fa-map-marker-alt"></i><span class="badge-value">${escapeHtml(shortAddress)}</span></div>`;
      }
      if (SHIPPING_DETAILS.charges > 0)
        html += `<div class="shipping-badge-horizontal shipping-charge-badge-horizontal"><i class="fas fa-rupee-sign"></i><span class="badge-value">₹ ${SHIPPING_DETAILS.charges.toFixed(2)}</span></div>`;
      container.innerHTML = html;
    } else
      container.innerHTML = `<div class="shipping-empty-state"><i class="fas fa-info-circle"></i> No shipping details added</div>`;
  } catch (error) {
    console.error("Error updating shipping details horizontal:", error);
  }
}

function clearShippingDetailsWithConfirm() {
  if (typeof Swal !== "undefined")
    Swal.fire({
      title: "Clear Shipping Details?",
      text: "Are you sure you want to remove all shipping details?",
      icon: "warning",
      showCancelButton: true,
      confirmButtonColor: "#d33",
      cancelButtonColor: "#3085d6",
      confirmButtonText: "Yes, clear it!",
      cancelButtonText: "Cancel",
    }).then((result) => {
      if (result.isConfirmed) {
        clearShippingDetails();
        if (typeof showToast === "function")
          showToast("Shipping details cleared successfully", "success");
      }
    });
  else if (confirm("Clear all shipping details?")) clearShippingDetails();
}

function clearShippingDetails() {
  try {
    SHIPPING_DETAILS = {
      name: "",
      contact: "",
      gstin: "",
      address: "",
      vehicle_number: "",
      charges: 0,
    };
    saveShippingDetailsToSession();
    updateShippingDetailsHorizontal();
    updateBillingSummary();
  } catch (error) {
    console.error("Error clearing shipping details:", error);
  }
}

// Initialize all features
document.addEventListener("DOMContentLoaded", function () {
  setTimeout(() => {
    initGstFilter();
    initTransportSection();
    initShippingModal();
  }, 500);
});

// Make functions available globally
window.updateCartItemQuantity = updateCartItemQuantity;
window.updateCartItemPriceType = updateCartItemPriceType;
window.updateCartItemDiscount = updateCartItemDiscount;
window.removeCartItem = removeCartItem;
window.cartItemDecrement = cartItemDecrement;
window.cartItemIncrement = cartItemIncrement;
window.SHIPPING_DETAILS = SHIPPING_DETAILS;
window.showShippingModal = showShippingModal;
window.saveShippingDetails = saveShippingDetails;
window.clearShippingDetails = clearShippingDetails;
window.clearShippingDetailsWithConfirm = clearShippingDetailsWithConfirm;
window.updateShippingDetailsHorizontal = updateShippingDetailsHorizontal;
window.retrieveHold = retrieveHold;
window.deleteHold = deleteHold;
window.retrieveQuotation = retrieveQuotation;
window.deleteQuotation = deleteQuotation;
window.printProfitReport = printProfitReport;

console.log("POS System: Script loaded successfully");
