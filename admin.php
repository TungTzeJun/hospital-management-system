<?php
require_once 'auth.php';
require_role(['admin', 'doctor']);

if (current_role() === 'admin') {
    header('Location: admin_directory.php');
    exit;
}

require_once 'db.php';
require_once 'queue_helpers.php';
require_role(['doctor']);

// -------------------- Department scoping --------------------
// Doctors only see/manage patients, appointments and records tied to
// their own department — "tied to"
// meaning: the patient's primary physician is in that department, OR
// they have an appointment with a doctor in that department, OR they
// already have a record from a doctor in that department.
$isDoctor      = current_role() === 'doctor';
$myDoctorId    = null;
$myDepartment  = null;

if ($isDoctor) {
    $meStmt = $pdo->prepare('SELECT id, department FROM doctors WHERE user_id = ?');
    $meStmt->execute([$_SESSION['user_id']]);
    $me = $meStmt->fetch();
    if (!$me) {
        die('No doctor profile found for this account.');
    }
    $myDoctorId   = (int) $me['id'];
    $myDepartment = $me['department'];
}

// True if $doctorId belongs to $department.
function doctor_in_department(PDO $pdo, int $doctorId, string $department): bool {
    $stmt = $pdo->prepare('SELECT 1 FROM doctors WHERE id = ? AND department = ?');
    $stmt->execute([$doctorId, $department]);
    return (bool) $stmt->fetch();
}

// True if $patientId is associated with $department via primary
// physician, an appointment, or an existing record.
function patient_in_department(PDO $pdo, int $patientId, string $department): bool {
    $stmt = $pdo->prepare(
        'SELECT 1 FROM patients p
         LEFT JOIN doctors pd ON pd.id = p.primary_doctor_id
         WHERE p.id = ? AND (
             pd.department = ?
             OR EXISTS (SELECT 1 FROM appointments a JOIN doctors d ON d.id = a.doctor_id WHERE a.patient_id = p.id AND d.department = ?)
             OR EXISTS (SELECT 1 FROM medical_records mr JOIN doctors d2 ON d2.id = mr.doctor_id WHERE mr.patient_id = p.id AND d2.department = ?)
         ) LIMIT 1'
    );
    $stmt->execute([$patientId, $department, $department, $department]);
    return (bool) $stmt->fetch();
}

// True if medical record $recordId was logged by a doctor in $department.
function record_in_department(PDO $pdo, int $recordId, string $department): bool {
    $stmt = $pdo->prepare('SELECT 1 FROM medical_records mr JOIN doctors d ON d.id = mr.doctor_id WHERE mr.id = ? AND d.department = ?');
    $stmt->execute([$recordId, $department]);
    return (bool) $stmt->fetch();
}

