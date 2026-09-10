<?php

declare(strict_types=1);

use App\Install\InstallState;
use Phinx\Migration\AbstractMigration;

/**
 * The first migration of the project — and therefore the one moment at which
 * a database can still be recognised as brand new.
 *
 * Reaching this migration means there is nothing here yet: no products, no
 * pages, no settings. `InstallState` records that fact, and every later
 * migration that would otherwise seed Van Veluw Laserdesign's own content
 * asks it whether it is looking at a real site's history or at an empty
 * generic install. See src/Install/InstallState.php and INSTALL-BOOTSTRAP.md.
 *
 * A database that already exists has run this migration long ago, so nothing
 * below touches it and its marker stays absent — which is exactly how it
 * keeps every historical backfill.
 */
final class CreateProductsTable extends AbstractMigration
{
    public function up(): void
    {
        InstallState::recordFreshInstall($this);

        $table = $this->table('products');
        $table
            ->addColumn('name', 'string', ['limit' => 150])
            ->addColumn('slug', 'string', ['limit' => 170])
            ->addColumn('description', 'text', ['null' => true])
            ->addColumn('price', 'decimal', ['precision' => 10, 'scale' => 2])
            ->addColumn('image_path', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('stock', 'integer', ['default' => 0])
            ->addColumn('active', 'boolean', ['default' => true])
            ->addColumn('created_at', 'datetime', ['null' => true])
            ->addColumn('updated_at', 'datetime', ['null' => true])
            ->addIndex(['slug'], ['unique' => true])
            ->create();
    }

    public function down(): void
    {
        $this->table('products')->drop()->save();

        if ($this->hasTable(InstallState::TABLE)) {
            $this->table(InstallState::TABLE)->drop()->save();
        }
    }
}
