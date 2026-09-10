<?php

namespace App\Repository;

/**
 * All `page_views` and `analytics_visitor_salts` SQL — the storage half of
 * the first-party website statistics (see
 * db/migrations/20260908230000_create_page_views_table.php).
 *
 * Both tables live in one repository for the same reason AdminUserRepository
 * owns its permissions pivot: a pageview cannot be recorded without the day's
 * salt, and a salt on its own is meaningless. Nothing else in the application
 * ever needs to read a salt.
 *
 * Deliberately NOT here: what counts as a bot, what a "visitor" is, which
 * paths are worth recording and how one period compares to another. Those are
 * policy and live in App\Service\Analytics\*, so they hold regardless of how
 * the numbers are stored. This class stores and counts what it is given.
 *
 * Every read takes a half-open [from, to) range of `Y-m-d H:i:s` strings, so
 * a pageview can never be counted in two adjacent periods at once. Timestamps
 * are written with PHP's date() and compared against PHP-built bounds, which
 * keeps "today" the same day at both ends no matter which timezone the host
 * runs in (MySQL never converts a DATETIME).
 */
class PageViewRepository extends Repository
{
    /**
     * Records one counted pageview. Callers have already decided that this
     * request should be counted at all — see
     * App\Service\Analytics\PageViewTracker.
     */
    public function record(
        string $path,
        string $visitorHash,
        ?string $referrerHost,
        string $deviceType,
        string $createdAt
    ): void {
        $stmt = $this->db->prepare(
            'INSERT INTO page_views (path, visitor_hash, referrer_host, device_type, created_at)
             VALUES (:path, :visitor_hash, :referrer_host, :device_type, :created_at)'
        );

        $stmt->execute([
            'path' => $path,
            'visitor_hash' => $visitorHash,
            'referrer_host' => $referrerHost,
            'device_type' => $deviceType,
            'created_at' => $createdAt,
        ]);
    }

