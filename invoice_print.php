<?php
// invoice_print.php - Using FPDF with conditional template based on business_id
session_start();
require_once 'config/database.php';

// Check authentication
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

$business_id = $_SESSION['business_id'] ?? 1;

// Check if we have invoice_id
if (isset($_GET['invoice_id'])) {
    $invoice_id = (int) $_GET['invoice_id'];
    if ($invoice_id <= 0) {
        die("Invalid invoice ID");
    }
} else {
    header('Location: invoices.php?msg=' . urlencode('Invoice ID is required') . '&type=danger');
    exit();
}

// Fetch invoice with shop details and shipping details
$stmt = $pdo->prepare("
    SELECT i.*,
           c.name as customer_name, c.phone as customer_phone, c.gstin as customer_gstin,
           c.address as customer_address,
           c.district as customer_district,
           c.state as customer_state,
           c.pincode as customer_pincode,
           u.full_name as seller_name,
           s.shop_name, s.address as shop_address, s.phone as shop_phone, s.gstin as shop_gstin,
           s.id as shop_id,
           i.shipping_name, i.shipping_contact, i.shipping_gstin, i.shipping_address,
           i.shipping_district, i.shipping_state, i.shipping_pincode,
           i.shipping_vehicle_number, i.shipping_transport_type,
           i.shipping_charges, i.transport_charge
    FROM invoices i
    LEFT JOIN customers c ON i.customer_id = c.id
    LEFT JOIN users u ON i.seller_id = u.id
    LEFT JOIN shops s ON i.shop_id = s.id
    WHERE i.id = ? AND i.business_id = ?
");
$stmt->execute([$invoice_id, $business_id]);
$invoice = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$invoice) {
    die("Invoice not found or access denied");
}

// Get shipping / transport details
$shipping_name = $invoice['shipping_name'] ?? '';
$shipping_contact = $invoice['shipping_contact'] ?? '';
$shipping_gstin = $invoice['shipping_gstin'] ?? '';
$shipping_address = $invoice['shipping_address'] ?? '';
$shipping_district = $invoice['shipping_district'] ?? '';
$shipping_state = $invoice['shipping_state'] ?? '';
$shipping_pincode = $invoice['shipping_pincode'] ?? '';

$shipping_full_address = trim(implode(', ', array_filter([
    $shipping_address,
    $shipping_district,
    $shipping_state,
    $shipping_pincode
])));

$shipping_vehicle_number = $invoice['shipping_vehicle_number'] ?? '';
$shipping_transport_type = $invoice['shipping_transport_type'] ?? '';
$shipping_charges = (float) ($invoice['shipping_charges'] ?? 0);
$transport_charge = (float) ($invoice['transport_charge'] ?? 0);
$total_extra_charges = $shipping_charges + $transport_charge;

// Get shop_id from invoice
$shop_id = $invoice['shop_id'] ?? null;

// Fetch invoice settings for this shop/business
$settings = [];

