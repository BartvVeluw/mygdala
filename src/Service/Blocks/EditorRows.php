<?php

declare(strict_types=1);

namespace App\Service\Blocks;

/**
 * The rows of ONE list inside ONE block-editor form: how a list of child items
 * (a carousel's cards, a card's tags) travels in the same POST as the block's
 * own fields, so a single "Opslaan" stores all of it (PAGE-EDITOR.md, "Eén
 * formulier per blok-editor").
 *
 * THE SHAPE ON THE WIRE. Each row is `<list>[<key>][<field>]`. The key is the
 * row's id for a stored row ("12") and "new<n>" for a row typed on the screen
 * ("new0"); the browser sends the rows in the order they are on screen, and
 * that order is the order that is stored. A stored row that is not sent is
 * one the editor removed — but only when the form says the list was on it at
 * all (a hidden `<list>_present`), so a form without the list can never empty
 * it.
 *
 * ACTIONS ARE NOT SAVES OF THEIR OWN. ↑, ↓ and × are submit buttons of the
 * same form, `editor_action=<list>:<verb>:<key>`. With JavaScript
 * (admin/assets/row-list.js) they move or remove the row on screen and send
 * nothing; without it they submit the whole form, and apply() performs the
 * move or removal on the rows that came along — so whatever else was typed is
 * validated and stored with it, never dropped. A screen may add verbs of its
 * own ('add', 'edit'); apply() leaves those to the endpoint.
 *
 * Pure: no database, no session, no request. The endpoint decides which ids
 * are really children of the block it is saving; a key naming anybody else's
 * row is not this class's to trust.
 */
final class EditorRows
{
    /** The verbs apply() performs. */
    public const VERBS = ['up', 'down', 'remove'];

    /** A key: a stored row's id, or "new" and a number for a row typed on screen. */
    private const KEY = '/^(?:[1-9][0-9]{0,9}|new[0-9]{1,4})$/';

    /**
     * The rows of one list as posted, in their order, each with its key, its
     * id (0 for a new row) and its fields as trimmed strings. Anything that is
     * not a row of that shape is left out.
     *
     * @return list<array{key: string, id: int, fields: array<string, string>}>
     */
    public static function fromPost(mixed $posted): array
    {
        if (!is_array($posted)) {
            return [];
        }

        $rows = [];
        foreach ($posted as $key => $fields) {
            $key = (string) $key;
            if (!preg_match(self::KEY, $key) || !is_array($fields)) {
                continue;
            }

            $clean = [];
            foreach ($fields as $name => $value) {
                if (is_string($name) && is_scalar($value)) {
                    $clean[$name] = trim((string) $value);
                }
            }

            $rows[] = ['key' => $key, 'id' => self::idOf($key), 'fields' => $clean];
        }

        return $rows;
    }

    /**
     * `<list>:<verb>[:<key>]`, or null for no action (a plain save) or
     * anything malformed.
     *
     * @return array{list: string, verb: string, key: string}|null
     */
    public static function parseAction(mixed $raw): ?array
    {
        if (!is_string($raw) || !preg_match('/^([a-z_]{1,32}):([a-z_]{1,16})(?::([a-z0-9]{1,14}))?$/', $raw, $match)) {
            return null;
        }

        return ['list' => $match[1], 'verb' => $match[2], 'key' => $match[3] ?? ''];
    }

    /**
     * The rows after $action, when it is an up/down/remove of a row of $list;
     * the rows unchanged otherwise. Moving the first row up or the last row
     * down changes nothing.
     *
     * @param list<array{key: string, id: int, fields: array<string, string>}> $rows
     * @param array{list: string, verb: string, key: string}|null $action
     * @return list<array{key: string, id: int, fields: array<string, string>}>
     */
    public static function apply(array $rows, ?array $action, string $list): array
    {
        if ($action === null || $action['list'] !== $list || !in_array($action['verb'], self::VERBS, true)) {
            return $rows;
        }

        $index = null;
        foreach ($rows as $i => $row) {
            if ($row['key'] === $action['key']) {
                $index = $i;
                break;
            }
        }

        if ($index === null) {
            return $rows;
        }

        if ($action['verb'] === 'remove') {
            array_splice($rows, $index, 1);

            return array_values($rows);
        }

        $other = $action['verb'] === 'up' ? $index - 1 : $index + 1;
        if ($other < 0 || $other >= count($rows)) {
            return $rows;
        }

        [$rows[$index], $rows[$other]] = [$rows[$other], $rows[$index]];

        return $rows;
    }

    /** The stored id a key names, 0 for a new row. */
    public static function idOf(string $key): int
    {
        return ctype_digit($key) ? (int) $key : 0;
    }
}
