<?php

declare(strict_types=1);

namespace Tests\Service;

use PHPUnit\Framework\TestCase;

/**
 * Source-level guard over the shared header and footer, in the same spirit
 * as Tests\Service\SiteIdentityTest (which polices the company NAME): after
 * Header & Footer V1 the shell carries no site-specific COPY and no
 * site-specific TARGET either.
 *
 * Reads files, opens nothing. The point is that a later edit which puts a
 * literal back — the quickest way to "just change the button text" — fails
 * here instead of quietly making the CMS single-tenant again.
 */
final class HeaderFooterContractTest extends TestCase
{
    /**
     * The literals that lived in the shell until this step, each now a
     * setting. Written in pieces where a whole phrase would otherwise appear
     * in this file and in the migration that pinned it, which is fine, but
     * these are the exact strings that must not come back.
     *
     * @var array<string, list<string>>
     */
    private const RETIRED_LITERALS = [
        'partials/header.php' => [
            'Vraag offerte aan',
            'Request a quote',
            '/contact.php',
        ],
        'partials/footer.php' => [
            'Ontworpen',
            'Designed &amp; built',
            'Nijmegen',
        ],
    ];

    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    private function source(string $relativePath): string
    {
        $path = self::root() . '/' . $relativePath;
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }

    public function testTheSharedShellCarriesNoSiteSpecificCopyOrTarget(): void
    {
        foreach (self::RETIRED_LITERALS as $file => $literals) {
            $source = $this->source($file);

            foreach ($literals as $literal) {
                $this->assertStringNotContainsString(
                    $literal,
                    $source,
                    $file . ' must read "' . $literal . '" from a setting, not carry it as a literal'
                );
            }
        }
    }

    /**
     * Since Navigation phase A the header's buttons are navigation items: the
     * partial asks NavigationService for them, prints the class the closed
     * variant list gives it, and the primary variant is exactly the styling
     * the single header CTA had.
     */
    public function testTheHeaderGetsItsButtonsFromTheNavigation(): void
    {
        $source = $this->source('partials/header.php');

        $this->assertStringContainsString('NavigationService::header()', $source);
        $this->assertStringContainsString('class="<?= $h($headerButton[\'class\']) ?>"', $source);
        $this->assertStringNotContainsString('HeaderCta', $source, 'the settings-based single button is retired');
        $this->assertFileDoesNotExist(self::root() . '/src/Service/HeaderCta.php');

        $this->assertSame(
            'btn btn--sm',
            \App\Service\NavigationPresentation::buttonClass(\App\Service\NavigationPresentation::VARIANT_PRIMARY),
            'one button keeps its existing styling'
        );
    }

    public function testTheFooterGetsItsSloganAndSocialRowFromTheSettingsServices(): void
    {
        $source = $this->source('partials/footer.php');

        $this->assertStringContainsString('FooterService::slogan()', $source);
        $this->assertStringContainsString('SocialProfiles::forFooter()', $source);
    }

    /**
     * An icon-only link is meaningless to a screen reader without a name of
     * its own, and its glyph must be hidden so the name is not read twice.
     */
    public function testEverySocialLinkIsNamedForAssistiveTechnology(): void
    {
        $source = $this->source('partials/footer.php');

        $this->assertStringContainsString('aria-label="<?= $h($socialLabel) ?>"', $source);
        $this->assertStringContainsString("SiteText::pick(['nl' => 'op', 'en' => 'on'])", $source, 'the accessible name is in the language being read');
        $this->assertStringNotContainsString('data-en-aria=', $source, 'no V1 pair');
        $this->assertStringContainsString('aria-hidden="true"', $source);
    }

    /** An outbound profile link opens safely or not at all. */
    public function testSocialLinksOpenSafely(): void
    {
        $this->assertStringContainsString(
            'rel="noopener noreferrer me"',
            $this->source('partials/footer.php')
        );
    }

    /**
     * Menu links and header buttons reuse the one link resolver rather than
     * growing a second one — that is what makes an unavailable module route
     * fail safely.
     */
    public function testHeaderTargetsGoThroughTheSharedLinkResolver(): void
    {
        $source = $this->source('src/Service/NavigationService.php');

        $this->assertStringContainsString('LinkResolver::resolve(', $source);
        $this->assertStringNotContainsString('RouteRegistry::url(', $source, 'no second resolver');
    }

    /**
     * Nothing an editor types becomes markup: the Footer screen offers the
     * networks the closed registry knows and no way to add one, and its
     * endpoints check an address with the one method the footer uses too.
     */
    public function testTheFooterScreenOffersOnlyRegisteredNetworks(): void
    {
        $source = $this->source('admin/footer.php');

        $this->assertStringContainsString('SocialProfiles::networks()', $source);
        $this->assertStringNotContainsString('<svg', $source, 'no icon markup is editable');

        $rules = $this->source('api/admin/_footer_social_link_input.php');
        $this->assertStringContainsString('SocialProfiles::isKnownNetwork(', $rules);
        $this->assertStringContainsString('SocialProfiles::isValidProfileUrl(', $rules);
        $this->assertStringContainsString('if (!self::isValidProfileUrl($network, $url)) {', $this->source('src/Service/SocialProfiles.php'), 'forFooter() applies the same check');
    }

    /**
     * Footer phase B made the old "Slotregel & social media" screen a
     * redirect to the one Footer screen, and nothing writes the seven legacy
     * social_*_url settings any more.
     */
    public function testTheOldScreenOnlyRedirectsAndNothingWritesTheLegacySocialSettings(): void
    {
        $old = $this->source('admin/header-footer.php');

        $this->assertStringContainsString("header('Location: /admin/footer.php#footer-bottom', true, 302);", $old);
        $this->assertStringNotContainsString('<form', $old);
        $this->assertFileDoesNotExist(self::root() . '/api/admin/update-header-footer-settings.php');

        foreach (array_merge((array) glob(self::root() . '/api/admin/*.php'), (array) glob(self::root() . '/admin/*.php')) as $path) {
            $this->assertDoesNotMatchRegularExpression(
                "/'social_[a-z]+_url'/",
                (string) file_get_contents((string) $path),
                basename((string) $path) . ' must not read or write a legacy social setting'
            );
        }
    }

    /** The stylesheet the social row needs is Core's, not a new file. */
    public function testTheSocialRowStylingLivesInCore(): void
    {
        $this->assertStringContainsString('.social-row', $this->source('assets/css/core.css'));
    }

    /** The token rename left two dead references behind; they are gone. */
    public function testNoPartialStillReferencesTheRetiredInkToken(): void
    {
        foreach ((array) glob(self::root() . '/partials/*.php') as $path) {
            $this->assertStringNotContainsString(
                '--ink-soft',
                (string) file_get_contents((string) $path),
                basename((string) $path) . ' uses a colour token that no stylesheet defines'
            );
        }
    }
}