// -------------------- Handle doctor actions (PRG pattern) --------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    // A doctor can only ever act on their own department — the POSTed value
    // is ignored rather than trusted, so editing the hidden form field can't
    // be used to call/reset another department's queue.
    $targetDepartment = trim($_POST['department'] ?? '');
    if ($isDoctor) {
        $targetDepartment = $myDepartment;
    } elseif (!in_array($targetDepartment, active_departments($pdo), true)) {
        $targetDepartment = null;
    }

    if ($action === 'call_next') {
        if (!$targetDepartment) {
            flash('Unknown department — nothing to call.', '⚠️');
        } else {
            // Real guard: don't advance the queue if nobody is actually
            // waiting in THIS department. Checked server-side (not just a
            // disabled button) so it can't be bypassed by resubmitting
            // the form directly.
            $pdo->beginTransaction();
            $next = $pdo->prepare(
                "SELECT a.id, a.ticket_number
                 FROM appointments a JOIN doctors d ON d.id = a.doctor_id
                 WHERE d.department = ? AND a.appt_date = CURDATE()
                   AND a.status IN ('Confirmed','Pending','Arrived')
                 ORDER BY a.ticket_number ASC, a.appt_time ASC, a.id ASC
                 LIMIT 1 FOR UPDATE"
            );
            $next->execute([$targetDepartment]);
            $appointment = $next->fetch();
            if ($appointment) {
                get_or_create_queue($pdo, $targetDepartment);
                $pdo->prepare(
                    "UPDATE appointments a JOIN doctors d ON d.id = a.doctor_id
                     SET a.status = 'Completed'
                     WHERE d.department = ? AND a.appt_date = CURDATE()
                       AND a.status = 'Called' AND a.ticket_number < ?"
                )->execute([$targetDepartment, $appointment['ticket_number']]);
                $pdo->prepare("UPDATE appointments SET status = 'Called' WHERE id = ?")->execute([$appointment['id']]);
                $pdo->prepare('UPDATE queue_state SET currently_serving = ? WHERE department = ?')->execute([$appointment['ticket_number'], $targetDepartment]);
                $pdo->commit();
                flash($targetDepartment . ': Called Ticket ' . format_department_ticket($targetDepartment, (int) $appointment['ticket_number']) . ' to the active station!', '🔊');
            } else {
                $pdo->rollBack();
                flash('No patients waiting in ' . $targetDepartment . ' — nothing to call.', '⚠️');
            }
        }
    }

    if ($action === 'reset_queue') {
        if (!$targetDepartment) {
            flash('Unknown department — nothing to reset.', '⚠️');
        } else {
            get_or_create_queue($pdo, $targetDepartment); // ensure the row exists
            $pdo->beginTransaction();
            $pdo->prepare(
                "UPDATE appointments a JOIN doctors d ON d.id = a.doctor_id
                 SET a.status = 'Arrived'
                 WHERE d.department = ? AND a.appt_date = CURDATE() AND a.status = 'Called'"
            )->execute([$targetDepartment]);
            $pdo->prepare('UPDATE queue_state SET currently_serving = -1 WHERE department = ?')->execute([$targetDepartment]);
            $pdo->commit();
            flash($targetDepartment . ' queue ticket counter reset to #1', '🔄');
        }
    }

    if ($action === 'complete_appointment') {
        $id = (int) ($_POST['appt_id'] ?? 0);
        $appointmentStmt = $pdo->prepare(
            'SELECT a.status FROM appointments a
             JOIN doctors d ON d.id = a.doctor_id
             WHERE a.id = ?' . ($isDoctor ? ' AND d.department = ?' : '')
        );
        $appointmentParams = $isDoctor ? [$id, $myDepartment] : [$id];
        $appointmentStmt->execute($appointmentParams);
        $appointment = $appointmentStmt->fetch();

        if ($isDoctor && !$appointment) {
            flash('You can only manage appointments within your own department.', '⚠️');
        } elseif (!$appointment || !in_array($appointment['status'], ['Confirmed', 'Pending', 'Arrived', 'Called'], true)) {
            flash('Only active appointments can be marked completed.', '⚠️');
        } else {
            $upd = $pdo->prepare(
                "UPDATE appointments SET status = 'Completed'
                 WHERE id = ? AND status IN ('Confirmed','Pending','Arrived','Called')"
            );
            $upd->execute([$id]);
            $updated = $upd->rowCount() > 0;
            flash(
                $updated ? 'Consultation marked as completed' : 'Only active appointments can be marked completed.',
                $updated ? '✅' : '⚠️'
            );
        }
    }

    if ($action === 'add_record') {
        $patientId = (int) ($_POST['patient_id'] ?? 0);
        $doctorId  = (int) ($_POST['doctor_id'] ?? 0);
        $title     = trim($_POST['title'] ?? '');
        $notes     = trim($_POST['notes'] ?? '');

        if ($isDoctor && (!doctor_in_department($pdo, $doctorId, $myDepartment) || ($patientId && !patient_in_department($pdo, $patientId, $myDepartment)))) {
            flash('You can only log records for physicians and patients in your own department.', '⚠️');
        } elseif ($patientId && $doctorId && $title) {
            $pdo->prepare('INSERT INTO medical_records (patient_id, doctor_id, title, notes, record_date, status) VALUES (?, ?, ?, ?, CURDATE(), "Under Review")')
                ->execute([$patientId, $doctorId, $title, $notes]);
            flash('New EMR medical record published to patient portal!', '📑');
        } else {
            flash('Please fill in patient, physician and record title.', '⚠️');
        }
    }

    if ($action === 'add_prescription') {
        $recordId = (int) ($_POST['record_id'] ?? 0);
        $medicineInput = $_POST['medicine_name'] ?? '';
        $dosageInput = $_POST['dosage'] ?? '';
        $instructionsInput = $_POST['instructions'] ?? '';
        $validUntilInput = $_POST['valid_until'] ?? '';
        $medicine = is_string($medicineInput) ? trim($medicineInput) : '';
        $dosage = is_string($dosageInput) ? trim($dosageInput) : '';
        $instructions = is_string($instructionsInput) ? trim($instructionsInput) : '';
        $quantity = filter_var($_POST['quantity'] ?? null, FILTER_VALIDATE_INT);
        $refills = filter_var($_POST['refills_allowed'] ?? null, FILTER_VALIDATE_INT);
        $validUntil = is_string($validUntilInput) ? trim($validUntilInput) : '';
        $validDate = DateTimeImmutable::createFromFormat('!Y-m-d', $validUntil);
        $validDateErrors = DateTimeImmutable::getLastErrors();
        $isValidUntil = $validDate
            && ($validDateErrors === false || ($validDateErrors['warning_count'] === 0 && $validDateErrors['error_count'] === 0))
            && $validDate->format('Y-m-d') === $validUntil;

        $recordStmt = $pdo->prepare(
            'SELECT mr.patient_id, mr.doctor_id
             FROM medical_records mr
             JOIN doctors d ON d.id = mr.doctor_id
             WHERE mr.id = ? AND mr.record_date <= CURDATE()' . ($isDoctor ? ' AND d.department = ?' : '')
        );
        $recordStmt->execute($isDoctor ? [$recordId, $myDepartment] : [$recordId]);
        $prescriptionRecord = $recordStmt->fetch();

        if (!$prescriptionRecord) {
            flash('Choose a checkup record you are allowed to manage.', '??');
        } elseif (
            $medicine === '' || strlen($medicine) > 150
            || $dosage === '' || strlen($dosage) > 120
            || $instructions === ''
            || $quantity === false || $quantity < 1 || $quantity > 1000
            || $refills === false || $refills < 0 || $refills > 5
            || !$isValidUntil || $validUntil < $pdo->query('SELECT CURDATE()')->fetchColumn()
        ) {
            flash('Enter a valid medicine, dosage, instructions, quantity, refill count, and expiry date today or later.', '??');
        } else {
            $pdo->prepare(
                'INSERT INTO prescriptions
                    (medical_record_id, patient_id, doctor_id, medicine_name, dosage, instructions, quantity, refills_allowed, valid_until)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $recordId,
                $prescriptionRecord['patient_id'],
                $prescriptionRecord['doctor_id'],
                $medicine,
                $dosage,
                $instructions,
                $quantity,
                $refills,
                $validUntil,
            ]);
            flash('Prescription added to the patient checkup.', '??');
        }
    }

    if ($action === 'verify_record') {
        $id = (int) ($_POST['record_id'] ?? 0);
        if ($isDoctor && !record_in_department($pdo, $id, $myDepartment)) {
            flash('You can only manage records from your own department.', '⚠️');
        } else {
            $pdo->prepare('UPDATE medical_records SET status = "Verified" WHERE id = ?')->execute([$id]);
            flash('Record marked as Verified', '✔️');
        }
    }

    if ($action === 'edit_record') {
        $id        = (int) ($_POST['record_id'] ?? 0);
        $patientId = (int) ($_POST['patient_id'] ?? 0);
        $doctorId  = (int) ($_POST['doctor_id'] ?? 0);
        $title     = trim($_POST['title'] ?? '');
        $notes     = trim($_POST['notes'] ?? '');
        $recDate   = trim($_POST['record_date'] ?? '');

        if ($isDoctor && (!record_in_department($pdo, $id, $myDepartment) || !doctor_in_department($pdo, $doctorId, $myDepartment) || !patient_in_department($pdo, $patientId, $myDepartment))) {
            flash('You can only manage records within your own department.', '⚠️');
        } elseif ($id && $patientId && $doctorId && $title && $recDate) {
            $pdo->prepare('UPDATE medical_records SET patient_id = ?, doctor_id = ?, title = ?, notes = ?, record_date = ? WHERE id = ?')
                ->execute([$patientId, $doctorId, $title, $notes, $recDate, $id]);
            flash('Medical record updated', '📝');
        } else {
            flash('Please fill in every field to update the record.', '⚠️');
        }
    }

    if ($action === 'delete_record') {
        $id = (int) ($_POST['record_id'] ?? 0);
        if ($isDoctor && !record_in_department($pdo, $id, $myDepartment)) {
            flash('You can only manage records from your own department.', '⚠️');
        } else {
            $pdo->prepare('DELETE FROM medical_records WHERE id = ?')->execute([$id]);
            flash('Medical record deleted', '🗑️');
        }
    }

    header('Location: admin.php');
    exit;
}

