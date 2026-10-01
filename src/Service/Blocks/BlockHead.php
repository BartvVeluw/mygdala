<?php

declare(strict_types=1);

namespace App\Service\Blocks;

/**
 * The optional editorial head of a block: an eyebrow, a title and a short
 * text above whatever the block shows. Plain text, all three optional, all
 * three per website language in block_translations.
 *
 * ONE DECLARATION for the blocks that share it. Most blocks with a head
 * (FAQ, Reviews, the gallery…) still write the same three fields out
 * themselves; this class exists so the next block does not, and so the
 * blocks whose head is everything they own (the Shop's Productgrid and
 * Collectie-tegels, App\Service\ShopListingContent) declare, index, read and
 * render it in exactly one way:
 *
 *   - fields()       for BlockDefinition::translatableFields(), the lengths
 *                    the gallery's head always allowed;
 *   - searchRoles()  for BlockDefinition::searchFields(): the title is a
 *                    heading, the eyebrow and the text are text;
 *   - words()        the three strings in the request's language, the shape
 *                    partials/section-head.php renders;
 *   - admin/_block_head_fields.php   the three fields in an editor.
 *
 * The keys are the gallery's (`eyebrow`, `title`, `lead`), so a head moved
 * from one block to another keeps its words.
 */
final class BlockHead
{
    public const EYEBROW = 'eyebrow';

    public const TITLE = 'title';

    public const LEAD = 'lead';

    /** The three keys, in reading order. */
    public const KEYS = [self::EYEBROW, self::TITLE, self::LEAD];

    /** @return list<TranslatableField> */
    public static function fields(): array
    {
        return [
            TranslatableField::plain(self::EYEBROW, 255),
            TranslatableField::plain(self::TITLE, 255),
            TranslatableField::plain(self::LEAD, 600),
        ];
    }

    /** @return array<string, string> key => BlockSearchRole */
    public static function searchRoles(): array
    {
        return [
            self::EYEBROW => BlockSearchRole::TEXT,
            self::TITLE => BlockSearchRole::HEADING,
            self::LEAD => BlockSearchRole::TEXT,
        ];
    }

    /**
     * The head of one owner row in the request's language, with the default
     * language's words where this language has none (BlockLocalization::text()).
     *
     * @return array{eyebrow: string, title: string, lead: string}
     */
    public static function words(string $ownerTable, int $ownerId): array
    {
        if ($ownerId <= 0) {
            return self::none();
        }

        $words = [];
        foreach (self::KEYS as $key) {
            $words[$key] = BlockLocalization::text($ownerTable, $ownerId, $key);
        }

        return $words;
    }

    /** @return array{eyebrow: string, title: string, lead: string} */
    public static function none(): array
    {
        return [self::EYEBROW => '', self::TITLE => '', self::LEAD => ''];
    }

    /**
     * Whether there is any head to print. Whitespace is no word: an editor
     * that left a space behind gets no empty wrapper.
     *
     * @param array<string, mixed> $content anything carrying the three keys
     */
    public static function has(array $content): bool
    {
        foreach (self::KEYS as $key) {
            if (trim((string) ($content[$key] ?? '')) !== '') {
                return true;
            }
        }

        return false;
    }
}
