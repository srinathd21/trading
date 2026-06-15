<?php
/**
 * invoice_designs/design2.php
 *
 * Detailed GST invoice – corrected fixed alignment version.
 * All values are supplied dynamically by invoice_print.php.
 */

if (!defined('INVOICE_DESIGN_LOADED')) {
    exit('Direct access is not allowed.');
}

/*
 * This template must not echo or print anything.
 * PHP warnings/notices would corrupt the PDF output stream.
 */

if (!isset($pdf) || !($pdf instanceof FPDF)) {
    $pdf = new FPDF('P', 'mm', 'A4');
}

/* -------------------------------------------------------------------------
   Dynamic values
------------------------------------------------------------------------- */

$d2CompanyName    = trim((string)($company_name ?? ''));
$d2CompanyAddress = trim((string)($company_address ?? ''));
$d2CompanyPhone   = trim((string)($company_phone ?? ''));
$d2CompanyEmail   = trim((string)($company_email ?? ($settings['company_email'] ?? '')));
$d2CompanyGstin   = trim((string)($company_gstin ?? ''));
$d2CompanyTagline = trim((string)(
    $settings['company_tagline']
    ?? $settings['company_slogan']
    ?? $settings['slogan']
    ?? ''
));

/*
 * Logo priority:
 * 1) $company_logo from invoice_print.php
 * 2) invoice_settings.logo_path
 */
$d2CompanyLogo = trim((string)(
    $company_logo
    ?? $settings['logo_path']
    ?? ''
));

/*
 * Brand logos:
 * invoice_print.php already fetches active branch/shop brand logos into $brand_logos.
 * If selected brand logos are available, design2 prints them in the footer strip.
 */
$d2BrandLogos = is_array($brand_logos ?? null) ? $brand_logos : [];

/*
 * Header business logo rule:
 * - Show business logo only for business_id = 28.
 * - Other businesses will not show the header logo unless you change this flag.
 */
$d2BusinessId = (int)($business_id ?? ($invoice['business_id'] ?? 0));
$d2ShowBusinessLogo = ($d2BusinessId === 28);

/*
 * Signature logo option from invoice_settings:
 * Add these columns:
 * show_signature_logo TINYINT(1)
 * signature_logo_path VARCHAR(500)
 */
$d2ShowSignatureLogo = (int)(
    $settings['show_signature_logo']
    ?? $settings['use_signature_logo']
    ?? 0
) === 1;

$d2SignatureLogo = trim((string)(
    $settings['signature_logo_path']
    ?? $settings['authorised_signature_path']
    ?? $settings['signature_path']
    ?? ''
));

$d2InvoiceNumber  = trim((string)($invoice['invoice_number'] ?? ''));
$d2InvoiceDate    = trim((string)($invoice_date ?? ''));
$d2Transport      = trim((string)($transport ?? ''));
$d2WaybillNo      = trim((string)($waybill_no ?? ''));
$d2BuyerOrderNo   = trim((string)($invoice['buyer_order_no'] ?? ''));
$d2PaymentTerms   = trim((string)($invoice['payment_terms'] ?? ''));
$d2DueDateRaw     = trim((string)($invoice['credit_due_date'] ?? ''));

if ($d2DueDateRaw !== '') {
    $d2DueDate = date('d-m-Y', strtotime($d2DueDateRaw));
} else {
    $d2DueDate = $d2InvoiceDate;
}

$d2PlaceOfSupply  = trim((string)($place_of_supply ?? 'Tamil Nadu (33)'));

$d2CustomerName    = trim((string)($customer_name ?? 'Walk-in Customer'));
$d2CustomerPhone   = trim((string)($customer_phone ?? ''));
$d2CustomerAddress = trim((string)($customer_full_address ?? ''));
$d2CustomerGstin   = trim((string)($customer_gstin ?? ''));

$d2ShippingName    = trim((string)($shipping_name ?? ''));
$d2ShippingPhone   = trim((string)($shipping_contact ?? ''));
$d2ShippingAddress = trim((string)($shipping_full_address ?? ''));
$d2ShippingGstin   = trim((string)($shipping_gstin ?? ''));

