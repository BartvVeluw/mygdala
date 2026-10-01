<?php

declare(strict_types=1);

namespace App\Service\Search;

use App\Repository\PageSectionRepository;
use App\Repository\SearchIndexRepository;
use App\Service\Blocks\BlockDefinition;
use App\Service\Blocks\BlockDefinitions;
use App\Service\Blocks\BlockLocalization;
use App\Service\Blocks\BlockSearchRole;

/**
 * The words of placed content blocks as a visitor reads them, as plain text,
 * per website language (Search 2.0, SEARCH.md "De tekst van de blokken").
 * Deterministic: the same stored blocks give the same text.
 *
 * WHAT. Only the fields a block declares in BlockDefinition::searchFields()
 * as HEADING or TEXT, of its own row and of its child rows
 * (BlockDefinition::childTables()). This class names no block type and no
 * block table; it reads the declarations. A block that shows other records
 * (a product, a project, a form) declares only its own words, so their
 * words are never copied into the page that shows them.
 *
 * WHAT A VISITOR SEES, NOTHING MORE. Nothing of a block that is hidden in the
 * block list (page_sections.is_active) or switched off in its own editor
 * (its row's is_active), of a type that is not registered right now (a
 * module that is off), or of a child row that is switched off (an item, a
 * card). Words per field in the asked-for language with the block system's
 * own fallback to the default language (BlockLocalization::value(), what
 * the public page prints), never another language.
 *
 * AS TEXT. Rich text loses its markup (SearchText::plain(): tags out,
 * entities decoded, white space collapsed); plain text only gets its white
 * space collapsed, because it is printed escaped as typed. The headings
 * inside a rich text (<h1>–<h6>) count as headings too. Scripts and styles
 * never reach here: rich text is RichTextSanitizer output.
 *
 * COST. For a batch of blocks: one query per content table (switched off?),
 * one per child table, and one for all their words (BlockLocalization::
 * preloadBlocks()). Never one per block, language or field.
 */
final class BlockTextExtractor
{
    /**
     * @param list<array<string, mixed>> $pageSections page_sections rows
     * @param list<string> $languages
     * @return array<int, array<string, array{headings: string, body: string}>> page_sections id => language => text; blocks without text are left out
     */
    public static function extract(array $pageSections, array $languages): array
    {
        $blocks = [];

        foreach ($pageSections as $pageSection) {
            $definition = BlockDefinitions::get((string) ($pageSection['section_type'] ?? ''));

            if (
                $definition === null
                || (int) ($pageSection['is_active'] ?? 0) !== 1
                || $definition->contentTable() === null
                || !self::hasSearchableField($definition)
            ) {
                continue;
            }

            $blocks[] = [$pageSection, $definition];
        }

        if ($blocks === [] || $languages === []) {
            return [];
        }

        // Switched off in its own editor: nothing on the public page.
        $byTable = [];
        foreach ($blocks as [$pageSection, $definition]) {
            $byTable[(string) $definition->contentTable()][] = (int) $pageSection['section_id'];
        }
        $off = [];
        $sections = new PageSectionRepository();
        foreach ($byTable as $table => $ids) {
            foreach ($sections->switchedOffContentIds($table, $ids) as $id) {
                $off[$table][$id] = true;
            }
        }
        $blocks = array_values(array_filter(
            $blocks,
            static fn (array $block): bool => !isset($off[(string) $block[1]->contentTable()][(int) $block[0]['section_id']])
        ));

        // The child rows that are shown, per block, in their own order.
        $children = self::children($blocks);

        $load = [];
        foreach ($blocks as [$pageSection, $definition]) {
            $load[(string) $definition->contentTable()][] = (int) $pageSection['section_id'];
        }
        BlockLocalization::preloadBlocks($load);

        $texts = [];
        foreach ($blocks as [$pageSection, $definition]) {
            $owners = [[(string) $definition->contentTable(), (int) $pageSection['section_id']]];
            foreach ($children[(int) $pageSection['id']] ?? [] as $child) {
                $owners[] = $child;
            }

            foreach ($languages as $language) {
                $text = self::text($definition, $owners, $language);
                if ($text['body'] !== '') {
                    $texts[(int) $pageSection['id']][$language] = $text;
                }
            }
        }

        return $texts;
    }

