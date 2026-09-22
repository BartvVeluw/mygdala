<?php

declare(strict_types=1);

namespace App\Service\Blocks;

use App\Service\Language\AdminTranslator;

/**
 * ONE list of translated child rows (a FAQ's questions, a Detailsectie's
 * points, a Tekst-met-afbeelding's images) inside ONE block-editor form, as
 * its one "Opslaan" posts it: validated with the block's own fields, handed
 * back whole when the save is refused, and stored inside the endpoint's
 * transaction (PAGE-EDITOR.md, "Eén formulier per blok-editor").
 *
 * The wire shape is App\Service\Blocks\EditorRows': `<list>[<key>][<field>]`
 * in screen order, `<list>_present` to say the list was on the form at all,
 * and ↑/↓ without JavaScript as `editor_action=<list>:up|down:<key>`. On top
 * of that, the rules every such list shares:
 *
 *  - REMOVING is a mark, `<list>[<key>][remove]`: the row stays on screen,
 *    greyed, until the save, and a refused save shows it still marked. A
 *    stored row that is not posted at all was not on the screen (added in
 *    another tab meanwhile) and keeps its place after the rows that were.
 *  - A NEW ROW is written in the website's default language, whatever
 *    language the screen shows, like a new page; so its words are checked as
 *    default-language words. A new row with nothing filled in is no row: the
 *    empty row a screen offers without JavaScript costs nothing.
 *  - A STORED ROW is written in the language on screen only, and keeps its
 *    id, so its other languages stay with it. Its words are required only in
 *    the default language (the block's TranslatableField declarations).
 *  - Its words go before the row when it is removed
 *    (BlockLocalization::deleteOwner()), in the same transaction.
 *
 * What a row has besides its words (a switch, an icon, a media item) is the
 * endpoint's: it checks those in `$extra`, and writes them in the `$create`
 * and `$update` it hands to save(). The endpoint also decides which ids are
 * really children of its block, by passing $storedIds; a key naming any
 * other row is dropped here.
 */
final class EditorChildList
{
    /** The fields every row sends and no editor types: they never make a new row "filled in". */
    private const MARKERS = ['active', 'remove', 'present'];

    /**
     * @param list<int> $storedIds the ids of the block's own rows of this list, in stored order
     * @param list<array{key: string, id: int, fields: array<string, string>}> $rows
     * @param list<string> $preset fields a new row arrives with before anything is typed
     */
    private function __construct(
        public readonly string $list,
        public readonly string $table,
        private readonly array $storedIds,
        public readonly bool $posted,
        private readonly array $rows,
        private readonly array $preset
    ) {
    }

    /**
     * @param array<string, mixed> $post the request's $_POST
     * @param list<int> $storedIds
     * @param array{list: string, verb: string, key: string}|null $action EditorRows::parseAction()
     * @param list<string> $preset fields a new row is sent with before anything is typed (a select
     *        with a first choice, such as an icon): they do not make an empty new row a row
     */
    public static function fromRequest(array $post, string $list, string $table, array $storedIds, ?array $action, array $preset = []): self
    {
        $rows = array_values(array_filter(
            EditorRows::fromPost($post[$list] ?? []),
            static fn (array $row): bool => $row['id'] === 0 || in_array($row['id'], $storedIds, true)
        ));

        return new self(
            $list,
            $table,
            array_values(array_map('intval', $storedIds)),
            isset($post[$list . '_present']),
            EditorRows::apply($rows, $action, $list),
            array_values($preset)
        );
    }

    /**
     * The rows as posted, in their order, after a no-JavaScript move.
     *
     * @return list<array{key: string, id: int, fields: array<string, string>}>
     */
    public function rows(): array
    {
        return $this->rows;
    }

    /** Whether a row's checkbox or flag field (`active`, `remove`) was sent. */
    public static function flag(array $row, string $field): bool
    {
        return ($row['fields'][$field] ?? '') !== '';
    }

    /** A new row with nothing typed or chosen in it: skipped, not refused. */
    public function isBlank(array $row): bool
    {
        if ($row['id'] !== 0) {
            return false;
        }

        foreach ($row['fields'] as $name => $value) {
            if (!in_array($name, self::MARKERS, true) && !in_array($name, $this->preset, true) && $value !== '') {
                return false;
            }
        }

        return true;
    }

    /** Whether the row is on its way out: marked, or new and empty. */
    public function isDropped(array $row): bool
    {
        return self::flag($row, 'remove') || $this->isBlank($row);
    }

    /** How many rows remain after this save: the ones kept on screen plus stored ones that were not on it. */
    public function keptCount(): int
    {
        if (!$this->posted) {
            return count($this->storedIds);
        }

        $count = 0;
        $seen = [];
        foreach ($this->rows as $row) {
            if ($row['id'] > 0) {
                $seen[] = $row['id'];
            }
            if (!$this->isDropped($row)) {
                $count++;
            }
        }

        return $count + count(array_diff($this->storedIds, $seen));
    }

