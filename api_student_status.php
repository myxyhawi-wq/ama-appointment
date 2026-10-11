<?php
// api_student_status.php — Student-facing real-time appointment status polling endpoint

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');

session_start();

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

// Only students can poll this endpoint
$current_role = $_SESSION['user_role'] ?? '';
if ($current_role === 'registrar') {
    echo json_encode(['success' => false, 'error' => 'Students only']);
    exit();
}

require_once __DIR__ . '/db.php';

$student_id = mysqli_real_escape_string($con, $_SESSION['user_id']);

$result = mysqli_query($con,
    "SELECT id, status, queue_number, queue_status
     FROM appointments
     WHERE student_id = '$student_id'
     ORDER BY id DESC
     LIMIT 50"
);

$appointments = [];
if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        $appointments[] = [
            'id'           => (int)$row['id'],
            'status'       => $row['status'],
            'queue_number' => $row['queue_number'],
            'queue_status' => $row['queue_status'],
        ];
    }
}

echo json_encode([
    'success'      => true,
    'appointments' => $appointments,
    'timestamp'    => time(),
]);
