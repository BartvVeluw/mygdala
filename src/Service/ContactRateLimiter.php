<?php

namespace App\Service;

use PDO;

/**
 * Basic, no-paid-service throttle for an anonymous public POST endpoint:
 * rejects a submission once the same visitor (identified by a salted hash of
 * their IP — never the raw IP is stored) has made too many attempts within
 * the time window. Not a bot-proof CAPTCHA replacement, just cheap protection
 * against a script hammering the endpoint.
 *
 * Built for POST /api/contact.php (where it is paired with that endpoint's
 * honeypot field check) and reused unchanged, with its own salt and its own
 * limits, by POST /api/personalization-upload.php — an anonymous endpoint
 * that writes files to disk, so it needs a ceiling of its own. The
 * constructor parameters default to the contact form's original values, so
 * every existing caller keeps behaving exactly as before.
 *
 * The `$salt` keeps the two counters completely separate inside the one
 * `contact_rate_limit_hits` table: the same visitor's contact attempts and
 * upload attempts hash to different values and can never consume each
 * other's budget.
 */
class ContactRateLimiter
{
    public const CONTACT_SALT = 'vvl-contact-rate-limit';
    public const PERSONALIZATION_UPLOAD_SALT = 'vvl-personalization-upload-rate-limit';

    private const PRUNE_AFTER_SECONDS = 86400; // keep the table small

    public function __construct(
        private readonly PDO $pdo,
        private readonly string $salt = self::CONTACT_SALT,
        private readonly int $maxAttempts = 5,
        private readonly int $windowSeconds = 600
    ) {
    }

    /**
     * Records this attempt and returns true if it's within the allowed rate,
     * false if the caller should be rejected (429).
     */
    public function allow(string $ip): bool
    {
        $ipHash = hash('sha256', $ip . '|' . $this->salt);
        $now = new \DateTimeImmutable();

        $this->pdo
            ->prepare('DELETE FROM contact_rate_limit_hits WHERE created_at < :cutoff')
            ->execute(['cutoff' => $now->modify('-' . self::PRUNE_AFTER_SECONDS . ' seconds')->format('Y-m-d H:i:s')]);

        $windowStart = $now->modify('-' . $this->windowSeconds . ' seconds')->format('Y-m-d H:i:s');
        $countStmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM contact_rate_limit_hits WHERE ip_hash = :ip_hash AND created_at >= :window_start'
        );
        $countStmt->execute(['ip_hash' => $ipHash, 'window_start' => $windowStart]);
        $recentAttempts = (int) $countStmt->fetchColumn();

        $insert = $this->pdo->prepare(
            'INSERT INTO contact_rate_limit_hits (ip_hash, created_at) VALUES (:ip_hash, :created_at)'
        );
        $insert->execute(['ip_hash' => $ipHash, 'created_at' => $now->format('Y-m-d H:i:s')]);

        return $recentAttempts < $this->maxAttempts;
    }
}
