<?php
/**
 * invoice_designs/design1.php
 *
 * Classic Bill of Supply / Tax Invoice layout.
 *
 * This file must be included from invoice_print.php after all invoice data,
 * totals and FPDF have been loaded.
 */

if (!defined('INVOICE_DESIGN_LOADED')) {
    exit('Direct access is not allowed.');
}

if (!isset($pdf) || !($pdf instanceof FPDF)) {
    $pdf = new FPDF('P', 'mm', 'A4');
}

/*
|--------------------------------------------------------------------------
| Safe dynamic values
|--------------------------------------------------------------------------
*/

$d1CompanyName = trim((string)($company_name ?? ''));
$d1CompanyAddress = trim((string)($company_address ?? ''));
$d1CompanyPhone = trim((string)($company_phone ?? ''));
$d1CompanyEmail = trim((string)($settings['company_email'] ?? ''));
$d1CompanyGstin = trim((string)($company_gstin ?? ''));
$d1CompanyPan = trim((string)($settings['pan_number'] ?? ''));

$d1InvoiceTitle = !empty($is_tax_invoice) ? 'TAX INVOICE' : 'BILL OF SUPPLY';
$d1InvoiceNumber = trim((string)($invoice['invoice_number'] ?? ''));
$d1InvoiceDate = trim((string)($invoice_date ?? ''));
$d1InvoiceState = trim((string)($customer_state ?: 'Tamil Nadu'));
$d1ReverseCharge = strtoupper(trim((string)($invoice['reverse_charge'] ?? 'NO')));

$d1BuyerName = trim((string)($customer_name ?? 'Walk-in Customer'));
$d1BuyerAddress = trim((string)($customer_full_address ?? ''));
$d1BuyerGstin = trim((string)($customer_gstin ?? ''));
$d1BuyerState = trim((string)($customer_state ?: 'Tamil Nadu'));
$d1BuyerStateCode = trim((string)($invoice['customer_state_code'] ?? '33'));

$d1Terms = trim((string)($settings['invoice_terms'] ?? ''));
$d1Footer = trim((string)($settings['invoice_footer'] ?? 'Thank you for your business'));

$d1SubTotal = (float)($subtotal ?? 0);
$d1TaxTotal = (float)($total_cgst ?? 0)
    + (float)($total_sgst ?? 0)
    + (float)($total_igst ?? 0);

$d1GrandTotal = (float)($grand_total ?? ($invoice['total'] ?? 0));
$d1BalanceDue = (float)($invoice['pending_amount'] ?? 0);

$d1TotalQty = 0.0;
foreach (($items ?? []) as $d1QtyItem) {
    $d1TotalQty += (float)($d1QtyItem['quantity'] ?? 0);
}

/*
|--------------------------------------------------------------------------
| Local helpers
|--------------------------------------------------------------------------
*/

$d1Text = static function ($value): string {
    if (function_exists('pdf_text_simple')) {
        return pdf_text_simple((string)$value);
    }

    $text = (string)$value;
    $converted = @iconv('UTF-8', 'windows-1252//TRANSLIT//IGNORE', $text);

    return $converted !== false ? $converted : preg_replace('/[^\x20-\x7E]/', '', $text);
};

$d1Money = static function ($value): string {
    if (function_exists('money')) {
        return money($value);
    }

    return number_format((float)$value, 2, '.', ',');
};

$d1Qty = static function ($value): string {
    if (function_exists('format_quantity')) {
        return format_quantity($value);
    }

    $value = (float)$value;

    return floor($value) == $value
        ? number_format($value, 0, '.', '')
        : number_format($value, 2, '.', '');
};

$d1Words = static function ($value): string {
    if (function_exists('number_to_words')) {
        return number_to_words($value);
    }

    return number_format((float)$value, 2) . ' Rupees Only';
};

$d1FitText = static function (FPDF $pdf, string $text, float $width, int $maxFont = 8, int $minFont = 6): int {
    $size = $maxFont;

    while ($size > $minFont) {
        $pdf->SetFont('Arial', '', $size);

        if ($pdf->GetStringWidth($text) <= ($width - 2)) {
            break;
        }

        $size--;
    }

    return $size;
};

/*
|--------------------------------------------------------------------------
| Page drawing closures
|--------------------------------------------------------------------------
*/

