<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Service\Forms\FormRecipient;
use App\Service\Language\AdminLocale;
use App\Service\SiteSettings;
use App\Service\SiteSettingsValidator;
use PHPUnit\Framework\TestCase;

/**
 * The contract of Site-instellingen (App\Service\SiteSettingsValidator): what
 * is required, what an empty value means, when the contact address may be
 * emptied, and that admin/settings.php asks exactly what the endpoint checks.
 *
 * No database, no session, no web server: the validator is handed the
 * request, the stored settings and the forms. Storing and reading back is
 * Tests\Repository\SiteSettingsPersistenceTest.
 */
final class SiteSettingsValidatorTest extends TestCase
{
    /** The Algemeen form with nothing but a site name filled in. */
    private const ALGEMEEN = [
        'site_name' => 'Testsite',
        'email' => '',
        'company_phone' => '',
        'city_nl' => '',
        'city_en' => '',
        'company_street' => '',
        'company_house_number' => '',
        'company_postal_code' => '',
        'company_city' => '',
        'company_country' => '',
        'kvk_number' => '',
    ];

    protected function setUp(): void
    {
        AdminLocale::overrideForTests('nl');
    }

    protected function tearDown(): void
    {
        AdminLocale::overrideForTests(null);
    }

    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    /**
     * @param array<string, mixed>       $post
     * @param array<string, string>      $current stored values on top of the defaults
     * @param list<array<string, mixed>> $forms
     *
     * @return array{values: array<string, string>, errors: list<string>}
     */
    private function validate(array $post, array $current = [], array $forms = []): array
    {
        return SiteSettingsValidator::validate($post, $current + SiteSettings::defaults(), $forms);
    }

    /**
     * A form as FormRepository::all() returns it: active, no address of its
     * own, and not keeping its submissions.
     *
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function form(string $name, array $overrides = []): array
    {
        return array_merge(
            ['id' => 1, 'name' => $name, 'is_active' => 1, 'notification_email' => null, 'store_submissions' => 0],
            $overrides
        );
    }

    // --- required and optional -------------------------------------------

    public function testASiteNameIsAllTheGeneralFormNeeds(): void
    {
        $this->assertSame([], $this->validate(self::ALGEMEEN)['errors']);
    }

    public function testTheSiteNameIsStillRequired(): void
    {
        $this->assertNotSame([], $this->validate(['site_name' => '   '] + self::ALGEMEEN)['errors']);
    }

    public function testEveryOtherFieldMayBeEmpty(): void
    {
        foreach (array_diff(array_keys(SiteSettingsValidator::FIELDS), SiteSettingsValidator::REQUIRED) as $key) {
            $result = $this->validate([$key => ''] + self::ALGEMEEN);

            $this->assertSame([], $result['errors'], $key . ' must be allowed to stay empty');
            $this->assertSame($key === 'seo_robots_index_default' ? '0' : '', $result['values'][$key], $key);
        }
    }

    public function testAnEmptyCityIsValid(): void
    {
        $result = $this->validate(['city_nl' => '', 'city_en' => ''] + self::ALGEMEEN);

        $this->assertSame([], $result['errors']);
        $this->assertSame('', $result['values']['city_nl']);
    }

    /**
     * Footer phase B: the footer description has one place, the Footer
     * screen. A stale form or a crafted request that still sends it here
     * writes nothing, so the two screens can never overwrite each other.
     */
    public function testTheFooterDescriptionIsNoLongerWrittenHere(): void
    {
        $result = $this->validate(['footer_description_nl' => 'Oud scherm', 'footer_description_en' => 'Old screen'] + self::ALGEMEEN);

        $this->assertSame([], $result['errors']);
        $this->assertArrayNotHasKey('footer_description_nl', $result['values']);
        $this->assertArrayNotHasKey('footer_description_en', $result['values']);
        $this->assertArrayNotHasKey('footer_description_nl', SiteSettingsValidator::FIELDS);
        $this->assertStringNotContainsString('name="footer_description', (string) file_get_contents(self::root() . '/admin/settings.php'));
    }

    public function testEmptyAddressFieldsAreValid(): void
    {
        $result = $this->validate(self::ALGEMEEN);

        $this->assertSame([], $result['errors']);

        foreach (['company_street', 'company_house_number', 'company_postal_code', 'company_city', 'company_country'] as $key) {
            $this->assertSame('', $result['values'][$key], $key);
        }
    }

    public function testFilledInAddressFieldsAreKeptAsTyped(): void
    {
        $result = $this->validate([
            'company_street' => ' Kerkstraat ',
            'company_house_number' => '12A',
            'company_postal_code' => '1234 AB',
            'company_city' => 'Utrecht',
            'company_country' => 'NL',
        ] + self::ALGEMEEN);

        $this->assertSame([], $result['errors']);
        $this->assertSame('Kerkstraat', $result['values']['company_street'], 'trimmed, and otherwise untouched');
        $this->assertSame('12A', $result['values']['company_house_number']);
        $this->assertSame('1234 AB', $result['values']['company_postal_code']);
        $this->assertSame('Utrecht', $result['values']['company_city']);
        $this->assertSame('NL', $result['values']['company_country']);
    }

