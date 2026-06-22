param(
    [string]$BaseUrl = 'http://127.0.0.1/hospital-chatbot/public',
    [int]$ScenarioCount = 2000,
    [string]$Email = ''
)

$ErrorActionPreference = 'Stop'

$script:ChatApi = "$BaseUrl/api/chat"
$script:LoginApi = "$BaseUrl/api/auth/login"
$script:DoctorsApi = "$BaseUrl/api/doctors"
$script:DepartmentsApi = "$BaseUrl/api/departments"
$script:ServicesApi = "$BaseUrl/api/services"
$script:Headers = @{ 'X-Chatbot-Test-Disable-Llm' = '1' }
$script:Results = New-Object System.Collections.Generic.List[object]

function Invoke-JsonApi {
    param(
        [string]$Method,
        [string]$Uri,
        [Microsoft.PowerShell.Commands.WebRequestSession]$Session,
        $Body = $null
    )

    $args = @{
        Method = $Method
        Uri = $Uri
        WebSession = $Session
        TimeoutSec = 45
        Headers = $script:Headers
        ContentType = 'application/json; charset=utf-8'
    }
    if ($null -ne $Body) {
        $args.Body = ($Body | ConvertTo-Json -Depth 8 -Compress)
    }
    return Invoke-RestMethod @args
}

function New-Chat {
    param([Microsoft.PowerShell.Commands.WebRequestSession]$Session = $null)
    if ($null -eq $Session) {
        $Session = New-Object Microsoft.PowerShell.Commands.WebRequestSession
    }
    return [pscustomobject]@{
        Session = $Session
        PageId = 'test-' + [guid]::NewGuid().ToString('N')
    }
}

function Send-Chat {
    param([pscustomobject]$Chat, [string]$Message)
    return Invoke-JsonApi -Method 'POST' -Uri $script:ChatApi -Session $Chat.Session -Body @{
        message = $Message
        chat_page_id = $Chat.PageId
    }
}

function Add-Result {
    param([string]$Group, [string]$Name, [bool]$Ok, [string]$Expected, $Response, [string]$Message)
    $script:Results.Add([pscustomobject]@{
        group = $Group
        name = $Name
        ok = $Ok
        expected = $Expected
        intent = if ($null -ne $Response -and $null -ne $Response.PSObject.Properties['intent']) { [string]$Response.intent } else { '' }
        reply = if ($null -ne $Response -and $null -ne $Response.PSObject.Properties['reply']) { [string]$Response.reply } else { '' }
        message = $Message
    }) | Out-Null
}

function Run-One {
    param(
        [string]$Group,
        [string]$Name,
        [string]$Message,
        [scriptblock]$Check,
        [string]$Expected,
        [pscustomobject]$Chat = $null
    )
    if ($null -eq $Chat) {
        $Chat = New-Chat
    }
    try {
        $response = Send-Chat -Chat $Chat -Message $Message
        $ok = [bool](& $Check $response)
        Add-Result -Group $Group -Name $Name -Ok $ok -Expected $Expected -Response $response -Message $Message
    } catch {
        Add-Result -Group $Group -Name $Name -Ok $false -Expected $Expected -Response $null -Message ($Message + ' | ERROR: ' + $_.Exception.Message)
    }
}

function Login-SeedPatient {
    $session = New-Object Microsoft.PowerShell.Commands.WebRequestSession
    [void](Invoke-JsonApi -Method 'POST' -Uri $script:LoginApi -Session $session -Body @{
        national_id = '401234567'
        phone = '0597000001'
    })
    return $session
}

$baseSession = New-Object Microsoft.PowerShell.Commands.WebRequestSession
$doctors = @((Invoke-RestMethod -Uri $script:DoctorsApi -Method GET -TimeoutSec 45).data)
$departments = @((Invoke-RestMethod -Uri $script:DepartmentsApi -Method GET -TimeoutSec 45).data)
$services = @((Invoke-RestMethod -Uri $script:ServicesApi -Method GET -TimeoutSec 45).data)
$authSession = Login-SeedPatient

