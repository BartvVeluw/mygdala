<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Service\AdminNavigation;
use App\Service\AdminPermissions;
use App\Service\Blocks\BlockDefinitions;
use App\Service\Blocks\ContactFormBlock;
use App\Service\Blocks\FormBlock;
use App\Service\Forms\FormFieldTypes;
use PHPUnit\Framework\TestCase;

/**
 * Where Core Forms' edges are, checked against the SOURCE rather than
 * against a running site: what its screens and endpoints demand before they
 * do anything, what the public endpoint may and may not take from a request,
 * and what Core is allowed to know.
 *
 * Reads files, opens no database and makes no request, so it belongs in the
 * fast tier alongside Tests\Service\MediaBoundaryTest and
 * Tests\Module\ShopDisabledTest, which guard the same kind of boundary.
 */
final class FormBoundaryTest extends TestCase
{
    /** Every admin write endpoint of Core Forms, and the permission it demands. */
    private const ADMIN_ENDPOINTS = [
        'create-form.php' => 'forms.manage',
        'update-form.php' => 'forms.manage',
        'delete-form.php' => 'forms.manage',
        'create-form-field.php' => 'forms.manage',
        'update-form-field.php' => 'forms.manage',
        'move-form-field.php' => 'forms.manage',
        'delete-form-field.php' => 'forms.manage',
        'delete-form-submission.php' => 'forms.submissions',
        // Placing a form on a page is content work, not form management.
        'update-form-block.php' => 'pages.manage',
        'update-contact-form.php' => 'pages.manage',
    ];

    /** Admin screens and the permission each one is behind. */
    private const ADMIN_SCREENS = [
        'forms.php' => 'forms.manage',
        'form.php' => 'forms.manage',
        'form-field.php' => 'forms.manage',
        'form-submissions.php' => 'forms.submissions',
        'form-submission.php' => 'forms.submissions',
        'form-block.php' => 'pages.manage',
        'contact-form.php' => 'pages.manage',
    ];

    /** Every public page template that can carry a form block. */
    private const PAGE_TEMPLATES = [
        'index.php',
        'shop.php',
        'diensten.php',
        'portfolio.php',
        'over-mij.php',
        'contact.php',
        'pagina.php',
    ];

    /* ------------------------------------------------------------------ */
    /* Permissions                                                         */
    /* ------------------------------------------------------------------ */

    public function testBothFormsPermissionsExistAndAreCore(): void
    {
        $this->assertTrue(AdminPermissions::isValid(AdminPermissions::FORMS_MANAGE));
        $this->assertTrue(AdminPermissions::isValid(AdminPermissions::FORMS_SUBMISSIONS));

        // Core, not a module: a CMS without forms is not a thing this
        // project ships, and neither permission may be switchable off.
        $this->assertTrue(AdminPermissions::isEnabled(AdminPermissions::FORMS_MANAGE));
        $this->assertTrue(AdminPermissions::isEnabled(AdminPermissions::FORMS_SUBMISSIONS));
    }

    /**
     * THE permission rule of this feature: an editor who may build a form
     * does not thereby get to read what people sent through it, and neither
     * does an editor who may edit pages. Personal data is a grant somebody
     * hands out on purpose.
     */
    public function testNothingQuietlyGrantsAccessToSubmissions(): void
    {
        foreach (AdminPermissions::implies() as $source => $implied) {
            $this->assertNotContains(
                AdminPermissions::FORMS_SUBMISSIONS,
                $implied,
                '"' . $source . '" must not imply access to form submissions'
            );
        }
    }

    public function testManagingFormsIsNotImpliedByEditingPages(): void
    {
        $implies = AdminPermissions::implies();

        $this->assertNotContains(AdminPermissions::FORMS_MANAGE, $implies[AdminPermissions::PAGES_MANAGE] ?? []);
    }

    public function testBothSectionsAreInTheAdminSidebarBehindTheirOwnPermission(): void
    {
        $items = [];
        foreach (AdminNavigation::items() as $item) {
            $items[$item['key']] = $item;
        }

        $this->assertArrayHasKey('forms', $items);
        $this->assertSame(AdminPermissions::FORMS_MANAGE, $items['forms']['permission']);

        $this->assertArrayHasKey('form_submissions', $items);
        $this->assertSame(AdminPermissions::FORMS_SUBMISSIONS, $items['form_submissions']['permission']);
    }