    // --- the contact address ---------------------------------------------

    public function testTheEmailAddressIsOptional(): void
    {
        $this->assertSame([], $this->validate(['email' => ''] + self::ALGEMEEN)['errors']);
    }

    public function testAFilledInEmailAddressMustBeAUsableOne(): void
    {
        $this->assertNotSame([], $this->validate(['email' => 'geen-adres'] + self::ALGEMEEN)['errors']);
        $this->assertNotSame(
            [],
            $this->validate(['email' => "info@example.com\r\nBcc: someone@example.com"] + self::ALGEMEEN)['errors'],
            'an address that could inject a second header is no address'
        );
        $this->assertSame([], $this->validate(['email' => 'info@example.com'] + self::ALGEMEEN)['errors']);
    }

    public function testEmptyingTheAddressIsRefusedWhileAnActiveFormWouldLoseItsSubmissions(): void
    {
        $result = $this->validate(
            ['email' => ''] + self::ALGEMEEN,
            ['email' => 'info@example.com'],
            [$this->form('Offerteformulier'), $this->form('Nieuwsbrief', ['store_submissions' => 1])]
        );

        $this->assertCount(1, $result['errors']);
        $this->assertStringContainsString('Offerteformulier', $result['errors'][0], 'the message names the form that would lose submissions');
        $this->assertStringNotContainsString('Nieuwsbrief', $result['errors'][0], 'a form that keeps its submissions loses nothing');
    }

    public function testEmptyingTheAddressIsAllowedWhenNoFormDependsOnItAlone(): void
    {
        $forms = [
            $this->form('Bewaart alles', ['store_submissions' => 1]),
            $this->form('Eigen adres', ['notification_email' => 'werkplaats@example.com']),
            $this->form('Uitgeschakeld', ['is_active' => 0]),
        ];

        $this->assertSame([], $this->validate(['email' => ''] + self::ALGEMEEN, ['email' => 'info@example.com'], $forms)['errors']);
    }

    /**
     * Only the step from a working address to none is refused. A site that
     * never had one is already in that state, and its screen says so; an
     * unrelated save must not be blocked on it.
     */
    public function testASiteThatNeverHadAnAddressIsNotBlockedFromOtherSaves(): void
    {
        $result = $this->validate(['email' => '', 'city_nl' => 'Nieuw'] + self::ALGEMEEN, ['email' => ''], [$this->form('Offerteformulier')]);

        $this->assertSame([], $result['errors']);
    }

    public function testFormsNeedingTheSiteAddressAreNamed(): void
    {
        $this->assertSame(
            ['Offerteformulier'],
            SiteSettingsValidator::formsNeedingTheSiteAddress([
                $this->form('Offerteformulier'),
                $this->form('Bewaart alles', ['store_submissions' => 1]),
            ])
        );
    }

    public function testAFormThatWouldLoseItsSubmissionsIsRecognised(): void
    {
        $this->assertTrue(FormRecipient::losesSubmissions($this->form('A'), null));
        $this->assertFalse(FormRecipient::losesSubmissions($this->form('A'), 'info@example.com'), 'the site address catches it');
        $this->assertFalse(FormRecipient::losesSubmissions($this->form('A', ['store_submissions' => 1]), null), 'kept in the CMS');
        $this->assertFalse(FormRecipient::losesSubmissions($this->form('A', ['notification_email' => 'werkplaats@example.com']), null), 'its own address');
        $this->assertFalse(FormRecipient::losesSubmissions($this->form('A', ['is_active' => 0]), null), 'switched off: nobody can send it anything');
        $this->assertTrue(FormRecipient::losesSubmissions($this->form('A', ['notification_email' => 'geen adres']), null), 'an unusable address of its own is no address');
        $this->assertTrue(FormRecipient::losesSubmissions($this->form('A'), 'geen adres'), 'nor is an unusable site address');

        // The form editor hands in booleans rather than a database row.
        $this->assertTrue(FormRecipient::losesSubmissions(['is_active' => true, 'notification_email' => '', 'store_submissions' => false], null));
    }

    public function testTheFormEditorRefusesAndFlagsAFormThatWouldLoseSubmissions(): void
    {
        $endpoint = (string) file_get_contents(self::root() . '/api/admin/update-form.php');
        $screen = (string) file_get_contents(self::root() . '/admin/form.php');

        $this->assertStringContainsString('FormRecipient::losesSubmissions($fields, FormRecipient::siteFallback())', $endpoint);
        $this->assertStringContainsString("AdminTranslator::trans('validation.form_submissions_go_nowhere')", $endpoint);
        $this->assertStringContainsString('FormRecipient::losesSubmissions($values, $siteFallback)', $screen);
    }

    // --- what may be written ---------------------------------------------

