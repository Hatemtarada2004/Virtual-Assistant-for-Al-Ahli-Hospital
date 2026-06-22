param(
    [string]$BaseUrl = 'http://127.0.0.1/hospital-chatbot/public',
    [int]$ScenarioCount = 1000
)

$ErrorActionPreference = 'Stop'
$script:Headers = @{ 'X-Chatbot-Test-Disable-Llm' = '1' }

function Invoke-JsonApi {
    param(
        [string]$Method,
        [string]$Uri,
        [Microsoft.PowerShell.Commands.WebRequestSession]$Session,
        $Body = $null
    )

    $params = @{
        Uri = $Uri
        Method = $Method
        WebSession = $Session
        TimeoutSec = 90
        Headers = $script:Headers
        ContentType = 'application/json; charset=utf-8'
    }

    if ($null -ne $Body) {
        $params.Body = ($Body | ConvertTo-Json -Depth 8 -Compress)
    }

    return Invoke-RestMethod @params
}

function Send-Chat {
    param(
        [Microsoft.PowerShell.Commands.WebRequestSession]$Session,
        [string]$PageId,
        [string]$Message
    )

    return Invoke-JsonApi -Method 'POST' -Uri ($script:ChatApi) -Session $Session -Body @{
        message = $Message
        chat_page_id = $PageId
    }
}

function New-Chat {
    return @{
        Session = New-Object Microsoft.PowerShell.Commands.WebRequestSession
        PageId = 'chat-' + [guid]::NewGuid().ToString('N')
    }
}

function New-Identity {
    param([int]$Index)
    $n = '{0:D4}' -f $Index
    return @{
        full_name = "مريض اختبار $n"
        national_id = "9911$n"
        phone = "0598$('{0:D6}' -f $Index)"
        email = "chatv2+$n@example.com"
        date_of_birth = '1996-01-01'
        gender = if ($Index % 2 -eq 0) { 'Male' } else { 'Female' }
    }
}

function Register-And-Login {
    param([hashtable]$Identity)
    $session = New-Object Microsoft.PowerShell.Commands.WebRequestSession
    try { [void](Invoke-JsonApi -Method 'POST' -Uri ($script:RegisterApi) -Session $session -Body $Identity) } catch {}
    [void](Invoke-JsonApi -Method 'POST' -Uri ($script:LoginApi) -Session $session -Body @{
        national_id = $Identity.national_id
        phone = $Identity.phone
    })
    return $session
}

function Get-AvailableSlots {
    param([int]$DoctorId, [string]$Date)
    $uri = '{0}/api/appointments/available?doctor_id={1}&date={2}' -f $BaseUrl, $DoctorId, $Date
    return @((Invoke-RestMethod -Uri $uri -Method 'GET' -TimeoutSec 90).data.available_slots)
}

