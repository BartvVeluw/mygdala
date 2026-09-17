<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Module\ModuleRegistry;
use App\Service\Blocks\BlockDefinition;
use App\Service\Blocks\BlockDefinitions;
use App\Service\Blocks\BlockSamples;
use App\Service\ItemGalleryContent;
use PHPUnit\Framework\TestCase;

/**
 * The sample content the Contentblokken library previews every block with,
 * and the promise behind it: the preview IS the block, with other words in
 * it.
 *
 * Checked over EVERY registered type, like BlockPresentationTest, so a new
 * block is held to it the moment it is registered:
 *
 *   - it has a sample, or it is the one declared exception;
 *   - its sample renders through the same partial render() calls, and hands
 *     that partial every key it reads (a missing key is a warning, and a
 *     warning fails here);
 *   - every sample word is escaped exactly like stored content;
 *   - the words name no site, no company and no way to reach anyone, and
 *     every link points inside the preview;
 *   - a sample is built from BlockSamples alone, never from a Content class,
 *     a repository, the settings or the request;
 *   - nothing on the public side ever reaches a sample.
 *
 * The preview document itself is Tests\Service\BlockPreviewContractTest; the
 * guard, the headers and "nothing is written" over real HTTP are
 * Tests\Service\BlockPreviewAccessTest. No database and no web server here.
 */
final class BlockSampleContractTest extends TestCase
{
    /**
     * The blocks the library cannot show with sample content, and why. Adding
     * one is a decision to write down here, not something that happens
     * because a definition forgot sampleContent().
     *
     * @var array<string, string>
     */
    private const WITHOUT_SAMPLE = [
        'product_grid' => 'its cards are drawn by assets/js/shop/shop.js from the live catalogue, not by its partial',
    ];

    /**
     * Words of the site this CMS grew out of, and of what it sells. A sample
     * shows on every installation, so none of them belongs in one.
     */
    private const SITE_WORDS = [
        'veluw', 'laser', 'graveer', 'graveren', 'gravure', 'acryl', 'nijmegen', 'mopa',
        'werkplaats', 'offerte', 'sleutelhanger',
    ];

    /** A price, a promise or a sales pitch, as patterns over lowercase text. */
    private const CLAIMS = [
        '/€|\beur\b|\beuro/u', '/\bgratis\b|\bfree\b/u', '/korting|discount/u', '/garantie|guarantee/u',
        '/\bbeste\b|\bbest\b|nummer 1|number one|#1/u', '/\d+\s*%/u',
    ];

    /**
     * Copy of one particular site that a PARTIAL could print around a sample:
     * the name, the city and the machines of the site this CMS grew out of,
     * and the contact promises its contact card once hardcoded. Phrases and
     * names, deliberately not ordinary words, so a generic label never trips
     * it. Matched case-insensitively against the text a visitor would read or
     * hear, not against class names.
     */
    private const RENDERED_SITE_COPY = [
        'van veluw', 'vanveluw', 'laserdesign', 'lasergravure', 'nijmegen', 'mopa',
        'werkplaats', 'ophalen op afspraak', 'pickup by appointment',
        'reactietijd', 'binnen enkele werkdagen', 'within a few business days',
        'logo of ontwerp', 'logo or design',
    ];

    /** The attributes that carry words (the language switch swaps these). */
    private const WORD_ATTRIBUTES = [
        'alt', 'title', 'placeholder', 'aria-label',
        'data-nl', 'data-en', 'data-nl-alt', 'data-en-alt', 'data-nl-aria', 'data-en-aria',
        'data-nl-placeholder', 'data-en-placeholder', 'data-form-success-nl', 'data-form-success-en',
    ];

    private const ESCAPE_MARKER = '<b data-sample-escape="1">';

    protected function setUp(): void
    {
        // Every module on, so every block a module can bring is checked.
        ModuleRegistry::overrideForTests(['shop' => true, 'portfolio' => true, 'blog' => true, 'personalization' => true]);
    }

    protected function tearDown(): void
    {
        ModuleRegistry::overrideForTests(null);
        restore_error_handler();
        parent::tearDown();
    }

    /** @return array<string, array{0: string}> */
    public static function registeredTypes(): array
    {
        ModuleRegistry::overrideForTests(['shop' => true, 'portfolio' => true, 'blog' => true, 'personalization' => true]);

        $cases = [];
        foreach (BlockDefinitions::types() as $type) {
            $cases[$type] = [$type];
        }

        ModuleRegistry::overrideForTests(null);

        return $cases;
    }