    /**
     * Every Forms admin screen must be reachable from the sidebar's
     * highlight map, or opening one would un-highlight the whole menu.
     */
    public function testEveryFormsScreenBelongsToASidebarEntry(): void
    {
        foreach (array_keys(self::ADMIN_SCREENS) as $script) {
            $this->assertNotNull(
                AdminNavigation::activeKeyForScript($script),
                $script . ' is not listed under any sidebar entry'
            );
        }
    }

    /* ------------------------------------------------------------------ */
    /* Admin guards                                                        */
    /* ------------------------------------------------------------------ */

    public function testEveryAdminEndpointChecksLoginPermissionMethodAndCsrf(): void
    {
        foreach (self::ADMIN_ENDPOINTS as $file => $permission) {
            $source = $this->read('api/admin/' . $file);

            $this->assertStringContainsString('AdminAuth::requireLoginForApi()', $source, $file . ' must require a login');
            $this->assertStringContainsString('requirePermissionForApi(', $source, $file . ' must require a permission');
            $this->assertStringContainsString($this->permissionReference($permission), $source, $file . ' must demand ' . $permission);
            $this->assertStringContainsString("REQUEST_METHOD'] !== 'POST'", $source, $file . ' must refuse anything but POST');
            $this->assertStringContainsString('Csrf::validate(', $source, $file . ' must validate a CSRF token');
        }
    }

    public function testEveryAdminScreenChecksLoginAndPermission(): void
    {
        foreach (self::ADMIN_SCREENS as $file => $permission) {
            $source = $this->read('admin/' . $file);

            $this->assertStringContainsString('AdminAuth::requireLogin()', $source, $file . ' must require a login');
            $this->assertStringContainsString($this->permissionReference($permission), $source, $file . ' must demand ' . $permission);
        }
    }

    /** The attachment download is the other way personal data leaves the CMS. */
    public function testTheAttachmentDownloadIsBehindTheSubmissionPermission(): void
    {
        $source = $this->read('api/admin/form-submission-attachment.php');

        $this->assertStringContainsString('AdminAuth::requireLogin()', $source);
        $this->assertStringContainsString("requirePermission('forms.submissions')", $source);
        $this->assertStringContainsString('ContactAttachmentStorage', $source, 'attachments must stay outside the webroot');
    }

    /* ------------------------------------------------------------------ */
    /* The public endpoint                                                 */
    /* ------------------------------------------------------------------ */

    /**
     * The one thing a public form endpoint must never do: let a request
     * decide who gets the e-mail. The recipient is resolved from the stored
     * form and the site's settings, and this is where that stays true.
     */
    public function testThePublicEndpointNeverTakesARecipientFromTheRequest(): void
    {
        foreach (['api/form-submit.php', 'src/Service/Forms/FormSubmissionHandler.php'] as $file) {
            $source = $this->read($file);

            foreach (['notification_email', 'to_email', 'recipient', 'mailto'] as $forbidden) {
                $this->assertStringNotContainsString(
                    "\$_POST['" . $forbidden,
                    $source,
                    $file . ' must not read a recipient out of the request'
                );
                $this->assertStringNotContainsString(
                    '$_REQUEST[',
                    $source,
                    $file . ' must not read $_REQUEST at all'
                );
            }
        }
    }

    public function testThePublicEndpointResolvesItsRecipientThroughTheOneServiceThatValidatesIt(): void
    {
        $handler = $this->read('src/Service/Forms/FormSubmissionHandler.php');

        $this->assertStringContainsString('FormRecipient::forForm(', $handler);
        $this->assertStringContainsString('FormRecipient::validAddress(', $handler, 'a Reply-To must be validated before it reaches a header');
    }

