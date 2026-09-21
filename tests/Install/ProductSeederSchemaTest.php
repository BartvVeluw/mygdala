<?php

declare(strict_types=1);

namespace Tests\Install;

use App\Database;
use PHPUnit\Framework\TestCase;

/**
 * Every column the test-product seed writes is a column this schema has.
 *
 * WHY THIS TEST EXISTS. README.md tells a developer to run
 * `phinx seed:run` for a few test products. Multilingual 2.0 phase 5 moved a
 * product's name and description to product_translations and dropped
 * products.name, name_en, description and description_en, but the seed kept
 * writing them, so the one command the README names failed on every
 * installation from then on, and nothing ran the seed to notice. Phase 7
 * rewrote it to write its words in the default language.
 *
 * The seed is only ever run by hand, so this test reads it instead: each
 * `INSERT INTO <table> (<columns>)` it prepares is checked against the
 * database the suite runs on.
 */
final class ProductSeederSchemaTest extends TestCase
{
    public function testEveryColumnTheSeedWritesExists(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/db/seeds/ProductSeeder.php');

        preg_match_all('/INSERT INTO (\w+) \(([^)]*)\)/', $source, $inserts, PREG_SET_ORDER);
        self::assertNotEmpty($inserts, 'the seed prepares its INSERT statements with written-out column lists');

        $db = Database::connection();
        foreach ($inserts as [, $table, $columnList]) {
            $known = array_column($db->query('SHOW COLUMNS FROM `' . $table . '`')->fetchAll(\PDO::FETCH_ASSOC), 'Field');
            foreach (array_map('trim', explode(',', $columnList)) as $column) {
                self::assertContains($column, $known, $table . '.' . $column . ' is written by db/seeds/ProductSeeder.php');
            }
        }
    }

    public function testTheSeedWritesItsWordsAsTranslationsNotAsProductColumns(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/db/seeds/ProductSeeder.php');

        self::assertStringContainsString('INSERT INTO product_translations', $source);
        self::assertStringNotContainsString("'name_en'", $source);
        self::assertStringNotContainsString("'description_en'", $source);
    }
}