// -------------------- Data for the page --------------------
if ($isDoctor) {
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) AS c FROM (
            SELECT DISTINCT p.id FROM patients p
            LEFT JOIN doctors pd ON pd.id = p.primary_doctor_id
            LEFT JOIN appointments a ON a.patient_id = p.id
            LEFT JOIN doctors ad ON ad.id = a.doctor_id
            LEFT JOIN medical_records mr ON mr.patient_id = p.id
            LEFT JOIN doctors rd ON rd.id = mr.doctor_id
            WHERE pd.department = ? OR ad.department = ? OR rd.department = ?
        ) dp'
    );
    $stmt->execute([$myDepartment, $myDepartment, $myDepartment]);
    $totalPatients = $stmt->fetch()['c'];

    $stmt = $pdo->prepare("SELECT COUNT(*) AS c FROM appointments a JOIN doctors d ON d.id = a.doctor_id WHERE a.appt_date = CURDATE() AND a.status NOT IN ('Cancelled', 'Completed') AND d.department = ?");
    $stmt->execute([$myDepartment]);
    $todaysApptCount = $stmt->fetch()['c'];

    $stmt = $pdo->prepare("SELECT COUNT(*) AS c FROM medical_records mr JOIN doctors d ON d.id = mr.doctor_id WHERE mr.status = 'Under Review' AND d.department = ?");
    $stmt->execute([$myDepartment]);
    $pendingReports = $stmt->fetch()['c'];
} else {
    $totalPatients   = $pdo->query('SELECT COUNT(*) AS c FROM patients')->fetch()['c'];
    $todaysApptCount = $pdo->query("SELECT COUNT(*) AS c FROM appointments WHERE appt_date = CURDATE() AND status NOT IN ('Cancelled', 'Completed')")->fetch()['c'];
    $pendingReports  = $pdo->query("SELECT COUNT(*) AS c FROM medical_records WHERE status = 'Under Review'")->fetch()['c'];
}

// -------------------- Per-department queues --------------------
// A doctor only ever manages their own department's queue.
$queueDepartments = $isDoctor ? [$myDepartment] : active_departments($pdo);

$departmentQueues = [];
$totalWaitingAllDepts = 0;
foreach ($queueDepartments as $dept) {
    get_or_create_queue($pdo, $dept);
    $serving  = department_currently_serving($pdo, $dept);
    // Scoped to today's tickets for THIS department only, so the queue
    // math doesn't drift upward forever and departments don't share
    // each other's ticket counters.
    $maxTicket = department_max_ticket($pdo, $dept);
    $nextTicket = department_next_ticket($pdo, $dept);
    // Real count of today's appointments in this department still
    // pending service — NOT maxTicket - serving, which would also count
    // tickets that were later cancelled or completed and could sit on a
    // stale, inflated number that never reflects reality.
    $waitingHere = department_waiting_count($pdo, $dept);
    $departmentQueues[] = [
        'department'  => $dept,
        'slug'        => department_slug($dept),
        'ticketPrefix' => department_ticket_prefix($dept),
        'serving'     => $serving,
        'maxTicket'   => $maxTicket,
        'nextTicket'  => $nextTicket,
        'waiting'     => $waitingHere,
        'progressPct' => $maxTicket > 0 ? min(100, max(0, round(($serving / $maxTicket) * 100))) : ($serving >= 0 ? 100 : 0),
    ];
    $totalWaitingAllDepts += $waitingHere;
}

$recordsSql = "SELECT mr.*, p.patient_code, u1.full_name AS patient_name, u2.full_name AS doctor_name
                FROM medical_records mr
                JOIN patients p ON p.id = mr.patient_id
                JOIN users u1 ON u1.id = p.user_id
                JOIN doctors d ON d.id = mr.doctor_id
                JOIN users u2 ON u2.id = d.user_id";
$recordsParams = [];
if ($isDoctor) {
    $recordsSql .= ' WHERE d.department = ?';
    $recordsParams[] = $myDepartment;
}
$recordsSql .= ' ORDER BY mr.record_date DESC LIMIT 25';
$stmt = $pdo->prepare($recordsSql);
$stmt->execute($recordsParams);
$records = $stmt->fetchAll();

$prescriptionOptionsSql = "SELECT mr.id, mr.title, mr.record_date, u1.full_name AS patient_name
                           FROM medical_records mr
                           JOIN patients p ON p.id = mr.patient_id
                           JOIN users u1 ON u1.id = p.user_id
                           JOIN doctors d ON d.id = mr.doctor_id
                           WHERE mr.record_date <= CURDATE()";
$prescriptionOptionsParams = [];
if ($isDoctor) {
    $prescriptionOptionsSql .= ' AND d.department = ?';
    $prescriptionOptionsParams[] = $myDepartment;
}
$prescriptionOptionsSql .= ' ORDER BY mr.record_date DESC, mr.id DESC';
$stmt = $pdo->prepare($prescriptionOptionsSql);
$stmt->execute($prescriptionOptionsParams);
$prescriptionOptions = $stmt->fetchAll();

$apptSql = "SELECT a.*, u1.full_name AS patient_name, p.patient_code, u2.full_name AS doctor_name
            FROM appointments a
            JOIN patients p ON p.id = a.patient_id
            JOIN users u1 ON u1.id = p.user_id
            JOIN doctors d ON d.id = a.doctor_id
            JOIN users u2 ON u2.id = d.user_id
            WHERE a.appt_date = CURDATE() AND a.status != 'Completed'";
$apptParams = [];
if ($isDoctor) {
    $apptSql .= ' AND d.department = ?';
    $apptParams[] = $myDepartment;
}
$apptSql .= ' ORDER BY a.appt_time ASC';
$stmt = $pdo->prepare($apptSql);
$stmt->execute($apptParams);
$appointments = $stmt->fetchAll();

if ($isDoctor) {
    $stmt = $pdo->prepare(
        'SELECT DISTINCT p.id, p.patient_code, u.full_name
         FROM patients p
         JOIN users u ON u.id = p.user_id
         LEFT JOIN doctors pd ON pd.id = p.primary_doctor_id
         LEFT JOIN appointments a ON a.patient_id = p.id
         LEFT JOIN doctors ad ON ad.id = a.doctor_id
         LEFT JOIN medical_records mr ON mr.patient_id = p.id
         LEFT JOIN doctors rd ON rd.id = mr.doctor_id
         WHERE pd.department = ? OR ad.department = ? OR rd.department = ?
         ORDER BY u.full_name'
    );
    $stmt->execute([$myDepartment, $myDepartment, $myDepartment]);
    $patientsList = $stmt->fetchAll();

    $stmt = $pdo->prepare('SELECT d.id, d.department, u.full_name FROM doctors d JOIN users u ON u.id = d.user_id WHERE d.department = ? ORDER BY u.full_name');
    $stmt->execute([$myDepartment]);
    $doctorsList = $stmt->fetchAll();
} else {
    $patientsList = $pdo->query("SELECT p.id, p.patient_code, u.full_name FROM patients p JOIN users u ON u.id = p.user_id ORDER BY u.full_name")->fetchAll();
    $doctorsList  = $pdo->query("SELECT d.id, d.department, u.full_name FROM doctors d JOIN users u ON u.id = d.user_id ORDER BY u.full_name")->fetchAll();
}

