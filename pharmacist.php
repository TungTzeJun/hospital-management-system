<?php
require_once 'auth.php';
require_role(['pharmacist']);
require_once 'db.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $orderId = filter_var($_POST['order_id'] ?? null, FILTER_VALIDATE_INT);
    $newStatus = $_POST['status'] ?? '';
    $allowedTransitions = [
        'Requested' => ['Ready', 'Cancelled'],
        'Ready' => ['Collected', 'Cancelled'],
    ];

    if ($orderId === false || $orderId < 1) {
        flash('Select a valid medication request.', '⚠️');
    } else {
        $pdo->beginTransaction();
        $orderStmt = $pdo->prepare('SELECT status FROM pharmacy_orders WHERE id = ? FOR UPDATE');
        $orderStmt->execute([$orderId]);
        $order = $orderStmt->fetch();

        if (!$order || !in_array($newStatus, $allowedTransitions[$order['status']] ?? [], true)) {
            $pdo->rollBack();
            flash('That medication request can no longer be updated to the selected status.', '⚠️');
        } else {
            $update = $pdo->prepare('UPDATE pharmacy_orders SET status = ? WHERE id = ? AND status = ?');
            $update->execute([$newStatus, $orderId, $order['status']]);
            $pdo->commit();
            flash('Medication request updated to ' . $newStatus . '.', '✅');
        }
    }

    header('Location: pharmacist.php');
    exit;
}

$orders = $pdo->query(
    "SELECT po.id, po.quantity AS order_quantity, po.status, po.created_at,
            pr.medicine_name, pr.dosage, pr.instructions, pr.valid_until,
            p.patient_code, u.full_name AS patient_name, u.phone AS patient_phone
     FROM pharmacy_orders po
     JOIN prescriptions pr ON pr.id = po.prescription_id AND pr.patient_id = po.patient_id
     JOIN patients p ON p.id = po.patient_id
     JOIN users u ON u.id = p.user_id
     WHERE po.status IN ('Requested', 'Ready')
     ORDER BY po.created_at ASC, po.id ASC"
)->fetchAll();
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pharmacy Requests - Healthcore</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@200..800&display=swap" rel="stylesheet">
</head>

<body>
    <nav class="navbar navbar-expand-lg navbar-custom sticky-top">
        <div class="navbar-layout">
            <a class="navbar-brand-custom" href="pharmacist.php"><span class="logo-icon">+</span> Healthcore Pharmacy</a>
            <div class="d-flex align-items-center gap-3">
                <button class="btn btn-outline-custom p-2 rounded-circle" id="themeToggleBtn" title="Toggle Light/Dark Theme">🌙</button>
                <span class="badge bg-secondary p-2"><?= htmlspecialchars($_SESSION['full_name']) ?> · Pharmacy</span>
                <a href="logout.php" class="btn btn-outline-danger btn-sm rounded-pill px-3">Logout</a>
            </div>
        </div>
    </nav>

    <main class="main-wrapper">
        <section class="welcome-header">
            <h2 class="manrope-800 mb-2">Medication Requests</h2>
            <p class="mb-0 opacity-90">Review patient pickup requests and update their fulfillment status. This portal does not process payment or delivery.</p>
        </section>

        <section class="card-custom">
            <div class="card-header-custom">
                <div>
                    <h5 class="manrope-700 mb-0">Open Requests (<?= count($orders) ?>)</h5>
                    <small class="text-muted">Only medication pickup requests are shown here. Queue and clinical record controls are not available.</small>
                </div>
            </div>
            <div class="card-body-custom">
                <?php if (!$orders): ?>
                    <p class="text-muted text-center py-4 mb-0">There are no open pharmacy requests.</p>
                <?php else: ?>
                    <div class="records-table-container">
                        <table class="records-table">
                            <thead><tr><th>Patient</th><th>Medication</th><th>Prescription directions</th><th>Requested quantity</th><th>Request date</th><th>Status / action</th></tr></thead>
                            <tbody>
                                <?php foreach ($orders as $order): ?>
                                    <tr>
                                        <td>
                                            <strong><?= htmlspecialchars($order['patient_name']) ?></strong>
                                            <br><small class="text-muted"><?= htmlspecialchars($order['patient_code']) ?></small>
                                            <?php if ($order['patient_phone']): ?><br><small class="text-muted"><?= htmlspecialchars($order['patient_phone']) ?></small><?php endif; ?>
                                        </td>
                                        <td>
                                            <strong><?= htmlspecialchars($order['medicine_name']) ?></strong>
                                            <br><?= htmlspecialchars($order['dosage']) ?>
                                            <br><small class="text-muted">Valid until <?= date('d M Y', strtotime($order['valid_until'])) ?></small>
                                        </td>
                                        <td><?= nl2br(htmlspecialchars($order['instructions'])) ?></td>
                                        <td><?= (int) $order['order_quantity'] ?></td>
                                        <td><?= date('d M Y', strtotime($order['created_at'])) ?></td>
                                        <td>
                                            <span class="badge <?= $order['status'] === 'Ready' ? 'bg-success' : 'bg-warning text-dark' ?>"><?= htmlspecialchars($order['status']) ?></span>
                                            <div class="d-flex flex-wrap gap-2 mt-2">
                                                <?php if ($order['status'] === 'Requested'): ?>
                                                    <form method="POST" action="pharmacist.php">
                                                        <?= csrf_field() ?>
                                                        <input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>">
                                                        <input type="hidden" name="status" value="Ready">
                                                        <button type="submit" class="btn btn-sm btn-primary-custom">Mark Ready</button>
                                                    </form>
                                                <?php else: ?>
                                                    <form method="POST" action="pharmacist.php">
                                                        <?= csrf_field() ?>
                                                        <input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>">
                                                        <input type="hidden" name="status" value="Collected">
                                                        <button type="submit" class="btn btn-sm btn-primary-custom">Mark Collected</button>
                                                    </form>
                                                <?php endif; ?>
                                                <form method="POST" action="pharmacist.php" onsubmit="return confirm('Cancel this medication request?');">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>">
                                                    <input type="hidden" name="status" value="Cancelled">
                                                    <button type="submit" class="btn btn-sm btn-outline-danger">Cancel</button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </section>
    </main>

    <footer><div class="container"><p class="mb-1">© 2026 Healthcore Hospital Management System.</p><small>Medication fulfillment access for authorized pharmacy staff.</small></div></footer>
    <div class="toast-container-custom" id="toastContainer"></div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="script.js?v=<?= (int) filemtime(__DIR__ . DIRECTORY_SEPARATOR . 'script.js') ?>"></script>
    <?php render_flash_script(); ?>
</body>

</html>
