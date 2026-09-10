<?php

declare(strict_types=1);

namespace App\Service\Media;

use App\Module\ModuleRegistry;
use App\Service\Media\Usage\BrandingMediaUsage;
use App\Service\Media\Usage\ContentBlockMediaUsage;
use App\Service\Media\Usage\PageSocialImageMediaUsage;

/**
 * Every feature that can answer "do you use this media item": Core's own
 * three, plus one per provider each ENABLED module contributes.
 *
 * The same shape as App\Service\AdminNavigation and App\Service\Sitemap —
 * an explicit, closed Core list merged with module contributions, cached for
 * the request, and forgotten by ModuleRegistry::reset(). It is deliberately
 * NOT an event bus: a module hands over an object, Core calls one method on
 * it, and nothing subscribes to anything.
 *
 * ENABLED rather than registered, on purpose. A module that is switched off
 * contributes nothing anywhere else either (MODULES.md), and its tables may
 * not even be queryable in a deployment that never had it. The consequence
 * is stated plainly in MEDIA.md: with the Shop off, an image used only by a
 * product would count as unused. Today that cannot happen — the Shop does
 * not use the library yet — and when it does, the answer is that a disabled
 * module's data is not part of the running site.
 */
final class MediaUsageRegistry
{
    /** @var list<MediaUsageProvider>|null built once per request */
    private static ?array $providers = null;

    /**
     * The features Core itself owns. A block or setting joining the library
     * either fits one of these three or brings a fourth; there is no
     * generic "scan every table" fallback, and there must not be one.
     *
     * @var list<class-string<MediaUsageProvider>>
     */
    private const CORE_PROVIDERS = [
        BrandingMediaUsage::class,
        PageSocialImageMediaUsage::class,
        ContentBlockMediaUsage::class,
    ];

    /** @return list<MediaUsageProvider> */
    public static function providers(): array
    {
        if (self::$providers !== null) {
            return self::$providers;
        }

        $providers = [];

        foreach (self::CORE_PROVIDERS as $class) {
            $providers[] = new $class();
        }

        foreach (ModuleRegistry::enabled() as $module) {
            foreach ($module->mediaUsageProviders() as $provider) {
                if ($provider instanceof MediaUsageProvider) {
                    $providers[] = $provider;
                }
            }
        }

        return self::$providers = $providers;
    }

    /** Forgets the merged list; App\Module\ModuleRegistry::reset() calls this. */
    public static function reset(): void
    {
        self::$providers = null;
    }

    /**
     * Every usage of every given media id, asked of every provider — a
     * bounded number of queries in total, never one per item.
     *
     * A provider that throws is logged and treated as "reports nothing"
     * rather than taking the whole admin screen down with it. That is the
     * safe direction for a LISTING; deletion does not rely on it, because
     * App\Service\Media\MediaService refuses to delete when a provider
     * fails rather than assuming the item is unused.
     *
     * @param list<int> $mediaIds
     *
     * @return array<int, list<MediaUsage>> keyed by media id, ids with no usage omitted
     */
    public static function usagesFor(array $mediaIds): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $mediaIds),
            static fn (int $id): bool => $id > 0
        )));

        if ($ids === []) {
            return [];
        }

        $all = [];

        foreach (self::providers() as $provider) {
            try {
                $reported = $provider->usagesFor($ids);
            } catch (\Throwable $e) {
                error_log('[MediaUsageRegistry] ' . $provider->key() . ': ' . $e->getMessage());
                continue;
            }

            foreach ($reported as $mediaId => $usages) {
                foreach ($usages as $usage) {
                    $all[(int) $mediaId][] = $usage;
                }
            }
        }

        return $all;
    }

    /**
     * The same question with no tolerance for a failing provider: used by
     * deletion, where "I could not find out" must never be rounded down to
     * "nothing uses it".
     *
     * @param list<int> $mediaIds
     *
     * @return array<int, list<MediaUsage>>
     *
     * @throws \RuntimeException when a provider cannot answer
     */
    public static function usagesForStrict(array $mediaIds): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $mediaIds),
            static fn (int $id): bool => $id > 0
        )));

        if ($ids === []) {
            return [];
        }

        $all = [];

        foreach (self::providers() as $provider) {
            try {
                $reported = $provider->usagesFor($ids);
            } catch (\Throwable $e) {
                error_log('[MediaUsageRegistry] ' . $provider->key() . ': ' . $e->getMessage());

                throw new \RuntimeException('Kon niet vaststellen waar deze afbeelding wordt gebruikt. Probeer het later opnieuw.');
            }

            foreach ($reported as $mediaId => $usages) {
                foreach ($usages as $usage) {
                    $all[(int) $mediaId][] = $usage;
                }
            }
        }

        return $all;
    }

    /**
     * Just the counts, for a listing that only needs a badge per thumbnail.
     *
     * @param list<int> $mediaIds
     *
     * @return array<int, int> keyed by media id; every requested id present
     */
    public static function countsFor(array $mediaIds): array
    {
        $counts = [];

        foreach ($mediaIds as $id) {
            $counts[(int) $id] = 0;
        }

        foreach (self::usagesFor($mediaIds) as $mediaId => $usages) {
            $counts[(int) $mediaId] = count($usages);
        }

        return $counts;
    }
}
