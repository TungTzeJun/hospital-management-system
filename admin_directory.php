<?php
require_once 'auth.php';
require_role(['admin']);
require_once 'db.php';

$patients = $pdo->query(
    'SELECT p.*, u.full_name, u.email, u.phone, u.created_at AS account_created_at,
            d.department AS primary_doctor_department, du.full_name AS primary_doctor_name
     FROM patients p
     JOIN users u ON u.id = p.user_id
     LEFT JOIN doctors d ON d.id = p.primary_doctor_id
     LEFT JOIN users du ON du.id = d.user_id
     ORDER BY u.full_name'
)->fetchAll();

$patientDirectory = [];
foreach ($patients as $patient) {
    $patient['appointments'] = [];
    $patient['records'] = [];
    $patient['allergies'] = [];
    $patient['conditions'] = [];
    $patient['prescriptions'] = [];
    $patientDirectory[(int) $patient['id']] = $patient;
}

$appointments = $pdo->query(
    'SELECT a.patient_id, a.appt_date, a.appt_time, a.department, a.reason,
            a.status, a.ticket_number, u.full_name AS doctor_name
     FROM appointments a
     JOIN doctors d ON d.id = a.doctor_id
     JOIN users u ON u.id = d.user_id
     ORDER BY a.appt_date DESC, a.appt_time DESC, a.id DESC'
)->fetchAll();
foreach ($appointments as $appointment) {
    $patientDirectory[(int) $appointment['patient_id']]['appointments'][] = $appointment;
}

$records = $pdo->query(
    'SELECT mr.patient_id, mr.title, mr.notes, mr.record_date, mr.status,
            u.full_name AS doctor_name, d.department
     FROM medical_records mr
     JOIN doctors d ON d.id = mr.doctor_id
     JOIN users u ON u.id = d.user_id
     ORDER BY mr.record_date DESC, mr.id DESC'
)->fetchAll();
foreach ($records as $record) {
    $patientDirectory[(int) $record['patient_id']]['records'][] = $record;
}

foreach ($pdo->query('SELECT patient_id, name, severity FROM allergies ORDER BY name')->fetchAll() as $allergy) {
    $patientDirectory[(int) $allergy['patient_id']]['allergies'][] = $allergy;
}

foreach ($pdo->query('SELECT patient_id, name FROM conditions ORDER BY name')->fetchAll() as $condition) {
    $patientDirectory[(int) $condition['patient_id']]['conditions'][] = $condition;
}

