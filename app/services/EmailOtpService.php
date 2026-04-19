<?php

declare(strict_types=1);

class EmailOtpService
{
    private array $config;

    public function __construct()
    {
        $this->config = require __DIR__ . '/../config/env.php';
    }

    public function generateCode(): string
    {
        return (string) random_int(100000, 999999);
    }

    public function sendBookingCode(string $email, string $code, array $context = []): array
    {
        $from = (string) ($this->config['mail_from'] ?? 'no-reply@ahli-hospital.local');
        $doctor = (string) ($context['doctor_name'] ?? 'الطبيب');
        $datetime = (string) ($context['appointment_datetime'] ?? '');
        $subject = 'Appointment verification code';
        $body = implode("\n", array_filter([
            'رمز تأكيد حجز موعدك في مستشفى الأهلي هو: ' . $code,
            'الرمز صالح لمدة 10 دقائق فقط.',
            $doctor !== '' ? 'الطبيب: ' . $doctor : null,
            $datetime !== '' ? 'الموعد: ' . $datetime : null,
            'إذا لم تطلب هذا الحجز، تجاهل هذه الرسالة.',
        ]));

        $headers = implode("\r\n", [
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'From: ' . $from,
        ]);

        $sent = false;
        if (function_exists('mail')) {
            try {
                $sent = @mail($email, $subject, $body, $headers);
            } catch (Throwable) {
                $sent = false;
            }
        }

        $logged = $this->writeDevelopmentOutbox($email, $subject, $body, $sent);

        return [
            'sent' => $sent,
            'logged' => $logged,
            'outbox_path' => $this->outboxPath(),
            'debug_code' => $this->isDebug() ? $code : null,
        ];
    }

    private function writeDevelopmentOutbox(string $email, string $subject, string $body, bool $sent): bool
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
