<?php

declare(strict_types=1);

namespace Tests\Install;

use App\Mail\EmailIdentity;
use App\Service\HttpUserAgent;
use App\Service\Shipping\PickupLocation;
use App\Service\SiteSettings;
use PHPUnit\Framework\TestCase;

/**
 * The identity leaks that are not in the database and not on the homepage:
 * strings this application says out loud at runtime, in places nobody looks
 * at while auditing content.
 *
 * Four of them, found by the fresh-install and identity audits:
 *
 *   outbound User-Agent   the address lookup and the PostNL rate sync
 *                         introduced themselves to two public services as
 *                         "VanVeluwLaserdesign…", from every installation.
 *   the pickup label      the checkout offered "Afhalen in Nijmegen" to
 *                         every customer of every installation.
 *   the mail sender       an unconfigured installation had a From address
 *                         to fall back on, and it was this company's.
 *   the order number      every order of every installation was numbered
 *                         "VLD-…", in the admin, the e-mails, the Mollie
 *                         description, the export and the invoice.
 *
 * None of them need a database or a webserver, so they are cheap enough to
 * run on every edit — which is the point: a hardcoded string is easy to
 * reintroduce and impossible to notice.
 */
final class GenericDistributionTest extends TestCase
{
    /** Directories that are the shipped application, as opposed to its history. */
    private const SOURCE_DIRECTORIES = ['src', 'partials', 'api', 'admin'];

    protected function tearDown(): void
    {
        SiteSettings::overrideForTests(null);
    }

    /* ------------------------------------------------------------------ */
    /* Outbound identity                                                   */
    /* ------------------------------------------------------------------ */

