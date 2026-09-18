<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Service\Blocks\BlockLocalization;
use App\Service\Blocks\BlockSamples;
use App\Service\Forms\FormRenderState;
use App\Service\Language\LocalizedValue;
use App\Service\Media\BlockImage;
use PHPUnit\Framework\TestCase;
use Tests\Support\SiteLanguageFixture;

require_once dirname(__DIR__, 2) . '/partials/section-page-hero.php';
require_once dirname(__DIR__, 2) . '/partials/section-form.php';
require_once dirname(__DIR__, 2) . '/partials/section-contact-form.php';
require_once dirname(__DIR__, 2) . '/partials/section-item-gallery.php';
require_once dirname(__DIR__, 2) . '/partials/section-faq.php';
require_once dirname(__DIR__, 2) . '/partials/section-feature-grid.php';
require_once dirname(__DIR__, 2) . '/partials/section-step-list.php';
require_once dirname(__DIR__, 2) . '/partials/section-stat-strip.php';
require_once dirname(__DIR__, 2) . '/partials/section-marquee.php';
require_once dirname(__DIR__, 2) . '/partials/section-homepage-hero.php';
require_once dirname(__DIR__, 2) . '/partials/section-text-image-split.php';
require_once dirname(__DIR__, 2) . '/partials/section-detail-section.php';
require_once dirname(__DIR__, 2) . '/partials/section-quicknav.php';
require_once dirname(__DIR__, 2) . '/partials/section-card-carousel.php';

/**
 * What a visitor gets from the block types phase 3B moved onto per-language
 * storage (Multilingual 2.0, docs/multilingual/ARCHITECTURE.md), rendered
 * through their real partials from words pinned in
 * App\Service\Blocks\BlockLocalization, for every default language the
 * registry can have. The phase 3A blocks are
 * Tests\Service\BlockLocalizedRenderingTest's.
 *
 * Per block: the first render shows the website's default language with the
 * fallback applied; the V1 switch gets its data-nl/data-en pair from the same
 * words; the default language decides whether a block (or an item) shows
 * anything, so a translation alone shows nothing; a third language is only a
 * row; and every word is plain text, escaped, never data-lang-html, except
 * the Detailsectie's body, which is sanitized rich text through the same
 * contract as the Tekstblok's. No database: the registry comes from
 * SiteLanguageFixture.
 */
final class RemainingBlocksRenderingTest extends TestCase
{
    private const ID = 43;

    protected function tearDown(): void
    {
        BlockLocalization::clearCache();
        SiteLanguageFixture::reset();
    }

    // ------------------------------------------------------------ Paginakop

    public function testAPageHeroShowsTheDutchWordsFirstOnADutchSiteWithTheV1Pair(): void
    {
        SiteLanguageFixture::useBilingual('nl');
        $this->words('page_heroes', [
            'nl' => ['eyebrow' => 'Over ons', 'title' => 'Wie wij zijn', 'lead' => 'Een inleiding.'],
            'en' => ['title' => 'Who we are'],
        ]);

        $html = $this->pageHero();

        self::assertStringContainsString('<h1  data-nl="Wie wij zijn" data-en="Who we are">Wie wij zijn</h1>', $html);
        self::assertStringContainsString('<p class="eyebrow"  data-nl="Over ons" data-en="Over ons">Over ons</p>', $html, 'an untranslated eyebrow falls back to Dutch in both halves');
        self::assertStringNotContainsString('data-lang-html', $html);
    }

    public function testAPageHeroShowsTheEnglishWordsFirstOnAnEnglishSite(): void
    {
        SiteLanguageFixture::useBilingual('en');
        $this->words('page_heroes', [
            'nl' => ['title' => 'Wie wij zijn'],
            'en' => ['title' => 'Who we are', 'lead' => 'An introduction.'],
        ]);

        $html = $this->pageHero();

        self::assertStringContainsString('>Who we are</h1>', $html, 'the first render is the default language, not the Dutch words');
        self::assertStringContainsString('data-nl="An introduction." data-en="An introduction.">An introduction.</p>', $html, 'untranslated Dutch falls back to English');
    }

