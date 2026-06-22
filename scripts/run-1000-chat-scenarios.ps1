param(
    [string]$BaseUrl = 'http://127.0.0.1/hospital-chatbot/public',
    [int]$ScenarioCount = 1000,
    [switch]$KeepArtifacts
)

$ErrorActionPreference = 'Stop'
$script:Headers = @{ 'X-Chatbot-Test-Disable-Llm' = '1' }

function U {
    param([string]$Escaped)
    return [string](ConvertFrom-Json ('"' + $Escaped + '"'))
}

function New-ChatHandle {
    return [pscustomobject]@{
        Session = New-Object Microsoft.PowerShell.Commands.WebRequestSession
        PageId = 'chat-' + [guid]::NewGuid().ToString('N')
    }
}

function Invoke-JsonApi {
    param(
        [string]$Method,
        [string]$Uri,
        [Microsoft.PowerShell.Commands.WebRequestSession]$Session,
        $Body = $null
    )

    $invokeArgs = @{
        Uri = $Uri
        Method = $Method
        WebSession = $Session
        TimeoutSec = 90
        Headers = $script:Headers
        ContentType = 'application/json; charset=utf-8'
    }

    if ($null -ne $Body) {
        $invokeArgs.Body = ($Body | ConvertTo-Json -Depth 8 -Compress)
    }

    $result = Invoke-RestMethod @invokeArgs
    return Normalize-ApiResult -Result $result
}

function Send-Chat {
    param(
        [pscustomobject]$Chat,
        [string]$Message
    )

    return Invoke-JsonApi -Method 'POST' -Uri ($script:ChatApi) -Session $Chat.Session -Body @{
        message = $Message
        chat_page_id = $Chat.PageId
    }
}

function Reset-Chat {
    param([pscustomobject]$Chat)
    try {
        [void](Send-Chat -Chat $Chat -Message 'reset')
    } catch {
    }
}

function Register-Patient {
    param(
        [Microsoft.PowerShell.Commands.WebRequestSession]$Session,
        [hashtable]$Identity
    )

    return Invoke-JsonApi -Method 'POST' -Uri ($script:AuthRegisterApi) -Session $Session -Body $Identity
}

function Login-Patient {
    param(
        [Microsoft.PowerShell.Commands.WebRequestSession]$Session,
        [hashtable]$Identity
    )

    return Invoke-JsonApi -Method 'POST' -Uri ($script:AuthLoginApi) -Session $Session -Body @{
        national_id = $Identity.national_id
        phone = $Identity.phone
    }
}

function Logout-Patient {
    param([Microsoft.PowerShell.Commands.WebRequestSession]$Session)
    return Invoke-JsonApi -Method 'POST' -Uri ($script:AuthLogoutApi) -Session $Session -Body @{}
}

function Get-JsonApi {
    param(
        [string]$Uri,
        [Microsoft.PowerShell.Commands.WebRequestSession]$Session
    )

    $result = Invoke-RestMethod -Uri $Uri -Method 'GET' -WebSession $Session -TimeoutSec 90
    return Normalize-ApiResult -Result $result
}

function Normalize-ApiResult {
    param($Result)

    if ($Result -is [string]) {
        $normalized = $Result.Trim()
        $normalized = $normalized.Trim([char]0xFEFF)
        if ($normalized.StartsWith('{') -or $normalized.StartsWith('[')) {
            return $normalized | ConvertFrom-Json
        }
    }

    return $Result
}

function New-TestIdentity {
    param([int]$Index)

    $stamp = '{0:D4}' -f $Index
    return @{
        full_name = "ظ…ط±ظٹط¶ ط§ط®طھط¨ط§ط± $stamp"
        national_id = "9900$stamp"
        phone = "0597$('{0:D6}' -f $Index)"
        email = "chat1000+$stamp@example.com"
        date_of_birth = '1995-01-15'
        gender = if ($Index % 2 -eq 0) { 'Male' } else { 'Female' }
    }
}