if ($d2ShippingName === '' && $d2ShippingAddress === '') {
    $d2ShippingName    = $d2CustomerName;
    $d2ShippingPhone   = $d2CustomerPhone;
    $d2ShippingAddress = $d2CustomerAddress;
    $d2ShippingGstin   = $d2CustomerGstin;
}

$d2Subtotal      = (float)($subtotal ?? 0);
$d2TotalTaxable  = (float)($total_taxable ?? 0);
$d2TotalCgst     = (float)($total_cgst ?? 0);
$d2TotalSgst     = (float)($total_sgst ?? 0);
$d2TotalIgst     = (float)($total_igst ?? 0);
$d2GrandTotal    = (float)($grand_total ?? ($invoice['total'] ?? 0));
$d2Terms         = trim((string)($settings['invoice_terms'] ?? ''));
$d2Footer        = trim((string)($settings['invoice_footer'] ?? 'Thank you for your business!'));

if ($d2CompanyTagline === '') {
    $d2CompanyTagline = trim((string)($settings['invoice_slogan'] ?? ''));
}

if ($d2CompanyTagline === '') {
    $d2CompanyTagline = "Quality is not classy...it's priceless";
}

$d2TotalQty = 0.0;
foreach (($items ?? []) as $d2QtyItem) {
    $d2TotalQty += (float)($d2QtyItem['quantity'] ?? 0);
}

/* -------------------------------------------------------------------------
   Helpers
------------------------------------------------------------------------- */

$d2Text = static function ($value): string {
    if (function_exists('pdf_text_simple')) {
        return pdf_text_simple((string)$value);
    }

    $text = (string)$value;
    $converted = @iconv('UTF-8', 'windows-1252//TRANSLIT//IGNORE', $text);

    return $converted !== false
        ? $converted
        : preg_replace('/[^\x20-\x7E]/', '', $text);
};

$d2Money = static function ($value): string {
    if (function_exists('money')) {
        return money($value);
    }

    return number_format((float)$value, 2, '.', ',');
};

$d2Qty = static function ($value): string {
    if (function_exists('format_quantity')) {
        return format_quantity($value);
    }

    $value = (float)$value;

    return floor($value) == $value
        ? number_format($value, 0, '.', '')
        : number_format($value, 2, '.', '');
};

$d2Words = static function ($value): string {
    if (function_exists('number_to_words')) {
        return number_to_words($value);
    }

    return number_format((float)$value, 2) . ' Rupees Only';
};

$d2FitFont = static function (
    FPDF $pdf,
    string $text,
    float $width,
    float $max = 8.0,
    float $min = 5.0
): float {
    $size = $max;

    while ($size >= $min) {
        $pdf->SetFont('Arial', '', $size);

        if ($pdf->GetStringWidth($text) <= ($width - 2)) {
            return $size;
        }

        $size -= 0.5;
    }

    return $min;
};


$d2LimitLines = static function (
    FPDF $pdf,
    string $text,
    float $width,
    int $maxLines,
    float $fontSize = 6.0
): string {
    $text = trim(preg_replace('/\s+/', ' ', $text));

    if ($text === '') {
        return '';
    }

    $pdf->SetFont('Arial', '', $fontSize);
    $words = preg_split('/\s+/', $text);
    $lines = [];
    $line = '';

    foreach ($words as $word) {
        $test = $line === '' ? $word : $line . ' ' . $word;

        if ($pdf->GetStringWidth($test) <= $width) {
            $line = $test;
            continue;
        }

        if ($line !== '') {
            $lines[] = $line;
        }

        $line = $word;

        if (count($lines) >= $maxLines) {
            break;
        }
    }

    if ($line !== '' && count($lines) < $maxLines) {
        $lines[] = $line;
    }

    if (count($lines) === $maxLines && count($words) > 0) {
        $last = rtrim($lines[$maxLines - 1], '.');
        while ($pdf->GetStringWidth($last . '...') > $width && mb_strlen($last) > 1) {
            $last = mb_substr($last, 0, -1);
        }
        $lines[$maxLines - 1] = $last . '...';
    }

    return implode("\n", $lines);
};

