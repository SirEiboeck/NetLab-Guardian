<?php
declare(strict_types=1);

/** SMTP-Versand mit TLS und Benutzername/Passwort, ohne zusätzliche PHP-Bibliothek. */
final class SmtpMailer
{
    /** @param array<string,mixed> $config */
    public function __construct(private readonly array $config)
    {
    }

    /** @param array<string,mixed> $config @return array<string,mixed> */
    public static function validateConfig(array $config, bool $requirePassword = true): array
    {
        foreach (['timezone', 'send_time', 'smtp_host', 'sender'] as $key) {
            if (!is_string($config[$key] ?? null) || trim($config[$key]) === '') {
                throw new InvalidArgumentException("Fehlende Mail-Einstellung: $key");
            }
        }
        new DateTimeZone($config['timezone']);
        IncidentMailSchedule::validateTime($config['send_time']);

        $host = $config['smtp_host'];
        // Nur DNS-Hostnamen erlauben (keine URLs, CRLF, Shell- oder Socket-Sonderzeichen).
        if (strlen($host) > 253 || !preg_match('/^(?=.{1,253}$)[a-zA-Z0-9](?:[a-zA-Z0-9.-]*[a-zA-Z0-9])$/D', $host)
            || str_contains($host, '..')) {
            throw new InvalidArgumentException('smtp_host muss ein gültiger DNS-Hostname sein.');
        }
        $port = $config['smtp_port'] ?? null;
        if ((!is_int($port) && !is_string($port)) ||
            !ctype_digit((string) $port) || (int) $port < 1 || (int) $port > 65535) {
            throw new InvalidArgumentException('smtp_port muss zwischen 1 und 65535 liegen.');
        }
        $config['smtp_port'] = (int) $port;
        $config['smtp_encryption'] ??= 'starttls';
        if (!in_array($config['smtp_encryption'], ['starttls', 'ssl'], true)) {
            throw new InvalidArgumentException('smtp_encryption muss "starttls" (587) oder "ssl" (465) sein. Unverschlüsseltes SMTP ist nicht erlaubt.');
        }
        if (!filter_var($config['sender'], FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/', $config['sender']) || str_ends_with(strtolower($config['sender']), '@example.com')) {
            throw new InvalidArgumentException('sender muss eine gültige E-Mail-Adresse sein.');
        }
        $config['smtp_username'] ??= $config['sender'];
        if (!is_string($config['smtp_username']) || trim($config['smtp_username']) === '' ||
            preg_match('/[\r\n]/', $config['smtp_username'])) {
            throw new InvalidArgumentException('smtp_username ist ungültig.');
        }
        if (!is_array($config['recipients'] ?? null) || $config['recipients'] === []) {
            throw new InvalidArgumentException('recipients muss mindestens eine E-Mail-Adresse enthalten.');
        }
        $recipients = [];
        foreach ($config['recipients'] as $recipient) {
            if (!is_string($recipient) || !filter_var($recipient, FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/', $recipient) || str_ends_with(strtolower($recipient), '@example.com')) {
                throw new InvalidArgumentException('Ungültige Empfängeradresse in recipients.');
            }
            $recipients[] = strtolower($recipient);
        }
        $config['recipients'] = array_values(array_unique($recipients));
        if ($requirePassword && (!is_string($config['smtp_password'] ?? null) || $config['smtp_password'] === '')) {
            throw new InvalidArgumentException('SMTP-Passwort fehlt im geschützten systemd-Credential.');
        }
        return $config;
    }

    public function send(string $subject, string $body): void
    {
        $config = self::validateConfig($this->config);
        if (str_contains($subject, "\r") || str_contains($subject, "\n")) {
            throw new InvalidArgumentException('Ungültiger Betreff.');
        }
        if (!extension_loaded('openssl')) {
            throw new RuntimeException('Die PHP-Erweiterung openssl wird für TLS-SMTP benötigt.');
        }
        $host = $config['smtp_host'];
        $port = $config['smtp_port'];
        $ssl = stream_context_create(['ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
            'peer_name' => $host,
            'SNI_enabled' => true,
            'disable_compression' => true,
        ]]);
        $scheme = $config['smtp_encryption'] === 'ssl' ? 'ssl://' : 'tcp://';
        $socket = @stream_socket_client($scheme . $host . ':' . $port, $errno, $errstr, 25, STREAM_CLIENT_CONNECT, $ssl);
        if (!is_resource($socket)) {
            throw new RuntimeException('SMTP-Verbindung fehlgeschlagen. Server, Port, Firewall und TLS prüfen.');
        }
        stream_set_timeout($socket, 25);
        try {
            self::expect($socket, [220]);
            $ehlo = self::command($socket, 'EHLO netlab-guardian.local', [250]);
            if ($config['smtp_encryption'] === 'starttls') {
                if (!preg_match('/^250[ -]STARTTLS(?:\s|$)/mi', $ehlo)) {
                    throw new RuntimeException('SMTP-Server bietet kein STARTTLS an. Passwort wird nicht unverschlüsselt übertragen.');
                }
                self::command($socket, 'STARTTLS', [220]);
                if (@stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT) !== true) {
                    throw new RuntimeException('TLS-Verbindung zum SMTP-Server konnte nicht hergestellt werden.');
                }
                $ehlo = self::command($socket, 'EHLO netlab-guardian.local', [250]);
            }
            // Kein Passwort vor einer erfolgreichen TLS-Verbindung senden.
            if (!preg_match('/^250[ -]AUTH(?:\s|=)([^\r\n]*)/mi', $ehlo, $match)) {
                throw new RuntimeException('SMTP-Server bietet keine Passwortanmeldung (AUTH) an. Eventuell ist OAuth2 oder eine Freischaltung erforderlich.');
            }
            $methods = strtoupper(' ' . trim($match[1]) . ' ');
            if (str_contains($methods, ' LOGIN ')) {
                self::command($socket, 'AUTH LOGIN', [334]);
                self::command($socket, base64_encode($config['smtp_username']), [334]);
                self::command($socket, base64_encode($config['smtp_password']), [235]);
            } elseif (str_contains($methods, ' PLAIN ')) {
                $token = base64_encode("\0" . $config['smtp_username'] . "\0" . $config['smtp_password']);
                self::command($socket, 'AUTH PLAIN ' . $token, [235]);
                unset($token);
            } else {
                throw new RuntimeException('SMTP-Server unterstützt nur andere Anmeldeverfahren (z. B. OAuth2), nicht LOGIN/PLAIN.');
            }

            self::command($socket, 'MAIL FROM:<' . $config['sender'] . '>', [250]);
            foreach ($config['recipients'] as $recipient) {
                self::command($socket, 'RCPT TO:<' . $recipient . '>', [250, 251]);
            }
            self::command($socket, 'DATA', [354]);

            $headers = [
                'From: <' . $config['sender'] . '>',
                'To: ' . implode(', ', array_map(static fn(string $e): string => '<' . $e . '>', $config['recipients'])),
                'Subject: ' . self::mimeSubject($subject),
                'Date: ' . date('r'),
                'MIME-Version: 1.0',
                'Content-Type: text/plain; charset=UTF-8',
                'Content-Transfer-Encoding: quoted-printable',
                'Auto-Submitted: auto-generated',
            ];
            $encodedBody = quoted_printable_encode(str_replace(["\r\n", "\r"], "\n", $body));
            $message = implode("\r\n", $headers) . "\r\n\r\n" . $encodedBody;
            // SMTP DATA: CRLF und Punkt-Stuffing, damit kein Text eine SMTP-Sequenz auslöst.
            $message = str_replace(["\r\n", "\r"], "\n", $message);
            $message = str_replace("\n", "\r\n", $message);
            $message = preg_replace('/^\./m', '..', $message);
            self::write($socket, rtrim((string) $message, "\r\n") . "\r\n.\r\n");
            self::expect($socket, [250]);
            // QUIT ist optional: Die Nachricht wurde bereits angenommen.
            self::write($socket, "QUIT\r\n");
        } finally {
            fclose($socket);
        }
    }

