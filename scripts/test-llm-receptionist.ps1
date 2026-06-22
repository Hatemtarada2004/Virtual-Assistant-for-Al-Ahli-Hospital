param([string]$BaseUrl = "http://localhost/hospital-chatbot/public")
$ErrorActionPreference = "Stop"
function U { param([string]$E); return [System.Text.RegularExpressions.Regex]::Unescape($E) }
function Send-Chat {
    param([Microsoft.PowerShell.Commands.WebRequestSession]$Session,[string]$PageId,[string]$Message)
    $body = @{ message=$Message; chat_page_id=$PageId } | ConvertTo-Json -Compress
    return Invoke-RestMethod -WebSession $Session -Uri "$BaseUrl/api/chat" -Method POST -ContentType "application/json; charset=utf-8" -Body $body -TimeoutSec 90
}
$results = @()
$r = Send-Chat (New-Object Microsoft.PowerShell.Commands.WebRequestSession) "g1" (U "مرحبا")
$results += [pscustomobject]@{ test="greeting"; ok=($r.intent -eq "greeting"); intent=$r.intent }
$r = Send-Chat (New-Object Microsoft.PowerShell.Commands.WebRequestSession) "d1" (U "وين قسم القلب")
$results += [pscustomobject]@{ test="department"; ok=($r.intent -eq "ask_departments"); intent=$r.intent }
$r = Send-Chat (New-Object Microsoft.PowerShell.Commands.WebRequestSession) "dr1" (U "مين دكاترة القلب")
$results += [pscustomobject]@{ test="doctor"; ok=($r.intent -eq "ask_doctors"); intent=$r.intent }
$r = Send-Chat (New-Object Microsoft.PowerShell.Commands.WebRequestSession) "em1" (U "عندي ألم صدر شديد ومش قادر اتنفس")
$results += [pscustomobject]@{ test="emergency"; ok=($r.intent -eq "medical_emergency"); intent=$r.intent }
$sess = New-Object Microsoft.PowerShell.Commands.WebRequestSession
$page = "bk-" + [guid]::NewGuid().ToString("N")
[void](Send-Chat $sess $page (U "بدي احجز عند دكتور قلب"))
$s2 = Send-Chat $sess $page (U "انس")
$results += [pscustomobject]@{ test="booking_doctor"; ok=($s2.intent -eq "booking_need_date"); intent=$s2.intent }
[void](Send-Chat $sess $page "2026-07-15 10:00")
$s4 = Send-Chat $sess $page (U "مراجعة عامة")
$results += [pscustomobject]@{ test="booking_national_id"; ok=($s4.intent -eq "booking_need_national_id"); intent=$s4.intent }
$logPath = "C:\xampp\htdocs\hospital-chatbot\storage\logs\email-otp.log"
$beforeCount = @(Get-Content $logPath -Encoding UTF8 -ErrorAction SilentlyContinue).Count
$s5 = Send-Chat $sess $page "401234567"
$results += [pscustomobject]@{ test="booking_otp_sent"; ok=($s5.intent -eq "booking_email_code_sent"); intent=$s5.intent }
$sw = Send-Chat $sess $page "999999"
$results += [pscustomobject]@{ test="otp_wrong"; ok=($sw.intent -eq "booking_otp_invalid"); intent=$sw.intent }
$otpCode = $null
$logLines = @(Get-Content $logPath -Encoding UTF8 -ErrorAction SilentlyContinue)
if ($logLines.Count -gt $beforeCount) {
    foreach ($line in $logLines[$beforeCount..($logLines.Count - 1)]) {
        $m = [regex]::Match($line, '\b\d{6}\b')
        if ($m.Success) { $otpCode = $m.Value; break }
    }
}
if (-not $otpCode) {
    $cm = [regex]::Match($s5.reply, '\b\d{6}\b')
    if ($cm.Success) { $otpCode = $cm.Value }
}
$final = $null; $resched = $null
if ($otpCode) {
    $final = Send-Chat $sess $page $otpCode
    if ($final -and $final.intent -eq "appointment_booked") {
        $resched = Send-Chat $sess $page (U "بدي اغير الموعد للساعه 11:00")
    }
}
$fi = if ($final) { $final.intent } else { "missing" }
$ri = if ($resched) { $resched.intent } else { "missing" }
$results += [pscustomobject]@{ test="booking_created"; ok=($fi -eq "appointment_booked"); intent=$fi }
$results += [pscustomobject]@{ test="booking_rescheduled"; ok=($ri -eq "appointment_rescheduled"); intent=$ri }
if ($final -and $final.data.appointment.appointment_id) {
    $aid = [int]$final.data.appointment.appointment_id
    & "C:\xampp\mysql\bin\mysql.exe" -uroot ahli_hospital -e "DELETE FROM Appointment WHERE appointment_id = $aid;" 2>$null
}
$results | Format-Table -AutoSize
$failed = @($results | Where-Object { -not $_.ok })
Write-Host ("TOTAL: {0} | PASSED: {1} | FAILED: {2}" -f $results.Count, ($results.Count - $failed.Count), $failed.Count)
if ($failed.Count -gt 0) { $failed | ForEach-Object { Write-Host ("  FAIL: {0} -> {1}" -f $_.test, $_.intent) }; exit 1 }