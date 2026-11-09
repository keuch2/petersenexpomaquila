<?php
declare(strict_types=1);

const BROCHURE_PATH = __DIR__ . '/../data/brochure.pdf';
const BROCHURE_MAX = 15 * 1024 * 1024;
const MAIL_CONFIG = __DIR__ . '/../data/mail.json';

/** Datos SMTP de data/mail.json (fuera del repo y bloqueado desde la web). */
function mail_config(): ?array
{
    if (!is_file(MAIL_CONFIG)) return null;
    $c = json_decode((string) file_get_contents(MAIL_CONFIG), true);
    return is_array($c) && !empty($c['host']) && !empty($c['from_email']) ? $c : null;
}

function brochure_exists(): bool
{
    return is_file(BROCHURE_PATH);
}

function brochure_can_email(): bool
{
    return brochure_exists() && mail_config() !== null;
}

/** El mensaje no incluye datos del formulario: así nadie puede usarlo para mandar texto propio a terceros. */
function brochure_message(array $c, string $to): string
{
    $boundary = 'piexpo' . bin2hex(random_bytes(12));
    $from = mb_encode_mimeheader($c['from_name'] ?? 'Petersen Industria', 'UTF-8', 'B') . ' <' . $c['from_email'] . '>';
    $domain = substr(strrchr($c['from_email'], '@') ?: '@localhost', 1);
    $text = "Hola:\r\n\r\nGracias por registrarte en el stand de Petersen Industria en Expo Maquila 2026.\r\n"
        . "Te adjuntamos nuestro brochure con las soluciones y marcas que representamos.\r\n\r\n"
        . "Petersen Industria\r\nhttps://www.petersen.com.py/division-industrial\r\n";
    return implode("\r\n", [
        'From: ' . $from,
        'To: <' . $to . '>',
        'Subject: ' . mb_encode_mimeheader('Brochure de Petersen Industria · Expo Maquila 2026', 'UTF-8', 'B'),
        'Date: ' . date(DATE_RFC2822),
        'Message-ID: <' . bin2hex(random_bytes(16)) . '@' . $domain . '>',
        'MIME-Version: 1.0',
        'Content-Type: multipart/mixed; boundary="' . $boundary . '"',
        '',
        '--' . $boundary,
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: base64',
        '',
        rtrim(chunk_split(base64_encode($text))),
        '--' . $boundary,
        'Content-Type: application/pdf; name="Brochure-Petersen-Industria.pdf"',
        'Content-Disposition: attachment; filename="Brochure-Petersen-Industria.pdf"',
        'Content-Transfer-Encoding: base64',
        '',
        rtrim(chunk_split(base64_encode((string) file_get_contents(BROCHURE_PATH)))),
        '--' . $boundary . '--',
    ]);
}

/** Cliente SMTP mínimo. Las líneas del mensaje son encabezados propios o base64: ninguna empieza con punto. */
function smtp_send(array $c, string $to, string $message): void
{
    $enc = strtolower((string) ($c['encryption'] ?? 'tls'));
    $port = (int) ($c['port'] ?? ($enc === 'ssl' ? 465 : 587));
    $ctx = stream_context_create(['ssl' => ['peer_name' => $c['host']]]);
    $s = @stream_socket_client(($enc === 'ssl' ? 'ssl://' : 'tcp://') . $c['host'] . ':' . $port, $errno, $error, 15, STREAM_CLIENT_CONNECT, $ctx);
    if (!$s) throw new RuntimeException('conexión SMTP: ' . $error);
    stream_set_timeout($s, 30);

    $put = static function (string $data) use ($s): void {
        for ($o = 0, $n = strlen($data); $o < $n; $o += $w) {
            $w = fwrite($s, substr($data, $o, 8192));
            if (!$w) throw new RuntimeException('SMTP: se cortó la conexión');
        }
    };
    $read = static function (int ...$ok) use ($s): void {
        do {
            $line = fgets($s, 2048);
            if ($line === false) throw new RuntimeException('SMTP: sin respuesta');
        } while (($line[3] ?? '') === '-');
        if (!in_array((int) substr($line, 0, 3), $ok, true)) throw new RuntimeException('SMTP: ' . trim($line));
    };
    $cmd = static function (string $line, int ...$ok) use ($put, $read): void {
        $put($line . "\r\n");
        $read(...$ok);
    };

    try {
        $read(220);
        $ehlo = 'EHLO ' . (gethostname() ?: 'localhost');
        $cmd($ehlo, 250);
        if ($enc === 'tls') {
            $cmd('STARTTLS', 220);
            if (!stream_socket_enable_crypto($s, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) throw new RuntimeException('SMTP: falló TLS');
            $cmd($ehlo, 250);
        }
        if (!empty($c['username'])) {
            $cmd('AUTH LOGIN', 334);
            $cmd(base64_encode((string) $c['username']), 334);
            $cmd(base64_encode((string) ($c['password'] ?? '')), 235);
        }
        $cmd('MAIL FROM:<' . $c['from_email'] . '>', 250);
        $cmd('RCPT TO:<' . $to . '>', 250, 251);
        $cmd('DATA', 354);
        $put($message . "\r\n.\r\n");
        $read(250);
        $put("QUIT\r\n");
    } finally {
        fclose($s);
    }
}

function send_brochure(string $to): bool
{
    $c = mail_config();
    if (!$c || !brochure_exists()) return false;
    try {
        smtp_send($c, $to, brochure_message($c, $to));
        return true;
    } catch (Throwable $e) {
        error_log('[petersenexpo] brochure: ' . $e->getMessage());
        return false;
    }
}
