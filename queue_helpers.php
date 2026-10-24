<?php
// ============================================================
// Per-department queue helpers.
//
// queue_state now holds ONE ROW PER DEPARTMENT (department is
// UNIQUE) instead of a single global row with id=1. These helpers
// are the only place that should touch queue_state directly, so
// every page (admin.php, index.php, queue_status.php) agrees on
// how a department's queue is fetched, created, called, and reset.
// ============================================================

// Fetch the queue row for a department, creating it (currently_serving
// starting at -1, before the first ticket is called) the first time it's ever needed. This means you don't
// have to pre-seed queue_state for every department up front — a brand
// new department just works the first time anyone looks at its queue.
function get_or_create_queue(PDO $pdo, string $department): array {
    $stmt = $pdo->prepare('SELECT * FROM queue_state WHERE department = ?');
    $stmt->execute([$department]);
    $row = $stmt->fetch();
    if ($row) {
        return $row;
    }

    // Race-safe: two simultaneous first-requests for the same brand-new
    // department could both miss the SELECT above. INSERT IGNORE means
    // only one of them actually creates the row; both then re-SELECT.
    $ins = $pdo->prepare('INSERT IGNORE INTO queue_state (department, currently_serving) VALUES (?, -1)');
    $ins->execute([$department]);

    $stmt->execute([$department]);
    $row = $stmt->fetch();

    if (!$row) {
        // Getting here almost always means queue_state still has the OLD
        // single-row schema (id INT PRIMARY KEY DEFAULT 1, not
        // AUTO_INCREMENT) — every INSERT then defaults to id=1, collides
        // with whichever row already has that id, and INSERT IGNORE
        // silently drops it instead of creating this department's row.
        // Run the queue_state migration in README.md ("If you already
        // imported the old schema.sql") to fix it.
        throw new RuntimeException(
            "Could not create a queue row for department \"{$department}\". " .
            "This usually means queue_state still has the old table structure " .
            "(id not AUTO_INCREMENT) — see the queue_state migration in README.md."
        );
    }

    return $row;
}

// Lock a department's queue row while allocating a ticket so concurrent
// bookings cannot both receive the same next number.
function lock_department_queue(PDO $pdo, string $department): void {
    get_or_create_queue($pdo, $department);

    $stmt = $pdo->prepare('SELECT id FROM queue_state WHERE department = ? FOR UPDATE');
    $stmt->execute([$department]);
    if (!$stmt->fetch()) {
        throw new RuntimeException("Could not lock the queue row for department \"{$department}\".");
    }
}

// The live serving number is scoped to today's Called appointments. The
// queue_state counter is retained for compatibility, but can be stale after
// midnight because ticket numbers restart each day.
function department_currently_serving(PDO $pdo, string $department): int {
    $stmt = $pdo->prepare(
        "SELECT COALESCE(MAX(a.ticket_number), -1) AS t FROM appointments a
         JOIN doctors d ON d.id = a.doctor_id
         WHERE d.department = ? AND a.appt_date = CURDATE() AND a.status = 'Called'"
    );
    $stmt->execute([$department]);
    return (int) $stmt->fetch()['t'];
}

// Estimated service time per patient varies by department.
function department_wait_minutes_per_patient(string $department): int {
    $minutesByDepartment = [
        'ENT' => 15,
        'Cardiology' => 20,
        'Neurology' => 25,
        'Radiology' => 30,
    ];

    return $minutesByDepartment[$department] ?? 20;
}

