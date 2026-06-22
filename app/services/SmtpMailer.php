<?php

declare(strict_types=1);

/**
 * Minimal SMTP client for sending plain-text UTF-8 emails.
 * Supports:
 * - STARTTLS on port 587 (smtp_secure = 'tls')
 * - Implicit TLS on port 465 (smtp_secure = 'ssl')
 * - AUTH LOGIN with app passwords (e.g., Gmail)
 */
class SmtpMailer
{
    private string $host;
    private int $port;
    private string $secure; // '', 'tls', 'ssl'
    private string $username;
    private string $password;
    private int $timeout;
    private bool $verifyPeer;
    private bool $verifyPeerName;
    private bool $allowSelfSigned;

    public function __construct(array $config)
    {
        $this->host = (string) ($config['smtp_host'] ?? '');
        $this->port = (int) ($config['smtp_port'] ?? 587);
        $this->secure = (string) ($config['smtp_secure'] ?? 'tls');
        $this->username = (string) ($config['smtp_user'] ?? '');
        $this->password = (string) ($config['smtp_password'] ?? '');
        $this->timeout = (int) ($config['smtp_timeout'] ?? 15);
        $this->verifyPeer = (bool) ($config['smtp_verify_peer'] ?? true);
        $this->verifyPeerName = (bool) ($config['smtp_verify_peer_name'] ?? true);
        $this->allowSelfSigned = (bool) ($config['smtp_allow_self_signed'] ?? false);
    }

    public function isConfigured(): bool
    {
        if ($this->host === '' || $this->username === '' || $this->password === '') {
            return false;
        }

        $username = mb_strtolower(trim($this->username), 'UTF-8');
        $password = trim($this->password);

        return $username !== 'your-email@gmail.com'
            && $password !== 'PUT_YOUR_APP_PASSWORD_HERE';
    }

    /**
     * @return array{sent: bool, error: ?string, transport: string}
     */
    public function send(string $from, string $to, string $subject, string $body, array $headers = []): array
    {
        $to = trim($to);
        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return ['sent' => false, 'error' => 'Invalid recipient email.', 'transport' => 'smtp'];
        }

        $from = trim($from);
        if ($from === '' || !filter_var($from, FILTER_VALIDATE_EMAIL)) {
            $from = $this->username;
        }

        $password = preg_replace('/\s+/', '', $this->password) ?? '';
        if ($password === '') {
            return ['sent' => false, 'error' => 'SMTP password missing.', 'transport' => 'smtp'];
        }

        $scheme = 'tcp://';
        $useStartTls = false;
        if ($this->secure === 'ssl') {
            $scheme = 'ssl://';
        } elseif ($this->secure === 'tls') {
            $scheme = 'tcp://';
            $useStartTls = true;
        }

