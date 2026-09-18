<?php

declare(strict_types=1);

namespace App\Service\Personalization;

use App\Module\ModuleRegistry;
use App\Repository\ProductPersonalizationRepository;
use App\Repository\ProductRepository;
use App\Service\Language\LanguageRegistry;

/**
 * Resolves a product's personalization CONFIGURATION into the one shape every
 * consumer uses: the public product page (partials/product-personalization.php),
 * the checkout validator, and the snapshot stored with an order line.
 *
 * Same role — and the same per-request cache + clearCache() convention — as
 * App\Service\RelatedProductsContent and App\Service\ProductSeo: "does this
 * product offer personalization, on which views, with which zones, under what
 * rules" is CMS configuration and belongs on the server, resolved once.
 *
 * forProduct() returns NULL for everything that must not show a
 * personalization UI, so a caller never re-derives the rule:
 *   - the product was never configured (no settings row — every ordinary
 *     product);
 *   - personalization is switched off;
 *   - no view has a preview image (there would be nothing to draw on);
 *   - no view has a usable zone (enabled, and allowing text and/or an image).
 *
 * A configuration that survives all of that is guaranteed renderable: every
 * view it returns has an image, and every zone it returns is usable. Callers
 * never have to guard again.
 */
class ProductPersonalizationContent
{
    /** @var array<int, array<string, mixed>|null> */
    private static array $cache = [];

    private ProductPersonalizationRepository $repository;
    private ProductRepository $products;

    public function __construct(
        ?ProductPersonalizationRepository $repository = null,
        ?ProductRepository $products = null
    ) {
        $this->repository = $repository ?? new ProductPersonalizationRepository();
        $this->products = $products ?? new ProductRepository();
    }

