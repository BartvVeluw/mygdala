<?php

declare(strict_types=1);

namespace App\Install;

use App\Database;
use PDO;

/**
 * Whether this installation has been through the Setup Wizard yet.
 *
 * This is the SECOND question about an installation, and it deliberately
 * builds on the first rather than inventing a state system of its own:
 *
 *   InstallState  WHICH KIND of database is this — one built from zero by
 *                 the current code, or one carrying this site's history?
 *   SetupState    has the owner of a from-zero database finished telling the
 *                 CMS who they are?
 *
 * Both live in the same `install_state` table, which is key/value and exists
 * only on a from-zero installation (it is created by the very first
 * migration — see InstallState). That is exactly the shape this needs, so
 * there is no second table, no second marker column and no migration:
 *
 *   state_key = 'install_kind'        'fresh_generic_install'
 *   state_key = 'setup_completed_at'  absent until the wizard finishes
 *
 * WHY A LEGACY INSTALLATION IS ALWAYS "CONFIGURED". A database that predates
 * the marker has no `install_state` table at all, so it cannot carry a
 * completion timestamp either. Rather than reading that as "setup not
 * finished" — which would drop the owner of a live site into a wizard that
 * offers to overwrite their identity — an absent install marker means
 * ALREADY CONFIGURED. It is the same safe direction InstallState chose:
 * unknown must never mean "start over".
 *
 * The same rule covers every failure: an unreachable database, a missing
 * table, a broken row. {@see isSetupRequired()} answers `false` for all of
 * them, so a database problem can never put a working CMS behind a wizard.
 */
final class SetupState
{
    /** The row that records the wizard finishing. */
    public const KEY_SETUP_COMPLETED_AT = 'setup_completed_at';

    /**
     * `install_state.state_value` is a 60-character column, which a
     * 'Y-m-d H:i:s' timestamp fits inside with room to spare.
     */
    private const TIMESTAMP_FORMAT = 'Y-m-d H:i:s';

    /**
     * The answer for the SHARED connection, remembered for this request only.
     *
     * Every admin page asks at least twice (requirePermission() calls
     * requireLogin(), which is where the redirect lives), and on an existing
     * installation each ask is a query against a table that is not there.
     * A caller that hands in its own PDO is never memoised: that is the
     * tests' path, and two scratch databases must not share an answer.
     */
    private static ?bool $setupRequired = null;

    /**
     * Does this installation still need to be set up? True only for a
     * from-zero database whose wizard has not been completed — never for an
     * existing site, and never when the answer cannot be read.
     */
    public static function isSetupRequired(?PDO $pdo = null): bool
    {
        if ($pdo === null && self::$setupRequired !== null) {
            return self::$setupRequired;
        }

        $required = self::resolveSetupRequired(self::connection($pdo));

        if ($pdo === null) {
            self::$setupRequired = $required;
        }

        return $required;
    }

    private static function resolveSetupRequired(?PDO $pdo): bool
    {
        if ($pdo === null) {
            return false;
        }

        if (InstallState::kind($pdo) !== InstallState::KIND_FRESH) {
            return false;
        }

        return self::completedAt($pdo) === null;
    }

    /**
     * Forgets the memoised answer. Called by markComplete(), so the rest of
     * the request that finished setup sees that it is finished.
     */
    public static function clearCache(): void
    {
        self::$setupRequired = null;
    }

    /** The inverse of {@see isSetupRequired()}, for screens that read better that way. */
    public static function isComplete(?PDO $pdo = null): bool
    {
        return !self::isSetupRequired($pdo);
    }

    /**
     * When the wizard was completed, or null when it has not been — which is
     * also the answer for every installation that has no marker table.
     */
    public static function completedAt(?PDO $pdo = null): ?string
    {
        $pdo = self::connection($pdo);

        if ($pdo === null) {
            return null;
        }

        try {
            $statement = $pdo->prepare(
                'SELECT state_value FROM ' . InstallState::TABLE . ' WHERE state_key = ?'
            );
            $statement->execute([self::KEY_SETUP_COMPLETED_AT]);
            $value = $statement->fetchColumn();
        } catch (\Throwable $e) {
            return null;
        }

        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        return $value;
    }

    /**
     * Records that setup finished. Called by the wizard's completion
     * endpoint as the LAST step, after every configuration change has been
     * committed — never before, so a half-configured site is never marked
     * as done (SETUP.md).
     *
     * Writes nothing on an installation that has no marker table: a legacy
     * site is already configured by definition, and creating the table there
     * would relabel it as something this class then has to reason about.
     *
     * @throws \RuntimeException when there is no database at all
     */
    public static function markComplete(?PDO $pdo = null): void
    {
        $pdo = self::connection($pdo);

        if ($pdo === null) {
            throw new \RuntimeException('Setup cannot be marked complete: no database connection.');
        }

        if (InstallState::kind($pdo) !== InstallState::KIND_FRESH) {
            return;
        }

        $statement = $pdo->prepare(
            'INSERT INTO ' . InstallState::TABLE . ' (state_key, state_value, created_at)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE state_value = VALUES(state_value)'
        );

        $now = date(self::TIMESTAMP_FORMAT);

        $statement->execute([self::KEY_SETUP_COMPLETED_AT, $now, $now]);

        self::clearCache();
    }

    /**
     * The shared connection, or null when there is none. Every caller here
     * treats null as "this is not a fresh install", which is the safe answer
     * (see the class docblock).
     */
    private static function connection(?PDO $pdo): ?PDO
    {
        if ($pdo !== null) {
            return $pdo;
        }

        try {
            return Database::connection();
        } catch (\Throwable $e) {
            error_log('[SetupState] no database connection: ' . $e->getMessage());

            return null;
        }
    }
}
