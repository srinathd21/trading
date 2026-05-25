<?php
require_once '../includes/auth.php';
require_once '../config/database.php';
require_once '../includes/number_format_helper.php';

checkAuth();

$business_id = getBusinessId();
$shop_id = getShopId();
$user_id = getUserId();

header('Content-Type: application/json');

try {
    $action = $_GET['action'] ?? '';

    switch ($action) {
        case 'get_next_number':
            getNextQuotationNumber();
            break;

        case 'save':
            saveQuotation();
            break;

        case 'list':
            listQuotations();
            break;

        case 'get_items':
            getQuotationItems();
            break;

        case 'delete':
            deleteQuotation();
            break;

        default:
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
    }
} catch (Exception $e) {
    error_log("Quotations API Error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Server error occurred: ' . $e->getMessage()
    ]);
}

function getNextQuotationNumber()
{
    global $pdo, $business_id, $shop_id;

    $data = json_decode(file_get_contents('php://input'), true) ?: [];

    $quotation_date = $data['quotation_date'] ?? $data['date'] ?? date('Y-m-d');

    $result = nf_generate_document_number($pdo, [
        'business_id'   => $business_id,
        'shop_id'       => $shop_id,
        'document_type' => 'quotation',
        'table_name'    => 'quotations',
        'number_column' => 'quotation_number',
        'date_column'   => 'quotation_date',
        'date_value'    => $quotation_date
    ]);

    if (!$result['success']) {
        echo json_encode($result);
        return;
    }

    echo json_encode([
        'success' => true,
        'quotation_number' => $result['number'],
        'document_number' => $result['number'],
        'prefix' => $result['prefix'],
        'middle_value' => $result['middle_value'],
        'next_number' => $result['sequence'],
        'reset_period' => $result['reset_period']
    ]);
}

function saveQuotation()
{
    global $pdo, $business_id, $shop_id, $user_id;

    $data = json_decode(file_get_contents('php://input'), true);

    if (!$data) {
        echo json_encode(['success' => false, 'message' => 'Invalid data']);
        return;
    }

    $pdo->beginTransaction();

    try {
        $quotation_number = trim($data['quotation_number'] ?? '');

        if ($quotation_number === '') {
            $generated = nf_generate_document_number($pdo, [
                'business_id'   => $business_id,
                'shop_id'       => $shop_id,
                'document_type' => 'quotation',
                'table_name'    => 'quotations',
                'number_column' => 'quotation_number',
                'date_column'   => 'quotation_date',
                'date_value'    => $data['quotation_date'] ?? date('Y-m-d')
            ]);

            if (!$generated['success']) {
                throw new Exception($generated['message'] ?? 'Unable to generate quotation number');
            }

            $quotation_number = $generated['number'];
        }

        $checkDuplicate = $pdo->prepare("
            SELECT id
            FROM quotations
            WHERE business_id = ?
              AND shop_id = ?
              AND quotation_number = ?
            LIMIT 1
        ");
        $checkDuplicate->execute([$business_id, $shop_id, $quotation_number]);

        if ($checkDuplicate->fetch()) {
            throw new Exception('Quotation number already exists. Please generate another number.');
        }

        $sql = "INSERT INTO quotations (
                    business_id, shop_id, quotation_number, quotation_date, valid_until,
                    customer_name, customer_phone, customer_email, customer_address, customer_gstin,
                    subtotal, total_discount, total_tax, grand_total, notes, created_by
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $business_id,
            $shop_id,
            $quotation_number,
            $data['quotation_date'],
            $data['valid_until'],
            $data['customer_name'],
            $data['customer_phone'] ?? '',
            $data['customer_email'] ?? '',
            $data['customer_address'] ?? '',
            $data['customer_gstin'] ?? '',
            $data['subtotal'],
            $data['total_discount'] ?? 0,
            $data['total_tax'] ?? 0,
            $data['grand_total'],
            $data['notes'] ?? '',
            $user_id
        ]);

        $quotation_id = $pdo->lastInsertId();

        if (isset($data['items']) && is_array($data['items'])) {
            $item_sql = "INSERT INTO quotation_items (
                            quotation_id, product_id, product_name, quantity, unit_price,
                            discount_amount, discount_type, total_price, hsn_code,
                            cgst_rate, sgst_rate, igst_rate, tax_amount, price_type
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

            $item_stmt = $pdo->prepare($item_sql);

            foreach ($data['items'] as $item) {
                $item_stmt->execute([
                    $quotation_id,
                    $item['product_id'],
                    $item['product_name'],
                    $item['quantity'],
                    $item['unit_price'],
                    $item['discount_amount'] ?? 0,
                    $item['discount_type'] ?? 'percent',
                    $item['total_price'],
                    $item['hsn_code'] ?? '',
                    $item['cgst_rate'] ?? 0,
                    $item['sgst_rate'] ?? 0,
                    $item['igst_rate'] ?? 0,
                    $item['tax_amount'] ?? 0,
                    $item['price_type'] ?? 'retail'
                ]);
            }
        }

        $pdo->commit();

        echo json_encode([
            'success' => true,
            'message' => 'Quotation saved successfully',
            'quotation_id' => $quotation_id,
            'quotation_number' => $quotation_number
        ]);

    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        echo json_encode([
            'success' => false,
            'message' => 'Failed to save quotation: ' . $e->getMessage()
        ]);
    }
}

