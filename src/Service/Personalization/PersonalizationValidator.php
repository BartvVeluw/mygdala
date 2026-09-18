<?php

declare(strict_types=1);

namespace App\Service\Personalization;

use App\Repository\PersonalizationPreviewSnapshotRepository;
use App\Repository\PersonalizationUploadRepository;

/**
 * THE server-side gate for customer personalization. The frontend editor is a
 * convenience; this class is the source of truth, and it re-reads the
 * product's live configuration itself rather than trusting anything in the
 * request. A crafted payload can therefore never:
 *
 *   - personalize a product that has personalization switched off;
 *   - use a view or a zone that does not exist, or a zone that is disabled;
 *   - send text to a zone where text is not allowed;
 *   - exceed the administrator's maximum text length (the browser's
 *     `maxlength` is decoration — this is the check that counts);
 *   - attach an image to a zone where uploads are not allowed;
 *   - attach an upload that belongs to a different product;
 *   - pick a font the zone does not offer;
 *   - set a text colour outside the shop's fixed palette;
 *   - move or rotate content outside the configured engraving area (transform
 *     values are clamped into range, never taken as given);
 *   - skip a zone the administrator marked required;
 *   - buy a personalization-REQUIRED product without personalizing it. The
 *     ordinary add-to-cart path runs through this class like every other
 *     path, so "just don't send any personalization" is not a way around it;
 *   - influence the price. Surcharges are read from the product's own zone
 *     configuration here and NEVER from the request — a submitted amount is
 *     not merely ignored, it is never looked at.
 *
 * It accepts BOTH payload shapes on purpose. Phase 1 sent one zone as a flat
 * object; Phase 2 sends `{"zones": [...]}`. A customer whose browser still
 * holds a Phase 1 cart in localStorage must be able to check out, so the flat
 * shape is normalised into a one-zone list rather than rejected.
 */
class PersonalizationValidator
{
    private PersonalizationUploadRepository $uploads;
    private PersonalizationPreviewSnapshotRepository $snapshots;

    public function __construct(
        ?PersonalizationUploadRepository $uploads = null,
        ?PersonalizationPreviewSnapshotRepository $snapshots = null
    ) {
        $this->uploads = $uploads ?? new PersonalizationUploadRepository();
        $this->snapshots = $snapshots ?? new PersonalizationPreviewSnapshotRepository();
    }