    public function testAPageHeroInAThirdDefaultLanguageIsWhatBothV1HalvesFallBackTo(): void
    {
        SiteLanguageFixture::useLanguages([
            SiteLanguageFixture::language('de', isDefault: true, sortOrder: 0),
            SiteLanguageFixture::language('nl', sortOrder: 1),
            SiteLanguageFixture::language('en', sortOrder: 2),
        ]);
        $this->words('page_heroes', ['de' => ['title' => 'Wer wir sind'], 'en' => ['title' => 'Who we are']]);

        $html = $this->pageHero();

        self::assertStringContainsString('data-nl="Wer wir sind" data-en="Who we are"', $html);
    }

    public function testAPageHeroWithATitleOnlyInTheTranslationRendersNothing(): void
    {
        SiteLanguageFixture::useBilingual('nl');
        $this->words('page_heroes', ['en' => ['eyebrow' => 'About', 'title' => 'Only English on a Dutch site']]);

        self::assertSame('', trim($this->pageHero()), 'the default language decides whether the header is there');
    }

    public function testAPageHeroEscapesItsWordsAndItsAltText(): void
    {
        SiteLanguageFixture::useBilingual('nl');
        $this->words('page_heroes', [
            'nl' => ['title' => '<script>alert(1)</script> & "kop"', 'lead' => '<img src=x onerror=alert(1)>'],
            'en' => ['title' => '"><svg onload=alert(1)>'],
        ]);

        $html = $this->pageHero(['image_path' => '/assets/media/x.webp', 'image_alt' => LocalizedValue::of(['nl' => 'Foto "met" <b>markup</b>', 'en' => "Photo ' quote"])]);

        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt; &amp; &quot;kop&quot;</h1>', $html);
        self::assertStringContainsString('data-en="&quot;&gt;&lt;svg onload=alert(1)&gt;"', $html);
        self::assertStringContainsString('alt="Foto &quot;met&quot; &lt;b&gt;markup&lt;/b&gt;" data-nl-alt="Foto &quot;met&quot; &lt;b&gt;markup&lt;/b&gt;" data-en-alt="Photo &#039; quote"', $html, 'alt text stays in its attribute, escaped, with its V1 pair');
        self::assertStringNotContainsString('<script', $html);
        self::assertStringNotContainsString('<svg', $html);
        self::assertStringNotContainsString('<img src=x', $html);
        self::assertStringNotContainsString('data-lang-html', $html);
    }

    // ------------------------------------------------------------ Formulier and Offerte-/contactformulier

    public function testAFormBlockPrintsItsHeadingAndIntroductionInTheDefaultLanguage(): void
    {
        SiteLanguageFixture::useBilingual('en');
        $this->words('form_blocks', ['nl' => ['title' => 'Stuur <b>een</b> bericht', 'intro' => 'Wij antwoorden.'], 'en' => ['title' => 'Send a message']]);

        $html = $this->capture(fn () => render_section_form(
            BlockLocalization::words('form_blocks', self::ID),
            (new BlockSamples())->form(),
            FormRenderState::fresh(FormRenderState::tokenFor('rendering-test', 'form'))
        ));

        self::assertStringContainsString('data-nl="Stuur &lt;b&gt;een&lt;/b&gt; bericht" data-en="Send a message">Send a message</h2>', $html, 'the English default first, the Dutch half escaped');
        self::assertStringNotContainsString('form-block__intro', $html, 'an introduction only in Dutch is no introduction on an English-default site');
        self::assertStringNotContainsString('data-lang-html', $html);
    }

