<?php
date_default_timezone_set('Asia/Kolkata');
session_start();
require_once 'config/database.php';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['admin', 'warehouse_manager', 'shop_manager','stock_manager'])) {
    header('Location: login.php');
    exit();
}

// Get current business ID from session
$current_business_id = $_SESSION['current_business_id'] ?? null;

if (!$current_business_id) {
    header('Location: select_shop.php');
    exit();
}

// Fetch all products for current business
$products = $pdo->prepare("
    SELECT p.id, p.product_name, p.product_code, p.barcode, p.retail_price
    FROM products p 
    WHERE p.is_active = 1 
    AND p.business_id = ?
    ORDER BY p.product_name
");
$products->execute([$current_business_id]);
$products = $products->fetchAll();

// Auto-generate barcode if empty (using product ID)
foreach ($products as &$p) {
    if (empty($p['barcode'])) {
        $p['barcode'] = str_pad($p['id'], 12, '0', STR_PAD_LEFT); // 12-digit EAN-like
    }
}

// Also fetch business name for display
$business_stmt = $pdo->prepare("SELECT business_name FROM businesses WHERE id = ?");
$business_stmt->execute([$current_business_id]);
$business = $business_stmt->fetch();
$business_name = $business['business_name'] ?? 'Unknown Business';
?>

<!doctype html>
<html lang="en">
<?php 
$page_title = "Barcode Generator - " . htmlspecialchars($business_name); 
include 'includes/head.php'; 
?>
<!-- DataTables CSS -->
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.7/css/jquery.dataTables.min.css">
<link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.2/css/buttons.dataTables.min.css">
<style>
/* Preview container - labels grid */
#labelsPreview {
    display: grid;
    grid-template-columns: repeat(2, 50mm); /* 50mm width per label */
    gap: 1mm; /* Small gap between labels */
    padding: 0;
    margin: 0;
    background: #fff;
    width: 102mm; /* Total width for 2 labels + gap (50*2 + 2 = 102mm) */
}

/* Each label - compact for barcode only */
.label-card {
    width: 50mm;
    height: 25mm; /* 25mm height */
    box-sizing: border-box;
    display: flex;
    flex-direction: column;
    justify-content: center;
    align-items: center;
    margin: 0;
    padding: 1mm;
    background: #fff;
    overflow: hidden;
    border: 1px solid #ddd;
    font-family: Arial, sans-serif;
    page-break-inside: avoid;
    position: relative;
}

/* Barcode container - takes most of the label */
.barcode-container {
    height: 18mm;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0;
    width: 85%;
    min-height: 18mm;
    background: white;
    padding: 0;
}

.barcode {
    width: 85%;
    height: 100%;
    display: block;
    overflow: visible;
}

/* Barcode numeric value only */
.barcode-text {
    font-size: 6px !important;
    font-family: 'Arial', sans-serif !important;
    font-weight: 800 !important;
    fill: #000 !important;
    text-anchor: middle !important;
    dominant-baseline: hanging !important;
    letter-spacing: 0.5px;
    position: relative !important;
    top: -100px !important;
}

/* Print styles optimized for labels */
@media print {
    @page {
        size: 105mm auto;
        margin: 0 !important;
        padding: 0 !important;
    }

    body {
        margin: 0 !important;
        padding: 0 !important;
        background: white !important;
        width: 105mm !important;
        min-height: 100vh;
        font-family: Arial, sans-serif !important;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }

    .no-print, .no-print * {
        display: none !important;
    }

    #layout-wrapper, .main-content, .page-content, .container-fluid {
        display: none !important;
    }

    #printArea {
        display: block !important;
        width: 105mm !important;
        margin: 0 !important;
        padding: 0 !important;
    }

    #labelsPreview {
        display: grid !important;
        grid-template-columns: repeat(2, 50mm) !important;
        gap: 0 !important;
        width: 105mm !important;
        margin: 0 !important;
        padding: 0 !important;
        background: white !important;
        page-break-inside: avoid;
    }

    .label-card {
        width: 50mm !important;
        height: 25mm !important;
        border: 1px solid #eee !important;
        margin: 0 !important;
        padding: 0.5mm !important;
        background: white !important;
        page-break-inside: avoid;
        display: flex !important;
        justify-content: center !important;
        align-items: center !important;
        flex-direction: column !important;
        overflow: hidden !important;
    }

    .barcode-container {
        height: 20mm !important;
        margin: 0 !important;
        min-height: 20mm !important;
        background: transparent !important;
        padding: 0 !important;
    }

    .barcode {
        width: 100% !important;
        height: 100% !important;
        overflow: visible !important;
    }

    .barcode-text {
        font-size: 6px !important;
        font-weight: 600 !important;
        fill: #000 !important;
        position: relative !important;
        top: -100px !important;
    }

    * {
        box-sizing: border-box;
        max-width: 80%;
    }
}

