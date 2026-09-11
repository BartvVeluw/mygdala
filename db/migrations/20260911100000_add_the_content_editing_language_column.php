<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * The THIRD language state this CMS keeps apart (MULTILINGUAL.md).
 *
 * Multilingual V1 stored two of the three, and the missing one turned out to
 * be the one editors actually asked for:
 *
 *   1. which language the CMS INTERFACE runs in   -> admin_users.interface_language
 *   2. which language version of the CONTENT
 *      an administrator is editing right now      -> admin_users.content_editing_language  (THIS)
 *   3. which language a VISITOR reads the site in -> the browser, localStorage
 *
 * Number 2 used to be derived from the site's settings rather than chosen by
 * the person doing the work, so an administrator reading a Dutch CMS could
 * not edit the English version of a page. It is a preference about a PERSON,
 * exactly like the interface language, so it lives on the same row.
 *
 * Per account rather than per browser session on purpose: an administrator
 * who moves between a laptop and a desktop should find the CMS in the state
 * they left it, and "which language am I writing in" is the kind of state
 * that is confusing to lose.
 *
 * NULLABLE WITH NO DEFAULT, for the same reason interface_language is: NULL
 * means "this person has not chosen", which
 * App\Service\Language\ContentEditingLanguage answers with the site's default
 * website language. Nobody is silently assigned a preference they never
 * expressed.
 *
 * FORWARD-ONLY AND ADDITIVE. No `_nl` or `_en` column is touched, renamed or
 * removed, no content is rewritten, and no existing settings row is changed.
 */
final class AddTheContentEditingLanguageColumn extends AbstractMigration
{
    public function up(): void
    {
        if (!$this->hasTable('admin_users')) {
            return;
        }

        $table = $this->table('admin_users');
        if ($table->hasColumn('content_editing_language')) {
            return;
        }

        $table
            ->addColumn('content_editing_language', 'string', [
                'limit' => 10,
                'null' => true,
                'default' => null,
                'comment' => 'Which language version of the website content this account is editing; NULL = not chosen',
                'after' => 'interface_language',
            ])
            ->update();
    }

    public function down(): void
    {
        if ($this->hasTable('admin_users') && $this->table('admin_users')->hasColumn('content_editing_language')) {
            $this->table('admin_users')->removeColumn('content_editing_language')->update();
        }
    }
}
