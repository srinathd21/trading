<?php
// low_stock_export.php
require_once 'config/database.php';
session_start();
if (!isset($_SESSION['user_id'])) exit();

$low_stock = $pdo->query("
    SELECT p.product_name, p.product_code, c.category_name,
           COALESCE(SUM(ps.quantity),0) as current_stock,
           p.min_stock_level,
           (p.min_stock_level - COALESCE(SUM(ps.quantity),0)) as reorder_qty,
           p.retail_price
    FROM products p
    LEFT JOIN product_stocks ps ON ps.product_id = p.id
    LEFT JOIN categories c ON p.category_id = c.id
    WHERE p.is_active = 1
    GROUP BY p.id
    HAVING current_stock < p.min_stock_level
    ORDER BY current_stock ASC
")->fetchAll();

$filename = "Low_Stock_Report_" . date('d-M-Y') . ".csv";
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
echo "\xEF\xBB\xBF"; // BOM

$output = fopen('php://output', 'w');
fputcsv($output, ['Product', 'Code', 'Category', 'Current Stock', 'Min Level', 'Reorder Qty', 'Retail Price', 'Status']);

foreach ($low_stock as $p) {
    $status = $p['current_stock'] == 0 ? 'OUT OF STOCK' : 'LOW STOCK';
    fputcsv($output, [
        $p['product_name'],
        $p['product_code'],
        $p['category_name'] ?: '—',
        $p['current_stock'],
        $p['min_stock_level'],
        max(1, $p['reorder_qty']),
        '₹' . number_format($p['retail_price'], 2),
        $status
    ]);
}
exit();
?>