    private static function definition(string $type): BlockDefinition
    {
        $definition = BlockDefinitions::get($type);
        self::assertNotNull($definition, $type);

        return $definition;
    }

    /**
     * Renders a sample with every warning turned into a failure: a partial
     * reading a key the sample does not have is exactly the drift this file
     * exists to catch.
     *
     * @param array<string, mixed> $sample
     */
    private static function render(BlockDefinition $definition, array $sample): string
    {
        set_error_handler(static function (int $level, string $message, string $file, int $line): never {
            throw new \ErrorException($message, 0, $level, $file, $line);
        });

        ob_start();

        try {
            $definition->renderSample($sample, $definition->type() . '-preview');

            return (string) ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        } finally {
            restore_error_handler();
        }
    }

    /** The source of one method of a definition, for the checks that read code. */
    private static function methodSource(BlockDefinition $definition, string $method): string
    {
        $reflection = new \ReflectionMethod($definition, $method);
        $lines = file((string) $reflection->getFileName());
        self::assertIsArray($lines);

        return implode('', array_slice($lines, $reflection->getStartLine() - 1, $reflection->getEndLine() - $reflection->getStartLine() + 1));
    }

    /**
     * Every string anywhere in a sample, the form's own words included.
     *
     * @param array<string, mixed> $sample
     *
     * @return list<string>
     */
    private static function strings(array $sample): array
    {
        $strings = [];

        array_walk_recursive($sample, static function (mixed $value) use (&$strings): void {
            if (is_string($value)) {
                $strings[] = $value;
            }

            // A block on per-language storage hands its partial one value per
            // field for every language (BlockSamples::localized()).
            if ($value instanceof \App\Service\Language\LocalizedValue) {
                foreach ($value->attributeValues() as $words) {
                    $strings[] = $words;
                }
            }
        });

        return $strings;
    }

    // --- Which blocks have a sample ---------------------------------------

    /**
     * @dataProvider registeredTypes
     */
    public function testEveryBlockHasASampleOrIsADeclaredException(string $type): void
    {
        $sample = self::definition($type)->sampleContent(new BlockSamples());

        if (array_key_exists($type, self::WITHOUT_SAMPLE)) {
            $this->assertNull($sample, "{$type} is declared without a sample, so it must not have one");

            return;
        }

        $this->assertIsArray($sample, "{$type} has no sample content; write sampleContent() or declare why not in WITHOUT_SAMPLE");
        $this->assertNotSame([], $sample);
    }

    public function testTheDeclaredExceptionsAreRealBlocks(): void
    {
        foreach (array_keys(self::WITHOUT_SAMPLE) as $type) {
            $this->assertTrue(BlockDefinitions::has($type), "WITHOUT_SAMPLE names {$type}, which is not registered");
        }
    }

    // --- The real partial --------------------------------------------------

    /**
     * @dataProvider registeredTypes
     */
    public function testEverySampleRendersSomethingAndMissesNoKeyItsPartialReads(string $type): void
    {
        $definition = self::definition($type);
        $sample = $definition->sampleContent(new BlockSamples());

        if ($sample === null) {
            $this->assertArrayHasKey($type, self::WITHOUT_SAMPLE);

            return;
        }

        $html = self::render($definition, $sample);

        $this->assertNotSame('', trim($html), "{$type}'s sample renders nothing, which would be an empty preview");
        $this->assertMatchesRegularExpression('/<(section|div|nav)\b/', $html, "{$type}'s sample renders no block element");
    }