    public function testNoSourceFileSendsAUserAgentNamingThisSite(): void
    {
        $offenders = [];

        foreach ($this->sourceFiles() as $path => $contents) {
            if (!preg_match('/user-agent/i', $contents)) {
                continue;
            }

            foreach (explode("\n", $contents) as $number => $line) {
                if (!preg_match('/user-agent/i', $line)) {
                    continue;
                }
                if (preg_match('/van\s*veluw|vanveluwlaserdesign/i', $line)) {
                    $offenders[] = $path . ':' . ($number + 1);
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "A User-Agent still names this site. Use App\\Service\\HttpUserAgent, which identifies the "
            . "application and this installation's own base URL:\n" . implode("\n", $offenders)
        );
    }

    public function testTheUserAgentNamesTheApplicationAndThePurpose(): void
    {
        $this->withBaseUrl('https://tweede-site.test', function (): void {
            $agent = HttpUserAgent::forPurpose('NL address verification');

            $this->assertStringStartsWith('CMS/', $agent);
            $this->assertStringContainsString('NL address verification', $agent);
        });
    }

    public function testTheContactIsThisInstallationsOwnUrlAndNothingElse(): void
    {
        // The whole point: a second site introduces itself as a second site.
        // On THIS installation the contact is this site's own domain, which
        // is correct and is not the leak — the leak was the same domain
        // arriving on every other installation, compiled in.
        $this->withBaseUrl('https://tweede-site.test', function (): void {
            $agent = HttpUserAgent::forPurpose('shipping rate sync');

            $this->assertStringContainsString('+https://tweede-site.test', $agent);
            $this->assertStringNotContainsStringIgnoringCase('veluw', $agent);
        });
    }

    public function testAnInstallationWithNoBaseUrlSendsTheProductTokenAlone(): void
    {
        $this->withBaseUrl(null, function (): void {
            // A contact address nobody can reach is worse than no contact,
            // and AppUrl's last resort is https://localhost.
            $this->assertSame('CMS/1.0 (NL address verification)', HttpUserAgent::forPurpose('NL address verification'));
            $this->assertSame('CMS/1.0', HttpUserAgent::forPurpose(''));
        });
    }

    /* ------------------------------------------------------------------ */
    /* The pickup label                                                    */
    /* ------------------------------------------------------------------ */

    public function testAnInstallationWithoutACityOffersPlainPickup(): void
    {
        SiteSettings::overrideForTests(['company_city' => '']);

        $this->assertSame('Afhalen', PickupLocation::labelNl());
        $this->assertSame('Pickup', PickupLocation::labelEn());
    }

    public function testAConfiguredCityIsNamedInBothLanguages(): void
    {
        SiteSettings::overrideForTests(['company_city' => 'Nijmegen']);

        $this->assertSame('Afhalen in Nijmegen', PickupLocation::labelNl());
        $this->assertSame('Pick up in Nijmegen', PickupLocation::labelEn());
    }

    public function testASecondSitesCityIsUsedJustAsReadily(): void
    {
        SiteSettings::overrideForTests(['company_city' => 'Gent']);

        $this->assertSame('Afhalen in Gent', PickupLocation::labelNl());
        $this->assertSame('Pick up in Gent', PickupLocation::labelEn());
    }

    public function testTheCheckoutAsksForTheLabelRatherThanWritingOneOut(): void
    {
        $checkout = (string) file_get_contents($this->root() . '/checkout.php');

        $this->assertStringContainsString('PickupLocation::labelNl()', $checkout);
        $this->assertStringNotContainsString('Afhalen in Nijmegen', $checkout);
    }

    /* ------------------------------------------------------------------ */
    /* The mail sender                                                     */
    /* ------------------------------------------------------------------ */

    public function testAnInstallationThatNamesNoSenderIsNotConfigured(): void
    {
        $previous = $_ENV['MAIL_FROM_ADDRESS'] ?? null;
        unset($_ENV['MAIL_FROM_ADDRESS']);
        SiteSettings::overrideForTests(['email' => '']);

        try {
            $this->assertFalse(EmailIdentity::isConfigured());
            $this->assertSame('', EmailIdentity::fromAddress());
        } finally {
            if ($previous !== null) {
                $_ENV['MAIL_FROM_ADDRESS'] = $previous;
            }
        }
    }

    public function testTheSiteSettingIsEnoughToBeConfigured(): void
    {
        $previous = $_ENV['MAIL_FROM_ADDRESS'] ?? null;
        unset($_ENV['MAIL_FROM_ADDRESS']);
        SiteSettings::overrideForTests(['email' => 'hallo@tweede-site.test']);

        try {
            $this->assertTrue(EmailIdentity::isConfigured());
            $this->assertSame('hallo@tweede-site.test', EmailIdentity::fromAddress());
        } finally {
            if ($previous !== null) {
                $_ENV['MAIL_FROM_ADDRESS'] = $previous;
            }
        }
    }

    public function testTheMailerRefusesToSendWithoutASender(): void
    {
        // Static source check rather than an SMTP conversation: the guard has
        // to sit BEFORE setFrom(), and that ordering is the whole point.
        $mailer = (string) file_get_contents($this->root() . '/src/Service/Mailer.php');

        $guard = strpos($mailer, 'EmailIdentity::isConfigured()');
        $setFrom = strpos($mailer, '$mail->setFrom(');

        $this->assertNotFalse($guard, 'App\Service\Mailer must refuse to send without a configured sender.');
        $this->assertNotFalse($setFrom);
        $this->assertLessThan($setFrom, $guard, 'The sender check must come before setFrom().');
    }

    public function testNoCodeInventsAFallbackSenderAddress(): void
    {
        $identity = (string) file_get_contents($this->root() . '/src/Mail/EmailIdentity.php');

        $this->assertDoesNotMatchRegularExpression(
            '/=\s*[\'"][^\'"\s]+@[^\'"\s]+[\'"]/',
            $identity,
            'App\Mail\EmailIdentity must not carry an e-mail address of its own.'
        );
    }

    /* ------------------------------------------------------------------ */
    /* The order number                                                    */
    /* ------------------------------------------------------------------ */

    public function testTheGenericOrderNumberPrefixIsNotThisSitesOwn(): void
    {
        $this->assertSame('ORD', SiteSettings::defaults()['order_number_prefix']);
    }

    public function testNoCodeWritesTheOldOrderNumberPrefixOut(): void
    {
        // String literals, not text: why the prefix moved is history, written
        // in comments on purpose, and a source-level match would trip on it.
        // Case-sensitive, so the frozen personalization font namespace
        // 'vvld-' (App\Service\Personalization\PersonalizationFonts) is not
        // mistaken for it.
        $offenders = [];

        foreach ($this->sourceFiles() + $this->rootFiles() as $path => $contents) {
            // The one file that must name it: the export's review needle, which
            // is how a copy finds the old prefix rather than a place that uses it.
            if ($path === 'src/Install/FreshSiteCopyPolicy.php') {
                continue;
            }

            foreach (token_get_all($contents) as $token) {
                if (
                    is_array($token)
                    && in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)
                    && str_contains($token[1], 'VLD-')
                ) {
                    $offenders[] = $path . ':' . $token[2];
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "A string literal still carries this site's order-number prefix. The prefix is the "
            . "order_number_prefix setting, and OrderRepository::formatOrderNumber() applies it:\n"
            . implode("\n", $offenders)
        );
    }

    /* ------------------------------------------------------------------ */
    /* The admin's browser storage                                         */
    /* ------------------------------------------------------------------ */

    public function testTheAdminScriptsStoreTheirStateUnderMygdalasOwnKeys(): void
    {
        // The remembered tab, the open rows, the return target and the
        // save-bar flag were all stored under the old site's prefix. Only
        // these exact keys: the personalization font namespace 'vvld-' is a
        // different thing and stays (App\Service\Personalization\PersonalizationFonts).
        $offenders = [];

        foreach (glob($this->root() . '/admin/assets/*.js') ?: [] as $path) {
            $contents = (string) file_get_contents($path);

            foreach (['vvldAdmin', 'vvldSaveBarSaved'] as $old) {
                if (str_contains($contents, $old)) {
                    $offenders[] = 'admin/assets/' . basename($path) . ' → ' . $old;
                }
            }
        }

        $this->assertSame([], $offenders, "An admin script still uses the old site's storage key:\n" . implode("\n", $offenders));
    }

    /* ------------------------------------------------------------------ */
    /* The shared header                                                   */
    /* ------------------------------------------------------------------ */

    public function testTheMiniCartRendersNoDemoProduct(): void
    {
        // The MARKUP, not the source: the file's docblock names the
        // placeholder it used to render, which is exactly the history a
        // future reader needs and exactly what a source-level match would
        // trip over.
        $markup = $this->renderMiniCart();

        $this->assertStringNotContainsStringIgnoringCase('sleutelhanger', $markup);
        $this->assertStringNotContainsStringIgnoringCase('keychain', $markup);
        $this->assertStringNotContainsStringIgnoringCase('berkenhout', $markup);
        $this->assertStringNotContainsString('14,95', $markup);
    }

    public function testTheMiniCartRendersTheSameEmptyStateTheScriptDoes(): void
    {
        $markup = $this->renderMiniCart();

        // The same sentence assets/js/shop/cart.js renders for an empty cart,
        // so the hand-over from server to script is invisible.
        $this->assertStringContainsString('Je winkelwagen is leeg.', $markup);
        $this->assertStringContainsString('data-en="Your cart is empty."', $markup);
        $this->assertStringContainsString('data-cart-count>0<', $markup);
        $this->assertStringContainsString('cart-dropdown__subtotal" hidden', $markup);

        $script = (string) file_get_contents($this->root() . '/assets/js/shop/cart.js');
        $this->assertStringContainsString('Je winkelwagen is leeg.', $script);
    }

    private function renderMiniCart(): string
    {
        $h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');

        ob_start();
        include $this->root() . '/partials/header-cart.php';

        return (string) ob_get_clean();
    }

    /* ------------------------------------------------------------------ */
    /* Files that should no longer ship                                    */
    /* ------------------------------------------------------------------ */

    public function testTheLegacyStaticProductMockupIsGone(): void
    {
        $this->assertFileDoesNotExist($this->root() . '/product-sleutelhanger.html');
    }

    public function testThePublicDatabaseDiagnosticIsGone(): void
    {
        // The project root is the site root, so this was a page anybody could
        // load that printed the product count and the database error.
        $this->assertFileDoesNotExist($this->root() . '/db-test.php');
    }

    /* ------------------------------------------------------------------ */

    /**
     * Runs $body with App\Service\AppUrl answering $baseUrl, or answering
     * nothing at all when $baseUrl is null, and restores the environment
     * afterwards whatever happens.
     */
    private function withBaseUrl(?string $baseUrl, callable $body): void
    {
        $previous = $_ENV['APP_URL'] ?? null;

        if ($baseUrl === null) {
            unset($_ENV['APP_URL']);
            // Step 2 of the chain must be silent too, or this installation's
            // own stored base URL would answer instead.
            SiteSettings::overrideForTests(['canonical_base_url' => '']);
        } else {
            $_ENV['APP_URL'] = $baseUrl;
        }

        try {
            $body();
        } finally {
            if ($previous === null) {
                unset($_ENV['APP_URL']);
            } else {
                $_ENV['APP_URL'] = $previous;
            }

            SiteSettings::overrideForTests(null);
        }
    }

    /** @return array<string, string> path => contents, PHP files only */
    private function sourceFiles(): array
    {
        $files = [];

        foreach (self::SOURCE_DIRECTORIES as $directory) {
            $base = $this->root() . '/' . $directory;
            if (!is_dir($base)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $relative = $directory . '/' . str_replace('\\', '/', substr($file->getPathname(), strlen($base) + 1));
                    $files[$relative] = (string) file_get_contents($file->getPathname());
                }
            }
        }

        return $files;
    }

    /** @return array<string, string> path => contents, the PHP files in the project root */
    private function rootFiles(): array
    {
        $files = [];

        foreach (glob($this->root() . '/*.php') ?: [] as $path) {
            $files[basename($path)] = (string) file_get_contents($path);
        }

        return $files;
    }

    private function root(): string
    {
        return dirname(__DIR__, 2);
    }
}