function Read-LatestOtpCode {
    param([string]$Email)

    if (-not (Test-Path $script:OtpLogPath)) {
        return $null
    }

    $lines = Get-Content $script:OtpLogPath
    for ($i = $lines.Count - 1; $i -ge 0; $i--) {
        $line = $lines[$i]
        if ([string]::IsNullOrWhiteSpace($line)) {
            continue
        }

        try {
            $entry = $line | ConvertFrom-Json
        } catch {
            continue
        }

        $entryTo = ''
        if ($null -ne $entry.PSObject.Properties['to']) {
            $entryTo = [string]$entry.to
        }

        if ($entryTo -ne $Email) {
            continue
        }

        $entryBody = ''
        if ($null -ne $entry.PSObject.Properties['body']) {
            $entryBody = [string]$entry.body
        }

        $match = [regex]::Match($entryBody, '\b(\d{6})\b')
        if ($match.Success) {
            return $match.Groups[1].Value
        }
    }

    return $null
}

function Get-AvailableSlots {
    param(
        [int]$DoctorId,
        [string]$Date
    )

    $uri = '{0}/api/appointments/available?doctor_id={1}&date={2}' -f $BaseUrl, $DoctorId, $Date
    $response = Normalize-ApiResult -Result (Invoke-RestMethod -Uri $uri -Method 'GET' -TimeoutSec 90)
    return $response.data.available_slots
}

function Find-BookableSlot {
    param(
        [int]$DoctorId,
        [int]$StartOffsetDays = 1,
        [int]$MaxOffsetDays = 10
    )

    for ($offset = $StartOffsetDays; $offset -le $MaxOffsetDays; $offset++) {
        $date = (Get-Date).Date.AddDays($offset).ToString('yyyy-MM-dd')
        try {
            $slots = @(Get-AvailableSlots -DoctorId $DoctorId -Date $date)
        } catch {
            continue
        }

        if ($slots.Count -gt 0) {
            return @{
                date = $date
                time = $slots[0]
                alternate_time = if ($slots.Count -gt 1) { $slots[1] } else { $slots[0] }
                slots = $slots
            }
        }
    }

    throw "No available slots found for doctor $DoctorId"
}

function Format-SqlTime {
    param([string]$Time)

    $trimmed = $Time.Trim()
    if ($trimmed -match '^\d{2}:\d{2}:\d{2}$') {
        return $trimmed
    }
    if ($trimmed -match '^\d{2}:\d{2}$') {
        return "$trimmed`:00"
    }
    return $trimmed
}

function Create-AppointmentViaApi {
    param(
        [Microsoft.PowerShell.Commands.WebRequestSession]$Session,
        $Doctor,
        [string]$Date,
        [string]$Time,
        [string]$Reason = 'ظ…ط±ط§ط¬ط¹ط© ط§ط®طھط¨ط§ط±'
    )

    $datetime = '{0} {1}' -f $Date, (Format-SqlTime -Time $Time)
    return Invoke-JsonApi -Method 'POST' -Uri ($script:AppointmentsApi) -Session $Session -Body @{
        doctor_id = [int]$Doctor.doctor_id
        department_id = [int]$Doctor.department_id
        appointment_datetime = $datetime
        reason = $Reason
    }
}

function Cancel-AppointmentViaApi {
    param(
        [Microsoft.PowerShell.Commands.WebRequestSession]$Session,
        [int]$AppointmentId
    )

    return Invoke-JsonApi -Method 'PUT' -Uri ('{0}/api/appointments/{1}/cancel' -f $BaseUrl, $AppointmentId) -Session $Session -Body @{}
}

function Reschedule-AppointmentViaApi {
    param(
        [Microsoft.PowerShell.Commands.WebRequestSession]$Session,
        [int]$AppointmentId,
        [string]$Datetime
    )

    return Invoke-JsonApi -Method 'PUT' -Uri ('{0}/api/appointments/{1}/reschedule' -f $BaseUrl, $AppointmentId) -Session $Session -Body @{
        appointment_datetime = $Datetime
    }
}

