<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register New Business</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            background-color: #f8f9fa;
            padding-top: 20px;
        }
        .container {
            max-width: 800px;
        }
        .section-title {
            color: #495057;
            border-bottom: 2px solid #dee2e6;
            padding-bottom: 10px;
            margin-bottom: 20px;
        }
        .required::after {
            content: " *";
            color: red;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1 class="text-center mb-4">Register New Business</h1>
        
        <form id="businessForm" method="POST" action="process_registration.php">
            
            <!-- Section 1: Business Details -->
            <div class="card mb-4">
                <div class="card-header bg-primary text-white">
                    <h3 class="mb-0">Business Information</h3>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="business_name" class="form-label required">Business Name</label>
                            <input type="text" class="form-control" id="business_name" name="business_name" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="business_code" class="form-label required">Business Code</label>
                            <input type="text" class="form-control" id="business_code" name="business_code" required>
                            <small class="form-text text-muted">Unique code for your business (e.g., VT001)</small>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="owner_name" class="form-label required">Owner Name</label>
                            <input type="text" class="form-control" id="owner_name" name="owner_name" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="phone" class="form-label required">Phone Number</label>
                            <input type="tel" class="form-control" id="phone" name="phone" required>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="email" class="form-label">Email Address</label>
                            <input type="email" class="form-control" id="email" name="email">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="gstin" class="form-label">GSTIN Number</label>
                            <input type="text" class="form-control" id="gstin" name="gstin">
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="address" class="form-label">Business Address</label>
                        <textarea class="form-control" id="address" name="address" rows="2"></textarea>
                    </div>
                    
                    <!-- Cloud Subscription -->
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label for="cloud_plan" class="form-label">Cloud Plan</label>
                            <select class="form-select" id="cloud_plan" name="cloud_plan">
                                <option value="free" selected>Free</option>
                                <option value="basic">Basic</option>
                                <option value="premium">Premium</option>
                                <option value="enterprise">Enterprise</option>
                            </select>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label for="cloud_expiry_date" class="form-label">Cloud Expiry Date</label>
                            <input type="date" class="form-control" id="cloud_expiry_date" name="cloud_expiry_date">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label for="cloud_subscription_status" class="form-label">Subscription Status</label>
                            <select class="form-select" id="cloud_subscription_status" name="cloud_subscription_status">
                                <option value="trial" selected>Trial</option>
                                <option value="active">Active</option>
                                <option value="expired">Expired</option>
                                <option value="cancelled">Cancelled</option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Section 2: Shop Details -->
            <div class="card mb-4">
                <div class="card-header bg-success text-white">
                    <h3 class="mb-0">Shop Information</h3>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="shop_name" class="form-label required">Shop Name</label>
                            <input type="text" class="form-control" id="shop_name" name="shop_name" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="shop_code" class="form-label required">Shop Code</label>
                            <input type="text" class="form-control" id="shop_code" name="shop_code" required>
                            <small class="form-text text-muted">Unique shop code (e.g., SP001)</small>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="shop_phone" class="form-label">Shop Phone</label>
                            <input type="tel" class="form-control" id="shop_phone" name="shop_phone">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="shop_gstin" class="form-label">Shop GSTIN</label>
                            <input type="text" class="form-control" id="shop_gstin" name="shop_gstin">
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="shop_address" class="form-label">Shop Address</label>
                        <textarea class="form-control" id="shop_address" name="shop_address" rows="2"></textarea>
                    </div>
                    
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" id="is_warehouse" name="is_warehouse" value="1">
                        <label class="form-check-label" for="is_warehouse">
                            This is a Warehouse (not a retail shop)
                        </label>
                    </div>
                </div>
            </div>
            
            <!-- Section 3: Super Admin User Details -->
            <div class="card mb-4">
                <div class="card-header bg-warning text-dark">
                    <h3 class="mb-0">Super Admin Account</h3>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="username" class="form-label required">Username</label>
                            <input type="text" class="form-control" id="username" name="username" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="user_email" class="form-label required">Email Address</label>
                            <input type="email" class="form-control" id="user_email" name="user_email" required>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="password" class="form-label required">Password</label>
                            <input type="password" class="form-control" id="password" name="password" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="confirm_password" class="form-label required">Confirm Password</label>
                            <input type="password" class="form-control" id="confirm_password" name="confirm_password" required>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="full_name" class="form-label required">Full Name</label>
                            <input type="text" class="form-control" id="full_name" name="full_name" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="user_phone" class="form-label">Phone Number</label>
                            <input type="tel" class="form-control" id="user_phone" name="user_phone">
                        </div>
                    </div>
                    
                    <div class="alert alert-info">
                        <strong>Note:</strong> This user will be created as the Super Admin/Owner of the business with full access rights.
                    </div>
                </div>
            </div>
            
            <!-- Submit Button -->
            <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                <button type="reset" class="btn btn-secondary me-md-2">Clear Form</button>
                <button type="submit" class="btn btn-primary btn-lg">Register Business</button>
            </div>
        </form>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Password validation
        document.getElementById('businessForm').addEventListener('submit', function(e) {
            const password = document.getElementById('password').value;
            const confirmPassword = document.getElementById('confirm_password').value;
            
            if (password !== confirmPassword) {
                e.preventDefault();
                alert('Passwords do not match!');
                return false;
            }
            
            if (password.length < 6) {
                e.preventDefault();
                alert('Password must be at least 6 characters long!');
                return false;
            }
        });
        
        // Set default cloud expiry date to 30 days from now
        function setDefaultCloudExpiry() {
            const today = new Date();
            const futureDate = new Date(today);
            futureDate.setDate(today.getDate() + 30);
            
            const formattedDate = futureDate.toISOString().split('T')[0];
            document.getElementById('cloud_expiry_date').value = formattedDate;
        }
        
        // Auto-generate business code from business name
        document.getElementById('business_name').addEventListener('blur', function() {
            const businessName = this.value.trim();
            if (businessName && !document.getElementById('business_code').value) {
                // Generate code: First 3 letters of each word, uppercase
                const words = businessName.split(' ');
                let code = '';
                for (let word of words) {
                    if (word.length >= 3) {
                        code += word.substring(0, 3).toUpperCase();
                    }
                }
                if (code.length > 6) code = code.substring(0, 6);
                document.getElementById('business_code').value = code + '001';
            }
        });
        
        // Auto-generate shop code from shop name
        document.getElementById('shop_name').addEventListener('blur', function() {
            const shopName = this.value.trim();
            if (shopName && !document.getElementById('shop_code').value) {
                const businessCode = document.getElementById('business_code').value;
                const prefix = businessCode ? businessCode.substring(0, 2) : 'SP';
                document.getElementById('shop_code').value = prefix + '001';
            }
        });
        
        // Call on page load
        setDefaultCloudExpiry();
    </script>
</body>
</html>