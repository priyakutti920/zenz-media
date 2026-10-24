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
}
