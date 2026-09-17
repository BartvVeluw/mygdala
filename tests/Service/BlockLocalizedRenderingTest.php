<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Service\Blocks\BlockLocalization;
use App\Service\Language\LocalizedValue;
use PHPUnit\Framework\TestCase;
use Tests\Support\SiteLanguageFixture;

require_once dirname(__DIR__, 2) . '/partials/section-rich-text.php';
require_once dirname(__DIR__, 2) . '/partials/section-cta-band.php';
require_once dirname(__DIR__, 2) . '/partials/section-contact-card.php';

/**
 * What a visitor gets from the three block types on per-language storage
 * (Multilingual 2.0 phase 3A): Tekstblok, Oproep met knop and Contactkaart,
 * rendered through their real partials from words pinned in
 * App\Service\Blocks\BlockLocalization, for every default language the
 * registry can have.
 *
 * The first render shows the website's default language, with the fallback
 * applied (the bug this phase fixes: an English-default site used to get the
 * Dutch rich-text body). The V1 switch keeps working from the same words:
 * plain text as a data-nl/data-en pair for core.js's textContent path, rich
 * text marked data-lang-html and only when the languages really differ.
 * No database: the registry comes from SiteLanguageFixture.
 */
final class BlockLocalizedRenderingTest extends TestCase
{
    private const ID = 41;

    protected function tearDown(): void
    {
        BlockLocalization::clearCache();
        SiteLanguageFixture::reset();
    }

    // ------------------------------------------------------------ Rich text

    public function testRichTextShowsTheDutchBodyFirstOnADutchSite(): void
    {
        SiteLanguageFixture::useBilingual('nl');
        $this->words('rich_text_sections', ['nl' => ['body' => '<p>Nederlandse <strong>tekst</strong></p>'], 'en' => ['body' => '<p>English <strong>text</strong></p>']]);

        $html = $this->richText();

        self::assertSame(
            '<div class="rich-content" data-lang-html data-nl="&lt;p&gt;Nederlandse &lt;strong&gt;tekst&lt;/strong&gt;&lt;/p&gt;" data-en="&lt;p&gt;English &lt;strong&gt;text&lt;/strong&gt;&lt;/p&gt;"><p>Nederlandse <strong>tekst</strong></p></div>',
            $this->element($html, 'rich-content')
        );
    }

    public function testRichTextShowsTheEnglishBodyFirstOnAnEnglishSite(): void
    {
        SiteLanguageFixture::useBilingual('en');
        $this->words('rich_text_sections', ['nl' => ['body' => '<p>Nederlandse tekst</p>'], 'en' => ['body' => '<p>English text</p>']]);

        $html = $this->richText();

        self::assertStringContainsString('"><p>English text</p></div>', $html, 'the first render is the default language, not the Dutch column');
        self::assertStringContainsString('data-nl="&lt;p&gt;Nederlandse tekst&lt;/p&gt;"', $html, 'the switch still has the Dutch body');
    }

    public function testAMissingEnglishBodyFallsBackToTheDefaultAndPrintsNoSwitchAttributes(): void
    {
        SiteLanguageFixture::useBilingual('nl');
        $this->words('rich_text_sections', ['nl' => ['body' => '<p>Alleen Nederlands</p>']]);

        self::assertSame(
            '<div class="rich-content"><p>Alleen Nederlands</p></div>',
            $this->element($this->richText(), 'rich-content'),
            'the same body in every language: the element a body without a translation always printed'
        );
    }

    public function testAMissingDutchBodyFallsBackToTheEnglishDefault(): void
    {
        SiteLanguageFixture::useBilingual('en');
        $this->words('rich_text_sections', ['en' => ['body' => '<p>Only English</p>']]);

        self::assertSame('<div class="rich-content"><p>Only English</p></div>', $this->element($this->richText(), 'rich-content'));
    }

