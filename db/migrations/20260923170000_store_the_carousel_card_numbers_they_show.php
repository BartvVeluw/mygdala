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
 * WHAT COUNTS AS "SHOWS TODAY", exactly as CardCarouselContent counted it
 * up to f715193 (forSection() and card()):
 *
 *   - the cards of each carousel in their order (sort_order, then id), the
 *     order CardCarouselRepository::findCardsByCarouselId() reads them in;
 *   - only cards with is_active = 1 take part, and of those only the ones
 *     with a title in the default language (hasRequiredWords(): the title is
 *     the card's one required word). "A title" is judged like
 *     BlockLocalization::raw() judges it: PHP trim(), so a title of only
 *     spaces, tabs or line breaks is no title. That is why the decision is
 *     made here in PHP and not with a SQL comparison, which in MySQL's PAD
 *     SPACE collations ignores trailing spaces but not tabs or newlines;
 *   - a card whose default-language label, trimmed, is not empty printed that
 *     label and gets nothing; every other counted card printed its place;
 *   - the carousel's own switch does not matter here: a hidden carousel
 *     rendered nothing, but it rendered these same numbers before it was
 *     hidden and would again once switched back on, so it keeps them
 *     (backwards compatibility, not new visible content).
 *
 * A hidden card, or one without a title, showed nothing and was not counted;
 * it gets nothing, and if it is shown later it starts without a label, as a
 * new card does.
 *
 * OTHER LANGUAGES need nothing: a language read its own label when it had
 * one, and otherwise fell back to the default language's, and only then to
 * the place (BlockLocalization::value()). The place is now stored as the
 * default language's label, so every language shows exactly what it showed.
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

        // Every default-language title and label of every card, in one read.
        $words = [];
        foreach ($this->fetchAll(
            "SELECT owner_id, field, value FROM block_translations
              WHERE owner_table = 'carousel_cards' AND language_code = " . $this->quote($code) . "
                AND field IN ('title', 'number_label')"
        ) as $row) {
            $words[(int) $row['owner_id']][(string) $row['field']] = (string) $row['value'];
        }

        $cards = $this->fetchAll(
            'SELECT id, carousel_id FROM carousel_cards WHERE is_active = 1 ORDER BY carousel_id ASC, sort_order ASC, id ASC'
        );

        $place = [];
        foreach ($cards as $card) {
            $cardId = (int) $card['id'];

            if (trim($words[$cardId]['title'] ?? '') === '') {
                continue;
            }

            $carouselId = (int) $card['carousel_id'];
            $place[$carouselId] = ($place[$carouselId] ?? 0) + 1;

            $label = $words[$cardId]['number_label'] ?? null;
            if (trim((string) $label) !== '') {
                continue;
            }

            $number = sprintf('%02d', $place[$carouselId]);

            if ($label !== null) {
                // A stored label that is only whitespace: it showed the place.
                $this->execute(
                    "UPDATE block_translations SET value = ?, updated_at = NOW()
                      WHERE owner_table = 'carousel_cards' AND owner_id = ? AND language_code = ? AND field = 'number_label'",
                    [$number, $cardId, $code]
                );

                continue;
            }

            $this->execute(
                "INSERT INTO block_translations (owner_table, owner_id, language_code, field, value, created_at, updated_at)
                 VALUES ('carousel_cards', ?, ?, 'number_label', ?, NOW(), NOW())",
                [$cardId, $code, $number]
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
