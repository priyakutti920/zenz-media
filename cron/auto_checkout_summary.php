<?php
/**
 * Automated Shift Cutoff & Daily Work Summary Scheduler CLI Runner
 *
 * Usage:
 *   php cron/auto_checkout_summary.php
 *   php cron/auto_checkout_summary.php --date=2026-10-24
 *   php cron/auto_checkout_summary.php --no-email
 *   php cron/auto_checkout_summary.php --verbose
 *
 * Windows Task Scheduler Setup:
 *   schtasks /Create /SC MINUTE /MO 5 /TN "StaffPortal_AutoCheckoutSummary" /TR "C:\xampp\php\php.exe C:\xampp\htdocs\cron\auto_checkout_summary.php" /F
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    echo "Forbidden: This script can only be executed via PHP CLI." . PHP_EOL;
    exit(1);
}

date_default_timezone_set('Asia/Kolkata');

require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/security.php';
require_once __DIR__ . '/../app/AttendanceHelper.php';
require_once __DIR__ . '/../app/Mailer.php';

Security::init($pdo);

// Parse CLI Arguments
$options = getopt('', ['date:', 'no-email', 'verbose', 'help', 'portal-url:']);

if (isset($options['help'])) {
    echo "======================================================================" . PHP_EOL;
    echo " Automated Shift Checkout & Daily Work Summary Scheduler CLI" . PHP_EOL;
    echo "======================================================================" . PHP_EOL;
    echo "Options:" . PHP_EOL;
    echo "  --date=YYYY-MM-DD    Override workday date (defaults to current server date)" . PHP_EOL;
    echo "  --no-email           Run token generation and portal closure without sending emails" . PHP_EOL;
    echo "  --portal-url=URL     Base URL for email summary links (defaults to site_settings or localhost)" . PHP_EOL;
    echo "  --verbose            Show detailed log lines for each processed employee" . PHP_EOL;
    echo "  --help               Display this help text" . PHP_EOL;
    exit(0);
}

$workDate = $options['date'] ?? date('Y-m-d');
$sendEmail = !isset($options['no-email']);
$portalUrl = $options['portal-url'] ?? null;
$verbose = isset($options['verbose']);

$startTime = microtime(true);
$currentTime = date('Y-m-d H:i:s');

echo "======================================================================" . PHP_EOL;
echo " [START] Automated Shift Cutoff & Daily Summary Scheduler" . PHP_EOL;
echo " Timestamp: {$currentTime} (Asia/Kolkata)" . PHP_EOL;
echo " Target Workday: {$workDate}" . PHP_EOL;
echo " Email Delivery: " . ($sendEmail ? "ENABLED" : "DISABLED (--no-email)") . PHP_EOL;
echo "======================================================================" . PHP_EOL;

try {
    $results = AttendanceHelper::processShiftCutoffsAndSendSummaryEmails($pdo, [
        'date'       => $workDate,
        'send_email' => $sendEmail,
        'portal_url' => $portalUrl
    ]);

    $duration = round(microtime(true) - $startTime, 3);

    echo PHP_EOL . "--- Scheduler Execution Summary ---" . PHP_EOL;
    echo "  Records Scanned:        " . $results['scanned'] . PHP_EOL;
    echo "  Cutoffs Reached:        " . $results['cutoff_reached'] . PHP_EOL;
    echo "  Tokens Generated:       " . $results['tokens_created'] . PHP_EOL;
    echo "  Summary Emails Sent:    " . $results['emails_sent'] . PHP_EOL;
    echo "  Summary Emails Failed:  " . $results['emails_failed'] . PHP_EOL;
    echo "  Reminders Sent:         " . $results['reminders_sent'] . PHP_EOL;
    echo "  Skipped (Not due):      " . $results['skipped'] . PHP_EOL;
    echo "  Duration:               {$duration}s" . PHP_EOL;

    if (!empty($results['errors'])) {
        echo PHP_EOL . "--- Encountered Warnings / Errors ---" . PHP_EOL;
        foreach ($results['errors'] as $err) {
            echo "  [WARN] " . $err . PHP_EOL;
        }
    }

    echo PHP_EOL . "[DONE] Scheduler run completed successfully." . PHP_EOL;
    exit(0);

} catch (Throwable $e) {
    echo PHP_EOL . "[FATAL] Scheduler crashed with error: " . $e->getMessage() . PHP_EOL;
    echo $e->getTraceAsString() . PHP_EOL;
    exit(1);
}
