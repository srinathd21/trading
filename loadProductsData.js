// loadProductsData.js
const PRODUCT_DATA_KEY = 'pos_products_data';
const PRODUCT_DATA_TIMESTAMP = 'pos_products_timestamp';
const CACHE_DURATION = 5 * 60 * 1000; // 5 minutes

async function loadProductsData() {
    try {
        // Check if we have cached data
        const cachedData = localStorage.getItem(PRODUCT_DATA_KEY);
        const cachedTimestamp = localStorage.getItem(PRODUCT_DATA_TIMESTAMP);
        const now = Date.now();
        
        // If cache exists and is fresh, use it
        if (cachedData && cachedTimestamp && 
            (now - parseInt(cachedTimestamp)) < CACHE_DURATION) {
            console.log('Using cached product data');
            return JSON.parse(cachedData);
        }
        
        console.log('Fetching fresh product data from server');
        
        // Fetch from server
        const response = await fetch('api/products.php?action=list');
        if (!response.ok) {
            throw new Error(`HTTP ${response.status}: ${response.statusText}`);
        }
        
        const data = await response.json();
        
        if (!data.success) {
            throw new Error(data.message || 'Failed to load products');
        }
        
        // Cache the data
        localStorage.setItem(PRODUCT_DATA_KEY, JSON.stringify(data));
        localStorage.setItem(PRODUCT_DATA_TIMESTAMP, now.toString());
        
        console.log(`Loaded ${data.products?.length || 0} products, cached for 5 minutes`);
        return data;
        
    } catch (error) {
        console.error('Error loading products data:', error);
        
        // Try to use cached data even if expired
        const cachedData = localStorage.getItem(PRODUCT_DATA_KEY);
        if (cachedData) {
            console.log('Using expired cached data as fallback');
            return JSON.parse(cachedData);
        }
        
        throw error;
    }
}

function clearProductsCache() {
    localStorage.removeItem(PRODUCT_DATA_KEY);
    localStorage.removeItem(PRODUCT_DATA_TIMESTAMP);
    console.log('Product cache cleared');
}

// Export for use in main script
if (typeof module !== 'undefined') {
    module.exports = { loadProductsData, clearProductsCache };
}