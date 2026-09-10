<?php

declare(strict_types=1);

namespace App\Service\Media\Usage;

use App\Service\Media\MediaUsage;
use App\Service\Media\MediaUsageProvider;
use App\Service\SiteSettings;

/**
 * The site's own identity assets: the logo, the alternate logo, the favicon
 * and the default social-sharing image.
 *
 * These are settings rows rather than a table of their own, so "usage" is a
 * comparison against four values that App\Service\SiteSettings has already
 * loaded once for this request. No query at all, which also means this
 * provider cannot be the reason a listing is slow.
 *
 * The values live in `site_settings` and stay there: the Media Library owns
 * the file's identity, SiteSettings owns which media item is the site's logo.
 * See THEMING.md and MEDIA.md.
 */
final class BrandingMediaUsage extends MediaUsageProvider
{
    /** Setting key => what an editor calls it. */
    public const ASSETS = [
        'logo_media_id' => 'Logo',
        'logo_alt_media_id' => 'Tweede logo',
        'favicon_media_id' => 'Favicon',
        'og_image_media_id' => 'Standaard deel-afbeelding',
    ];

    public function key(): string
    {
        return 'branding';
    }

    public function label(): string
    {
        return 'Site-instellingen';
    }

    public function usagesFor(array $mediaIds): array
    {
        $wanted = array_flip(array_map('intval', $mediaIds));
        $settings = SiteSettings::all();

        $usages = [];

        foreach (self::ASSETS as $settingKey => $label) {
            $mediaId = (int) ($settings[$settingKey] ?? 0);

            if ($mediaId < 1 || !isset($wanted[$mediaId])) {
                continue;
            }

            $usages[$mediaId][] = new MediaUsage(
                source: $this->key(),
                label: $label,
                editUrl: '/admin/settings.php',
            );
        }

        return $usages;
    }
}
