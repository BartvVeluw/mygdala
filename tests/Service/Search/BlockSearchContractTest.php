<?php

declare(strict_types=1);

namespace Tests\Service\Search;

use App\Module\ModuleRegistry;
use App\Service\Blocks\BlockDefinitions;
use App\Service\Blocks\BlockSearchRole;
use App\Service\Search\SearchText;
use App\Service\SectionRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Search 2.0 without a database (SEARCH.md "De tekst van de blokken"):
 *
 *   - every block classifies every translatable field it declares for the
 *     site search (BlockDefinition::searchFields()), so a new block has to
 *     decide and Search never needs to know it;
 *   - the words a visitor does not look for are never offered (alt texts,
 *     button and link labels, anchor labels), and a decorative or dynamic
 *     block offers nothing;
 *   - Search names no block type and no block table;
 *   - every place that writes a placed block's words keeps the index current;
 *   - the ranking order and the excerpt choice.
 */
final class BlockSearchContractTest extends TestCase
{
    /** Blocks without words of their own: nothing to find them by. */
    private const NO_OWN_WORDS = ['spacer', 'quicknav', 'shop_collections', 'product_grid', 'media_banner', 'project_images'];

    protected function setUp(): void
    {
        ModuleRegistry::overrideForTests(['shop' => true, 'personalization' => true, 'portfolio' => true, 'blog' => true, 'multilingual' => true, 'articles' => true]);
        BlockDefinitions::reset();
        SectionRegistry::reset();
    }

    protected function tearDown(): void
    {
        ModuleRegistry::overrideForTests(null);
        BlockDefinitions::reset();
        SectionRegistry::reset();
    }

    public function testEveryBlockClassifiesEveryFieldItDeclares(): void
    {
        foreach (BlockDefinitions::all() as $type => $definition) {
            $declared = [];
            foreach ($definition->translatableFields() as $table => $fields) {
                foreach ($fields as $field) {
                    $declared[$table][] = $field->key;
                }
            }

            $classified = array_map('array_keys', $definition->searchFields());
            ksort($declared);
            ksort($classified);
            $this->assertSame($declared, $classified, $type . ': searchFields() must name every translatable field, and nothing else');

            foreach ($definition->searchFields() as $table => $roles) {
                foreach ($roles as $field => $role) {
                    $this->assertContains($role, BlockSearchRole::ALL, "{$type} {$table}.{$field}");
                }
            }
        }
    }

    public function testWordsNobodyLooksForAreNeverOffered(): void
    {
        foreach (BlockDefinitions::all() as $type => $definition) {
            foreach ($definition->searchFields() as $table => $roles) {
                foreach ($roles as $field => $role) {
                    if (preg_match('/(^|_)(alt|button_label|link_label|cta_label|nav_label|primary_label|secondary_label|source_label|number_label)$/', $field) === 1) {
                        $this->assertSame(BlockSearchRole::NONE, $role, "{$type} {$table}.{$field} is not something a visitor searches for");
                    }
                }
            }
        }

        foreach (self::NO_OWN_WORDS as $type) {
            $definition = BlockDefinitions::get($type);
            $this->assertNotNull($definition, $type);
            $offered = array_filter(array_merge([], ...array_values($definition->searchFields())), static fn (string $role): bool => $role !== BlockSearchRole::NONE);
            $this->assertSame([], $offered, $type . ' has no words of its own to offer');
        }

        $this->assertTrue(BlockDefinitions::get('spacer')->isDecorative());
    }

    public function testTheBlocksSayWhatTheirHeadingsAre(): void
    {
        $this->assertSame(BlockSearchRole::HEADING, BlockDefinitions::get('faq')->searchFields()['faq_items']['question']);
        $this->assertSame(BlockSearchRole::TEXT, BlockDefinitions::get('faq')->searchFields()['faq_items']['answer']);
        $this->assertSame(BlockSearchRole::HEADING, BlockDefinitions::get('cta_band')->searchFields()['cta_bands']['title']);
        $this->assertSame(BlockSearchRole::TEXT, BlockDefinitions::get('rich_text')->searchFields()['rich_text_sections']['body']);
        $this->assertSame(BlockSearchRole::NONE, BlockDefinitions::get('detail_section')->searchFields()['detail_sections']['label'], 'shown in one label mode only');
    }

    public function testSearchNamesNoBlockTypeAndNoBlockTable(): void
    {
        $types = BlockDefinitions::types();
        $tables = [];
        foreach (BlockDefinitions::all() as $definition) {
            $tables = array_merge($tables, array_keys($definition->translatableFields()));
        }

        foreach (glob(dirname(__DIR__, 3) . '/src/Service/Search/*.php') ?: [] as $file) {
            $code = (string) file_get_contents($file);
            foreach (array_unique([...$types, ...$tables]) as $name) {
                $this->assertDoesNotMatchRegularExpression("/['\"]" . preg_quote($name, '/') . "['\"]/", $code, basename($file) . ' names ' . $name);
            }
        }
    }