    public function testOnlyTheListedKeysTheRequestCarriedAreWritten(): void
    {
        $result = $this->validate([
            'site_name' => 'Testsite',
            'csrf_token' => 'abc',
            'header_cta_label_nl' => 'Niet van dit scherm',
            'footer_show_email' => '0',
        ]);

        $this->assertSame(['site_name' => 'Testsite'], $result['values']);
    }

    public function testAValueThatIsNotTextIsIgnored(): void
    {
        $result = $this->validate(['site_name' => ['Testsite'], 'email' => ['x@example.com']]);

        $this->assertSame([], $result['values']);
        $this->assertSame([], $result['errors'], 'a field that did not arrive as text is a field that did not arrive');
    }

    public function testAChangedValueOverTheLimitIsRefused(): void
    {
        $this->assertNotSame([], $this->validate(['city_nl' => str_repeat('a', 151)] + self::ALGEMEEN)['errors']);
        $this->assertNotSame([], $this->validate(['company_country' => 'NLD'] + self::ALGEMEEN)['errors']);
    }

    public function testAnUntouchedStoredValueOverTheLimitDoesNotBlockTheSave(): void
    {
        $legacy = str_repeat('a', 600);

        $result = $this->validate(['city_nl' => $legacy] + self::ALGEMEEN, ['city_nl' => $legacy]);

        $this->assertSame([], $result['errors']);
    }

    public function testTheIndexingSwitchIsStoredAsAClosedEnum(): void
    {
        $this->assertSame('1', $this->validate(['seo_robots_index_default' => '1'])['values']['seo_robots_index_default']);
        $this->assertSame('0', $this->validate(['seo_robots_index_default' => 'yes'])['values']['seo_robots_index_default']);
        $this->assertSame('0', $this->validate(['seo_robots_index_default' => '0'])['values']['seo_robots_index_default']);
    }

    public function testEveryListedFieldIsAKnownSetting(): void
    {
        foreach (array_keys(SiteSettingsValidator::FIELDS) as $key) {
            $this->assertArrayHasKey($key, SiteSettings::defaults(), $key);
        }

        foreach (SiteSettingsValidator::REQUIRED as $key) {
            $this->assertArrayHasKey($key, SiteSettingsValidator::FIELDS, $key);
        }
    }

    // --- the screen and the endpoint follow the same contract ------------

    /**
     * Every listed field is one control on admin/settings.php, with the
     * validator's own maxlength, and `required` exactly where the validator
     * requires it. One line per control is how the screen is written, which
     * is what keeps this parse honest.
     */
    public function testTheScreenAsksExactlyWhatTheValidatorChecks(): void
    {
        $lines = preg_split('/\R/', (string) file_get_contents(self::root() . '/admin/settings.php')) ?: [];

        foreach (SiteSettingsValidator::FIELDS as $key => $maxLength) {
            if ($key === 'seo_robots_index_default') {
                continue; // a switch with its hidden 0: no length, never required
            }

            $controls = array_values(array_filter(
                $lines,
                static fn (string $line): bool => preg_match('/<(?:input|textarea)\b.*\bname="' . preg_quote($key, '/') . '"/', $line) === 1
            ));

            $this->assertCount(1, $controls, $key . ' must be exactly one control on admin/settings.php');

            $this->assertStringContainsString(
                $key === 'seo_default_description'
                    ? 'maxlength="<?= \App\Service\Seo::MAX_META_DESCRIPTION_LENGTH ?>"'
                    : 'maxlength="' . $maxLength . '"',
                $controls[0],
                $key . ': the screen must accept the same length as the validator'
            );

            $this->assertSame(
                in_array($key, SiteSettingsValidator::REQUIRED, true),
                preg_match('/\srequired(?=[\s>])/', $controls[0]) === 1,
                $key . ': required on the screen must match the validator'
            );
        }
    }

    public function testTheEndpointWritesWhatTheValidatorLetThrough(): void
    {
        $endpoint = (string) file_get_contents(self::root() . '/api/admin/update-site-settings.php');

        $this->assertStringContainsString('SiteSettingsValidator::validate($_POST, $current, $forms)', $endpoint);
        $this->assertStringContainsString('->upsertMany($fields)', $endpoint);
        $this->assertStringNotContainsString(
            'SiteSettings::defaults()',
            $endpoint,
            'the endpoint must not walk every known setting: only the validator\'s list may be written'
        );
    }

    // --- an empty value leaves nothing behind on the site -----------------

    public function testAnEmptyValueLeavesNoEmptyMarkupOnTheSite(): void
    {
        $footer = (string) file_get_contents(self::root() . '/partials/footer.php');
        $this->assertStringContainsString(
            "<?php if (trim(\$footerDescriptionNl) !== '' || trim(\$footerDescriptionEn) !== ''): ?>",
            $footer,
            'the footer prints no paragraph without a description'
        );

        $contact = (string) file_get_contents(self::root() . '/partials/section-contact-form.php');
        $this->assertStringContainsString("<?php if (\$contactEmail !== ''): ?>", $contact, 'no bare "E-mail" label or empty mailto:');
        $this->assertStringContainsString('<?php if (!$contactCity->isEmpty()): ?>', $contact, 'no bare "Plaats" label');
    }
}