    /** No company's address may be written into generic Forms code. */
    public function testNoFormsFileNamesOneCompanysAddressOrCopy(): void
    {
        foreach ($this->genericFormsFiles() as $file) {
            $source = strtolower($this->read($file));

            foreach (['vanveluwlaserdesign', 'van veluw', 'nijmegen', 'laserdesign', 'lasergravure', 'offerte'] as $literal) {
                $this->assertStringNotContainsString(
                    $literal,
                    $source,
                    $file . ' must not contain the site-specific literal "' . $literal . '" — that belongs in the migrated form definition'
                );
            }
        }
    }

    /**
     * A DEFAULT VALUE IS DATA, NEVER CODE. The contact form's audience
     * radio starts on "Particulier" because its row says so — and if that
     * word ever reappears in a renderer, a controller or a field type, the
     * generic engine has started knowing about one site's form again.
     */
    public function testNoGenericFormsFileNamesAParticularFormsDefaultChoice(): void
    {
        foreach ($this->genericFormsFiles() as $file) {
            $source = strtolower($this->read($file));

            foreach (['particulier', 'zakelijk'] as $optionLabel) {
                $this->assertStringNotContainsString(
                    $optionLabel,
                    $source,
                    $file . ' names the option "' . $optionLabel . '" — an option belongs in a database row, not in code'
                );
            }
        }
    }

    /**
     * Only a choice field may start pre-selected. A pre-filled text box
     * holds an answer nobody typed, and a pre-ticked consent box is consent
     * nobody gave.
     */
    public function testOnlyChoiceFieldsAcceptADefaultValue(): void
    {
        foreach (FormFieldTypes::all() as $key => $type) {
            $this->assertSame(in_array($key, ['select', 'radio'], true), $type->usesDefaultValue(), $key);
        }
    }

    /**
     * The admin must never let an editor type a default: it is a radio on
     * one of the field's own option rows (or on "no default"), so it cannot
     * name a choice nobody is offered. The radio's value is the row, not a
     * label, and the endpoint turns it into that row's option.
     */
    public function testTheDefaultIsChosenFromTheOptionsRatherThanTyped(): void
    {
        $editor = $this->read('admin/form-field.php') . $this->read('admin/_form_fields.php');

        $this->assertStringContainsString('<input type="radio" name="default_option" value="<?= $h($index) ?>"', $editor);
        $this->assertStringContainsString('<input type="radio" name="default_option" value=""', $editor);
        $this->assertStringNotContainsString(
            'name="default_value"',
            $editor,
            'the editor sends no default of its own: a free-text default could name an option the field does not have'
        );
        $this->assertDoesNotMatchRegularExpression('/type="text"[^>]*name="default_option"/', $editor);

        // And the endpoint checks it against the options being saved.
        $this->assertStringContainsString(
            'FormField::isUsableDefault(',
            $this->read('api/admin/update-form-field.php'),
            'the one rule about defaults must be the one the endpoint applies'
        );
    }

    /** Core Forms is Core: it must not name the Shop, its tables or its classes. */
    public function testCoreFormsNamesNothingFromTheShopOrPersonalisation(): void
    {
        foreach ($this->genericFormsFiles() as $file) {
            $source = $this->read($file);

            foreach ([
                'ProductRepository',
                'OrderRepository',
                'CollectionService',
                'MollieClientFactory',
                'ShopModule',
                'PersonalizationModule',
                'ProductPersonalization',
                'FROM products',
                'FROM orders',
                'FROM order_items',
                'FROM collections',
            ] as $forbidden) {
                $this->assertStringNotContainsString(
                    $forbidden,
                    $source,
                    $file . ' must not know about ' . $forbidden . ' — Forms is Core (MODULES.md)'
                );
            }
        }
    }

    /* ------------------------------------------------------------------ */
    /* Blocks and assets                                                   */
    /* ------------------------------------------------------------------ */

    public function testBothFormBlocksAreRegisteredAsCoreBlocks(): void
    {
        $this->assertTrue(BlockDefinitions::has('form'));
        $this->assertTrue(BlockDefinitions::has('contact_form'));

        $this->assertInstanceOf(FormBlock::class, BlockDefinitions::get('form'));
        $this->assertInstanceOf(ContactFormBlock::class, BlockDefinitions::get('contact_form'));

        // Core, not a module: neither may disappear when a module is off.
        $this->assertNull(BlockDefinitions::moduleOwnerOf('form'));
        $this->assertNull(BlockDefinitions::moduleOwnerOf('contact_form'));
    }

