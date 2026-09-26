<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\SpacerRepository;

/**
 * Read model of the Witruimte block (App\Service\Blocks\SpacerBlock): how
 * much vertical room one instance puts between the blocks around it.
 *
 * THE HEIGHT IS A WORD FROM A CLOSED LIST, never a number
 * (CONTENT-BLOCKS.md, "Een weergavekeuze is een woord uit een gesloten
 * lijst"). The heights themselves are steps of the spacing scale in
 * assets/css/blocks/spacer.css, smaller on a phone; a stored value this
 * class does not know reads as the default.
 *
 * The three states of every block (CONTENT-BLOCKS.md): no row or a failed
 * lookup is STATE_FALLBACK, a row switched off is STATE_HIDDEN, and both
 * render nothing — here that means no room either.
 */
final class SpacerContent
{
    public const STATE_FALLBACK = 'fallback';

    public const STATE_ACTIVE = 'active';

    public const STATE_HIDDEN = 'hidden';

    /** Value => the CMS label. 'medium' is where a new spacer starts. */
    public const SIZES = [
        'small' => 'Klein',
        'medium' => 'Middel',
        'large' => 'Groot',
        'xlarge' => 'Extra groot',
    ];

    public const DEFAULT_SIZE = 'medium';

    /** @var array<string, array{state: string, size: string}> */
    private static array $cache = [];

    /**
     * @return array{state: string, size: string} 'size' one of SIZES; templates
     *         must check 'state' !== STATE_HIDDEN before rendering
     */
    public static function forSection(string $pageSlug, string $sectionKey): array
    {
        $cacheKey = $pageSlug . ':' . $sectionKey;
        if (isset(self::$cache[$cacheKey])) {
            return self::$cache[$cacheKey];
        }

        try {
            $row = (new SpacerRepository())->findBySlugAndKey($pageSlug, $sectionKey);
        } catch (\Throwable $e) {
            error_log('[SpacerContent] lookup failed for "' . $cacheKey . '": ' . $e->getMessage());
            $row = null;
        }

        if ($row === null) {
            return self::$cache[$cacheKey] = ['state' => self::STATE_FALLBACK, 'size' => self::DEFAULT_SIZE];
        }

        if (!(bool) $row['is_active']) {
            return self::$cache[$cacheKey] = ['state' => self::STATE_HIDDEN, 'size' => self::DEFAULT_SIZE];
        }

        return self::$cache[$cacheKey] = ['state' => self::STATE_ACTIVE, 'size' => self::size((string) ($row['size'] ?? ''))];
    }

    /** A stored height, or the default for anything this class does not know. */
    public static function size(string $stored): string
    {
        return array_key_exists($stored, self::SIZES) ? $stored : self::DEFAULT_SIZE;
    }

    public static function clearCache(): void
    {
        self::$cache = [];
    }
}
