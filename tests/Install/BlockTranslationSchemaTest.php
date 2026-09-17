<?php

declare(strict_types=1);

namespace Tests\Install;

use App\Database;
use App\Service\Blocks\BlockDefinitions;
use PHPUnit\Framework\TestCase;

/**
 * The block_translations table as the migrations left it in the test database
 * (db/migrations/20260917160000, docs/multilingual/ARCHITECTURE.md), and the
 * rule that binds it to the block registry: a block that declares
 * translatable fields keeps no `_nl`/`_en` column of its own any more, so
 * there is never a block whose words live in two places.
 *
 * Reads information_schema only.
 */
final class BlockTranslationSchemaTest extends TestCase
{
    public function testTheTableHasTheAgreedShape(): void
    {
        $columns = [];
        foreach ($this->query(
            'SELECT column_name AS name, column_type AS type, is_nullable AS nullable, character_set_name AS charset, collation_name AS collation
               FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = ?
              ORDER BY ordinal_position',
            ['block_translations']
        ) as $row) {
            $columns[$row['name']] = $row;
        }

        self::assertSame(
            ['id', 'owner_table', 'owner_id', 'language_code', 'field', 'value', 'created_at', 'updated_at'],
            array_keys($columns),
            'one row per field per language per owner, and nothing else'
        );

        foreach (['owner_table', 'language_code', 'field'] as $identifier) {
            self::assertSame('ascii', $columns[$identifier]['charset'], $identifier);
            self::assertSame('ascii_bin', $columns[$identifier]['collation'], $identifier);
            self::assertSame('NO', $columns[$identifier]['nullable'], $identifier);
        }

        self::assertSame('varchar(12)', $columns['language_code']['type'], 'the same type as site_languages.code');
        self::assertSame('int unsigned', $columns['owner_id']['type'], 'the same type as every content table id');
        self::assertSame('mediumtext', $columns['value']['type'], 'room for a long rich-text body');
        self::assertSame('NO', $columns['value']['nullable'], 'a field without words has no row, not a NULL');
    }

    public function testOneFieldPerLanguagePerOwnerIsUnique(): void
    {
        $unique = $this->query(
            "SELECT GROUP_CONCAT(column_name ORDER BY seq_in_index) AS columns
               FROM information_schema.statistics
              WHERE table_schema = DATABASE() AND table_name = 'block_translations' AND non_unique = 0 AND index_name <> 'PRIMARY'
              GROUP BY index_name"
        );

        self::assertSame([['columns' => 'owner_table,owner_id,language_code,field']], $unique);
    }

    public function testTheLanguageIsAForeignKeyThatRefusesToDeleteWordsAndTheOwnerIsNot(): void
    {
        $keys = $this->query(
            "SELECT k.column_name AS column_name, k.referenced_table_name AS target, k.referenced_column_name AS target_column, r.delete_rule AS on_delete
               FROM information_schema.key_column_usage k
               JOIN information_schema.referential_constraints r
                 ON r.constraint_schema = k.constraint_schema AND r.constraint_name = k.constraint_name
              WHERE k.table_schema = DATABASE() AND k.table_name = 'block_translations'"
        );

        self::assertSame(
            [['column_name' => 'language_code', 'target' => 'site_languages', 'target_column' => 'code', 'on_delete' => 'RESTRICT']],
            $keys,
            'owner_id is polymorphic and has no foreign key; BlockLocalization guards it instead'
        );
    }

    public function testABlockOnPerLanguageStorageHasNoFixedLanguageColumnsLeft(): void
    {
        foreach (BlockDefinitions::all() as $type => $definition) {
            foreach (array_keys($definition->translatableFields()) as $table) {
                $legacy = $this->query(
                    "SELECT column_name AS name FROM information_schema.columns
                      WHERE table_schema = DATABASE() AND table_name = ?
                        AND (column_name LIKE '%\\_nl' OR column_name LIKE '%\\_en')",
                    [$table]
                );

                self::assertSame([], $legacy, "{$type} declares its words in block_translations, but {$table} still has fixed language columns");
            }
        }

        self::assertNotEmpty($this->query("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'block_translations'"));
    }

    /**
     * @param list<mixed> $parameters
     * @return list<array<string, mixed>>
     */
    private function query(string $sql, array $parameters = []): array
    {
        $statement = Database::connection()->prepare($sql);
        $statement->execute($parameters);

        return array_map(
            static fn (array $row): array => array_change_key_case($row, CASE_LOWER),
            $statement->fetchAll()
        );
    }
}
