<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Service\Blocks\BlockLocalization;
use App\Service\Routing\RequestLanguage;
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
 * registry can have and every language a request can be answered in.
 *
 * ONE LANGUAGE PER RESPONSE (phase 7): the element carries the words of the
 * request's language, with the fallback applied, and nothing else — no
 * data-nl/data-en pair and no data-lang-html. Plain text is escaped; rich
 * text is RichTextSanitizer output. No database: the registry comes from
 * SiteLanguageFixture and the request's language from RequestLanguage.
 */
final class BlockLocalizedRenderingTest extends TestCase
{
    private const ID = 41;

    protected function tearDown(): void
    {
        BlockLocalization::clearCache();
        SiteLanguageFixture::reset();
        RequestLanguage::reset();
    }

    // ------------------------------------------------------------ Rich text

    public function testRichTextShowsTheBodyOfTheLanguageBeingRead(): void
    {
        SiteLanguageFixture::useBilingual('nl');
        $this->words('rich_text_sections', ['nl' => ['body' => '<p>Nederlandse <strong>tekst</strong></p>'], 'en' => ['body' => '<p>English <strong>text</strong></p>']]);

        self::assertSame(
            '<div class="rich-content"><p>Nederlandse <strong>tekst</strong></p></div>',
            $this->element($this->richText('nl'), 'rich-content')
        );
        self::assertSame(
            '<div class="rich-content"><p>English <strong>text</strong></p></div>',
            $this->element($this->richText('en'), 'rich-content')
        );
    }

    public function testAnUnprefixedRequestOnAnEnglishSiteGetsTheEnglishBody(): void
    {
        SiteLanguageFixture::useBilingual('en');
        $this->words('rich_text_sections', ['nl' => ['body' => '<p>Nederlandse tekst</p>'], 'en' => ['body' => '<p>English text</p>']]);

        $html = $this->richText(null);

        self::assertStringContainsString('<p>English text</p>', $html, 'no prefix means the default language, not the Dutch column');
        self::assertStringNotContainsString('Nederlandse', $html, 'the other language is not in the document at all');
    }

    public function testAMissingTranslationFallsBackToTheDefaultLanguage(): void
    {
        SiteLanguageFixture::useBilingual('nl');
        $this->words('rich_text_sections', ['nl' => ['body' => '<p>Alleen Nederlands</p>']]);

        self::assertSame(
            '<div class="rich-content"><p>Alleen Nederlands</p></div>',
            $this->element($this->richText('en'), 'rich-content')
        );
    }

    public function testAThirdLanguageNeedsNoCode(): void
    {
        SiteLanguageFixture::useLanguages([
            SiteLanguageFixture::language('de', isDefault: true, sortOrder: 0),
            SiteLanguageFixture::language('nl', sortOrder: 1),
            SiteLanguageFixture::language('en', sortOrder: 2),
        ]);
        $this->words('rich_text_sections', ['de' => ['body' => '<p>Deutscher Text</p>'], 'en' => ['body' => '<p>English text</p>']]);

        self::assertStringContainsString('<p>Deutscher Text</p>', $this->richText('de'));
        self::assertStringContainsString('<p>Deutscher Text</p>', $this->richText('nl'), 'untranslated Dutch falls back to German, the default');
        self::assertStringContainsString('<p>English text</p>', $this->richText('en'));
    }

    public function testABodyThatExistsOnlyAsATranslationShowsNothingAnywhere(): void
    {
        SiteLanguageFixture::useBilingual('nl');
        $this->words('rich_text_sections', ['en' => ['body' => '<p>Only English</p>']]);

        self::assertSame('', trim($this->richText('nl')));
        self::assertSame('', trim($this->richText('en')), 'the default language decides whether the block is there');
    }

    public function testRichTextStaysMarkupAndMaliciousMarkupIsSanitizedInEveryLanguage(): void
    {
        SiteLanguageFixture::useBilingual('nl');
        $this->words('rich_text_sections', [
            'nl' => ['body' => '<p>Veilig <a href="https://example.test" onclick="steal()">link</a></p><script>alert(1)</script><img src=x onerror=alert(1)>'],
            'en' => ['body' => '<p>Safe</p><iframe src="https://evil.test"></iframe><p style="x" onmouseover="alert(1)">hover</p>'],
        ]);

        foreach (['nl', 'en'] as $language) {
            $html = $this->richText($language);

            foreach (['<script', 'onclick', 'onerror', '<img', '<iframe', 'onmouseover', 'alert(1)'] as $hostile) {
                self::assertStringNotContainsString($hostile, html_entity_decode($html, ENT_QUOTES), $hostile . ' survived in ' . $language);
            }
        }

        self::assertStringContainsString('<a href="https://example.test"', $this->richText('nl'), 'links stay real markup');
    }

    public function testAnEmptyBodyRendersNothingInAnyLanguage(): void
    {
        SiteLanguageFixture::useBilingual('nl');
        $this->words('rich_text_sections', ['nl' => ['body' => "  \n "], 'en' => ['body' => '']]);

        self::assertSame('', trim($this->richText('nl')));
        self::assertSame('', trim($this->richText('en')));
    }

    // ------------------------------------------------------------ CTA band

