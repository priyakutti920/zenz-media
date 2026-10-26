<?php
require_once '../app/db.php';
require_once '../app/security.php';
require_once '../app/Mailer.php';
Security::init($pdo);
Security::initSecureSession();

if (!Security::isAuthenticated()) {
    header('Location: ../admin');
    exit();
}

$adminId = (int)($_SESSION['admin_id'] ?? 1);
$canView = Security::hasPermission('projects.view', $adminId) || Security::hasPermission('projects.manage', $adminId);
if (!$canView) {
    Security::requirePermission('projects.view', $adminId);
}
$canManage = Security::hasPermission('projects.manage', $adminId);

$pageTitle = 'Projects';
$currentPage = 'project';
$message = '';
$messageType = '';
$statuses = [
    'submitted' => 'Submitted',
    'under_review' => 'Under Review',
    'changes_requested' => 'Changes Requested',
    'approved' => 'Approved',
    'on_hold' => 'On Hold',
    'completed' => 'Completed',
    'rejected' => 'Rejected'
];

// Projects schema is maintained via migrations

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$canManage) {
        $message = 'Access Denied: You do not have permission to manage projects.';
        $messageType = 'error';
    } elseif (!isset($_POST['csrf_token']) || !Security::validateCSRFToken($_POST['csrf_token'], 'projects')) {
        $message = 'Invalid security token. Refresh and try again.';
        $messageType = 'error';
    } elseif (isset($_POST['receive_project'])) {
        $employeeId = (int)($_POST['employee_id'] ?? 0);
        $title = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $driveLink = trim($_POST['drive_link'] ?? '');
        $urlParts = parse_url($driveLink);

        if (!$employeeId || !$title || !$driveLink) {
            $message = 'Employee, title, and Drive link are required.';
            $messageType = 'error';
        } elseif (!filter_var($driveLink, FILTER_VALIDATE_URL) || strtolower($urlParts['scheme'] ?? '') !== 'https') {
            $message = 'Enter a valid HTTPS Drive link.';
            $messageType = 'error';
        } else {
            try {
                $pdo->beginTransaction();
                $stmt = $pdo->prepare('INSERT INTO projects (employee_id, title, description, drive_link) VALUES (?, ?, ?, ?)');
                $stmt->execute([$employeeId, $title, $description ?: null, $driveLink]);
                $projectId = (int)$pdo->lastInsertId();
                $stmt = $pdo->prepare("INSERT INTO project_status_updates (project_id, status, note) VALUES (?, 'submitted', 'Project received')");
                $stmt->execute([$projectId]);
                $pdo->commit();
                $message = 'Project received successfully.';
                $messageType = 'success';

                // Send non-blocking project assignment email to employee
                try {
                    $empStmt = $pdo->prepare("SELECT first_name, last_name, email FROM employees WHERE id = ? LIMIT 1");
                    $empStmt->execute([$employeeId]);
                    $emp = $empStmt->fetch(PDO::FETCH_ASSOC);
                    if ($emp && !empty($emp['email'])) {
                        $mailer = new Mailer($pdo);
                        $empName = trim($emp['first_name'] . ' ' . $emp['last_name']);
                        $mailer->sendProjectAssignedAlert($emp['email'], $empName, $title, $driveLink, $description);
                    }
                } catch (Throwable $mailEx) {
                    error_log("Project assignment email error: " . $mailEx->getMessage());
                }
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log('Receive project: ' . $e->getMessage());
                $message = 'Could not save the project.';
                $messageType = 'error';
            }
        }
    } elseif (isset($_POST['update_status'])) {
        $projectId = (int)($_POST['project_id'] ?? 0);
        $status = $_POST['status'] ?? '';
        $note = trim($_POST['note'] ?? '');

        if (!$projectId || !isset($statuses[$status])) {
            $message = 'Invalid project status.';
            $messageType = 'error';
        } else {
            try {
                $pdo->beginTransaction();
                $stmt = $pdo->prepare('UPDATE projects SET status = ? WHERE id = ?');
                $stmt->execute([$status, $projectId]);
                $stmt = $pdo->prepare('INSERT INTO project_status_updates (project_id, status, note) VALUES (?, ?, ?)');
                $stmt->execute([$projectId, $status, $note ?: null]);
                $pdo->commit();
                $message = $status === 'approved' ? 'Project approved successfully.' : 'Project status updated.';
                $messageType = 'success';

                // Send non-blocking project review/status update email to employee
                try {
                    $projStmt = $pdo->prepare("SELECT p.title, e.first_name, e.last_name, e.email FROM projects p JOIN employees e ON e.id = p.employee_id WHERE p.id = ? LIMIT 1");
                    $projStmt->execute([$projectId]);
                    $projData = $projStmt->fetch(PDO::FETCH_ASSOC);
                    if ($projData && !empty($projData['email'])) {
                        $mailer = new Mailer($pdo);
                        $empName = trim($projData['first_name'] . ' ' . $projData['last_name']);
                        $mailer->sendProjectStatusAlert($projData['email'], $empName, $projData['title'], $status, $note);
                    }
                } catch (Throwable $mailEx) {
                    error_log("Project status update email error: " . $mailEx->getMessage());
                }
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log('Project status: ' . $e->getMessage());
                $message = 'Could not update the project.';
                $messageType = 'error';
            }
        }
    }
}

