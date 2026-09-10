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

    public function testTheHeaderGetsItsButtonFromTheSettingsService(): void
    {
        $source = $this->source('partials/header.php');

        $this->assertStringContainsString('HeaderCta::forHeader()', $source);
        $this->assertStringContainsString('class="btn btn--sm"', $source, 'the button keeps its existing styling');
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
        $this->assertStringContainsString('data-en-aria=', $source, 'the accessible name follows the language switch');
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
     * The CTA reuses the one link resolver rather than growing a second
     * one — that is what makes an unavailable module route fail safely.
     */
    public function testTheCtaTargetGoesThroughTheSharedLinkResolver(): void
    {
        $source = $this->source('src/Service/HeaderCta.php');

        $this->assertStringContainsString('LinkResolver::resolve(', $source);
        $this->assertStringNotContainsString('RouteRegistry::url(', $source, 'no second resolver');
    }

    /**
     * Nothing an editor types becomes markup: the admin screen offers the
     * networks the closed registry knows and no way to add one.
     */
    public function testTheAdminScreenOffersOnlyRegisteredNetworks(): void
    {
        $source = $this->source('admin/header-footer.php');

        $this->assertStringContainsString('SocialProfiles::networks()', $source);
        $this->assertStringNotContainsString('svg', $source, 'no icon markup is editable');
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
