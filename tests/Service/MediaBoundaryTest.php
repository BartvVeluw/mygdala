<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Module\ModuleDefinition;
use App\Module\ModuleRegistry;
use App\Service\AdminNavigation;
use App\Service\AdminPermissions;
use App\Service\Media\MediaUsageProvider;
use App\Service\Media\MediaUsageRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Where the Media Library's edges are, checked against the source rather than
 * against a running site: what its screens and endpoints demand before they
 * do anything, and what Core is allowed to know.
 *
 * Reads files, opens no database and makes no request, so it belongs in the
 * fast tier alongside Tests\Module\ShopDisabledTest — the test that already
 * guards the other half of this boundary.
 */
final class MediaBoundaryTest extends TestCase
{
    private const MEDIA_ENDPOINTS = [
        'media-list.php' => 'media.view',
        'media-upload.php' => 'media.view',
        'create-media.php' => 'media.view',
        'update-media.php' => 'media.manage',
        'delete-media.php' => 'media.manage',
    ];

    /* ------------------------------------------------------------------ */
    /* Permissions                                                         */
    /* ------------------------------------------------------------------ */

    public function testBothMediaPermissionsExistAndAreCore(): void
    {
        $this->assertTrue(AdminPermissions::isValid(AdminPermissions::MEDIA_VIEW));
        $this->assertTrue(AdminPermissions::isValid(AdminPermissions::MEDIA_MANAGE));

        // Core, not a module: media is not switchable off.
        $this->assertTrue(AdminPermissions::isEnabled(AdminPermissions::MEDIA_VIEW));
        $this->assertTrue(AdminPermissions::isEnabled(AdminPermissions::MEDIA_MANAGE));
    }

    /**
     * Managing implies using. Nothing implies MANAGING — deleting from a
     * shared library, and rewriting alt text several pages depend on, stays a
     * grant somebody hands out on purpose.
     */
    public function testManagingMediaImpliesUsingItAndNothingImpliesManaging(): void
    {
        $implies = AdminPermissions::implies();

        $this->assertContains(AdminPermissions::MEDIA_VIEW, $implies[AdminPermissions::MEDIA_MANAGE] ?? []);

        foreach ($implies as $source => $implied) {
            if ($source === AdminPermissions::MEDIA_MANAGE) {
                continue;
            }

            $this->assertNotContains(
                AdminPermissions::MEDIA_MANAGE,
                $implied,
                $source . ' must not quietly hand out the right to delete shared media'
            );
        }
    }

    /**
     * An editor who could already upload an image through a block's own file
     * field must not lose that when the picker replaces it.
     */
    public function testEveryContentPermissionCarriesTheRightToUseTheLibrary(): void
    {
        foreach ([
            AdminPermissions::PAGES_MANAGE,
            AdminPermissions::PORTFOLIO_MANAGE,
            AdminPermissions::SETTINGS_MANAGE,
        ] as $permission) {
            $this->assertContains(
                AdminPermissions::MEDIA_VIEW,
                AdminPermissions::expand([$permission]),
                $permission . ' must include being able to pick an image'
            );
        }
    }

    public function testNobodyGetsMediaManageJustByEditingPages(): void
    {
        $this->assertNotContains(
            AdminPermissions::MEDIA_MANAGE,
            AdminPermissions::expand([AdminPermissions::PAGES_MANAGE])
        );
    }

    /* ------------------------------------------------------------------ */
    /* Guards on the screens and the endpoints                             */
    /* ------------------------------------------------------------------ */

    public function testEveryMediaEndpointDemandsItsPermissionAndAWriteDemandsACsrfToken(): void
    {
        foreach (self::MEDIA_ENDPOINTS as $endpoint => $permission) {
            $source = $this->source('api/admin/' . $endpoint);

            $this->assertStringContainsString('AdminAuth::requireLoginForApi()', $source, $endpoint);
            $this->assertStringContainsString(
                "AdminAuth::requirePermissionForApi('" . $permission . "')",
                $source,
                $endpoint . ' must demand ' . $permission
            );

            if ($endpoint === 'media-list.php') {
                // A read changes nothing, and a token on a GET only invites
                // somebody to put one in a URL.
                $this->assertStringNotContainsString('Csrf::validate', $source, 'the listing is read-only');
                continue;
            }

            $this->assertStringContainsString('Csrf::validate(', $source, $endpoint . ' writes and must check CSRF');
            $this->assertLessThan(
                strpos($source, 'Csrf::validate('),
                strpos($source, 'AdminAuth::requirePermissionForApi'),
                $endpoint . ': the permission comes before the token'
            );
        }
    }

    public function testTheLibraryScreenGuardsItselfRatherThanRelyingOnTheMenu(): void
    {
        $source = $this->source('admin/media.php');

        $this->assertStringContainsString("AdminAuth::requirePermission('media.view')", $source);
        // The destructive half of the screen is drawn only for a manager, but
        // the endpoints behind it enforce it regardless.
        $this->assertStringContainsString('AdminPermissions::MEDIA_MANAGE', $source);
    }

    public function testTheSidebarOffersMediaBehindTheSamePermissionTheScreenDemands(): void
    {
        $entry = null;
        foreach (AdminNavigation::items() as $item) {
            if ($item['key'] === 'media') {
                $entry = $item;
            }
        }

        $this->assertNotNull($entry, 'the Media Library needs a way in');
        $this->assertSame(AdminPermissions::MEDIA_VIEW, $entry['permission']);
        $this->assertSame('/admin/media.php', $entry['url']);
    }

