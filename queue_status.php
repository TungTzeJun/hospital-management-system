<?php
// ============================================================
// Live queue status endpoint (JSON) — now PER DEPARTMENT.
// Polled from the browser (script.js) on both the patient
// dashboard and every department panel on the admin/doctor
// queue controller, so ticket numbers update in real time
// without a full page reload.
// ============================================================
require_once 'auth.php';
require_once 'db.php';
require_once 'queue_helpers.php';

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

// Any logged-in role (patient, doctor, admin) may poll this —
// require_login() rather than require_role() so it works on
// both index.php and admin.php.
if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['error' => 'Not logged in']);
    exit;
}

$department = trim($_GET['department'] ?? '');

// A doctor may only ever poll their OWN department's queue — force
// it server-side rather than trusting whatever ?department= was sent,
// so one doctor can't read another department's live queue by
// editing the query string.
if (current_role() === 'doctor') {
    $meStmt = $pdo->prepare('SELECT department FROM doctors WHERE user_id = ?');
    $meStmt->execute([$_SESSION['user_id']]);
    $me = $meStmt->fetch();
    $department = $me['department'] ?? '';
}

if ($department === '') {
    http_response_code(400);
    echo json_encode(['error' => 'department is required']);
    exit;
}

$queue = get_or_create_queue($pdo, $department);
$today = $pdo->query('SELECT CURDATE()')->fetchColumn();
$currentlyServing = department_currently_serving($pdo, $department);
$maxTicket = department_max_ticket($pdo, $department);
$waiting = department_waiting_count($pdo, $department);
$progressPct = $maxTicket > 0 ? min(100, max(0, round(($currentlyServing / $maxTicket) * 100))) : ($currentlyServing >= 0 ? 100 : 0);

$response = [
    'currentlyServing' => $currentlyServing,
    'nextTicket'        => department_next_ticket($pdo, $department),
    'maxTicket'         => $maxTicket,
    'waiting'           => $waiting,
    'progressPct'       => $progressPct,
    'department'        => $department,
    'ticketPrefix'      => department_ticket_prefix($department),
];

// Patients also get their own ticket + personalized wait estimate for
// THIS department, same as index.php computes on first page load.
if (current_role() === 'patient') {
    $stmt = $pdo->prepare('SELECT id FROM patients WHERE user_id = ?');
    $stmt->execute([$_SESSION['user_id']]);
    $patient = $stmt->fetch();

    $myTicket = null;
    $myStatus = null;
    if ($patient) {
        $stmt = $pdo->prepare(
            "SELECT a.ticket_number, a.appt_date, a.status FROM appointments a
             JOIN doctors d ON d.id = a.doctor_id
             WHERE a.patient_id = ? AND d.department = ?
               AND a.status IN ('Confirmed','Pending','Arrived','Called') AND a.appt_date >= CURDATE()
             ORDER BY a.appt_date ASC, (a.status = 'Called') DESC, a.appt_time ASC LIMIT 1"
        );
        $stmt->execute([$patient['id'], $department]);
        $row = $stmt->fetch();
        // Only a live ticket if that appointment is actually TODAY —
        // see index.php for why (a future date's ticket number has
        // nothing to do with today's currentlyServing counter).
        if ($row && $row['appt_date'] === $today) {
            $myTicket = (int) $row['ticket_number'];
            $myStatus = $row['status'];
        }
    }

    $waitEstimate = $myTicket !== null
        ? department_estimated_wait($pdo, $department, $myTicket, $myStatus)
        : ['ahead' => 0, 'minutes' => null];
    $response['myTicket']    = $myTicket;
    $response['myTurn']      = $myStatus === 'Called';
    $response['ahead']       = $waitEstimate['ahead'];
    $response['estWaitMins'] = $waitEstimate['minutes'];
}

echo json_encode($response);
