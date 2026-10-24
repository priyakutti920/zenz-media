<?php
/**
 * Cloud Aspire - Task Assignments & Workforce Pipeline Management
 * Enterprise-grade task board with Multi-View (Table, Kanban, Grid), Real-Time Filters, and Pipeline Tracking
 */

// Include core database and security infrastructure
require_once '../app/db.php';
require_once '../app/security.php';
require_once '../app/Mailer.php';

if (isset($pdo) && $pdo instanceof PDO) {
    Security::init($pdo);
}
Security::initSecureSession();

// Check if admin is authenticated
if (!Security::isAuthenticated()) {
    header("Location: ../admin");
    exit();
}

$adminId = $_SESSION['admin_id'] ?? null;
Security::requirePermission('tasks.view', $adminId, '../admin/dashboard');

$canCreate = Security::hasPermission('tasks.create', $adminId);
$canManage = Security::hasPermission('tasks.manage', $adminId);

$pageTitle = 'Task Assignments';
$currentPage = 'task';

$message = '';
$messageType = '';

// Tasks table schema is maintained via migrations

// -------------------------------------------------------------------
// CSV Export Handler
// -------------------------------------------------------------------
if (isset($_GET['action']) && $_GET['action'] === 'export_tasks_csv') {
    $expFilterEmployee = $_GET['filter_employee'] ?? '';
    $expFilterPriority = $_GET['filter_priority'] ?? '';
    $expFilterStatus   = $_GET['filter_status'] ?? '';
    $expSearch         = trim($_GET['search'] ?? '');

    $expSql = "SELECT t.*, e.first_name, e.last_name, e.email, e.position, e.department, e.employee_id as emp_code
               FROM tasks t 
               LEFT JOIN employees e ON t.employee_id = e.id";
    $expParams = [];
    $expWhere = [];

    if (!empty($expFilterEmployee)) {
        $expWhere[] = "t.employee_id = ?";
        $expParams[] = (int)$expFilterEmployee;
    }
    if (!empty($expFilterPriority)) {
        $expWhere[] = "t.priority = ?";
        $expParams[] = $expFilterPriority;
    }
    if (!empty($expFilterStatus)) {
        $expWhere[] = "t.status = ?";
        $expParams[] = $expFilterStatus;
    }
    if (!empty($expSearch)) {
        $expWhere[] = "(t.title LIKE ? OR t.description LIKE ? OR e.first_name LIKE ? OR e.last_name LIKE ?)";
        $expParams[] = "%$expSearch%";
        $expParams[] = "%$expSearch%";
        $expParams[] = "%$expSearch%";
        $expParams[] = "%$expSearch%";
    }
    if (!empty($expWhere)) {
        $expSql .= " WHERE " . implode(" AND ", $expWhere);
    }
    $expSql .= " ORDER BY t.created_at DESC";

    try {
        $expStmt = $pdo->prepare($expSql);
        $expStmt->execute($expParams);
        $expRows = $expStmt->fetchAll(PDO::FETCH_ASSOC);

        Security::logAudit($adminId, 'export_tasks_csv', 'tasks', null, ['count' => count($expRows)]);

        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="tasks_export_' . date('Ymd_His') . '.csv"');
        header('Pragma: no-cache');
        header('Expires: 0');

        $out = fopen('php://output', 'w');
        // UTF-8 BOM
        fputs($out, "\xEF\xBB\xBF");
        fputcsv($out, ['Task ID', 'Title', 'Employee Code', 'Assignee Name', 'Department', 'Designation', 'Priority', 'Status', 'Due Date', 'Created At', 'Description']);

        foreach ($expRows as $r) {
            fputcsv($out, [
                'TASK-' . str_pad($r['id'], 4, '0', STR_PAD_LEFT),
                $r['title'],
                $r['emp_code'] ?: ('EMP-' . str_pad($r['employee_id'], 3, '0', STR_PAD_LEFT)),
                trim($r['first_name'] . ' ' . $r['last_name']),
                $r['department'] ?: 'General',
                $r['position'] ?: 'Employee',
                ucfirst($r['priority']),
                ucwords(str_replace('_', ' ', $r['status'])),
                $r['due_date'] ? date('d-M-Y', strtotime($r['due_date'])) : 'No Due Date',
                date('d-M-Y H:i', strtotime($r['created_at'])),
                $r['description']
            ]);
        }
        fclose($out);
        exit();
    } catch (PDOException $e) {
        error_log("Task CSV export error: " . $e->getMessage());
    }
}

// -------------------------------------------------------------------
// Handle POST Actions (CSRF protected)
// -------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !Security::validateCSRFToken($_POST['csrf_token'], 'task_action')) {
        $message = 'Invalid or expired security token. Please refresh and try again.';
        $messageType = 'error';
    } elseif (isset($_POST['action']) && $_POST['action'] === 'delete') {
        if (!$canManage) {
            $message = 'Access Denied: You do not have permission to delete tasks.';
            $messageType = 'error';
        } else {
            $id = (int)($_POST['id'] ?? 0);
            try {
                $stmt = $pdo->prepare("DELETE FROM tasks WHERE id = ?");
                $stmt->execute([$id]);
                Security::logAudit($adminId, 'delete_task', 'tasks', $id, ['status' => 'deleted']);
                $message = 'Task deleted successfully.';
                $messageType = 'success';
            } catch (PDOException $e) {
                error_log("Delete task error: " . $e->getMessage());
                $message = 'Error deleting task. Please try again.';
                $messageType = 'error';
            }
        }
    } elseif (isset($_POST['action']) && $_POST['action'] === 'toggle_status') {
        if (!$canManage) {
            $message = 'Access Denied: You do not have permission to update task status.';
            $messageType = 'error';
        } else {
            $id = (int)($_POST['id'] ?? 0);
            $status = $_POST['status'] ?? '';
            $allowedStatuses = ['sent', 'in_progress', 'verify', 'completed'];
            if ($id > 0 && in_array($status, $allowedStatuses, true)) {
                try {
                    $stmt = $pdo->prepare("UPDATE tasks SET status = ?, updated_at = NOW() WHERE id = ?");
                    $stmt->execute([$status, $id]);
                    Security::logAudit($adminId, 'toggle_task_status', 'tasks', $id, ['new_status' => $status]);
                    $message = 'Task status updated to ' . htmlspecialchars(ucwords(str_replace('_', ' ', $status))) . '.';
                    $messageType = 'success';
                } catch (PDOException $e) {
                    error_log("Update task status error: " . $e->getMessage());
                    $message = 'Error updating status. Please try again.';
                    $messageType = 'error';
                }
            }
        }
    } elseif (isset($_POST['submit_task'])) {
        $employeeId  = (int)($_POST['employee_id'] ?? 0);
        $title       = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $priority    = $_POST['priority'] ?? 'medium';
        $status      = $_POST['status'] ?? 'sent';
        $dueDate     = !empty($_POST['due_date']) ? $_POST['due_date'] : null;
        $id          = isset($_POST['task_id']) && !empty($_POST['task_id']) ? (int)$_POST['task_id'] : null;

        $allowedPriorities = ['low', 'medium', 'high', 'critical'];
        $allowedStatuses   = ['sent', 'in_progress', 'verify', 'completed'];

        if (!in_array($priority, $allowedPriorities, true)) $priority = 'medium';
        if (!in_array($status, $allowedStatuses, true)) $status = 'sent';

        if ($id && !$canManage) {
            $message = 'Access Denied: You do not have permission to modify tasks.';
            $messageType = 'error';
        } elseif (!$id && !$canCreate) {
            $message = 'Access Denied: You do not have permission to create tasks.';
            $messageType = 'error';
        } elseif (empty($title) || empty($employeeId)) {
            $message = 'Please enter a task title and assign it to an employee.';
            $messageType = 'error';
        } else {
            try {
                if ($id) {
                    $stmt = $pdo->prepare("UPDATE tasks SET
                        employee_id = ?, title = ?, description = ?,
                        priority = ?, status = ?, due_date = ?, updated_at = NOW()
                        WHERE id = ?");
                    $stmt->execute([$employeeId, $title, $description, $priority, $status, $dueDate, $id]);

                    Security::logAudit($adminId, 'update_task', 'tasks', $id, [
                        'title' => $title,
                        'employee_id' => $employeeId,
                        'priority' => $priority,
                        'status' => $status,
                        'due_date' => $dueDate
                    ]);

                    $message = 'Task "' . htmlspecialchars($title) . '" updated successfully.';
                    $messageType = 'success';

                    // Non-blocking email alert to employee about task updates
                    try {
                        $empStmt = $pdo->prepare("SELECT first_name, last_name, email FROM employees WHERE id = ? LIMIT 1");
                        $empStmt->execute([$employeeId]);
                        $emp = $empStmt->fetch(PDO::FETCH_ASSOC);
                        if ($emp && !empty($emp['email'])) {
                            $mailer = new Mailer($pdo);
                            $empName = trim($emp['first_name'] . ' ' . $emp['last_name']);
                            $mailer->sendTaskUpdatedAlert($emp['email'], $empName, $title, $priority, $dueDate, $status, $description);
                        }
                    } catch (Throwable $mailEx) {
                        error_log("Task update email alert error: " . $mailEx->getMessage());
                    }
                } else {
                    $stmt = $pdo->prepare("INSERT INTO tasks
                        (employee_id, title, description, priority, status, due_date)
                        VALUES (?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$employeeId, $title, $description, $priority, $status, $dueDate]);
                    $newTaskId = (int)$pdo->lastInsertId();

                    Security::logAudit($adminId, 'create_task', 'tasks', $newTaskId, [
                        'title' => $title,
                        'employee_id' => $employeeId,
                        'priority' => $priority,
                        'status' => $status,
                        'due_date' => $dueDate
                    ]);

                    $message = 'New task "' . htmlspecialchars($title) . '" assigned successfully.';
                    $messageType = 'success';

                    // Non-blocking email alert to employee about newly assigned task
                    try {
                        $empStmt = $pdo->prepare("SELECT first_name, last_name, email FROM employees WHERE id = ? LIMIT 1");
                        $empStmt->execute([$employeeId]);
                        $emp = $empStmt->fetch(PDO::FETCH_ASSOC);
                        if ($emp && !empty($emp['email'])) {
                            $mailer = new Mailer($pdo);
                            $empName = trim($emp['first_name'] . ' ' . $emp['last_name']);
                            $mailer->sendTaskAssignedAlert($emp['email'], $empName, $title, $priority, $dueDate, $description);
                        }
                    } catch (Throwable $mailEx) {
                        error_log("Task assignment email alert error: " . $mailEx->getMessage());
                    }
                }
            } catch (PDOException $e) {
                error_log("Save task error: " . $e->getMessage());
                $message = 'Error saving task. Please try again.';
                $messageType = 'error';
            }
        }
    }
}

$csrfToken = Security::generateCSRFToken('task_action');

// -------------------------------------------------------------------
// Filter & Search Parameters
// -------------------------------------------------------------------
$filterEmployee = $_GET['filter_employee'] ?? '';
$filterPriority = $_GET['filter_priority'] ?? '';
$filterStatus   = $_GET['filter_status'] ?? '';
$searchQuery    = trim($_GET['search'] ?? '');
$filterTab      = $_GET['tab'] ?? 'all'; // all, sent, in_progress, verify, completed, overdue

// Build query for listing tasks with complete employee information
$sql = "SELECT t.*, e.first_name, e.last_name, e.email, e.position, e.department, e.avatar, e.employee_id as emp_code
        FROM tasks t 
        LEFT JOIN employees e ON t.employee_id = e.id";
$params = [];
$whereClauses = [];

if (!empty($filterEmployee)) {
    $whereClauses[] = "t.employee_id = ?";
    $params[] = (int)$filterEmployee;
}
if (!empty($filterPriority)) {
    $whereClauses[] = "t.priority = ?";
    $params[] = $filterPriority;
}
if (!empty($filterStatus)) {
    $whereClauses[] = "t.status = ?";
    $params[] = $filterStatus;
}
if (!empty($searchQuery)) {
    $whereClauses[] = "(t.title LIKE ? OR t.description LIKE ? OR e.first_name LIKE ? OR e.last_name LIKE ? OR e.department LIKE ?)";
    $params[] = "%$searchQuery%";
    $params[] = "%$searchQuery%";
    $params[] = "%$searchQuery%";
    $params[] = "%$searchQuery%";
    $params[] = "%$searchQuery%";
}

// Quick Tab Filters
if ($filterTab === 'sent') {
    $whereClauses[] = "t.status = 'sent'";
} elseif ($filterTab === 'in_progress') {
    $whereClauses[] = "t.status = 'in_progress'";
} elseif ($filterTab === 'verify') {
    $whereClauses[] = "t.status = 'verify'";
} elseif ($filterTab === 'completed') {
    $whereClauses[] = "t.status = 'completed'";
} elseif ($filterTab === 'overdue') {
    $whereClauses[] = "t.due_date IS NOT NULL AND t.due_date < CURDATE() AND t.status != 'completed'";
}