    public function testAContactFormBlockPrintsItsHeadingFromTheDefaultLanguage(): void
    {
        SiteLanguageFixture::useBilingual('nl');
        $this->words('contact_form_sections', ['nl' => ['title' => 'Vraag een offerte aan'], 'en' => ['title' => 'Request a quote']]);

        $html = $this->capture(fn () => render_section_contact_form(
            ['state' => 'active', 'title' => BlockLocalization::words('contact_form_sections', self::ID)['title'], 'form_id' => null, 'allow_attachment' => false],
            null,
            FormRenderState::fresh(FormRenderState::tokenFor('rendering-test', 'contact')),
            ['email' => '', 'city' => \App\Service\Language\LocalizedValue::of([])]
        ));

        self::assertStringContainsString('data-nl="Vraag een offerte aan" data-en="Request a quote">Vraag een offerte aan</h2>', $html);
    }

    // ------------------------------------------------------------ Galerij and Projecten

    public function testAGalleryPrintsItsOwnWordsFromTheDefaultLanguageAndItsItemsAsTheyCame(): void
    {
        SiteLanguageFixture::useBilingual('en');
        $this->words('item_galleries', [
            'nl' => ['title' => 'Onze projecten', 'footer_note' => 'En meer.', 'button_label' => 'Al het werk'],
            'en' => ['title' => 'Our projects', 'button_label' => '<b>All</b> work'],
        ]);

        $html = $this->gallery(['button_url' => '/work']);

        self::assertStringContainsString('<h2  data-nl="Onze projecten" data-en="Our projects">Our projects</h2>', $html);
        self::assertStringNotContainsString('En meer.', $html, 'a closing text only in Dutch is no closing text on an English-default site');
        self::assertStringContainsString('data-nl="Al het werk" data-en="&lt;b&gt;All&lt;/b&gt; work">&lt;b&gt;All&lt;/b&gt; work</a>', $html);
        self::assertStringNotContainsString('class="eyebrow"', $html, 'no eyebrow in the default language: no element');
        self::assertStringContainsString('data-nl="Kaart NL" data-en="Card EN">Card EN</p>', $html, 'an item keeps its own source\'s Dutch/English pair');
    }

    public function testAGalleryButtonOnlyInTheTranslationDoesNotShow(): void
    {
        SiteLanguageFixture::useBilingual('nl');
        $this->words('item_galleries', ['en' => ['button_label' => 'All work', 'title' => 'Only English']]);

        $html = $this->gallery(['button_url' => '/work']);

        self::assertStringNotContainsString('btn--ghost', $html, 'the default language has no label');
        self::assertStringNotContainsString('section-head', $html, 'nor a heading');
    }

    // ------------------------------------------------------------ Repeaters (wave B): child rows own their words

    public function testAFaqPrintsItsHeadingAndEachQuestionFromItsOwnRow(): void
    {
        SiteLanguageFixture::useBilingual('en');
        $this->words('faq_sections', ['nl' => ['eyebrow' => 'Vragen', 'title' => 'Veelgesteld'], 'en' => ['title' => 'Frequently asked']]);
        $this->childWords('faq_items', 1, ['nl' => ['question' => 'Hoe lang?', 'answer' => 'Een week.'], 'en' => ['question' => 'How long?', 'answer' => 'A week.']]);
        $this->childWords('faq_items', 2, ['nl' => ['question' => 'Wat kost het?', 'answer' => 'Dat hangt ervan af.'], 'en' => ['question' => 'What does it cost?']]);

        $html = $this->capture(fn () => render_section_faq(BlockLocalization::words('faq_sections', self::ID) + [
            'items' => [BlockLocalization::words('faq_items', 1), BlockLocalization::words('faq_items', 2)],
        ]));

        self::assertStringContainsString('data-nl="Veelgesteld" data-en="Frequently asked">Frequently asked</h2>', $html, 'the English default first');
        self::assertStringContainsString('data-nl="Hoe lang?" data-en="How long?">How long?</span>', $html);
        self::assertStringContainsString('data-nl="Dat hangt ervan af." data-en="">', $html, 'an English answer that is missing on an English-default site has nothing to fall back to');
        self::assertStringNotContainsString('data-lang-html', $html);
    }

