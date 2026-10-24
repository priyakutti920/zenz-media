<?php
// ============================================================================
// ATTENDANCE REQUESTS APPROVAL CONSOLE (HR & CEO)
// Review, Schedule, Approve, or Reject WFH & On-Site Location Exceptions
// ============================================================================

date_default_timezone_set('Asia/Kolkata');

require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/security.php';
require_once __DIR__ . '/../app/AttendanceHelper.php';

Security::init($pdo);
Security::initSecureSession();

// Check if admin is logged in
if (!Security::isAuthenticated()) {
    header("Location: ../admin");
    exit();
}

$adminId = (int)($_SESSION['admin_id'] ?? 1);
Security::requirePermission('attendance.view', $adminId, '../admin/dashboard');

$canManage = Security::hasPermission('attendance.manage', $adminId) 
          || Security::hasPermission('attendance.approve_requests', $adminId)
          || Security::hasPermission('settings.manage', $adminId);

$pageTitle = 'WFH & Location Exception Approvals';
$currentPage = 'attendance_requests';

// Consume PRG Flash Messages
$flashMessage = $_SESSION['att_req_flash'] ?? '';
$flashType = $_SESSION['att_req_flash_type'] ?? '';
unset($_SESSION['att_req_flash'], $_SESSION['att_req_flash_type']);

// Handle Approval / Rejection POST Actions
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!isset($_POST['csrf_token']) || !Security::validateCSRFToken($_POST['csrf_token'], 'attendance_approval_action')) {
        $_SESSION['att_req_flash'] = "Security token mismatch or session expired. Please try again.";
        $_SESSION['att_req_flash_type'] = "error";
        header("Location: attendance_requests");
        exit();
    }

    if (!$canManage) {
        $_SESSION['att_req_flash'] = "Access Denied: You do not possess authorization to approve attendance requests.";
        $_SESSION['att_req_flash_type'] = "error";
        header("Location: attendance_requests");
        exit();
    }

    $action = $_POST['action'] ?? '';
    $requestId = (int)($_POST['request_id'] ?? 0);

    if ($action === 'approve') {
        $approvedTime = trim($_POST['approved_check_in_time'] ?? '');
        $resResult = AttendanceHelper::approveAttendanceRequest($pdo, $requestId, $adminId, $approvedTime);

        if ($resResult['success']) {
            Security::logAudit($adminId, 'approve_attendance_request', 'attendance_requests', $requestId, ['approved_time' => $approvedTime]);
            $_SESSION['att_req_flash'] = $resResult['message'];
            $_SESSION['att_req_flash_type'] = "success";
        } else {
            $_SESSION['att_req_flash'] = $resResult['error'];
            $_SESSION['att_req_flash_type'] = "error";
        }
    } elseif ($action === 'reject') {
        $rejectionReason = trim($_POST['rejection_reason'] ?? '');
        $resResult = AttendanceHelper::rejectAttendanceRequest($pdo, $requestId, $adminId, $rejectionReason);

        if ($resResult['success']) {
            Security::logAudit($adminId, 'reject_attendance_request', 'attendance_requests', $requestId, ['reason' => $rejectionReason]);
            $_SESSION['att_req_flash'] = $resResult['message'];
            $_SESSION['att_req_flash_type'] = "success";
        } else {
            $_SESSION['att_req_flash'] = $resResult['error'];
            $_SESSION['att_req_flash_type'] = "error";
        }
    }

    header("Location: attendance_requests");
    exit();
}

// Filters & Query Parameters
$statusFilter = $_GET['status'] ?? 'pending';
$dateFilter = $_GET['date'] ?? '';
$searchFilter = trim($_GET['search'] ?? '');

$sql = "SELECT ar.*, 
               e.first_name, e.last_name, e.employee_id as emp_code, e.department, e.position, e.avatar,
               a.username as approver_name
        FROM attendance_requests ar
        JOIN employees e ON ar.employee_id = e.id
        LEFT JOIN admin a ON ar.approver_id = a.id
        WHERE 1=1";
