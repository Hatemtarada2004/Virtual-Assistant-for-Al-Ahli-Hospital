<?php

declare(strict_types=1);

require_once __DIR__ . '/SmtpMailer.php';

class EmailOtpService
{
    private array $config;
    private SmtpMailer $smtp;

    public function __construct()
    {
        $this->config = require __DIR__ . '/../config/load_env.php';
        $this->smtp = new SmtpMailer($this->config);
    }

    public function generateCode(): string
    {
        return (string) random_int(100000, 999999);
    }

    public function isValidEmail(string $email): bool
    {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    public function sendBookingCode(string $email, string $code, array $context = []): array
    {
        $doctor = (string) ($context['doctor_name'] ?? 'الطبيب');
        $datetime = (string) ($context['appointment_datetime'] ?? '');

        return $this->sendPlainText($email, 'Appointment verification code', implode("\n", array_filter([
            'رمز تأكيد حجز موعدك في مستشفى الأهلي هو: ' . $code,
            'الرمز صالح لمدة 10 دقائق فقط.',
            $doctor !== '' ? 'الطبيب: ' . $doctor : null,
            $datetime !== '' ? 'الموعد: ' . $datetime : null,
            'إذا لم تطلب هذا الحجز، تجاهل هذه الرسالة.',
        ])), $code);
    }

    public function sendLabResultCode(string $email, string $code, array $context = []): array
    {
        $patientName = (string) ($context['patient_name'] ?? '');
        $testNames = (array) ($context['test_names'] ?? []);

        return $this->sendPlainText($email, 'Lab result verification code', implode("\n", array_filter([
            'رمز التحقق لطلب نتيجة الفحص هو: ' . $code,
            'الرمز صالح لمدة 10 دقائق فقط.',
            $patientName !== '' ? 'اسم المريض: ' . $patientName : null,
            !empty($testNames) ? 'الفحوصات المطلوبة: ' . implode(', ', $testNames) : null,
            'إذا لم تطلب نتيجة فحص، تجاهل هذه الرسالة.',
        ])), $code);
    }

    public function sendAppointmentConfirmation(string $email, array $appointment, array $booking = []): array
    {
        $doctor = (string) ($appointment['doctor_name'] ?? $booking['selected_doctor']['full_name'] ?? 'الطبيب');
        $datetime = (string) ($appointment['appointment_datetime'] ?? '');
        $appointmentId = (string) ($appointment['appointment_id'] ?? '');

        return $this->sendPlainText($email, 'Appointment confirmed', implode("\n", array_filter([
            'تم تأكيد حجز موعدك في مستشفى الأهلي.',
            $appointmentId !== '' ? 'رقم الحجز: ' . $appointmentId : null,
            $doctor !== '' ? 'الطبيب: ' . $doctor : null,
            $datetime !== '' ? 'الموعد: ' . $datetime : null,
            'إذا بدك تعديل أو إلغاء الموعد، تواصل مع الاستقبال.',
        ])));
    }

    public function sendLabResults(string $email, array $tests, array $patient = []): array
    {
        $patientName = (string) ($patient['full_name'] ?? 'المريض');
        $lines = [
            'نتائج الفحوصات الخاصة بـ ' . $patientName,
            'هذه الرسالة أرسلت بعد التحقق من رقم الهوية ورمز البريد.',
            '',
        ];

        foreach ($tests as $test) {
            $name = (string) ($test['test_name'] ?? 'فحص');
            $date = (string) ($test['test_date'] ?? '');
            $status = (string) ($test['status'] ?? '');
            $result = trim((string) ($test['result_text'] ?? ''));
            $doctor = trim((string) ($test['doctor_name'] ?? ''));

            $lines[] = $name . ($date !== '' ? ' - ' . $date : '');
            $lines[] = $status === 'Ready'
                ? 'النتيجة: ' . ($result !== '' ? $result : 'جاهزة بدون نص تفصيلي.')
                : 'الحالة: قيد الانتظار.';
            if ($doctor !== '') {
                $lines[] = 'الطبيب الطالب: ' . $doctor;
            }
            $lines[] = '';
        }

        $lines[] = 'للاستفسار عن تفاصيل طبية، راجع الطبيب أو الاستقبال.';

        return $this->sendPlainText($email, 'Lab results from Ahli Hospital', implode("\n", $lines));
    }

    private function sendPlainText(string $email, string $subject, string $body, ?string $debugCode = null): array
    {
        if (!$this->isValidEmail($email)) {
            return [
                'sent' => false,
                'error' => 'Invalid email format',
                'logged' => false,
                'outbox_path' => null,
                'transport' => null,
                'debug_code' => null,
            ];
        }

        $from = (string) ($this->config['mail_from'] ?? $this->config['smtp_from'] ?? 'no-reply@ahli-hospital.local');
        $headers = implode("\r\n", [
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'From: ' . $from,
        ]);

        $sent = false;
        $error = null;
        $transport = null;
        $smtpEnabled = (bool) ($this->config['smtp_enabled'] ?? true);

        if ($smtpEnabled && $this->smtp->isConfigured()) {
            $smtpFrom = (string) ($this->config['smtp_from'] ?? $from);
            $result = $this->smtp->send($smtpFrom, $email, $subject, $body);
            $sent = (bool) ($result['sent'] ?? false);
            $error = $result['error'] ?? null;
            $transport = $result['transport'] ?? 'smtp';
        } elseif ($smtpEnabled) {
            $transport = 'smtp';
            $error = 'SMTP is enabled but not configured with real credentials in app/config/env.local.php or app/config/env.php.';
        } elseif (function_exists('mail')) {
            try {
                $sent = @mail($email, $subject, $body, $headers);
                $transport = 'php_mail';
            } catch (Throwable $e) {
                $sent = false;
                $transport = 'php_mail';
                $error = $e->getMessage();
            }
        }

        $logged = $this->writeDevelopmentOutbox($email, $subject, $body, $sent, $transport, $error);

        return [
            'sent' => $sent,
            'logged' => $logged,
            'outbox_path' => $this->outboxPath(),
            'transport' => $transport,
            'error' => $error,
            'debug_code' => ($debugCode !== null && $this->isDebug() && (bool) ($this->config['otp_debug_code_enabled'] ?? false)) ? $debugCode : null,
        ];
    }

    private function writeDevelopmentOutbox(string $email, string $subject, string $body, bool $sent, ?string $transport = null, ?string $error = null): bool
    {
        if (!$this->isDebug() && $sent) {
            return false;
        }

        $path = $this->outboxPath();
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            return false;
        }

        $entry = [
            'at' => date('c'),
            'mail_sent' => $sent,
            'transport' => $transport,
            'error' => $error,
            'to' => $email,
            'subject' => $subject,
            'body' => $body,
        ];

        return file_put_contents($path, json_encode($entry, JSON_UNESCAPED_UNICODE) . PHP_EOL, FILE_APPEND | LOCK_EX) !== false;
    }

    private function outboxPath(): string
    {
        $root = defined('APP_ROOT') ? APP_ROOT : dirname(__DIR__, 2);
        return $root . '/storage/logs/email-otp.log';
    }

    private function isDebug(): bool
    {
        return (bool) ($this->config['app_debug'] ?? false)
            || (string) ($this->config['app_env'] ?? 'development') !== 'production';
    }
}
