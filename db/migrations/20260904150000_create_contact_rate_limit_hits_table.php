<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Basic, no-paid-service spam protection for POST /api/contact.php: one row
 * per submission attempt, keyed by a hash of the visitor's IP (never the raw
 * IP — see App\Service\ContactRateLimiter). The endpoint counts recent rows
 * for that hash to decide whether to accept or reject a new submission, and
 * prunes rows older than its window on every check, so this table never
 * grows unbounded.
 */
final class CreateContactRateLimitHitsTable extends AbstractMigration
{
    public function up(): void
    {
        $this->table('contact_rate_limit_hits')
            ->addColumn('ip_hash', 'string', ['limit' => 64])
            ->addColumn('created_at', 'datetime')
            ->addIndex(['ip_hash', 'created_at'])
            ->create();
    }

    public function down(): void
    {
        $this->table('contact_rate_limit_hits')->drop()->save();
    }
}
