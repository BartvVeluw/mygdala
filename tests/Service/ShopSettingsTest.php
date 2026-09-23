<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Mail\EmailIdentity;
use App\Mail\EmailPlaceholders;
use App\Mail\OrderConfirmationBuilder;
use App\Module\ModuleRegistry;
use App\Service\AdminNavigation;
use App\Service\AdminPermissions;
use App\Service\InvoiceService;
use App\Service\Language\AdminLocale;
use App\Service\ShopSettings;
use App\Service\SiteSettings;
use App\Service\SiteSettingsValidator;
use PHPUnit\Framework\TestCase;

/**
 * Shop-instellingen: invoices, order numbers and the order confirmation
 * e-mail as the Shop's own screen (App\Service\ShopSettings,
 * admin/shop-settings.php, api/admin/update-shop-settings.php).
 *
 * No database: settings come from SiteSettings::overrideForTests(), modules
 * from ModuleRegistry::overrideForTests(), and the screen is asserted on its
 * source. That stored values survive the Shop being switched off and on is
 * Tests\Repository\SiteSettingsPersistenceTest.
 */
final class ShopSettingsTest extends TestCase
{
    private const SCREEN = 'admin/shop-settings.php';
    private const ENDPOINT = 'api/admin/update-shop-settings.php';

    /** @var array<string, string|null> */
    private array $environment = [];

    protected function setUp(): void
    {
        AdminLocale::overrideForTests('nl');

        foreach (['MAIL_FROM_ADDRESS', 'SHOP_NOTIFICATION_EMAIL'] as $name) {
            $this->environment[$name] = $_ENV[$name] ?? null;
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->environment as $name => $value) {
            if ($value === null) {
                unset($_ENV[$name]);
            } else {
                $_ENV[$name] = $value;
            }
        }

        AdminLocale::overrideForTests(null);
        SiteSettings::overrideForTests(null);
        ModuleRegistry::overrideForTests(null);
    }

