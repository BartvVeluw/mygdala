<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Gives the Page Hero (`page_heroes`, 20260904200000) an optional image from
 * the Media Library and three presentation choices: where its text sits, how
 * large its title is and how large its intro text is.
 *
 * ADDITIVE, AND TODAY'S HEADER IS THE DEFAULT. No existing column changes and
 * every existing row keeps every value it has. The three choices are NOT NULL
 * with a default that MySQL writes into the existing rows as part of ADD
 * COLUMN, and those defaults (`left`, `normal`, `normal`) are exactly the
 * header every page rendered before this migration. `media_id` starts NULL,
 * which is "no image".
 *
 * A REFERENCE AND NOTHING ELSE. The features that joined the library in
 * 20260909260000 kept their old path column beside the new reference as a
 * fallback. A page hero never had an image, so there is no path to keep and
 * no local alt text either: there is no caption to preserve, and the item's
 * own alt text is the one to use (MEDIA.md, "Hoe een feature naar media
 * verwijst").
 *
 * ON DELETE RESTRICT, for the reason 20260909260000 gives: an item that is
 * still used must not be deletable. App\Service\Media\Usage\ContentBlockMediaUsage
 * is the first line of defence, this constraint the second.
 *
 * The choices are closed lists stored as short strings, the same shape as
 * text_image_splits.layout and homepage_hero.layout, and App\Service\PageHeroContent
 * checks them on the way in and on the way out. No CSS value is ever stored.
 * The defaults are written out here rather than read from that class, so this
 * migration keeps meaning what it meant when the class changes.
 *
 * Schema only, so there is no fresh-install guard: a new installation and an
 * upgraded one end on the same table (db/migrations/CLAUDE.md).
 */
final class AddMediaAndPresentationToPageHeroes extends AbstractMigration
{
    /** column => the default that reproduces the header as it was */
    private const CHOICES = [
        'content_position' => 'left',
        'title_size' => 'normal',
        'text_size' => 'normal',
    ];

    public function up(): void
    {
        if (!$this->table('page_heroes')->hasColumn('media_id')) {
            $this->table('page_heroes')
                ->addColumn('media_id', 'integer', [
                    'signed' => false,
                    'null' => true,
                    'after' => 'breadcrumb_label_en',
                    'comment' => 'App\Service\Media reference; no path column of its own',
                ])
                ->addForeignKey('media_id', 'media', 'id', [
                    'delete' => 'RESTRICT',
                    'update' => 'CASCADE',
                ])
                ->update();
        }

        $after = 'media_id';

        foreach (self::CHOICES as $column => $default) {
            if (!$this->table('page_heroes')->hasColumn($column)) {
                $this->table('page_heroes')
                    ->addColumn($column, 'string', [
                        'limit' => 20,
                        'null' => false,
                        'default' => $default,
                        'after' => $after,
                    ])
                    ->update();
            }

            $after = $column;
        }
    }

    public function down(): void
    {
        foreach (array_reverse(array_keys(self::CHOICES)) as $column) {
            if ($this->table('page_heroes')->hasColumn($column)) {
                $this->table('page_heroes')->removeColumn($column)->update();
            }
        }

        $table = $this->table('page_heroes');

        if (!$table->hasColumn('media_id')) {
            return;
        }

        if ($table->hasForeignKey('media_id')) {
            $table->dropForeignKey('media_id')->update();
        }

        $this->table('page_heroes')->removeColumn('media_id')->update();
    }
}