function department_estimated_wait(PDO $pdo, string $department, int $ticketNumber, string $status): array {
    if ($status === 'Called') {
        return ['ahead' => 0, 'minutes' => 0];
    }

    $stmt = $pdo->prepare(
        "SELECT
            SUM(CASE WHEN a.status IN ('Confirmed','Pending','Arrived') THEN 1 ELSE 0 END) AS waiting_ahead,
            SUM(CASE WHEN a.status = 'Called' THEN 1 ELSE 0 END) AS called_ahead
         FROM appointments a
         JOIN doctors d ON d.id = a.doctor_id
         WHERE d.department = ? AND a.appt_date = CURDATE()
           AND a.status IN ('Confirmed','Pending','Arrived','Called')
           AND a.ticket_number < ?"
    );
    $stmt->execute([$department, $ticketNumber]);
    $counts = $stmt->fetch();
    $waitingAhead = (int) ($counts['waiting_ahead'] ?? 0);
    $calledAhead = (int) ($counts['called_ahead'] ?? 0);
    $ahead = $waitingAhead + $calledAhead;

    if ($ahead === 0) {
        return ['ahead' => 0, 'minutes' => 0];
    }

    $minutesPerPatient = department_wait_minutes_per_patient($department);
    $currentlyServing = department_currently_serving($pdo, $department);
    $servingAhead = $currentlyServing >= 0 && $currentlyServing < $ticketNumber;
    $otherCalledAhead = max(0, $calledAhead - (int) $servingAhead);
    $minutes = ($waitingAhead + $otherCalledAhead) * $minutesPerPatient;

    if ($servingAhead) {
        $stmt = $pdo->prepare(
            'SELECT GREATEST(0, TIMESTAMPDIFF(SECOND, updated_at, CURRENT_TIMESTAMP)) AS elapsed_seconds
             FROM queue_state WHERE department = ?'
        );
        $stmt->execute([$department]);
        $elapsedSeconds = (int) $stmt->fetchColumn();
        $remainingSeconds = max(0, ($minutesPerPatient * 60) - $elapsedSeconds);
        $minutes += (int) ceil($remainingSeconds / 60);
    }

    return ['ahead' => $ahead, 'minutes' => $minutes];
}

// All departments that currently have at least one doctor. This is what
// drives the admin "one card per department" queue view, and is used to
// validate that a department name POSTed from a form is a real one
// before we act on it (an admin should not be able to call/reset a
// queue for a made-up department name).
function active_departments(PDO $pdo): array {
    return $pdo->query('SELECT DISTINCT department FROM doctors ORDER BY department')->fetchAll(PDO::FETCH_COLUMN);
}

// Real, status-based count of patients still waiting in $department
// today — same rule used everywhere else in the app (not ticket-number
// arithmetic, so it never sits on a stale/inflated number).
function department_waiting_count(PDO $pdo, string $department): int {
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) AS c FROM appointments a
         JOIN doctors d ON d.id = a.doctor_id
         WHERE d.department = ? AND a.appt_date = CURDATE()
           AND a.status IN ('Confirmed','Pending','Arrived')"
    );
    $stmt->execute([$department]);
    return (int) $stmt->fetch()['c'];
}

// Highest ticket number issued today for $department (tickets reset
// per department per day — see queue_helpers' next_ticket_number()).
function department_max_ticket(PDO $pdo, string $department): int {
    $stmt = $pdo->prepare(
        "SELECT COALESCE(MAX(a.ticket_number), 0) AS t FROM appointments a
         JOIN doctors d ON d.id = a.doctor_id
         WHERE d.department = ? AND a.appt_date = ?"
    );
    $stmt->execute([$department, date('Y-m-d')]);
    return (int) $stmt->fetch()['t'];
}

// Next ticket number to hand out for a department on a given date.
// Tickets are scoped per department (not just per day) now that each
// department runs its own queue, so Cardiology and Neurology both get
// to start at #1 on the same day instead of competing for one counter.
function next_ticket_number(PDO $pdo, string $department, string $date): int {
    $stmt = $pdo->prepare(
        "SELECT COALESCE(MAX(a.ticket_number), 0) + 1 AS t FROM appointments a
         JOIN doctors d ON d.id = a.doctor_id
         WHERE d.department = ? AND a.appt_date = ?"
    );
    $stmt->execute([$department, $date]);
    return (int) $stmt->fetch()['t'];
}

// The next eligible appointment's ticket number, including gaps left by
// cancelled or completed appointments.
function department_next_ticket(PDO $pdo, string $department): int {
    $stmt = $pdo->prepare(
        "SELECT MIN(a.ticket_number) AS t FROM appointments a
         JOIN doctors d ON d.id = a.doctor_id
         WHERE d.department = ? AND a.appt_date = CURDATE()
           AND a.status IN ('Confirmed','Pending','Arrived')"
    );
    $stmt->execute([$department]);
    $ticket = $stmt->fetch()['t'];
    if ($ticket !== null) {
        return (int) $ticket;
    }

    return max(1, department_currently_serving($pdo, $department) + 1);
}

// Short, URL/DOM-id-safe slug for a department name (e.g. "General
// Medicine" -> "general-medicine"), used to give each per-department
// queue card its own unique element IDs on admin.php.
function department_slug(string $department): string {
    return strtolower(trim(preg_replace('/[^a-zA-Z0-9]+/', '-', $department), '-'));
}
