<?php

declare(strict_types=1);

use App\Install\InstallState;
use Phinx\Migration\AbstractMigration;

/**
 * Writes the header call-to-action and the footer slogan this installation
 * is CURRENTLY rendering into `site_settings`, so that partials/header.php
 * and partials/footer.php can stop carrying them as literals without the
 * site losing its "Vraag offerte aan" button or its closing line.
 *
 * Same shape and the same reasoning as
 * 20260909210000_pin_branding_paths_before_generic_defaults.php: hardcoded
 * copy becomes a setting, the CODE default becomes generic (the button off
 * and unlabelled, the slogan off and empty — see
 * App\Service\SiteSettings::DEFAULTS), and this migration makes sure
 * "generic default" can only ever mean "this install has not chosen one
 * yet". A second installation of this CMS gets a coherent header and footer
 * without inheriting another company's wording.
 *
 * THE CTA'S TARGET is stored the way every other link in this CMS is stored
 * — a link_type plus one companion field, read back by
 * App\Service\LinkResolver (see App\Service\HeaderCta). The literal being
 * replaced was '/contact.php', so this points at the Contact PAGE rather
 * than at that path: the button then follows the page if its slug ever
 * changes, disappears rather than 404s if the page is unpublished, and
 * App\Service\PageService can see that the page is still linked. Falling
 * back to the raw path only happens if that page is somehow missing, which
 * would otherwise leave the site with no button at all.
 *
 * NO SOCIAL ROWS. The other half of Header & Footer V1 is social profiles,
 * and this site has none configured anywhere in code or data. Inventing
 * plausible ones would be worse than leaving the row unrendered, so nothing
 * is written for them at all.
 *
 * Forward-only and non-destructive: INSERT IGNORE against the unique index
 * on setting_key, so a key that already has a row keeps whatever it holds,
 * including a deliberately emptied one. Idempotent.
 */
final class PinHeaderCtaAndFooterSlogan extends AbstractMigration
{
    /** The values partials/header.php and partials/footer.php used to hold. */
    private const PREVIOUS_LITERALS = [
        'header_cta_enabled' => '1',
        'header_cta_label_nl' => 'Vraag offerte aan',
        'header_cta_label_en' => 'Request a quote',
        'header_cta_open_in_new_tab' => '0',

        'footer_slogan_enabled' => '1',
        'footer_slogan_nl' => 'Ontworpen & gebouwd met zorg in Nijmegen',
        'footer_slogan_en' => 'Designed & built with care in Nijmegen',
    ];

    /** The page the old '/contact.php' literal meant. */
    private const CTA_PAGE_CONTENT_KEY = 'contact';

    /** What to point at when that page does not exist on this install. */
    private const CTA_FALLBACK_URL = '/contact.php';

    public function up(): void
    {
        if (!$this->hasTable('site_settings')) {
            return;
        }

        if (InstallState::isFreshInstall($this)) {
            // There is nothing to pin: no header ever rendered "Vraag
            // offerte aan" here, and there is no Contact page for the button
            // to point at — the fallback below would hand a brand-new site a
            // button aimed at /contact.php, which is a 404 on it. The code
            // defaults are already the right answer for an install that has
            // not chosen one (button off and unlabelled, slogan off and
            // empty — App\Service\SiteSettings::DEFAULTS).
            // See src/Install/InstallState.php.
            return;
        }

        $rows = self::PREVIOUS_LITERALS + $this->ctaTarget();

        $now = date('Y-m-d H:i:s');

        foreach ($rows as $key => $value) {
            $this->execute(
                'INSERT IGNORE INTO site_settings (setting_key, setting_value, created_at, updated_at)
                 VALUES (?, ?, ?, ?)',
                [$key, $value, $now, $now]
            );
        }
    }

    /**
     * The three target keys, pointing at the Contact page when it exists and
     * at the old raw path otherwise.
     *
     * @return array<string, string>
     */
    private function ctaTarget(): array
    {
        $pageId = null;

        if ($this->hasTable('pages')) {
            $row = $this->fetchRow(
                sprintf(
                    "SELECT id FROM pages WHERE content_key = '%s' LIMIT 1",
                    self::CTA_PAGE_CONTENT_KEY
                )
            );

            if (is_array($row) && isset($row['id'])) {
                $pageId = (int) $row['id'];
            }
        }

        if ($pageId === null) {
            return [
                'header_cta_link_type' => 'external',
                'header_cta_target_page_id' => '',
                'header_cta_external_url' => self::CTA_FALLBACK_URL,
            ];
        }

        return [
            'header_cta_link_type' => 'page',
            'header_cta_target_page_id' => (string) $pageId,
            'header_cta_external_url' => '',
        ];
    }

    /**
     * Nothing to undo: these are ordinary settings rows, and deleting them
     * would take a wording the owner may since have changed.
     */
    public function down(): void
    {
    }
}
