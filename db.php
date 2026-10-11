<?php
$host = "127.0.0.1";
$user = "root";
$pass = "";
$dbname = "register"; // use ONE database only
$port = 3307;

mysqli_report(MYSQLI_REPORT_OFF);

$con = mysqli_connect($host, $user, $pass, $dbname, $port);

if (!$con) {
    $con = mysqli_connect("localhost", $user, $pass, $dbname, 3306);
}

if (!$con) {
    die("Connection failed: " . mysqli_connect_error());
}

if (!mysqli_select_db($con, $dbname)) {
    if (!mysqli_query($con, "CREATE DATABASE IF NOT EXISTS `$dbname`")) {
        die("Database creation failed: " . mysqli_error($con));
    }

    if (!mysqli_select_db($con, $dbname)) {
        die("Unable to select database '$dbname': " . mysqli_error($con));
    }
}

if (!mysqli_set_charset($con, "utf8mb4")) {
    error_log("Failed to set charset: " . mysqli_error($con));
}

if (!function_exists('popup_response_script')) {
    function popup_response_script($message, $type = 'error', $redirect = '') {
        $redirect_script = $redirect !== '' ? 'window.location.href = ' . json_encode($redirect) . ';' : '';
        return '<script>document.addEventListener("DOMContentLoaded", function () { var style = document.createElement("link"); style.rel = "stylesheet"; style.href = "popup.css"; document.head.appendChild(style); var script = document.createElement("script"); script.src = "popup.js?v=' . filemtime(__DIR__ . '/popup.js') . '"; script.onload = function () { AppPopup.show(' . json_encode($message) . ', ' . json_encode($type) . '); }; document.body.appendChild(script); window.addEventListener("appPopupClosed", function () { ' . $redirect_script . ' }); });</script>';
    }
}

// 1. Ensure users table exists
$table_sql = "CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id VARCHAR(50) NOT NULL DEFAULT '',
    first_name VARCHAR(100) NOT NULL,
    last_name VARCHAR(100) NOT NULL,
    email VARCHAR(255) NOT NULL UNIQUE,
    phone VARCHAR(25) NOT NULL DEFAULT '',
    password VARCHAR(255) NOT NULL,
    role VARCHAR(50) NOT NULL DEFAULT 'student',
    education_level VARCHAR(20) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)";

if (!mysqli_query($con, $table_sql)) {
    die("Users table creation failed: " . mysqli_error($con));
}

// Check & add missing columns to users table
$columns_result = mysqli_query($con, "SHOW COLUMNS FROM users");
$existing_user_columns = [];
if ($columns_result) {
    while ($column = mysqli_fetch_assoc($columns_result)) {
        $existing_user_columns[] = $column['Field'];
    }
}

if (!in_array('phone', $existing_user_columns, true)) {
    mysqli_query($con, "ALTER TABLE users ADD COLUMN phone VARCHAR(25) NOT NULL DEFAULT '' AFTER email");
}

if (!in_array('student_id', $existing_user_columns, true)) {
    mysqli_query($con, "ALTER TABLE users ADD COLUMN student_id VARCHAR(50) NOT NULL DEFAULT '' AFTER id");
}

if (!in_array('role', $existing_user_columns, true)) {
    mysqli_query($con, "ALTER TABLE users ADD COLUMN role VARCHAR(50) NOT NULL DEFAULT 'student' AFTER password");
} else {
    // Keep the role column flexible for staff and student accounts.
    mysqli_query($con, "ALTER TABLE users MODIFY COLUMN role VARCHAR(50) NOT NULL DEFAULT 'student'");
}
if (!in_array('education_level', $existing_user_columns, true)) {
    mysqli_query($con, "ALTER TABLE users ADD COLUMN education_level VARCHAR(20) NULL AFTER role");
}
// 2. Ensure appointments table exists
$appointments_table_sql = "CREATE TABLE IF NOT EXISTS appointments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id VARCHAR(50) NOT NULL,
    office VARCHAR(100) NOT NULL DEFAULT 'registrar',
    service VARCHAR(150) NOT NULL,
    appointment_date DATE NOT NULL, 
    appointment_time TIME NOT NULL,
    status VARCHAR(50) NOT NULL DEFAULT 'Pending',
    rejection_reason TEXT NULL,
    registrar_notes TEXT NULL,
    queue_number VARCHAR(20) NOT NULL DEFAULT '',
    queue_status VARCHAR(30) NOT NULL DEFAULT 'Waiting',
    called_at DATETIME NULL DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    processed_at DATETIME NULL,
    INDEX (student_id),
    INDEX (office),
    INDEX (appointment_date)
)";

