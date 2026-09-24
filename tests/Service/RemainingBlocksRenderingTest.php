<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Service\Blocks\BlockLocalization;
use App\Service\Blocks\BlockSamples;
use App\Service\Forms\FormRenderState;
use App\Service\HomepageHeroContent;
use App\Service\Media\BlockImage;
use App\Service\Routing\RequestLanguage;
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
 * registry can have and every language a request can be answered in. The
 * phase 3A blocks are Tests\Service\BlockLocalizedRenderingTest's.
 *
 * Per block: the element carries the words of the REQUEST's language with
 * the fallback applied, and no second language — no data-nl/data-en pair,
 * no data-lang-html (phase 7); the default language decides whether a block
 * (or an item, or a button) shows anything, so a translation alone shows
 * nothing; a third language is only a row; and every word is plain text,
 * escaped, except the Detailsectie's body, which is sanitized rich text, and
 * the homepage headline, which is escaped words around one hardcoded <em>.
 * No database: the registry comes from SiteLanguageFixture.
 */
final class RemainingBlocksRenderingTest extends TestCase
{
    private const ID = 43;

    protected function tearDown(): void
    {
        BlockLocalization::clearCache();
        SiteLanguageFixture::reset();
        RequestLanguage::reset();
    }

    // ------------------------------------------------------------ Paginakop

    public function testAPageHeroShowsTheWordsOfTheLanguageBeingRead(): void
    {
        SiteLanguageFixture::useBilingual('nl');
        $this->words('page_heroes', [
            'nl' => ['eyebrow' => 'Over ons', 'title' => 'Wie wij zijn', 'lead' => 'Een inleiding.'],
            'en' => ['title' => 'Who we are'],
        ]);

        $dutch = $this->pageHero('nl');
        self::assertStringContainsString('>Wie wij zijn</h1>', $dutch);
        self::assertStringNotContainsString('Who we are', $dutch, 'one language per document');

        $english = $this->pageHero('en');
        self::assertStringContainsString('>Who we are</h1>', $english);
        self::assertStringContainsString('<p class="eyebrow">Over ons</p>', $english, 'an untranslated eyebrow falls back to the default language');
        $this->assertNoLanguagePairs($english);
    }

    public function testAPageHeroOnAnEnglishSiteShowsEnglishOnAnUnprefixedUrl(): void
    {
        SiteLanguageFixture::useBilingual('en');
        $this->words('page_heroes', [
            'nl' => ['title' => 'Wie wij zijn'],
            'en' => ['title' => 'Who we are', 'lead' => 'An introduction.'],
        ]);

        self::assertStringContainsString('>Who we are</h1>', $this->pageHero(null), 'no prefix is the default language, not the Dutch words');
        self::assertStringContainsString('>An introduction.</p>', $this->pageHero('nl'), 'untranslated Dutch falls back to English');
    }

    public function testAPageHeroInAThirdLanguageNeedsNoCode(): void
    {
        SiteLanguageFixture::useLanguages([
            SiteLanguageFixture::language('de', isDefault: true, sortOrder: 0),
            SiteLanguageFixture::language('nl', sortOrder: 1),
            SiteLanguageFixture::language('en', sortOrder: 2),
        ]);
        $this->words('page_heroes', ['de' => ['title' => 'Wer wir sind'], 'en' => ['title' => 'Who we are']]);

        self::assertStringContainsString('>Wer wir sind</h1>', $this->pageHero('de'));
        self::assertStringContainsString('>Wer wir sind</h1>', $this->pageHero('nl'), 'untranslated Dutch falls back to German, the default');
        self::assertStringContainsString('>Who we are</h1>', $this->pageHero('en'));
    }

    public function testAPageHeroWithATitleOnlyInTheTranslationRendersNothing(): void
    {
        SiteLanguageFixture::useBilingual('nl');
        $this->words('page_heroes', ['en' => ['eyebrow' => 'About', 'title' => 'Only English on a Dutch site']]);

        self::assertSame('', trim($this->pageHero('nl')));
        self::assertSame('', trim($this->pageHero('en')), 'the default language decides whether the header is there');
    }

