<?php

declare(strict_types=1);

namespace Tests\Repository;

use App\Module\ModuleRegistry;
use App\Repository\SiteSettingRepository;
use App\Service\ShopSettings;
use App\Service\SiteSettings;
use App\Service\SiteSettingsValidator;
use PHPUnit\Framework\TestCase;

/**
 * Site-instellingen and Shop-instellingen against the test database: what
 * the validators let through is stored the way their endpoints store it, and
 * App\Service\SiteSettings reads it back the way every page does.
 *
 * tearDown() puts back the rows it touched. A key that had no row before is
 * left with an empty one, which SiteSettings reads as its default, exactly
 * like a missing row.
 */
final class SiteSettingsPersistenceTest extends TestCase
{
    /** Every key a test here writes. */
    private const TOUCHED = [
        'company_street',
        'company_house_number',
        'company_postal_code',
        'company_city',
        'company_country',
        'company_phone',
        'invoice_footer_text',
        'company_name',
        'invoice_number_prefix',
        'order_email_intro',
    ];

    /** @var array<string, string> */
    private array $original = [];

    protected function setUp(): void
    {
        $this->original = (new SiteSettingRepository())->findAll();
        SiteSettings::clearCache();
    }

    protected function tearDown(): void
    {
        ModuleRegistry::overrideForTests(null);

        $restore = [];
        foreach (self::TOUCHED as $key) {
            $restore[$key] = $this->original[$key] ?? '';
        }

        (new SiteSettingRepository())->upsertMany($restore);
        SiteSettings::clearCache();
    }

    /**
     * What api/admin/update-site-settings.php does with a submission, minus
     * the request.
     *
     * @param array<string, string> $post
     */
    private function saveSiteSettings(array $post): void
    {
        $validated = SiteSettingsValidator::validate($post, SiteSettings::all(), []);
        $this->assertSame([], $validated['errors']);

        (new SiteSettingRepository())->upsertMany($validated['values']);
        SiteSettings::clearCache();
    }

    /**
     * What api/admin/update-shop-settings.php does with a submission.
     *
     * @param array<string, string> $post
     */
    private function saveShopSettings(array $post): void
    {
        $validated = ShopSettings::validate($post, SiteSettings::all());
        $this->assertSame([], $validated['errors']);

        (new SiteSettingRepository())->upsertMany($validated['values']);
        SiteSettings::clearCache();
    }

    public function testFilledInAddressFieldsAreStoredAndReadBack(): void
    {
        $this->saveSiteSettings([
            'company_street' => 'Kerkstraat',
            'company_house_number' => '12A',
            'company_postal_code' => '1234 AB',
            'company_city' => 'Utrecht',
            'company_country' => 'BE',
            'company_phone' => '030 123 45 67',
        ]);

        $settings = SiteSettings::all();

        $this->assertSame('Kerkstraat', $settings['company_street']);
        $this->assertSame('12A', $settings['company_house_number']);
        $this->assertSame('1234 AB', $settings['company_postal_code']);
        $this->assertSame('Utrecht', $settings['company_city']);
        $this->assertSame('BE', $settings['company_country']);
        $this->assertSame('030 123 45 67', $settings['company_phone']);
    }

    public function testEmptiedOptionalFieldsStayEmpty(): void
    {
        $this->saveSiteSettings(['company_street' => 'Kerkstraat', 'company_phone' => '030 123 45 67']);
        $this->saveSiteSettings(['company_street' => '', 'company_phone' => '', 'company_country' => '']);

        $settings = SiteSettings::all();

        $this->assertSame('', $settings['company_street']);
        $this->assertSame('', $settings['company_phone']);
        $this->assertSame('NL', $settings['company_country'], 'the one address field with a format default keeps it');
    }

    /**
     * A save writes only what its form carried, so a setting that lives on
     * another tab or another screen keeps its stored value.
     */
    public function testASiteSettingsSaveLeavesEverySettingItDidNotCarryAlone(): void
    {
        $this->saveShopSettings(['invoice_footer_text' => 'Blijft staan.']);

        $this->saveSiteSettings(['company_city' => 'Utrecht']);

        $this->assertSame('Blijft staan.', SiteSettings::all()['invoice_footer_text']);
    }

    /**
     * Switching the Shop off is not uninstalling it (MODULES.md): its settings
     * stay stored, a Site-instellingen save cannot reach them, and every one
     * of them is there when the Shop comes back.
     */
    public function testShopSettingsSurviveTheShopBeingSwitchedOffAndOn(): void
    {
        $this->saveShopSettings([
            'company_name' => 'J. Jansen Handelsonderneming',
            'invoice_number_prefix' => 'JJH',
            'order_email_intro' => 'Hoi {{customer_name}}, dank je wel!',
        ]);

        ModuleRegistry::overrideForTests(['shop' => false, 'personalization' => false]);
        $this->saveSiteSettings(['company_city' => 'Utrecht']);
        $this->assertStoredShopSettings();

        ModuleRegistry::overrideForTests(['shop' => true, 'personalization' => true]);
        SiteSettings::clearCache();
        $this->assertStoredShopSettings();
    }

    private function assertStoredShopSettings(): void
    {
        $settings = SiteSettings::all();

        $this->assertSame('J. Jansen Handelsonderneming', $settings['company_name']);
        $this->assertSame('JJH', $settings['invoice_number_prefix']);
        $this->assertSame('Hoi {{customer_name}}, dank je wel!', $settings['order_email_intro'], 'placeholders are stored as typed');
    }
}