    /**
     * The words of one row: every field the list's table declares, as typed.
     *
     * @return array<string, string>
     */
    public function words(array $row): array
    {
        $words = [];
        foreach (array_keys(BlockLocalization::fields($this->table)) as $field) {
            $words[$field] = $row['fields'][$field] ?? '';
        }

        return $words;
    }

    /**
     * What is wrong with the rows, per field, as the CMS message: the key is
     * `<list>.<row key>.<field>`, which the screen prints next to that field.
     * Rows on their way out are not checked.
     *
     * @param callable(array{key: string, id: int, fields: array<string, string>}): array<string, string>|null $extra
     *        the endpoint's own checks of a row's other fields: field => message
     * @return array<string, string>
     */
    public function problems(string $languageCode, ?callable $extra = null): array
    {
        if (!$this->posted) {
            return [];
        }

        $defaultLanguage = BlockLocalization::defaultLanguage();
        $errors = [];

        foreach ($this->rows as $row) {
            if ($this->isDropped($row)) {
                continue;
            }

            $language = $row['id'] === 0 ? $defaultLanguage : $languageCode;
            $errors += self::wordErrors($this->table, $language, $this->words($row), $this->list . '.' . $row['key'] . '.');

            foreach ($extra === null ? [] : $extra($row) as $field => $message) {
                $errors[$this->list . '.' . $row['key'] . '.' . $field] = $message;
            }
        }

        return $errors;
    }

    /**
     * What is wrong with one owner's words, per field, as the CMS message:
     * the block's own fields as well as a row's. The key is $prefix and the
     * field.
     *
     * @param array<string, string> $words
     * @return array<string, string>
     */
    public static function wordErrors(string $table, string $languageCode, array $words, string $prefix = ''): array
    {
        $errors = [];
        foreach (BlockLocalization::problems($table, $languageCode, $words) as $field => $problem) {
            $errors[$prefix . $field] = AdminTranslator::trans(
                $problem === TranslatableField::MISSING ? 'validation.veld_verplicht' : 'validation.text_too_long'
            );
        }

        return $errors;
    }

    /**
     * One line per row with a problem, for the list at the top of the screen:
     * "<noun> <place>: <message>", the place counted as on screen.
     *
     * @param array<string, string> $fieldErrors from problems()
     * @return list<string>
     */
    public function summary(array $fieldErrors, string $noun): array
    {
        $lines = [];
        foreach ($this->rows as $position => $row) {
            $prefix = $this->list . '.' . $row['key'] . '.';
            foreach ($fieldErrors as $key => $message) {
                if (str_starts_with($key, $prefix)) {
                    $line = $noun . ' ' . ($position + 1) . ': ' . $message;
                    if (!in_array($line, $lines, true)) {
                        $lines[] = $line;
                    }
                }
            }
        }

        return $lines;
    }

    /**
     * The rows exactly as posted, for a refused save to hand back: the screen
     * shows them again in this order, with their marks and their new rows.
     *
     * @return list<array{key: string, fields: array<string, string>}>
     */
    public function old(): array
    {
        return array_map(
            static fn (array $row): array => ['key' => $row['key'], 'fields' => $row['fields']],
            $this->rows
        );
    }

    /**
     * Store the list, inside the transaction the endpoint has open: removed
     * rows with their words in every language, new rows with their words in
     * the default language, every other row with its words in $languageCode,
     * and then the order. A form without the list changes nothing.
     *
     * @param callable(array{key: string, id: int, fields: array<string, string>}): int $create inserts the row, returns its id
     * @param callable(int, array{key: string, id: int, fields: array<string, string>}): void $update writes what the row has besides words
     * @param callable(int): void $delete deletes the row
     * @param callable(list<int>): void $reorder stores the order of the block's rows
     * @return array<string, int> the id each new row's key was given
     */
    public function save(string $languageCode, callable $create, callable $update, callable $delete, callable $reorder): array
    {
        if (!$this->posted) {
            return [];
        }

        $defaultLanguage = BlockLocalization::defaultLanguage();
        $order = [];
        $seen = [];
        $created = [];

        foreach ($this->rows as $row) {
            if ($row['id'] === 0) {
                if ($this->isDropped($row)) {
                    continue;
                }

                $id = $create($row);
                BlockLocalization::save($this->table, $id, $defaultLanguage, $this->words($row));
                $created[$row['key']] = $id;
                $order[] = $id;
                continue;
            }

            $seen[] = $row['id'];

            if (self::flag($row, 'remove')) {
                // Its words first: once the row is gone, nothing finds them.
                BlockLocalization::deleteOwner($this->table, $row['id']);
                $delete($row['id']);
                continue;
            }

            $update($row['id'], $row);
            BlockLocalization::save($this->table, $row['id'], $languageCode, $this->words($row));
            $order[] = $row['id'];
        }

        // A row that was not on the screen at all keeps its place after the ones that were.
        foreach ($this->storedIds as $id) {
            if (!in_array($id, $seen, true)) {
                $order[] = $id;
            }
        }

        $reorder($order);

        return $created;
    }
}