    /**
     * @param mixed $submitted the request's `personalization` value for one cart line
     * @return array{zones: list<array<string, mixed>>, surcharge_cents: int}|null
     * @throws PersonalizationValidationException
     */
    public function validate(int $productId, mixed $submitted): ?array
    {
        $entries = self::normalizeSubmitted($submitted);
        $config = ProductPersonalizationContent::forProduct($productId);

        if ($config === null) {
            if ($entries === []) {
                return null;
            }

            throw new PersonalizationValidationException(
                'One of your items can no longer be personalized. Please remove it and add it again.'
            );
        }

        $validated = [];
        $seenZoneKeys = [];

        foreach ($entries as $entry) {
            $zoneKey = is_string($entry['zone_key'] ?? null) && $entry['zone_key'] !== ''
                ? $entry['zone_key']
                : PersonalizationRules::DEFAULT_ZONE_KEY;

            $located = ProductPersonalizationContent::locateZone($config, $zoneKey);

            if ($located === null) {
                throw new PersonalizationValidationException(
                    'One of your items was personalized in an area that no longer exists. Please remove it and add it again.'
                );
            }

            // A payload that names the same zone twice would make "which of
            // the two is the order line" undefined.
            if (isset($seenZoneKeys[$zoneKey])) {
                throw new PersonalizationValidationException(
                    'One of your items has conflicting personalization. Please remove it and add it again.'
                );
            }
            $seenZoneKeys[$zoneKey] = true;

            $zone = $located['zone'];
            $view = $located['view'];

            $text = $this->validateText($entry['text'] ?? null, $zone);
            $upload = $this->validateUpload($entry['upload_token'] ?? null, $productId, $zone);

            if ($text === null && $upload === null) {
                // The customer opened this zone but left it empty. Not an
                // error in itself — the required-zone check below decides.
                continue;
            }

            // The surcharge comes from the product's own configuration, and
            // applies exactly once when the zone is used, whatever is in it.
            $surchargeCents = $zone['surcharge_cents'];

            // The font is resolved against the fonts the zone ACTUALLY
            // offers, which as of Phase 3 is the globally ACTIVE library
            // (App\Service\Personalization\PersonalizationFonts) — never the
            // request. A key that has been deactivated, deleted, or invented
            // outright is not in that list and therefore cannot survive: the
            // customer ends up on the default instead.
            $fontKey = $text === null
                ? null
                : PersonalizationFonts::resolveSubmitted(
                    $entry['font'] ?? null,
                    $zone['fonts'],
                    (string) $zone['default_font']
                );

            // Everything the CMS needs to re-render this text later, copied
            // out of the library now — see the snapshot's `font` key. Stored
            // on the order row as well as in the snapshot so the order screen
            // can read it without parsing JSON.
            $font = $fontKey === null ? null : PersonalizationFonts::record($fontKey);

            // The colour is a preview preference from a fixed palette. Not
            // in the palette (invented, wrong type, missing) resolves to the
            // default rather than failing the order — the result can only
            // ever be one of five server-defined values, so correcting it is
            // safe in a way that accepting a CSS string would not be.
            $colorKey = $text === null
                ? null
                : PersonalizationColors::resolveSubmitted($entry['color'] ?? null);

            $validated[] = [
                'zone_key' => $zone['zone_key'],
                'view_key' => $view['view_key'],
                'text_value' => $text,
                'upload_id' => $upload === null ? null : (int) $upload['id'],
                'upload_token' => $upload === null ? null : (string) $upload['token'],
                'font_key' => $fontKey,
                'font_label' => $font === null ? null : (string) $font['label'],
                'font_stack' => $font === null ? null : (string) $font['stack'],
                'font_file_path' => $font === null ? null : $font['file_path'],
                'text_color' => $colorKey,
                'transform' => PersonalizationRules::normalizeTransformSet(
                    $entry['transform'] ?? null,
                    $zone['allow_rotation']
                ),
                'surcharge_cents' => $surchargeCents,
                'config_snapshot' => ProductPersonalizationContent::snapshot(
                    $config,
                    $view,
                    $zone,
                    $surchargeCents,
                    $fontKey,
                    $colorKey
                ),
            ];
        }

        $this->assertRequiredZonesFilled($config, $validated);

        if ($validated === []) {
            /**
             * A product that REQUIRES personalization cannot be bought plain.
             * This is the server-side half of that rule and the authoritative
             * one: the product page's own check is a courtesy, and the
             * ordinary add-to-cart path goes through here exactly like every
             * other path, so there is no second route that skips it.
             *
             * `is_required` deliberately, never the stored `mode`: it is the
             * one place that already combines "the owner set it to required"
             * with "the product is not in the shop at all, so there is no
             * other way to buy it" (see ProductPersonalizationContent). A
             * personalization-only product left on "optional" is still
             * required, and reading `mode` here would have let exactly that
             * product through blank.
             */
            if (($config['is_required'] ?? false) === true) {
                throw new PersonalizationValidationException(
                    'One of your items has to be personalized before it can be ordered. Please open the product and complete it.'
                );
            }

            return null;
        }

        // Ordered by the product's OWN configuration (view order, then zone
        // order), never by the order the browser happened to send. That does
        // two things at once: two identical personalizations always serialise
        // identically, so the fingerprint below can recognise them as one cart
        // line; and the resulting order rows read top-to-bottom the way the
        // administrator arranged the zones, rather than alphabetically.
        $configOrder = [];
        foreach (ProductPersonalizationContent::allZones($config) as $index => $located) {
            $configOrder[$located['zone']['zone_key']] = $index;
        }

        usort(
            $validated,
            static fn (array $a, array $b): int =>
                ($configOrder[$a['zone_key']] ?? PHP_INT_MAX) <=> ($configOrder[$b['zone_key']] ?? PHP_INT_MAX)
        );

        $surchargeTotal = 0;
        foreach ($validated as $zone) {
            $surchargeTotal += $zone['surcharge_cents'];
        }

        return ['zones' => $validated, 'surcharge_cents' => $surchargeTotal];
    }