    public function testEveryWriterOfAPlacedBlocksWordsKeepsTheIndexCurrent(): void
    {
        $root = dirname(__DIR__, 3);

        // An endpoint that writes block words either ends in place() (which
        // reindexes) or reindexes itself.
        foreach (glob($root . '/api/admin/*.php') ?: [] as $file) {
            $code = (string) file_get_contents($file);
            if (!str_contains($code, 'BlockLocalization::save(')) {
                continue;
            }

            $this->assertTrue(
                str_contains($code, 'ContentBlockDrafts::place(') || str_contains($code, 'BlockSearchIndex::reindex'),
                basename($file) . ' writes block words without keeping the search index current'
            );
        }

        $place = (string) file_get_contents($root . '/src/Service/Blocks/ContentBlockDrafts.php');
        $this->assertStringContainsString('BlockSearchIndex::reindexSection($placed)', $place);
        $registry = (string) file_get_contents($root . '/src/Service/SectionRegistry.php');
        $this->assertStringContainsString('BlockSearchIndex::reindexSection($pageSection)', $registry, 'hide and show');

        // A service that places a block on a page itself reindexes it.
        // (App\Service\ProjectImagesPlacement places a block without words.)
        foreach (['src/Service/Blog/BlogContentConversion.php', 'api/admin/add-page-section.php'] as $relative) {
            $this->assertStringContainsString('BlockSearchIndex::reindex', (string) file_get_contents($root . '/' . $relative), $relative);
        }
    }

    public function testTheOrderOfTheWords(): void
    {
        $q = SearchText::fold('glasgraveren');
        $levels = [
            'exact title' => SearchText::score($q, 'Glasgraveren', ''),
            'title' => SearchText::score($q, 'Over glasgraveren hier', ''),
            'intro' => SearchText::score($q, 'Werkplaats', 'Wij doen glasgraveren.'),
            'content heading' => SearchText::score($q, 'Werkplaats', 'Intro', [], 'Glasgraveren op maat', 'Glasgraveren op maat en meer.'),
            'content' => SearchText::score($q, 'Werkplaats', 'Intro', [], 'Andere kop', 'Ook glasgraveren kan.'),
        ];

        $this->assertSame(array_values($levels), array_values(array_unique($levels)), 'each level its own score');
        $sorted = $levels;
        arsort($sorted);
        $this->assertSame(array_keys($levels), array_keys($sorted));
        $this->assertSame(SearchText::SCORE_CONTENT_HEADING, $levels['content heading']);
        $this->assertSame(SearchText::SCORE_CONTENT, $levels['content']);

        // Loose terms count in the content too, and stay below every phrase level.
        $terms = [SearchText::fold('glas'), SearchText::fold('hout')];
        $loose = SearchText::score(SearchText::fold('glas hout'), 'Werkplaats', 'Over glas', $terms, '', 'En ook hout.');
        $this->assertGreaterThan(0, $loose);
        $this->assertLessThan(SearchText::SCORE_CONTENT, $loose);
        $this->assertSame(0, SearchText::score(SearchText::fold('glas hout'), 'Werkplaats', 'Over glas', $terms));
    }

    public function testTheExcerptComesFromWhereTheWordsAre(): void
    {
        $q = SearchText::fold('aluminium');
        $intro = 'Een korte introductie van de werkplaats.';
        $content = str_repeat('Vulling over iets anders. ', 20) . 'Verschillende materialen zoals hout, glas en aluminium kunnen worden gegraveerd. ' . str_repeat('Meer tekst. ', 20);

        $excerpt = SearchText::bestExcerpt([$intro, $content], $q);
        $this->assertStringContainsString('aluminium', $excerpt);
        $this->assertStringStartsWith('…', $excerpt, 'cut around the match, not the start');
        $this->assertLessThanOrEqual(SearchText::EXCERPT_LENGTH + 2, mb_strlen($excerpt));

        $this->assertSame($intro, SearchText::bestExcerpt([$intro, $content], SearchText::fold('zzz-nergens')), 'no match: the own text');
        $this->assertSame($intro, SearchText::bestExcerpt([$intro, 'aluminium'], SearchText::fold('introductie')), 'the own text first');
        $this->assertSame('Alleen inhoud.', SearchText::bestExcerpt(['', 'Alleen inhoud.'], $q));
    }
}