$params = [];

if ($statusFilter !== 'all') {
    $sql .= " AND ar.status = ?";
    $params[] = $statusFilter;
}
if (!empty($dateFilter)) {
    $sql .= " AND ar.attendance_date = ?";
    $params[] = $dateFilter;
}
if (!empty($searchFilter)) {
    $sql .= " AND (e.first_name LIKE ? OR e.last_name LIKE ? OR e.employee_id LIKE ? OR ar.reason LIKE ?)";
    $like = "%{$searchFilter}%";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

$sql .= " ORDER BY CASE WHEN ar.status = 'pending' THEN 1 ELSE 2 END, ar.attendance_date DESC, ar.id DESC";

$requests = [];
try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error querying attendance requests: " . $e->getMessage());
}

// Calculate Summary Statistics
$statPending = 0;
$statApproved = 0;
$statRejected = 0;
$statTotal = 0;
try {
    $st = $pdo->query("SELECT status, COUNT(*) as c FROM attendance_requests GROUP BY status");
    while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
        if ($r['status'] === 'pending') $statPending = (int)$r['c'];
        elseif ($r['status'] === 'approved' || $r['status'] === 'checked_in') $statApproved += (int)$r['c'];
        elseif ($r['status'] === 'rejected') $statRejected = (int)$r['c'];
        $statTotal += (int)$r['c'];
    }
} catch (PDOException $e) {}

$csrfToken = Security::generateCSRFToken('attendance_approval_action');

include 'header.php';
?>

<style>
.req-stats-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 16px;
    margin-bottom: 24px;
}
@media (max-width: 900px) {
    .req-stats-grid { grid-template-columns: repeat(2, 1fr); }
}
@media (max-width: 500px) {
    .req-stats-grid { grid-template-columns: 1fr; }
}

.req-stat-card {
    background: var(--adm-surface);
    border: 1px solid var(--adm-border);
    border-radius: var(--adm-radius-lg);
    box-shadow: var(--adm-shadow-card);
    padding: 18px 20px;
    display: flex;
    align-items: center;
    gap: 16px;
    text-decoration: none;
    color: inherit;
    transition: all var(--adm-transition);
}
.req-stat-card:hover {
    transform: translateY(-2px);
    box-shadow: var(--adm-shadow-hover);
}
.req-stat-card.active-stat {
    border-color: var(--adm-brand);
    background: var(--adm-brand-light);
}

