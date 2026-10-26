<?php
// Include database connection first
require_once '../app/db.php';
require_once '../app/security.php';
require_once '../app/LeaveHelper.php';
Security::init($pdo);
Security::initSecureSession();

// Check if admin is logged in
if (!Security::isAuthenticated()) {
    header("Location: ../admin");
    exit();
}

$adminId = (int)($_SESSION['admin_id'] ?? 1);
$canView = Security::hasPermission('leaves.view', $adminId) || Security::hasPermission('leaves.manage', $adminId);
if (!$canView) {
    Security::requirePermission('leaves.view', $adminId);
}
$canManage = Security::hasPermission('leaves.manage', $adminId);

$pageTitle = 'Leave Management';
$currentPage = 'leave';

// Initialize variables
$message = '';
$messageType = '';

// Handle Actions via POST (CSRF protected & RBAC gated)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!$canManage) {
        $message = "Access Denied: You do not have permission to manage leave requests.";
        $messageType = "error";
    } elseif (!isset($_POST['csrf_token']) || !Security::validateCSRFToken($_POST['csrf_token'], 'leave_action')) {
        $message = "Invalid security token. Please refresh and try again.";
        $messageType = "error";
    } else {
        $action = $_POST['action'];
        $leaveId = (int)($_POST['id'] ?? 0);
        
        if ($action === 'approve') {
            $res = LeaveHelper::approveLeave($pdo, $leaveId, $adminId);
            $message = $res['message'];
            $messageType = $res['success'] ? 'success' : 'error';
        } elseif ($action === 'reject') {
            $rejectionReason = trim($_POST['rejection_reason'] ?? '');
            $res = LeaveHelper::rejectLeave($pdo, $leaveId, $adminId, $rejectionReason);
            $message = $res['message'];
            $messageType = $res['success'] ? 'success' : 'error';
        } elseif ($action === 'delete') {
            $res = LeaveHelper::deleteLeave($pdo, $leaveId, $adminId);
            $message = $res['message'];
            $messageType = $res['success'] ? 'success' : 'error';
        } elseif ($action === 'delete_code') {
            try {
                $stmt = $pdo->prepare("UPDATE leave_requests SET leave_code = NULL WHERE id = ?");
                $stmt->execute([$leaveId]);
                $message = "Leave code deleted successfully.";
                $messageType = 'success';
            } catch (PDOException $e) {
                error_log("Error deleting leave code: " . $e->getMessage());
                $message = "Error deleting leave code. Please try again.";
                $messageType = 'error';
            }
        } elseif ($action === 'mark_used') {
            try {
                $stmt = $pdo->prepare("SELECT leave_code FROM leave_requests WHERE id = ?");
                $stmt->execute([$leaveId]);
                $leaveCode = $stmt->fetchColumn();
                
                if ($leaveCode) {
                    $histStmt = $pdo->prepare("INSERT INTO leave_code_history (leave_id, leave_code, action, performed_by) VALUES (?, ?, 'used', ?)");
                    $histStmt->execute([$leaveId, $leaveCode, $adminId]);
                    $message = "Leave code marked as used.";
                    $messageType = 'success';
                } else {
                    $message = "No leave code found to mark as used.";
                    $messageType = 'error';
                }
            } catch (PDOException $e) {
                error_log("Error marking code as used: " . $e->getMessage());
                $message = "Error marking leave code as used. Please try again.";
                $messageType = 'error';
            }
        } elseif ($action === 'generate_leave') {
            $employeeId = (int)($_POST['employee_id'] ?? 0);
            $leaveType  = $_POST['leave_type'] ?? 'casual';
            $startDate  = $_POST['start_date'] ?? '';
            $endDate    = $_POST['end_date'] ?? '';
            $reason     = trim($_POST['reason'] ?? '');

            $res = LeaveHelper::generateLeave($pdo, $adminId, $employeeId, $leaveType, $startDate, $endDate, $reason);
            $message = $res['message'];
            $messageType = $res['success'] ? 'success' : 'error';
        }
    }
    
    // Redirect to prevent form resubmission
    header("Location: leave?msg=" . urlencode($message) . "&type=" . $messageType);
    exit();
}

$csrfToken = Security::generateCSRFToken('leave_action');

