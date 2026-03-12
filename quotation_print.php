<?php
// quotation_print.php
session_start();
require_once 'config/database.php';

// Check authentication
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

$business_id = $_SESSION['business_id'] ?? 1;

// Check if we have quotation_id
if (isset($_GET['id'])) {
    $quotation_id = (int)$_GET['id'];
    if ($quotation_id <= 0) {
        die("Invalid quotation ID");
    }
} else {
    die("Quotation ID is required");
}

// Fetch quotation with shop details - MODIFIED: Removed JOIN with customers and users
$stmt = $pdo->prepare("
    SELECT q.*,
           s.shop_name, s.address as shop_address, s.phone as shop_phone, s.gstin as shop_gstin,
           s.id as shop_id
    FROM quotations q
    LEFT JOIN shops s ON q.shop_id = s.id
    WHERE q.id = ? AND q.business_id = ?
");
$stmt->execute([$quotation_id, $business_id]);
$quotation = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$quotation) {
    die("Quotation not found or access denied");
}

// Get shop_id from quotation
$shop_id = $quotation['shop_id'] ?? null;

// Fetch quotation settings for this shop/business
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
        'invoice_terms' => "1. This quotation is valid for 30 days from the date of issue.\n2. Prices are subject to change without prior notice.\n3. Delivery subject to stock availability.\n4. Taxes extra as applicable.\n5. Payment terms: 50% advance, balance before delivery.",
        'invoice_footer' => 'Thank you for your business! We look forward to serving you.',
        'invoice_prefix' => 'QT'
    ];
}

// Get company info from settings
$company_name = $settings['company_name'] ?? 'Ecommer';
$company_address = $settings['company_address'] ?? 'Sogathur X Road, Dharmapuri';
$company_phone = $settings['company_phone'] ?? '9003552650';
$company_gstin = $settings['gst_number'] ?? ($quotation['shop_gstin'] ?? '');

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