    /**
     * The random salt for one calendar day, generating it on first use.
     *
     * Two visitors hitting the site in the same second on a day with no salt
     * yet both try to create one; INSERT IGNORE lets the loser fail silently
     * and re-read the winner's row, so a day can never end up with two salts
     * (which would split that day's visitor count in half).
     *
     * @param string $date 'Y-m-d'
     */
    public function visitorSaltForDate(string $date): string
    {
        $existing = $this->findSalt($date);
        if ($existing !== null) {
            return $existing;
        }

        $stmt = $this->db->prepare(
            'INSERT IGNORE INTO analytics_visitor_salts (salt_date, salt, created_at)
             VALUES (:salt_date, :salt, :created_at)'
        );
        $stmt->execute([
            'salt_date' => $date,
            'salt' => bin2hex(random_bytes(32)),
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $salt = $this->findSalt($date);
        if ($salt === null) {
            throw new \RuntimeException('Could not obtain the visitor salt for ' . $date . '.');
        }

        return $salt;
    }

    private function findSalt(string $date): ?string
    {
        $stmt = $this->db->prepare(
            'SELECT salt FROM analytics_visitor_salts WHERE salt_date = :salt_date LIMIT 1'
        );
        $stmt->execute(['salt_date' => $date]);
        $salt = $stmt->fetchColumn();

        return $salt === false ? null : (string) $salt;
    }

    public function countPageViews(string $from, string $toExclusive): int
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM page_views WHERE created_at >= :from AND created_at < :to'
        );
        $stmt->execute(['from' => $from, 'to' => $toExclusive]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Distinct visitor hashes in the range. Because the salt rotates daily
     * (see the migration), a range longer than a day counts a returning
     * visitor once per day rather than once overall — the approximation is
     * the point, not a defect.
     */
    public function countVisitors(string $from, string $toExclusive): int
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(DISTINCT visitor_hash) FROM page_views WHERE created_at >= :from AND created_at < :to'
        );
        $stmt->execute(['from' => $from, 'to' => $toExclusive]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Per-day totals in the range, keyed by 'Y-m-d'. Days without a single
     * pageview are absent (SQL cannot invent rows that were never written);
     * the caller fills the gaps, because only it knows which days the chart
     * is meant to show.
     *
     * @return array<string, array{pageviews: int, visitors: int}>
     */
    public function dailyTotals(string $from, string $toExclusive): array
    {
        $stmt = $this->db->prepare(
            'SELECT DATE(created_at) AS day, COUNT(*) AS pageviews, COUNT(DISTINCT visitor_hash) AS visitors
             FROM page_views
             WHERE created_at >= :from AND created_at < :to
             GROUP BY DATE(created_at)
             ORDER BY day ASC'
        );
        $stmt->execute(['from' => $from, 'to' => $toExclusive]);

        $totals = [];
        foreach ($stmt->fetchAll() as $row) {
            $totals[(string) $row['day']] = [
                'pageviews' => (int) $row['pageviews'],
                'visitors' => (int) $row['visitors'],
            ];
        }

        return $totals;
    }

    /**
     * Most-viewed paths in the range, busiest first.
     *
     * @return list<array{path: string, pageviews: int, visitors: int}>
     */
    public function topPages(string $from, string $toExclusive, int $limit): array
    {
        // Interpolated, not bound: PDO runs with emulated prepares off, and
        // MySQL will not accept a placeholder in LIMIT. Clamped to an int
        // first, so nothing a caller passes can reach the query as text.
        $limit = max(1, min(50, $limit));

        $stmt = $this->db->prepare(
            'SELECT path, COUNT(*) AS pageviews, COUNT(DISTINCT visitor_hash) AS visitors
             FROM page_views
             WHERE created_at >= :from AND created_at < :to
             GROUP BY path
             ORDER BY pageviews DESC, path ASC
             LIMIT ' . $limit
        );
        $stmt->execute(['from' => $from, 'to' => $toExclusive]);

        $pages = [];
        foreach ($stmt->fetchAll() as $row) {
            $pages[] = [
                'path' => (string) $row['path'],
                'pageviews' => (int) $row['pageviews'],
                'visitors' => (int) $row['visitors'],
            ];
        }

        return $pages;
    }

    /**
     * External sites that sent the most visits in the range. Rows with no
     * external referrer (direct visits and internal navigation, stored as
     * NULL) are not a "source" and are left out entirely.
     *
     * @return list<array{host: string, pageviews: int}>
     */
    public function topReferrers(string $from, string $toExclusive, int $limit): array
    {
        $limit = max(1, min(50, $limit));

        $stmt = $this->db->prepare(
            'SELECT referrer_host, COUNT(*) AS pageviews
             FROM page_views
             WHERE created_at >= :from AND created_at < :to AND referrer_host IS NOT NULL
             GROUP BY referrer_host
             ORDER BY pageviews DESC, referrer_host ASC
             LIMIT ' . $limit
        );
        $stmt->execute(['from' => $from, 'to' => $toExclusive]);

        $referrers = [];
        foreach ($stmt->fetchAll() as $row) {
            $referrers[] = [
                'host' => (string) $row['referrer_host'],
                'pageviews' => (int) $row['pageviews'],
            ];
        }

        return $referrers;
    }

    /**
     * Whether anything has ever been recorded — the dashboard shows an
     * explanatory empty state rather than a wall of zeroes on a site that
     * simply has not been visited since the feature went live.
     */
    public function hasAnyData(): bool
    {
        return $this->db->query('SELECT 1 FROM page_views LIMIT 1')->fetchColumn() !== false;
    }

    /**
     * Deletes pageviews recorded before the given timestamp; returns how many
     * rows went. Used by scripts/prune-analytics.php.
     */
    public function deletePageViewsBefore(string $before): int
    {
        $stmt = $this->db->prepare('DELETE FROM page_views WHERE created_at < :before');
        $stmt->execute(['before' => $before]);

        return $stmt->rowCount();
    }

    /**
     * Deletes the salts of days before the given date. This is what makes an
     * old visitor hash permanently unlinkable to any IP address, so it is a
     * privacy control rather than housekeeping — see the migration.
     *
     * @param string $before 'Y-m-d'
     */
    public function deleteSaltsBefore(string $before): int
    {
        $stmt = $this->db->prepare('DELETE FROM analytics_visitor_salts WHERE salt_date < :before');
        $stmt->execute(['before' => $before]);

        return $stmt->rowCount();
    }
}