// Fetch all employees for dropdown select options
try {
    $stmt = $pdo->query("SELECT id, first_name, last_name, department FROM employees ORDER BY first_name ASC");
    $employeesList = $stmt->fetchAll();
} catch (PDOException $e) {
    $employeesList = [];
}

if (isset($_GET['msg'])) {
    $message = $_GET['msg'];
    $messageType = $_GET['type'] ?? 'info';
}

// Fetch general stats
$stats = ['total' => 0, 'pending' => 0, 'approved' => 0, 'rejected' => 0];
try {
    $stmt = $pdo->query("SELECT status, COUNT(*) as count FROM leave_requests GROUP BY status");
    $rawStats = $stmt->fetchAll();
    foreach ($rawStats as $row) {
        $stats['total'] += $row['count'];
        if ($row['status'] === 'pending') $stats['pending'] += $row['count'];
        if ($row['status'] === 'approved') $stats['approved'] += $row['count'];
        if ($row['status'] === 'rejected') $stats['rejected'] += $row['count'];
    }
} catch (PDOException $e) {
    // Fail silently
}

// Fetch all leave requests
try {
    $sql = "
        SELECT lr.*, e.first_name, e.last_name, e.department, e.position 
        FROM leave_requests lr
        JOIN employees e ON lr.employee_id = e.id
        ORDER BY lr.created_at DESC
    ";
    $stmt = $pdo->query($sql);
    $leaves = $stmt->fetchAll();
} catch (PDOException $e) {
    $leaves = [];
    $message = 'Error loading leave requests: ' . $e->getMessage();
    $messageType = 'error';
}
?>

<?php include 'header.php'; ?>

<!-- Page Header -->
<div class="page-header">
    <div>
        <h1 class="page-title">Leave Management</h1>
        <p class="page-subtitle">Review, approve, and manage employee leave requests</p>
    </div>
    <button class="btn btn-primary" onclick="openModal('generateLeaveModal')">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <line x1="12" y1="5" x2="12" y2="19"></line>
            <line x1="5" y1="12" x2="19" y2="12"></line>
        </svg>
        Generate Leave
    </button>
</div>

<!-- Alert Messages -->
<?php if ($message): ?>
<div class="alert alert-<?php echo $messageType; ?>">
    <?php if ($messageType === 'success'): ?>
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path>
            <polyline points="22 4 12 14.01 9 11.01"></polyline>
        </svg>
    <?php else: ?>
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <circle cx="12" cy="12" r="10"></circle>
            <line x1="12" y1="8" x2="12" y2="12"></line>
            <line x1="12" y1="16" x2="12.01" y2="16"></line>
        </svg>
    <?php endif; ?>
    <span><?php echo htmlspecialchars($message); ?></span>
</div>
<?php endif; ?>

<!-- Stats Cards -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon-wrapper total">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
                <line x1="16" y1="2" x2="16" y2="6"></line>
                <line x1="8" y1="2" x2="8" y2="6"></line>
                <line x1="3" y1="10" x2="21" y2="10"></line>
            </svg>
        </div>
        <div class="stat-details">
            <h3 class="stat-value"><?php echo $stats['total']; ?></h3>
            <p class="stat-label">Total Requests</p>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon-wrapper pending">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <circle cx="12" cy="12" r="10"></circle>
                <polyline points="12 6 12 12 16 14"></polyline>
            </svg>
        </div>
        <div class="stat-details">
            <h3 class="stat-value"><?php echo $stats['pending']; ?></h3>
            <p class="stat-label">Pending</p>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon-wrapper active">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path>
                <polyline points="22 4 12 14.01 9 11.01"></polyline>
            </svg>
        </div>
        <div class="stat-details">
            <h3 class="stat-value"><?php echo $stats['approved']; ?></h3>
            <p class="stat-label">Approved</p>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon-wrapper inactive">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <circle cx="12" cy="12" r="10"></circle>
                <line x1="15" y1="9" x2="9" y2="15"></line>
                <line x1="9" y1="9" x2="15" y2="15"></line>
            </svg>
        </div>
        <div class="stat-details">
            <h3 class="stat-value"><?php echo $stats['rejected']; ?></h3>
            <p class="stat-label">Rejected</p>
        </div>
    </div>
</div>

