<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * First-party website statistics: one row per counted public pageview, plus
 * the daily rotating salt that makes "how many visitors" answerable without
 * ever storing who they were.
 *
 * Why a table of our own instead of Google Analytics or a paid service: the
 * CMS dashboard only needs a handful of numbers (pageviews, roughly how many
 * people, which pages, where they came from), the shop already has a
 * database, and shipping a third-party tracker would drag in a cookie-consent
 * obligation and an outbound data transfer for numbers we can count
 * ourselves. See App\Service\Analytics\PageViewTracker for the request-time
 * half and App\Service\Analytics\AnalyticsDashboard for the reporting half.
 *
 * WHAT IS DELIBERATELY NOT STORED
 *  - No IP address, ever — not even hashed on its own, and not for a moment
 *    longer than the request that produced it.
 *  - No user agent string: it is reduced to one of three device types
 *    ('desktop', 'tablet', 'mobile') and then dropped.
 *  - No full referrer URL, only its HOST. A referrer path can carry a search
 *    query or a private URL someone was reading; the host alone answers
 *    "where do visitors come from" without any of that.
 *  - No query strings, with one narrow exception: `path` keeps `?id=<digits>`
 *    on /product.php, because that is what makes a product page a distinct
 *    page rather than 400 hits on "/product.php". Every other parameter
 *    (order tokens on /bestelling-status.php, utm_*, anything a visitor
 *    typed) is discarded before the row is written — see
 *    PageViewTracker::normalizePath().
 *
 * `visitor_hash` is sha256(daily salt + IP + user agent), where the salt is a
 * random 32-byte value generated once per calendar day and stored in
 * `analytics_visitor_salts`. Two consequences, both intentional:
 *  - The same person browsing on two different days produces two unrelated
 *    hashes, so nobody can be followed across days. "Visitors this month" is
 *    therefore an approximation — closer to "daily unique visitors added up"
 *    than to a deduplicated headcount. That is the trade-off this project
 *    wants: a slightly soft number instead of a durable per-person id.
 *  - Once a day's salt is deleted (scripts/prune-analytics.php, default after
 *    60 days) that day's hashes can no longer be checked against ANY IP, even
 *    by someone holding both the database and a list of addresses. The salt
 *    row is what keeps the hash reversible-by-guessing at all, so deleting it
 *    is the actual privacy control, not a tidy-up.
 *
 * Both indexes lead with created_at because every dashboard query is a date
 * range first and a grouping second; putting visitor_hash / path second lets
 * MySQL answer the distinct-visitor and top-pages queries from the index
 * without touching the rows. Kept to two composite indexes rather than three
 * single-column ones: this table is written on every pageview and read a
 * handful of times a day, so index maintenance is the cost that matters.
 *
 * Everything here is plain MySQL 5.7-compatible DDL (no functional indexes,
 * no generated columns, no JSON), because Vimexx shared hosting is where
 * this ends up.
 */
final class CreatePageViewsTable extends AbstractMigration
{
    public function up(): void
    {
        $this->table('page_views', ['id' => true])
            // 190, not 255: it is part of a composite index, and a public URL
            // longer than this is a URL nobody is analysing anyway (the
            // tracker truncates rather than dropping the pageview).
            ->addColumn('path', 'string', ['limit' => 190, 'null' => false])
            ->addColumn('visitor_hash', 'char', ['limit' => 64, 'null' => false])
            // NULL means "no external referrer": a direct visit, a browser
            // that sent none, or navigation from our own site.
            ->addColumn('referrer_host', 'string', ['limit' => 190, 'null' => true])
            ->addColumn('device_type', 'string', ['limit' => 10, 'null' => false])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addIndex(['created_at', 'visitor_hash'])
            ->addIndex(['created_at', 'path'])
            ->create();

        $this->table('analytics_visitor_salts', ['id' => false, 'primary_key' => ['salt_date']])
            // Explicit 'null' => false on a primary-key column: Phinx leaves
            // columns nullable by default and MySQL rejects a nullable part
            // of a PRIMARY KEY (this project hit exactly that on
            // admin_user_permissions).
            ->addColumn('salt_date', 'date', ['null' => false])
            ->addColumn('salt', 'char', ['limit' => 64, 'null' => false])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->create();
    }

    public function down(): void
    {
        $this->table('analytics_visitor_salts')->drop()->save();
        $this->table('page_views')->drop()->save();
    }
}