    /**
     * The whole point of the preview: renderSample() calls the partial
     * render() calls, and nothing else writes markup. A definition that
     * drew its preview some other way would be the second implementation this
     * design refuses.
     *
     * @dataProvider registeredTypes
     */
    public function testASampleGoesThroughTheSamePartialAsTheStoredBlock(string $type): void
    {
        $definition = self::definition($type);

        if ($definition->sampleContent(new BlockSamples()) === null) {
            $this->assertArrayHasKey($type, self::WITHOUT_SAMPLE);

            return;
        }

        preg_match_all('/\brender_section_[a-z_]+(?=\()/', self::methodSource($definition, 'render'), $production);
        preg_match_all('/\brender_section_[a-z_]+(?=\()/', self::methodSource($definition, 'renderSample'), $preview);

        $this->assertNotSame([], $production[0], "{$type}::render() calls no partial");
        $this->assertSame(
            array_values(array_unique($production[0])),
            array_values(array_unique($preview[0])),
            "{$type}::renderSample() must call exactly the partial render() calls"
        );

        $sampleSource = self::methodSource($definition, 'renderSample');
        $this->assertStringNotContainsString('<', preg_replace('/\/\*.*?\*\/|\/\/[^\n]*/s', '', $sampleSource) ?? '', "{$type}::renderSample() writes markup of its own");
        $this->assertStringNotContainsString('echo', $sampleSource, "{$type}::renderSample() writes markup of its own");
    }

    // --- The words ---------------------------------------------------------

    /**
     * @dataProvider registeredTypes
     */
    public function testEverySampleWordIsEscapedLikeStoredContent(string $type): void
    {
        $definition = self::definition($type);
        $hostile = new BlockSamples(static fn (string $word): string => self::ESCAPE_MARKER . $word);
        $sample = $definition->sampleContent($hostile);

        if ($sample === null) {
            $this->assertArrayHasKey($type, self::WITHOUT_SAMPLE);

            return;
        }

        $html = self::render($definition, $sample);

        $this->assertStringNotContainsString(self::ESCAPE_MARKER, $html, "{$type} prints a sample word unescaped");

        // Rich text is markup by contract (sanitized, never escaped back to
        // text) and is not made of sample words, so a block of rich text
        // alone has nothing to prove here.
        $words = array_filter(self::strings($sample), static fn (string $value): bool => str_contains($value, self::ESCAPE_MARKER));
        if ($words === [] && !$this->hasForm($sample)) {
            return;
        }

        $this->assertStringContainsString(
            htmlspecialchars(self::ESCAPE_MARKER, ENT_QUOTES, 'UTF-8'),
            $html,
            "{$type} shows none of its sample words, so this test proves nothing about it"
        );
    }

    /** @param array<string, mixed> $sample */
    private function hasForm(array $sample): bool
    {
        return ($sample['form'] ?? null) instanceof \App\Service\Forms\FormDefinition;
    }

    /**
     * Only the sample's own words: a partial's fixed copy is the block's, and
     * shows on the live site as much as in the preview.
     */
    public function testTheWordsNameNoSiteNoCompanyNoPriceAndNobodyToReach(): void
    {
        foreach (BlockDefinitions::all() as $type => $definition) {
            $sample = $definition->sampleContent(new BlockSamples());
            if ($sample === null) {
                continue;
            }

            $words = self::strings($sample);

            if ($this->hasForm($sample)) {
                $form = $sample['form'];
                $words[] = $form->name;
                $words[] = $form->submitLabel->nl . ' ' . $form->submitLabel->en;
                $words[] = $form->successMessage->nl . ' ' . $form->successMessage->en;
                foreach ($form->fields as $field) {
                    $words[] = $field->label->nl . ' ' . $field->label->en;
                }
            }

            foreach ($words as $word) {
                $lower = mb_strtolower(strip_tags($word));

                foreach (self::SITE_WORDS as $siteWord) {
                    $this->assertStringNotContainsString($siteWord, $lower, "{$type}'s sample says \"{$siteWord}\"");
                }

                foreach (self::CLAIMS as $claim) {
                    $this->assertDoesNotMatchRegularExpression($claim, $lower, "{$type}'s sample makes a claim: \"{$word}\"");
                }

                $this->assertDoesNotMatchRegularExpression('/\+?\d[\d \-]{8,}\d/', $lower, "{$type}'s sample carries something that reads as a phone number");
                $this->assertDoesNotMatchRegularExpression('#https?://#', $lower, "{$type}'s sample points outside the preview");

                preg_match_all('/[a-z0-9._%+\-]+@[a-z0-9.\-]+\.[a-z]{2,}/', $lower, $addresses);
                foreach ($addresses[0] as $address) {
                    $this->assertStringEndsWith('@example.com', $address, "{$type}'s sample carries an e-mail address that could reach somebody");
                }
            }
        }
    }

