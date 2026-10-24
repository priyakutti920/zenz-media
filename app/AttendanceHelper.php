<?php

require_once __DIR__ . '/security.php';

class AttendanceHelper {

    /**
     * Get the currently active shift settings
     */
    public static function getActiveShift($pdo) {
        try {
            $stmt = $pdo->query("SELECT * FROM shift_settings WHERE is_active = 1 LIMIT 1");
            return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (PDOException $e) {
            error_log("Error fetching active shift: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Calculate delay in minutes
     * Returns 0 if not delayed
     */
    public static function calculateDelay($checkInTime, $shift) {
        if (!$shift || empty($checkInTime)) return 0;

        $checkIn = strtotime($checkInTime);
        $shiftStartTime = $shift['checkin_start'] ?? ($shift['start_time'] ?? '09:00:00');
        $shiftStart = strtotime(date('Y-m-d') . ' ' . $shiftStartTime);
        
        // Add grace period to start time
        $graceMinutes = (int)($shift['grace_minutes'] ?? 0);
        $allowedStart = $shiftStart + ($graceMinutes * 60);

        if ($checkIn > $allowedStart) {
            return (int)floor(($checkIn - $shiftStart) / 60);
        }
        return 0;
    }

    /**
     * Calculate early exit in minutes
     * Returns 0 if not leaving early
     */
    public static function calculateEarlyExit($checkOutTime, $shift) {
        if (!$shift || empty($checkOutTime)) return 0;

        $checkOut = strtotime($checkOutTime);
        $shiftEndTime = $shift['checkout_time'] ?? ($shift['end_time'] ?? '18:00:00');
        $shiftEnd = strtotime(date('Y-m-d') . ' ' . $shiftEndTime);

        if ($checkOut < $shiftEnd) {
            return (int)floor(($shiftEnd - $checkOut) / 60);
        }
        return 0;
    }

    /**
     * Calculate total working hours and minutes
     * Returns array ['minutes' => int, 'hours_formatted' => float]
     */
    public static function calculateWorkingHours($checkInTime, $checkOutTime) {
        if (empty($checkInTime) || empty($checkOutTime)) {
            return ['minutes' => 0, 'hours_formatted' => 0.00];
        }

        $in = strtotime($checkInTime);
        $out = strtotime($checkOutTime);
        
        if ($out < $in) {
            // Check out is on the next day
            $out += 86400; 
        }

        $diffMinutes = (int)floor(($out - $in) / 60);
        $hoursFormatted = round($diffMinutes / 60, 2);

        return [
            'minutes' => $diffMinutes,
            'hours_formatted' => $hoursFormatted
        ];
    }

    /**
     * Determine attendance status based on delay, early exit, and checkout state
     */
    public static function getAttendanceStatus($delayMins, $earlyExitMins, $shift, $isCheckedOut = false) {
        if (!$shift) return $isCheckedOut ? 'completed' : 'present';

        if (!$isCheckedOut) {
            // When still active on shift (not yet checked out):
            return ($delayMins > ($shift['late_threshold'] ?? 10)) ? 'delayed' : 'present';
        }

        // When closing / checking out:
        $status = 'completed'; // default full completed shift

        if ($delayMins > ($shift['late_threshold'] ?? 10)) {
            $status = 'delayed'; // arrived late
        }

        if ($earlyExitMins > ($shift['early_exit_threshold'] ?? 10)) {
            if ($status === 'delayed') {
                $status = 'half_day'; // arrived late AND departed early
            } else {
                $status = 'left_early';
            }
        }

        return $status;
    }

    /**
     * Fetch Office Geolocation & Geofencing Settings from site_settings
     */
    public static function getOfficeLocationSettings($pdo) {
        try {
            $stmt = $pdo->query("SELECT office_name, office_address, office_latitude, office_longitude, office_radius_meters, 
                location_attendance_enabled, wfh_early_window_minutes, wfh_grace_period_minutes, wfh_late_threshold_minutes, wfh_auto_reschedule_allowed 
                FROM site_settings WHERE is_active = 1 ORDER BY updated_at DESC LIMIT 1");
            $cfg = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($cfg) {
                return [
                    'office_name'                 => !empty($cfg['office_name']) ? $cfg['office_name'] : 'Cloud Aspire Headquarters',
                    'office_address'              => $cfg['office_address'] ?: '',
                    'office_latitude'             => ($cfg['office_latitude'] !== null) ? (float)$cfg['office_latitude'] : 11.94160000,
                    'office_longitude'            => ($cfg['office_longitude'] !== null) ? (float)$cfg['office_longitude'] : 79.80830000,
                    'office_radius_meters'        => !empty($cfg['office_radius_meters']) ? (int)$cfg['office_radius_meters'] : 100,
                    'location_attendance_enabled' => isset($cfg['location_attendance_enabled']) ? (int)$cfg['location_attendance_enabled'] : 1,
                    'wfh_early_window_minutes'    => isset($cfg['wfh_early_window_minutes']) ? (int)$cfg['wfh_early_window_minutes'] : 15,
                    'wfh_grace_period_minutes'    => isset($cfg['wfh_grace_period_minutes']) ? (int)$cfg['wfh_grace_period_minutes'] : 15,
                    'wfh_late_threshold_minutes'  => isset($cfg['wfh_late_threshold_minutes']) ? (int)$cfg['wfh_late_threshold_minutes'] : 30,
                    'wfh_auto_reschedule_allowed' => isset($cfg['wfh_auto_reschedule_allowed']) ? (int)$cfg['wfh_auto_reschedule_allowed'] : 0,
                ];
            }
        } catch (PDOException $e) {
            error_log("Error fetching office location settings: " . $e->getMessage());
        }

        return [
            'office_name'                 => 'Cloud Aspire Headquarters',
            'office_address'              => '',
            'office_latitude'             => 11.94160000,
            'office_longitude'            => 79.80830000,
            'office_radius_meters'        => 100,
            'location_attendance_enabled' => 1,
            'wfh_early_window_minutes'    => 15,
            'wfh_grace_period_minutes'    => 15,
            'wfh_late_threshold_minutes'  => 30,
            'wfh_auto_reschedule_allowed' => 0
        ];
    }

    /**
     * Centralized Server-Side Geofence Validation for Check-In and Check-Out
     * Validates coordinates and enforces radius restrictions for on-site staff.
     * Checks if employee has an authorized WFH or On-site exception approval for today.
     */
    public static function validateEmployeeLocation($pdo, $workMode, $submittedLat, $submittedLng, $action = 'check_in', $employeeId = null, $date = null) {
        $officeSettings = self::getOfficeLocationSettings($pdo);
        if ($date === null) {
            $date = date('Y-m-d');
        }

        // 1. Check if employee has an authorized approved request for this specific date
        $approvedRequest = null;
        if ($employeeId !== null) {
            $approvedRequest = self::getTodayApprovedRequest($pdo, $employeeId, $date);
        }

        if ($approvedRequest) {
            if ($approvedRequest['requested_work_mode'] === 'wfh') {
                return [
                    'allowed'              => true,
                    'is_wfh'               => true,
                    'has_approved_request' => true,
                    'request'              => $approvedRequest,
                    'latitude'             => ($submittedLat !== '' && is_numeric($submittedLat)) ? (float)$submittedLat : null,
                    'longitude'            => ($submittedLng !== '' && is_numeric($submittedLng)) ? (float)$submittedLng : null,
                    'distance'             => null,
                    'office_radius'        => (int)($officeSettings['office_radius_meters'] ?? 100)
                ];
            } elseif ($approvedRequest['requested_work_mode'] === 'on_site_exception') {
                return [
                    'allowed'               => true,
                    'is_wfh'                => false,
                    'is_on_site_exception'  => true,
                    'has_approved_request'  => true,
                    'request'               => $approvedRequest,
                    'latitude'              => ($submittedLat !== '' && is_numeric($submittedLat)) ? (float)$submittedLat : null,
                    'longitude'             => ($submittedLng !== '' && is_numeric($submittedLng)) ? (float)$submittedLng : null,
                    'distance'              => null,
                    'office_radius'         => (int)($officeSettings['office_radius_meters'] ?? 100)
                ];
            }
        }

        // 2. Permanent Work From Home employees or globally disabled geofencing bypass restriction
        if ($workMode === 'wfh' || empty($officeSettings['location_attendance_enabled'])) {
            return [
                'allowed'       => true,
                'is_wfh'        => ($workMode === 'wfh'),
                'geofence_off'  => empty($officeSettings['location_attendance_enabled']),
                'latitude'      => ($submittedLat !== '' && is_numeric($submittedLat)) ? (float)$submittedLat : null,
                'longitude'     => ($submittedLng !== '' && is_numeric($submittedLng)) ? (float)$submittedLng : null,
                'distance'      => null,
                'office_radius' => (int)($officeSettings['office_radius_meters'] ?? 100)
            ];
        }

        // 3. On-Site employees with active geofencing: coordinates are strictly mandatory
        $actionName = ($action === 'check_out') ? 'check-out' : 'check-in';

        if ($submittedLat === '' || $submittedLat === null || $submittedLng === '' || $submittedLng === null || !is_numeric($submittedLat) || !is_numeric($submittedLng)) {
            return [
                'allowed'           => false,
                'location_mismatch' => false,
                'error'             => "Location permission is required for on-site {$actionName}. Please enable device GPS and grant location access in your browser."
            ];
        }

        $lat = (float)$submittedLat;
        $lng = (float)$submittedLng;

        if (!Security::validateCoordinates($lat, $lng)) {
            return [
                'allowed'           => false,
                'location_mismatch' => false,
                'error'             => "Invalid GPS coordinates received ({$lat}, {$lng}). Please try again."
            ];
        }

        $officeLat = (float)($officeSettings['office_latitude'] ?? 0);
        $officeLng = (float)($officeSettings['office_longitude'] ?? 0);
        $officeRadius = (int)($officeSettings['office_radius_meters'] ?? 100);

        if (!Security::validateCoordinates($officeLat, $officeLng)) {
            return [
                'allowed'           => false,
                'location_mismatch' => false,
                'error'             => "Office location coordinates are not properly configured in system settings. Please contact the administrator."
            ];
        }

        $distance = Security::calculateHaversineDistance($lat, $lng, $officeLat, $officeLng);

        if ($distance > $officeRadius) {
            $formattedDist = round($distance);
            return [
                'allowed'           => false,
                'location_mismatch' => true,
                'error'             => "Location verification rejected: You are {$formattedDist} meters away from the office. The permitted attendance radius is {$officeRadius} meters.",
                'distance'          => $formattedDist,
                'office_radius'     => $officeRadius,
                'latitude'          => $lat,
                'longitude'         => $lng
            ];
        }

        return [
            'allowed'           => true,
            'is_wfh'            => false,
            'location_mismatch' => false,
            'latitude'          => $lat,
            'longitude'         => $lng,
            'distance'          => round($distance, 2),
            'office_radius'     => $officeRadius
        ];
    }

    /**
     * Retrieve any active or latest attendance request for an employee on a given date
     */
    public static function getAttendanceRequestForDate($pdo, $employeeId, $date = null) {
        if ($date === null) {
            $date = date('Y-m-d');
        }
        try {
            $stmt = $pdo->prepare("SELECT ar.*, a.username as approver_username 
                FROM attendance_requests ar 
                LEFT JOIN admin a ON ar.approver_id = a.id 
                WHERE ar.employee_id = ? AND ar.attendance_date = ? 
                ORDER BY ar.id DESC LIMIT 1");
            $stmt->execute([$employeeId, $date]);
            return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (PDOException $e) {
            error_log("Error fetching attendance request: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Retrieve recent attendance requests for an employee
     */
    public static function getEmployeeRequestsHistory($pdo, $employeeId, $limit = 10) {
        try {
            $stmt = $pdo->prepare("SELECT ar.*, a.username as approver_username 
                FROM attendance_requests ar 
                LEFT JOIN admin a ON ar.approver_id = a.id 
                WHERE ar.employee_id = ? 
                ORDER BY ar.attendance_date DESC, ar.id DESC LIMIT " . (int)$limit);
            $stmt->execute([(int)$employeeId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error fetching employee request history: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Retrieve an approved request for today that is ready for check-in
     */
    public static function getTodayApprovedRequest($pdo, $employeeId, $date = null) {
        if ($date === null) {
            $date = date('Y-m-d');
        }
        try {
            $stmt = $pdo->prepare("SELECT * FROM attendance_requests 
                WHERE employee_id = ? AND attendance_date = ? AND status = 'approved' 
                LIMIT 1");
            $stmt->execute([$employeeId, $date]);
            return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (PDOException $e) {
            error_log("Error fetching approved request: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Create an Attendance Request (WFH or On-Site Exception)
     */
    public static function createAttendanceRequest($pdo, $employeeId, $data) {
        $date        = $data['attendance_date'] ?? date('Y-m-d');
        $mode        = in_array($data['requested_work_mode'] ?? '', ['wfh', 'on_site_exception'], true) ? $data['requested_work_mode'] : 'wfh';
        $reason      = trim($data['reason'] ?? '');
        $details     = trim($data['supporting_details'] ?? '');
        $reqTime     = trim($data['requested_check_in_time'] ?? date('H:i:s'));
        $lat         = isset($data['latitude']) && is_numeric($data['latitude']) ? (float)$data['latitude'] : null;
        $lng         = isset($data['longitude']) && is_numeric($data['longitude']) ? (float)$data['longitude'] : null;
        $dist        = isset($data['distance']) && is_numeric($data['distance']) ? (int)$data['distance'] : null;

        if (empty($reason) || strlen($reason) < 5) {
            return ['success' => false, 'error' => 'Please provide a clear reason for your request (minimum 5 characters).'];
        }

        // Validate date format
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return ['success' => false, 'error' => 'Invalid attendance date format.'];
        }

        // Validate time format (HH:MM or HH:MM:SS)
        if (!preg_match('/^\d{1,2}:\d{2}(:\d{2})?$/', $reqTime)) {
            $reqTime = date('H:i:00');
        } else {
            $reqTime = date('H:i:00', strtotime($reqTime));
        }

        try {
            // Check if active pending or approved request already exists for this date
            $checkStmt = $pdo->prepare("SELECT id, status FROM attendance_requests 
                WHERE employee_id = ? AND attendance_date = ? AND status IN ('pending', 'approved') LIMIT 1");
            $checkStmt->execute([$employeeId, $date]);
            $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);

            if ($existing) {
                $statusLabel = ($existing['status'] === 'approved') ? 'an approved' : 'a pending';
                return ['success' => false, 'error' => "You already have {$statusLabel} request for this date."];
            }

            $insStmt = $pdo->prepare("INSERT INTO attendance_requests 
                (employee_id, attendance_date, requested_work_mode, reason, supporting_details, requested_check_in_time, status, submitted_lat, submitted_lng, submitted_dist) 
                VALUES (?, ?, ?, ?, ?, ?, 'pending', ?, ?, ?)");
            $insStmt->execute([
                $employeeId,
                $date,
                $mode,
                $reason,
                $details ?: null,
                $reqTime,
                $lat,
                $lng,
                $dist
            ]);

            return [
                'success'    => true,
                'request_id' => (int)$pdo->lastInsertId(),
                'message'    => 'Your attendance request has been submitted to HR/CEO for review and approval.'
            ];
        } catch (PDOException $e) {
            error_log("createAttendanceRequest error: " . $e->getMessage());
            return ['success' => false, 'error' => 'Database error while submitting request. Please try again.'];
        }
    }

    /**
     * Cancel a pending attendance request (by employee)
     */
    public static function cancelAttendanceRequest($pdo, $employeeId, $requestId) {
        try {
            $stmt = $pdo->prepare("UPDATE attendance_requests SET status = 'cancelled', updated_at = NOW() 
                WHERE id = ? AND employee_id = ? AND status = 'pending'");
            $stmt->execute([(int)$requestId, (int)$employeeId]);

            if ($stmt->rowCount() > 0) {
                return ['success' => true, 'message' => 'Attendance request cancelled successfully.'];
            }
            return ['success' => false, 'error' => 'Request not found or it cannot be cancelled in its current state.'];
        } catch (PDOException $e) {
            error_log("cancelAttendanceRequest error: " . $e->getMessage());
            return ['success' => false, 'error' => 'Database error while cancelling request.'];
        }
    }

    /**
     * Evaluate whether current time falls within the permitted scheduled check-in window
     */
    public static function evaluateScheduledCheckInWindow($approvedTime, $settings, $currentTime = null) {
        if ($currentTime === null) {
            $currentTime = date('H:i:s');
        }

        $today = date('Y-m-d');
        $approvedTs = strtotime($today . ' ' . $approvedTime);
        $currentTs  = strtotime($today . ' ' . $currentTime);

        $earlyWindowMinutes = (int)($settings['wfh_early_window_minutes'] ?? 15);
        $graceMinutes       = (int)($settings['wfh_grace_period_minutes'] ?? 15);
        $lateThreshold      = (int)($settings['wfh_late_threshold_minutes'] ?? 30);

        $earliestAllowedTs  = $approvedTs - ($earlyWindowMinutes * 60);
        $onTimeEndTs        = $approvedTs + ($graceMinutes * 60);

        // 1. Too early
        if ($currentTs < $earliestAllowedTs) {
            $earlyByMins = ceil(($earliestAllowedTs - $currentTs) / 60);
            return [
                'can_check_in'       => false,
                'status'             => 'too_early',
                'approved_time'      => date('h:i A', $approvedTs),
                'window_opens_at'    => date('h:i A', $earliestAllowedTs),
                'minutes_remaining'  => $earlyByMins,
                'delay_minutes'      => 0,
                'message'            => "Early check-in rejected: Your approved check-in time is " . date('h:i A', $approvedTs) . ". Check-in window opens at " . date('h:i A', $earliestAllowedTs) . " (" . $earlyByMins . " minutes remaining)."
            ];
        }

        // 2. On-Time Check-In Window
        if ($currentTs <= $onTimeEndTs) {
            return [
                'can_check_in'    => true,
                'status'          => 'on_time',
                'approved_time'   => date('h:i A', $approvedTs),
                'delay_minutes'   => 0,
                'message'         => 'Check-in is permitted on time.'
            ];
        }

        // 3. Late Check-In Window (After grace period)
        $delayMinutes = (int)floor(($currentTs - $approvedTs) / 60);

        // Check if late threshold has been exceeded and auto-reschedule is disallowed
        if ($currentTs > ($approvedTs + ($lateThreshold * 60)) && empty($settings['wfh_auto_reschedule_allowed'])) {
            return [
                'can_check_in'      => false,
                'status'            => 'expired',
                'approved_time'     => date('h:i A', $approvedTs),
                'delay_minutes'     => $delayMinutes,
                'late_threshold'    => $lateThreshold,
                'message'           => "Check-in window expired: Your approved check-in time was " . date('h:i A', $approvedTs) . " (" . $delayMinutes . " mins ago). The permitted late threshold of " . $lateThreshold . " minutes has passed. Please submit a new attendance request for HR/CEO approval."
            ];
        }

        return [
            'can_check_in'    => true,
            'status'          => 'delayed',
            'approved_time'   => date('h:i A', $approvedTs),
            'delay_minutes'   => $delayMinutes,
            'message'         => "Check-in permitted (Delayed by {$delayMinutes} minutes past approved time)."
        ];
    }

    /**
     * Approve Attendance Request (HR/CEO with Scheduled Check-In Time)
     */
    public static function approveAttendanceRequest($pdo, $requestId, $adminId, $approvedTime, $rescheduledReason = null) {
        if (empty($approvedTime)) {
            return ['success' => false, 'error' => 'An approved check-in time must be specified.'];
        }

        $formattedTime = date('H:i:00', strtotime($approvedTime));

        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("SELECT * FROM attendance_requests WHERE id = ? FOR UPDATE");
            $stmt->execute([(int)$requestId]);
            $req = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$req) {
                $pdo->rollBack();
                return ['success' => false, 'error' => 'Attendance request not found.'];
            }

            if ($req['status'] !== 'pending') {
                $pdo->rollBack();
                return ['success' => false, 'error' => "Request has already been processed (Current status: {$req['status']})."];
            }

            // Self-approval protection
            $adminEmail = $pdo->query("SELECT email FROM admin WHERE id = " . (int)$adminId)->fetchColumn();
            if ($adminEmail) {
                $empEmail = $pdo->query("SELECT email FROM employees WHERE id = " . (int)$req['employee_id'])->fetchColumn();
                if ($empEmail && strtolower(trim($adminEmail)) === strtolower(trim($empEmail))) {
                    // Check if super admin (super admin may override)
                    $isSuper = (int)$pdo->query("SELECT COUNT(*) FROM admin_roles ar JOIN roles r ON ar.role_id = r.id WHERE ar.admin_id = " . (int)$adminId . " AND r.slug = 'super_admin'")->fetchColumn() > 0;
                    if (!$isSuper) {
                        $pdo->rollBack();
                        return ['success' => false, 'error' => 'Self-approval restriction: You cannot approve your own attendance request.'];
                    }
                }
            }

            $upStmt = $pdo->prepare("UPDATE attendance_requests SET 
                status = 'approved',
                approved_check_in_time = ?,
                approver_id = ?,
                decision_timestamp = NOW(),
                updated_at = NOW()
                WHERE id = ?");
            $upStmt->execute([
                $formattedTime,
                $adminId,
                $req['id']
            ]);

            $pdo->commit();
            return [
                'success'       => true,
                'message'       => "Request approved successfully. Scheduled check-in time set to " . date('h:i A', strtotime($formattedTime)) . ".",
                'approved_time' => $formattedTime
            ];
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log("approveAttendanceRequest error: " . $e->getMessage());
            return ['success' => false, 'error' => 'Database error while approving request: ' . $e->getMessage()];
        }
    }

    /**
     * Reject Attendance Request (HR/CEO with Mandatory Rejection Reason)
     */
    public static function rejectAttendanceRequest($pdo, $requestId, $adminId, $rejectionReason) {
        $reason = trim($rejectionReason ?? '');
        if (empty($reason)) {
            return ['success' => false, 'error' => 'A rejection reason is mandatory when rejecting a request.'];
        }

        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("SELECT * FROM attendance_requests WHERE id = ? FOR UPDATE");
            $stmt->execute([(int)$requestId]);
            $req = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$req) {
                $pdo->rollBack();
                return ['success' => false, 'error' => 'Attendance request not found.'];
            }

            if ($req['status'] !== 'pending') {
                $pdo->rollBack();
                return ['success' => false, 'error' => "Request has already been processed (Current status: {$req['status']})."];
            }

            $upStmt = $pdo->prepare("UPDATE attendance_requests SET 
                status = 'rejected',
                rejection_reason = ?,
                approver_id = ?,
                decision_timestamp = NOW(),
                updated_at = NOW()
                WHERE id = ?");
            $upStmt->execute([
                $reason,
                $adminId,
                $req['id']
            ]);

            $pdo->commit();
            return [
                'success' => true,
                'message' => 'Attendance request rejected successfully.'
            ];
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log("rejectAttendanceRequest error: " . $e->getMessage());
            return ['success' => false, 'error' => 'Database error while rejecting request.'];
        }
    }

    /**
     * Transition approved request to checked_in state upon actual attendance punch
     */
    public static function markAttendanceRequestCheckedIn($pdo, $requestId, $actualCheckInTime = null) {
        if ($actualCheckInTime === null) {
            $actualCheckInTime = date('H:i:s');
        }
        try {
            $stmt = $pdo->prepare("UPDATE attendance_requests SET 
                status = 'checked_in', 
                actual_check_in = ?, 
                updated_at = NOW() 
                WHERE id = ? AND status = 'approved'");
            $stmt->execute([$actualCheckInTime, (int)$requestId]);
            return true;
        } catch (PDOException $e) {
            error_log("markAttendanceRequestCheckedIn error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get count of pending attendance requests
     */
    public static function getPendingRequestsCount($pdo) {
        try {
            return (int)$pdo->query("SELECT COUNT(*) FROM attendance_requests WHERE status = 'pending'")->fetchColumn();
        } catch (PDOException $e) {
            return 0;
        }
    }

    /**
     * Locate the employee's active, open attendance record
     * Handles normal today check-in as well as overnight shifts safely.
     * Stale records older than yesterday are excluded so they never block attendance.
     */
    public static function getActiveOpenAttendance($pdo, $employeeId, $todayDate = null) {
        if ($todayDate === null) {
            $todayDate = date('Y-m-d');
        }

        // Priority 1: Check today's record first
        $stmt = $pdo->prepare("SELECT * FROM attendance WHERE employee_id = ? AND date = ? LIMIT 1");
        $stmt->execute([$employeeId, $todayDate]);
        $rec = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($rec) {
            $punches = json_decode($rec['punch_history'] ?? '[]', true) ?: [];
            if (empty($punches) && !empty($rec['check_in']) && empty($rec['check_out'])) {
                return $rec;
            }
            foreach ($punches as $p) {
                if (empty($p['out'])) {
                    return $rec;
                }
            }
        }

        // Priority 2: Check ONLY for an overnight shift from yesterday (< 20 hours duration)
        $yesterday = date('Y-m-d', strtotime('-1 day', strtotime($todayDate)));
        $stmt2 = $pdo->prepare("SELECT * FROM attendance 
            WHERE employee_id = ? AND date = ? AND (check_out IS NULL OR check_out = '' OR punch_history LIKE '%\"out\":null%') 
            ORDER BY id DESC LIMIT 1");
        $stmt2->execute([$employeeId, $yesterday]);
        $yesterdayRec = $stmt2->fetch(PDO::FETCH_ASSOC);

        if ($yesterdayRec) {
            $punches = json_decode($yesterdayRec['punch_history'] ?? '[]', true) ?: [];
            $hasOpenPunch = false;
            if (empty($punches) && !empty($yesterdayRec['check_in']) && empty($yesterdayRec['check_out'])) {
                $hasOpenPunch = true;
            } else {
                foreach ($punches as $p) {
                    if (empty($p['out'])) {
                        $hasOpenPunch = true;
                        break;
                    }
                }
            }

            if ($hasOpenPunch) {
                // Verify that check-in was within the last 20 hours (plausible overnight shift)
                $firstCheckIn = $yesterdayRec['check_in'] ?: '18:00:00';
                $checkInTs = strtotime($yesterday . ' ' . $firstCheckIn);
                if (time() - $checkInTs < (20 * 3600)) {
                    return $yesterdayRec;
                }
            }
        }

        return null;
    }

    /**
     * Atomically close attendance session and persist Daily Work Summary
     * Protected with database transaction and row-level locking
     */
    public static function closeAttendanceTransaction($pdo, $employeeId, $attendanceId, $summaryData, $leaveCode = null, $currentTime = null, $geoData = null) {
        if ($currentTime === null) {
            $currentTime = date('H:i:s');
        }

        try {
            $pdo->beginTransaction();

            // Lock record and verify employee ownership
            $stmt = $pdo->prepare("SELECT * FROM attendance WHERE id = ? AND employee_id = ? FOR UPDATE");
            $stmt->execute([$attendanceId, $employeeId]);
            $record = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$record) {
                $pdo->rollBack();
                return ['success' => false, 'error' => 'Attendance record not found or access denied.'];
            }

            // Decode punches and find the open punch session
            $punchHistory = json_decode($record['punch_history'] ?? '[]', true) ?: [];
            if (empty($punchHistory) && !empty($record['check_in'])) {
                $punchHistory[] = ['in' => $record['check_in'], 'out' => $record['check_out']];
            }

            $openPunchIndex = -1;
            foreach ($punchHistory as $idx => $p) {
                if (empty($p['out'])) {
                    $openPunchIndex = $idx;
                    break;
                }
            }

            if ($openPunchIndex === -1 && !empty($record['check_out'])) {
                $pdo->rollBack();
                return ['success' => false, 'error' => 'This attendance session has already been closed.'];
            }

            // Close the open punch session
            if ($openPunchIndex !== -1) {
                $punchHistory[$openPunchIndex]['out'] = $currentTime;
            } else {
                $punchHistory[] = ['in' => $record['check_in'] ?: $currentTime, 'out' => $currentTime];
            }

            // Calculate total work minutes across all punch sessions
            $totalMinutes = 0;
            foreach ($punchHistory as $p) {
                if (!empty($p['in']) && !empty($p['out'])) {
                    $inTime = strtotime($record['date'] . ' ' . $p['in']);
                    $outTime = strtotime($record['date'] . ' ' . $p['out']);
                    if ($outTime < $inTime) {
                        $outTime += 86400; // spanned past midnight
                    }
                    $totalMinutes += max(0, (int)round(($outTime - $inTime) / 60));
                }
            }

            $workHoursFormatted = round($totalMinutes / 60, 2);
            $activeShift = self::getActiveShift($pdo);
            $earlyExitMins = self::calculateEarlyExit($currentTime, $activeShift);

            // Compute official checkout status
            $status = self::getAttendanceStatus($record['delay_minutes'], $earlyExitMins, $activeShift, true);
            $oldStatus = $status;
            if ($status === 'delayed') $oldStatus = 'late';
            if ($status === 'left_early' || $status === 'completed') $oldStatus = 'present';

            // Sanitize work summary fields
            $workSummary    = trim($summaryData['work_summary'] ?? '');
            $completedTasks = trim($summaryData['completed_tasks'] ?? '');
            $pendingTasks   = trim($summaryData['pending_tasks'] ?? '');

            if (empty($workSummary) || empty($completedTasks)) {
                $pdo->rollBack();
                return ['success' => false, 'error' => 'Both "Tasks Worked On Today" and "Tasks Completed Today" are mandatory.'];
            }

            $finalLeaveCode = !empty($leaveCode) ? trim($leaveCode) : $record['leave_code'];

            // Geolocation coordinates for check-out
            $checkOutLat  = isset($geoData['latitude']) ? (float)$geoData['latitude'] : null;
            $checkOutLng  = isset($geoData['longitude']) ? (float)$geoData['longitude'] : null;
            $checkOutDist = isset($geoData['distance']) ? (int)round($geoData['distance']) : null;

            // 1. Update attendance record
            $upStmt = $pdo->prepare("UPDATE attendance SET 
                check_out = ?, 
                early_exit_minutes = ?, 
                total_work_minutes = ?, 
                working_hours = ?, 
                attendance_status = ?, 
                status = ?, 
                punch_history = ?, 
                leave_code = ?,
                work_summary = ?,
                completed_tasks = ?,
                pending_tasks = ?,
                check_out_lat = ?,
                check_out_lng = ?,
                check_out_dist = ?,
                updated_at = NOW()
                WHERE id = ? AND employee_id = ?");

            $upStmt->execute([
                $currentTime,
                $earlyExitMins,
                $totalMinutes,
                $workHoursFormatted,
                $status,
                $oldStatus,
                json_encode($punchHistory),
                $finalLeaveCode,
                $workSummary,
                $completedTasks,
                $pendingTasks ?: null,
                $checkOutLat,
                $checkOutLng,
                $checkOutDist,
                $record['id'],
                $employeeId
            ]);

            // 2. Insert or Update into daily_work_summaries table
            $sumStmt = $pdo->prepare("INSERT INTO daily_work_summaries 
                (attendance_id, employee_id, date, work_summary, completed_tasks, pending_tasks) 
                VALUES (?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE 
                work_summary = VALUES(work_summary), 
                completed_tasks = VALUES(completed_tasks), 
                pending_tasks = VALUES(pending_tasks),
                updated_at = NOW()");

            $sumStmt->execute([
                $record['id'],
                $employeeId,
                $record['date'],
                $workSummary,
                $completedTasks,
                $pendingTasks ?: null
            ]);

            $pdo->commit();
            return [
                'success'    => true,
                'message'    => 'Attendance closed successfully and Daily Work Summary recorded at ' . date('h:i A') . '.',
                'record_id'  => $record['id'],
                'work_hours' => $workHoursFormatted
            ];

        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log("closeAttendanceTransaction error: " . $e->getMessage());
            return ['success' => false, 'error' => 'Database error while closing attendance. Please try again.'];
        }
    }

    /**
     * Check if an employee can check in (prevents duplicates)
     */
    public static function canCheckIn($pdo, $employeeId, $date) {
        $stmt = $pdo->prepare("SELECT id, check_in, check_out FROM attendance WHERE employee_id = ? AND date = ?");
        $stmt->execute([$employeeId, $date]);
        $record = $stmt->fetch(PDO::FETCH_ASSOC);

        // Can check in if no record exists for today, or if record exists but check_in is null
        return !$record || empty($record['check_in']);
    }

    /**
     * Check if an employee can check out
     */
    public static function canCheckOut($pdo, $employeeId, $date) {
        $stmt = $pdo->prepare("SELECT id, check_in, check_out FROM attendance WHERE employee_id = ? AND date = ?");
        $stmt->execute([$employeeId, $date]);
        $record = $stmt->fetch(PDO::FETCH_ASSOC);

        // Can check out if checked in but not yet checked out
        return $record && !empty($record['check_in']) && empty($record['check_out']);
    }

    // =========================================================================
    // AUTOMATIC PORTAL CLOSURE, SHIFT TIMING & HR REVIEW METHODS
    // =========================================================================

    /**
     * Safe, idempotent schema check to guarantee portal closure columns exist
     */
    public static function ensurePortalClosureSchema($pdo) {
        static $ensured = false;
        if ($ensured) return;

        try {
            // Check attendance columns
            $cols = $pdo->query("SHOW COLUMNS FROM attendance")->fetchAll(PDO::FETCH_COLUMN);
            if (!in_array('is_flagged_for_review', $cols, true)) {
                $pdo->exec("ALTER TABLE `attendance` ADD COLUMN `is_flagged_for_review` TINYINT(1) NOT NULL DEFAULT 0 AFTER `check_out_dist`");
            }
            if (!in_array('flag_reason', $cols, true)) {
                $pdo->exec("ALTER TABLE `attendance` ADD COLUMN `flag_reason` VARCHAR(255) DEFAULT NULL AFTER `is_flagged_for_review`");
            }
            if (!in_array('closed_by_system', $cols, true)) {
                $pdo->exec("ALTER TABLE `attendance` ADD COLUMN `closed_by_system` TINYINT(1) NOT NULL DEFAULT 0 AFTER `flag_reason`");
            }

            // Check employees columns
            $empCols = $pdo->query("SHOW COLUMNS FROM employees")->fetchAll(PDO::FETCH_COLUMN);
            if (!in_array('shift_id', $empCols, true)) {
                $pdo->exec("ALTER TABLE `employees` ADD COLUMN `shift_id` INT(11) DEFAULT NULL AFTER `work_mode`");
            }

            // Check attendance_requests columns
            $reqCols = $pdo->query("SHOW COLUMNS FROM attendance_requests")->fetchAll(PDO::FETCH_COLUMN);
            if (!in_array('approved_check_out_time', $reqCols, true)) {
                $pdo->exec("ALTER TABLE `attendance_requests` ADD COLUMN `approved_check_out_time` TIME DEFAULT NULL AFTER `approved_check_in_time`");
            }

            $ensured = true;
        } catch (PDOException $e) {
            error_log("ensurePortalClosureSchema notice: " . $e->getMessage());
        }
    }

    /**
     * Retrieve the shift assigned to a specific employee or fallback to active shift policy.
     * Incorporates approved exceptions/schedule changes for a given date if present.
     */
    public static function getEmployeeShift($pdo, $employeeId = null, $date = null) {
        if ($date === null) {
            $date = date('Y-m-d');
        }

        $shift = null;

        // 1. Check if employee has a specific assigned shift
        if ($employeeId !== null && (int)$employeeId > 0) {
            try {
                $stmt = $pdo->prepare("SELECT s.* FROM employees e 
                    JOIN shift_settings s ON e.shift_id = s.id 
                    WHERE e.id = ? LIMIT 1");
                $stmt->execute([(int)$employeeId]);
                $shift = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
            } catch (PDOException $e) {
                error_log("Error fetching employee assigned shift: " . $e->getMessage());
            }
        }

        // 2. Fall back to active shift policy
        if (!$shift) {
            $shift = self::getActiveShift($pdo);
        }

        // 3. Fall back to safe system defaults
        if (!$shift) {
            $shift = [
                'id'                   => 1,
                'shift_name'           => 'Regular Shift',
                'description'          => 'Standard General Office Shift',
                'checkin_start'        => '09:00:00',
                'checkin_end'          => '10:00:00',
                'checkout_time'        => '17:00:00',
                'grace_minutes'        => 10,
                'late_threshold'       => 10,
                'early_exit_threshold' => 10,
                'color'                => '#4f46e5',
                'is_active'            => 1
            ];
        }

        // 4. Incorporate approved schedule exceptions for this date if present
        if ($employeeId !== null && (int)$employeeId > 0) {
            $approvedReq = self::getTodayApprovedRequest($pdo, $employeeId, $date);
            if ($approvedReq) {
                if (!empty($approvedReq['approved_check_in_time'])) {
                    $shift['checkin_start'] = $approvedReq['approved_check_in_time'];
                    $shift['is_approved_exception'] = true;
                }
                if (!empty($approvedReq['approved_check_out_time'])) {
                    $shift['checkout_time'] = $approvedReq['approved_check_out_time'];
                    $shift['is_approved_exception'] = true;
                }
            }
        }

        return $shift;
    }

    /**
     * Determine if a shift spans across midnight (overnight shift)
     */
    public static function isOvernightShift($shift) {
        if (!$shift || empty($shift['checkin_start']) || empty($shift['checkout_time'])) {
            return false;
        }
        $startSec = strtotime('1970-01-01 ' . $shift['checkin_start']);
        $endSec   = strtotime('1970-01-01 ' . $shift['checkout_time']);
        return ($endSec < $startSec);
    }

    /**
     * Compute shift window timestamps for a given workday date.
     * Correctly handles daytime shifts as well as overnight shifts spanning midnight.
     */
    public static function getShiftWindowForWorkday($shift, $workdayDate) {
        $startTime = $shift['checkin_start'] ?? '09:00:00';
        $endTime   = $shift['checkout_time'] ?? '17:00:00';
        $isOvernight = self::isOvernightShift($shift);

        $startTs = strtotime($workdayDate . ' ' . $startTime);
        $endTs   = $isOvernight 
            ? strtotime($workdayDate . ' ' . $endTime . ' +1 day')
            : strtotime($workdayDate . ' ' . $endTime);

        return [
            'workday_date'        => $workdayDate,
            'shift_name'          => $shift['shift_name'] ?? 'Regular Shift',
            'checkin_start'       => $startTime,
            'checkout_time'       => $endTime,
            'is_overnight'        => $isOvernight,
            'start_ts'            => $startTs,
            'end_ts'              => $endTs,
            'start_formatted'     => date('h:i A', $startTs),
            'end_formatted'       => date('h:i A', $endTs),
            'end_datetime'        => date('Y-m-d H:i:s', $endTs),
            'end_date'            => date('Y-m-d', $endTs)
        ];
    }

    /**
     * Flag an attendance record for HR review without fabricating any check-out timestamp.
     * Preserves true attendance data and sets audit flags.
     */
    public static function flagAttendanceForReview($pdo, $attendanceId, $reason) {
        try {
            $stmt = $pdo->prepare("UPDATE attendance SET 
                is_flagged_for_review = 1,
                flag_reason = ?,
                closed_by_system = 1,
                remarks = CONCAT(IFNULL(remarks, ''), IF(remarks IS NULL OR remarks = '', '', ' | '), ?),
                updated_at = NOW()
                WHERE id = ? AND (is_flagged_for_review = 0 OR is_flagged_for_review IS NULL)");
            $stmt->execute([
                $reason,
                "[AUTO-CLOSED] " . $reason,
                (int)$attendanceId
            ]);
            return true;
        } catch (PDOException $e) {
            error_log("flagAttendanceForReview error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Authoritative Server-Side Evaluation of Portal Closure Status.
     * 
     * Enforces:
     * - Server-side time in Asia/Kolkata timezone (never device clock).
     * - Automatic closure when scheduled check-out time is reached.
     * - Flagging records for HR review when checkout was not performed (no fabricated timestamp).
     * - Immediate session closure when an employee checks out early.
     * - Full support for overnight shifts, approved schedule changes, and active portal sessions.
     */
    public static function evaluatePortalClosureStatus($pdo, $employeeId, $currentTimestamp = null) {
        // Enforce application timezone
        if (date_default_timezone_get() !== 'Asia/Kolkata') {
            date_default_timezone_set('Asia/Kolkata');
        }

        if ($currentTimestamp === null) {
            $currentTimestamp = time();
        }

        $serverTime = date('H:i:s', $currentTimestamp);
        $serverDate = date('Y-m-d', $currentTimestamp);

        // Fetch assigned shift policy (with any approved exceptions)
        $shift = self::getEmployeeShift($pdo, $employeeId, $serverDate);

        // Check for any active open attendance record (handles today & plausible overnight shifts)
        $activeOpenRec = self::getActiveOpenAttendance($pdo, $employeeId, $serverDate);

        // ─────────────────────────────────────────────────────────────────────
        // CASE 1: EMPLOYEE HAS AN ACTIVE OPEN ATTENDANCE SESSION
        // ─────────────────────────────────────────────────────────────────────
        if ($activeOpenRec) {
            $workdayDate = $activeOpenRec['date'];
            $window = self::getShiftWindowForWorkday($shift, $workdayDate);
            $cutoffTs = $window['end_ts'];

            // Has scheduled check-out cutoff been reached?
            if ($currentTimestamp >= $cutoffTs) {
                // Scheduled shift ended without employee checkout!
                $flagReason = "Missing check-out before scheduled shift cutoff ({$window['end_formatted']})";
                self::flagAttendanceForReview($pdo, $activeOpenRec['id'], $flagReason);

                // Reload flagged state
                $activeOpenRec['is_flagged_for_review'] = 1;
                $activeOpenRec['flag_reason'] = $flagReason;
                $activeOpenRec['closed_by_system'] = 1;

                return [
                    'is_closed'               => true,
                    'closure_reason'          => 'scheduled_end_reached_missing_checkout',
                    'scheduled_checkout_time' => $window['end_formatted'],
                    'scheduled_checkout_raw'  => $shift['checkout_time'],
                    'scheduled_checkout_ts'   => $cutoffTs,
                    'seconds_remaining'       => 0,
                    'time_remaining_text'     => '00h 00m 00s',
                    'message'                 => 'Your scheduled work hours have ended. Attendance access is now closed for today.',
                    'details'                 => "Your scheduled shift ended at {$window['end_formatted']}. Since check-out was not completed prior to the cutoff, your attendance session has been locked and flagged for HR review. Your actual check-in data has been safely preserved.",
                    'needs_hr_review'         => true,
                    'flag_reason'             => $flagReason,
                    'attendance_record'       => $activeOpenRec,
                    'shift'                   => $shift,
                    'shift_window'            => $window,
                    'server_time'             => $serverTime,
                    'server_date'             => $serverDate,
                    'can_check_in'            => false,
                    'can_check_out'           => false,
                    'can_submit_request'      => false
                ];
            }

            // Portal is OPEN: Employee is on shift and cutoff has not arrived
            $secsRemaining = max(0, $cutoffTs - $currentTimestamp);
            $hours = floor($secsRemaining / 3600);
            $mins  = floor(($secsRemaining % 3600) / 60);
            $secs  = $secsRemaining % 60;
            $remainingText = sprintf('%02dh %02dm %02ds', $hours, $mins, $secs);

            return [
                'is_closed'               => false,
                'closure_reason'          => 'open_active_shift',
                'scheduled_checkout_time' => $window['end_formatted'],
                'scheduled_checkout_raw'  => $shift['checkout_time'],
                'scheduled_checkout_ts'   => $cutoffTs,
                'seconds_remaining'       => $secsRemaining,
                'time_remaining_text'     => $remainingText,
                'message'                 => "Shift active. Scheduled check-out at {$window['end_formatted']} ({$remainingText} remaining).",
                'details'                 => "Please record your check-out and submit your daily work summary before {$window['end_formatted']}.",
                'needs_hr_review'         => false,
                'flag_reason'             => null,
                'attendance_record'       => $activeOpenRec,
                'shift'                   => $shift,
                'shift_window'            => $window,
                'server_time'             => $serverTime,
                'server_date'             => $serverDate,
                'can_check_in'            => false,
                'can_check_out'           => true,
                'can_submit_request'      => false
            ];
        }

        // ─────────────────────────────────────────────────────────────────────
        // CASE 2: NO ACTIVE OPEN ATTENDANCE SESSION
        // ─────────────────────────────────────────────────────────────────────
        // Check today's attendance record
        $stmtToday = $pdo->prepare("SELECT * FROM attendance WHERE employee_id = ? AND date = ? LIMIT 1");
        $stmtToday->execute([(int)$employeeId, $serverDate]);
        $todayRec = $stmtToday->fetch(PDO::FETCH_ASSOC);

        // Subcase 2A: Employee already checked out (early or regular)
        if ($todayRec && !empty($todayRec['check_out'])) {
            return [
                'is_closed'               => true,
                'closure_reason'          => 'already_checked_out',
                'scheduled_checkout_time' => date('h:i A', strtotime($shift['checkout_time'])),
                'scheduled_checkout_raw'  => $shift['checkout_time'],
                'scheduled_checkout_ts'   => strtotime("$serverDate {$shift['checkout_time']}"),
                'seconds_remaining'       => 0,
                'time_remaining_text'     => '00h 00m 00s',
                'message'                 => 'Your shift for today has been concluded.',
                'details'                 => 'You have already completed check-out for today at ' . date('h:i A', strtotime($todayRec['check_out'])) . '. Attendance access is now closed for today.',
                'needs_hr_review'         => !empty($todayRec['is_flagged_for_review']),
                'flag_reason'             => $todayRec['flag_reason'] ?? null,
                'attendance_record'       => $todayRec,
                'shift'                   => $shift,
                'shift_window'            => self::getShiftWindowForWorkday($shift, $serverDate),
                'server_time'             => $serverTime,
                'server_date'             => $serverDate,
                'can_check_in'            => false,
                'can_check_out'           => false,
                'can_submit_request'      => false
            ];
        }

        // Subcase 2B: Already flagged for review
        if ($todayRec && !empty($todayRec['is_flagged_for_review'])) {
            $window = self::getShiftWindowForWorkday($shift, $serverDate);
            return [
                'is_closed'               => true,
                'closure_reason'          => 'flagged_for_review',
                'scheduled_checkout_time' => $window['end_formatted'],
                'scheduled_checkout_raw'  => $shift['checkout_time'],
                'scheduled_checkout_ts'   => $window['end_ts'],
                'seconds_remaining'       => 0,
                'time_remaining_text'     => '00h 00m 00s',
                'message'                 => 'Your scheduled work hours have ended. Attendance access is now closed for today.',
                'details'                 => "Your attendance record is currently flagged for HR review: " . ($todayRec['flag_reason'] ?: 'Review required') . ".",
                'needs_hr_review'         => true,
                'flag_reason'             => $todayRec['flag_reason'] ?? 'Pending HR Review',
                'attendance_record'       => $todayRec,
                'shift'                   => $shift,
                'shift_window'            => $window,
                'server_time'             => $serverTime,
                'server_date'             => $serverDate,
                'can_check_in'            => false,
                'can_check_out'           => false,
                'can_submit_request'      => false
            ];
        }

        // Subcase 2C: No check-in recorded yet for today's workday
        $window = self::getShiftWindowForWorkday($shift, $serverDate);
        $cutoffTs = $window['end_ts'];

        if ($currentTimestamp >= $cutoffTs) {
            // Cutoff has passed: No check-in occurred today and shift is over
            return [
                'is_closed'               => true,
                'closure_reason'          => 'scheduled_end_reached_no_checkin',
                'scheduled_checkout_time' => $window['end_formatted'],
                'scheduled_checkout_raw'  => $shift['checkout_time'],
                'scheduled_checkout_ts'   => $cutoffTs,
                'seconds_remaining'       => 0,
                'time_remaining_text'     => '00h 00m 00s',
                'message'                 => 'Your scheduled work hours have ended. Attendance access is now closed for today.',
                'details'                 => "The scheduled shift check-out cutoff ({$window['end_formatted']}) has passed. Check-in, check-out and attendance actions are now closed for today.",
                'needs_hr_review'         => false,
                'flag_reason'             => null,
                'attendance_record'       => $todayRec ?: null,
                'shift'                   => $shift,
                'shift_window'            => $window,
                'server_time'             => $serverTime,
                'server_date'             => $serverDate,
                'can_check_in'            => false,
                'can_check_out'           => false,
                'can_submit_request'      => false
            ];
        }

        // Cutoff has not arrived yet: Portal is open for check-in / request
        $secsRemaining = max(0, $cutoffTs - $currentTimestamp);
        $hours = floor($secsRemaining / 3600);
        $mins  = floor(($secsRemaining % 3600) / 60);
        $secs  = $secsRemaining % 60;
        $remainingText = sprintf('%02dh %02dm %02ds', $hours, $mins, $secs);

        return [
            'is_closed'               => false,
            'closure_reason'          => 'open_pending_checkin',
            'scheduled_checkout_time' => $window['end_formatted'],
            'scheduled_checkout_raw'  => $shift['checkout_time'],
            'scheduled_checkout_ts'   => $cutoffTs,
            'seconds_remaining'       => $secsRemaining,
            'time_remaining_text'     => $remainingText,
            'message'                 => "Portal open. Shift check-out cutoff is at {$window['end_formatted']} ({$remainingText} remaining).",
            'details'                 => "Ready for shift check-in. Scheduled cutoff is at {$window['end_formatted']}.",
            'needs_hr_review'         => false,
            'flag_reason'             => null,
            'attendance_record'       => $todayRec ?: null,
            'shift'                   => $shift,
            'shift_window'            => $window,
            'server_time'             => $serverTime,
            'server_date'             => $serverDate,
            'can_check_in'            => true,
            'can_check_out'           => false,
            'can_submit_request'      => true
        ];
    }

    /**
     * HR / Administrator Override to resolve flagged attendance records.
     * Enforces attendance.manage permissions and records audit logs.
     */
    public static function adminResolveFlaggedAttendance($pdo, $attendanceId, $adminId, $approvedCheckOutTime, $resolutionNotes = '', $attendanceStatus = 'completed') {
        if (is_array($approvedCheckOutTime)) {
            $data = $approvedCheckOutTime;
            $approvedCheckOutTime = $data['approved_check_out'] ?? ($data['check_out'] ?? '');
            $resolutionNotes = $data['notes'] ?? ($data['resolution_notes'] ?? '');
            $attendanceStatus = $data['status'] ?? ($data['resolution_status'] ?? 'completed');
        }

        if (empty($approvedCheckOutTime)) {
            return ['success' => false, 'error' => 'An approved check-out time is mandatory.'];
        }
        if (empty(trim($resolutionNotes))) {
            return ['success' => false, 'error' => 'A resolution reason / note is mandatory for the audit log.'];
        }
        if ($adminId && !Security::hasPermission('attendance.manage', $adminId)) {
            return ['success' => false, 'error' => 'Access Denied: You do not possess authorization to modify attendance records.'];
        }

        // Format check-out time (HH:MM:SS)
        $formattedTime = date('H:i:00', strtotime($approvedCheckOutTime));

        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("SELECT a.*, e.first_name, e.last_name, e.shift_id 
                FROM attendance a 
                JOIN employees e ON a.employee_id = e.id 
                WHERE a.id = ? FOR UPDATE");
            $stmt->execute([(int)$attendanceId]);
            $record = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$record) {
                $pdo->rollBack();
                return ['success' => false, 'error' => 'Attendance record not found.'];
            }

            $shift = self::getEmployeeShift($pdo, $record['employee_id'], $record['date']);
            $delayMins = (int)$record['delay_minutes'];
            $earlyExitMins = self::calculateEarlyExit($formattedTime, $shift);
            $workData = self::calculateWorkingHours($record['check_in'], $formattedTime);

            // Reconstruct punch history
            $punchHistory = json_decode($record['punch_history'] ?? '[]', true) ?: [];
            if (empty($punchHistory) && !empty($record['check_in'])) {
                $punchHistory[] = ['in' => $record['check_in'], 'out' => $formattedTime];
            } else {
                $closedAny = false;
                foreach ($punchHistory as &$p) {
                    if (empty($p['out'])) {
                        $p['out'] = $formattedTime;
                        $closedAny = true;
                        break;
                    }
                }
                unset($p);
                if (!$closedAny && !empty($punchHistory)) {
                    $punchHistory[count($punchHistory) - 1]['out'] = $formattedTime;
                }
            }

            $auditNote = "HR Override by Admin #{$adminId}: Check-out set to {$formattedTime}. Reason: " . trim($resolutionNotes);
            $newRemarks = !empty($record['remarks']) ? ($record['remarks'] . ' | ' . $auditNote) : $auditNote;

            $autoStatus = self::getAttendanceStatus($delayMins, $earlyExitMins, $shift, true);
            $oldStatus = ($autoStatus === 'delayed') ? 'late' : (($autoStatus === 'left_early' || $autoStatus === 'completed') ? 'present' : $autoStatus);
            $finalStatus = !empty($attendanceStatus) ? $attendanceStatus : $autoStatus;

            $upStmt = $pdo->prepare("UPDATE attendance SET 
                check_out = ?,
                early_exit_minutes = ?,
                total_work_minutes = ?,
                working_hours = ?,
                attendance_status = ?,
                status = ?,
                punch_history = ?,
                is_flagged_for_review = 0,
                flag_reason = NULL,
                remarks = ?,
                updated_at = NOW()
                WHERE id = ?");
            $upStmt->execute([
                $formattedTime,
                $earlyExitMins,
                $workData['minutes'],
                $workData['hours_formatted'],
                $finalStatus,
                $oldStatus,
                json_encode($punchHistory),
                $newRemarks,
                $attendanceId
            ]);

            $pdo->commit();

            Security::logAudit($adminId, 'resolve_flagged_attendance', 'attendance', $attendanceId, [
                'employee_id'   => $record['employee_id'],
                'check_out'     => $formattedTime,
                'working_hours' => $workData['hours_formatted'],
                'notes'         => trim($resolutionNotes)
            ]);

            return [
                'success'       => true,
                'message'       => "Attendance record #{$attendanceId} resolved successfully. Check-out recorded as " . date('h:i A', strtotime($formattedTime)) . ".",
                'working_hours' => $workData['hours_formatted']
            ];
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log("adminResolveFlaggedAttendance error: " . $e->getMessage());
            return ['success' => false, 'error' => 'Database error while resolving attendance record.'];
        }
    }

    /**
     * Get count of attendance records currently flagged for HR review
     */
    public static function getFlaggedAttendanceCount($pdo) {
        try {
            return (int)$pdo->query("SELECT COUNT(*) FROM attendance WHERE is_flagged_for_review = 1")->fetchColumn();
        } catch (PDOException $e) {
            return 0;
        }
    }

    /**
     * Fetch attendance records flagged for HR review
     */
    public static function getFlaggedAttendanceRecords($pdo, $limit = 50) {
        try {
            $stmt = $pdo->prepare("SELECT a.*, e.first_name, e.last_name, e.employee_id as emp_code, e.department, e.work_mode
                FROM attendance a
                JOIN employees e ON a.employee_id = e.id
                WHERE a.is_flagged_for_review = 1
                ORDER BY a.date DESC, a.id DESC LIMIT " . (int)$limit);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("getFlaggedAttendanceRecords error: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Format a recent attendance record for UI presentation across desktop table and mobile cards.
     * Evaluates all 8 required columns: Date, Status, Scheduled Shift, Check In, Check Out,
     * Working Hours, Location Info, and Daily Summary.
     *
     * @param array $row Attendance record with joined shift, requests, and summaries
     * @param bool $isPortalClosedToday Whether today's attendance portal is closed
     * @param string|null $today Today's date (defaults to current date in Asia/Kolkata)
     * @return array
     */
    public static function formatRecentAttendanceRecord(array $row, bool $isPortalClosedToday = false, ?string $today = null): array {
        if ($today === null) {
            $today = date('Y-m-d');
        }

        // 1. Date
        $attDate = $row['date'] ?? $today;
        $formattedDate = date('D, M j, Y', strtotime($attDate));

        // Check overnight shift
        $isOvernight = false;
        if (!empty($row['resolved_checkin_start']) && !empty($row['resolved_checkout_time'])) {
            if (strtotime($row['resolved_checkout_time']) < strtotime($row['resolved_checkin_start'])) {
                $isOvernight = true;
            }
        }
        if (!$isOvernight && !empty($row['check_in']) && !empty($row['check_out'])) {
            if (strtotime($row['check_out']) < strtotime($row['check_in'])) {
                $isOvernight = true;
            }
        }
        $overnightNextDayShort = date('M j', strtotime($attDate . ' +1 day'));
        $overnightNextDayLong = date('D, M j, Y', strtotime($attDate . ' +1 day'));

        // 2. Status
        if (!empty($row['is_flagged_for_review']) || !empty($row['closed_by_system']) || ($row['attendance_status'] ?? '') === 'pending_hr_review') {
            $statusKey = 'pending_hr_review';
            $statusLabel = 'Pending HR Review';
            $statusIcon = 'fa-solid fa-triangle-exclamation';
        } elseif (!empty($row['check_in']) && empty($row['check_out'])) {
            if ($attDate === $today && !$isPortalClosedToday) {
                $statusKey = 'in_progress';
                $statusLabel = 'In Progress';
                $statusIcon = 'fa-solid fa-spinner fa-spin';
            } else {
                $statusKey = 'pending_hr_review';
                $statusLabel = 'Pending HR Review';
                $statusIcon = 'fa-solid fa-clock-rotate-left';
            }
        } else {
            $rawStatus = strtolower($row['attendance_status'] ?? ($row['status'] ?? 'present'));
            switch ($rawStatus) {
                case 'completed':
                    $statusKey = 'completed';
                    $statusLabel = 'Completed';
                    $statusIcon = 'fa-solid fa-circle-check';
                    break;
                case 'delayed':
                case 'late':
                    $statusKey = 'delayed';
                    $statusLabel = 'Delayed';
                    $statusIcon = 'fa-solid fa-clock';
                    break;
                case 'left_early':
                    $statusKey = 'left_early';
                    $statusLabel = 'Left Early';
                    $statusIcon = 'fa-solid fa-person-walking-arrow-right';
                    break;
                case 'half_day':
                    $statusKey = 'half_day';
                    $statusLabel = 'Half Day';
                    $statusIcon = 'fa-solid fa-adjust';
                    break;
                case 'absent':
                    $statusKey = 'absent';
                    $statusLabel = 'Absent';
                    $statusIcon = 'fa-solid fa-circle-xmark';
                    break;
                case 'on_leave':
                    $statusKey = 'on_leave';
                    $statusLabel = 'On Leave';
                    $statusIcon = 'fa-solid fa-calendar-day';
                    break;
                case 'present':
                default:
                    if (!empty($row['check_out'])) {
                        $statusKey = 'completed';
                        $statusLabel = 'Completed';
                        $statusIcon = 'fa-solid fa-circle-check';
                    } else {
                        $statusKey = 'present';
                        $statusLabel = 'Present';
                        $statusIcon = 'fa-solid fa-circle';
                    }
                    break;
            }
        }

        // 3. Scheduled Shift
        $hasSchedule = false;
        $shiftScheduleText = 'Schedule unavailable';
        $shiftName = $row['resolved_shift_name'] ?? 'Regular Shift';
        $shiftExceptionMode = '';
        if (!empty($row['resolved_checkin_start']) && !empty($row['resolved_checkout_time'])) {
            $hasSchedule = true;
            $shiftStartFormatted = date('h:i A', strtotime($row['resolved_checkin_start']));
            $shiftEndFormatted = date('h:i A', strtotime($row['resolved_checkout_time']));
            $shiftScheduleText = $shiftStartFormatted . ' – ' . $shiftEndFormatted;
            if (!empty($row['approved_request_mode'])) {
                $shiftExceptionMode = ($row['approved_request_mode'] === 'wfh') ? 'Approved WFH' : 'Approved Exception';
            }
        }

        // 4. Check In
        $hasCheckIn = !empty($row['check_in']);
        $checkInFormatted = $hasCheckIn ? date('h:i A', strtotime($row['check_in'])) : '—';

        // 5. Check Out
        $hasCheckOut = !empty($row['check_out']);
        if ($hasCheckOut) {
            $checkOutFormatted = date('h:i A', strtotime($row['check_out']));
        } elseif ($hasCheckIn) {
            $checkOutFormatted = 'Not checked out';
        } else {
            $checkOutFormatted = '—';
        }

        // 6. Working Hours
        if (!$hasCheckIn) {
            $workingHoursDisplay = '—';
        } elseif (!$hasCheckOut) {
            if ($statusKey === 'in_progress') {
                $workingHoursDisplay = 'In progress';
            } else {
                $workingHoursDisplay = 'Pending checkout';
            }
        } else {
            $inSec = strtotime($row['date'] . ' ' . $row['check_in']);
            $outSec = strtotime($row['date'] . ' ' . $row['check_out']);
            if ($outSec < $inSec) {
                $outSec = strtotime($row['date'] . ' ' . $row['check_out'] . ' +1 day');
            }
            $diffSec = max(0, $outSec - $inSec);
            $hrs = floor($diffSec / 3600);
            $mins = floor(($diffSec % 3600) / 60);
            if ($hrs > 0 && $mins > 0) {
                $workingHoursDisplay = "{$hrs}h {$mins}m";
            } elseif ($hrs > 0) {
                $workingHoursDisplay = "{$hrs}h";
            } elseif ($mins > 0) {
                $workingHoursDisplay = "{$mins}m";
            } else {
                $workingHoursDisplay = "< 1m";
            }
        }

        // 7. Location Info
        $locSub = '';
        if ($row['check_in_dist'] !== null) {
            $locType = 'verified';
            $locText = (int)$row['check_in_dist'] . 'm from office';
            if ($row['check_out_dist'] !== null) {
                $locSub = 'Out: ' . (int)$row['check_out_dist'] . 'm';
            }
        } elseif (($row['approved_request_mode'] ?? '') === 'wfh' || (($row['employee_work_mode'] ?? '') === 'wfh' && $row['check_in_dist'] === null)) {
            $locType = 'remote';
            $locText = 'WFH (Remote)';
            $locSub = 'Approved';
        } elseif (($row['approved_request_mode'] ?? '') === 'on_site_exception') {
            $locType = 'exception';
            $locText = 'On-Site Exception';
            $locSub = 'Approved';
        } else {
            $locType = 'missing';
            $locText = 'GPS not recorded';
            $locSub = '';
        }

        // 8. Daily Summary
        $finalSummary = trim($row['final_work_summary'] ?? '');
        $finalCompleted = trim($row['final_completed_tasks'] ?? '');
        $finalPending = trim($row['final_pending_tasks'] ?? '');
        $hasSummary = (!empty($finalSummary) || !empty($finalCompleted) || !empty($finalPending));

        return [
            'date_formatted'        => $formattedDate,
            'is_overnight'          => $isOvernight,
            'overnight_next_short'  => $overnightNextDayShort,
            'overnight_next_long'   => $overnightNextDayLong,
            'status_key'            => $statusKey,
            'status_label'          => $statusLabel,
            'status_icon'           => $statusIcon,
            'has_schedule'          => $hasSchedule,
            'shift_schedule_text'   => $shiftScheduleText,
            'shift_name'            => $shiftName,
            'shift_exception_mode'  => $shiftExceptionMode,
            'has_check_in'          => $hasCheckIn,
            'check_in_formatted'    => $checkInFormatted,
            'has_check_out'         => $hasCheckOut,
            'check_out_formatted'   => $checkOutFormatted,
            'working_hours_display' => $workingHoursDisplay,
            'loc_type'              => $locType,
            'loc_text'              => $locText,
            'loc_sub'               => $locSub,
            'has_summary'           => $hasSummary,
            'final_work_summary'    => $finalSummary,
            'final_completed_tasks' => $finalCompleted,
            'final_pending_tasks'   => $finalPending,
        ];
    }
}