.stat-icon-wrap {
    width: 46px;
    height: 46px;
    border-radius: var(--adm-radius-md);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.25rem;
    flex-shrink: 0;
}
.icon-pending  { background: rgba(217, 119, 6, 0.12); color: #d97706; }
.icon-approved { background: rgba(5, 150, 105, 0.12); color: #059669; }
.icon-rejected { background: rgba(225, 29, 72, 0.12); color: #e11d48; }
.icon-all      { background: var(--adm-brand-light); color: var(--adm-brand); }

.stat-number {
    font-family: 'Outfit', sans-serif;
    font-size: 1.7rem;
    font-weight: 800;
    color: var(--adm-text-main);
    line-height: 1.1;
}
.stat-label {
    font-size: 0.78rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: var(--adm-muted);
}

/* ── Filter Toolbar ── */
.filter-card {
    background: var(--adm-surface);
    border: 1px solid var(--adm-border);
    border-radius: var(--adm-radius-lg);
    box-shadow: var(--adm-shadow-card);
    padding: 16px 20px;
    margin-bottom: 24px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 14px;
}
.filter-tabs {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}
.filter-tab-btn {
    padding: 6px 14px;
    border-radius: var(--adm-radius-pill);
    font-size: 0.82rem;
    font-weight: 700;
    text-decoration: none;
    border: 1px solid var(--adm-border-subtle);
    background: var(--adm-surface-subtle);
    color: var(--adm-text-body);
    transition: all var(--adm-transition);
}
.filter-tab-btn.active {
    background: var(--adm-brand);
    color: #ffffff;
    border-color: var(--adm-brand);
}

/* ── Table & Cards ── */
.requests-card {
    background: var(--adm-surface);
    border: 1px solid var(--adm-border);
    border-radius: var(--adm-radius-lg);
    box-shadow: var(--adm-shadow-card);
    overflow: hidden;
}
.table-wrap {
    width: 100%;
    overflow-x: auto;
}
.req-table {
    width: 100%;
    border-collapse: collapse;
    text-align: left;
}
.req-table th {
    padding: 14px 20px;
    font-size: 0.78rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--adm-muted);
    background: var(--adm-surface-subtle);
    border-bottom: 1px solid var(--adm-border);
    white-space: nowrap;
}
.req-table td {
    padding: 16px 20px;
    font-size: 0.9rem;
    border-bottom: 1px solid var(--adm-border-subtle);
    color: var(--adm-text-main);
    vertical-align: middle;
}
.req-table tbody tr:hover {
    background: var(--adm-surface-subtle);
}
.req-table tr:last-child td { border-bottom: none; }

.badge-mode {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 4px 10px;
    border-radius: var(--adm-radius-pill);
    font-size: 0.76rem;
    font-weight: 700;
    white-space: nowrap;
}
.mode-wfh {
    background: var(--adm-brand-light);
    color: var(--adm-brand);
    border: 1px solid var(--adm-brand-border);
}
.mode-exception {
    background: rgba(217, 119, 6, 0.12);
    color: #d97706;
    border: 1px solid rgba(217, 119, 6, 0.3);
}

.badge-status-req {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 4px 10px;
    border-radius: var(--adm-radius-pill);
    font-size: 0.76rem;
    font-weight: 700;
    text-transform: capitalize;
    white-space: nowrap;
}
.st-pending   { background: rgba(217, 119, 6, 0.12); color: #d97706; border: 1px solid rgba(217, 119, 6, 0.3); }
.st-approved  { background: rgba(5, 150, 105, 0.12); color: #059669; border: 1px solid rgba(5, 150, 105, 0.3); }
.st-checked_in{ background: var(--adm-brand-light); color: var(--adm-brand); border: 1px solid var(--adm-brand-border); }
.st-rejected  { background: rgba(225, 29, 72, 0.12); color: #e11d48; border: 1px solid rgba(225, 29, 72, 0.3); }
.st-cancelled { background: var(--adm-surface-subtle); color: var(--adm-muted); border: 1px solid var(--adm-border); }

/* Action Buttons */
.btn-approve {
    padding: 6px 14px;
    border-radius: var(--adm-radius-pill);
    background: #059669;
    color: #ffffff;
    font-size: 0.8rem;
    font-weight: 700;
    border: none;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: all var(--adm-transition);
}
.btn-approve:hover { background: #047857; transform: translateY(-1px); }

.btn-reject {
    padding: 6px 14px;
    border-radius: var(--adm-radius-pill);
    background: var(--adm-surface);
    border: 1px solid #e11d48;
    color: #e11d48;
    font-size: 0.8rem;
    font-weight: 700;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: all var(--adm-transition);
}
.btn-reject:hover { background: rgba(225, 29, 72, 0.08); }

/* Modal Dialogs */
.modal-overlay {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(9, 13, 22, 0.72);
    backdrop-filter: blur(6px);
    z-index: 10000;
    align-items: center;
    justify-content: center;
    padding: 16px;
}
.modal-box {
    background: var(--adm-surface);
    border: 1px solid var(--adm-border);
    border-radius: var(--adm-radius-lg);
    box-shadow: var(--adm-shadow-hover);
    max-width: 500px;
    width: 100%;
    padding: 24px;
    animation: popIn 0.2s ease;
}
@keyframes popIn {
    from { transform: scale(0.95); opacity: 0; }
    to { transform: scale(1); opacity: 1; }
}
</style>

<div class="content" style="max-width: 1360px; margin: 0 auto; padding: 24px 20px 60px;">

    <!-- Breadcrumb & Header Ribbon -->
    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 14px; margin-bottom: 24px;">
        <div>
            <div style="font-size: 0.82rem; color: var(--adm-muted); margin-bottom: 4px; display: flex; align-items: center; gap: 6px;">
                <a href="dashboard" style="color: inherit; text-decoration: none;">Admin</a>
                <span>&rsaquo;</span>
                <a href="attendance" style="color: inherit; text-decoration: none;">Attendance</a>
                <span>&rsaquo;</span>
                <span style="color: var(--adm-brand); font-weight: 700;">WFH &amp; Location Approvals</span>
            </div>
            <h1 style="font-family: 'Outfit', sans-serif; font-size: 1.8rem; font-weight: 800; color: var(--adm-text-main); letter-spacing: -0.02em;">
                WFH &amp; Attendance Location Exception Approvals
            </h1>
        </div>

        <div style="display: flex; gap: 10px;">
            <a href="attendance" class="btn-saas" style="padding: 8px 16px; border-radius: var(--adm-radius-pill); background: var(--adm-surface); border: 1px solid var(--adm-border); color: var(--adm-text-body); font-size: 0.85rem; font-weight: 600; text-decoration: none; display: inline-flex; align-items: center; gap: 6px;">
                <i class="fa-solid fa-calendar-check"></i>
                <span>Attendance Log</span>
            </a>
            <a href="office_location" class="btn-saas" style="padding: 8px 16px; border-radius: var(--adm-radius-pill); background: var(--adm-brand-light); border: 1px solid var(--adm-brand-border); color: var(--adm-brand); font-size: 0.85rem; font-weight: 700; text-decoration: none; display: inline-flex; align-items: center; gap: 6px;">
                <i class="fa-solid fa-location-dot"></i>
                <span>Office GPS Settings</span>
            </a>
        </div>
    </div>

    <!-- Feedback Toast -->
    <?php if (!empty($flashMessage)): ?>
        <div class="alert-saas alert-<?php echo ($flashType === 'success') ? 'success' : 'danger'; ?>" style="margin-bottom: 20px; padding: 14px 18px; border-radius: var(--adm-radius-md); font-weight: 600; display: flex; align-items: center; gap: 10px; background: <?php echo ($flashType === 'success') ? 'var(--adm-success-light)' : 'var(--adm-danger-light)'; ?>; color: <?php echo ($flashType === 'success') ? 'var(--adm-success)' : 'var(--adm-danger)'; ?>; border: 1px solid <?php echo ($flashType === 'success') ? 'var(--adm-success)' : 'var(--adm-danger-border)'; ?>;">
            <i class="fa-solid <?php echo ($flashType === 'success') ? 'fa-circle-check' : 'fa-circle-exclamation'; ?>"></i>
            <span><?php echo htmlspecialchars($flashMessage); ?></span>
        </div>
    <?php endif; ?>

    <!-- Summary KPI Stats Ribbon -->
    <div class="req-stats-grid">
        <a href="?status=pending" class="req-stat-card <?php echo $statusFilter === 'pending' ? 'active-stat' : ''; ?>">
            <div class="stat-icon-wrap icon-pending">
                <i class="fa-solid fa-hourglass-start"></i>
            </div>
            <div>
                <div class="stat-number" style="color: #d97706;"><?php echo $statPending; ?></div>
                <div class="stat-label">Pending Approval</div>
            </div>
        </a>

        <a href="?status=approved" class="req-stat-card <?php echo $statusFilter === 'approved' ? 'active-stat' : ''; ?>">
            <div class="stat-icon-wrap icon-approved">
                <i class="fa-solid fa-circle-check"></i>
            </div>
            <div>
                <div class="stat-number" style="color: #059669;"><?php echo $statApproved; ?></div>
                <div class="stat-label">Approved &amp; Scheduled</div>
            </div>
        </a>

        <a href="?status=rejected" class="req-stat-card <?php echo $statusFilter === 'rejected' ? 'active-stat' : ''; ?>">
            <div class="stat-icon-wrap icon-rejected">
                <i class="fa-solid fa-circle-xmark"></i>
            </div>
            <div>
                <div class="stat-number" style="color: #e11d48;"><?php echo $statRejected; ?></div>
                <div class="stat-label">Rejected</div>
            </div>
        </a>

        <a href="?status=all" class="req-stat-card <?php echo $statusFilter === 'all' ? 'active-stat' : ''; ?>">
            <div class="stat-icon-wrap icon-all">
                <i class="fa-solid fa-list-check"></i>
            </div>
            <div>
                <div class="stat-number"><?php echo $statTotal; ?></div>
                <div class="stat-label">Total Requests</div>
            </div>
        </a>
    </div>

    <!-- Filter & Search Toolbar -->
    <div class="filter-card">
        <div class="filter-tabs">
            <a href="?status=pending<?php echo !empty($dateFilter) ? '&date='.$dateFilter : ''; ?>" class="filter-tab-btn <?php echo $statusFilter === 'pending' ? 'active' : ''; ?>">
                Pending (<?php echo $statPending; ?>)
            </a>
            <a href="?status=approved<?php echo !empty($dateFilter) ? '&date='.$dateFilter : ''; ?>" class="filter-tab-btn <?php echo $statusFilter === 'approved' ? 'active' : ''; ?>">
                Approved (<?php echo $statApproved; ?>)
            </a>
            <a href="?status=rejected<?php echo !empty($dateFilter) ? '&date='.$dateFilter : ''; ?>" class="filter-tab-btn <?php echo $statusFilter === 'rejected' ? 'active' : ''; ?>">
                Rejected (<?php echo $statRejected; ?>)
            </a>
            <a href="?status=all<?php echo !empty($dateFilter) ? '&date='.$dateFilter : ''; ?>" class="filter-tab-btn <?php echo $statusFilter === 'all' ? 'active' : ''; ?>">
                All Requests
            </a>
        </div>

        <form method="GET" action="" style="display: flex; gap: 10px; flex-wrap: wrap;">
            <input type="hidden" name="status" value="<?php echo htmlspecialchars($statusFilter); ?>">
            <input type="date" name="date" value="<?php echo htmlspecialchars($dateFilter); ?>" class="form-control-saas" style="height: 36px; padding: 0 10px; font-size: 0.82rem; width: auto;" onchange="this.form.submit()">
            <input type="text" name="search" value="<?php echo htmlspecialchars($searchFilter); ?>" placeholder="Search employee / reason..." class="form-control-saas" style="height: 36px; padding: 0 12px; font-size: 0.82rem; width: 220px;">
            <button type="submit" class="btn-saas" style="height: 36px; padding: 0 14px; border-radius: var(--adm-radius-md); background: var(--adm-brand); color: #ffffff; border: none; font-size: 0.82rem; font-weight: 700; cursor: pointer;">
                <i class="fa-solid fa-magnifying-glass"></i>
            </button>
            <?php if (!empty($dateFilter) || !empty($searchFilter)): ?>
                <a href="?status=<?php echo htmlspecialchars($statusFilter); ?>" class="btn-saas" style="height: 36px; padding: 0 12px; border-radius: var(--adm-radius-md); background: var(--adm-surface-subtle); border: 1px solid var(--adm-border); color: var(--adm-muted); font-size: 0.82rem; font-weight: 600; text-decoration: none; display: flex; align-items: center;" title="Reset filters">
                    &times;
                </a>
            <?php endif; ?>
        </form>
    </div>

    <!-- Requests Table Card -->
    <div class="requests-card">
        <?php if (empty($requests)): ?>
            <div style="padding: 60px 24px; text-align: center; color: var(--adm-muted);">
                <i class="fa-solid fa-clipboard-check" style="font-size: 2.5rem; opacity: 0.35; margin-bottom: 12px;"></i>
                <p style="font-weight: 700; font-size: 1.1rem; color: var(--adm-text-main);">No attendance requests found</p>
                <p style="font-size: 0.85rem;">All pending WFH and location exception requests have been addressed.</p>
            </div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="req-table">
                    <thead>
                        <tr>
                            <th>Employee</th>
                            <th>Date</th>
                            <th>Requested Mode</th>
                            <th>Requested Time</th>
                            <th>Reason &amp; Location</th>
                            <th>Status &amp; Schedule</th>
                            <th>Approver Decision</th>
                            <th style="text-align: right;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($requests as $r): ?>
                            <tr>
                                <td>
                                    <div style="font-weight: 800; color: var(--adm-text-main);">
                                        <?php echo htmlspecialchars($r['first_name'] . ' ' . $r['last_name']); ?>
                                    </div>
                                    <div style="font-size: 0.78rem; color: var(--adm-muted);">
                                        <?php echo htmlspecialchars($r['emp_code'] ?: ('EMP-' . $r['employee_id'])); ?>
                                        &bull; <?php echo htmlspecialchars($r['department'] ?: 'General'); ?>
                                    </div>
                                </td>

                                <td style="font-weight: 700; white-space: nowrap;">
                                    <?php echo date('D, M j, Y', strtotime($r['attendance_date'])); ?>
                                </td>

                                <td>
                                    <?php if ($r['requested_work_mode'] === 'wfh'): ?>
                                        <span class="badge-mode mode-wfh">
                                            <i class="fa-solid fa-house-laptop"></i> Work From Home
                                        </span>
                                    <?php else: ?>
                                        <span class="badge-mode mode-exception">
                                            <i class="fa-solid fa-location-crosshairs"></i> On-Site Exception
                                        </span>
                                    <?php endif; ?>
                                </td>

                                <td style="font-weight: 800; font-family: monospace; font-size: 0.95rem;">
                                    <?php echo date('h:i A', strtotime($r['requested_check_in_time'])); ?>
                                </td>

                                <td style="max-width: 280px;">
                                    <div style="font-weight: 600; color: var(--adm-text-main); font-size: 0.86rem;">
                                        <?php echo htmlspecialchars($r['reason']); ?>
                                    </div>
                                    <?php if (!empty($r['supporting_details'])): ?>
                                        <div style="font-size: 0.76rem; color: var(--adm-muted); margin-top: 2px;">
                                            Note: <?php echo htmlspecialchars($r['supporting_details']); ?>
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($r['submitted_dist'] !== null): ?>
                                        <div style="font-size: 0.74rem; color: #d97706; margin-top: 3px; font-weight: 700;">
                                            <i class="fa-solid fa-location-dot"></i> Distance: <?php echo (int)$r['submitted_dist']; ?>m from office
                                        </div>
                                    <?php endif; ?>
                                </td>

                                <td>
                                    <span class="badge-status-req st-<?php echo htmlspecialchars($r['status']); ?>">
                                        <?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $r['status']))); ?>
                                    </span>
                                    <?php if ($r['status'] === 'approved' && !empty($r['approved_check_in_time'])): ?>
                                        <div style="font-size: 0.78rem; color: #059669; font-weight: 800; margin-top: 3px;">
                                            <i class="fa-solid fa-clock"></i> Scheduled: <?php echo date('h:i A', strtotime($r['approved_check_in_time'])); ?>
                                        </div>
                                    <?php elseif ($r['status'] === 'checked_in' && !empty($r['actual_check_in'])): ?>
                                        <div style="font-size: 0.78rem; color: var(--adm-brand); font-weight: 800; margin-top: 3px;">
                                            <i class="fa-solid fa-circle-check"></i> Punched: <?php echo date('h:i A', strtotime($r['actual_check_in'])); ?>
                                        </div>
                                    <?php elseif ($r['status'] === 'rejected' && !empty($r['rejection_reason'])): ?>
                                        <div style="font-size: 0.74rem; color: #e11d48; margin-top: 3px;">
                                            Reason: <?php echo htmlspecialchars($r['rejection_reason']); ?>
                                        </div>
                                    <?php endif; ?>
                                </td>

                                <td style="font-size: 0.8rem; color: var(--adm-muted);">
                                    <?php if (!empty($r['approver_name'])): ?>
                                        <div>By <strong><?php echo htmlspecialchars($r['approver_name']); ?></strong></div>
                                        <div style="font-size: 0.72rem;"><?php echo date('M d, h:i A', strtotime($r['decision_timestamp'])); ?></div>
                                    <?php else: ?>
                                        <span>&mdash;</span>
                                    <?php endif; ?>
                                </td>

                                <td style="text-align: right; white-space: nowrap;">
                                    <?php if ($r['status'] === 'pending'): ?>
                                        <?php if ($canManage): ?>
                                            <button type="button" class="btn-approve" onclick="openApproveModal(<?php echo (int)$r['id']; ?>, '<?php echo htmlspecialchars(addslashes($r['first_name'] . ' ' . $r['last_name'])); ?>', '<?php echo date('H:i', strtotime($r['requested_check_in_time'])); ?>', '<?php echo date('h:i A', strtotime($r['requested_check_in_time'])); ?>')">
                                                <i class="fa-solid fa-check"></i> Approve
                                            </button>
                                            <button type="button" class="btn-reject" onclick="openRejectModal(<?php echo (int)$r['id']; ?>, '<?php echo htmlspecialchars(addslashes($r['first_name'] . ' ' . $r['last_name'])); ?>')">
                                                <i class="fa-solid fa-xmark"></i> Reject
                                            </button>
                                        <?php else: ?>
                                            <span style="font-size:0.78rem; color:var(--adm-muted);">Awaiting review</span>
                                        <?php endif; ?>
                                    <?php elseif ($r['status'] === 'approved'): ?>
                                        <span style="font-size: 0.78rem; color: #059669; font-weight: 700;"><i class="fa-solid fa-check"></i> Authorized</span>
                                    <?php else: ?>
                                        <span style="font-size: 0.78rem; color: var(--adm-muted);">&mdash;</span>
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

<!-- ─────────────────────────────────────────────────────────────────────────── -->
<!-- MODAL: APPROVE & SCHEDULE CHECK-IN TIME                                    -->
<!-- ─────────────────────────────────────────────────────────────────────────── -->
<div class="modal-overlay" id="approveModal">
    <div class="modal-box">
        <form method="POST" action="">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
            <input type="hidden" name="action" value="approve">
            <input type="hidden" name="request_id" id="approve_request_id" value="">

            <h3 style="font-family:'Outfit', sans-serif; font-size:1.25rem; font-weight:800; color:var(--adm-text-main); margin-bottom:4px; display:flex; align-items:center; gap:8px;">
                <i class="fa-solid fa-circle-check" style="color:#059669;"></i>
                <span>Approve Attendance Request</span>
            </h3>
            <p style="font-size:0.84rem; color:var(--adm-muted); margin-bottom:18px;" id="approveModalSub">
                Confirm or reschedule the authorized check-in time for this employee.
            </p>

            <div style="background:var(--adm-surface-subtle); border:1px solid var(--adm-border-subtle); padding:12px 14px; border-radius:var(--adm-radius-md); margin-bottom:16px;">
                <div style="font-size:0.78rem; color:var(--adm-muted); font-weight:700; text-transform:uppercase;">Requested Time</div>
                <div style="font-size:1.1rem; font-weight:800; color:var(--adm-text-main);" id="approveModalReqTimeDisplay">10:00 AM</div>
            </div>

            <div style="margin-bottom:18px;">
                <label for="approved_check_in_time" style="display:block; font-size:0.84rem; font-weight:700; margin-bottom:6px; color:var(--adm-text-main);">
                    Approved Check-In Time <span style="color:#e11d48;">*</span>
                </label>
                <input type="time" id="approved_check_in_time" name="approved_check_in_time" class="form-control-saas" required style="font-size:1rem; font-weight:700;">
                <small style="font-size:0.75rem; color:var(--adm-muted); margin-top:4px; display:block;">
                    Employee will be permitted to punch in at this specific approved time (&plusmn; configured grace period).
                </small>
            </div>

            <div style="display:flex; justify-content:flex-end; gap:10px;">
                <button type="button" class="btn-saas" onclick="closeApproveModal()" style="padding:8px 16px; border-radius:var(--adm-radius-pill); background:var(--adm-surface); border:1px solid var(--adm-border); color:var(--adm-text-body); font-weight:600; cursor:pointer;">
                    Cancel
                </button>
                <button type="submit" class="btn-saas" style="padding:8px 20px; border-radius:var(--adm-radius-pill); background:#059669; color:#ffffff; border:none; font-weight:700; cursor:pointer;">
                    <i class="fa-solid fa-check"></i> Authorize &amp; Schedule
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ─────────────────────────────────────────────────────────────────────────── -->
<!-- MODAL: REJECT ATTENDANCE REQUEST                                           -->
<!-- ─────────────────────────────────────────────────────────────────────────── -->
<div class="modal-overlay" id="rejectModal">
    <div class="modal-box">
        <form method="POST" action="">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
            <input type="hidden" name="action" value="reject">
            <input type="hidden" name="request_id" id="reject_request_id" value="">

            <h3 style="font-family:'Outfit', sans-serif; font-size:1.25rem; font-weight:800; color:var(--adm-text-main); margin-bottom:4px; display:flex; align-items:center; gap:8px;">
                <i class="fa-solid fa-circle-xmark" style="color:#e11d48;"></i>
                <span>Reject Attendance Request</span>
            </h3>
            <p style="font-size:0.84rem; color:var(--adm-muted); margin-bottom:18px;" id="rejectModalSub">
                Please provide an explanation for rejecting this request.
            </p>

            <div style="margin-bottom:18px;">
                <label for="rejection_reason" style="display:block; font-size:0.84rem; font-weight:700; margin-bottom:6px; color:var(--adm-text-main);">
                    Rejection Reason <span style="color:#e11d48;">*</span>
                </label>
                <textarea id="rejection_reason" name="rejection_reason" rows="3" class="form-control-saas" style="height:auto; padding:10px 12px; line-height:1.5;" required placeholder="e.g. In-person presence required for client meeting, insufficient advance notice..."></textarea>
            </div>

            <div style="display:flex; justify-content:flex-end; gap:10px;">
                <button type="button" class="btn-saas" onclick="closeRejectModal()" style="padding:8px 16px; border-radius:var(--adm-radius-pill); background:var(--adm-surface); border:1px solid var(--adm-border); color:var(--adm-text-body); font-weight:600; cursor:pointer;">
                    Cancel
                </button>
                <button type="submit" class="btn-saas" style="padding:8px 20px; border-radius:var(--adm-radius-pill); background:#e11d48; color:#ffffff; border:none; font-weight:700; cursor:pointer;">
                    <i class="fa-solid fa-xmark"></i> Reject Request
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function openApproveModal(reqId, empName, rawTime, formattedTime) {
    document.getElementById('approve_request_id').value = reqId;
    document.getElementById('approveModalSub').textContent = 'Confirm or reschedule approved check-in time for ' + empName + '.';
    document.getElementById('approveModalReqTimeDisplay').textContent = formattedTime;
    document.getElementById('approved_check_in_time').value = rawTime;
    document.getElementById('approveModal').style.display = 'flex';
}
function closeApproveModal() {
    document.getElementById('approveModal').style.display = 'none';
}

function openRejectModal(reqId, empName) {
    document.getElementById('reject_request_id').value = reqId;
    document.getElementById('rejectModalSub').textContent = 'Enter the mandatory reason for declining ' + empName + '\'s request.';
    document.getElementById('rejection_reason').value = '';
    document.getElementById('rejectModal').style.display = 'flex';
}
function closeRejectModal() {
    document.getElementById('rejectModal').style.display = 'none';
}

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeApproveModal();
        closeRejectModal();
    }
});
</script>

<?php include 'footer.php'; ?>