    /**
     * The text a visitor would read or hear in a rendered block: its text
     * nodes and every word-carrying attribute, both languages.
     */
    private static function renderedWords(string $html): string
    {
        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="utf-8"?><div>' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $xpath = new \DOMXPath($document);
        $words = [];

        foreach ($xpath->query('//text()') ?: [] as $node) {
            $words[] = $node->textContent;
        }

        foreach (self::WORD_ATTRIBUTES as $attribute) {
            foreach ($xpath->query('//@' . $attribute) ?: [] as $node) {
                $words[] = $node->textContent;
            }
        }

        return mb_strtolower(implode(' ', $words));
    }

    /**
     * Not only the samples: what the block's own partial prints around them
     * must be neutral too. A partial that hardcodes one site's name, city or
     * contact promises (the "Direct contact" card once said "Werkplaats —
     * ophalen op afspraak" and "Meestal binnen enkele werkdagen") would show
     * it on every installation, preview and live site alike.
     *
     * @dataProvider registeredTypes
     */
    public function testTheRenderedPreviewCarriesNoCopyOfOneParticularSite(string $type): void
    {
        $definition = self::definition($type);
        $sample = $definition->sampleContent(new BlockSamples());

        if ($sample === null) {
            $this->assertArrayHasKey($type, self::WITHOUT_SAMPLE);

            return;
        }

        $words = self::renderedWords(self::render($definition, $sample));
        $this->assertNotSame('', trim($words), "{$type} renders no words, so this test proves nothing about it");

        foreach (self::RENDERED_SITE_COPY as $copy) {
            $this->assertStringNotContainsString($copy, $words, "{$type}'s rendered preview says \"{$copy}\"");
        }
    }

    /**
     * The contact card is where such copy lived, so the scan above must really
     * see it: with both details filled in, every line of the card renders.
     */
    public function testTheContactCardIsPartOfWhatTheNeutralityScanReads(): void
    {
        $definition = self::definition('contact_form');
        $sample = $definition->sampleContent(new BlockSamples());
        $this->assertIsArray($sample);

        $words = self::renderedWords(self::render($definition, $sample));

        $this->assertStringContainsString('direct contact', $words);
        $this->assertStringContainsString(BlockSamples::EMAIL, $words);
        $this->assertStringContainsString('voorbeeldstad', $words);
        $this->assertStringContainsString('plaats', $words, 'the city sits under a generic label');
        $this->assertStringContainsString('bijlage', $words, 'the attachment control is part of the sample');
    }

    /**
     * A link in a preview goes nowhere: every href a sample renders is a
     * fragment, and the only form action is the one partials/form.php always
     * prints, carrying a form key no stored form can have.
     */
    public function testEveryLinkPointsInsideThePreviewAndNoFormCanReachAStoredForm(): void
    {
        foreach (BlockDefinitions::all() as $type => $definition) {
            $sample = $definition->sampleContent(new BlockSamples());
            if ($sample === null) {
                continue;
            }

            $html = self::render($definition, $sample);

            // The details card turns its address into a mailto: link; on
            // example.com it reaches nobody, and the preview stops the click.
            preg_match_all('/\shref="([^"]*)"/', $html, $links);
            foreach ($links[1] as $href) {
                if ($href === 'mailto:' . BlockSamples::EMAIL) {
                    continue;
                }

                $this->assertStringStartsWith('#', $href, "{$type}'s sample links to {$href}");
            }

            preg_match_all('/<form\b[^>]*\saction="([^"]*)"/', $html, $actions);
            foreach ($actions[1] as $action) {
                $this->assertSame('/api/form-submit.php', $action, "{$type}'s sample form posts somewhere unexpected");
                $this->assertStringContainsString('name="form-key" value="' . BlockSamples::FORM_KEY . '"', $html);
            }
        }

        $this->assertDoesNotMatchRegularExpression('/^[a-z0-9-]+$/', BlockSamples::FORM_KEY, 'a key FormCatalog::internalKeyFor() could produce');
    }

    public function testTheSampleFormIsInMemoryAndNotifiesNobody(): void
    {
        $form = (new BlockSamples())->form();

        $this->assertSame(0, $form->id);
        $this->assertSame(BlockSamples::FORM_KEY, $form->internalKey);
        $this->assertSame('', $form->notificationEmail);
        $this->assertFalse($form->storesSubmissions);
        $this->assertTrue($form->isRenderable());
    }