/* DataTables customization */
.dataTables_wrapper {
    margin-bottom: 20px;
}

/* Preview section styling */
#printArea {
    margin-top: 20px;
    border: 1px solid #ddd;
    padding: 15px;
    background: #f9f9f9;
    overflow-x: auto;
    border-radius: 5px;
    display: none; /* Hidden by default */
}

/* Button styles */
.btn-generate-one {
    font-size: 12px;
    padding: 4px 10px;
}

/* Badge styles */
.badge.bg-success {
    background-color: #28a745 !important;
    font-size: 11px;
    padding: 3px 6px;
}

.badge.bg-secondary {
    background-color: #6c757d !important;
    font-size: 11px;
    padding: 3px 6px;
}

/* Responsive adjustments */
@media screen and (max-width: 1200px) {
    #labelsPreview {
        grid-template-columns: repeat(2, 50mm);
        width: 102mm;
    }
}

@media screen and (max-width: 768px) {
    #labelsPreview {
        grid-template-columns: repeat(1, 50mm);
        width: 52mm;
    }
}

/* Custom styles for the new UI */
.shop-info {
    text-align: center;
    padding: 10px;
}

.shop-name {
    font-size: 20px;
    font-weight: bold;
    color: #333;
    margin-bottom: 5px;
}

.shop-address {
    font-size: 14px;
    color: #666;
}

.product-card {
    transition: all 0.3s ease;
}

.product-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 15px rgba(0,0,0,0.1);
}

.quantity-input {
    width: 60px;
    text-align: center;
}

/* Custom notification */
.custom-notification {
    position: fixed;
    top: 80px;
    right: 20px;
    background: #28a745;
    color: white;
    padding: 10px 15px;
    border-radius: 4px;
    box-shadow: 0 2px 5px rgba(0,0,0,0.2);
    z-index: 9999;
    animation: slideIn 0.3s ease;
}

@keyframes slideIn {
    from {
        transform: translateX(100%);
        opacity: 0;
    }
    to {
        transform: translateX(0);
        opacity: 1;
    }
}

/* Settings card styling */
.settings-card {
    background: #f8f9fa;
    border: 1px solid #dee2e6;
    border-radius: 5px;
    padding: 15px;
}

.settings-card .form-label {
    font-weight: 500;
    font-size: 14px;
}

/* Generation section specific */
#generationSection {
    display: none;
}

/* Header buttons */
#btnGenerateAll, #btnClear, #btnPrint, #btnBackToSelection {
    padding: 6px 12px;
    font-size: 14px;
}

/* Hide copy number display */
.copy-number {
    display: none !important;
}

/* DataTables filter fix */
.dataTables_filter input {
    padding: 4px 8px !important;
    border: 1px solid #ced4da !important;
    border-radius: 4px !important;
    font-size: 14px !important;
}

/* Product info in barcode */
.product-info {
    font-size: 10px !important;
    text-align: center;
    margin-top: 2px;
    line-height: 1.2;
}

.product-code {
    font-weight: 600;
    color: #333;
}

.product-price {
    color: #28a745;
    font-weight: bold;
}

/* Grid layout adjustments based on labels per row */
.grid-2-cols { grid-template-columns: repeat(2, 50mm) !important; width: 102mm !important; }
.grid-3-cols { grid-template-columns: repeat(3, 50mm) !important; width: 153mm !important; }
.grid-4-cols { grid-template-columns: repeat(4, 50mm) !important; width: 204mm !important; }
.grid-5-cols { grid-template-columns: repeat(5, 50mm) !important; width: 255mm !important; }

