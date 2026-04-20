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
           u.full_name as seller_name,
           s.shop_name, s.address as shop_address, s.phone as shop_phone, s.gstin as shop_gstin,
           s.id as shop_id,
           i.shipping_name, i.shipping_contact, i.shipping_gstin, i.shipping_address,
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
$transport = !empty($shipping_transport_type)
    ? $shipping_transport_type
    : (!empty($shipping_vehicle_number) ? 'Vehicle: ' . $shipping_vehicle_number : 'By Road');
$waybill_no = $invoice['shipping_waybill_no'] ?? '';

// ========== Include FPDF library ==========
require_once 'libs/fpdf.php';

// ========== Helper functions ==========
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

        // function ChargesBox()
        // {
        //     $shippingCharges = (float) ($this->shipping['shipping_charges'] ?? 0);
        //     $transportCharge = (float) ($this->shipping['transport_charge'] ?? 0);
        //     $transportType = $this->shipping['transport_type'] ?? '';
        //     $totalExtra = (float) ($this->shipping['total_extra'] ?? 0);

        //     if ($shippingCharges <= 0 && $transportCharge <= 0 && empty($transportType)) {
        //         return;
        //     }

        //     $this->Ln(2);
        //     $this->SetFont('Arial', 'B', 9);
        //     $this->SetX(5);
        //     $this->Cell(200, 7, 'Additional Charges', 1, 1, 'C');

        //     $this->SetFont('Arial', '', 9);

        //     if ($shippingCharges > 0) {
        //         $this->SetX(5);
        //         $this->Cell(100, 7, 'Shipping Charges', 1, 0, 'L');
        //         $this->Cell(100, 7, money($shippingCharges), 1, 1, 'R');
        //     }

        //     if (!empty($transportType) || $transportCharge > 0) {
        //         $label = 'Transport Charge';
        //         if (!empty($transportType)) {
        //             $label .= ' (' . pdf_text_simple($transportType) . ')';
        //         }

        //         $this->SetX(5);
        //         $this->Cell(100, 7, $label, 1, 0, 'L');
        //         $this->Cell(100, 7, money($transportCharge), 1, 1, 'R');
        //     }

        //     if ($totalExtra > 0) {
        //         $this->SetFont('Arial', 'B', 9);
        //         $this->SetX(5);
        //         $this->Cell(100, 7, 'Total Extra Charges', 1, 0, 'L');
        //         $this->Cell(100, 7, money($totalExtra), 1, 1, 'R');
        //         $this->SetFont('Arial', '', 9);
        //     }
        // }

        function Header()
        {
            // Outer border
            $this->Rect(5, 5, 200, 287);

            // Title - TAX INVOICE
            $this->SetFont('Arial', 'B', 10);
            $this->SetXY(5, 4);
            $title = $this->invoice['is_tax_invoice'] ? 'TAX INVOICE' : 'INVOICE';
            $this->Cell(200, 8, $title, 0, 1, 'C');

            // ========== ADD SHOP ADDRESS UNDER TITLE ==========
            $this->SetY(12);
            $this->SetX(5);

            // Logo for business_id 28 (supported formats only)
            if (
                !empty($this->company['logo']) &&
                file_exists($this->company['logo']) &&
                in_array(strtolower(pathinfo($this->company['logo'], PATHINFO_EXTENSION)), ['jpg', 'jpeg', 'png'])
            ) {
                try {
                    $this->Image($this->company['logo'], 10, 8, 20, 20);
                } catch (Exception $e) {
                    // keep layout without breaking PDF
                } catch (Error $e) {
                    // keep layout without breaking PDF
                }
            }

            // Shop/Business Name - Bold
            $this->SetFont('Arial', 'B', 18);
            $this->Cell(200, 4, pdf_text_simple($this->company['name']), 0, 1, 'C');

            // Shop Address - Normal
            $this->SetFont('Arial', '', 8);
            $this->SetX(5);
            $this->SetY(18);
            $this->MultiCell(200, 4, pdf_text_simple($this->company['address']), 0, 'C');

            // Phone (if available)
            if (!empty($this->company['phone'])) {
                $this->SetX(5);
                $this->MultiCell(200, 4, pdf_text_simple("Phone: " . $this->company['phone']), 0, 'C');
            }

            // GSTIN (if available)
            if (!empty($this->company['gstin'])) {
                $this->SetX(5);
                $this->MultiCell(200, 4, pdf_text_simple("GSTIN: " . $this->company['gstin']), 0, 'C');
            }

            // IRN and Ack details (if available)
            $this->SetFont('Arial', '', 7);

            if (!empty($this->invoice['irn_no'])) {
                $irnText = "IRN No.: " . $this->invoice['irn_no'];
                $this->SetY($this->GetY() + 2);
                $this->SetX(5);
                $this->MultiCell(200, 4, $irnText, 0, 'C');
            }

            if (!empty($this->invoice['ack_no']) && !empty($this->invoice['ack_date'])) {
                $this->SetFont('Arial', '', 7);
                $this->SetY($this->GetY() + 1);
                $this->SetX(5);
                $this->Cell(200, 4, 'Ack No.: ' . $this->invoice['ack_no'] . ' | Ack Date: ' . $this->invoice['ack_date'], 0, 1, 'C');
            }

            $this->headerEndY = $this->GetY();
        }

        function LabelValue($label, $value, $totalWidth = 100)
        {
            // Bold label
            $this->SetFont('Arial', 'B', 9);
            $labelWidth = $this->GetStringWidth($label);

            $this->Cell($labelWidth, 5, pdf_text_simple($label), 0, 0);

            // Normal value (tight join)
            $this->SetFont('Arial', '', 9);
            $this->Cell($totalWidth - $labelWidth, 5, pdf_text_simple($value), 0, 0);
        }

        function TopSection()
        {
            $this->SetFont('Arial', '', 9);

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

            $this->SetFont('Arial', 'B', 9);

            // Header row
            $this->SetX(5);
            $this->Cell(100, 6, 'Details of Receiver (Billed to)', 1, 0, 'C');
            $this->Cell(100, 6, 'Details of Consignee (Shipped to)', 1, 1, 'C');

            $this->SetFont('Arial', '', 9);

            $leftX = 5;
            $rightX = 105;
            $y = $this->GetY();

            // ================= LEFT BOX =================
            $this->SetXY($leftX, $y);

            // Address - customer name bold, address normal
            $this->SetFont('Arial', 'B', 9);
            $this->SetX($leftX);
            $this->Cell(100, 5, pdf_text_simple($this->customer['name']), 0, 1, 'L');

            $this->SetFont('Arial', '', 9);
            $this->SetX($leftX);
            $this->MultiCell(100, 5, pdf_text_simple($this->customer['address']), 0);

            // GSTIN (JOINED)
            $this->SetX($leftX);

            $this->SetFont('Arial', 'B', 9);
            $label = 'GSTIN: ';
            $labelWidth = $this->GetStringWidth($label);

            $this->Cell($labelWidth, 5, $label, 0, 0);

            $this->SetFont('Arial', '', 9);
            $gstinValue = !empty($this->customer['gstin']) ? $this->customer['gstin'] : 'Not Registered';
            $this->Cell(100 - $labelWidth, 5, pdf_text_simple($gstinValue), 0, 1);

            $leftFinalY = $this->GetY();

            // ================= RIGHT BOX =================
            $this->SetXY($rightX, $y);

            // Address - shipping/customer name bold, address normal
            $hasShipping = !empty($this->shipping['name']) || !empty($this->shipping['address']);

            if ($hasShipping) {
                $shipName = $this->shipping['name'];
                $shipAddr = $this->shipping['address'];
            } else {
                $shipName = $this->customer['name'];
                $shipAddr = $this->customer['address'];
            }

            $this->SetFont('Arial', 'B', 9);
            $this->SetX($rightX);
            $this->Cell(100, 5, pdf_text_simple($shipName), 0, 1, 'L');

            $this->SetFont('Arial', '', 9);
            $this->SetX($rightX);
            $this->MultiCell(100, 5, pdf_text_simple($shipAddr), 0);

            // GSTIN (JOINED)
            $this->SetX($rightX);

            $this->SetFont('Arial', 'B', 9);
            $label = 'GSTIN: ';
            $labelWidth = $this->GetStringWidth($label);

            $this->Cell($labelWidth, 5, $label, 0, 0);

            $this->SetFont('Arial', '', 9);
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
            $this->SetFont('Arial', 'B', 8);

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

    $rowH = 5.2;   // reduce product row height
    $totalRowH = 7;

    foreach ($this->items as $index => $item) {
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

        $rowBorder = ($index === 0) ? 'LTR' : 'LR';

        $this->SetX(5);
        $this->Cell(10, $rowH, $sn, $rowBorder, 0, 'C');

        $this->SetFont('Arial', 'B', 8);
        $this->Cell(60, $rowH, pdf_text_simple($productDesc), $rowBorder, 0, 'L');
        $this->SetFont('Arial', '', 8);

        $this->Cell(20, $rowH, pdf_text_simple($item['hsn_code'] ?? ''), $rowBorder, 0, 'C');
        $this->Cell(12, $rowH, format_quantity($quantity), $rowBorder, 0, 'C');
        $this->Cell(18, $rowH, money($unit_price), $rowBorder, 0, 'R');
        $this->Cell(15, $rowH, $discount_rate > 0 ? $discount_rate . '%' : '', $rowBorder, 0, 'R');
        $this->Cell(25, $rowH, money($taxable_value), $rowBorder, 0, 'R');
        $this->Cell(18, $rowH, $total_gst_rate > 0 ? number_format($total_gst_rate, 1) . '%' : '0%', $rowBorder, 0, 'C');
        $this->Cell(22, $rowH, money($net_total), $rowBorder, 1, 'R');

        $sn++;
    }

    $this->SetFont('Arial', 'B', 8);
    $this->SetX(5);
    $this->Cell(10, $totalRowH, '', 1, 0);
    $this->Cell(60, $totalRowH, 'Total:', 1, 0, 'R');
    $this->Cell(20, $totalRowH, '', 1, 0);
    $this->Cell(12, $totalRowH, format_quantity($totalQty), 1, 0, 'C');
    $this->Cell(18, $totalRowH, '', 1, 0);
    $this->Cell(15, $totalRowH, '', 1, 0);
    $this->Cell(25, $totalRowH, money($totalTaxableAmount), 1, 0, 'R');
    $this->Cell(18, $totalRowH, '', 1, 0);
    $this->Cell(22, $totalRowH, money($totalAmount), 1, 1, 'R');
}
        function FooterSection()
        {
            $this->SetFont('Arial', 'B', 9);
            $this->SetX(5);
            $amountInWords = number_to_words($this->totals['grand_total']);
            $this->Cell(200, 8, 'In Words: ' . $amountInWords, 0, 1);
        }

        function TotalsBox()
        {
            $this->SetFont('Arial', 'B', 9);

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

            $this->SetFont('Arial', '', 9);

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
            $this->SetFont('Arial', 'B', 9);
            $this->Cell(30, 7, 'Add: CGST', 1, 0, 'L');
            $this->SetFont('Arial', '', 9);
            $this->Cell(30, 7, money($totalCgst), 1, 1, 'R');

            // Add: SGST row
            $this->SetX(5);
            $this->Cell(30, 7, '', 1, 0);
            $this->Cell(40, 7, '', 1, 0);
            $this->Cell(35, 7, '', 1, 0);
            $this->Cell(35, 7, '', 1, 0);
            $this->SetFont('Arial', 'B', 9);
            $this->Cell(30, 7, 'Add: SGST', 1, 0, 'L');
            $this->SetFont('Arial', '', 9);
            $this->Cell(30, 7, money($totalSgst), 1, 1, 'R');

            // Extra charges rows
            $shippingCharges = (float) ($this->totals['shipping_charges'] ?? 0);
            $transportCharge = (float) ($this->totals['transport_charge'] ?? 0);
            $totalExtraCharges = (float) ($this->totals['total_extra_charges'] ?? 0);

            if ($shippingCharges > 0) {
                $this->SetX(5);
                $this->Cell(30, 7, '', 1, 0);
                $this->Cell(40, 7, '', 1, 0);
                $this->Cell(35, 7, '', 1, 0);
                $this->Cell(35, 7, '', 1, 0);
                $this->SetFont('Arial', 'B', 9);
                $this->Cell(30, 7, 'Shipping', 1, 0, 'L');
                $this->SetFont('Arial', '', 9);
                $this->Cell(30, 7, money($shippingCharges), 1, 1, 'R');
            }

            if ($transportCharge > 0) {
                $this->SetX(5);
                $this->Cell(30, 7, '', 1, 0);
                $this->Cell(40, 7, '', 1, 0);
                $this->Cell(35, 7, '', 1, 0);
                $this->Cell(35, 7, '', 1, 0);
                $this->SetFont('Arial', 'B', 9);
                $this->Cell(30, 7, 'Transport', 1, 0, 'L');
                $this->SetFont('Arial', '', 9);
                $this->Cell(30, 7, money($transportCharge), 1, 1, 'R');
            }

            if ($totalExtraCharges > 0) {
                $this->SetX(5);
                $this->Cell(30, 7, '', 1, 0);
                $this->Cell(40, 7, '', 1, 0);
                $this->Cell(35, 7, '', 1, 0);
                $this->Cell(35, 7, '', 1, 0);
                $this->SetFont('Arial', 'B', 9);
                $this->Cell(30, 7, 'Extra Total', 1, 0, 'L');
                $this->Cell(30, 7, money($totalExtraCharges), 1, 1, 'R');
                $this->SetFont('Arial', '', 9);
            }

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
            $this->SetFont('Arial', 'B', 9);
            $this->Cell(30, 7, 'Add: IGST', 1, 0, 'L');
            $this->SetFont('Arial', '', 9);
            $this->Cell(30, 7, money($totalIgst), 1, 1, 'R');

            // Rounded Off row
            $calculatedTotal = $this->totals['taxable'] + $totalCgst + $totalSgst + $totalIgst + $shippingCharges + $transportCharge;
            $roundOff = round($this->totals['grand_total'] - $calculatedTotal, 2);
            $this->SetX(5);
            $this->Cell(30, 7, '', 1, 0);
            $this->Cell(40, 7, '', 1, 0);
            $this->Cell(35, 7, '', 1, 0);
            $this->Cell(35, 7, '', 1, 0);
            $this->SetFont('Arial', 'B', 9);
            $this->Cell(30, 7, 'Rounded Off', 1, 0, 'L');
            $this->SetFont('Arial', '', 9);
            $this->Cell(30, 7, money($roundOff), 1, 1, 'R');

            // Eighth row: Final totals
            $this->SetFont('Arial', 'B', 9);
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
            $this->SetFont('Arial', 'B', 10);
            $this->SetX(4);
            $this->Cell(200, 8, 'Bank Details', 0, 1, 'C');

            $this->SetFont('Arial', '', 9);

            $this->SetX(5);

            // -------- ROW 1 --------
            // Name
            $this->SetFont('Arial', 'B', 9);
            $this->Cell(25, 6, 'Name', 0, 0);
            $this->SetFont('Arial', '', 9);
            $bankName = !empty($this->account['account_name']) ? $this->account['account_name'] : $this->company['name'];
            $this->Cell(75, 6, ': ' . pdf_text_simple($bankName), 0, 0);

            // Bank
            $this->SetFont('Arial', 'B', 9);
            $this->Cell(20, 6, 'Bank', 0, 0);
            $this->SetFont('Arial', '', 9);
            $this->Cell(30, 6, ': ' . pdf_text_simple($this->account['bank_name'] ?? ''), 0, 0);

            // Branch
            $this->SetFont('Arial', 'B', 9);
            $this->Cell(20, 6, 'Branch', 0, 0);
            $this->SetFont('Arial', '', 9);
            $this->Cell(30, 6, ': ' . pdf_text_simple($this->account['branch'] ?? ''), 0, 1);

            // -------- ROW 2 --------
            $this->SetX(5);

            // A/c No.
            $this->SetFont('Arial', 'B', 9);
            $this->Cell(25, 6, 'A/c No.', 0, 0);
            $this->SetFont('Arial', '', 9);
            $this->Cell(75, 6, ': ' . pdf_text_simple($this->account['account_number'] ?? ''), 0, 0);

            // IFSC Code
            $this->SetFont('Arial', 'B', 9);
            $this->Cell(20, 6, 'IFSC Code', 0, 0);
            $this->SetFont('Arial', '', 9);
            $this->Cell(30, 6, ': ' . pdf_text_simple($this->account['ifsc'] ?? ''), 0, 0);

            // Account Type
            $this->SetFont('Arial', 'B', 9);
            $this->Cell(20, 6, 'Account', 0, 0);
            $this->SetFont('Arial', '', 9);
            $this->Cell(30, 6, ': Current', 0, 1);

            $endY = $this->GetY();

            // Border
            $this->Rect(5, $startY, 200, ($endY - $startY));
        }

        function FinalFooterSection()
        {
            $startY = $this->GetY();
            $boxHeight = 32;

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
            $this->SetFont('Arial', '', 8);
            $this->SetXY(5, $startY + 24);
            $this->Cell($col1, 5, 'Receivers Signature', 0, 0, 'C');

            // =========================
            // COLUMN 2 → TERMS FROM SETTINGS
            // =========================
            $centerX = 5 + $col1;

            $this->SetFont('Arial', 'B', 9);
            $this->SetXY($centerX, $startY + 2);
            $this->Cell($col2, 4, 'TERMS AND CONDITIONS', 0, 1, 'C');

            $terms = trim((string) ($this->company['invoice_terms'] ?? ''));

            if ($terms === '') {
                $terms = "Goods once sold will not be taken back or exchanged.\nThank you for your business.";
            }

            $this->SetFont('Arial', '', 7);
            $this->SetXY($centerX + 2, $startY + 8);
            $this->MultiCell($col2 - 4, 3.8, pdf_text_simple($terms), 0, 'L');

            // =========================
            // COLUMN 3 → COMPANY
            // =========================
            $rightX = 5 + $col1 + $col2;

            $this->SetFont('Arial', '', 9);
            $this->SetXY($rightX, $startY + 6);
            $this->Cell($col3, 5, 'For ' . pdf_text_simple($this->company['name']), 0, 0, 'C');

            $this->SetXY($rightX, $startY + 24);
            $this->Cell($col3, 5, 'Authorised Signatory', 0, 0, 'C');
        }

        // Add custom footer to close the outer border properly
        function Footer()
        {
            // The outer border is already drawn in Header
            if ($this->PageNo() > 1) {
                $this->SetY(-15);
                $this->SetFont('Arial', 'I', 8);
                $this->Cell(0, 10, 'Page ' . $this->PageNo(), 0, 0, 'C');
            }
        }
    }

    // ========== Create PDF with NEW template ==========
    $pdf = new InvoicePDF();
    $pdf->AliasNbPages();

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
        'destination' => $shipping_address ? substr($shipping_address, 0, 50) : 'Chennai',
        'payment_terms' => 'Immediate',
        'due_date' => $invoice_date
    ];

    // Set customer info
    $pdf->customer = [
        'name' => $customer_name,
        'phone' => $customer_phone,
        'gstin' => $customer_gstin,
        'address' => $customer_address
    ];

    // Set shipping info
    $pdf->shipping = [
        'name' => $shipping_name,
        'contact' => $shipping_contact,
        'gstin' => $shipping_gstin,
        'address' => $shipping_address,
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

    // Set account details from first bank account
    if (!empty($bank_accounts)) {
        $bank = $bank_accounts[0];
        $pdf->account = [
            'account_name' => $bank['account_holder_name'] ?? '',
            'bank_name' => $bank['bank_name'],
            'account_number' => $bank['account_number'],
            'ifsc' => $bank['ifsc_code'] ?? '',
            'branch' => $bank['branch_name'] ?? '',
            'upi' => $bank['upi_id'] ?? ''
        ];
    } else {
        $pdf->account = [];
    }

    // Set items
    $pdf->items = $items;

    // Set transport and waybill
    $pdf->transport = $transport;
    $pdf->waybill_no = $invoice['shipping_waybill_no'] ?? '';

    // ========== Generate PDF ==========
    $pdf->AddPage();
    $pdf->TopSection();
    $pdf->PartyBoxes();
    $pdf->TableHeader();
    $pdf->TableRows();
    //$pdf->ChargesBox();
    $pdf->FooterSection();
    $pdf->TotalsBox();
    $pdf->BankDetailsSection();
    $pdf->FinalFooterSection();

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
        public $col_w = [];
        public $col_headers = [];
        public $lm = 8;
        public $rm = 8;
        public $tm = 8;
        public $bm = 15;
        public $verified_by = '-';
        public $is_gst_invoice = true; // Flag for GST/non-GST invoice

        // Column width proportions - original for GST invoice
        private $col_props_gst = [0.05, 0.27, 0.08, 0.09, 0.11, 0.06, 0.10, 0.10, 0.14];
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
            $this->SetFont('Arial', '', 6.8);
        }

        function Footer()
        {
            $this->SetY($this->GetPageHeight() - 20);
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

            $unit = !empty($item['product_unit']) ? $item['product_unit'] : (empty($item['unit']) ? 'PCS' : $item['unit']);

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
                $item_text = (!empty($item['product_code']) ? $item['product_code'] . " - " : "") . $item['product_name'];
                $this->BoxText($x1, $y, $this->col_w[1], $cellH, $item_text, 'L', 'M', 1.2, 1.0, 5.4);

                // HSN
                $this->Rect($x2, $y, $this->col_w[2], $cellH);
                $this->SetXY($x2, $y);
                $this->Cell($this->col_w[2], $cellH, pdf_text_simple($item['hsn_code'] ?? ''), 0, 0, 'C');

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
                $item_text = (!empty($item['product_code']) ? $item['product_code'] . " - " : "") . $item['product_name'];
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
                if ($discount_amount > 0) {
                    $disc_text = 'Rs. ' . money($discount_amount) . "\n(" . $discount_rate . "%)";
                } else {
                    $disc_text = '-';
                }
                $this->BoxText($x4, $y, $this->col_w[4], $cellH, $disc_text, 'C', 'M', 1.0, 1.0, 5.4);

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
            $a = $this->account;

            $hasAny = false;
            foreach (['account_name', 'bank_name', 'account_number', 'ifsc', 'branch', 'upi'] as $k) {
                if (!empty($a[$k])) {
                    $hasAny = true;
                    break;
                }
            }
            if (!$hasAny)
                return;

            // Only add account details if there's space on current page
            if ($this->GetY() + 28 > ($this->GetPageHeight() - $this->bm)) {
                return; // Don't add new page, just skip
            }

            $this->SetFont('Arial', 'B', 9);
            $this->Cell(0, 6, pdf_text_simple('Account Details'), 0, 1, 'L');

            $this->SetFont('Arial', '', 8);
            $lines = [];
            if (!empty($a['account_name']))
                $lines[] = 'A/C Name : ' . $a['account_name'];
            if (!empty($a['bank_name']))
                $lines[] = 'Bank : ' . $a['bank_name'];
            if (!empty($a['account_number']))
                $lines[] = 'A/C No : ' . $a['account_number'];
            if (!empty($a['ifsc']))
                $lines[] = 'IFSC : ' . $a['ifsc'];
            if (!empty($a['branch']))
                $lines[] = 'Branch : ' . $a['branch'];
            if (!empty($a['upi']))
                $lines[] = 'UPI : ' . $a['upi'];

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
        'address' => $customer_address
    ];

    // Set shipping info
    $pdf->shipping = [
        'name' => $shipping_name,
        'contact' => $shipping_contact,
        'gstin' => $shipping_gstin,
        'address' => $shipping_address,
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

    // Set account details from first bank account
    if (!empty($bank_accounts)) {
        $bank = $bank_accounts[0];
        $pdf->account = [
            'account_name' => $bank['account_holder_name'] ?? '',
            'bank_name' => $bank['bank_name'],
            'account_number' => $bank['account_number'],
            'ifsc' => $bank['ifsc_code'] ?? '',
            'branch' => $bank['branch_name'] ?? '',
            'upi' => $bank['upi_id'] ?? ''
        ];
    } else {
        $pdf->account = [];
    }

    // Set verified by (seller)
    $pdf->verified_by = $invoice['seller_name'];

    // ========== Generate PDF ==========
    $pdf->AddPage();
    $pdf->SetFont('Arial', '', 6.8);
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
                $pdf->SetFont('Arial', '', 6.8);
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
            $pdf->SetFont('Arial', 'B', 9);
            $pdf->Cell(0, 6, pdf_text_simple('Notes:'), 0, 1, 'L');
            $pdf->SetFont('Arial', '', 8);
            $pdf->MultiCell(0, 4, pdf_text_simple($notes), 0, 'L');
        }
    }

    // ========== Terms and Conditions ==========
    $terms = $settings['invoice_terms'] ?? '';
    if ($terms !== '') {
        // Check if we have space for terms
        if ($pdf->GetY() + 30 < ($pdf->GetPageHeight() - $pdf->bm)) {
            $pdf->SetFont('Arial', 'B', 9);
            $pdf->Cell(0, 6, pdf_text_simple('Terms & Conditions:'), 0, 1, 'L');
            $pdf->SetFont('Arial', '', 8);
            $pdf->MultiCell(0, 4, pdf_text_simple($terms), 0, 'L');
        }
    }

    // ========== Authorized Signatory ==========
    // Make sure we have space for signature
    if ($pdf->GetY() + 25 < ($pdf->GetPageHeight() - $pdf->bm)) {
        $pdf->Ln(10);
        $pdf->SetFont('Arial', 'B', 9);
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