<?php
require_once '../includes/auth.php';
require_once '../config/database.php';
require_once '../includes/number_format_helper.php';

checkAuth();

header('Content-Type: application/json');

$business_id = getBusinessId();
$shop_id = getShopId();

try {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    if (!$data) {
        $data = $_POST;
    }

    $document_type = $data['document_type'] ?? ($_GET['document_type'] ?? '');

    if ($document_type === '') {
        echo json_encode([
            'success' => false,
            'message' => 'document_type is required'
        ]);
        exit;
    }

    switch ($document_type) {
        case 'invoice_gst':
            $result = nf_generate_document_number($pdo, [
                'business_id' => $business_id,
                'shop_id' => $shop_id,
                'document_type' => 'invoice_gst',
                'table_name' => 'invoices',
                'number_column' => 'invoice_number',
                'date_column' => 'created_at',
                'date_value' => $data['date'] ?? $data['invoice_date'] ?? date('Y-m-d H:i:s')
            ]);
            break;

        case 'invoice_non_gst':
            $result = nf_generate_document_number($pdo, [
                'business_id' => $business_id,
                'shop_id' => $shop_id,
                'document_type' => 'invoice_non_gst',
                'table_name' => 'invoices',
                'number_column' => 'invoice_number',
                'date_column' => 'created_at',
                'date_value' => $data['date'] ?? $data['invoice_date'] ?? date('Y-m-d H:i:s')
            ]);
            break;

        case 'purchase':
            $result = nf_generate_document_number($pdo, [
                'business_id' => $business_id,
                'shop_id' => $shop_id,
                'document_type' => 'purchase',
                'table_name' => 'purchases',
                'number_column' => 'purchase_number',
                'date_column' => 'purchase_date',
                'date_value' => $data['date'] ?? $data['purchase_date'] ?? date('Y-m-d')
            ]);
            break;

        case 'quotation':
            $result = nf_generate_document_number($pdo, [
                'business_id' => $business_id,
                'shop_id' => $shop_id,
                'document_type' => 'quotation',
                'table_name' => 'quotations',
                'number_column' => 'quotation_number',
                'date_column' => 'quotation_date',
                'date_value' => $data['date'] ?? $data['quotation_date'] ?? date('Y-m-d')
            ]);
            break;

        default:
            echo json_encode([
                'success' => false,
                'message' => 'Invalid document_type'
            ]);
            exit;
    }

    if (!$result['success']) {
        echo json_encode($result);
        exit;
    }

    echo json_encode([
        'success' => true,
        'document_type' => $document_type,
        'document_number' => $result['number'],
        'number' => $result['number'],
        'prefix' => $result['prefix'],
        'middle_value' => $result['middle_value'],
        'separator' => $result['separator'],
        'sequence' => $result['sequence'],
        'reset_period' => $result['reset_period']
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Failed to generate number: ' . $e->getMessage()
    ]);
}