$patientDirectory = [];
if (!$isDoctor) {
    $directoryPatients = $pdo->query(
        'SELECT p.*, u.full_name, u.email, u.phone, u.created_at AS account_created_at,
                pd.department AS primary_doctor_department, du.full_name AS primary_doctor_name
         FROM patients p
         JOIN users u ON u.id = p.user_id
         LEFT JOIN doctors pd ON pd.id = p.primary_doctor_id
         LEFT JOIN users du ON du.id = pd.user_id
         ORDER BY u.full_name'
    )->fetchAll();

    foreach ($directoryPatients as $directoryPatient) {
        $directoryPatient['appointments'] = [];
        $directoryPatient['records'] = [];
        $directoryPatient['allergies'] = [];
        $directoryPatient['conditions'] = [];
        $directoryPatient['prescriptions'] = [];
        $patientDirectory[(int) $directoryPatient['id']] = $directoryPatient;
    }

    $directoryAppointments = $pdo->query(
        'SELECT a.patient_id, a.appt_date, a.appt_time, a.department, a.reason,
                a.status, a.ticket_number, u.full_name AS doctor_name
         FROM appointments a
         JOIN doctors d ON d.id = a.doctor_id
         JOIN users u ON u.id = d.user_id
         ORDER BY a.appt_date DESC, a.appt_time DESC, a.id DESC'
    )->fetchAll();
    foreach ($directoryAppointments as $appointment) {
        $patientDirectory[(int) $appointment['patient_id']]['appointments'][] = $appointment;
    }

    $directoryRecords = $pdo->query(
        'SELECT mr.patient_id, mr.title, mr.notes, mr.record_date, mr.status,
                u.full_name AS doctor_name, d.department
         FROM medical_records mr
         JOIN doctors d ON d.id = mr.doctor_id
         JOIN users u ON u.id = d.user_id
         ORDER BY mr.record_date DESC, mr.id DESC'
    )->fetchAll();
    foreach ($directoryRecords as $record) {
        $patientDirectory[(int) $record['patient_id']]['records'][] = $record;
    }

    foreach ($pdo->query('SELECT patient_id, name, severity FROM allergies ORDER BY name')->fetchAll() as $allergy) {
        $patientDirectory[(int) $allergy['patient_id']]['allergies'][] = $allergy;
    }

    foreach ($pdo->query('SELECT patient_id, name FROM conditions ORDER BY name')->fetchAll() as $condition) {
        $patientDirectory[(int) $condition['patient_id']]['conditions'][] = $condition;
    }

    $directoryPrescriptions = $pdo->query(
        'SELECT pr.id, pr.patient_id, pr.medicine_name, pr.dosage, pr.instructions,
                pr.quantity, pr.refills_allowed, pr.valid_until, pr.created_at,
                mr.title AS record_title, mr.record_date, d.department,
                du.full_name AS doctor_name, po.id AS order_id, po.quantity AS order_quantity,
                po.status AS order_status, po.created_at AS order_created_at
         FROM prescriptions pr
         JOIN medical_records mr ON mr.id = pr.medical_record_id AND mr.patient_id = pr.patient_id
         JOIN doctors d ON d.id = pr.doctor_id AND d.id = mr.doctor_id
         JOIN users du ON du.id = d.user_id
         LEFT JOIN pharmacy_orders po ON po.prescription_id = pr.id AND po.patient_id = pr.patient_id
         ORDER BY pr.created_at DESC, pr.id DESC, po.id DESC'
    )->fetchAll();
    foreach ($directoryPrescriptions as $prescriptionRow) {
        $directoryPatientId = (int) $prescriptionRow['patient_id'];
        $prescriptionId = (int) $prescriptionRow['id'];
        if (!isset($patientDirectory[$directoryPatientId]['prescriptions'][$prescriptionId])) {
            $prescriptionDirectoryItem = $prescriptionRow;
            $prescriptionDirectoryItem['orders'] = [];
            $patientDirectory[$directoryPatientId]['prescriptions'][$prescriptionId] = $prescriptionDirectoryItem;
        }
        if ($prescriptionRow['order_id'] !== null) {
            $patientDirectory[$directoryPatientId]['prescriptions'][$prescriptionId]['orders'][] = $prescriptionRow;
        }
    }
}