$d2SafeLogoPath = static function (string $path): string {
    if ($path === '') {
        return '';
    }

    $candidates = [
        $path,
        __DIR__ . '/../' . ltrim($path, '/\\'),
        __DIR__ . '/' . ltrim($path, '/\\'),
    ];

    foreach ($candidates as $candidate) {
        if (is_file($candidate)) {
            $ext = strtolower(pathinfo($candidate, PATHINFO_EXTENSION));

            if (in_array($ext, ['jpg', 'jpeg', 'png'], true)) {
                return $candidate;
            }
        }
    }

    return '';
};

$d2DrawBrandLogos = static function (
    float $x,
    float $y,
    float $w,
    float $h
) use (
    $pdf,
    $d2BrandLogos,
    $d2SafeLogoPath
): void {
    if (empty($d2BrandLogos) || $h < 5) {
        return;
    }

    $logos = [];
    $gap = 3.0;
    $totalWidth = 0.0;

    foreach ($d2BrandLogos as $logoRow) {
        $logoPath = trim((string)($logoRow['logo_path'] ?? ''));

        if ($logoPath === '') {
            continue;
        }

        $safePath = $d2SafeLogoPath($logoPath);

        if ($safePath === '') {
            continue;
        }

        $width = (float)($logoRow['width_mm'] ?? 24);
        $height = (float)($logoRow['height_mm'] ?? 8);

        if ($width <= 0) {
            $width = 24;
        }

        if ($height <= 0) {
            $height = 8;
        }

        if ($width > 45) {
            $width = 45;
        }

        if ($height > ($h - 2)) {
            $ratio = ($h - 2) / $height;
            $height *= $ratio;
            $width *= $ratio;
        }

        $logos[] = [
            'path' => $safePath,
            'w' => $width,
            'h' => $height,
        ];

        $totalWidth += $width;
    }

    if (empty($logos)) {
        return;
    }

    $totalWidth += $gap * (count($logos) - 1);

    if ($totalWidth > ($w - 6)) {
        $scale = ($w - 6) / $totalWidth;
        $totalWidth = 0;

        foreach ($logos as $idx => $logo) {
            $logos[$idx]['w'] = $logo['w'] * $scale;
            $logos[$idx]['h'] = $logo['h'] * $scale;
            $totalWidth += $logos[$idx]['w'];
        }

        $totalWidth += $gap * (count($logos) - 1);
    }

    $currentX = $x + (($w - $totalWidth) / 2);

    foreach ($logos as $logo) {
        $logoY = $y + (($h - $logo['h']) / 2);
        $pdf->Image($logo['path'], $currentX, $logoY, $logo['w'], $logo['h']);
        $currentX += $logo['w'] + $gap;
    }
};

/* -------------------------------------------------------------------------
   Fixed-layout drawing methods
------------------------------------------------------------------------- */

$d2DrawOuterBorder = static function () use ($pdf): void {
    $pdf->SetDrawColor(0, 0, 0);
    $pdf->SetLineWidth(0.2);
    $pdf->Rect(5, 5, 200, 287);
};

$d2DrawMainHeader = static function () use (
    $pdf,
    $d2Text,
    $d2SafeLogoPath,
    $d2FitFont,
    $d2CompanyName,
    $d2CompanyAddress,
    $d2CompanyPhone,
    $d2CompanyEmail,
    $d2CompanyGstin,
    $d2CompanyTagline,
    $d2CompanyLogo,
    $d2ShowBusinessLogo
): void {
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->SetXY(5, 6);
    $pdf->Cell(200, 5, 'TAX INVOICE', 0, 1, 'C');

    $logo = $d2ShowBusinessLogo ? $d2SafeLogoPath($d2CompanyLogo) : '';

    if ($logo !== '') {
        $pdf->Image($logo, 10, 13, 36, 20);
    }

    /*
     * Center all header content to the full invoice body width (200 mm),
     * so the business name and details sit in the visual center of the page.
     */
    $pdf->SetTextColor(32, 61, 135);
    $companyFont = $d2FitFont($pdf, $d2Text($d2CompanyName), 120, 18.0, 11.0);
    $pdf->SetFont('Arial', 'B', $companyFont);
    $pdf->SetXY(5, 12);
    $pdf->Cell(200, 7, $d2Text($d2CompanyName), 0, 1, 'C');

    if ($d2CompanyTagline !== '') {
        $pdf->SetTextColor(90, 90, 90);
        $pdf->SetFont('Arial', 'I', 8);
        $pdf->SetXY(5, 19);
        $pdf->Cell(200, 4, $d2Text($d2CompanyTagline), 0, 1, 'C');
    }

    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetFont('Arial', '', 7.5);
    $pdf->SetXY(30, 24);
    $pdf->MultiCell(150, 3.5, $d2Text($d2CompanyAddress), 0, 'C');

    $contact = 'Contact: ' . $d2Text($d2CompanyPhone);

    if ($d2CompanyEmail !== '') {
        $contact .= '    Email: ' . $d2Text($d2CompanyEmail);
    }

    $pdf->SetXY(30, 32);
    $pdf->Cell(150, 3.5, $contact, 0, 1, 'C');

    $pdf->SetXY(30, 36);
    $pdf->Cell(
        150,
        3.5,
        'GSTIN: ' . ($d2CompanyGstin !== '' ? $d2Text($d2CompanyGstin) : '-'),
        0,
        1,
        'C'
    );

    $pdf->Line(5, 42, 205, 42);
};

