<?php
// admin_worker_changes.php — NeinMaid
// Admin reviews & approves worker profile edits.

session_start();
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header("Location: login.php");
    exit();
}

date_default_timezone_set('Asia/Kuala_Lumpur');

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$DB_HOST = "localhost";
$DB_USER = "root";
$DB_PASS = "";
$DB_NAME = "maid_system";

try {
    $conn = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);
    $conn->set_charset("utf8mb4");
} catch (Throwable $e) {
    die("DB Error: " . $e->getMessage());
}

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// Notifications system (reuses your existing notifications.php)
// Load notifications helper (robust path check)
$notifPaths = [
    __DIR__ . '/notifications.php',      // same folder
    __DIR__ . '/../notifications.php',   // one level up
];

$notifLoaded = false;
foreach ($notifPaths as $p) {
    if (file_exists($p)) {
        require_once $p;
        $notifLoaded = true;
        break;
    }
}

if (!$notifLoaded) {
    die("notifications.php not found. Expected in: " . implode(' or ', $notifPaths));
}

/* =========================
   APPROVE
   ========================= */
if (isset($_POST['approve'])) {
    $id        = (int)$_POST['change_id'];
    $workerId  = (int)$_POST['worker_id'];

    $row = $conn->query("SELECT * FROM worker_profile_changes WHERE id=$id")->fetch_assoc();
    if ($row) {
        $field = $row['field'];
        $new   = $conn->real_escape_string($row['new_value']);

        // Update main worker table
        $conn->query("UPDATE worker_profiles SET $field='$new' WHERE id=$workerId");

        // Mark change
        $conn->query("
            UPDATE worker_profile_changes
            SET status='approved', reviewed_at=NOW()
            WHERE id=$id
        ");

        notify_worker_by_profile(
            $conn,
            $workerId,
            "worker_profile_approved",
            "Profile Updated",
            "Your request to update [$field] has been approved."
        );
    }

    header("Location: admin_worker_changes.php?success=approved");
    exit();
}

/* =========================
   REJECT
   ========================= */
if (isset($_POST['reject'])) {
    $id        = (int)$_POST['change_id'];
    $workerId  = (int)$_POST['worker_id'];

    // Mark rejected
    $conn->query("
        UPDATE worker_profile_changes
        SET status='rejected', reviewed_at=NOW()
        WHERE id=$id
    ");

    notify_worker_by_profile(
        $conn,
        $workerId,
        "worker_profile_rejected",
        "Profile Update Rejected",
        "Your request to update your profile has been rejected by admin."
    );

    header("Location: admin_worker_changes.php?success=rejected");
    exit();
}

/* =========================
   Fetch all changes
   ========================= */
$sql = "
  SELECT c.*, w.name AS worker_name
  FROM worker_profile_changes c
  JOIN worker_profiles w ON w.id = c.worker_id
  ORDER BY c.status='pending' DESC, c.created_at DESC
";
$changes = $conn->query($sql)->fetch_all(MYSQLI_ASSOC);

/* =========================
   Render page
   ========================= */
$pageTitle  = "Worker Profile Changes – Admin";
$activePage = "worker_changes";
include __DIR__ . "/admin_header.php";
?>

<div class="page-header">
    <div>
        <div class="page-title">Worker Profile Changes</div>
        <div class="page-subtitle">
            Review and approve worker requests to update their profile.
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <div class="card-title">All Requests</div>
        <div class="card-subtitle">
            <?= count($changes); ?> changes found
        </div>
    </div>

    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Worker</th>
                    <th>Field</th>
                    <th>Current Value</th>
                    <th>New Value</th>
                    <th>Status</th>
                    <th>Requested</th>
                    <th>Action</th>
                </tr>
            </thead>

            <tbody>
            <?php if (!$changes): ?>
                <tr>
                    <td colspan="8" class="text-muted small">No changes found.</td>
                </tr>
            <?php endif; ?>

            <?php foreach ($changes as $c): ?>
                <?php
                    $tag = "tag";
                    if ($c['status'] === 'pending')   $tag .= " tag-warning";
                    if ($c['status'] === 'approved')  $tag .= " tag-success";
                    if ($c['status'] === 'rejected')  $tag .= " tag-danger";
                ?>
                <tr>
                    <td><?= (int)$c['id']; ?></td>
                    <td><strong><?= h($c['worker_name']); ?></strong></td>
                    <td><?= h($c['field']); ?></td>
                    <td class="small"><?= nl2br(h($c['old_value'])); ?></td>
                    <td class="small"><?= nl2br(h($c['new_value'])); ?></td>

                    <td><span class="<?= $tag; ?>"><?= ucfirst($c['status']); ?></span></td>
                    <td><?= h($c['created_at']); ?></td>

                    <td>
                        <?php if ($c['status'] === 'pending'): ?>
                            <form method="post" style="display:inline-block;">
                                <input type="hidden" name="change_id" value="<?= $c['id']; ?>">
                                <input type="hidden" name="worker_id" value="<?= $c['worker_id']; ?>">
                                <button class="btn btn-sm btn-success" name="approve">Approve</button>
                            </form>

                            <form method="post" style="display:inline-block;">
                                <input type="hidden" name="change_id" value="<?= $c['id']; ?>">
                                <input type="hidden" name="worker_id" value="<?= $c['worker_id']; ?>">
                                <button class="btn btn-sm btn-danger" name="reject">Reject</button>
                            </form>
                        <?php else: ?>
                            <span class="text-muted small">No action</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include __DIR__ . "/admin_footer.php"; ?>