<!-- Leave Requests Grid -->
<?php if (empty($leaves)): ?>
<div class="empty-state">
    <div class="empty-state-icon">
        <svg width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
            <circle cx="12" cy="7" r="4"></circle>
        </svg>
    </div>
    <h3>No leave requests found</h3>
    <p>When employees submit leave requests, they will appear here.</p>
</div>
<?php else: ?>
<div class="task-grid">
    <?php foreach ($leaves as $leave): 
        $empInitials = strtoupper(substr($leave['first_name'], 0, 1) . substr($leave['last_name'], 0, 1));
        $formattedStartDate = date('M d, Y', strtotime($leave['start_date']));
        $formattedEndDate = date('M d, Y', strtotime($leave['end_date']));
    ?>
    <div class="task-card">
        <div class="task-card-header">
            <span class="badge" style="background: var(--bg-tertiary); color: var(--text-secondary); border: 1px solid var(--border-color);">
                <?php echo ucfirst(str_replace('_', ' ', $leave['leave_type'])); ?>
            </span>
            
            <span class="badge status-badge status-<?php echo $leave['status']; ?>">
                <?php echo ucfirst($leave['status']); ?>
            </span>
        </div>

        <div class="task-card-body">
            <h3 class="task-title" style="margin-bottom: 8px;">
                <?php echo $leave['days']; ?> Day(s) Leave
            </h3>
            
            <div class="task-meta" style="margin-bottom: 12px;">
                <div class="meta-item date-item">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
                        <line x1="16" y1="2" x2="16" y2="6"></line>
                        <line x1="8" y1="2" x2="8" y2="6"></line>
                        <line x1="3" y1="10" x2="21" y2="10"></line>
                    </svg>
                    <span><?php echo $formattedStartDate; ?> - <?php echo $formattedEndDate; ?></span>
                </div>
            </div>

            <p class="task-description" style="font-size: 13px;">
                <strong>Reason:</strong> <?php echo nl2br(htmlspecialchars($leave['reason'])); ?>
            </p>

            <?php if ($leave['status'] === 'rejected' && !empty($leave['rejection_reason'])): ?>
                <div style="margin-top: 8px; padding: 8px 12px; background: rgba(239, 68, 68, 0.08); border-left: 3px solid #ef4444; border-radius: 4px; font-size: 12px; color: #b91c1c;">
                    <strong>Declined Reason:</strong> <?php echo htmlspecialchars($leave['rejection_reason']); ?>
                </div>
            <?php endif; ?>
            
            <?php if ($leave['status'] === 'approved' && $leave['leave_code']): 
                $isActive = (strtotime($leave['end_date']) >= strtotime(date('Y-m-d')));
                $codeStatusColor = $isActive ? 'var(--success-color)' : 'var(--text-tertiary)';
                $codeStatusBg = $isActive ? 'rgba(16, 185, 129, 0.1)' : 'rgba(107, 114, 128, 0.1)';
                $codeStatusLabel = $isActive ? 'Active' : 'Expired';
                
                // Fetch usage history
                $histStmt = $pdo->prepare("SELECT created_at FROM leave_code_history WHERE leave_id = ? ORDER BY created_at DESC");
                $histStmt->execute([$leave['id']]);
                $usageHistory = $histStmt->fetchAll();
            ?>
            <div style="margin-top: 15px; padding: 10px; background: <?php echo $codeStatusBg; ?>; border-radius: 6px; border: 1px dashed <?php echo $codeStatusColor; ?>; text-align: center; position: relative;">
                <a href="javascript:void(0)" onclick="if(confirm('Are you sure you want to delete this leave code?')) submitLeaveAction('delete_code', <?php echo $leave['id']; ?>)" style="position: absolute; top: 6px; left: 6px; color: var(--text-tertiary);" title="Delete Code">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <line x1="18" y1="6" x2="6" y2="18"></line>
                        <line x1="6" y1="6" x2="18" y2="18"></line>
                    </svg>
                </a>
                <span style="position: absolute; top: -10px; right: 10px; background: var(--surface); color: <?php echo $codeStatusColor; ?>; font-size: 10px; font-weight: 700; padding: 2px 6px; border-radius: 4px; border: 1px solid <?php echo $codeStatusColor; ?>; text-transform: uppercase;">
                    <?php echo $codeStatusLabel; ?>
                </span>
                <span style="font-size: 12px; color: var(--text-secondary); display: block; margin-bottom: 4px;">Leave Code</span>
                <strong style="font-size: 16px; color: <?php echo $codeStatusColor; ?>; letter-spacing: 1px; font-family: monospace;"><?php echo $leave['leave_code']; ?></strong>
                
                <div style="margin-top: 10px; padding-top: 10px; border-top: 1px dashed rgba(0,0,0,0.1);">
                    <button type="button" onclick="submitLeaveAction('mark_used', <?php echo $leave['id']; ?>)" class="btn-action" style="background: var(--primary-500); color: white; border-radius: 4px; padding: 4px 10px; font-size: 12px; width: auto; height: auto; display: inline-block; text-decoration: none; border:none; cursor:pointer;">Mark as Used</button>
                    
                    <?php if (count($usageHistory) > 0): ?>
                        <div style="margin-top: 10px; font-size: 11px; color: var(--text-secondary); text-align: left; max-height: 80px; overflow-y: auto; background: var(--surface-elevated); padding: 8px; border-radius: 4px;">
                            <strong style="display: block; margin-bottom: 4px;">Usage History:</strong>
                            <?php foreach ($usageHistory as $log): ?>
                                <div style="margin-bottom: 2px;">• Used on <?php echo date('M d, Y h:i A', strtotime($log['created_at'])); ?></div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div style="margin-top: 8px; font-size: 11px; color: var(--text-tertiary);">No usage history recorded.</div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <div class="task-card-footer">
            <div class="assignee-info">
                <div class="assignee-avatar">
                    <?php echo $empInitials; ?>
                </div>
                <div class="assignee-text">
                    <span class="assignee-name"><?php echo htmlspecialchars($leave['first_name'] . ' ' . $leave['last_name']); ?></span>
                    <span class="assignee-dept"><?php echo htmlspecialchars($leave['department'] ?: 'General'); ?></span>
                </div>
            </div>
            
            <div class="task-actions">
                <?php if ($leave['status'] === 'pending'): ?>
                <button type="button" onclick="if(confirm('Approve this leave request?')) submitLeaveAction('approve', <?php echo $leave['id']; ?>)" class="btn-action" style="color: var(--success-color); background: rgba(16, 185, 129, 0.1); border:none; cursor:pointer;" title="Approve">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path>
                        <polyline points="22 4 12 14.01 9 11.01"></polyline>
                    </svg>
                </button>
                <button type="button" onclick="rejectLeavePrompt(<?php echo $leave['id']; ?>)" class="btn-action btn-delete" style="border:none; cursor:pointer;" title="Reject">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <line x1="18" y1="6" x2="6" y2="18"></line>
                        <line x1="6" y1="6" x2="18" y2="18"></line>
                    </svg>
                </button>
                <?php endif; ?>
                
                <button type="button" onclick="if(confirm('Are you sure you want to permanently delete this leave request?')) submitLeaveAction('delete', <?php echo $leave['id']; ?>)" class="btn-action btn-delete" style="border:none; cursor:pointer;" title="Delete">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <polyline points="3 6 5 6 21 6"></polyline>
                        <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path>
                    </svg>
                </button>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Generate Leave Modal -->