    public function testThePictureIsTheLibrarysOwnFile(): void
    {
        $path = dirname(__DIR__, 2) . BlockSamples::IMAGE_PATH;

        $this->assertFileExists($path);
        $this->assertStringContainsString('Owner: App\Service\Blocks\BlockSamples', (string) file_get_contents($path));
        $this->assertStringNotContainsString('<script', (string) file_get_contents($path));
    }

    // --- Pure, and only for the preview -----------------------------------

    /**
     * A sample is made of BlockSamples and nothing else: no stored row, no
     * setting, no request and no session, so it is the same on every
     * installation and cannot touch the database.
     *
     * @dataProvider registeredTypes
     */
    public function testASampleReadsNothingButTheSamples(string $type): void
    {
        $definition = self::definition($type);

        foreach (['sampleContent', 'renderSample'] as $method) {
            $source = self::methodSource($definition, $method);

            foreach (['Content::for', 'Content::current', 'activeForShop', 'navItemsForPage', 'positionMarkers', 'Repository', 'Database', 'SiteSettings', 'FormCatalog', 'forInstance', '$_GET', '$_POST', '$_SESSION', 'PDO'] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $source, "{$type}::{$method}() reaches for {$forbidden}");
            }
        }

        $samples = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Service/Blocks/BlockSamples.php');
        foreach (['Repository', 'App\\Database', 'SiteSettings', '$_GET', '$_POST', '$_SESSION', 'Content::'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $samples, "BlockSamples reaches for {$forbidden}");
        }
    }

    /**
     * Sample content is not a fallback. Only the block library and the block
     * definitions may know it exists; a public template, a partial, a Content
     * class or the registry's render path reaching for it would put demo
     * words on a live site.
     */
    public function testNothingOnThePublicSideReachesASample(): void
    {
        $root = dirname(__DIR__, 2);
        $allowed = [
            'admin/block-preview.php',
            'admin/content-blocks.php',
            'admin/_block_library.php',
            'src/Service/Blocks/BlockDefinition.php',
            'src/Service/Blocks/BlockSamples.php',
        ];

        $files = array_merge(
            glob($root . '/*.php') ?: [],
            glob($root . '/partials/*.php') ?: [],
            glob($root . '/api/*.php') ?: [],
            glob($root . '/api/admin/*.php') ?: [],
            glob($root . '/admin/*.php') ?: [],
            glob($root . '/src/Service/*.php') ?: [],
            glob($root . '/src/Module/*.php') ?: []
        );

        foreach ($files as $file) {
            $relative = str_replace('\\', '/', substr($file, strlen($root) + 1));
            if (in_array($relative, $allowed, true)) {
                continue;
            }

            // Code only: a docblock may say where a partial's sample comes from.
            $source = '';
            foreach (token_get_all((string) file_get_contents($file)) as $token) {
                if (!is_array($token) || !in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    $source .= is_array($token) ? $token[1] : $token;
                }
            }

            foreach (['BlockSamples', 'sampleContent(', 'renderSample('] as $needle) {
                $this->assertStringNotContainsString($needle, $source, "{$relative} reaches for {$needle}");
            }
        }
    }

    public function testASwitchedOffModuleTakesItsBlocksSampleWithIt(): void
    {
        $this->assertNotNull(BlockDefinitions::get('project_cards'));
        $this->assertNotNull(BlockDefinitions::get('shop_collections'));

        ModuleRegistry::overrideForTests(['shop' => false, 'portfolio' => false, 'blog' => false, 'personalization' => false]);

        $this->assertNull(BlockDefinitions::get('project_cards'));
        $this->assertNull(BlockDefinitions::get('shop_collections'));
        $this->assertNotNull(BlockDefinitions::get('rich_text'), 'Core is unaffected');

        // And Core names none of a module's blocks to preview them.
        $samples = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Service/Blocks/BlockSamples.php');
        foreach (['project_cards', 'shop_collections', 'product_grid', 'Portfolio', 'Shop'] as $moduleWord) {
            $this->assertStringNotContainsString("'" . $moduleWord, $samples);
        }
    }

    public function testTheGalleryScopeItStartsFromIsARealOne(): void
    {
        $sample = self::definition('item_gallery')->sampleContent(new BlockSamples());

        $this->assertIsArray($sample);
        $this->assertTrue(ItemGalleryContent::isPortfolioScope((string) $sample['portfolio_scope']));
    }
}