    private static function source(string $relativePath): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/' . $relativePath);
    }

    /** @return array<string, string> */
    private static function catalog(string $locale): array
    {
        /** @var array<string, string> $messages */
        $messages = require dirname(__DIR__, 2) . '/src/Service/Language/messages/' . $locale . '.php';

        return $messages;
    }

    /** @return list<string> */
    private static function lines(string $relativePath): array
    {
        return preg_split('/\R/', self::source($relativePath)) ?: [];
    }

    /**
     * The one line on the screen holding the control named $key.
     *
     * @return array{0: int, 1: string} line index and line
     */
    private function control(string $key): array
    {
        $found = [];

        foreach (self::lines(self::SCREEN) as $index => $line) {
            if (preg_match('/<(?:input|textarea)\b.*\bname="' . preg_quote($key, '/') . '"/', $line) === 1) {
                $found[] = [$index, $line];
            }
        }

        $this->assertCount(1, $found, $key . ' must be exactly one control on ' . self::SCREEN);

        return $found[0];
    }

    /** @return array<string, string> tab key => the source written inside it */
    private function panels(): array
    {
        preg_match_all(
            "/admin_tab_panel\('([a-z-]+)'\)(.*?)admin_tab_panel_end\(\)/s",
            self::source(self::SCREEN),
            $matches,
            PREG_SET_ORDER
        );

        $panels = [];
        foreach ($matches as $match) {
            $panels[$match[1]] = ($panels[$match[1]] ?? '') . $match[2];
        }

        return $panels;
    }

    // --- the Shop owns it ------------------------------------------------

    public function testTheScreenIsInTheSidebarOnlyWhileTheShopRuns(): void
    {
        ModuleRegistry::overrideForTests(['shop' => false, 'personalization' => false, 'multilingual' => true]);
        $this->assertNotContains('shop_settings', array_column(AdminNavigation::items(), 'key'));

        ModuleRegistry::overrideForTests(['shop' => true, 'personalization' => true, 'multilingual' => true]);
        $entries = array_values(array_filter(AdminNavigation::items(), static fn (array $item): bool => $item['key'] === 'shop_settings'));

        $this->assertCount(1, $entries);
        $this->assertSame('/admin/shop-settings.php', $entries[0]['url']);
        $this->assertSame(
            AdminPermissions::SETTINGS_MANAGE,
            $entries[0]['permission'],
            'the permission the Facturen and E-mails tabs asked, so the move changed nobody\'s access'
        );
    }

    /**
     * settings.manage does not switch off with the Shop, so the permission
     * alone would leave both files reachable. The module guard runs first.
     */
    public function testTheScreenAndItsEndpointCloseWithTheShop(): void
    {
        $screen = self::source(self::SCREEN);
        $guard = strpos($screen, "ModuleGuard::requireAdmin('shop');");
        $this->assertNotFalse($guard);
        $this->assertLessThan(strpos($screen, 'AdminAuth::requireLogin()'), $guard);

        $endpoint = self::source(self::ENDPOINT);
        $guard = strpos($endpoint, "ModuleGuard::requireApi('shop');");
        $this->assertNotFalse($guard);
        $this->assertLessThan(strpos($endpoint, 'AdminAuth::requireLoginForApi()'), $guard);
        $this->assertStringContainsString("AdminAuth::requirePermissionForApi('settings.manage')", $endpoint);
        $this->assertStringContainsString('ShopSettings::validate($_POST, $current)', $endpoint);
        $this->assertStringContainsString("->upsertMany(\$validated['values'])", $endpoint);
    }

    public function testSiteSettingsNoLongerShowsInvoicesOrShopEmails(): void
    {
        $settings = self::source('admin/settings.php');

        foreach (array_keys(ShopSettings::FIELDS) as $key) {
            $this->assertStringNotContainsString('name="' . $key . '"', $settings, $key . ' belongs to Shop-instellingen');
        }

        $this->assertSame(
            [],
            array_values(array_intersect(array_keys(SiteSettingsValidator::FIELDS), array_keys(ShopSettings::FIELDS))),
            'one setting, one screen: Instellingen can no longer write a Shop setting'
        );
    }

    // --- the fields and the tabs -----------------------------------------

    public function testEveryFieldIsAKnownSettingOnExactlyOneTab(): void
    {
        $onTabs = array_merge(...array_values(ShopSettings::TABS));

        $this->assertSame(count($onTabs), count(array_unique($onTabs)), 'no field on two tabs');
        $this->assertEqualsCanonicalizing(array_keys(ShopSettings::FIELDS), $onTabs, 'every field on a tab, and nothing else');

        foreach (array_keys(ShopSettings::FIELDS) as $key) {
            $this->assertArrayHasKey($key, SiteSettings::defaults(), $key);
        }
    }

    public function testEachTabIsOneFormToTheShopEndpointWithItsOwnFields(): void
    {
        $panels = $this->panels();
        $this->assertSame(array_keys(ShopSettings::TABS), array_keys($panels));

        foreach (ShopSettings::TABS as $tab => $fields) {
            $this->assertSame(1, substr_count($panels[$tab], 'action="/api/admin/update-shop-settings.php"'), $tab);
            $this->assertStringContainsString('<input type="hidden" name="section" value="' . $tab . '">', $panels[$tab]);

            foreach ($fields as $field) {
                $this->assertStringContainsString('name="' . $field . '"', $panels[$tab], $field . ' must be on the ' . $tab . ' tab');
            }
        }
    }

    public function testTheScreenAsksExactlyWhatTheValidatorChecks(): void
    {
        foreach (ShopSettings::FIELDS as $key => $maxLength) {
            [, $line] = $this->control($key);

            $this->assertStringContainsString('maxlength="' . $maxLength . '"', $line, $key);
            $this->assertDoesNotMatchRegularExpression('/\srequired(?=[\s>])/', $line, $key . ': nothing on this screen is required');
        }
    }

    public function testEveryFieldCarriesItsExplanation(): void
    {
        $lines = self::lines(self::SCREEN);
        $nl = self::catalog('nl');
        $en = self::catalog('en');

        foreach (array_keys(ShopSettings::FIELDS) as $key) {
            [$index, $line] = $this->control($key);
            $this->assertMatchesRegularExpression('/\bid="([a-z-]+)"/', $line);
            preg_match('/\bid="([a-z-]+)"/', $line, $id);

            $label = $lines[$index - 1] ?? '';
            $this->assertStringContainsString("admin_field_label('" . $id[1] . "'", $label, $key . ': the label must point at its own field');
            $this->assertSame(1, preg_match("/admin_t\('(help\.shop_settings\.[a-z_]+)'\)/", $label, $help), $key . ' has no explanation');
            $this->assertArrayHasKey($help[1], $nl);
            $this->assertArrayHasKey($help[1], $en);
        }

        $this->assertDoesNotMatchRegularExpression(
            '#<label\b[^>]*>(?:(?!</label>).)*admin_help\(#s',
            self::source(self::SCREEN),
            'a help icon never sits inside a <label>'
        );
    }

    // --- validation --------------------------------------------------------

    public function testNothingOnTheScreenIsRequired(): void
    {
        $result = ShopSettings::validate(array_fill_keys(array_keys(ShopSettings::FIELDS), ''), SiteSettings::defaults());

        $this->assertSame([], $result['errors']);
        $this->assertSame(array_fill_keys(array_keys(ShopSettings::FIELDS), ''), $result['values']);
    }

    public function testTheOrderNumberPrefixIsLettersAndDigits(): void
    {
        $this->assertNotSame([], ShopSettings::validate(['order_number_prefix' => 'ORD-'], SiteSettings::defaults())['errors']);
        $this->assertNotSame([], ShopSettings::validate(['order_number_prefix' => 'ABCDEFGHIJK'], SiteSettings::defaults())['errors']);
        $this->assertSame([], ShopSettings::validate(['order_number_prefix' => 'SHOP26'], SiteSettings::defaults())['errors']);
    }

    public function testOnlyShopSettingsAreEverWrittenHere(): void
    {
        $result = ShopSettings::validate(
            ['site_name' => 'Anders', 'email' => '', 'company_street' => 'Kerkstraat', 'invoice_footer_text' => ' Bedankt! '],
            SiteSettings::defaults()
        );

        $this->assertSame(['invoice_footer_text' => 'Bedankt!'], $result['values']);
    }

    public function testAChangedValueOverTheLimitIsRefusedButAnUntouchedOneIsNot(): void
    {
        $this->assertNotSame([], ShopSettings::validate(['invoice_footer_text' => str_repeat('a', 501)], SiteSettings::defaults())['errors']);

        $legacy = str_repeat('a', 600);
        $this->assertSame([], ShopSettings::validate(['invoice_footer_text' => $legacy], ['invoice_footer_text' => $legacy] + SiteSettings::defaults())['errors']);
    }

    public function testASaveOnlyEverReturnsToOneOfTheTabs(): void
    {
        $this->assertSame('emails', ShopSettings::section('emails'));
        $this->assertSame('bedrijf', ShopSettings::section('../settings'));
        $this->assertSame('bedrijf', ShopSettings::section(['emails']));
        $this->assertSame('bedrijf', ShopSettings::section(null));
    }

    // --- company name and KVK: a plain website needs neither ---------------

    public function testAPlainWebsiteNeedsNoCompanyNameOrKvkNumber(): void
    {
        $this->assertSame(['site_name'], SiteSettingsValidator::REQUIRED);
        $this->assertSame([], SiteSettingsValidator::validate(['site_name' => 'Het blog van Sanne', 'kvk_number' => ''], SiteSettings::defaults(), [])['errors']);
        $this->assertArrayNotHasKey('company_name', SiteSettingsValidator::FIELDS, 'the legal name is not asked on a site without a shop');
    }

    public function testAnInvoiceNamesTheSiteWhenNoCompanyNameIsGiven(): void
    {
        SiteSettings::overrideForTests(['site_name' => 'Mooie Kaarsen']);

        $this->assertSame('Mooie Kaarsen', InvoiceService::buildSellerSnapshot()['company_name']);
    }

    public function testAnInvoiceNamesTheCompanyWhenOneIsGiven(): void
    {
        SiteSettings::overrideForTests(['site_name' => 'Mooie Kaarsen', 'company_name' => 'J. Jansen Handelsonderneming']);

        $this->assertSame('J. Jansen Handelsonderneming', InvoiceService::buildSellerSnapshot()['company_name']);
    }

    // --- the standard text and "Herstel standaardtekst" --------------------

    public function testTheStandardTextHasOneSource(): void
    {
        $copy = OrderConfirmationBuilder::defaultCopy();

        $this->assertEqualsCanonicalizing(OrderConfirmationBuilder::CUSTOMER_COPY_KEYS, array_keys($copy));

        foreach ($copy as $key => $text) {
            $this->assertSame(SiteSettings::defaults()[$key], $text, $key . ' must be the SiteSettings default itself');
        }

        $builder = '';
        foreach (token_get_all(self::source('src/Mail/OrderConfirmationBuilder.php')) as $token) {
            if (!is_array($token) || !in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                $builder .= is_array($token) ? $token[1] : $token;
            }
        }

        $this->assertStringContainsString('$emailSettings + self::defaultCopy()', $builder);
        $this->assertStringNotContainsString('Bedankt voor je bestelling', $builder, 'no second copy of the standard text in the builder');
        $this->assertStringNotContainsString('Bedankt voor je bestelling', self::source(self::SCREEN));
        $this->assertStringNotContainsString('Bedankt voor je bestelling', self::source('admin/assets/shop-settings.js'));
    }

    public function testTheStandardTextKeepsItsPlaceholdersAndUsesOnlyRealOnes(): void
    {
        $copy = OrderConfirmationBuilder::defaultCopy();

        $this->assertStringContainsString('{{customer_name}}', $copy['order_email_intro']);
        $this->assertStringContainsString('{{order_number}}', $copy['order_email_subject']);

        foreach ($copy as $key => $text) {
            preg_match_all('/\{\{\s*([a-z_]+)\s*\}\}/', $text, $matches);
            foreach ($matches[1] as $placeholder) {
                $this->assertContains($placeholder, EmailPlaceholders::KNOWN, $key . ' uses a placeholder the e-mail would not replace');
            }
        }

        // What an owner saves after restoring is exactly the standard text.
        $restored = ShopSettings::validate($copy, SiteSettings::defaults());
        $this->assertSame([], $restored['errors']);
        $this->assertSame($copy, $restored['values']);
    }

    public function testRestoringFillsTheFieldsFromTheStandardTextAndSavesNothing(): void
    {
        $screen = self::source(self::SCREEN);
        $this->assertStringContainsString('$standardCopy = OrderConfirmationBuilder::defaultCopy();', $screen);
        $this->assertStringContainsString('/admin/assets/shop-settings.js', $screen);

        foreach (OrderConfirmationBuilder::CUSTOMER_COPY_KEYS as $key) {
            [, $line] = $this->control($key);
            $this->assertStringContainsString('data-default-value="<?= $h($standardCopy[\'' . $key . '\']) ?>"', $line, $key);
        }

        $this->assertMatchesRegularExpression(
            '/<button type="button" class="admin-btn-secondary" data-restore-defaults\b[^\n]*\bhidden>/',
            $screen,
            'a button that never submits, hidden until its script can make it work'
        );

        $script = self::source('admin/assets/shop-settings.js');
        $code = (string) preg_replace(['#/\*.*?\*/#s', '#^\s*//.*$#m'], '', $script);

        $this->assertStringContainsString('window.confirm(', $code, 'restoring asks first');
        $this->assertStringContainsString('data-default-value', $code);

        foreach (['.submit(', 'requestSubmit', 'fetch(', 'XMLHttpRequest', 'innerHTML'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $code, 'restoring must not save or send anything: ' . $forbidden);
        }

        preg_match_all('/"((?:[^"\\\\]|\\\\.)*)"/', $code, $literals);
        foreach ($literals[1] as $literal) {
            $this->assertMatchesRegularExpression(
                '/^(?:use strict|[a-z-]*|\[data-[a-z-]+\])$/',
                $literal,
                'the script holds no sentence an editor reads; the catalog does: "' . $literal . '"'
            );
        }
    }

    public function testARestoredButUnsavedTextIsShownAsUnsaved(): void
    {
        $screen = self::source(self::SCREEN);

        // The page editor's save bar, not a second unsaved-changes mechanism:
        // it already listens for the events the restore button dispatches.
        $this->assertStringContainsString("require_once __DIR__ . '/_save_bar.php';", $screen);
        $this->assertStringContainsString('<?php save_bar(); ?>', $screen);
        $this->assertStringContainsString('<?php save_bar_script(); ?>', $screen);

        $script = (string) preg_replace(['#/\*.*?\*/#s', '#^\s*//.*$#m'], '', self::source('admin/assets/shop-settings.js'));
        $this->assertStringContainsString('new Event("input", { bubbles: true })', $script);
        $this->assertStringContainsString('new Event("change", { bubbles: true })', $script);

        // A reload shows what is stored, not the restored text a browser
        // would otherwise put back into the fields.
        $this->assertMatchesRegularExpression(
            '#<form method="post" action="/api/admin/update-shop-settings\.php"[^>]*\bautocomplete="off"#',
            $this->panels()['emails']
        );
    }

    // --- placeholders --------------------------------------------------------

    public function testEveryPlaceholderTheEmailReplacesIsExplainedAndNoOther(): void
    {
        foreach (['nl', 'en'] as $locale) {
            $catalog = self::catalog($locale);

            foreach (EmailPlaceholders::KNOWN as $placeholder) {
                $this->assertArrayHasKey('help.shop_settings.placeholder.' . $placeholder, $catalog, $locale . ': ' . $placeholder);
            }

            foreach (array_keys($catalog) as $key) {
                if (str_starts_with($key, 'help.shop_settings.placeholder.')) {
                    $this->assertContains(substr($key, strlen('help.shop_settings.placeholder.')), EmailPlaceholders::KNOWN, $locale . ': ' . $key . ' explains a placeholder that does not exist');
                }
            }
        }

        $help = ShopSettings::placeholderHelp();
        $this->assertSame(count(EmailPlaceholders::KNOWN), substr_count($help, '{{'));

        foreach (EmailPlaceholders::KNOWN as $placeholder) {
            $this->assertStringContainsString('<strong>{{' . $placeholder . '}}</strong>', $help);
        }
    }

    public function testTheScreenListsThePlaceholdersFromTheRuntime(): void
    {
        $screen = self::source(self::SCREEN);

        $this->assertStringContainsString('foreach (EmailPlaceholders::KNOWN as $placeholder)', $screen);
        $this->assertStringContainsString("admin_help(admin_t('shop_settings.placeholders'), ShopSettings::placeholderHelp())", $screen);

        foreach (EmailPlaceholders::KNOWN as $placeholder) {
            $this->assertStringNotContainsString('{{' . $placeholder . '}}', $screen, 'the list is read, not typed out');
        }
    }

    // --- a confirmation that cannot go out is said ---------------------------

    public function testAConfirmationThatCannotGoOutIsNamedOnTheScreen(): void
    {
        unset($_ENV['MAIL_FROM_ADDRESS'], $_ENV['SHOP_NOTIFICATION_EMAIL']);
        SiteSettings::overrideForTests(['email' => '']);
        $this->assertFalse(EmailIdentity::isConfigured());

        $problems = ShopSettings::confirmationMailProblems();
        $this->assertSame(['shop_settings.warning_no_sender', 'shop_settings.warning_no_notification_address'], $problems);

        foreach ($problems as $key) {
            $this->assertArrayHasKey($key, self::catalog('nl'));
            $this->assertArrayHasKey($key, self::catalog('en'));
        }

        SiteSettings::overrideForTests(['email' => 'winkel@example.com']);
        $_ENV['SHOP_NOTIFICATION_EMAIL'] = 'bestellingen@example.com';
        $this->assertSame([], ShopSettings::confirmationMailProblems());

        $this->assertStringContainsString('$mailProblems = ShopSettings::confirmationMailProblems();', self::source(self::SCREEN));
    }
}