$greetings = @('مرحبا', 'أهلا', 'السلام عليكم', 'هاي', 'صباح الخير')
$smalltalk = @('كيفك', 'شو أخبارك', 'كيف الحال', 'طمني عنك')
$doctorList = @('مين دكاتره قسم القلب', 'مين أطباء العيون', 'دكاترة الأطفال مين', 'مين دكتور الباطنية')
$doctorBioPrefix = @('احكيلي سيرة عن', 'نبذة عن', 'معلومات عن الطبيب', 'مين هو')
$labQuestions = @('بدي نتائج الفحوصات', 'طلعت نتيجة التحليل؟', 'فحوصاتي جاهزة؟', 'بدي نتائج تحاليلي')
$serviceQuestions = @('كم سعر فحص القلب', 'شو تكلفة الأشعة', 'كم الكشفية', 'شو الخدمات المتوفرة')
$departmentQuestions = @('وين قسم القلب', 'شو دوام قسم الأشعة', 'موقع قسم الأطفال', 'وين الطوارئ')
$emergencyQuestions = @('عندي ألم صدر شديد ومش قادر أتنفس', 'في نزيف قوي وما بوقف', 'في ضيق نفس حاد', 'فقدت الوعي')
$fallbackQuestions = @('asdfgh', '؟؟؟', 'كلام غير مفهوم جدا', '123 بلا معنى')
$bookingStarts = @('بدي أحجز عند {0}', 'أريد موعد مع {0}', 'احجزلي عند {0}', 'بدي أكشف عند {0}')
$sideQuestions = @('كم الكشفية؟', 'وين القسم؟', 'احكيلي سيرة عن {0}', 'بدي نتائج الفحوصات')

$index = 1

for ($i = 0; $i -lt 200; $i++) {
    Run-One 'greeting' ("scenario-$index") $greetings[$i % $greetings.Count] { param($r) $r.intent -eq 'greeting' } 'greeting'
    $index++
}

for ($i = 0; $i -lt 100; $i++) {
    Run-One 'smalltalk' ("scenario-$index") $smalltalk[$i % $smalltalk.Count] { param($r) $r.intent -eq 'smalltalk' } 'smalltalk friendly handling'
    $index++
}

for ($i = 0; $i -lt 200; $i++) {
    Run-One 'doctor_list' ("scenario-$index") $doctorList[$i % $doctorList.Count] { param($r) $r.intent -eq 'ask_doctors' -and $r.reply.Length -gt 10 } 'ask_doctors'
    $index++
}

for ($i = 0; $i -lt 200; $i++) {
    $doctor = $doctors[$i % $doctors.Count]
    $message = "$($doctorBioPrefix[$i % $doctorBioPrefix.Count]) $($doctor.full_name)"
    Run-One 'doctor_bio' ("scenario-$index") $message { param($r) $r.intent -eq 'ask_doctors' -and $r.reply -match 'تخصص|قسم|يعمل|ضمن|التواصل|البريد' } 'doctor biography'
    $index++
}

for ($i = 0; $i -lt 200; $i++) {
    Run-One 'lab_login_required' ("scenario-$index") $labQuestions[$i % $labQuestions.Count] { param($r) $r.intent -eq 'lab_results_login_required' } 'lab login required'
    $index++
}

for ($i = 0; $i -lt 200; $i++) {
    $chat = New-Chat -Session $authSession
    Run-One 'lab_authenticated' ("scenario-$index") $labQuestions[$i % $labQuestions.Count] { param($r) @('lab_results', 'lab_results_empty') -contains $r.intent } 'lab results for logged-in patient' $chat
    $index++
}

for ($i = 0; $i -lt 150; $i++) {
    Run-One 'services' ("scenario-$index") $serviceQuestions[$i % $serviceQuestions.Count] { param($r) $r.intent -eq 'ask_services' -or $r.intent -eq 'hospital_knowledge' } 'services/prices'
    $index++
}

