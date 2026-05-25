<?php
// includes/business_features.php
// Business-level feature access helper.

if (!function_exists('hasBusinessFeature')) {
    function hasBusinessFeature(PDO $pdo, int $business_id, string $feature_key): bool
    {
        if ($business_id <= 0 || $feature_key === '') {
            return false;
        }

        try {
            $stmt = $pdo->prepare("
                SELECT bfa.is_enabled, bfa.expires_at
                FROM business_feature_access bfa
                INNER JOIN business_feature_master bfm ON bfm.id = bfa.feature_id
                WHERE bfa.business_id = ?
                  AND bfm.feature_key = ?
                  AND bfm.is_active = 1
                LIMIT 1
            ");
            $stmt->execute([$business_id, $feature_key]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$row || (int)$row['is_enabled'] !== 1) {
                return false;
            }

            if (!empty($row['expires_at']) && $row['expires_at'] < date('Y-m-d')) {
                return false;
            }

            return true;
        } catch (Throwable $e) {
            return false;
        }
    }
}