<div class="modal" id="generateLeaveModal">
    <div class="modal-backdrop" onclick="closeModal('generateLeaveModal')"></div>
    <div class="modal-content">
        <div class="modal-header">
            <h2 class="modal-title">Generate Approved Leave</h2>
            <button class="modal-close" onclick="closeModal('generateLeaveModal')">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <line x1="18" y1="6" x2="6" y2="18"></line>
                    <line x1="6" y1="6" x2="18" y2="18"></line>
                </svg>
            </button>
        </div>
        <form method="POST" action="leave" id="generateLeaveForm">
            <input type="hidden" name="action" value="generate_leave">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">Employee <span class="required">*</span></label>
                    <select name="employee_id" class="form-input" required>
                        <option value="">Select Employee</option>
                        <?php foreach ($employeesList as $emp): ?>
                            <option value="<?php echo $emp['id']; ?>">
                                <?php echo htmlspecialchars($emp['first_name'] . ' ' . $emp['last_name'] . ' (' . $emp['department'] . ')'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label">Leave Type <span class="required">*</span></label>
                    <select name="leave_type" class="form-input" required>
                        <option value="annual">Annual Leave</option>
                        <option value="sick">Sick Leave</option>
                        <option value="casual">Casual Leave</option>
                        <option value="early_departure">Early Departure</option>
                    </select>
                </div>

                <div class="form-row" style="display: flex; gap: 15px; margin-bottom: 15px;">
                    <div class="form-group" style="flex: 1; margin-bottom: 0;">
                        <label class="form-label">Start Date <span class="required">*</span></label>
                        <input type="date" name="start_date" class="form-input" required>
                    </div>
                    
                    <div class="form-group" style="flex: 1; margin-bottom: 0;">
                        <label class="form-label">End Date <span class="required">*</span></label>
                        <input type="date" name="end_date" class="form-input" required>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Reason <span class="required">*</span></label>
                    <textarea name="reason" class="form-input" rows="4" required placeholder="Enter the reason for leave..."></textarea>
                </div>
            </div>
            
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('generateLeaveModal')">Cancel</button>
                <button type="submit" class="btn btn-primary">Generate Leave</button>
            </div>
        </form>
    </div>
</div>

<style>
/* Stats Grid styling */
.stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 24px; margin-bottom: 32px; }
.stat-card { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius-xl); padding: 24px; display: flex; align-items: center; gap: 20px; box-shadow: var(--shadow-sm); transition: all var(--transition); }
.stat-card:hover { transform: translateY(-2px); box-shadow: var(--shadow-md); border-color: var(--border-hover); }
.stat-icon-wrapper { width: 48px; height: 48px; border-radius: var(--radius-lg); display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.stat-icon-wrapper svg { width: 24px; height: 24px; }
.stat-icon-wrapper.total { background: rgba(59, 130, 246, 0.1); color: var(--primary-500); }
.stat-icon-wrapper.pending { background: rgba(107, 114, 128, 0.1); color: var(--gray-500); }
.stat-icon-wrapper.active { background: rgba(16, 185, 129, 0.1); color: var(--success-500); }
.stat-icon-wrapper.inactive { background: rgba(239, 68, 68, 0.1); color: var(--danger-500); }
.stat-value { margin: 0; font-size: 24px; font-weight: 700; color: var(--text-primary); }
.stat-label { margin: 4px 0 0 0; font-size: 14px; color: var(--text-secondary); font-weight: 500; }

/* Modal Styles */
.modal { display: none; position: fixed; inset: 0; z-index: 1000; align-items: center; justify-content: center; padding: 24px; }
.modal.show { display: flex; }
.modal-backdrop { position: absolute; width: 100%; height: 100%; background: rgba(0, 0, 0, 0.4); backdrop-filter: blur(4px); }
.modal-content { background: var(--surface-elevated); border: 1px solid var(--border); border-radius: var(--radius-2xl); width: 100%; max-width: 520px; max-height: 90vh; overflow-y: auto; position: relative; z-index: 1001; box-shadow: var(--shadow-xl); }
.modal-header { padding: 24px; border-bottom: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center; }
.modal-title { margin: 0; font-size: 20px; font-weight: 700; color: var(--text-primary); }
.modal-close { background: transparent; border: none; color: var(--text-secondary); cursor: pointer; padding: 4px; border-radius: var(--radius-full); }
.modal-close:hover { background: var(--surface-hover); color: var(--text-primary); }
.modal-body { padding: 24px; display: flex; flex-direction: column; gap: 20px; }
.form-group { display: flex; flex-direction: column; gap: 8px; }
.form-label { font-size: 14px; font-weight: 600; color: var(--text-secondary); }
.required { color: var(--danger-500); }
.form-input { width: 100%; padding: 12px 16px; border: 1px solid var(--border); border-radius: var(--radius-lg); background: var(--surface); color: var(--text-primary); font-family: inherit; font-size: 14px; }
.form-input:focus { border-color: var(--border-focus); box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.15); outline: none; }
.modal-footer { padding: 24px; border-top: 1px solid var(--border); display: flex; justify-content: flex-end; gap: 12px; }

