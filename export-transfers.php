<?php
date_default_timezone_set('Asia/Kolkata');
session_start();
require_once 'config/database.php';

// ==================== SECURITY & PERMISSION CHECK ====================
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

$user_role = $_SESSION['role'] ?? '';
$allowed_roles = ['admin', 'warehouse_manager', 'stock_manager', 'shop_manager'];
if (!in_array($user_role, $allowed_roles)) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

// Get current business ID
$current_business_id = $_SESSION['current_business_id'] ?? null;
if (!$current_business_id) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Business not selected']);
    exit();
}

// ==================== GET FILTERS FROM REQUEST ====================
$search = trim($_POST['search'] ?? $_GET['search'] ?? '');
$status_filter = $_POST['status'] ?? $_GET['status'] ?? 'all';
$from_date = $_POST['from_date'] ?? $_GET['from_date'] ?? '';
$to_date = $_POST['to_date'] ?? $_GET['to_date'] ?? '';
$export_type = $_POST['export_type'] ?? $_GET['export_type'] ?? 'csv';

// Build WHERE conditions
$where = "WHERE st.business_id = ?";
$params = [$current_business_id];

if ($search !== '') {
    $where .= " AND (st.transfer_number LIKE ? OR fs.shop_name LIKE ? OR ts.shop_name LIKE ? OR u.full_name LIKE ?)";
    $like = "%$search%";
    $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like;
}

if ($status_filter !== 'all') {
    $where .= " AND st.status = ?";
    $params[] = $status_filter;
}

if ($from_date !== '') {
    $where .= " AND DATE(st.transfer_date) >= ?";
    $params[] = $from_date;
}

if ($to_date !== '') {
    $where .= " AND DATE(st.transfer_date) <= ?";
    $params[] = $to_date;
}

try {
    // Get all transfers with complete details
    $sql = "
        SELECT 
            st.*,
            fs.shop_name as from_shop_name,
            ts.shop_name as to_shop_name,
            u.full_name as created_by_name,
            COALESCE(SUM(sti.quantity), 0) as total_quantity,
            COALESCE(COUNT(DISTINCT sti.product_id), 0) as unique_items,
            COALESCE(SUM(p.retail_price * sti.quantity), 0) as estimated_value,
            GROUP_CONCAT(DISTINCT CONCAT(
                'Product: ', p.product_name, 
                ' (', p.product_code, ')',
                ' - Qty: ', sti.quantity,
                CASE WHEN sti.return_quantity > 0 THEN CONCAT(' | Returns: ', sti.return_quantity) ELSE '' END,
                ' | Status: ', sti.status
            ) SEPARATOR '; ') as items_details
        FROM stock_transfers st
        LEFT JOIN shops fs ON st.from_shop_id = fs.id AND fs.business_id = st.business_id
        LEFT JOIN shops ts ON st.to_shop_id = ts.id AND ts.business_id = st.business_id
        LEFT JOIN users u ON st.created_by = u.id
        LEFT JOIN stock_transfer_items sti ON st.id = sti.stock_transfer_id AND sti.business_id = st.business_id
        LEFT JOIN products p ON sti.product_id = p.id AND p.business_id = st.business_id
        $where
        GROUP BY st.id
        ORDER BY st.created_at DESC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $transfers = $stmt->fetchAll();

    // Get business name for filename
    $business_name = $_SESSION['current_business_name'] ?? 'Business';
    $filename = "stock_transfers_" . date('Y-m-d_H-i-s') . "_" . str_replace(' ', '_', $business_name) . ".$export_type";

    // Generate export based on type
    if ($export_type === 'csv') {
        exportToCSV($transfers, $filename);
    } elseif ($export_type === 'excel') {
        exportToExcel($transfers, $filename);
    } else {
        exportToPDF($transfers, $filename);
    }

} catch (PDOException $e) {
    error_log("Export Error: " . $e->getMessage());
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Database error occurred']);
    exit();
}

