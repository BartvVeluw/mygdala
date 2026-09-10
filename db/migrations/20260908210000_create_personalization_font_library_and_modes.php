<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Phase 3 of product personalization. Three changes, all ADDITIVE — no
 * existing column is dropped, no existing row is rewritten in a way that
 * changes what it already meant, and every historical order keeps rendering
 * from its own snapshot.
 *
 * ## 1. A global font library (`personalization_fonts`)
 *
 * Phase 2 kept the list of engraving fonts in a PHP constant
 * (App\Service\Personalization\PersonalizationFonts) and made every ZONE pick
 * which of them it offered. That was the wrong shape twice over: the owner
 * cannot add a font without a code change, and choosing fonts per zone is
 * work nobody wants to repeat on every product.
 *
 * The registry moves into this table, managed from the CMS
 * (admin/personalization-fonts.php). `font_key` keeps exactly the meaning it
 * had in the constant — a stable identifier recorded in order rows — so the
 * five keys that already exist are BACKFILLED here under the same keys, with
 * the same labels and the same CSS stacks. Nothing that already refers to
 * `quattrocento_sans` changes meaning.
 *
 * `source` separates the two kinds of font a registry entry can be:
 *   'builtin' — a family the browser already has, or one the site's own
 *               stylesheet loads. `css_stack` is the whole definition and
 *               there is no file to serve.
 *   'upload'  — a font file the owner uploaded (see
 *               App\Service\Personalization\PersonalizationFontUploader). The
 *               file lives under assets/fonts/personalization/ with a
 *               server-generated name; `file_path` is the only thing that
 *               ever points at it, and `original_filename` is display
 *               metadata that is never used to build a path.
 *
 * Deletion is deliberately NOT cascading anywhere: an order row records a
 * font by key plus its own copy of the label/stack/file (see 3 below), so a
 * font can be deactivated — or even deleted — without a historical order
 * losing what it was engraved in.
 *
 * ## 2. Required vs optional personalization (`personalization_mode`)
 *
 * A product could be configured as personalizable and still be added to the
 * cart with every zone left empty, which defeats the point for a product that
 * only exists as a personalized product. The mode lives on the product's
 * personalization settings row, defaults to 'optional' — exactly the
 * behaviour every already-configured product has today — and is enforced
 * server-side by App\Service\Personalization\PersonalizationValidator.
 *
 * ## 3. Font facts on the order row
 *
 * `font_key` alone stops being enough the moment a font can be deleted from
 * the library. The three new columns are the order's own copy of what that
 * key rendered as at the moment of purchase; they are NULL for every existing
 * row, which keeps meaning "look the key up in the registry", and that path
 * still works because every Phase 2 key was backfilled above.
 *
 * ## 4. Dedicated personalization preview images
 *
 * Phase 1/2 stored a view's preview image through the ordinary product-photo
 * uploader, so it landed in assets/images/products/ next to the shop gallery.
 * Personalization previews are a different kind of image with a different
 * purpose and must never be confused with — or fall back to — a product
 * photo, so they get their own folder. Existing files are COPIED, not moved:
 * a historical order's snapshot records the path it used, and moving the file
 * would blank the reconstruction in the CMS order screen.
 *
 * MySQL 5.7 / PHP 8.2 compatible: no JSON column type, no generated columns,
 * every foreign-key-ish column `signed => false`, and no DEFAULT on a TEXT.
 */
final class CreatePersonalizationFontLibraryAndModes extends AbstractMigration
{
    /**
     * The Phase 2 code registry, verbatim. Backfilled so that every zone's
     * stored `allowed_fonts`/`default_font` and every order's `font_key` keep
     * resolving to exactly the font they already meant.
     */
    private const BUILTIN_FONTS = [
        ['quattrocento_sans', 'Quattrocento Sans', "'Quattrocento Sans', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif"],
        ['trirong', 'Trirong', "'Trirong', Georgia, 'Times New Roman', serif"],
        ['georgia', 'Georgia', "Georgia, 'Times New Roman', Times, serif"],
        ['arial', 'Arial', "Arial, Helvetica, sans-serif"],
        ['courier', 'Courier', "'Courier New', Courier, monospace"],
    ];

