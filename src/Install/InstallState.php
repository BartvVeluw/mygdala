<?php

namespace App\Install;

use PDO;
use Phinx\Migration\AbstractMigration;

/**
 * Tells a migration WHICH KIND of database it is running against: one that
 * has been carrying this site's content for a while, or one that is being
 * built from zero right now.
 *
 * Why this exists
 * ---------------
 * Almost every early migration in this project does two things at once: it
 * creates a table, and it fills that table with the copy that was hardcoded
 * in the Van Veluw Laserdesign templates at the time. That was correct then
 * — the site had to keep rendering the same HTML the minute the migration
 * ran. It is wrong now: a brand-new, generic installation of this CMS also
 * ran those migrations, and therefore also received Diensten, Portfolio,
 * Over mij, Contact and three Dutch legal pages it never asked for.
 *
 * The fix cannot be "a later migration deletes those pages": on the real
 * site those rows ARE the site, and a delete that is even slightly wrong
 * destroys live content. So instead every historical seed keeps running
 * exactly as before on an existing installation, and simply does not run on
 * an installation that has no history to preserve. This class is the one
 * place that knows the difference.
 *
 * How the two cases are told apart
 * --------------------------------
 * By construction, not by guesswork:
 *
 *   - A database built from zero runs the FIRST migration
 *     (20260903120000_create_products_table.php). That migration writes the
 *     marker below. Nothing else ever writes it.
 *   - A database that already existed has run that migration long ago, so it
 *     never writes the marker and never can. Its `install_state` table is
 *     therefore absent — and an absent marker means "existing installation".
 *
 * That is why {@see isFreshInstall()} answers `false` when the table is
 * missing. "Unknown" must mean "preserve everything", never "skip the
 * seed": a wrong `false` costs a fresh install a few rows it can delete, a
 * wrong `true` costs a real site its content.
 *
 * A half-migrated legacy database (one that stopped somewhere in the middle
 * years ago and is being caught up now) also lands on `false`, which is the
 * safe answer for it too: its remaining backfills run and its data arrives
 * intact.
 *
 * Frozen API
 * ----------
 * Migrations are historical artefacts and must keep running unchanged. The
 * four public members below are therefore FROZEN: their names, signatures
 * and stored values may not change, and no migration may be made to depend
 * on anything else here.
 * `Tests\Install\InstallStateTest` holds that line.
 */
final class InstallState
{
    /** The table this class owns. Present only on a from-zero installation. */
    public const TABLE = 'install_state';

    /** The one row that matters. */
    public const KEY_INSTALL_KIND = 'install_kind';

    /** This database was built from zero by the current code. */
    public const KIND_FRESH = 'fresh_generic_install';

    /**
     * This database predates the install marker: it carries site content
     * that historical backfills are responsible for, and every one of them
     * must keep running.
     */
    public const KIND_LEGACY = 'legacy_existing_site';

    /**
     * Records that this database is being created from zero. Called by the
     * first migration and by nothing else.
     *
     * Refuses to mark a database that already holds application tables, so
     * that re-running the first migration against a populated database can
     * never relabel a real site as disposable.
     */
    public static function recordFreshInstall(AbstractMigration $migration): void
    {
        if ($migration->hasTable(self::TABLE)) {
            return;
        }

        $migration->table(self::TABLE, ['id' => false, 'primary_key' => ['state_key']])
            // 'null' => false is required on a primary-key column: Phinx
            // otherwise emits a nullable column and MySQL rejects the key.
            ->addColumn('state_key', 'string', ['limit' => 60, 'null' => false])
            ->addColumn('state_value', 'string', ['limit' => 60, 'null' => false])
            ->addColumn('created_at', 'datetime', ['null' => true])
            ->create();

        $kind = self::looksUntouched($migration) ? self::KIND_FRESH : self::KIND_LEGACY;

        $migration->execute(
            'INSERT INTO ' . self::TABLE . ' (state_key, state_value, created_at) VALUES (?, ?, ?)',
            [self::KEY_INSTALL_KIND, $kind, date('Y-m-d H:i:s')]
        );
    }

    /**
     * Is this database being built from zero, with no site content that a
     * historical backfill has to preserve?
     *
     * A missing marker means "existing installation" — see the class
     * docblock. This is the question every historical seed asks before
     * inserting Van Veluw Laserdesign content.
     */
    public static function isFreshInstall(AbstractMigration $migration): bool
    {
        if (!$migration->hasTable(self::TABLE)) {
            return false;
        }

        return self::kind($migration->getAdapter()->getConnection()) === self::KIND_FRESH;
    }

    /**
     * The same answer for code that is not a migration — the admin, and the
     * setup wizard that will build on this. Returns KIND_LEGACY when the
     * marker is absent or unreadable, for the same reason as above.
     */
    public static function kind(PDO $pdo): string
    {
        try {
            $statement = $pdo->prepare(
                'SELECT state_value FROM ' . self::TABLE . ' WHERE state_key = ?'
            );
            $statement->execute([self::KEY_INSTALL_KIND]);
            $value = $statement->fetchColumn();
        } catch (\Throwable $e) {
            return self::KIND_LEGACY;
        }

        return $value === self::KIND_FRESH ? self::KIND_FRESH : self::KIND_LEGACY;
    }

    /**
     * Does this database still look like an empty one? The first migration
     * creates `products`, so at the moment it asks, the only tables that may
     * exist are Phinx's own log and the marker table itself.
     */
    private static function looksUntouched(AbstractMigration $migration): bool
    {
        foreach (['products', 'pages', 'site_settings', 'page_sections', 'information_pages'] as $table) {
            if ($migration->hasTable($table)) {
                return false;
            }
        }

        return true;
    }
}