$d1DrawHeader = static function () use (
    $pdf,
    $d1Text,
    $d1CompanyName,
    $d1CompanyAddress,
    $d1CompanyPhone,
    $d1CompanyEmail,
    $d1CompanyGstin,
    $d1CompanyPan,
    $d1InvoiceTitle,
    $d1InvoiceNumber,
    $d1InvoiceDate,
    $d1InvoiceState,
    $d1ReverseCharge,
    $d1BuyerName,
    $d1BuyerAddress,
    $d1BuyerGstin,
    $d1BuyerState,
    $d1BuyerStateCode
): void {
    $pdf->SetMargins(7, 7, 7);
    $pdf->SetAutoPageBreak(false);
    $pdf->SetDrawColor(0, 0, 0);
    $pdf->SetLineWidth(0.2);

    $pdf->SetFont('Arial', 'I', 8);
    $pdf->SetXY(7, 5);
    $pdf->Cell(196, 4, 'Thank you for doing business with us', 0, 1, 'C');

    $headerTop = 10;
    $headerHeight = 38;

    $pdf->Rect(7, $headerTop, 196, $headerHeight);

    $pdf->SetFont('Arial', 'B', 16);
    $pdf->SetXY(10, 14);
    $pdf->Cell(190, 8, $d1Text($d1CompanyName), 0, 1, 'C');

    $pdf->SetFont('Arial', '', 8);
    $pdf->SetXY(13, 23);
    $pdf->MultiCell(184, 4, $d1Text($d1CompanyAddress), 0, 'C');

    $contactLine = 'Phone: ' . $d1Text($d1CompanyPhone);

    if ($d1CompanyEmail !== '') {
        $contactLine .= '    Email: ' . $d1Text($d1CompanyEmail);
    }

    $pdf->SetXY(10, 32);
    $pdf->Cell(190, 4, $contactLine, 0, 1, 'C');

    $gstLine = 'GSTIN: ' . ($d1CompanyGstin !== '' ? $d1Text($d1CompanyGstin) : '-');

    if ($d1CompanyPan !== '') {
        $gstLine .= '    PAN: ' . $d1Text($d1CompanyPan);
    }

    $pdf->SetXY(10, 37);
    $pdf->Cell(190, 4, $gstLine, 0, 1, 'C');

    $pdf->SetFillColor(218, 235, 250);
    $pdf->SetFont('Arial', 'B', 14);
    $pdf->SetXY(7, 48);
    $pdf->Cell(196, 12, $d1InvoiceTitle, 1, 1, 'C', true);

    $pdf->SetFont('Arial', 'I', 7);
    $pdf->SetXY(160, 50);
    $pdf->Cell(40, 5, 'Original For Recipient', 0, 0, 'R');

    $metaTop = 60;
    $metaHeight = 23;

    $pdf->Rect(7, $metaTop, 196, $metaHeight);

    $pdf->SetFont('Arial', '', 8);

    $meta = [
        ['Invoice Number', $d1InvoiceNumber],
        ['Invoice Date', $d1InvoiceDate],
        ['State', $d1InvoiceState],
        ['Reverse Charge', $d1ReverseCharge],
    ];

    $y = 62;

    foreach ($meta as $row) {
        $pdf->SetXY(10, $y);
        $pdf->Cell(55, 4.5, $row[0], 0, 0, 'L');

        $pdf->SetFont('Arial', 'B', 8);
        $pdf->SetXY(140, $y);
        $pdf->Cell(60, 4.5, $d1Text($row[1]), 0, 1, 'R');

        $pdf->SetFont('Arial', '', 8);
        $y += 5;
    }

    $pdf->SetFillColor(218, 235, 250);
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->SetXY(7, 83);
    $pdf->Cell(196, 7, 'Details of Receiver | Billed to', 1, 1, 'C', true);

    $partyTop = 90;
    $partyHeight = 29;

    $pdf->Rect(7, $partyTop, 196, $partyHeight);

    $pdf->SetFont('Arial', '', 8);
    $pdf->SetXY(10, 93);
    $pdf->Cell(18, 4.5, 'Name:', 0, 0);
    $pdf->SetFont('Arial', 'B', 8);
    $pdf->Cell(165, 4.5, $d1Text($d1BuyerName), 0, 1);

    $pdf->SetFont('Arial', '', 8);
    $pdf->SetXY(10, 98);
    $pdf->Cell(18, 4.5, 'Address:', 0, 0);

    $pdf->SetXY(28, 98);
    $pdf->MultiCell(170, 4, $d1Text($d1BuyerAddress), 0, 'L');

    $pdf->SetXY(10, 108);
    $pdf->Cell(18, 4.5, 'GSTIN:', 0, 0);
    $pdf->Cell(75, 4.5, $d1Text($d1BuyerGstin !== '' ? $d1BuyerGstin : '-'), 0, 0);
    $pdf->Cell(22, 4.5, 'State Code:', 0, 0);
    $pdf->Cell(20, 4.5, $d1Text($d1BuyerStateCode), 0, 1);

    $pdf->SetXY(10, 113);
    $pdf->Cell(18, 4.5, 'State:', 0, 0);
    $pdf->Cell(80, 4.5, $d1Text($d1BuyerState), 0, 1);
};

