<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Mail\EmailIdentity;
use App\Service\CookieConsentConfig;
use App\Service\SiteSettings;
use PHPUnit\Framework\TestCase;

/**
 * Shared infrastructure must not name one particular company.
 *
 * This codebase is meant to be installed more than once, so the admin shell,
 * the login screen, the 404 page, the transactional e-mails, the payment
 * description and the cookie policy read the site's own name out of
 * App\Service\SiteSettings instead of carrying it as a literal.
 *
 * What this deliberately does NOT police: ordinary page and block content.
 * The "over mij" copy in App\Service\TextImageSplitContent and the seeded
 * rows in db/migrations are this site's editorial text, owned by whoever
 * edits the CMS, and turning those into settings would be a different (and
 * wrong) feature.
 */
final class SiteIdentityTest extends TestCase
{
    /** The one company name that must not appear in shared infrastructure. */
    private const LITERAL = 'Van Veluw Laserdesign';

    /**
     * Files that are infrastructure rather than content, and every one of
     * which used to carry the literal.
     *
     * @var list<string>
     */
    private const SHARED_INFRASTRUCTURE = [
        'admin/_header.php',
        'admin/login.php',
        'partials/page-not-found.php',
        'partials/route-not-found-page.php',
        'partials/header.php',
        'partials/footer.php',
        'partials/head-branding.php',
        'src/Mail/ContactRequestBuilder.php',
        'src/Mail/OrderConfirmationBuilder.php',
        'src/Mail/WithdrawalRequestBuilder.php',
        'src/Mail/EmailIdentity.php',
        'src/Service/Mailer.php',
        'src/Service/OrderConfirmationService.php',
        'src/Service/CookieConsentConfig.php',
        'api/checkout.php',
        'api/contact.php',
        'api/withdrawal-request.php',
        'cart.php',
        'checkout.php',
        'bestelling-status.php',
        'cookiebeleid.php',
        'herroeping.php',
        'portfolio-detail.php',
    ];