        $remote = $scheme . $this->host . ':' . $this->port;
        $errno = 0;
        $errstr = '';
        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => $this->verifyPeer,
                'verify_peer_name' => $this->verifyPeerName,
                'allow_self_signed' => $this->allowSelfSigned,
                'peer_name' => $this->host,
                'SNI_enabled' => true,
                'crypto_method' => $this->cryptoMethod(),
            ],
        ]);

        $socket = @stream_socket_client($remote, $errno, $errstr, $this->timeout, STREAM_CLIENT_CONNECT, $context);
        if (!is_resource($socket)) {
            return ['sent' => false, 'error' => 'SMTP connect failed: ' . ($errstr !== '' ? $errstr : (string) $errno), 'transport' => 'smtp'];
        }

        stream_set_timeout($socket, $this->timeout);

        try {
            $this->expect($socket, [220]);

            $this->command($socket, 'EHLO localhost');
            $this->expect($socket, [250]);

            if ($useStartTls) {
                $this->command($socket, 'STARTTLS');
                $this->expect($socket, [220]);

                $ok = @stream_socket_enable_crypto($socket, true, $this->cryptoMethod());
                if ($ok !== true) {
                    throw new RuntimeException('STARTTLS negotiation failed.');
                }

                $this->command($socket, 'EHLO localhost');
                $this->expect($socket, [250]);
            }

            // AUTH LOGIN
            $this->command($socket, 'AUTH LOGIN');
            $this->expect($socket, [334]);
            $this->command($socket, base64_encode($this->username));
            $this->expect($socket, [334]);
            $this->command($socket, base64_encode($password));
            $this->expect($socket, [235]);

            $this->command($socket, 'MAIL FROM:<' . $from . '>');
            $this->expect($socket, [250]);
            $this->command($socket, 'RCPT TO:<' . $to . '>');
            $this->expect($socket, [250, 251]);

            $this->command($socket, 'DATA');
            $this->expect($socket, [354]);

            $raw = $this->buildMessage($from, $to, $subject, $body, $headers);
            $this->write($socket, $raw . "\r\n.\r\n");
            $this->expect($socket, [250]);

            $this->command($socket, 'QUIT');
            @fclose($socket);

            return ['sent' => true, 'error' => null, 'transport' => 'smtp'];
        } catch (Throwable $e) {
            try {
                $this->command($socket, 'QUIT');
            } catch (Throwable) {
                // ignore
            }
            @fclose($socket);
            return ['sent' => false, 'error' => $e->getMessage(), 'transport' => 'smtp'];
        }
    }

    private function buildMessage(string $from, string $to, string $subject, string $body, array $headers): string
    {
        $subjectEncoded = $this->encodeHeader($subject);
        $defaultHeaders = [
            'Date' => date('r'),
            'From' => $from,
            'To' => $to,
            'Subject' => $subjectEncoded,
            'MIME-Version' => '1.0',
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Content-Transfer-Encoding' => '8bit',
        ];

        foreach ($headers as $k => $v) {
            $k = trim((string) $k);
            if ($k === '') {
                continue;
            }
            $defaultHeaders[$k] = (string) $v;
        }

        $lines = [];
        foreach ($defaultHeaders as $k => $v) {
            $lines[] = $k . ': ' . $v;
        }

        $normalizedBody = str_replace(["\r\n", "\r"], "\n", (string) $body);
        $normalizedBody = preg_replace("/\n\./", "\n..", $normalizedBody) ?? $normalizedBody;
        $normalizedBody = str_replace("\n", "\r\n", $normalizedBody);

        return implode("\r\n", $lines) . "\r\n\r\n" . $normalizedBody . "\r\n";
    }

    private function encodeHeader(string $value): string
    {
        $value = (string) $value;
        if ($value === '') {
            return '';
        }
        // RFC 2047 base64 encoding for UTF-8
        $b64 = base64_encode($value);
        return '=?UTF-8?B?' . $b64 . '?=';
    }

    private function cryptoMethod(): int
    {
        $methods = [];

        foreach ([
            'STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT',
            'STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT',
            'STREAM_CRYPTO_METHOD_TLSv1_1_CLIENT',
            'STREAM_CRYPTO_METHOD_TLSv1_0_CLIENT',
            'STREAM_CRYPTO_METHOD_TLS_CLIENT',
        ] as $constant) {
            if (defined($constant)) {
                $methods[] = constant($constant);
            }
        }

        if ($methods === []) {
            throw new RuntimeException('No TLS client crypto methods are available in this PHP build.');
        }

        return array_reduce($methods, static fn(int $carry, int $method): int => $carry | $method, 0);
    }

    private function command($socket, string $line): void
    {
        $this->write($socket, $line . "\r\n");
    }

    private function write($socket, string $data): void
    {
        $len = strlen($data);
        $written = 0;
        while ($written < $len) {
            $n = @fwrite($socket, substr($data, $written));
            if ($n === false || $n === 0) {
                throw new RuntimeException('SMTP write failed.');
            }
            $written += $n;
        }
    }

    /**
     * @param int[] $expectedCodes
     */
    private function expect($socket, array $expectedCodes): void
    {
        $line = $this->readResponse($socket);
        $code = (int) substr($line, 0, 3);
        if (!in_array($code, $expectedCodes, true)) {
            throw new RuntimeException('SMTP error: ' . trim($line));
        }
    }

    private function readResponse($socket): string
    {
        $full = '';
        while (true) {
            $line = @fgets($socket, 8192);
            if ($line === false) {
                throw new RuntimeException('SMTP read failed.');
            }
            $full .= $line;
            // Multi-line replies have a hyphen after the code: "250-..."
            if (strlen($line) >= 4 && $line[3] === ' ') {
                break;
            }
        }

        // Return the last line for code checks, but keep context for debugging
        $parts = preg_split("/\r\n|\n|\r/", trim($full)) ?: [];
        return (string) (end($parts) ?: $full);
    }
}
