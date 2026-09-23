<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * A card of the Kaarten-carrousel no longer numbers itself: an empty
 * "Nummer of label" (`number_label`, a word in block_translations) now means
 * that the card shows no label at all (App\Service\CardCarouselContent). Until
 * now an empty label printed the card's place among the visible cards, "01",
 * "02", ...
 *
 * So that no site changes by itself, this migration writes down what every
 * card shows today: a card that is visible and has no label of its own in
 * the default language gets its current place as its label, in the default
 * language. Its translations, empty or not, keep reading exactly as before:
 * an empty one falls back to the default language, which now holds that same
 * number. After this an owner can empty a label on purpose, and a new card
 * starts without one.
 *
 * WHAT COUNTS AS "SHOWS TODAY", exactly as CardCarouselContent counted it:
 *
 *   - the cards of each carousel in their order (sort_order, then id);
 *   - only cards with is_active = 1 and a title in the default language
 *     (the card's required word) take part in the count;
 *   - the carousel's own switch does not matter: a carousel that is hidden
 *     now shows the same numbers again when it is switched back on.
 *
 * A hidden card, or one without a title, shows nothing today and gets
 * nothing; if it is shown later it starts without a label, as a new card
 * does.
 *
 * DATA ONLY, idempotent: it only fills a default-language label that is
 * missing, so a second run finds nothing to fill. No fresh-install guard is
 * needed: a fresh installation has no carousel cards. No down(): the labels
 * are ordinary content the owner may change from here on.
 */
final class StoreTheCarouselCardNumbersTheyShow extends AbstractMigration
{
    public function up(): void
    {
        if (!$this->hasTable('carousel_cards') || !$this->hasTable('block_translations') || !$this->hasTable('site_languages')) {
            return;
        }

        $default = $this->fetchRow('SELECT code FROM site_languages WHERE is_default = 1 LIMIT 1');
        if ($default === false || !is_array($default) || (string) $default['code'] === '') {
            return;
        }
        $code = (string) $default['code'];

        $cards = $this->fetchAll(
            "SELECT c.id, c.carousel_id,
                    EXISTS (SELECT 1 FROM block_translations t
                             WHERE t.owner_table = 'carousel_cards' AND t.owner_id = c.id
                               AND t.language_code = " . $this->quote($code) . " AND t.field = 'title'
                               AND t.value <> '') AS has_title,
                    EXISTS (SELECT 1 FROM block_translations t
                             WHERE t.owner_table = 'carousel_cards' AND t.owner_id = c.id
                               AND t.language_code = " . $this->quote($code) . " AND t.field = 'number_label'
                               AND t.value <> '') AS has_label
               FROM carousel_cards c
              WHERE c.is_active = 1
              ORDER BY c.carousel_id ASC, c.sort_order ASC, c.id ASC"
        );

        $place = [];
        foreach ($cards as $card) {
            if ((int) $card['has_title'] !== 1) {
                continue;
            }

            $carouselId = (int) $card['carousel_id'];
            $place[$carouselId] = ($place[$carouselId] ?? 0) + 1;

            if ((int) $card['has_label'] === 1) {
                continue;
            }

            $label = sprintf('%02d', $place[$carouselId]);

            // An empty stored value (or none) becomes the number; a row that
            // somehow exists with an empty value is updated, not duplicated.
            $this->execute(
                "UPDATE block_translations SET value = ?, updated_at = NOW()
                  WHERE owner_table = 'carousel_cards' AND owner_id = ? AND language_code = ? AND field = 'number_label' AND value = ''",
                [$label, (int) $card['id'], $code]
            );
            $this->execute(
                "INSERT INTO block_translations (owner_table, owner_id, language_code, field, value, created_at, updated_at)
                 SELECT 'carousel_cards', ?, ?, 'number_label', ?, NOW(), NOW()
                   FROM DUAL
                  WHERE NOT EXISTS (SELECT 1 FROM block_translations t
                                     WHERE t.owner_table = 'carousel_cards' AND t.owner_id = ?
                                       AND t.language_code = ? AND t.field = 'number_label')",
                [(int) $card['id'], $code, $label, (int) $card['id'], $code]
            );
        }
    }

    public function down(): void
    {
        // Content, not schema: nothing to take back.
    }

    private function quote(string $value): string
    {
        return "'" . str_replace("'", "''", $value) . "'";
    }
}