    public function testAFeatureGridStepListStatStripAndMarqueeEscapeTheirItemsAndStayPlainText(): void
    {
        SiteLanguageFixture::useBilingual('nl');
        $payload = '<img src=x onerror=alert(1)> & "quoted"';
        $escaped = '&lt;img src=x onerror=alert(1)&gt; &amp; &quot;quoted&quot;';

        $this->words('feature_grids', ['nl' => ['title' => 'Kenmerken']]);
        $this->childWords('feature_grid_items', 3, ['nl' => ['title' => $payload, 'body' => 'Tekst'], 'en' => ['title' => '"><script>alert(2)</script>']]);
        $grid = $this->capture(fn () => render_section_feature_grid(BlockLocalization::words('feature_grids', self::ID) + [
            'items' => [['icon_key' => 'heart'] + BlockLocalization::words('feature_grid_items', 3)],
        ], 'grid-test'));

        $this->words('step_list_sections', ['nl' => ['title' => 'Stappen']]);
        $this->childWords('step_list_items', 4, ['nl' => ['title' => $payload, 'body' => 'Uitleg']]);
        $steps = $this->capture(fn () => render_section_step_list(BlockLocalization::words('step_list_sections', self::ID) + [
            'items' => [BlockLocalization::words('step_list_items', 4)],
        ], 'steps-test'));

        $this->childWords('stat_strip_items', 5, ['nl' => ['primary_text' => $payload, 'secondary_text' => 'uitleg']]);
        $strip = $this->capture(fn () => render_section_stat_strip(['items' => [BlockLocalization::words('stat_strip_items', 5)]], 'strip-test'));

        $this->childWords('marquee_items', 6, ['nl' => ['label' => $payload], 'en' => ['label' => 'Plain']]);
        $marquee = $this->capture(fn () => render_section_marquee(['items' => [BlockLocalization::words('marquee_items', 6)]]));

        foreach (['feature grid' => $grid, 'step list' => $steps, 'stat strip' => $strip, 'marquee' => $marquee] as $what => $html) {
            self::assertStringContainsString($escaped, $html, $what . ': a child label with markup is escaped text');
            self::assertStringNotContainsString('<img src=x', $html, $what);
            self::assertStringNotContainsString('<script', $html, $what);
            self::assertStringNotContainsString('data-lang-html', $html, $what . ': plain text is never marked as HTML');
        }
        self::assertStringContainsString('data-en="&quot;&gt;&lt;script&gt;alert(2)&lt;/script&gt;"', $grid, 'the other half of the pair is escaped too');
    }

    // ------------------------------------------------------------ Openingssectie homepage

    public function testTheHomepageHeroComposesItsHeadlinePerLanguageAndPrintsTheRestAsText(): void
    {
        SiteLanguageFixture::useBilingual('en');
        $this->words('homepage_hero', [
            'nl' => ['title' => 'Wij maken <het> mooi', 'title_highlight' => '<het>', 'primary_label' => 'Contact', 'image_alt' => 'Een "werkbank"', 'badge_title' => 'Sinds 2010', 'badge_text' => 'Tekst'],
            'en' => ['title' => 'We make it beautiful', 'title_highlight' => 'beautiful', 'primary_label' => 'Get in touch', 'image_alt' => "A 'workbench'"],
        ]);
        $this->childWords('homepage_hero_stats', 7, ['nl' => ['primary_text' => '12 jaar', 'secondary_text' => '<b>ervaring</b>'], 'en' => ['primary_text' => '12 years']]);

        $hero = BlockLocalization::words('homepage_hero', self::ID) + [
            'state' => 'active',
            'title_highlight_size' => 100,
            'primary_url' => '/contact',
            'secondary_url' => '',
            'image_path' => 'assets/images/hero.jpg',
            'media_type' => 'image',
            'video_path' => '',
            'layout' => 'media-right',
            'stats' => [BlockLocalization::words('homepage_hero_stats', 7)],
        ];

        $html = $this->capture(fn () => render_section_homepage_hero($hero));

        self::assertStringContainsString(
            'data-lang-html data-nl="Wij maken &lt;em&gt;&amp;lt;het&amp;gt;&lt;/em&gt; mooi" data-en="We make it &lt;em&gt;beautiful&lt;/em&gt;">We make it <em>beautiful</em></h1>',
            $html,
            'the headline is markup built from escaped words and a hardcoded <em>, one fragment per language, the default first'
        );
        self::assertStringContainsString('alt="A &#039;workbench&#039;" data-nl-alt="Een &quot;werkbank&quot;" data-en-alt="A &#039;workbench&#039;"', $html);
        self::assertStringContainsString('data-nl="&lt;b&gt;ervaring&lt;/b&gt;" data-en="">', $html, 'a stat caption is escaped text');
        self::assertStringNotContainsString('hero__badge', $html, 'no badge in the English default');
        self::assertSame(1, substr_count($html, 'data-lang-html'), 'only the headline is marked as HTML');
    }

