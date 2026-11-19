<?php
// ============================================================
// Database connection (PDO + MySQL)
// Edit these 4 values to match your XAMPP / hosting setup.
// ============================================================
$DB_HOST = getenv('MYSQLHOST') ?: getenv('DB_HOST') ?: 'localhost';
$DB_PORT = getenv('MYSQLPORT') ?: getenv('DB_PORT') ?: '3306';
$DB_NAME = getenv('MYSQLDATABASE') ?: getenv('DB_NAME') ?: 'healthcore';
$DB_USER = getenv('MYSQLUSER') ?: getenv('DB_USER') ?: 'root';
$DB_PASS = getenv('MYSQLPASSWORD') ?: getenv('DB_PASSWORD') ?: '';

// ------------------------------------------------------------
// Invite codes required to self-register staff accounts. Set a
// unique PHARMACIST_INVITE_CODE in the hosting environment before
// enabling pharmacist registration.
// ------------------------------------------------------------
define('DOCTOR_INVITE_CODE', 'healthcore2026');
define('PHARMACIST_INVITE_CODE', getenv('PHARMACIST_INVITE_CODE') ?: '');

// ------------------------------------------------------------
// Generate 30-minute appointment slots from 8:30 AM through 8:30 PM.
// This is shared by the booking form, availability check, and server guard.
// ------------------------------------------------------------
$slotStart = new DateTimeImmutable('08:30');
$slotEnd = new DateTimeImmutable('20:30');
$appointmentSlots = [];

for ($slot = $slotStart; $slot <= $slotEnd; $slot = $slot->modify('+30 minutes')) {
    $appointmentSlots[] = $slot->format('h:i A');
}

define('APPT_SLOTS', $appointmentSlots);

if (!class_exists(PDO::class) || !extension_loaded('pdo_mysql')) {
    die('Database connection failed: the PHP PDO MySQL driver (pdo_mysql) is not enabled. Enable it for the PHP version used by your web server or hosting account, then restart PHP/web server.');
}

try {
    $pdo = new PDO(
        "mysql:host={$DB_HOST};port={$DB_PORT};dbname={$DB_NAME};charset=utf8mb4",
        $DB_USER,
        $DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
} catch (PDOException $e) {
    die('Database connection failed. Check db.php credentials and make sure the "healthcore" database has been imported. (' . $e->getMessage() . ')');
}
