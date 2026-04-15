<?php
// print-purchase.php
session_start();
date_default_timezone_set('Asia/Kolkata');
require_once 'config/database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

$business_id = $_SESSION['business_id'] ?? 1;

$purchase_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($purchase_id <= 0) {
    die('Invalid purchase ID');
}

/* =========================
   FETCH PURCHASE HEADER
========================= */
$stmt = $pdo->prepare("
    SELECT p.*, 
           m.name as manufacturer_name,
           m.contact_person,
           m.phone as m_phone, 
           m.email as m_email,
           m.address as m_address,
           m.gstin as m_gstin,
           m.account_holder_name,
           m.bank_name,
           m.account_number,
           m.ifsc_code,
           m.branch_name,
           u.full_name as created_by_name
    FROM purchases p
    LEFT JOIN manufacturers m ON p.manufacturer_id = m.id
    LEFT JOIN users u ON p.created_by = u.id
    WHERE p.id = ? AND p.business_id = ?
");
$stmt->execute([$purchase_id, $business_id]);
$purchase = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$purchase) {
    die('Purchase not found');
}

/* =========================
   FETCH GST CREDIT
========================= */
$stmt_gst = $pdo->prepare("
    SELECT * FROM gst_credits
    WHERE purchase_id = ? AND business_id = ?
    ORDER BY created_at DESC
    LIMIT 1
");
$stmt_gst->execute([$purchase_id, $business_id]);
$gst_credit = $stmt_gst->fetch(PDO::FETCH_ASSOC);

/* =========================
   FETCH PURCHASE ITEMS
========================= */
$stmt_items = $pdo->prepare("
    SELECT pi.*, 
           p.product_name, 
           p.product_code, 
           p.hsn_code,
           p.unit_of_measure,
           p.mrp as current_product_mrp,
           ps.quantity as current_stock,
           pb.old_mrp,
           pb.new_mrp,
           pb.batch_number,
           pb.expiry_date
    FROM purchase_items pi
    JOIN products p ON pi.product_id = p.id AND p.business_id = ?
    LEFT JOIN product_stocks ps ON ps.product_id = p.id AND ps.business_id = p.business_id
    LEFT JOIN purchase_batches pb ON pb.purchase_id = pi.purchase_id
        AND pb.product_id = pi.product_id
        AND pb.business_id = p.business_id
    WHERE pi.purchase_id = ? AND pi.business_id = ?
    ORDER BY pi.id
");
$stmt_items->execute([$business_id, $purchase_id, $business_id]);
$items = $stmt_items->fetchAll(PDO::FETCH_ASSOC);

if (!$items) {
    die('No purchase items found');
}

/* =========================
   FETCH INVOICE SETTINGS
========================= */
$shop_id = $purchase['shop_id'] ?? null;
$settings = [];

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
        'company_name'    => $business['business_name'] ?? 'Company',
        'company_address' => $business['address'] ?? '',
        'company_phone'   => $business['phone'] ?? '',
        'company_email'   => '',
        'company_website' => '',
        'gst_number'      => $business['gstin'] ?? '',
        'invoice_terms'   => '',
        'invoice_footer'  => 'Thank you',
        'logo_path'       => ''
    ];
}

/* =========================
   COMPANY INFO
========================= */
$company_name    = $settings['company_name'] ?? 'Company';
$company_address = $settings['company_address'] ?? '';
$company_phone   = $settings['company_phone'] ?? '';
$company_email   = $settings['company_email'] ?? '';
$company_website = $settings['company_website'] ?? '';
$company_gstin   = $settings['gst_number'] ?? '';

$company_logo = '';
if (!empty($settings['logo_path']) && file_exists($settings['logo_path'])) {
    $ext = strtolower(pathinfo($settings['logo_path'], PATHINFO_EXTENSION));
    if (in_array($ext, ['jpg', 'jpeg', 'png'])) {
        $company_logo = $settings['logo_path'];
    }
}