function Cleanup-TestArtifacts {
    if ($KeepArtifacts) {
        return
    }

    $sql = @"
DELETE FROM Appointment WHERE patient_id IN (SELECT patient_id FROM Patient WHERE email LIKE 'chat1000+%@example.com');
DELETE FROM Patient WHERE email LIKE 'chat1000+%@example.com';
"@

    $tempFile = Join-Path $env:TEMP ('chat1000-cleanup-' + [guid]::NewGuid().ToString('N') + '.sql')
    Set-Content -LiteralPath $tempFile -Value $sql -Encoding UTF8
    try {
        Get-Content -LiteralPath $tempFile | & 'C:\xampp\mysql\bin\mysql.exe' -uroot ahli_hospital --default-character-set=utf8mb4 | Out-Null
    } finally {
        Remove-Item -LiteralPath $tempFile -Force -ErrorAction SilentlyContinue
    }
}

function Assert-Intent {
    param($Response, [string[]]$Allowed)
    $intent = if ($null -ne $Response -and $null -ne $Response.PSObject.Properties['intent']) { [string]$Response.intent } else { '' }
    return $Allowed -contains $intent
}

function Assert-ReplyNotContains {
    param($Response, [string[]]$Needles)
    $reply = if ($null -ne $Response -and $null -ne $Response.PSObject.Properties['reply']) { [string]$Response.reply } else { '' }
    foreach ($needle in $Needles) {
        if ($reply.Contains($needle)) {
            return $false
        }
    }
    return $true
}

function Add-ScenarioResult {
    param(
        [string]$Name,
        [bool]$Ok,
        [string]$Group,
        [string]$Note,
        $LastResponse
    )

    $script:Results.Add([pscustomobject]@{
        name = $Name
        ok = $Ok
        group = $Group
        intent = if ($null -ne $LastResponse -and $null -ne $LastResponse.PSObject.Properties['intent']) { [string]$LastResponse.intent } else { '' }
        reply = if ($null -ne $LastResponse -and $null -ne $LastResponse.PSObject.Properties['reply']) { [string]$LastResponse.reply } else { '' }
        note = $Note
    }) | Out-Null
}

function Run-SingleTurnScenario {
    param(
        [string]$Name,
        [string]$Group,
        [string]$Message,
        [scriptblock]$Predicate
    )

    $chat = New-ChatHandle
    $response = Send-Chat -Chat $chat -Message $Message
    $ok = & $Predicate $response
    $note = if ($ok) { 'ok' } else { "Unexpected intent/reply for: $Message" }
    Add-ScenarioResult -Name $Name -Ok $ok -Group $Group -Note $note -LastResponse $response
}

function Run-BookingProgressScenario {
    param(
        [string]$Name,
        $Doctor,
        [hashtable]$Slot,
        [string]$OpeningMessage
    )

    $chat = New-ChatHandle
    $step1 = Send-Chat -Chat $chat -Message $OpeningMessage
    $bookingStepMessage = ('{0} ط§ظ„ط³ط§ط¹ط© {1}' -f $Slot.date, $Slot.time)
    $step2 = Send-Chat -Chat $chat -Message $bookingStepMessage
    $bookingActive = $false
    if ($null -ne $step1.data -and $null -ne $step1.data.conversation_state -and $null -ne $step1.data.conversation_state.booking) {
        $bookingActive = [bool]$step1.data.conversation_state.booking.active
    }
    $ok = ($bookingActive -eq $true) -and (Assert-Intent -Response $step2 -Allowed @('booking_need_reason', 'booking_choose_time'))
    Add-ScenarioResult -Name $Name -Ok $ok -Group 'booking_progress' -Note 'Booking should stay active and advance to reason/time.' -LastResponse $step2
}

function Run-BookingInterruptionScenario {
    param(
        [string]$Name,
        $Doctor,
        [hashtable]$Slot,
        [string]$SideQuestion
    )

    $chat = New-ChatHandle
    [void](Send-Chat -Chat $chat -Message ('ط¨ط¯ظٹ ط§ط­ط¬ط² ط¹ظ†ط¯ {0}' -f $Doctor.full_name))
    $bookingStepMessage = ('{0} ط§ظ„ط³ط§ط¹ط© {1}' -f $Slot.date, $Slot.time)
    [void](Send-Chat -Chat $chat -Message $bookingStepMessage)
    $response = Send-Chat -Chat $chat -Message $SideQuestion
    $bookingActive = $true
    if ($null -ne $response.data -and $null -ne $response.data.conversation_state -and $null -ne $response.data.conversation_state.booking) {
        $bookingActive = [bool]$response.data.conversation_state.booking.active
    }
    $ok = (Assert-Intent -Response $response -Allowed @('booking_side_question', 'ask_services', 'ask_departments', 'ask_doctors')) -and (-not $bookingActive)
    Add-ScenarioResult -Name $Name -Ok $ok -Group 'booking_interrupt' -Note 'Side question should stop sticky booking flow.' -LastResponse $response
}