@media screen and (max-width: 768px) {
    .grid-2-cols, .grid-3-cols, .grid-4-cols, .grid-5-cols {
        grid-template-columns: repeat(1, 50mm) !important;
        width: 52mm !important;
    }
}
</style>
<body data-sidebar="dark">
<div id="layout-wrapper">
    <?php include 'includes/topbar.php'; ?>
    <div class="vertical-menu">
        <div data-simplebar class="h-100">
            <?php include('includes/sidebar.php')?>
        </div>
    </div>

    <div class="main-content">
        <div class="page-content">
            <div class="container-fluid">

                <!-- Header -->
                <div class="row align-items-center mb-3 no-print">
                    <div class="col-12 d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <h4 class="mb-0"><?= htmlspecialchars($business_name) ?> - Barcode Generator</h4>
                        <div class="d-flex flex-wrap gap-2">
                            <button id="btnGenerateAll" class="btn btn-primary btn-sm">
                                <i class="fas fa-barcode me-1"></i> Generate All
                            </button>
                            <button id="btnClear" class="btn btn-danger btn-sm">
                                <i class="fas fa-trash me-1"></i> Clear
                            </button>
                            <button id="btnPrint" class="btn btn-success btn-sm" style="display:none;">
                                <i class="fas fa-print me-1"></i> Print Labels
                            </button>
                            <button id="btnBackToSelection" class="btn btn-secondary btn-sm" style="display:none;">
                                <i class="fas fa-arrow-left me-1"></i> Back to Products
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Shop Info -->
                <div class="card no-print mb-3">
                    <div class="card-body">
                        <div class="shop-info">
                            <div class="shop-name"><?= htmlspecialchars($business_name) ?> - BARCODE GENERATOR</div>
                            <div class="shop-address">50×25 mm Die-Cut Labels (2 per row) - Product Barcodes</div>
                        </div>
                    </div>
                </div>

                <!-- Step 1: Product Selection -->
                <div class="card shadow-sm mb-4 no-print" id="selectionSection">
                    <div class="card-header bg-light">
                        <h5 class="mb-0">
                            <i class="fas fa-list-check me-2"></i>Step 1: Select Products & Set Quantities
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="row mb-3">
                            <div class="col-12">
                                <div class="d-flex align-items-center flex-wrap gap-3">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="selectAll" checked>
                                        <label class="form-check-label fw-medium" for="selectAll">
                                            <i class="fas fa-check-double me-1"></i>Select All Products
                                        </label>
                                    </div>
                                    <div class="input-group" style="width: 200px;">
                                        <span class="input-group-text"><i class="fas fa-hashtag"></i></span>
                                        <input type="number" id="setAllQuantity" class="form-control" value="1" min="1" max="100">
                                        <button class="btn btn-outline-primary" type="button" onclick="applyQuantityToAll()">
                                            Apply to All
                                        </button>
                                    </div>
                                    <div class="ms-auto">
                                        <button class="btn btn-outline-secondary btn-sm" onclick="selectNone()">
                                            <i class="fas fa-times me-1"></i> Select None
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Product Count Summary -->
                        <div class="row mb-3">
                            <div class="col-12">
                                <div class="alert alert-info py-2">
                                    <i class="fas fa-info-circle me-2"></i>
                                    Showing <strong><span id="totalProductsCount">0</span></strong> products | 
                                    Selected: <strong><span id="selectedProductsCount">0</span></strong> | 
                                    Barcodes to generate: <strong><span id="totalBarcodesCount">0</span></strong>
                                </div>
                            </div>
                        </div>
                        
                        <div class="table-responsive">
                            <table id="productsTable" class="table table-bordered table-striped" style="width:100%">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>Product Name</th>
                                        <th>Product Code</th>
                                        <th>Barcode</th>
                                        <th>Price</th>
                                        <th>Copies</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($products)): ?>
                                    
                                    <?php else: ?>
                                    <?php $row_num = 1; ?>
                                    <?php foreach ($products as $p): ?>
                                    <tr data-id="<?= $p['id'] ?>"
                                        data-name="<?= htmlspecialchars($p['product_name']) ?>"
                                        data-code="<?= htmlspecialchars($p['product_code'] ?? '') ?>"
                                        data-barcode="<?= $p['barcode'] ?>"
                                        data-price="<?= $p['retail_price'] ?>">
                                        <td><?= $row_num++ ?></td>
                                        <td><?= htmlspecialchars($p['product_name']) ?></td>
                                        <td><?= htmlspecialchars($p['product_code'] ?? 'N/A') ?></td>
                                        <td>
                                            <?php if (!empty($p['barcode'])): ?>
                                                <span class="badge bg-success" title="Using database barcode">
                                                    <?= $p['barcode'] ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary" title="Auto-generated">
                                                    Auto
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td>₹<?= number_format($p['retail_price'], 2) ?></td>
                                        <td>
                                            <div class="input-group input-group-sm" style="width: 100px;">
                                                <button class="btn btn-outline-secondary btn-sm" type="button" onclick="decrementQuantity(<?= $p['id'] ?>)">-</button>
                                                <input type="number" 
                                                       class="form-control form-control-sm quantity-input text-center copies"
                                                       data-id="<?= $p['id'] ?>"
                                                       id="qty_<?= $p['id'] ?>"
                                                       value="1"
                                                       min="1"
                                                       max="100"
                                                       style="height: 28px;">
                                                <button class="btn btn-outline-secondary btn-sm" type="button" onclick="incrementQuantity(<?= $p['id'] ?>)">+</button>
                                            </div>
                                        </td>
                                        <td>
                                            <button type="button" class="btn btn-sm btn-primary btn-generate-one">
                                                <i class="fas fa-barcode me-1"></i> Generate
                                            </button>
                                            <input class="form-check-input product-checkbox d-none" type="checkbox" 
                                                   id="prod_<?= $p['id'] ?>" 
                                                   data-id="<?= $p['id'] ?>"
                                                   data-name="<?= htmlspecialchars($p['product_name']) ?>"
                                                   data-code="<?= htmlspecialchars($p['product_code'] ?? '') ?>"
                                                   data-barcode="<?= $p['barcode'] ?>"
                                                   data-price="<?= $p['retail_price'] ?>"
                                                   checked>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Step 2: Barcode Settings & Generation -->
                <div class="card shadow-sm mb-4 no-print" id="generationSection">
                    <div class="card-header bg-light">
                        <h5 class="mb-0">
                            <i class="fas fa-cogs me-2"></i>Step 2: Barcode Settings & Generation
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="row g-3 mb-4">
                            <div class="col-md-3">
                                <label class="form-label">
                                    <i class="fas fa-barcode me-1"></i>Barcode Type
                                </label>
                                <select id="barcodeType" class="form-select">
                                    <option value="CODE128">CODE128 (Recommended)</option>
                                    <option value="EAN13">EAN-13</option>
                                    <option value="C39">Code 39</option>
                                    <option value="QR">QR Code</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">
                                    <i class="fas fa-arrows-alt-h me-1"></i>Barcode Width
                                </label>
                                <div class="input-group">
                                    <input type="number" id="barcodeWidth" class="form-control" value="2" min="1" max="5" step="0.1">
                                    <span class="input-group-text">mm</span>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">
                                    <i class="fas fa-arrows-alt-v me-1"></i>Barcode Height
                                </label>
                                <div class="input-group">
                                    <input type="number" id="barcodeHeight" class="form-control" value="60" min="20" max="200" step="5">
                                    <span class="input-group-text">px</span>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">
                                    <i class="fas fa-columns me-1"></i>Labels per Row
                                </label>
                                <select id="labelsPerRow" class="form-select">
                                    <option value="2">2 per row</option>
                                    <option value="3" selected>3 per row (A4)</option>
                                    <option value="4">4 per row</option>
                                    <option value="5">5 per row</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">
                                    <i class="fas fa-tag me-1"></i>Show Price
                                </label>
                                <select id="showPrice" class="form-select">
                                    <option value="1">Yes</option>
                                    <option value="0">No</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">
                                    <i class="fas fa-hashtag me-1"></i>Show Product Code
                                </label>
                                <select id="showCode" class="form-select">
                                    <option value="1">Yes</option>
                                    <option value="0">No</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">
                                    <i class="fas fa-text-height me-1"></i>Font Size
                                </label>
                                <select id="fontSize" class="form-select">
                                    <option value="12">Small (12px)</option>
                                    <option value="14" selected>Medium (14px)</option>
                                    <option value="16">Large (16px)</option>
                                    <option value="18">Extra Large (18px)</option>
                                </select>
                            </div>
                            <div class="col-md-3 d-flex align-items-end">
                                <button onclick="regenerateBarcodes()" class="btn btn-primary w-100">
                                    <i class="fas fa-redo me-1"></i> Regenerate
                                </button>
                            </div>
                        </div>

                        <!-- Label Preview -->
                        <div id="printArea">
                            <div id="labelsPreview"></div>
                        </div>
                        
                        <!-- Summary Information -->
                        <div class="card mt-4 bg-light">
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-4">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <span>Unique Products:</span>
                                            <span class="fw-bold" id="uniqueProducts">0</span>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <span>Total Barcodes:</span>
                                            <span class="fw-bold" id="totalBarcodes">0</span>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <span>Total Pages:</span>
                                            <span class="fw-bold" id="totalPages">0</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php include 'includes/footer.php'; ?>
    </div>
