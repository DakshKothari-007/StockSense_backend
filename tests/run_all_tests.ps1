# ========================================================
# StockSense Backend Test Suite Runner
# Runs all automated backend test suites from /tests/
# ========================================================

$php = "C:\xampp\php\php.exe"
$testsDir = $PSScriptRoot

$suites = @(
    "test_token_auth.php",
    "test_login_errors.php",
    "test_profile_and_edit.php",
    "test_inventory_backend.php",
    "test_sales_backend.php",
    "test_sale_without_sale_date.php",
    "test_insights_backend.php",
    "test_reorder_backend.php",
    "test_settings_backend.php",
    "test_notifications_and_delete.php",
    "test_notification_product_icons.php",
    "test_push.php",
    "test_weekly_digest.php",
    "test_icon_classification.php",
    "test_import_column_detection.php",
    "test_bug1_import_detection.php",
    "test_import_good_file.php",
    "test_import_existing_and_new_sku.php",
    "test_import_idempotency.php",
    "test_bug3_chatbot_stock_responses.php",
    "test_ai.php"
)

Write-Host "========================================================" -ForegroundColor Cyan
Write-Host "       STOCKSENSE BACKEND COMPREHENSIVE TEST RUNNER     " -ForegroundColor Cyan
Write-Host "========================================================" -ForegroundColor Cyan
Write-Host "Running $($suites.Count) test suites from: $testsDir`n"

$totalSuites = $suites.Count
$passedSuites = 0
$failedSuites = 0
$suiteResults = @()

foreach ($suite in $suites) {
    $suitePath = Join-Path $testsDir $suite
    if (-not (Test-Path $suitePath)) {
        Write-Host " [SKIP] $suite (File not found)" -ForegroundColor Yellow
        continue
    }

    Write-Host ">>> Running $suite ..." -ForegroundColor Yellow
    $sw = [System.Diagnostics.Stopwatch]::StartNew()
    $output = & $php $suitePath 2>&1
    $exitCode = $LASTEXITCODE
    $sw.Stop()
    $elapsedSec = [math]::Round($sw.Elapsed.TotalSeconds, 2)

    $hasFailures = ($output -match "\[FAIL\]" -or $output -match "FAILURES:" -or $exitCode -ne 0)

    if (-not $hasFailures) {
        Write-Host " [PASS] $suite ($($elapsedSec)s)" -ForegroundColor Green
        $passedSuites++
        $suiteResults += [PSCustomObject]@{ Suite = $suite; Status = "PASS"; Time = "$($elapsedSec)s" }
    } else {
        Write-Host "![FAIL] $suite ($($elapsedSec)s) - ExitCode: $exitCode" -ForegroundColor Red
        $failedSuites++
        $suiteResults += [PSCustomObject]@{ Suite = $suite; Status = "FAIL"; Time = "$($elapsedSec)s" }
        # Print tail of error output
        $output | Select-Object -Last 10 | ForEach-Object { Write-Host "   $_" -ForegroundColor DarkRed }
    }
}

Write-Host "`n========================================================" -ForegroundColor Cyan
Write-Host "                   FINAL TEST SUMMARY                   " -ForegroundColor Cyan
Write-Host "========================================================" -ForegroundColor Cyan
$suiteResults | Format-Table -AutoSize
$finalColor = if ($failedSuites -eq 0) { "Green" } else { "Red" }
Write-Host "Total Suites: $totalSuites | Passed: $passedSuites | Failed: $failedSuites" -ForegroundColor $finalColor

if ($failedSuites -gt 0) {
    exit 1
} else {
    exit 0
}