/* =========================
   TOTALS
========================= */
$subtotal = 0;
$total_cgst = 0;
$total_sgst = 0;
$total_igst = 0;
$total_taxable = 0;
$taxable_by_rate = [];

foreach ($items as $item) {
    $quantity = (float)($item['quantity'] ?? 0);
    $rate = (float)($item['purchase_price'] ?? 0);
    $taxable = $quantity * $rate;

    $cgst = (float)($item['cgst_amount'] ?? 0);
    $sgst = (float)($item['sgst_amount'] ?? 0);
    $igst = (float)($item['igst_amount'] ?? 0);

    $cgst_rate = (float)($item['cgst_rate'] ?? 0);
    $sgst_rate = (float)($item['sgst_rate'] ?? 0);
    $igst_rate = (float)($item['igst_rate'] ?? 0);
    $gst_rate  = $cgst_rate + $sgst_rate + $igst_rate;

    $subtotal += $taxable;
    $total_taxable += $taxable;
    $total_cgst += $cgst;
    $total_sgst += $sgst;
    $total_igst += $igst;

    if ($gst_rate > 0) {
        if (!isset($taxable_by_rate[$gst_rate])) {
            $taxable_by_rate[$gst_rate] = [
                'taxable' => 0,
                'cgst' => 0,
                'sgst' => 0,
                'igst' => 0,
            ];
        }
        $taxable_by_rate[$gst_rate]['taxable'] += $taxable;
        $taxable_by_rate[$gst_rate]['cgst'] += $cgst;
        $taxable_by_rate[$gst_rate]['sgst'] += $sgst;
        $taxable_by_rate[$gst_rate]['igst'] += $igst;
    }
}

$grand_total = (float)($purchase['total_amount'] ?? ($total_taxable + $total_cgst + $total_sgst + $total_igst));
$total_gst   = (float)($purchase['total_gst'] ?? ($total_cgst + $total_sgst + $total_igst));

$purchase_date = !empty($purchase['purchase_date']) ? date('d-m-Y', strtotime($purchase['purchase_date'])) : date('d-m-Y');
$created_at    = !empty($purchase['created_at']) ? date('d-m-Y h:i A', strtotime($purchase['created_at'])) : date('d-m-Y h:i A');

$payment_status = ucfirst($purchase['payment_status'] ?? 'Unpaid');
$paid_amount    = (float)($purchase['paid_amount'] ?? 0);
$pending_amount = max(0, $grand_total - $paid_amount);

/* =========================
   HELPERS
========================= */
require_once 'libs/fpdf.php';

function money($v) {
    return number_format((float)$v, 2, '.', ',');
}

function format_quantity($v) {
    $v = (float)$v;
    return floor($v) == $v ? number_format($v, 0, '.', '') : number_format($v, 2, '.', '');
}