if (!empty($whereClauses)) {
    $sql .= " WHERE " . implode(" AND ", $whereClauses);
}
$sql .= " ORDER BY t.created_at DESC";

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $tasks = [];
    error_log("Error loading tasks: " . $e->getMessage());
    $message = 'Error loading tasks. Please try again.';
    $messageType = 'error';
}

// Fetch all active employees for assignment dropdown
try {
    $stmt = $pdo->query("SELECT id, first_name, last_name, department, position, employee_id as emp_code, avatar FROM employees ORDER BY first_name ASC");
    $employeesList = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $employeesList = [];
}

// Fetch distinct departments
$departmentsList = [];
foreach ($employeesList as $emp) {
    if (!empty($emp['department']) && !in_array($emp['department'], $departmentsList)) {
        $departmentsList[] = $emp['department'];
    }
}
sort($departmentsList);

// Compute Comprehensive Statistics for Dashboard Cards & Tab Counters
$stats = [
    'total'       => 0,
    'sent'        => 0,
    'in_progress' => 0,
    'verify'      => 0,
    'completed'   => 0,
    'overdue'     => 0,
    'critical'    => 0,
    'high'        => 0
];

try {
    $rawRows = $pdo->query("SELECT id, status, priority, due_date FROM tasks")->fetchAll(PDO::FETCH_ASSOC);
    $todayDate = date('Y-m-d');
    foreach ($rawRows as $r) {
        $stats['total']++;
        if ($r['status'] === 'sent')        $stats['sent']++;
        if ($r['status'] === 'in_progress') $stats['in_progress']++;
        if ($r['status'] === 'verify')      $stats['verify']++;
        if ($r['status'] === 'completed')   $stats['completed']++;
        if ($r['priority'] === 'critical')  $stats['critical']++;
        if ($r['priority'] === 'high')      $stats['high']++;

        if (!empty($r['due_date']) && $r['due_date'] < $todayDate && $r['status'] !== 'completed') {
            $stats['overdue']++;
        }
    }
} catch (PDOException $e) {
    // Fail gracefully
}

/**
 * Helper to calculate urgency metadata
 */
function getTaskUrgencyBadge($dueDate, $status) {
    if ($status === 'completed') {
        return [
            'class' => 'urgency-done',
            'label' => 'Completed',
            'icon'  => 'fa-check',
            'is_overdue' => false
        ];
    }
    if (empty($dueDate)) {
        return [
            'class' => 'urgency-none',
            'label' => 'No Due Date',
            'icon'  => 'fa-calendar',
            'is_overdue' => false
        ];
    }

    $today = new DateTime('today');
    $due   = new DateTime($dueDate);
    $diff  = $today->diff($due);
    $days  = (int)$diff->format('%r%a');

    if ($days < 0) {
        $abs = abs($days);
        return [
            'class' => 'urgency-overdue',
            'label' => 'Overdue by ' . $abs . 'd',
            'icon'  => 'fa-triangle-exclamation',
            'is_overdue' => true
        ];
    } elseif ($days === 0) {
        return [
            'class' => 'urgency-today',
            'label' => 'Due Today',
            'icon'  => 'fa-clock',
            'is_overdue' => false
        ];
    } elseif ($days === 1) {
        return [
            'class' => 'urgency-tomorrow',
            'label' => 'Due Tomorrow',
            'icon'  => 'fa-calendar-day',
            'is_overdue' => false
        ];
    } else {
        return [
            'class' => 'urgency-upcoming',
            'label' => 'Due in ' . $days . 'd',
            'icon'  => 'fa-calendar',
            'is_overdue' => false
        ];
    }
}
?>

<?php include 'header.php'; ?>