function Run-BookingResetScenario {
    param(
        [string]$Name,
        $Doctor,
        [string]$ResetMessage
    )

    $chat = New-ChatHandle
    [void](Send-Chat -Chat $chat -Message ('ط¨ط¯ظٹ ط§ط­ط¬ط² ط¹ظ†ط¯ {0}' -f $Doctor.full_name))
    $response = Send-Chat -Chat $chat -Message $ResetMessage
    $bookingActive = $true
    if ($null -ne $response.data -and $null -ne $response.data.conversation_state -and $null -ne $response.data.conversation_state.booking) {
        $bookingActive = [bool]$response.data.conversation_state.booking.active
    }
    $ok = (([string]$response.intent) -eq 'reset') -and (-not $bookingActive)
    Add-ScenarioResult -Name $Name -Ok $ok -Group 'booking_reset' -Note 'Reset/cancel should clear booking state.' -LastResponse $response
}

function Run-LoginRequiredScenario {
    param([string]$Name, [string]$Message)
    $chat = New-ChatHandle
    $response = Send-Chat -Chat $chat -Message $Message
    $ok = (([string]$response.intent) -eq 'appointment_login_required')
    Add-ScenarioResult -Name $Name -Ok $ok -Group 'login_required' -Note 'Existing appointment actions should require login.' -LastResponse $response
}

function Run-AuthScenario {
    param([string]$Name, [int]$Index)
    $identity = New-TestIdentity -Index (7000 + $Index)
    $session = New-Object Microsoft.PowerShell.Commands.WebRequestSession
    $register = Register-Patient -Session $session -Identity $identity
    $logout = Logout-Patient -Session $session
    $login = Login-Patient -Session $session -Identity $identity
    $ok = (([bool]$register.success) -eq $true) -and (([bool]$login.success) -eq $true)
    Add-ScenarioResult -Name $Name -Ok $ok -Group 'auth' -Note 'Register/login flow should work.' -LastResponse $login
}

function Run-ExistingAppointmentCancelScenario {
    param(
        [string]$Name,
        [int]$Index,
        $Doctor
    )

    $identity = New-TestIdentity -Index (8000 + $Index)
    $session = New-Object Microsoft.PowerShell.Commands.WebRequestSession
    [void](Register-Patient -Session $session -Identity $identity)
    $slot = Find-BookableSlot -DoctorId ([int]$Doctor.doctor_id) -StartOffsetDays 2 -MaxOffsetDays 12
    $created = Create-AppointmentViaApi -Session $session -Doctor $Doctor -Date $slot.date -Time $slot.time
    $chat = [pscustomobject]@{ Session = $session; PageId = 'chat-' + [guid]::NewGuid().ToString('N') }
    $response = Send-Chat -Chat $chat -Message 'ط¨ط¯ظٹ ط§ظ„ط؛ظٹ ظ…ظˆط¹ط¯ظٹ'
    $ok = (([string]$response.intent) -eq 'appointment_cancelled')
    Add-ScenarioResult -Name $Name -Ok $ok -Group 'appointment_cancel' -Note 'Logged-in patient should be able to cancel own appointment.' -LastResponse $response
}

