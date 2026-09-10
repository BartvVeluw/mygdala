<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddTranslatableFieldsToProducts extends AbstractMigration
{
    public function change(): void
    {
        $table = $this->table('products');
        $table
            ->addColumn('name_en', 'string', ['limit' => 150, 'null' => true, 'after' => 'name'])
            ->addColumn('description_en', 'text', ['null' => true, 'after' => 'description'])
            ->addColumn('eyebrow', 'string', ['limit' => 100, 'null' => true, 'after' => 'description_en'])
            ->addColumn('eyebrow_en', 'string', ['limit' => 100, 'null' => true, 'after' => 'eyebrow'])
            ->update();
    }
}
