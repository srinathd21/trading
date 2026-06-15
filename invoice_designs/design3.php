<?php
/**
 * invoice_designs/design3.php
 *
 * Clean modern invoice layout.
 * This file is included by invoice_print.php after all invoice, business,
 * customer, item, bank and total data have already been loaded.
 */

if (!defined('INVOICE_DESIGN_LOADED')) {
    exit('Direct access is not allowed.');
}

if (!isset($pdf) || !($pdf instanceof FPDF)) {
    $pdf = new FPDF('P', 'mm', 'A4');
}

/*
|--------------------------------------------------------------------------
| Dynamic data
|--------------------------------------------------------------------------
*/

$d3CompanyName = trim((string)($company_name ?? ''));
$d3CompanyAddress = trim((string)($company_address ?? ''));
$d3CompanyPhone = trim((string)($company_phone ?? ''));
$d3CompanyGstin = trim((string)($company_gstin ?? ''));

$d3InvoiceTitle = !empty($is_tax_invoice) ? 'TAX INVOICE' : 'INVOICE';
$d3InvoiceNumber = trim((string)($invoice['invoice_number'] ?? ''));
$d3InvoiceDate = trim((string)($invoice_date ?? ''));
$d3PaymentMode = trim((string)($payment_method ?? ''));
$d3PaymentStatus = trim((string)($payment_status ?? ''));
$d3PrintedOn = date('d-m-Y H:i:s');
$d3PlaceOfSupply = trim((string)($place_of_supply ?? ''));

$d3BillToName = trim((string)($customer_name ?? 'Walk-in Customer'));
$d3BillToPhone = trim((string)($customer_phone ?? ''));
$d3BillToAddress = trim((string)($customer_full_address ?? ''));

$d3ShipToName = trim((string)($shipping_name ?? ''));
$d3ShipToPhone = trim((string)($shipping_contact ?? ''));
$d3ShipToAddress = trim((string)($shipping_full_address ?? ''));

if ($d3ShipToName === '' && $d3ShipToAddress === '') {
    $d3ShipToName = $d3BillToName;
    $d3ShipToPhone = $d3BillToPhone;
    $d3ShipToAddress = $d3BillToAddress;
}

$d3Subtotal = (float)($subtotal ?? 0);
$d3TaxableValue = (float)($total_taxable ?? 0);
$d3TotalCgst = (float)($total_cgst ?? 0);
$d3TotalSgst = (float)($total_sgst ?? 0);
$d3TotalIgst = (float)($total_igst ?? 0);
$d3GrandTotal = (float)($grand_total ?? ($invoice['total'] ?? 0));

$d3Terms = trim((string)($settings['invoice_terms'] ?? ''));
$d3VerifiedBy = trim((string)($invoice['seller_name'] ?? ''));

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

$d3Text = static function ($value): string {
    if (function_exists('pdf_text_simple')) {
        return pdf_text_simple((string)$value);
    }

    $text = (string)$value;
    $converted = @iconv('UTF-8', 'windows-1252//TRANSLIT//IGNORE', $text);

    return $converted !== false
        ? $converted
        : preg_replace('/[^\x20-\x7E]/', '', $text);
};

$d3Money = static function ($value): string {
    if (function_exists('money')) {
        return money($value);
    }

    return number_format((float)$value, 2, '.', ',');
};

$d3Qty = static function ($value): string {
    if (function_exists('format_quantity')) {
        return format_quantity($value);
    }

    $value = (float)$value;

    return floor($value) == $value
        ? number_format($value, 0, '.', '')
        : number_format($value, 2, '.', '');
};

