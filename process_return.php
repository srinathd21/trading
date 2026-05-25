<?php
date_default_timezone_set('Asia/Kolkata');
session_start();

require_once 'config/database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

$user_id = (int)($_SESSION['user_id'] ?? 0);
$business_id = (int)($_SESSION['current_business_id'] ?? ($_SESSION['business_id'] ?? 1));
$user_role = $_SESSION['role'] ?? '';
$current_shop_id = $_SESSION['current_shop_id'] ?? null;

$can_process_returns = in_array($user_role, ['admin', 'shop_manager', 'seller', 'cashier'], true);

if (!$can_process_returns) {
    $_SESSION['error'] = "You don't have permission to process returns.";
    header('Location: invoices.php');
    exit();
}

/* =========================================================
   HELPERS
========================================================= */
function redirectBack(string $type, string $message): void
{
    $_SESSION[$type] = $message;
    header('Location: invoices.php');
    exit();
}

function tableExists(PDO $pdo, string $table): bool
{
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
        ");
        $stmt->execute([$table]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (Exception $e) {
        return false;
    }
}

function columnExists(PDO $pdo, string $table, string $column): bool
{
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
              AND COLUMN_NAME = ?
        ");
        $stmt->execute([$table, $column]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (Exception $e) {
        return false;
    }
}

function ensureReturnProductsManageTable(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `return_products_manage` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `business_id` int(11) NOT NULL,
            `shop_id` int(11) DEFAULT NULL,

            `return_id` int(11) NOT NULL,
            `return_item_id` int(11) NOT NULL,
            `invoice_id` int(11) NOT NULL,
            `invoice_item_id` int(11) NOT NULL,

            `customer_id` int(11) NOT NULL,
            `product_id` int(11) NOT NULL,
            `product_name` varchar(255) DEFAULT NULL,

            `returned_qty` decimal(12,4) NOT NULL DEFAULT 0.0000,
            `managed_qty` decimal(12,4) NOT NULL DEFAULT 0.0000,

            `unit_price` decimal(12,2) NOT NULL DEFAULT 0.00,
            `return_value` decimal(12,2) NOT NULL DEFAULT 0.00,

            `return_condition` enum('good','damaged','expired','wrong_item','defective','other') NOT NULL DEFAULT 'good',
            `manage_action` enum('pending','restocked','damaged_stock','scrap','supplier_return','hold') NOT NULL DEFAULT 'pending',

            `stock_updated` tinyint(1) NOT NULL DEFAULT 0,
            `reason` varchar(255) DEFAULT NULL,
            `notes` text DEFAULT NULL,

            `managed_by` int(11) DEFAULT NULL,
            `managed_at` datetime DEFAULT NULL,

            `created_at` timestamp NULL DEFAULT current_timestamp(),
            `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),

            PRIMARY KEY (`id`),
            KEY `idx_rpm_business` (`business_id`),
            KEY `idx_rpm_shop` (`shop_id`),
            KEY `idx_rpm_return` (`return_id`),
            KEY `idx_rpm_return_item` (`return_item_id`),
            KEY `idx_rpm_invoice` (`invoice_id`),
            KEY `idx_rpm_invoice_item` (`invoice_item_id`),
            KEY `idx_rpm_customer` (`customer_id`),
            KEY `idx_rpm_product` (`product_id`),
            KEY `idx_rpm_action` (`manage_action`),
            KEY `idx_rpm_stock_updated` (`stock_updated`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

/*
    Safe column add:
    This will not stop return process if product_name already exists.
*/
function ensureReturnItemsProductName(PDO $pdo): void
{
    if (columnExists($pdo, 'return_items', 'product_name')) {
        return;
    }

    try {
        $pdo->exec("
            ALTER TABLE `return_items`
            ADD COLUMN `product_name` varchar(255) DEFAULT NULL AFTER `product_id`
        ");
    } catch (PDOException $e) {
        /*
            42S21 / 1060 = Duplicate column name.
            Ignore because column already exists.
        */
        $sqlState = $e->getCode();
        $msg = $e->getMessage();

        if ($sqlState === '42S21' || strpos($msg, 'Duplicate column name') !== false || strpos($msg, '1060') !== false) {
            return;
        }

        throw $e;
    }
}

/* =========================================================
   ONLY POST ALLOWED
========================================================= */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: invoices.php');
    exit();
}

$invoice_id = (int)($_POST['invoice_id'] ?? 0);
$customer_id = (int)($_POST['customer_id'] ?? 0);
$return_reason = trim((string)($_POST['return_reason'] ?? ''));
$return_date = $_POST['return_date'] ?? date('Y-m-d');
$return_notes = trim((string)($_POST['return_notes'] ?? ''));
$refund_to_cash = isset($_POST['refund_to_cash']) ? 1 : 0;

if ($invoice_id <= 0 || $customer_id <= 0 || $return_reason === '') {
    redirectBack('error', 'Missing required fields.');
}

try {
    ensureReturnProductsManageTable($pdo);
    ensureReturnItemsProductName($pdo);

    /* =========================================================
       VERIFY INVOICE
    ========================================================= */
    $invoice_stmt = $pdo->prepare("
        SELECT *
        FROM invoices
        WHERE id = ?
          AND business_id = ?
        LIMIT 1
    ");
    $invoice_stmt->execute([$invoice_id, $business_id]);
    $invoice = $invoice_stmt->fetch(PDO::FETCH_ASSOC);

    if (!$invoice) {
        redirectBack('error', 'Invoice not found.');
    }

    if ($user_role !== 'admin' && !empty($current_shop_id)) {
        if ((int)($invoice['shop_id'] ?? 0) !== (int)$current_shop_id) {
            redirectBack('error', 'This invoice does not belong to your current shop.');
        }
    }

    $invoice_shop_id = (int)($invoice['shop_id'] ?? $current_shop_id ?? 0);

    if ($invoice_shop_id <= 0) {
        redirectBack('error', 'Shop not found for this invoice.');
    }

    /* =========================================================
       COLLECT RETURN ITEMS
    ========================================================= */
    $return_items = [];
    $total_return_amount = 0.00;

    foreach ($_POST as $key => $value) {
        if (strpos($key, 'return_qty_') !== 0) {
            continue;
        }

        $invoice_item_id = (int)str_replace('return_qty_', '', $key);
        $qty = (int)$value;

        if ($invoice_item_id <= 0 || $qty <= 0) {
            continue;
        }

        $item_stmt = $pdo->prepare("
            SELECT
                ii.*,
                COALESCE(p.product_name, '') AS product_name,
                COALESCE(p.stock_price, 0) AS stock_price
            FROM invoice_items ii
            LEFT JOIN products p ON p.id = ii.product_id
            WHERE ii.id = ?
              AND ii.invoice_id = ?
            LIMIT 1
        ");
        $item_stmt->execute([$invoice_item_id, $invoice_id]);
        $item = $item_stmt->fetch(PDO::FETCH_ASSOC);

        if (!$item) {
            continue;
        }

        $sold_qty = (int)($item['quantity'] ?? 0);
        $already_returned = (int)($item['return_qty'] ?? 0);
        $available_qty = max(0, $sold_qty - $already_returned);

        if ($qty > $available_qty) {
            redirectBack(
                'error',
                'Return quantity exceeds available quantity for product: ' . (($item['product_name'] ?? '') ?: 'Item')
            );
        }

        $unit_price = (float)($item['unit_price'] ?? 0);
        $return_amount = round($qty * $unit_price, 2);
        $total_return_amount += $return_amount;

        $return_items[] = [
            'invoice_item_id' => $invoice_item_id,
            'product_id'      => (int)($item['product_id'] ?? 0),
            'product_name'    => (string)($item['product_name'] ?? ''),
            'quantity'        => $qty,
            'unit_price'      => $unit_price,
            'return_amount'   => $return_amount,
            'stock_price'     => (float)($item['stock_price'] ?? 0),
        ];
    }

    if (empty($return_items)) {
        redirectBack('error', 'No items selected for return.');
    }

    /* =========================================================
       SAVE RETURN
    ========================================================= */
    $pdo->beginTransaction();

    $return_stmt = $pdo->prepare("
        INSERT INTO returns (
            invoice_id,
            customer_id,
            return_date,
            total_return_amount,
            return_reason,
            notes,
            refund_to_cash,
            processed_by,
            business_id
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    $return_stmt->execute([
        $invoice_id,
        $customer_id,
        $return_date,
        $total_return_amount,
        $return_reason,
        $return_notes,
        $refund_to_cash,
        $user_id,
        $business_id
    ]);

    $return_id = (int)$pdo->lastInsertId();

    foreach ($return_items as $item) {
        /*
            Update only invoice return qty.
            Do not directly restock product_stocks here.
        */
        $update_item = $pdo->prepare("
            UPDATE invoice_items
            SET return_qty = return_qty + ?,
                return_status = CASE
                    WHEN return_qty + ? >= quantity THEN 1
                    ELSE return_status
                END
            WHERE id = ?
              AND invoice_id = ?
        ");

        $update_item->execute([
            $item['quantity'],
            $item['quantity'],
            $item['invoice_item_id'],
            $invoice_id
        ]);

        /*
            Insert return_items with product_name.
        */
        $return_item_stmt = $pdo->prepare("
            INSERT INTO return_items (
                return_id,
                invoice_item_id,
                product_id,
                product_name,
                quantity,
                unit_price,
                return_value
            ) VALUES (?, ?, ?, ?, ?, ?, ?)
        ");

        $return_item_stmt->execute([
            $return_id,
            $item['invoice_item_id'],
            $item['product_id'],
            $item['product_name'],
            $item['quantity'],
            $item['unit_price'],
            $item['return_amount']
        ]);

        $return_item_id = (int)$pdo->lastInsertId();

        /*
            Store in Return Management as pending.
            Stock update should happen only from return_management.php.
        */
        $manage_stmt = $pdo->prepare("
            INSERT INTO return_products_manage (
                business_id,
                shop_id,
                return_id,
                return_item_id,
                invoice_id,
                invoice_item_id,
                customer_id,
                product_id,
                product_name,
                returned_qty,
                managed_qty,
                unit_price,
                return_value,
                return_condition,
                manage_action,
                stock_updated,
                reason,
                notes,
                managed_by,
                managed_at
            ) VALUES (
                ?, ?, ?, ?, ?, ?, ?, ?, ?,
                ?, 0, ?, ?,
                'good',
                'pending',
                0,
                ?, ?,
                NULL,
                NULL
            )
        ");

        $manage_stmt->execute([
            $business_id,
            $invoice_shop_id,
            $return_id,
            $return_item_id,
            $invoice_id,
            $item['invoice_item_id'],
            $customer_id,
            $item['product_id'],
            $item['product_name'],
            $item['quantity'],
            $item['unit_price'],
            $item['return_amount'],
            $return_reason,
            $return_notes
        ]);
    }

    /*
        Refund record:
        invoice_payments payment_method enum does not allow 'refund',
        so use 'other' with negative amount.
    */
    if ($refund_to_cash && (float)($invoice['paid_amount'] ?? 0) > 0) {
        $refund_payment_stmt = $pdo->prepare("
            INSERT INTO invoice_payments (
                invoice_id,
                customer_id,
                business_id,
                payment_amount,
                payment_method,
                payment_date,
                notes,
                created_by,
                created_at
            ) VALUES (?, ?, ?, ?, 'other', ?, ?, ?, NOW())
        ");

        $refund_payment_stmt->execute([
            $invoice_id,
            $customer_id,
            $business_id,
            -abs($total_return_amount),
            $return_date,
            "Refund for return #{$return_id}",
            $user_id
        ]);
    }

    $pdo->commit();

    $_SESSION['success'] = 'Return processed successfully. Products moved to Return Management. Return amount: ₹' . number_format($total_return_amount, 2);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    $_SESSION['error'] = 'Failed to process return: ' . $e->getMessage();
}

header('Location: invoices.php');
exit();
?>