$prescriptions = $pdo->query(
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
foreach ($prescriptions as $prescriptionRow) {
    $patientId = (int) $prescriptionRow['patient_id'];
    $prescriptionId = (int) $prescriptionRow['id'];
    if (!isset($patientDirectory[$patientId]['prescriptions'][$prescriptionId])) {
        $prescription = $prescriptionRow;
        $prescription['orders'] = [];
        $patientDirectory[$patientId]['prescriptions'][$prescriptionId] = $prescription;
    }
    if ($prescriptionRow['order_id'] !== null) {
        $patientDirectory[$patientId]['prescriptions'][$prescriptionId]['orders'][] = $prescriptionRow;
    }
}

$doctors = $pdo->query(
    "SELECT d.doctor_code, d.department, u.full_name, u.email, u.phone
     FROM doctors d
     JOIN users u ON u.id = d.user_id
     ORDER BY d.department, u.full_name"
)->fetchAll();

?>
<!DOCTYPE html>
<html lang="en" data-theme="light">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Patient & Doctor Directory - Healthcore</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@200..800&display=swap" rel="stylesheet">
</head>

<body>
    <nav class="navbar navbar-expand-lg navbar-custom sticky-top">
        <div class="navbar-layout">
            <a class="navbar-brand-custom" href="admin_directory.php"><span class="logo-icon">+</span> Healthcore</a>
            <div class="d-flex align-items-center gap-3">
                <button class="btn btn-outline-custom p-2 rounded-circle" id="themeToggleBtn" title="Toggle Light/Dark Theme">🌙</button>
                <span class="badge bg-secondary p-2"><?= htmlspecialchars($_SESSION['full_name']) ?> · Read-only access</span>
                <a href="logout.php" class="btn btn-outline-danger btn-sm rounded-pill px-3">Logout</a>
            </div>
        </div>
    </nav>

    <main class="main-wrapper">
        <section class="welcome-header">
            <h2 class="manrope-800 mb-2">Patient & Doctor Directory</h2>
            <p class="mb-0 opacity-90">Read-only hospital information. Queue controls and clinical management are not available to admin accounts.</p>
        </section>

        <section class="card-custom mb-4" id="patient-directory">
            <div class="card-header-custom flex-wrap gap-2">
                <div>
                    <h5 class="manrope-700 mb-0">All Registered Patients (<?= count($patientDirectory) ?>)</h5>
                    <small class="text-muted">Patient profiles, appointments, checkups, allergies, conditions, prescriptions, and pharmacy history.</small>
                </div>
                <input type="search" class="form-control form-control-custom table-search-bar" id="patientDirectorySearch" placeholder="Search any patient detail...">
            </div>
            <div class="card-body-custom">
                <?php if (!$patientDirectory): ?>
                    <p class="text-muted text-center py-3 mb-0">No registered patients were found.</p>
                <?php else: ?>
                    <div id="patientDirectoryEntries">
                        <?php foreach ($patientDirectory as $patient): ?>
                            <details class="patient-directory-entry">
                                <summary class="patient-directory-summary">
                                    <span>
                                        <strong><?= htmlspecialchars($patient['full_name']) ?></strong>
                                        <small class="text-muted d-block">Patient #<?= htmlspecialchars($patient['patient_code']) ?> · <?= htmlspecialchars($patient['email']) ?></small>
                                    </span>
                                    <span class="text-muted small"><?= htmlspecialchars($patient['phone'] ?: 'No phone on file') ?></span>
                                </summary>
                                <div class="patient-directory-content">
                                    <h6 class="manrope-700">Profile</h6>
                                    <div class="row g-3 mb-4">
                                        <div class="col-md-4"><small class="text-muted d-block">Account created</small><?= date('d M Y', strtotime($patient['account_created_at'])) ?></div>
                                        <div class="col-md-4"><small class="text-muted d-block">Date of birth</small><?= htmlspecialchars($patient['dob'] ?: '—') ?></div>
                                        <div class="col-md-4"><small class="text-muted d-block">Gender</small><?= htmlspecialchars($patient['gender'] ?: '—') ?></div>
                                        <div class="col-md-4"><small class="text-muted d-block">Blood type</small><?= htmlspecialchars($patient['blood_type'] ?: '—') ?></div>
                                        <div class="col-md-4"><small class="text-muted d-block">Height / weight</small><?= htmlspecialchars(($patient['height_cm'] ? $patient['height_cm'] . ' cm' : '—') . ' / ' . ($patient['weight_kg'] ? $patient['weight_kg'] . ' kg' : '—')) ?></div>
                                        <div class="col-md-4"><small class="text-muted d-block">Primary physician</small><?= htmlspecialchars($patient['primary_doctor_name'] ?: 'Not assigned') ?><?= $patient['primary_doctor_department'] ? ' · ' . htmlspecialchars($patient['primary_doctor_department']) : '' ?></div>
                                        <div class="col-md-6"><small class="text-muted d-block">Address</small><?= nl2br(htmlspecialchars($patient['address'] ?: '—')) ?></div>
                                        <div class="col-md-6"><small class="text-muted d-block">Emergency contact</small><?= htmlspecialchars($patient['emergency_name'] ?: '—') ?><?= $patient['emergency_phone'] ? ' · ' . htmlspecialchars($patient['emergency_phone']) : '' ?></div>
                                        <div class="col-md-6"><small class="text-muted d-block">Insurance provider</small><?= htmlspecialchars($patient['insurance_provider'] ?: '—') ?></div>
                                        <div class="col-md-6"><small class="text-muted d-block">Insurance policy number</small><?= htmlspecialchars($patient['insurance_policy_no'] ?: '—') ?></div>
                                    </div>

                                    <div class="row g-4">
                                        <div class="col-lg-6">
                                            <h6 class="manrope-700">Allergies</h6>
                                            <?php if (!$patient['allergies']): ?><p class="text-muted small">None recorded.</p><?php else: ?>
                                                <ul><?php foreach ($patient['allergies'] as $allergy): ?><li><?= htmlspecialchars($allergy['name']) ?> — <?= htmlspecialchars($allergy['severity'] ?: 'Mild') ?></li><?php endforeach; ?></ul>
                                            <?php endif; ?>
                                        </div>
                                        <div class="col-lg-6">
                                            <h6 class="manrope-700">Medical conditions</h6>
                                            <?php if (!$patient['conditions']): ?><p class="text-muted small">None recorded.</p><?php else: ?>
                                                <ul><?php foreach ($patient['conditions'] as $condition): ?><li><?= htmlspecialchars($condition['name']) ?></li><?php endforeach; ?></ul>
                                            <?php endif; ?>
                                        </div>

                                        <div class="col-12">
                                            <h6 class="manrope-700">Appointments</h6>
                                            <?php if (!$patient['appointments']): ?><p class="text-muted small">No appointments recorded.</p><?php else: ?>
                                                <div class="records-table-container"><table class="records-table">
                                                    <thead><tr><th>Date / time</th><th>Department</th><th>Physician</th><th>Status</th><th>Reason</th></tr></thead>
                                                    <tbody><?php foreach ($patient['appointments'] as $appointment): ?>
                                                        <tr><td><?= date('d M Y', strtotime($appointment['appt_date'])) ?> · <?= htmlspecialchars($appointment['appt_time']) ?></td><td><?= htmlspecialchars($appointment['department']) ?></td><td><?= htmlspecialchars($appointment['doctor_name']) ?></td><td><?= htmlspecialchars($appointment['status']) ?><?= $appointment['ticket_number'] !== null ? ' · Ticket ' . (int) $appointment['ticket_number'] : '' ?></td><td><?= htmlspecialchars($appointment['reason'] ?: '—') ?></td></tr>
                                                    <?php endforeach; ?></tbody>
                                                </table></div>
                                            <?php endif; ?>
                                        </div>

                                        <div class="col-12">
                                            <h6 class="manrope-700">Checkups and medical records</h6>
                                            <?php if (!$patient['records']): ?><p class="text-muted small">No records recorded.</p><?php else: ?>
                                                <div class="records-table-container"><table class="records-table">
                                                    <thead><tr><th>Date</th><th>Record</th><th>Department / physician</th><th>Status</th><th>Notes</th></tr></thead>
                                                    <tbody><?php foreach ($patient['records'] as $record): ?>
                                                        <tr><td><?= date('d M Y', strtotime($record['record_date'])) ?></td><td><?= htmlspecialchars($record['title']) ?></td><td><?= htmlspecialchars($record['department']) ?> · <?= htmlspecialchars($record['doctor_name']) ?></td><td><?= htmlspecialchars($record['status']) ?></td><td><?= nl2br(htmlspecialchars($record['notes'] ?: '—')) ?></td></tr>
                                                    <?php endforeach; ?></tbody>
                                                </table></div>
                                            <?php endif; ?>
                                        </div>

                                        <div class="col-12">
                                            <h6 class="manrope-700">Prescriptions and pharmacy requests</h6>
                                            <?php if (!$patient['prescriptions']): ?><p class="text-muted small">No prescriptions recorded.</p><?php else: ?>
                                                <div class="records-table-container"><table class="records-table">
                                                    <thead><tr><th>Medication</th><th>Checkup</th><th>Directions</th><th>Quantity / refills</th><th>Valid until</th><th>Pharmacy requests</th></tr></thead>
                                                    <tbody><?php foreach ($patient['prescriptions'] as $prescription): ?>
                                                        <tr>
                                                            <td><strong><?= htmlspecialchars($prescription['medicine_name']) ?></strong><br><?= htmlspecialchars($prescription['dosage']) ?></td>
                                                            <td><?= htmlspecialchars($prescription['record_title']) ?><br><small class="text-muted"><?= date('d M Y', strtotime($prescription['record_date'])) ?> · <?= htmlspecialchars($prescription['doctor_name']) ?></small></td>
                                                            <td><?= nl2br(htmlspecialchars($prescription['instructions'])) ?></td>
                                                            <td><?= (int) $prescription['quantity'] ?> · <?= (int) $prescription['refills_allowed'] ?> refills</td>
                                                            <td><?= date('d M Y', strtotime($prescription['valid_until'])) ?></td>
                                                            <td><?php if (!$prescription['orders']): ?><span class="text-muted">No request</span><?php else: foreach ($prescription['orders'] as $order): ?><div><?= htmlspecialchars($order['order_status']) ?> — <?= (int) $order['order_quantity'] ?> — <?= date('d M Y', strtotime($order['order_created_at'])) ?></div><?php endforeach; endif; ?></td>
                                                        </tr>
                                                    <?php endforeach; ?></tbody>
                                                </table></div>
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

        <section class="card-custom mb-4" id="doctor-directory">
            <div class="card-header-custom flex-wrap gap-2">
                <div>
                    <h5 class="manrope-700 mb-0">Doctors (<?= count($doctors) ?>)</h5>
                    <small class="text-muted">Hospital doctor contact and department information.</small>
                </div>
                <input type="search" class="form-control form-control-custom table-search-bar" id="doctorDirectorySearch" placeholder="Search doctors...">
            </div>
            <div class="card-body-custom">
                <div class="records-table-container">
                    <table class="records-table">
                        <thead><tr><th>Doctor</th><th>Doctor code</th><th>Department</th><th>Email</th><th>Phone</th></tr></thead>
                        <tbody id="doctorDirectoryEntries">
                            <?php if (!$doctors): ?><tr><td colspan="5" class="text-muted text-center py-3">No doctors were found.</td></tr><?php endif; ?>
                            <?php foreach ($doctors as $doctor): ?>
                                <tr class="doctor-directory-entry"><td><?= htmlspecialchars($doctor['full_name']) ?></td><td><?= htmlspecialchars($doctor['doctor_code']) ?></td><td><?= htmlspecialchars($doctor['department']) ?></td><td><?= htmlspecialchars($doctor['email']) ?></td><td><?= htmlspecialchars($doctor['phone'] ?: '—') ?></td></tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <p class="text-muted text-center py-3 mb-0 d-none" id="doctorDirectoryNoResults">No doctors match that search.</p>
            </div>
        </section>
    </main>

    <footer><div class="container"><p class="mb-1">© 2026 Healthcore Hospital Management System.</p><small>Read-only directory for authorized admin accounts.</small></div></footer>
    <div class="toast-container-custom" id="toastContainer"></div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="script.js?v=<?= (int) filemtime(__DIR__ . DIRECTORY_SEPARATOR . 'script.js') ?>"></script>
    <?php render_flash_script(); ?>
</body>

</html>