function statusBadge(string $status): string {
    return match ($status) {
        'Verified', 'Completed', 'Confirmed' => 'bg-success',
        'Under Review', 'Pending' => 'bg-warning text-dark',
        'Arrived', 'Called' => 'bg-info text-dark',
        'Cancelled' => 'bg-danger',
        default => 'bg-secondary',
    };
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Staff Admin Portal - Healthcore Hospital Management</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@200..800&display=swap" rel="stylesheet">
</head>

<body>
    <nav class="navbar navbar-expand-lg navbar-custom sticky-top border-bottom border-primary">
        <div class="navbar-layout">
            <a class="navbar-brand-custom text-primary d-flex align-items-center gap-2" href="admin.php">
                <span class="logo-icon bg-primary text-white">+</span>
                <span>Healthcore <span class="admin-badge ms-1">DOCTOR</span></span>
            </a>

            <button class="navbar-toggler border-0" type="button" data-bs-toggle="collapse" data-bs-target="#adminNavbar">
                <span class="navbar-toggler-icon">☰</span>
            </button>

            <div class="collapse navbar-collapse justify-content-center" id="adminNavbar">
                <ul class="navbar-nav gap-2">
                    <li class="nav-item"><a class="nav-link nav-link-custom active" href="admin.php">Overview</a></li>
                    <?php if (!$isDoctor): ?>
                        <li class="nav-item"><a class="nav-link nav-link-custom" href="#patient-directory">All Patients</a></li>
                    <?php endif; ?>
                    <li class="nav-item"><a class="nav-link nav-link-custom" href="#records-management">Patient Records</a></li>
                    <li class="nav-item"><a class="nav-link nav-link-custom" href="#queue-controller">Queue Control</a></li>
                    <li class="nav-item"><a class="nav-link nav-link-custom" href="#appointments-management">Appointments</a></li>
                </ul>
            </div>

            <div class="d-flex align-items-center gap-3">
                <button class="btn btn-outline-custom p-2 rounded-circle" id="themeToggleBtn" title="Toggle Light/Dark Theme">🌙</button>
                <a href="logout.php" class="btn btn-outline-danger btn-sm rounded-pill px-3">🚪 Logout</a>
                <span class="badge bg-secondary p-2 d-none d-md-inline-block"><?= htmlspecialchars($_SESSION['full_name']) ?></span>
            </div>
        </div>
    </nav>

    <main class="main-wrapper">
        <div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
            <div>
                <h2 class="manrope-800 mb-1">Hospital Administration Portal 🩺</h2>
                <?php if ($isDoctor): ?>
                    <span class="badge bg-primary mt-2">🏥 Viewing: <?= htmlspecialchars($myDepartment) ?> Department only</span>
                <?php endif; ?>
            </div>
            <button class="btn btn-primary-custom" data-bs-toggle="modal" data-bs-target="#addRecordModal">+ Log New Medical Record</button>
        </div>

        <div class="row g-3 mb-4">
            <div class="col-md-3">
                <div class="kpi-card">
                    <div class="kpi-icon-box">👥</div>
                    <div><small class="text-muted d-block"><?= $isDoctor ? 'Patients in Your Department' : 'Total Registered Patients' ?></small><h4 class="manrope-800 mb-0"><?= (int) $totalPatients ?></h4></div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="kpi-card">
                    <div class="kpi-icon-box">📅</div>
                    <div><small class="text-muted d-block">Today's Appointments</small><h4 class="manrope-800 mb-0"><?= (int) $todaysApptCount ?> Scheduled</h4></div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="kpi-card">
                    <div class="kpi-icon-box bg-success bg-opacity-10 text-success">🎫</div>
                    <?php if ($isDoctor): ?>
                        <div><small class="text-muted d-block">Currently Serving Ticket</small><h4 class="manrope-800 mb-0 text-success"><?= htmlspecialchars(format_department_ticket($departmentQueues[0]['department'] ?? '', $departmentQueues[0]['serving'] ?? null)) ?></h4></div>
                    <?php else: ?>
                        <div><small class="text-muted d-block">Total Waiting (All Depts)</small><h4 class="manrope-800 mb-0 text-success"><?= (int) $totalWaitingAllDepts ?> Patients</h4></div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="col-md-3">
                <div class="kpi-card">
                    <div class="kpi-icon-box bg-warning bg-opacity-10 text-warning">📄</div>
                    <div><small class="text-muted d-block">Pending Lab Reports</small><h4 class="manrope-800 mb-0 text-warning"><?= (int) $pendingReports ?> Needs Review</h4></div>
                </div>
            </div>
        </div>

        <?php if (!$isDoctor): ?>
        <section class="card-custom mb-4" id="patient-directory">
            <div class="card-header-custom flex-wrap gap-2">
                <div>
                    <h5 class="manrope-700 mb-0">All Registered Patients</h5>
                    <small class="text-muted">Hospital-wide patient profiles and their appointments, checkups, allergies, conditions, prescriptions, and pharmacy requests.</small>
                </div>
                <input type="search" class="form-control form-control-custom table-search-bar" id="patientDirectorySearch" placeholder="Search any patient detail...">
            </div>
            <div class="card-body-custom">
                <?php if (!$patientDirectory): ?>
                    <p class="text-muted text-center py-3 mb-0">No registered patients were found.</p>
                <?php else: ?>
                    <div id="patientDirectoryEntries">
                        <?php foreach ($patientDirectory as $directoryPatient): ?>
                            <details class="patient-directory-entry">
                                <summary class="patient-directory-summary">
                                    <span>
                                        <strong><?= htmlspecialchars($directoryPatient['full_name']) ?></strong>
                                        <small class="text-muted d-block">Patient #<?= htmlspecialchars($directoryPatient['patient_code']) ?> · <?= htmlspecialchars($directoryPatient['email']) ?></small>
                                    </span>
                                    <span class="text-muted small"><?= htmlspecialchars($directoryPatient['phone'] ?: 'No phone on file') ?></span>
                                </summary>
                                <div class="patient-directory-content">
                                    <h6 class="manrope-700">Profile</h6>
                                    <div class="row g-3 mb-4">
                                        <div class="col-md-4"><small class="text-muted d-block">Account created</small><?= date('d M Y', strtotime($directoryPatient['account_created_at'])) ?></div>
                                        <div class="col-md-4"><small class="text-muted d-block">Date of birth</small><?= htmlspecialchars($directoryPatient['dob'] ?: '—') ?></div>
                                        <div class="col-md-4"><small class="text-muted d-block">Gender</small><?= htmlspecialchars($directoryPatient['gender'] ?: '—') ?></div>
                                        <div class="col-md-4"><small class="text-muted d-block">Blood type</small><?= htmlspecialchars($directoryPatient['blood_type'] ?: '—') ?></div>
                                        <div class="col-md-4"><small class="text-muted d-block">Height / weight</small><?= htmlspecialchars(($directoryPatient['height_cm'] ? $directoryPatient['height_cm'] . ' cm' : '—') . ' / ' . ($directoryPatient['weight_kg'] ? $directoryPatient['weight_kg'] . ' kg' : '—')) ?></div>
                                        <div class="col-md-4"><small class="text-muted d-block">Primary physician</small><?= htmlspecialchars($directoryPatient['primary_doctor_name'] ?: 'Not assigned') ?><?= $directoryPatient['primary_doctor_department'] ? ' · ' . htmlspecialchars($directoryPatient['primary_doctor_department']) : '' ?></div>
                                        <div class="col-md-6"><small class="text-muted d-block">Address</small><?= nl2br(htmlspecialchars($directoryPatient['address'] ?: '—')) ?></div>
                                        <div class="col-md-6"><small class="text-muted d-block">Emergency contact</small><?= htmlspecialchars($directoryPatient['emergency_name'] ?: '—') ?><?= $directoryPatient['emergency_phone'] ? ' · ' . htmlspecialchars($directoryPatient['emergency_phone']) : '' ?></div>
                                        <div class="col-md-6"><small class="text-muted d-block">Insurance provider</small><?= htmlspecialchars($directoryPatient['insurance_provider'] ?: '—') ?></div>
                                        <div class="col-md-6"><small class="text-muted d-block">Insurance policy number</small><?= htmlspecialchars($directoryPatient['insurance_policy_no'] ?: '—') ?></div>
                                    </div>

                                    <div class="row g-4">
                                        <div class="col-lg-6">
                                            <h6 class="manrope-700">Allergies</h6>
                                            <?php if (!$directoryPatient['allergies']): ?>
                                                <p class="text-muted small">None recorded.</p>
                                            <?php else: ?>
                                                <ul class="mb-3">
                                                    <?php foreach ($directoryPatient['allergies'] as $allergy): ?>
                                                        <li><?= htmlspecialchars($allergy['name']) ?> — <?= htmlspecialchars($allergy['severity'] ?: 'Mild') ?></li>
                                                    <?php endforeach; ?>
                                                </ul>
                                            <?php endif; ?>
                                        </div>
                                        <div class="col-lg-6">
                                            <h6 class="manrope-700">Medical conditions</h6>
                                            <?php if (!$directoryPatient['conditions']): ?>
                                                <p class="text-muted small">None recorded.</p>
                                            <?php else: ?>
                                                <ul class="mb-3">
                                                    <?php foreach ($directoryPatient['conditions'] as $condition): ?>
                                                        <li><?= htmlspecialchars($condition['name']) ?></li>
                                                    <?php endforeach; ?>
                                                </ul>
                                            <?php endif; ?>
                                        </div>

                                        <div class="col-12">
                                            <h6 class="manrope-700">Appointments</h6>
                                            <?php if (!$directoryPatient['appointments']): ?>
                                                <p class="text-muted small">No appointments recorded.</p>
                                            <?php else: ?>
                                                <div class="records-table-container">
                                                    <table class="records-table">
                                                        <thead><tr><th>Date / time</th><th>Department</th><th>Physician</th><th>Status</th><th>Reason</th></tr></thead>
                                                        <tbody>
                                                            <?php foreach ($directoryPatient['appointments'] as $appointment): ?>
                                                                <tr>
                                                                    <td><?= date('d M Y', strtotime($appointment['appt_date'])) ?> · <?= htmlspecialchars($appointment['appt_time']) ?></td>
                                                                    <td><?= htmlspecialchars($appointment['department']) ?></td>
                                                                    <td><?= htmlspecialchars($appointment['doctor_name']) ?></td>
                                                                    <td><?= htmlspecialchars($appointment['status']) ?><?= $appointment['ticket_number'] !== null ? ' · Ticket ' . (int) $appointment['ticket_number'] : '' ?></td>
                                                                    <td><?= htmlspecialchars($appointment['reason'] ?: '—') ?></td>
                                                                </tr>
                                                            <?php endforeach; ?>
                                                        </tbody>
                                                    </table>
                                                </div>
                                            <?php endif; ?>
                                        </div>

                                        <div class="col-12">
                                            <h6 class="manrope-700">Checkups and medical records</h6>
                                            <?php if (!$directoryPatient['records']): ?>
                                                <p class="text-muted small">No checkups or records recorded.</p>
                                            <?php else: ?>
                                                <div class="records-table-container">
                                                    <table class="records-table">
                                                        <thead><tr><th>Date</th><th>Record</th><th>Department / physician</th><th>Status</th><th>Notes</th></tr></thead>
                                                        <tbody>
                                                            <?php foreach ($directoryPatient['records'] as $record): ?>
                                                                <tr>
                                                                    <td><?= date('d M Y', strtotime($record['record_date'])) ?></td>
                                                                    <td><?= htmlspecialchars($record['title']) ?></td>
                                                                    <td><?= htmlspecialchars($record['department']) ?> · <?= htmlspecialchars($record['doctor_name']) ?></td>
                                                                    <td><?= htmlspecialchars($record['status']) ?></td>
                                                                    <td><?= nl2br(htmlspecialchars($record['notes'] ?: '—')) ?></td>
                                                                </tr>
                                                            <?php endforeach; ?>
                                                        </tbody>
                                                    </table>
                                                </div>
                                            <?php endif; ?>
                                        </div>

                                        <div class="col-12">
                                            <h6 class="manrope-700">Prescriptions and pharmacy requests</h6>
                                            <?php if (!$directoryPatient['prescriptions']): ?>
                                                <p class="text-muted small">No prescriptions recorded.</p>
                                            <?php else: ?>
                                                <div class="records-table-container">
                                                    <table class="records-table">
                                                        <thead><tr><th>Medication</th><th>Checkup</th><th>Directions</th><th>Quantity / refills</th><th>Valid until</th><th>Pharmacy requests</th></tr></thead>
                                                        <tbody>
                                                            <?php foreach ($directoryPatient['prescriptions'] as $prescription): ?>
                                                                <tr>
                                                                    <td><strong><?= htmlspecialchars($prescription['medicine_name']) ?></strong><br><?= htmlspecialchars($prescription['dosage']) ?></td>
                                                                    <td><?= htmlspecialchars($prescription['record_title']) ?><br><small class="text-muted"><?= date('d M Y', strtotime($prescription['record_date'])) ?> · <?= htmlspecialchars($prescription['doctor_name']) ?></small></td>
                                                                    <td><?= nl2br(htmlspecialchars($prescription['instructions'])) ?></td>
                                                                    <td><?= (int) $prescription['quantity'] ?> · <?= (int) $prescription['refills_allowed'] ?> refills</td>
                                                                    <td><?= date('d M Y', strtotime($prescription['valid_until'])) ?></td>
                                                                    <td>
                                                                        <?php if (!$prescription['orders']): ?>
                                                                            <span class="text-muted">No request</span>
                                                                        <?php else: ?>
                                                                            <?php foreach ($prescription['orders'] as $medicationOrder): ?>
                                                                                <div><?= htmlspecialchars($medicationOrder['order_status']) ?> — <?= (int) $medicationOrder['order_quantity'] ?> — <?= date('d M Y', strtotime($medicationOrder['order_created_at'])) ?></div>
                                                                            <?php endforeach; ?>
                                                                        <?php endif; ?>
                                                                    </td>
                                                                </tr>
                                                            <?php endforeach; ?>
                                                        </tbody>
                                                    </table>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </details>
                        <?php endforeach; ?>
                    </div>
                    <p class="text-muted text-center py-3 mb-0 d-none" id="patientDirectoryNoResults">No patients match that search.</p>
                <?php endif; ?>
            </div>
        </section>
        <?php endif; ?>

        <!-- Live Queue Controller — one card per department -->
        <section class="card-custom mb-4" id="queue-controller">
            <div class="card-header-custom">
                <div class="d-flex align-items-center gap-2">
                    <span>⚡ Outpatient Live Queue Controller</span>
                    <span class="queue-badge-live"><span class="pulse-dot"></span> <?= count($departmentQueues) ?> Department<?= count($departmentQueues) === 1 ? '' : 's' ?></span>
                </div>
            </div>
            <div class="card-body-custom">
                <?php if (!$departmentQueues): ?>
                    <p class="text-muted text-center py-3 mb-0">No departments have any doctors assigned yet.</p>
                <?php endif; ?>
                <?php foreach ($departmentQueues as $i => $dq): ?>
                    <div class="dept-queue-panel <?= $i > 0 ? 'border-top pt-4 mt-4' : '' ?>"
                         data-department="<?= htmlspecialchars($dq['department']) ?>"
                         data-ticket-prefix="<?= htmlspecialchars($dq['ticketPrefix']) ?>"
                         data-slug="<?= htmlspecialchars($dq['slug']) ?>">
                        <h6 class="manrope-700 mb-3">🏥 <?= htmlspecialchars($dq['department']) ?> Department</h6>
                        <div class="row align-items-center">
                            <div class="col-lg-4 text-center border-end">
                                <small class="text-muted d-block">CURRENT TICKET CALLING</small>
                                <h1 class="display-2 manrope-800 text-primary my-1 dept-serving-ticket"><?= htmlspecialchars(format_department_ticket($dq['department'], $dq['serving'])) ?></h1>
                                <p class="text-muted small mb-0">Target Room: <?= htmlspecialchars($dq['department']) ?></p>
                            </div>
                            <div class="col-lg-5 p-4">
                                <div class="d-flex justify-content-between mb-2">
                                    <span>Next Ticket in Line: <strong class="text-primary"><span class="dept-next-ticket"><?= htmlspecialchars(format_department_ticket($dq['department'], $dq['nextTicket'])) ?></span></strong></span>
                                    <span>Total Waiting: <strong class="text-warning"><span class="dept-waiting-count"><?= $dq['waiting'] ?></span> Patients</strong></span>
                                </div>
                                <div class="progress mb-3" style="height: 12px;">
                                    <div class="progress-bar progress-bar-striped progress-bar-animated bg-success dept-progress-bar" role="progressbar" style="width: <?= $dq['progressPct'] ?>%"></div>
                                </div>
                            </div>
                            <div class="col-lg-3 text-center p-3">
                                <form method="POST" action="admin.php" class="mb-2">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="call_next">
                                    <input type="hidden" name="department" value="<?= htmlspecialchars($dq['department']) ?>">
                                    <button type="submit" class="btn btn-success w-100 py-3 manrope-700 shadow-sm dept-call-next-btn" <?= $dq['waiting'] === 0 ? 'disabled' : '' ?>>🔊 CALL NEXT TICKET</button>
                                </form>
                                <form method="POST" action="admin.php" onsubmit="return confirm('Reset <?= htmlspecialchars($dq['department']) ?> queue serving counter to #1?');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="reset_queue">
                                    <input type="hidden" name="department" value="<?= htmlspecialchars($dq['department']) ?>">
                                    <button type="submit" class="btn btn-outline-danger btn-sm w-100">🔄 Reset Queue Ticket</button>
                                </form>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>

        <!-- EMR Records -->
        <section class="card-custom mb-4" id="records-management">
            <div class="card-header-custom flex-wrap gap-2">
                <div>
                    <h5 class="manrope-700 mb-0">Electronic Medical Records (EMR)</h5>
                    <small class="text-muted">Inspect, verify, and log official medical reports for patients<?= $isDoctor ? ' — ' . htmlspecialchars($myDepartment) . ' Department' : '' ?></small>
                </div>
                <div class="d-flex gap-2 align-items-center flex-wrap">
                    <select class="form-select form-select-custom" id="adminStatusFilter" style="max-width:160px;">
                        <option value="">All Status</option>
                        <option value="Verified">Verified</option>
                        <option value="Under Review">Under Review</option>
                    </select>
                    <input type="text" class="form-control form-control-custom table-search-bar" id="adminRecordSearch" placeholder="🔍 Search patient name or ID...">
                    <button class="btn btn-outline-custom btn-sm" data-bs-toggle="modal" data-bs-target="#addPrescriptionModal">+ Add Prescription</button>
                    <button class="btn btn-primary-custom btn-sm" data-bs-toggle="modal" data-bs-target="#addRecordModal">+ Add Record</button>
                </div>
            </div>
            <div class="card-body-custom">
                <div class="records-table-container">
                    <table class="records-table">
                        <thead>
                            <tr><th>Patient ID</th><th>Patient Name</th><th>Record Title / Exam</th><th>Attending Physician</th><th>Date</th><th>Status</th><th>Actions</th></tr>
                        </thead>
                        <tbody id="adminRecordsTableBody">
                            <?php if (!$records): ?>
                                <tr><td colspan="7" class="text-muted text-center py-3">No medical records yet.</td></tr>
                            <?php endif; ?>
                            <?php foreach ($records as $r): ?>
                                <tr data-status="<?= htmlspecialchars($r['status']) ?>">
                                    <td><strong>#<?= htmlspecialchars($r['patient_code']) ?></strong></td>
                                    <td><?= htmlspecialchars($r['patient_name']) ?></td>
                                    <td><?= htmlspecialchars($r['title']) ?></td>
                                    <td><?= htmlspecialchars($r['doctor_name']) ?></td>
                                    <td><?= date('d M Y', strtotime($r['record_date'])) ?></td>
                                    <td><span class="badge <?= statusBadge($r['status']) ?>"><?= htmlspecialchars($r['status']) ?></span></td>
                                    <td class="text-nowrap">
                                        <button type="button" class="btn btn-sm btn-outline-custom view-record-btn" title="View"
                                                data-patient="<?= htmlspecialchars($r['patient_name']) ?> (#<?= htmlspecialchars($r['patient_code']) ?>)"
                                                data-doctor="<?= htmlspecialchars($r['doctor_name']) ?>"
                                                data-date="<?= date('d M Y', strtotime($r['record_date'])) ?>"
                                                data-status="<?= htmlspecialchars($r['status']) ?>"
                                                data-title="<?= htmlspecialchars($r['title']) ?>"
                                                data-notes="<?= htmlspecialchars($r['notes'] ?? '') ?>">👁️</button>
                                        <button type="button" class="btn btn-sm btn-outline-custom edit-record-btn" title="Edit"
                                                data-id="<?= (int) $r['id'] ?>"
                                                data-patient-id="<?= (int) $r['patient_id'] ?>"
                                                data-doctor-id="<?= (int) $r['doctor_id'] ?>"
                                                data-title="<?= htmlspecialchars($r['title']) ?>"
                                                data-notes="<?= htmlspecialchars($r['notes'] ?? '') ?>"
                                                data-date="<?= htmlspecialchars($r['record_date']) ?>">✏️</button>
                                        <?php if ($r['status'] !== 'Verified'): ?>
                                            <form method="POST" action="admin.php" class="d-inline">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="verify_record">
                                                <input type="hidden" name="record_id" value="<?= (int) $r['id'] ?>">
                                                <button type="submit" class="btn btn-sm btn-success" title="Mark Verified">✔️</button>
                                            </form>
                                        <?php endif; ?>
                                        <form method="POST" action="admin.php" class="d-inline" onsubmit="return confirm('Delete this medical record? This cannot be undone.');">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="delete_record">
                                            <input type="hidden" name="record_id" value="<?= (int) $r['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete">🗑️</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

        <!-- Today's Appointments -->
        <section class="card-custom mb-4" id="appointments-management">
            <div class="card-header-custom">
                <h5 class="manrope-700 mb-0">Scheduled Consultations List</h5>
                <small class="text-muted">Today's appointments <?= $isDoctor ? ' — ' . htmlspecialchars($myDepartment) . ' Department' : '' ?></small>
            </div>
            <div class="card-body-custom">
                <div class="records-table-container">
                    <table class="records-table">
                        <thead>
                            <tr><th>Time Slot</th><th>Patient Name</th><th>Department</th><th>Physician</th><th>Status</th><th>Action</th></tr>
                        </thead>
                        <tbody>
                            <?php if (!$appointments): ?>
                                <tr><td colspan="6" class="text-muted text-center py-3">No appointments scheduled for today.</td></tr>
                            <?php endif; ?>
                            <?php foreach ($appointments as $a): ?>
                                <tr>
                                    <td><strong><?= htmlspecialchars($a['appt_time']) ?></strong></td>
                                    <td><?= htmlspecialchars($a['patient_name']) ?> (#<?= htmlspecialchars($a['patient_code']) ?>)</td>
                                    <td><?= htmlspecialchars($a['department']) ?></td>
                                    <td><?= htmlspecialchars($a['doctor_name']) ?></td>
                                    <td><span class="badge <?= statusBadge($a['status']) ?>"><?= htmlspecialchars($a['status']) ?></span></td>
                                    <td>
                                        <?php if ($a['status'] === 'Completed'): ?>
                                            <button class="btn btn-sm btn-success" disabled>✔️ Done</button>
                                        <?php elseif ($a['status'] === 'Cancelled'): ?>
                                            <button class="btn btn-sm btn-outline-secondary" disabled>Cancelled</button>
                                        <?php else: ?>
                                            <form method="POST" action="admin.php">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="complete_appointment">
                                                <input type="hidden" name="appt_id" value="<?= (int) $a['id'] ?>">
                                                <button type="submit" class="btn btn-sm btn-success">Mark Completed</button>
                                            </form>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
    </main>

    <!-- MODAL: Add Prescription -->
    <div class="modal fade" id="addPrescriptionModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content modal-content-custom">
                <div class="modal-header modal-header-custom">
                    <h5 class="modal-title manrope-700">Add Medication to a Checkup</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST" action="admin.php">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="add_prescription">
                    <div class="modal-body p-4">
                        <p class="text-muted">Only enter medication and instructions prescribed by the attending clinician. Patients can request pharmacy fulfillment until the expiry date.</p>
                        <div class="mb-3">
                            <label class="form-label manrope-600">Checkup record</label>
                            <select name="record_id" class="form-select form-select-custom" required>
                                <option value="">Choose a patient checkup...</option>
                                <?php foreach ($prescriptionOptions as $record): ?>
                                    <option value="<?= (int) $record['id'] ?>">
                                        <?= htmlspecialchars($record['patient_name']) ?> ? <?= htmlspecialchars($record['title']) ?> (<?= date('d M Y', strtotime($record['record_date'])) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php if (!$prescriptionOptions): ?>
                                <small class="text-muted">Create a medical record before adding its prescription.</small>
                            <?php endif; ?>
                        </div>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label manrope-600">Medication name</label>
                                <input type="text" name="medicine_name" class="form-control form-control-custom" maxlength="150" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label manrope-600">Dosage</label>
                                <input type="text" name="dosage" class="form-control form-control-custom" maxlength="120" placeholder="As prescribed" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label manrope-600">Quantity per fulfillment</label>
                                <input type="number" name="quantity" class="form-control form-control-custom" min="1" max="1000" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label manrope-600">Refills allowed</label>
                                <input type="number" name="refills_allowed" class="form-control form-control-custom" min="0" max="5" value="0" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label manrope-600">Prescription valid until</label>
                                <input type="date" name="valid_until" class="form-control form-control-custom" min="<?= date('Y-m-d') ?>" required>
                            </div>
                            <div class="col-12">
                                <label class="form-label manrope-600">Directions / instructions</label>
                                <textarea name="instructions" class="form-control form-control-custom" rows="3" required></textarea>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer modal-footer-custom">
                        <button type="button" class="btn btn-outline-custom" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary-custom">Save Prescription</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- MODAL: Add New Medical Record -->
    <div class="modal fade" id="addRecordModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content modal-content-custom">
                <div class="modal-header modal-header-custom">
                    <h5 class="modal-title manrope-700">Log New Medical Record (EMR)</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST" action="admin.php">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="add_record">
                    <div class="modal-body p-4">
                        <div class="row g-3 mb-3">
                            <div class="col-md-6">
                                <label class="form-label manrope-600">Patient</label>
                                <select name="patient_id" class="form-select form-select-custom" required>
                                    <option value="">Choose a patient...</option>
                                    <?php foreach ($patientsList as $p): ?>
                                        <option value="<?= (int) $p['id'] ?>">#<?= htmlspecialchars($p['patient_code']) ?> — <?= htmlspecialchars($p['full_name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label manrope-600">Attending Physician</label>
                                <select name="doctor_id" class="form-select form-select-custom" required>
                                    <option value="">Choose a physician...</option>
                                    <?php foreach ($doctorsList as $d): ?>
                                        <option value="<?= (int) $d['id'] ?>"><?= htmlspecialchars($d['full_name']) ?> (<?= htmlspecialchars($d['department']) ?>)</option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-12">
                                <label class="form-label manrope-600">Record Title / Procedure</label>
                                <input type="text" name="title" class="form-control form-control-custom" placeholder="e.g. Chest X-Ray Scan" required>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label manrope-600">Diagnostic Summary & Notes</label>
                            <textarea name="notes" class="form-control form-control-custom" rows="4" placeholder="Enter clinical findings, vitals, lab parameters, and prescription details..." required></textarea>
                        </div>
                    </div>
                    <div class="modal-footer modal-footer-custom">
                        <button type="button" class="btn btn-outline-custom" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary-custom">Save & Publish Record</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- MODAL: View Medical Record -->
    <div class="modal fade" id="viewRecordModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content modal-content-custom">
                <div class="modal-header modal-header-custom">
                    <h5 class="modal-title manrope-700">Medical Record Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <small class="text-muted d-block">Patient</small>
                            <strong id="viewRecPatient"></strong>
                        </div>
                        <div class="col-md-6">
                            <small class="text-muted d-block">Attending Physician</small>
                            <strong id="viewRecDoctor"></strong>
                        </div>
                        <div class="col-md-6">
                            <small class="text-muted d-block">Date</small>
                            <strong id="viewRecDate"></strong>
                        </div>
                        <div class="col-md-6">
                            <small class="text-muted d-block">Status</small>
                            <strong id="viewRecStatus"></strong>
                        </div>
                    </div>
                    <hr>
                    <h6 class="manrope-700 mb-2" id="viewRecTitle"></h6>
                    <p class="text-secondary mb-0" id="viewRecNotes" style="white-space: pre-wrap;"></p>
                </div>
                <div class="modal-footer modal-footer-custom">
                    <button type="button" class="btn btn-primary-custom" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- MODAL: Edit Medical Record -->
    <div class="modal fade" id="editRecordModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content modal-content-custom">
                <div class="modal-header modal-header-custom">
                    <h5 class="modal-title manrope-700">Edit Medical Record</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST" action="admin.php">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="edit_record">
                    <input type="hidden" name="record_id" id="editRecId">
                    <div class="modal-body p-4">
                        <div class="row g-3 mb-3">
                            <div class="col-md-6">
                                <label class="form-label manrope-600">Patient</label>
                                <select name="patient_id" id="editRecPatient" class="form-select form-select-custom" required>
                                    <?php foreach ($patientsList as $p): ?>
                                        <option value="<?= (int) $p['id'] ?>">#<?= htmlspecialchars($p['patient_code']) ?> — <?= htmlspecialchars($p['full_name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label manrope-600">Attending Physician</label>
                                <select name="doctor_id" id="editRecDoctor" class="form-select form-select-custom" required>
                                    <?php foreach ($doctorsList as $d): ?>
                                        <option value="<?= (int) $d['id'] ?>"><?= htmlspecialchars($d['full_name']) ?> (<?= htmlspecialchars($d['department']) ?>)</option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label manrope-600">Record Date</label>
                                <input type="date" name="record_date" id="editRecDate" class="form-control form-control-custom" required max="<?= date('Y-m-d') ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label manrope-600">Record Title / Procedure</label>
                                <input type="text" name="title" id="editRecTitle" class="form-control form-control-custom" required>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label manrope-600">Diagnostic Summary & Notes</label>
                            <textarea name="notes" id="editRecNotes" class="form-control form-control-custom" rows="4" required></textarea>
                        </div>
                    </div>
                    <div class="modal-footer modal-footer-custom">
                        <button type="button" class="btn btn-outline-custom" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary-custom">Save Changes</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="toast-container-custom" id="toastContainer"></div>

    <footer>
        <div class="container">
            <p class="mb-1">© 2026 Healthcore Hospital Administration System. Confidentially Restricted to Authorized Staff.</p>
        </div>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="script.js?v=<?= (int) filemtime(__DIR__ . DIRECTORY_SEPARATOR . 'script.js') ?>"></script>
    <?php render_flash_script(); ?>
</body>

</html>