$d2DrawMeta = static function () use (
    $pdf,
    $d2Text,
    $d2InvoiceNumber,
    $d2Transport,
    $d2CustomerName,
    $d2BuyerOrderNo,
    $d2PaymentTerms,
    $d2InvoiceDate,
    $d2WaybillNo,
    $d2CustomerPhone,
    $d2DueDate,
    $d2PlaceOfSupply
): void {
    $pdf->Line(105, 42, 105, 76);
    $pdf->Line(5, 76, 205, 76);

    $left = [
        ['Invoice No', $d2InvoiceNumber],
        ['Transport Name & NO', $d2Transport],
        ['Customer Name', $d2CustomerName],
        ["Buyer's Order No", $d2BuyerOrderNo],
        ['Terms Of Payment', $d2PaymentTerms],
    ];

    $right = [
        ['Date', $d2InvoiceDate],
        ['Way Bill No', $d2WaybillNo],
        ['Customer NO', $d2CustomerPhone],
        ['Date Of Supply', $d2InvoiceDate],
        ['Due Date', $d2DueDate],
        ['State / Code', $d2PlaceOfSupply],
    ];

    $drawColumn = static function (
        float $x,
        float $startY,
        float $labelWidth,
        array $rows
    ) use ($pdf, $d2Text): void {
        $y = $startY;

        foreach ($rows as $row) {
            $pdf->SetFont('Arial', '', 7.5);
            $pdf->SetXY($x, $y);
            $pdf->Cell($labelWidth, 4.5, $row[0], 0, 0, 'L');
            $pdf->Cell(4, 4.5, ':', 0, 0, 'C');

            $value = $d2Text((string)$row[1]);
            $font = 7.5;

            while ($font > 5.5) {
                $pdf->SetFont('Arial', '', $font);

                if ($pdf->GetStringWidth($value) <= 50) {
                    break;
                }

                $font -= 0.5;
            }

            $pdf->Cell(50, 4.5, $value, 0, 1, 'L');
            $y += 5;
        }
    };

    $drawColumn(8, 44, 40, $left);
    $drawColumn(108, 44, 38, $right);
};

$d2DrawParties = static function () use (
    $pdf,
    $d2Text,
    $d2CustomerName,
    $d2CustomerAddress,
    $d2CustomerGstin,
    $d2ShippingName,
    $d2ShippingAddress,
    $d2ShippingGstin
): void {
    $pdf->SetFillColor(190, 205, 230);
    $pdf->SetFont('Arial', 'B', 7.5);
    $pdf->SetXY(5, 76);
    $pdf->Cell(100, 6, 'DETAILS OF RECEIVER (BILLED TO)', 1, 0, 'C', true);
    $pdf->Cell(100, 6, 'DETAILS OF CONSIGNEE (SHIPPED TO)', 1, 1, 'C', true);

    $pdf->Rect(5, 82, 100, 30);
    $pdf->Rect(105, 82, 100, 30);

    $pdf->SetFont('Arial', 'B', 7.5);
    $pdf->SetXY(8, 85);
    $pdf->Cell(94, 4, $d2Text($d2CustomerName), 0, 1, 'L');

    $pdf->SetFont('Arial', '', 7);
    $pdf->SetXY(8, 90);
    $pdf->MultiCell(94, 3.6, $d2Text($d2CustomerAddress), 0, 'L');

    $pdf->SetFont('Arial', 'B', 7.5);
    $pdf->SetXY(108, 85);
    $pdf->Cell(94, 4, $d2Text($d2ShippingName), 0, 1, 'L');

    $pdf->SetFont('Arial', '', 7);
    $pdf->SetXY(108, 90);
    $pdf->MultiCell(94, 3.6, $d2Text($d2ShippingAddress), 0, 'L');

    $pdf->SetFont('Arial', '', 7);
    $pdf->SetXY(8, 106);
    $pdf->Cell(
        94,
        4,
        'GSTIN NO: ' . $d2Text($d2CustomerGstin !== '' ? $d2CustomerGstin : '-'),
        0,
        0,
        'L'
    );

    $pdf->SetXY(108, 106);
    $pdf->Cell(
        94,
        4,
        'GSTIN NO: ' . $d2Text($d2ShippingGstin !== '' ? $d2ShippingGstin : '-'),
        0,
        1,
        'L'
    );
};

