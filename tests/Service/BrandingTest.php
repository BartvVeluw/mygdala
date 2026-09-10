<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Service\Branding;
use App\Service\Media\MediaService;
use App\Service\SiteSettings;
use PHPUnit\Framework\TestCase;

/**
 * The logo, the alternate logo, the favicon and the social image: the small
 * fallbacks that used to be spread across templates, or missing entirely.
 */
final class BrandingTest extends TestCase
{
    protected function tearDown(): void
    {
        SiteSettings::overrideForTests(null);
        MediaService::overrideForTests(null);
        parent::tearDown();
    }

    public function testAStoredPathIsMadeRootRelativeWhicheverWayItWasSaved(): void
    {
        SiteSettings::overrideForTests(['logo_path' => 'assets/images/logo.png']);
        $this->assertSame('/assets/images/logo.png', Branding::logoPath());

        SiteSettings::overrideForTests(['logo_path' => '/assets/images/logo.png']);
        $this->assertSame('/assets/images/logo.png', Branding::logoPath());
    }

    public function testAnAbsoluteUrlIsLeftAlone(): void
    {
        SiteSettings::overrideForTests(['logo_path' => 'https://cdn.example.com/logo.svg']);

        $this->assertSame('https://cdn.example.com/logo.svg', Branding::logoPath());
    }

    public function testNoLogoIsAnEmptyStringRatherThanALoneSlash(): void
    {
        SiteSettings::overrideForTests(['logo_path' => '']);

        $this->assertSame('', Branding::logoPath());
    }

    public function testTheAlternateLogoFallsBackToThePrimaryOne(): void
    {
        SiteSettings::overrideForTests([
            'logo_path' => 'assets/images/logo.png',
            'logo_alt_path' => '',
        ]);

        $this->assertSame('/assets/images/logo.png', Branding::alternateLogoPath());
        $this->assertFalse(Branding::hasAlternateLogo());
    }

    public function testAnAlternateLogoIsUsedWhenItIsSet(): void
    {
        SiteSettings::overrideForTests([
            'logo_path' => 'assets/images/logo.png',
            'logo_alt_path' => 'assets/images/branding/footer-logo.png',
        ]);

        $this->assertSame('/assets/images/branding/footer-logo.png', Branding::alternateLogoPath());
        $this->assertTrue(Branding::hasAlternateLogo());
    }

    public function testWithNoLogoAtAllTheAlternateIsAlsoEmpty(): void
    {
        SiteSettings::overrideForTests(['logo_path' => '', 'logo_alt_path' => '']);

        $this->assertSame('', Branding::alternateLogoPath());
    }

    /** @return list<array{string, string|null}> */
    public static function faviconTypeProvider(): array
    {
        return [
            ['assets/images/favicon-v.png', 'image/png'],
            ['assets/images/branding/icon.ICO', 'image/x-icon'],
            ['assets/images/branding/icon.svg', 'image/svg+xml'],
            ['assets/images/branding/icon.webp', 'image/webp'],
            ['assets/images/branding/icon.jpeg', 'image/jpeg'],
            ['assets/images/branding/icon.gif', 'image/gif'],
            ['assets/images/branding/icon.bin', null],
            ['assets/images/branding/icon', null],
            ['', null],
        ];
    }

    /** @dataProvider faviconTypeProvider */
    public function testTheFaviconTypeComesFromTheFileNotFromAnAssumption(string $path, ?string $expected): void
    {
        SiteSettings::overrideForTests(['favicon_path' => $path]);

        $this->assertSame($expected, Branding::faviconType());
    }

    public function testTheSocialImageIsPreservedAndMadeRootRelative(): void
    {
        SiteSettings::overrideForTests(['og_image_path' => 'assets/images/hero-collage-a.webp']);

        $this->assertSame('/assets/images/hero-collage-a.webp', Branding::socialImagePath());
    }

    /* ------------------------------------------------------------------ */
    /* Media Library: a chosen item wins, the stored path is the fallback   */
    /* ------------------------------------------------------------------ */

    public function testAChosenMediaItemWinsOverTheStoredPath(): void
    {
        MediaService::overrideForTests([
            7 => ['path' => 'assets/media/abc123.webp', 'mime_type' => 'image/webp'],
        ]);
        SiteSettings::overrideForTests([
            'logo_media_id' => '7',
            'logo_path' => 'assets/images/old-logo.png',
        ]);

        $this->assertSame('/assets/media/abc123.webp', Branding::logoPath());
    }

    public function testTheStoredPathStillWorksWhenNoMediaItemIsChosen(): void
    {
        MediaService::overrideForTests([]);
        SiteSettings::overrideForTests(['logo_path' => 'assets/images/old-logo.png']);

        $this->assertSame('/assets/images/old-logo.png', Branding::logoPath());
    }

    /**
     * The fallback is load-bearing, not decoration: a reference to a media
     * item that has since disappeared must not blank out a site's logo.
     */
    public function testAMediaIdPointingAtNothingFallsBackToTheStoredPath(): void
    {
        MediaService::overrideForTests([]);
        SiteSettings::overrideForTests([
            'logo_media_id' => '999999',
            'logo_path' => 'assets/images/old-logo.png',
        ]);

        $this->assertSame('/assets/images/old-logo.png', Branding::logoPath());
    }

    public function testTheAlternateLogoStillFallsBackToThePrimaryOne(): void
    {
        MediaService::overrideForTests([
            7 => ['path' => 'assets/media/primary.webp', 'mime_type' => 'image/webp'],
        ]);
        SiteSettings::overrideForTests(['logo_media_id' => '7', 'logo_alt_media_id' => '']);

        $this->assertFalse(Branding::hasAlternateLogo());
        $this->assertSame('/assets/media/primary.webp', Branding::alternateLogoPath());
    }

    public function testTheSocialImageComesFromTheChosenMediaItem(): void
    {
        MediaService::overrideForTests([
            3 => ['path' => 'assets/media/share.webp', 'mime_type' => 'image/webp'],
        ]);
        SiteSettings::overrideForTests(['og_image_media_id' => '3']);

        $this->assertSame('/assets/media/share.webp', Branding::socialImagePath());
    }

    /**
     * A media item knows its real type from the file header, which beats
     * guessing from an extension — and the site's own logo is an SVG that the
     * uploaders refuse but the library adopted in place, so this case is real.
     */
    public function testTheFaviconTypeComesFromTheMediaItemWhenThereIsOne(): void
    {
        MediaService::overrideForTests([
            5 => ['path' => 'assets/media/icon.png', 'mime_type' => 'image/png'],
        ]);
        SiteSettings::overrideForTests(['favicon_media_id' => '5']);

        $this->assertSame('image/png', Branding::faviconType());
    }
}
