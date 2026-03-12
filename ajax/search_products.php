<?php
// ajax/search_products.php
require_once '../config/database.php';
$term = $_GET['term'] . '%';
$warehouse_stmt = $pdo->query("SELECT id FROM shops WHERE is_warehouse = 1 LIMIT 1");
$warehouse_id = $warehouse_stmt->fetchColumn();

$stmt = $pdo->prepare("
    SELECT p.id, p.product_name, p.product_code, COALESCE(ps.quantity, 0) as stock,
           CONCAT(p.product_name, ' (', p.product_code, ')') as label
    FROM products p
    LEFT JOIN product_stocks ps ON p.id = ps.product_id AND ps.shop_id = ?
    WHERE p.is_active = 1 AND (
        p.product_name LIKE ? OR 
        p.product_code LIKE ? OR 
        p.barcode LIKE ?
    )
    ORDER BY p.product_name LIMIT 20
");
$stmt->execute([$warehouse_id, $term, $term, $term]);
$results = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode(array_map(function($r) {
    return [
        'id' => $r['id'],
        'label' => $r['label'],
        'stock' => $r['stock']
    ];
}, $results));