<?php
// template_classic.php - Classic Template (Your first template)
// This file should be placed in the invoice_templates/ folder

// The data is already fetched by the router, but we need to re-fetch or use passed data
// For simplicity, we'll re-fetch the data here

// Note: The router already started session and included config
// We just need to fetch the invoice data again or use a better approach

// For better performance, you can modify the router to pass the fetched data
// But for now, we'll re-fetch

$business_id = $_SESSION['business_id'] ?? 1;
$invoice_id = (int)$_GET['invoice_id'];

// Fetch invoice with all details
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
    die("Invoice not found");
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

// Fetch invoice settings
$settings_stmt = $pdo->prepare("
    SELECT * FROM invoice_settings
    WHERE business_id = ? AND (shop_id = ? OR (shop_id IS NULL AND ? IS NULL))
    ORDER BY shop_id DESC LIMIT 1
");
$settings_stmt->execute([$business_id, $shop_id, $shop_id]);
$settings = $settings_stmt->fetch(PDO::FETCH_ASSOC);

// Fallback to business table if no settings
if (!$settings) {
    $business_stmt = $pdo->prepare("SELECT business_name, phone, address, gstin FROM businesses WHERE id = ?");
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

// Get company info
$company_name = $settings['company_name'] ?? 'Ecommer';
$company_address = $settings['company_address'] ?? 'Sogathur X Road, Dharmapuri';
$company_phone = $settings['company_phone'] ?? '9003552650';
$company_gstin = $settings['gst_number'] ?? ($invoice['shop_gstin'] ?? '');

// Fetch bank accounts
if ($shop_id) {
    $bank_account_sql = "SELECT * FROM bank_accounts 
                        WHERE business_id = ? AND shop_id = ? AND is_active = 1
                        ORDER BY is_default DESC, id ASC LIMIT 2";
    $bank_account_stmt = $pdo->prepare($bank_account_sql);
    $bank_account_stmt->execute([$business_id, $shop_id]);
} else {
    $bank_account_sql = "SELECT * FROM bank_accounts 
                        WHERE business_id = ? AND shop_id IS NULL AND is_active = 1
                        ORDER BY is_default DESC, id ASC LIMIT 2";
    $bank_account_stmt = $pdo->prepare($bank_account_sql);
    $bank_account_stmt->execute([$business_id]);
}
$bank_accounts = $bank_account_stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch invoice items
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

// Calculate totals
$subtotal = $total_discount = $total_profit = 0;
$total_taxable = $total_cgst = $total_sgst = $total_igst = 0;

foreach ($items as $item) {
    $line_total = $item['unit_price'] * $item['quantity'];
    $discount = $item['discount_amount'] ?? 0;
    $subtotal += $line_total;
    $total_discount += $discount;
    $total_taxable += $item['taxable_value'] ?? 0;
    $total_cgst += $item['cgst_amount'] ?? 0;
    $total_sgst += $item['sgst_amount'] ?? 0;
    $total_igst += $item['igst_amount'] ?? 0;
}

$overall_discount = $invoice['overall_discount'] ?? 0;
$grand_total = $invoice['total'];
$is_tax_invoice = !empty($invoice['customer_gstin']) || ($total_cgst + $total_sgst + $total_igst) > 0;
$invoice_date = date('d-m-Y', strtotime($invoice['created_at']));
$payment_method = $invoice['payment_method'] ?? 'Cash';
$payment_status = $invoice['payment_status'] ?? 'Paid';
$place_of_supply = 'Tamil Nadu (33)';
$customer_name = $invoice['customer_name'] ?? 'Walk-in Customer';
$customer_phone = $invoice['customer_phone'] ?? '';
$customer_address = $invoice['customer_address'] ?? '';
$customer_gstin = $invoice['customer_gstin'] ?? '';

// Include FPDF
require_once 'libs/fpdf.php';

// Helper functions
function money($v) { 
    return number_format((float)$v, 2, '.', ','); 
}

function format_quantity($v) {
    $v = (float)$v;
    if (floor($v) == $v) return number_format($v, 0, '.', '');
    return number_format($v, 2, '.', '');
}

function pdf_text_simple($s) {
    $s = (string)$s;
    $s = str_replace(["₹", "â‚¹", "€", "£", "¥"], ["Rs.", "Rs.", "EUR", "GBP", "JPY"], $s);
    $s = preg_replace('/[^\x00-\x7F]/', '', $s);
    return $s;
}

// PDF Class for Classic Template
class ClassicInvoicePDF extends FPDF {
    public $company = [];
    public $invoice = [];
    public $customer = [];
    public $shipping = [];
    public $totals = [];
    public $account = [];
    public $col_w = [];
    public $col_headers = [];
    public $lm = 8; public $rm = 8; public $tm = 8; public $bm = 15;
    public $verified_by = '-';
    public $is_gst_invoice = true;
    
    private $col_props_gst = [0.05, 0.27, 0.08, 0.09, 0.11, 0.06, 0.10, 0.10, 0.14];
    private $col_props_non_gst = [0.05, 0.35, 0.14, 0.08, 0.16, 0.22];
    
    function Header() {
        $pw = $this->GetPageWidth();
        $printable = $pw - ($this->lm + $this->rm);
        
        $this->SetXY($this->lm, $this->tm);
        
        // Logo
        if (!empty($this->company['logo']) && file_exists($this->company['logo'])) {
            $old_error_level = error_reporting(0);
            try {
                $image_loaded = @$this->Image($this->company['logo'], $this->lm, $this->tm, 15, 15);
                if ($image_loaded !== false) {
                    $this->SetX($this->lm + 17);
                } else {
                    $this->SetX($this->lm);
                }
            } catch (Exception $e) {
                $this->SetX($this->lm);
            }
            error_reporting($old_error_level);
        } else {
            $this->SetX($this->lm);
        }
        
        // Title
        $title = $this->is_gst_invoice ? 'TAX INVOICE' : 'INVOICE';
        $this->SetFont('Arial','B',14);
        $this->Cell(100, 7, pdf_text_simple($title), 0, 0, 'L');
        
        $this->SetFont('Arial','',9);
        $this->Cell(0, 7, pdf_text_simple('Page '.$this->PageNo().'/{nb}'), 0, 1, 'L');
        
        $this->SetX($this->lm);
        $logo_offset = (!empty($this->company['logo']) && file_exists($this->company['logo'])) ? 17 : 0;
        
        $this->SetFont('Arial','B',12);
        $this->SetX($this->lm + $logo_offset + 0.1);
        $this->Cell(0, 6, pdf_text_simple($this->company['name']), 0, 1, 'L');
        
        $this->SetFont('Arial','',9);
        $this->SetX($this->lm + $logo_offset);
        $address_width = 100;
        $this->MultiCell($address_width, 4.5, pdf_text_simple($this->company['address']), 0, 'L');
        
        $this->SetX($this->lm + $logo_offset);
        if (!empty($this->company['phone'])) {
            $this->Cell(0, 5, pdf_text_simple('Phone: '.$this->company['phone']), 0, 1, 'L');
        }
        
        $company_info_height = $this->GetY();
        $right_start_y = $this->tm + 1;
        
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
        $max_y = max($company_info_height, $right_info_height);
        $this->SetY($max_y + 2);
        
        $this->SetFont('Arial','',9);
        $this->SetX($this->lm);
        if ($this->is_gst_invoice) {
            $this->Cell(120, 5, pdf_text_simple('GSTIN : '.$this->company['gstin']), 0, 0, 'L');
            $this->Cell(0, 5, pdf_text_simple('Place of Supply : '.$this->invoice['place_of_supply']), 0, 1, 'R');
        } else {
            $this->Cell(0, 5, pdf_text_simple('PAN : '.$this->company['gstin']), 0, 1, 'L');
        }
        
        $this->Ln(2);
        
        $colW = round($printable / 2);
        $this->SetFont('Arial','B',10);
        $this->SetX($this->lm);
        $this->Cell($colW, 6, pdf_text_simple('Bill To'), 0, 0, 'L');
        $this->Cell($colW, 6, pdf_text_simple('Ship To'), 0, 1, 'L');
        
        $this->SetFont('Arial','',9);
        $bill_y_start = $this->GetY();
        
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
        
        if ($this->shipping['charges'] > 0) {
            $this->Ln(2);
            $this->SetFont('Arial','',9);
            $this->SetX($this->lm);
            $this->Cell($colW, 5, '', 0, 0, 'L');
            $this->SetX($this->lm + $colW);
            $this->SetTextColor(0, 100, 0);
            $this->Cell($colW, 5, pdf_text_simple('Shipping Charges: Rs. ' . money($this->shipping['charges'])), 0, 1, 'R');
            $this->SetTextColor(0, 0, 0);
        }
        
        $this->Ln(2);
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
        $unit = !empty($item['product_unit']) ? $item['product_unit'] : (empty($item['unit']) ? 'PCS' : $item['unit']);
        
        if ($this->is_gst_invoice) {
            $gst_multiplier = 1 + ($total_gst_rate / 100);
            $base_unit_price = $unit_price / $gst_multiplier;
            
            $base_after_discount = $base_unit_price * $quantity;
            if ($discount_amount > 0) {
                $discount_ratio = $discount_amount / $line_total_before_discount;
                $base_after_discount = $base_unit_price * $quantity * (1 - $discount_ratio);
            }
            $total_gst_amount = $line_total_after_discount - $base_after_discount;
            
            $x0 = $x;
            $x1 = $x0 + $this->col_w[0];
            $x2 = $x1 + $this->col_w[1];
            $x3 = $x2 + $this->col_w[2];
            $x4 = $x3 + $this->col_w[3];
            $x5 = $x4 + $this->col_w[4];
            $x6 = $x5 + $this->col_w[5];
            $x7 = $x6 + $this->col_w[6];
            $x8 = $x7 + $this->col_w[7];
            
            $this->Rect($x0, $y, $this->col_w[0], $cellH);
            $this->SetXY($x0, $y);
            $this->Cell($this->col_w[0], $cellH, (string)$sn, 0, 0, 'C');
            
            $item_text = (!empty($item['product_code']) ? $item['product_code'] . " - " : "") . $item['product_name'];
            $this->BoxText($x1, $y, $this->col_w[1], $cellH, $item_text, 'L', 'M', 1.2, 1.0, 5.4);
            
            $this->Rect($x2, $y, $this->col_w[2], $cellH);
            $this->SetXY($x2, $y);
            $this->Cell($this->col_w[2], $cellH, pdf_text_simple($item['hsn_code'] ?? ''), 0, 0, 'C');
            
            $gst_text = $total_gst_rate > 0 ? number_format($total_gst_rate, 1) . '%' : '0%';
            $this->BoxText($x3, $y, $this->col_w[3], $cellH, $gst_text, 'C', 'M', 1.0, 1.0, 5.4);
            
            $rate_text = 'Rs. ' . money($base_unit_price);
            $this->Rect($x4, $y, $this->col_w[4], $cellH);
            $this->SetXY($x4, $y);
            $this->Cell($this->col_w[4], $cellH, pdf_text_simple($rate_text), 0, 0, 'R');
            
            $qty_text = format_quantity($quantity) . ' ' . $unit;
            $this->Rect($x5, $y, $this->col_w[5], $cellH);
            $this->SetXY($x5, $y);
            $this->Cell($this->col_w[5], $cellH, pdf_text_simple($qty_text), 0, 0, 'C');
            
            if ($discount_amount > 0) {
                $disc_text = 'Rs. ' . money($discount_amount) . "\n(" . $discount_rate . "%)";
            } else {
                $disc_text = '-';
            }
            $this->BoxText($x6, $y, $this->col_w[6], $cellH, $disc_text, 'C', 'M', 1.0, 1.0, 5.4);
            
            if ($total_gst_amount > 0) {
                $gst_amt_text = 'Rs. ' . money($total_gst_amount);
            } else {
                $gst_amt_text = '-';
            }
            $this->Rect($x7, $y, $this->col_w[7], $cellH);
            $this->SetXY($x7, $y);
            $this->Cell($this->col_w[7], $cellH, pdf_text_simple($gst_amt_text), 0, 0, 'R');
            
            $total_text = 'Rs. ' . money($line_total_after_discount);
            $this->Rect($x8, $y, $this->col_w[8], $cellH);
            $this->SetXY($x8, $y);
            $this->Cell($this->col_w[8], $cellH, pdf_text_simple($total_text), 0, 0, 'R');
        } else {
            $x0 = $x;
            $x1 = $x0 + $this->col_w[0];
            $x2 = $x1 + $this->col_w[1];
            $x3 = $x2 + $this->col_w[2];
            $x4 = $x3 + $this->col_w[3];
            $x5 = $x4 + $this->col_w[4];
            
            $this->Rect($x0, $y, $this->col_w[0], $cellH);
            $this->SetXY($x0, $y);
            $this->Cell($this->col_w[0], $cellH, (string)$sn, 0, 0, 'C');
            
            $item_text = (!empty($item['product_code']) ? $item['product_code'] . " - " : "") . $item['product_name'];
            $this->BoxText($x1, $y, $this->col_w[1], $cellH, $item_text, 'L', 'M', 1.2, 1.0, 5.4);
            
            $rate_text = 'Rs. ' . money($unit_price);
            $this->Rect($x2, $y, $this->col_w[2], $cellH);
            $this->SetXY($x2, $y);
            $this->Cell($this->col_w[2], $cellH, pdf_text_simple($rate_text), 0, 0, 'R');
            
            $qty_text = format_quantity($quantity) . ' ' . $unit;
            $this->Rect($x3, $y, $this->col_w[3], $cellH);
            $this->SetXY($x3, $y);
            $this->Cell($this->col_w[3], $cellH, pdf_text_simple($qty_text), 0, 0, 'C');
            
            if ($discount_amount > 0) {
                $disc_text = 'Rs. ' . money($discount_amount) . "\n(" . $discount_rate . "%)";
            } else {
                $disc_text = '-';
            }
            $this->BoxText($x4, $y, $this->col_w[4], $cellH, $disc_text, 'C', 'M', 1.0, 1.0, 5.4);
            
            $total_text = 'Rs. ' . money($line_total_after_discount);
            $this->Rect($x5, $y, $this->col_w[5], $cellH);
            $this->SetXY($x5, $y);
            $this->Cell($this->col_w[5], $cellH, pdf_text_simple($total_text), 0, 0, 'R');
        }
    }
    
    function DrawAmountSummary() {
        $t = $this->totals;
        
        $this->SetFont('Arial','',9);
        $rightX = $this->GetPageWidth() - $this->rm - 80;
        $startY = $this->GetY();
        $y = $startY;
        
        $this->SetFont('Arial','',9);
        $this->SetXY($rightX, $y);
        
        if ($this->is_gst_invoice) {
            if ($t['taxable'] > 0) {
                $this->SetX($rightX);
                $this->Cell(40, 6, pdf_text_simple('Taxable Value'), 0, 0, 'L');
                $this->Cell(40, 6, pdf_text_simple('Rs. ' . money($t['taxable'])), 0, 1, 'R');
                $y = $this->GetY();
            }
            
            if ($t['cgst'] > 0) {
                $this->SetX($rightX);
                $this->Cell(40, 6, pdf_text_simple('CGST'), 0, 0, 'L');
                $this->Cell(40, 6, pdf_text_simple('Rs. ' . money($t['cgst'])), 0, 1, 'R');
                $y = $this->GetY();
            }
            
            if ($t['sgst'] > 0) {
                $this->SetX($rightX);
                $this->Cell(40, 6, pdf_text_simple('SGST'), 0, 0, 'L');
                $this->Cell(40, 6, pdf_text_simple('Rs. ' . money($t['sgst'])), 0, 1, 'R');
                $y = $this->GetY();
            }
            
            if ($this->shipping['charges'] > 0) {
                $this->SetX($rightX);
                $this->Cell(40, 6, pdf_text_simple('Shipping Charges'), 0, 0, 'L');
                $this->Cell(40, 6, pdf_text_simple('Rs. ' . money($this->shipping['charges'])), 0, 1, 'R');
                $y = $this->GetY();
            }
            
            if (isset($t['overall_discount']) && $t['overall_discount'] > 0) {
                $this->SetX($rightX);
                $this->Cell(40, 6, pdf_text_simple('Overall Discount'), 0, 0, 'L');
                $this->Cell(40, 6, pdf_text_simple('- Rs. ' . money($t['overall_discount'])), 0, 1, 'R');
                $y = $this->GetY();
            }
            
            if ($t['discount'] > 0 && $t['overall_discount'] == 0) {
                $this->SetX($rightX);
                $this->Cell(40, 6, pdf_text_simple('Item Discounts'), 0, 0, 'L');
                $this->Cell(40, 6, pdf_text_simple('- Rs. ' . money($t['discount'])), 0, 1, 'R');
                $y = $this->GetY();
            }
        } else {
            $this->SetX($rightX);
            $this->Cell(40, 6, pdf_text_simple('Subtotal'), 0, 0, 'L');
            $this->Cell(40, 6, pdf_text_simple('Rs. ' . money($t['subtotal'])), 0, 1, 'R');
            $y = $this->GetY();
            
            if ($this->shipping['charges'] > 0) {
                $this->SetX($rightX);
                $this->Cell(40, 6, pdf_text_simple('Shipping Charges'), 0, 0, 'L');
                $this->Cell(40, 6, pdf_text_simple('Rs. ' . money($this->shipping['charges'])), 0, 1, 'R');
                $y = $this->GetY();
            }
            
            if (isset($t['overall_discount']) && $t['overall_discount'] > 0) {
                $this->SetX($rightX);
                $this->Cell(40, 6, pdf_text_simple('Overall Discount'), 0, 0, 'L');
                $this->Cell(40, 6, pdf_text_simple('- Rs. ' . money($t['overall_discount'])), 0, 1, 'R');
                $y = $this->GetY();
            }
            
            if ($t['discount'] > 0 && $t['overall_discount'] == 0) {
                $this->SetX($rightX);
                $this->Cell(40, 6, pdf_text_simple('Item Discounts'), 0, 0, 'L');
                $this->Cell(40, 6, pdf_text_simple('- Rs. ' . money($t['discount'])), 0, 1, 'R');
                $y = $this->GetY();
            }
        }
        
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
        
        if ($this->GetY() + 28 > ($this->GetPageHeight() - $this->bm)) {
            return;
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
    
    function initColumnWidths() {
        $pageWidth = $this->GetPageWidth();
        $printable = $pageWidth - ($this->lm + $this->rm);
        
        if ($this->is_gst_invoice) {
            $this->col_headers = ['SN', 'Item Description', 'HSN', 'GST(%)', 'Rate', 'Qty', 'Disc', 'GST Amt', 'Total'];
            $this->col_w = [];
            foreach ($this->col_props_gst as $p) {
                $this->col_w[] = round($printable * $p);
            }
            $totalWidth = array_sum($this->col_w);
            if ($totalWidth != $printable) {
                $this->col_w[1] += ($printable - $totalWidth);
            }
        } else {
            $this->col_headers = ['SN', 'Item Description', 'Rate', 'Qty', 'Disc', 'Total'];
            $this->col_w = [];
            foreach ($this->col_props_non_gst as $p) {
                $this->col_w[] = round($printable * $p);
            }
            $totalWidth = array_sum($this->col_w);
            if ($totalWidth != $printable) {
                $this->col_w[1] += ($printable - $totalWidth);
            }
        }
    }
}

// Create PDF
$pdf = new ClassicInvoicePDF('P','mm','A4');
$pdf->AliasNbPages();
$pdf->lm = 8; $pdf->rm = 8; $pdf->tm = 8; $pdf->bm = 15;
$pdf->SetMargins($pdf->lm, $pdf->tm, $pdf->rm);
$pdf->SetAutoPageBreak(true, $pdf->bm);
$pdf->is_gst_invoice = $is_tax_invoice;
$pdf->initColumnWidths();

$pdf->company = [
    'name'    => $company_name,
    'address' => $company_address,
    'gstin'   => $company_gstin,
    'phone'   => $company_phone,
    'email'   => $settings['company_email'] ?? '',
    'logo'    => !empty($settings['logo_path']) ? $settings['logo_path'] : ''
];

$pdf->invoice = [
    'number'          => $invoice['invoice_number'],
    'date'            => $invoice_date,
    'payment'         => $payment_method,
    'status'          => $payment_status,
    'printed_on'      => date('d-m-Y H:i:s'),
    'place_of_supply' => $place_of_supply
];

$pdf->customer = [
    'name'    => $customer_name,
    'phone'   => $customer_phone,
    'gstin'   => $customer_gstin,
    'address' => $customer_address
];

$pdf->shipping = [
    'name'           => $shipping_name,
    'contact'        => $shipping_contact,
    'gstin'          => $shipping_gstin,
    'address'        => $shipping_address,
    'vehicle_number' => $shipping_vehicle_number,
    'charges'        => $shipping_charges
];

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

$pdf->verified_by = $invoice['seller_name'];

// Generate PDF
$pdf->AddPage();
$pdf->SetFont('Arial','',6.8);
$lineH = 5.6;
$minLines = 1;
$sn = 1;

if (!empty($items)) {
    foreach ($items as $item) {
        $name = $item['product_name'] ?? '';
        $code = $item['product_code'] ?? '';
        $itemText = (!empty($code) ? $code . " - " : "") . $name;
        
        $itemLines = max($minLines, $pdf->NbLines(max(1, $pdf->col_w[1] - 3), $itemText));
        $maxLines = max($itemLines, 1);
        $cellH = ($maxLines * $lineH) + 2;
        
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

$pdf->Ln(2);
$pdf->DrawAmountSummary();
$pdf->DrawAccountDetails();

$notes = trim($invoice['notes'] ?? '');
if ($notes !== '') {
    if ($pdf->GetY() + 20 < ($pdf->GetPageHeight() - $pdf->bm)) {
        $pdf->SetFont('Arial','B',9);
        $pdf->Cell(0,6, pdf_text_simple('Notes:'), 0, 1, 'L');
        $pdf->SetFont('Arial','',8);
        $pdf->MultiCell(0,4, pdf_text_simple($notes), 0, 'L');
    }
}

$terms = $settings['invoice_terms'] ?? '';
if ($terms !== '') {
    if ($pdf->GetY() + 30 < ($pdf->GetPageHeight() - $pdf->bm)) {
        $pdf->SetFont('Arial','B',9);
        $pdf->Cell(0,6, pdf_text_simple('Terms & Conditions:'), 0, 1, 'L');
        $pdf->SetFont('Arial','',8);
        $pdf->MultiCell(0,4, pdf_text_simple($terms), 0, 'L');
    }
}

if ($pdf->GetY() + 25 < ($pdf->GetPageHeight() - $pdf->bm)) {
    $pdf->Ln(10);
    $pdf->SetFont('Arial','B',9);
    $pdf->Cell(0,6, pdf_text_simple('For ' . $company_name), 0, 1, 'R');
    $pdf->SetFont('Arial','',9);
    $pdf->Cell(0,15, pdf_text_simple('Authorized Signatory'), 0, 1, 'R');
}

if (!empty($settings['invoice_footer'])) {
    if ($pdf->GetY() + 10 < ($pdf->GetPageHeight() - $pdf->bm)) {
        $pdf->SetFont('Arial','I',8);
        $pdf->Cell(0,10, pdf_text_simple($settings['invoice_footer']), 0, 1, 'C');
    }
}

// Output PDF
while (ob_get_level()) ob_end_clean();
$pdf_content = $pdf->Output('S', 'Invoice_' . $invoice['invoice_number'] . '.pdf');

header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="Invoice_' . $invoice['invoice_number'] . '.pdf"');
header('Content-Length: ' . strlen($pdf_content));
header('Cache-Control: private, max-age=0, must-revalidate');
header('Pragma: public');

echo $pdf_content;
echo '<script type="text/javascript">window.onload = function() { window.print(); }</script>';
exit;
?>