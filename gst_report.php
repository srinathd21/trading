<?php
date_default_timezone_set('Asia/Kolkata');
session_start();
require_once 'config/database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

if (!in_array($_SESSION['role'], ['admin', 'warehouse_manager', 'shop_manager'])) {
    header('Location: dashboard.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$current_business_id = $_SESSION['current_business_id'] ?? null;

if (!$current_business_id) {
    $_SESSION['error'] = "Please select a business first.";
    header('Location: select_shop.php');
    exit();
}

// Default date range (current month)
$start_date = date('Y-m-01');
$end_date = date('Y-m-t');

// Get filters
$filter_start_date = $_GET['start_date'] ?? $start_date;
$filter_end_date = $_GET['end_date'] ?? $end_date;
$shop_id = $_GET['shop_id'] ?? '';
$gst_type = $_GET['gst_type'] ?? 'all';
$report_type = $_GET['report_type'] ?? 'summary';
$export_type = $_GET['export'] ?? ''; // excel, json, csv

// Get shops for filter
$shops = $pdo->prepare("
    SELECT id, shop_name, shop_code 
    FROM shops 
    WHERE business_id = ? 
    AND is_active = 1 
    ORDER BY shop_name
");
$shops->execute([$current_business_id]);
$shops = $shops->fetchAll();

// Function to get GST summary
function getGSTSummary($pdo, $business_id, $start_date, $end_date, $shop_id) {
    $params = [$business_id, $start_date, $end_date];
    $shop_condition = '';
    
    if ($shop_id && $shop_id !== 'all') {
        $shop_condition = " AND i.shop_id = ?";
        $params[] = $shop_id;
    }
    
    $query = "
        SELECT 
            DATE(i.created_at) as invoice_date,
            i.id as invoice_id,
            i.invoice_number,
            i.customer_type,
            i.gst_status,
            s.shop_name,
            c.name as customer_name,
            c.gstin as customer_gstin,
            SUM(ii.taxable_value) as total_taxable_value,
            SUM(ii.cgst_amount) as total_cgst,
            SUM(ii.sgst_amount) as total_sgst,
            SUM(ii.igst_amount) as total_igst,
            SUM(ii.cgst_amount + ii.sgst_amount + ii.igst_amount) as total_gst,
            SUM(ii.total_with_gst) as invoice_total
        FROM invoices i
        INNER JOIN invoice_items ii ON i.id = ii.invoice_id
        INNER JOIN shops s ON i.shop_id = s.id
        INNER JOIN customers c ON i.customer_id = c.id
        WHERE i.business_id = ?
        AND DATE(i.created_at) BETWEEN ? AND ?
        AND i.gst_status = 1
        $shop_condition
        GROUP BY i.id, DATE(i.created_at)
        ORDER BY i.created_at DESC
    ";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

// Function to get HSN-wise GST summary
function getHSNWiseGST($pdo, $business_id, $start_date, $end_date, $shop_id) {
    $params = [$business_id, $start_date, $end_date];
    $shop_condition = '';
    
    if ($shop_id && $shop_id !== 'all') {
        $shop_condition = " AND i.shop_id = ?";
        $params[] = $shop_id;
    }
    
    $query = "
        SELECT 
            ii.hsn_code,
            p.product_name,
            COUNT(DISTINCT i.id) as invoice_count,
            SUM(ii.quantity) as total_quantity,
            SUM(ii.taxable_value) as total_taxable_value,
            SUM(ii.cgst_amount) as total_cgst,
            SUM(ii.sgst_amount) as total_sgst,
            SUM(ii.igst_amount) as total_igst,
            ROUND(AVG(ii.cgst_rate), 2) as avg_cgst_rate,
            ROUND(AVG(ii.sgst_rate), 2) as avg_sgst_rate,
            ROUND(AVG(ii.igst_rate), 2) as avg_igst_rate
        FROM invoice_items ii
        INNER JOIN invoices i ON ii.invoice_id = i.id
        INNER JOIN products p ON ii.product_id = p.id
        WHERE i.business_id = ?
        AND DATE(i.created_at) BETWEEN ? AND ?
        AND i.gst_status = 1
        $shop_condition
        GROUP BY ii.hsn_code, p.product_name
        HAVING ii.hsn_code IS NOT NULL
        ORDER BY total_taxable_value DESC
    ";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

// Function to get monthly GST trend
function getMonthlyGSTTrend($pdo, $business_id, $year, $shop_id) {
    $params = [$business_id, $year . '-01-01', $year . '-12-31'];
    $shop_condition = '';
    
    if ($shop_id && $shop_id !== 'all') {
        $shop_condition = " AND i.shop_id = ?";
        $params[] = $shop_id;
    }
    
    $query = "
        SELECT 
            DATE_FORMAT(i.created_at, '%Y-%m') as month,
            DATE_FORMAT(i.created_at, '%M %Y') as month_name,
            COUNT(DISTINCT i.id) as invoice_count,
            SUM(ii.taxable_value) as total_taxable_value,
            SUM(ii.cgst_amount) as total_cgst,
            SUM(ii.sgst_amount) as total_sgst,
            SUM(ii.igst_amount) as total_igst,
            SUM(ii.cgst_amount + ii.sgst_amount + ii.igst_amount) as total_gst
        FROM invoices i
        INNER JOIN invoice_items ii ON i.id = ii.invoice_id
        WHERE i.business_id = ?
        AND DATE(i.created_at) BETWEEN ? AND ?
        AND i.gst_status = 1
        $shop_condition
        GROUP BY DATE_FORMAT(i.created_at, '%Y-%m')
        ORDER BY month
    ";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

// Get reports based on type
if ($report_type === 'summary') {
    $gst_summary = getGSTSummary($pdo, $current_business_id, $filter_start_date, $filter_end_date, $shop_id);
} elseif ($report_type === 'hsn') {
    $hsn_summary = getHSNWiseGST($pdo, $current_business_id, $filter_start_date, $filter_end_date, $shop_id);
} elseif ($report_type === 'monthly') {
    $year = date('Y');
    if (isset($_GET['year'])) {
        $year = $_GET['year'];
    }
    $monthly_trend = getMonthlyGSTTrend($pdo, $current_business_id, $year, $shop_id);
}

// Calculate totals for invoice summary
$total_taxable = 0;
$total_cgst = 0;
$total_sgst = 0;
$total_igst = 0;
$total_gst = 0;
$total_invoices = 0;
$total_invoice_amount = 0;

if ($report_type === 'summary' && isset($gst_summary)) {
    foreach ($gst_summary as $row) {
        $total_taxable += $row['total_taxable_value'];
        $total_cgst += $row['total_cgst'];
        $total_sgst += $row['total_sgst'];
        $total_igst += $row['total_igst'];
        $total_gst += $row['total_gst'];
        $total_invoice_amount += $row['invoice_total'];
        $total_invoices++;
    }
}

if ($report_type === 'hsn' && isset($hsn_summary)) {
    foreach ($hsn_summary as $row) {
        $total_taxable += $row['total_taxable_value'];
        $total_cgst += $row['total_cgst'];
        $total_sgst += $row['total_sgst'];
        $total_igst += $row['total_igst'];
    }
}

// Get GST rates for reference
$gst_rates = $pdo->prepare("
    SELECT hsn_code, description, cgst_rate, sgst_rate, igst_rate, status 
    FROM gst_rates 
    WHERE business_id = ? 
    ORDER BY hsn_code
");
$gst_rates->execute([$current_business_id]);
$gst_rates_list = $gst_rates->fetchAll();

// Export functionality
if ($export_type === 'excel') {
    exportToExcel($report_type, $filter_start_date, $filter_end_date, $shop_id);
    exit();
} elseif ($export_type === 'json') {
    exportToJSON($report_type, $filter_start_date, $filter_end_date, $shop_id);
    exit();
} elseif ($export_type === 'csv') {
    exportToCSV($report_type, $filter_start_date, $filter_end_date, $shop_id);
    exit();
}

// Export to CSV function
function exportToCSV($report_type, $start_date, $end_date, $shop_id) {
    global $pdo, $current_business_id, $shops;
    
    $filename = "gst_report_" . date('Y_m_d_H_i_s') . ".csv";
    
    // Set headers for CSV download
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    
    // Create output stream
    $output = fopen('php://output', 'w');
    
    // Add BOM for UTF-8
    fwrite($output, "\xEF\xBB\xBF");
    
    // Get shop name
    $shop_name = "All Shops";
    if ($shop_id !== 'all') {
        foreach ($shops as $shop) {
            if ($shop['id'] == $shop_id) {
                $shop_name = $shop['shop_name'];
                break;
            }
        }
    }
    
    // Report header
    fputcsv($output, ['GST Report - ' . ucfirst($report_type)]);
    fputcsv($output, ['Period: ' . date('d M Y', strtotime($start_date)) . ' to ' . date('d M Y', strtotime($end_date))]);
    fputcsv($output, ['Shop: ' . $shop_name]);
    fputcsv($output, ['Generated on: ' . date('d M Y h:i A')]);
    fputcsv($output, ['']); // Empty row
    
    if ($report_type === 'summary') {
        $data = getGSTSummary($pdo, $current_business_id, $start_date, $end_date, $shop_id);
        
        // Table headers
        fputcsv($output, ['Date', 'Invoice No', 'Customer', 'Customer Type', 'Shop', 'Taxable Value', 'CGST', 'SGST', 'IGST', 'Total GST', 'Invoice Total']);
        
        // Data rows
        $grand_taxable = 0;
        $grand_cgst = 0;
        $grand_sgst = 0;
        $grand_igst = 0;
        $grand_gst = 0;
        $grand_total = 0;
        
        foreach ($data as $row) {
            fputcsv($output, [
                date('d M Y', strtotime($row['invoice_date'])),
                $row['invoice_number'],
                $row['customer_name'],
                $row['customer_type'],
                $row['shop_name'],
                number_format($row['total_taxable_value'], 2, '.', ''),
                number_format($row['total_cgst'], 2, '.', ''),
                number_format($row['total_sgst'], 2, '.', ''),
                number_format($row['total_igst'], 2, '.', ''),
                number_format($row['total_gst'], 2, '.', ''),
                number_format($row['invoice_total'], 2, '.', '')
            ]);
            
            $grand_taxable += $row['total_taxable_value'];
            $grand_cgst += $row['total_cgst'];
            $grand_sgst += $row['total_sgst'];
            $grand_igst += $row['total_igst'];
            $grand_gst += $row['total_gst'];
            $grand_total += $row['invoice_total'];
        }
        
        // Empty row
        fputcsv($output, ['']);
        
        // Summary row
        fputcsv($output, ['GRAND TOTALS', '', '', '', '',
            number_format($grand_taxable, 2, '.', ''),
            number_format($grand_cgst, 2, '.', ''),
            number_format($grand_sgst, 2, '.', ''),
            number_format($grand_igst, 2, '.', ''),
            number_format($grand_gst, 2, '.', ''),
            number_format($grand_total, 2, '.', '')
        ]);
        
        // Empty row
        fputcsv($output, ['']);
        
        // Overall summary
        fputcsv($output, ['OVERALL SUMMARY']);
        fputcsv($output, ['Total Invoices', count($data)]);
        fputcsv($output, ['Total Taxable Value', '₹' . number_format($grand_taxable, 2, '.', '')]);
        fputcsv($output, ['Total CGST', '₹' . number_format($grand_cgst, 2, '.', '')]);
        fputcsv($output, ['Total SGST', '₹' . number_format($grand_sgst, 2, '.', '')]);
        fputcsv($output, ['Total IGST', '₹' . number_format($grand_igst, 2, '.', '')]);
        fputcsv($output, ['Total GST', '₹' . number_format($grand_gst, 2, '.', '')]);
        fputcsv($output, ['Grand Total', '₹' . number_format($grand_total, 2, '.', '')]);
        
        if ($grand_taxable > 0) {
            $effective_rate = ($grand_gst / $grand_taxable) * 100;
            fputcsv($output, ['Effective GST Rate', number_format($effective_rate, 2) . '%']);
        }
        
    } elseif ($report_type === 'hsn') {
        $data = getHSNWiseGST($pdo, $current_business_id, $start_date, $end_date, $shop_id);
        
        // Table headers
        fputcsv($output, ['HSN Code', 'Product Name', 'Invoices', 'Quantity', 'Avg CGST %', 'Avg SGST %', 'Avg IGST %', 'Taxable Value', 'CGST', 'SGST', 'IGST', 'Total GST']);
        
        // Data rows
        $grand_taxable = 0;
        $grand_cgst = 0;
        $grand_sgst = 0;
        $grand_igst = 0;
        
        foreach ($data as $row) {
            fputcsv($output, [
                $row['hsn_code'],
                $row['product_name'],
                $row['invoice_count'],
                $row['total_quantity'],
                number_format($row['avg_cgst_rate'], 2),
                number_format($row['avg_sgst_rate'], 2),
                number_format($row['avg_igst_rate'], 2),
                number_format($row['total_taxable_value'], 2, '.', ''),
                number_format($row['total_cgst'], 2, '.', ''),
                number_format($row['total_sgst'], 2, '.', ''),
                number_format($row['total_igst'], 2, '.', ''),
                number_format($row['total_cgst'] + $row['total_sgst'] + $row['total_igst'], 2, '.', '')
            ]);
            
            $grand_taxable += $row['total_taxable_value'];
            $grand_cgst += $row['total_cgst'];
            $grand_sgst += $row['total_sgst'];
            $grand_igst += $row['total_igst'];
        }
        
        // Empty row
        fputcsv($output, ['']);
        
        // Summary row
        fputcsv($output, ['GRAND TOTALS', '', '', '', '', '', '',
            number_format($grand_taxable, 2, '.', ''),
            number_format($grand_cgst, 2, '.', ''),
            number_format($grand_sgst, 2, '.', ''),
            number_format($grand_igst, 2, '.', ''),
            number_format($grand_cgst + $grand_sgst + $grand_igst, 2, '.', '')
        ]);
        
    } elseif ($report_type === 'monthly') {
        $year = date('Y');
        $data = getMonthlyGSTTrend($pdo, $current_business_id, $year, $shop_id);
        
        // Table headers
        fputcsv($output, ['Month', 'Invoices', 'Taxable Value', 'CGST', 'SGST', 'IGST', 'Total GST', 'GST %']);
        
        // Data rows
        $yearly_taxable = 0;
        $yearly_cgst = 0;
        $yearly_sgst = 0;
        $yearly_igst = 0;
        $yearly_gst = 0;
        
        foreach ($data as $row) {
            $gst_percentage = $row['total_taxable_value'] > 0 ? 
                ($row['total_gst'] / $row['total_taxable_value']) * 100 : 0;
            
            fputcsv($output, [
                $row['month_name'],
                $row['invoice_count'],
                number_format($row['total_taxable_value'], 2, '.', ''),
                number_format($row['total_cgst'], 2, '.', ''),
                number_format($row['total_sgst'], 2, '.', ''),
                number_format($row['total_igst'], 2, '.', ''),
                number_format($row['total_gst'], 2, '.', ''),
                number_format($gst_percentage, 2)
            ]);
            
            $yearly_taxable += $row['total_taxable_value'];
            $yearly_cgst += $row['total_cgst'];
            $yearly_sgst += $row['total_sgst'];
            $yearly_igst += $row['total_igst'];
            $yearly_gst += $row['total_gst'];
        }
        
        // Empty row
        fputcsv($output, ['']);
        
        // Yearly totals
        $yearly_gst_percentage = $yearly_taxable > 0 ? ($yearly_gst / $yearly_taxable) * 100 : 0;
        
        fputcsv($output, ['YEARLY TOTALS', array_sum(array_column($data, 'invoice_count')),
            number_format($yearly_taxable, 2, '.', ''),
            number_format($yearly_cgst, 2, '.', ''),
            number_format($yearly_sgst, 2, '.', ''),
            number_format($yearly_igst, 2, '.', ''),
            number_format($yearly_gst, 2, '.', ''),
            number_format($yearly_gst_percentage, 2) . '%'
        ]);
    }
    
    fclose($output);
    exit();
}

// Export to Excel function
function exportToExcel($report_type, $start_date, $end_date, $shop_id) {
    global $pdo, $current_business_id, $shops;
    
    $filename = "gst_report_" . date('Y_m_d') . ".xls";
    
    header("Content-Type: application/vnd.ms-excel");
    header("Content-Disposition: attachment; filename=\"$filename\"");
    
    // Get shop name
    $shop_name = "All Shops";
    if ($shop_id !== 'all') {
        foreach ($shops as $shop) {
            if ($shop['id'] == $shop_id) {
                $shop_name = $shop['shop_name'];
                break;
            }
        }
    }
    
    echo "<html>";
    echo "<head>";
    echo "<meta charset='UTF-8'>";
    echo "<style>";
    echo "td { padding: 5px; border: 1px solid #ddd; }";
    echo "th { background-color: #f2f2f2; padding: 8px; border: 1px solid #ddd; }";
    echo ".header { background-color: #4CAF50; color: white; font-weight: bold; }";
    echo ".total { background-color: #e8f5e9; font-weight: bold; }";
    echo "</style>";
    echo "</head>";
    echo "<body>";
    
    echo "<table border='1'>";
    
    // Report header
    echo "<tr><td colspan='10' class='header' style='text-align:center;'>";
    echo "<h2>GST Report - " . ucfirst($report_type) . "</h2>";
    echo "<p>Period: " . date('d M Y', strtotime($start_date)) . " to " . date('d M Y', strtotime($end_date)) . "</p>";
    echo "<p>Shop: " . $shop_name . "</p>";
    echo "<p>Generated on: " . date('d M Y h:i A') . "</p>";
    echo "</td></tr>";
    
    if ($report_type === 'summary') {
        $data = getGSTSummary($pdo, $current_business_id, $start_date, $end_date, $shop_id);
        
        // Table headers
        echo "<tr>";
        echo "<th>Date</th>";
        echo "<th>Invoice No</th>";
        echo "<th>Customer</th>";
        echo "<th>Customer Type</th>";
        echo "<th>Shop</th>";
        echo "<th>Taxable Value</th>";
        echo "<th>CGST</th>";
        echo "<th>SGST</th>";
        echo "<th>IGST</th>";
        echo "<th>Total GST</th>";
        echo "<th>Invoice Total</th>";
        echo "</tr>";
        
        // Data rows
        $total_taxable = 0;
        $total_cgst = 0;
        $total_sgst = 0;
        $total_igst = 0;
        $total_gst = 0;
        $total_invoice = 0;
        
        foreach ($data as $row) {
            echo "<tr>";
            echo "<td>" . date('d M Y', strtotime($row['invoice_date'])) . "</td>";
            echo "<td>" . $row['invoice_number'] . "</td>";
            echo "<td>" . $row['customer_name'] . "</td>";
            echo "<td>" . ucfirst($row['customer_type']) . "</td>";
            echo "<td>" . $row['shop_name'] . "</td>";
            echo "<td>" . number_format($row['total_taxable_value'], 2) . "</td>";
            echo "<td>" . number_format($row['total_cgst'], 2) . "</td>";
            echo "<td>" . number_format($row['total_sgst'], 2) . "</td>";
            echo "<td>" . number_format($row['total_igst'], 2) . "</td>";
            echo "<td>" . number_format($row['total_gst'], 2) . "</td>";
            echo "<td>" . number_format($row['invoice_total'], 2) . "</td>";
            echo "</tr>";
            
            $total_taxable += $row['total_taxable_value'];
            $total_cgst += $row['total_cgst'];
            $total_sgst += $row['total_sgst'];
            $total_igst += $row['total_igst'];
            $total_gst += $row['total_gst'];
            $total_invoice += $row['invoice_total'];
        }
        
        // Totals row
        echo "<tr class='total'>";
        echo "<td colspan='5'><strong>TOTALS</strong></td>";
        echo "<td><strong>" . number_format($total_taxable, 2) . "</strong></td>";
        echo "<td><strong>" . number_format($total_cgst, 2) . "</strong></td>";
        echo "<td><strong>" . number_format($total_sgst, 2) . "</strong></td>";
        echo "<td><strong>" . number_format($total_igst, 2) . "</strong></td>";
        echo "<td><strong>" . number_format($total_gst, 2) . "</strong></td>";
        echo "<td><strong>" . number_format($total_invoice, 2) . "</strong></td>";
        echo "</tr>";
        
    } elseif ($report_type === 'hsn') {
        $data = getHSNWiseGST($pdo, $current_business_id, $start_date, $end_date, $shop_id);
        
        // Table headers
        echo "<tr>";
        echo "<th>HSN Code</th>";
        echo "<th>Product Name</th>";
        echo "<th>Invoice Count</th>";
        echo "<th>Total Quantity</th>";
        echo "<th>Avg CGST %</th>";
        echo "<th>Avg SGST %</th>";
        echo "<th>Avg IGST %</th>";
        echo "<th>Taxable Value</th>";
        echo "<th>CGST</th>";
        echo "<th>SGST</th>";
        echo "<th>IGST</th>";
        echo "<th>Total GST</th>";
        echo "</tr>";
        
        // Data rows
        $total_taxable = 0;
        $total_cgst = 0;
        $total_sgst = 0;
        $total_igst = 0;
        $total_gst = 0;
        
        foreach ($data as $row) {
            echo "<tr>";
            echo "<td>" . $row['hsn_code'] . "</td>";
            echo "<td>" . $row['product_name'] . "</td>";
            echo "<td>" . $row['invoice_count'] . "</td>";
            echo "<td>" . $row['total_quantity'] . "</td>";
            echo "<td>" . number_format($row['avg_cgst_rate'], 2) . "%</td>";
            echo "<td>" . number_format($row['avg_sgst_rate'], 2) . "%</td>";
            echo "<td>" . number_format($row['avg_igst_rate'], 2) . "%</td>";
            echo "<td>" . number_format($row['total_taxable_value'], 2) . "</td>";
            echo "<td>" . number_format($row['total_cgst'], 2) . "</td>";
            echo "<td>" . number_format($row['total_sgst'], 2) . "</td>";
            echo "<td>" . number_format($row['total_igst'], 2) . "</td>";
            echo "<td>" . number_format($row['total_cgst'] + $row['total_sgst'] + $row['total_igst'], 2) . "</td>";
            echo "</tr>";
            
            $total_taxable += $row['total_taxable_value'];
            $total_cgst += $row['total_cgst'];
            $total_sgst += $row['total_sgst'];
            $total_igst += $row['total_igst'];
        }
        
        // Totals row
        echo "<tr class='total'>";
        echo "<td colspan='7'><strong>TOTALS</strong></td>";
        echo "<td><strong>" . number_format($total_taxable, 2) . "</strong></td>";
        echo "<td><strong>" . number_format($total_cgst, 2) . "</strong></td>";
        echo "<td><strong>" . number_format($total_sgst, 2) . "</strong></td>";
        echo "<td><strong>" . number_format($total_igst, 2) . "</strong></td>";
        echo "<td><strong>" . number_format($total_cgst + $total_sgst + $total_igst, 2) . "</strong></td>";
        echo "</tr>";
        
    } elseif ($report_type === 'monthly') {
        $year = date('Y');
        $data = getMonthlyGSTTrend($pdo, $current_business_id, $year, $shop_id);
        
        // Table headers
        echo "<tr>";
        echo "<th>Month</th>";
        echo "<th>Invoice Count</th>";
        echo "<th>Taxable Value</th>";
        echo "<th>CGST</th>";
        echo "<th>SGST</th>";
        echo "<th>IGST</th>";
        echo "<th>Total GST</th>";
        echo "<th>GST %</th>";
        echo "</tr>";
        
        // Data rows
        $total_taxable = 0;
        $total_cgst = 0;
        $total_sgst = 0;
        $total_igst = 0;
        $total_gst = 0;
        $total_invoices = 0;
        
        foreach ($data as $row) {
            $gst_percentage = $row['total_taxable_value'] > 0 ? 
                ($row['total_gst'] / $row['total_taxable_value']) * 100 : 0;
            
            echo "<tr>";
            echo "<td>" . $row['month_name'] . "</td>";
            echo "<td>" . $row['invoice_count'] . "</td>";
            echo "<td>" . number_format($row['total_taxable_value'], 2) . "</td>";
            echo "<td>" . number_format($row['total_cgst'], 2) . "</td>";
            echo "<td>" . number_format($row['total_sgst'], 2) . "</td>";
            echo "<td>" . number_format($row['total_igst'], 2) . "</td>";
            echo "<td>" . number_format($row['total_gst'], 2) . "</td>";
            echo "<td>" . number_format($gst_percentage, 2) . "%</td>";
            echo "</tr>";
            
            $total_taxable += $row['total_taxable_value'];
            $total_cgst += $row['total_cgst'];
            $total_sgst += $row['total_sgst'];
            $total_igst += $row['total_igst'];
            $total_gst += $row['total_gst'];
            $total_invoices += $row['invoice_count'];
        }
        
        // Totals row
        $yearly_gst_percentage = $total_taxable > 0 ? ($total_gst / $total_taxable) * 100 : 0;
        
        echo "<tr class='total'>";
        echo "<td><strong>Yearly Totals</strong></td>";
        echo "<td><strong>" . $total_invoices . "</strong></td>";
        echo "<td><strong>" . number_format($total_taxable, 2) . "</strong></td>";
        echo "<td><strong>" . number_format($total_cgst, 2) . "</strong></td>";
        echo "<td><strong>" . number_format($total_sgst, 2) . "</strong></td>";
        echo "<td><strong>" . number_format($total_igst, 2) . "</strong></td>";
        echo "<td><strong>" . number_format($total_gst, 2) . "</strong></td>";
        echo "<td><strong>" . number_format($yearly_gst_percentage, 2) . "%</strong></td>";
        echo "</tr>";
    }
    
    echo "</table>";
    echo "</body></html>";
    exit();
}

// Export to JSON function
function exportToJSON($report_type, $start_date, $end_date, $shop_id) {
    global $pdo, $current_business_id, $shops;
    
    // Get shop name
    $shop_name = "All Shops";
    if ($shop_id !== 'all') {
        foreach ($shops as $shop) {
            if ($shop['id'] == $shop_id) {
                $shop_name = $shop['shop_name'];
                break;
            }
        }
    }
    
    $report_data = [
        'metadata' => [
            'report_type' => $report_type,
            'period' => [
                'start_date' => $start_date,
                'end_date' => $end_date,
                'display_period' => date('d M Y', strtotime($start_date)) . ' to ' . date('d M Y', strtotime($end_date))
            ],
            'shop' => $shop_name,
            'generated_on' => date('Y-m-d H:i:s'),
            'format' => 'JSON'
        ],
        'data' => []
    ];
    
    if ($report_type === 'summary') {
        $data = getGSTSummary($pdo, $current_business_id, $start_date, $end_date, $shop_id);
        
        $report_data['data'] = $data;
        $report_data['summary'] = [
            'total_invoices' => count($data),
            'total_taxable_value' => array_sum(array_column($data, 'total_taxable_value')),
            'total_cgst' => array_sum(array_column($data, 'total_cgst')),
            'total_sgst' => array_sum(array_column($data, 'total_sgst')),
            'total_igst' => array_sum(array_column($data, 'total_igst')),
            'total_gst' => array_sum(array_column($data, 'total_gst')),
            'grand_total' => array_sum(array_column($data, 'invoice_total'))
        ];
        
    } elseif ($report_type === 'hsn') {
        $data = getHSNWiseGST($pdo, $current_business_id, $start_date, $end_date, $shop_id);
        
        $report_data['data'] = $data;
        $report_data['summary'] = [
            'total_hsn_codes' => count($data),
            'total_taxable_value' => array_sum(array_column($data, 'total_taxable_value')),
            'total_cgst' => array_sum(array_column($data, 'total_cgst')),
            'total_sgst' => array_sum(array_column($data, 'total_sgst')),
            'total_igst' => array_sum(array_column($data, 'total_igst')),
            'total_gst' => array_sum(array_map(function($item) {
                return $item['total_cgst'] + $item['total_sgst'] + $item['total_igst'];
            }, $data))
        ];
        
    } elseif ($report_type === 'monthly') {
        $year = date('Y');
        $data = getMonthlyGSTTrend($pdo, $current_business_id, $year, $shop_id);
        
        $report_data['metadata']['year'] = $year;
        $report_data['data'] = $data;
        $report_data['summary'] = [
            'total_months' => count($data),
            'total_taxable_value' => array_sum(array_column($data, 'total_taxable_value')),
            'total_cgst' => array_sum(array_column($data, 'total_cgst')),
            'total_sgst' => array_sum(array_column($data, 'total_sgst')),
            'total_igst' => array_sum(array_column($data, 'total_igst')),
            'total_gst' => array_sum(array_column($data, 'total_gst'))
        ];
    }
    
    header('Content-Type: application/json');
    header('Content-Disposition: attachment; filename="gst_report_' . date('Y_m_d') . '.json"');
    echo json_encode($report_data, JSON_PRETTY_PRINT);
    exit();
}
?>

<!doctype html>
<html lang="en">
<?php 
$page_title = "GST Reports";
include('includes/head.php') 
?>
<style>
.gst-card {
    border-left: 4px solid;
    transition: transform 0.2s;
}
.gst-card:hover {
    transform: translateY(-2px);
}
.gst-card.cgst { border-left-color: #0d6efd; }
.gst-card.sgst { border-left-color: #198754; }
.gst-card.igst { border-left-color: #fd7e14; }
.gst-card.total { border-left-color: #6f42c1; }

.summary-table th {
    background-color: #f8f9fa;
    font-weight: 600;
}

.hsn-row {
    transition: background-color 0.2s;
}
.hsn-row:hover {
    background-color: rgba(13, 110, 253, 0.05) !important;
}

.chart-container {
    position: relative;
    height: 300px;
    width: 100%;
}

.tax-breakdown {
    font-size: 0.85rem;
}

.download-btn {
    min-width: 120px;
}

.export-options {
    position: relative;
}

.export-dropdown {
    position: absolute;
    right: 0;
    left: auto;
    min-width: 180px;
}

.overall-summary {
    background-color: #f8f9fa;
    border-left: 4px solid #6f42c1;
}

.table-total-row {
    background-color: #e8f4ff !important;
    font-weight: bold;
}

.gst-composition-card {
    border: 1px solid #dee2e6;
    border-radius: 8px;
    overflow: hidden;
}

.composition-header {
    background-color: #f8f9fa;
    border-bottom: 1px solid #dee2e6;
    padding: 15px;
    font-weight: 600;
}

.composition-body {
    padding: 15px;
}

.distribution-item {
    margin-bottom: 15px;
}

.distribution-label {
    display: flex;
    justify-content: space-between;
    margin-bottom: 5px;
}

.distribution-progress {
    height: 8px;
    border-radius: 4px;
    background-color: #e9ecef;
    overflow: hidden;
}

@media print {
    .no-print {
        display: none !important;
    }
    .card {
        border: 1px solid #dee2e6 !important;
        box-shadow: none !important;
    }
    table {
        font-size: 11px !important;
    }
}
</style>
<body data-sidebar="dark">
<div id="layout-wrapper">
    <?php include('includes/topbar.php') ?>
    <div class="vertical-menu">
        <div data-simplebar class="h-100">
            <?php include('includes/sidebar.php') ?>
        </div>
    </div>

    <div class="main-content">
        <div class="page-content">
            <div class="container-fluid">

                <!-- Page Header -->
                <div class="row mb-4">
                    <div class="col-12">
                        <div class="page-title-box d-flex align-items-center justify-content-between">
                            <h4 class="mb-0">
                                <i class="bx bx-line-chart me-2"></i> GST Reports & Analytics
                                <small class="text-muted ms-2">
                                    <i class="bx bx-store me-1"></i>
                                    <?= htmlspecialchars($_SESSION['current_business_name'] ?? 'Business') ?>
                                </small>
                            </h4>
                            <div class="export-options">
                                <div class="btn-group no-print">
                                    <button type="button" class="btn btn-outline-primary dropdown-toggle" data-bs-toggle="dropdown">
                                        <i class="bx bx-download me-1"></i> Export
                                    </button>
                                    <div class="dropdown-menu export-dropdown">
                                        <a class="dropdown-item" href="#" onclick="window.print()">
                                            <i class="bx bx-printer me-2"></i> Print Report
                                        </a>
                                        <div class="dropdown-divider"></div>
                                        <h6 class="dropdown-header">Download As</h6>
                                        <a class="dropdown-item export-link" href="#" data-type="csv">
                                            <i class="bx bx-file me-2 text-success"></i> CSV (.csv)
                                        </a>
                                        <a class="dropdown-item export-link" href="#" data-type="excel">
                                            <i class="bx bx-file me-2 text-success"></i> Excel (.xls)
                                        </a>
                                        <a class="dropdown-item export-link" href="#" data-type="json">
                                            <i class="bx bx-code me-2 text-info"></i> JSON (.json)
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Filter Card -->
                <div class="row mb-4">
                    <div class="col-12">
                        <div class="card shadow-sm">
                            <div class="card-header bg-light">
                                <h5 class="mb-0">
                                    <i class="bx bx-filter-alt me-2"></i> Report Filters
                                </h5>
                            </div>
                            <div class="card-body">
                                <form method="GET" id="reportForm" class="row g-3">
                                    <input type="hidden" name="export" id="exportType">
                                    <div class="col-md-3">
                                        <label class="form-label">Start Date</label>
                                        <input type="date" name="start_date" class="form-control" 
                                               value="<?= htmlspecialchars($filter_start_date) ?>" required>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">End Date</label>
                                        <input type="date" name="end_date" class="form-control" 
                                               value="<?= htmlspecialchars($filter_end_date) ?>" required>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Shop/Location</label>
                                        <select name="shop_id" class="form-select">
                                            <option value="all">All Shops</option>
                                            <?php foreach ($shops as $shop): ?>
                                            <option value="<?= $shop['id'] ?>" <?= $shop_id == $shop['id'] ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($shop['shop_name']) ?> (<?= $shop['shop_code'] ?>)
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Report Type</label>
                                        <select name="report_type" class="form-select" onchange="this.form.submit()">
                                            <option value="summary" <?= $report_type === 'summary' ? 'selected' : '' ?>>Invoice Summary</option>
                                            <option value="hsn" <?= $report_type === 'hsn' ? 'selected' : '' ?>>HSN Code Wise</option>
                                            <option value="monthly" <?= $report_type === 'monthly' ? 'selected' : '' ?>>Monthly Trend</option>
                                        </select>
                                    </div>
                                    <div class="col-12">
                                        <div class="d-flex justify-content-between">
                                            <button type="submit" class="btn btn-primary">
                                                <i class="bx bx-refresh me-1"></i> Generate Report
                                            </button>
                                            <a href="gst_report.php" class="btn btn-outline-secondary">
                                                <i class="bx bx-reset me-1"></i> Reset Filters
                                            </a>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- GST Summary Cards -->
                <?php if ($report_type === 'summary'): ?>
                <div class="row mb-4">
                    <div class="col-md-3">
                        <div class="card gst-card cgst shadow-sm">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="text-muted mb-2">CGST</h6>
                                        <h3 class="mb-0">₹<?= number_format($total_cgst, 2) ?></h3>
                                        <small class="text-muted">Central GST Collection</small>
                                    </div>
                                    <div class="avatar-sm">
                                        <span class="avatar-title bg-primary bg-opacity-10 rounded">
                                            <i class="bx bx-building-house text-primary font-size-24"></i>
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-3">
                        <div class="card gst-card sgst shadow-sm">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="text-muted mb-2">SGST</h6>
                                        <h3 class="mb-0">₹<?= number_format($total_sgst, 2) ?></h3>
                                        <small class="text-muted">State GST Collection</small>
                                    </div>
                                    <div class="avatar-sm">
                                        <span class="avatar-title bg-success bg-opacity-10 rounded">
                                            <i class="bx bx-map text-success font-size-24"></i>
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-3">
                        <div class="card gst-card igst shadow-sm">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="text-muted mb-2">IGST</h6>
                                        <h3 class="mb-0">₹<?= number_format($total_igst, 2) ?></h3>
                                        <small class="text-muted">Interstate GST Collection</small>
                                    </div>
                                    <div class="avatar-sm">
                                        <span class="avatar-title bg-warning bg-opacity-10 rounded">
                                            <i class="bx bx-transfer-alt text-warning font-size-24"></i>
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-3">
                        <div class="card gst-card total shadow-sm">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="text-muted mb-2">Total GST</h6>
                                        <h3 class="mb-0">₹<?= number_format($total_gst, 2) ?></h3>
                                        <small class="text-muted"><?= $total_invoices ?> invoices</small>
                                    </div>
                                    <div class="avatar-sm">
                                        <span class="avatar-title bg-purple bg-opacity-10 rounded">
                                            <i class="bx bx-calculator text-purple font-size-24"></i>
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Report Content -->
                <div class="row">
                    <div class="col-12">
                        <div class="card shadow-sm">
                            <div class="card-header bg-light">
                                <div class="d-flex justify-content-between align-items-center">
                                    <h5 class="mb-0">
                                        <i class="bx bx-file me-2"></i>
                                        <?php 
                                        $titles = [
                                            'summary' => 'GST Invoice Summary',
                                            'hsn' => 'HSN Code Wise GST Report',
                                            'monthly' => 'Monthly GST Trend'
                                        ];
                                        echo $titles[$report_type];
                                        ?>
                                    </h5>
                                    <div class="text-muted">
                                        Period: <?= date('d M Y', strtotime($filter_start_date)) ?> - <?= date('d M Y', strtotime($filter_end_date)) ?>
                                        <?php if ($shop_id !== 'all'): ?>
                                        | Shop: <?= htmlspecialchars($shops[array_search($shop_id, array_column($shops, 'id'))]['shop_name'] ?? 'All') ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <div class="card-body">
                                
                                <?php if ($report_type === 'summary'): ?>
                                
                                <!-- Invoice Summary Table -->
                                <div class="table-responsive">
                                    <table class="table table-hover summary-table" id="summaryTable">
                                        <thead class="table-light">
                                            <tr>
                                                <th>Date</th>
                                                <th>Invoice No.</th>
                                                <th>Customer</th>
                                                <th>Shop</th>
                                                <th class="text-end">Taxable Value</th>
                                                <th class="text-end">CGST</th>
                                                <th class="text-end">SGST</th>
                                                <th class="text-end">IGST</th>
                                                <th class="text-end">Total GST</th>
                                                <th class="text-end">Invoice Total</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (empty($gst_summary)): ?>
                                            <tr>
                                                <td colspan="10" class="text-center py-4">
                                                    <i class="bx bx-line-chart fs-1 text-muted mb-2 d-block"></i>
                                                    <h5>No GST invoices found</h5>
                                                    <p class="text-muted">No GST-enabled invoices for the selected period.</p>
                                                </td>
                                            </tr>
                                            <?php else: ?>
                                            <?php foreach ($gst_summary as $invoice): ?>
                                            <tr>
                                                <td><?= date('d M Y', strtotime($invoice['invoice_date'])) ?></td>
                                                <td>
                                                    <a href="invoice_view.php?id=<?= $invoice['invoice_id'] ?>" 
                                                       class="text-decoration-none">
                                                        <strong><?= htmlspecialchars($invoice['invoice_number']) ?></strong>
                                                    </a>
                                                </td>
                                                <td>
                                                    <div><?= htmlspecialchars($invoice['customer_name']) ?></div>
                                                    <small class="text-muted">
                                                        <?= $invoice['customer_type'] ?> 
                                                        <?php if ($invoice['customer_gstin']): ?>
                                                        | GSTIN: <?= $invoice['customer_gstin'] ?>
                                                        <?php endif; ?>
                                                    </small>
                                                </td>
                                                <td><?= htmlspecialchars($invoice['shop_name']) ?></td>
                                                <td class="text-end">₹<?= number_format($invoice['total_taxable_value'], 2) ?></td>
                                                <td class="text-end">
                                                    <span class="badge bg-primary bg-opacity-10 text-primary px-3 py-1">
                                                        ₹<?= number_format($invoice['total_cgst'], 2) ?>
                                                    </span>
                                                </td>
                                                <td class="text-end">
                                                    <span class="badge bg-success bg-opacity-10 text-success px-3 py-1">
                                                        ₹<?= number_format($invoice['total_sgst'], 2) ?>
                                                    </span>
                                                </td>
                                                <td class="text-end">
                                                    <span class="badge bg-warning bg-opacity-10 text-warning px-3 py-1">
                                                        ₹<?= number_format($invoice['total_igst'], 2) ?>
                                                    </span>
                                                </td>
                                                <td class="text-end">
                                                    <strong class="text-dark">₹<?= number_format($invoice['total_gst'], 2) ?></strong>
                                                </td>
                                                <td class="text-end">
                                                    <strong>₹<?= number_format($invoice['invoice_total'], 2) ?></strong>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                            
                                            <!-- Overall Totals Row -->
                                            <tr class="table-total-row">
                                                <td colspan="4" class="text-end"><strong>OVERALL TOTALS:</strong></td>
                                                <td class="text-end"><strong>₹<?= number_format($total_taxable, 2) ?></strong></td>
                                                <td class="text-end"><strong>₹<?= number_format($total_cgst, 2) ?></strong></td>
                                                <td class="text-end"><strong>₹<?= number_format($total_sgst, 2) ?></strong></td>
                                                <td class="text-end"><strong>₹<?= number_format($total_igst, 2) ?></strong></td>
                                                <td class="text-end"><strong>₹<?= number_format($total_gst, 2) ?></strong></td>
                                                <td class="text-end"><strong>₹<?= number_format($total_invoice_amount, 2) ?></strong></td>
                                            </tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                                
                                <!-- GST Composition and Distribution Section -->
                                <?php if (!empty($gst_summary)): ?>
                                <div class="row mt-4">
                                    <div class="col-md-6">
                                        <!-- GST Composition -->
                                        <div class="card gst-composition-card">
                                            <div class="composition-header">
                                                <i class="bx bx-pie-chart-alt me-2"></i> GST Composition
                                            </div>
                                            <div class="composition-body">
                                                <div class="row">
                                                    <div class="col-6">
                                                        <div class="mb-3">
                                                            <small class="text-muted d-block">Taxable Value</small>
                                                            <h5 class="mb-0">₹<?= number_format($total_taxable, 2) ?></h5>
                                                        </div>
                                                        <div class="mb-3">
                                                            <small class="text-muted d-block">Total GST</small>
                                                            <h5 class="mb-0 text-purple">₹<?= number_format($total_gst, 2) ?></h5>
                                                        </div>
                                                        <div class="mb-3">
                                                            <small class="text-muted d-block">Grand Total</small>
                                                            <h5 class="mb-0 text-dark">₹<?= number_format($total_invoice_amount, 2) ?></h5>
                                                        </div>
                                                    </div>
                                                    <div class="col-6">
                                                        <div class="mb-3">
                                                            <small class="text-muted d-block">Total Invoices</small>
                                                            <h5 class="mb-0"><?= $total_invoices ?></h5>
                                                        </div>
                                                        <?php if ($total_taxable > 0): ?>
                                                        <div class="mb-3">
                                                            <small class="text-muted d-block">Effective GST Rate</small>
                                                            <h5 class="mb-0 text-primary">
                                                                <?= number_format(($total_gst / $total_taxable) * 100, 2) ?>%
                                                            </h5>
                                                        </div>
                                                        <?php endif; ?>
                                                        <div class="mb-3">
                                                            <small class="text-muted d-block">GST to Taxable Ratio</small>
                                                            <h5 class="mb-0">
                                                                <?= number_format($total_taxable > 0 ? ($total_gst / $total_taxable) * 100 : 0, 2) ?>%
                                                            </h5>
                                                        </div>
                                                    </div>
                                                </div>
                                                
                                                <!-- GST Breakdown -->
                                                <div class="row mt-3">
                                                    <div class="col-12">
                                                        <div class="row">
                                                            <div class="col-4 text-center">
                                                                <div class="p-2 border rounded">
                                                                    <small class="text-muted d-block">CGST</small>
                                                                    <h6 class="mb-0 text-primary">₹<?= number_format($total_cgst, 2) ?></h6>
                                                                    <small class="text-muted">
                                                                        <?= $total_gst > 0 ? number_format(($total_cgst / $total_gst) * 100, 1) : 0 ?>%
                                                                    </small>
                                                                </div>
                                                            </div>
                                                            <div class="col-4 text-center">
                                                                <div class="p-2 border rounded">
                                                                    <small class="text-muted d-block">SGST</small>
                                                                    <h6 class="mb-0 text-success">₹<?= number_format($total_sgst, 2) ?></h6>
                                                                    <small class="text-muted">
                                                                        <?= $total_gst > 0 ? number_format(($total_sgst / $total_gst) * 100, 1) : 0 ?>%
                                                                    </small>
                                                                </div>
                                                            </div>
                                                            <div class="col-4 text-center">
                                                                <div class="p-2 border rounded">
                                                                    <small class="text-muted d-block">IGST</small>
                                                                    <h6 class="mb-0 text-warning">₹<?= number_format($total_igst, 2) ?></h6>
                                                                    <small class="text-muted">
                                                                        <?= $total_gst > 0 ? number_format(($total_igst / $total_gst) * 100, 1) : 0 ?>%
                                                                    </small>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="col-md-6">
                                        <!-- GST Distribution -->
                                        <div class="card gst-composition-card">
                                            <div class="composition-header">
                                                <i class="bx bx-bar-chart-alt me-2"></i> GST Distribution
                                            </div>
                                            <div class="composition-body">
                                                <div class="distribution-item">
                                                    <div class="distribution-label">
                                                        <small>CGST (Central)</small>
                                                        <small class="text-primary"><?= $total_gst > 0 ? number_format(($total_cgst / $total_gst) * 100, 1) : 0 ?>%</small>
                                                    </div>
                                                    <div class="distribution-progress">
                                                        <div class="progress-bar bg-primary" role="progressbar" 
                                                             style="width: <?= $total_gst > 0 ? ($total_cgst / $total_gst) * 100 : 0 ?>%;">
                                                        </div>
                                                    </div>
                                                    <small class="text-muted">₹<?= number_format($total_cgst, 2) ?></small>
                                                </div>
                                                
                                                <div class="distribution-item">
                                                    <div class="distribution-label">
                                                        <small>SGST (State)</small>
                                                        <small class="text-success"><?= $total_gst > 0 ? number_format(($total_sgst / $total_gst) * 100, 1) : 0 ?>%</small>
                                                    </div>
                                                    <div class="distribution-progress">
                                                        <div class="progress-bar bg-success" role="progressbar" 
                                                             style="width: <?= $total_gst > 0 ? ($total_sgst / $total_gst) * 100 : 0 ?>%;">
                                                        </div>
                                                    </div>
                                                    <small class="text-muted">₹<?= number_format($total_sgst, 2) ?></small>
                                                </div>
                                                
                                                <div class="distribution-item">
                                                    <div class="distribution-label">
                                                        <small>IGST (Interstate)</small>
                                                        <small class="text-warning"><?= $total_gst > 0 ? number_format(($total_igst / $total_gst) * 100, 1) : 0 ?>%</small>
                                                    </div>
                                                    <div class="distribution-progress">
                                                        <div class="progress-bar bg-warning" role="progressbar" 
                                                             style="width: <?= $total_gst > 0 ? ($total_igst / $total_gst) * 100 : 0 ?>%;">
                                                        </div>
                                                    </div>
                                                    <small class="text-muted">₹<?= number_format($total_igst, 2) ?></small>
                                                </div>
                                                
                                                <div class="row mt-4">
                                                    <div class="col-6">
                                                        <div class="p-3 border rounded text-center">
                                                            <small class="text-muted d-block">Taxable Value</small>
                                                            <h6 class="mb-0"><?= $total_taxable > 0 ? number_format(($total_taxable / $total_invoice_amount) * 100, 1) : 0 ?>%</h6>
                                                            <small class="text-muted">of Grand Total</small>
                                                        </div>
                                                    </div>
                                                    <div class="col-6">
                                                        <div class="p-3 border rounded text-center">
                                                            <small class="text-muted d-block">Total GST</small>
                                                            <h6 class="mb-0 text-purple"><?= $total_gst > 0 ? number_format(($total_gst / $total_invoice_amount) * 100, 1) : 0 ?>%</h6>
                                                            <small class="text-muted">of Grand Total</small>
                                                        </div>
                                                    </div>
                                                </div>
                                                
                                                <div class="mt-3 pt-3 border-top">
                                                    <small class="text-muted">
                                                        <i class="bx bx-info-circle me-1"></i>
                                                        Report generated on <?= date('d M Y h:i A') ?>
                                                        <?php if ($shop_id !== 'all'): ?>
                                                        | Shop specific report
                                                        <?php endif; ?>
                                                    </small>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <?php endif; ?>
                                
                                <?php elseif ($report_type === 'hsn'): ?>
                                
                                <!-- HSN Code Wise Report -->
                                <div class="table-responsive">
                                    <table class="table table-hover" id="hsnTable">
                                        <thead class="table-light">
                                            <tr>
                                                <th>HSN Code</th>
                                                <th>Product Name</th>
                                                <th class="text-center">Invoices</th>
                                                <th class="text-center">Quantity</th>
                                                <th class="text-center">Avg CGST %</th>
                                                <th class="text-center">Avg SGST %</th>
                                                <th class="text-center">Avg IGST %</th>
                                                <th class="text-end">Taxable Value</th>
                                                <th class="text-end">CGST</th>
                                                <th class="text-end">SGST</th>
                                                <th class="text-end">IGST</th>
                                                <th class="text-end">Total GST</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (empty($hsn_summary)): ?>
                                            <tr>
                                                <td colspan="12" class="text-center py-4">
                                                    <i class="bx bx-hash fs-1 text-muted mb-2 d-block"></i>
                                                    <h5>No HSN data found</h5>
                                                    <p class="text-muted">No HSN codes recorded for the selected period.</p>
                                                </td>
                                            </tr>
                                            <?php else: ?>
                                            <?php foreach ($hsn_summary as $hsn): ?>
                                            <tr class="hsn-row">
                                                <td>
                                                    <strong class="text-primary"><?= htmlspecialchars($hsn['hsn_code']) ?></strong>
                                                    <?php 
                                                    // Find GST rate details
                                                    $gst_rate = null;
                                                    foreach ($gst_rates_list as $rate) {
                                                        if ($rate['hsn_code'] === $hsn['hsn_code']) {
                                                            $gst_rate = $rate;
                                                            break;
                                                        }
                                                    }
                                                    ?>
                                                    <?php if ($gst_rate): ?>
                                                    <br>
                                                    <small class="text-muted">
                                                        <?= $gst_rate['description'] ?>
                                                        <span class="badge bg-<?= $gst_rate['status'] === 'active' ? 'success' : 'secondary' ?> ms-1">
                                                            <?= $gst_rate['status'] ?>
                                                        </span>
                                                    </small>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?= htmlspecialchars($hsn['product_name']) ?></td>
                                                <td class="text-center">
                                                    <span class="badge bg-info rounded-pill px-3">
                                                        <?= $hsn['invoice_count'] ?>
                                                    </span>
                                                </td>
                                                <td class="text-center">
                                                    <span class="badge bg-secondary bg-opacity-10 text-secondary px-3">
                                                        <?= number_format($hsn['total_quantity']) ?>
                                                    </span>
                                                </td>
                                                <td class="text-center">
                                                    <?= $hsn['avg_cgst_rate'] ? number_format($hsn['avg_cgst_rate'], 2) . '%' : '-' ?>
                                                </td>
                                                <td class="text-center">
                                                    <?= $hsn['avg_sgst_rate'] ? number_format($hsn['avg_sgst_rate'], 2) . '%' : '-' ?>
                                                </td>
                                                <td class="text-center">
                                                    <?= $hsn['avg_igst_rate'] ? number_format($hsn['avg_igst_rate'], 2) . '%' : '-' ?>
                                                </td>
                                                <td class="text-end">₹<?= number_format($hsn['total_taxable_value'], 2) ?></td>
                                                <td class="text-end">
                                                    <span class="badge bg-primary bg-opacity-10 text-primary px-3 py-1">
                                                        ₹<?= number_format($hsn['total_cgst'], 2) ?>
                                                    </span>
                                                </td>
                                                <td class="text-end">
                                                    <span class="badge bg-success bg-opacity-10 text-success px-3 py-1">
                                                        ₹<?= number_format($hsn['total_sgst'], 2) ?>
                                                    </span>
                                                </td>
                                                <td class="text-end">
                                                    <span class="badge bg-warning bg-opacity-10 text-warning px-3 py-1">
                                                        ₹<?= number_format($hsn['total_igst'], 2) ?>
                                                    </span>
                                                </td>
                                                <td class="text-end">
                                                    <strong>₹<?= number_format($hsn['total_cgst'] + $hsn['total_sgst'] + $hsn['total_igst'], 2) ?></strong>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                            
                                            <!-- Overall Totals Row -->
                                            <tr class="table-total-row">
                                                <td colspan="7" class="text-end"><strong>OVERALL TOTALS:</strong></td>
                                                <td class="text-end"><strong>₹<?= number_format($total_taxable, 2) ?></strong></td>
                                                <td class="text-end"><strong>₹<?= number_format($total_cgst, 2) ?></strong></td>
                                                <td class="text-end"><strong>₹<?= number_format($total_sgst, 2) ?></strong></td>
                                                <td class="text-end"><strong>₹<?= number_format($total_igst, 2) ?></strong></td>
                                                <td class="text-end"><strong>₹<?= number_format($total_cgst + $total_sgst + $total_igst, 2) ?></strong></td>
                                            </tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                                
                                <?php elseif ($report_type === 'monthly'): ?>
                                
                                <!-- Monthly Trend -->
                                <div class="row mb-4">
                                    <div class="col-12">
                                        <div class="card shadow-sm">
                                            <div class="card-body">
                                                <h6 class="mb-3">Monthly GST Collection Trend</h6>
                                                <div class="chart-container">
                                                    <canvas id="monthlyTrendChart"></canvas>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="table-responsive">
                                    <table class="table table-hover" id="monthlyTable">
                                        <thead class="table-light">
                                            <tr>
                                                <th>Month</th>
                                                <th class="text-center">Invoices</th>
                                                <th class="text-end">Taxable Value</th>
                                                <th class="text-end">CGST</th>
                                                <th class="text-end">SGST</th>
                                                <th class="text-end">IGST</th>
                                                <th class="text-end">Total GST</th>
                                                <th class="text-end">GST %</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (empty($monthly_trend)): ?>
                                            <tr>
                                                <td colspan="8" class="text-center py-4">
                                                    <i class="bx bx-calendar fs-1 text-muted mb-2 d-block"></i>
                                                    <h5>No monthly data found</h5>
                                                    <p class="text-muted">No GST data for the selected year.</p>
                                                </td>
                                            </tr>
                                            <?php else: ?>
                                            <?php 
                                            $yearly_total_taxable = 0;
                                            $yearly_total_cgst = 0;
                                            $yearly_total_sgst = 0;
                                            $yearly_total_igst = 0;
                                            $yearly_total_gst = 0;
                                            ?>
                                            <?php foreach ($monthly_trend as $month): ?>
                                            <?php 
                                            $yearly_total_taxable += $month['total_taxable_value'];
                                            $yearly_total_cgst += $month['total_cgst'];
                                            $yearly_total_sgst += $month['total_sgst'];
                                            $yearly_total_igst += $month['total_igst'];
                                            $yearly_total_gst += $month['total_gst'];
                                            $gst_percentage = $month['total_taxable_value'] > 0 ? 
                                                ($month['total_gst'] / $month['total_taxable_value']) * 100 : 0;
                                            ?>
                                            <tr>
                                                <td>
                                                    <strong><?= htmlspecialchars($month['month_name']) ?></strong>
                                                </td>
                                                <td class="text-center">
                                                    <span class="badge bg-info rounded-pill px-3">
                                                        <?= $month['invoice_count'] ?>
                                                    </span>
                                                </td>
                                                <td class="text-end">₹<?= number_format($month['total_taxable_value'], 2) ?></td>
                                                <td class="text-end">
                                                    <span class="badge bg-primary bg-opacity-10 text-primary px-3 py-1">
                                                        ₹<?= number_format($month['total_cgst'], 2) ?>
                                                    </span>
                                                </td>
                                                <td class="text-end">
                                                    <span class="badge bg-success bg-opacity-10 text-success px-3 py-1">
                                                        ₹<?= number_format($month['total_sgst'], 2) ?></span>
                                                </td>
                                                <td class="text-end">
                                                    <span class="badge bg-warning bg-opacity-10 text-warning px-3 py-1">
                                                        ₹<?= number_format($month['total_igst'], 2) ?>
                                                    </span>
                                                </td>
                                                <td class="text-end">
                                                    <strong>₹<?= number_format($month['total_gst'], 2) ?></strong>
                                                </td>
                                                <td class="text-end">
                                                    <span class="badge bg-<?= $gst_percentage > 10 ? 'success' : 'secondary' ?> bg-opacity-10 text-<?= $gst_percentage > 10 ? 'success' : 'secondary' ?> px-3 py-1">
                                                        <?= number_format($gst_percentage, 2) ?>%
                                                    </span>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                            
                                            <!-- Overall Totals Row -->
                                            <?php 
                                            $yearly_gst_percentage = $yearly_total_taxable > 0 ? 
                                                ($yearly_total_gst / $yearly_total_taxable) * 100 : 0;
                                            ?>
                                            <tr class="table-total-row">
                                                <td><strong>OVERALL TOTALS</strong></td>
                                                <td class="text-center"><strong><?= array_sum(array_column($monthly_trend, 'invoice_count')) ?></strong></td>
                                                <td class="text-end"><strong>₹<?= number_format($yearly_total_taxable, 2) ?></strong></td>
                                                <td class="text-end"><strong>₹<?= number_format($yearly_total_cgst, 2) ?></strong></td>
                                                <td class="text-end"><strong>₹<?= number_format($yearly_total_sgst, 2) ?></strong></td>
                                                <td class="text-end"><strong>₹<?= number_format($yearly_total_igst, 2) ?></strong></td>
                                                <td class="text-end"><strong>₹<?= number_format($yearly_total_gst, 2) ?></strong></td>
                                                <td class="text-end"><strong><?= number_format($yearly_gst_percentage, 2) ?>%</strong></td>
                                            </tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                                
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

            </div>
        </div>
        <?php include('includes/footer.php') ?>
    </div>
</div>

<?php include('includes/rightbar.php') ?>
<?php include('includes/scripts.php') ?>
<!-- Add Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<script>
$(document).ready(function() {
    <?php if ($report_type === 'monthly' && !empty($monthly_trend)): ?>
    // Monthly Trend Chart
    const monthlyCtx = document.getElementById('monthlyTrendChart').getContext('2d');
    const monthlyData = {
        labels: <?= json_encode(array_column($monthly_trend, 'month_name')) ?>,
        datasets: [
            {
                label: 'CGST',
                data: <?= json_encode(array_column($monthly_trend, 'total_cgst')) ?>,
                backgroundColor: 'rgba(13, 110, 253, 0.5)',
                borderColor: 'rgba(13, 110, 253, 1)',
                borderWidth: 1
            },
            {
                label: 'SGST',
                data: <?= json_encode(array_column($monthly_trend, 'total_sgst')) ?>,
                backgroundColor: 'rgba(25, 135, 84, 0.5)',
                borderColor: 'rgba(25, 135, 84, 1)',
                borderWidth: 1
            },
            {
                label: 'IGST',
                data: <?= json_encode(array_column($monthly_trend, 'total_igst')) ?>,
                backgroundColor: 'rgba(253, 126, 20, 0.5)',
                borderColor: 'rgba(253, 126, 20, 1)',
                borderWidth: 1
            },
            {
                label: 'Total GST',
                data: <?= json_encode(array_column($monthly_trend, 'total_gst')) ?>,
                backgroundColor: 'rgba(111, 66, 193, 0.5)',
                borderColor: 'rgba(111, 66, 193, 1)',
                borderWidth: 2,
                type: 'line',
                fill: false,
                tension: 0.4
            }
        ]
    };
    
    new Chart(monthlyCtx, {
        type: 'bar',
        data: monthlyData,
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                y: {
                    beginAtZero: true,
                    title: {
                        display: true,
                        text: 'Amount (₹)'
                    },
                    ticks: {
                        callback: function(value) {
                            return '₹' + value.toLocaleString();
                        }
                    }
                }
            },
            plugins: {
                legend: {
                    position: 'top',
                },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            let label = context.dataset.label || '';
                            if (label) {
                                label += ': ';
                            }
                            label += '₹' + context.parsed.y.toLocaleString();
                            return label;
                        }
                    }
                }
            }
        }
    });
    <?php endif; ?>

    // Export functionality - SIMPLIFIED VERSION
    $('.export-link').click(function(e) {
        e.preventDefault();
        const exportType = $(this).data('type');
        
        // Direct download without notification
        let url = 'gst_report.php?';
        const params = new URLSearchParams();
        
        params.append('start_date', $('[name="start_date"]').val());
        params.append('end_date', $('[name="end_date"]').val());
        params.append('shop_id', $('[name="shop_id"]').val());
        params.append('report_type', $('[name="report_type"]').val());
        params.append('export', exportType);
        
        url += params.toString();
        
        // Direct download
        window.location.href = url;
    });

    // Print optimization
    $('[onclick="window.print()"]').click(function() {
        // Add print-specific classes
        $('body').addClass('print-mode');
        setTimeout(function() {
            $('body').removeClass('print-mode');
        }, 1000);
    });
});
</script>
</body>
</html>