    /**
     * The generic block may be placed as often as an editor likes; the
     * contact block stays capped at one because two "Direct contact" cards
     * on a page is not a layout anybody wants.
     */
    public function testTheGenericFormBlockMayBePlacedMoreThanOncePerPage(): void
    {
        $this->assertTrue(BlockDefinitions::get('form')->meta()['allow_multiple']);
        $this->assertNull(BlockDefinitions::get('form')->meta()['max_instances']);

        $this->assertFalse(BlockDefinitions::get('contact_form')->meta()['allow_multiple']);
    }

    /**
     * Forms CSS and JS belong to the blocks that render a form and to
     * nothing else — so a page without a form downloads neither.
     */
    public function testFormAssetsAreOwnedByTheFormBlocksAndNothingElse(): void
    {
        foreach (['form', 'contact_form'] as $type) {
            $definition = BlockDefinitions::get($type);

            $this->assertSame(['assets/css/blocks/form.css'], $definition->styles(), $type);
            $this->assertSame(['assets/js/blocks/form.js'], $definition->scripts(), $type);
        }

        foreach (['assets/css/blocks/form.css', 'assets/js/blocks/form.js'] as $asset) {
            $this->assertFileExists($this->path($asset));
        }

        // The site shell must not carry them: a page with no form loads none.
        foreach (['assets/css/core.css', 'assets/js/core.js'] as $shell) {
            $this->assertStringNotContainsString('data-form-block', $this->read($shell), $shell . ' must not initialise forms');
        }
    }

    public function testTheOldContactFormScriptIsGoneRatherThanOrphaned(): void
    {
        $this->assertFileDoesNotExist(
            $this->path('assets/js/blocks/contact-form.js'),
            'the contact form uses the shared form script now; an unclaimed block asset fails FrontendAssetOwnershipTest'
        );
    }

    /**
     * One script for any number of forms, and it must scope itself per
     * instance rather than assuming a single form on the page.
     */
    public function testTheFormScriptWorksPerInstance(): void
    {
        $source = $this->read('assets/js/blocks/form.js');

        $this->assertStringContainsString('querySelectorAll("[data-form-block]")', $source);
        $this->assertStringNotContainsString('document.querySelector("[data-form-block]")', $source);
    }

    /* ------------------------------------------------------------------ */
    /* The pre-output hook                                                 */
    /* ------------------------------------------------------------------ */

    /**
     * A failed submission's answers are read out of the public session, and
     * session_start() refuses once output has begun — so every page template
     * that can carry a form must prime it on its first lines.
     */
    public function testEveryPageTemplatePrimesThePublicFormSession(): void
    {
        foreach (self::PAGE_TEMPLATES as $template) {
            $source = $this->read($template);

            $this->assertStringContainsString(
                'PublicFormSession::prime()',
                $source,
                $template . ' must prime the public form session before it prints anything'
            );

            $primeAt = strpos($source, 'PublicFormSession::prime()');
            $outputAt = stripos($source, '<!doctype');

            $this->assertNotFalse($outputAt, $template . ' should print a document');
            $this->assertLessThan(
                $outputAt,
                $primeAt,
                $template . ' primes the session after it has started printing, which is too late'
            );
        }
    }

    /**
     * The public session is its own session, never the admin one: an
     * anonymous visitor must not be able to touch a signed-in session.
     */
    public function testThePublicSessionIsSeparateFromTheAdminSession(): void
    {
        $public = $this->read('src/Service/Forms/PublicFormSession.php');
        $admin = $this->read('src/Service/AdminAuth.php');

        preg_match("/SESSION_NAME\s*=\s*'([^']+)'/", $public, $publicName);
        preg_match("/SESSION_NAME\s*=\s*'([^']+)'/", $admin, $adminName);

        $this->assertNotSame('', $publicName[1] ?? '');
        $this->assertNotSame($adminName[1] ?? '', $publicName[1] ?? '', 'the public form session must have a name of its own');
        // It may NAME the admin session in prose — the docblock explains
        // precisely why the two are separate — but it must not reach into it.
        $this->assertStringNotContainsString(
            'AdminAuth',
            $this->withoutComments($public),
            'the public session must not reach into admin authentication'
        );
    }