$d3FitText = static function (
    FPDF $pdf,
    string $text,
    float $width,
    int $maxFont = 8,
    int $minFont = 5
): int {
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
| Page header
|--------------------------------------------------------------------------
*/

$d3DrawHeader = static function () use (
    $pdf,
    $d3Text,
    $d3CompanyName,
    $d3CompanyAddress,
    $d3CompanyPhone,
    $d3CompanyGstin,
    $d3InvoiceTitle,
    $d3InvoiceNumber,
    $d3InvoiceDate,
    $d3PaymentMode,
    $d3PaymentStatus,
    $d3PrintedOn,
    $d3PlaceOfSupply,
    $d3BillToName,
    $d3BillToPhone,
    $d3BillToAddress,
    $d3ShipToName,
    $d3ShipToPhone,
    $d3ShipToAddress
): void {
    $pdf->SetMargins(8, 8, 8);
    $pdf->SetAutoPageBreak(false);
    $pdf->SetDrawColor(0, 0, 0);
    $pdf->SetLineWidth(0.2);

    $pdf->SetFont('Arial', 'B', 20);
    $pdf->SetXY(8, 8);
    $pdf->Cell(90, 10, $d3InvoiceTitle, 0, 0, 'L');

    $pdf->SetFont('Arial', '', 8);
    $pdf->SetXY(92, 10);
    $pdf->Cell(30, 5, 'Page ' . $pdf->PageNo() . ' / {nb}', 0, 0, 'C');

    $pdf->SetFont('Arial', '', 8);
    $pdf->SetXY(128, 8);
    $pdf->Cell(72, 5, 'Invoice No : ' . $d3Text($d3InvoiceNumber), 0, 1, 'R');
    $pdf->SetX(128);
    $pdf->Cell(72, 5, 'Invoice Date : ' . $d3Text($d3InvoiceDate), 0, 1, 'R');
    $pdf->SetX(128);
    $pdf->Cell(72, 5, 'Payment Mode : ' . $d3Text($d3PaymentMode), 0, 1, 'R');
    $pdf->SetX(128);
    $pdf->Cell(72, 5, 'Status : ' . $d3Text($d3PaymentStatus), 0, 1, 'R');
    $pdf->SetX(128);
    $pdf->Cell(72, 5, 'Printed On : ' . $d3Text($d3PrintedOn), 0, 1, 'R');
    $pdf->SetX(128);
    $pdf->Cell(72, 5, 'Place of Supply : ' . $d3Text($d3PlaceOfSupply), 0, 1, 'R');

    $pdf->SetFont('Arial', 'B', 13);
    $pdf->SetXY(8, 20);
    $pdf->Cell(110, 6, $d3Text($d3CompanyName), 0, 1, 'L');

    $pdf->SetFont('Arial', '', 8);
    $pdf->SetXY(8, 27);
    $pdf->MultiCell(110, 4, $d3Text($d3CompanyAddress), 0, 'L');

    $pdf->SetXY(8, 36);
    $pdf->Cell(110, 4, 'Phone: ' . $d3Text($d3CompanyPhone), 0, 1, 'L');

    $pdf->SetXY(8, 41);
    $pdf->Cell(110, 4, 'GSTIN : ' . ($d3CompanyGstin !== '' ? $d3Text($d3CompanyGstin) : '-'), 0, 1, 'L');

    $partyTop = 52;

    $pdf->SetFont('Arial', 'B', 10);
    $pdf->SetXY(8, $partyTop);
    $pdf->Cell(90, 6, 'Bill To', 0, 0, 'L');
    $pdf->SetXY(108, $partyTop);
    $pdf->Cell(90, 6, 'Ship To', 0, 1, 'L');

    $pdf->SetFont('Arial', '', 8);

    $pdf->SetXY(8, $partyTop + 7);
    $pdf->Cell(18, 4, 'Name:', 0, 0);
    $pdf->Cell(72, 4, $d3Text($d3BillToName), 0, 1);

    $pdf->SetXY(8, $partyTop + 12);
    $pdf->Cell(18, 4, 'Phone:', 0, 0);
    $pdf->Cell(72, 4, $d3Text($d3BillToPhone), 0, 1);

    $pdf->SetXY(8, $partyTop + 17);
    $pdf->Cell(18, 4, 'Address:', 0, 0);
    $pdf->SetXY(26, $partyTop + 17);
    $pdf->MultiCell(72, 4, $d3Text($d3BillToAddress), 0, 'L');

    $pdf->SetXY(108, $partyTop + 7);
    $pdf->Cell(18, 4, 'Name:', 0, 0);
    $pdf->Cell(72, 4, $d3Text($d3ShipToName), 0, 1);

    $pdf->SetXY(108, $partyTop + 12);
    $pdf->Cell(18, 4, 'Phone:', 0, 0);
    $pdf->Cell(72, 4, $d3Text($d3ShipToPhone), 0, 1);

    $pdf->SetXY(108, $partyTop + 17);
    $pdf->Cell(18, 4, 'Address:', 0, 0);
    $pdf->SetXY(126, $partyTop + 17);
    $pdf->MultiCell(72, 4, $d3Text($d3ShipToAddress), 0, 'L');
};

$d3DrawItemsHeader = static function () use ($pdf): void {
    $pdf->SetFont('Arial', 'B', 7);
    $pdf->SetX(8);
    $pdf->Cell(8, 7, 'SN', 1, 0, 'C');
    $pdf->Cell(46, 7, 'Item Description', 1, 0, 'C');
    $pdf->Cell(20, 7, 'HSN', 1, 0, 'C');
    $pdf->Cell(20, 7, 'GST(%)', 1, 0, 'C');
    $pdf->Cell(22, 7, 'Rate', 1, 0, 'C');
    $pdf->Cell(17, 7, 'Qty', 1, 0, 'C');
    $pdf->Cell(20, 7, 'Disc', 1, 0, 'C');
    $pdf->Cell(22, 7, 'GST Amt', 1, 0, 'C');
    $pdf->Cell(22, 7, 'Total', 1, 1, 'C');
};

/*
|--------------------------------------------------------------------------
| Start page
|--------------------------------------------------------------------------
*/

$pdf->AliasNbPages();
$pdf->AddPage('P', 'A4');

$d3DrawHeader();

$pdf->SetY(83);
$d3DrawItemsHeader();

$rowHeight = 8;
$maxRowsFirstPage = 11;
$maxRowsOtherPages = 25;
$currentRow = 0;
$itemIndex = 0;

foreach (($items ?? []) as $item) {
    $maxRowsThisPage = $pdf->PageNo() === 1 ? $maxRowsFirstPage : $maxRowsOtherPages;

    if ($currentRow >= $maxRowsThisPage) {
        $pdf->AddPage('P', 'A4');

        $pdf->SetMargins(8, 8, 8);
        $pdf->SetAutoPageBreak(false);

        $pdf->SetFont('Arial', 'B', 10);
        $pdf->SetXY(8, 8);
        $pdf->Cell(192, 5, $d3Text($d3CompanyName), 0, 1, 'L');

        $pdf->SetFont('Arial', '', 8);
        $pdf->SetXY(8, 14);
        $pdf->Cell(
            192,
            5,
            'Invoice No: ' . $d3Text($d3InvoiceNumber),
            0,
            1,
            'R'
        );

        $pdf->SetY(22);
        $d3DrawItemsHeader();

        $currentRow = 0;
    }

    $itemIndex++;

    $name = function_exists('item_display_name')
        ? item_display_name($item)
        : (string)($item['product_name'] ?? '');

    $code = function_exists('item_display_code')
        ? item_display_code($item)
        : (string)($item['product_code'] ?? '');

    if ($code !== '') {
        $name = $code . ' - ' . $name;
    }

    $hsn = function_exists('item_display_hsn')
        ? item_display_hsn($item)
        : (string)($item['hsn_code'] ?? '');

    $qty = (float)($item['quantity'] ?? 0);
    $rate = (float)($item['unit_price'] ?? 0);
    $discount = (float)($item['discount_amount'] ?? 0);

    $gstRate = (float)($item['cgst_rate'] ?? 0)
        + (float)($item['sgst_rate'] ?? 0)
        + (float)($item['igst_rate'] ?? 0);

    $gstAmount = (float)($item['cgst_amount'] ?? 0)
        + (float)($item['sgst_amount'] ?? 0)
        + (float)($item['igst_amount'] ?? 0);

    $lineTotal = isset($item['total_with_gst'])
        ? (float)$item['total_with_gst']
        : (
            isset($item['total_price'])
                ? (float)$item['total_price']
                : (($qty * $rate) - $discount + $gstAmount)
        );

    $pdf->SetX(8);
    $pdf->SetFont('Arial', '', 7);

    $pdf->Cell(8, $rowHeight, (string)$itemIndex, 1, 0, 'C');

    $safeName = $d3Text($name);
    $nameSize = $d3FitText($pdf, $safeName, 46, 7, 5);
    $pdf->SetFont('Arial', '', $nameSize);
    $pdf->Cell(46, $rowHeight, $safeName, 1, 0, 'L');

    $pdf->SetFont('Arial', '', 7);
    $pdf->Cell(20, $rowHeight, $d3Text($hsn), 1, 0, 'C');
    $pdf->Cell(20, $rowHeight, number_format($gstRate, 1) . '%', 1, 0, 'C');
    $pdf->Cell(22, $rowHeight, $d3Money($rate), 1, 0, 'R');
    $pdf->Cell(17, $rowHeight, $d3Qty($qty), 1, 0, 'C');
    $pdf->Cell(20, $rowHeight, $discount > 0 ? $d3Money($discount) : '-', 1, 0, 'R');
    $pdf->Cell(22, $rowHeight, $d3Money($gstAmount), 1, 0, 'R');
    $pdf->Cell(22, $rowHeight, $d3Money($lineTotal), 1, 1, 'R');

    $currentRow++;
}

/*
|--------------------------------------------------------------------------
| Totals and account details
|--------------------------------------------------------------------------
*/

$requiredBottomSpace = 118;
$bottomLimitY = 286;

if ($pdf->GetY() > ($bottomLimitY - $requiredBottomSpace)) {
    $pdf->AddPage('P', 'A4');

    $pdf->SetMargins(8, 8, 8);
    $pdf->SetAutoPageBreak(false);

    $pdf->SetFont('Arial', 'B', 10);
    $pdf->SetXY(8, 8);
    $pdf->Cell(192, 5, $d3Text($d3CompanyName), 0, 1, 'L');

    $pdf->SetFont('Arial', '', 8);
    $pdf->SetXY(8, 14);
    $pdf->Cell(
        192,
        5,
        'Invoice No: ' . $d3Text($d3InvoiceNumber),
        0,
        1,
        'R'
    );

    $pdf->SetY(24);
}

$summaryTop = $pdf->GetY() + 4;

$pdf->SetFont('Arial', '', 9);
$pdf->SetXY(118, $summaryTop);
$pdf->Cell(45, 6, 'Taxable Value', 0, 0, 'L');
$pdf->Cell(37, 6, $d3Money($d3TaxableValue), 0, 1, 'R');

$pdf->SetX(118);
$pdf->Cell(45, 6, 'CGST', 0, 0, 'L');
$pdf->Cell(37, 6, $d3Money($d3TotalCgst), 0, 1, 'R');

$pdf->SetX(118);
$pdf->Cell(45, 6, 'SGST', 0, 0, 'L');
$pdf->Cell(37, 6, $d3Money($d3TotalSgst), 0, 1, 'R');

if ($d3TotalIgst > 0) {
    $pdf->SetX(118);
    $pdf->Cell(45, 6, 'IGST', 0, 0, 'L');
    $pdf->Cell(37, 6, $d3Money($d3TotalIgst), 0, 1, 'R');
}

$pdf->SetFont('Arial', 'B', 11);
$pdf->SetX(118);
$pdf->Cell(45, 8, 'GRAND TOTAL', 0, 0, 'L');
$pdf->Cell(37, 8, $d3Money($d3GrandTotal), 0, 1, 'R');

/*
|--------------------------------------------------------------------------
| Bank accounts
|--------------------------------------------------------------------------
*/

$pdf->Ln(4);
$pdf->SetFont('Arial', 'B', 9);
$pdf->SetX(8);
$pdf->Cell(192, 6, 'Account Details', 0, 1, 'L');

$bankTop = $pdf->GetY();
$bankHeight = 34;

$pdf->Rect(8, $bankTop, 96, $bankHeight);
$pdf->Rect(104, $bankTop, 96, $bankHeight);

$bank1 = $bank_accounts[0] ?? [];
$bank2 = $bank_accounts[1] ?? [];

$drawBank = static function (
    FPDF $pdf,
    float $x,
    float $y,
    array $bank,
    string $title
) use ($d3Text): void {
    $pdf->SetFont('Arial', 'B', 7);
    $pdf->SetXY($x + 3, $y + 3);
    $pdf->Cell(90, 4, $title, 0, 1, 'L');

    $rows = [
        ['A/C Name', $bank['account_holder_name'] ?? ''],
        ['Bank', $bank['bank_name'] ?? ''],
        ['A/C No', $bank['account_number'] ?? ''],
        ['IFSC', $bank['ifsc_code'] ?? ''],
        ['Branch', $bank['branch_name'] ?? ''],
    ];

    $rowY = $y + 9;

    foreach ($rows as $row) {
        $pdf->SetFont('Arial', '', 6.5);
        $pdf->SetXY($x + 3, $rowY);
        $pdf->Cell(22, 3.8, $row[0], 0, 0, 'L');
        $pdf->Cell(4, 3.8, ':', 0, 0, 'C');
        $pdf->Cell(64, 3.8, $d3Text((string)$row[1]), 0, 1, 'L');
        $rowY += 4.2;
    }
};

$drawBank($pdf, 8, $bankTop, $bank1, 'Bank Account 1');
$drawBank($pdf, 104, $bankTop, $bank2, 'Bank Account 2');

$pdf->SetY($bankTop + $bankHeight + 5);

/*
|--------------------------------------------------------------------------
| Terms and signature
|--------------------------------------------------------------------------
*/

$pdf->SetFont('Arial', 'B', 8);
$pdf->SetX(8);
$pdf->Cell(95, 5, 'Terms & Conditions:', 0, 1, 'L');

$pdf->SetFont('Arial', '', 6.5);
$pdf->SetX(8);
$pdf->MultiCell(
    100,
    3.8,
    $d3Text($d3Terms),
    0,
    'L'
);

$signatureY = max($pdf->GetY() + 4, 235);

$pdf->SetFont('Arial', 'B', 9);
$pdf->SetXY(130, $signatureY);
$pdf->Cell(70, 5, 'For ' . $d3Text($d3CompanyName), 0, 1, 'C');

$pdf->Line(150, $signatureY + 18, 190, $signatureY + 18);

$pdf->SetFont('Arial', '', 7);
$pdf->SetXY(130, $signatureY + 20);
$pdf->Cell(70, 4, 'Authorised Signatory', 0, 1, 'C');

/*
|--------------------------------------------------------------------------
| Footer
|--------------------------------------------------------------------------
*/

$pdf->SetFont('Arial', 'I', 7);
$pdf->SetXY(8, 278);
$pdf->Cell(100, 4, 'This is a computer generated invoice.', 0, 1, 'L');

if ($d3VerifiedBy !== '') {
    $pdf->SetXY(8, 282);
    $pdf->Cell(100, 4, 'Verified By : ' . $d3Text($d3VerifiedBy), 0, 1, 'L');
}

$pdf->SetXY(120, 282);
$pdf->Cell(80, 4, 'Printed On - ' . $d3Text($d3PrintedOn), 0, 0, 'R');