    public function testAPageHeroEscapesItsWordsAndItsAltText(): void
    {
        SiteLanguageFixture::useBilingual('nl');
        $this->words('page_heroes', [
            'nl' => ['title' => '<script>alert(1)</script> & "kop"', 'lead' => '<img src=x onerror=alert(1)>'],
            'en' => ['title' => '"><svg onload=alert(1)>'],
        ]);

        // Beside the text, where the picture is content and carries its alt
        // text; behind the text it is decoration and has none.
        $dutch = $this->pageHero('nl', ['image_path' => '/assets/media/x.webp', 'image_alt' => 'Foto "met" <b>markup</b>', 'image_mode' => 'right']);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt; &amp; &quot;kop&quot;</h1>', $dutch);
        self::assertStringContainsString('alt="Foto &quot;met&quot; &lt;b&gt;markup&lt;/b&gt;"', $dutch, 'alt text stays in its attribute, escaped');

        $english = $this->pageHero('en', ['image_path' => '/assets/media/x.webp', 'image_alt' => "Photo ' quote", 'image_mode' => 'right']);
        self::assertStringContainsString('&quot;&gt;&lt;svg onload=alert(1)&gt;</h1>', $english);
        self::assertStringContainsString('alt="Photo &#039; quote"', $english);

        foreach ([$dutch, $english] as $html) {
            self::assertStringNotContainsString('<script', $html);
            self::assertStringNotContainsString('<svg', $html);
            self::assertStringNotContainsString('<img src=x', $html);
            $this->assertNoLanguagePairs($html);
        }
    }

    // ------------------------------------------------------------ Formulier and Offerte-/contactformulier

    public function testAFormBlockPrintsItsHeadingAndIntroductionInTheLanguageBeingRead(): void
    {
        SiteLanguageFixture::useBilingual('en');
        $this->words('form_blocks', ['nl' => ['title' => 'Stuur <b>een</b> bericht', 'intro' => 'Wij antwoorden.'], 'en' => ['title' => 'Send a message']]);

        $english = $this->formBlock(null);
        self::assertStringContainsString('>Send a message</h2>', $english, 'the English default on an unprefixed URL');
        self::assertStringNotContainsString('form-block__intro', $english, 'an introduction only in Dutch is no introduction on an English-default site');

        $dutch = $this->formBlock('nl');
        self::assertStringContainsString('>Stuur &lt;b&gt;een&lt;/b&gt; bericht</h2>', $dutch, 'the Dutch heading, escaped');
        $this->assertNoLanguagePairs($dutch);
    }

    public function testAContactFormBlockPrintsItsHeadingInTheLanguageBeingRead(): void
    {
        SiteLanguageFixture::useBilingual('nl');
        $this->words('contact_form_sections', ['nl' => ['title' => 'Vraag een offerte aan'], 'en' => ['title' => 'Request a quote']]);

        self::assertStringContainsString('>Vraag een offerte aan</h2>', $this->contactForm('nl'));

        $english = $this->contactForm('en');
        self::assertStringContainsString('>Request a quote</h2>', $english);
        self::assertStringContainsString('>Direct contact</h2>', $english, 'the card\'s own label is a code catalogue');
        $this->assertNoLanguagePairs($english);
    }

    // ------------------------------------------------------------ Galerij and Projecten