    private static function hasSearchableField(BlockDefinition $definition): bool
    {
        foreach ($definition->searchFields() as $fields) {
            foreach ($fields as $role) {
                if ($role === BlockSearchRole::HEADING || $role === BlockSearchRole::TEXT) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * The shown child rows of each block, walking BlockDefinition::childTables()
     * in its declared order (a table may hang under another child table):
     * page_sections id => list of [table, row id].
     *
     * @param list<array{0: array<string, mixed>, 1: BlockDefinition}> $blocks
     * @return array<int, list<array{0: string, 1: int}>>
     */
    private static function children(array $blocks): array
    {
        $repository = new SearchIndexRepository();
        $children = [];

        // Per type, so one query per child table for every block of that type.
        $byType = [];
        foreach ($blocks as [$pageSection, $definition]) {
            $byType[$definition->type()][] = [$pageSection, $definition];
        }

        foreach ($byType as $group) {
            $definition = $group[0][1];
            $searchable = $definition->searchFields();
            // row table => row id => page_sections id; starts at the block rows.
            $belongs = [(string) $definition->contentTable() => []];
            foreach ($group as [$pageSection]) {
                $belongs[(string) $definition->contentTable()][(int) $pageSection['section_id']] = (int) $pageSection['id'];
            }

            foreach ($definition->childTables() as $table => $hang) {
                $parents = $belongs[$hang['parent']] ?? [];
                $belongs[$table] = [];

                foreach ($repository->childRows($table, $hang['column'], array_keys($parents)) as $row) {
                    if (array_key_exists('is_active', $row) && (int) $row['is_active'] !== 1) {
                        continue;
                    }

                    $sectionId = $parents[(int) $row[$hang['column']]] ?? null;
                    if ($sectionId === null) {
                        continue;
                    }

                    $belongs[$table][(int) $row['id']] = $sectionId;
                    if (isset($searchable[$table])) {
                        $children[$sectionId][] = [$table, (int) $row['id']];
                    }
                }
            }
        }

        return $children;
    }

    /**
     * @param list<array{0: string, 1: int}> $owners the block row first, then its child rows
     * @return array{headings: string, body: string}
     */
    private static function text(BlockDefinition $definition, array $owners, string $language): array
    {
        $searchable = $definition->searchFields();
        $headings = [];
        $body = [];

        foreach ($owners as [$table, $id]) {
            $declared = BlockLocalization::fields($table);

            foreach ($searchable[$table] ?? [] as $field => $role) {
                if ($role === BlockSearchRole::NONE || !isset($declared[$field])) {
                    continue;
                }

                $value = BlockLocalization::value($table, $id, $field, $language);
                if ($value === '') {
                    continue;
                }

                if ($declared[$field]->isRich()) {
                    $text = SearchText::plain($value);
                    foreach (self::richHeadings($value) as $heading) {
                        $headings[] = $heading;
                    }
                } else {
                    $text = self::plainLine($value);
                }

                if ($text === '') {
                    continue;
                }

                if ($role === BlockSearchRole::HEADING) {
                    $headings[] = $text;
                }
                $body[] = $text;
            }
        }

        return ['headings' => implode(' ', $headings), 'body' => implode(' ', $body)];
    }

    /** @return list<string> the text of every <h1>–<h6> in a sanitized rich text */
    private static function richHeadings(string $html): array
    {
        if (preg_match_all('#<h[1-6]\b[^>]*>(.*?)</h[1-6]>#is', $html, $matches) < 1) {
            return [];
        }

        return array_values(array_filter(array_map([SearchText::class, 'plain'], $matches[1]), static fn (string $text): bool => $text !== ''));
    }

    /** Plain text as one line: control characters and runs of white space become one space. */
    private static function plainLine(string $text): string
    {
        return trim((string) preg_replace('/[\p{Cc}\p{Cf}\s]+/u', ' ', $text));
    }
}