    public function testAThirdLanguageAsTheDefaultIsWhatBothV1HalvesFallBackTo(): void
    {
        SiteLanguageFixture::useLanguages([
            SiteLanguageFixture::language('de', isDefault: true, sortOrder: 0),
            SiteLanguageFixture::language('nl', sortOrder: 1),
            SiteLanguageFixture::language('en', sortOrder: 2),
        ]);
        $this->words('rich_text_sections', ['de' => ['body' => '<p>Deutscher Text</p>'], 'en' => ['body' => '<p>English text</p>']]);

        $html = $this->richText();

        self::assertStringContainsString('data-nl="&lt;p&gt;Deutscher Text&lt;/p&gt;"', $html, 'untranslated Dutch falls back to German, the default');
        self::assertStringContainsString('data-en="&lt;p&gt;English text&lt;/p&gt;"', $html);
    }

    public function testRichTextStaysMarkupAndMaliciousMarkupIsSanitizedInEveryLanguage(): void
    {
        SiteLanguageFixture::useBilingual('nl');
        $this->words('rich_text_sections', [
            'nl' => ['body' => '<p>Veilig <a href="https://example.test" onclick="steal()">link</a></p><script>alert(1)</script><img src=x onerror=alert(1)>'],
            'en' => ['body' => '<p>Safe</p><iframe src="https://evil.test"></iframe><p style="x" onmouseover="alert(1)">hover</p>'],
        ]);

        $html = $this->richText();

        self::assertStringContainsString('<a href="https://example.test"', $html, 'links stay real markup');
        foreach (['<script', 'onclick', 'onerror', '<img', '<iframe', 'onmouseover', 'alert(1)'] as $hostile) {
            self::assertStringNotContainsString($hostile, html_entity_decode($html, ENT_QUOTES), $hostile . ' survived, visible or in the switch attributes');
        }
    }

    public function testAnEmptyBodyRendersNothingInAnyLanguage(): void
    {
        SiteLanguageFixture::useBilingual('nl');
        $this->words('rich_text_sections', ['nl' => ['body' => "  \n "], 'en' => ['body' => '']]);

        self::assertSame('', trim($this->richText()));
    }

    // ------------------------------------------------------------ CTA band

    public function testACtaBandPrintsItsPlainWordsAsTextWithTheV1Pair(): void
    {
        SiteLanguageFixture::useBilingual('nl');
        $this->words('cta_bands', [
            'nl' => ['eyebrow' => 'Nieuw', 'title' => 'Neem <b>contact</b> op', 'primary_label' => 'Contact'],
            'en' => ['title' => 'Get in <b>touch</b>'],
        ]);

        $html = $this->ctaBand(['primary_url' => '/contact', 'secondary_url' => '']);

        self::assertStringContainsString('<h2  data-nl="Neem &lt;b&gt;contact&lt;/b&gt; op" data-en="Get in &lt;b&gt;touch&lt;/b&gt;">Neem &lt;b&gt;contact&lt;/b&gt; op</h2>', $html);
        self::assertStringContainsString('<p class="eyebrow"  data-nl="Nieuw" data-en="Nieuw">Nieuw</p>', $html, 'an untranslated field falls back to the default language in both halves');
        self::assertStringNotContainsString('data-lang-html', $html, 'plain text is never marked as HTML');
        self::assertStringNotContainsString('class="lead"', $html, 'an empty lead renders nothing');
        self::assertStringNotContainsString('btn--ghost', $html, 'no secondary button without its words and URL');
    }

    public function testACtaBandOnAnEnglishSiteShowsEnglishFirst(): void
    {
        SiteLanguageFixture::useBilingual('en');
        $this->words('cta_bands', [
            'nl' => ['eyebrow' => 'Nieuw', 'title' => 'Titel', 'primary_label' => 'Knop', 'secondary_label' => 'Tweede'],
            'en' => ['eyebrow' => 'New', 'title' => 'Title', 'primary_label' => 'Button'],
        ]);

        $html = $this->ctaBand(['primary_url' => '/', 'secondary_url' => '/tweede']);

        self::assertStringContainsString('>Title</h2>', $html);
        self::assertStringContainsString('>New</p>', $html);
        self::assertStringNotContainsString('btn--ghost', $html, 'the English default has no secondary label, so there is no second button');
    }