</div>

<?php include 'includes/scripts.php'; ?>
<!-- Additional Libraries -->
<script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/dataTables.buttons.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.5/dist/JsBarcode.all.min.js"></script>
<script>
$(document).ready(function() {
    // Initialize DataTable with proper configuration
    var table = $('#productsTable').DataTable({
        pageLength: 10,
        dom: '<"row"<"col-sm-12 col-md-6"l><"col-sm-12 col-md-6"f>>rt<"row"<"col-sm-12 col-md-6"i><"col-sm-12 col-md-6"p>>',
        language: {
            search: "Search products:",
            lengthMenu: "Show _MENU_ entries",
            info: "Showing _START_ to _END_ of _TOTAL_ entries",
            infoEmpty: "Showing 0 to 0 of 0 entries",
            infoFiltered: "(filtered from _MAX_ total entries)"
        },
        columnDefs: [
            { 
                targets: [0, 5, 6], 
                orderable: false,
                searchable: false
            },
            {
                targets: '_all',
                searchable: true
            }
        ],
        initComplete: function() {
            // Fix search input styling
            $('.dataTables_filter input').addClass('form-control form-control-sm');
            $('.dataTables_filter label').addClass('form-label');
            
            // Fix length selector
            $('.dataTables_length select').addClass('form-select form-select-sm');
            
            // Initialize counts after table is loaded
            setTimeout(updateProductCounts, 100);
        }
    });

    // Initialize counts
    updateProductCounts();
    
    // Select All checkbox
    $('#selectAll').on('change', function() {
        const isChecked = this.checked;
        // Handle both visible and hidden checkboxes
        $('.product-checkbox').prop('checked', isChecked);
        
        // Update DataTable rows
        table.rows().every(function() {
            const row = this.node();
            const productId = $(row).data('id');
            if (productId) {
                $(`#prod_${productId}`).prop('checked', isChecked);
            }
        });
        
        updateProductCounts();
    });
    
    // Individual checkbox changes
    $(document).on('change', '.product-checkbox', updateProductCounts);
    
    // Quantity input changes
    $(document).on('change', '.quantity-input', function() {
        let value = parseInt($(this).val());
        if (isNaN(value) || value < 1) {
            value = 1;
            $(this).val(1);
        }
        if (value > 100) {
            value = 100;
            $(this).val(100);
        }
        updateProductCounts();
    });
    
    // Generate all selected products
    $('#btnGenerateAll').on('click', function(){
        const selectedCheckboxes = $('.product-checkbox:checked');
        
        if (selectedCheckboxes.length === 0) {
            alert('Please select at least one product');
            return;
        }
        
        generateSelectedBarcodes();
        showNotification('All selected barcodes generated successfully!', 'success');
    });
    
    // Generate single product
    $(document).on('click', '.btn-generate-one', function(){
        const $row = $(this).closest('tr');
        const productId = $row.data('id');
        
        // Check the checkbox for this product
        $(`#prod_${productId}`).prop('checked', true);
        updateProductCounts();
        
        // Generate barcodes for this product only
        generateSelectedBarcodes();
        showNotification('Barcode generated!', 'info');
    });
    
    // Clear preview
    $('#btnClear').on('click', function(){
        $('#labelsPreview').empty();
        $('#printArea').hide();
        $('#btnPrint').hide();
        showNotification('Preview cleared!', 'warning');
    });
    
    // Print labels
    $('#btnPrint').on('click', function(){
        if ($('#labelsPreview').children().length === 0) {
            alert('No barcodes generated to print!');
            return;
        }

        // Count labels
        const labelCount = $('#labelsPreview .label-card').length;
        
        // Get current settings
        const labelsPerRow = parseInt($('#labelsPerRow').val());
        const showPrice = $('#showPrice').val() === '1';
        const showCode = $('#showCode').val() === '1';
        const fontSize = parseInt($('#fontSize').val());
        
        // Create print-friendly version
        const printWindow = window.open('', '_blank');
        
        // Determine grid class based on labels per row
        let gridClass = 'grid-2-cols';
        if (labelsPerRow === 3) gridClass = 'grid-3-cols';
        else if (labelsPerRow === 4) gridClass = 'grid-4-cols';
        else if (labelsPerRow === 5) gridClass = 'grid-5-cols';
        
        const printContent = `
            <!DOCTYPE html>
            <html>
            <head>
                <title><?= htmlspecialchars($business_name) ?> - Barcode Labels</title>
                <meta charset="UTF-8">
                <style>
                    @page { 
                        size: auto;
                        margin: 5mm !important; 
                        padding: 0 !important; 
                    }
                    body { 
                        margin: 0; 
                        padding: 5mm; 
                        background: white; 
                        font-family: Arial, sans-serif;
                        -webkit-print-color-adjust: exact;
                        print-color-adjust: exact;
                    }
                    #labelsPreview {
                        display: grid;
                        gap: 2mm;
                        margin: 0;
                        padding: 0;
                        page-break-inside: avoid;
                    }
                    .grid-2-cols { grid-template-columns: repeat(2, 50mm); width: 104mm; }
                    .grid-3-cols { grid-template-columns: repeat(3, 50mm); width: 156mm; }
                    .grid-4-cols { grid-template-columns: repeat(4, 50mm); width: 208mm; }
                    .grid-5-cols { grid-template-columns: repeat(5, 50mm); width: 260mm; }
                    
                    .label-card {
                        width: 50mm;
                        height: 25mm;
                        display: flex;
                        flex-direction: column;
                        justify-content: center;
                        align-items: center;
                        padding: 0.5mm;
                        box-sizing: border-box;
                        page-break-inside: avoid;
                        border: 1px solid #eee;
                        background: white;
                        overflow: hidden;
                    }
                    .barcode-container { 
                        height: 18mm;
                        display: flex; 
                        align-items: center; 
                        justify-content: center; 
                        margin: 0; 
                        width: 85%;
                    }
                    .barcode { 
                        width: 85%; 
                        height: 100%;
                        display: block; 
                        overflow: visible;
                    }
                    .barcode-text {
                        font-size: ${fontSize - 2}px;
                        font-family: 'Arial', sans-serif;
                        font-weight: 600;
                        fill: #000;
                        text-anchor: middle;
                        dominant-baseline: hanging;
                        letter-spacing: 0.5px;
                        position: relative;
                        top: -100px;
                    }
                    .product-info {
                        font-size: ${Math.max(fontSize - 4, 8)}px;
                        text-align: center;
                        margin-top: 1mm;
                        line-height: 1.2;
                    }
                    .product-code {
                        font-weight: 600;
                        color: #333;
                    }
                    .product-price {
                        color: #28a745;
                        font-weight: bold;
                    }
                    * {
                        box-sizing: border-box;
                        max-width: 100%;
                    }
                </style>
            </head>
            <body>
                <div id="labelsPreview" class="${gridClass}">${$('#labelsPreview').html()}</div>
                <script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.6/dist/JsBarcode.all.min.js"><\/script>
                <script>
                    window.onload = function() {
                        // Generate barcodes in print window
                        document.querySelectorAll('.barcode').forEach(function(svg) {
                            const barcodeValue = svg.getAttribute('data-value');
                            if (barcodeValue) {
                                JsBarcode(svg, barcodeValue, {
                                    format: "CODE128",
                                    height: 18,
                                    displayValue: true,
                                    fontSize: ${fontSize - 2},
                                    margin: 0,
                                    width: 1,
                                    background: 'transparent',
                                    fontOptions: 'bold',
                                    textMargin: 0
                                });
                                
                                // Style the barcode text
                                const textElements = svg.querySelectorAll('text');
                                textElements.forEach(text => {
                                    text.setAttribute('font-weight', '900');
                                });
                            }
                        });
                        
                        // Auto print after short delay
                        setTimeout(function() {
                            window.print();
                            setTimeout(function() { 
                                window.close(); 
                            }, 500);
                        }, 500);
                    };
                <\/script>
            </body>
            </html>
        `;

        printWindow.document.write(printContent);
        printWindow.document.close();
        
        showNotification('Printing ' + labelCount + ' barcodes...', 'info');
    });
    
    // Back to selection
    $('#btnBackToSelection').on('click', function(){
        $('#selectionSection').show();
        $('#generationSection').hide();
        $('#btnPrint').hide();
        $('#btnBackToSelection').hide();
        $('#btnGenerateAll').show();
        $('#btnClear').show();
    });
});