function Run-ExistingAppointmentRescheduleScenario {
    param(
        [string]$Name,
        [int]$Index,
        $Doctor
    )

    $identity = New-TestIdentity -Index (9000 + $Index)
    $session = New-Object Microsoft.PowerShell.Commands.WebRequestSession
    [void](Register-Patient -Session $session -Identity $identity)
    $firstSlot = Find-BookableSlot -DoctorId ([int]$Doctor.doctor_id) -StartOffsetDays 2 -MaxOffsetDays 12
    [void](Create-AppointmentViaApi -Session $session -Doctor $Doctor -Date $firstSlot.date -Time $firstSlot.time)

    $targetSlot = $null
    for ($offset = 3; $offset -le 13; $offset++) {
        $candidate = Find-BookableSlot -DoctorId ([int]$Doctor.doctor_id) -StartOffsetDays $offset -MaxOffsetDays $offset
        if ($candidate.date -ne $firstSlot.date -or $candidate.time -ne $firstSlot.time) {
            $targetSlot = $candidate
            break
        }
    }

    if ($null -eq $targetSlot) {
        throw 'Could not find alternate slot for reschedule scenario.'
    }

    $chat = [pscustomobject]@{ Session = $session; PageId = 'chat-' + [guid]::NewGuid().ToString('N') }
    $message = ('ط¨ط¯ظٹ ط§ط؛ظٹط± ظ…ظˆط¹ط¯ظٹ ظ„ظٹظˆظ… {0} ط§ظ„ط³ط§ط¹ط© {1}' -f $targetSlot.date, $targetSlot.time)
    $response = Send-Chat -Chat $chat -Message $message
    $ok = (([string]$response.intent) -eq 'appointment_rescheduled')
    Add-ScenarioResult -Name $Name -Ok $ok -Group 'appointment_reschedule' -Note 'Logged-in patient should be able to reschedule own appointment.' -LastResponse $response
}

function Run-FullBookingOtpScenario {
    param(
        [string]$Name,
        [int]$Index,
        $Doctor
    )

    $chat = New-ChatHandle
    $slot = Find-BookableSlot -DoctorId ([int]$Doctor.doctor_id) -StartOffsetDays 4 -MaxOffsetDays 14
    $email = ('chat1000+otp{0:D4}' -f $Index) + '@example.com'
    [void](Send-Chat -Chat $chat -Message ('ط¨ط¯ظٹ ط§ط­ط¬ط² ط¹ظ†ط¯ {0}' -f $Doctor.full_name))
    $bookingStepMessage = ('{0} ط§ظ„ط³ط§ط¹ط© {1}' -f $slot.date, $slot.time)
    [void](Send-Chat -Chat $chat -Message $bookingStepMessage)
    [void](Send-Chat -Chat $chat -Message 'ظ…ط±ط§ط¬ط¹ط©')
    $emailResponse = Send-Chat -Chat $chat -Message $email
    $wrongResponse = Send-Chat -Chat $chat -Message '111111'
    $otp = Read-LatestOtpCode -Email $email
    $final = if ($otp) { Send-Chat -Chat $chat -Message $otp } else { $null }
    $ok = (([string]$emailResponse.intent) -eq 'booking_email_code_sent') -and (([string]$wrongResponse.intent) -eq 'booking_otp_invalid') -and ($null -ne $final) -and (([string]$final.intent) -eq 'appointment_booked')
    Add-ScenarioResult -Name $Name -Ok $ok -Group 'booking_otp' -Note 'OTP flow should reject wrong code and accept the correct one.' -LastResponse $(if ($null -ne $final) { $final } else { $wrongResponse })
}

$script:ChatApi = $BaseUrl + '/api/chat'
$script:AuthRegisterApi = $BaseUrl + '/api/auth/register'
$script:AuthLoginApi = $BaseUrl + '/api/auth/login'
$script:AuthLogoutApi = $BaseUrl + '/api/auth/logout'
$script:AppointmentsApi = $BaseUrl + '/api/appointments'
$script:OtpLogPath = 'C:\xampp\htdocs\hospital-chatbot\storage\logs\email-otp.log'
$script:Results = New-Object System.Collections.Generic.List[object]

Cleanup-TestArtifacts

$doctorsResponse = Get-JsonApi -Uri ($BaseUrl + '/api/doctors') -Session (New-Object Microsoft.PowerShell.Commands.WebRequestSession)
$departmentsResponse = Get-JsonApi -Uri ($BaseUrl + '/api/departments') -Session (New-Object Microsoft.PowerShell.Commands.WebRequestSession)
$servicesResponse = Get-JsonApi -Uri ($BaseUrl + '/api/services') -Session (New-Object Microsoft.PowerShell.Commands.WebRequestSession)

$doctors = @($doctorsResponse.data)
$departments = @($departmentsResponse.data)
$services = @($servicesResponse.data)

