<?php
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/AttendanceHelper.php';

$summary = AttendanceHelper::getMonthWorkingDaysSummary($pdo, (int)date('Y'), (int)date('n'));
echo "Working Days Summary for current month:\n";
print_r($summary);

$todaySchedule = AttendanceHelper::getDayScheduleType($pdo, date('Y-m-d'));
echo "Today schedule:\n";
print_r($todaySchedule);