<div class="task-management-wrapper">

    <!-- Page Header & Main Controls -->
    <div class="page-header">
        <div class="header-content">
            <div class="header-badges">
                <span class="hub-pill"><i class="fa-solid fa-list-check"></i> Workforce Tasks</span>
                <span class="pipeline-pill"><i class="fa-solid fa-code-commit"></i> Agile Pipeline</span>
            </div>
            <h1 class="page-title">Task Assignments & Tracking</h1>
            <p class="page-subtitle">Assign, prioritize, track workflow stages, and monitor employee turnaround in real-time.</p>
        </div>
        <div class="header-actions">
            <!-- CSV Export Button -->
            <a href="?action=export_tasks_csv&<?php echo http_build_query(array_filter(['filter_employee' => $filterEmployee, 'filter_priority' => $filterPriority, 'filter_status' => $filterStatus, 'search' => $searchQuery, 'tab' => $filterTab])); ?>" class="btn btn-secondary" title="Export current task selection to CSV">
                <i class="fa-solid fa-file-arrow-down"></i>
                <span>Export CSV</span>
            </a>

            <!-- Assign Task Button -->
            <button class="btn btn-primary" onclick="openAddTaskModal()">
                <i class="fa-solid fa-plus"></i>
                <span>Assign Task</span>
            </button>
        </div>
    </div>

    <!-- Alert Messages -->
    <?php if ($message): ?>
    <div class="alert alert-<?php echo $messageType; ?>">
        <div class="alert-icon">
            <?php if ($messageType === 'success'): ?>
                <i class="fa-solid fa-circle-check"></i>
            <?php else: ?>
                <i class="fa-solid fa-triangle-exclamation"></i>
            <?php endif; ?>
        </div>
        <div class="alert-content">
            <span><?php echo htmlspecialchars($message); ?></span>
        </div>
        <button class="alert-close" onclick="this.parentElement.remove()">
            <i class="fa-solid fa-xmark"></i>
        </button>
    </div>
    <?php endif; ?>

    <!-- Executive KPI Statistics Grid -->
    <div class="stats-grid">
        <!-- Total Tasks -->
        <a href="?tab=all" class="stat-card <?php echo $filterTab === 'all' ? 'active-stat' : ''; ?>">
            <div class="stat-icon-wrapper total">
                <i class="fa-solid fa-folder-tree"></i>
            </div>
            <div class="stat-details">
                <div class="stat-value"><?php echo $stats['total']; ?></div>
                <div class="stat-label">Total Tasks</div>
            </div>
            <div class="stat-trend"><i class="fa-solid fa-arrow-right"></i></div>
        </a>

        <!-- Sent / To Do -->
        <a href="?tab=sent" class="stat-card <?php echo $filterTab === 'sent' ? 'active-stat' : ''; ?>">
            <div class="stat-icon-wrapper sent">
                <i class="fa-solid fa-inbox"></i>
            </div>
            <div class="stat-details">
                <div class="stat-value"><?php echo $stats['sent']; ?></div>
                <div class="stat-label">To Do / Sent</div>
            </div>
            <div class="stat-trend"><i class="fa-solid fa-arrow-right"></i></div>
        </a>

        <!-- In Progress (NEW ICON FIXED) -->
        <a href="?tab=in_progress" class="stat-card <?php echo $filterTab === 'in_progress' ? 'active-stat' : ''; ?>">
            <div class="stat-icon-wrapper in-progress">
                <i class="fa-solid fa-arrows-rotate"></i>
            </div>
            <div class="stat-details">
                <div class="stat-value"><?php echo $stats['in_progress']; ?></div>
                <div class="stat-label">In Progress</div>
            </div>
            <div class="stat-trend"><i class="fa-solid fa-arrow-right"></i></div>
        </a>

        <!-- Under Verification -->
        <a href="?tab=verify" class="stat-card <?php echo $filterTab === 'verify' ? 'active-stat' : ''; ?>">
            <div class="stat-icon-wrapper verify">
                <i class="fa-solid fa-magnifying-glass-chart"></i>
            </div>
            <div class="stat-details">
                <div class="stat-value"><?php echo $stats['verify']; ?></div>
                <div class="stat-label">Under Verification</div>
            </div>
            <div class="stat-trend"><i class="fa-solid fa-arrow-right"></i></div>
        </a>

        <!-- Completed -->
        <a href="?tab=completed" class="stat-card <?php echo $filterTab === 'completed' ? 'active-stat' : ''; ?>">
            <div class="stat-icon-wrapper completed">
                <i class="fa-solid fa-circle-check"></i>
            </div>
            <div class="stat-details">
                <div class="stat-value"><?php echo $stats['completed']; ?></div>
                <div class="stat-label">Completed</div>
            </div>
            <div class="stat-trend"><i class="fa-solid fa-arrow-right"></i></div>
        </a>

        <!-- Overdue Tasks -->
        <a href="?tab=overdue" class="stat-card <?php echo $filterTab === 'overdue' ? 'active-stat' : ''; ?>">
            <div class="stat-icon-wrapper overdue">
                <i class="fa-solid fa-triangle-exclamation"></i>
            </div>
            <div class="stat-details">
                <div class="stat-value"><?php echo $stats['overdue']; ?></div>
                <div class="stat-label">Overdue Tasks</div>
            </div>
            <div class="stat-trend"><i class="fa-solid fa-arrow-right"></i></div>
        </a>
    </div>

    <!-- Toolbar & Clean View Switcher -->
    <div class="task-control-panel">
        <div class="panel-top-bar">
            <!-- Filter Status Quick Tabs -->
            <div class="status-quick-tabs">
                <a href="?tab=all<?php echo $filterEmployee ? '&filter_employee='.$filterEmployee : ''; ?>" class="tab-chip <?php echo $filterTab === 'all' ? 'active' : ''; ?>">
                    All Tasks <span class="tab-count"><?php echo $stats['total']; ?></span>
                </a>
                <a href="?tab=sent<?php echo $filterEmployee ? '&filter_employee='.$filterEmployee : ''; ?>" class="tab-chip <?php echo $filterTab === 'sent' ? 'active' : ''; ?>">
                    <i class="fa-solid fa-inbox"></i> To Do <span class="tab-count"><?php echo $stats['sent']; ?></span>
                </a>
                <a href="?tab=in_progress<?php echo $filterEmployee ? '&filter_employee='.$filterEmployee : ''; ?>" class="tab-chip <?php echo $filterTab === 'in_progress' ? 'active' : ''; ?>">
                    <i class="fa-solid fa-arrows-rotate"></i> In Progress <span class="tab-count"><?php echo $stats['in_progress']; ?></span>
                </a>
                <a href="?tab=verify<?php echo $filterEmployee ? '&filter_employee='.$filterEmployee : ''; ?>" class="tab-chip <?php echo $filterTab === 'verify' ? 'active' : ''; ?>">
                    <i class="fa-solid fa-magnifying-glass-chart"></i> Review <span class="tab-count"><?php echo $stats['verify']; ?></span>
                </a>
                <a href="?tab=completed<?php echo $filterEmployee ? '&filter_employee='.$filterEmployee : ''; ?>" class="tab-chip <?php echo $filterTab === 'completed' ? 'active' : ''; ?>">
                    <i class="fa-solid fa-circle-check"></i> Completed <span class="tab-count"><?php echo $stats['completed']; ?></span>
                </a>
                <?php if ($stats['overdue'] > 0): ?>
                <a href="?tab=overdue<?php echo $filterEmployee ? '&filter_employee='.$filterEmployee : ''; ?>" class="tab-chip tab-chip-overdue <?php echo $filterTab === 'overdue' ? 'active' : ''; ?>">
                    <i class="fa-solid fa-triangle-exclamation"></i> Overdue <span class="tab-count count-alert"><?php echo $stats['overdue']; ?></span>
                </a>
                <?php endif; ?>
            </div>

            <!-- View Switcher (Clean Table, Kanban Pipeline, Modern Grid) -->
            <div class="view-switcher-group">
                <span class="view-label">View:</span>
                <div class="view-btn-pill">
                    <button type="button" class="view-btn active" id="viewBtnTable" onclick="switchTaskView('table')" title="Clean Enterprise Table View">
                        <i class="fa-solid fa-table-list"></i>
                        <span>Table</span>
                    </button>
                    <button type="button" class="view-btn" id="viewBtnKanban" onclick="switchTaskView('kanban')" title="Agile Kanban Pipeline Board">
                        <i class="fa-solid fa-table-columns"></i>
                        <span>Kanban</span>
                    </button>
                    <button type="button" class="view-btn" id="viewBtnGrid" onclick="switchTaskView('grid')" title="Visual Cards Grid">
                        <i class="fa-solid fa-grip"></i>
                        <span>Grid</span>
                    </button>
                </div>
            </div>
        </div>

        <!-- Filter Inputs Row -->
        <form method="GET" action="" class="filter-inputs-form" id="filterForm">
            <input type="hidden" name="tab" value="<?php echo htmlspecialchars($filterTab); ?>">

            <div class="search-box-wrapper">
                <i class="fa-solid fa-magnifying-glass search-icon"></i>
                <input type="text" id="taskSearchInput" name="search" class="task-input search-input" placeholder="Search tasks by title, description, assignee, department... (Press / to focus)" value="<?php echo htmlspecialchars($searchQuery); ?>">
                <span class="kbd-hint">/</span>
                <?php if (!empty($searchQuery)): ?>
                    <a href="?tab=<?php echo urlencode($filterTab); ?>" class="clear-search-btn" title="Clear search"><i class="fa-solid fa-xmark"></i></a>
                <?php endif; ?>
            </div>

            <div class="filter-select-group">
                <!-- Assignee -->
                <div class="select-wrapper">
                    <i class="fa-solid fa-user select-icon"></i>
                    <select name="filter_employee" class="task-select" onchange="this.form.submit()">
                        <option value="">All Team Members</option>
                        <?php foreach ($employeesList as $emp): ?>
                            <option value="<?php echo $emp['id']; ?>" <?php echo $filterEmployee == $emp['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($emp['first_name'] . ' ' . $emp['last_name']); ?> (<?php echo htmlspecialchars($emp['department'] ?: 'General'); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Priority -->
                <div class="select-wrapper">
                    <i class="fa-solid fa-flag select-icon"></i>
                    <select name="filter_priority" class="task-select" onchange="this.form.submit()">
                        <option value="">All Priorities</option>
                        <option value="critical" <?php echo $filterPriority === 'critical' ? 'selected' : ''; ?>>🔥 Critical</option>
                        <option value="high"     <?php echo $filterPriority === 'high' ? 'selected' : ''; ?>>⚡ High</option>
                        <option value="medium"   <?php echo $filterPriority === 'medium' ? 'selected' : ''; ?>>🔷 Medium</option>
                        <option value="low"      <?php echo $filterPriority === 'low' ? 'selected' : ''; ?>>🟢 Low</option>
                    </select>
                </div>

                <!-- Status Filter (if not using quick tab) -->
                <div class="select-wrapper">
                    <i class="fa-solid fa-sliders select-icon"></i>
                    <select name="filter_status" class="task-select" onchange="this.form.submit()">
                        <option value="">All Workflow Statuses</option>
                        <option value="sent"        <?php echo $filterStatus === 'sent' ? 'selected' : ''; ?>>📥 To Do / Sent</option>
                        <option value="in_progress" <?php echo $filterStatus === 'in_progress' ? 'selected' : ''; ?>>🔄 In Progress</option>
                        <option value="verify"      <?php echo $filterStatus === 'verify' ? 'selected' : ''; ?>>🔍 Under Verification</option>
                        <option value="completed"   <?php echo $filterStatus === 'completed' ? 'selected' : ''; ?>>✅ Completed</option>
                    </select>
                </div>

                <?php if (!empty($searchQuery) || !empty($filterEmployee) || !empty($filterPriority) || !empty($filterStatus) || $filterTab !== 'all'): ?>
                    <a href="task" class="btn btn-outline-danger btn-reset-filters" title="Reset all filters">
                        <i class="fa-solid fa-rotate-left"></i>
                        <span>Reset</span>
                    </a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <!-- =================================================================== -->
    <!-- VIEW CONTAINER: TABLE, KANBAN, AND GRID                           -->
    <!-- =================================================================== -->

    <?php if (empty($tasks)): ?>
        <div class="empty-state-card">
            <div class="empty-state-graphic">
                <div class="empty-icon-circle">
                    <i class="fa-solid fa-clipboard-list"></i>
                </div>
            </div>
            <h3 class="empty-title">No Tasks Found</h3>
            <p class="empty-desc">
                <?php if (!empty($searchQuery) || !empty($filterEmployee) || !empty($filterPriority) || !empty($filterStatus) || $filterTab !== 'all'): ?>
                    No tasks match the active filters or search criteria. Try clearing filters or refining your query.
                <?php else: ?>
                    Your team has no tasks in this view yet. Start assigning tasks to keep your workforce agile.
                <?php endif; ?>
            </p>
            <div class="empty-actions">
                <?php if (!empty($searchQuery) || !empty($filterEmployee) || !empty($filterPriority) || !empty($filterStatus) || $filterTab !== 'all'): ?>
                    <a href="task" class="btn btn-secondary">
                        <i class="fa-solid fa-rotate-left"></i>
                        <span>Clear All Filters</span>
                    </a>
                <?php endif; ?>
                <button type="button" class="btn btn-primary" onclick="openAddTaskModal()">
                    <i class="fa-solid fa-plus"></i>
                    <span>Assign First Task</span>
                </button>
            </div>
        </div>
    <?php else: ?>

        <!-- 1. CLEAN ENTERPRISE TABLE VIEW (DEFAULT CLEAN VIEW) -->
        <div class="view-wrapper" id="taskTableView">
            <div class="table-card">
                <div class="table-responsive">
                    <table class="enterprise-task-table" id="enterpriseTaskTable">
                        <thead>
                            <tr>
                                <th style="width: 50px;">#</th>
                                <th style="min-width: 260px;">Task Title & Overview</th>
                                <th style="min-width: 200px;">Assignee</th>
                                <th style="width: 130px;">Priority</th>
                                <th style="width: 160px;">Workflow Status</th>
                                <th style="width: 150px;">Due Urgency</th>
                                <th style="width: 120px;">Created</th>
                                <th style="width: 110px; text-align: right;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $idx = 1;
                            foreach ($tasks as $task): 
                                $urgency = getTaskUrgencyBadge($task['due_date'], $task['status']);
                                $empName = trim($task['first_name'] . ' ' . $task['last_name']);
                                $empCode = $task['emp_code'] ?: ('EMP-' . str_pad($task['employee_id'], 3, '0', STR_PAD_LEFT));
                                $empInitials = strtoupper(substr($task['first_name'], 0, 1) . substr($task['last_name'], 0, 1));
                            ?>
                            <tr class="task-row task-priority-<?php echo $task['priority']; ?>" data-task-id="<?php echo $task['id']; ?>" data-search-text="<?php echo htmlspecialchars(strtolower($task['title'] . ' ' . $task['description'] . ' ' . $empName . ' ' . $task['department'] . ' ' . $empCode)); ?>">
                                <td class="col-index">
                                    <span class="index-num"><?php echo $idx++; ?></span>
                                </td>
                                
                                <td class="col-title">
                                    <div class="task-cell-main">
                                        <div class="task-heading">
                                            <a href="javascript:void(0)" class="task-title-link" onclick="openTaskDetails(<?php echo htmlspecialchars(json_encode($task)); ?>)">
                                                <?php echo htmlspecialchars($task['title']); ?>
                                            </a>
                                            <?php if ($urgency['is_overdue']): ?>
                                                <span class="badge-mini-overdue" title="Overdue Task"><i class="fa-solid fa-triangle-exclamation"></i></span>
                                            <?php endif; ?>
                                        </div>
                                        <?php if (!empty($task['description'])): ?>
                                            <div class="task-excerpt">
                                                <?php echo htmlspecialchars(mb_strimwidth($task['description'], 0, 90, '...')); ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </td>

                                <td class="col-assignee">
                                    <div class="assignee-cell">
                                        <?php if (!empty($task['avatar'])): ?>
                                            <img src="/assets/uploads/avatars/<?php echo htmlspecialchars($task['avatar']); ?>" class="assignee-img" alt="<?php echo htmlspecialchars($empName); ?>">
                                        <?php else: ?>
                                            <div class="assignee-initials"><?php echo $empInitials; ?></div>
                                        <?php endif; ?>
                                        <div class="assignee-meta">
                                            <span class="assignee-full-name"><?php echo htmlspecialchars($empName); ?></span>
                                            <span class="assignee-sub"><span class="code-tag"><?php echo htmlspecialchars($empCode); ?></span> &bull; <?php echo htmlspecialchars($task['department'] ?: 'General'); ?></span>
                                        </div>
                                    </div>
                                </td>

                                <td class="col-priority">
                                    <span class="priority-pill priority-<?php echo $task['priority']; ?>">
                                        <?php if ($task['priority'] === 'critical'): ?>
                                            <i class="fa-solid fa-fire"></i> Critical
                                        <?php elseif ($task['priority'] === 'high'): ?>
                                            <i class="fa-solid fa-bolt"></i> High
                                        <?php elseif ($task['priority'] === 'medium'): ?>
                                            <i class="fa-solid fa-bars-staggered"></i> Medium
                                        <?php else: ?>
                                            <i class="fa-solid fa-arrow-down"></i> Low
                                        <?php endif; ?>
                                    </span>
                                </td>

                                <td class="col-status">
                                    <div class="status-select-wrapper">
                                        <select class="status-inline-select status-val-<?php echo $task['status']; ?>" onchange="changeTaskStatusInline(<?php echo $task['id']; ?>, this.value)">
                                            <option value="sent" <?php echo $task['status'] === 'sent' ? 'selected' : ''; ?>>📥 To Do</option>
                                            <option value="in_progress" <?php echo $task['status'] === 'in_progress' ? 'selected' : ''; ?>>🔄 In Progress</option>
                                            <option value="verify" <?php echo $task['status'] === 'verify' ? 'selected' : ''; ?>>🔍 Under Review</option>
                                            <option value="completed" <?php echo $task['status'] === 'completed' ? 'selected' : ''; ?>>✅ Completed</option>
                                        </select>
                                    </div>
                                </td>

                                <td class="col-due">
                                    <span class="urgency-pill <?php echo $urgency['class']; ?>">
                                        <i class="fa-solid <?php echo $urgency['icon']; ?>"></i>
                                        <span><?php echo $urgency['label']; ?></span>
                                    </span>
                                </td>

                                <td class="col-date">
                                    <span class="date-text" title="<?php echo date('d M Y, h:i A', strtotime($task['created_at'])); ?>">
                                        <?php echo date('M d, Y', strtotime($task['created_at'])); ?>
                                    </span>
                                </td>

                                <td class="col-actions">
                                    <div class="actions-cluster">
                                        <button type="button" class="action-btn btn-view" onclick="openTaskDetails(<?php echo htmlspecialchars(json_encode($task)); ?>)" title="View Full Details">
                                            <i class="fa-solid fa-eye"></i>
                                        </button>
                                        <button type="button" class="action-btn btn-edit" onclick="openEditTaskModal(<?php echo htmlspecialchars(json_encode($task)); ?>)" title="Edit Task">
                                            <i class="fa-solid fa-pen-to-square"></i>
                                        </button>
                                        <button type="button" class="action-btn btn-delete" onclick="promptDeleteTask(<?php echo $task['id']; ?>, '<?php echo htmlspecialchars(addslashes($task['title'])); ?>')" title="Delete Task">
                                            <i class="fa-solid fa-trash"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- 2. AGILE KANBAN PIPELINE BOARD VIEW -->
        <div class="view-wrapper" id="taskKanbanView" style="display: none;">
            <div class="kanban-board-grid">
                
                <!-- Column 1: Sent / To Do -->
                <div class="kanban-column col-sent">
                    <div class="kanban-column-header">
                        <div class="col-title-group">
                            <span class="kanban-indicator indicator-sent"></span>
                            <h3 class="kanban-title">To Do / Backlog</h3>
                        </div>
                        <span class="kanban-count" id="kanbanCountSent">
                            <?php 
                            $sentTasks = array_filter($tasks, fn($t) => $t['status'] === 'sent');
                            echo count($sentTasks);
                            ?>
                        </span>
                    </div>
                    <div class="kanban-cards-stack">
                        <?php foreach ($sentTasks as $task): 
                            $urgency = getTaskUrgencyBadge($task['due_date'], $task['status']);
                            $empName = trim($task['first_name'] . ' ' . $task['last_name']);
                            $empInitials = strtoupper(substr($task['first_name'], 0, 1) . substr($task['last_name'], 0, 1));
                        ?>
                            <div class="kanban-card priority-border-<?php echo $task['priority']; ?>" data-task-id="<?php echo $task['id']; ?>">
                                <div class="kanban-card-top">
                                    <span class="priority-pill priority-<?php echo $task['priority']; ?> mini">
                                        <?php echo ucfirst($task['priority']); ?>
                                    </span>
                                    <span class="urgency-pill <?php echo $urgency['class']; ?> mini">
                                        <i class="fa-solid <?php echo $urgency['icon']; ?>"></i> <?php echo $urgency['label']; ?>
                                    </span>
                                </div>
                                <h4 class="kanban-card-title" onclick="openTaskDetails(<?php echo htmlspecialchars(json_encode($task)); ?>)">
                                    <?php echo htmlspecialchars($task['title']); ?>
                                </h4>
                                <?php if (!empty($task['description'])): ?>
                                    <p class="kanban-card-desc"><?php echo htmlspecialchars(mb_strimwidth($task['description'], 0, 80, '...')); ?></p>
                                <?php endif; ?>
                                <div class="kanban-card-footer">
                                    <div class="kanban-assignee" title="<?php echo htmlspecialchars($empName); ?>">
                                        <?php if (!empty($task['avatar'])): ?>
                                            <img src="/assets/uploads/avatars/<?php echo htmlspecialchars($task['avatar']); ?>" class="assignee-img-sm" alt="<?php echo htmlspecialchars($empName); ?>">
                                        <?php else: ?>
                                            <span class="assignee-initials-sm"><?php echo $empInitials; ?></span>
                                        <?php endif; ?>
                                        <span class="assignee-name-sm"><?php echo htmlspecialchars($empName); ?></span>
                                    </div>
                                    <div class="kanban-quick-advance">
                                        <button type="button" class="btn-advance" onclick="changeTaskStatusInline(<?php echo $task['id']; ?>, 'in_progress')" title="Move to In Progress">
                                            <span>Start</span> <i class="fa-solid fa-arrow-right"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        <?php if (empty($sentTasks)): ?>
                            <div class="kanban-empty-slot">No pending tasks in To Do</div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Column 2: In Progress -->
                <div class="kanban-column col-in-progress">
                    <div class="kanban-column-header">
                        <div class="col-title-group">
                            <span class="kanban-indicator indicator-in-progress"></span>
                            <h3 class="kanban-title">In Progress</h3>
                        </div>
                        <span class="kanban-count" id="kanbanCountInProgress">
                            <?php 
                            $inProgTasks = array_filter($tasks, fn($t) => $t['status'] === 'in_progress');
                            echo count($inProgTasks);
                            ?>
                        </span>
                    </div>
                    <div class="kanban-cards-stack">
                        <?php foreach ($inProgTasks as $task): 
                            $urgency = getTaskUrgencyBadge($task['due_date'], $task['status']);
                            $empName = trim($task['first_name'] . ' ' . $task['last_name']);
                            $empInitials = strtoupper(substr($task['first_name'], 0, 1) . substr($task['last_name'], 0, 1));
                        ?>
                            <div class="kanban-card priority-border-<?php echo $task['priority']; ?>" data-task-id="<?php echo $task['id']; ?>">
                                <div class="kanban-card-top">
                                    <span class="priority-pill priority-<?php echo $task['priority']; ?> mini">
                                        <?php echo ucfirst($task['priority']); ?>
                                    </span>
                                    <span class="urgency-pill <?php echo $urgency['class']; ?> mini">
                                        <i class="fa-solid <?php echo $urgency['icon']; ?>"></i> <?php echo $urgency['label']; ?>
                                    </span>
                                </div>
                                <h4 class="kanban-card-title" onclick="openTaskDetails(<?php echo htmlspecialchars(json_encode($task)); ?>)">
                                    <?php echo htmlspecialchars($task['title']); ?>
                                </h4>
                                <?php if (!empty($task['description'])): ?>
                                    <p class="kanban-card-desc"><?php echo htmlspecialchars(mb_strimwidth($task['description'], 0, 80, '...')); ?></p>
                                <?php endif; ?>
                                <div class="kanban-card-footer">
                                    <div class="kanban-assignee" title="<?php echo htmlspecialchars($empName); ?>">
                                        <?php if (!empty($task['avatar'])): ?>
                                            <img src="/assets/uploads/avatars/<?php echo htmlspecialchars($task['avatar']); ?>" class="assignee-img-sm" alt="<?php echo htmlspecialchars($empName); ?>">
                                        <?php else: ?>
                                            <span class="assignee-initials-sm"><?php echo $empInitials; ?></span>
                                        <?php endif; ?>
                                        <span class="assignee-name-sm"><?php echo htmlspecialchars($empName); ?></span>
                                    </div>
                                    <div class="kanban-quick-advance">
                                        <button type="button" class="btn-advance btn-advance-verify" onclick="changeTaskStatusInline(<?php echo $task['id']; ?>, 'verify')" title="Move to Verification">
                                            <span>Review</span> <i class="fa-solid fa-arrow-right"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        <?php if (empty($inProgTasks)): ?>
                            <div class="kanban-empty-slot">No active tasks in progress</div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Column 3: Under Verification -->
                <div class="kanban-column col-verify">
                    <div class="kanban-column-header">
                        <div class="col-title-group">
                            <span class="kanban-indicator indicator-verify"></span>
                            <h3 class="kanban-title">Under Review</h3>
                        </div>
                        <span class="kanban-count" id="kanbanCountVerify">
                            <?php 
                            $verifyTasks = array_filter($tasks, fn($t) => $t['status'] === 'verify');
                            echo count($verifyTasks);
                            ?>
                        </span>
                    </div>
                    <div class="kanban-cards-stack">
                        <?php foreach ($verifyTasks as $task): 
                            $urgency = getTaskUrgencyBadge($task['due_date'], $task['status']);
                            $empName = trim($task['first_name'] . ' ' . $task['last_name']);
                            $empInitials = strtoupper(substr($task['first_name'], 0, 1) . substr($task['last_name'], 0, 1));
                        ?>
                            <div class="kanban-card priority-border-<?php echo $task['priority']; ?>" data-task-id="<?php echo $task['id']; ?>">
                                <div class="kanban-card-top">
                                    <span class="priority-pill priority-<?php echo $task['priority']; ?> mini">
                                        <?php echo ucfirst($task['priority']); ?>
                                    </span>
                                    <span class="urgency-pill <?php echo $urgency['class']; ?> mini">
                                        <i class="fa-solid <?php echo $urgency['icon']; ?>"></i> <?php echo $urgency['label']; ?>
                                    </span>
                                </div>
                                <h4 class="kanban-card-title" onclick="openTaskDetails(<?php echo htmlspecialchars(json_encode($task)); ?>)">
                                    <?php echo htmlspecialchars($task['title']); ?>
                                </h4>
                                <?php if (!empty($task['description'])): ?>
                                    <p class="kanban-card-desc"><?php echo htmlspecialchars(mb_strimwidth($task['description'], 0, 80, '...')); ?></p>
                                <?php endif; ?>
                                <div class="kanban-card-footer">
                                    <div class="kanban-assignee" title="<?php echo htmlspecialchars($empName); ?>">
                                        <?php if (!empty($task['avatar'])): ?>
                                            <img src="/assets/uploads/avatars/<?php echo htmlspecialchars($task['avatar']); ?>" class="assignee-img-sm" alt="<?php echo htmlspecialchars($empName); ?>">
                                        <?php else: ?>
                                            <span class="assignee-initials-sm"><?php echo $empInitials; ?></span>
                                        <?php endif; ?>
                                        <span class="assignee-name-sm"><?php echo htmlspecialchars($empName); ?></span>
                                    </div>
                                    <div class="kanban-quick-advance">
                                        <button type="button" class="btn-advance btn-advance-complete" onclick="changeTaskStatusInline(<?php echo $task['id']; ?>, 'completed')" title="Mark Completed">
                                            <span>Approve</span> <i class="fa-solid fa-check"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        <?php if (empty($verifyTasks)): ?>
                            <div class="kanban-empty-slot">No tasks awaiting review</div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Column 4: Completed -->
                <div class="kanban-column col-completed">
                    <div class="kanban-column-header">
                        <div class="col-title-group">
                            <span class="kanban-indicator indicator-completed"></span>
                            <h3 class="kanban-title">Completed</h3>
                        </div>
                        <span class="kanban-count" id="kanbanCountCompleted">
                            <?php 
                            $compTasks = array_filter($tasks, fn($t) => $t['status'] === 'completed');
                            echo count($compTasks);
                            ?>
                        </span>
                    </div>
                    <div class="kanban-cards-stack">
                        <?php foreach ($compTasks as $task): 
                            $empName = trim($task['first_name'] . ' ' . $task['last_name']);
                            $empInitials = strtoupper(substr($task['first_name'], 0, 1) . substr($task['last_name'], 0, 1));
                        ?>
                            <div class="kanban-card kanban-card-done" data-task-id="<?php echo $task['id']; ?>">
                                <div class="kanban-card-top">
                                    <span class="priority-pill priority-<?php echo $task['priority']; ?> mini">
                                        <?php echo ucfirst($task['priority']); ?>
                                    </span>
                                    <span class="urgency-pill urgency-done mini">
                                        <i class="fa-solid fa-check-double"></i> Done
                                    </span>
                                </div>
                                <h4 class="kanban-card-title text-done" onclick="openTaskDetails(<?php echo htmlspecialchars(json_encode($task)); ?>)">
                                    <?php echo htmlspecialchars($task['title']); ?>
                                </h4>
                                <div class="kanban-card-footer">
                                    <div class="kanban-assignee" title="<?php echo htmlspecialchars($empName); ?>">
                                        <?php if (!empty($task['avatar'])): ?>
                                            <img src="/assets/uploads/avatars/<?php echo htmlspecialchars($task['avatar']); ?>" class="assignee-img-sm" alt="<?php echo htmlspecialchars($empName); ?>">
                                        <?php else: ?>
                                            <span class="assignee-initials-sm"><?php echo $empInitials; ?></span>
                                        <?php endif; ?>
                                        <span class="assignee-name-sm"><?php echo htmlspecialchars($empName); ?></span>
                                    </div>
                                    <div class="kanban-quick-advance">
                                        <button type="button" class="btn-reopen" onclick="changeTaskStatusInline(<?php echo $task['id']; ?>, 'in_progress')" title="Re-open task to In Progress">
                                            <i class="fa-solid fa-rotate-left"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        <?php if (empty($compTasks)): ?>
                            <div class="kanban-empty-slot">No completed tasks yet</div>
                        <?php endif; ?>
                    </div>
                </div>

            </div>
        </div>

        <!-- 3. MODERN CARDS GRID VIEW -->
        <div class="view-wrapper" id="taskGridView" style="display: none;">
            <div class="modern-task-grid">
                <?php foreach ($tasks as $task): 
                    $urgency = getTaskUrgencyBadge($task['due_date'], $task['status']);
                    $empName = trim($task['first_name'] . ' ' . $task['last_name']);
                    $empCode = $task['emp_code'] ?: ('EMP-' . str_pad($task['employee_id'], 3, '0', STR_PAD_LEFT));
                    $empInitials = strtoupper(substr($task['first_name'], 0, 1) . substr($task['last_name'], 0, 1));
                ?>
                <div class="modern-card priority-accent-<?php echo $task['priority']; ?>" data-task-id="<?php echo $task['id']; ?>">
                    <div class="modern-card-header">
                        <span class="priority-pill priority-<?php echo $task['priority']; ?>">
                            <?php if ($task['priority'] === 'critical'): ?>
                                <i class="fa-solid fa-fire"></i> Critical
                            <?php elseif ($task['priority'] === 'high'): ?>
                                <i class="fa-solid fa-bolt"></i> High
                            <?php elseif ($task['priority'] === 'medium'): ?>
                                <i class="fa-solid fa-bars-staggered"></i> Medium
                            <?php else: ?>
                                <i class="fa-solid fa-arrow-down"></i> Low
                            <?php endif; ?>
                        </span>

                        <div class="status-badge-picker">
                            <select class="status-pill-select status-pill-<?php echo $task['status']; ?>" onchange="changeTaskStatusInline(<?php echo $task['id']; ?>, this.value)">
                                <option value="sent" <?php echo $task['status'] === 'sent' ? 'selected' : ''; ?>>To Do</option>
                                <option value="in_progress" <?php echo $task['status'] === 'in_progress' ? 'selected' : ''; ?>>In Progress</option>
                                <option value="verify" <?php echo $task['status'] === 'verify' ? 'selected' : ''; ?>>Under Review</option>
                                <option value="completed" <?php echo $task['status'] === 'completed' ? 'selected' : ''; ?>>Completed</option>
                            </select>
                        </div>
                    </div>

                    <div class="modern-card-body">
                        <h3 class="modern-card-title" onclick="openTaskDetails(<?php echo htmlspecialchars(json_encode($task)); ?>)">
                            <?php echo htmlspecialchars($task['title']); ?>
                        </h3>
                        <p class="modern-card-desc">
                            <?php echo nl2br(htmlspecialchars($task['description'] ?: 'No detailed instructions provided.')); ?>
                        </p>

                        <div class="modern-card-meta">
                            <span class="urgency-pill <?php echo $urgency['class']; ?>">
                                <i class="fa-solid <?php echo $urgency['icon']; ?>"></i>
                                <span><?php echo $urgency['label']; ?></span>
                            </span>
                            <?php if (!empty($task['due_date'])): ?>
                                <span class="due-cal-date"><i class="fa-regular fa-calendar"></i> <?php echo date('M d, Y', strtotime($task['due_date'])); ?></span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="modern-card-footer">
                        <div class="footer-assignee">
                            <?php if (!empty($task['avatar'])): ?>
                                <img src="/assets/uploads/avatars/<?php echo htmlspecialchars($task['avatar']); ?>" class="assignee-img" alt="<?php echo htmlspecialchars($empName); ?>">
                            <?php else: ?>
                                <div class="assignee-initials"><?php echo $empInitials; ?></div>
                            <?php endif; ?>
                            <div class="footer-assignee-text">
                                <span class="footer-emp-name"><?php echo htmlspecialchars($empName); ?></span>
                                <span class="footer-emp-sub"><?php echo htmlspecialchars($empCode); ?> &bull; <?php echo htmlspecialchars($task['department'] ?: 'General'); ?></span>
                            </div>
                        </div>

                        <div class="footer-actions">
                            <button type="button" class="action-btn btn-view" onclick="openTaskDetails(<?php echo htmlspecialchars(json_encode($task)); ?>)" title="View Details">
                                <i class="fa-solid fa-eye"></i>
                            </button>
                            <button type="button" class="action-btn btn-edit" onclick="openEditTaskModal(<?php echo htmlspecialchars(json_encode($task)); ?>)" title="Edit Task">
                                <i class="fa-solid fa-pen-to-square"></i>
                            </button>
                            <button type="button" class="action-btn btn-delete" onclick="promptDeleteTask(<?php echo $task['id']; ?>, '<?php echo htmlspecialchars(addslashes($task['title'])); ?>')" title="Delete Task">
                                <i class="fa-solid fa-trash"></i>
                            </button>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

    <?php endif; ?>

</div>

<!-- =================================================================== -->
<!-- MODALS: ADD/EDIT TASK, VIEW DETAILS, AND CONFIRM DELETE            -->
<!-- =================================================================== -->

<!-- Add / Assign Task Modal -->
<div class="app-modal" id="taskModal">
    <div class="modal-backdrop" onclick="closeAppModal('taskModal')"></div>
    <div class="modal-card">
        <div class="modal-card-header">
            <div class="header-title-wrap">
                <div class="modal-icon-badge" id="modalIconBadge">
                    <i class="fa-solid fa-plus"></i>
                </div>
                <div>
                    <h2 class="modal-heading" id="modalTitle">Assign New Task</h2>
                    <p class="modal-subheading" id="modalSubtitle">Create and assign an agile work assignment for your team.</p>
                </div>
            </div>
            <button type="button" class="modal-close-btn" onclick="closeAppModal('taskModal')">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <form method="POST" action="" class="modal-body-form" id="taskForm">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
            <input type="hidden" id="form_task_id" name="task_id" value="">

            <div class="form-grid-2">
                <!-- Assign Employee -->
                <div class="input-field-group">
                    <label for="form_employee_id" class="field-label">Assign Employee <span class="required-star">*</span></label>
                    <div class="input-icon-wrap">
                        <i class="fa-solid fa-user-check field-icon"></i>
                        <select id="form_employee_id" name="employee_id" class="modal-input" required>
                            <option value="">Select Team Member</option>
                            <?php foreach ($employeesList as $emp): ?>
                                <option value="<?php echo $emp['id']; ?>">
                                    <?php echo htmlspecialchars($emp['first_name'] . ' ' . $emp['last_name']); ?> (<?php echo htmlspecialchars($emp['department'] ?: 'No Dept'); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <!-- Due Date -->
                <div class="input-field-group">
                    <label for="form_due_date" class="field-label">Due Date</label>
                    <div class="input-icon-wrap">
                        <i class="fa-regular fa-calendar-days field-icon"></i>
                        <input type="date" id="form_due_date" name="due_date" class="modal-input" value="<?php echo date('Y-m-d', strtotime('+3 days')); ?>">
                    </div>
                </div>
            </div>

            <!-- Task Title -->
            <div class="input-field-group">
                <label for="form_title" class="field-label">Task Title <span class="required-star">*</span></label>
                <div class="input-icon-wrap">
                    <i class="fa-solid fa-heading field-icon"></i>
                    <input type="text" id="form_title" name="title" class="modal-input" required placeholder="e.g. Design modern dashboard, Fix payment gateway timeout...">
                </div>
            </div>

            <!-- Description -->
            <div class="input-field-group">
                <label for="form_description" class="field-label">Task Description & Deliverables</label>
                <textarea id="form_description" name="description" class="modal-input modal-textarea" rows="4" placeholder="Provide step-by-step instructions, references, acceptance criteria, or links..."></textarea>
            </div>

            <div class="form-grid-2">
                <!-- Priority -->
                <div class="input-field-group">
                    <label for="form_priority" class="field-label">Priority Level</label>
                    <div class="input-icon-wrap">
                        <i class="fa-solid fa-flag field-icon"></i>
                        <select id="form_priority" name="priority" class="modal-input">
                            <option value="low">🟢 Low Priority</option>
                            <option value="medium" selected>🔷 Medium Priority</option>
                            <option value="high">⚡ High Priority</option>
                            <option value="critical">🔥 Critical Priority</option>
                        </select>
                    </div>
                </div>

                <!-- Initial Status -->
                <div class="input-field-group">
                    <label for="form_status" class="field-label">Workflow Status</label>
                    <div class="input-icon-wrap">
                        <i class="fa-solid fa-circle-nodes field-icon"></i>
                        <select id="form_status" name="status" class="modal-input">
                            <option value="sent" selected>📥 Sent / To Do</option>
                            <option value="in_progress">🔄 In Progress</option>
                            <option value="verify">🔍 Under Verification</option>
                            <option value="completed">✅ Completed</option>
                        </select>
                    </div>
                </div>
            </div>

            <div class="modal-card-footer">
                <button type="button" class="btn btn-secondary" onclick="closeAppModal('taskModal')">Cancel</button>
                <button type="submit" name="submit_task" class="btn btn-primary" id="modalSubmitBtn">
                    <i class="fa-solid fa-check"></i>
                    <span>Assign Task</span>
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Task Details Quick Drawer / Modal -->
<div class="app-modal" id="taskDetailsModal">
    <div class="modal-backdrop" onclick="closeAppModal('taskDetailsModal')"></div>
    <div class="modal-card modal-card-lg">
        <div class="modal-card-header">
            <div class="header-title-wrap">
                <div class="modal-icon-badge" style="background: var(--adm-brand-light); color: var(--adm-brand);">
                    <i class="fa-solid fa-file-lines"></i>
                </div>
                <div>
                    <h2 class="modal-heading" id="viewTaskHeading">Task Overview</h2>
                    <p class="modal-subheading" id="viewTaskIdTag">TASK-0000</p>
                </div>
            </div>
            <button type="button" class="modal-close-btn" onclick="closeAppModal('taskDetailsModal')">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <div class="modal-body-details">
            <div class="details-top-badges">
                <span class="priority-pill" id="viewPriorityPill">Priority</span>
                <span class="status-pill" id="viewStatusPill">Status</span>
                <span class="urgency-pill" id="viewUrgencyPill">Urgency</span>
            </div>

            <div class="details-section">
                <h4 class="details-label"><i class="fa-solid fa-align-left"></i> Description & Requirements</h4>
                <div class="details-description-box" id="viewDescriptionBox">
                    No description provided.
                </div>
            </div>

            <div class="details-grid-meta">
                <div class="meta-box">
                    <span class="meta-label">Assignee</span>
                    <div class="meta-assignee" id="viewAssigneeBox">
                        <!-- Populated by JS -->
                    </div>
                </div>

                <div class="meta-box">
                    <span class="meta-label">Due Date</span>
                    <div class="meta-val" id="viewDueDateBox">
                        <i class="fa-regular fa-calendar"></i> <span>-</span>
                    </div>
                </div>

                <div class="meta-box">
                    <span class="meta-label">Created At</span>
                    <div class="meta-val" id="viewCreatedAtBox">
                        <i class="fa-regular fa-clock"></i> <span>-</span>
                    </div>
                </div>

                <div class="meta-box">
                    <span class="meta-label">Last Updated</span>
                    <div class="meta-val" id="viewUpdatedAtBox">
                        <i class="fa-solid fa-arrows-rotate"></i> <span>-</span>
                    </div>
                </div>
            </div>
        </div>

        <div class="modal-card-footer">
            <div class="footer-left">
                <button type="button" class="btn btn-outline-danger" id="viewDeleteBtn">
                    <i class="fa-solid fa-trash"></i> Delete
                </button>
            </div>
            <div class="footer-right">
                <button type="button" class="btn btn-secondary" onclick="closeAppModal('taskDetailsModal')">Close</button>
                <button type="button" class="btn btn-primary" id="viewEditBtn">
                    <i class="fa-solid fa-pen-to-square"></i> Edit Task
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Branded Delete Confirmation Modal -->
<div class="app-modal" id="deleteConfirmModal">
    <div class="modal-backdrop" onclick="closeAppModal('deleteConfirmModal')"></div>
    <div class="modal-card modal-card-sm">
        <div class="modal-card-header">
            <div class="header-title-wrap">
                <div class="modal-icon-badge" style="background: var(--adm-danger-light); color: var(--adm-danger);">
                    <i class="fa-solid fa-triangle-exclamation"></i>
                </div>
                <div>
                    <h2 class="modal-heading text-danger">Delete Task</h2>
                    <p class="modal-subheading">Confirm task removal from workflow pipeline.</p>
                </div>
            </div>
            <button type="button" class="modal-close-btn" onclick="closeAppModal('deleteConfirmModal')">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <div class="delete-modal-body">
            <p>Are you sure you want to delete this task? This action cannot be undone.</p>
            <div class="delete-task-preview" id="deleteTaskTitlePreview">Task Title</div>
        </div>

        <form method="POST" action="" id="deleteForm" class="modal-card-footer">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" id="delete_task_id" value="">

            <button type="button" class="btn btn-secondary" onclick="closeAppModal('deleteConfirmModal')">Cancel</button>
            <button type="submit" class="btn btn-danger">
                <i class="fa-solid fa-trash"></i>
                <span>Permanently Delete</span>
            </button>
        </form>
    </div>
</div>

<!-- Hidden Quick Status Form -->
<form method="POST" action="" id="quickStatusForm" style="display: none;">
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
    <input type="hidden" name="action" value="toggle_status">
    <input type="hidden" name="id" id="quick_task_id" value="">
    <input type="hidden" name="status" id="quick_task_status" value="">
</form>

<style>
/* =================================================================== */
/* TASK HUB MODERN STYLING SYSTEM (ALIGNED TO --ADM-* DESIGN TOKENS)    */
/* =================================================================== */

.task-management-wrapper {
    display: flex;
    flex-direction: column;
    gap: 24px;
    margin-bottom: 50px;
}

/* Page Header */
.page-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 20px;
    flex-wrap: wrap;
    margin-bottom: 4px;
}

.header-badges {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 8px;
}

.hub-pill, .pipeline-pill {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.6px;
    padding: 4px 10px;
    border-radius: var(--adm-radius-sm, 8px);
}

.hub-pill {
    background: var(--adm-brand-light, #eef2ff);
    color: var(--adm-brand, #4f46e5);
    border: 1px solid var(--adm-brand-border, #c7d2fe);
}

.pipeline-pill {
    background: rgba(16, 185, 129, 0.1);
    color: var(--adm-success, #059669);
    border: 1px solid rgba(16, 185, 129, 0.25);
}

.page-title {
    font-family: 'Plus Jakarta Sans', var(--font-sans, sans-serif);
    font-size: 26px;
    font-weight: 800;
    color: var(--adm-text-main, #09090b);
    letter-spacing: -0.02em;
    margin: 0 0 6px 0;
}

.page-subtitle {
    font-size: 14px;
    color: var(--adm-muted, #475569);
    margin: 0;
    max-width: 650px;
}

.header-actions {
    display: flex;
    align-items: center;
    gap: 12px;
}

/* Buttons */
.btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    padding: 10px 18px;
    font-size: 13.5px;
    font-weight: 600;
    border-radius: var(--adm-radius-md, 12px);
    border: 1px solid transparent;
    cursor: pointer;
    text-decoration: none;
    transition: all var(--transition-fast, 0.15s ease);
    white-space: nowrap;
}

.btn-primary {
    background: var(--adm-brand, #4f46e5);
    color: #ffffff;
    box-shadow: 0 2px 8px rgba(79, 70, 229, 0.25);
}
.btn-primary:hover {
    background: var(--adm-brand-hover, #4338ca);
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(79, 70, 229, 0.35);
}

.btn-secondary {
    background: var(--adm-surface, #ffffff);
    color: var(--adm-text-body, #1e293b);
    border-color: var(--adm-border, #cbd5e1);
}
.btn-secondary:hover {
    background: var(--adm-surface-subtle, #f1f5f9);
    border-color: var(--adm-brand, #4f46e5);
    color: var(--adm-brand, #4f46e5);
}

.btn-danger {
    background: var(--adm-danger, #e11d48);
    color: #ffffff;
}
.btn-danger:hover {
    background: #be123c;
}

.btn-outline-danger {
    background: transparent;
    color: var(--adm-danger, #e11d48);
    border-color: var(--adm-danger-border, #fecdd3);
}
.btn-outline-danger:hover {
    background: var(--adm-danger-light, #fff1f2);
}

/* Alerts */
.alert {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 14px 20px;
    border-radius: var(--adm-radius-md, 12px);
    font-size: 14px;
    font-weight: 500;
}
.alert-success {
    background: var(--adm-success-light, #ecfdf5);
    color: var(--adm-success, #059669);
    border: 1px solid rgba(5, 150, 105, 0.25);
}
.alert-error {
    background: var(--adm-danger-light, #fff1f2);
    color: var(--adm-danger, #e11d48);
    border: 1px solid var(--adm-danger-border, #fecdd3);
}
.alert-close {
    background: transparent;
    border: none;
    color: inherit;
    cursor: pointer;
    margin-left: auto;
    font-size: 16px;
    opacity: 0.7;
}
.alert-close:hover { opacity: 1; }

/* KPI Statistics Grid */
.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 16px;
}

.stat-card {
    background: var(--adm-surface, #ffffff);
    border: 1px solid var(--adm-border, #cbd5e1);
    border-radius: var(--adm-radius-lg, 16px);
    padding: 18px 20px;
    display: flex;
    align-items: center;
    gap: 16px;
    text-decoration: none;
    position: relative;
    transition: all var(--transition-fast, 0.15s ease);
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.04);
}
.stat-card:hover {
    transform: translateY(-2px);
    border-color: var(--adm-brand, #4f46e5);
    box-shadow: 0 6px 16px rgba(0, 0, 0, 0.06);
}
.stat-card.active-stat {
    border-color: var(--adm-brand, #4f46e5);
    background: var(--adm-brand-light, #eef2ff);
}

.stat-icon-wrapper {
    width: 44px;
    height: 44px;
    border-radius: var(--adm-radius-md, 12px);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 18px;
    flex-shrink: 0;
}

.stat-icon-wrapper.total       { background: rgba(79, 70, 229, 0.12); color: var(--adm-brand, #4f46e5); }
.stat-icon-wrapper.sent        { background: rgba(2, 132, 199, 0.12); color: #0284c7; }
.stat-icon-wrapper.in-progress { background: rgba(217, 119, 6, 0.12);  color: #d97706; }
.stat-icon-wrapper.verify      { background: rgba(124, 58, 237, 0.12);color: #7c3aed; }
.stat-icon-wrapper.completed   { background: rgba(5, 150, 105, 0.12); color: #059669; }
.stat-icon-wrapper.overdue     { background: rgba(225, 29, 72, 0.12);  color: #e11d48; }


.stat-details {
    flex: 1;
    min-width: 0;
}

.stat-value {
    font-size: 24px;
    font-weight: 800;
    color: var(--adm-text-main, #09090b);
    line-height: 1.1;
}

.stat-label {
    font-size: 12.5px;
    font-weight: 600;
    color: var(--adm-muted, #475569);
    margin-top: 4px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.stat-trend {
    font-size: 12px;
    color: var(--adm-faint, #64748b);
    opacity: 0.5;
    transition: all 0.2s ease;
}
.stat-card:hover .stat-trend {
    opacity: 1;
    transform: translateX(2px);
    color: var(--adm-brand, #4f46e5);
}

/* Control Panel & Quick Filters */
.task-control-panel {
    background: var(--adm-surface, #ffffff);
    border: 1px solid var(--adm-border, #cbd5e1);
    border-radius: var(--adm-radius-lg, 16px);
    padding: 16px 20px;
    display: flex;
    flex-direction: column;
    gap: 16px;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.04);
}

.panel-top-bar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    flex-wrap: wrap;
    border-bottom: 1px solid var(--adm-border-subtle, #e2e8f0);
    padding-bottom: 14px;
}

.status-quick-tabs {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
}

.tab-chip {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 6px 14px;
    border-radius: 20px;
    font-size: 13px;
    font-weight: 600;
    color: var(--adm-muted, #475569);
    background: var(--adm-surface-subtle, #f1f5f9);
    text-decoration: none;
    transition: all 0.15s ease;
}
.tab-chip:hover {
    background: var(--adm-brand-light, #eef2ff);
    color: var(--adm-brand, #4f46e5);
}
.tab-chip.active {
    background: var(--adm-brand, #4f46e5);
    color: #ffffff;
}

.tab-count {
    font-size: 11px;
    font-weight: 700;
    background: rgba(0, 0, 0, 0.08);
    padding: 1px 7px;
    border-radius: 10px;
}
.tab-chip.active .tab-count {
    background: rgba(255, 255, 255, 0.25);
    color: #ffffff;
}
.tab-chip-overdue .count-alert {
    background: var(--adm-danger, #e11d48);
    color: #ffffff;
}

/* View Switcher */
.view-switcher-group {
    display: flex;
    align-items: center;
    gap: 10px;
}
.view-label {
    font-size: 12.5px;
    font-weight: 600;
    color: var(--adm-muted, #475569);
}
.view-btn-pill {
    display: flex;
    background: var(--adm-surface-subtle, #f1f5f9);
    padding: 3px;
    border-radius: 12px;
    border: 1px solid var(--adm-border, #cbd5e1);
}
.view-btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 6px 14px;
    border-radius: 9px;
    font-size: 12.5px;
    font-weight: 600;
    background: transparent;
    border: none;
    color: var(--adm-muted, #475569);
    cursor: pointer;
    transition: all 0.15s ease;
}
.view-btn:hover {
    color: var(--adm-text-main, #09090b);
}
.view-btn.active {
    background: var(--adm-surface, #ffffff);
    color: var(--adm-brand, #4f46e5);
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
}

/* Filter Inputs Form */
.filter-inputs-form {
    display: flex;
    align-items: center;
    gap: 14px;
    flex-wrap: wrap;
}

.search-box-wrapper {
    position: relative;
    flex: 1;
    min-width: 0;
    max-width: 100%;
    display: flex;
    align-items: center;
}

.search-icon {
    position: absolute;
    left: 14px;
    color: var(--adm-faint, #64748b);
    font-size: 14px;
    pointer-events: none;
}

.task-input.search-input {
    width: 100%;
    padding: 10px 42px 10px 38px;
    font-size: 13.5px;
    border: 1px solid var(--adm-border, #cbd5e1);
    border-radius: var(--adm-radius-md, 12px);
    background: var(--adm-surface, #ffffff);
    color: var(--adm-text-main, #09090b);
    transition: all 0.15s ease;
}
.task-input.search-input:focus {
    border-color: var(--adm-brand, #4f46e5);
    box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.15);
    outline: none;
}

.kbd-hint {
    position: absolute;
    right: 14px;
    font-size: 11px;
    font-weight: 700;
    color: var(--adm-faint, #64748b);
    background: var(--adm-surface-subtle, #f1f5f9);
    padding: 2px 7px;
    border-radius: 6px;
    border: 1px solid var(--adm-border, #cbd5e1);
    pointer-events: none;
}

.clear-search-btn {
    position: absolute;
    right: 38px;
    color: var(--adm-faint, #64748b);
    text-decoration: none;
    font-size: 13px;
    padding: 4px;
}
.clear-search-btn:hover { color: var(--adm-text-main, #09090b); }

.filter-select-group {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
}

.select-wrapper {
    position: relative;
    display: flex;
    align-items: center;
}
.select-icon {
    position: absolute;
    left: 12px;
    font-size: 12px;
    color: var(--adm-faint, #64748b);
    pointer-events: none;
}
.task-select {
    padding: 9px 14px 9px 32px;
    font-size: 13px;
    font-weight: 500;
    border-radius: var(--adm-radius-md, 12px);
    border: 1px solid var(--adm-border, #cbd5e1);
    background: var(--adm-surface, #ffffff);
    color: var(--adm-text-body, #1e293b);
    cursor: pointer;
    transition: all 0.15s ease;
}
.task-select:focus {
    border-color: var(--adm-brand, #4f46e5);
    outline: none;
}

/* =================================================================== */
/* 1. ENTERPRISE TABLE VIEW STYLES                                      */
/* =================================================================== */

.table-card {
    background: var(--adm-surface, #ffffff);
    border: 1px solid var(--adm-border, #cbd5e1);
    border-radius: var(--adm-radius-lg, 16px);
    overflow: hidden;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.04);
}

.table-responsive {
    width: 100%;
    overflow-x: auto;
}

.enterprise-task-table {
    width: 100%;
    border-collapse: collapse;
    text-align: left;
}

.enterprise-task-table thead {
    background: var(--adm-surface-subtle, #f1f5f9);
    border-bottom: 1px solid var(--adm-border, #cbd5e1);
}

.enterprise-task-table th {
    padding: 14px 18px;
    font-size: 12px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.6px;
    color: var(--adm-muted, #475569);
    white-space: nowrap;
}

.enterprise-task-table td {
    padding: 16px 18px;
    border-bottom: 1px solid var(--adm-border-subtle, #e2e8f0);
    vertical-align: middle;
    font-size: 13.5px;
}

.enterprise-task-table tbody tr {
    transition: background-color 0.12s ease;
}
.enterprise-task-table tbody tr:hover {
    background-color: rgba(79, 70, 229, 0.03);
}

.col-index .index-num {
    font-size: 12px;
    font-weight: 700;
    color: var(--adm-faint, #64748b);
}

/* Task Title cell */
.task-cell-main {
    display: flex;
    flex-direction: column;
    gap: 4px;
}
.task-heading {
    display: flex;
    align-items: center;
    gap: 8px;
}
.task-title-link {
    font-size: 14.5px;
    font-weight: 700;
    color: var(--adm-text-main, #09090b);
    text-decoration: none;
    line-height: 1.3;
    overflow-wrap: break-word;
    word-break: break-word;
}
.task-title-link:hover {
    color: var(--adm-brand, #4f46e5);
}
.task-excerpt {
    font-size: 12.5px;
    color: var(--adm-muted, #475569);
    line-height: 1.4;
}
.badge-mini-overdue {
    display: inline-flex;
    align-items: center;
    font-size: 11px;
    color: var(--adm-danger, #e11d48);
}

/* Assignee Cell */
.assignee-cell {
    display: flex;
    align-items: center;
    gap: 10px;
}
.assignee-img, .assignee-initials {
    width: 36px;
    height: 36px;
    border-radius: 50%;
    object-fit: cover;
    flex-shrink: 0;
}
.assignee-initials {
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 13px;
    font-weight: 700;
    background: linear-gradient(135deg, #4f46e5, #8b5cf6);
    color: #ffffff;
}
.assignee-meta {
    display: flex;
    flex-direction: column;
    min-width: 0;
}
.assignee-full-name {
    font-size: 13.5px;
    font-weight: 700;
    color: var(--adm-text-main, #09090b);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.assignee-sub {
    font-size: 11.5px;
    color: var(--adm-muted, #475569);
}
.code-tag {
    font-weight: 700;
    color: var(--adm-brand, #4f46e5);
}

/* Priority Pills */
.priority-pill {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 5px 10px;
    border-radius: 8px;
    font-size: 12px;
    font-weight: 700;
    white-space: nowrap;
}
.priority-critical { background: rgba(225, 29, 72, 0.12); color: #e11d48; }
.priority-high     { background: rgba(249, 115, 22, 0.12); color: #f97316; }
.priority-medium   { background: rgba(59, 130, 246, 0.12); color: #2563eb; }
.priority-low      { background: rgba(16, 185, 129, 0.12); color: #059669; }

.priority-pill.mini {
    font-size: 10.5px;
    padding: 3px 8px;
}

/* Inline Status Dropdown Select */
.status-inline-select {
    padding: 6px 12px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 700;
    border: 1px solid transparent;
    cursor: pointer;
    outline: none;
    transition: all 0.15s ease;
}
.status-inline-select.status-val-sent        { background: rgba(2, 132, 199, 0.1);  color: #0284c7; border-color: rgba(2, 132, 199, 0.25); }
.status-inline-select.status-val-in_progress { background: rgba(217, 119, 6, 0.1);  color: #d97706; border-color: rgba(217, 119, 6, 0.25); }
.status-inline-select.status-val-verify      { background: rgba(124, 58, 237, 0.1); color: #7c3aed; border-color: rgba(124, 58, 237, 0.25); }
.status-inline-select.status-val-completed   { background: rgba(5, 150, 105, 0.1);  color: #059669; border-color: rgba(5, 150, 105, 0.25); }

/* Urgency Pills */
.urgency-pill {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 4px 10px;
    border-radius: 8px;
    font-size: 11.5px;
    font-weight: 600;
    white-space: nowrap;
}
.urgency-pill.mini {
    font-size: 10.5px;
    padding: 2px 7px;
}
.urgency-overdue  { background: rgba(225, 29, 72, 0.12); color: #e11d48; }
.urgency-today    { background: rgba(217, 119, 6, 0.12);  color: #d97706; }
.urgency-tomorrow { background: rgba(79, 70, 229, 0.12); color: #4f46e5; }
.urgency-upcoming { background: rgba(100, 116, 139, 0.1); color: #64748b; }
.urgency-done     { background: rgba(5, 150, 105, 0.12);  color: #059669; }
.urgency-none     { background: rgba(148, 163, 184, 0.1); color: #94a3b8; }

.date-text {
    font-size: 12.5px;
    color: var(--adm-muted, #475569);
}

/* Actions Cluster */
.actions-cluster {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: 6px;
}
.action-btn {
    width: 32px;
    height: 32px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 8px;
    border: 1px solid transparent;
    cursor: pointer;
    font-size: 13px;
    transition: all 0.15s ease;
}
.btn-view {
    background: var(--adm-surface-subtle, #f1f5f9);
    color: var(--adm-text-body, #1e293b);
}
.btn-view:hover {
    background: var(--adm-brand-light, #eef2ff);
    color: var(--adm-brand, #4f46e5);
}
.btn-edit {
    background: rgba(79, 70, 229, 0.08);
    color: var(--adm-brand, #4f46e5);
}
.btn-edit:hover {
    background: var(--adm-brand, #4f46e5);
    color: #ffffff;
}
.btn-delete {
    background: rgba(225, 29, 72, 0.08);
    color: var(--adm-danger, #e11d48);
}
.btn-delete:hover {
    background: var(--adm-danger, #e11d48);
    color: #ffffff;
}

/* =================================================================== */
/* 2. KANBAN PIPELINE BOARD VIEW STYLES                                 */
/* =================================================================== */

.kanban-board-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 18px;
    align-items: flex-start;
}
@media (max-width: 1200px) {
    .kanban-board-grid {
        grid-template-columns: repeat(2, 1fr);
    }
}
@media (max-width: 680px) {
    .kanban-board-grid {
        grid-template-columns: 1fr;
    }
}

.kanban-column {
    background: var(--adm-surface-subtle, #f1f5f9);
    border: 1px solid var(--adm-border, #cbd5e1);
    border-radius: var(--adm-radius-lg, 16px);
    display: flex;
    flex-direction: column;
    min-height: 500px;
    overflow: hidden;
}

.kanban-column-header {
    padding: 14px 18px;
    background: var(--adm-surface, #ffffff);
    border-bottom: 1px solid var(--adm-border, #cbd5e1);
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.col-title-group {
    display: flex;
    align-items: center;
    gap: 8px;
}
.kanban-indicator {
    width: 8px;
    height: 8px;
    border-radius: 50%;
}
.indicator-sent        { background: #0284c7; }
.indicator-in-progress { background: #d97706; }
.indicator-verify      { background: #7c3aed; }
.indicator-completed   { background: #059669; }

.kanban-title {
    margin: 0;
    font-size: 14px;
    font-weight: 700;
    color: var(--adm-text-main, #09090b);
}

.kanban-count {
    font-size: 12px;
    font-weight: 700;
    background: var(--adm-surface-subtle, #f1f5f9);
    color: var(--adm-muted, #475569);
    padding: 2px 8px;
    border-radius: 12px;
    border: 1px solid var(--adm-border, #cbd5e1);
}

.kanban-cards-stack {
    padding: 14px;
    display: flex;
    flex-direction: column;
    gap: 12px;
    flex: 1;
}

.kanban-card {
    background: var(--adm-surface, #ffffff);
    border: 1px solid var(--adm-border, #cbd5e1);
    border-radius: var(--adm-radius-md, 12px);
    padding: 14px;
    display: flex;
    flex-direction: column;
    gap: 10px;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.04);
    transition: all 0.15s ease;
}
.kanban-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
    border-color: var(--adm-brand, #4f46e5);
}

.priority-border-critical { border-left: 3px solid #e11d48; }
.priority-border-high     { border-left: 3px solid #f97316; }
.priority-border-medium   { border-left: 3px solid #3b82f6; }
.priority-border-low      { border-left: 3px solid #10b981; }

.kanban-card-top {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 6px;
}

.kanban-card-title {
    margin: 0;
    font-size: 14px;
    font-weight: 700;
    color: var(--adm-text-main, #09090b);
    line-height: 1.35;
    cursor: pointer;
    overflow-wrap: break-word;
    word-break: break-word;
}
.kanban-card-title:hover {
    color: var(--adm-brand, #4f46e5);
}
.kanban-card-title.text-done {
    text-decoration: line-through;
    opacity: 0.75;
}

.kanban-card-desc {
    margin: 0;
    font-size: 12px;
    color: var(--adm-muted, #475569);
    line-height: 1.45;
    overflow-wrap: break-word;
    word-break: break-word;
}

.kanban-card-footer {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    padding-top: 8px;
    border-top: 1px solid var(--adm-border-subtle, #e2e8f0);
}

.kanban-assignee {
    display: flex;
    align-items: center;
    gap: 6px;
    min-width: 0;
}
.assignee-img-sm, .assignee-initials-sm {
    width: 22px;
    height: 22px;
    border-radius: 50%;
    object-fit: cover;
    flex-shrink: 0;
}
.assignee-initials-sm {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 9px;
    font-weight: 700;
    background: #4f46e5;
    color: #ffffff;
}
.assignee-name-sm {
    font-size: 11.5px;
    font-weight: 600;
    color: var(--adm-text-body, #1e293b);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.btn-advance {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 3px 8px;
    font-size: 11px;
    font-weight: 700;
    border-radius: 6px;
    border: 1px solid rgba(217, 119, 6, 0.3);
    background: rgba(217, 119, 6, 0.1);
    color: #d97706;
    cursor: pointer;
    transition: all 0.15s ease;
}
.btn-advance:hover {
    background: #d97706;
    color: #ffffff;
}

.btn-advance-verify {
    border-color: rgba(124, 58, 237, 0.3);
    background: rgba(124, 58, 237, 0.1);
    color: #7c3aed;
}
.btn-advance-verify:hover {
    background: #7c3aed;
    color: #ffffff;
}

.btn-advance-complete {
    border-color: rgba(5, 150, 105, 0.3);
    background: rgba(5, 150, 105, 0.1);
    color: #059669;
}
.btn-advance-complete:hover {
    background: #059669;
    color: #ffffff;
}

.btn-reopen {
    padding: 3px 6px;
    font-size: 11px;
    border-radius: 6px;
    border: 1px solid var(--adm-border, #cbd5e1);
    background: var(--adm-surface-subtle, #f1f5f9);
    color: var(--adm-muted, #475569);
    cursor: pointer;
}
.btn-reopen:hover {
    color: var(--adm-brand, #4f46e5);
}

.kanban-empty-slot {
    text-align: center;
    padding: 30px 10px;
    color: var(--adm-faint, #64748b);
    font-size: 12px;
    font-style: italic;
}

/* =================================================================== */
/* 3. MODERN CARD GRID VIEW STYLES                                      */
/* =================================================================== */

.modern-task-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(min(100%, 320px), 1fr));
    gap: 20px;
}

.modern-card {
    background: var(--adm-surface, #ffffff);
    border: 1px solid var(--adm-border, #cbd5e1);
    border-radius: var(--adm-radius-lg, 16px);
    display: flex;
    flex-direction: column;
    transition: all 0.15s ease;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.04);
}
.modern-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 8px 20px rgba(0, 0, 0, 0.08);
    border-color: var(--adm-brand, #4f46e5);
}

.modern-card-header {
    padding: 16px 20px 10px 20px;
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.status-pill-select {
    padding: 4px 10px;
    border-radius: 12px;
    font-size: 11.5px;
    font-weight: 700;
    border: 1px solid var(--adm-border, #cbd5e1);
    background: var(--adm-surface-subtle, #f1f5f9);
    color: var(--adm-text-body, #1e293b);
    cursor: pointer;
}

.modern-card-body {
    padding: 10px 20px 16px 20px;
    flex: 1;
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.modern-card-title {
    margin: 0;
    font-size: 16px;
    font-weight: 700;
    color: var(--adm-text-main, #09090b);
    line-height: 1.35;
    cursor: pointer;
}
.modern-card-title:hover {
    color: var(--adm-brand, #4f46e5);
}

.modern-card-desc {
    margin: 0;
    font-size: 13px;
    color: var(--adm-muted, #475569);
    line-height: 1.5;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}

.modern-card-meta {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-top: auto;
    font-size: 12px;
    color: var(--adm-faint, #64748b);
}
.due-cal-date {
    display: inline-flex;
    align-items: center;
    gap: 5px;
}

.modern-card-footer {
    padding: 14px 20px;
    background: var(--adm-surface-subtle, #f1f5f9);
    border-top: 1px solid var(--adm-border-subtle, #e2e8f0);
    border-bottom-left-radius: var(--adm-radius-lg, 16px);
    border-bottom-right-radius: var(--adm-radius-lg, 16px);
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.footer-assignee {
    display: flex;
    align-items: center;
    gap: 10px;
    min-width: 0;
}
.footer-assignee-text {
    display: flex;
    flex-direction: column;
    min-width: 0;
}
.footer-emp-name {
    font-size: 13px;
    font-weight: 700;
    color: var(--adm-text-main, #09090b);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.footer-emp-sub {
    font-size: 11px;
    color: var(--adm-muted, #475569);
}

.footer-actions {
    display: flex;
    align-items: center;
    gap: 6px;
}

/* Empty State */
.empty-state-card {
    text-align: center;
    padding: 60px 24px;
    background: var(--adm-surface, #ffffff);
    border: 1px solid var(--adm-border, #cbd5e1);
    border-radius: var(--adm-radius-xl, 20px);
    max-width: 550px;
    margin: 30px auto;
}
.empty-icon-circle {
    width: 68px;
    height: 68px;
    border-radius: 50%;
    background: var(--adm-brand-light, #eef2ff);
    color: var(--adm-brand, #4f46e5);
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 28px;
    margin-bottom: 18px;
}
.empty-title {
    font-size: 20px;
    font-weight: 800;
    color: var(--adm-text-main, #09090b);
    margin: 0 0 8px 0;
}
.empty-desc {
    font-size: 14px;
    color: var(--adm-muted, #475569);
    margin: 0 0 24px 0;
    line-height: 1.5;
}
.empty-actions {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 12px;
}

/* =================================================================== */
/* MODAL SYSTEM (SPACIOUS, NO CUTOFFS, DARK MODE COMPLIANT)            */
/* =================================================================== */

.app-modal {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    width: 100%;
    height: 100%;
    display: none;
    align-items: center;
    justify-content: center;
    z-index: 99999;
    padding: 20px;
}
.app-modal.show {
    display: flex;
}

.modal-backdrop {
    position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(9, 9, 11, 0.65);
    backdrop-filter: blur(4px);
}

.modal-card {
    position: relative;
    z-index: 2;
    background: var(--adm-surface, #ffffff);
    border: 1px solid var(--adm-border, #cbd5e1);
    border-radius: var(--adm-radius-xl, 20px);
    width: 100%;
    max-width: 640px;
    max-height: 90vh;
    display: flex;
    flex-direction: column;
    overflow: hidden;
    box-shadow: 0 20px 40px rgba(0, 0, 0, 0.25);
}
.modal-card-lg { max-width: 720px; }
.modal-card-sm { max-width: 480px; }

.modal-card-header {
    padding: 20px 24px;
    border-bottom: 1px solid var(--adm-border, #cbd5e1);
    display: flex;
    align-items: center;
    justify-content: space-between;
    background: var(--adm-surface, #ffffff);
}

.header-title-wrap {
    display: flex;
    align-items: center;
    gap: 14px;
}

.modal-icon-badge {
    width: 42px;
    height: 42px;
    border-radius: 12px;
    background: var(--adm-brand-light, #eef2ff);
    color: var(--adm-brand, #4f46e5);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 18px;
    flex-shrink: 0;
}

.modal-heading {
    margin: 0;
    font-size: 18px;
    font-weight: 800;
    color: var(--adm-text-main, #09090b);
}

.modal-subheading {
    margin: 2px 0 0 0;
    font-size: 13px;
    color: var(--adm-muted, #475569);
}

.modal-close-btn {
    background: var(--adm-surface-subtle, #f1f5f9);
    border: none;
    width: 32px;
    height: 32px;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--adm-muted, #475569);
    cursor: pointer;
    transition: all 0.15s ease;
}
.modal-close-btn:hover {
    background: var(--adm-danger-light, #fff1f2);
    color: var(--adm-danger, #e11d48);
}

.modal-body-form {
    padding: 20px 24px;
    overflow-y: auto;
    display: flex;
    flex-direction: column;
    gap: 14px;
}

.modal-body-details {
    padding: 24px;
    overflow-y: auto;
    display: flex;
    flex-direction: column;
    gap: 20px;
}

.form-grid-2 {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
}
@media (max-width: 580px) {
    .form-grid-2 { grid-template-columns: 1fr; }
}

.input-field-group {
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.field-label {
    font-size: 13px;
    font-weight: 700;
    color: var(--adm-text-main, #09090b);
}
.required-star { color: var(--adm-danger, #e11d48); }

.input-icon-wrap {
    position: relative;
    display: flex;
    align-items: center;
}
.field-icon {
    position: absolute;
    left: 14px;
    font-size: 14px;
    color: var(--adm-faint, #64748b);
    pointer-events: none;
}

.modal-input {
    width: 100%;
    padding: 11px 14px 11px 38px;
    font-size: 13.5px;
    border-radius: var(--adm-radius-md, 12px);
    border: 1px solid var(--adm-border, #cbd5e1);
    background: var(--adm-surface, #ffffff);
    color: var(--adm-text-main, #09090b);
    transition: all 0.15s ease;
    outline: none;
}
.modal-input:focus {
    border-color: var(--adm-brand, #4f46e5);
    box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.15);
}

.modal-textarea {
    padding: 12px 16px;
    resize: vertical;
    min-height: 90px;
}

.modal-card-footer {
    padding: 16px 24px;
    border-top: 1px solid var(--adm-border, #cbd5e1);
    background: var(--adm-surface-subtle, #f1f5f9);
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: 12px;
}
.modal-card-footer .footer-left { margin-right: auto; }
.modal-card-footer .footer-right { display: flex; gap: 10px; }

/* Details Drawer Specs */
.details-top-badges {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
}
.details-section {
    display: flex;
    flex-direction: column;
    gap: 8px;
}
.details-label {
    margin: 0;
    font-size: 13px;
    font-weight: 700;
    color: var(--adm-muted, #475569);
    display: flex;
    align-items: center;
    gap: 6px;
}
.details-description-box {
    background: var(--adm-surface-subtle, #f1f5f9);
    border: 1px solid var(--adm-border, #cbd5e1);
    border-radius: var(--adm-radius-md, 12px);
    padding: 16px;
    font-size: 14px;
    line-height: 1.6;
    color: var(--adm-text-main, #09090b);
    white-space: pre-wrap;
    word-break: break-word;
}
.details-grid-meta {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 14px;
}
@media (max-width: 500px) {
    .details-grid-meta { grid-template-columns: 1fr; }
}
.meta-box {
    background: var(--adm-surface-subtle, #f1f5f9);
    border: 1px solid var(--adm-border-subtle, #e2e8f0);
    border-radius: var(--adm-radius-md, 12px);
    padding: 12px 16px;
    display: flex;
    flex-direction: column;
    gap: 6px;
}
.meta-label {
    font-size: 11.5px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: var(--adm-faint, #64748b);
}
.meta-val {
    font-size: 13.5px;
    font-weight: 600;
    color: var(--adm-text-main, #09090b);
    display: flex;
    align-items: center;
    gap: 8px;
}

/* Delete Confirm Body */
.delete-modal-body {
    padding: 20px 24px;
    font-size: 14px;
    color: var(--adm-text-body, #1e293b);
}
.delete-task-preview {
    margin-top: 12px;
    padding: 12px 16px;
    background: var(--adm-surface-subtle, #f1f5f9);
    border-left: 3px solid var(--adm-danger, #e11d48);
    border-radius: var(--adm-radius-sm, 8px);
    font-weight: 700;
    color: var(--adm-text-main, #09090b);
}

/* =================================================================== */
/* DARK THEME ADAPTATIONS                                              */
/* =================================================================== */
[data-theme="dark"] .task-control-panel,
[data-theme="dark"] .stat-card,
[data-theme="dark"] .table-card,
[data-theme="dark"] .modern-card,
[data-theme="dark"] .modal-card,
[data-theme="dark"] .modal-card-header,
[data-theme="dark"] .kanban-card,
[data-theme="dark"] .kanban-column-header {
    background: var(--adm-surface, #18181b);
    border-color: var(--adm-border, #27272a);
}

[data-theme="dark"] .kanban-column,
[data-theme="dark"] .enterprise-task-table thead,
[data-theme="dark"] .tab-chip,
[data-theme="dark"] .view-btn-pill,
[data-theme="dark"] .modern-card-footer,
[data-theme="dark"] .modal-card-footer,
[data-theme="dark"] .details-description-box,
[data-theme="dark"] .meta-box,
[data-theme="dark"] .delete-task-preview {
    background: var(--adm-surface-subtle, #27272a);
    border-color: var(--adm-border, #3f3f46);
}

[data-theme="dark"] .task-input,
[data-theme="dark"] .task-select,
[data-theme="dark"] .modal-input {
    background: #18181b;
    border-color: #3f3f46;
    color: #f4f4f5;
}

[data-theme="dark"] .view-btn.active {
    background: #27272a;
    color: #818cf8;
}

[data-theme="dark"] .enterprise-task-table td {
    border-bottom-color: #27272a;
}
[data-theme="dark"] .enterprise-task-table tbody tr:hover {
    background-color: rgba(99, 102, 241, 0.08);
}

@media (max-width: 480px) {
    .task-control-panel { padding: 12px; }
    .view-switcher-group { width: 100%; justify-content: space-between; }
    .view-btn-pill { flex: 1; }
    .view-btn { padding: 6px 8px; flex: 1; justify-content: center; font-size: 11.5px; }
    .filter-inputs-form { flex-direction: column; align-items: stretch; gap: 8px; }
    .filter-select { width: 100%; }
    .btn-create-task { width: 100%; justify-content: center; }
    .task-top-actions { width: 100%; }
    .status-quick-tabs { overflow-x: auto; width: 100%; flex-wrap: nowrap; scrollbar-width: none; -webkit-overflow-scrolling: touch; }
    .status-quick-tabs::-webkit-scrollbar { display: none; }
    .tab-chip { white-space: nowrap; flex-shrink: 0; }
}
</style>

<script>
// ===================================================================
// TASK WORKFORCE SCRIPT & VIEW SWITCHER
// ===================================================================

document.addEventListener('DOMContentLoaded', function() {
    // 1. Restore persistent view preference
    const savedView = localStorage.getItem('admin_task_view_mode') || 'table';
    switchTaskView(savedView);

    // 2. Global '/' shortcut to focus search
    document.addEventListener('keydown', function(e) {
        if (e.key === '/' && !['INPUT', 'TEXTAREA', 'SELECT'].includes(document.activeElement.tagName)) {
            e.preventDefault();
            const searchInput = document.getElementById('taskSearchInput');
            if (searchInput) {
                searchInput.focus();
                searchInput.select();
            }
        }
        if (e.key === 'Escape') {
            closeAllAppModals();
        }
    });

    // 3. Client-side real-time table row filtering
    const searchInput = document.getElementById('taskSearchInput');
    if (searchInput) {
        searchInput.addEventListener('input', function(e) {
            const query = e.target.value.toLowerCase().trim();
            const rows = document.querySelectorAll('#enterpriseTaskTable tbody tr.task-row');
            rows.forEach(row => {
                const text = row.getAttribute('data-search-text') || '';
                if (!query || text.includes(query)) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });
        });
    }
});

// Switch view between Table, Kanban, and Grid
function switchTaskView(mode) {
    const tableView = document.getElementById('taskTableView');
    const kanbanView = document.getElementById('taskKanbanView');
    const gridView = document.getElementById('taskGridView');

    const btnTable = document.getElementById('viewBtnTable');
    const btnKanban = document.getElementById('viewBtnKanban');
    const btnGrid = document.getElementById('viewBtnGrid');

    if (!tableView || !kanbanView || !gridView) return;

    // Reset visibility
    tableView.style.display = 'none';
    kanbanView.style.display = 'none';
    gridView.style.display = 'none';

    btnTable.classList.remove('active');
    btnKanban.classList.remove('active');
    btnGrid.classList.remove('active');

    if (mode === 'kanban') {
        kanbanView.style.display = 'block';
        btnKanban.classList.add('active');
    } else if (mode === 'grid') {
        gridView.style.display = 'block';
        btnGrid.classList.add('active');
    } else {
        tableView.style.display = 'block';
        btnTable.classList.add('active');
        mode = 'table';
    }

    localStorage.setItem('admin_task_view_mode', mode);
}

// Inline Status Change Handler
function changeTaskStatusInline(taskId, newStatus) {
    const form = document.getElementById('quickStatusForm');
    const idInput = document.getElementById('quick_task_id');
    const statusInput = document.getElementById('quick_task_status');

    if (!form || !idInput || !statusInput) return;

    idInput.value = taskId;
    statusInput.value = newStatus;

    form.submit();
}

// Modal Handlers
function openAppModal(id) {
    const modal = document.getElementById(id);
    if (modal) {
        modal.classList.add('show');
        document.body.style.overflow = 'hidden';
    }
}

function closeAppModal(id) {
    const modal = document.getElementById(id);
    if (modal) {
        modal.classList.remove('show');
        document.body.style.overflow = '';
    }
}

function closeAllAppModals() {
    document.querySelectorAll('.app-modal.show').forEach(m => {
        m.classList.remove('show');
    });
    document.body.style.overflow = '';
}

// Open Add Task Modal
function openAddTaskModal() {
    document.getElementById('form_task_id').value = '';
    document.getElementById('form_title').value = '';
    document.getElementById('form_description').value = '';
    document.getElementById('form_priority').value = 'medium';
    document.getElementById('form_status').value = 'sent';
    
    // Set default due date (+3 days)
    const d = new Date();
    d.setDate(d.getDate() + 3);
    document.getElementById('form_due_date').value = d.toISOString().split('T')[0];

    document.getElementById('modalTitle').textContent = 'Assign New Task';
    document.getElementById('modalSubtitle').textContent = 'Create and assign an agile work assignment for your team.';
    document.getElementById('modalSubmitBtn').innerHTML = '<i class="fa-solid fa-check"></i> <span>Assign Task</span>';
    document.getElementById('modalIconBadge').innerHTML = '<i class="fa-solid fa-plus"></i>';

    openAppModal('taskModal');
}

// Open Edit Task Modal
function openEditTaskModal(task) {
    closeAppModal('taskDetailsModal');

    document.getElementById('form_task_id').value = task.id;
    document.getElementById('form_employee_id').value = task.employee_id;
    document.getElementById('form_title').value = task.title;
    document.getElementById('form_description').value = task.description || '';
    document.getElementById('form_priority').value = task.priority;
    document.getElementById('form_status').value = task.status;
    document.getElementById('form_due_date').value = task.due_date || '';

    document.getElementById('modalTitle').textContent = 'Edit Task Assignment';
    document.getElementById('modalSubtitle').textContent = 'Update parameters, reassign team member, or adjust timeline.';
    document.getElementById('modalSubmitBtn').innerHTML = '<i class="fa-solid fa-floppy-disk"></i> <span>Save Changes</span>';
    document.getElementById('modalIconBadge').innerHTML = '<i class="fa-solid fa-pen-to-square"></i>';

    openAppModal('taskModal');
}

// Open Task Details Modal
function openTaskDetails(task) {
    document.getElementById('viewTaskHeading').textContent = task.title;
    document.getElementById('viewTaskIdTag').textContent = 'TASK-' + String(task.id).padStart(4, '0');
    document.getElementById('viewDescriptionBox').textContent = task.description || 'No detailed instructions or requirements provided.';

    // Priority Pill
    const priEl = document.getElementById('viewPriorityPill');
    priEl.className = 'priority-pill priority-' + task.priority;
    priEl.textContent = task.priority.toUpperCase();

    // Status Pill
    const staEl = document.getElementById('viewStatusPill');
    staEl.className = 'status-inline-select status-val-' + task.status;
    staEl.textContent = task.status.replace('_', ' ').toUpperCase();

    // Assignee Box
    const empName = (task.first_name + ' ' + task.last_name).trim();
    const empDept = task.department || 'General';
    const empCode = task.emp_code || ('EMP-' + String(task.employee_id).padStart(3, '0'));
    const empInitials = (task.first_name ? task.first_name[0] : '') + (task.last_name ? task.last_name[0] : '');

    let avatarHtml = '';
    if (task.avatar) {
        avatarHtml = `<img src="/assets/uploads/avatars/${task.avatar}" class="assignee-img" alt="${empName}">`;
    } else {
        avatarHtml = `<div class="assignee-initials">${empInitials.toUpperCase()}</div>`;
    }

    document.getElementById('viewAssigneeBox').innerHTML = `
        <div class="assignee-cell">
            ${avatarHtml}
            <div class="assignee-meta">
                <span class="assignee-full-name">${empName}</span>
                <span class="assignee-sub"><span class="code-tag">${empCode}</span> &bull; ${empDept}</span>
            </div>
        </div>
    `;

    // Due Date Box
    const dueBox = document.getElementById('viewDueDateBox');
    if (task.due_date) {
        dueBox.innerHTML = `<i class="fa-regular fa-calendar-check"></i> <span>${task.due_date}</span>`;
    } else {
        dueBox.innerHTML = `<i class="fa-regular fa-calendar"></i> <span>No Due Date</span>`;
    }

    // Created At Box
    document.getElementById('viewCreatedAtBox').innerHTML = `<i class="fa-regular fa-clock"></i> <span>${task.created_at || 'Just now'}</span>`;
    document.getElementById('viewUpdatedAtBox').innerHTML = `<i class="fa-solid fa-arrows-rotate"></i> <span>${task.updated_at || 'Never'}</span>`;

    // Action button wiring
    document.getElementById('viewEditBtn').onclick = function() {
        openEditTaskModal(task);
    };
    document.getElementById('viewDeleteBtn').onclick = function() {
        closeAppModal('taskDetailsModal');
        promptDeleteTask(task.id, task.title);
    };

    openAppModal('taskDetailsModal');
}

// Prompt Delete Confirmation Modal
function promptDeleteTask(taskId, taskTitle) {
    document.getElementById('delete_task_id').value = taskId;
    document.getElementById('deleteTaskTitlePreview').textContent = taskTitle;
    openAppModal('deleteConfirmModal');
}
</script>

<?php include 'footer.php'; ?>