    /**
     * The COMPOSED preview snapshots a cart line submitted, reduced to the
     * ones this product may actually claim.
     *
     * Supplementary by construction: an unusable token is silently dropped
     * rather than failing the order, because a snapshot is a convenience for
     * the workshop and the structured personalization is the real record. A
     * token survives only when it exists, is still unclaimed, was posted for
     * THIS product, and names a view this product really has — so a token
     * cannot be replayed onto another product's order line, stolen from
     * somebody else's order, or presented as the wrong side of the product.
     *
     * @param array<string, mixed> $config a resolved configuration
     * @param mixed $submitted the request's `preview_tokens` for one cart line
     * @return array<string, int> view_key => snapshot id
     */
    public function validatePreviewTokens(array $config, mixed $submitted): array
    {
        if (!is_array($submitted) || $submitted === []) {
            return [];
        }

        $viewKeys = [];
        foreach ($config['views'] ?? [] as $view) {
            $viewKeys[$view['view_key']] = true;
        }

        $claimable = [];

        foreach ($submitted as $viewKey => $token) {
            if (!is_string($viewKey) || !isset($viewKeys[$viewKey])) {
                continue;
            }

            if (!PersonalizationRules::isValidUploadToken($token)) {
                continue;
            }

            $snapshot = $this->snapshots->findByToken((string) $token);

            if ($snapshot === null
                || $snapshot['order_item_id'] !== null
                || (int) ($snapshot['product_id'] ?? 0) !== (int) $config['product_id']
                || (string) $snapshot['view_key'] !== $viewKey) {
                continue;
            }

            $claimable[$viewKey] = (int) $snapshot['id'];
        }

        return $claimable;
    }

