<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateCustomersTable extends AbstractMigration
{
    public function change(): void
    {
        $table = $this->table('customers');
        $table
            ->addColumn('name', 'string', ['limit' => 150])
            ->addColumn('email', 'string', ['limit' => 190])
            ->addColumn('phone', 'string', ['limit' => 30, 'null' => true])
            ->addColumn('address_line', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('postal_code', 'string', ['limit' => 15, 'null' => true])
            ->addColumn('city', 'string', ['limit' => 100, 'null' => true])
            ->addColumn('country', 'string', ['limit' => 2, 'default' => 'NL'])
            ->addColumn('created_at', 'datetime', ['null' => true])
            ->addColumn('updated_at', 'datetime', ['null' => true])
            ->addIndex(['email'], ['unique' => true])
            ->create();
    }
}