    public function up(): void
    {
        // ---------------------------------------------------------------
        // 1. The global font library
        // ---------------------------------------------------------------
        $this->table('personalization_fonts')
            // Stable identifier, never a display name: it ends up in an order
            // row and must keep meaning the same thing years later.
            ->addColumn('font_key', 'string', ['limit' => 32, 'null' => false])
            ->addColumn('label', 'string', ['limit' => 100, 'null' => false])
            ->addColumn('source', 'string', ['limit' => 16, 'default' => 'upload'])
            // 'builtin' only: the complete CSS font-family declaration.
            ->addColumn('css_stack', 'string', ['limit' => 255, 'null' => true])
            // 'upload' only: web-servable path under assets/fonts/personalization/,
            // always a server-generated filename.
            ->addColumn('file_path', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('file_format', 'string', ['limit' => 10, 'null' => true])
            // Display metadata. Never used to build a path.
            ->addColumn('original_filename', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('byte_size', 'integer', ['signed' => false, 'null' => true])
            ->addColumn('is_active', 'boolean', ['default' => true])
            ->addColumn('sort_order', 'integer', ['default' => 0])
            ->addColumn('created_at', 'datetime', ['null' => true])
            ->addColumn('updated_at', 'datetime', ['null' => true])
            ->addIndex(['font_key'], ['unique' => true])
            ->addIndex(['is_active', 'sort_order'])
            ->create();

        $insert = $this->getAdapter()->getConnection()->prepare(
            'INSERT INTO personalization_fonts
                (font_key, label, source, css_stack, file_path, file_format,
                 original_filename, byte_size, is_active, sort_order, created_at, updated_at)
             VALUES (:font_key, :label, \'builtin\', :css_stack, NULL, NULL, NULL, NULL, 1, :sort_order, :created_at, :updated_at)'
        );

        $now = date('Y-m-d H:i:s');

        foreach (self::BUILTIN_FONTS as $index => [$key, $label, $stack]) {
            $insert->execute([
                'font_key' => $key,
                'label' => $label,
                'css_stack' => $stack,
                'sort_order' => $index,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        // ---------------------------------------------------------------
        // 2. Required vs optional personalization
        // ---------------------------------------------------------------
        $this->table('product_personalization_settings')
            ->addColumn('personalization_mode', 'string', ['limit' => 16, 'default' => 'optional'])
            ->update();

        // ---------------------------------------------------------------
        // 3. The order row's own copy of the font it was engraved in
        // ---------------------------------------------------------------
        $this->table('order_item_personalizations')
            ->addColumn('font_label', 'string', ['limit' => 100, 'null' => true])
            ->addColumn('font_stack', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('font_file_path', 'string', ['limit' => 255, 'null' => true])
            ->update();

        // ---------------------------------------------------------------
        // 4. Personalization previews into their own folder
        // ---------------------------------------------------------------
        $this->movePreviewImagesIntoTheirOwnFolder();
    }

    public function down(): void
    {
        $this->table('order_item_personalizations')
            ->removeColumn('font_label')
            ->removeColumn('font_stack')
            ->removeColumn('font_file_path')
            ->update();

        $this->table('product_personalization_settings')
            ->removeColumn('personalization_mode')
            ->update();

        $this->table('personalization_fonts')->drop()->save();

        // The copied files are deliberately left in place: down() must not
        // delete an image a view could still be pointing at, and the original
        // under assets/images/products/ was never removed anyway.
    }

    /**
     * Copies every view preview image that still lives in the shared product
     * photo folder into assets/images/personalization/ and repoints the row.
     *
     * COPY, never move: a Phase 1/2 order snapshot recorded the old path, and
     * the CMS order screen renders the reconstruction from that exact path.
     * The old file therefore stays exactly where every historical order
     * expects it, while everything from here on reads the dedicated folder.
     *
     * Purely best-effort on the filesystem side — a missing source file is
     * left pointing where it already pointed rather than failing the
     * migration, because a schema change must not be blocked by a stray
     * deleted upload.
     */
    private function movePreviewImagesIntoTheirOwnFolder(): void
    {
        $root = dirname(__DIR__, 2);
        $targetDir = $root . '/assets/images/personalization/';
        $legacyPrefix = 'assets/images/products/';
        $newPrefix = 'assets/images/personalization/';

        if (!is_dir($targetDir) && !@mkdir($targetDir, 0755, true) && !is_dir($targetDir)) {
            return;
        }

        $update = $this->getAdapter()->getConnection()->prepare(
            'UPDATE product_personalization_views SET preview_image_path = :path, updated_at = NOW() WHERE id = :id'
        );

        $rows = $this->fetchAll('SELECT id, preview_image_path FROM product_personalization_views');

        foreach ($rows as $row) {
            $path = trim((string) ($row['preview_image_path'] ?? ''));

            if ($path === '' || !str_starts_with($path, $legacyPrefix)) {
                continue;
            }

            $filename = basename($path);
            $source = $root . '/' . $legacyPrefix . $filename;
            $destination = $targetDir . $filename;

            if (!is_file($source)) {
                continue;
            }

            if (!is_file($destination) && !@copy($source, $destination)) {
                continue;
            }

            @chmod($destination, 0644);

            $update->execute(['path' => $newPrefix . $filename, 'id' => (int) $row['id']]);
        }
    }
}