    // ------------------------------------------------------------ Detailsectie (wave C): rich body, plain everything else

    public function testADetailSectionBodyIsRichTextShownInTheDefaultLanguageWithItsPairOnlyWhenTheLanguagesDiffer(): void
    {
        SiteLanguageFixture::useBilingual('nl');
        $this->words('detail_sections', [
            'nl' => ['title' => 'Hout graveren', 'body' => '<p>Nederlands <strong>vet</strong></p>'],
            'en' => ['title' => 'Wood engraving', 'body' => '<p>English <strong>bold</strong></p>'],
        ]);

        $html = $this->detailSection();

        self::assertStringContainsString(
            '<div class="rich-content service-detail__body" data-lang-html data-nl="&lt;p&gt;Nederlands &lt;strong&gt;vet&lt;/strong&gt;&lt;/p&gt;" data-en="&lt;p&gt;English &lt;strong&gt;bold&lt;/strong&gt;&lt;/p&gt;"><p>Nederlands <strong>vet</strong></p></div>',
            $html,
            'the Dutch body first, as markup, with the escaped pair for the switch'
        );
        self::assertSame(1, substr_count($html, 'data-lang-html'), 'only the body is marked as HTML');

        // One body in the default language only: no pair, nothing to switch.
        $this->words('detail_sections', ['nl' => ['title' => 'Hout graveren', 'body' => '<p>Alleen Nederlands</p>']]);
        self::assertStringContainsString('<div class="rich-content service-detail__body"><p>Alleen Nederlands</p></div>', $this->detailSection(), 'a missing translation falls back to the default body');
    }

    public function testADetailSectionOnAnEnglishSiteShowsTheEnglishBodyFirstAndATranslationAloneShowsNothing(): void
    {
        SiteLanguageFixture::useBilingual('en');
        $this->words('detail_sections', [
            'nl' => ['title' => 'Hout graveren', 'body' => '<p>Nederlands</p>'],
            'en' => ['title' => 'Wood engraving', 'body' => '<p>English</p>'],
        ]);

        self::assertStringContainsString('"><p>English</p></div>', $this->detailSection(), 'the English default first');

        $this->words('detail_sections', ['en' => ['title' => 'Wood engraving'], 'nl' => ['body' => '<p>Alleen Nederlands</p>']]);
        self::assertStringNotContainsString('service-detail__body', $this->detailSection(), 'a Dutch body on an English-default site has no default body to show');
    }

    public function testADetailSectionBodyInAThirdDefaultLanguageIsWhatBothV1HalvesFallBackTo(): void
    {
        SiteLanguageFixture::useLanguages([
            SiteLanguageFixture::language('de', isDefault: true, sortOrder: 0),
            SiteLanguageFixture::language('nl', sortOrder: 1),
            SiteLanguageFixture::language('en', sortOrder: 2),
        ]);
        $this->words('detail_sections', ['de' => ['title' => 'Holz', 'body' => '<p>Deutsch</p>'], 'en' => ['body' => '<p>English</p>']]);

        self::assertStringContainsString('data-lang-html data-nl="&lt;p&gt;Deutsch&lt;/p&gt;" data-en="&lt;p&gt;English&lt;/p&gt;"', $this->detailSection());
    }

