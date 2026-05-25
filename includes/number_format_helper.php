<?php
if (!function_exists('nf_normalize_date_object')) {
    function nf_normalize_date_object($date_value = '')
    {
        $date_value = trim((string)$date_value);

        if ($date_value === '') {
            return new DateTime();
        }

        $date_value = str_replace('T', ' ', $date_value);
        $date_only = substr($date_value, 0, 10);

        $dateObj = DateTime::createFromFormat('Y-m-d', $date_only);

        if (!$dateObj) {
            return new DateTime();
        }

        return $dateObj;
    }
}

if (!function_exists('nf_get_financial_year')) {
    function nf_get_financial_year(DateTime $dateObj)
    {
        $year = (int)$dateObj->format('Y');
        $month = (int)$dateObj->format('m');

        if ($month >= 4) {
            return [
                'start_year' => $year,
                'end_year' => $year + 1,
                'label' => $year . '-' . ($year + 1),
                'start' => $year . '-04-01 00:00:00',
                'end' => ($year + 1) . '-03-31 23:59:59'
            ];
        }

        return [
            'start_year' => $year - 1,
            'end_year' => $year,
            'label' => ($year - 1) . '-' . $year,
            'start' => ($year - 1) . '-04-01 00:00:00',
            'end' => $year . '-03-31 23:59:59'
        ];
    }
}

if (!function_exists('nf_get_middle_value')) {
    function nf_get_middle_value(DateTime $dateObj, $middle_format)
    {
        switch ($middle_format) {
            case 'year_month':
                return $dateObj->format('Ym');

            case 'financial_year':
                $fy = nf_get_financial_year($dateObj);
                return $fy['label'];

            case 'year':
                return $dateObj->format('Y');

            case 'none':
            default:
                return '';
        }
    }
}

if (!function_exists('nf_get_reset_range')) {
    function nf_get_reset_range(DateTime $dateObj, $reset_period)
    {
        switch ($reset_period) {
            case 'month':
                return [
                    'start' => $dateObj->format('Y-m-01 00:00:00'),
                    'end' => $dateObj->format('Y-m-t 23:59:59')
                ];

            case 'year':
                return [
                    'start' => $dateObj->format('Y-01-01 00:00:00'),
                    'end' => $dateObj->format('Y-12-31 23:59:59')
                ];

            case 'never':
                return [
                    'start' => '1970-01-01 00:00:00',
                    'end' => '2999-12-31 23:59:59'
                ];

            case 'financial_year':
            default:
                $fy = nf_get_financial_year($dateObj);
                return [
                    'start' => $fy['start'],
                    'end' => $fy['end']
                ];
        }
    }
}

if (!function_exists('nf_default_prefix')) {
    function nf_default_prefix($document_type)
    {
        $map = [
            'invoice_gst' => 'INV',
            'invoice_non_gst' => 'NGST',
            'purchase' => 'PO',
            'quotation' => 'QT'
        ];

        return $map[$document_type] ?? 'DOC';
    }
}