// ==================== CSV EXPORT FUNCTION ====================
function exportToCSV($data, $filename) {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');

    // Add BOM for UTF-8
    echo "\xEF\xBB\xBF";

    $output = fopen('php://output', 'w');
    
    // Headers
    fputcsv($output, [
        'Transfer No',
        'Transfer Date',
        'From Shop',
        'To Shop',
        'Status',
        'Total Items',
        'Total Quantity',
        'Estimated Value',
        'Created By',
        'Created At',
        'Expected Delivery',
        'Notes'
    ]);

    // Data rows
    foreach ($data as $row) {
        fputcsv($output, [
            $row['transfer_number'],
            date('d/m/Y', strtotime($row['transfer_date'])),
            $row['from_shop_name'],
            $row['to_shop_name'],
            ucwords(str_replace('_', ' ', $row['status'])),
            $row['unique_items'],
            $row['total_quantity'],
            '₹' . number_format($row['estimated_value'], 2),
            $row['created_by_name'] ?? 'N/A',
            date('d/m/Y h:i A', strtotime($row['created_at'])),
            $row['expected_delivery_date'] ? date('d/m/Y', strtotime($row['expected_delivery_date'])) : 'N/A',
            $row['notes'] ?? ''
        ]);
    }

    fclose($output);
    exit();
}

