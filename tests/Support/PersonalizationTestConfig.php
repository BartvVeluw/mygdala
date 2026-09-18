<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Repository\ProductPersonalizationRepository;
use App\Service\Personalization\PersonalizationLocalization;
use App\Service\Personalization\PersonalizationRules;
use App\Service\Personalization\ProductPersonalizationContent;

/**
 * Builds a product's personalization configuration for a test, through the
 * real repository — never by writing rows directly.
 *
 * Phase 2 replaced the single save-everything call with granular per-view and
 * per-zone writes (see App\Repository\ProductPersonalizationRepository), which
 * is right for the CMS but verbose in a test that just needs "a product with
 * one text zone". This is that convenience, and nothing more: it applies no
 * rules of its own, so a test can still express an invalid configuration and
 * assert that the resolver or the validator rejects it.
 */
final class PersonalizationTestConfig
{
    /**
     * A DEDICATED personalization preview path — the folder real preview
     * images live in since Phase 3 (assets/images/personalization/), never the
     * product gallery's. Any non-empty path satisfies the resolver's "is there
     * an image" rule, but using the real folder keeps these fixtures honest
     * about the one thing the separation is for.
     */
    public const IMAGE = 'assets/images/personalization/zz-test-preview.png';

    /** What a product photo path looks like — never a valid preview image source. */
    public const PRODUCT_GALLERY_IMAGE = 'assets/images/products/zz-test-gallery.png';

    /**
     * @param array<string, mixed> $overrides zone properties to change
     * @return array<string, mixed> a complete zone definition
     */
    public static function zone(string $zoneKey, array $overrides = []): array
    {
        return $overrides + [
            'zone_key' => $zoneKey,
            'label' => null,
            'label_en' => null,
            'instructions' => null,
            'instructions_en' => null,
            'placeholder' => null,
            'placeholder_en' => null,
            'allow_text' => true,
            'allow_image' => true,
            'is_enabled' => true,
            'is_required' => false,
            'allow_rotation' => true,
            'max_text_length' => 30,
            'default_font' => null,
            'allowed_fonts' => [],
            'surcharge_cents' => 0,
            'area_x' => 25.0,
            'area_y' => 35.0,
            'area_width' => 50.0,
            'area_height' => 30.0,
        ];
    }

    /**
     * Configures a product from a compact description.
     *
     * @param array<int, array{view_key?: string, label?: ?string, label_en?: ?string, image?: ?string, zones: array<int, array<string, mixed>>}> $views
     * @param array<string, mixed> $settings
     * @return array{settings_id: int, view_ids: array<string, int>, zone_ids: array<string, int>}
     */
    public static function configure(int $productId, array $views, array $settings = []): array
    {
        $repository = new ProductPersonalizationRepository();

        $settingsId = $repository->saveSettings($productId, $settings + [
            'is_enabled' => true,
            'personalization_mode' => PersonalizationRules::PURCHASE_OPTIONAL,
        ]);

        // The words, per website language, where they live since Multilingual
        // 2.0 phase 5 wave D. The description above still speaks the
        // `<field>`/`<field>_en` pair, because that is what a test wants to
        // say; this is the one place that knows where each half goes.
        self::words(
            static fn (string $language, string $value): mixed
                => PersonalizationLocalization::saveInstructions($settingsId, $language, $value),
            $settings['instructions'] ?? null,
            $settings['instructions_en'] ?? null
        );

        $viewIds = [];
        $zoneIds = [];

        foreach ($views as $index => $view) {
            $viewKey = $view['view_key'] ?? ($index === 0 ? PersonalizationRules::DEFAULT_VIEW_KEY : 'view' . $index);

            $viewId = $repository->createView($settingsId, ['view_key' => $viewKey]);
            $viewIds[$viewKey] = $viewId;

            self::words(
                static fn (string $language, string $value): mixed
                    => PersonalizationLocalization::saveViewLabel($viewId, $language, $value),
                $view['label'] ?? null,
                $view['label_en'] ?? null
            );

            // array_key_exists, not ??: a test that deliberately configures a
            // view WITHOUT an image passes null and must keep it.
            $image = array_key_exists('image', $view) ? $view['image'] : self::IMAGE;
            if ($image !== null) {
                $repository->updateViewPreviewImagePath($viewId, $image);
            }

            foreach ($view['zones'] as $zone) {
                $zoneId = $repository->createZone($settingsId, $viewId, $zone);
                $zoneIds[$zone['zone_key']] = $zoneId;

                foreach ([
                    PersonalizationLocalization::LABEL => 'label',
                    PersonalizationLocalization::INSTRUCTIONS => 'instructions',
                    PersonalizationLocalization::PLACEHOLDER => 'placeholder',
                ] as $field => $key) {
                    self::words(
                        static fn (string $language, string $value): mixed
                            => PersonalizationLocalization::saveZone($zoneId, $language, [$field => $value]),
                        $zone[$key] ?? null,
                        $zone[$key . '_en'] ?? null
                    );
                }
            }
        }

        ProductPersonalizationContent::clearCache();
        PersonalizationLocalization::clearCache();

        return ['settings_id' => $settingsId, 'view_ids' => $viewIds, 'zone_ids' => $zoneIds];
    }

    /**
     * Hands one field's Dutch and English halves to the writer that owns them,
     * skipping a language that has nothing to say — which is how "not
     * translated" is stored.
     */
    private static function words(callable $save, ?string $dutch, ?string $english): void
    {
        foreach (['nl' => $dutch, 'en' => $english] as $language => $value) {
            if ($value !== null && trim($value) !== '') {
                $save((string) $language, $value);
            }
        }
    }

    /**
     * The shape Phase 1 produced and the commonest case in these tests: one
     * view, one zone, both under the key "default".
     *
     * @param array<string, mixed> $zoneOverrides
     * @param array<string, mixed> $settings
     * @return array{settings_id: int, view_ids: array<string, int>, zone_ids: array<string, int>}
     */
    public static function singleZone(int $productId, array $zoneOverrides = [], array $settings = [], ?string $image = self::IMAGE): array
    {
        return self::configure($productId, [[
            'view_key' => PersonalizationRules::DEFAULT_VIEW_KEY,
            'image' => $image,
            'zones' => [self::zone(PersonalizationRules::DEFAULT_ZONE_KEY, $zoneOverrides)],
        ]], $settings);
    }
}
