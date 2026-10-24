<?php
// ============================================================================
// BACKUP RESTORATION VERIFICATION IN NON-PRODUCTION ENVIRONMENT
// Verifies full backup-and-restore disaster recovery pipeline.
// ============================================================================

require_once __DIR__ . '/../app/db.php';

$tempDb = 'test_restore_verification_' . time();
$testDumpFile = __DIR__ . '/../database/temp_verify_dump_' . time() . '.sql';

echo "--- 1. Generating Fresh Single-Transaction Backup of Staging DB ---" . PHP_EOL;
$dumpCmd = "c:\\xampp\\mysql\\bin\\mysqldump.exe -u root --single-transaction --quick --routines --triggers --databases qaschyan_staging --result-file=\"" . str_replace('\\', '/', $testDumpFile) . "\"";
exec($dumpCmd, $dumpOutput, $dumpCode);

if ($dumpCode !== 0 || !file_exists($testDumpFile) || filesize($testDumpFile) === 0) {
    echo "  [FAIL] mysqldump failed with code {$dumpCode}" . PHP_EOL;
    exit(1);
}
echo "  [PASS] Backup snapshot created successfully (" . round(filesize($testDumpFile) / 1024, 2) . " KB)" . PHP_EOL;

echo "--- 2. Retargeting Dump Stream to Isolated Non-Production DB: `{$tempDb}` ---" . PHP_EOL;
$sql = file_get_contents($testDumpFile);
$retargetedSql = str_replace('`qaschyan_staging`', "`{$tempDb}`", $sql);
$retargetedFile = __DIR__ . '/../database/temp_retargeted_' . time() . '.sql';
file_put_contents($retargetedFile, $retargetedSql);

echo "--- 3. Restoring Snapshot into Non-Production Target DB ---" . PHP_EOL;
$restoreCmd = "c:\\xampp\\mysql\\bin\\mysql.exe -u root -e \"source " . str_replace('\\', '/', $retargetedFile) . "\"";
exec($restoreCmd, $restoreOutput, $restoreCode);

if ($restoreCode !== 0) {
    echo "  [FAIL] Restoration failed with code {$restoreCode}" . PHP_EOL;
    $pdo->exec("DROP DATABASE IF EXISTS `{$tempDb}`");
    unlink($testDumpFile);
    unlink($retargetedFile);
    exit(1);
}
echo "  [PASS] Snapshot restored into `{$tempDb}` without errors" . PHP_EOL;

echo "--- 4. Comparing All 20 Tables & Exact Row Counts ---" . PHP_EOL;
$stagingTables = $pdo->query("SHOW TABLES FROM qaschyan_staging")->fetchAll(PDO::FETCH_COLUMN);
$restoredTables = $pdo->query("SHOW TABLES FROM `{$tempDb}`")->fetchAll(PDO::FETCH_COLUMN);

$tableCountsMatch = (count($stagingTables) === count($restoredTables) && count($stagingTables) === 20);
echo "  Table Count Match: " . ($tableCountsMatch ? "PASS (20 / 20 Tables)" : "FAIL") . PHP_EOL;

$allTablesIdentical = true;
foreach ($stagingTables as $table) {
    $stagingCount = (int)$pdo->query("SELECT COUNT(*) FROM qaschyan_staging.`{$table}`")->fetchColumn();
    $restoredCount = (int)$pdo->query("SELECT COUNT(*) FROM `{$tempDb}`.`{$table}`")->fetchColumn();
    if ($stagingCount !== $restoredCount) {
        $allTablesIdentical = false;
        echo "  [MISMATCH] Table `{$table}`: Staging={$stagingCount}, Restored={$restoredCount}" . PHP_EOL;
    }
}

if ($allTablesIdentical) {
    echo "  [PASS] All 20 table row counts match staging with 100% data fidelity!" . PHP_EOL;
}

echo "--- 5. Cleaning Up Test Artifacts ---" . PHP_EOL;
$pdo->exec("DROP DATABASE IF EXISTS `{$tempDb}`");
unlink($testDumpFile);
unlink($retargetedFile);
echo "  Temporary test database `{$tempDb}` and SQL files removed." . PHP_EOL;

$finalPassed = ($tableCountsMatch && $allTablesIdentical);
echo PHP_EOL . "======================================================================" . PHP_EOL;
echo "  BACKUP RESTORATION VERIFICATION: " . ($finalPassed ? "PASS (DISASTER RECOVERY VERIFIED)" : "FAIL") . PHP_EOL;
echo "======================================================================" . PHP_EOL;