function Find-Slot {
    param([int]$DoctorId)
    for ($offset = 1; $offset -le 10; $offset++) {
        $date = (Get-Date).Date.AddDays($offset).ToString('yyyy-MM-dd')
        $slots = @(Get-AvailableSlots -DoctorId $DoctorId -Date $date)
        if ($slots.Count -gt 0) {
            return @{ date = $date; time = $slots[0] }
        }
    }
    throw "No slot found for doctor $DoctorId"
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

function Create-Appointment {
    param(
        [Microsoft.PowerShell.Commands.WebRequestSession]$Session,
        [int]$DoctorId,
        [int]$DepartmentId,
        [string]$Date,
        [string]$Time
    )

    return Invoke-JsonApi -Method 'POST' -Uri ($script:AppointmentsApi) -Session $Session -Body @{
        doctor_id = $DoctorId
        department_id = $DepartmentId
        appointment_datetime = "$Date $(Format-SqlTime -Time $Time)"
        reason = 'اختبار آلي'
    }
}

function Cleanup-TestData {
    $sql = @"
DELETE FROM Appointment WHERE patient_id IN (SELECT patient_id FROM Patient WHERE email LIKE 'chatv2+%@example.com');
DELETE FROM Patient WHERE email LIKE 'chatv2+%@example.com';
"@

    $tempFile = [System.IO.Path]::GetTempFileName()
    try {
        Set-Content -LiteralPath $tempFile -Value $sql -Encoding UTF8
        Get-Content -LiteralPath $tempFile | & 'C:\xampp\mysql\bin\mysql.exe' -uroot ahli_hospital --default-character-set=utf8mb4 | Out-Null
    } finally {
        Remove-Item -LiteralPath $tempFile -Force -ErrorAction SilentlyContinue
    }
}

function Add-Result {
    param([string]$Group, [bool]$Passed, [string]$Name, [string]$Detail)
    $script:Results.Add([pscustomobject]@{
        group = $Group
        passed = $Passed
        name = $Name
        detail = $Detail
    }) | Out-Null
}

$script:ChatApi = $BaseUrl + '/api/chat'
$script:RegisterApi = $BaseUrl + '/api/auth/register'
$script:LoginApi = $BaseUrl + '/api/auth/login'
$script:AppointmentsApi = $BaseUrl + '/api/appointments'
$script:Results = New-Object System.Collections.Generic.List[object]

Cleanup-TestData

$doctors = @((Invoke-RestMethod -Uri ($BaseUrl + '/api/doctors') -Method 'GET' -TimeoutSec 90).data)
$departments = @((Invoke-RestMethod -Uri ($BaseUrl + '/api/departments') -Method 'GET' -TimeoutSec 90).data)

$greetings = @('مرحبا', 'أهلا', 'السلام عليكم', 'هاي')
$smalltalk = @('كيفك', 'شلونك', 'كيف الحال')
$departmentQs = @('وين قسم الأشعة', 'شو دوام قسم الأطفال', 'موقع الطوارئ')
$doctorQs = @('مين دكاترة جراحة القلب', 'مين دكتور الأطفال', 'شو أطباء العيون')
$priceQs = @('كم الكشفية', 'كم كشفيع الدكتور', 'شو تكلفة الكشف')
$emergencyQs = @('عندي ألم صدر شديد ومش قادر أتنفس', 'في نزيف قوي وما بوقف', 'في ضيق نفس حاد')
$fallbackQs = @('asdfgh', 'بلوب بلوب', '123 بلا معنى')
$resetQs = @('بطل الحجز', 'الغاء', 'reset')

$scenario = 1

for ($i = 0; $i -lt 150; $i++) {
    $chat = New-Chat
    $r = Send-Chat -Session $chat.Session -PageId $chat.PageId -Message $greetings[$i % $greetings.Count]
    Add-Result 'greeting' ($r.intent -eq 'greeting') ("greeting-$scenario") $r.intent
    $scenario++
}

for ($i = 0; $i -lt 100; $i++) {
    $chat = New-Chat
    $r = Send-Chat -Session $chat.Session -PageId $chat.PageId -Message $smalltalk[$i % $smalltalk.Count]
    Add-Result 'smalltalk' ($r.intent -eq 'smalltalk') ("smalltalk-$scenario") $r.intent
    $scenario++
}

for ($i = 0; $i -lt 150; $i++) {
    $chat = New-Chat
    $r = Send-Chat -Session $chat.Session -PageId $chat.PageId -Message $departmentQs[$i % $departmentQs.Count]
    Add-Result 'department' (@('ask_departments', 'hospital_knowledge') -contains $r.intent) ("department-$scenario") $r.intent
    $scenario++
}

for ($i = 0; $i -lt 150; $i++) {
    $chat = New-Chat
    $r = Send-Chat -Session $chat.Session -PageId $chat.PageId -Message $doctorQs[$i % $doctorQs.Count]
    Add-Result 'doctor' ($r.intent -eq 'ask_doctors') ("doctor-$scenario") $r.intent
    $scenario++
}

for ($i = 0; $i -lt 100; $i++) {
    $chat = New-Chat
    $r = Send-Chat -Session $chat.Session -PageId $chat.PageId -Message $priceQs[$i % $priceQs.Count]
    Add-Result 'service' ($r.intent -eq 'ask_services') ("service-$scenario") $r.intent
    $scenario++
}

for ($i = 0; $i -lt 100; $i++) {
    $chat = New-Chat
    $doctor = $doctors[$i % $doctors.Count]
    $slot = Find-Slot -DoctorId ([int]$doctor.doctor_id)
    $r1 = Send-Chat -Session $chat.Session -PageId $chat.PageId -Message ("بدي احجز عند {0} {1}" -f $doctor.full_name, $slot.date)
    $r2 = Send-Chat -Session $chat.Session -PageId $chat.PageId -Message $slot.time
    $r3 = Send-Chat -Session $chat.Session -PageId $chat.PageId -Message 'مراجعة'
    Add-Result 'booking_progress' ($r1.intent -like 'booking_*' -and $r2.intent -eq 'booking_need_reason' -and $r3.intent -eq 'booking_need_national_id') ("booking-progress-$scenario") "$($r1.intent),$($r2.intent),$($r3.intent)"
    $scenario++
}

for ($i = 0; $i -lt 100; $i++) {
    $chat = New-Chat
    $doctor = $doctors[$i % $doctors.Count]
    $slot = Find-Slot -DoctorId ([int]$doctor.doctor_id)
    [void](Send-Chat -Session $chat.Session -PageId $chat.PageId -Message ("بدي احجز عند {0} {1}" -f $doctor.full_name, $slot.date))
    [void](Send-Chat -Session $chat.Session -PageId $chat.PageId -Message $slot.time)
    [void](Send-Chat -Session $chat.Session -PageId $chat.PageId -Message 'مراجعة')
    $r = Send-Chat -Session $chat.Session -PageId $chat.PageId -Message $priceQs[$i % $priceQs.Count]
    Add-Result 'booking_interrupt' ($r.intent -eq 'ask_services' -and -not $r.data.conversation_state.booking.active) ("booking-interrupt-$scenario") "$($r.intent)|active=$($r.data.conversation_state.booking.active)"
    $scenario++
}

for ($i = 0; $i -lt 50; $i++) {
    $chat = New-Chat
    $doctor = $doctors[$i % $doctors.Count]
    $slot = Find-Slot -DoctorId ([int]$doctor.doctor_id)
    [void](Send-Chat -Session $chat.Session -PageId $chat.PageId -Message ("بدي احجز عند {0} {1}" -f $doctor.full_name, $slot.date))
    $r = Send-Chat -Session $chat.Session -PageId $chat.PageId -Message $resetQs[$i % $resetQs.Count]
    Add-Result 'booking_reset' ($r.intent -eq 'reset') ("booking-reset-$scenario") $r.intent
    $scenario++
}

for ($i = 0; $i -lt 50; $i++) {
    $chat = New-Chat
    $r = Send-Chat -Session $chat.Session -PageId $chat.PageId -Message $emergencyQs[$i % $emergencyQs.Count]
    Add-Result 'emergency' ($r.intent -eq 'medical_emergency') ("emergency-$scenario") $r.intent
    $scenario++
}

for ($i = 0; $i -lt 50; $i++) {
    $chat = New-Chat
    $r = Send-Chat -Session $chat.Session -PageId $chat.PageId -Message $fallbackQs[$i % $fallbackQs.Count]
    Add-Result 'fallback' (@('fallback', 'hospital_knowledge') -contains $r.intent) ("fallback-$scenario") $r.intent
    $scenario++
}

for ($i = 0; $i -lt 50; $i++) {
    $chat = New-Chat
    $r = Send-Chat -Session $chat.Session -PageId $chat.PageId -Message 'الغي موعدي'
    Add-Result 'login_required' ($r.intent -eq 'appointment_login_required') ("login-$scenario") $r.intent
    $scenario++
}

for ($i = 0; $i -lt 50; $i++) {
    $identity = New-Identity -Index $i
    $session = Register-And-Login -Identity $identity
    $doctor = $doctors[$i % $doctors.Count]
    $slot = Find-Slot -DoctorId ([int]$doctor.doctor_id)
    $appointment = Create-Appointment -Session $session -DoctorId ([int]$doctor.doctor_id) -DepartmentId ([int]$doctor.department_id) -Date $slot.date -Time $slot.time
    $chat = @{ Session = $session; PageId = 'auth-' + [guid]::NewGuid().ToString('N') }
    $cancel = Send-Chat -Session $chat.Session -PageId $chat.PageId -Message 'بدي الغي موعدي'
    $rebookSlot = Find-Slot -DoctorId ([int]$doctor.doctor_id)
    [void](Create-Appointment -Session $session -DoctorId ([int]$doctor.doctor_id) -DepartmentId ([int]$doctor.department_id) -Date $rebookSlot.date -Time $rebookSlot.time)
    $reschedule = Send-Chat -Session $chat.Session -PageId $chat.PageId -Message ("بدي اغير موعدي ليوم {0} الساعة 09:30" -f ((Get-Date).Date.AddDays(10).ToString('yyyy-MM-dd')))
    $ok = ($cancel.intent -eq 'appointment_cancelled') -and ($reschedule.intent -eq 'appointment_rescheduled')
    Add-Result 'appointment_actions' $ok ("appt-$scenario") "$($cancel.intent),$($reschedule.intent)"
    $scenario++
}

$summary = $script:Results | Group-Object group | ForEach-Object {
    $total = $_.Count
    $passed = @($_.Group | Where-Object passed).Count
    [pscustomobject]@{
        group = $_.Name
        total = $total
        passed = $passed
        failed = $total - $passed
    }
}

$failed = @($script:Results | Where-Object { -not $_.passed })
$report = [pscustomobject]@{
    base_url = $BaseUrl
    total = $script:Results.Count
    summary = $summary
    failed = $failed
}

$reportPath = Join-Path $PSScriptRoot 'chat-1000-report-v2.json'
$report | ConvertTo-Json -Depth 8 | Set-Content -Path $reportPath -Encoding UTF8
Cleanup-TestData
$report | ConvertTo-Json -Depth 8