// ==================== EXCEL EXPORT FUNCTION ====================
function exportToExcel($data, $filename) {
    require_once 'vendor/autoload.php';
    
    $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    
    // Set properties
    $spreadsheet->getProperties()
        ->setCreator($_SESSION['full_name'] ?? 'System')
        ->setTitle('Stock Transfers Report')
        ->setSubject('Stock Transfers Data')
        ->setDescription('Export of stock transfers data')
        ->setKeywords('stock transfers export')
        ->setCategory('Report');

    // Headers
    $headers = [
        'Transfer Number',
        'Transfer Date',
        'From Shop',
        'To Shop',
        'Status',
        'Items Count',
        'Total Quantity',
        'Estimated Value (₹)',
        'Created By',
        'Created Date',
        'Created Time',
        'Expected Delivery',
        'Notes'
    ];
    
    $sheet->fromArray($headers, null, 'A1');
    
    // Style headers
    $headerStyle = [
        'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
        'fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['rgb' => '4472C4']]
    ];
    $sheet->getStyle('A1:M1')->applyFromArray($headerStyle);
    
    // Data rows
    $row = 2;
    foreach ($data as $item) {
        $sheet->setCellValue('A' . $row, $item['transfer_number']);
        $sheet->setCellValue('B' . $row, date('d/m/Y', strtotime($item['transfer_date'])));
        $sheet->setCellValue('C' . $row, $item['from_shop_name']);
        $sheet->setCellValue('D' . $row, $item['to_shop_name']);
        $sheet->setCellValue('E' . $row, ucwords(str_replace('_', ' ', $item['status'])));
        $sheet->setCellValue('F' . $row, $item['unique_items']);
        $sheet->setCellValue('G' . $row, $item['total_quantity']);
        $sheet->setCellValue('H' . $row, $item['estimated_value']);
        $sheet->setCellValue('I' . $row, $item['created_by_name'] ?? 'N/A');
        $sheet->setCellValue('J' . $row, date('d/m/Y', strtotime($item['created_at'])));
        $sheet->setCellValue('K' . $row, date('h:i A', strtotime($item['created_at'])));
        $sheet->setCellValue('L' . $row, $item['expected_delivery_date'] ? date('d/m/Y', strtotime($item['expected_delivery_date'])) : 'N/A');
        $sheet->setCellValue('M' . $row, $item['notes'] ?? '');
        
        // Format currency
        $sheet->getStyle('H' . $row)->getNumberFormat()->setFormatCode('"₹"#,##0.00');
        
        $row++;
    }
    
    // Auto size columns
    foreach (range('A', 'M') as $col) {
        $sheet->getColumnDimension($col)->setAutoSize(true);
    }
    
    // Set border
    $lastRow = $row - 1;
    $borderStyle = [
        'borders' => [
            'outline' => ['borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN],
            'inside' => ['borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN]
        ]
    ];
    $sheet->getStyle('A1:M' . $lastRow)->applyFromArray($borderStyle);
    
    // Set header row height
    $sheet->getRowDimension(1)->setRowHeight(25);
    
    // Export
    $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
    
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0');
    
    $writer->save('php://output');
    exit();
}

// ==================== PDF EXPORT FUNCTION ====================
function exportToPDF($data, $filename) {
    require_once 'vendor/autoload.php';
    
    $pdf = new \Mpdf\Mpdf([
        'mode' => 'utf-8',
        'format' => 'A4-L',
        'margin_left' => 10,
        'margin_right' => 10,
        'margin_top' => 15,
        'margin_bottom' => 15,
        'margin_header' => 5,
        'margin_footer' => 5
    ]);
    
    // PDF Header
    $pdf->SetHTMLHeader('
        <div style="text-align: center; border-bottom: 1px solid #ddd; padding-bottom: 10px; margin-bottom: 20px;">
            <h2 style="margin: 0; color: #333;">' . htmlspecialchars($_SESSION['current_business_name'] ?? 'Business') . '</h2>
            <h3 style="margin: 5px 0; color: #666;">Stock Transfers Report</h3>
            <p style="margin: 0; color: #888;">Generated on: ' . date('d/m/Y h:i A') . '</p>
        </div>
    ');
    
    // Summary section
    $summary = '
        <div style="margin-bottom: 20px; background: #f8f9fa; padding: 15px; border-radius: 5px; border: 1px solid #ddd;">
            <table width="100%" style="border-collapse: collapse;">
                <tr>
                    <td width="33%" style="padding: 5px;"><strong>Total Transfers:</strong> ' . count($data) . '</td>
                    <td width="33%" style="padding: 5px;"><strong>Export Date:</strong> ' . date('d/m/Y') . '</td>
                    <td width="33%" style="padding: 5px;"><strong>Generated By:</strong> ' . htmlspecialchars($_SESSION['full_name'] ?? 'System') . '</td>
                </tr>
            </table>
        </div>
    ';
    
    $pdf->WriteHTML($summary);
    
    // Table headers
    $html = '
        <style>
            table { width: 100%; border-collapse: collapse; font-size: 10px; }
            th { background-color: #007bff; color: white; padding: 8px; text-align: left; }
            td { padding: 6px; border: 1px solid #ddd; }
            tr:nth-child(even) { background-color: #f8f9fa; }
            .status-pending { background-color: #fff3cd; color: #856404; }
            .status-approved { background-color: #d1ecf1; color: #0c5460; }
            .status-in_transit { background-color: #cce5ff; color: #004085; }
            .status-delivered { background-color: #d4edda; color: #155724; }
            .status-cancelled { background-color: #f8d7da; color: #721c24; }
        </style>
        
        <table>
            <thead>
                <tr>
                    <th width="10%">Transfer No</th>
                    <th width="8%">Date</th>
                    <th width="15%">From Shop</th>
                    <th width="15%">To Shop</th>
                    <th width="8%">Status</th>
                    <th width="6%">Items</th>
                    <th width="8%">Qty</th>
                    <th width="10%">Value (₹)</th>
                    <th width="10%">Created By</th>
                    <th width="10%">Expected</th>
                </tr>
            </thead>
            <tbody>
    ';
    
    foreach ($data as $row) {
        $status_class = 'status-' . $row['status'];
        $html .= '
            <tr>
                <td>' . htmlspecialchars($row['transfer_number']) . '</td>
                <td>' . date('d/m/Y', strtotime($row['transfer_date'])) . '</td>
                <td>' . htmlspecialchars($row['from_shop_name']) . '</td>
                <td>' . htmlspecialchars($row['to_shop_name']) . '</td>
                <td class="' . $status_class . '">' . ucwords(str_replace('_', ' ', $row['status'])) . '</td>
                <td>' . $row['unique_items'] . '</td>
                <td>' . $row['total_quantity'] . '</td>
                <td>' . number_format($row['estimated_value'], 2) . '</td>
                <td>' . htmlspecialchars($row['created_by_name'] ?? 'N/A') . '</td>
                <td>' . ($row['expected_delivery_date'] ? date('d/m/Y', strtotime($row['expected_delivery_date'])) : 'N/A') . '</td>
            </tr>
        ';
    }
    
    $html .= '</tbody></table>';
    
    $pdf->WriteHTML($html);
    
    // Footer
    $pdf->SetHTMLFooter('
        <table width="100%" style="border-top: 1px solid #ddd; padding-top: 5px; font-size: 8px; color: #666;">
            <tr>
                <td width="50%">Page {PAGENO} of {nbpg}</td>
                <td width="50%" align="right">© ' . date('Y') . ' ' . htmlspecialchars($_SESSION['current_business_name'] ?? 'Business') . '</td>
            </tr>
        </table>
    ');
    
    // Output PDF
    $pdf->Output($filename, 'D');
    exit();
}