<?php

/**
 * CLI helper: sends ONE real test email over the real Vimexx SMTP mailbox,
 * bypassing the docker-compose.yml override that forces MAIL_HOST=mailpit
 * for the php container. Does NOT touch App\Service\Mailer or any
 * order-sending code path — Mailpit stays the default for the app itself.
 *
 * Reads dedicated TEST_SMTP_* variables from .env (never the MAIL_* ones,
 * so this can never accidentally be triggered by normal app code, and the
 * app can never accidentally pick up these test credentials).
 *
 * Usage: docker compose exec php php scripts/send_test_email.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script can only be run from the command line.');
}

require dirname(__DIR__) . '/vendor/autoload.php';

use Dotenv\Dotenv;
use PHPMailer\PHPMailer\Exception as PHPMailerException;
use PHPMailer\PHPMailer\PHPMailer;

$root = dirname(__DIR__);
if (file_exists($root . '/.env')) {
    Dotenv::createImmutable($root)->load();
}

$host = $_ENV['TEST_SMTP_HOST'] ?? '';
$port = $_ENV['TEST_SMTP_PORT'] ?? '';
$encryption = strtolower((string) ($_ENV['TEST_SMTP_ENCRYPTION'] ?? ''));
$username = $_ENV['TEST_SMTP_USERNAME'] ?? '';
$password = $_ENV['TEST_SMTP_PASSWORD'] ?? '';
$to = $_ENV['TEST_SMTP_TO'] ?? '';

$missing = [];
foreach (['TEST_SMTP_HOST' => $host, 'TEST_SMTP_PORT' => $port, 'TEST_SMTP_USERNAME' => $username, 'TEST_SMTP_PASSWORD' => $password, 'TEST_SMTP_TO' => $to] as $name => $value) {
    if ($value === '') {
        $missing[] = $name;
    }
}
if ($missing !== []) {
    fwrite(STDERR, 'Missing required .env values: ' . implode(', ', $missing) . PHP_EOL);
    fwrite(STDERR, 'See .env.example for what to fill in.' . PHP_EOL);
    exit(1);
}

$redact = static function (string $message) use ($username, $password): string {
    $secrets = array_filter([$username, $password], static fn (string $v): bool => $v !== '');
    return $secrets === [] ? $message : str_replace($secrets, '[redacted]', $message);
};

$mail = new PHPMailer(true);

try {
    $mail->CharSet = PHPMailer::CHARSET_UTF8;
    $mail->isSMTP();
    $mail->Host = $host;
    $mail->Port = (int) $port;
    $mail->Timeout = 15;
    $mail->SMTPAuth = true;
    $mail->Username = $username;
    $mail->Password = $password;

    if ($encryption === 'ssl') {
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
    } elseif ($encryption === 'tls') {
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    }

    $mail->setFrom($username, \App\Mail\EmailIdentity::name());
    $mail->addAddress($to);
    $mail->Subject = '[VVL SMTP TEST] Vimexx SMTP connection test — ' . date('Y-m-d H:i:s');
    $mail->isHTML(false);
    $mail->Body = "This is a one-off test email sent directly via the real Vimexx SMTP mailbox\n"
        . "from the local development environment, to verify the connection works.\n\n"
        . "It was NOT sent through the normal order-confirmation flow (that still uses Mailpit).\n"
        . 'Sent at: ' . date('c') . "\n";

    $mail->send();

    echo "OK: test email sent to {$to} via {$host}:{$port}. Check that inbox now." . PHP_EOL;
} catch (PHPMailerException $e) {
    fwrite(STDERR, 'FAILED: ' . $redact($mail->ErrorInfo ?: $e->getMessage()) . PHP_EOL);
    exit(1);
}