$d2Columns = [
    ['w' => 12, 'label' => 'S.No'],
    ['w' => 64, 'label' => 'Description Of Goods'],
    ['w' => 24, 'label' => 'HSN/SAC'],
    ['w' => 22, 'label' => 'Qty'],
    ['w' => 23, 'label' => 'Rate'],
    ['w' => 17, 'label' => 'CGST'],
    ['w' => 17, 'label' => 'SGST'],
    ['w' => 21, 'label' => 'Amount'],
];

$d2DrawItemsHeader = static function () use ($pdf, $d2Columns): void {
    $pdf->SetFillColor(190, 205, 230);
    $pdf->SetFont('Arial', 'B', 6.8);
    $pdf->SetX(5);

    foreach ($d2Columns as $column) {
        $pdf->Cell($column['w'], 6, $column['label'], 1, 0, 'C', true);
    }

    $pdf->Ln();
};

$d2DrawContinuationHeader = static function () use (
    $pdf,
    $d2Text,
    $d2CompanyName,
    $d2InvoiceNumber,
    $d2DrawOuterBorder,
    $d2DrawItemsHeader
): void {
    $d2DrawOuterBorder();

    $pdf->SetFont('Arial', 'B', 10);
    $pdf->SetXY(8, 8);
    $pdf->Cell(120, 5, $d2Text($d2CompanyName), 0, 0, 'L');

    $pdf->SetFont('Arial', '', 8);
    $pdf->SetXY(125, 8);
    $pdf->Cell(75, 5, 'Invoice No: ' . $d2Text($d2InvoiceNumber), 0, 1, 'R');

    $pdf->SetY(18);
    $d2DrawItemsHeader();
};

/* -------------------------------------------------------------------------
   Render items over pages
------------------------------------------------------------------------- */

$pdf->AddPage('P', 'A4');
$pdf->SetMargins(5, 5, 5);
$pdf->SetAutoPageBreak(false);

$d2DrawOuterBorder();
$d2DrawMainHeader();
$d2DrawMeta();
$d2DrawParties();

$pdf->SetY(112);
$d2DrawItemsHeader();

$rowHeight = 5.5;
$firstPageRows = 13;
$otherPageRows = 40;
$currentPageRow = 0;
$itemIndex = 0;
$totalItems = count($items ?? []);