function updateProductCounts() {
    // Get all checkboxes (including hidden ones)
    const allCheckboxes = $('.product-checkbox');
    const totalProducts = allCheckboxes.length;
    
    // Count selected checkboxes
    const selectedCheckboxes = $('.product-checkbox:checked');
    const selectedProducts = selectedCheckboxes.length;
    
    // Calculate total barcodes count
    let totalBarcodes = 0;
    selectedCheckboxes.each(function() {
        const productId = $(this).data('id');
        const quantityInput = $(`#qty_${productId}`);
        const quantity = parseInt(quantityInput.val()) || 1;
        totalBarcodes += quantity;
    });
    
    // Update display
    $('#totalProductsCount').text(totalProducts);
    $('#selectedProductsCount').text(selectedProducts);
    $('#totalBarcodesCount').text(totalBarcodes);
}

function incrementQuantity(productId) {
    const input = $(`#qty_${productId}`);
    if (input.length) {
        const currentValue = parseInt(input.val()) || 1;
        if (currentValue < 100) {
            input.val(currentValue + 1).trigger('change');
        }
    }
}

function decrementQuantity(productId) {
    const input = $(`#qty_${productId}`);
    if (input.length) {
        const currentValue = parseInt(input.val()) || 1;
        if (currentValue > 1) {
            input.val(currentValue - 1).trigger('change');
        }
    }
}