    /**
     * The picker hands back an id and nothing else. If a path, a filename or
     * a URL could come out of it, a crafted POST could name any file on the
     * server.
     */
    public function testThePickerSubmitsOnlyAnIdAndTheEndpointsResolveItThemselves(): void
    {
        $picker = $this->source('admin/_media_picker.php');

        $this->assertStringContainsString('data-media-picker-input', $picker);
        $this->assertStringNotContainsString('name="media_path"', $picker);
        $this->assertStringNotContainsString('type="file" name="media', $picker);

        foreach ([
            'create-text-image-split-image.php',
            'update-text-image-split-image.php',
            'create-detail-section-image.php',
            'update-detail-section-image.php',
            'update-detail-section-main-image.php',
            'update-carousel-card-image.php',
        ] as $endpoint) {
            $source = $this->source('api/admin/' . $endpoint);

            $this->assertStringContainsString(
                'BlockImage::fromRequest(',
                $source,
                $endpoint . ' must resolve the submitted id against the library'
            );
            $this->assertStringNotContainsString(
                "\$_POST['image_path']",
                $source,
                $endpoint . ' must never take a path from the request'
            );
        }
    }

    /**
     * These editors no longer own an upload of their own. That is the whole
     * point of the step, and the check that keeps a file field from creeping
     * back in beside the picker.
     */
    public function testTheIntegratedBlockEndpointsNoLongerUploadFilesThemselves(): void
    {
        foreach ([
            'create-text-image-split-image.php',
            'update-text-image-split-image.php',
            'create-detail-section-image.php',
            'update-detail-section-image.php',
            'update-detail-section-main-image.php',
            'update-carousel-card-image.php',
            'update-site-settings.php',
            'update-page.php',
        ] as $endpoint) {
            $source = $this->source('api/admin/' . $endpoint);

            $this->assertStringNotContainsString('SectionImageUploader', $source, $endpoint);
            $this->assertStringNotContainsString('BrandingAssetUploader', $source, $endpoint);
        }
    }

    /* ------------------------------------------------------------------ */
    /* Module boundary                                                     */
    /* ------------------------------------------------------------------ */

    /**
     * Core Media must not know what a product, a variant or a collection is.
     * A module answers for its own tables through a provider — that is the
     * contract, and this is the check that it has not been shortcut.
     */
    public function testCoreMediaNamesNoShopClassAndNoShopTable(): void
    {
        $forbidden = [
            'ProductRepository',
            'ProductImageRepository',
            'VariantImageRepository',
            'CollectionRepository',
            'PersonalizationUpload',
            'ContactAttachment',
            'product_images',
            'variant_images',
            'personalization_uploads',
            'contact_request_attachments',
        ];

        foreach ($this->mediaSourceFiles() as $file) {
            $source = (string) file_get_contents($file);

            foreach ($forbidden as $needle) {
                $this->assertStringNotContainsString(
                    $needle,
                    $source,
                    basename($file) . ' must not know about ' . $needle
                );
            }
        }
    }

    /**
     * A module answers for its own media, and the Blog is the first one that
     * does (MEDIA.md). What matters here is not WHICH modules contribute but
     * that every provider is a real one of the contract's own type — Core
     * Media still never names a table of theirs.
     */
    public function testAModuleAnswersForItsOwnMediaThroughTheContributionPoint(): void
    {
        $this->assertTrue(
            method_exists(ModuleDefinition::class, 'mediaUsageProviders'),
            'a module needs a way to answer for its own media'
        );

        foreach (ModuleRegistry::all() as $module) {
            foreach ($module->mediaUsageProviders() as $provider) {
                $this->assertInstanceOf(MediaUsageProvider::class, $provider, $module->key());
                $this->assertNotSame('', trim($provider->key()), $module->key());
                $this->assertNotSame('', trim($provider->label()), $module->key());
            }
        }

        $blogProviders = ModuleRegistry::definition('blog')?->mediaUsageProviders() ?? [];
        $this->assertCount(1, $blogProviders, 'the Blog answers for its posts');
    }

    /**
     * Whatever the providers are, each must be able to answer about a whole
     * batch at once. That is what keeps the library listing from growing a
     * query per thumbnail.
     */
    public function testEveryUsageProviderAnswersForABatch(): void
    {
        foreach (MediaUsageRegistry::providers() as $provider) {
            $this->assertInstanceOf(MediaUsageProvider::class, $provider);
            $this->assertNotSame('', $provider->key());
            $this->assertNotSame('', $provider->label());

            $parameters = (new \ReflectionMethod($provider, 'usagesFor'))->getParameters();
            $this->assertCount(1, $parameters);
            $this->assertSame('array', (string) $parameters[0]->getType());
        }
    }

    /* ------------------------------------------------------------------ */
    /* Helpers                                                             */
    /* ------------------------------------------------------------------ */

    /** @return list<string> */
    private function mediaSourceFiles(): array
    {
        $root = dirname(__DIR__, 2);

        return array_merge(
            (array) glob($root . '/src/Service/Media/*.php'),
            (array) glob($root . '/src/Service/Media/Usage/*.php'),
            [$root . '/src/Repository/MediaRepository.php']
        );
    }

    private function source(string $relative): string
    {
        $path = dirname(__DIR__, 2) . '/' . $relative;
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }
}
