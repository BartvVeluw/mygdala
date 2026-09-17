<?php

declare(strict_types=1);

namespace Tests\Install;

use PHPUnit\Framework\TestCase;

/**
 * A table name a migration says out loud must be a table this schema has.
 *
 * WHY THIS TEST EXISTS. 20260910140000_add_multilingual_language_settings
 * decides what to store by probing a map of tables for English content:
 *
 *     'navigation_items' => 'label_en'
 *     'homepage_heroes'  => 'title_en'
 *
 * Neither is a table of this schema; they are `nav_items` and
 * `homepage_hero`. The probe loop skips a table `hasTable()` does not know,
 * so both misses were silent, the migration concluded there was no English
 * content, and it stored the wrong answer. Nothing failed, nothing was
 * logged, and the mistake only surfaced during a real rollout.
 *
 * A misspelled table name inside a guarded lookup is invisible at runtime by
 * construction, which is exactly the kind of mistake a cheap source check
 * should catch instead. This one needs no database and no webserver: it
 * reads the migrations and compares the names they REFERENCE against the
 * names they CREATE, which together are the whole schema.
 *
 * WHAT COUNTS AS A REFERENCE. Two shapes, both of which the multilingual
 * migration uses:
 *
 *   - a literal `hasTable('some_table')`;
 *   - a `'some_table' => 'some_column_en'` map entry, the idiom this project
 *     uses to say "look in this column of this table".
 *
 * A name built at runtime is out of scope on purpose. This test is about
 * typos in names that are written down, which is where they hide.
 */
final class MigrationTableNamesTest extends TestCase
{
    /**
     * Wrong names that have already shipped and are deliberately left alone.
     *
     * 20260910140000 has run on real installations, so its recorded history
     * stays as it was and 20260911200000_correct_the_stored_content_languages
     * repairs the state it produced instead. Grandfathering them here rather
     * than deleting this test keeps the guard live for every OTHER name: a
     * new misspelling is not on this list and fails.
     *
     * Nothing should ever be added to this list. A new entry means a new
     * migration shipped with a table name that does not exist.
     */
    private const HISTORICAL_MISTAKES = [
        'navigation_items' => 'nav_items',
        'homepage_heroes' => 'homepage_hero',
    ];

    /**
     * Phinx column options share the `'key' => 'value'` shape of a table map,
     * and `['after' => 'title_en']` is not a reference to a table called
     * "after". These are the option names this project actually passes.
     */
    private const COLUMN_OPTION_KEYS = [
        'after', 'before', 'default', 'comment', 'limit', 'null', 'update',
        'values', 'signed', 'identity', 'precision', 'scale', 'collation', 'encoding',
    ];

    public function testEveryTableNameAMigrationReferencesIsOneAMigrationCreates(): void
    {
        $created = self::tablesCreated();
        $unknown = [];

        foreach (self::tablesReferenced() as $table => $where) {
            if (isset($created[$table]) || isset(self::HISTORICAL_MISTAKES[$table])) {
                continue;
            }

            $unknown[] = $table . ' (' . implode(', ', array_unique($where)) . ')';
        }

        $this->assertSame(
            [],
            $unknown,
            "A migration names a table that no migration creates. A guarded lookup on a name\n"
            . "like this fails silently, so check the spelling against the migration that\n"
            . "creates the table:\n  " . implode("\n  ", $unknown)
        );
    }

    public function testTheTablesTheMisspelledProbesMeantAreRealTables(): void
    {
        $created = self::tablesCreated();

        foreach (self::HISTORICAL_MISTAKES as $wrong => $right) {
            $this->assertArrayHasKey(
                $right,
                $created,
                "{$right} is what {$wrong} was meant to say, so it has to be a table this schema creates."
            );
            $this->assertArrayNotHasKey(
                $wrong,
                $created,
                "{$wrong} is now a real table, so it is no longer a mistake and belongs off the list."
            );
        }
    }

    public function testEveryGrandfatheredMistakeIsStillInTheMigrations(): void
    {
        $referenced = self::tablesReferenced();

        foreach (array_keys(self::HISTORICAL_MISTAKES) as $wrong) {
            $this->assertArrayHasKey(
                $wrong,
                $referenced,
                "No migration says {$wrong} any more, so drop it from HISTORICAL_MISTAKES "
                . 'rather than leaving a permanent exemption behind.'
            );
        }
    }

    // ------------------------------------------------------------ internals

    /** @return array<string, true> every table name the migrations create */
    private static function tablesCreated(): array
    {
        $created = [];

        foreach (self::migrationSources() as $source) {
            foreach ([
                '/\$this->table\(\s*\'([a-z0-9_]+)\'/',
                '/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?([a-z0-9_]+)`?/i',
                '/RENAME\s+TABLE\s+`?[a-z0-9_]+`?\s+TO\s+`?([a-z0-9_]+)`?/i',
            ] as $pattern) {
                if (preg_match_all($pattern, $source, $matches) === 0) {
                    continue;
                }

                foreach ($matches[1] as $table) {
                    $created[$table] = true;
                }
            }

            // `private const TABLE = 'site_languages'` with
            // `$this->table(self::TABLE, …)->create()`: the name is written
            // down once, in the constant, and created through it. The chain
            // may not reach into a later `$this->` statement.
            if (
                preg_match('/const\s+TABLE\s*=\s*\'([a-z0-9_]+)\'/', $source, $constant) === 1
                && preg_match('/\$this->table\(\s*self::TABLE\b(?:(?!\$this->)[\s\S])*?->create\(\)/', $source) === 1
            ) {
                $created[$constant[1]] = true;
            }
        }

        return $created;
    }

    /** @return array<string, list<string>> table name => the files that name it */
    private static function tablesReferenced(): array
    {
        $referenced = [];

        foreach (self::migrationSources() as $file => $source) {
            if (preg_match_all('/hasTable\(\s*\'([a-z0-9_]+)\'\s*\)/', $source, $matches) > 0) {
                foreach ($matches[1] as $table) {
                    $referenced[$table][] = $file . ', hasTable()';
                }
            }

            if (preg_match_all('/\'([a-z][a-z0-9_]*)\'\s*=>\s*\'([a-z][a-z0-9_]*_(?:en|nl))\'/', $source, $matches) > 0) {
                foreach ($matches[1] as $index => $table) {
                    if (in_array($table, self::COLUMN_OPTION_KEYS, true)) {
                        continue;
                    }

                    $referenced[$table][] = $file . ', probes ' . $matches[2][$index];
                }
            }
        }

        ksort($referenced);

        return $referenced;
    }

    /** @return array<string, string> file name => source */
    private static function migrationSources(): array
    {
        $sources = [];

        foreach (glob(dirname(__DIR__, 2) . '/db/migrations/*.php') ?: [] as $path) {
            $sources[basename($path)] = (string) file_get_contents($path);
        }

        self::assertNotSame([], $sources, 'No migrations were found to read.');

        return $sources;
    }
}