    public static function clearCache(): void
    {
        self::$cache = [];
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function forProduct(int $productId): ?array
    {
        if (array_key_exists($productId, self::$cache)) {
            return self::$cache[$productId];
        }

        try {
            $resolved = (new self())->resolve($productId);
        } catch (\Throwable $e) {
            // A configuration lookup failing must never take down a product
            // page — it degrades to "this product has no personalization".
            error_log('[ProductPersonalizationContent] ' . $e->getMessage());
            $resolved = null;
        }

        self::$cache[$productId] = $resolved;

        return $resolved;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function resolve(int $productId): ?array
    {
        // The module gate, at the one place every caller already goes through
        // — the product page, the public catalogue, the checkout validator and
        // the order snapshot all resolve a product's configuration here. With
        // Personalisatie switched off every product therefore reports "cannot
        // be personalized", so no editor renders, no price surcharge applies
        // and api/checkout.php rejects a cart line that still carries
        // personalization data instead of quietly storing it. See MODULES.md.
        if (!ModuleRegistry::isEnabled('personalization')) {
            return null;
        }

        if ($productId < 1) {
            return null;
        }

        $stored = $this->repository->findForProduct($productId);

        if ($stored === null || (int) ($stored['settings']['is_enabled'] ?? 0) !== 1) {
            return null;
        }

        // Every label, instruction and placeholder this configuration prints,
        // in one query per store rather than one per view and one per zone.
        // They are words per website language since Multilingual 2.0 phase 5
        // wave D (App\Service\Personalization\PersonalizationLocalization).
        self::preloadWords($stored['views']);

        $views = [];
        foreach ($stored['views'] as $view) {
            $previewImagePath = trim((string) ($view['preview_image_path'] ?? ''));

            if ($previewImagePath === '') {
                continue;
            }

            $zones = [];
            foreach ($view['zones'] ?? [] as $zone) {
                $resolvedZone = self::resolveZone($zone);
                if ($resolvedZone !== null) {
                    $zones[] = $resolvedZone;
                }
            }

            if ($zones === []) {
                continue;
            }

            $viewId = (int) $view['id'];

            $views[] = [
                'view_key' => (string) $view['view_key'],
                'label' => self::word(PersonalizationLocalization::viewLabel($viewId, LanguageRegistry::DUTCH)),
                'label_en' => self::word(PersonalizationLocalization::viewLabel($viewId, LanguageRegistry::ENGLISH)),
                'preview_image_path' => $previewImagePath,
                'zones' => $zones,
            ];
        }

        if ($views === []) {
            return null;
        }

        $mode = PersonalizationRules::purchaseMode($stored['settings']['personalization_mode'] ?? null);

        /**
         * A product that is NOT in the ordinary shop has no ordinary purchase
         * path, so personalizing it is not optional whatever its own mode
         * says: there is simply no other way to buy it. Deriving that here,
         * once, is what stops the product page, the editor and the checkout
         * validator from ever disagreeing about whether a given product may
         * be added to the cart blank — they all read `is_required` and none
         * of them re-derives the rule.
         *
         * The stored `mode` is left untouched and still travels separately,
         * so switching a product back into the shop restores exactly the
         * behaviour the owner configured rather than a guess.
         */
        $isShopPurchasable = $this->products->isShopPurchasable($productId);
        $isRequired = $mode === PersonalizationRules::PURCHASE_REQUIRED || !$isShopPurchasable;
        $settingsId = (int) $stored['settings']['id'];

        return [
            'product_id' => $productId,
            'mode' => $mode,
            'is_shop_purchasable' => $isShopPurchasable,
            'is_personalization_only' => !$isShopPurchasable,
            'is_required' => $isRequired,
            'instructions' => self::word(PersonalizationLocalization::instructions($settingsId, LanguageRegistry::DUTCH)),
            'instructions_en' => self::word(PersonalizationLocalization::instructions($settingsId, LanguageRegistry::ENGLISH)),
            // The fonts a customer may pick, resolved ONCE for the whole
            // configuration rather than per zone: the library is global, so
            // every text zone on this product offers exactly this list.
            'fonts' => PersonalizationFonts::activeKeys(),
            'default_font' => PersonalizationFonts::fallbackKey(),
            // The colour a customer may PREVIEW their text in. A fixed
            // palette in code, never a value the browser may invent — see
            // App\Service\Personalization\PersonalizationColors.
            'colors' => PersonalizationColors::payload(),
            'default_color' => PersonalizationColors::FALLBACK,
            'views' => $views,
        ];
    }

    /**
     * @param array<string, mixed> $zone a raw row
     * @return array<string, mixed>|null null when the zone cannot be offered
     */
    private static function resolveZone(array $zone): ?array
    {
        if ((int) ($zone['is_enabled'] ?? 1) !== 1) {
            return null;
        }

        $allowText = (int) ($zone['allow_text'] ?? 0) === 1;
        $allowImage = (int) ($zone['allow_image'] ?? 0) === 1;

        if (!$allowText && !$allowImage) {
            return null;
        }

        // Fonts are GLOBAL as of Phase 3: every text zone offers exactly the
        // fonts that are active in the library right now (see
        // App\Service\Personalization\PersonalizationFonts). The zone's own
        // legacy `allowed_fonts`/`default_font` columns are deliberately NOT
        // read — a font list per zone is precisely what this refactor removed.
        $fonts = $allowText ? PersonalizationFonts::activeKeys() : [];
        $defaultFont = $allowText ? PersonalizationFonts::fallbackKey() : null;
        $defaultColor = $allowText ? PersonalizationColors::FALLBACK : null;

        $zoneId = (int) $zone['id'];
        $word = static fn (string $field, string $language): ?string => self::word(
            PersonalizationLocalization::zoneWord($zoneId, $field, $language)
        );

        return [
            'zone_key' => (string) $zone['zone_key'],
            'label' => $word(PersonalizationLocalization::LABEL, LanguageRegistry::DUTCH),
            'label_en' => $word(PersonalizationLocalization::LABEL, LanguageRegistry::ENGLISH),
            'instructions' => $word(PersonalizationLocalization::INSTRUCTIONS, LanguageRegistry::DUTCH),
            'instructions_en' => $word(PersonalizationLocalization::INSTRUCTIONS, LanguageRegistry::ENGLISH),
            'placeholder' => $word(PersonalizationLocalization::PLACEHOLDER, LanguageRegistry::DUTCH),
            'placeholder_en' => $word(PersonalizationLocalization::PLACEHOLDER, LanguageRegistry::ENGLISH),
            'allow_text' => $allowText,
            'allow_image' => $allowImage,
            'mode' => PersonalizationRules::contentMode($allowText, $allowImage),
            'is_required' => (int) ($zone['is_required'] ?? 0) === 1,
            'allow_rotation' => (int) ($zone['allow_rotation'] ?? 1) === 1,
            'max_text_length' => PersonalizationRules::clampMaxTextLength($zone['max_text_length'] ?? null),
            'fonts' => $fonts,
            'default_font' => $defaultFont,
            'default_color' => $defaultColor,
            'surcharge_cents' => Money::toCents($zone['surcharge'] ?? 0),
            'area' => PersonalizationRules::clampArea([
                'x' => $zone['area_x'] ?? null,
                'y' => $zone['area_y'] ?? null,
                'width' => $zone['area_width'] ?? null,
                'height' => $zone['area_height'] ?? null,
            ]),
        ];
    }

    /* ------------------------------------------------------------------ */
    /* Overview-level completeness                                         */
    /* ------------------------------------------------------------------ */

    /**
     * Whether a configuration that is switched ON would still render NOTHING,
     * judged from the counts a LIST screen already has — the `view_count` /
     * `view_with_image_count` / `zone_count` columns of
     * ProductPersonalizationRepository::findAllConfigured().
     *
     * It exists so the two CMS screens that flag this (the Personalisatie
     * overview's "Onvolledig" badge and the dashboard's "Aandacht nodig"
     * list) ask the same question in the same words instead of each writing
     * their own comparison. resolve() above stays THE authority on whether a
     * given product can be personalized; this is the cheap overview-level
     * mirror of its two structural requirements (a view needs a preview
     * image, and there has to be a zone), for a screen that has a whole
     * catalogue to judge and cannot resolve every product in full.
     *
     * Known and accepted imprecision: because the counts are per
     * configuration rather than per view, a configuration whose only
     * image-bearing view holds no zones while a second, imageless view holds
     * them all is not flagged here, even though resolve() would return null
     * for it. Both screens link straight into the editor, where each view
     * shows its own image and zones — so the finer case is visible exactly
     * where it is fixed.
     */
    public static function summaryIsIncomplete(int $viewsWithImageCount, int $zoneCount): bool
    {
        return $viewsWithImageCount === 0 || $zoneCount === 0;
    }

    /* ------------------------------------------------------------------ */
    /* Lookups                                                             */
    /* ------------------------------------------------------------------ */

    /**
     * One zone out of a resolved configuration, by key, together with the
     * view it lives on — which is what both the validator and the order
     * snapshot need, and why this returns the pair rather than the zone
     * alone. Zone keys are unique per product, so the view is unambiguous.
     *
     * @param array<string, mixed> $config
     * @return array{view: array<string, mixed>, zone: array<string, mixed>}|null
     */
    public static function locateZone(array $config, string $zoneKey): ?array
    {
        foreach ($config['views'] ?? [] as $view) {
            foreach ($view['zones'] as $zone) {
                if ($zone['zone_key'] === $zoneKey) {
                    return ['view' => $view, 'zone' => $zone];
                }
            }
        }

        return null;
    }

    /**
     * Every zone of a configuration, flattened, each paired with its view.
     * Used to check required zones without the caller having to walk two
     * levels of nesting itself.
     *
     * @param array<string, mixed> $config
     * @return list<array{view: array<string, mixed>, zone: array<string, mixed>}>
     */
    public static function allZones(array $config): array
    {
        $zones = [];
        foreach ($config['views'] ?? [] as $view) {
            foreach ($view['zones'] as $zone) {
                $zones[] = ['view' => $view, 'zone' => $zone];
            }
        }

        return $zones;
    }

    /* ------------------------------------------------------------------ */
    /* Order snapshot                                                      */
    /* ------------------------------------------------------------------ */

    /**
     * The immutable copy stored with one order-line zone
     * (order_item_personalizations.config_snapshot_json).
     *
     * Deliberately a SUPERSET of the Phase 1 (`version: 1`) snapshot: the
     * same `preview_image_path`, `zone.area`, `zone.max_text_length` and
     * `render` keys mean the same things in the same places, so the CMS order
     * screen renders a Phase 1 order and a Phase 2 order through one code
     * path. Version 2 only ADDED: which view the zone sat on, its labels, its
     * required state, the fonts it offered, and the surcharge configuration
     * that was in force.
     *
     * Version 3 ADDS two more things and changes nothing:
     *   - `purchase_mode`, so an order can still say whether this product was
     *     sold as a required-personalization product at the time;
     *   - `font`, the CHOSEN font's own label, CSS stack and file path,
     *     copied out of the library at the moment of purchase. That copy is
     *     what makes the library safely editable: deactivating, renaming or
     *     even deleting a font can never change what a historical order says
     *     it was engraved in, because the order stopped depending on the
     *     library row the second it was placed. `$fontKey` is null for a zone
     *     the customer filled with an image only.
     *
     * `surcharge_charged` is what the customer was actually charged for this
     * zone on this order — recorded separately from the zone's configured
     * surcharge so that a configuration read later can never be mistaken for
     * an invoice line.
     *
     * @param array<string, mixed> $config a resolved configuration
     * @param array<string, mixed> $view   the zone's view
     * @param array<string, mixed> $zone   the zone itself
     * @return array<string, mixed>
     */
    public static function snapshot(
        array $config,
        array $view,
        array $zone,
        int $surchargeChargedCents,
        ?string $fontKey = null,
        ?string $colorKey = null
    ): array {
        return [
            'version' => 3,
            'captured_at' => (new \DateTimeImmutable())->format('c'),
            'preview_image_path' => $view['preview_image_path'],
            'instructions' => $config['instructions'] ?? null,
            'purchase_mode' => $config['mode'] ?? PersonalizationRules::PURCHASE_OPTIONAL,
            // Whether the product was a personalization-only product at the
            // time. Recorded because it explains WHY personalization was
            // mandatory on this order even when `purchase_mode` says
            // "optional" — the product simply had no other purchase path.
            'personalization_only' => (bool) ($config['is_personalization_only'] ?? false),
            'font' => self::fontSnapshot($fontKey),
            // The palette entry the customer picked, copied the same way the
            // font is: a later palette change can never rewrite what a placed
            // order says the text was previewed in.
            'color' => $colorKey === null ? null : PersonalizationColors::snapshot($colorKey),
            'view' => [
                'view_key' => $view['view_key'],
                'label' => $view['label'],
                'label_en' => $view['label_en'],
            ],
            'zone' => [
                'zone_key' => $zone['zone_key'],
                'label' => $zone['label'],
                'label_en' => $zone['label_en'],
                'instructions' => $zone['instructions'],
                'allow_text' => $zone['allow_text'],
                'allow_image' => $zone['allow_image'],
                'mode' => $zone['mode'],
                'is_required' => $zone['is_required'],
                'allow_rotation' => $zone['allow_rotation'],
                'max_text_length' => $zone['max_text_length'],
                'allowed_fonts' => $zone['fonts'],
                'default_font' => $zone['default_font'],
                'surcharge' => Money::format($zone['surcharge_cents']),
                'area' => $zone['area'],
            ],
            'surcharge_charged' => Money::format($surchargeChargedCents),
            'render' => [
                'text_base_height_ratio' => PersonalizationRules::TEXT_BASE_HEIGHT_RATIO,
                'image_base_width_ratio' => PersonalizationRules::IMAGE_BASE_WIDTH_RATIO,
            ],
        ];
    }

    /**
     * The order's own copy of the font it was engraved in: everything the CMS
     * needs to re-render that text years later without consulting the
     * library. `source`/`file_path` are what let an uploaded font's
     * `@font-face` be rebuilt even after the library row is gone.
     *
     * @return array<string, mixed>|null
     */
    private static function fontSnapshot(?string $fontKey): ?array
    {
        if ($fontKey === null || $fontKey === '') {
            return null;
        }

        $font = PersonalizationFonts::record($fontKey);

        return [
            'key' => $fontKey,
            'label' => $font === null ? $fontKey : (string) $font['label'],
            'stack' => $font === null ? null : (string) $font['stack'],
            'source' => $font === null ? null : (string) $font['source'],
            'file_path' => $font === null ? null : $font['file_path'],
            'file_format' => $font === null ? null : $font['file_format'],
        ];
    }

    private static function nullableText(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : '';

        return $value === '' ? null : $value;
    }

    /**
     * One word from the store, as this class's consumers expect it: null when
     * there is nothing to print in any language.
     *
     * The value already carries the one fallback rule
     * (App\Service\Language\LanguageFallback), so a zone labelled only in the
     * default language reads the same in both halves of the temporary V1 pair
     * — which is exactly what the browser used to do for itself.
     */
    private static function word(string $value): ?string
    {
        return self::nullableText($value);
    }

    /**
     * The words of every view and every zone of one configuration, in one
     * query per store.
     *
     * @param array<int, array<string, mixed>> $views
     */
    private static function preloadWords(array $views): void
    {
        $viewIds = [];
        $zoneIds = [];

        foreach ($views as $view) {
            $viewIds[] = (int) $view['id'];

            foreach ($view['zones'] ?? [] as $zone) {
                $zoneIds[] = (int) $zone['id'];
            }
        }

        PersonalizationLocalization::preloadViews($viewIds);
        PersonalizationLocalization::preloadZones($zoneIds);
    }
}
