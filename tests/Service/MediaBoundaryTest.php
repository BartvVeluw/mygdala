<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Module\ModuleDefinition;
use App\Module\ModuleRegistry;
use App\Module\PortfolioModule;
use App\Service\AdminNavigation;
use App\Service\AdminPermissions;
use App\Service\Media\MediaUsage;
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
        'rename-media.php' => 'media.manage',
        'delete-media-items.php' => 'media.manage',
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
            PortfolioModule::PORTFOLIO_MANAGE,
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
            'update-text-image-split-section.php',
            'create-detail-section-image.php',
            'update-detail-section-image.php',
            'update-detail-section-main-image.php',
            'update-carousel-card.php',
            'update-page-hero.php',
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
            'update-text-image-split-section.php',
            'create-detail-section-image.php',
            'update-detail-section-image.php',
            'update-detail-section-main-image.php',
            'update-carousel-card.php',
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

    /**
     * Where an item is used is told by one rule. No usage can be reported
     * without the permission that may read about it, and the two places that
     * print usages — the item view and the refusals of a selection — hand the
     * complete list to VisibleMediaUsages with the signed-in account's own
     * permissions instead of printing labels themselves. A refused single
     * delete names no place at all: it returns to the item view.
     */
    public function testWhereAnItemIsUsedIsToldOnlyAsFarAsTheReaderMayOpenThosePlaces(): void
    {
        $permission = array_values(array_filter(
            (new \ReflectionMethod(MediaUsage::class, '__construct'))->getParameters(),
            static fn (\ReflectionParameter $parameter): bool => $parameter->getName() === 'permission'
        ));

        $this->assertCount(1, $permission, 'a usage names the permission that may read about it');
        $this->assertFalse($permission[0]->isOptional(), 'and no provider can leave it out');
        $this->assertSame('string', (string) $permission[0]->getType());

        $screen = $this->source('admin/media.php');
        $this->assertStringContainsString('$usages = VisibleMediaUsages::of($service->usagesOf($item->id), AdminAuth::can(...));', $screen);
        $this->assertSame(1, substr_count($screen, '->usagesOf('), 'the item view reads usages through that rule only');
        $this->assertStringContainsString('foreach ($usages->shown as $usage)', $screen, 'and prints only the places the reader may open');

        $selection = $this->source('api/admin/delete-media-items.php');
        $this->assertStringContainsString("VisibleMediaUsages::of(\$kept['usages'], AdminAuth::can(...))", $selection);
        $this->assertStringNotContainsString('->label', $selection, 'no label reaches the JSON or the flash by another road');
        $this->assertStringNotContainsString('->editUrl', $selection);

        $this->assertStringNotContainsString("['usages']", $this->source('api/admin/delete-media.php'), 'a refused single delete names no place itself');
    }

    /* ------------------------------------------------------------------ */
    /* The library screen: adding files                                    */
    /* ------------------------------------------------------------------ */

    /**
     * Uploading goes through the shared file input (ADMIN-UI.md), several
     * files at once, and the drop zone is an addition around it — never the
     * only way in, because a keyboard and a phone need the button.
     */
    public function testTheUploadFormUsesTheSharedFileInputAndTheDropZoneOnlyAddsToIt(): void
    {
        $screen = $this->source('admin/media.php');

        $this->assertStringContainsString('admin_file_input([', $screen);
        $this->assertStringContainsString("'name' => 'files[]'", $screen);
        $this->assertStringContainsString("'multiple' => true", $screen);
        $this->assertStringContainsString('data-media-upload-input', $screen);
        $this->assertStringContainsString('data-media-dropzone', $screen);
        $this->assertStringNotContainsString('<input type="file"', $screen, 'no hand-written file input beside the primitive');

        // What the file dialog offers and what the queue checks come from
        // the uploader, which checks the same again.
        $this->assertStringContainsString('MediaUploader::ALLOWED_EXTENSIONS', $screen);
        $this->assertStringContainsString('MediaUploader::maxBytes()', $screen);
    }

    /**
     * Without a script the form still posts every chosen file to an endpoint
     * that answers with a redirect; the queue sends one file per request to
     * the JSON endpoint the picker already uses.
     */
    public function testUploadingWorksWithAndWithoutTheScript(): void
    {
        $screen = $this->source('admin/media.php');

        $this->assertMatchesRegularExpression(
            '#<form method="post" action="/api/admin/create-media\.php" enctype="multipart/form-data"[^>]*data-media-upload>#',
            $screen
        );
        $this->assertStringContainsString("'uploadUrl' => '/api/admin/media-upload.php'", $screen);

        $create = $this->source('api/admin/create-media.php');
        $this->assertStringContainsString("MediaUploader::filesFrom(\$_FILES['files']", $create);
        $this->assertStringContainsString('->uploadMany(', $create);
        $this->assertStringContainsString("header('Location: /admin/media.php", $create);

        $queue = $this->source('admin/assets/media-upload.js');
        $this->assertStringContainsString('body.append("file", entry.file', $queue, 'one file per request');
        $this->assertStringContainsString('URL.revokeObjectURL', $queue, 'a preview is released with its row');
    }

    /**
     * The library's scripts write no markup from strings, attach no inline
     * handler and carry no sentence an editor reads: the words come from the
     * catalog, through the page.
     */
    public function testTheLibraryScriptsWriteNoMarkupAndCarryNoSentences(): void
    {
        $catalog = require dirname(__DIR__, 2) . '/src/Service/Language/messages/nl.php';

        foreach (['admin/assets/media-upload.js', 'admin/assets/media-library.js'] as $script) {
            $source = $this->source($script);

            $this->assertStringNotContainsString('innerHTML', $source, $script);
            $this->assertStringNotContainsString('insertAdjacentHTML', $source, $script);
            $this->assertDoesNotMatchRegularExpression('/\.on[a-z]+\s*=[^=]|setAttribute\(\s*["\']on/', $source, $script);

            foreach ($catalog as $key => $text) {
                if (!str_starts_with($key, 'media.') || mb_strlen($text) < 12) {
                    continue;
                }

                $this->assertStringNotContainsString($text, $source, $script . ' carries the words of ' . $key);
            }
        }

        $screen = $this->source('admin/media.php');
        $start = (int) strpos($screen, 'data-media-upload>');
        $upload = substr($screen, $start, (int) strpos($screen, 'data-media-library') - $start);

        $this->assertNotSame('', $upload);
        $this->assertDoesNotMatchRegularExpression('/\son[a-z]+\s*=/i', $upload, 'the upload section attaches no inline handler');
    }

    /* ------------------------------------------------------------------ */
    /* The library screen: searching and filtering                         */
    /* ------------------------------------------------------------------ */

    /**
     * Search and filter use the shared controls (ADMIN-UI.md), only read — a
     * GET with no token, so a filtered view has a URL that survives a reload
     * — and offer exactly the kinds App\Service\Media\MediaType knows.
     */
    public function testTheLibraryIsSearchedAndFilteredWithTheSharedControls(): void
    {
        $screen = $this->source('admin/media.php');

        $this->assertMatchesRegularExpression('#<form method="get" action="/admin/media\.php" class="admin-toolbar[^"]*" role="search"#', $screen);
        $this->assertMatchesRegularExpression(
            '#<label class="admin-search">\s*<span class="admin-visually-hidden">#',
            $screen,
            'the search field keeps a name a screen reader can say'
        );
        $this->assertStringContainsString('<input type="search" name="q"', $screen);
        $this->assertStringContainsString('<select name="type" class="admin-select"', $screen);
        $this->assertStringContainsString('MediaType::all()', $screen, 'the options come from the closed list');
        $this->assertStringContainsString('MediaType::isKnown(', $screen, 'a type from the URL is checked against it');
        $this->assertStringContainsString('data-media-results', $screen);

        $script = $this->source('admin/assets/media-library.js');
        $this->assertStringContainsString('history.pushState', $script, 'a filtered view keeps an address of its own');
        $this->assertStringContainsString('"popstate"', $script, 'and Back redraws it');
    }

    /* ------------------------------------------------------------------ */
    /* The library screen: renaming and deleting a selection               */
    /* ------------------------------------------------------------------ */

    /**
     * The two write endpoints this screen added guard before they read
     * anything the request sent: login, permission, POST, the token — and
     * only then an id or a name.
     */
    public function testRenamingAndDeletingASelectionGuardBeforeReadingTheRequest(): void
    {
        foreach (['rename-media.php' => "\$_POST['name']", 'delete-media-items.php' => "\$_POST['media_ids']"] as $endpoint => $read) {
            $source = $this->source('api/admin/' . $endpoint);

            $login = strpos($source, 'AdminAuth::requireLoginForApi()');
            $permission = strpos($source, "AdminAuth::requirePermissionForApi('media.manage')");
            $post = strpos($source, "\$_SERVER['REQUEST_METHOD'] !== 'POST'");
            $csrf = strpos($source, "Csrf::validate(\$_POST['csrf_token'] ?? null)");
            $input = strpos($source, $read);

            foreach ([$login, $permission, $post, $csrf, $input] as $position) {
                $this->assertNotFalse($position, $endpoint);
            }

            $this->assertLessThan($permission, $login, $endpoint . ': login first');
            $this->assertLessThan($post, $permission, $endpoint . ': then the permission');
            $this->assertLessThan($csrf, $post, $endpoint . ': then POST only');
            $this->assertLessThan($input, $csrf, $endpoint . ': nothing is read before the token');
        }
    }

    /**
     * Deleting a selection never takes an address to return to from the
     * request, caps how much one request may delete, and leaves the decision
     * about what is in use to the service.
     */
    public function testDeletingASelectionTrustsNoAddressAndNoCount(): void
    {
        $source = $this->source('api/admin/delete-media-items.php');

        $this->assertStringContainsString('MediaType::isKnown(', $source);
        $this->assertStringContainsString("header('Location: ' . \$returnTo)", $source);
        $this->assertStringNotContainsString("\$_POST['return_to']", $source);
        $this->assertStringNotContainsString('HTTP_REFERER', $source);
        $this->assertStringContainsString('MediaService::MAX_DELETE_AT_ONCE', $source);
        $this->assertStringContainsString('->deleteMany(', $source);
    }

    /**
     * Selecting uses the shared checkbox with a name a screen reader can say,
     * is drawn for a manager only, asks before it deletes — with the focus on
     * the safe choice — and nothing on the screen is wired through an inline
     * handler.
     */
    public function testTheScreenSelectsWithSharedCheckboxesAndAsksBeforeDeleting(): void
    {
        $screen = $this->source('admin/media.php');

        $this->assertStringContainsString(
            '<input type="checkbox" class="admin-checkbox" name="media_ids[]" value="<?= (int) $gridItem->id ?>" form="media-bulk-form"',
            $screen,
            'a card is selected with the shared checkbox, which belongs to the selection form'
        );
        $this->assertStringContainsString("admin_te('media.select.label', ['name' => \$displayName])", $screen, 'every checkbox is named after its file');
        $this->assertMatchesRegularExpression(
            '#<\?php if \(\$canManage\): \?>\s*(<\?php /\*.*?\*/ \?>\s*)?<form method="post" action="/api/admin/delete-media-items\.php"#s',
            $screen,
            'the selection bar is drawn for a manager only'
        );

        foreach (['data-media-select-all', 'data-media-selected-count', 'data-media-bulk-actions', 'data-media-delete-dialog', 'data-media-rename-dialog'] as $hook) {
            $this->assertStringContainsString($hook, $screen);
        }

        $this->assertStringContainsString('autofocus data-media-dialog-close', $screen, 'the dialog opens on Annuleren');
        $this->assertStringContainsString(
            '<form method="post" action="/api/admin/delete-media.php"<?= admin_confirm_attributes(',
            $screen,
            'the single delete asks in the shared confirmation dialog'
        );
        $this->assertDoesNotMatchRegularExpression('/\son[a-z]+\s*=/i', $screen, 'no inline handler anywhere on the media screen');
    }

    /**
     * One way to ask before something is gone (ADMIN-UI.md). A single delete
     * on the item view asks in the CMS's shared dialog, printed once by the
     * screen, and the library keeps no confirmation hook of its own. Only
     * deleting a selection has a dialog of its own, because it shows what
     * will really go; without <dialog> its fallback question is the one place
     * the library's script still asks the browser.
     */
    public function testASingleDeleteAsksInTheSharedDialogAndOnlyASelectionHasItsOwn(): void
    {
        $screen = $this->source('admin/media.php');
        $script = $this->source('admin/assets/media-library.js');

        $this->assertStringContainsString("admin_t('media.delete.confirm', ['name' => \$item->displayName()])", $screen, 'the question names the file');
        $this->assertSame(1, substr_count($screen, '<?= admin_confirm_attributes('), 'only the single delete uses the shared question');
        $this->assertSame(1, substr_count($screen, '<?= admin_confirm_dialog() ?>'), 'the shared dialog is printed once');

        foreach (['admin/media.php' => $screen, 'admin/assets/media-library.js' => $script] as $file => $source) {
            $this->assertStringNotContainsString('data-media-confirm', $source, $file . ' keeps no confirmation hook of its own');
        }

        $this->assertSame(1, substr_count($script, 'window.confirm('), 'the script asks the browser in one place only');
        $this->assertMatchesRegularExpression(
            '/function fillDeleteDialogText\(chosen, event\) \{[\s\S]*?window\.confirm\(question\)/',
            $script,
            'and that place is the selection\'s fallback without <dialog>'
        );
        $this->assertMatchesRegularExpression(
            '#<\?php if \(\$item === null\): \?>\s*(<\?php /\*.*?\*/ \?>\s*)?<script src="<\?= AssetVersion::url\(\'/admin/assets/media-library\.js\'\) \?>" defer></script>#s',
            $screen,
            'the library script is loaded for the grid only: the item view has nothing left for it to do'
        );
    }

    /**
     * A rename sends an id and the part of the name before the extension —
     * never an extension, a path or a filename — and nothing in the library's
     * code renames a file on disk.
     */
    public function testRenamingChangesANameAndNeverAFile(): void
    {
        $screen = $this->source('admin/media.php');

        $this->assertStringContainsString('action="/api/admin/rename-media.php"', $screen);
        $this->assertStringContainsString('MediaFilename::withoutExtension(', $screen);
        $this->assertStringContainsString('data-media-rename-extension', $screen);
        $this->assertStringNotContainsString('name="extension"', $screen);
        $this->assertStringNotContainsString('name="path"', $screen);

        $files = array_merge($this->mediaSourceFiles(), [dirname(__DIR__, 2) . '/api/admin/rename-media.php']);

        foreach ($files as $file) {
            $this->assertDoesNotMatchRegularExpression(
                '/(?<!->)(?<!::)(?<!function )\brename\s*\(/',
                (string) file_get_contents($file),
                basename($file) . ' must not rename a file on disk'
            );
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