function selectNone() {
    $('.product-checkbox').prop('checked', false);
    $('#selectAll').prop('checked', false);
    updateProductCounts();
}

function applyQuantityToAll() {
    const quantity = $('#setAllQuantity').val();
    $('.quantity-input').val(quantity).trigger('change');
}

function generateSelectedBarcodes() {
    const selectedCheckboxes = $('.product-checkbox:checked');
    
    if (selectedCheckboxes.length === 0) {
        alert('Please select at least one product');
        return;
    }
    
    // Store selected products data with quantities
    window.selectedProducts = [];
    selectedCheckboxes.each(function() {
        const productId = $(this).data('id');
        const quantityInput = $(`#qty_${productId}`);
        const quantity = parseInt(quantityInput.val()) || 1;
        
        // Add the product multiple times based on quantity
        for (let i = 0; i < quantity; i++) {
            window.selectedProducts.push({
                id: $(this).data('id'),
                name: $(this).data('name'),
                code: $(this).data('code'),
                barcode: $(this).data('barcode'),
                price: $(this).data('price'),
                quantity: quantity
            });
        }
    });
    
    // Show generation section, hide selection
    $('#selectionSection').hide();
    $('#generationSection').show();
    $('#btnPrint').show();
    $('#btnBackToSelection').show();
    $('#btnGenerateAll').hide();
    $('#btnClear').hide();
    
    // Generate barcodes
    generateBarcodes();
}

