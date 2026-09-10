<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Adds a "display type" to option groups (e.g. "Kleur" -> color, "KM" ->
 * standard) and an optional hex colour per option value. Purely additive —
 * existing options/values default to 'standard'/NULL, so nothing already
 * built (variant selection, cart, checkout, order snapshots) changes
 * behaviour. See MAIN.MD.
 */
final class AddDisplayTypeAndColorToOptions extends AbstractMigration
{
    public function change(): void
    {
        $this->table('product_options')
            ->addColumn('display_type', 'string', ['limit' => 20, 'default' => 'standard'])
            ->update();

        $this->table('product_option_values')
            ->addColumn('hex_color', 'string', ['limit' => 7, 'null' => true])
            ->update();
    }
}