// ========== Fetch quotation items ==========
$items_stmt = $pdo->prepare("
    SELECT qi.*, 
           p.product_name, p.product_code, p.hsn_code, p.mrp, p.gst_id,
           g.cgst_rate, g.sgst_rate, g.igst_rate
           
    FROM quotation_items qi
    JOIN products p ON qi.product_id = p.id
    LEFT JOIN gst_rates g ON p.gst_id = g.id
    WHERE qi.quotation_id = ?
    ORDER BY qi.id
");
$items_stmt->execute([$quotation_id]);
$items = $items_stmt->fetchAll();

// ========== Calculate totals ==========
$subtotal = $total_discount = 0;
$total_taxable = $total_cgst = $total_sgst = $total_igst = 0;

foreach ($items as $item) {
    $line_total = $item['unit_price'] * $item['quantity'];
    $discount = $item['discount_amount'] ?? 0;
    $net = $line_total - $discount;
    
    $subtotal += $line_total;
    $total_discount += $discount;
    
    // Calculate tax
    $taxable = $net;
    $cgst_rate = $item['cgst_rate'] ?? 0;
    $sgst_rate = $item['sgst_rate'] ?? 0;
    $igst_rate = $item['igst_rate'] ?? 0;
    
    $cgst_amount = $taxable * ($cgst_rate / 100);
    $sgst_amount = $taxable * ($sgst_rate / 100);
    $igst_amount = $taxable * ($igst_rate / 100);
    
    $total_taxable += $taxable;
    $total_cgst += $cgst_amount;
    $total_sgst += $sgst_amount;
    $total_igst += $igst_amount;
}

$grand_total = $quotation['grand_total'];
$is_tax_quotation = !empty($quotation['customer_gstin']) || ($total_cgst + $total_sgst + $total_igst) > 0;
$quotation_date = date('d-m-Y', strtotime($quotation['quotation_date']));
$valid_until = date('d-m-Y', strtotime($quotation['valid_until']));

// Customer details - MODIFIED: Get directly from quotation table
$customer_name = $quotation['customer_name'] ?? 'Customer';
$customer_phone = $quotation['customer_phone'] ?? '';
$customer_email = $quotation['customer_email'] ?? '';
$customer_address = $quotation['customer_address'] ?? '';
$customer_gstin = $quotation['customer_gstin'] ?? '';

// Place of supply
$place_of_supply = 'Tamil Nadu (33)';

// Created by name - MODIFIED: Fetch from session or use default
$created_by_name = $_SESSION['full_name'] ?? 'Staff';

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

// ========== Quotation PDF Class ==========
class QuotationPDF extends FPDF {
    public $company = [];
    public $quotation = [];
    public $customer = [];
    public $totals = [];
    public $account = [];
    public $col_w = [];
    public $col_headers = [];
    public $lm = 8; public $rm = 8; public $tm = 8; public $bm = 15;
    public $verified_by = '-';
    
    // Column width proportions - similar to invoice
    private $col_props = [0.05, 0.27, 0.08, 0.09, 0.11, 0.06, 0.10, 0.10, 0.14];
    
    function Header() {
        $pw = $this->GetPageWidth();
        $printable = $pw - ($this->lm + $this->rm);
        
        $this->SetXY($this->lm, $this->tm);
        
        // Logo (if exists)
        if (!empty($this->company['logo']) && file_exists($this->company['logo'])) {
            $this->Image($this->company['logo'], $this->lm, $this->tm, 15, 15);
            $this->SetX($this->lm + 17);
        } else {
            $this->SetX($this->lm);
        }
        
        // Title - QUOTATION
        $this->SetFont('Arial','B',14);
        $this->Cell(100, 7, pdf_text_simple('QUOTATION'), 0, 0, 'L');
        
        // Page number on right
        $this->SetFont('Arial','',9);
        $this->Cell(0, 7, pdf_text_simple('Page '.$this->PageNo().'/{nb}'), 0, 1, 'L');
        
        // Company info
        $this->SetX($this->lm);
        $logo_offset = (!empty($this->company['logo']) && file_exists($this->company['logo'])) ? 17 : 0;
        
        // Company name
        $this->SetFont('Arial','B',12);
        $this->SetX($this->lm + $logo_offset + 0.1);
        $this->Cell(0, 6, pdf_text_simple($this->company['name']), 0, 1, 'L');
        
        $this->SetFont('Arial','',9);
        $this->SetX($this->lm + $logo_offset);
        $this->MultiCell(0, 4.2, pdf_text_simple($this->company['address']), 0, 'L');
        
        $this->SetX($this->lm + $logo_offset);
        if (!empty($this->company['phone'])) {
            $this->Cell(0, 5, pdf_text_simple('Phone: '.$this->company['phone']), 0, 1, 'L');
        }
        
        $company_info_height = $this->GetY();
        
        // Quotation info (right side)
        $right_start_y = $this->tm + 1;
        
        $this->SetXY($pw - $this->rm - 80, $right_start_y);
        $this->SetFont('Arial','',9);
        $this->Cell(80, 5, pdf_text_simple('Quotation No : '.$this->quotation['number']), 0, 1, 'R');
        $this->SetX($pw - $this->rm - 80);
        $this->Cell(80, 5, pdf_text_simple('Quotation Date : '.$this->quotation['date']), 0, 1, 'R');
        $this->SetX($pw - $this->rm - 80);
        $this->Cell(80, 5, pdf_text_simple('Valid Until : '.$this->quotation['valid_until']), 0, 1, 'R');
        $this->SetX($pw - $this->rm - 80);
        $this->Cell(80, 5, pdf_text_simple('Status : '.$this->quotation['status']), 0, 1, 'R');
        $this->SetX($pw - $this->rm - 80);
        $this->Cell(80, 5, pdf_text_simple('Printed On : '.$this->quotation['printed_on']), 0, 1, 'R');
        
        $right_info_height = $this->GetY();
        
        // Take the maximum height
        $max_y = max($company_info_height, $right_info_height);
        $this->SetY($max_y + 2);
        
        // GSTIN and Place of Supply
        $this->SetFont('Arial','',9);
        $this->SetX($this->lm);
        $this->Cell(120, 5, pdf_text_simple('GSTIN : '.$this->company['gstin']), 0, 0, 'L');
        $this->Cell(0, 5, pdf_text_simple('Place of Supply : '.$this->quotation['place_of_supply']), 0, 1, 'R');
        
        $this->Ln(2);
        
        // Quotation To (Bill To)
        $this->SetFont('Arial','B',10);
        $this->SetX($this->lm);
        $this->Cell(0, 6, pdf_text_simple('Quotation To:'), 0, 1, 'L');
        
        $this->SetFont('Arial','',9);
        $bill_info = [];
        if (!empty($this->customer['name'])) {
            $bill_info[] = 'Name : '.$this->customer['name'];
        }
        if (!empty($this->customer['phone'])) {
            $bill_info[] = 'Mobile : '.$this->customer['phone'];
        }
        if (!empty($this->customer['email'])) {
            $bill_info[] = 'Email : '.$this->customer['email'];
        }
        if (!empty($this->customer['gstin'])) {
            $bill_info[] = 'GSTIN : '.$this->customer['gstin'];
        }
        if (!empty($this->customer['address'])) {
            $bill_info[] = 'Address : '.$this->customer['address'];
        }
        
        foreach($bill_info as $line) {
            $this->SetX($this->lm);
            $this->Cell(0, 5, pdf_text_simple($line), 0, 1, 'L');
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
        $this->Cell(0,4.5, pdf_text_simple('This is a computer generated quotation.'), 0, 1, 'L');
        
        // Verified by (if available)
        if (!empty($this->verified_by)) {
            $this->Cell(0,4.5, pdf_text_simple('Prepared By : ' . $this->verified_by), 0, 1, 'L');
        }
        
        $this->Cell(0,4.5, pdf_text_simple('Printed On - '.$this->quotation['printed_on']), 0, 0, 'R');
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
        $unit_price = $item['unit_price'] ?? 0;
        $quantity = $item['quantity'] ?? 0;
        $discount_amount = $item['discount_amount'] ?? 0;
        $discount_rate = $item['discount_rate'] ?? 0;
        
        $cgst_rate = $item['cgst_rate'] ?? 0;
        $sgst_rate = $item['sgst_rate'] ?? 0;
        $igst_rate = $item['igst_rate'] ?? 0;
        
        $total_gst_rate = $cgst_rate + $sgst_rate + $igst_rate;
        $line_total_before_discount = $unit_price * $quantity;
        $line_total_after_discount = $line_total_before_discount - $discount_amount;
        
        // For quotation, calculate tax
        $taxable_value = $line_total_after_discount;
        $cgst_amount = $taxable_value * ($cgst_rate / 100);
        $sgst_amount = $taxable_value * ($sgst_rate / 100);
        $igst_amount = $taxable_value * ($igst_rate / 100);
        $total_gst_amount = $cgst_amount + $sgst_amount + $igst_amount;
        $total_with_gst = $taxable_value + $total_gst_amount;
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
        
        // Rate
        $rate_text = 'Rs. ' . money($unit_price);
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
        
        // GST Amt
        if ($total_gst_amount > 0) {
            $gst_amt_text = 'Rs. ' . money($total_gst_amount);
        } else {
            $gst_amt_text = '-';
        }
        $this->Rect($x7, $y, $this->col_w[7], $cellH);
        $this->SetXY($x7, $y);
        $this->Cell($this->col_w[7], $cellH, pdf_text_simple($gst_amt_text), 0, 0, 'R');
        
        // Total
        $total_text = 'Rs. ' . money($total_with_gst);
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
        
        if ($t['discount'] > 0) {
            $this->SetXY($leftX, $y);
            $this->Cell(50, 6, pdf_text_simple('Discount'), 0, 0, 'L');
            $this->Cell(30, 6, pdf_text_simple('Rs. ' . money($t['discount'])), 0, 1, 'R');
            $y = $this->GetY();
        }
        
        if ($t['taxable'] > 0) {
            $this->SetXY($leftX, $y);
            $this->Cell(50, 6, pdf_text_simple('Taxable Amount'), 0, 0, 'L');
            $this->Cell(30, 6, pdf_text_simple('Rs. ' . money($t['taxable'])), 0, 1, 'R');
            $y = $this->GetY();
        }
        
        if ($t['cgst'] > 0) {
            $this->SetXY($leftX, $y);
            $this->Cell(50, 6, pdf_text_simple('CGST'), 0, 0, 'L');
            $this->Cell(30, 6, pdf_text_simple('Rs. ' . money($t['cgst'])), 0, 1, 'R');
            $y = $this->GetY();
        }
        
        if ($t['sgst'] > 0) {
            $this->SetXY($leftX, $y);
            $this->Cell(50, 6, pdf_text_simple('SGST'), 0, 0, 'L');
            $this->Cell(30, 6, pdf_text_simple('Rs. ' . money($t['sgst'])), 0, 1, 'R');
            $y = $this->GetY();
        }
        
        if ($t['igst'] > 0) {
            $this->SetXY($leftX, $y);
            $this->Cell(50, 6, pdf_text_simple('IGST'), 0, 0, 'L');
            $this->Cell(30, 6, pdf_text_simple('Rs. ' . money($t['igst'])), 0, 1, 'R');
            $y = $this->GetY();
        }
        
        if (($t['cgst'] + $t['sgst'] + $t['igst']) > 0) {
            $this->SetXY($leftX, $y);
            $this->Cell(50, 6, pdf_text_simple('Total GST'), 0, 0, 'L');
            $this->Cell(30, 6, pdf_text_simple('Rs. ' . money($t['cgst'] + $t['sgst'] + $t['igst'])), 0, 1, 'R');
            $y = $this->GetY();
        }
        
        $this->SetFont('Arial','B',11);
        $this->SetXY($rightX, $startY);
        $this->Cell(40, 8, pdf_text_simple('QUOTATION TOTAL'), 0, 0, 'L');
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
        
        // Only add account details if there's space
        if ($this->GetY() + 28 > ($this->GetPageHeight() - $this->bm)) {
            return;
        }
        
        $this->SetFont('Arial','B',9);
        $this->Cell(0,6, pdf_text_simple('Payment Details'), 0, 1, 'L');
        
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
        
        if ($yStart + $h > ($this->GetPageHeight() - $this->bm)) {
            return;
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
        
        // Column headers (same as invoice for consistency)
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
$pdf = new QuotationPDF('P','mm','A4');
$pdf->AliasNbPages();

$pdf->lm = 8; $pdf->rm = 8; $pdf->tm = 8; $pdf->bm = 15;
$pdf->SetMargins($pdf->lm, $pdf->tm, $pdf->rm);
$pdf->SetAutoPageBreak(true, $pdf->bm);

// Initialize column widths
$pdf->initColumnWidths();

// Set company info
$pdf->company = [
    'name'    => $company_name,
    'address' => $company_address,
    'gstin'   => $company_gstin,
    'phone'   => $company_phone,
    'email'   => $settings['company_email'] ?? '',
    'logo'    => !empty($settings['logo_path']) ? $settings['logo_path'] : ''
];

// Set quotation info
$pdf->quotation = [
    'number'          => $quotation['quotation_number'],
    'date'            => $quotation_date,
    'valid_until'     => $valid_until,
    'status'          => ucfirst($quotation['status']),
    'printed_on'      => date('d-m-Y H:i:s'),
    'place_of_supply' => $place_of_supply
];

// Set customer info - MODIFIED: Use direct quotation fields
$pdf->customer = [
    'name'    => $customer_name,
    'phone'   => $customer_phone,
    'email'   => $customer_email,
    'gstin'   => $customer_gstin,
    'address' => $customer_address
];

// Set totals
$pdf->totals = [
    'subtotal'    => $subtotal,
    'discount'    => $total_discount,
    'taxable'     => $total_taxable,
    'cgst'        => $total_cgst,
    'sgst'        => $total_sgst,
    'igst'        => $total_igst,
    'grand_total' => $grand_total
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

// Set prepared by (use session or default)
$pdf->verified_by = $created_by_name;

// ========== Generate PDF ==========
$pdf->AddPage();
$pdf->SetFont('Arial','',6.8);
$lineH = 5.6;
$minLines = 1;
$sn = 1;

// Add items
if (!empty($items)) {
    foreach ($items as $item) {
        $name = $item['product_name'] ?? '';
        $code = $item['product_code'] ?? '';
        $itemText = (!empty($code) ? $code . " - " : "") . $name;
        
        // Calculate required height
        $itemLines = max($minLines, $pdf->NbLines(max(1, $pdf->col_w[1] - 3), $itemText));
        $maxLines = max($itemLines, 1);
        $cellH = ($maxLines * $lineH) + 2;
        
        // Check if need new page
        $currentY = $pdf->GetY();
        $pageHeight = $pdf->GetPageHeight();
        $bottomMargin = $pdf->bm;
        
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
$notes = trim($quotation['notes'] ?? '');
if ($notes !== '') {
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
    if ($pdf->GetY() + 30 < ($pdf->GetPageHeight() - $pdf->bm)) {
        $pdf->SetFont('Arial','B',9);
        $pdf->Cell(0,6, pdf_text_simple('Terms & Conditions:'), 0, 1, 'L');
        $pdf->SetFont('Arial','',8);
        $pdf->MultiCell(0,4, pdf_text_simple($terms), 0, 'L');
    }
}

// ========== Authorized Signatory ==========
if ($pdf->GetY() + 25 < ($pdf->GetPageHeight() - $pdf->bm)) {
    $pdf->Ln(10);
    $pdf->SetFont('Arial','B',9);
    $pdf->Cell(0,6, pdf_text_simple('For ' . $company_name), 0, 1, 'R');
    $pdf->SetFont('Arial','',9);
    $pdf->Cell(0,15, pdf_text_simple('Authorized Signatory'), 0, 1, 'R');
}

// ========== Footer note ==========
if (!empty($settings['invoice_footer'])) {
    if ($pdf->GetY() + 10 < ($pdf->GetPageHeight() - $pdf->bm)) {
        $pdf->SetFont('Arial','I',8);
        $pdf->Cell(0,10, pdf_text_simple($settings['invoice_footer']), 0, 1, 'C');
    }
}

// ========== Output PDF ==========
while (ob_get_level()) ob_end_clean();
$pdf->Output('I', 'Quotation_' . $quotation['quotation_number'] . '.pdf');
exit;