$employees = $projects = $updates = [];
$stats = ['total' => 0, 'submitted' => 0, 'under_review' => 0, 'approved' => 0];
try {
    $employees = $pdo->query('SELECT id, first_name, last_name FROM employees ORDER BY first_name, last_name')->fetchAll();
    $projects = $pdo->query('SELECT p.*, e.first_name, e.last_name, e.department, e.position FROM projects p JOIN employees e ON e.id = p.employee_id ORDER BY p.updated_at DESC')->fetchAll();
    foreach ($pdo->query('SELECT status, COUNT(*) total FROM projects GROUP BY status')->fetchAll() as $row) {
        $stats['total'] += (int)$row['total'];
        if (isset($stats[$row['status']])) $stats[$row['status']] = (int)$row['total'];
    }
    if ($projects) {
        $ids = array_column($projects, 'id');
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare("SELECT * FROM project_status_updates WHERE project_id IN ($marks) ORDER BY created_at DESC, id DESC");
        $stmt->execute($ids);
        foreach ($stmt->fetchAll() as $row) $updates[$row['project_id']][] = $row;
    }
} catch (PDOException $e) {
    error_log('Load projects: ' . $e->getMessage());
}

$csrfToken = Security::generateCSRFToken('projects');
include 'header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Employee Projects</h1>
        <p class="page-subtitle">Review submitted projects, view Drive links, approve work, and manage status updates.</p>
    </div>
</div>

<?php if ($message): ?>
<div class="alert alert-<?php echo $messageType; ?>"><?php echo htmlspecialchars($message); ?></div>
<?php endif; ?>

<div class="project-stats">
    <div><span>Total</span><strong><?php echo $stats['total']; ?></strong></div>
    <div><span>Submitted</span><strong><?php echo $stats['submitted']; ?></strong></div>
    <div><span>Under Review</span><strong><?php echo $stats['under_review']; ?></strong></div>
    <div><span>Approved</span><strong><?php echo $stats['approved']; ?></strong></div>
</div>

<div class="project-list">
<?php if (!$projects): ?>
    <div class="empty-projects"><h2>No projects found</h2><p>Projects submitted by employees will appear here.</p></div>
<?php endif; ?>
<?php foreach ($projects as $project): ?>
    <article class="project-card">
        <div class="project-head">
            <span class="status status-<?php echo htmlspecialchars($project['status']); ?>"><?php echo htmlspecialchars($statuses[$project['status']] ?? $project['status']); ?></span>
            <small><?php echo date('M j, Y', strtotime($project['submitted_at'])); ?></small>
        </div>
        <h2><?php echo htmlspecialchars($project['title']); ?></h2>
        <p><?php echo nl2br(htmlspecialchars($project['description'] ?: 'No description provided.')); ?></p>
        <div class="employee-name"><?php echo htmlspecialchars($project['first_name'] . ' ' . $project['last_name']); ?> <small><?php echo htmlspecialchars($project['department'] ?: ($project['position'] ?: 'Employee')); ?></small></div>
        <a class="drive-link" href="<?php echo htmlspecialchars($project['drive_link']); ?>" target="_blank" rel="noopener noreferrer">View Drive Project ↗</a>
        <form method="post" class="status-form">
            <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
            <input type="hidden" name="project_id" value="<?php echo $project['id']; ?>">
            <select name="status"><?php foreach ($statuses as $value => $label): ?><option value="<?php echo $value; ?>" <?php echo $project['status'] === $value ? 'selected' : ''; ?>><?php echo $label; ?></option><?php endforeach; ?></select>
            <input name="note" placeholder="Add review note or feedback">
            <button class="project-btn primary" name="update_status">Save Status</button>
        </form>
        <?php if (!empty($updates[$project['id']])): ?>
        <details><summary>Status history (<?php echo count($updates[$project['id']]); ?>)</summary>
            <?php foreach ($updates[$project['id']] as $update): ?>
            <div class="history-row"><strong><?php echo htmlspecialchars($statuses[$update['status']] ?? $update['status']); ?></strong><small><?php echo date('M j, Y g:i A', strtotime($update['created_at'])); ?></small><?php if ($update['note']): ?><p><?php echo htmlspecialchars($update['note']); ?></p><?php endif; ?></div>
            <?php endforeach; ?>
        </details>
        <?php endif; ?>
    </article>