    public function testACtaBandPrintsItsPlainWordsEscapedAndNothingElse(): void
    {
        SiteLanguageFixture::useBilingual('nl');
        $this->words('cta_bands', [
            'nl' => ['eyebrow' => 'Nieuw', 'title' => 'Neem <b>contact</b> op', 'primary_label' => 'Contact'],
            'en' => ['title' => 'Get in <b>touch</b>'],
        ]);

        $html = $this->ctaBand('en', ['primary_url' => '/contact', 'secondary_url' => '']);

        self::assertStringContainsString('<h2>Get in &lt;b&gt;touch&lt;/b&gt;</h2>', $html);
        self::assertStringContainsString('<p class="eyebrow">Nieuw</p>', $html, 'an untranslated field falls back to the default language');
        self::assertStringNotContainsString('data-nl', $html);
        self::assertStringNotContainsString('data-en', $html);
        self::assertStringNotContainsString('data-lang-html', $html);
        self::assertStringNotContainsString('class="lead"', $html, 'an empty lead renders nothing');
        self::assertStringNotContainsString('btn--ghost', $html, 'no secondary button without its words and URL');
    }

    public function testTheDefaultLanguageDecidesTheSecondaryButton(): void
    {
        SiteLanguageFixture::useBilingual('en');
        $this->words('cta_bands', [
            'nl' => ['eyebrow' => 'Nieuw', 'title' => 'Titel', 'primary_label' => 'Knop', 'secondary_label' => 'Tweede'],
            'en' => ['eyebrow' => 'New', 'title' => 'Title', 'primary_label' => 'Button'],
        ]);

        $english = $this->ctaBand(null, ['primary_url' => '/', 'secondary_url' => '/tweede']);
        self::assertStringContainsString('<h2>Title</h2>', $english);
        self::assertStringContainsString('<p class="eyebrow">New</p>', $english);
        self::assertStringNotContainsString('btn--ghost', $english, 'the English default has no secondary label, so there is no second button');

        $dutch = $this->ctaBand('nl', ['primary_url' => '/', 'secondary_url' => '/tweede']);
        self::assertStringNotContainsString('btn--ghost', $dutch, 'not in Dutch either: a translation alone never makes a button appear');
    }

    public function testACtaBandWithoutDefaultWordsRendersNothingInAnyLanguage(): void
    {
        SiteLanguageFixture::useBilingual('nl');
        $this->words('cta_bands', ['en' => ['title' => 'Only English on a Dutch site']]);

        self::assertSame('', trim($this->ctaBand('nl', ['primary_url' => '/', 'secondary_url' => ''])));
        self::assertSame('', trim($this->ctaBand('en', ['primary_url' => '/', 'secondary_url' => ''])), 'the default language has no title and no button label');
    }

    // ------------------------------------------------------------ Contact card

    public function testAContactCardFallsBackPerFieldAndStaysPlainText(): void
    {
        SiteLanguageFixture::useBilingual('nl');
        $this->words('contact_cards', [
            'nl' => ['title' => 'Liever mailen?', 'body' => 'Wij <script>antwoorden</script> snel.', 'button_label' => 'Mail ons'],
            'en' => ['title' => 'Rather email?'],
        ]);

        $html = $this->contactCard('en', 'mailto:info@example.test');

        self::assertStringContainsString('>Rather email?</h3>', $html);
        self::assertStringContainsString('>Mail ons</a>', $html, 'the untranslated button label falls back to the default language');
        self::assertStringContainsString('Wij &lt;script&gt;antwoorden&lt;/script&gt; snel.', $html, 'plain text is escaped, never markup');
        self::assertStringNotContainsString('data-nl', $html);
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

        self::assertStringContainsString('>Überschrift</h3>', $this->contactCard('de', ''));
        self::assertStringContainsString('>Titel</h3>', $this->contactCard('nl', ''));
        self::assertStringContainsString('>Titel</h3>', $this->contactCard('en', ''), 'English falls back to the Dutch default');
    }

    // ------------------------------------------------------------ helpers

    /** @param array<string, array<string, string>> $translations */
    private function words(string $table, array $translations): void
    {
        BlockLocalization::overrideForTests($table, self::ID, $translations);
    }

    /** Answer the request in $language (null: an unprefixed URL, the default language). */
    private function answerIn(?string $language): void
    {
        RequestLanguage::reset();
        if ($language !== null) {
            RequestLanguage::set($language, true);
        }
    }

    /** The rich-text partial, with the presence rule of App\Service\RichTextContent. */
    private function richText(?string $language): string
    {
        $this->answerIn($language);

        $body = BlockLocalization::hasDefaultWords('rich_text_sections', self::ID, 'body')
            ? BlockLocalization::text('rich_text_sections', self::ID, 'body')
            : '';

        return $this->capture(static fn () => render_section_rich_text(['state' => 'active', 'body' => $body]));
    }

    /**
     * The CTA partial, with the two rules of App\Service\CtaBandContent: the
     * default language decides whether the band has words, and whether the
     * secondary button is there.
     *
     * @param array{primary_url: string, secondary_url: string} $urls
     */
    private function ctaBand(?string $language, array $urls): string
    {
        $this->answerIn($language);

        $hasWords = BlockLocalization::hasDefaultWords('cta_bands', self::ID, 'title')
            || BlockLocalization::hasDefaultWords('cta_bands', self::ID, 'primary_label');

        $content = ['state' => 'active'] + $urls;
        foreach (['eyebrow', 'title', 'lead', 'primary_label', 'secondary_label'] as $field) {
            $content[$field] = $hasWords ? BlockLocalization::text('cta_bands', self::ID, $field) : '';
        }

        if ($content['secondary_url'] === '' || !BlockLocalization::hasDefaultWords('cta_bands', self::ID, 'secondary_label')) {
            $content['secondary_label'] = '';
        }

        return $this->capture(static fn () => render_section_cta_band($content));
    }

    private function contactCard(?string $language, string $buttonUrl): string
    {
        $this->answerIn($language);

        $content = ['state' => 'active', 'button_url' => $buttonUrl];
        foreach (['title', 'body', 'button_label'] as $field) {
            $content[$field] = BlockLocalization::text('contact_cards', self::ID, $field);
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
