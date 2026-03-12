// test-api.js
async function testAPI() {
    try {
        console.log('Testing API endpoint...');
        const response = await fetch('api/products.php?action=list');
        console.log('Response status:', response.status);
        console.log('Response headers:', response.headers);
        
        const text = await response.text();
        console.log('Raw response:', text);
        
        try {
            const data = JSON.parse(text);
            console.log('Parsed JSON:', data);
        } catch (e) {
            console.error('Failed to parse JSON:', e);
        }
    } catch (error) {
        console.error('API test failed:', error);
    }
}

testAPI();