<?php

declare(strict_types=1);

namespace App\Service\Blocks;

use App\Service\Language\AdminTranslator;
use App\Service\Language\LanguageRegistry;

/**
 * THE list of drawers a content block can sit in, and the only place their
 * Dutch names are written.
 *
 * A category is presentation, not capability: it decides which heading a
 * block appears under in the block picker (admin/page.php) and in the
 * Contentblokken catalogue (admin/content-blocks.php), and nothing else. No
 * render path, no write endpoint and no permission consults one, so moving a
 * block from one category to another is a labelling change and never a
 * behavioural one.
 *
 * The list is CLOSED and ordered, for the same reason
 * App\Service\Blocks\BlockDefinitions is: a category key arrives from a
 * definition, is echoed into a filter button's value and comes back in a
 * request as a filter term. It may only ever hit or miss a key here.
 * Tests\Service\BlockPresentationTest fails the build on a definition
 * naming a category this file does not list.
 *
 * The ORDER is the order the picker and the catalogue show, and it runs
 * roughly the way a page is built: the heading first, then the words, then
 * the pictures, then what a visitor is asked to do, and finally the blocks a
 * module brings.
 */
final class BlockCategories
{
    /** The top of a page: the band carrying its <h1> and its opening promise. */
    public const HERO = 'hero';

    /** Words: text, features, questions, figures, steps. */
    public const CONTENT = 'content';

    /** Blocks whose point is a picture, a gallery or a carousel. */
    public const MEDIA = 'media';

    /** Blocks asking the visitor to do something: click, fill in, get in touch. */
    public const ACTION = 'action';

    /** Blocks a module contributes — today only the Shop's two. */
    public const SHOP = 'shop';

    /**
     * key => Dutch name, in display order.
     *
     * @var array<string, string>
     */
    private const LABELS = [
        self::HERO => 'Kop van de pagina',
        self::CONTENT => 'Content',
        self::MEDIA => 'Beeld & media',
        self::ACTION => 'Actie & interactie',
        self::SHOP => 'Shop',
    ];

    /**
     * Every category, in display order.
     *
     * @return array<string, string> key => Dutch name
     */
    public static function all(): array
    {
        return self::LABELS;
    }

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_keys(self::LABELS);
    }

    public static function has(string $key): bool
    {
        return array_key_exists($key, self::LABELS);
    }

    /**
     * The Dutch name, or the key itself when nothing declares it — a screen
     * printing an unknown category must still print something, and the key
     * is the most useful thing left to say.
     */
    public static function label(string $key): string
    {
        $fallback = self::LABELS[$key] ?? $key;
        $catalogue = 'blockcategory.' . $key;

        // The heading a person reads, in their own CMS language; the key
        // itself never changes (MULTILINGUAL.md).
        return AdminTranslator::has($catalogue, LanguageRegistry::DEFAULT_LANGUAGE)
            ? AdminTranslator::trans($catalogue)
            : $fallback;
    }

    /**
     * $definitions sorted into their categories, in this file's display
     * order, with empty categories left out — what the picker and the
     * catalogue both iterate over, so the two always show the same headings
     * in the same order.
     *
     * A definition naming a category nobody registered is not dropped: it
     * lands in a group under its own key, so a mistake shows up on screen as
     * a stray heading instead of a block that quietly disappeared.
     * Tests\Service\BlockPresentationTest fails the build before it gets
     * that far.
     *
     * @param array<string, BlockDefinition> $definitions type => definition
     *
     * @return array<string, array<string, BlockDefinition>> category key => type => definition
     */
    public static function group(array $definitions): array
    {
        $grouped = array_fill_keys(self::keys(), []);

        foreach ($definitions as $type => $definition) {
            $category = $definition->category();
            $grouped[$category] ??= [];
            $grouped[$category][$type] = $definition;
        }

        return array_filter($grouped, static fn (array $blocks): bool => $blocks !== []);
    }
}
