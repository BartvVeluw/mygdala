<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Social profiles become repeatable footer items: a `footer_social_links`
 * table, and every social URL an installation had filled in is copied into
 * it as one visible row.
 *
 * WHY A TABLE. Until Footer phase B a site had exactly one optional URL per
 * network, as seven fixed `social_<network>_url` settings rendered in the
 * order of App\Service\SocialProfiles::NETWORKS. That could not give an
 * editor a second Instagram account, an order of their own, or a way to take
 * a profile off the website without deleting what they typed. A row per
 * profile has all three, the same way `footer_links` has them for links.
 *
 * THE COLUMNS
 *
 *   network     a key of the CLOSED registry App\Service\SocialProfiles
 *               (instagram, facebook, …), validated in PHP rather than as a
 *               MySQL ENUM, the convention nav_items.link_type set. The label,
 *               the icon and the domain check all come from that registry;
 *               nothing about how a profile looks is stored here.
 *   url         the address, 2048 like footer_links.external_url
 *   sort_order  one list, one order
 *   is_visible  hidden = off the website, kept here
 *
 * No title per language: a network name and an address need no translation,
 * so the table is language-neutral on purpose (MULTILINGUAL.md). No unique
 * index on `network`: two accounts on one network are allowed.
 *
 * THE COPY. What the footer rendered is what it keeps rendering:
 *
 *   - one row per `social_<network>_url` that holds more than whitespace, with
 *     the value trimmed, which is what the admin endpoint stored and what the
 *     footer printed;
 *   - in the order the registry rendered them, which was the only order a
 *     site ever had;
 *   - visible;
 *   - an empty value adds nothing, so a fresh install (whose defaults are all
 *     empty, INSTALL-BOOTSTRAP.md) gets no invented profile;
 *   - a value longer than the column (not a real profile URL; the old screen
 *     capped input at 2048) is not truncated into a different address. Its
 *     settings row stays, so nothing is lost.
 *
 * The seven keys are written out below rather than read from the registry:
 * a later network added to App\Service\SocialProfiles must never change what
 * this migration did on a database that already ran it.
 *
 * A stored address is copied as it is, even one the stricter check in
 * App\Service\SocialProfiles::isValidProfileUrl() now refuses. It is then
 * listed on the Footer screen as "Niet op de website" instead of silently
 * disappearing, and the editor can correct it.
 *
 * THE OLD ROWS STAY. The `social_*_url` settings are not deleted and not
 * emptied; nothing reads or writes them any more (partials/footer.php and
 * App\Service\PageSeo read this table through SocialProfiles::forFooter()).
 * Removing them is a separate, destructive decision, as with header_cta_*
 * (20260916230000).
 *
 * Schema first and unconditionally, data after it (db/migrations/CLAUDE.md).
 * Idempotent: the table is created only when missing, and the copy is
 * skipped as soon as the table holds any row, so a second run never adds a
 * second copy. One multi-row INSERT, so a copy is either complete or absent.
 * MySQL/Vimexx: plain statements, no CTEs.
 */
final class MoveSocialProfilesIntoFooterSocialLinks extends AbstractMigration
{
    /** network => the legacy settings key, in the registry's rendering order. */
    private const LEGACY_KEYS = [
        'instagram' => 'social_instagram_url',
        'facebook' => 'social_facebook_url',
        'pinterest' => 'social_pinterest_url',
        'linkedin' => 'social_linkedin_url',
        'youtube' => 'social_youtube_url',
        'tiktok' => 'social_tiktok_url',
        'etsy' => 'social_etsy_url',
    ];

    private const URL_LIMIT = 2048;

    public function up(): void
    {
        $this->createTable();
        $this->copyTheLegacyProfiles();
    }

    private function createTable(): void
    {
        if ($this->hasTable('footer_social_links')) {
            return;
        }

        $this->table('footer_social_links', ['id' => true])
            ->addColumn('network', 'string', [
                'limit' => 30,
                'null' => false,
                'comment' => 'a key of App\Service\SocialProfiles',
            ])
            ->addColumn('url', 'string', ['limit' => self::URL_LIMIT, 'null' => false])
            ->addColumn('sort_order', 'integer', ['null' => false, 'default' => 0])
            ->addColumn('is_visible', 'boolean', ['null' => false, 'default' => true])
            ->addColumn('created_at', 'datetime', ['null' => true])
            ->addColumn('updated_at', 'datetime', ['null' => true])
            ->addIndex(['sort_order'])
            ->create();
    }

    private function copyTheLegacyProfiles(): void
    {
        if (!$this->hasTable('site_settings')) {
            return;
        }

        $existing = $this->fetchRow('SELECT COUNT(*) AS c FROM footer_social_links');
        if ((int) $existing['c'] > 0) {
            return;
        }

        $stored = [];
        foreach ($this->fetchAll("SELECT setting_key, setting_value FROM site_settings WHERE setting_key LIKE 'social\\_%\\_url'") as $row) {
            $stored[(string) $row['setting_key']] = (string) ($row['setting_value'] ?? '');
        }

        $placeholders = [];
        $parameters = [];
        $now = date('Y-m-d H:i:s');

        foreach (self::LEGACY_KEYS as $network => $key) {
            $url = trim($stored[$key] ?? '');

            if ($url === '' || mb_strlen($url) > self::URL_LIMIT) {
                continue;
            }

            $placeholders[] = '(?, ?, ?, 1, ?, ?)';
            array_push($parameters, $network, $url, count($placeholders) - 1, $now, $now);
        }

        if ($placeholders === []) {
            return;
        }

        $this->execute(
            'INSERT INTO footer_social_links (network, url, sort_order, is_visible, created_at, updated_at) VALUES '
                . implode(', ', $placeholders),
            $parameters
        );
    }

    /**
     * Forward-only in practice (db/migrations/CLAUDE.md). The copied rows are
     * ordinary content by now and the settings were never touched, so there
     * is nothing to undo that would not lose an edit.
     */
    public function down(): void
    {
    }
}
