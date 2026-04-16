const CART_STORAGE_KEY = 'electromart_cart';
let cart = [];

function loadCart() {
  try {
    const storedCart = localStorage.getItem(CART_STORAGE_KEY);
    if (!storedCart) return [];
    const parsedCart = JSON.parse(storedCart);
    return Array.isArray(parsedCart) ? parsedCart : [];
  } catch (error) {
    console.error('Failed to load cart from localStorage:', error);
    return [];
  }
}

function saveCart() {
  try {
    localStorage.setItem(CART_STORAGE_KEY, JSON.stringify(cart));
  } catch (error) {
    console.error('Failed to save cart to localStorage:', error);
  }
}

function formatINR(value) {
  return '₹' + value.toLocaleString('en-IN', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2
  });
}

function renderCart() {
  const cartContent = document.getElementById('cartContent');
  const cartSubtotal = document.getElementById('cartSubtotal');
  const cartCount = document.getElementById('cartCount');
  const mobileCartCount = document.getElementById('mobileCartCount');

  if (!cartContent || !cartSubtotal) return;

  if (cart.length === 0) {
    cartContent.innerHTML = `
      <div class="cart-empty">
        <i class="bi bi-cart-x"></i>
        <h6 class="mb-2 text-dark">Your cart is empty</h6>
        <p class="mb-0">Add electrical items to view them here.</p>
      </div>
    `;

    if (cartCount) cartCount.textContent = '0';
    if (mobileCartCount) mobileCartCount.textContent = '0';
    cartSubtotal.textContent = '₹0.00';
    return;
  }

  let html = '<ul class="cart-items">';
  let subtotal = 0;
  let totalQty = 0;

  cart.forEach((item, index) => {
    const itemTotal = item.price * item.qty;
    subtotal += itemTotal;
    totalQty += item.qty;

    html += `
      <li class="cart-item">
        <div class="cart-item-image">
          <img src="${item.image}" alt="${item.name}">
        </div>
        <div class="cart-item-content">
          <div class="cart-item-title">${item.name}</div>
          <div class="cart-item-price">${formatINR(item.price)} × ${item.qty} = <strong>${formatINR(itemTotal)}</strong></div>
          <div class="cart-item-actions">
            <div class="qty-box">
              <button class="qty-btn" onclick="changeQty(${index}, -1)">−</button>
              <div class="qty-value">${item.qty}</div>
              <button class="qty-btn" onclick="changeQty(${index}, 1)">+</button>
            </div>
            <button class="remove-btn" onclick="removeItem(${index})">Remove</button>
          </div>
        </div>
      </li>
    `;
  });

  html += '</ul>';
  cartContent.innerHTML = html;
  cartSubtotal.textContent = formatINR(subtotal);

  if (cartCount) cartCount.textContent = totalQty;
  if (mobileCartCount) mobileCartCount.textContent = totalQty;
}

function addToCart(name, price, image) {
  const existingItem = cart.find(item => item.name === name);

  if (existingItem) {
    existingItem.qty += 1;
  } else {
    cart.push({
      name,
      price,
      image,
      qty: 1
    });
  }

  saveCart();
  renderCart();

  const sideCartElement = document.getElementById('sideCart');
  if (sideCartElement) {
    const sideCart = bootstrap.Offcanvas.getOrCreateInstance(sideCartElement);
    sideCart.show();
  }
}

function changeQty(index, change) {
  if (!cart[index]) return;

  cart[index].qty += change;

  if (cart[index].qty <= 0) {
    cart.splice(index, 1);
  }

  saveCart();
  renderCart();
}

function removeItem(index) {
  if (!cart[index]) return;

  cart.splice(index, 1);
  saveCart();
  renderCart();
}

function bindCartButtons() {
  const addToCartButtons = document.querySelectorAll('.add-to-cart-btn');

  addToCartButtons.forEach(button => {
    button.addEventListener('click', function () {
      const name = this.dataset.name;
      const price = parseFloat(this.dataset.price);
      const image = this.dataset.image;

      addToCart(name, price, image);
    });
  });
}