    public function testADetailSectionSanitizesItsBodyAndEscapesItsLabelsPointsAndAltTexts(): void
    {
        SiteLanguageFixture::useBilingual('nl');
        $payload = '<img src=x onerror=alert(1)> & "quoted"';
        $escaped = '&lt;img src=x onerror=alert(1)&gt; &amp; &quot;quoted&quot;';
        $this->words('detail_sections', [
            'nl' => [
                'title' => $payload, 'lead' => 'Inleiding', 'closing_note' => $payload, 'cta_label' => $payload, 'main_image_alt' => 'Foto "met" <b>markup</b>',
                'body' => '<p>Veilig <a href="https://example.test" onclick="steal()">link</a></p><script>alert(1)</script><img src=x onerror=alert(1)>',
            ],
            'en' => ['body' => '<p>Safe</p><iframe src="https://evil.test"></iframe><p onmouseover="alert(1)">hover</p>', 'main_image_alt' => "Photo ' quote"],
        ]);
        $this->childWords('detail_section_points', 8, ['nl' => ['title' => $payload, 'body' => 'Uitleg'], 'en' => ['title' => '"><script>alert(2)</script>']]);
        $this->childWords('detail_section_images', 9, ['nl' => ['alt' => 'Werkbank "oud"'], 'en' => ['alt' => '<b>bench</b>']]);

        $html = $this->detailSection([
            'main_image_path' => '/assets/media/x.webp',
            'cta_url' => '/contact',
            'points' => [BlockLocalization::words('detail_section_points', 8)],
            'images' => [['image_path' => '/assets/media/y.webp', 'width' => null, 'height' => null] + ['alt' => BlockLocalization::bilingual('detail_section_images', 9, 'alt')]],
        ]);

        self::assertStringContainsString('<a href="https://example.test"', $html, 'a link in the body stays real markup');
        foreach (['<script', 'onclick', 'onerror', '<iframe', 'onmouseover'] as $hostile) {
            self::assertStringNotContainsString($hostile, html_entity_decode($this->element($html, 'service-detail__body'), ENT_QUOTES), $hostile . ' survived in the body, visible or in the switch attributes');
        }

        self::assertSame(4, substr_count($html, '>' . $escaped), 'the title, the CTA label, the closing note and a point title are escaped text');
        self::assertStringContainsString('data-en="&quot;&gt;&lt;script&gt;alert(2)&lt;/script&gt;"', $html, 'a point title is escaped in both halves');
        self::assertStringContainsString('alt="Foto &quot;met&quot; &lt;b&gt;markup&lt;/b&gt;" data-nl-alt="Foto &quot;met&quot; &lt;b&gt;markup&lt;/b&gt;" data-en-alt="Photo &#039; quote"', $html, 'the main image alt stays in its attribute');
        self::assertStringContainsString('alt="Werkbank &quot;oud&quot;" data-nl-alt="Werkbank &quot;oud&quot;" data-en-alt="&lt;b&gt;bench&lt;/b&gt;"', $html, 'a gallery image alt from its own row');
        self::assertStringNotContainsString('<img src=x', $html);
        self::assertSame(1, substr_count($html, 'data-lang-html'), 'plain text is never marked as HTML');
    }

    public function testTheQuicknavLabelIsTheShortLabelOrTheTitlePerLanguageAndStaysText(): void
    {
        SiteLanguageFixture::useBilingual('nl');
        $this->words('detail_sections', ['nl' => ['nav_label' => '<b>Hout</b>', 'title' => 'Hout graveren'], 'en' => ['title' => 'Wood engraving']]);

        $html = $this->capture(fn () => render_section_quicknav([
            ['anchor' => 'hout"><script>', 'label' => BlockLocalization::bilingualFirst('detail_sections', self::ID, ['nav_label', 'title'])],
        ]));

        self::assertStringContainsString('<a href="#hout&quot;&gt;&lt;script&gt;"  data-nl="&lt;b&gt;Hout&lt;/b&gt;" data-en="Wood engraving">&lt;b&gt;Hout&lt;/b&gt;</a>', $html, 'the English title before the Dutch short label');
        self::assertStringNotContainsString('data-lang-html', $html);
    }

