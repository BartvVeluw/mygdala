<?php

namespace App\Service;

use App\Mail\EmailIdentity;
use Dotenv\Dotenv;
use PHPMailer\PHPMailer\Exception as PHPMailerException;
use PHPMailer\PHPMailer\PHPMailer;

/**
 * Thin wrapper around PHPMailer, configured from .env (MAIL_*). Mirrors
 * App\Database / MollieClientFactory's pattern: env-driven, no app-specific
 * logic — email *content* lives in App\Mail instead.
 *
 * Local dev points MAIL_HOST at Mailpit (see docker-compose.yml), which
 * catches mail instead of delivering it — nothing is ever sent to a real
 * inbox while developing. Production fills in the real Vimexx SMTP mailbox.
 */
class Mailer
{
    private static bool $envLoaded = false;

    /**
     * @param array<int, array{path:string,name:string,mime?:string}> $attachments
     * @throws PHPMailerException on send failure
     */
    public function send(
        string $toEmail,
        string $toName,
        string $subject,
        string $html,
        string $text,
        ?string $replyToEmail = null,
        ?string $replyToName = null,
        array $attachments = []
    ): void {
        self::loadEnv();

        $mail = new PHPMailer(true);
        $mail->CharSet = PHPMailer::CHARSET_UTF8;
        $mail->isSMTP();
        $mail->Host = $_ENV['MAIL_HOST'] ?? '127.0.0.1';
        $mail->Port = (int) ($_ENV['MAIL_PORT'] ?? 1025);
        $mail->Timeout = 10;

        $username = $_ENV['MAIL_USERNAME'] ?? '';
        if ($username !== '') {
            $mail->SMTPAuth = true;
            $mail->Username = $username;
            $mail->Password = $_ENV['MAIL_PASSWORD'] ?? '';
        }

        $encryption = strtolower((string) ($_ENV['MAIL_ENCRYPTION'] ?? 'none'));
        if ($encryption === 'tls') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        } elseif ($encryption === 'ssl') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        }

        // App\Mail\EmailIdentity keeps the existing precedence — .env wins,
        // because the mail provider may only be allowed to send as a specific
        // address — and falls back to the site's own settings instead of one
        // company's details written into the code.
        //
        // An installation that has named neither refuses to send. There is no
        // address left to fall back on that would not be somebody else's, and
        // sending as a stranger is worse than not sending: the exception says
        // which two places the value comes from, and every caller already
        // treats a send failure as a failure rather than swallowing it.
        if (!EmailIdentity::isConfigured()) {
            throw new PHPMailerException(EmailIdentity::unconfiguredMessage());
        }

        $mail->setFrom(EmailIdentity::fromAddress(), EmailIdentity::name());
        $mail->addAddress($toEmail, $toName);
        if ($replyToEmail !== null && $replyToEmail !== '') {
            $mail->addReplyTo($replyToEmail, $replyToName ?? '');
        }
        $mail->Subject = $subject;
        $mail->isHTML(true);
        $mail->Body = $html;
        $mail->AltBody = $text;

        foreach ($attachments as $attachment) {
            $mail->addAttachment(
                $attachment['path'],
                $attachment['name'],
                PHPMailer::ENCODING_BASE64,
                $attachment['mime'] ?? ''
            );
        }

        $mail->send();
    }

    /**
     * Strips configured SMTP credentials out of an error string before it's
     * logged, so a PHPMailer failure message (which can echo back the SMTP
     * server's raw response) never leaks MAIL_USERNAME/MAIL_PASSWORD into logs.
     */
    public static function redactCredentials(string $message): string
    {
        self::loadEnv();

        $secrets = array_filter([
            $_ENV['MAIL_USERNAME'] ?? '',
            $_ENV['MAIL_PASSWORD'] ?? '',
        ], static fn (string $v): bool => $v !== '');

        if ($secrets === []) {
            return $message;
        }

        return str_replace($secrets, '[redacted]', $message);
    }

    private static function loadEnv(): void
    {
        if (self::$envLoaded) {
            return;
        }
        self::$envLoaded = true;

        $root = dirname(__DIR__, 2);
        if (file_exists($root . '/.env')) {
            Dotenv::createImmutable($root)->load();
        }
    }
}
