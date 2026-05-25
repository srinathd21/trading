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

// ========== CONDITIONAL TEMPLATE SELECTION ==========
// Check if business_id is 28 (use new template), otherwise use old template

if ($business_id == 28) {
    // ========== NEW TEMPLATE DESIGN (for business_id = 28) ==========
    
    // Set default logo path for business_id 28
    $defaultLogoPath = __DIR__ . '/assets/logo.png';
    $logoPath = (!empty($company_logo) && file_exists($company_logo)) ? $company_logo : $defaultLogoPath;
    
    // ========== PDF Class - New Template Design ==========
    class InvoicePDF extends FPDF
    {
        public $company = [];
        public $invoice = [];
        public $customer = [];
        public $shipping = [];
        public $totals = [];
        public $taxable_by_rate = [];
        public $account = [];
        public $accounts = [];
        public $brandLogos = [];
        public $items = [];
        public $transport = '';
        public $waybill_no = '';
        public $logoPath = '';
        public $headerEndY = 0;

        function Header()
        {
            $this->SetDrawColor(0, 0, 0);
            $this->SetLineWidth(0.2);
            
            // Outer border
            $this->Rect(5, 5, 200, 287);
            
            // Title - TAX INVOICE
            $this->SetFont('Arial', 'B', 10);
            $this->SetXY(5, 6);
            $title = $this->invoice['is_tax_invoice'] ? 'TAX INVOICE' : 'INVOICE';
            $this->Cell(200, 4, $title, 0, 1, 'C');
            
            // Logo
            if (file_exists($this->logoPath)) {
                $this->Image($this->logoPath, 10, 13, 38, 18);
            }
            
            // Company Name (Bold, larger font, custom color)
            $this->SetFont('Arial', 'B', 22);
            $this->SetTextColor(32, 61, 135);
            $this->SetXY(52, 12);
            $companyName = pdf_text_simple($this->company['name']);
$this->Cell(115, 8, $companyName, 0, 1, 'C');

/* Company Slogan */
$slogan = pdf_text_simple($this->company['slogan'] ?? "Quality is not classy...it's priceless");
$this->SetFont('Arial', 'I', 10);
$this->SetTextColor(80, 80, 80);
$this->SetX(52);
$this->Cell(115, 4, $slogan, 0, 1, 'C');
            
            // Reset text color
            $this->SetTextColor(0, 0, 0);
            $this->SetFont('Arial', '', 9);
            
            // Company Address
            $this->SetXY(48, 25);
            $address = pdf_text_simple($this->company['address']);
            $this->MultiCell(120, 3.5, $address, 0, 'C');
            
            // Contact and Email
            $this->SetXY(48, 30);
            $contactText = 'Contact: ' . pdf_text_simple($this->company['phone']);
            if (!empty($this->company['email'])) {
                $contactText .= ', Email: ' . pdf_text_simple($this->company['email']);
            }
            $this->Cell(120, 3.5, $contactText, 0, 1, 'C');
            
            // GSTIN
            $this->SetFont('Arial', '', 9);
            $this->SetXY(48, 35);
            $this->Cell(120, 4, 'GSTIN: ' . pdf_text_simple($this->company['gstin']), 0, 1, 'C');
            
            // Divider line
            $this->Line(5, 43, 205, 43);
        }
        
        function Footer()
        {
            // Only add page number on pages beyond first
            if ($this->PageNo() > 1) {
                $this->SetY(-12);
                $this->SetFont('Arial', '', 9);
                $this->Cell(200, 5, 'Page ' . $this->PageNo() . ' / {nb}', 0, 0, 'C');
            }
        }
        
        function labelValue($x, $y, $label, $value)
        {
            $this->SetFont('Arial', '', 9);
            $this->SetXY($x, $y);
            $this->Cell(42, 4.5, $label, 0, 0, 'L');
            $this->Cell(4, 4.5, ':', 0, 0, 'C');
            $this->Cell(55, 4.5, pdf_text_simple($value), 0, 1, 'L');
        }
        
        function TopSection()
        {
            // Draw vertical divider line
            $this->Line(105, 43, 105, 77);
            $this->Line(5, 77, 205, 77);
            
            $leftY = 45;
            
            // Left column
            $this->labelValue(8, $leftY, 'Invoice No', $this->invoice['number']);
            $this->labelValue(8, $leftY + 5, 'Transport Name & NO', $this->transport);
            $this->labelValue(8, $leftY + 10, 'Customer Name', $this->customer['name']);
            $this->labelValue(8, $leftY + 15, "Buyer's Order No", $this->invoice['buyer_order_no'] ?? 'By Phone');
            $this->labelValue(8, $leftY + 20, 'Terms Of Payment', $this->invoice['payment_terms'] ?? 'Immediate');
           
            
            // Right column
            $this->labelValue(108, $leftY, 'Date', $this->invoice['date']);
            $this->labelValue(108, $leftY + 5, 'Way Bill No', $this->waybill_no);
            $this->labelValue(108, $leftY + 10, 'Customer NO', $this->customer['phone']);
            $this->labelValue(108, $leftY + 15, 'Date Of Supply', $this->invoice['date']);
            $this->labelValue(108, $leftY + 20, 'Due Date', $this->invoice['due_date'] ?? $this->invoice['date']);
            $this->labelValue(108, $leftY + 25, 'State / Code', $this->invoice['place_of_supply']);
        }
        
        function PartyBoxes()
        {
            // Header row with background
            $this->SetFillColor(190, 205, 230);
            $this->SetFont('Arial', 'B', 8);
            
            $this->SetXY(5, 77);
            $this->Cell(100, 5, 'Details Of Receiver (Billed to)', 1, 0, 'C', true);
            $this->Cell(100, 5, 'Details Of Consignee (Shipped to)', 1, 1, 'C', true);
            
            $this->SetFont('Arial', '', 9);
            
            // Left box content - Billed to
            $this->SetXY(7, 84);
            $billToText = pdf_text_simple($this->customer['name']) . "\n" . pdf_text_simple($this->customer['address']);
            $this->MultiCell(96, 4, $billToText, 0);
            
            // Right box content - Shipped to
            $hasShipping = !empty($this->shipping['name']) || !empty($this->shipping['address']);
            $shipName = $hasShipping ? $this->shipping['name'] : $this->customer['name'];
            $shipAddr = $hasShipping ? $this->shipping['address'] : $this->customer['address'];
            
            $this->SetXY(107, 84);
            $shipToText = pdf_text_simple($shipName) . "\n" . pdf_text_simple($shipAddr);
            $this->MultiCell(96, 4, $shipToText, 0);
            
            // GSTIN and State Code row
            $this->SetXY(7, 101);
            $billGstin = !empty($this->customer['gstin']) ? $this->customer['gstin'] : 'Not Registered';
            $this->Cell(70, 4, 'GSTIN NO: ' . pdf_text_simple($billGstin), 0, 0);
            $this->Cell(26, 4, 'State Code : 33', 0, 0);
            
            $shipGstin = ($hasShipping && !empty($this->shipping['gstin'])) ? $this->shipping['gstin'] : $billGstin;
            $this->SetXY(107, 101);
            $this->Cell(70, 4, 'GSTIN NO: ' . pdf_text_simple($shipGstin), 0, 0);
            $this->Cell(26, 4, 'State Code : 33', 0, 1);
            
            // Bottom borders for the party boxes
            $this->Line(105, 77, 105, 106);
            $this->Line(5, 106, 205, 106);
        }
        
        function ItemsHeader()
        {
            $this->SetFillColor(190, 205, 230);
            $this->SetFont('Arial', 'B', 8);
            
            $this->SetX(5);
            $this->Cell(12, 5, 'S.No', 1, 0, 'C', true);
            $this->Cell(70, 5, 'Description Of Goods', 1, 0, 'C', true);
            $this->Cell(22, 5, 'HSN/SAC', 1, 0, 'C', true);
            $this->Cell(18, 5, 'Qty', 1, 0, 'C', true);
            $this->Cell(24, 5, 'Rate', 1, 0, 'C', true);
            $this->Cell(16, 5, 'CGST', 1, 0, 'C', true);
            $this->Cell(16, 5, 'SGST', 1, 0, 'C', true);
            $this->Cell(22, 5, 'Amount', 1, 1, 'C', true);
        }
        
        function itemRow($row)
        {
            $this->SetFont('Arial', '', 9);
            $this->SetX(5);

            // Border only LEFT + RIGHT.
            // This removes bottom border from every item row.
            $border = 'LR';

            $this->Cell(12, 4.5, $row[0], $border, 0, 'C');
            $this->Cell(70, 4.5, pdf_text_simple($row[1]), $border, 0, 'L');
            $this->Cell(22, 4.5, $row[2], $border, 0, 'C');
            $this->Cell(18, 4.5, $row[3], $border, 0, 'C');
            $this->Cell(24, 4.5, $row[4], $border, 0, 'C');
            $this->Cell(16, 4.5, $row[5], $border, 0, 'C');
            $this->Cell(16, 4.5, $row[6], $border, 0, 'C');
            $this->Cell(22, 4.5, $row[7], $border, 1, 'C');
        }

        function TotalsBoxHeight()
        {
            $rows = 0;

            // Total Amount row below item blank area
            $rows++;

            // In Words row
            $rows++;

            // GST summary header
            $rows++;

            // GST rate rows. Keep at least one blank row when GST data is empty.
            $rows += !empty($this->taxable_by_rate) ? count($this->taxable_by_rate) : 1;

            // CGST and SGST rows
            $rows += 2;

            // Extra charges rows
            if ((float)($this->totals['shipping_charges'] ?? 0) > 0) {
                $rows++;
            }
            if ((float)($this->totals['transport_charge'] ?? 0) > 0) {
                $rows++;
            }

            // IGST row when applicable
            $totalIgst = 0;
            if (!empty($this->taxable_by_rate)) {
                foreach ($this->taxable_by_rate as $rate => $data) {
                    $totalIgst += (float)($data['igst'] ?? 0);
                }
            }
            if ($totalIgst > 0) {
                $rows++;
            }

            // Final payable total row
            $rows++;

            return $rows * 5;
        }

        function DrawBlankItemArea($endY)
        {
            $startY = $this->GetY();
            if ($endY <= $startY) {
                return;
            }

            // Draw only vertical column lines in the blank item area.
            // This keeps the empty space clean while preserving the table columns.
            $x = 5;
            $widths = [12, 70, 22, 18, 24, 16, 16, 22];

            $this->SetDrawColor(0, 0, 0);
            $this->SetLineWidth(0.2);

            $this->Line($x, $startY, $x, $endY);
            foreach ($widths as $w) {
                $x += $w;
                $this->Line($x, $startY, $x, $endY);
            }

            // Bottom line exactly before Total Amount row.
            $this->Line(5, $endY, 205, $endY);
            $this->SetY($endY);
        }

        function TotalsBox($totalQty = 0, $totalAmount = 0)
        {
            $this->SetFont('Arial', '', 9);

            // Total row must be at bottom, not immediately after the last item.
            $this->SetFillColor(190, 205, 230);
            $this->SetFont('Arial', 'B', 8);
            $this->SetX(5);
            $this->Cell(104, 5, 'Total Amount', 1, 0, 'R', true);
            $this->Cell(18, 5, format_quantity($totalQty) . ' nos', 1, 0, 'C');
            $this->Cell(56, 5, '', 1, 0);
            $this->Cell(22, 5, money($totalAmount), 1, 1, 'C');

            $this->SetFont('Arial', '', 9);

            // In Words row
            $this->SetX(5);
            $this->SetFillColor(190, 205, 230);
            $this->Cell(35, 5, 'In Words', 1, 0, 'C', true);
            $amountInWords = number_to_words($this->totals['grand_total']);
            $this->Cell(165, 5, pdf_text_simple($amountInWords), 1, 1);

            // GST Summary Header
            $this->SetX(5);
            $this->Cell(35, 5, 'GST Rate', 1, 0, 'C', true);
            $this->Cell(38, 5, 'Taxable Values', 1, 0, 'C', true);
            $this->Cell(38, 5, 'CGST Tax', 1, 0, 'C', true);
            $this->Cell(38, 5, 'SGST Tax', 1, 0, 'C', true);
            $this->Cell(33, 5, 'Sub Total', 1, 0, 'C', true);
            $this->Cell(18, 5, money($this->totals['subtotal']), 1, 1, 'C', true);

            $totalCgst = 0;
            $totalSgst = 0;
            $totalIgst = 0;

            // Display rows for each GST rate. If no GST data, leave one blank row.
            if (!empty($this->taxable_by_rate)) {
                foreach ($this->taxable_by_rate as $rate => $data) {
                    $this->SetX(5);
                    $this->Cell(35, 5, number_format($rate, 1) . '%', 1, 0, 'C');
                    $this->Cell(38, 5, money($data['taxable']), 1, 0, 'R');
                    $this->Cell(38, 5, money($data['cgst']), 1, 0, 'R');
                    $this->Cell(38, 5, money($data['sgst']), 1, 0, 'R');
                    $this->Cell(33, 5, '', 1, 0);
                    $this->Cell(18, 5, '', 1, 1);

                    $totalCgst += (float)($data['cgst'] ?? 0);
                    $totalSgst += (float)($data['sgst'] ?? 0);
                    $totalIgst += (float)($data['igst'] ?? 0);
                }
            } else {
                $this->SetX(5);
                $this->Cell(35, 5, '', 1, 0);
                $this->Cell(38, 5, '', 1, 0);
                $this->Cell(38, 5, '', 1, 0);
                $this->Cell(38, 5, '', 1, 0);
                $this->Cell(33, 5, '', 1, 0);
                $this->Cell(18, 5, '', 1, 1);
            }

            // CGST row
            $this->SetX(5);
            $this->Cell(35, 5, '', 1, 0);
            $this->Cell(38, 5, '', 1, 0);
            $this->Cell(38, 5, '', 1, 0);
            $this->Cell(38, 5, '', 1, 0);
            $this->SetFont('Arial', 'B', 8);
            $this->Cell(33, 5, 'Add CGST', 1, 0, 'C');
            $this->SetFont('Arial', '', 9);
            $this->Cell(18, 5, money($totalCgst), 1, 1, 'R');

            // SGST row
            $this->SetX(5);
            $this->Cell(35, 5, '', 1, 0);
            $this->Cell(38, 5, '', 1, 0);
            $this->Cell(38, 5, '', 1, 0);
            $this->Cell(38, 5, '', 1, 0);
            $this->SetFont('Arial', 'B', 8);
            $this->Cell(33, 5, 'Add SGST', 1, 0, 'C');
            $this->SetFont('Arial', '', 9);
            $this->Cell(18, 5, money($totalSgst), 1, 1, 'R');

            // Extra charges rows
            $shippingCharges = (float)($this->totals['shipping_charges'] ?? 0);
            $transportCharge = (float)($this->totals['transport_charge'] ?? 0);

            if ($shippingCharges > 0) {
                $this->SetX(5);
                $this->Cell(35, 5, '', 1, 0);
                $this->Cell(38, 5, '', 1, 0);
                $this->Cell(38, 5, '', 1, 0);
                $this->Cell(38, 5, '', 1, 0);
                $this->SetFont('Arial', 'B', 8);
                $this->Cell(33, 5, 'Shipping', 1, 0, 'C');
                $this->SetFont('Arial', '', 9);
                $this->Cell(18, 5, money($shippingCharges), 1, 1, 'R');
            }

            if ($transportCharge > 0) {
                $this->SetX(5);
                $this->Cell(35, 5, '', 1, 0);
                $this->Cell(38, 5, '', 1, 0);
                $this->Cell(38, 5, '', 1, 0);
                $this->Cell(38, 5, '', 1, 0);
                $this->SetFont('Arial', 'B', 8);
                $this->Cell(33, 5, 'Transport', 1, 0, 'C');
                $this->SetFont('Arial', '', 9);
                $this->Cell(18, 5, money($transportCharge), 1, 1, 'R');
            }

            if ($totalIgst > 0) {
                $this->SetX(154);
                $this->SetFont('Arial', 'B', 8);
                $this->Cell(33, 5, 'Add IGST', 1, 0, 'C');
                $this->SetFont('Arial', '', 9);
                $this->Cell(18, 5, money($totalIgst), 1, 1, 'R');
            }

            // Final payable amount row
            $this->SetX(154);
            $this->SetFillColor(190, 205, 230);
            $this->SetFont('Arial', 'B', 8);
            $this->Cell(33, 5, 'Total Amount', 1, 0, 'C', true);
            $this->Cell(18, 5, money($this->totals['grand_total']), 1, 1, 'R', true);
        }

        function BankDetailsHeight()
        {
            $accounts = !empty($this->accounts) ? $this->accounts : [];
            if (empty($accounts) && !empty($this->account)) {
                $accounts = [$this->account];
            }

            if (empty($accounts)) {
                return 29;
            }

            $cols = count($accounts) > 1 ? 2 : 1;
            $rows = (int)ceil(count($accounts) / $cols);
            $rowH = 22;

            return 5 + ($rows * $rowH);
        }

        function BrandLogosHeight()
        {
            if (empty($this->brandLogos)) {
                return 0;
            }

            $maxH = 0;
            foreach ($this->brandLogos as $logo) {
                $h = (float)($logo['height_mm'] ?? 8);
                if ($h > $maxH) {
                    $maxH = $h;
                }
            }

            if ($maxH <= 0) {
                $maxH = 8;
            }

            // Keep footer strip safe inside A4 border.
            if ($maxH > 14) {
                $maxH = 14;
            }

            return $maxH + 2;
        }

        function FooterBoxTopY()
        {
            // Outer border ends at Y=292. Footer signature area remains 27mm.
            return 292 - $this->BrandLogosHeight() - 27;
        }

        function BankTopY()
        {
            return $this->FooterBoxTopY() - $this->BankDetailsHeight();
        }

        function DrawBrandLogosFooter($topY)
        {
            if (empty($this->brandLogos)) {
                return;
            }

            $stripH = $this->BrandLogosHeight();
            $this->SetDrawColor(0, 0, 0);
            $this->Rect(5, $topY, 200, $stripH);

            $logos = [];
            $totalW = 0;
            $gap = 2;

            foreach ($this->brandLogos as $logo) {
                $path = $logo['logo_path'] ?? '';
                if (empty($path) || !file_exists($path)) {
                    continue;
                }

                $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
                if (!in_array($ext, ['jpg', 'jpeg', 'png'], true)) {
                    continue;
                }

                $w = (float)($logo['width_mm'] ?? 24);
                $h = (float)($logo['height_mm'] ?? 8);
                if ($w <= 0) $w = 24;
                if ($h <= 0) $h = 8;
                if ($w > 80) $w = 80;
                if ($h > ($stripH - 2)) $h = $stripH - 2;

                $logos[] = ['path' => $path, 'w' => $w, 'h' => $h];
                $totalW += $w;
            }

            if (empty($logos)) {
                return;
            }

            $totalW += $gap * (count($logos) - 1);
            $availableW = 196;

            // If all manual widths do not fit, scale them proportionally.
            $scale = $totalW > $availableW ? ($availableW / $totalW) : 1;
            $drawTotalW = 0;
            foreach ($logos as $i => $logo) {
                $logos[$i]['dw'] = $logo['w'] * $scale;
                $logos[$i]['dh'] = $logo['h'] * $scale;
                if ($logos[$i]['dh'] > ($stripH - 2)) {
                    $ratio = ($stripH - 2) / $logos[$i]['dh'];
                    $logos[$i]['dh'] *= $ratio;
                    $logos[$i]['dw'] *= $ratio;
                }
                $drawTotalW += $logos[$i]['dw'];
            }
            $drawTotalW += $gap * (count($logos) - 1);

            $x = 5 + ((200 - $drawTotalW) / 2);
            foreach ($logos as $logo) {
                $y = $topY + (($stripH - $logo['dh']) / 2);
                $this->Image($logo['path'], $x, $y, $logo['dw'], $logo['dh']);
                $x += $logo['dw'] + $gap;
            }
        }

        function drawSingleBank($x, $y, $w, $h, $account, $index)
        {
            $this->Rect($x, $y, $w, $h);

            $accountName   = !empty($account['account_name']) ? $account['account_name'] : $this->company['name'];
            $bankName      = $account['bank_name'] ?? '';
            $accountNumber = $account['account_number'] ?? '';
            $ifsc          = $account['ifsc'] ?? '';
            $branch        = $account['branch'] ?? '';
            $upi           = $account['upi'] ?? '';

            $labelW = 16;
            $colonW = 3;
            $lineH = 3.25;
            $textW = $w - $labelW - $colonW - 6;

            $this->SetFont('Arial', 'B', 7.5);
            $this->SetXY($x + 2, $y + 1.2);
            $this->Cell($w - 4, 3.2, 'Bank Account ' . $index, 0, 1, 'L');

            $this->SetFont('Arial', '', 7.2);
            $lineY = $y + 4.7;

            $rows = [
                ['Name', $accountName],
                ['Bank', $bankName],
                ['AC.NO', $accountNumber],
                ['IFSC', $ifsc],
                ['Branch', $branch],
            ];

            if (!empty($upi)) {
                $rows[] = ['UPI', $upi];
            }

            foreach ($rows as $r) {
                if ($lineY + $lineH > $y + $h - 0.8) {
                    break;
                }
                $this->SetXY($x + 2, $lineY);
                $this->Cell($labelW, $lineH, pdf_text_simple($r[0]), 0, 0, 'L');
                $this->Cell($colonW, $lineH, ':', 0, 0, 'C');
                $this->Cell($textW, $lineH, pdf_text_simple($r[1]), 0, 1, 'L');
                $lineY += $lineH;
            }
        }

        function BankDetailsSection()
        {
            $accounts = !empty($this->accounts) ? $this->accounts : [];
            if (empty($accounts) && !empty($this->account)) {
                $accounts = [$this->account];
            }

            $bankTopY = $this->BankTopY();

            // If content already reached bank area, move bank/footer to next page safely.
            if ($this->GetY() > $bankTopY) {
                $this->AddPage();
                $bankTopY = $this->BankTopY();
            }

            $this->SetXY(5, $bankTopY);

            // Bank Details Header
            $this->SetFillColor(190, 205, 230);
            $this->SetFont('Arial', 'B', 8);
            $this->Cell(200, 5, 'Bank Details', 1, 1, 'C', true);

            $startY = $this->GetY();
            $totalH = $this->BankDetailsHeight() - 5;

            if (empty($accounts)) {
                $this->Rect(5, $startY, 200, $totalH);
                $this->SetY($startY + $totalH);
                return;
            }

            $cols = count($accounts) > 1 ? 2 : 1;
            $cellW = 200 / $cols;
            $rowH = 22;

            foreach ($accounts as $idx => $account) {
                $col = $idx % $cols;
                $row = (int)floor($idx / $cols);
                $x = 5 + ($col * $cellW);
                $y = $startY + ($row * $rowH);
                $this->drawSingleBank($x, $y, $cellW, $rowH, $account, $idx + 1);
            }

            $this->SetY($bankTopY + $this->BankDetailsHeight());
        }
        function FooterBox()
        {
            $footerTop = $this->FooterBoxTopY();
            $logoTop = 292 - $this->BrandLogosHeight();
            $footerH = $logoTop - $footerTop;
            if ($footerH <= 0) {
                $footerH = 27;
                $footerTop = 255;
                $logoTop = 292;
            }

            $this->SetXY(5, $footerTop);
            
            $this->SetFont('Arial', '', 9);
            $this->Cell(48, $footerH, 'Receivers Signature', 1, 0, 'L');
            
            $this->SetXY(53, $footerTop);
            $this->Cell(88, $footerH, '', 1, 0);
            
            // Terms and Conditions
            $terms = trim((string) ($this->company['invoice_terms'] ?? ''));
            if ($terms === '') {
                $terms = "Terms/Declaration\n1) Goods Once Delivered Will Not Be Taken Back,\n2) Interest @ 24% Will Be Charged, If Not Paid Fully\nWithin Due Date.\n3) Cheque Accepted Subject To Realization.";
            }
            
            $this->SetXY(55, $footerTop + 2);
            $this->SetFont('Arial', '', 6.5);
            $this->MultiCell(83, 3.2, pdf_text_simple($terms));
            
            $this->SetXY(141, $footerTop);
            $this->Cell(64, $footerH, '', 1, 0);
            
            $this->SetXY(141, $footerTop + 2);
            $this->SetFont('Arial', '', 9);
            $this->Cell(64, 5, 'For ' . pdf_text_simple($this->company['name']), 0, 1, 'C');
            
            // Logo in footer (if exists)
            if (file_exists($this->logoPath)) {
                $this->Image($this->logoPath, 163, $footerTop + 11, 25, min(14, max(8, $footerH - 10)));
            }
            
            $this->SetXY(141, $logoTop - 5);
            $this->SetFont('Arial', '', 9);
            $this->Cell(64, 4, 'Seal & Authorised Signatory', 0, 1, 'C');

            $this->DrawBrandLogosFooter($logoTop);
        }
    }
    
    // ========== Create PDF with NEW template ==========
    $pdf = new InvoicePDF('P', 'mm', 'A4');
    $pdf->AliasNbPages();
    $pdf->SetAutoPageBreak(false);
    
    // Set logo path
    $pdf->logoPath = $logoPath;
    
    // Set company info
    $pdf->company = [
        'name' => $company_name,
        'address' => $company_address,
        'gstin' => $company_gstin,
        'phone' => $company_phone,
        'email' => $settings['company_email'] ?? '',
        'website' => $settings['company_website'] ?? '',
        'logo' => $company_logo,
        'invoice_terms' => $settings['invoice_terms'] ?? ''
    ];
    
    // Set invoice info
    $pdf->invoice = [
        'number' => $invoice['invoice_number'],
        'date' => $invoice_date,
        'payment' => $payment_method,
        'status' => $payment_status,
        'printed_on' => date('d-m-Y H:i:s'),
        'place_of_supply' => $place_of_supply,
        'is_tax_invoice' => $is_tax_invoice,
        'irn_no' => $invoice['irn_no'] ?? '',
        'ack_no' => $invoice['ack_no'] ?? '',
        'ack_date' => $invoice['ack_date'] ?? '',
        'destination' => $shipping_full_address ? substr($shipping_full_address, 0, 50) : 'Chennai',
        'payment_terms' => 'Immediate',
        'due_date' => $invoice_date,
        'buyer_order_no' => $invoice['buyer_order_no'] ?? 'By Phone | ' . $invoice_date
    ];
    
    // Set customer info
    $pdf->customer = [
        'name' => $customer_name,
        'phone' => $customer_phone,
        'gstin' => $customer_gstin,
        'address' => $customer_full_address
    ];
    
    // Set shipping info
    $pdf->shipping = [
        'name' => $shipping_name,
        'contact' => $shipping_contact,
        'gstin' => $shipping_gstin,
        'address' => $shipping_full_address,
        'district' => $shipping_district,
        'state' => $shipping_state,
        'pincode' => $shipping_pincode,
        'vehicle_number' => $shipping_vehicle_number,
        'transport_type' => $shipping_transport_type,
        'shipping_charges' => $shipping_charges,
        'transport_charge' => $transport_charge,
        'total_extra' => $total_extra_charges
    ];
    
    $pdf->totals = [
        'subtotal' => $subtotal,
        'discount' => $total_discount + $overall_discount,
        'overall_discount' => $overall_discount,
        'taxable' => $total_taxable,
        'cgst' => $total_cgst,
        'sgst' => $total_sgst,
        'igst' => $total_igst,
        'shipping_charges' => $shipping_charges,
        'transport_charge' => $transport_charge,
        'total_extra_charges' => $total_extra_charges,
        'grand_total' => $grand_total
    ];
    
    // Set taxable by rate for totals box
    $pdf->taxable_by_rate = $taxable_by_rate;
    
    // Set all active bank account details for this branch/business
    $pdf->accounts = [];
    if (!empty($bank_accounts)) {
        foreach ($bank_accounts as $bank) {
            $pdf->accounts[] = [
                'account_name' => $bank['account_holder_name'] ?? '',
                'bank_name' => $bank['bank_name'] ?? '',
                'account_number' => $bank['account_number'] ?? '',
                'ifsc' => $bank['ifsc_code'] ?? '',
                'branch' => $bank['branch_name'] ?? '',
                'upi' => $bank['upi_id'] ?? ''
            ];
        }
        $pdf->account = $pdf->accounts[0];
    } else {
        $pdf->account = [];
        $pdf->accounts = [];
    }
    
    // Set items
    $pdf->brandLogos = $brand_logos;

    $pdf->brandLogos = $brand_logos;

    $pdf->items = $items;
    
    // Set transport and waybill
    $pdf->transport = $transport;
    $pdf->waybill_no = $waybill_no;
    
    // ========== Generate PDF ==========
    $pdf->AddPage();
    $pdf->TopSection();
    $pdf->PartyBoxes();
    
    // Items table
    $pdf->SetY(106);
    $pdf->ItemsHeader();
    
    $itemCount = 0;
    foreach ($items as $item) {
        $itemCount++;
        
        // Check if need new page
        if ($itemCount == 19) {
            $pdf->AddPage();
            $pdf->SetY(46);
            $pdf->ItemsHeader();
        }
        
        if ($itemCount > 18 && $pdf->GetY() > 240) {
            $pdf->AddPage();
            $pdf->SetY(46);
            $pdf->ItemsHeader();
        }
        
        $unit_price = $item['unit_price'] ?? 0;
        $quantity = $item['quantity'] ?? 0;
        $discount_amount = $item['discount_amount'] ?? 0;
        
        $cgst_rate = $item['cgst_rate'] ?? 0;
        $sgst_rate = $item['sgst_rate'] ?? 0;
        $igst_rate = $item['igst_rate'] ?? 0;
        
        // Calculate rate without GST
        $total_gst_rate = $cgst_rate + $sgst_rate + $igst_rate;
        if ($total_gst_rate > 0) {
            $gst_multiplier = 1 + ($total_gst_rate / 100);
            $rate_without_gst = $unit_price / $gst_multiplier;
        } else {
            $rate_without_gst = $unit_price;
        }
        
        $line_total = $unit_price * $quantity;
        $net_total = $line_total - $discount_amount;
        
        $displayName = item_display_name($item);
        $displayCode = item_display_code($item);
        $productDesc = (!empty($displayCode) ? $displayCode . ' ' : '') . $displayName;
        $qtyDisplay = format_quantity($quantity) . ' ' . item_display_unit($item);
        
        $row = [
            $itemCount,
            $productDesc,
            item_display_hsn($item),
            $qtyDisplay,
            money($rate_without_gst),  // Rate without GST
            $cgst_rate > 0 ? number_format($cgst_rate, 1) . '%' : '',
            $sgst_rate > 0 ? number_format($sgst_rate, 1) . '%' : '',
            money($net_total)
        ];
        
        $pdf->itemRow($row);
    }
    
    // Total row
    $totalQty = 0;
    $totalAmount = 0;
    foreach ($items as $item) {
        $totalQty += $item['quantity'] ?? 0;
        $unit_price = $item['unit_price'] ?? 0;
        $quantity = $item['quantity'] ?? 0;
        $discount_amount = $item['discount_amount'] ?? 0;
        $totalAmount += ($unit_price * $quantity) - $discount_amount;
    }
    
    // Move Total row + GST summary to the bottom area above Bank Details.
    // Blank item space keeps vertical column lines only.
    $summaryHeight = $pdf->TotalsBoxHeight();
    $bankTopY = $pdf->BankTopY();
    $summaryStartY = $bankTopY - $summaryHeight;

    if ($pdf->GetY() > $summaryStartY) {
        $pdf->AddPage();
        $pdf->SetY(46);
        $pdf->ItemsHeader();
    }

    $pdf->DrawBlankItemArea($summaryStartY);
    $pdf->TotalsBox($totalQty, $totalAmount);
    $pdf->BankDetailsSection();
    $pdf->FooterBox();
    
} else {
    // ========== OLD TEMPLATE DESIGN (for all other business_ids) ==========
    
    // ========== PDF Class (modified for non-GST invoices) ==========
    class InvoicePDF extends FPDF
    {
        public $company = [];
        public $invoice = [];
        public $customer = [];
        public $shipping = [];
        public $totals = [];
        public $account = [];
        public $accounts = [];
        public $brandLogos = [];
        public $col_w = [];
        public $col_headers = [];
        public $lm = 8;
        public $rm = 8;
        public $tm = 8;
        public $bm = 15;
        public $verified_by = '-';
        public $is_gst_invoice = true; // Flag for GST/non-GST invoice

        // Column width proportions - original for GST invoice
        private $col_props_gst = [0.05, 0.25, 0.08, 0.09, 0.11, 0.07, 0.10, 0.10, 0.15];
        // Column width proportions for non-GST invoice (HSN, GST%, GST Amt columns removed)
        private $col_props_non_gst = [0.05, 0.35, 0.14, 0.08, 0.16, 0.22]; // SN, Item, Rate, Qty, Disc, Total

        function Header()
        {
            $pw = $this->GetPageWidth();
            $printable = $pw - ($this->lm + $this->rm);

            $this->SetXY($this->lm, $this->tm);

            // Logo (if exists) - with error suppression and fallback
            if (!empty($this->company['logo']) && file_exists($this->company['logo'])) {
                // Suppress errors and try to load the image
                $old_error_level = error_reporting(0);
                try {
                    // Try with @ to suppress warnings
                    $image_loaded = @$this->Image($this->company['logo'], $this->lm, $this->tm, 15, 15);
                    if ($image_loaded !== false) {
                        $this->SetX($this->lm + 17);
                    } else {
                        $this->SetX($this->lm);
                    }
                } catch (Exception $e) {
                    // Silently fail - just don't show logo
                    $this->SetX($this->lm);
                    error_log("Logo error: " . $e->getMessage());
                } catch (Error $e) {
                    // Silently fail - just don't show logo
                    $this->SetX($this->lm);
                    error_log("Logo error: " . $e->getMessage());
                }
                error_reporting($old_error_level);
            } else {
                $this->SetX($this->lm);
            }

            // Title - on the same line as logo
            $title = $this->is_gst_invoice ? 'TAX INVOICE' : 'INVOICE';
            $this->SetFont('Arial', 'B', 14);
            $this->Cell(100, 7, pdf_text_simple($title), 0, 0, 'L');

            // Page number on right
            $this->SetFont('Arial', '', 9);
            $this->Cell(0, 7, pdf_text_simple('Page ' . $this->PageNo() . '/{nb}'), 0, 1, 'L');

            // Now move to next line for company info
            $this->SetX($this->lm);

            // If logo exists, we need to align text properly
            $logo_offset = (!empty($this->company['logo']) && file_exists($this->company['logo'])) ? 17 : 0;

            // Company name below logo area - moved right by 5mm
            $this->SetFont('Arial', 'B', 12);
            $this->SetX($this->lm + $logo_offset + 0.1); // Add 5mm offset
            $this->Cell(0, 6, pdf_text_simple($this->company['name']), 0, 1, 'L');

            $this->SetFont('Arial', '', 9);
            $this->SetX($this->lm + $logo_offset);

            // Wrap company address
            $address_width = 100;
            $this->MultiCell($address_width, 4.5, pdf_text_simple($this->company['address']), 0, 'L');

            $this->SetX($this->lm + $logo_offset);
            if (!empty($this->company['phone'])) {
                $this->Cell(0, 5, pdf_text_simple('Phone: ' . $this->company['phone']), 0, 1, 'L');
            }

            $company_info_height = $this->GetY();

            // Invoice info (right side) - aligned with company info
            $right_start_y = $this->tm + 1; // Start a bit lower than top

            $this->SetXY($pw - $this->rm - 80, $right_start_y);
            $this->SetFont('Arial', '', 9);
            $this->Cell(80, 5, pdf_text_simple('Invoice No : ' . $this->invoice['number']), 0, 1, 'R');
            $this->SetX($pw - $this->rm - 80);
            $this->Cell(80, 5, pdf_text_simple('Invoice Date : ' . $this->invoice['date']), 0, 1, 'R');
            $this->SetX($pw - $this->rm - 80);
            $this->Cell(80, 5, pdf_text_simple('Payment Mode : ' . $this->invoice['payment']), 0, 1, 'R');
            $this->SetX($pw - $this->rm - 80);
            $this->Cell(80, 5, pdf_text_simple('Status : ' . $this->invoice['status']), 0, 1, 'R');
            $this->SetX($pw - $this->rm - 80);
            $this->Cell(80, 5, pdf_text_simple('Printed On : ' . $this->invoice['printed_on']), 0, 1, 'R');

            $right_info_height = $this->GetY();

            // Take the maximum height between left and right columns
            $max_y = max($company_info_height, $right_info_height);
            $this->SetY($max_y + 2);

            // GSTIN and Place of Supply (only show GSTIN for GST invoices)
            $this->SetFont('Arial', '', 9);
            $this->SetX($this->lm);
            if ($this->is_gst_invoice) {
                $this->Cell(120, 5, pdf_text_simple('GSTIN : ' . $this->company['gstin']), 0, 0, 'L');
                $this->Cell(0, 5, pdf_text_simple('Place of Supply : ' . $this->invoice['place_of_supply']), 0, 1, 'R');
            } else {
                $this->Cell(0, 5, pdf_text_simple('PAN : ' . $this->company['gstin']), 0, 1, 'L');
            }

            $this->Ln(2);

            // Bill To and Ship To
            $colW = round($printable / 2);
            $this->SetFont('Arial', 'B', 10);
            $this->SetX($this->lm);
            $this->Cell($colW, 6, pdf_text_simple('Bill To'), 0, 0, 'L');
            $this->Cell($colW, 6, pdf_text_simple('Ship To'), 0, 1, 'L');

            $this->SetFont('Arial', '', 9);

            // Bill To details with proper wrapping
            $bill_y_start = $this->GetY();

            // Bill To (left side)
            $this->SetX($this->lm);
            $this->MultiCell($colW - 2, 5, pdf_text_simple('Name: ' . $this->customer['name']), 0, 'L');
            $this->SetX($this->lm);
            $this->MultiCell($colW - 2, 5, pdf_text_simple('Mobile: ' . $this->customer['phone']), 0, 'L');

            if ($this->is_gst_invoice && !empty($this->customer['gstin'])) {
                $this->SetX($this->lm);
                $this->MultiCell($colW - 2, 5, pdf_text_simple('GSTIN: ' . $this->customer['gstin']), 0, 'L');
            }

            $this->SetX($this->lm);
            $this->MultiCell($colW - 2, 5, pdf_text_simple('Address: ' . $this->customer['address']), 0, 'L');

            $bill_y_end = $this->GetY();

            // Ship To details (right side)
            $this->SetY($bill_y_start);
            $has_shipping = !empty($this->shipping['name']) || !empty($this->shipping['address']);

            if ($has_shipping) {
                if (!empty($this->shipping['name'])) {
                    $this->SetX($this->lm + $colW);
                    $this->MultiCell($colW - 2, 5, pdf_text_simple('Name: ' . $this->shipping['name']), 0, 'L');
                }
                if (!empty($this->shipping['contact'])) {
                    $this->SetX($this->lm + $colW);
                    $this->MultiCell($colW - 2, 5, pdf_text_simple('Mobile: ' . $this->shipping['contact']), 0, 'L');
                }
                if ($this->is_gst_invoice && !empty($this->shipping['gstin'])) {
                    $this->SetX($this->lm + $colW);
                    $this->MultiCell($colW - 2, 5, pdf_text_simple('GSTIN: ' . $this->shipping['gstin']), 0, 'L');
                }
                if (!empty($this->shipping['vehicle_number'])) {
                    $this->SetX($this->lm + $colW);
                    $this->MultiCell($colW - 2, 5, pdf_text_simple('Vehicle No: ' . $this->shipping['vehicle_number']), 0, 'L');
                }
                if (!empty($this->shipping['address'])) {
                    $this->SetX($this->lm + $colW);
                    $this->MultiCell($colW - 2, 5, pdf_text_simple('Address: ' . $this->shipping['address']), 0, 'L');
                }
            } else {
                // Copy bill to details to ship to
                $this->SetX($this->lm + $colW);
                $this->MultiCell($colW - 2, 5, pdf_text_simple('Name: ' . $this->customer['name']), 0, 'L');
                $this->SetX($this->lm + $colW);
                $this->MultiCell($colW - 2, 5, pdf_text_simple('Mobile: ' . $this->customer['phone']), 0, 'L');
                if ($this->is_gst_invoice && !empty($this->customer['gstin'])) {
                    $this->SetX($this->lm + $colW);
                    $this->MultiCell($colW - 2, 5, pdf_text_simple('GSTIN: ' . $this->customer['gstin']), 0, 'L');
                }
                $this->SetX($this->lm + $colW);
                $this->MultiCell($colW - 2, 5, pdf_text_simple('Address: ' . $this->customer['address']), 0, 'L');
            }

            $ship_y_end = $this->GetY();
            $this->SetY(max($bill_y_end, $ship_y_end));

            // Show transport type if available
            if (!empty($this->shipping['transport_type'])) {
                $this->Ln(2);
                $this->SetFont('Arial', '', 9);
                $this->SetX($this->lm);
                $this->Cell($colW, 5, '', 0, 0, 'L');
                $this->SetX($this->lm + $colW);
                $this->SetTextColor(0, 100, 0);
                $this->Cell($colW, 5, pdf_text_simple('Transport: ' . $this->shipping['transport_type']), 0, 1, 'R');
                $this->SetTextColor(0, 0, 0);
            }

            // Show shipping charges if applicable
            if ($this->shipping['shipping_charges'] > 0) {
                $this->Ln(2);
                $this->SetFont('Arial', '', 9);
                $this->SetX($this->lm);
                $this->Cell($colW, 5, '', 0, 0, 'L');
                $this->SetX($this->lm + $colW);
                $this->SetTextColor(0, 100, 0);
                $this->Cell($colW, 5, pdf_text_simple('Shipping Charges: Rs. ' . money($this->shipping['shipping_charges'])), 0, 1, 'R');
                $this->SetTextColor(0, 0, 0);
            }

            // Show transport charge if applicable
            if ($this->shipping['transport_charge'] > 0) {
                $this->Ln(2);
                $this->SetFont('Arial', '', 9);
                $this->SetX($this->lm);
                $this->Cell($colW, 5, '', 0, 0, 'L');
                $this->SetX($this->lm + $colW);
                $this->SetTextColor(0, 100, 0);
                $this->Cell($colW, 5, pdf_text_simple('Transport Charge: Rs. ' . money($this->shipping['transport_charge'])), 0, 1, 'R');
                $this->SetTextColor(0, 0, 0);
            }

            $this->Ln(2);

            // Table header
            $this->TableHeader();
        }

        function TableHeader()
        {
            $this->SetFont('Arial', 'B', 8);
            foreach ($this->col_headers as $i => $h) {
                $this->Cell($this->col_w[$i], 8, pdf_text_simple($h), 1, 0, 'C');
            }
            $this->Ln();
            $this->SetFont('Arial', '', 8);
        }


        function DrawBrandLogosFooter()
        {
            if (empty($this->brandLogos)) {
                return;
            }

            $stripH = 10;
            $topY = $this->GetPageHeight() - 11;
            $xLeft = $this->lm;
            $availableW = $this->GetPageWidth() - ($this->lm + $this->rm);
            $gap = 2;
            $logos = [];
            $totalW = 0;

            foreach ($this->brandLogos as $logo) {
                $path = $logo['logo_path'] ?? '';
                if (empty($path) || !file_exists($path)) continue;
                $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
                if (!in_array($ext, ['jpg', 'jpeg', 'png'], true)) continue;

                $w = (float)($logo['width_mm'] ?? 24);
                $h = (float)($logo['height_mm'] ?? 8);
                if ($w <= 0) $w = 24;
                if ($h <= 0) $h = 8;
                if ($w > 80) $w = 80;
                if ($h > ($stripH - 1)) $h = $stripH - 1;

                $logos[] = ['path' => $path, 'w' => $w, 'h' => $h];
                $totalW += $w;
            }

            if (empty($logos)) return;

            $totalW += $gap * (count($logos) - 1);
            $scale = $totalW > $availableW ? ($availableW / $totalW) : 1;
            $drawTotalW = 0;
            foreach ($logos as $i => $logo) {
                $logos[$i]['dw'] = $logo['w'] * $scale;
                $logos[$i]['dh'] = $logo['h'] * $scale;
                $drawTotalW += $logos[$i]['dw'];
            }
            $drawTotalW += $gap * (count($logos) - 1);

            $x = $xLeft + (($availableW - $drawTotalW) / 2);
            foreach ($logos as $logo) {
                $y = $topY + (($stripH - $logo['dh']) / 2);
                $this->Image($logo['path'], $x, $y, $logo['dw'], $logo['dh']);
                $x += $logo['dw'] + $gap;
            }
        }

        function Footer()
        {
            $this->DrawBrandLogosFooter();
            $this->SetY($this->GetPageHeight() - 22);
            $this->SetFont('Arial', 'I', 8);
            $this->Cell(0, 4.5, pdf_text_simple('This is a computer generated invoice.'), 0, 1, 'L');

            // Verified by (if available)
            if (!empty($this->verified_by)) {
                $this->Cell(0, 4.5, pdf_text_simple('Verified By : ' . $this->verified_by), 0, 1, 'L');
            }

            $this->Cell(0, 4.5, pdf_text_simple('Printed On - ' . $this->invoice['printed_on']), 0, 0, 'R');
        }

        function BoxText($x, $y, $w, $h, $txt, $align = 'L', $vAlign = 'T', $padX = 1.5, $padY = 1.2, $lineH = 5.4)
        {
            $this->Rect($x, $y, $w, $h);
            $txt = trim((string) $txt);
            if ($txt === '')
                return;

            $textW = max(1, $w - 2 * $padX);
            $lines = $this->NbLines($textW, $txt);
            $textH = $lines * $lineH;

            $startY = $y + $padY;
            if ($vAlign === 'M') {
                $startY = $y + max($padY, ($h - $textH) / 2);
            } elseif ($vAlign === 'B') {
                $startY = $y + max($padY, $h - $textH - $padY);
            }

            $this->SetXY($x + $padX, $startY);
            $this->MultiCell($textW, $lineH, pdf_text_simple($txt), 0, $align);
        }

        function NbLines($w, $txt)
        {
            $txt = (string) $txt;
            $txt = str_replace("\r", '', $txt);
            $cw = &$this->CurrentFont['cw'];
            if ($w == 0)
                $w = $this->w - $this->rMargin - $this->x;
            $wmax = ($w - 2 * $this->cMargin) * 1000 / $this->FontSize;
            $s = $txt;
            $nb = strlen($s);
            if ($nb > 0 && $s[$nb - 1] == "\n")
                $nb--;
            $sep = -1;
            $i = 0;
            $j = 0;
            $l = 0;
            $nl = 1;
            while ($i < $nb) {
                $c = $s[$i];
                if ($c == "\n") {
                    $i++;
                    $sep = -1;
                    $j = $i;
                    $l = 0;
                    $nl++;
                    continue;
                }
                if ($c == ' ')
                    $sep = $i;
                $l += $cw[$c] ?? 0;
                if ($l > $wmax) {
                    if ($sep == -1) {
                        if ($i == $j)
                            $i++;
                    } else {
                        $i = $sep + 1;
                    }
                    $sep = -1;
                    $j = $i;
                    $l = 0;
                    $nl++;
                } else {
                    $i++;
                }
            }
            return $nl;
        }

        function AddItemRow($x, $y, $sn, $item, $cellH)
        {
            // Calculate item details
            $unit_price = $item['unit_price'] ?? 0;
            $quantity = $item['quantity'] ?? 0;
            $discount_amount = $item['discount_amount'] ?? 0;
            $discount_rate = $item['discount_rate'] ?? 0;

            $cgst_rate = $item['cgst_rate'] ?? 0;
            $sgst_rate = $item['sgst_rate'] ?? 0;
            $igst_rate = $item['igst_rate'] ?? 0;

            $total_gst_rate = $cgst_rate + $sgst_rate + $igst_rate;

            // Calculate line totals
            $line_total_before_discount = $unit_price * $quantity;

            // After discount total
            $line_total_after_discount = $line_total_before_discount - $discount_amount;

            $unit = item_display_unit($item);

            if ($this->is_gst_invoice) {
                // GST INVOICE - Show rate without GST
                $gst_multiplier = 1 + ($total_gst_rate / 100);
                $base_unit_price = $unit_price / $gst_multiplier;

                // Calculate GST amount
                $base_after_discount = $base_unit_price * $quantity;
                if ($discount_amount > 0) {
                    $discount_ratio = $discount_amount / $line_total_before_discount;
                    $base_after_discount = $base_unit_price * $quantity * (1 - $discount_ratio);
                }
                $total_gst_amount = $line_total_after_discount - $base_after_discount;

                // Set positions
                $x0 = $x;
                $x1 = $x0 + $this->col_w[0];
                $x2 = $x1 + $this->col_w[1];
                $x3 = $x2 + $this->col_w[2];
                $x4 = $x3 + $this->col_w[3];
                $x5 = $x4 + $this->col_w[4];
                $x6 = $x5 + $this->col_w[5];
                $x7 = $x6 + $this->col_w[6];
                $x8 = $x7 + $this->col_w[7];

                // SN
                $this->Rect($x0, $y, $this->col_w[0], $cellH);
                $this->SetXY($x0, $y);
                $this->Cell($this->col_w[0], $cellH, (string) $sn, 0, 0, 'C');

                // Item Description
                $item_text = (!empty(item_display_code($item)) ? item_display_code($item) . " - " : "") . item_display_name($item);
                $this->BoxText($x1, $y, $this->col_w[1], $cellH, $item_text, 'L', 'M', 1.2, 1.0, 5.4);

                // HSN
                $this->Rect($x2, $y, $this->col_w[2], $cellH);
                $this->SetXY($x2, $y);
                $this->Cell($this->col_w[2], $cellH, pdf_text_simple(item_display_hsn($item)), 0, 0, 'C');

                // GST(%) 
                $gst_text = $total_gst_rate > 0 ? number_format($total_gst_rate, 1) . '%' : '0%';
                $this->BoxText($x3, $y, $this->col_w[3], $cellH, $gst_text, 'C', 'M', 1.0, 1.0, 5.4);

                // Rate (Without GST)
                $rate_text = 'Rs. ' . money($base_unit_price);
                $this->Rect($x4, $y, $this->col_w[4], $cellH);
                $this->SetXY($x4, $y);
                $this->Cell($this->col_w[4], $cellH, pdf_text_simple($rate_text), 0, 0, 'R');

                // Qty
                $qty_text = format_quantity($quantity) . ' ' . $unit;
                $this->Rect($x5, $y, $this->col_w[5], $cellH);
                $this->SetXY($x5, $y);
                $this->Cell($this->col_w[5], $cellH, pdf_text_simple($qty_text), 0, 0, 'C');

                // Discount
                // Show percentage only when item discount percentage is actually available.
                // For proportional overall discount, discount_rate can be 0.00; printing it
                // as a second line was pushing text outside the discount column.
                if ($discount_amount > 0) {
                    $discount_rate_float = (float) $discount_rate;
                    $disc_text = 'Rs. ' . money($discount_amount);

                    if ($discount_rate_float > 0) {
                        $discount_rate_text = rtrim(rtrim(number_format($discount_rate_float, 2, '.', ''), '0'), '.');
                        $disc_text .= "\n(" . $discount_rate_text . "%)";
                    }
                } else {
                    $disc_text = '-';
                }
                $this->BoxText($x6, $y, $this->col_w[6], $cellH, $disc_text, 'C', 'M', 1.0, 1.0, 4.8);

                // GST Amt - Show calculated GST amount
                if ($total_gst_amount > 0) {
                    $gst_amt_text = 'Rs. ' . money($total_gst_amount);
                } else {
                    $gst_amt_text = '-';
                }
                $this->Rect($x7, $y, $this->col_w[7], $cellH);
                $this->SetXY($x7, $y);
                $this->Cell($this->col_w[7], $cellH, pdf_text_simple($gst_amt_text), 0, 0, 'R');

                // Total (After discount, with GST)
                $total_text = 'Rs. ' . money($line_total_after_discount);
                $this->Rect($x8, $y, $this->col_w[8], $cellH);
                $this->SetXY($x8, $y);
                $this->Cell($this->col_w[8], $cellH, pdf_text_simple($total_text), 0, 0, 'R');
            } else {
                // NON-GST INVOICE - Show simple item row without GST columns
                // Column positions: SN, Item, Rate, Qty, Disc, Total
                $x0 = $x;
                $x1 = $x0 + $this->col_w[0];
                $x2 = $x1 + $this->col_w[1];
                $x3 = $x2 + $this->col_w[2];
                $x4 = $x3 + $this->col_w[3];
                $x5 = $x4 + $this->col_w[4];

                // SN
                $this->Rect($x0, $y, $this->col_w[0], $cellH);
                $this->SetXY($x0, $y);
                $this->Cell($this->col_w[0], $cellH, (string) $sn, 0, 0, 'C');

                // Item Description (with code)
                $item_text = (!empty(item_display_code($item)) ? item_display_code($item) . " - " : "") . item_display_name($item);
                $this->BoxText($x1, $y, $this->col_w[1], $cellH, $item_text, 'L', 'M', 1.2, 1.0, 5.4);

                // Rate (WITH GST - full price for non-GST)
                $rate_text = 'Rs. ' . money($unit_price);
                $this->Rect($x2, $y, $this->col_w[2], $cellH);
                $this->SetXY($x2, $y);
                $this->Cell($this->col_w[2], $cellH, pdf_text_simple($rate_text), 0, 0, 'R');

                // Qty
                $qty_text = format_quantity($quantity) . ' ' . $unit;
                $this->Rect($x3, $y, $this->col_w[3], $cellH);
                $this->SetXY($x3, $y);
                $this->Cell($this->col_w[3], $cellH, pdf_text_simple($qty_text), 0, 0, 'C');

                // Discount
                // Show percentage only when item discount percentage is actually available.
                // For proportional overall discount, discount_rate can be 0.00; printing it
                // as a second line was pushing text outside the discount column.
                if ($discount_amount > 0) {
                    $discount_rate_float = (float) $discount_rate;
                    $disc_text = 'Rs. ' . money($discount_amount);

                    if ($discount_rate_float > 0) {
                        $discount_rate_text = rtrim(rtrim(number_format($discount_rate_float, 2, '.', ''), '0'), '.');
                        $disc_text .= "\n(" . $discount_rate_text . "%)";
                    }
                } else {
                    $disc_text = '-';
                }
                $this->BoxText($x4, $y, $this->col_w[4], $cellH, $disc_text, 'C', 'M', 1.0, 1.0, 4.8);

                // Total (After discount)
                $total_text = 'Rs. ' . money($line_total_after_discount);
                $this->Rect($x5, $y, $this->col_w[5], $cellH);
                $this->SetXY($x5, $y);
                $this->Cell($this->col_w[5], $cellH, pdf_text_simple($total_text), 0, 0, 'R');
            }
        }

        function DrawAmountSummary()
        {
            $t = $this->totals;

            $this->SetFont('Arial', '', 9);
            $leftX = $this->lm;
            $rightX = $this->GetPageWidth() - $this->rm - 80;
            $startY = $this->GetY();

            $y = $startY;

            // Right side summary
            $this->SetFont('Arial', '', 9);
            $this->SetXY($rightX, $y);

            if ($this->is_gst_invoice) {
                // GST INVOICE - Show taxable value and GST components
                // Taxable Value (without GST)
                if ($t['taxable'] > 0) {
                    $this->SetX($rightX);
                    $this->Cell(40, 6, pdf_text_simple('Taxable Value'), 0, 0, 'L');
                    $this->Cell(40, 6, pdf_text_simple('Rs. ' . money($t['taxable'])), 0, 1, 'R');
                    $y = $this->GetY();
                }

                // CGST
                if ($t['cgst'] > 0) {
                    $this->SetX($rightX);
                    $this->Cell(40, 6, pdf_text_simple('CGST'), 0, 0, 'L');
                    $this->Cell(40, 6, pdf_text_simple('Rs. ' . money($t['cgst'])), 0, 1, 'R');
                    $y = $this->GetY();
                }

                // SGST
                if ($t['sgst'] > 0) {
                    $this->SetX($rightX);
                    $this->Cell(40, 6, pdf_text_simple('SGST'), 0, 0, 'L');
                    $this->Cell(40, 6, pdf_text_simple('Rs. ' . money($t['sgst'])), 0, 1, 'R');
                    $y = $this->GetY();
                }

                // IGST
                if ($t['igst'] > 0) {
                    $this->SetX($rightX);
                    $this->Cell(40, 6, pdf_text_simple('IGST'), 0, 0, 'L');
                    $this->Cell(40, 6, pdf_text_simple('Rs. ' . money($t['igst'])), 0, 1, 'R');
                    $y = $this->GetY();
                }

                // Add Shipping Charges if they exist
                if ($t['shipping_charges'] > 0) {
                    $this->SetX($rightX);
                    $this->Cell(40, 6, pdf_text_simple('Shipping Charges'), 0, 0, 'L');
                    $this->Cell(40, 6, pdf_text_simple('Rs. ' . money($t['shipping_charges'])), 0, 1, 'R');
                    $y = $this->GetY();
                }

                // Add Transport Charge if they exist
                if ($t['transport_charge'] > 0) {
                    $this->SetX($rightX);
                    $this->Cell(40, 6, pdf_text_simple('Transport Charge'), 0, 0, 'L');
                    $this->Cell(40, 6, pdf_text_simple('Rs. ' . money($t['transport_charge'])), 0, 1, 'R');
                    $y = $this->GetY();
                }

                // Add Overall Discount if exists
                if (isset($t['overall_discount']) && $t['overall_discount'] > 0) {
                    $this->SetX($rightX);
                    $this->Cell(40, 6, pdf_text_simple('Overall Discount'), 0, 0, 'L');
                    $this->Cell(40, 6, pdf_text_simple('- Rs. ' . money($t['overall_discount'])), 0, 1, 'R');
                    $y = $this->GetY();
                }

                // Item Discounts if any and no overall discount
                if ($t['discount'] > 0 && $t['overall_discount'] == 0) {
                    $this->SetX($rightX);
                    $this->Cell(40, 6, pdf_text_simple('Item Discounts'), 0, 0, 'L');
                    $this->Cell(40, 6, pdf_text_simple('- Rs. ' . money($t['discount'])), 0, 1, 'R');
                    $y = $this->GetY();
                }
            } else {
                // NON-GST INVOICE - Simple summary
                // Subtotal (without any tax breakdown)
                $this->SetX($rightX);
                $this->Cell(40, 6, pdf_text_simple('Subtotal'), 0, 0, 'L');
                $this->Cell(40, 6, pdf_text_simple('Rs. ' . money($t['subtotal'])), 0, 1, 'R');
                $y = $this->GetY();

                // Add Shipping Charges if they exist
                if ($t['shipping_charges'] > 0) {
                    $this->SetX($rightX);
                    $this->Cell(40, 6, pdf_text_simple('Shipping Charges'), 0, 0, 'L');
                    $this->Cell(40, 6, pdf_text_simple('Rs. ' . money($t['shipping_charges'])), 0, 1, 'R');
                    $y = $this->GetY();
                }

                // Add Transport Charge if they exist
                if ($t['transport_charge'] > 0) {
                    $this->SetX($rightX);
                    $this->Cell(40, 6, pdf_text_simple('Transport Charge'), 0, 0, 'L');
                    $this->Cell(40, 6, pdf_text_simple('Rs. ' . money($t['transport_charge'])), 0, 1, 'R');
                    $y = $this->GetY();
                }

                // Add Overall Discount if exists
                if (isset($t['overall_discount']) && $t['overall_discount'] > 0) {
                    $this->SetX($rightX);
                    $this->Cell(40, 6, pdf_text_simple('Overall Discount'), 0, 0, 'L');
                    $this->Cell(40, 6, pdf_text_simple('- Rs. ' . money($t['overall_discount'])), 0, 1, 'R');
                    $y = $this->GetY();
                }

                // Item Discounts if any and no overall discount
                if ($t['discount'] > 0 && $t['overall_discount'] == 0) {
                    $this->SetX($rightX);
                    $this->Cell(40, 6, pdf_text_simple('Item Discounts'), 0, 0, 'L');
                    $this->Cell(40, 6, pdf_text_simple('- Rs. ' . money($t['discount'])), 0, 1, 'R');
                    $y = $this->GetY();
                }
            }

            // Grand Total with bold font
            $this->SetFont('Arial', 'B', 11);
            $this->SetX($rightX);
            $this->Cell(40, 8, pdf_text_simple('GRAND TOTAL'), 0, 0, 'L');
            $this->Cell(40, 8, pdf_text_simple('Rs. ' . money($t['grand_total'])), 0, 1, 'R');

            $endY = max($y, $startY + 9);
            $this->SetY($endY + 2);
        }

        function DrawAccountDetails()
        {
            $accounts = !empty($this->accounts) ? $this->accounts : [];
            if (empty($accounts) && !empty($this->account)) {
                $accounts = [$this->account];
            }

            if (empty($accounts)) {
                return;
            }

            $x = $this->lm;
            $w = $this->GetPageWidth() - ($this->lm + $this->rm);
            $cols = count($accounts) > 1 ? 2 : 1;
            $cellW = $w / $cols;
            $rowH = 28;
            $headerH = 6;
            $totalH = $headerH + (ceil(count($accounts) / $cols) * $rowH);

            // Only add account details if there's space on current page.
            if ($this->GetY() + $totalH > ($this->GetPageHeight() - $this->bm)) {
                return;
            }

            $this->SetFont('Arial', 'B', 10);
            $this->Cell(0, $headerH, pdf_text_simple('Account Details'), 0, 1, 'L');

            $yStart = $this->GetY();
            $this->SetFont('Arial', '', 7.2);

            foreach ($accounts as $idx => $a) {
                $col = $idx % $cols;
                $row = (int)floor($idx / $cols);
                $cx = $x + ($col * $cellW);
                $cy = $yStart + ($row * $rowH);

                $this->Rect($cx, $cy, $cellW, $rowH);
                $this->SetFont('Arial', 'B', 7.2);
                $this->SetXY($cx + 2, $cy + 1.5);
                $this->Cell($cellW - 4, 4, pdf_text_simple('Bank Account ' . ($idx + 1)), 0, 1, 'L');

                $this->SetFont('Arial', '', 7.2);
                $lines = [];
                if (!empty($a['account_name'])) $lines[] = 'A/C Name : ' . $a['account_name'];
                if (!empty($a['bank_name'])) $lines[] = 'Bank : ' . $a['bank_name'];
                if (!empty($a['account_number'])) $lines[] = 'A/C No : ' . $a['account_number'];
                if (!empty($a['ifsc'])) $lines[] = 'IFSC : ' . $a['ifsc'];
                if (!empty($a['branch'])) $lines[] = 'Branch : ' . $a['branch'];
                if (!empty($a['upi'])) $lines[] = 'UPI : ' . $a['upi'];

                $ly = $cy + 5.8;
                foreach ($lines as $ln) {
                    if ($ly + 3.5 > $cy + $rowH - 1) {
                        break;
                    }
                    $this->SetXY($cx + 2, $ly);
                    $this->Cell($cellW - 4, 3.5, pdf_text_simple($ln), 0, 1, 'L');
                    $ly += 3.5;
                }
            }

            $this->SetY($yStart + (ceil(count($accounts) / $cols) * $rowH) + 2);
        }

        // Helper method to initialize column widths based on invoice type
        function initColumnWidths()
        {
            $pageWidth = $this->GetPageWidth();
            $printable = $pageWidth - ($this->lm + $this->rm);

            if ($this->is_gst_invoice) {
                // GST Invoice headers
                $this->col_headers = ['SN', 'Item Description', 'HSN', 'GST(%)', 'Rate', 'Qty', 'Disc', 'GST Amt', 'Total'];

                // Calculate column widths based on proportions
                $this->col_w = [];
                foreach ($this->col_props_gst as $p) {
                    $this->col_w[] = round($printable * $p);
                }

                // Adjust if total doesn't match printable width
                $totalWidth = array_sum($this->col_w);
                if ($totalWidth != $printable) {
                    $this->col_w[1] += ($printable - $totalWidth);
                }
            } else {
                // Non-GST Invoice headers (simplified)
                $this->col_headers = ['SN', 'Item Description', 'Rate', 'Qty', 'Disc', 'Total'];

                // Calculate column widths based on proportions
                $this->col_w = [];
                foreach ($this->col_props_non_gst as $p) {
                    $this->col_w[] = round($printable * $p);
                }

                // Adjust if total doesn't match printable width
                $totalWidth = array_sum($this->col_w);
                if ($totalWidth != $printable) {
                    $this->col_w[1] += ($printable - $totalWidth);
                }
            }
        }
    }

    // ========== Create PDF with OLD template ==========
    $pdf = new InvoicePDF('P', 'mm', 'A4');
    $pdf->AliasNbPages();

    $pdf->lm = 8;
    $pdf->rm = 8;
    $pdf->tm = 8;
    $pdf->bm = 15;
    $pdf->SetMargins($pdf->lm, $pdf->tm, $pdf->rm);
    $pdf->SetAutoPageBreak(true, $pdf->bm);

    // Set GST invoice flag based on whether this is a tax invoice
    $pdf->is_gst_invoice = $is_tax_invoice;

    // Initialize column widths based on invoice type
    $pdf->initColumnWidths();

    // Set company info - using data from invoice_settings table
    $pdf->company = [
        'name' => $company_name,
        'address' => $company_address,
        'gstin' => $company_gstin,
        'phone' => $company_phone,
        'email' => $settings['company_email'] ?? '',
        'logo' => $company_logo
    ];

    // Set invoice info
    $pdf->invoice = [
        'number' => $invoice['invoice_number'],
        'date' => $invoice_date,
        'payment' => $payment_method,
        'status' => $payment_status,
        'printed_on' => date('d-m-Y H:i:s'),
        'place_of_supply' => $place_of_supply
    ];

    // Set customer info
    $pdf->customer = [
        'name' => $customer_name,
        'phone' => $customer_phone,
        'gstin' => $customer_gstin,
        'address' => $customer_full_address
    ];

    // Set shipping info
    $pdf->shipping = [
        'name' => $shipping_name,
        'contact' => $shipping_contact,
        'gstin' => $shipping_gstin,
        'address' => $shipping_full_address,
        'district' => $shipping_district,
        'state' => $shipping_state,
        'pincode' => $shipping_pincode,
        'vehicle_number' => $shipping_vehicle_number,
        'transport_type' => $shipping_transport_type,
        'shipping_charges' => $shipping_charges,
        'transport_charge' => $transport_charge,
        'total_extra' => $total_extra_charges
    ];

    // Set totals - INCLUDING OVERALL DISCOUNT
    $pdf->totals = [
        'subtotal' => $subtotal,
        'discount' => $total_discount + $overall_discount,
        'overall_discount' => $overall_discount,
        'taxable' => $total_taxable,
        'cgst' => $total_cgst,
        'sgst' => $total_sgst,
        'igst' => $total_igst,
        'shipping_charges' => $shipping_charges,
        'transport_charge' => $transport_charge,
        'total_extra_charges' => $total_extra_charges,
        'grand_total' => $grand_total
    ];

    // Set all active bank account details for this branch/business
    $pdf->accounts = [];
    if (!empty($bank_accounts)) {
        foreach ($bank_accounts as $bank) {
            $pdf->accounts[] = [
                'account_name' => $bank['account_holder_name'] ?? '',
                'bank_name' => $bank['bank_name'] ?? '',
                'account_number' => $bank['account_number'] ?? '',
                'ifsc' => $bank['ifsc_code'] ?? '',
                'branch' => $bank['branch_name'] ?? '',
                'upi' => $bank['upi_id'] ?? ''
            ];
        }
        $pdf->account = $pdf->accounts[0];
    } else {
        $pdf->account = [];
        $pdf->accounts = [];
    }

    // Set verified by (seller)
    $pdf->verified_by = $invoice['seller_name'];

    // ========== Generate PDF ==========
    $pdf->AddPage();
    $pdf->SetFont('Arial', '', 8);
    $lineH = 6.2;
    $minLines = 1;
    $sn = 1;

    // Add items only if we have items. Manual seller sale items are fetched from invoice_items snapshot.
    if (!empty($items)) {
        foreach ($items as $item) {
            $name = item_display_name($item);
            $code = item_display_code($item);
            $itemText = (!empty($code) ? $code . " - " : "") . $name;

            // Calculate required height
            $itemLines = max($minLines, $pdf->NbLines(max(1, $pdf->col_w[1] - 3), $itemText));
            $maxLines = max($itemLines, 1);
            $cellH = ($maxLines * $lineH) + 3;

            // Check if need new page - with stricter conditions to prevent extra pages
            $currentY = $pdf->GetY();
            $pageHeight = $pdf->GetPageHeight();
            $bottomMargin = $pdf->bm;

            // Calculate if we have enough space for this item AND the summary section
            if ($currentY + $cellH + 80 > ($pageHeight - $bottomMargin)) {
                $pdf->AddPage();
                $pdf->SetFont('Arial', '', 8);
                $pdf->TableHeader();
            }

            $x = $pdf->GetX();
            $y = $pdf->GetY();

            $pdf->AddItemRow($x, $y, $sn, $item, $cellH);

            $pdf->SetXY($x, $y + $cellH);
            $sn++;
        }
    }

    // ========== Summary and Account Details ==========
    $pdf->Ln(2);
    $pdf->DrawAmountSummary();
    $pdf->DrawAccountDetails();

    // ========== Notes ==========
    $notes = trim($invoice['notes'] ?? '');
    if ($notes !== '') {
        // Check if we have space for notes
        if ($pdf->GetY() + 20 < ($pdf->GetPageHeight() - $pdf->bm)) {
            $pdf->SetFont('Arial', 'B', 10);
            $pdf->Cell(0, 6, pdf_text_simple('Notes:'), 0, 1, 'L');
            $pdf->SetFont('Arial', '', 9);
            $pdf->MultiCell(0, 4, pdf_text_simple($notes), 0, 'L');
        }
    }

    // ========== Terms and Conditions ==========
    $terms = $settings['invoice_terms'] ?? '';
    if ($terms !== '') {
        // Check if we have space for terms
        if ($pdf->GetY() + 30 < ($pdf->GetPageHeight() - $pdf->bm)) {
            $pdf->SetFont('Arial', 'B', 8);
            $pdf->Cell(0, 5, pdf_text_simple('Terms & Conditions:'), 0, 1, 'L');
            $pdf->SetFont('Arial', '', 7);
            $pdf->MultiCell(0, 3.5, pdf_text_simple($terms), 0, 'L');
        }
    }

    // ========== Authorized Signatory ==========
    // Make sure we have space for signature
    if ($pdf->GetY() + 25 < ($pdf->GetPageHeight() - $pdf->bm)) {
        $pdf->Ln(10);
        $pdf->SetFont('Arial', 'B', 10);
        $pdf->Cell(0, 6, pdf_text_simple('For ' . $company_name), 0, 1, 'R');
        $pdf->SetFont('Arial', '', 9);
        $pdf->Cell(0, 15, pdf_text_simple('Authorized Signatory'), 0, 1, 'R');
    }

    // ========== Footer note ==========
    if (!empty($settings['invoice_footer'])) {
        // Only add footer if we have space
        if ($pdf->GetY() + 10 < ($pdf->GetPageHeight() - $pdf->bm)) {
            $pdf->SetFont('Arial', 'I', 8);
            $pdf->Cell(0, 10, pdf_text_simple($settings['invoice_footer']), 0, 1, 'C');
        }
    }
}

// ========== Output PDF ==========
while (ob_get_level())
    ob_end_clean();

$pdf_content = $pdf->Output('S', 'Invoice_' . $invoice['invoice_number'] . '.pdf');

// Set headers for PDF download
header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="Invoice_' . $invoice['invoice_number'] . '.pdf"');
header('Content-Length: ' . strlen($pdf_content));
header('Cache-Control: private, max-age=0, must-revalidate');
header('Pragma: public');

// Output PDF content
echo $pdf_content;
exit;
?>