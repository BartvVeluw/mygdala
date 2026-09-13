<?php

declare(strict_types=1);

use App\Install\InstallState;
use Phinx\Migration\AbstractMigration;

/**
 * Writes "VLD" as `order_number_prefix` for every installation that has been
 * handing out order numbers with it, so App\Repository\OrderRepository can
 * take its prefix from App\Service\SiteSettings — whose generic default is
 * "ORD" — without renumbering a single existing order.
 *
 * Why a pin and not a conversion: an order number is not stored. It is
 * derived from the order's id and creation year every time it is shown, and
 * until now the prefix was a literal "VLD-" in that formatter. The customer
 * e-mails, Mollie payment descriptions, invoices and bookkeeping exports that
 * already carry "VLD-2026-000127" can only keep matching the admin if this
 * installation keeps deriving the same string. Same shape and reasoning as
 * 20260910110000_pin_business_details_before_generic_defaults.php.
 *
 * WHO GETS THE PIN. Two kinds of installation have issued "VLD-" numbers:
 *
 *   - every existing installation, which InstallState reports as legacy;
 *   - a FRESH installation that already has orders: built from zero after the
 *     fresh-install cleanup but before this migration, it received the
 *     hardcoded prefix on its orders just the same.
 *
 * A database being built from zero right now has no history and no orders at
 * the moment this runs, so it stores nothing and the generic "ORD" applies.
 * Tests\Install\FreshInstallTest guards that case;
 * Tests\Install\OrderNumberPrefixPinTest covers the other two.
 *
 * Data only, no schema. Forward-only and non-destructive: INSERT IGNORE
 * against the unique index on setting_key, so a prefix an owner already chose
 * is kept. Idempotent.
 */
final class PinTheOrderNumberPrefixBeforeTheGenericDefault extends AbstractMigration
{
    /** What App\Repository\OrderRepository::formatOrderNumber() wrote out by hand. */
    private const PREVIOUS_CODE_PREFIX = 'VLD';

    public function up(): void
    {
        if (!$this->hasTable('site_settings')) {
            return;
        }

        if (InstallState::isFreshInstall($this) && !$this->hasIssuedOrderNumbers()) {
            return;
        }

        $now = date('Y-m-d H:i:s');

        $this->execute(
            'INSERT IGNORE INTO site_settings (setting_key, setting_value, created_at, updated_at)
             VALUES (?, ?, ?, ?)',
            ['order_number_prefix', self::PREVIOUS_CODE_PREFIX, $now, $now]
        );
    }

    /**
     * Nothing to undo: the row this wrote is an ordinary settings row, and
     * deleting it would renumber every order again.
     */
    public function down(): void
    {
    }

    /** Whether any order exists, and so has already been shown a number. */
    private function hasIssuedOrderNumbers(): bool
    {
        if (!$this->hasTable('orders')) {
            return false;
        }

        return $this->fetchRow('SELECT id FROM orders LIMIT 1') !== false;
    }
}