<?php endforeach; ?>
</div>

<style>
.project-stats{display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:24px}.project-stats>div,.receive-card,.project-card,.empty-projects{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius-xl);padding:20px}.project-stats span{display:block;color:var(--text-secondary);font-size:13px}.project-stats strong{display:block;margin-top:6px;font-size:28px}.receive-card{margin-bottom:24px}.receive-card h2{margin:0 0 18px}.receive-form{display:grid;grid-template-columns:repeat(3,1fr);gap:14px}.receive-form label{font-size:13px;font-weight:600;color:var(--text-secondary)}.receive-form input,.receive-form select,.receive-form textarea,.status-form input,.status-form select{box-sizing:border-box;width:100%;margin-top:7px;padding:11px;border:1px solid var(--border);border-radius:var(--radius-lg);background:var(--bg-secondary);color:var(--text-primary);font:inherit}.receive-form .wide{grid-column:1/-1}.project-btn{padding:11px 17px;border:0;border-radius:var(--radius-lg);font-weight:600;cursor:pointer}.project-btn.primary{background:var(--primary-500);color:#fff}.project-list{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:20px}.project-card h2{margin:16px 0 8px}.project-card>p{min-height:42px;color:var(--text-secondary);font-size:14px}.project-head{display:flex;justify-content:space-between;align-items:center}.project-head small{color:var(--text-tertiary)}.status{padding:6px 10px;border-radius:999px;font-size:12px;font-weight:700;background:var(--bg-secondary)}.status-submitted{background:#fef3c7;color:#92400e}.status-under_review{background:#ede9fe;color:#5b21b6}.status-approved,.status-completed{background:#d1fae5;color:#065f46}.status-changes_requested,.status-rejected{background:#fee2e2;color:#991b1b}.employee-name{padding:13px 0;border-top:1px solid var(--border);font-weight:600}.employee-name small{display:block;margin-top:3px;color:var(--text-tertiary);font-weight:400}.drive-link{display:block;margin-bottom:15px;padding:11px;text-align:center;border-radius:var(--radius-lg);background:#f0fdf4;color:#166534;font-weight:700;text-decoration:none}.status-form{display:grid;grid-template-columns:1fr 1.5fr auto;gap:10px;padding:14px;background:var(--bg-secondary);border-radius:var(--radius-lg)}.status-form input,.status-form select{margin:0;background:var(--surface)}details{margin-top:15px;border-top:1px solid var(--border);padding-top:13px}summary{color:var(--primary-500);cursor:pointer;font-size:13px;font-weight:600}.history-row{padding:10px 0;border-bottom:1px solid var(--border)}.history-row strong,.history-row small{display:block}.history-row small{color:var(--text-tertiary);font-size:11px}.history-row p{margin:5px 0 0;color:var(--text-secondary);font-size:13px}.empty-projects{grid-column:1/-1;text-align:center}
@media(max-width:900px){.project-stats{grid-template-columns:repeat(2,1fr)}.receive-form,.project-list{grid-template-columns:1fr}.status-form{grid-template-columns:1fr}}@media(max-width:500px){.project-stats{grid-template-columns:1fr}}
[data-theme="dark"] .drive-link{background:rgba(22,163,74,.12);color:#86efac}
</style>

<?php include 'footer.php'; ?>