$greetings = @('مرحبا', 'أهلا', 'السلام عليكم', 'هلا', 'هاي')
$smalltalk = @('كيفك', 'شلونك', 'شو اخبارك', 'كيف الحال')
$locationVerbs = @('وين', 'شو دوام', 'موقع', 'اي طابق')
$doctorPrompts = @('مين دكاترة', 'مين طبيب', 'شو تخصص')
$pricePrompts = @('كم الكشفية', 'كم كشفيع', 'كم سعر', 'شو تكلفة', 'كم الرسوم')
$bookingStarters = @('بدي احجز عند {0}', 'اريد موعد مع {0}', 'احجزلي عند {0}', 'بدي موعد مع {0}')
$sideQuestions = @('كم الكشفية تاعت الدكتور', 'وين القسم', 'شو الخدمات الموجودة', 'كيفك')
$resetMessages = @('بطل الحجز', 'الغاء', 'ابدا من جديد', 'reset')
$loginRequiredMessages = @('الغي موعدي', 'بدي اغير موعدي', 'عدل موعدي')
$emergencyMessages = @('عندي ألم صدر شديد ومش قادر اتنفس', 'في نزيف قوي وما بوقف', 'في ضيق نفس حاد', 'الولد فاقد الوعي')
$fallbackMessages = @('بلوب بلوب', '؟؟؟', 'شي غريب جدا', 'asdfgh', '123 بلا معنى')

$scenarioIndex = 1

for ($i = 0; $i -lt 60; $i++) {
    $message = $greetings[$i % $greetings.Count]
    Run-SingleTurnScenario -Name ("greeting-{0:D4}" -f $scenarioIndex) -Group 'greeting' -Message $message -Predicate { param($r) ($r.intent -eq 'greeting') }
    $scenarioIndex++
}

for ($i = 0; $i -lt 40; $i++) {
    $message = $smalltalk[$i % $smalltalk.Count]
    Run-SingleTurnScenario -Name ("smalltalk-{0:D4}" -f $scenarioIndex) -Group 'smalltalk' -Message $message -Predicate { param($r) ($r.intent -eq 'smalltalk') }
    $scenarioIndex++
}

for ($i = 0; $i -lt 100; $i++) {
    $department = $departments[$i % $departments.Count]
    $verb = $locationVerbs[$i % $locationVerbs.Count]
    $message = "$verb $($department.name)"
    Run-SingleTurnScenario -Name ("department-{0:D4}" -f $scenarioIndex) -Group 'department' -Message $message -Predicate { param($r) @('ask_departments', 'hospital_location', 'hospital_knowledge') -contains $r.intent }
    $scenarioIndex++
}

for ($i = 0; $i -lt 100; $i++) {
    $doctor = $doctors[$i % $doctors.Count]
    $prompt = $doctorPrompts[$i % $doctorPrompts.Count]
    $subject = if ($i % 2 -eq 0) { $doctor.full_name } else { $doctor.specialty }
    $message = "$prompt $subject"
    Run-SingleTurnScenario -Name ("doctor-{0:D4}" -f $scenarioIndex) -Group 'doctor' -Message $message -Predicate { param($r) ($r.intent -eq 'ask_doctors') }
    $scenarioIndex++
}

for ($i = 0; $i -lt 140; $i++) {
    $service = $services[$i % $services.Count]
    $prompt = $pricePrompts[$i % $pricePrompts.Count]
    $message = "$prompt $($service.name)"
    Run-SingleTurnScenario -Name ("service-{0:D4}" -f $scenarioIndex) -Group 'service' -Message $message -Predicate { param($r) ($r.intent -eq 'ask_services') -and ($r.reply -match '\d') }
    $scenarioIndex++
}

for ($i = 0; $i -lt 160; $i++) {
    $doctor = $doctors[$i % $doctors.Count]
    $slot = Find-BookableSlot -DoctorId ([int]$doctor.doctor_id) -StartOffsetDays 1 -MaxOffsetDays 10
    $template = $bookingStarters[$i % $bookingStarters.Count]
    $opening = [string]::Format($template, $doctor.full_name)
    Run-BookingProgressScenario -Name ("booking-progress-{0:D4}" -f $scenarioIndex) -Doctor $doctor -Slot $slot -OpeningMessage $opening
    $scenarioIndex++
}