function listQuotations()
{
    global $pdo, $business_id, $shop_id;

    updateExpiredQuotations();

    $sql = "SELECT 
                q.*,
                DATE_FORMAT(q.quotation_date, '%Y-%m-%d') as formatted_date,
                DATE_FORMAT(q.valid_until, '%Y-%m-%d') as formatted_valid_until,
                (
                    SELECT COUNT(*)
                    FROM quotations
                    WHERE shop_id = ?
                      AND business_id = ?
                ) as total_count
            FROM quotations q
            WHERE q.shop_id = ?
              AND q.business_id = ?
            ORDER BY q.created_at DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$shop_id, $business_id, $shop_id, $business_id]);
    $quotations = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'quotations' => $quotations,
        'total' => count($quotations)
    ]);
}

function updateExpiredQuotations()
{
    global $pdo, $business_id, $shop_id;

    $sql = "UPDATE quotations
            SET status = 'expired'
            WHERE valid_until < CURDATE()
              AND status NOT IN ('accepted', 'rejected', 'expired')
              AND shop_id = ?
              AND business_id = ?";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$shop_id, $business_id]);
}

function getQuotationItems()
{
    global $pdo;

    $quotation_id = $_GET['quotation_id'] ?? 0;

    if (!$quotation_id) {
        echo json_encode(['success' => false, 'message' => 'Quotation ID required']);
        return;
    }

    $sql = "SELECT *
            FROM quotation_items
            WHERE quotation_id = ?";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$quotation_id]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'items' => $items
    ]);
}

function deleteQuotation()
{
    global $pdo, $business_id, $shop_id;

    $data = json_decode(file_get_contents('php://input'), true);
    $quotation_id = $data['quotation_id'] ?? 0;

    if (!$quotation_id) {
        echo json_encode(['success' => false, 'message' => 'Quotation ID required']);
        return;
    }

    $pdo->beginTransaction();

    try {
        $delete_items_sql = "DELETE FROM quotation_items WHERE quotation_id = ?";
        $delete_items_stmt = $pdo->prepare($delete_items_sql);
        $delete_items_stmt->execute([$quotation_id]);

        $delete_sql = "DELETE FROM quotations
                       WHERE id = ?
                         AND shop_id = ?
                         AND business_id = ?";

        $delete_stmt = $pdo->prepare($delete_sql);
        $delete_stmt->execute([$quotation_id, $shop_id, $business_id]);

        $pdo->commit();

        echo json_encode([
            'success' => true,
            'message' => 'Quotation deleted successfully'
        ]);

    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        echo json_encode([
            'success' => false,
            'message' => 'Failed to delete quotation: ' . $e->getMessage()
        ]);
    }
}
?>