/* Grid Styles for Leave Cards */
.task-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(min(100%, 320px), 1fr)); gap: 24px; margin-bottom: 32px; }
.task-card { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius-xl); overflow: hidden; display: flex; flex-direction: column; transition: all var(--transition-smooth); box-shadow: var(--shadow-sm); min-width: 0; overflow-wrap: break-word; word-break: break-word; }
.task-card:hover { transform: translateY(-4px); box-shadow: var(--shadow-lg); border-color: var(--border-hover); }
.task-card-header { padding: 16px 20px; border-bottom: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center; background: rgba(0,0,0,0.01); }
.task-card-body { padding: 20px; flex-grow: 1; display: flex; flex-direction: column; }
.task-card-footer { padding: 16px 20px; border-top: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center; background: var(--surface-elevated); }
.badge { padding: 4px 10px; border-radius: var(--radius-full); font-size: 12px; font-weight: 600; }
.status-badge.status-pending { background: rgba(107, 114, 128, 0.1); color: var(--gray-600); }
.status-badge.status-approved { background: rgba(16, 185, 129, 0.1); color: var(--success-600); }
.status-badge.status-rejected { background: rgba(239, 68, 68, 0.1); color: var(--danger-600); }
.assignee-info { display: flex; align-items: center; gap: 12px; }
.assignee-avatar { width: 32px; height: 32px; border-radius: 50%; background: linear-gradient(135deg, var(--primary-500), var(--primary-600)); color: white; display: flex; align-items: center; justify-content: center; font-size: 12px; font-weight: 700; }
.assignee-text { display: flex; flex-direction: column; gap: 2px; }
.assignee-name { font-size: 14px; font-weight: 600; color: var(--text-primary); }
.assignee-dept { font-size: 12px; color: var(--text-tertiary); }
.task-actions { display: flex; gap: 8px; }
.btn-action { width: 32px; height: 32px; border-radius: var(--radius-md); border: none; display: flex; align-items: center; justify-content: center; cursor: pointer; transition: all var(--transition); background: transparent; }
.btn-action:hover { background: var(--surface-hover); }
.btn-delete:hover { background: rgba(239, 68, 68, 0.1); color: var(--danger-500); }