for ($i = 0; $i -lt 100; $i++) {
    $dept = $departments[$i % $departments.Count]
    $message = if ($i % 2 -eq 0) { "وين $($dept.name)" } else { $departmentQuestions[$i % $departmentQuestions.Count] }
    Run-One 'departments' ("scenario-$index") $message { param($r) @('ask_departments', 'hospital_knowledge') -contains $r.intent } 'departments'
    $index++
}

for ($i = 0; $i -lt 150; $i++) {
    $doctor = $doctors[$i % $doctors.Count]
    $message = [string]::Format($bookingStarts[$i % $bookingStarts.Count], $doctor.full_name)
    Run-One 'booking_start' ("scenario-$index") $message { param($r) $r.intent -like 'booking_*' -and $r.data.conversation_state.booking.active -eq $true } 'booking active'
    $index++
}

for ($i = 0; $i -lt 150; $i++) {
    $doctor = $doctors[$i % $doctors.Count]
    $chat = New-Chat
    [void](Send-Chat -Chat $chat -Message ([string]::Format($bookingStarts[$i % $bookingStarts.Count], $doctor.full_name)))
    $side = [string]::Format($sideQuestions[$i % $sideQuestions.Count], $doctor.full_name)
    Run-One 'booking_interruption' ("scenario-$index") $side { param($r) @('booking_side_question', 'booking_need_date', 'booking_choose_time') -contains $r.intent -and $r.reply.Length -gt 10 } 'answer side question while booking' $chat
    $index++
}

for ($i = 0; $i -lt 150; $i++) {
    Run-One 'emergency' ("scenario-$index") $emergencyQuestions[$i % $emergencyQuestions.Count] { param($r) $r.intent -eq 'medical_emergency' } 'medical emergency'
    $index++
}

for ($i = 0; $i -lt 200; $i++) {
    Run-One 'fallback' ("scenario-$index") $fallbackQuestions[$i % $fallbackQuestions.Count] { param($r) @('fallback', 'hospital_knowledge') -contains $r.intent } 'fallback'
    $index++
}

if (($index - 1) -ne $ScenarioCount) {
    throw "Generated $($index - 1) scenarios instead of $ScenarioCount."
}

$passed = @($script:Results | Where-Object ok).Count
$failed = $script:Results.Count - $passed
$summary = $script:Results | Group-Object group | Sort-Object Name | ForEach-Object {
    $groupPassed = @($_.Group | Where-Object ok).Count
    [pscustomobject]@{
        group = $_.Name
        total = $_.Count
        passed = $groupPassed
        failed = $_.Count - $groupPassed
        pass_rate = [math]::Round(($groupPassed / $_.Count) * 100, 2)
    }
}

$report = [pscustomobject]@{
    ran_at = (Get-Date).ToString('s')
    base_url = $BaseUrl
    total = $script:Results.Count
    passed = $passed
    failed = $failed
    pass_rate = [math]::Round(($passed / $script:Results.Count) * 100, 2)
    summary = $summary
    failures = @($script:Results | Where-Object { -not $_.ok })
}

$reportPath = Join-Path $PSScriptRoot 'chat-2000-report.json'
$report | ConvertTo-Json -Depth 10 | Set-Content -LiteralPath $reportPath -Encoding UTF8

$emailResult = $null
try {
    $args = @($reportPath)
    if ($Email -ne '') {
        $args += $Email
    }
    $emailRaw = & 'C:\xampp\php\php.exe' (Join-Path $PSScriptRoot 'mail-chat-report.php') @args
    $emailResult = $emailRaw | ConvertFrom-Json
} catch {
    $emailResult = [pscustomobject]@{ sent = $false; error = $_.Exception.Message }
}

[pscustomobject]@{
    report_path = $reportPath
    total = $report.total
    passed = $report.passed
    failed = $report.failed
    pass_rate = $report.pass_rate
    email = $emailResult
    summary = $summary
} | ConvertTo-Json -Depth 8

if ($failed -gt 0) {
    exit 1
}




