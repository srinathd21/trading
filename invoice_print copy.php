<?php
// invoice_print.php - Using FPDF with new template design
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
    $invoice_id = (int)$_GET['invoice_id'];
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
           u.full_name as seller_name,
           s.shop_name, s.address as shop_address, s.phone as shop_phone, s.gstin as shop_gstin,
           s.id as shop_id,
           i.shipping_name, i.shipping_contact, i.shipping_gstin, i.shipping_address,
           i.shipping_vehicle_number, i.shipping_charges
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

// Get shipping details
$shipping_name = $invoice['shipping_name'] ?? '';
$shipping_contact = $invoice['shipping_contact'] ?? '';
$shipping_gstin = $invoice['shipping_gstin'] ?? '';
$shipping_address = $invoice['shipping_address'] ?? '';
$shipping_vehicle_number = $invoice['shipping_vehicle_number'] ?? '';
$shipping_charges = $invoice['shipping_charges'] ?? 0;

// Get shop_id from invoice
$shop_id = $invoice['shop_id'] ?? null;

// Fetch invoice settings for this shop/business
$settings_stmt = $pdo->prepare("
    SELECT * FROM invoice_settings
    WHERE business_id = ? AND shop_id = ?
    LIMIT 1
");
$settings_stmt->execute([$business_id, $shop_id]);
$settings = $settings_stmt->fetch(PDO::FETCH_ASSOC);

// If no shop-specific settings, get business default
if (!$settings && $shop_id) {
    $settings_stmt = $pdo->prepare("
        SELECT * FROM invoice_settings
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

// ========== Fetch default active bank accounts ==========
if ($shop_id) {
    $bank_account_sql = "SELECT * FROM bank_accounts 
                        WHERE business_id = ? AND shop_id = ? AND is_active = 1
                        ORDER BY is_default DESC, id ASC
                        LIMIT 2";
    $bank_account_stmt = $pdo->prepare($bank_account_sql);
    $bank_account_stmt->execute([$business_id, $shop_id]);
} else {
    $bank_account_sql = "SELECT * FROM bank_accounts 
                        WHERE business_id = ? AND shop_id IS NULL AND is_active = 1
                        ORDER BY is_default DESC, id ASC
                        LIMIT 2";
    $bank_account_stmt = $pdo->prepare($bank_account_sql);
    $bank_account_stmt->execute([$business_id]);
}
$bank_accounts = $bank_account_stmt->fetchAll(PDO::FETCH_ASSOC);

// ========== Fetch invoice items ==========
$items_stmt = $pdo->prepare("
    SELECT ii.*, 
           p.product_name, p.product_code, p.hsn_code, p.mrp, p.gst_id,
           g.cgst_rate, g.sgst_rate, g.igst_rate,
           ii.unit as product_unit
    FROM invoice_items ii
    JOIN products p ON ii.product_id = p.id
    LEFT JOIN gst_rates g ON p.gst_id = g.id
    WHERE ii.invoice_id = ?
    ORDER BY ii.id
");
$items_stmt->execute([$invoice_id]);
$items = $items_stmt->fetchAll();

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
    $gst_rate = ($item['cgst_rate'] ?? 0) + ($item['sgst_rate'] ?? 0) + ($item['igst_rate'] ?? 0);
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
$customer_gstin = $invoice['customer_gstin'] ?? '';

// Transport and waybill details (from shipping)
$transport = !empty($shipping_vehicle_number) ? 'Vehicle: ' . $shipping_vehicle_number : 'By Road';
$waybill_no = $invoice['shipping_waybill_no'] ?? '';

// ========== Include FPDF library ==========
require_once 'libs/fpdf.php';

// ========== Helper functions ==========
function money($v) { 
    return number_format((float)$v, 2, '.', ','); 
}

function money_rs($v) { 
    return 'Rs. ' . money($v);
}

function format_quantity($v) {
    $v = (float)$v;
    if (floor($v) == $v) return number_format($v, 0, '.', '');
    return number_format($v, 2, '.', '');
}

function pdf_text_simple($s) {
    $s = (string)$s;
    // Replace problematic characters
    $s = str_replace(["₹", "â‚¹", "€", "£", "¥"], ["Rs.", "Rs.", "EUR", "GBP", "JPY"], $s);
    
    // Remove any non-ASCII characters
    $s = preg_replace('/[^\x00-\x7F]/', '', $s);
    
    return $s;
}

function number_to_words($number) {
    $words = array(
        '0' => '', '1' => 'One', '2' => 'Two', '3' => 'Three', '4' => 'Four', '5' => 'Five',
        '6' => 'Six', '7' => 'Seven', '8' => 'Eight', '9' => 'Nine', '10' => 'Ten',
        '11' => 'Eleven', '12' => 'Twelve', '13' => 'Thirteen', '14' => 'Fourteen',
        '15' => 'Fifteen', '16' => 'Sixteen', '17' => 'Seventeen', '18' => 'Eighteen',
        '19' => 'Nineteen', '20' => 'Twenty', '30' => 'Thirty', '40' => 'Forty',
        '50' => 'Fifty', '60' => 'Sixty', '70' => 'Seventy', '80' => 'Eighty', '90' => 'Ninety'
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

function convert_number_to_words($num, $words) {
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
    public $items = [];
    public $transport = '';
    public $waybill_no = '';
    public $headerEndY = 0;
    
    function Header()
    {
        // Outer border
        $this->Rect(5, 5, 200, 287);
        
        // Title
        $this->SetFont('Arial','B',14);
        $this->SetXY(5,8);
        $title = $this->invoice['is_tax_invoice'] ? 'TAX INVOICE' : 'INVOICE';
        $this->Cell(200,8, $title, 0, 1, 'C');
        
        // IRN and Ack details (if available)
        $this->SetFont('Arial','',7);
        $this->SetXY(5,16);
        
        if (!empty($this->invoice['irn_no'])) {
            $irnText = "IRN No.: " . $this->invoice['irn_no'];
            $this->MultiCell(200, 5, $irnText, 0, 'C');
        }
        
        if (!empty($this->invoice['ack_no']) && !empty($this->invoice['ack_date'])) {
            $this->SetFont('Arial','',8);
            $this->SetXY(5, $this->GetY());
            $this->Cell(200,5, 'Ack No.: ' . $this->invoice['ack_no'] . ' | Ack Date: ' . $this->invoice['ack_date'], 0, 1, 'C');
        }
        
        $this->headerEndY = $this->GetY();
    }
    
    function LabelValue($label, $value, $totalWidth = 100)
    {
        // Bold label
        $this->SetFont('Arial','B',9);
        $labelWidth = $this->GetStringWidth($label);
        
        $this->Cell($labelWidth, 5, pdf_text_simple($label), 0, 0);
        
        // Normal value (tight join)
        $this->SetFont('Arial','',9);
        $this->Cell($totalWidth - $labelWidth, 5, pdf_text_simple($value), 0, 0);
    }
    
    function TopSection()
    {
        $this->SetFont('Arial','',9);
        
        // Start below header dynamically
        $this->SetY($this->headerEndY + 3);
        
        $startY = $this->GetY();
        $midX = 105;
        
        // -------- ROW 1 --------
        $this->SetX(5);
        $this->LabelValue('Invoice #: ', $this->invoice['number']);
        $this->LabelValue('Date: ', $this->invoice['date']);
        $this->Ln();
        
        // -------- ROW 2 --------
        $this->SetX(5);
        $this->LabelValue('Transport: ', $this->transport);
        $this->LabelValue('Waybill No.: ', $this->waybill_no);
        $this->Ln();
        
        // -------- ROW 3 --------
        $this->SetX(5);
        $this->LabelValue('Cust. Name: ', $this->customer['name']);
        $this->LabelValue('Cust. Number: ', $this->customer['phone']);
        $this->Ln();
        
        // -------- ROW 4 --------
        $this->SetX(5);
        $buyerOrderNo = $this->invoice['buyer_order_no'] ?? 'By Phone | ' . date('d/m/y', strtotime($this->invoice['date']));
        $this->LabelValue("Buyer's Order No.: ", $buyerOrderNo);
        $this->LabelValue('Date of Supply: ', $this->invoice['date']);
        $this->Ln();
        
        // -------- ROW 5 --------
        $this->SetX(5);
        $this->LabelValue('Terms of Payment: ', $this->invoice['payment_terms'] ?? 'Immediate');
        $this->LabelValue('Due Date: ', $this->invoice['due_date'] ?? $this->invoice['date']);
        $this->Ln();
        
        // -------- ROW 6 --------
        $this->SetX(5);
        $this->LabelValue('Destination: ', $this->invoice['destination'] ?? '');
        $this->LabelValue('State / Code: ', $this->invoice['place_of_supply']);
        $this->Ln();
        
        // -------- ROW 7 --------
        $this->SetX(5);
        $documentContact = !empty($this->company['email']) ? $this->company['name'] . '; ' . $this->company['email'] : $this->company['name'];
        $this->LabelValue('Document Contact: ', $documentContact);
        $this->Ln();
        
        // End Y
        $endY = $this->GetY();
        
        // -------- BORDERS --------
        $this->Rect(5, $startY, 200, $endY - $startY);
        $this->Line($midX, $startY, $midX, $endY);
        
        // Move cursor below section
        $this->SetY($endY + 2);
    }
    
    function PartyBoxes()
    {
        $this->Ln(2);
        
        $this->SetFont('Arial','B',9);
        
        // Header row
        $this->SetX(5);
        $this->Cell(100, 6, 'Details of Receiver (Billed to)', 1, 0, 'C');
        $this->Cell(100, 6, 'Details of Consignee (Shipped to)', 1, 1, 'C');
        
        $this->SetFont('Arial','',9);
        
        $leftX = 5;
        $rightX = 105;
        $y = $this->GetY();
        
        // ================= LEFT BOX =================
        $this->SetXY($leftX, $y);
        
        // Address
        $billAddress = $this->customer['name'] . "\n" . $this->customer['address'];
        $this->MultiCell(100, 5, pdf_text_simple($billAddress), 0);
        
        // GSTIN (JOINED)
        $this->SetX($leftX);
        
        $this->SetFont('Arial','B',9);
        $label = 'GSTIN: ';
        $labelWidth = $this->GetStringWidth($label);
        
        $this->Cell($labelWidth, 5, $label, 0, 0);
        
        $this->SetFont('Arial','',9);
        $gstinValue = !empty($this->customer['gstin']) ? $this->customer['gstin'] : 'Not Registered';
        $this->Cell(100 - $labelWidth, 5, pdf_text_simple($gstinValue), 0, 1);
        
        $leftFinalY = $this->GetY();
        
        // ================= RIGHT BOX =================
        $this->SetXY($rightX, $y);
        
        // Address
        $hasShipping = !empty($this->shipping['name']) || !empty($this->shipping['address']);
        if ($hasShipping) {
            $shipAddress = $this->shipping['name'] . "\n" . $this->shipping['address'];
        } else {
            $shipAddress = $this->customer['name'] . "\n" . $this->customer['address'];
        }
        $this->MultiCell(100, 5, pdf_text_simple($shipAddress), 0);
        
        // GSTIN (JOINED)
        $this->SetX($rightX);
        
        $this->SetFont('Arial','B',9);
        $label = 'GSTIN: ';
        $labelWidth = $this->GetStringWidth($label);
        
        $this->Cell($labelWidth, 5, $label, 0, 0);
        
        $this->SetFont('Arial','',9);
        if ($hasShipping && !empty($this->shipping['gstin'])) {
            $shipGstin = $this->shipping['gstin'];
        } else {
            $shipGstin = !empty($this->customer['gstin']) ? $this->customer['gstin'] : 'Not Registered';
        }
        $this->Cell(100 - $labelWidth, 5, pdf_text_simple($shipGstin), 0, 1);
        
        $rightFinalY = $this->GetY();
        
        // ================= HEIGHT SYNC =================
        $boxEndY = max($leftFinalY, $rightFinalY);
        
        // Draw borders AFTER content
        $this->Rect($leftX, $y, 100, $boxEndY - $y);
        $this->Rect($rightX, $y, 100, $boxEndY - $y);
        
        // Move cursor below
        $this->SetY($boxEndY);
    }
    
    function TableHeader()
    {
        $this->SetFont('Arial','B',8);
        
        // Header with borders
        $this->SetX(5);
        $this->Cell(10, 7, 'Sl.', 1, 0, 'C');
        $this->Cell(60, 7, 'Description of Goods', 1, 0, 'C');
        $this->Cell(20, 7, 'HSN / SAC', 1, 0, 'C');
        $this->Cell(12, 7, 'Qty', 1, 0, 'C');
        $this->Cell(18, 7, 'Rate', 1, 0, 'C');
        $this->Cell(15, 7, 'Disc%', 1, 0, 'C');
        $this->Cell(25, 7, 'Taxable Amt.', 1, 0, 'C');
        $this->Cell(18, 7, 'GST %', 1, 0, 'C');
        $this->Cell(22, 7, 'Amount', 1, 1, 'C');
    }
    
    function TableRows()
    {
        $this->SetFont('Arial', '', 8);
        
        $sn = 1;
        $totalQty = 0;
        $totalTaxableAmount = 0;
        $totalAmount = 0;
        
        foreach ($this->items as $item) {
            $unit_price = $item['unit_price'] ?? 0;
            $quantity = $item['quantity'] ?? 0;
            $discount_amount = $item['discount_amount'] ?? 0;
            $discount_rate = $item['discount_rate'] ?? 0;
            
            $cgst_rate = $item['cgst_rate'] ?? 0;
            $sgst_rate = $item['sgst_rate'] ?? 0;
            $igst_rate = $item['igst_rate'] ?? 0;
            $total_gst_rate = $cgst_rate + $sgst_rate + $igst_rate;
            
            $line_total = $unit_price * $quantity;
            $net_total = $line_total - $discount_amount;
            $taxable_value = $item['taxable_value'] ?? $net_total;
            
            $totalQty += $quantity;
            $totalTaxableAmount += $taxable_value;
            $totalAmount += $net_total;
            
            $productDesc = (!empty($item['product_code']) ? $item['product_code'] . ' ' : '') . $item['product_name'];
            
            $this->SetX(5);
            $this->Cell(10, 7, $sn, 1, 0, 'C');
            $this->Cell(60, 7, pdf_text_simple($productDesc), 1, 0, 'L');
            $this->Cell(20, 7, pdf_text_simple($item['hsn_code'] ?? ''), 1, 0, 'C');
            $this->Cell(12, 7, format_quantity($quantity), 1, 0, 'C');
            $this->Cell(18, 7, money($unit_price), 1, 0, 'R');
            $this->Cell(15, 7, $discount_rate > 0 ? $discount_rate . '%' : '', 1, 0, 'R');
            $this->Cell(25, 7, money($taxable_value), 1, 0, 'R');
            $this->Cell(18, 7, $total_gst_rate > 0 ? number_format($total_gst_rate, 1) . '%' : '0%', 1, 0, 'C');
            $this->Cell(22, 7, money($net_total), 1, 1, 'R');
            
            $sn++;
        }
        
        // Add total row
        $this->SetFont('Arial', 'B', 8);
        $this->SetX(5);
        $this->Cell(10, 7, '', 1, 0);
        $this->Cell(60, 7, 'Total:', 1, 0, 'R');
        $this->Cell(20, 7, '', 1, 0);
        $this->Cell(12, 7, format_quantity($totalQty), 1, 0, 'C');
        $this->Cell(18, 7, '', 1, 0);
        $this->Cell(15, 7, '', 1, 0);
        $this->Cell(25, 7, money($totalTaxableAmount), 1, 0, 'R');
        $this->Cell(18, 7, '', 1, 0);
        $this->Cell(22, 7, money($totalAmount), 1, 1, 'R');
    }
    
    function FooterSection()
    {
        $this->SetFont('Arial','B',9);
        $this->SetX(5);
        $amountInWords = number_to_words($this->totals['grand_total']);
        $this->Cell(200, 8, 'In Words: ' . $amountInWords, 0, 1);
    }
    
    function TotalsBox()
    {
        $this->SetFont('Arial','B',9);
        
        // First row: Header
        $this->SetX(5);
        $this->Cell(30, 7, 'GST Rate', 1, 0, 'C');
        $this->Cell(40, 7, 'Taxable Values', 1, 0, 'C');
        $this->Cell(15, 7, 'CGST %', 1, 0, 'C');
        $this->Cell(20, 7, 'CGST Amt', 1, 0, 'C');
        $this->Cell(15, 7, 'SGST %', 1, 0, 'C');
        $this->Cell(20, 7, 'SGST Amt', 1, 0, 'C');
        $this->Cell(30, 7, 'Subtotal', 1, 0, 'C');
        $this->Cell(30, 7, money($this->totals['subtotal']), 1, 1, 'R');
        
        $this->SetFont('Arial','',9);
        
        $totalCgst = 0;
        $totalSgst = 0;
        $totalIgst = 0;
        
        // Display rows for each GST rate
        if (!empty($this->taxable_by_rate)) {
            foreach ($this->taxable_by_rate as $rate => $data) {
                $cgstRate = $rate / 2;
                $sgstRate = $rate / 2;
                
                $this->SetX(5);
                $this->Cell(30, 7, number_format($rate, 1) . '% GST', 1, 0, 'C');
                $this->Cell(40, 7, money($data['taxable']), 1, 0, 'R');
                $this->Cell(15, 7, number_format($cgstRate, 1) . '%', 1, 0, 'C');
                $this->Cell(20, 7, money($data['cgst']), 1, 0, 'R');
                $this->Cell(15, 7, number_format($sgstRate, 1) . '%', 1, 0, 'C');
                $this->Cell(20, 7, money($data['sgst']), 1, 0, 'R');
                $this->Cell(30, 7, '', 1, 0);
                $this->Cell(30, 7, '', 1, 1);
                
                $totalCgst += $data['cgst'];
                $totalSgst += $data['sgst'];
                $totalIgst += $data['igst'];
            }
        }
        
        // Add: CGST row
        $this->SetX(5);
        $this->Cell(30, 7, '', 1, 0);
        $this->Cell(40, 7, '', 1, 0);
        $this->Cell(35, 7, '', 1, 0);
        $this->Cell(35, 7, '', 1, 0);
        $this->SetFont('Arial','B',9);
        $this->Cell(30, 7, 'Add: CGST', 1, 0, 'L');
        $this->SetFont('Arial','',9);
        $this->Cell(30, 7, money($totalCgst), 1, 1, 'R');
        
        // Add: SGST row
        $this->SetX(5);
        $this->Cell(30, 7, '', 1, 0);
        $this->Cell(40, 7, '', 1, 0);
        $this->Cell(35, 7, '', 1, 0);
        $this->Cell(35, 7, '', 1, 0);
        $this->SetFont('Arial','B',9);
        $this->Cell(30, 7, 'Add: SGST', 1, 0, 'L');
        $this->SetFont('Arial','',9);
        $this->Cell(30, 7, money($totalSgst), 1, 1, 'R');
        
        // Empty row
        $this->SetX(5);
        $this->Cell(30, 7, '', 1, 0);
        $this->Cell(40, 7, '', 1, 0);
        $this->Cell(35, 7, '', 1, 0);
        $this->Cell(35, 7, '', 1, 0);
        $this->Cell(30, 7, '', 1, 0);
        $this->Cell(30, 7, '', 1, 1);
        
        // Add: IGST row
        $this->SetX(5);
        $this->Cell(30, 7, '', 1, 0);
        $this->Cell(40, 7, '', 1, 0);
        $this->Cell(35, 7, '', 1, 0);
        $this->Cell(35, 7, '', 1, 0);
        $this->SetFont('Arial','B',9);
        $this->Cell(30, 7, 'Add: IGST', 1, 0, 'L');
        $this->SetFont('Arial','',9);
        $this->Cell(30, 7, money($totalIgst), 1, 1, 'R');
        
        // Rounded Off row
        $roundOff = $this->totals['grand_total'] - ($this->totals['subtotal'] + $totalCgst + $totalSgst + $totalIgst);
        $this->SetX(5);
        $this->Cell(30, 7, '', 1, 0);
        $this->Cell(40, 7, '', 1, 0);
        $this->Cell(35, 7, '', 1, 0);
        $this->Cell(35, 7, '', 1, 0);
        $this->SetFont('Arial','B',9);
        $this->Cell(30, 7, 'Rounded Off', 1, 0, 'L');
        $this->SetFont('Arial','',9);
        $this->Cell(30, 7, money($roundOff), 1, 1, 'R');
        
        // Eighth row: Final totals
        $this->SetFont('Arial','B',9);
        $this->SetX(5);
        $this->Cell(30, 8, 'Total in INR', 1, 0, 'C');
        $this->Cell(40, 8, money($this->totals['subtotal']), 1, 0, 'R');
        $this->Cell(35, 8, money($totalCgst), 1, 0, 'R');
        $this->Cell(35, 8, money($totalSgst), 1, 0, 'R');
        $this->Cell(30, 8, 'Total Amount', 1, 0, 'C');
        $this->Cell(30, 8, money($this->totals['grand_total']), 1, 1, 'R');
        
        $this->SetY($this->GetY());
    }
    
    function BankDetailsSection()
    {
        if ($this->GetY() > 250) {
            $this->AddPage();
        }
        
        $startY = $this->GetY();
        
        // Title
        $this->SetFont('Arial','B',10);
        $this->SetX(4);
        $this->Cell(200, 8, 'Bank Details', 0, 1, 'C');
        
        $this->SetFont('Arial','',9);
        
        $this->SetX(5);
        
        // -------- ROW 1 --------
        // Name
        $this->SetFont('Arial','B',9);
        $this->Cell(25, 6, 'Name', 0, 0);
        $this->SetFont('Arial','',9);
        $bankName = !empty($this->account['account_name']) ? $this->account['account_name'] : $this->company['name'];
        $this->Cell(75, 6, ': ' . pdf_text_simple($bankName), 0, 0);
        
        // Bank
        $this->SetFont('Arial','B',9);
        $this->Cell(20, 6, 'Bank', 0, 0);
        $this->SetFont('Arial','',9);
        $this->Cell(30, 6, ': ' . pdf_text_simple($this->account['bank_name'] ?? ''), 0, 0);
        
        // Branch
        $this->SetFont('Arial','B',9);
        $this->Cell(20, 6, 'Branch', 0, 0);
        $this->SetFont('Arial','',9);
        $this->Cell(30, 6, ': ' . pdf_text_simple($this->account['branch'] ?? ''), 0, 1);
        
        // -------- ROW 2 --------
        $this->SetX(5);
        
        // A/c No.
        $this->SetFont('Arial','B',9);
        $this->Cell(25, 6, 'A/c No.', 0, 0);
        $this->SetFont('Arial','',9);
        $this->Cell(75, 6, ': ' . pdf_text_simple($this->account['account_number'] ?? ''), 0, 0);
        
        // IFSC Code
        $this->SetFont('Arial','B',9);
        $this->Cell(20, 6, 'IFSC Code', 0, 0);
        $this->SetFont('Arial','',9);
        $this->Cell(30, 6, ': ' . pdf_text_simple($this->account['ifsc'] ?? ''), 0, 0);
        
        // Account Type
        $this->SetFont('Arial','B',9);
        $this->Cell(20, 6, 'Account', 0, 0);
        $this->SetFont('Arial','',9);
        $this->Cell(30, 6, ': Current', 0, 1);
        
        $endY = $this->GetY();
        
        // Border
        $this->Rect(5, $startY, 200, ($endY - $startY));
    }
    
    function FinalFooterSection()
    {
        $startY = $this->GetY();
        $boxHeight = 30;
        
        // Outer border
        $this->Rect(5, $startY, 200, $boxHeight);
        
        // Columns
        $col1 = 50;
        $col2 = 90;
        $col3 = 60;
        
        // Vertical lines
        $this->Line(5 + $col1, $startY, 5 + $col1, $startY + $boxHeight);
        $this->Line(5 + $col1 + $col2, $startY, 5 + $col1 + $col2, $startY + $boxHeight);
        
        // =========================
        // COLUMN 1 → RECEIVER
        // =========================
        $this->SetFont('Arial','',8);
        $this->SetXY(5, $startY + 22);
        $this->Cell($col1, 5, 'Receivers Signature', 0, 0, 'C');
        
        // =========================
        // COLUMN 2 → TERMS
        // =========================
        $centerX = 5 + $col1;
        
        $this->SetFont('Arial','B',9);
        $this->SetXY($centerX, $startY + 2);
        $this->Cell($col2, 4, 'TERMS AND CONDITIONS', 0, 0, 'C');
        
        $this->SetFont('Arial','',8);
        $this->SetXY($centerX, $startY + 8);
        $this->Cell($col2, 4, 'PLEASE SEE REVERSE SIDE FOR DETAILS', 0, 0, 'C');
        
        $this->SetXY($centerX, $startY + 13);
        $this->Cell($col2, 4, 'ALL DISPUTES SUBJECT TO CHENNAI JURISDICTION ONLY', 0, 0, 'C');
        
        $this->SetTextColor(0, 128, 0);
        if (!empty($this->company['website'])) {
            $this->SetXY($centerX, $startY + 18);
            $this->Cell($col2, 4, pdf_text_simple($this->company['website']), 0, 0, 'C');
        }
        $this->SetTextColor(0, 0, 0);
        
        // =========================
        // COLUMN 3 → COMPANY
        // =========================
        $rightX = 5 + $col1 + $col2;
        
        $this->SetXY($rightX, $startY + 6);
        $this->Cell($col3, 5, 'For ' . pdf_text_simple($this->company['name']), 0, 0, 'C');
        
        $this->SetXY($rightX, $startY + 22);
        $this->Cell($col3, 5, 'Authorised Signatory', 0, 0, 'C');
    }
    
    // Add custom footer to close the outer border properly
    function Footer()
    {
        // The outer border is already drawn in Header
        if ($this->PageNo() > 1) {
            $this->SetY(-15);
            $this->SetFont('Arial','I',8);
            $this->Cell(0, 10, 'Page ' . $this->PageNo(), 0, 0, 'C');
        }
    }
}

// ========== Create PDF ==========
$pdf = new InvoicePDF();
$pdf->AliasNbPages();

// Set company info
$pdf->company = [
    'name'    => $company_name,
    'address' => $company_address,
    'gstin'   => $company_gstin,
    'phone'   => $company_phone,
    'email'   => $settings['company_email'] ?? '',
    'website' => $settings['company_website'] ?? '',
    'logo'    => !empty($settings['logo_path']) ? $settings['logo_path'] : ''
];

// Set invoice info
$pdf->invoice = [
    'number'          => $invoice['invoice_number'],
    'date'            => $invoice_date,
    'payment'         => $payment_method,
    'status'          => $payment_status,
    'printed_on'      => date('d-m-Y H:i:s'),
    'place_of_supply' => $place_of_supply,
    'is_tax_invoice'  => $is_tax_invoice,
    'irn_no'          => $invoice['irn_no'] ?? '',
    'ack_no'          => $invoice['ack_no'] ?? '',
    'ack_date'        => $invoice['ack_date'] ?? '',
    'destination'     => $shipping_address ? substr($shipping_address, 0, 50) : 'Chennai',
    'payment_terms'   => 'Immediate',
    'due_date'        => $invoice_date
];

// Set customer info
$pdf->customer = [
    'name'    => $customer_name,
    'phone'   => $customer_phone,
    'gstin'   => $customer_gstin,
    'address' => $customer_address
];

// Set shipping info
$pdf->shipping = [
    'name'           => $shipping_name,
    'contact'        => $shipping_contact,
    'gstin'          => $shipping_gstin,
    'address'        => $shipping_address,
    'vehicle_number' => $shipping_vehicle_number,
    'charges'        => $shipping_charges
];

// Set totals
$pdf->totals = [
    'subtotal'        => $subtotal,
    'overall_discount'=> $overall_discount,
    'discount'        => $total_discount,
    'taxable'         => $total_taxable,
    'cgst'            => $total_cgst,
    'sgst'            => $total_sgst,
    'igst'            => $total_igst,
    'grand_total'     => $grand_total
];

// Set taxable by rate for totals box
$pdf->taxable_by_rate = $taxable_by_rate;

// Set account details from first bank account
if (!empty($bank_accounts)) {
    $bank = $bank_accounts[0];
    $pdf->account = [
        'account_name'   => $bank['account_holder_name'] ?? '',
        'bank_name'      => $bank['bank_name'],
        'account_number' => $bank['account_number'],
        'ifsc'           => $bank['ifsc_code'] ?? '',
        'branch'         => $bank['branch_name'] ?? '',
        'upi'            => $bank['upi_id'] ?? ''
    ];
} else {
    $pdf->account = [];
}

// Set items
$pdf->items = $items;

// Set transport and waybill
$pdf->transport = !empty($shipping_vehicle_number) ? 'Vehicle: ' . $shipping_vehicle_number : 'By Road';
$pdf->waybill_no = $invoice['shipping_waybill_no'] ?? '';

// ========== Generate PDF ==========
$pdf->AddPage();
$pdf->TopSection();
$pdf->PartyBoxes();
$pdf->TableHeader();
$pdf->TableRows();
$pdf->FooterSection();
$pdf->TotalsBox();
$pdf->BankDetailsSection();
$pdf->FinalFooterSection();

// ========== Output PDF ==========
while (ob_get_level()) ob_end_clean();

$pdf_content = $pdf->Output('S', 'Invoice_' . $invoice['invoice_number'] . '.pdf');

// Set headers for PDF download
header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="Invoice_' . $invoice['invoice_number'] . '.pdf"');
header('Content-Length: ' . strlen($pdf_content));
header('Cache-Control: private, max-age=0, must-revalidate');
header('Pragma: public');

// Output PDF content
echo $pdf_content;

// Add JavaScript for auto-print
echo '<script type="text/javascript">
    window.onload = function() { window.print(); }
</script>';

exit;
?>