    // ------------------------------------------------------------ Tekst met afbeelding and Kaarten-carrousel (wave C)

    public function testATextWithImagesPrintsItsParagraphsAndAltTextsFromTheirOwnRowsAsText(): void
    {
        SiteLanguageFixture::useBilingual('nl');
        $payload = '<img src=x onerror=alert(1)> & "quoted"';
        $this->words('text_image_splits', ['nl' => ['eyebrow' => 'Over mij', 'title' => 'Het verhaal', 'button_label' => 'Contact'], 'en' => ['title' => 'The story']]);
        $this->childWords('text_image_split_paragraphs', 10, ['nl' => ['content' => $payload], 'en' => ['content' => 'English paragraph']]);
        $this->childWords('text_image_split_images', 11, ['nl' => ['alt' => 'Een "werkplaats"'], 'en' => ['alt' => "A 'workshop'"]]);

        $section = BlockLocalization::words('text_image_splits', self::ID) + [
            'layout' => 'image_right',
            'button_url' => '/contact',
            'paragraphs' => [BlockLocalization::words('text_image_split_paragraphs', 10)],
            'images' => [['image_path' => '/assets/media/z.webp', 'width' => null, 'height' => null, 'alt' => BlockLocalization::bilingual('text_image_split_images', 11, 'alt')]],
        ];
        $html = $this->capture(fn () => render_section_text_image_split($section, false, 'split-test'));

        self::assertStringContainsString('data-nl="Het verhaal" data-en="The story">Het verhaal</h2>', $html);
        self::assertStringContainsString('data-en="English paragraph">&lt;img src=x onerror=alert(1)&gt; &amp; &quot;quoted&quot;</p>', $html, 'a paragraph is escaped text');
        self::assertStringContainsString('alt="Een &quot;werkplaats&quot;" data-nl-alt="Een &quot;werkplaats&quot;" data-en-alt="A &#039;workshop&#039;"', $html);
        self::assertStringNotContainsString('<img src=x', $html);
        self::assertStringNotContainsString('data-lang-html', $html);
    }

    public function testACarouselPrintsItsCardsAndTheirTagsThreeLevelsDownAsText(): void
    {
        SiteLanguageFixture::useBilingual('en');
        $payload = '<b>Duurzaam</b> & "eerlijk"';
        $this->words('card_carousels', ['nl' => ['title' => 'Materialen'], 'en' => ['title' => 'Materials "we" use']]);
        $this->childWords('carousel_cards', 12, [
            'nl' => ['title' => 'Hout', 'body' => 'Warm.', 'image_alt' => 'Eiken "plank"', 'link_label' => 'Lees meer'],
            'en' => ['title' => 'Wood', 'image_alt' => "Oak 'board'", 'link_label' => 'Read more'],
        ]);
        $this->childWords('carousel_card_tags', 13, ['nl' => ['label' => $payload], 'en' => ['label' => '"><script>alert(3)</script>']]);

        $content = BlockLocalization::words('card_carousels', self::ID) + [
            'cards' => [BlockLocalization::words('carousel_cards', 12) + [
                'index_label' => '01',
                'image_path' => '/assets/media/oak.webp',
                'image_width' => null,
                'image_height' => null,
                'link_url' => '/materialen/hout',
                'tags' => [BlockLocalization::words('carousel_card_tags', 13)],
            ]],
        ];
        $html = $this->capture(fn () => render_section_card_carousel($content));

        self::assertStringContainsString('data-nl="Materialen" data-en="Materials &quot;we&quot; use">Materials &quot;we&quot; use</h2>', $html, 'the English default first');
        self::assertStringContainsString('aria-label="Materials &quot;we&quot; use" data-nl-aria="Materialen" data-en-aria="Materials &quot;we&quot; use"', $html, 'the carousel is named after its title, in its attribute');
        self::assertStringContainsString('alt="Oak &#039;board&#039;" data-nl-alt="Eiken &quot;plank&quot;" data-en-alt="Oak &#039;board&#039;"', $html);
        self::assertStringContainsString('data-nl="&lt;b&gt;Duurzaam&lt;/b&gt; &amp; &quot;eerlijk&quot;" data-en="&quot;&gt;&lt;script&gt;alert(3)&lt;/script&gt;"', $html, 'a tag, the third level, is escaped text in both halves');
        self::assertStringNotContainsString('<script', $html);
        self::assertStringNotContainsString('<b>', $html);
        self::assertStringNotContainsString('data-lang-html', $html);
    }