$d1DrawTableHeader = static function () use ($pdf): void {
    $pdf->SetFillColor(218, 235, 250);
    $pdf->SetFont('Arial', 'B', 7);

    $pdf->SetX(7);
    $pdf->Cell(12, 7, 'Sr. No.', 1, 0, 'C', true);
    $pdf->Cell(77, 7, 'Name of Product / Description', 1, 0, 'C', true);
    $pdf->Cell(27, 7, 'HSN/SAC', 1, 0, 'C', true);
    $pdf->Cell(20, 7, 'QTY', 1, 0, 'C', true);
    $pdf->Cell(19, 7, 'Unit', 1, 0, 'C', true);
    $pdf->Cell(20, 7, 'Rate', 1, 0, 'C', true);
    $pdf->Cell(21, 7, 'Total', 1, 1, 'C', true);
};

/*
|--------------------------------------------------------------------------
| Page 1
|--------------------------------------------------------------------------
*/

$pdf->AddPage('P', 'A4');
$d1DrawHeader();

$pdf->SetY(119);
$d1DrawTableHeader();

$tableStartY = $pdf->GetY();
$rowHeight = 7;
$maxRowsFirstPage = 14;
$maxRowsOtherPages = 25;
$currentRowOnPage = 0;
$totalItemCount = count($items ?? []);
$itemIndex = 0;

foreach (($items ?? []) as $item) {
    $maxRowsThisPage = $pdf->PageNo() === 1 ? $maxRowsFirstPage : $maxRowsOtherPages;

    if ($currentRowOnPage >= $maxRowsThisPage) {
        $pdf->AddPage('P', 'A4');

        $pdf->SetMargins(7, 7, 7);
        $pdf->SetAutoPageBreak(false);

        $pdf->SetFont('Arial', 'B', 10);
        $pdf->SetXY(7, 8);
        $pdf->Cell(196, 6, $d1Text($d1CompanyName), 0, 1, 'C');

        $pdf->SetFont('Arial', '', 8);
        $pdf->SetXY(7, 15);
        $pdf->Cell(196, 5, 'Invoice No: ' . $d1Text($d1InvoiceNumber), 0, 1, 'R');

        $pdf->SetY(22);
        $d1DrawTableHeader();

        $currentRowOnPage = 0;
    }

    $itemIndex++;

    $itemName = function_exists('item_display_name')
        ? item_display_name($item)
        : (string)($item['product_name'] ?? '');

    $itemCode = function_exists('item_display_code')
        ? item_display_code($item)
        : (string)($item['product_code'] ?? '');

    if ($itemCode !== '') {
        $itemName = $itemCode . ' - ' . $itemName;
    }

    $itemHsn = function_exists('item_display_hsn')
        ? item_display_hsn($item)
        : (string)($item['hsn_code'] ?? '');

    $itemUnit = function_exists('item_display_unit')
        ? item_display_unit($item)
        : (string)($item['product_unit'] ?? 'pcs');

    $quantity = (float)($item['quantity'] ?? 0);
    $rate = (float)($item['unit_price'] ?? 0);

    $lineTotal = isset($item['total_with_gst'])
        ? (float)$item['total_with_gst']
        : (
            isset($item['total_price'])
                ? (float)$item['total_price']
                : ($quantity * $rate)
        );

    $pdf->SetX(7);
    $pdf->SetFont('Arial', '', 7);

    $pdf->Cell(12, $rowHeight, (string)$itemIndex, 1, 0, 'C');

    $safeItemName = $d1Text($itemName);
    $nameFontSize = $d1FitText($pdf, $safeItemName, 77, 7, 5);
    $pdf->SetFont('Arial', '', $nameFontSize);
    $pdf->Cell(77, $rowHeight, $safeItemName, 1, 0, 'L');

    $pdf->SetFont('Arial', '', 7);
    $pdf->Cell(27, $rowHeight, $d1Text($itemHsn), 1, 0, 'C');
    $pdf->Cell(20, $rowHeight, $d1Qty($quantity), 1, 0, 'C');
    $pdf->Cell(19, $rowHeight, $d1Text($itemUnit), 1, 0, 'C');
    $pdf->Cell(20, $rowHeight, $d1Money($rate), 1, 0, 'R');
    $pdf->Cell(21, $rowHeight, $d1Money($lineTotal), 1, 1, 'R');

    $currentRowOnPage++;
}

/*
|--------------------------------------------------------------------------
| Fill remaining first/last page item area
|--------------------------------------------------------------------------
*/

$totalsRequiredHeight = 73;
$footerBottomY = 286;
$totalsTopY = $footerBottomY - $totalsRequiredHeight;

