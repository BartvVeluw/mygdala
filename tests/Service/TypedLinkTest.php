<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Database;
use App\Repository\SiteLanguageRepository;
use App\Service\Language\SiteLanguages;
use App\Service\PageContent;
use App\Service\PageLocalization;
use App\Service\PageTranslation;
use App\Service\Routing\RequestLanguage;
use App\Service\Routing\TypedLink;
use PHPUnit\Framework\TestCase;
use Tests\Support\PageFixture;

/**
 * AN ADDRESS AN EDITOR TYPED INTO A BLOCK, printed in the language being read
 * (Multilingual 2.0 phase 7, wave E; docs/multilingual/ROUTING.md).
 *
 * A button typed as '/over-ons' on the Dutch site has to take an English
 * visitor to the English version of that page, not back into Dutch — and an
 * address this code cannot place exactly has to stay exactly as typed. Both
 * halves are pinned here, against real pages.
 */
final class TypedLinkTest extends TestCase
{
    private const TRANSLATED = 'zz-typed-link';
    private const TRANSLATED_EN = 'zz-typed-link-en';
    private const UNTRANSLATED = 'zz-typed-link-only-nl';

    private bool $addedGerman = false;

    protected function setUp(): void
    {
        SiteLanguages::clearCache();
        if (SiteLanguages::defaultCode() !== 'nl' || !SiteLanguages::isActive('en')) {
            $this->markTestSkipped('this test expects the test database to publish nl (default) and en');
        }

        $translated = PageFixture::create(['content_key' => self::TRANSLATED, 'slug' => self::TRANSLATED, 'status' => PageContent::STATUS_PUBLISHED], 'Getypte link');
        PageLocalization::save($translated, 'en', [PageTranslation::TITLE => 'Typed link'], self::TRANSLATED_EN);
        PageFixture::create(['content_key' => self::UNTRANSLATED, 'slug' => self::UNTRANSLATED, 'status' => PageContent::STATUS_PUBLISHED], 'Alleen Nederlands');
        PageContent::clearCache();
    }

    protected function tearDown(): void
    {
        $db = Database::connection();
        $db->prepare('DELETE FROM pages WHERE content_key IN (?, ?)')->execute([self::TRANSLATED, self::UNTRANSLATED]);
        if ($this->addedGerman) {
            (new SiteLanguageRepository())->delete('de');
            $this->addedGerman = false;
        }

        RequestLanguage::reset();
        SiteLanguages::clearCache();
        PageContent::clearCache();
        PageLocalization::clearCache();
    }

    public function testAnAddressItCannotPlaceStaysExactlyAsTyped(): void
    {
        foreach ([
            '',
            'https://example.com/over-ons',
            '//cdn.example.com/x.pdf',
            'mailto:info@example.com',
            'tel:+31612345678',
            '#contact',
            '?pagina=2',
            'relatief/pad',
            '/iets-onbekends',
            '/iets/heel/diep',
            '/en/' . self::TRANSLATED_EN,
        ] as $typed) {
            self::assertSame($typed, TypedLink::href($typed, 'en'), $typed);
        }
    }

    public function testTheDefaultLanguageNeedsNoTranslation(): void
    {
        self::assertSame('/' . self::TRANSLATED, TypedLink::href('/' . self::TRANSLATED, 'nl'));
        self::assertSame('/', TypedLink::href('/', 'nl'));
    }

    public function testTheSiteRootIsThatLanguagesHome(): void
    {
        self::assertSame('/en/', TypedLink::href('/', 'en'));
        self::assertSame('/en/?utm=x#top', TypedLink::href('/?utm=x#top', 'en'));

        $this->addGerman();
        self::assertSame('/de/', TypedLink::href('/', 'de'), 'a third language is only a row in the registry');
    }

    public function testAPageIsItsVersionInThatLanguageWithTheQueryAndAnchorKept(): void
    {
        self::assertSame('/en/' . self::TRANSLATED_EN, TypedLink::href('/' . self::TRANSLATED, 'en'));
        self::assertSame('/en/' . self::TRANSLATED_EN . '#team', TypedLink::href('/' . self::TRANSLATED . '#team', 'en'));
        self::assertSame('/en/' . self::TRANSLATED_EN . '?a=1', TypedLink::href('/' . self::TRANSLATED . '?a=1', 'en'));
    }

    /** No version in this language: the page's default address, never a guessed '/en/...'. */
    public function testAPageWithoutAVersionInThatLanguageKeepsItsDefaultAddress(): void
    {
        self::assertSame('/' . self::UNTRANSLATED, TypedLink::href('/' . self::UNTRANSLATED, 'en'));

        $this->addGerman();
        self::assertSame('/' . self::TRANSLATED, TypedLink::href('/' . self::TRANSLATED, 'de'), 'no German version yet');
    }

    public function testARegisteredRouteIsThatRouteInThatLanguage(): void
    {
        self::assertSame('/en/cookiebeleid.php', TypedLink::href('/cookiebeleid.php', 'en'));
    }

    public function testWithoutALanguageItIsTheRequestsLanguage(): void
    {
        RequestLanguage::set('en', true);

        self::assertSame('/en/' . self::TRANSLATED_EN, TypedLink::href('/' . self::TRANSLATED));
    }

    /** Every typed block address goes through it, in the content class that reads it. */
    public function testEveryBlockThatPrintsATypedAddressTranslatesIt(): void
    {
        $root = dirname(__DIR__, 2);

        foreach ([
            // A block button with a destination goes through LinkChoice, whose
            // own address is a TypedLink (LinkChoiceTest pins that part).
            'src/Service/HomepageHeroContent.php' => ["LinkChoice::href(", "self::buttonHref(\$row, 'primary')", "self::buttonHref(\$row, 'secondary')"],
            'src/Service/CtaBandContent.php' => ["TypedLink::href((string) (\$row['primary_url']", "TypedLink::href((string) (\$row['secondary_url']"],
            'src/Service/ContactCardContent.php' => ['return TypedLink::href($stored);'],
            'src/Service/DetailSectionContent.php' => ["TypedLink::href((string) (\$row['cta_url']"],
            'src/Service/ItemGalleryContent.php' => ["TypedLink::href((string) (\$row['fallback_link_url']", "TypedLink::href((string) (\$row['button_url']"],
            'src/Service/TextImageSplitContent.php' => ["TypedLink::href((string) (\$row['button_url']"],
            'src/Service/CardCarouselContent.php' => ['return LinkChoice::href('],
            'src/Service/Routing/LinkChoice.php' => ['return TypedLink::href(trim($url));'],
        ] as $file => $calls) {
            $source = (string) file_get_contents($root . '/' . $file);
            foreach ($calls as $call) {
                self::assertStringContainsString($call, $source, $file);
            }
        }
    }

    private function addGerman(): void
    {
        if (SiteLanguages::exists('de')) {
            $this->markTestSkipped('the test database already registers de');
        }

        (new SiteLanguageRepository())->create('de', 'German', 'Deutsch');
        $this->addedGerman = true;
        SiteLanguages::clearCache();
    }
}