if (!mysqli_query($con, $appointments_table_sql)) {
    die("Appointments table creation failed: " . mysqli_error($con));
}

// Check & add missing columns to appointments table
$appointments_columns_result = mysqli_query($con, "SHOW COLUMNS FROM appointments");
$existing_app_columns = [];
if ($appointments_columns_result) {
    while ($column = mysqli_fetch_assoc($appointments_columns_result)) {
        $existing_app_columns[] = $column['Field'];
    }
}

if (!in_array('queue_number', $existing_app_columns, true)) {
    mysqli_query($con, "ALTER TABLE appointments ADD COLUMN queue_number VARCHAR(20) NOT NULL DEFAULT '' AFTER status");
}
if (!in_array('queue_status', $existing_app_columns, true)) {
    mysqli_query($con, "ALTER TABLE appointments ADD COLUMN queue_status VARCHAR(30) NOT NULL DEFAULT 'Waiting' AFTER queue_number");
}
if (!in_array('called_at', $existing_app_columns, true)) {
    mysqli_query($con, "ALTER TABLE appointments ADD COLUMN called_at DATETIME NULL DEFAULT NULL AFTER queue_status");
}
if (!in_array('slot_id', $existing_app_columns, true)) {
    mysqli_query($con, "ALTER TABLE appointments ADD COLUMN slot_id INT NULL DEFAULT NULL AFTER id");
    mysqli_query($con, "ALTER TABLE appointments ADD INDEX (slot_id)");
}
if (!in_array('rejection_reason', $existing_app_columns, true)) {
    mysqli_query($con, "ALTER TABLE appointments ADD COLUMN rejection_reason TEXT NULL AFTER status");
}
if (!in_array('updated_at', $existing_app_columns, true)) {
    mysqli_query($con, "ALTER TABLE appointments ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at");
}
if (!in_array('registrar_notes', $existing_app_columns, true)) {
    mysqli_query($con, "ALTER TABLE appointments ADD COLUMN registrar_notes TEXT NULL AFTER rejection_reason");
}
if (!in_array('processed_at', $existing_app_columns, true)) {
    mysqli_query($con, "ALTER TABLE appointments ADD COLUMN processed_at DATETIME NULL AFTER updated_at");
}