// First try shop-specific settings when shop_id exists
if (!empty($shop_id)) {
    $settings_stmt = $pdo->prepare("
        SELECT *
        FROM invoice_settings
        WHERE business_id = ? AND shop_id = ?
        LIMIT 1
    ");
    $settings_stmt->execute([$business_id, $shop_id]);
    $settings = $settings_stmt->fetch(PDO::FETCH_ASSOC);
}

// Always fallback to business default settings
if (empty($settings)) {
    $settings_stmt = $pdo->prepare("
        SELECT *
        FROM invoice_settings
        WHERE business_id = ? AND shop_id IS NULL
        LIMIT 1
    ");
    $settings_stmt->execute([$business_id]);
    $settings = $settings_stmt->fetch(PDO::FETCH_ASSOC);
}

// Fallback to business table if no settings
if (!$settings) {
    $business_stmt = $pdo->prepare("
        SELECT business_name, phone, address, gstin
        FROM businesses
        WHERE id = ?
        LIMIT 1
    ");
    $business_stmt->execute([$business_id]);
    $business = $business_stmt->fetch(PDO::FETCH_ASSOC);

    $settings = [
        'company_name' => $business['business_name'] ?? 'CLASSIC CAR CARE',
        'company_address' => $business['address'] ?? '111-J, SALEM MAIN ROAD, DHARMAPURI-636705',
        'company_phone' => $business['phone'] ?? '9943701430, 8489755755',
        'company_email' => '',
        'company_website' => '',
        'gst_number' => $business['gstin'] ?? '33AKDPY5436F1Z2',
        'pan_number' => '',
        'logo_path' => '',
        'qr_code_path' => '',
        'qr_code_data' => '',
        'invoice_terms' => "1. Goods Once Sold will not be taken back or exchanged.\n2. Seller is not responsible for any loss or damage of goods in transit\n3. Buyer Undertake to submit prescribed S.T.dech., to the seller on demand\n4. Dispute if any will be subject to Chennai Court jurisdiction Only.\n5. Certified that the particulars given above are true and correct",
        'invoice_footer' => 'Thank you for your business! Visit Again.',
        'invoice_prefix' => 'INV'
    ];
}

// Get company info from settings
$company_name = $settings['company_name'] ?? 'Ecommer';
$company_address = $settings['company_address'] ?? 'Sogathur X Road, Dharmapuri';
$company_phone = $settings['company_phone'] ?? '9003552650';
$company_gstin = $settings['gst_number'] ?? ($invoice['shop_gstin'] ?? '');

// ========== Safe logo path for FPDF ==========
$company_logo = '';
if (!empty($settings['logo_path']) && file_exists($settings['logo_path'])) {
    $logo_ext = strtolower(pathinfo($settings['logo_path'], PATHINFO_EXTENSION));
    if (in_array($logo_ext, ['jpg', 'jpeg', 'png'])) {
        $company_logo = $settings['logo_path'];
    }
}

// ========== Fetch all active bank accounts ==========
// Priority:
// 1) If the invoice belongs to a branch/shop, show ALL active bank accounts for that shop.
// 2) If that shop has no bank accounts, fallback to ALL active business-default bank accounts.
// 3) If no shop_id exists, show ALL active business-default bank accounts.
$bank_accounts = [];

if (!empty($shop_id)) {
    $bank_account_sql = "SELECT *
                         FROM bank_accounts
                         WHERE business_id = ?
                           AND shop_id = ?
                           AND is_active = 1
                         ORDER BY is_default DESC, id ASC";
    $bank_account_stmt = $pdo->prepare($bank_account_sql);
    $bank_account_stmt->execute([$business_id, $shop_id]);
    $bank_accounts = $bank_account_stmt->fetchAll(PDO::FETCH_ASSOC);
}

if (empty($bank_accounts)) {
    $bank_account_sql = "SELECT *
                         FROM bank_accounts
                         WHERE business_id = ?
                           AND shop_id IS NULL
                           AND is_active = 1
                         ORDER BY is_default DESC, id ASC";
    $bank_account_stmt = $pdo->prepare($bank_account_sql);
    $bank_account_stmt->execute([$business_id]);
    $bank_accounts = $bank_account_stmt->fetchAll(PDO::FETCH_ASSOC);
}


// ========== Fetch active footer brand logos ===========
// Priority:
// 1) If invoice belongs to a branch/shop, show ALL active brand logos for that shop.
// 2) If no shop logos, fallback to ALL active business-default brand logos.
// 3) Manual width_mm and height_mm are used while printing.
$brand_logos = [];
try {
    if (!empty($shop_id)) {
        $brand_logo_sql = "SELECT *
                           FROM invoice_brand_logos
                           WHERE business_id = ?
                             AND shop_id = ?
                             AND is_active = 1
                           ORDER BY sort_order ASC, id ASC";
        $brand_logo_stmt = $pdo->prepare($brand_logo_sql);
        $brand_logo_stmt->execute([$business_id, $shop_id]);
        $brand_logos = $brand_logo_stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    if (empty($brand_logos)) {
        $brand_logo_sql = "SELECT *
                           FROM invoice_brand_logos
                           WHERE business_id = ?
                             AND shop_id IS NULL
                             AND is_active = 1
                           ORDER BY sort_order ASC, id ASC";
        $brand_logo_stmt = $pdo->prepare($brand_logo_sql);
        $brand_logo_stmt->execute([$business_id]);
        $brand_logos = $brand_logo_stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Exception $e) {
    $brand_logos = [];
}

// ========== Fetch invoice items ==========
// Manual seller sale items do not exist in products table.
// So use LEFT JOIN and always prefer invoice_items.product_name_snapshot.
$items_stmt = $pdo->prepare("
    SELECT
        ii.id,
        ii.invoice_id,
        ii.product_id,
        ii.product_name_snapshot,
        ii.is_manual_item,
        ii.sale_type,
        ii.quantity,
        ii.unit_price,
        ii.original_price,
        ii.total_price,
        ii.discount_rate,
        ii.discount_amount,
        ii.hsn_code AS item_hsn_code,
        ii.cgst_rate AS item_cgst_rate,
        ii.sgst_rate AS item_sgst_rate,
        ii.igst_rate AS item_igst_rate,
        ii.cgst_amount,
        ii.sgst_amount,
        ii.igst_amount,
        ii.total_with_gst,
        ii.taxable_value,
        ii.profit,
        ii.gst_inclusive,
        ii.referral_commission,
        ii.unit AS item_unit,

        COALESCE(NULLIF(ii.product_name_snapshot, ''), p.product_name, 'Manual Sale Item') AS product_name,
        CASE WHEN COALESCE(ii.is_manual_item, 0) = 1 THEN '' ELSE COALESCE(p.product_code, '') END AS product_code,
        COALESCE(NULLIF(ii.hsn_code, ''), p.hsn_code, '') AS hsn_code,
        p.mrp,
        p.gst_id,
        COALESCE(ii.cgst_rate, g.cgst_rate, 0) AS cgst_rate,
        COALESCE(ii.sgst_rate, g.sgst_rate, 0) AS sgst_rate,
        COALESCE(ii.igst_rate, g.igst_rate, 0) AS igst_rate,
        COALESCE(NULLIF(ii.unit, ''), p.unit_of_measure, 'pcs') AS product_unit
    FROM invoice_items ii
    LEFT JOIN products p ON ii.product_id = p.id
    LEFT JOIN gst_rates g ON p.gst_id = g.id
    WHERE ii.invoice_id = ?
    ORDER BY ii.id
");
$items_stmt->execute([$invoice_id]);
$items = $items_stmt->fetchAll(PDO::FETCH_ASSOC);

// ========== Calculate totals ==========
$subtotal = $total_discount = $total_profit = 0;
$total_taxable = $total_cgst = $total_sgst = $total_igst = 0;
$taxable_by_rate = []; // Group by GST rate for totals box

foreach ($items as $item) {
    $line_total = $item['unit_price'] * $item['quantity'];
    $discount = $item['discount_amount'] ?? 0;
    $net = $line_total - $discount;

    $subtotal += $line_total;
    $total_discount += $discount;
    $total_profit += $item['profit'] ?? 0;

    $taxable = $item['taxable_value'] ?? 0;
    $cgst = $item['cgst_amount'] ?? 0;
    $sgst = $item['sgst_amount'] ?? 0;
    $igst = $item['igst_amount'] ?? 0;

    $total_taxable += $taxable;
    $total_cgst += $cgst;
    $total_sgst += $sgst;
    $total_igst += $igst;

    // Group by GST rate for totals box
    $gst_rate = (float)($item['cgst_rate'] ?? 0) + (float)($item['sgst_rate'] ?? 0) + (float)($item['igst_rate'] ?? 0);
    if ($gst_rate > 0) {
        if (!isset($taxable_by_rate[$gst_rate])) {
            $taxable_by_rate[$gst_rate] = ['taxable' => 0, 'cgst' => 0, 'sgst' => 0, 'igst' => 0];
        }
        $taxable_by_rate[$gst_rate]['taxable'] += $taxable;
        $taxable_by_rate[$gst_rate]['cgst'] += $cgst;
        $taxable_by_rate[$gst_rate]['sgst'] += $sgst;
        $taxable_by_rate[$gst_rate]['igst'] += $igst;
    }
}

// Get overall discount from invoice
$overall_discount = $invoice['overall_discount'] ?? 0;
$grand_total = $invoice['total'];
$is_tax_invoice = !empty($invoice['customer_gstin']) || ($total_cgst + $total_sgst + $total_igst) > 0;
$invoice_date = date('d-m-Y', strtotime($invoice['created_at']));
$invoice_time = date('h:i A', strtotime($invoice['created_at']));

// Payment method from invoice
$payment_method = $invoice['payment_method'] ?? 'Cash';
$payment_status = $invoice['payment_status'] ?? 'Paid';

// Place of supply
$place_of_supply = 'Tamil Nadu (33)';

// Customer details
$customer_name = $invoice['customer_name'] ?? 'Walk-in Customer';
$customer_phone = $invoice['customer_phone'] ?? '';
$customer_address = $invoice['customer_address'] ?? '';
$customer_district = $invoice['customer_district'] ?? '';
$customer_state = $invoice['customer_state'] ?? '';
$customer_pincode = $invoice['customer_pincode'] ?? '';

$customer_full_address = trim(implode(', ', array_filter([
    $customer_address,
    $customer_district,
    $customer_state,
    $customer_pincode
])));

$customer_gstin = $invoice['customer_gstin'] ?? '';

// Transport and waybill details (from shipping)
$transport = !empty($shipping_transport_type)
    ? $shipping_transport_type
    : (!empty($shipping_vehicle_number) ? 'Vehicle: ' . $shipping_vehicle_number : 'By Road');
$waybill_no = $invoice['shipping_waybill_no'] ?? '';

// ========== Include FPDF library ==========
require_once 'libs/fpdf.php';

// ========== Helper functions ==========

function item_display_name(array $item): string
{
    $name = trim((string)($item['product_name_snapshot'] ?? ''));

    if ($name === '') {
        $name = trim((string)($item['product_name'] ?? ''));
    }

    if ($name === '') {
        $name = 'Manual Sale Item';
    }

    return $name;
}

function item_display_code(array $item): string
{
    if (!empty($item['is_manual_item']) || empty($item['product_id'])) {
        return '';
    }

    return trim((string)($item['product_code'] ?? ''));
}

function item_display_hsn(array $item): string
{
    $hsn = trim((string)($item['item_hsn_code'] ?? ''));

    if ($hsn === '') {
        $hsn = trim((string)($item['hsn_code'] ?? ''));
    }

    return $hsn;
}

function item_display_unit(array $item): string
{
    $unit = trim((string)($item['item_unit'] ?? ''));

    if ($unit === '') {
        $unit = trim((string)($item['product_unit'] ?? ''));
    }

    return $unit !== '' ? $unit : 'pcs';
}

function money($v)
{
    return number_format((float) $v, 2, '.', ',');
}

function money_rs($v)
{
    return 'Rs. ' . money($v);
}

function format_quantity($v)
{
    $v = (float) $v;
    if (floor($v) == $v)
        return number_format($v, 0, '.', '');
    return number_format($v, 2, '.', '');
}

function pdf_text_simple($s)
{
    $s = (string) $s;
    // Replace problematic characters
    $s = str_replace(["₹", "â‚¹", "€", "£", "¥"], ["Rs.", "Rs.", "EUR", "GBP", "JPY"], $s);

    // Remove any non-ASCII characters
    $s = preg_replace('/[^\x00-\x7F]/', '', $s);

    return $s;
}

function number_to_words($number)
{
    $words = array(
        '0' => '',
        '1' => 'One',
        '2' => 'Two',
        '3' => 'Three',
        '4' => 'Four',
        '5' => 'Five',
        '6' => 'Six',
        '7' => 'Seven',
        '8' => 'Eight',
        '9' => 'Nine',
        '10' => 'Ten',
        '11' => 'Eleven',
        '12' => 'Twelve',
        '13' => 'Thirteen',
        '14' => 'Fourteen',
        '15' => 'Fifteen',
        '16' => 'Sixteen',
        '17' => 'Seventeen',
        '18' => 'Eighteen',
        '19' => 'Nineteen',
        '20' => 'Twenty',
        '30' => 'Thirty',
        '40' => 'Forty',
        '50' => 'Fifty',
        '60' => 'Sixty',
        '70' => 'Seventy',
        '80' => 'Eighty',
        '90' => 'Ninety'
    );

    $amount = round($number, 2);
    $rupees = floor($amount);
    $paise = round(($amount - $rupees) * 100);

    if ($rupees == 0) {
        $rupees_text = 'Zero';
    } else {
        $rupees_text = convert_number_to_words($rupees, $words);
    }

    $result = ucfirst($rupees_text) . ' Rupees';
    if ($paise > 0) {
        $paise_text = convert_number_to_words($paise, $words);
        $result .= ' and ' . ucfirst($paise_text) . ' Paise';
    }
    $result .= ' Only';

    return $result;
}

function convert_number_to_words($num, $words)
{
    if ($num < 21) {
        return $words[$num];
    } elseif ($num < 100) {
        $tens = floor($num / 10) * 10;
        $units = $num % 10;
        return $words[$tens] . ($units ? ' ' . $words[$units] : '');
    } elseif ($num < 1000) {
        $hundreds = floor($num / 100);
        $remainder = $num % 100;
        return $words[$hundreds] . ' Hundred' . ($remainder ? ' and ' . convert_number_to_words($remainder, $words) : '');
    } elseif ($num < 100000) {
        $thousands = floor($num / 1000);
        $remainder = $num % 1000;
        return convert_number_to_words($thousands, $words) . ' Thousand' . ($remainder ? ' ' . convert_number_to_words($remainder, $words) : '');
    } elseif ($num < 10000000) {
        $lakhs = floor($num / 100000);
        $remainder = $num % 100000;
        return convert_number_to_words($lakhs, $words) . ' Lakh' . ($remainder ? ' ' . convert_number_to_words($remainder, $words) : '');
    } else {
        $crores = floor($num / 10000000);
        $remainder = $num % 10000000;
        return convert_number_to_words($crores, $words) . ' Crore' . ($remainder ? ' ' . convert_number_to_words($remainder, $words) : '');
    }
}

// ========== SELECTED INVOICE DESIGN PRINT ==========
/*
    This section makes print work exactly by selected invoice design.

    Flow:
    invoice_designs.php saves selected design per business in:
        business_selected_invoice_design.business_id
        business_selected_invoice_design.design_id

    invoice_print.php:
        current session business_id
        -> selected design row
        -> new_invoice_designs.design_file
        -> require invoice_designs/designX.php
*/

$selectedDesign = null;
$designFile = '';
$designName = '';

try {
    $designStmt = $pdo->prepare("
        SELECT d.id, d.design_name, d.design_code, d.design_file
        FROM business_selected_invoice_design bsd
        INNER JOIN new_invoice_designs d ON d.id = bsd.design_id
        WHERE bsd.business_id = ?
          AND d.is_active = 1
        LIMIT 1
    ");
    $designStmt->execute([$business_id]);
    $selectedDesign = $designStmt->fetch(PDO::FETCH_ASSOC);

    if (!empty($selectedDesign['design_file'])) {
        $designFile = basename((string)$selectedDesign['design_file']);
        $designName = (string)($selectedDesign['design_name'] ?? '');
    }
} catch (Throwable $e) {
    die('Invoice design selection table error: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
}

if ($designFile === '') {
    // No selected design for this business. Use business default fallback.
    $designFile = ((int)$business_id === 28) ? 'design2.php' : 'design1.php';
}

// Security: only allow design1.php, design2.php, design3.php etc.
if (!preg_match('/^design[0-9]+\.php$/', $designFile)) {
    die('Invalid selected invoice design file.');
}

$designPath = __DIR__ . '/invoice_designs/' . $designFile;

if (!is_file($designPath)) {
    die('Selected invoice design file not found: invoice_designs/' . htmlspecialchars($designFile, ENT_QUOTES, 'UTF-8'));
}

/*
    Extra variable aliases for design files.
    These make design1.php/design2.php/design3.php compatible with this print page.
*/
$invoice_settings = $settings;
$invoiceItems = $items;
$invoice_items = $items;
$banks = $bank_accounts;
$accounts = $bank_accounts;
$footer_brand_logos = $brand_logos;
$selected_design = $selectedDesign;
$selected_design_file = $designFile;

$company_slogan = $settings['invoice_slogan'] ?? "Quality is not classy...it's priceless";
$signature_logo_path = $settings['signature_logo_path'] ?? '';
$show_signature_logo = !empty($settings['show_signature_logo']);

if (!defined('INVOICE_DESIGN_LOADED')) {
    define('INVOICE_DESIGN_LOADED', true);
}

// Clean any accidental whitespace before PDF output.
while (ob_get_level() > 0) {
    @ob_end_clean();
}

require $designPath;

// Most invoice_designs/designX.php files only draw the PDF layout.
// They do not call Output(), so invoice_print.php must output the PDF here.
if (isset($pdf) && $pdf instanceof FPDF) {
    $safeInvoiceNo = preg_replace('/[^A-Za-z0-9_\-]/', '_', (string)($invoice['invoice_number'] ?? 'invoice'));
    $pdf->Output('I', 'invoice_' . $safeInvoiceNo . '.pdf');
    exit;
}

die('Selected invoice design did not create a PDF object: ' . htmlspecialchars($designFile, ENT_QUOTES, 'UTF-8'));