    public function testAnEmptyCtaBandRendersNothing(): void
    {
        SiteLanguageFixture::useBilingual('nl');
        $this->words('cta_bands', ['en' => ['title' => 'Only English on a Dutch site']]);

        self::assertSame('', trim($this->ctaBand(['primary_url' => '/', 'secondary_url' => ''])), 'the default language has no title and no button label');
    }

    // ------------------------------------------------------------ Contact card

    public function testAContactCardFallsBackPerFieldAndStaysPlainText(): void
    {
        SiteLanguageFixture::useBilingual('nl');
        $this->words('contact_cards', [
            'nl' => ['title' => 'Liever mailen?', 'body' => 'Wij <script>antwoorden</script> snel.', 'button_label' => 'Mail ons'],
            'en' => ['title' => 'Rather email?'],
        ]);

        $html = $this->contactCard('mailto:info@example.test');

        self::assertStringContainsString('data-nl="Liever mailen?" data-en="Rather email?">Liever mailen?</h3>', $html);
        self::assertStringContainsString('data-nl="Mail ons" data-en="Mail ons">Mail ons</a>', $html);
        self::assertStringContainsString('Wij &lt;script&gt;antwoorden&lt;/script&gt; snel.', $html, 'plain text is escaped, never markup');
        self::assertStringNotContainsString('data-lang-html', $html);
    }

    public function testAContactCardInAThirdLanguageNeedsNoCode(): void
    {
        SiteLanguageFixture::useLanguages([
            SiteLanguageFixture::language('nl', isDefault: true, sortOrder: 0),
            SiteLanguageFixture::language('en', sortOrder: 1),
            SiteLanguageFixture::language('de', sortOrder: 2),
        ]);
        $this->words('contact_cards', ['nl' => ['title' => 'Titel'], 'de' => ['title' => 'Überschrift']]);

        self::assertSame('Überschrift', BlockLocalization::value('contact_cards', self::ID, 'title', 'de'));
        self::assertStringContainsString('>Titel</h3>', $this->contactCard(''), 'the V1 page keeps its Dutch first render');
    }

    // ------------------------------------------------------------ helpers

    /** @param array<string, array<string, string>> $translations */
    private function words(string $table, array $translations): void
    {
        BlockLocalization::overrideForTests($table, self::ID, $translations);
    }

    private function richText(): string
    {
        return $this->capture(static fn () => render_section_rich_text([
            'state' => 'active',
            'body' => BlockLocalization::bilingual('rich_text_sections', self::ID, 'body'),
        ]));
    }

    /** @param array{primary_url: string, secondary_url: string} $urls */
    private function ctaBand(array $urls): string
    {
        $content = ['state' => 'active'] + $urls;
        foreach (['eyebrow', 'title', 'lead', 'primary_label', 'secondary_label'] as $field) {
            $content[$field] = BlockLocalization::bilingual('cta_bands', self::ID, $field);
        }

        if ($content['secondary_url'] === '' || $content['secondary_label']->primaryValue() === '') {
            $content['secondary_label'] = LocalizedValue::of([]);
        }

        return $this->capture(static fn () => render_section_cta_band($content));
    }

    private function contactCard(string $buttonUrl): string
    {
        $content = ['state' => 'active', 'button_url' => $buttonUrl];
        foreach (['title', 'body', 'button_label'] as $field) {
            $content[$field] = BlockLocalization::bilingual('contact_cards', self::ID, $field);
        }

        return $this->capture(static fn () => render_section_contact_card($content));
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

    private function element(string $html, string $class): string
    {
        self::assertSame(1, preg_match('/<div class="' . preg_quote($class, '/') . '"[^>]*>.*?<\/div>/s', $html, $match), 'no .' . $class . ' element');

        return $match[0];
    }
}