function generateBarcodes() {
    const container = $('#labelsPreview');
    const labelsPerRow = parseInt($('#labelsPerRow').val());
    
    container.empty();
    
    // Apply grid class based on labels per row
    let gridClass = 'grid-2-cols';
    if (labelsPerRow === 3) gridClass = 'grid-3-cols';
    else if (labelsPerRow === 4) gridClass = 'grid-4-cols';
    else if (labelsPerRow === 5) gridClass = 'grid-5-cols';
    
    container.removeClass('grid-2-cols grid-3-cols grid-4-cols grid-5-cols').addClass(gridClass);
    
    // Track product quantities for summary
    const productSummary = {};
    window.selectedProducts.forEach(product => {
        if (!productSummary[product.id]) {
            productSummary[product.id] = {
                name: product.name,
                quantity: 0
            };
        }
        productSummary[product.id].quantity++;
    });
    
    window.selectedProducts.forEach((product) => {
        // Create label card
        const labelCard = $(`
            <div class="label-card">
                <div class="barcode-container">
                    <svg class="barcode" data-value="${escapeHtml(product.barcode)}" 
                         xmlns="http://www.w3.org/2000/svg" aria-hidden="true"></svg>
                </div>
            </div>
        `);
        
        container.append(labelCard);
        
        // Generate barcode
        setTimeout(() => {
            const svg = labelCard.find('.barcode')[0];
            const type = $('#barcodeType').val();
            const width = parseFloat($('#barcodeWidth').val());
            const height = parseInt($('#barcodeHeight').val());
            const fontSize = parseInt($('#fontSize').val());
            const showPrice = $('#showPrice').val() === '1';
            const showCode = $('#showCode').val() === '1';
            
            try {
                if (type === 'QR') {
                    // For QR codes, fall back to CODE128
                    JsBarcode(svg, product.barcode, {
                        format: 'CODE128',
                        lineColor: "#000",
                        width: width,
                        height: height,
                        displayValue: true,
                        fontSize: fontSize,
                        margin: 10,
                        background: "#ffffff"
                    });
                } else {
                    JsBarcode(svg, product.barcode, {
                        format: type,
                        lineColor: "#000",
                        width: width,
                        height: height,
                        displayValue: true,
                        fontSize: fontSize,
                        margin: 10,
                        background: "#ffffff"
                    });
                }
                
                // Post-process SVG
                setTimeout(() => {
                    const svgElement = svg;
                    if (!svgElement) return;

                    // Style barcode text
                    const textElements = svgElement.querySelectorAll('text');
                    textElements.forEach(text => {
                        text.classList.add('barcode-text');
                        text.setAttribute('font-weight', '900');
                        text.setAttribute('font-size', fontSize + 'px');
                    });

                    // Clean up SVG
                    const rects = svgElement.querySelectorAll('rect');
                    rects.forEach(rect => {
                        const w = rect.getAttribute('width') || '';
                        const h = rect.getAttribute('height') || '';
                        if (w === '100%' || w === '100' || 
                            (h && svgElement.getAttribute('height') && h === svgElement.getAttribute('height'))) {
                            rect.remove();
                        }
                    });

                    // Make SVG responsive
                    svgElement.setAttribute('width', '100%');
                    svgElement.setAttribute('height', '100%');
                    svgElement.setAttribute('preserveAspectRatio', 'xMidYMid meet');
                    svgElement.style.overflow = 'visible';

                }, 10);
                
                // Add product info if needed
                if (showPrice || showCode) {
                    const infoDiv = $(`
                        <div class="product-info" style="font-size: ${Math.max(fontSize - 4, 10)}px">
                            ${showCode && product.code ? `<div class="product-code">${product.code}</div>` : ''}
                            ${showPrice && product.price > 0 ? `<div class="product-price">₹${parseFloat(product.price).toFixed(2)}</div>` : ''}
                        </div>
                    `);
                    labelCard.append(infoDiv);
                }

            } catch (e) {
                console.error('Barcode generation error:', e);
                // Fallback to text
                $(svg).replaceWith(`<div style="font-size:${fontSize}px;color:#333;text-align:center;font-weight:bold;padding:2mm;">${escapeHtml(product.barcode)}</div>`);
            }
        }, 100);
    });
    
    // Show preview
    $('#printArea').show();
    
    // Update summary information
    updateSummary(productSummary, labelsPerRow);
    
    // Scroll to preview
    $('html, body').animate({
        scrollTop: $("#printArea").offset().top - 20
    }, 500);
}

