param(
    [string]$BaseUrl = "http://localhost/Hospital/public"
)

$ErrorActionPreference = "Stop"

function U {
    param([string]$Escaped)
    return [System.Text.RegularExpressions.Regex]::Unescape($Escaped)
}

function Send-Chat {
    param(
        [Microsoft.PowerShell.Commands.WebRequestSession]$Session,
        [string]$PageId,
        [string]$Message
    )

    $body = @{ message = $Message; chat_page_id = $PageId } | ConvertTo-Json
    Invoke-RestMethod -WebSession $Session -Uri "$BaseUrl/api/chat" -Method POST -ContentType "application/json; charset=utf-8" -Body $body -TimeoutSec 90
}

$results = @()

$hello = Send-Chat -Session (New-Object Microsoft.PowerShell.Commands.WebRequestSession) -PageId "eval-hello" -Message (U "\u0645\u0631\u062d\u0628\u0627")
$results += [pscustomobject]@{ test = "greeting"; ok = ($hello.intent -eq "greeting"); intent = $hello.intent; reply = $hello.reply }

$deptMessage = U "\u0648\u064a\u0646 \u0642\u0633\u0645 \u0627\u0644\u0642\u0644\u0628 \u0648\u0634\u0648 \u062f\u0648\u0627\u0645\u0647"
$dept = Send-Chat -Session (New-Object Microsoft.PowerShell.Commands.WebRequestSession) -PageId "eval-dept" -Message $deptMessage
$results += [pscustomobject]@{ test = "department"; ok = ($dept.intent -eq "ask_departments"); intent = $dept.intent; reply = $dept.reply }

$doctorMessage = U "\u0645\u064a\u0646 \u062f\u0643\u0627\u062a\u0631\u0629 \u0627\u0644\u0642\u0644\u0628"
$doctor = Send-Chat -Session (New-Object Microsoft.PowerShell.Commands.WebRequestSession) -PageId "eval-doctor" -Message $doctorMessage
$results += [pscustomobject]@{ test = "doctor"; ok = ($doctor.intent -eq "ask_doctors"); intent = $doctor.intent; reply = $doctor.reply }

$session = New-Object Microsoft.PowerShell.Commands.WebRequestSession
$page = "eval-booking-" + [guid]::NewGuid().ToString("N")
$email = "eval_" + (Get-Date -Format "yyyyMMddHHmmss") + "@example.com"

$step1 = Send-Chat -Session $session -PageId $page -Message (U "\u0628\u062f\u064a \u0627\u062d\u062c\u0632 \u0639\u0646\u062f \u062f\u0643\u062a\u0648\u0631 \u0642\u0644\u0628")
$step2 = Send-Chat -Session $session -PageId $page -Message (U "\u0627\u0646\u0633")
$step3 = Send-Chat -Session $session -PageId $page -Message (U "2026-05-22 \u0627\u0644\u0633\u0627\u0639\u0629 10:30")
$step4 = Send-Chat -Session $session -PageId $page -Message (U "\u0645\u0631\u0627\u062c\u0639\u0629 \u0639\u0627\u0645\u0629")
$step5 = Send-Chat -Session $session -PageId $page -Message $email
$wrong = Send-Chat -Session $session -PageId $page -Message "111111"

$codeMatch = [regex]::Match($step5.reply, "\b\d{6}\b")
$final = $null
if ($codeMatch.Success) {
    $final = Send-Chat -Session $session -PageId $page -Message $codeMatch.Value
}

$bookingOk = $final -and $final.intent -eq "appointment_booked"
$finalIntent = if ($final) { $final.intent } else { "missing" }
$finalReply = if ($final) { $final.reply } else { "missing" }
$results += [pscustomobject]@{ test = "booking_partial_doctor"; ok = ($step2.intent -eq "booking_need_date"); intent = $step2.intent; reply = $step2.reply }
$results += [pscustomobject]@{ test = "otp_wrong"; ok = ($wrong.intent -eq "booking_otp_invalid"); intent = $wrong.intent; reply = $wrong.reply }
$results += [pscustomobject]@{ test = "booking_created"; ok = $bookingOk; intent = $finalIntent; reply = $finalReply }

if ($bookingOk -and $final.data.appointment.appointment_id) {
    $appointmentId = [int]$final.data.appointment.appointment_id
    & C:\xampp\mysql\bin\mysql.exe -uroot ahli_hospital -e "DELETE FROM Appointment WHERE appointment_id = $appointmentId; DELETE FROM Patient WHERE email = '$email';" | Out-Null
}

$emergencyMessage = U "\u0639\u0646\u062f\u064a \u0623\u0644\u0645 \u0635\u062f\u0631 \u0634\u062f\u064a\u062f \u0648\u0645\u0634 \u0642\u0627\u062f\u0631 \u0627\u062a\u0646\u0641\u0633"
$emergency = Send-Chat -Session (New-Object Microsoft.PowerShell.Commands.WebRequestSession) -PageId "eval-emergency" -Message $emergencyMessage
$results += [pscustomobject]@{ test = "emergency"; ok = ($emergency.intent -eq "medical_emergency"); intent = $emergency.intent; reply = $emergency.reply }

$results | Format-Table -AutoSize

if (($results | Where-Object { -not $_.ok }).Count -gt 0) {
    exit 1
}
