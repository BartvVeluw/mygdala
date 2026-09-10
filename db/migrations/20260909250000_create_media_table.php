<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * The Media Library's one table: a stable database identity for a reusable
 * public site image, so a file can be uploaded once, found again, reused in
 * several places, and carry one default alt text. See MEDIA.md.
 *
 * WHAT BELONGS HERE. Public, reusable site media that an editor picks in the
 * CMS: a logo, a favicon, a social-sharing image, a photo in a content
 * block. Nothing else. Customer personalisation uploads, contact-request
 * attachments, generated order previews and invoice PDFs are private files
 * belonging to one record, they live outside the webroot, and putting them
 * in a library whose whole purpose is REUSE would be a privacy bug rather
 * than a feature.
 *
 * PATH IS THE IDENTITY, and it is unique. Two rows for one file would defeat
 * the point: "where is this image used" could then answer for half the
 * usages. The unique index is also what makes the adoption of the site's
 * existing images (see the migration that follows) safe to run twice.
 *
 * COLLATION. `path` is utf8mb4_bin rather than this project's usual
 * utf8mb4_unicode_ci, for the same reason `redirects.source_path` is: a
 * case-INSENSITIVE unique index would treat Foto.jpg and foto.jpg as one
 * row, while the Linux filesystem underneath treats them as two different
 * files. A binary collation makes storage agree with the disk.
 *
 * NO FOLDERS, NO TAGS, NO EXIF, NO FOCAL POINT, NO TRANSFORMATIONS. V1 is
 * deliberately small: identity, reuse, metadata, safe deletion. A column
 * added now "because the table could hold it" is a column every later
 * feature has to keep true.
 *
 * `mime_type` is what makes future media kinds possible without exposing
 * them: nothing here says "image". The UPLOAD path is what restricts the
 * formats (App\Service\Media\MediaUploader), and it can be widened later
 * without a schema change.
 */
final class CreateMediaTable extends AbstractMigration
{
    public function up(): void
    {
        if ($this->hasTable('media')) {
            return;
        }

        $this->table('media', ['id' => true])
            // Root-relative and WITHOUT a leading slash, the form every
            // existing image column in this database already uses
            // ('assets/media/ab12....webp'). App\Service\Media\MediaItem
            // owns the one conversion to a public URL; no template ever
            // concatenates a path itself.
            ->addColumn('path', 'string', [
                'limit' => 255,
                'collation' => 'utf8mb4_bin',
                'comment' => 'Root-relative, no leading slash, web-servable',
            ])
            // A small preview generated FOR this item and owned exclusively
            // by it, so the admin grid never loads full-size photos. NULL
            // means there is none — true for every legacy file adopted in
            // place, which the admin then displays at thumbnail size with
            // CSS instead. Deleting a media item deletes this file too;
            // nothing else may ever reference it.
            ->addColumn('thumbnail_path', 'string', [
                'limit' => 255,
                'null' => true,
                'collation' => 'utf8mb4_bin',
                'comment' => 'Generated preview owned exclusively by this row; NULL = none',
            ])
            // The name the editor's own file had. Never used as a
            // filesystem name — it exists so a human can recognise the
            // image again, and so search has something to match on.
            ->addColumn('original_filename', 'string', ['limit' => 255, 'default' => ''])
            ->addColumn('mime_type', 'string', ['limit' => 100, 'default' => ''])
            ->addColumn('width', 'integer', ['limit' => 11, 'null' => true])
            ->addColumn('height', 'integer', ['limit' => 11, 'null' => true])
            ->addColumn('file_size', 'integer', ['signed' => false, 'null' => true])
            // THE reason to centralise media: one default alt text per file,
            // maintained in one place. A feature that uses the item may
            // still keep a local override for a context where the same photo
            // means something different — see MEDIA.md.
            ->addColumn('alt_text', 'string', ['limit' => 255, 'default' => ''])
            // SHA-256 of the stored bytes. Lets an exact re-upload of a file
            // that is already in the library reuse the existing item instead
            // of making a second copy. NULL when it could not be computed
            // (an adopted row whose file is missing). NOT unique: a legacy
            // site can genuinely contain the same bytes at two paths, and a
            // migration must never fail over that.
            ->addColumn('checksum', 'char', ['limit' => 64, 'null' => true])
            ->addColumn('created_at', 'datetime', ['null' => true])
            ->addColumn('updated_at', 'datetime', ['null' => true])
            ->addIndex(['path'], ['unique' => true])
            ->addIndex(['checksum'])
            // The library lists newest first and searches on filename/alt
            // text; this is the index the listing's ORDER BY uses.
            ->addIndex(['created_at'])
            ->create();
    }

    public function down(): void
    {
        // Forward-only in practice, but a table this migration created and
        // nothing has adopted yet is safe to drop again.
        if ($this->hasTable('media')) {
            $this->table('media')->drop()->save();
        }
    }
}