    /* ------------------------------------------------------------------ */
    /* The legacy endpoint                                                 */
    /* ------------------------------------------------------------------ */

    /**
     * api/contact.php is a compatibility shim and must stay one: a second
     * engine that also validates, stores and mails is exactly what Core
     * Forms was built to end.
     */
    public function testTheLegacyContactEndpointHoldsNoEngineOfItsOwn(): void
    {
        $source = $this->read('api/contact.php');

        foreach (['FILTER_VALIDATE_EMAIL', 'Mailer', 'ContactRequestRepository', 'ContactRequestBuilder', 'beginTransaction'] as $forbidden) {
            $this->assertStringNotContainsString(
                $forbidden,
                $source,
                'api/contact.php must delegate rather than validate, store or send (' . $forbidden . ')'
            );
        }

        $this->assertStringContainsString("require __DIR__ . '/form-submit.php'", $source, 'it must hand over to the one pipeline');
    }

    /* ------------------------------------------------------------------ */
    /* Deferred by design                                                  */
    /* ------------------------------------------------------------------ */

    /** V1 adds no paid or third-party challenge to a public form. */
    public function testNoExternalCaptchaServiceIsWiredIntoForms(): void
    {
        foreach ($this->genericFormsFiles() as $file) {
            // Comments stripped: App\Service\Forms\FormSpamGuard names these
            // services in prose precisely to record that Forms uses none of
            // them, and that explanation is worth keeping.
            $source = strtolower($this->withoutComments($this->read($file)));

            foreach (['recaptcha', 'hcaptcha', 'turnstile', 'akismet', 'siteverify'] as $service) {
                $this->assertStringNotContainsString($service, $source, $file . ' must not depend on ' . $service);
            }
        }
    }

    public function testTheFormBuilderOffersNoUploadField(): void
    {
        $this->assertFalse(FormFieldTypes::has('file'));

        $editor = $this->read('admin/form-field.php');
        $this->assertStringNotContainsString('type="file"', $editor, 'the field editor must not be able to create an upload field');
    }

    /* ------------------------------------------------------------------ */
    /* The admin screens                                                   */
    /* ------------------------------------------------------------------ */

    /**
     * No Forms screen asks with the browser's confirm() any more: every form
     * that deletes something carries the CMS's question
     * (admin_confirm_attributes()), and a screen with such a form prints the
     * dialog it is asked in. Tests\Service\FormAdminHttpTest checks the
     * questions and what a confirmed request deletes.
     */
    public function testEveryFormsDeletionAsksInTheCmsDialog(): void
    {
        foreach (['admin/forms.php', 'admin/form.php', 'admin/form-field.php', 'admin/form-block.php', 'admin/form-submissions.php', 'admin/form-submission.php', 'admin/assets/forms-admin.js'] as $file) {
            $source = $this->read($file);

            $this->assertStringNotContainsString('onsubmit', $source, $file . ' has an inline handler');
            $this->assertDoesNotMatchRegularExpression('/\bconfirm\(/', $source, $file . ' asks with the browser\'s confirm()');

            $deletes = preg_match_all('#<form method="post" action="/api/admin/delete-[a-z-]+\.php"#', $source);
            $asks = preg_match_all('#<form method="post" action="/api/admin/delete-[a-z-]+\.php"(?: class="[^"]*")?<\?= admin_confirm_attributes\(#', $source);

            $this->assertSame($deletes, $asks, $file . ': every delete form asks first');
            if ($deletes > 0) {
                $this->assertSame(1, substr_count($source, '<?= admin_confirm_dialog() ?>'), $file . ' prints the dialog once');
            }
        }
    }