function updateSummary(productSummary, labelsPerRow) {
    const uniqueProducts = Object.keys(productSummary).length;
    const totalBarcodes = window.selectedProducts.length;
    
    // Calculate estimated pages
    const rowsPerPage = Math.floor(297 / 50);
    const itemsPerPage = rowsPerPage * labelsPerRow;
    const estimatedPages = Math.ceil(totalBarcodes / itemsPerPage);
    
    $('#uniqueProducts').text(uniqueProducts);
    $('#totalBarcodes').text(totalBarcodes);
    $('#totalPages').text(estimatedPages);
}

function regenerateBarcodes() {
    if (window.selectedProducts && window.selectedProducts.length > 0) {
        $('#labelsPreview').empty();
        generateBarcodes();
    }
}

// Helper function to escape HTML
function escapeHtml(str) {
    if (!str) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

// Show notification
function showNotification(message, type = 'info') {
    // Remove existing notification
    $('.custom-notification').remove();
    
    const bgColor = type === 'success' ? '#28a745' : 
                   type === 'warning' ? '#ffc107' : 
                   type === 'danger' ? '#dc3545' : '#17a2b8';
    
    const notification = $(`
        <div class="custom-notification" style="background: ${bgColor}">
            ${message}
        </div>
    `);
    
    $('body').append(notification);
    
    setTimeout(() => {
        notification.fadeOut(300, function() {
            $(this).remove();
        });
    }, 3000);
}

// Event listeners for settings changes
$('#labelsPerRow, #fontSize, #showPrice, #showCode, #barcodeType, #barcodeWidth, #barcodeHeight').on('change', function() {
    if (window.selectedProducts && window.selectedProducts.length > 0) {
        regenerateBarcodes();
    }
});
</script>
</body>
</html>