/* Empty State */
.empty-state { text-align: center; padding: 60px 24px; background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius-xl); box-shadow: var(--shadow-sm); max-width: 600px; margin: 0 auto 40px auto; }
.empty-state svg { color: var(--text-tertiary); margin-bottom: 20px; }
.empty-state h3 { margin: 0 0 8px 0; font-size: 20px; font-weight: 700; color: var(--text-primary); }
.empty-state p { margin: 0 0 24px 0; font-size: 15px; color: var(--text-secondary); }
@media (max-width: 480px) {
    .task-grid { gap: 16px; margin-bottom: 24px; }
    .task-card-header, .task-card-body, .task-card-footer { padding: 14px 12px; }
}
</style>

<script>
function openModal(id) {
    const modal = document.getElementById(id);
    if (modal) {
        modal.classList.add('show');
        document.body.style.overflow = 'hidden';
    }
}

function closeModal(id) {
    const modal = document.getElementById(id);
    if (modal) {
        modal.classList.remove('show');
        document.body.style.overflow = '';
    }
}

function rejectLeavePrompt(id) {
    const reason = prompt('Please enter the reason for declining this leave application (optional):');
    if (reason !== null) {
        submitLeaveAction('reject', id, { rejection_reason: reason });
    }
}

function submitLeaveAction(action, id, extraParams) {
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = 'leave';
    
    const csrf = document.createElement('input');
    csrf.type = 'hidden';
    csrf.name = 'csrf_token';
    csrf.value = '<?php echo htmlspecialchars($csrfToken); ?>';
    form.appendChild(csrf);

    const act = document.createElement('input');
    act.type = 'hidden';
    act.name = 'action';
    act.value = action;
    form.appendChild(act);

    const idInput = document.createElement('input');
    idInput.type = 'hidden';
    idInput.name = 'id';
    idInput.value = id;
    form.appendChild(idInput);

    if (extraParams && typeof extraParams === 'object') {
        for (const k in extraParams) {
            const p = document.createElement('input');
            p.type = 'hidden';
            p.name = k;
            p.value = extraParams[k];
            form.appendChild(p);
        }
    }

    document.body.appendChild(form);
    form.submit();
}
</script>

<?php include 'footer.php'; ?>
