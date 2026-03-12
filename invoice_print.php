<?php
// invoice_print.php - Using FPDF with same UI as sale-invoice.php
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

// Fetch invoice with shop details
$stmt = $pdo->prepare("
    SELECT i.*,
           c.name as customer_name, c.phone as customer_phone, c.gstin as customer_gstin,
           c.address as customer_address,
           u.full_name as seller_name,
           s.shop_name, s.address as shop_address, s.phone as shop_phone, s.gstin as shop_gstin,
           s.id as shop_id
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

foreach ($items as $item) {
    $line_total = $item['unit_price'] * $item['quantity'];
    $discount = $item['discount_amount'] ?? 0;
    $net = $line_total - $discount;

    $subtotal += $line_total;
    $total_discount += $discount;
    $total_profit += $item['profit'] ?? 0;

    $total_taxable += $item['taxable_value'] ?? 0;
    $total_cgst += $item['cgst_amount'] ?? 0;
    $total_sgst += $item['sgst_amount'] ?? 0;
    $total_igst += $item['igst_amount'] ?? 0;
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

// QR Code amount (if UPI payment)
$qr_amount = 0;
if ($payment_method === 'UPI' || strpos($payment_method, 'UPI') !== false) {
    $qr_amount = $grand_total;
}
$show_qr = ($qr_amount > 0);

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

// ========== PDF Class (same as sale-invoice.php) ==========
class InvoicePDF extends FPDF {
    public $company = [];
    public $invoice = [];
    public $customer = [];
    public $totals = [];
    public $account = [];
    public $col_w = [];
    public $col_headers = [];
    public $lm = 8; public $rm = 8; public $tm = 8; public $bm = 15;
    public $verified_by = '-';
    
    // Column width proportions
    private $col_props = [0.05, 0.27, 0.08, 0.09, 0.11, 0.06, 0.10, 0.10, 0.14];
    
    function Header() {
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
        $this->SetFont('Arial','B',14);
        $this->Cell(100, 7, pdf_text_simple('TAX INVOICE'), 0, 0, 'L');
        
        // Page number on right
        $this->SetFont('Arial','',9);
        $this->Cell(0, 7, pdf_text_simple('Page '.$this->PageNo().'/{nb}'), 0, 1, 'L');
        
        // Now move to next line for company info
        $this->SetX($this->lm);
        
        // If logo exists, we need to align text properly
        $logo_offset = (!empty($this->company['logo']) && file_exists($this->company['logo'])) ? 17 : 0;
        
        // Company name below logo area - moved right by 5mm
        $this->SetFont('Arial','B',12);
        $this->SetX($this->lm + $logo_offset + 0.1); // Add 5mm offset
        $this->Cell(0, 6, pdf_text_simple($this->company['name']), 0, 1, 'L');
        
        $this->SetFont('Arial','',9);
        $this->SetX($this->lm + $logo_offset);
        $this->MultiCell(0, 4.2, pdf_text_simple($this->company['address']), 0, 'L');
        
        $this->SetX($this->lm + $logo_offset);
        if (!empty($this->company['phone'])) {
            $this->Cell(0, 5, pdf_text_simple('Phone: '.$this->company['phone']), 0, 1, 'L');
        }
        
        $company_info_height = $this->GetY();
        
        // Invoice info (right side) - aligned with company info
        $right_start_y = $this->tm + 1; // Start a bit lower than top
        
        $this->SetXY($pw - $this->rm - 80, $right_start_y);
        $this->SetFont('Arial','',9);
        $this->Cell(80, 5, pdf_text_simple('Invoice No : '.$this->invoice['number']), 0, 1, 'R');
        $this->SetX($pw - $this->rm - 80);
        $this->Cell(80, 5, pdf_text_simple('Invoice Date : '.$this->invoice['date']), 0, 1, 'R');
        $this->SetX($pw - $this->rm - 80);
        $this->Cell(80, 5, pdf_text_simple('Payment Mode : '.$this->invoice['payment']), 0, 1, 'R');
        $this->SetX($pw - $this->rm - 80);
        $this->Cell(80, 5, pdf_text_simple('Status : '.$this->invoice['status']), 0, 1, 'R');
        $this->SetX($pw - $this->rm - 80);
        $this->Cell(80, 5, pdf_text_simple('Printed On : '.$this->invoice['printed_on']), 0, 1, 'R');
        
        $right_info_height = $this->GetY();
        
        // Take the maximum height between left and right columns
        $max_y = max($company_info_height, $right_info_height);
        $this->SetY($max_y + 2);
        
        // GSTIN and Place of Supply
        $this->SetFont('Arial','',9);
        $this->SetX($this->lm);
        $this->Cell(120, 5, pdf_text_simple('GSTIN : '.$this->company['gstin']), 0, 0, 'L');
        $this->Cell(0, 5, pdf_text_simple('Place of Supply : '.$this->invoice['place_of_supply']), 0, 1, 'R');
        
        $this->Ln(2);
        
        // Bill To and Ship To
        $colW = round($printable / 2);
        $this->SetFont('Arial','B',10);
        $this->SetX($this->lm);
        $this->Cell($colW, 6, pdf_text_simple('Bill To'), 0, 0, 'L');
        $this->Cell($colW, 6, pdf_text_simple('Ship To'), 0, 1, 'L');
        
        $this->SetFont('Arial','',9);
        $bill_info = [
            'Name : '.$this->customer['name'],
            'Mobile : '.$this->customer['phone'],
            'GSTIN : '.$this->customer['gstin'],
            'Address : '.$this->customer['address'],
        ];
        
        foreach($bill_info as $line) {
            $this->SetX($this->lm);
            $this->Cell($colW, 5, pdf_text_simple($line), 0, 0, 'L');
            $this->Cell($colW, 5, pdf_text_simple($line), 0, 1, 'L');
        }
        
        $this->Ln(2);
        
        // Table header
        $this->TableHeader();
    }
    
    function TableHeader() {
        $this->SetFont('Arial','B',8);
        foreach($this->col_headers as $i => $h) {
            $this->Cell($this->col_w[$i], 8, pdf_text_simple($h), 1, 0, 'C');
        }
        $this->Ln();
        $this->SetFont('Arial','',6.8);
    }
    
    function Footer() {
        $this->SetY($this->GetPageHeight() - 20);
        $this->SetFont('Arial','I',8);
        $this->Cell(0,4.5, pdf_text_simple('This is a computer generated invoice.'), 0, 1, 'L');
        
        // Verified by (if available)
        if (!empty($this->verified_by)) {
            $this->Cell(0,4.5, pdf_text_simple('Verified By : ' . $this->verified_by), 0, 1, 'L');
        }
        
        $this->Cell(0,4.5, pdf_text_simple('Printed On - '.$this->invoice['printed_on']), 0, 0, 'R');
    }
    
    function BoxText($x, $y, $w, $h, $txt, $align='L', $vAlign='T', $padX=1.5, $padY=1.2, $lineH=5.4) {
        $this->Rect($x, $y, $w, $h);
        $txt = trim((string)$txt);
        if ($txt === '') return;
        
        $textW = max(1, $w - 2*$padX);
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
    
    function NbLines($w, $txt) {
        $txt = (string)$txt;
        $txt = str_replace("\r", '', $txt);
        $cw = &$this->CurrentFont['cw'];
        if ($w == 0) $w = $this->w - $this->rMargin - $this->x;
        $wmax = ($w - 2*$this->cMargin) * 1000 / $this->FontSize;
        $s = $txt;
        $nb = strlen($s);
        if ($nb > 0 && $s[$nb-1] == "\n") $nb--;
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
            if ($c == ' ') $sep = $i;
            $l += $cw[$c] ?? 0;
            if ($l > $wmax) {
                if ($sep == -1) {
                    if ($i == $j) $i++;
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
    
    function AddItemRow($x, $y, $sn, $item, $cellH) {
    // Calculate item details
    $unit_price = $item['unit_price'] ?? 0; // This is GST inclusive price (1239)
    $quantity = $item['quantity'] ?? 0;
    $discount_amount = $item['discount_amount'] ?? 0;
    $discount_rate = $item['discount_rate'] ?? 0;
    
    $cgst_rate = $item['cgst_rate'] ?? 0;
    $sgst_rate = $item['sgst_rate'] ?? 0;
    $igst_rate = $item['igst_rate'] ?? 0;
    
    $total_gst_rate = $cgst_rate + $sgst_rate + $igst_rate; // 18%
    
    // Calculate base price without GST
    // If rate is 1239 with 18% GST, then base price = 1239 / (1 + 18/100) = 1050
    $gst_multiplier = 1 + ($total_gst_rate / 100);
    $base_unit_price = $unit_price / $gst_multiplier; // This gives 1050
    
    // Calculate line totals
    $line_total_before_discount = $unit_price * $quantity; // Total with GST before discount
    $line_total_before_discount_base = $base_unit_price * $quantity; // Total without GST before discount
    
    $discount_amount = $item['discount_amount'] ?? 0; // Discount amount (if any)
    
    // After discount, we need to calculate how discount affects base and GST
    if ($discount_amount > 0) {
        // Discount is applied on GST inclusive price
        $line_total_after_discount = $line_total_before_discount - $discount_amount;
        
        // Calculate the discount proportion
        $discount_ratio = $discount_amount / $line_total_before_discount;
        
        // Apply same discount ratio to base amount
        $base_after_discount = $line_total_before_discount_base * (1 - $discount_ratio);
        
        // GST amount after discount
        $total_gst_amount = $line_total_after_discount - $base_after_discount;
    } else {
        $line_total_after_discount = $line_total_before_discount;
        $base_after_discount = $line_total_before_discount_base;
        $total_gst_amount = $line_total_after_discount - $base_after_discount;
    }
    
    $unit = !empty($item['product_unit']) ? $item['product_unit'] : (empty($item['unit']) ? 'PCS' : $item['unit']);
    
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
    $this->Cell($this->col_w[0], $cellH, (string)$sn, 0, 0, 'C');
    
    // Item Description
    $item_text = (!empty($item['product_code']) ? $item['product_code'] . " - " : "") . $item['product_name'];
    $this->BoxText($x1, $y, $this->col_w[1], $cellH, $item_text, 'L', 'M', 1.2, 1.0, 5.4);
    
    // HSN
    $this->Rect($x2, $y, $this->col_w[2], $cellH);
    $this->SetXY($x2, $y);
    $this->Cell($this->col_w[2], $cellH, pdf_text_simple($item['hsn_code'] ?? ''), 0, 0, 'C');
    
    // GST(%) 
    $gst_text = $total_gst_rate > 0 ? number_format($total_gst_rate, 1) . '%' : '0%';
    $this->BoxText($x3, $y, $this->col_w[3], $cellH, $gst_text, 'C', 'M', 1.0, 1.0, 5.4);
    
    // Rate (Without GST) - THIS IS THE KEY CHANGE
    $rate_text = 'Rs. ' . money($base_unit_price); // Show 1050 instead of 1239
    $this->Rect($x4, $y, $this->col_w[4], $cellH);
    $this->SetXY($x4, $y);
    $this->Cell($this->col_w[4], $cellH, pdf_text_simple($rate_text), 0, 0, 'R');
    
    // Qty
    $qty_text = format_quantity($quantity) . ' ' . $unit;
    $this->Rect($x5, $y, $this->col_w[5], $cellH);
    $this->SetXY($x5, $y);
    $this->Cell($this->col_w[5], $cellH, pdf_text_simple($qty_text), 0, 0, 'C');
    
    // Discount 
    if ($discount_amount > 0) {
        $disc_text = 'Rs. ' . money($discount_amount) . "\n(" . $discount_rate . "%)";
    } else {
        $disc_text = '-';
    }
    $this->BoxText($x6, $y, $this->col_w[6], $cellH, $disc_text, 'C', 'M', 1.0, 1.0, 5.4);
    
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
}
    
    function DrawAmountSummary() {
    $t = $this->totals;
    
    $this->SetFont('Arial','',9);
    $leftX  = $this->lm;
    $rightX = $this->GetPageWidth() - $this->rm - 80;
    $startY = $this->GetY();
    
    $y = $startY;
    
    // Right side summary
    $this->SetFont('Arial','',9);
    $this->SetXY($rightX, $y);
    
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
    
    // Grand Total with bold font
    $this->SetFont('Arial','B',11);
    $this->SetX($rightX);
    $this->Cell(40, 8, pdf_text_simple('GRAND TOTAL'), 0, 0, 'L');
    $this->Cell(40, 8, pdf_text_simple('Rs. ' . money($t['grand_total'])), 0, 1, 'R');
    
    $endY = max($y, $startY + 9);
    $this->SetY($endY + 2);
}
    function DrawAccountDetails() {
        $a = $this->account;
        
        $hasAny = false;
        foreach (['account_name','bank_name','account_number','ifsc','branch','upi'] as $k) {
            if (!empty($a[$k])) { $hasAny = true; break; }
        }
        if (!$hasAny) return;
        
        // Only add account details if there's space on current page
        if ($this->GetY() + 28 > ($this->GetPageHeight() - $this->bm)) {
            return; // Don't add new page, just skip
        }
        
        $this->SetFont('Arial','B',9);
        $this->Cell(0,6, pdf_text_simple('Account Details'), 0, 1, 'L');
        
        $this->SetFont('Arial','',8);
        $lines = [];
        if (!empty($a['account_name']))   $lines[] = 'A/C Name : '.$a['account_name'];
        if (!empty($a['bank_name']))      $lines[] = 'Bank : '.$a['bank_name'];
        if (!empty($a['account_number'])) $lines[] = 'A/C No : '.$a['account_number'];
        if (!empty($a['ifsc']))           $lines[] = 'IFSC : '.$a['ifsc'];
        if (!empty($a['branch']))         $lines[] = 'Branch : '.$a['branch'];
        if (!empty($a['upi']))            $lines[] = 'UPI : '.$a['upi'];
        
        $x = $this->lm;
        $w = $this->GetPageWidth() - ($this->lm + $this->rm);
        $yStart = $this->GetY();
        $h = max(18, count($lines) * 4.5 + 4);
        
        // Check if we have space for account details
        if ($yStart + $h > ($this->GetPageHeight() - $this->bm)) {
            return; // Skip if no space
        }
        
        $this->Rect($x, $yStart, $w, $h);
        $this->SetXY($x + 2, $yStart + 2);
        
        foreach ($lines as $ln) {
            $this->Cell($w - 4, 4.5, pdf_text_simple($ln), 0, 1, 'L');
        }
        $this->Ln(2);
    }
    
    // Helper method to initialize column widths
    function initColumnWidths() {
        $pageWidth = $this->GetPageWidth();
        $printable = $pageWidth - ($this->lm + $this->rm);
        
        // Modified headers
        $this->col_headers = ['SN', 'Item Description', 'HSN', 'GST(%)', 'Rate', 'Qty', 'Disc', 'GST Amt', 'Total'];
        
        // Calculate column widths based on proportions
        $this->col_w = [];
        foreach ($this->col_props as $p) {
            $this->col_w[] = round($printable * $p);
        }
        
        // Adjust if total doesn't match printable width
        $totalWidth = array_sum($this->col_w);
        if ($totalWidth != $printable) {
            $this->col_w[1] += ($printable - $totalWidth);
        }
    }
}

// ========== Create PDF ==========
$pdf = new InvoicePDF('P','mm','A4');
$pdf->AliasNbPages();

$pdf->lm = 8; $pdf->rm = 8; $pdf->tm = 8; $pdf->bm = 15;
$pdf->SetMargins($pdf->lm, $pdf->tm, $pdf->rm);
$pdf->SetAutoPageBreak(true, $pdf->bm);

// Initialize column widths
$pdf->initColumnWidths();

// Set company info - using data from invoice_settings table
$pdf->company = [
    'name'    => $company_name,
    'address' => $company_address,
    'gstin'   => $company_gstin,
    'phone'   => $company_phone,
    'email'   => $settings['company_email'] ?? '',
    'logo'    => !empty($settings['logo_path']) ? $settings['logo_path'] : ''
];

// Set invoice info
$pdf->invoice = [
    'number'          => $invoice['invoice_number'],
    'date'            => $invoice_date,
    'payment'         => $payment_method,
    'status'          => $payment_status,
    'printed_on'      => date('d-m-Y H:i:s'),
    'place_of_supply' => $place_of_supply
];

// Set customer info
$pdf->customer = [
    'name'    => $customer_name,
    'phone'   => $customer_phone,
    'gstin'   => $customer_gstin,
    'address' => $customer_address
];

// Set totals - INCLUDING OVERALL DISCOUNT
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

// Set verified by (seller)
$pdf->verified_by = $invoice['seller_name'];

// ========== Generate PDF ==========
$pdf->AddPage();
$pdf->SetFont('Arial','',6.8);
$lineH = 5.6;
$minLines = 1;
$sn = 1;

// Add items only if we have items
if (!empty($items)) {
    foreach ($items as $item) {
        $name = $item['product_name'] ?? '';
        $code = $item['product_code'] ?? '';
        $itemText = (!empty($code) ? $code . " - " : "") . $name;
        
        // Calculate required height
        $itemLines = max($minLines, $pdf->NbLines(max(1, $pdf->col_w[1] - 3), $itemText));
        $maxLines = max($itemLines, 1);
        $cellH = ($maxLines * $lineH) + 2;
        
        // Check if need new page - with stricter conditions to prevent extra pages
        $currentY = $pdf->GetY();
        $pageHeight = $pdf->GetPageHeight();
        $bottomMargin = $pdf->bm;
        
        // Calculate if we have enough space for this item AND the summary section
        if ($currentY + $cellH + 80 > ($pageHeight - $bottomMargin)) {
            $pdf->AddPage();
            $pdf->SetFont('Arial','',6.8);
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
        $pdf->SetFont('Arial','B',9);
        $pdf->Cell(0,6, pdf_text_simple('Notes:'), 0, 1, 'L');
        $pdf->SetFont('Arial','',8);
        $pdf->MultiCell(0,4, pdf_text_simple($notes), 0, 'L');
    }
}

// ========== Terms and Conditions ==========
$terms = $settings['invoice_terms'] ?? '';
if ($terms !== '') {
    // Check if we have space for terms
    if ($pdf->GetY() + 30 < ($pdf->GetPageHeight() - $pdf->bm)) {
        $pdf->SetFont('Arial','B',9);
        $pdf->Cell(0,6, pdf_text_simple('Terms & Conditions:'), 0, 1, 'L');
        $pdf->SetFont('Arial','',8);
        $pdf->MultiCell(0,4, pdf_text_simple($terms), 0, 'L');
    }
}

// ========== Authorized Signatory ==========
// Make sure we have space for signature
if ($pdf->GetY() + 25 < ($pdf->GetPageHeight() - $pdf->bm)) {
    $pdf->Ln(10);
    $pdf->SetFont('Arial','B',9);
    $pdf->Cell(0,6, pdf_text_simple('For ' . $company_name), 0, 1, 'R');
    $pdf->SetFont('Arial','',9);
    $pdf->Cell(0,15, pdf_text_simple('Authorized Signatory'), 0, 1, 'R');
}

// ========== Footer note ==========
if (!empty($settings['invoice_footer'])) {
    // Only add footer if we have space
    if ($pdf->GetY() + 10 < ($pdf->GetPageHeight() - $pdf->bm)) {
        $pdf->SetFont('Arial','I',8);
        $pdf->Cell(0,10, pdf_text_simple($settings['invoice_footer']), 0, 1, 'C');
    }
}

// ========== Output PDF with auto-print JavaScript using HTML approach ==========
while (ob_get_level()) ob_end_clean();

// Instead of using IncludeJS (which doesn't exist), we'll output the PDF with a small HTML wrapper that auto-prints
$pdf_content = $pdf->Output('S', 'Invoice_' . $invoice['invoice_number'] . '.pdf');

// Set headers for PDF download
header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="Invoice_' . $invoice['invoice_number'] . '.pdf"');
header('Content-Length: ' . strlen($pdf_content));
header('Cache-Control: private, max-age=0, must-revalidate');
header('Pragma: public');

// Output PDF content
echo $pdf_content;

// Add JavaScript for auto-print using output buffering
echo '<script type="text/javascript">
    window.onload = function() { window.print(); }
</script>';

exit;