for ($i = 0; $i -lt 120; $i++) {
    $doctor = $doctors[$i % $doctors.Count]
    $slot = Find-BookableSlot -DoctorId ([int]$doctor.doctor_id) -StartOffsetDays 1 -MaxOffsetDays 10
    $question = $sideQuestions[$i % $sideQuestions.Count]
    Run-BookingInterruptionScenario -Name ("booking-interrupt-{0:D4}" -f $scenarioIndex) -Doctor $doctor -Slot $slot -SideQuestion $question
    $scenarioIndex++
}

for ($i = 0; $i -lt 50; $i++) {
    $doctor = $doctors[$i % $doctors.Count]
    $resetMessage = $resetMessages[$i % $resetMessages.Count]
    Run-BookingResetScenario -Name ("booking-reset-{0:D4}" -f $scenarioIndex) -Doctor $doctor -ResetMessage $resetMessage
    $scenarioIndex++
}

for ($i = 0; $i -lt 30; $i++) {
    $message = $loginRequiredMessages[$i % $loginRequiredMessages.Count]
    Run-LoginRequiredScenario -Name ("login-required-{0:D4}" -f $scenarioIndex) -Message $message
    $scenarioIndex++
}

for ($i = 0; $i -lt 30; $i++) {
    Run-AuthScenario -Name ("auth-{0:D4}" -f $scenarioIndex) -Index $i
    $scenarioIndex++
}

for ($i = 0; $i -lt 40; $i++) {
    $doctor = $doctors[$i % $doctors.Count]
    Run-ExistingAppointmentCancelScenario -Name ("appointment-cancel-{0:D4}" -f $scenarioIndex) -Index $i -Doctor $doctor
    $scenarioIndex++
}

for ($i = 0; $i -lt 40; $i++) {
    $doctor = $doctors[$i % $doctors.Count]
    Run-ExistingAppointmentRescheduleScenario -Name ("appointment-reschedule-{0:D4}" -f $scenarioIndex) -Index $i -Doctor $doctor
    $scenarioIndex++
}

for ($i = 0; $i -lt 20; $i++) {
    $doctor = $doctors[$i % $doctors.Count]
    Run-FullBookingOtpScenario -Name ("booking-otp-{0:D4}" -f $scenarioIndex) -Index $i -Doctor $doctor
    $scenarioIndex++
}

for ($i = 0; $i -lt 40; $i++) {
    $message = $emergencyMessages[$i % $emergencyMessages.Count]
    Run-SingleTurnScenario -Name ("emergency-{0:D4}" -f $scenarioIndex) -Group 'emergency' -Message $message -Predicate { param($r) ($r.intent -eq 'medical_emergency') }
    $scenarioIndex++
}

for ($i = 0; $i -lt 30; $i++) {
    $message = $fallbackMessages[$i % $fallbackMessages.Count]
    Run-SingleTurnScenario -Name ("fallback-{0:D4}" -f $scenarioIndex) -Group 'fallback' -Message $message -Predicate { param($r) ($r.intent -eq 'fallback') }
    $scenarioIndex++
}

if (($scenarioIndex - 1) -ne $ScenarioCount) {
    throw "Scenario generator produced $($scenarioIndex - 1) scenarios instead of $ScenarioCount."
}

$failures = @($script:Results | Where-Object { -not $_.ok })
$summary = $script:Results | Group-Object group | Sort-Object Name | ForEach-Object {
    [pscustomobject]@{
        group = $_.Name
        total = $_.Count
        failed = @($_.Group | Where-Object { -not $_.ok }).Count
    }
}

$report = [pscustomobject]@{
    ran_at = (Get-Date).ToString('s')
    base_url = $BaseUrl
    total = $script:Results.Count
    failed = $failures.Count
    summary = $summary
    failures = $failures
}

$reportPath = Join-Path (Split-Path -Parent $PSCommandPath) 'chat-1000-report.json'
$report | ConvertTo-Json -Depth 8 | Set-Content -LiteralPath $reportPath -Encoding UTF8

$summary | Format-Table -AutoSize
Write-Host "Report: $reportPath"

if ($failures.Count -gt 0) {
    $failures | Select-Object -First 25 | Format-Table -AutoSize
    exit 1
}