    /** Whatever .env had, so every test can restore it. */
    private ?string $envFromName = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->envFromName = isset($_ENV['MAIL_FROM_NAME']) ? (string) $_ENV['MAIL_FROM_NAME'] : null;
    }

    protected function tearDown(): void
    {
        SiteSettings::overrideForTests(null);

        if ($this->envFromName === null) {
            unset($_ENV['MAIL_FROM_NAME']);
        } else {
            $_ENV['MAIL_FROM_NAME'] = $this->envFromName;
        }

        parent::tearDown();
    }

    public function testNoSharedInfrastructureFileNamesOneCompany(): void
    {
        $root = dirname(__DIR__, 2);
        $offenders = [];

        foreach (self::SHARED_INFRASTRUCTURE as $file) {
            $path = $root . '/' . $file;

            $this->assertFileExists($path);

            $source = (string) file_get_contents($path);

            // A comment explaining the history is fine; rendered output is not.
            $withoutComments = (string) preg_replace('#(/\*.*?\*/)|(//[^\n]*)#s', '', $source);

            if (str_contains($withoutComments, self::LITERAL)) {
                $offenders[] = $file;
            }
        }

        $this->assertSame([], $offenders, 'these files should read the site name from SiteSettings');
    }

    public function testTheAdminShellAndLoginScreenShowTheConfiguredName(): void
    {
        $root = dirname(__DIR__, 2);

        foreach (['admin/_header.php', 'admin/login.php'] as $file) {
            $this->assertStringContainsString(
                "SiteSettings::get('site_name')",
                (string) file_get_contents($root . '/' . $file),
                $file . ' should render the configured site name'
            );
        }
    }

    public function testTheSharedNotFoundTitleUsesTheConfiguredName(): void
    {
        SiteSettings::overrideForTests(['site_name' => 'Testbedrijf']);

        require_once dirname(__DIR__, 2) . '/partials/page-not-found.php';

        ob_start();
        render_page_not_found_head();
        $html = (string) ob_get_clean();

        // The 404 head goes through the one shared renderer, in the
        // language of the request and in no other.
        $this->assertStringContainsString('<title>Pagina niet gevonden — Testbedrijf</title>', $html);
        $this->assertStringNotContainsString('data-en', $html);

        // ...and it is still noindex, with nothing a page that does not
        // exist has any business claiming: no canonical, no share preview.
        $this->assertStringContainsString('name="robots" content="noindex,follow"', $html);
        $this->assertStringNotContainsString('rel="canonical"', $html);
        $this->assertStringNotContainsString('og:image', $html);
    }

    public function testTheEmailFooterIsBuiltFromSettings(): void
    {
        // A deployment may pin the sender name in .env; this is about
        // what the code falls back to when it does not.
        unset($_ENV['MAIL_FROM_NAME']);

        SiteSettings::overrideForTests([
            'site_name' => 'Testbedrijf',
            'company_city' => 'Arnhem',
            'email' => 'hallo@testbedrijf.test',
        ]);

        $footer = EmailIdentity::footerLine();

        $this->assertSame('Testbedrijf &middot; Arnhem &middot; hallo@testbedrijf.test', $footer);
        $this->assertStringNotContainsString(self::LITERAL, $footer);
    }

    public function testAnEmptyPartIsLeftOutRatherThanLeavingADanglingSeparator(): void
    {
        // A deployment may pin the sender name in .env; this is about
        // what the code falls back to when it does not.
        unset($_ENV['MAIL_FROM_NAME']);

        SiteSettings::overrideForTests([
            'site_name' => 'Testbedrijf',
            'company_city' => '',
            'email' => 'hallo@testbedrijf.test',
        ]);

        $this->assertSame('Testbedrijf &middot; hallo@testbedrijf.test', EmailIdentity::footerLine());
    }

    public function testTheSenderNameStillPrefersTheDeployConfiguration(): void
    {
        SiteSettings::overrideForTests(['site_name' => 'Testbedrijf']);

        $previous = $_ENV['MAIL_FROM_NAME'] ?? null;

        try {
            $_ENV['MAIL_FROM_NAME'] = 'Verzendnaam uit .env';
            $this->assertSame('Verzendnaam uit .env', EmailIdentity::name());

            $_ENV['MAIL_FROM_NAME'] = '';
            $this->assertSame('Testbedrijf', EmailIdentity::name());

            unset($_ENV['MAIL_FROM_NAME']);
            $this->assertSame('Testbedrijf', EmailIdentity::name());
        } finally {
            if ($previous === null) {
                unset($_ENV['MAIL_FROM_NAME']);
            } else {
                $_ENV['MAIL_FROM_NAME'] = $previous;
            }
        }
    }

    public function testTheCookiePolicyNamesTheConfiguredSite(): void
    {
        SiteSettings::overrideForTests(['site_name' => 'Testbedrijf']);

        $providers = array_column(CookieConsentConfig::policyEntries(), 'provider');

        $this->assertNotEmpty($providers);

        foreach ($providers as $provider) {
            $this->assertStringNotContainsString(self::LITERAL, (string) $provider);
            $this->assertStringNotContainsString('{{site_name}}', (string) $provider);
            $this->assertStringContainsString('Testbedrijf', (string) $provider);
        }
    }

    /* ------------------------------------------------------------------ */
    /* New-install behaviour                                               */
    /* ------------------------------------------------------------------ */

    public function testTheCodeDefaultsForBrandingAreGenericRatherThanThisSites(): void
    {
        $defaults = SiteSettings::defaults();

        $this->assertSame('', $defaults['logo_path']);
        $this->assertSame('', $defaults['logo_alt_path']);
        $this->assertSame('', $defaults['favicon_path']);
        $this->assertSame('', $defaults['og_image_path']);
        $this->assertStringNotContainsString(self::LITERAL, $defaults['site_name']);
    }

    /**
     * The migration that made those generic defaults safe: it pins this
     * install's current values as real rows first, so nothing already live
     * falls back to an empty default.
     */
    public function testAMigrationPinsTheCurrentBrandingBeforeTheDefaultsWentGeneric(): void
    {
        $path = dirname(__DIR__, 2)
            . '/db/migrations/20260909210000_pin_branding_paths_before_generic_defaults.php';

        $this->assertFileExists($path);

        $source = (string) file_get_contents($path);

        $this->assertStringContainsString('INSERT IGNORE', $source, 'it must never overwrite a chosen value');
        foreach (['site_name', 'logo_path', 'favicon_path', 'og_image_path'] as $key) {
            $this->assertStringContainsString("'" . $key . "'", $source);
        }
    }
}