    /**
     * A stable fingerprint of one validated personalization, used by checkout
     * to decide which cart lines are the same order line. Two units that
     * differ in ANY zone — its text, its font, its upload, where it was
     * placed, whether the zone was used at all — produce different
     * fingerprints and therefore stay separate lines; two units personalized
     * identically merge, which is exactly how the cart already treats two
     * identical plain products.
     *
     * The configuration snapshot is deliberately not part of it: it carries a
     * capture timestamp, so including it would make two identical
     * personalizations added a second apart look different.
     *
     * @param array{zones: list<array<string, mixed>>, surcharge_cents: int}|null $personalization
     */
    public static function fingerprint(?array $personalization): string
    {
        if ($personalization === null) {
            return '';
        }

        $material = [];
        foreach ($personalization['zones'] as $zone) {
            $material[] = [
                'zone' => $zone['zone_key'],
                'view' => $zone['view_key'],
                'text' => $zone['text_value'],
                'font' => $zone['font_key'],
                'color' => $zone['text_color'] ?? null,
                'upload' => $zone['upload_token'],
                'transform' => $zone['transform'],
                'surcharge' => $zone['surcharge_cents'],
            ];
        }

        return hash('sha256', (string) json_encode($material, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /* ------------------------------------------------------------------ */
    /* Payload shapes                                                      */
    /* ------------------------------------------------------------------ */

    /**
     * Normalises either payload shape into a list of per-zone entries, and
     * drops entries that carry nothing at all so an empty editor never counts
     * as "personalized".
     *
     * @return list<array<string, mixed>>
     */
    private static function normalizeSubmitted(mixed $submitted): array
    {
        if (!is_array($submitted) || $submitted === []) {
            return [];
        }

        // Phase 2: {"zones": [...]}
        if (isset($submitted['zones'])) {
            if (!is_array($submitted['zones'])) {
                return [];
            }
            $candidates = $submitted['zones'];
        } else {
            // Phase 1: a single flat zone object.
            $candidates = [$submitted];
        }

        $entries = [];
        foreach ($candidates as $candidate) {
            if (!is_array($candidate)) {
                continue;
            }

            $text = $candidate['text'] ?? null;
            $token = $candidate['upload_token'] ?? null;

            $hasText = is_string($text) && trim($text) !== '';
            $hasUpload = is_string($token) && trim($token) !== '';

            if ($hasText || $hasUpload) {
                $entries[] = $candidate;
            }
        }

        return $entries;
    }

    /* ------------------------------------------------------------------ */
    /* Per-zone rules                                                      */
    /* ------------------------------------------------------------------ */

    /**
     * @param array<string, mixed> $zone
     * @throws PersonalizationValidationException
     */
    private function validateText(mixed $text, array $zone): ?string
    {
        if (!is_string($text)) {
            return null;
        }

        // Control characters (including newlines pasted into a single-line
        // field) are stripped rather than rejected: they cannot be engraved
        // and are never what a customer meant to send. Everything else — the
        // customer's exact characters, accents and spacing — is preserved and
        // escaped at every point where it is displayed.
        $text = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $text) ?? '';
        $text = trim($text);

        if ($text === '') {
            return null;
        }

        if (!$zone['allow_text']) {
            throw new PersonalizationValidationException(
                'Text personalization is not available for one of your items. Please remove it and add it again.'
            );
        }

        if (mb_strlen($text) > $zone['max_text_length']) {
            throw new PersonalizationValidationException(
                'Your personalization text is longer than this product allows (maximum '
                . $zone['max_text_length'] . ' characters).'
            );
        }

        return $text;
    }

    /**
     * @param array<string, mixed> $zone
     * @return array<string, mixed>|null the upload row
     * @throws PersonalizationValidationException
     */
    private function validateUpload(mixed $token, int $productId, array $zone): ?array
    {
        if (!is_string($token) || trim($token) === '') {
            return null;
        }

        $token = trim($token);

        // Shape first: a token that is not exactly 32 hex characters never
        // reaches the database and can never be turned into a filename.
        if (!PersonalizationRules::isValidUploadToken($token)) {
            throw new PersonalizationValidationException(
                'The uploaded image for one of your items could not be found. Please upload it again.'
            );
        }

        if (!$zone['allow_image']) {
            throw new PersonalizationValidationException(
                'Image personalization is not available for one of your items. Please remove it and add it again.'
            );
        }

        $upload = $this->uploads->findByToken($token);

        if ($upload === null) {
            throw new PersonalizationValidationException(
                'The uploaded image for one of your items could not be found. Please upload it again.'
            );
        }

        // An upload is bound to the product it was uploaded for, so a token
        // cannot be replayed against a different product — including one that
        // does not allow uploads at all.
        if ((int) ($upload['product_id'] ?? 0) !== $productId) {
            throw new PersonalizationValidationException(
                'The uploaded image for one of your items does not belong to that product. Please upload it again.'
            );
        }

        return $upload;
    }

    /**
     * A zone the administrator marked required must actually carry something,
     * and what "something" means follows the zone's own content mode: text
     * for a text zone, an image for an image zone, and either for a zone that
     * offers both.
     *
     * @param array<string, mixed> $config
     * @param list<array<string, mixed>> $validated
     * @throws PersonalizationValidationException
     */
    private function assertRequiredZonesFilled(array $config, array $validated): void
    {
        $byZoneKey = [];
        foreach ($validated as $zone) {
            $byZoneKey[$zone['zone_key']] = $zone;
        }

        foreach (ProductPersonalizationContent::allZones($config) as $located) {
            $zone = $located['zone'];

            if (!$zone['is_required']) {
                continue;
            }

            $filled = $byZoneKey[$zone['zone_key']] ?? null;

            if ($filled !== null && self::satisfiesMode($filled, $zone['mode'])) {
                continue;
            }

            throw new PersonalizationValidationException(
                'One of your items is missing required personalization ('
                . self::zoneLabel($zone) . '). Please open the product and complete it.'
            );
        }
    }

    /**
     * @param array<string, mixed> $filled
     */
    private static function satisfiesMode(array $filled, string $mode): bool
    {
        return match ($mode) {
            PersonalizationRules::MODE_TEXT => $filled['text_value'] !== null,
            PersonalizationRules::MODE_IMAGE => $filled['upload_id'] !== null,
            default => $filled['text_value'] !== null || $filled['upload_id'] !== null,
        };
    }

    /**
     * A name a customer can act on. Falls back to the zone key only when the
     * administrator never labelled the zone — never to an internal id.
     *
     * The zone arrives from App\Service\Personalization\ProductPersonalizationContent,
     * so `label` already carries the one fallback rule
     * (App\Service\Language\LanguageFallback): the asked-for language, then
     * the default one. This class picks no language of its own and has no
     * "else the English one" branch any more.
     *
     * @param array<string, mixed> $zone
     */
    private static function zoneLabel(array $zone): string
    {
        return $zone['label'] ?? $zone['zone_key'];
    }
}