// Canonical appointment statuses for reporting and future foreign-key integration.
mysqli_query($con, "CREATE TABLE IF NOT EXISTS statuses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(30) NOT NULL UNIQUE,
    label VARCHAR(50) NOT NULL
)");
mysqli_query($con, "INSERT IGNORE INTO statuses (code, label) VALUES
    ('pending', 'Pending'), ('approved', 'Approved'), ('rejected', 'Rejected'), ('cancelled', 'Cancelled')");

// 3. Ensure appointment_slots table exists (Staff-Controlled Availability System)
mysqli_query($con, "CREATE TABLE IF NOT EXISTS appointment_slots (
    id INT AUTO_INCREMENT PRIMARY KEY,
    office VARCHAR(50) NOT NULL,
    slot_date DATE NOT NULL,
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    max_capacity INT NOT NULL DEFAULT 3,
    status VARCHAR(20) NOT NULL DEFAULT 'open',
    created_by VARCHAR(50) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_office_slot (office, slot_date, start_time),
    INDEX (office),
    INDEX (slot_date),
    INDEX (status)
)");

mysqli_query($con, "CREATE TABLE IF NOT EXISTS appointment_day_limits (
    office VARCHAR(50) NOT NULL,
    slot_date DATE NOT NULL,
    max_capacity INT UNSIGNED NOT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (office, slot_date)
)");

// Helper to seed initial published slots if none exist
$slots_count_q = mysqli_query($con, "SELECT COUNT(*) as total FROM appointment_slots");
$slots_count = mysqli_fetch_assoc($slots_count_q)['total'] ?? 0;
if ($slots_count == 0) {
    // Generate open slots for today and next 5 weekdays (e.g. 8:00 AM - 11:30 AM & 1:00 PM - 4:30 PM)
    $today_ts = strtotime('today');
    for ($d = 0; $d < 7; $d++) {
        $cur_date = date('Y-m-d', strtotime("+$d days", $today_ts));
        $day_of_week = date('N', strtotime($cur_date));
        if ($day_of_week >= 6) continue; // Skip weekends

        // Registrar standard open windows (e.g. Morning 9:00 - 11:30, Afternoon 1:30 - 4:00)
        $reg_times = [
            ['09:00:00', '09:30:00'],
            ['09:30:00', '10:00:00'],
            ['10:00:00', '10:30:00'],
            ['10:30:00', '11:00:00'],
            ['11:00:00', '11:30:00'],
            ['13:30:00', '14:00:00'],
            ['14:00:00', '14:30:00'],
            ['14:30:00', '15:00:00'],
            ['15:00:00', '15:30:00'],
            ['15:30:00', '16:00:00']
        ];
        foreach ($reg_times as $t) {
            mysqli_query($con, "INSERT IGNORE INTO appointment_slots (office, slot_date, start_time, end_time, max_capacity, status, created_by) 
                                VALUES ('registrar', '$cur_date', '{$t[0]}', '{$t[1]}', 3, 'open', 'ADMIN-001')");
        }

    }
}

// 4. Ensure offices table exists & is populated
mysqli_query($con, "CREATE TABLE IF NOT EXISTS offices (
    id INT AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(50) NOT NULL UNIQUE,
    name VARCHAR(100) NOT NULL,
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

$offices_count = mysqli_query($con, "SELECT COUNT(*) AS total FROM offices");
$row_oc = mysqli_fetch_assoc($offices_count);
if (($row_oc['total'] ?? 0) == 0) {
    mysqli_query($con, "INSERT INTO offices (code, name, description) VALUES
        ('registrar', 'Registrar Office', 'Enrollment, Academic Records, Transcripts, and Certifications')");
}

// 4. Ensure services table exists & is populated
mysqli_query($con, "CREATE TABLE IF NOT EXISTS services (
    id INT AUTO_INCREMENT PRIMARY KEY,
    office_code VARCHAR(50) NOT NULL,
    service_name VARCHAR(150) NOT NULL,
    estimated_duration_min INT DEFAULT 15,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX (office_code)
)");
$service_cols = mysqli_query($con, "SHOW COLUMNS FROM services");
$service_fields = [];
if ($service_cols) while ($service_col = mysqli_fetch_assoc($service_cols)) $service_fields[] = $service_col['Field'];
if (!in_array('is_visible', $service_fields, true)) mysqli_query($con, "ALTER TABLE services ADD COLUMN is_visible TINYINT(1) NOT NULL DEFAULT 1");
if (!in_array('available_from', $service_fields, true)) mysqli_query($con, "ALTER TABLE services ADD COLUMN available_from DATETIME NULL");
if (!in_array('available_until', $service_fields, true)) mysqli_query($con, "ALTER TABLE services ADD COLUMN available_until DATETIME NULL");
if (!in_array('education_level', $service_fields, true)) mysqli_query($con, "ALTER TABLE services ADD COLUMN education_level VARCHAR(20) NOT NULL DEFAULT 'ALL'");
if (!in_array('target_level', $service_fields, true)) mysqli_query($con, "ALTER TABLE services ADD COLUMN target_level VARCHAR(20) NOT NULL DEFAULT 'Both'");
mysqli_query($con, "UPDATE services SET target_level = CASE WHEN education_level = 'SHS' THEN 'SHS' WHEN education_level = 'College' THEN 'College' ELSE 'Both' END WHERE target_level = 'Both'");

mysqli_query($con, "CREATE TABLE IF NOT EXISTS queue_control (
    office VARCHAR(50) PRIMARY KEY,
    is_paused TINYINT(1) NOT NULL DEFAULT 0,
    counter_name VARCHAR(100) NOT NULL DEFAULT 'Registrar Window 1',
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)");
mysqli_query($con, "INSERT IGNORE INTO queue_control (office) VALUES ('registrar')");

$services_count = mysqli_query($con, "SELECT COUNT(*) AS total FROM services");
$row_sc = mysqli_fetch_assoc($services_count);
if (($row_sc['total'] ?? 0) == 0) {
    mysqli_query($con, "INSERT INTO services (office_code, service_name, estimated_duration_min) VALUES
        ('registrar', 'Enrollment Verification', 15),
        ('registrar', 'Transcript Request (TOR)', 20),
        ('registrar', 'Student Records Update', 15),
        ('registrar', 'Certificate of Enrollment (COE)', 10),
        ('registrar', 'Certificate of Good Moral Character', 10),
        ('registrar', 'Honorable Dismissal', 20)");
}

// Keep level-specific services available without changing existing general services.
$level_services = [
    ['SHS', 'Form 137 / SF10 Request', 20],
    ['SHS', 'Strand Transfer Request', 15],
    ['SHS', 'SHS Diploma Request', 15],
    ['College', 'Subject Add / Drop', 15],
    ['College', 'Graduation Clearance', 20],
    ['College', 'Evaluation Request', 20]
];
foreach ($level_services as $level_service) {
    $level_service_stmt = mysqli_prepare($con, "INSERT INTO services (office_code, service_name, estimated_duration_min, education_level, target_level) SELECT 'registrar', ?, ?, ?, ? WHERE NOT EXISTS (SELECT 1 FROM services WHERE office_code = 'registrar' AND service_name = ?)");
    if ($level_service_stmt) {
        mysqli_stmt_bind_param($level_service_stmt, 'sisss', $level_service[1], $level_service[2], $level_service[0], $level_service[0], $level_service[1]);
        mysqli_stmt_execute($level_service_stmt);
        mysqli_stmt_close($level_service_stmt);
    }
}

// Durable event log for authenticated Server-Sent Event clients.
mysqli_query($con, "CREATE TABLE IF NOT EXISTS realtime_events (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    event_type VARCHAR(80) NOT NULL,
    appointment_id INT NOT NULL,
    student_id VARCHAR(50) NOT NULL,
    payload TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX (student_id),
    INDEX (created_at)
)");

// 6. Seed Default Registrar Admin
$admin_email = 'admin@aclc.com';
$admin_exists = mysqli_query($con, "SELECT student_id FROM users WHERE role = 'registrar' OR email = '$admin_email' LIMIT 1");
if ($admin_exists && mysqli_num_rows($admin_exists) === 0) {
    $hashed_admin_password = password_hash('admin123', PASSWORD_DEFAULT);
    $admin_insert = mysqli_prepare($con, "INSERT INTO users (student_id, first_name, last_name, email, phone, password, role) VALUES (?, ?, ?, ?, ?, ?, ?)");
    if ($admin_insert) {
        $admin_student_id = 'ADMIN-001';
        $admin_first = 'Registrar';
        $admin_last = 'Admin';
        $admin_phone = '09171234567';
        $admin_role = 'registrar';
        mysqli_stmt_bind_param($admin_insert, 'sssssss', $admin_student_id, $admin_first, $admin_last, $admin_email, $admin_phone, $hashed_admin_password, $admin_role);
        mysqli_stmt_execute($admin_insert);
        mysqli_stmt_close($admin_insert);
    }
}

?>