foreach (($items ?? []) as $item) {
    $limit = $pdf->PageNo() === 1 ? $firstPageRows : $otherPageRows;

    if ($currentPageRow >= $limit) {
        $pdf->AddPage('P', 'A4');
        $pdf->SetMargins(5, 5, 5);
        $pdf->SetAutoPageBreak(false);

        $d2DrawContinuationHeader();
        $currentPageRow = 0;
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

    $unit = function_exists('item_display_unit')
        ? item_display_unit($item)
        : (string)($item['product_unit'] ?? 'pcs');

    $qty = (float)($item['quantity'] ?? 0);
    $rate = (float)($item['unit_price'] ?? 0);
    $cgstRate = (float)($item['cgst_rate'] ?? 0);
    $sgstRate = (float)($item['sgst_rate'] ?? 0);

    $amount = isset($item['total_with_gst'])
        ? (float)$item['total_with_gst']
        : (
            isset($item['total_price'])
                ? (float)$item['total_price']
                : ($qty * $rate)
        );

    $values = [
        (string)$itemIndex,
        $d2Text($name),
        $d2Text($hsn),
        $d2Qty($qty) . ' ' . $d2Text($unit),
        $d2Money($rate),
        number_format($cgstRate, 1) . '%',
        number_format($sgstRate, 1) . '%',
        $d2Money($amount),
    ];

    $alignments = ['C', 'L', 'C', 'C', 'R', 'C', 'C', 'R'];

    $pdf->SetX(5);

    foreach ($d2Columns as $i => $column) {
        $fontSize = $d2FitFont($pdf, $values[$i], $column['w'], 7, 5);
        $pdf->SetFont('Arial', '', $fontSize);
        $pdf->Cell(
            $column['w'],
            $rowHeight,
            $values[$i],
            'LR',
            0,
            $alignments[$i]
        );
    }

    $pdf->Ln();
    $currentPageRow++;
}

/* -------------------------------------------------------------------------
   Ensure totals fit on a dedicated aligned page if needed
------------------------------------------------------------------------- */

$fixedTotalsTop = 184;

if ($pdf->PageNo() === 1) {
    $itemsStart = 116;
} else {
    $itemsStart = 24;
}

if ($pdf->GetY() > $fixedTotalsTop) {
    $pdf->AddPage('P', 'A4');
    $pdf->SetMargins(5, 5, 5);
    $pdf->SetAutoPageBreak(false);

    $d2DrawContinuationHeader();
}

/* Fill blank item area with vertical lines */
$currentY = $pdf->GetY();
$lineX = 5;

$pdf->Line($lineX, $currentY, $lineX, $fixedTotalsTop);

foreach ($d2Columns as $column) {
    $lineX += $column['w'];
    $pdf->Line($lineX, $currentY, $lineX, $fixedTotalsTop);
}

$pdf->Line(5, $fixedTotalsTop, 205, $fixedTotalsTop);
$pdf->SetY($fixedTotalsTop);

/* -------------------------------------------------------------------------
   Fixed aligned totals section
------------------------------------------------------------------------- */

$pdf->SetFillColor(190, 205, 230);
$pdf->SetFont('Arial', 'B', 7);
$pdf->SetX(5);
$pdf->Cell(100, 6, 'Total Quantity', 1, 0, 'R', true);
$pdf->Cell(22, 6, $d2Qty($d2TotalQty), 1, 0, 'C');
$pdf->Cell(57, 6, 'Sub Total', 1, 0, 'R', true);
$pdf->Cell(21, 6, $d2Money($d2Subtotal), 1, 1, 'R');

$pdf->SetX(5);
$pdf->SetFont('Arial', 'B', 7);
$pdf->Cell(33, 6, 'In Words', 1, 0, 'C', true);
$pdf->SetFont('Arial', '', 7);
$pdf->Cell(167, 6, $d2Text($d2Words($d2GrandTotal)), 1, 1, 'L');

$pdf->SetFont('Arial', 'B', 7);
$pdf->SetX(5);
$pdf->Cell(33, 6, 'GST Rate', 1, 0, 'C', true);
$pdf->Cell(43, 6, 'Taxable Value', 1, 0, 'C', true);
$pdf->Cell(43, 6, 'CGST Tax', 1, 0, 'C', true);
$pdf->Cell(43, 6, 'SGST Tax', 1, 0, 'C', true);
$pdf->Cell(38, 6, 'Sub Total', 1, 1, 'C', true);

$totalTaxRate = 0.0;

if ($d2TotalTaxable > 0) {
    $totalTaxRate = (($d2TotalCgst + $d2TotalSgst + $d2TotalIgst) / $d2TotalTaxable) * 100;
}

$pdf->SetFont('Arial', '', 7);
$pdf->SetX(5);
$pdf->Cell(33, 6, number_format($totalTaxRate, 1) . '%', 1, 0, 'C');
$pdf->Cell(43, 6, $d2Money($d2TotalTaxable), 1, 0, 'R');
$pdf->Cell(43, 6, $d2Money($d2TotalCgst), 1, 0, 'R');
$pdf->Cell(43, 6, $d2Money($d2TotalSgst), 1, 0, 'R');
$pdf->Cell(38, 6, $d2Money($d2Subtotal), 1, 1, 'R');

$pdf->SetFont('Arial', 'B', 7);
$pdf->SetX(5);
$pdf->Cell(157, 6, '', 1, 0);
$pdf->Cell(22, 6, 'Add CGST', 1, 0, 'R');
$pdf->SetFont('Arial', '', 7);
$pdf->Cell(21, 6, $d2Money($d2TotalCgst), 1, 1, 'R');

$pdf->SetFont('Arial', 'B', 7);
$pdf->SetX(5);
$pdf->Cell(157, 6, '', 1, 0);
$pdf->Cell(22, 6, 'Add SGST', 1, 0, 'R');
$pdf->SetFont('Arial', '', 7);
$pdf->Cell(21, 6, $d2Money($d2TotalSgst), 1, 1, 'R');

if ($d2TotalIgst > 0) {
    $pdf->SetFont('Arial', 'B', 7);
    $pdf->SetX(5);
    $pdf->Cell(157, 6, '', 1, 0);
    $pdf->Cell(22, 6, 'Add IGST', 1, 0, 'R');
    $pdf->SetFont('Arial', '', 7);
    $pdf->Cell(21, 6, $d2Money($d2TotalIgst), 1, 1, 'R');
}

$pdf->SetFillColor(190, 205, 230);
$pdf->SetFont('Arial', 'B', 8);
$pdf->SetX(5);
$pdf->Cell(157, 6, '', 1, 0);
$pdf->Cell(22, 6, 'Grand Total', 1, 0, 'R', true);
$pdf->Cell(21, 6, $d2Money($d2GrandTotal), 1, 1, 'R', true);

/* -------------------------------------------------------------------------
   Bank details – fixed two equal columns
------------------------------------------------------------------------- */

$pdf->SetFillColor(190, 205, 230);
$pdf->SetFont('Arial', 'B', 8);
$pdf->SetX(5);
$pdf->Cell(200, 6, 'Bank Details', 1, 1, 'C', true);

$bankTop = $pdf->GetY();
$bankHeight = 29;

$pdf->Rect(5, $bankTop, 100, $bankHeight);
$pdf->Rect(105, $bankTop, 100, $bankHeight);

$bank1 = $bank_accounts[0] ?? [];
$bank2 = $bank_accounts[1] ?? [];

$drawBank = static function (
    float $x,
    float $y,
    array $bank,
    string $title
) use ($pdf, $d2Text, $d2FitFont): void {
    $pdf->SetFont('Arial', 'B', 7);
    $pdf->SetXY($x + 3, $y + 3);
    $pdf->Cell(94, 4, $title, 0, 1, 'L');

    $rows = [
        ['Name', $bank['account_holder_name'] ?? ''],
        ['Bank', $bank['bank_name'] ?? ''],
        ['A/C No', $bank['account_number'] ?? ''],
        ['IFSC', $bank['ifsc_code'] ?? ''],
        ['Branch', $bank['branch_name'] ?? ''],
    ];

    $rowY = $y + 7.5;

    foreach ($rows as $row) {
        $pdf->SetFont('Arial', '', 6.5);
        $pdf->SetXY($x + 3, $rowY);
        $pdf->Cell(19, 3.5, $row[0], 0, 0, 'L');
        $pdf->Cell(3, 3.5, ':', 0, 0, 'C');

        $value = $d2Text((string)$row[1]);
        $size = $d2FitFont($pdf, $value, 69, 6.5, 5);
        $pdf->SetFont('Arial', '', $size);
        $pdf->Cell(69, 3.5, $value, 0, 1, 'L');

        $rowY += 3.7;
    }
};

$drawBank(5, $bankTop, $bank1, 'Bank Account 1');
$drawBank(105, $bankTop, $bank2, 'Bank Account 2');

$pdf->SetY($bankTop + $bankHeight);

/* -------------------------------------------------------------------------
   Bottom footer – fixed alignment like given format
------------------------------------------------------------------------- */

$footerTop = $pdf->GetY();

/*
 * Required format:
 * Left   : Receivers Signature
 * Middle : Terms text only
 * Right  : For Company, logo, Seal & Authorised Signatory
 * Bottom : Brand logos strip
 * No extra blue thank-you strip in Design 2.
 */
$brandStripHeight = !empty($d2BrandLogos) ? 10 : 0;
$footerHeight = 25;

/* Keep inside A4 page safe area */
$maxBottomY = 286;
if (($footerTop + $footerHeight + $brandStripHeight) > $maxBottomY) {
    $footerHeight = max(20, $maxBottomY - $footerTop - $brandStripHeight);
}

$leftX  = 5;
$midX   = 55;
$rightX = 155;
$colH   = $footerHeight;

/* 3 footer boxes */
$pdf->SetDrawColor(0, 0, 0);
$pdf->Rect($leftX,  $footerTop, 50,  $colH);
$pdf->Rect($midX,   $footerTop, 100, $colH);
$pdf->Rect($rightX, $footerTop, 50,  $colH);

/* Left receiver signature area */
$pdf->SetFont('Arial', '', 8);
$pdf->SetXY($leftX + 1.5, $footerTop + ($colH / 2) - 2);
$pdf->Cell(47, 4, 'Receivers Signature', 0, 1, 'L');

/* Middle terms area - print each condition one by one */
$pdf->SetFont('Arial', '', 5.8);

$termsRaw = str_replace(["\r\n", "\r"], "\n", (string)$d2Terms);
$termsLines = array_values(array_filter(array_map('trim', explode("\n", $termsRaw)), static function ($line) {
    return $line !== '';
}));

/*
 * If old saved terms are in one line like:
 * 1.Terms 2.Terms 3.Terms
 * split them into separate list rows.
 */
if (count($termsLines) <= 1 && trim($termsRaw) !== '') {
    preg_match_all('/(?:^|\s)(\d+\..*?)(?=\s+\d+\.|$)/u', trim($termsRaw), $matches);
    if (!empty($matches[1])) {
        $termsLines = array_map('trim', $matches[1]);
    }
}

$termY = $footerTop + 3;
$termLineHeight = 3.4;
$maxTermLines = max(4, (int)floor(($colH - 4) / $termLineHeight));

$printedTerms = 0;
foreach ($termsLines as $termLine) {
    if ($printedTerms >= $maxTermLines) {
        break;
    }

    $pdf->SetFont('Arial', '', 5.8);
    $pdf->SetXY($midX + 3, $termY);
    $pdf->MultiCell(94, $termLineHeight, $d2Text($termLine), 0, 'L');

    $termY += $termLineHeight;
    $printedTerms++;
}

/* Right signature area - clean vertical alignment */
$pdf->SetFont('Arial', '', 8.2);
$pdf->SetXY($rightX + 1, $footerTop + 3);
$pdf->Cell(48, 4, 'For ' . $d2Text($d2CompanyName), 0, 1, 'C');

/* Optional authorised signature logo from invoice_settings */
$d2SignatureSafePath = ($d2ShowSignatureLogo && $d2SignatureLogo !== '')
    ? $d2SafeLogoPath($d2SignatureLogo)
    : '';

/*
 * Signature logo position:
 * Company name at top.
 * Signature logo must be directly above "Seal & Authorised Signatory".
 */
$sealTextY = $footerTop + $colH - 5.7;

if ($d2SignatureSafePath !== '') {
    $signatureW = 18;
    $signatureH = 8.5;
    $signatureX = $rightX + ((50 - $signatureW) / 2);
    $signatureY = $sealTextY - $signatureH - 1.2; // directly above seal text

    if ($signatureY < ($footerTop + 8)) {
        $signatureY = $footerTop + 8;
    }

    $pdf->Image($d2SignatureSafePath, $signatureX, $signatureY, $signatureW, $signatureH);
}

$pdf->SetFont('Arial', '', 7.8);
$pdf->SetXY($rightX + 1, $sealTextY);
$pdf->Cell(48, 4.5, 'Seal & Authorised Signatory', 0, 1, 'C');

/* Selected brand logos strip - directly below footer boxes */
if ($brandStripHeight > 0) {
    $brandTop = $footerTop + $footerHeight;
    $pdf->SetFillColor(255, 255, 255);
    $pdf->Rect(5, $brandTop, 200, $brandStripHeight, 'DF');
    $d2DrawBrandLogos(6, $brandTop + 1, 198, $brandStripHeight - 2);
}

/* Design 2 intentionally does not print extra footer message strip */