    private static function mimeSubject(string $subject): string
    {
        // RFC 2047: Teilstücke an UTF-8-Codepoint-Grenzen, maximal 75 Zeichen pro encoded-word.
        $chars = preg_split('//u', $subject, -1, PREG_SPLIT_NO_EMPTY);
        if ($chars === false) {
            throw new InvalidArgumentException('Betreff enthält ungültiges UTF-8.');
        }
        $parts = [];
        $chunk = '';
        foreach ($chars as $char) {
            if (strlen($chunk . $char) > 42 && $chunk !== '') {
                $parts[] = '=?UTF-8?B?' . base64_encode($chunk) . '?=';
                $chunk = '';
            }
            $chunk .= $char;
        }
        if ($chunk !== '') {
            $parts[] = '=?UTF-8?B?' . base64_encode($chunk) . '?=';
        }
        return implode("\r\n ", $parts);
    }

    /** @param resource $socket */
    private static function write($socket, string $data): void
    {
        $remaining = strlen($data);
        $offset = 0;
        while ($remaining > 0) {
            $written = @fwrite($socket, substr($data, $offset));
            if ($written === false || $written === 0) {
                throw new RuntimeException('SMTP-Verbindung beim Schreiben abgebrochen.');
            }
            $offset += $written;
            $remaining -= $written;
        }
    }

    /** @param resource $socket @param list<int> $expected */
    private static function command($socket, string $line, array $expected): string
    {
        self::write($socket, $line . "\r\n");
        return self::expect($socket, $expected);
    }

    /** @param resource $socket @param list<int> $expected */
    private static function expect($socket, array $expected): string
    {
        $reply = '';
        for ($i = 0; $i < 100; $i++) {
            $line = @fgets($socket, 4096);
            if ($line === false || !preg_match('/^(\d{3})([ -])/', $line, $m)) {
                throw new RuntimeException('Ungültige SMTP-Antwort oder Verbindungsabbruch.');
            }
            $reply .= $line;
            if ($m[2] === ' ') {
                $code = (int) $m[1];
                if (!in_array($code, $expected, true)) {
                    // Serverantwort begrenzen; nie Passwort/Authentifizierungsdaten loggen.
                    $detail = substr(trim($line), 0, 240);
                    throw new RuntimeException("SMTP-Server antwortet mit $code ($detail). Serverberechtigung/Anmeldedaten prüfen.");
                }
                return $reply;
            }
        }
        throw new RuntimeException('SMTP-Server sendet zu viele Antwortzeilen.');
    }
}
