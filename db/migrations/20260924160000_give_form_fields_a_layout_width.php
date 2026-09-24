<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Forms 2.0 phase 1: a field chooses how wide it sits in its form.
 *
 *   layout_width   one of 'full', 'three_quarters', 'two_thirds', 'half',
 *                  'third', 'quarter' (App\Service\Forms\FormFieldWidth).
 *                  A new field is 'full'.
 *
 * NOTHING THAT EXISTS MOVES. Until now partials/form.php chose the width
 * from the type: a text, e-mail or telephone field sat half a row wide, every
 * other field took the whole row. Every existing field gets exactly that
 * width, written down, so each stored form renders as it did — the new
 * twelve-column grid draws a half as wide as the old two-column grid did.
 * The list of types is written out here rather than read from
 * FormFieldWidth::formerDefaultFor(), so this migration keeps meaning what it
 * meant when that method is gone.
 *
 * Three steps, each safe to run again (db/migrations/CLAUDE.md): add the
 * column empty, fill the rows that are still empty, then make it NOT NULL
 * with its default. A run that stopped after the first step finishes the
 * rest the next time. Schema and backfill alike, no fresh-install guard: a
 * fresh install's contact form was created by an earlier migration and is
 * treated the same as any other.
 */
final class GiveFormFieldsALayoutWidth extends AbstractMigration
{
    public function up(): void
    {
        $table = $this->table('form_fields');

        if (!$table->hasColumn('layout_width')) {
            $table
                ->addColumn('layout_width', 'string', [
                    'limit' => 20,
                    'null' => true,
                    'default' => null,
                    'after' => 'is_required',
                ])
                ->update();
        }

        $this->execute(
            "UPDATE form_fields SET layout_width = 'half'
              WHERE layout_width IS NULL AND field_type IN ('text', 'email', 'tel')"
        );
        $this->execute("UPDATE form_fields SET layout_width = 'full' WHERE layout_width IS NULL");

        $this->table('form_fields')
            ->changeColumn('layout_width', 'string', [
                'limit' => 20,
                'null' => false,
                'default' => 'full',
                'comment' => 'App\Service\Forms\FormFieldWidth: the share of a row the field takes',
            ])
            ->update();
    }

    public function down(): void
    {
        if ($this->table('form_fields')->hasColumn('layout_width')) {
            $this->table('form_fields')->removeColumn('layout_width')->update();
        }
    }
}