if ($pdf->GetY() > $totalsTopY) {
    $pdf->AddPage('P', 'A4');

    $pdf->SetMargins(7, 7, 7);
    $pdf->SetAutoPageBreak(false);

    $pdf->SetFont('Arial', 'B', 10);
    $pdf->SetXY(7, 8);
    $pdf->Cell(196, 6, $d1Text($d1CompanyName), 0, 1, 'C');

    $pdf->SetFont('Arial', '', 8);
    $pdf->SetXY(7, 15);
    $pdf->Cell(196, 5, 'Invoice No: ' . $d1Text($d1InvoiceNumber), 0, 1, 'R');

    $pdf->SetY(22);
    $d1DrawTableHeader();
}

$currentY = $pdf->GetY();

if ($currentY < $totalsTopY) {
    $xPositions = [7, 19, 96, 123, 143, 162, 182, 203];

    foreach ($xPositions as $x) {
        $pdf->Line($x, $currentY, $x, $totalsTopY);
    }

    $pdf->Line(7, $totalsTopY, 203, $totalsTopY);
}

$pdf->SetY($totalsTopY);

/*
|--------------------------------------------------------------------------
| Total quantity row
|--------------------------------------------------------------------------
*/

$pdf->SetFillColor(218, 235, 250);
$pdf->SetFont('Arial', 'B', 7);
$pdf->SetX(7);
$pdf->Cell(12, 6, '', 1, 0, 'C', true);
$pdf->Cell(104, 6, 'Total', 1, 0, 'R', true);
$pdf->Cell(20, 6, $d1Qty($d1TotalQty), 1, 0, 'C', true);
$pdf->Cell(40, 6, '', 1, 0, 'C', true);
$pdf->Cell(20, 6, $d1Money($d1SubTotal), 1, 1, 'R', true);

/*
|--------------------------------------------------------------------------
| Amount in words + totals
|--------------------------------------------------------------------------
*/

$summaryY = $pdf->GetY();
$summaryHeight = 28;

$pdf->Rect(7, $summaryY, 118, $summaryHeight);
$pdf->Rect(125, $summaryY, 78, $summaryHeight);

$pdf->SetFont('Arial', 'B', 7);
$pdf->SetXY(10, $summaryY + 5);
$pdf->Cell(112, 4, 'Total Invoice Amount in words', 0, 1, 'C');

$pdf->SetFont('Arial', 'B', 8);
$pdf->SetXY(10, $summaryY + 11);
$pdf->MultiCell(
    112,
    4.5,
    $d1Text($d1Words($d1GrandTotal)),
    0,
    'C'
);

$summaryRows = [
    ['Sub Total', $d1SubTotal],
    ['Tax Total', $d1TaxTotal],
    ['Grand Total', $d1GrandTotal],
    ['Balance Due', $d1BalanceDue],
];

$rowY = $summaryY;

foreach ($summaryRows as $index => $summaryRow) {
    $pdf->SetXY(125, $rowY);
    $pdf->SetFont('Arial', $index === 2 ? 'B' : '', 7.5);
    $pdf->Cell(42, 7, $summaryRow[0], 1, 0, 'L');
    $pdf->Cell(36, 7, $d1Money($summaryRow[1]), 1, 1, 'R');
    $rowY += 7;
}

/*
|--------------------------------------------------------------------------
| Terms and signature
|--------------------------------------------------------------------------
*/

$bottomY = $summaryY + $summaryHeight;
$bottomHeight = 39;

$pdf->Rect(7, $bottomY, 98, $bottomHeight);
$pdf->Rect(105, $bottomY, 98, $bottomHeight);

$pdf->SetFont('Arial', 'B', 8);
$pdf->SetXY(10, $bottomY + 4);
$pdf->Cell(90, 4, 'Terms And Conditions', 0, 1, 'L');

$pdf->SetFont('Arial', '', 6.5);
$pdf->SetXY(10, $bottomY + 9);
$pdf->MultiCell(
    90,
    3.5,
    $d1Text($d1Terms),
    0,
    'L'
);

$pdf->SetFont('Arial', '', 7);
$pdf->SetXY(108, $bottomY + 4);
$pdf->Cell(
    92,
    4,
    'Certified that the particulars given above are true and correct',
    0,
    1,
    'C'
);

$pdf->SetFont('Arial', 'B', 9);
$pdf->SetXY(108, $bottomY + 10);
$pdf->Cell(
    92,
    5,
    'For, ' . $d1Text($d1CompanyName),
    0,
    1,
    'C'
);

$pdf->Line(135, $bottomY + 31, 178, $bottomY + 31);

$pdf->SetFont('Arial', '', 7);
$pdf->SetXY(108, $bottomY + 32);
$pdf->Cell(92, 4, 'Authorised Signatory', 0, 1, 'C');

$pdf->SetFont('Arial', 'I', 7);
$pdf->SetXY(7, 291);
$pdf->Cell(
    196,
    4,
    $d1Text($d1Footer !== '' ? $d1Footer : 'Thank you for your business'),
    0,
    0,
    'C'
);
