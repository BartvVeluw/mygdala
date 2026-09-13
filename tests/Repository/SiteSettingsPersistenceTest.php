<?php

declare(strict_types=1);

namespace Tests\Repository;

use App\Repository\SiteSettingRepository;
use App\Service\SiteSettings;
use App\Service\SiteSettingsValidator;
use PHPUnit\Framework\TestCase;

/**
 * Site-instellingen against the test database: what the validator lets
 * through is stored the way api/admin/update-site-settings.php stores it, and
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
        'city_nl',
        'footer_description_nl',
        'invoice_footer_text',
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
        $restore = [];
        foreach (self::TOUCHED as $key) {
            $restore[$key] = $this->original[$key] ?? '';
        }

        (new SiteSettingRepository())->upsertMany($restore);
        SiteSettings::clearCache();
    }

    /**
     * What the endpoint does with a submission, minus the request.
     *
     * @param array<string, string> $post
     */
    private function save(array $post): void
    {
        $validated = SiteSettingsValidator::validate($post, SiteSettings::all(), []);
        $this->assertSame([], $validated['errors']);

        (new SiteSettingRepository())->upsertMany($validated['values']);
        SiteSettings::clearCache();
    }

    public function testFilledInAddressFieldsAreStoredAndReadBack(): void
    {
        $this->save([
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
        $this->save(['company_street' => 'Kerkstraat', 'city_nl' => 'Utrecht', 'footer_description_nl' => 'Een zin.']);
        $this->save(['company_street' => '', 'city_nl' => '', 'footer_description_nl' => '', 'company_country' => '']);

        $settings = SiteSettings::all();

        $this->assertSame('', $settings['company_street']);
        $this->assertSame('', $settings['city_nl']);
        $this->assertSame('', $settings['footer_description_nl']);
        $this->assertSame('NL', $settings['company_country'], 'the one address field with a format default keeps it');
    }

    /**
     * A save writes only what its form carried, so a setting that lives on
     * another tab or another screen keeps its stored value.
     */
    public function testASaveLeavesEverySettingItDidNotCarryAlone(): void
    {
        (new SiteSettingRepository())->upsertMany(['invoice_footer_text' => 'Blijft staan.']);
        SiteSettings::clearCache();

        $this->save(['company_city' => 'Utrecht']);

        $this->assertSame('Blijft staan.', SiteSettings::all()['invoice_footer_text']);
    }
}