    /**
     * The option rows' script: moving a row moves its neighbour past it and
     * nothing else, the first row cannot go up and the last cannot go down,
     * and every add, remove and move tells the save bar with a change event
     * — none of them types anything, and input and change are all the bar
     * listens to. Its words stay the catalogue's.
     */
    public function testTheOptionRowsScriptReordersInPlaceAndTellsTheSaveBar(): void
    {
        $script = $this->read('admin/assets/forms-admin.js');

        $this->assertStringContainsString('list.insertBefore(sibling, up ? row.nextElementSibling : row);', $script);
        $this->assertStringContainsString('if (up) up.disabled = position === 0;', $script);
        $this->assertStringContainsString('if (down) down.disabled = position === all.length - 1;', $script);
        $this->assertStringContainsString('list.dispatchEvent(new Event("change", { bubbles: true }));', $script);
        $this->assertSame(4, substr_count($script, 'changed()'), 'one definition, and a call after adding, removing and moving');
        $this->assertStringContainsString('status.getAttribute("data-form-option-moved")', $script, 'where a row went, in the words the server put on the page');
        $this->assertStringNotContainsString('innerHTML', $script);
        $this->assertStringNotContainsString('/api/admin/', $script, 'moving a row posts nothing');

        // The editor: the move buttons exist per row, hidden until the script
        // runs, and never submit.
        $editor = $this->read('admin/form-field.php');
        $this->assertStringContainsString('<span class="admin-option-row__move" data-form-option-move-group hidden>', $editor);
        $this->assertSame(2, preg_match_all('#<button type="button" class="admin-btn-ghost admin-option-row__move-button" data-form-option-move="(?:up|down)"#', $editor));
    }

    /**
     * Every generic Forms file — the service namespace, the blocks, the
     * public renderer, the endpoints and the admin screens.
     *
     * @return list<string> project-relative paths
     */
    private function genericFormsFiles(): array
    {
        $root = $this->path('');

        $files = array_merge(
            (array) glob($root . 'src/Service/Forms/*.php'),
            (array) glob($root . 'src/Service/Forms/FieldTypes/*.php'),
            [
                $root . 'src/Service/FormBlockContent.php',
                $root . 'src/Service/Blocks/FormBlock.php',
                $root . 'src/Repository/FormRepository.php',
                $root . 'src/Repository/FormBlockRepository.php',
                $root . 'src/Repository/FormSubmissionRepository.php',
                $root . 'src/Mail/FormSubmissionBuilder.php',
                $root . 'partials/form.php',
                $root . 'partials/section-form.php',
                $root . 'api/form-submit.php',
                $root . 'admin/forms.php',
                $root . 'admin/form.php',
                $root . 'admin/form-field.php',
                $root . 'admin/form-block.php',
                $root . 'admin/form-submissions.php',
                $root . 'admin/form-submission.php',
                $root . 'assets/js/blocks/form.js',
                $root . 'assets/css/blocks/form.css',
            ],
            (array) glob($root . 'api/admin/*form*.php')
        );

        // The `contact_form` wrapper is deliberately ABOUT the contact form:
        // its block label, its editor and its endpoint carry that name, and
        // CONTENT-BLOCKS.md keeps the type key as it is. It is the wrapper,
        // not the generic engine, so it is not held to the "names no
        // site-specific copy" rule the rest of this list is.
        $files = array_filter(
            $files,
            static fn (string $path): bool => is_file($path)
                && !str_contains($path, 'contact-form')
                && !str_contains($path, 'ContactForm')
        );

        return array_values(array_map(
            static fn (string $path): string => ltrim(str_replace($root, '', $path), '/'),
            $files
        ));
    }

    /**
     * A guard's permission is always a LITERAL in this project, never a
     * constant or an expression — Tests\Service\AdminAccessControlTest
     * enforces that across all 174 endpoints, so nothing can derive its
     * permission from anything a request sent.
     */
    private function permissionReference(string $permission): string
    {
        return "'" . $permission . "'";
    }

    /**
     * The file with its comments removed, so a check about what the code
     * DOES is not defeated by prose about what it deliberately does not.
     */
    private function withoutComments(string $source): string
    {
        $stripped = preg_replace('#/\*.*?\*/#s', ' ', $source) ?? $source;
        $stripped = preg_replace('#(^|\s)//[^
]*#', ' ', $stripped) ?? $stripped;

        return $stripped;
    }

    private function path(string $relative): string
    {
        return dirname(__DIR__, 2) . '/' . $relative;
    }

    private function read(string $relative): string
    {
        $path = $this->path($relative);
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }
}