if (!function_exists('nf_load_setting')) {
    function nf_load_setting(PDO $pdo, $business_id, $shop_id, $document_type)
    {
        $setting = null;

        if (!empty($shop_id)) {
            $stmt = $pdo->prepare("
                SELECT *
                FROM number_format_settings
                WHERE business_id = ?
                  AND shop_id = ?
                  AND document_type = ?
                  AND is_active = 1
                LIMIT 1
            ");
            $stmt->execute([$business_id, $shop_id, $document_type]);
            $setting = $stmt->fetch(PDO::FETCH_ASSOC);
        }

        if (!$setting) {
            $stmt = $pdo->prepare("
                SELECT *
                FROM number_format_settings
                WHERE business_id = ?
                  AND shop_id IS NULL
                  AND document_type = ?
                  AND is_active = 1
                LIMIT 1
            ");
            $stmt->execute([$business_id, $document_type]);
            $setting = $stmt->fetch(PDO::FETCH_ASSOC);
        }

        if (!$setting) {
            $setting = [
                'business_id' => $business_id,
                'shop_id' => $shop_id,
                'document_type' => $document_type,
                'prefix' => nf_default_prefix($document_type),
                'middle_format' => 'year_month',
                'separator' => '-',
                'number_length' => 4,
                'reset_period' => 'financial_year',
                'is_active' => 1
            ];
        }

        $setting['number_length'] = (int)($setting['number_length'] ?? 4);
        if ($setting['number_length'] <= 0) {
            $setting['number_length'] = 4;
        }

        return $setting;
    }
}

if (!function_exists('nf_preview_number')) {
    function nf_preview_number(array $setting, $date_value = '', $sample_number = 9)
    {
        $dateObj = nf_normalize_date_object($date_value);

        $prefix = trim($setting['prefix'] ?? '');
        $middle = nf_get_middle_value($dateObj, $setting['middle_format'] ?? 'year_month');
        $separator = $setting['format_separator'] ?? '-';
        $length = (int)($setting['number_length'] ?? 4);

        if ($length <= 0) {
            $length = 4;
        }

        return $prefix . $middle . $separator . str_pad($sample_number, $length, '0', STR_PAD_LEFT);
    }
}

if (!function_exists('nf_generate_document_number')) {
    function nf_generate_document_number(PDO $pdo, array $args)
    {
        $business_id = (int)($args['business_id'] ?? 0);
        $shop_id = $args['shop_id'] ?? null;
        $document_type = $args['document_type'] ?? '';
        $table_name = $args['table_name'] ?? '';
        $number_column = $args['number_column'] ?? '';
        $date_column = $args['date_column'] ?? 'created_at';
        $date_value = $args['date_value'] ?? date('Y-m-d H:i:s');

        if ($business_id <= 0 || $document_type === '' || $table_name === '' || $number_column === '') {
            return [
                'success' => false,
                'message' => 'Invalid number generation parameters'
            ];
        }

        $allowed_tables = ['invoices', 'purchases', 'quotations'];
        $allowed_columns = ['invoice_number', 'purchase_number', 'quotation_number'];
        $allowed_date_columns = ['created_at', 'purchase_date', 'quotation_date'];

        if (!in_array($table_name, $allowed_tables, true)) {
            return ['success' => false, 'message' => 'Invalid table name'];
        }

        if (!in_array($number_column, $allowed_columns, true)) {
            return ['success' => false, 'message' => 'Invalid number column'];
        }

        if (!in_array($date_column, $allowed_date_columns, true)) {
            return ['success' => false, 'message' => 'Invalid date column'];
        }

        $dateObj = nf_normalize_date_object($date_value);
        $setting = nf_load_setting($pdo, $business_id, $shop_id, $document_type);

        $prefix = trim($setting['prefix'] ?? '');
        $middle_format = $setting['middle_format'] ?? 'year_month';
        $separator = $setting['format_separator'] ?? '-';
        $number_length = (int)($setting['number_length'] ?? 4);
        $reset_period = $setting['reset_period'] ?? 'financial_year';

        if ($number_length <= 0) {
            $number_length = 4;
        }

        $middle_value = nf_get_middle_value($dateObj, $middle_format);
        $reset_range = nf_get_reset_range($dateObj, $reset_period);

        $full_prefix = $prefix . $middle_value . $separator;

        $sql = "
            SELECT {$number_column} AS document_number
            FROM {$table_name}
            WHERE business_id = ?
              AND {$number_column} LIKE ?
              AND {$date_column} BETWEEN ? AND ?
        ";

        $params = [
            $business_id,
            $prefix . '%',
            $reset_range['start'],
            $reset_range['end']
        ];

        if ($shop_id !== null && $shop_id !== '') {
            $sql .= " AND shop_id = ?";
            $params[] = $shop_id;
        }

        $sql .= " ORDER BY CAST(RIGHT({$number_column}, {$number_length}) AS UNSIGNED) DESC LIMIT 1";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $last = $stmt->fetch(PDO::FETCH_ASSOC);

        $seq = 1;

        if ($last && !empty($last['document_number'])) {
            $last_digits = substr($last['document_number'], -$number_length);
            if (ctype_digit($last_digits)) {
                $seq = ((int)$last_digits) + 1;
            }
        }

        $attempt = 0;
        $max_attempts = 100;

        while ($attempt < $max_attempts) {
            $sequence = str_pad($seq, $number_length, '0', STR_PAD_LEFT);
            $document_number = $full_prefix . $sequence;

            $check_sql = "
                SELECT id
                FROM {$table_name}
                WHERE business_id = ?
                  AND {$number_column} = ?
                LIMIT 1
            ";

            $check_params = [$business_id, $document_number];

            if ($shop_id !== null && $shop_id !== '') {
                $check_sql = "
                    SELECT id
                    FROM {$table_name}
                    WHERE business_id = ?
                      AND shop_id = ?
                      AND {$number_column} = ?
                    LIMIT 1
                ";
                $check_params = [$business_id, $shop_id, $document_number];
            }

            $check_stmt = $pdo->prepare($check_sql);
            $check_stmt->execute($check_params);

            if (!$check_stmt->fetch(PDO::FETCH_ASSOC)) {
                return [
                    'success' => true,
                    'number' => $document_number,
                    'prefix' => $prefix,
                    'middle_format' => $middle_format,
                    'middle_value' => $middle_value,
                    'separator' => $separator,
                    'sequence' => $sequence,
                    'number_length' => $number_length,
                    'reset_period' => $reset_period,
                    'range_start' => $reset_range['start'],
                    'range_end' => $reset_range['end']
                ];
            }

            $seq++;
            $attempt++;
        }

        return [
            'success' => false,
            'message' => 'Unable to generate unique document number'
        ];
    }
}