    public function testAGalleryPrintsItsOwnWordsAndItsItemsInTheLanguageBeingRead(): void
    {
        SiteLanguageFixture::useBilingual('en');
        $this->words('item_galleries', [
            'nl' => ['title' => 'Onze projecten', 'footer_note' => 'En meer.', 'button_label' => 'Al het werk'],
            'en' => ['title' => 'Our projects', 'button_label' => '<b>All</b> work'],
        ]);

        $html = $this->gallery(null, ['button_url' => '/work'], 'Card EN');

        self::assertStringContainsString('<h2>Our projects</h2>', $html);
        self::assertStringNotContainsString('En meer.', $html, 'a closing text only in Dutch is no closing text on an English-default site');
        self::assertStringContainsString('>&lt;b&gt;All&lt;/b&gt; work</a>', $html);
        self::assertStringNotContainsString('class="eyebrow"', $html, 'no eyebrow in the default language: no element');
        self::assertStringContainsString('<p>Card EN</p>', $html, 'an item arrives in the language being read from its own source');
        $this->assertNoLanguagePairs($html);
    }

    public function testAGalleryButtonOnlyInTheTranslationDoesNotShow(): void
    {
        SiteLanguageFixture::useBilingual('nl');
        $this->words('item_galleries', ['en' => ['button_label' => 'All work', 'title' => 'Only English']]);

        foreach (['nl', 'en'] as $language) {
            $html = $this->gallery($language, ['button_url' => '/work'], 'Kaart');

            self::assertStringNotContainsString('btn--ghost', $html, $language . ': the default language has no label');
        }
        self::assertStringNotContainsString('section-head', $this->gallery('nl', ['button_url' => '/work'], 'Kaart'), 'nor a heading in the default language');
    }

    // ------------------------------------------------------------ Repeaters (wave B): child rows own their words

    public function testAFaqPrintsItsHeadingAndEachQuestionFromItsOwnRow(): void
    {
        SiteLanguageFixture::useBilingual('en');
        $this->words('faq_sections', ['nl' => ['eyebrow' => 'Vragen', 'title' => 'Veelgesteld'], 'en' => ['title' => 'Frequently asked']]);
        $this->childWords('faq_items', 1, ['nl' => ['question' => 'Hoe lang?', 'answer' => 'Een week.'], 'en' => ['question' => 'How long?', 'answer' => 'A week.']]);
        $this->childWords('faq_items', 2, ['nl' => ['question' => 'Wat kost het?', 'answer' => 'Dat hangt ervan af.'], 'en' => ['question' => 'What does it cost?']]);

        $render = function (?string $language): string {
            $this->answerIn($language);

            return $this->capture(fn () => render_section_faq(BlockLocalization::words('faq_sections', self::ID) + [
                'items' => [BlockLocalization::words('faq_items', 1), BlockLocalization::words('faq_items', 2)],
            ]));
        };

        $english = $render(null);
        self::assertStringContainsString('>Frequently asked</h2>', $english, 'the English default on an unprefixed URL');
        self::assertStringContainsString('>How long?</span>', $english);
        self::assertStringNotContainsString('Dat hangt ervan af.', $english, 'an English answer that is missing on an English-default site has nothing to fall back to');

        $dutch = $render('nl');
        self::assertStringContainsString('>Dat hangt ervan af.', $dutch);
        $this->assertNoLanguagePairs($dutch);
    }