function pdf_text_simple($s) {
    $s = (string)$s;
    $s = str_replace(["₹", "â‚¹", "€", "£", "¥"], ["Rs.", "Rs.", "EUR", "GBP", "JPY"], $s);
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

    $rupees_text = $rupees == 0 ? 'Zero' : convert_number_to_words($rupees, $words);

    $result = ucfirst($rupees_text) . ' Rupees';
    if ($paise > 0) {
        $paise_text = convert_number_to_words($paise, $words);
        $result .= ' and ' . ucfirst($paise_text) . ' Paise';
    }
    return $result . ' Only';
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

/* =========================
   PDF CLASS
========================= */
class PurchasePDF extends FPDF
{
    public $company = [];
    public $purchase = [];
    public $supplier = [];
    public $totals = [];
    public $items = [];
    public $taxable_by_rate = [];
    public $verified_by = '-';
    public $logo = '';

    public $lm = 8;
    public $rm = 8;
    public $tm = 8;
    public $bm = 15;

    public $col_w = [];
    public $col_headers = [];
    private $col_props = [0.05, 0.27, 0.10, 0.08, 0.11, 0.07, 0.10, 0.10, 0.12];

    function initColumnWidths()
    {
        $pageWidth = $this->GetPageWidth();
        $printable = $pageWidth - ($this->lm + $this->rm);

        $this->col_headers = ['SN', 'Item Description', 'HSN', 'Qty', 'Rate', 'Unit', 'Taxable', 'GST Amt', 'Total'];
        $this->col_w = [];

        foreach ($this->col_props as $p) {
            $this->col_w[] = round($printable * $p);
        }

        $totalWidth = array_sum($this->col_w);
        if ($totalWidth != $printable) {
            $this->col_w[1] += ($printable - $totalWidth);
        }
    }

    function Header()
    {
        $pw = $this->GetPageWidth();
        $this->SetXY($this->lm, $this->tm);

        if (!empty($this->logo) && file_exists($this->logo)) {
            try {
                $this->Image($this->logo, $this->lm, $this->tm, 15, 15);
                $this->SetX($this->lm + 17);
            } catch (Exception $e) {
                $this->SetX($this->lm);
            } catch (Error $e) {
                $this->SetX($this->lm);
            }
        } else {
            $this->SetX($this->lm);
        }

        $this->SetFont('Arial', 'B', 14);
        $this->Cell(100, 7, pdf_text_simple('PURCHASE ORDER'), 0, 0, 'L');

        $this->SetFont('Arial', '', 9);
        $this->Cell(0, 7, pdf_text_simple('Page ' . $this->PageNo() . '/{nb}'), 0, 1, 'R');

        $logo_offset = (!empty($this->logo) && file_exists($this->logo)) ? 17 : 0;

        $this->SetFont('Arial', 'B', 12);
        $this->SetX($this->lm + $logo_offset);
        $this->Cell(0, 6, pdf_text_simple($this->company['name']), 0, 1, 'L');

        $this->SetFont('Arial', '', 9);
        $this->SetX($this->lm + $logo_offset);
        $this->MultiCell(100, 4.5, pdf_text_simple($this->company['address']), 0, 'L');

        if (!empty($this->company['phone'])) {
            $this->SetX($this->lm + $logo_offset);
            $this->Cell(0, 5, pdf_text_simple('Phone: ' . $this->company['phone']), 0, 1, 'L');
        }

        if (!empty($this->company['gstin'])) {
            $this->SetX($this->lm + $logo_offset);
            $this->Cell(0, 5, pdf_text_simple('GSTIN: ' . $this->company['gstin']), 0, 1, 'L');
        }

        $company_y = $this->GetY();

        $this->SetXY($pw - $this->rm - 90, $this->tm + 5);
        $this->SetFont('Arial', '', 9);
        $this->Cell(90, 5, pdf_text_simple('PO No : ' . $this->purchase['number']), 0, 1, 'R');
        $this->SetX($pw - $this->rm - 90);
        $this->Cell(90, 5, pdf_text_simple('PO Date : ' . $this->purchase['date']), 0, 1, 'R');
        $this->SetX($pw - $this->rm - 90);
        $this->Cell(90, 5, pdf_text_simple('Payment Status : ' . $this->purchase['payment_status']), 0, 1, 'R');
        $this->SetX($pw - $this->rm - 90);
        $this->Cell(90, 5, pdf_text_simple('Created By : ' . $this->purchase['created_by']), 0, 1, 'R');
        $this->SetX($pw - $this->rm - 90);
        $this->Cell(90, 5, pdf_text_simple('Printed On : ' . $this->purchase['printed_on']), 0, 1, 'R');

        $right_y = $this->GetY();
        $this->SetY(max($company_y, $right_y) + 2);

        $colW = round(($pw - ($this->lm + $this->rm)) / 2);

        $this->SetFont('Arial', 'B', 10);
        $this->SetX($this->lm);
        $this->Cell($colW, 6, pdf_text_simple('Supplier Details'), 0, 0, 'L');
        $this->Cell($colW, 6, pdf_text_simple('Purchase Details'), 0, 1, 'L');

        $this->SetFont('Arial', '', 9);
        $boxStartY = $this->GetY();

        // Left box
        $this->SetX($this->lm);
        $this->MultiCell($colW - 2, 5, pdf_text_simple(
            'Name: ' . $this->supplier['name'] . "\n" .
            (!empty($this->supplier['contact_person']) ? 'Contact: ' . $this->supplier['contact_person'] . "\n" : '') .
            (!empty($this->supplier['phone']) ? 'Phone: ' . $this->supplier['phone'] . "\n" : '') .
            (!empty($this->supplier['email']) ? 'Email: ' . $this->supplier['email'] . "\n" : '') .
            (!empty($this->supplier['gstin']) ? 'GSTIN: ' . $this->supplier['gstin'] . "\n" : '') .
            (!empty($this->supplier['address']) ? 'Address: ' . $this->supplier['address'] : '')
        ), 0, 'L');
        $leftY = $this->GetY();

        // Right box
        $this->SetY($boxStartY);
        $this->SetX($this->lm + $colW);
        $this->MultiCell($colW - 2, 5, pdf_text_simple(
            'Purchase Invoice No: ' . ($this->purchase['purchase_invoice_no'] ?: '-') . "\n" .
            'Reference No: ' . ($this->purchase['reference'] ?: '-') . "\n" .
            'Paid Amount: Rs. ' . money($this->totals['paid_amount']) . "\n" .
            'Pending Amount: Rs. ' . money($this->totals['pending_amount']) . "\n" .
            'GST Credit: Rs. ' . money($this->totals['gst_credit'])
        ), 0, 'L');
        $rightY = $this->GetY();

        $endY = max($leftY, $rightY);
        $this->SetY($endY + 2);

        $this->TableHeader();
    }

    function TableHeader()
    {
        $this->SetFont('Arial', 'B', 8);
        foreach ($this->col_headers as $i => $h) {
            $this->Cell($this->col_w[$i], 8, pdf_text_simple($h), 1, 0, 'C');
        }
        $this->Ln();
        $this->SetFont('Arial', '', 7);
    }

    function NbLines($w, $txt)
    {
        $txt = (string)$txt;
        $txt = str_replace("\r", '', $txt);
        $cw = &$this->CurrentFont['cw'];
        if ($w == 0) $w = $this->w - $this->rMargin - $this->x;
        $wmax = ($w - 2 * $this->cMargin) * 1000 / $this->FontSize;
        $s = $txt;
        $nb = strlen($s);
        if ($nb > 0 && $s[$nb - 1] == "\n") $nb--;
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

    function BoxText($x, $y, $w, $h, $txt, $align = 'L')
    {
        $this->Rect($x, $y, $w, $h);
        $this->SetXY($x + 1.2, $y + 1.0);
        $this->MultiCell($w - 2.4, 4.5, pdf_text_simple($txt), 0, $align);
    }

    function AddItemRow($x, $y, $sn, $item, $cellH)
    {
        $quantity = (float)($item['quantity'] ?? 0);
        $rate = (float)($item['purchase_price'] ?? 0);
        $taxable = $quantity * $rate;
        $gst_amount = (float)($item['cgst_amount'] ?? 0) + (float)($item['sgst_amount'] ?? 0) + (float)($item['igst_amount'] ?? 0);
        $total = (float)($item['total_price'] ?? ($taxable + $gst_amount));

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

        $desc = (!empty($item['product_code']) ? $item['product_code'] . ' - ' : '') . ($item['product_name'] ?? '');
        if (!empty($item['batch_number'])) {
            $desc .= "\nBatch: " . $item['batch_number'];
        }
        if (!empty($item['expiry_date'])) {
            $desc .= "\nExp: " . date('M Y', strtotime($item['expiry_date']));
        }
        $this->BoxText($x1, $y, $this->col_w[1], $cellH, $desc, 'L');

        $this->Rect($x2, $y, $this->col_w[2], $cellH);
        $this->SetXY($x2, $y);
        $this->Cell($this->col_w[2], $cellH, pdf_text_simple($item['hsn_code'] ?? ''), 0, 0, 'C');

        $this->Rect($x3, $y, $this->col_w[3], $cellH);
        $this->SetXY($x3, $y);
        $this->Cell($this->col_w[3], $cellH, format_quantity($quantity), 0, 0, 'C');

        $this->Rect($x4, $y, $this->col_w[4], $cellH);
        $this->SetXY($x4, $y);
        $this->Cell($this->col_w[4], $cellH, money($rate), 0, 0, 'R');

        $this->Rect($x5, $y, $this->col_w[5], $cellH);
        $this->SetXY($x5, $y);
        $this->Cell($this->col_w[5], $cellH, pdf_text_simple($item['unit_of_measure'] ?? 'PCS'), 0, 0, 'C');

        $this->Rect($x6, $y, $this->col_w[6], $cellH);
        $this->SetXY($x6, $y);
        $this->Cell($this->col_w[6], $cellH, money($taxable), 0, 0, 'R');

        $this->Rect($x7, $y, $this->col_w[7], $cellH);
        $this->SetXY($x7, $y);
        $this->Cell($this->col_w[7], $cellH, money($gst_amount), 0, 0, 'R');

        $this->Rect($x8, $y, $this->col_w[8], $cellH);
        $this->SetXY($x8, $y);
        $this->Cell($this->col_w[8], $cellH, money($total), 0, 0, 'R');
    }

    function DrawAmountSummary()
    {
        $t = $this->totals;
        $rightX = $this->GetPageWidth() - $this->rm - 80;
        $y = $this->GetY();

        $this->SetFont('Arial', '', 9);

        $this->SetXY($rightX, $y);
        $this->Cell(40, 6, 'Subtotal', 0, 0, 'L');
        $this->Cell(40, 6, 'Rs. ' . money($t['subtotal']), 0, 1, 'R');

        $this->SetX($rightX);
        $this->Cell(40, 6, 'CGST', 0, 0, 'L');
        $this->Cell(40, 6, 'Rs. ' . money($t['cgst']), 0, 1, 'R');

        $this->SetX($rightX);
        $this->Cell(40, 6, 'SGST', 0, 0, 'L');
        $this->Cell(40, 6, 'Rs. ' . money($t['sgst']), 0, 1, 'R');

        $this->SetX($rightX);
        $this->Cell(40, 6, 'IGST', 0, 0, 'L');
        $this->Cell(40, 6, 'Rs. ' . money($t['igst']), 0, 1, 'R');

        if ($t['gst_credit'] > 0) {
            $this->SetX($rightX);
            $this->Cell(40, 6, 'GST Credit', 0, 0, 'L');
            $this->Cell(40, 6, 'Rs. ' . money($t['gst_credit']), 0, 1, 'R');
        }

        $this->SetX($rightX);
        $this->Cell(40, 6, 'Paid Amount', 0, 0, 'L');
        $this->Cell(40, 6, 'Rs. ' . money($t['paid_amount']), 0, 1, 'R');

        $this->SetX($rightX);
        $this->Cell(40, 6, 'Pending Amount', 0, 0, 'L');
        $this->Cell(40, 6, 'Rs. ' . money($t['pending_amount']), 0, 1, 'R');

        $this->SetFont('Arial', 'B', 11);
        $this->SetX($rightX);
        $this->Cell(40, 8, 'GRAND TOTAL', 0, 0, 'L');
        $this->Cell(40, 8, 'Rs. ' . money($t['grand_total']), 0, 1, 'R');

        $this->Ln(2);
    }

    function DrawBankDetails()
    {
        if (empty($this->supplier['account_holder_name']) &&
            empty($this->supplier['bank_name']) &&
            empty($this->supplier['account_number']) &&
            empty($this->supplier['ifsc_code']) &&
            empty($this->supplier['branch_name'])) {
            return;
        }

        if ($this->GetY() + 30 > ($this->GetPageHeight() - $this->bm)) {
            return;
        }

        $this->SetFont('Arial', 'B', 9);
        $this->Cell(0, 6, 'Supplier Bank Details', 0, 1, 'L');

        $this->SetFont('Arial', '', 8);
        $x = $this->lm;
        $w = $this->GetPageWidth() - ($this->lm + $this->rm);
        $yStart = $this->GetY();
        $lines = [];

        if (!empty($this->supplier['account_holder_name'])) $lines[] = 'A/C Name : ' . $this->supplier['account_holder_name'];
        if (!empty($this->supplier['bank_name'])) $lines[] = 'Bank : ' . $this->supplier['bank_name'];
        if (!empty($this->supplier['account_number'])) $lines[] = 'A/C No : ' . $this->supplier['account_number'];
        if (!empty($this->supplier['ifsc_code'])) $lines[] = 'IFSC : ' . $this->supplier['ifsc_code'];
        if (!empty($this->supplier['branch_name'])) $lines[] = 'Branch : ' . $this->supplier['branch_name'];

        $h = max(18, count($lines) * 4.5 + 4);
        $this->Rect($x, $yStart, $w, $h);
        $this->SetXY($x + 2, $yStart + 2);

        foreach ($lines as $ln) {
            $this->Cell($w - 4, 4.5, pdf_text_simple($ln), 0, 1, 'L');
        }

        $this->Ln(2);
    }

    function Footer()
    {
        $this->SetY($this->GetPageHeight() - 20);
        $this->SetFont('Arial', 'I', 8);
        $this->Cell(0, 4.5, pdf_text_simple('This is a computer generated purchase print.'), 0, 1, 'L');

        if (!empty($this->verified_by)) {
            $this->Cell(0, 4.5, pdf_text_simple('Verified By : ' . $this->verified_by), 0, 1, 'L');
        }

        $this->Cell(0, 4.5, pdf_text_simple('Printed On - ' . $this->purchase['printed_on']), 0, 0, 'R');
    }
}

/* =========================
   BUILD PDF
========================= */
$pdf = new PurchasePDF('P', 'mm', 'A4');
$pdf->AliasNbPages();
$pdf->SetMargins($pdf->lm, $pdf->tm, $pdf->rm);
$pdf->SetAutoPageBreak(true, $pdf->bm);
$pdf->initColumnWidths();

$pdf->company = [
    'name'    => $company_name,
    'address' => $company_address,
    'phone'   => $company_phone,
    'email'   => $company_email,
    'website' => $company_website,
    'gstin'   => $company_gstin
];

$pdf->supplier = [
    'name'                => $purchase['manufacturer_name'] ?? '',
    'contact_person'      => $purchase['contact_person'] ?? '',
    'phone'               => $purchase['m_phone'] ?? '',
    'email'               => $purchase['m_email'] ?? '',
    'address'             => $purchase['m_address'] ?? '',
    'gstin'               => $purchase['m_gstin'] ?? '',
    'account_holder_name' => $purchase['account_holder_name'] ?? '',
    'bank_name'           => $purchase['bank_name'] ?? '',
    'account_number'      => $purchase['account_number'] ?? '',
    'ifsc_code'           => $purchase['ifsc_code'] ?? '',
    'branch_name'         => $purchase['branch_name'] ?? '',
];

$pdf->purchase = [
    'number'              => $purchase['purchase_number'] ?? '',
    'date'                => $purchase_date,
    'payment_status'      => $payment_status,
    'purchase_invoice_no' => $purchase['purchase_invoice_no'] ?? '',
    'reference'           => $purchase['reference'] ?? '',
    'created_by'          => $purchase['created_by_name'] ?? '',
    'printed_on'          => date('d-m-Y h:i A')
];

$pdf->totals = [
    'subtotal'       => $subtotal,
    'cgst'           => $total_cgst,
    'sgst'           => $total_sgst,
    'igst'           => $total_igst,
    'grand_total'    => $grand_total,
    'paid_amount'    => $paid_amount,
    'pending_amount' => $pending_amount,
    'gst_credit'     => (float)($gst_credit['credit_amount'] ?? 0)
];

$pdf->items = $items;
$pdf->taxable_by_rate = $taxable_by_rate;
$pdf->verified_by = $purchase['created_by_name'] ?? '-';
$pdf->logo = $company_logo;

$pdf->AddPage();
$pdf->SetFont('Arial', '', 7);

$sn = 1;
$lineH = 5.4;
$minLines = 1;

foreach ($items as $item) {
    $itemText = (!empty($item['product_code']) ? $item['product_code'] . ' - ' : '') . ($item['product_name'] ?? '');
    $itemLines = max($minLines, $pdf->NbLines(max(1, $pdf->col_w[1] - 3), $itemText));
    $cellH = ($itemLines * $lineH) + 6;

    $currentY = $pdf->GetY();
    if ($currentY + $cellH + 80 > ($pdf->GetPageHeight() - $pdf->bm)) {
        $pdf->AddPage();
        $pdf->TableHeader();
    }

    $x = $pdf->GetX();
    $y = $pdf->GetY();

    $pdf->AddItemRow($x, $y, $sn, $item, $cellH);
    $pdf->SetXY($x, $y + $cellH);
    $sn++;
}

$pdf->Ln(2);

$pdf->SetFont('Arial', 'B', 9);
$pdf->Cell(0, 7, pdf_text_simple('Amount in Words: ' . number_to_words($grand_total)), 0, 1, 'L');

$pdf->DrawAmountSummary();
$pdf->DrawBankDetails();

if (!empty($purchase['notes']) && $pdf->GetY() + 20 < ($pdf->GetPageHeight() - $pdf->bm)) {
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->Cell(0, 6, 'Notes:', 0, 1, 'L');
    $pdf->SetFont('Arial', '', 8);
    $pdf->MultiCell(0, 4.5, pdf_text_simple($purchase['notes']), 0, 'L');
}

if (!empty($settings['invoice_terms']) && $pdf->GetY() + 25 < ($pdf->GetPageHeight() - $pdf->bm)) {
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->Cell(0, 6, 'Terms & Conditions:', 0, 1, 'L');
    $pdf->SetFont('Arial', '', 8);
    $pdf->MultiCell(0, 4.5, pdf_text_simple($settings['invoice_terms']), 0, 'L');
}

if ($pdf->GetY() + 25 < ($pdf->GetPageHeight() - $pdf->bm)) {
    $pdf->Ln(8);
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->Cell(0, 6, pdf_text_simple('For ' . $company_name), 0, 1, 'R');
    $pdf->SetFont('Arial', '', 9);
    $pdf->Cell(0, 15, pdf_text_simple('Authorized Signatory'), 0, 1, 'R');
}

if (!empty($settings['invoice_footer']) && $pdf->GetY() + 10 < ($pdf->GetPageHeight() - $pdf->bm)) {
    $pdf->SetFont('Arial', 'I', 8);
    $pdf->Cell(0, 10, pdf_text_simple($settings['invoice_footer']), 0, 1, 'C');
}

/* =========================
   OUTPUT PDF
========================= */
while (ob_get_level()) ob_end_clean();

$pdf_content = $pdf->Output('S', 'Purchase_' . ($purchase['purchase_number'] ?? $purchase_id) . '.pdf');

header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="Purchase_' . ($purchase['purchase_number'] ?? $purchase_id) . '.pdf"');
header('Content-Length: ' . strlen($pdf_content));
header('Cache-Control: private, max-age=0, must-revalidate');
header('Pragma: public');

echo $pdf_content;
exit;
?>