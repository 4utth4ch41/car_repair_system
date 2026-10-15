<?php
/**
 * Public tracking: start with one exact field; add filters if owner/car is ambiguous.
 * Keep the agreed status allowlist, ownership checks, and no history fallback.
 * Rate limiting and validation run before loading the shared PDO connection.
 */
require_once __DIR__ . '/includes/public_tracking.php';

header('Cache-Control: no-store, private');
header('Referrer-Policy: no-referrer');

$searchFields = trackingSearchFields();
$searchType = 'plate';
$error = '';
$notice = '';
$result = null;
$nextField = null;
$extraFields = [];
$modalState = 'idle';
$values = ['plate' => '', 'full_name' => '', 'phone' => ''];
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $postedType = $_POST['search_type'] ?? null;
    if (is_string($postedType) && isset($searchFields[$postedType])) {
        $searchType = $postedType;
    }
    if (trackingRateLimitExceeded($_SERVER['REMOTE_ADDR'] ?? 'unknown')) {
        // Preserve safe form values for display only; blocked requests never query PDO.
        [$values] = trackingInput($_POST);
        http_response_code(429);
        $error = 'การค้นหามากเกินไปหรือระบบค้นหายังไม่พร้อม กรุณาลองใหม่ภายหลัง';
    } else {
        [$values, $error] = trackingInput($_POST);
        if ($error === '') {
            try {
                require __DIR__ . '/config/database.php';
                // Recompute from all submitted filters; do not trust a client-supplied stage or ID.
                $outcome = trackingLookup($pdo, $values, $searchType);
                $modalState = $outcome['state'];
                $result = $outcome['result'];
                $nextField = $outcome['next_field'];
                if ($outcome['state'] === 'not_found') {
                    $error = 'ไม่พบข้อมูลการซ่อมของคุณ';
                } elseif ($outcome['state'] === 'needs_more') {
                    $notice = 'กรุณากรอก' . $searchFields[$nextField]['label'] . 'เพิ่มเติม เพื่อระบุรถของคุณให้ชัดเจน';
                } elseif ($outcome['state'] === 'ambiguous') {
                    $error = 'ยังไม่สามารถระบุรถของคุณได้ชัดเจน กรุณาติดต่ออู่เพื่อตรวจสอบข้อมูล';
                }
            } catch (Throwable $e) {
                http_response_code(503);
                $error = 'เกิดข้อผิดพลาดระหว่างการประมวลผล โปรดลองใหม่ภายหลัง';
                error_log('Public tracking: lookup failed (' . get_class($e) . ')');
            }
        }
    }
    // This context controls presentation only; SQL still recomputes all filters.
    if (($_POST['form_context'] ?? '') === 'refinement' && $modalState === 'idle') {
        $modalState = 'error';
    }
    // Preserve earlier filters, including an invalid extra field so it can be corrected.
    foreach (array_slice(trackingSearchOrder($searchType), 1) as $key) {
        if ($values[$key] !== '' || $nextField === $key || array_key_exists($key, $_POST)) {
            $extraFields[] = $key;
        }
    }
}
require __DIR__ . '/includes/public_tracking_view.php';