    public function testAFeatureGridStepListStatStripAndMarqueeEscapeTheirItemsAndStayPlainText(): void
    {
        SiteLanguageFixture::useBilingual('nl');
        $payload = '<img src=x onerror=alert(1)> & "quoted"';
        $escaped = '&lt;img src=x onerror=alert(1)&gt; &amp; &quot;quoted&quot;';

        $this->words('feature_grids', ['nl' => ['title' => 'Kenmerken']]);
        $this->childWords('feature_grid_items', 3, ['nl' => ['title' => $payload, 'body' => 'Tekst'], 'en' => ['title' => '"><script>alert(2)</script>']]);
        $grid = fn (): string => $this->capture(fn () => render_section_feature_grid(BlockLocalization::words('feature_grids', self::ID) + [
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

        foreach (['feature grid' => $grid(), 'step list' => $steps, 'stat strip' => $strip, 'marquee' => $marquee] as $what => $html) {
            self::assertStringContainsString($escaped, $html, $what . ': a child label with markup is escaped text');
            self::assertStringNotContainsString('<img src=x', $html, $what);
            self::assertStringNotContainsString('<script', $html, $what);
            $this->assertNoLanguagePairs($html);
        }

        $this->answerIn('en');
        self::assertStringContainsString('&quot;&gt;&lt;script&gt;alert(2)&lt;/script&gt;', $grid(), 'the English title is escaped too');
    }

    // ------------------------------------------------------------ Openingssectie homepage

    public function testTheHomepageHeroComposesItsHeadlineAndPrintsTheRestAsText(): void
    {
        SiteLanguageFixture::useBilingual('en');
        $this->words('homepage_hero', [
            'nl' => ['title' => 'Wij maken <het> mooi', 'title_highlight' => '<het>', 'primary_label' => 'Contact', 'image_alt' => 'Een "werkbank"', 'badge_title' => 'Sinds 2010', 'badge_text' => 'Tekst'],
            'en' => ['title' => 'We make it beautiful', 'title_highlight' => 'beautiful', 'primary_label' => 'Get in touch', 'image_alt' => "A 'workbench'"],
        ]);
        $this->childWords('homepage_hero_stats', 7, ['nl' => ['primary_text' => '12 jaar', 'secondary_text' => '<b>ervaring</b>'], 'en' => ['primary_text' => '12 years']]);

        $english = $this->homepageHero(null);
        self::assertStringContainsString(
            '<h1 style="--hero-highlight-size: 100%">We make it <em>beautiful</em></h1>',
            $english,
            'the headline is markup built from escaped words and a hardcoded <em>'
        );
        self::assertStringContainsString('alt="A &#039;workbench&#039;"', $english);
        self::assertStringNotContainsString('hero__badge', $english, 'no badge in the English default');
        $this->assertNoLanguagePairs($english);

        $dutch = $this->homepageHero('nl');
        self::assertStringContainsString('>Wij maken <em>&lt;het&gt;</em> mooi</h1>', $dutch, 'an editor\'s angle brackets stay text inside the <em>');
        self::assertStringContainsString('&lt;b&gt;ervaring&lt;/b&gt;', $dutch, 'a stat caption is escaped text');
        self::assertStringNotContainsString('hero__badge', $dutch, 'the English default decides the badge in every language');
    }

    // ------------------------------------------------------------ Detailsectie (wave C): rich body, plain everything else

    public function testADetailSectionBodyIsRichTextInTheLanguageBeingRead(): void
    {
        SiteLanguageFixture::useBilingual('nl');
        $this->words('detail_sections', [
            'nl' => ['title' => 'Hout graveren', 'body' => '<p>Nederlands <strong>vet</strong></p>'],
            'en' => ['title' => 'Wood engraving', 'body' => '<p>English <strong>bold</strong></p>'],
        ]);

        self::assertStringContainsString('<div class="rich-content service-detail__body"><p>Nederlands <strong>vet</strong></p></div>', $this->detailSection('nl'));
        self::assertStringContainsString('<div class="rich-content service-detail__body"><p>English <strong>bold</strong></p></div>', $this->detailSection('en'));

        $this->words('detail_sections', ['nl' => ['title' => 'Hout graveren', 'body' => '<p>Alleen Nederlands</p>']]);
        self::assertStringContainsString('<p>Alleen Nederlands</p>', $this->detailSection('en'), 'a missing translation falls back to the default body');
    }

    public function testADetailSectionBodyOnlyInATranslationShowsNothing(): void
    {
        SiteLanguageFixture::useBilingual('en');
        $this->words('detail_sections', ['en' => ['title' => 'Wood engraving'], 'nl' => ['body' => '<p>Alleen Nederlands</p>']]);

        self::assertStringNotContainsString('service-detail__body', $this->detailSection(null));
        self::assertStringNotContainsString('service-detail__body', $this->detailSection('nl'), 'a Dutch body on an English-default site has no default body to show');
    }

    public function testADetailSectionBodyInAThirdLanguageNeedsNoCode(): void
    {
        SiteLanguageFixture::useLanguages([
            SiteLanguageFixture::language('de', isDefault: true, sortOrder: 0),
            SiteLanguageFixture::language('nl', sortOrder: 1),
            SiteLanguageFixture::language('en', sortOrder: 2),
        ]);
        $this->words('detail_sections', ['de' => ['title' => 'Holz', 'body' => '<p>Deutsch</p>'], 'en' => ['body' => '<p>English</p>']]);

        self::assertStringContainsString('<p>Deutsch</p>', $this->detailSection('nl'), 'untranslated Dutch falls back to German, the default');
        self::assertStringContainsString('<p>English</p>', $this->detailSection('en'));
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

        $render = function (?string $language): string {
            $this->answerIn($language);

            return $this->detailSection($language, [
                'main_image_path' => '/assets/media/x.webp',
                'cta_url' => '/contact',
                'points' => [BlockLocalization::words('detail_section_points', 8)],
                'images' => [['image_path' => '/assets/media/y.webp', 'width' => null, 'height' => null] + ['alt' => BlockLocalization::text('detail_section_images', 9, 'alt')]],
            ]);
        };

        $dutch = $render('nl');
        self::assertStringContainsString('<a href="https://example.test"', $dutch, 'a link in the body stays real markup');
        self::assertSame(4, substr_count($dutch, '>' . $escaped), 'the title, the CTA label, the closing note and a point title are escaped text');
        self::assertStringContainsString('alt="Foto &quot;met&quot; &lt;b&gt;markup&lt;/b&gt;"', $dutch, 'the main image alt stays in its attribute');
        self::assertStringContainsString('alt="Werkbank &quot;oud&quot;"', $dutch, 'a gallery image alt from its own row');

        $english = $render('en');
        self::assertStringContainsString('&quot;&gt;&lt;script&gt;alert(2)&lt;/script&gt;', $english, 'a point title is escaped in English too');
        self::assertStringContainsString('alt="Photo &#039; quote"', $english);
        self::assertStringContainsString('alt="&lt;b&gt;bench&lt;/b&gt;"', $english);

        foreach (['nl' => $dutch, 'en' => $english] as $language => $html) {
            foreach (['<script', 'onclick', 'onerror', '<iframe', 'onmouseover'] as $hostile) {
                self::assertStringNotContainsString($hostile, html_entity_decode($this->element($html, 'service-detail__body'), ENT_QUOTES), $hostile . ' survived in the ' . $language . ' body');
            }
            self::assertStringNotContainsString('<img src=x', $html);
            $this->assertNoLanguagePairs($html);
        }
    }

    public function testTheQuicknavLabelIsTheShortLabelOrTheTitleInTheLanguageBeingRead(): void
    {
        SiteLanguageFixture::useBilingual('nl');
        $this->words('detail_sections', ['nl' => ['nav_label' => '<b>Hout</b>', 'title' => 'Hout graveren'], 'en' => ['title' => 'Wood engraving']]);

        $render = function (string $language): string {
            $this->answerIn($language);

            return $this->capture(fn () => render_section_quicknav([
                ['anchor' => 'hout"><script>', 'label' => BlockLocalization::first('detail_sections', self::ID, ['nav_label', 'title'])],
            ]));
        };

        self::assertStringContainsString('<a href="#hout&quot;&gt;&lt;script&gt;">&lt;b&gt;Hout&lt;/b&gt;</a>', $render('nl'));
        self::assertStringContainsString('>Wood engraving</a>', $render('en'), 'the English title before the Dutch short label');
        self::assertStringContainsString('aria-label="Jump to section"', $render('en'), 'the landmark name is a code catalogue');
        $this->assertNoLanguagePairs($render('en'));
    }

    // ------------------------------------------------------------ Tekst met afbeelding and Kaarten-carrousel (wave C)

    public function testATextWithImagesPrintsEachItemsWordsFromItsOwnRowRichTextSanitizedAndTheRestAsText(): void
    {
        SiteLanguageFixture::useBilingual('nl');
        $payload = '<img src=x onerror=alert(1)> & "quoted"';
        $this->childWords('text_image_split_items', 10, [
            'nl' => ['eyebrow' => 'Over <mij>', 'title' => 'Het verhaal', 'body' => '<p>' . $payload . '</p><p><strong>Vet</strong></p>', 'button_label' => 'Contact', 'alt' => 'Een "werkplaats"'],
            'en' => ['title' => 'The story', 'body' => '<p>English paragraph</p>', 'alt' => "A 'workshop'"],
        ]);

        $render = function (string $language): string {
            $this->answerIn($language);
            $section = ['items' => [
                BlockLocalization::words('text_image_split_items', 10) + [
                    'image_side' => 'left', 'image_column' => '25', 'image_height' => 'small', 'image_focus' => 'bottom',
                    'button_url' => '/contact',
                    'image' => ['image_path' => '/assets/media/z.webp', 'width' => null, 'height' => null, 'alt' => BlockLocalization::text('text_image_split_items', 10, 'alt'), 'media_id' => null],
                ],
            ]];

            return $this->capture(fn () => render_section_text_image_split($section, false, 'split-test'));
        };

        $dutch = $render('nl');
        self::assertStringContainsString('>Het verhaal</h2>', $dutch);
        self::assertStringContainsString('>Over &lt;mij&gt;</p>', $dutch, 'the eyebrow is escaped text');
        self::assertStringContainsString('<strong>Vet</strong>', $dutch, 'the body is markup');
        self::assertStringNotContainsString('onerror', $dutch, 'sanitized on read');
        self::assertStringContainsString('alt="Een &quot;werkplaats&quot;"', $dutch);
        self::assertStringContainsString('text-image__item--image-left text-image__item--column-25 text-image__item--height-small', $dutch);
        self::assertStringContainsString('object-position: 50% 100%;', $dutch, 'the focus point as ImageFocus says');

        $english = $render('en');
        self::assertStringContainsString('>The story</h2>', $english);
        self::assertStringContainsString('<p>English paragraph</p>', $english);
        self::assertStringContainsString('alt="A &#039;workshop&#039;"', $english);
        $this->assertNoLanguagePairs($english);
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

        $render = function (?string $language): string {
            $this->answerIn($language);
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

            return $this->capture(fn () => render_section_card_carousel($content));
        };

        $english = $render(null);
        self::assertStringContainsString('>Materials &quot;we&quot; use</h2>', $english, 'the English default on an unprefixed URL');
        self::assertStringContainsString('aria-label="Materials &quot;we&quot; use"', $english, 'the carousel is named after its title, in its attribute');
        self::assertStringContainsString('alt="Oak &#039;board&#039;"', $english);
        self::assertStringContainsString('&quot;&gt;&lt;script&gt;alert(3)&lt;/script&gt;', $english, 'a tag, the third level, is escaped text');
        self::assertStringContainsString('aria-label="Previous card"', $english, 'a control label is a code catalogue in the language being read');

        $dutch = $render('nl');
        self::assertStringContainsString('>Materialen</h2>', $dutch);
        self::assertStringContainsString('alt="Eiken &quot;plank&quot;"', $dutch);
        self::assertStringContainsString('&lt;b&gt;Duurzaam&lt;/b&gt; &amp; &quot;eerlijk&quot;', $dutch);
        self::assertStringContainsString('aria-label="Vorige kaart"', $dutch);

        foreach ([$english, $dutch] as $html) {
            self::assertStringNotContainsString('<script', $html);
            self::assertStringNotContainsString('<b>', $html);
            $this->assertNoLanguagePairs($html);
        }
    }

    // ------------------------------------------------------------ helpers

    /** Answer the request in $language (null: an unprefixed URL, the default language). */
    private function answerIn(?string $language): void
    {
        RequestLanguage::reset();
        if ($language !== null) {
            RequestLanguage::set($language, true);
        }
    }

    /** No V1 output left: one language per document (phase 7). */
    private function assertNoLanguagePairs(string $html): void
    {
        foreach (['data-nl', 'data-en', 'data-lang-html'] as $v1) {
            self::assertStringNotContainsString($v1, $html, $v1 . ' is V1 output');
        }
    }

    /** @param array<string, mixed> $overrides */
    private function detailSection(?string $language, array $overrides = []): string
    {
        $this->answerIn($language);

        $words = BlockLocalization::words('detail_sections', self::ID);
        if (!BlockLocalization::hasDefaultWords('detail_sections', self::ID, 'body')) {
            $words['body'] = '';
        }

        $content = $overrides + $words + [
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

    private function formBlock(?string $language): string
    {
        $this->answerIn($language);

        return $this->capture(fn () => render_section_form(
            BlockLocalization::words('form_blocks', self::ID),
            (new BlockSamples())->form(),
            FormRenderState::fresh(FormRenderState::tokenFor('rendering-test', 'form'))
        ));
    }

    private function contactForm(string $language): string
    {
        $this->answerIn($language);

        return $this->capture(fn () => render_section_contact_form(
            ['state' => 'active', 'title' => BlockLocalization::words('contact_form_sections', self::ID)['title'], 'form_id' => null, 'allow_attachment' => false],
            null,
            FormRenderState::fresh(FormRenderState::tokenFor('rendering-test', 'contact')),
            ['email' => '', 'city' => '']
        ));
    }

    private function homepageHero(?string $language): string
    {
        $this->answerIn($language);

        $words = BlockLocalization::words('homepage_hero', self::ID);
        if (!BlockLocalization::hasDefaultWords('homepage_hero', self::ID, 'badge_title')
            || !BlockLocalization::hasDefaultWords('homepage_hero', self::ID, 'badge_text')) {
            $words['badge_title'] = '';
            $words['badge_text'] = '';
        }

        $hero = $words + [
            'state' => 'active',
            'title_highlight_size' => HomepageHeroContent::HIGHLIGHT_SIZE_DEFAULT,
            'primary_url' => '/contact',
            'secondary_url' => '',
            'image_path' => 'assets/images/hero.jpg',
            'media_type' => 'image',
            'video_path' => '',
            'layout' => 'media-right',
            'stats' => [BlockLocalization::words('homepage_hero_stats', 7)],
        ];

        return $this->capture(fn () => render_section_homepage_hero($hero));
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

    /**
     * The page-header partial, with the presence rule of
     * App\Service\PageHeroContent: no title in the default language, no words.
     *
     * @param array<string, mixed> $image
     */
    private function pageHero(?string $language, array $image = []): string
    {
        $this->answerIn($language);

        $words = BlockLocalization::hasDefaultWords('page_heroes', self::ID, 'title')
            ? BlockLocalization::words('page_heroes', self::ID)
            : BlockLocalization::words('page_heroes', 0);

        // The image's own keys first: its alt text replaces the header's
        // own `image_alt` word, as PageHeroContent::forSlug() layers them.
        $content = $image + $words + [
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

    /**
     * The gallery partial, with the button rule of App\Service\ItemGalleryContent,
     * and one item whose title arrives from its source in the language being read.
     *
     * @param array<string, mixed> $settings
     */
    private function gallery(?string $language, array $settings, string $itemTitle): string
    {
        $this->answerIn($language);

        $words = BlockLocalization::words('item_galleries', self::ID);
        if (!BlockLocalization::hasDefaultWords('item_galleries', self::ID, 'button_label')) {
            $words['button_label'] = '';
        }

        $content = $words + $settings + [
            'enable_lightbox' => false,
            'filter_categories' => [],
            'fallback_link_url' => '',
            'button_url' => '',
            'background' => 'default',
            'tight_top' => false,
            'items' => [[
                'image_path' => 'assets/images/x.jpg',
                'alt' => '',
                'title' => $itemTitle,
                'subtitle' => '',
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
