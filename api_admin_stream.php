<?php
// api_admin_stream.php - Real-Time Admin Dashboard Stats Polling Endpoint

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');

session_start();
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

$current_role = $_SESSION['user_role'] ?? '';
if ($current_role !== 'registrar') {
    echo json_encode(['success' => false, 'error' => 'Staff role required']);
    exit();
}

require_once __DIR__ . '/db.php';

$office = strtolower(trim($_GET['office'] ?? $current_role));
if ($office !== 'registrar') $office = 'registrar';

$office_filter_sql = " AND a.office = 'registrar'";

// Waiting count (pending appointments not yet serving)
$count_waiting_q = mysqli_query($con, "SELECT COUNT(*) as cnt FROM appointments a WHERE (a.queue_status = 'Waiting' OR a.queue_status = '' OR a.queue_status IS NULL) AND a.status = 'Pending' {$office_filter_sql}");
$waiting_count = ($count_waiting_q && ($cnt_row = mysqli_fetch_assoc($count_waiting_q))) ? (int)($cnt_row['cnt'] ?? 0) : 0;

// Serving count
$count_serving_q = mysqli_query($con, "SELECT COUNT(*) as cnt FROM appointments a WHERE a.queue_status = 'Serving' {$office_filter_sql}");
$serving_count = ($count_serving_q && ($cnt_row = mysqli_fetch_assoc($count_serving_q))) ? (int)($cnt_row['cnt'] ?? 0) : 0;

// Pending count
$count_pending_q = mysqli_query($con, "SELECT COUNT(*) as cnt FROM appointments a WHERE a.status = 'Pending' {$office_filter_sql}");
$pending_count = ($count_pending_q && ($cnt_row = mysqli_fetch_assoc($count_pending_q))) ? (int)($cnt_row['cnt'] ?? 0) : 0;

// Latest appointment ID (to detect new bookings without fetching all rows)
$max_id_q = mysqli_query($con, "SELECT COALESCE(MAX(id), 0) as mid FROM appointments");
$latest_id = ($max_id_q && ($mid_row = mysqli_fetch_assoc($max_id_q))) ? (int)$mid_row['mid'] : 0;

echo json_encode([
    'success' => true,
    'office' => $office,
    'waiting_count' => $waiting_count,
    'serving_count' => $serving_count,
    'pending_count' => $pending_count,
    'latest_appointment_id' => $latest_id,
    'timestamp' => time()
]);