    // ------------------------------------------------------------ helpers

    /** @param array<string, mixed> $overrides */
    private function detailSection(array $overrides = []): string
    {
        $content = $overrides + BlockLocalization::words('detail_sections', self::ID) + [
            'id' => self::ID,
            'anchor' => 'hout',
            'main_image_path' => '',
            'main_image_width' => null,
            'main_image_height' => null,
            'image_position' => 'image_right',
            'cta_url' => '',
            'points' => [],
            'images' => [],
        ];

        return $this->capture(static fn () => render_section_detail_section($content, ['index_label' => '01', 'bg_soft' => false], 'detail-test'));
    }

    /** The element of $html with this class, from its opening tag to the first closing tag of that kind. */
    private function element(string $html, string $class): string
    {
        self::assertSame(1, preg_match('/<(\w+) class="[^"]*\b' . preg_quote($class, '/') . '\b[^"]*"[^>]*>.*?<\/\1>/s', $html, $match), $class);

        return $match[0];
    }

    /** @param array<string, array<string, string>> $translations */
    private function childWords(string $table, int $id, array $translations): void
    {
        BlockLocalization::overrideForTests($table, $id, $translations);
    }

    /** @param array<string, array<string, string>> $translations */
    private function words(string $table, array $translations): void
    {
        BlockLocalization::overrideForTests($table, self::ID, $translations);
    }

    /** @param array<string, mixed> $image */
    private function pageHero(array $image = []): string
    {
        $content = BlockLocalization::words('page_heroes', self::ID) + $image + [
            'state' => 'active',
            'media_id' => null,
            'image_path' => '',
            'image_alt' => BlockImage::fromOwner([], null)['alt'],
            'image_width' => null,
            'image_height' => null,
            'content_position' => 'left',
            'title_size' => 'normal',
            'text_size' => 'normal',
        ];

        return $this->capture(static fn () => render_section_page_hero($content));
    }

    /** @param array<string, mixed> $settings */
    private function gallery(array $settings): string
    {
        $content = BlockLocalization::words('item_galleries', self::ID) + $settings + [
            'enable_lightbox' => false,
            'filter_categories' => [],
            'fallback_link_url' => '',
            'button_url' => '',
            'background' => 'default',
            'tight_top' => false,
            'items' => [[
                'image_path' => 'assets/images/x.jpg',
                // One LocalizedValue per field, the shape every source hands
                // the partial since phase 5 wave A.
                'alt' => \App\Service\Language\LocalizedValue::of([]),
                'title' => \App\Service\Language\LocalizedValue::ofDutchEnglish('Kaart NL', 'Card EN'),
                'subtitle' => \App\Service\Language\LocalizedValue::of([]),
                'categories' => '',
                'url' => '',
                'is_detail_link' => false,
            ]],
        ];

        return $this->capture(static fn () => render_section_item_gallery($content, 'rendering-test'));
    }

    private function capture(\Closure $render): string
    {
        ob_start();
        try {
            $render();
        } finally {
            $html = (string) ob_get_clean();
        }

        return $html;
    }
}
