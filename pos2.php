<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>POS Billing System - GST Invoice</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Arial, sans-serif;
        }

        body {
            background-color: #f0f2f5;
            padding: 15px;
            min-width: 1200px;
            overflow-y: hidden;
        }

        .container {
            background-color: white;
            border: 1px solid #d0d0d0;
            height: calc(100vh - 30px);
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        /* Header Styles */
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px 15px;
            border-bottom: 1px solid #d0d0d0;
            background-color: white;
        }

        .header-title {
            font-size: 22px;
            font-weight: 600;
            color: #333;
        }

        .balance-badge {
            background-color: #ffcc00;
            color: #333;
            padding: 6px 15px;
            border-radius: 3px;
            font-weight: bold;
            font-size: 14px;
            border: 1px solid #e6b800;
        }

        /* Form Section Styles */
        .form-section {
            padding: 15px;
            border-bottom: 1px solid #d0d0d0;
        }

        .form-row {
            display: flex;
            margin-bottom: 12px;
        }

        .form-row:last-child {
            margin-bottom: 0;
        }

        .form-group {
            margin-right: 20px;
            display: flex;
            flex-direction: column;
        }

        .form-group label {
            font-size: 12px;
            color: #555;
            margin-bottom: 4px;
            font-weight: 500;
        }

        .form-group input, .form-group select, .form-group textarea {
            padding: 6px 8px;
            border: 1px solid #b0b0b0;
            font-size: 13px;
            height: 32px;
            width: 200px;
        }

        .form-group textarea {
            height: 32px;
            resize: none;
        }

        .radio-group {
            display: flex;
            align-items: center;
            margin-top: 4px;
        }

        .radio-group label {
            display: flex;
            align-items: center;
            margin-right: 15px;
            font-size: 13px;
            cursor: pointer;
        }

        .radio-group input {
            margin-right: 5px;
        }

        .link {
            color: #2f8ee0;
            text-decoration: none;
            font-size: 12px;
            margin-left: 8px;
        }

        /* Particulars Section */
        .particulars-section {
            padding: 15px;
            border-bottom: 1px solid #d0d0d0;
        }

        .section-title {
            font-size: 16px;
            font-weight: 600;
            color: #333;
            margin-bottom: 12px;
            display: flex;
            align-items: center;
        }

        .particulars-header {
            display: flex;
            margin-bottom: 10px;
        }

        .tagging-options {
            display: flex;
            margin-right: 30px;
        }

        .tagging-options label {
            display: flex;
            align-items: center;
            margin-right: 15px;
            font-size: 13px;
            cursor: pointer;
        }

        .tagging-options input {
            margin-right: 5px;
        }

        .batch-input {
            display: flex;
            flex-direction: column;
        }

        .batch-input label {
            font-size: 12px;
            color: #555;
            margin-bottom: 4px;
            font-weight: 500;
        }

        .batch-input input {
            padding: 6px 8px;
            border: 1px solid #b0b0b0;
            font-size: 13px;
            height: 32px;
            width: 150px;
        }

        .item-entry-row {
            display: flex;
            align-items: flex-end;
            margin-bottom: 10px;
        }

        .item-field {
            margin-right: 10px;
            display: flex;
            flex-direction: column;
        }

        .item-field label {
            font-size: 12px;
            color: #555;
            margin-bottom: 4px;
            font-weight: 500;
        }

        .item-name {
            width: 300px;
        }

        .uom {
            width: 100px;
        }

        .quantity, .sale-price, .discount, .amount {
            width: 120px;
        }

        .add-item-btn {
            background-color: #5eb174;
            color: white;
            border: none;
            height: 32px;
            width: 40px;
            font-size: 16px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-left: 5px;
        }

        .item-description {
            width: 100%;
        }

        .item-description textarea {
            width: 100%;
            height: 32px;
            padding: 6px 8px;
            border: 1px solid #b0b0b0;
            font-size: 13px;
            resize: none;
        }

        /* Items Table */
        .items-table-section {
            padding: 15px;
            border-bottom: 1px solid #d0d0d0;
            flex: 1;
            overflow: hidden;
        }

        .items-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }

        .items-table thead {
            background-color: #2f8ee0;
            color: white;
        }

        .items-table th {
            padding: 8px 10px;
            text-align: left;
            font-weight: 600;
            border-right: 1px solid white;
        }

        .items-table th:last-child {
            border-right: none;
        }

        .items-table td {
            padding: 8px 10px;
            border-bottom: 1px solid #e0e0e0;
        }

        .items-table tbody tr {
            cursor: pointer;
        }

        .items-table tbody tr:hover {
            background-color: #f5f5f5;
        }

        .items-table tbody tr.selected {
            background-color: #e8e8e8;
        }

        /* Options & Summary Section */
        .options-summary-section {
            display: flex;
            padding: 15px;
            border-bottom: 1px solid #d0d0d0;
        }

        .options-left {
            width: 30%;
            display: flex;
            flex-direction: column;
        }

        .checkbox-group {
            margin-bottom: 12px;
            display: flex;
            align-items: center;
        }

        .checkbox-group input {
            margin-right: 8px;
        }

        .checkbox-group label {
            font-size: 13px;
        }

        .bell-indicator {
            color: #ff9900;
            font-size: 18px;
        }

        .delivery-terms {
            width: 40%;
            padding: 0 20px;
            border-left: 1px solid #e0e0e0;
            border-right: 1px solid #e0e0e0;
        }

        .delivery-terms-box {
            background-color: #f8f8f8;
            padding: 12px;
            border: 1px solid #e0e0e0;
        }

        .delivery-terms-box p {
            font-size: 13px;
            margin-bottom: 5px;
        }

        .amount-summary {
            width: 30%;
            padding-left: 20px;
        }

        .summary-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 8px;
            font-size: 14px;
        }

        .summary-row.total {
            font-weight: bold;
            font-size: 18px;
            margin-top: 10px;
            padding-top: 8px;
            border-top: 2px solid #d0d0d0;
        }

        /* Payment & Remarks Section */
        .payment-remarks-section {
            display: flex;
            padding: 15px;
        }

        .payment-details {
            width: 40%;
        }

        .payment-options {
            display: flex;
            flex-wrap: wrap;
            margin-bottom: 12px;
        }

        .payment-options label {
            display: flex;
            align-items: center;
            margin-right: 15px;
            font-size: 13px;
            cursor: pointer;
            margin-bottom: 8px;
        }

        .payment-options input {
            margin-right: 5px;
        }

        .payment-amount {
            display: flex;
            align-items: center;
        }

        .payment-amount label {
            font-size: 13px;
            margin-right: 10px;
        }

        .payment-amount input {
            padding: 6px 8px;
            border: 1px solid #b0b0b0;
            font-size: 13px;
            height: 32px;
            width: 150px;
        }

        .remarks-section {
            width: 60%;
            padding-left: 20px;
        }

        .remarks-section label {
            display: block;
            font-size: 12px;
            color: #555;
            margin-bottom: 4px;
            font-weight: 500;
        }

        .remarks-section textarea {
            width: 100%;
            height: 60px;
            padding: 8px;
            border: 1px solid #b0b0b0;
            font-size: 13px;
            resize: none;
        }

        /* Footer Action Buttons */
        .footer {
            padding: 15px;
            display: flex;
            justify-content: flex-end;
            background-color: #f8f8f8;
            border-top: 1px solid #d0d0d0;
        }

        .action-buttons {
            display: flex;
        }

        .btn {
            padding: 8px 20px;
            font-size: 14px;
            font-weight: 600;
            border: none;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-left: 15px;
            height: 36px;
            min-width: 140px;
        }

        .btn i {
            margin-right: 8px;
        }

        .btn-save-print {
            background-color: #2f8ee0;
            color: white;
        }

        .btn-save {
            background-color: #409faf;
            color: white;
        }

        .currency-prefix {
            position: relative;
        }

        .currency-prefix::before {
            content: "₹";
            position: absolute;
            left: 8px;
            top: 50%;
            transform: translateY(-50%);
            font-size: 13px;
            color: #333;
        }

        .currency-prefix input {
            padding-left: 22px;
        }

        .percentage-input {
            position: relative;
        }

        .percentage-input::after {
            content: "%";
            position: absolute;
            right: 8px;
            top: 50%;
            transform: translateY(-50%);
            font-size: 13px;
            color: #333;
        }

        .percentage-input input {
            padding-right: 22px;
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Header -->
        <div class="header">
            <div class="header-title">Unsaved Invoice</div>
            <div class="balance-badge">A/c Balance : ₹ -6077.00</div>
        </div>

        <!-- Invoice Information Section -->
        <div class="form-section">
            <div class="form-row">
                <div class="form-group">
                    <label for="invoice-type">Invoice Type</label>
                    <select id="invoice-type">
                        <option selected>GST</option>
                        <option>Non-GST</option>
                        <option>Export</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="invoice-no">Invoice No. *</label>
                    <input type="text" id="invoice-no" value="INV-2023-00127" required>
                </div>
                <div class="form-group">
                    <label for="date">Date</label>
                    <input type="date" id="date" value="2023-11-15">
                </div>
                <div class="form-group">
                    <label for="sold-by">Sold By</label>
                    <select id="sold-by">
                        <option selected>John Sharma</option>
                        <option>Priya Verma</option>
                        <option>Raj Patel</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Bill To</label>
                    <div class="radio-group">
                        <label><input type="radio" name="bill-to" checked> Cash A/c</label>
                        <label><input type="radio" name="bill-to"> Client A/c</label>
                    </div>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label for="mobile">Mobile No.</label>
                    <input type="text" id="mobile" value="9876543210">
                </div>
                <div class="form-group">
                    <label for="client-name">Client Name</label>
                    <div style="display: flex; align-items: center;">
                        <select id="client-name" style="width: 180px;">
                            <option selected>Retail Customer</option>
                            <option>ABC Enterprises</option>
                            <option>XYZ Traders</option>
                        </select>
                        <a href="#" class="link">Retailers</a>
                    </div>
                </div>
                <div class="form-group">
                    <label for="address">Address</label>
                    <input type="text" id="address" value="123, Main Street, Mumbai" style="width: 300px;">
                </div>
                <div class="form-group">
                    <label for="place-of-supply">Place of Supply</label>
                    <select id="place-of-supply">
                        <option selected>Maharashtra</option>
                        <option>Delhi</option>
                        <option>Karnataka</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="gstin">Client GSTIN</label>
                    <input type="text" id="gstin" value="27AAACU1234F1Z5">
                </div>
            </div>
        </div>

        <!-- Particulars Section -->
        <div class="particulars-section">
            <div class="section-title">Particulars</div>
            <div class="particulars-header">
                <div class="tagging-options">
                    <label><input type="radio" name="tagging" checked> Tagging</label>
                    <label><input type="radio" name="tagging"> Item Code</label>
                </div>
                <div class="batch-input">
                    <label for="batch-no">Batch No.</label>
                    <input type="text" id="batch-no" value="BATCH-001">
                </div>
            </div>
            <div class="item-entry-row">
                <div class="item-field item-name">
                    <label for="item-name">Item Name</label>
                    <div style="display: flex;">
                        <select id="item-name" style="flex: 1;">
                            <option selected>Premium Laptop</option>
                            <option>Wireless Mouse</option>
                            <option>External Hard Drive</option>
                            <option>Keyboard</option>
                        </select>
                        <button style="background: none; border: 1px solid #b0b0b0; margin-left: 2px; width: 30px; cursor: pointer;">+</button>
                    </div>
                </div>
                <div class="item-field uom">
                    <label for="uom">UoM</label>
                    <select id="uom">
                        <option selected>Pcs</option>
                        <option>Box</option>
                        <option>Kg</option>
                        <option>Meter</option>
                    </select>
                </div>
                <div class="item-field quantity">
                    <label for="quantity">Quantity</label>
                    <input type="number" id="quantity" value="1" min="1">
                </div>
                <div class="item-field sale-price currency-prefix">
                    <label for="sale-price">Sale Price</label>
                    <input type="number" id="sale-price" value="55000">
                </div>
                <div class="item-field discount percentage-input">
                    <label for="discount">Discount</label>
                    <input type="number" id="discount" value="5" min="0" max="100">
                </div>
                <div class="item-field amount currency-prefix">
                    <label for="amount">Amount</label>
                    <input type="text" id="amount" value="52,250" readonly>
                </div>
                <button class="add-item-btn">+</button>
            </div>
            <div class="item-field item-description">
                <label for="item-description">Item Description</label>
                <textarea id="item-description">15.6" FHD Display, 16GB RAM, 512GB SSD, Intel Core i7</textarea>
            </div>
        </div>

        <!-- Items Table -->
        <div class="items-table-section">
            <table class="items-table">
                <thead>
                    <tr>
                        <th>S. No</th>
                        <th>Item Name</th>
                        <th>Tag</th>
                        <th>Quantity</th>
                        <th>UoM</th>
                        <th>Sale Price</th>
                        <th>Disc.(%)</th>
                        <th>Amount</th>
                    </tr>
                </thead>
                <tbody>
                    <tr class="selected">
                        <td>1</td>
                        <td>Premium Laptop</td>
                        <td>ELEC-001</td>
                        <td>1</td>
                        <td>Pcs</td>
                        <td>₹ 55,000.00</td>
                        <td>5%</td>
                        <td>₹ 52,250.00</td>
                    </tr>
                    <tr>
                        <td>2</td>
                        <td>Wireless Mouse</td>
                        <td>ELEC-002</td>
                        <td>2</td>
                        <td>Pcs</td>
                        <td>₹ 1,200.00</td>
                        <td>10%</td>
                        <td>₹ 2,160.00</td>
                    </tr>
                    <tr>
                        <td>3</td>
                        <td>External Hard Drive (1TB)</td>
                        <td>ELEC-003</td>
                        <td>1</td>
                        <td>Pcs</td>
                        <td>₹ 4,500.00</td>
                        <td>0%</td>
                        <td>₹ 4,500.00</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Options & Summary Section -->
        <div class="options-summary-section">
            <div class="options-left">
                <div class="checkbox-group">
                    <input type="checkbox" id="shipping-costs" checked>
                    <label for="shipping-costs">Add Shipping and Packaging Costs</label>
                </div>
                <div class="checkbox-group">
                    <input type="checkbox" id="invoice-ref">
                    <label for="invoice-ref">Invoice Reference</label>
                </div>
                <div class="checkbox-group">
                    <i class="fas fa-bell bell-indicator"></i>
                    <label style="margin-left: 8px;">Notifications Active</label>
                </div>
            </div>
            <div class="delivery-terms">
                <div class="delivery-terms-box">
                    <p><strong>PAYMENT TERMS :</strong> Online</p>
                    <p><strong>Payment ID:</strong> MOJO1234ABCD5678</p>
                </div>
            </div>
            <div class="amount-summary">
                <div class="summary-row">
                    <span>Sub Total</span>
                    <span>₹ 58,910.00</span>
                </div>
                <div class="summary-row">
                    <span>Add CGST (9%)</span>
                    <span>₹ 5,301.90</span>
                </div>
                <div class="summary-row">
                    <span>Add SGST (9%)</span>
                    <span>₹ 5,301.90</span>
                </div>
                <div class="summary-row">
                    <span>Round Off (+)</span>
                    <span>₹ 0.20</span>
                </div>
                <div class="summary-row total">
                    <span>TOTAL AMOUNT</span>
                    <span>₹ 69,514.00</span>
                </div>
            </div>
        </div>

        <!-- Payment & Remarks Section -->
        <div class="payment-remarks-section">
            <div class="payment-details">
                <div class="payment-options">
                    <label><input type="radio" name="payment-mode" checked> Cash</label>
                    <label><input type="radio" name="payment-mode"> Cheque</label>
                    <label><input type="radio" name="payment-mode"> Card</label>
                    <label><input type="radio" name="payment-mode"> Mobile Wallet</label>
                    <label><input type="radio" name="payment-mode"> Demand Draft</label>
                    <label><input type="radio" name="payment-mode"> Bank Transfer</label>
                </div>
                <div class="payment-amount">
                    <label>Amount:</label>
                    <div class="currency-prefix">
                        <input type="number" value="69514">
                    </div>
                </div>
            </div>
            <div class="remarks-section">
                <label for="remarks">Remarks (Private Use)</label>
                <textarea id="remarks">Payment received in full. Product delivered with warranty card and accessories.</textarea>
            </div>
        </div>

        <!-- Footer Action Buttons -->
        <div class="footer">
            <div class="action-buttons">
                <button class="btn btn-save-print">
                    <i class="fas fa-print"></i> Save and Print
                </button>
                <button class="btn btn-save">
                    <i class="fas fa-save"></i> Save
                </button>
            </div>
        </div>
    </div>

    <script>
        // Auto-calculate amount based on quantity, price, and discount
        function calculateAmount() {
            const quantity = parseFloat(document.getElementById('quantity').value) || 0;
            const salePrice = parseFloat(document.getElementById('sale-price').value) || 0;
            const discount = parseFloat(document.getElementById('discount').value) || 0;
            
            const discountAmount = (salePrice * discount) / 100;
            const amount = (salePrice - discountAmount) * quantity;
            
            // Format the amount with commas
            document.getElementById('amount').value = amount.toLocaleString('en-IN');
            
            // Update the total in the summary section
            updateSummary();
        }

        // Update the summary totals
        function updateSummary() {
            // In a real application, this would calculate from all items in the table
            // For this demo, we'll just update based on the current item
            const quantity = parseFloat(document.getElementById('quantity').value) || 0;
            const salePrice = parseFloat(document.getElementById('sale-price').value) || 0;
            const discount = parseFloat(document.getElementById('discount').value) || 0;
            
            const discountAmount = (salePrice * discount) / 100;
            const subtotal = (salePrice - discountAmount) * quantity;
            const cgst = subtotal * 0.09; // 9% CGST
            const sgst = subtotal * 0.09; // 9% SGST
            const total = subtotal + cgst + sgst;
            
            // Round off calculation
            const roundedTotal = Math.round(total);
            const roundOff = roundedTotal - total;
            
            // Update the summary section
            document.querySelectorAll('.summary-row')[0].children[1].textContent = '₹ ' + subtotal.toLocaleString('en-IN', {minimumFractionDigits: 2});
            document.querySelectorAll('.summary-row')[1].children[1].textContent = '₹ ' + cgst.toLocaleString('en-IN', {minimumFractionDigits: 2});
            document.querySelectorAll('.summary-row')[2].children[1].textContent = '₹ ' + sgst.toLocaleString('en-IN', {minimumFractionDigits: 2});
            document.querySelectorAll('.summary-row')[3].children[1].textContent = '₹ ' + roundOff.toLocaleString('en-IN', {minimumFractionDigits: 2});
            document.querySelectorAll('.summary-row')[4].children[1].textContent = '₹ ' + roundedTotal.toLocaleString('en-IN');
            
            // Update payment amount
            document.querySelector('.payment-amount input').value = roundedTotal;
        }

        // Initialize event listeners
        document.addEventListener('DOMContentLoaded', function() {
            // Calculate amount when inputs change
            document.getElementById('quantity').addEventListener('input', calculateAmount);
            document.getElementById('sale-price').addEventListener('input', calculateAmount);
            document.getElementById('discount').addEventListener('input', calculateAmount);
            
            // Initialize calculation
            calculateAmount();
            
            // Table row selection
            const tableRows = document.querySelectorAll('.items-table tbody tr');
            tableRows.forEach(row => {
                row.addEventListener('click', function() {
                    tableRows.forEach(r => r.classList.remove('selected'));
                    this.classList.add('selected');
                    
                    // In a real application, this would load the selected item into the form
                });
            });
            
            // Add item button functionality
            document.querySelector('.add-item-btn').addEventListener('click', function() {
                // In a real application, this would add the current item to the table
                alert('Item added to invoice. In a full application, this would update the items table.');
            });
            
            // Save and Print button
            document.querySelector('.btn-save-print').addEventListener('click', function() {
                alert('Invoice saved and sent to printer. In a full application, this would save the invoice and open print dialog.');
            });
            
            // Save button
            document.querySelector('.btn-save').addEventListener('click', function() {
                alert('Invoice saved. In a full application, this would save the invoice to the database.');
            });
            
            // Keyboard navigation
            document.addEventListener('keydown', function(e) {
                // Tab navigation is already built into browsers
                // We'll add some additional shortcuts
                if (e.key === 'F2') {
                    e.preventDefault();
                    document.querySelector('.btn-save').click();
                } else if (e.key === 'F9') {
                    e.preventDefault();
                    document.querySelector('.btn-save-print').click();
                } else if (e.key === 'F8' && e.ctrlKey) {
                    e.preventDefault();
                    document.querySelector('.add-item-btn').click();
                }
            });
        });
    </script>
</body>
</html>