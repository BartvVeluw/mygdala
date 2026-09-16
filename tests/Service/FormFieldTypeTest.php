<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Service\Forms\FieldTypes\CheckboxFieldType;
use App\Service\Forms\FieldTypes\FormFieldControl;
use App\Service\Forms\FieldTypes\FormFieldType;
use App\Service\Forms\FormField;
use App\Service\Forms\FormFieldKey;
use App\Service\Forms\FormFieldOptions;
use App\Service\Forms\FormFieldTypes;
use App\Service\Forms\FormRecipient;
use App\Service\Forms\FormSourcePath;
use App\Service\Forms\FormSpamGuard;
use App\Service\Forms\FormText;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The pieces of Core Forms that are pure logic: the closed field-type
 * registry and the contract every type honours, option parsing, key
 * generation, the bilingual fallback, safe source paths, address validation
 * and the two spam checks that need no database.
 *
 * NO DATABASE AND NO WEB SERVER — this file belongs to the `fast` tier and
 * must keep passing with DB_HOST pointing at nothing (TESTING.md). Anything
 * about repositories, rendering or submitting lives in the other Form test
 * files.
 */
class FormFieldTypeTest extends TestCase
{
    /**
     * The registry is a CLOSED list. If this ever starts reading a
     * directory, a database row or a request, this test is where it shows.
     */
    public function testRegistryHoldsExactlyTheTypesVersionOneSupports(): void
    {
        $this->assertSame(
            ['text', 'textarea', 'email', 'tel', 'select', 'radio', 'checkbox', 'consent'],
            FormFieldTypes::keys()
        );
    }

    public function testAnUnregisteredKeyIsAMissAndNeverAClassName(): void
    {
        foreach (['', 'file', 'date', 'FormFieldType', 'App\\Service\\Forms\\FieldTypes\\TextFieldType', '../text'] as $key) {
            $this->assertFalse(FormFieldTypes::has($key), $key . ' must not be a registered field type');
            $this->assertNull(FormFieldTypes::get($key), $key . ' must not resolve to a type');
        }
    }

    /**
     * Forms V1 deliberately has no upload field, no date/time picker, no
     * repeater and no rich text. FORMS.md says so; this makes it true.
     */
    public function testTheDeferredFieldTypesAreGenuinelyAbsent(): void
    {
        foreach (['file', 'upload', 'attachment', 'date', 'time', 'address', 'repeater', 'signature', 'richtext', 'html', 'hidden', 'payment', 'calculated'] as $deferred) {
            $this->assertFalse(FormFieldTypes::has($deferred), 'Forms V1 must not support "' . $deferred . '"');
        }
    }

    /** Every registered type answers the whole contract, without exception. */
    public function testEveryRegisteredTypeHonoursTheContract(): void
    {
        foreach (FormFieldTypes::all() as $key => $type) {
            $this->assertInstanceOf(FormFieldType::class, $type);
            $this->assertSame($key, $type->key(), 'a type must know the key it is registered under');
            $this->assertGreaterThan(0, $type->maxLength(), $key . ' needs a positive maximum length');
            $this->assertContains($type->labelPosition(), ['before', 'wrap', 'legend'], $key . ' has an unknown label position');

            // normalize() must accept anything a request can carry.
            foreach ([null, '', 'x', 42, 1.5, ['a'], new \stdClass()] as $raw) {
                $this->assertIsString($type->normalize($raw, $this->field($key)), $key . '::normalize must always return a string');
            }
        }
    }

    /**
     * What the CMS calls a type is the admin catalogue's, filed under the
     * type's registry key — the only place those words exist
     * (App\Service\Forms\FormFieldTypes). Every registered type has a name
     * and a description in every catalogue, neither of them is the bare
     * key, and the classes carry no name of their own to drift from it.
     */
    public function testEveryRegisteredTypeIsNamedAndDescribedInEveryCatalogue(): void
    {
        $root = dirname(__DIR__, 2);

        foreach (['nl', 'en'] as $language) {
            $catalog = require $root . '/src/Service/Language/messages/' . $language . '.php';
            $names = [];

            foreach (FormFieldTypes::keys() as $key) {
                foreach (['label', 'description'] as $part) {
                    $catalogKey = 'formfieldtype.' . $key . '.' . $part;

                    $this->assertArrayHasKey($catalogKey, $catalog, $language . ' has no ' . $catalogKey);
                    $this->assertNotSame('', trim($catalog[$catalogKey]), $catalogKey . ' is empty in ' . $language);
                    $this->assertNotSame($key, $catalog[$catalogKey], $catalogKey . ' is the bare key in ' . $language);
                }

                $names[] = $catalog['formfieldtype.' . $key . '.label'];
            }

            $this->assertSame($names, array_unique($names), 'two types share a name in ' . $language);
        }

        foreach (FormFieldTypes::all() as $key => $type) {
            $this->assertFalse(method_exists($type, 'label'), $key . ' names itself in PHP; the name belongs in the catalogue');
        }
    }

    /** Only an e-mail field may ever become the notification's Reply-To. */
    public function testOnlyTheEmailTypeHoldsAnEmailAddress(): void
    {
        foreach (FormFieldTypes::all() as $key => $type) {
            $this->assertSame($key === 'email', $type->holdsEmailAddress(), $key);
        }
    }

    public function testOnlyAConsentBoxIsAlwaysRequired(): void
    {
        foreach (FormFieldTypes::all() as $key => $type) {
            $this->assertSame($key === 'consent', $type->requiredIsFixed(), $key);
        }
    }

    public function testOnlyChoiceTypesUseOptions(): void
    {
        foreach (FormFieldTypes::all() as $key => $type) {
            $this->assertSame(in_array($key, ['select', 'radio'], true), $type->usesOptions(), $key);
        }
    }

    /**
     * The one rule that protects the outgoing e-mail: nothing a visitor
     * types can carry a CR or an LF into a header.
     */
    #[DataProvider('controlCharacterProvider')]
    public function testNoTypeLetsAControlCharacterThrough(string $raw): void
    {
        foreach (FormFieldTypes::all() as $key => $type) {
            $value = $type->normalize($raw, $this->field($key));

            $this->assertSame(
                0,
                preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value),
                $key . ' let a control character through'
            );

            if ($key !== 'textarea') {
                $this->assertStringNotContainsString("\n", $value, $key . ' let a newline through');
                $this->assertStringNotContainsString("\r", $value, $key . ' let a carriage return through');
            }
        }
    }

    /** @return list<array{0: string}> */
    public static function controlCharacterProvider(): array
    {
        return [
            ["a@b.com\r\nBcc: evil@example.com"],
            ["regel\r\ntwee"],
            ["met\ttab"],
            ["nul\0byte"],
        ];
    }

    /** A textarea is the one type that may keep newlines, normalised to LF. */
    public function testATextareaKeepsItsLineBreaksAndNormalisesThem(): void
    {
        $type = FormFieldTypes::get('textarea');

        $this->assertSame("een\ntwee\ndrie", $type->normalize("een\r\ntwee\rdrie", $this->field('textarea')));
    }

    public function testValuesAreCappedAtTheTypeMaximum(): void
    {
        foreach (FormFieldTypes::all() as $key => $type) {
            $value = $type->normalize(str_repeat('a', $type->maxLength() + 500), $this->field($key));

            $this->assertLessThanOrEqual($type->maxLength(), mb_strlen($value), $key . ' did not cap its value');
        }
    }

    public function testEmailValidationAcceptsRealAddressesAndRefusesTheRest(): void
    {
        $type = FormFieldTypes::get('email');
        $field = $this->field('email');

        foreach (['a@b.nl', 'voor.naam+tag@sub.example.co.uk'] as $valid) {
            $this->assertNull($type->validate($valid, $field), $valid . ' should be accepted');
        }

        foreach (['geen-adres', 'a@', '@b.nl', 'a b@c.nl'] as $invalid) {
            $this->assertInstanceOf(FormText::class, $type->validate($invalid, $field), $invalid . ' should be refused');
        }
    }

    /**
     * A phone field must not enforce one country's format: a Belgian number,
     * an extension and a number typed with spaces are all real customers.
     */
    public function testTelephoneAcceptsInternationalAndLooselyTypedNumbers(): void
    {
        $type = FormFieldTypes::get('tel');
        $field = $this->field('tel');

        foreach (['0612345678', '+31 6 12345678', '+32 (0)2 555 44 33', '024-1234567', '0241234567 / 202'] as $valid) {
            $this->assertNull($type->validate($valid, $field), $valid . ' should be accepted');
        }

        foreach (['bel me maar', 'nul-zes', '+', '12'] as $invalid) {
            $this->assertInstanceOf(FormText::class, $type->validate($invalid, $field), $invalid . ' should be refused');
        }
    }

    public function testAChoiceValueMustBeOneOfTheConfiguredOptions(): void
    {
        foreach (['select', 'radio'] as $key) {
            $type = FormFieldTypes::get($key);
            $field = $this->field($key, "Particulier|Personal\nZakelijk|Business");

            $this->assertNull($type->validate('Particulier', $field), $key . ' should accept a configured option');
            $this->assertNull($type->validate('Zakelijk', $field), $key . ' should accept a configured option');

            foreach (['Personal', 'particulier', 'Iets anders', '<script>'] as $invalid) {
                $this->assertInstanceOf(
                    FormText::class,
                    $type->validate($invalid, $field),
                    $key . ' must refuse "' . $invalid . '"'
                );
            }
        }
    }

    public function testACheckboxRecordsAReadableWordRatherThanAOne(): void
    {
        $type = FormFieldTypes::get('checkbox');
        $field = $this->field('checkbox');

        $this->assertSame(CheckboxFieldType::CHECKED_NL, $type->normalize('Ja', $field));
        $this->assertSame(CheckboxFieldType::CHECKED_NL, $type->normalize('1', $field));
        $this->assertSame('', $type->normalize(null, $field), 'an unticked box posts nothing at all');
        $this->assertSame('', $type->normalize('', $field));
    }

    public function testEveryTypeRendersAControlCarryingItsIdAndName(): void
    {
        foreach (FormFieldTypes::all() as $key => $type) {
            $field = $this->field($key, "Een|One\nTwee|Two");

            ob_start();
            $type->renderControl(new FormFieldControl($field, 'form-abc-veld', 'veld', '', 'form-abc-veld-error', false));
            $html = (string) ob_get_clean();

            $this->assertNotSame('', $html, $key . ' rendered nothing');
            $this->assertStringContainsString('name="veld"', $html, $key . ' must post under the field key');

            if ($key !== 'radio') {
                // A radio group's ids are per option; the group itself has none.
                $this->assertStringContainsString('id="form-abc-veld"', $html, $key . ' must carry the id its label points at');
            } else {
                $this->assertStringContainsString('id="form-abc-veld-0"', $html, 'a radio option needs its own id');
            }
        }
    }

    public function testARenderedControlEscapesWhatTheVisitorTyped(): void
    {
        $type = FormFieldTypes::get('text');

        ob_start();
        $type->renderControl(new FormFieldControl(
            $this->field('text'),
            'form-abc-veld',
            'veld',
            '"><script>alert(1)</script>',
            '',
            true
        ));
        $html = (string) ob_get_clean();

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringContainsString('aria-invalid="true"', $html);
    }

    // ---------------------------------------------------------------- options

    public function testOptionsParseOnePerLineWithAnOptionalEnglishHalf(): void
    {
        $options = FormFieldOptions::fromStored("Particulier|Personal\nZakelijk\n\n  Overig | Other  ");

        $this->assertSame(3, $options->count());
        $this->assertSame(['Particulier', 'Zakelijk', 'Overig'], array_map(static fn ($o) => $o->nl, $options->all()));
        $this->assertSame(['Personal', 'Zakelijk', 'Other'], array_map(static fn ($o) => $o->en, $options->all()));
    }

    public function testDuplicateAndEmptyOptionsAreDropped(): void
    {
        $options = FormFieldOptions::fromStored("Ja|Yes\n\nJa|Anders\n   \nNee|No");

        $this->assertSame(2, $options->count());
        $this->assertSame('Yes', $options->find('Ja')->en, 'the first spelling of an option wins');
    }

    public function testAnOptionListCannotGrowWithoutBound(): void
    {
        $lines = [];
        for ($i = 0; $i < FormFieldOptions::MAX_OPTIONS + 20; $i++) {
            $lines[] = 'Optie ' . $i;
        }

        $this->assertSame(FormFieldOptions::MAX_OPTIONS, FormFieldOptions::fromStored(implode("\n", $lines))->count());
    }

    /**
     * The field editor's option rows become exactly the stored text the
     * textarea used to produce: one line per option, `NL|EN` only where the
     * English differs, empty and duplicate rows dropped by the same parser.
     */
    public function testOptionRowsBecomeTheStoredTextTheParserReads(): void
    {
        $rows = [
            ['nl' => 'Ja', 'en' => 'Yes'],
            ['nl' => 'Nee', 'en' => ''],
            ['nl' => '', 'en' => 'Maybe'],
            ['nl' => 'Ja', 'en' => 'Yes please'],
            ['nl' => 'Later', 'en' => 'Later'],
        ];

        $stored = FormFieldOptions::rowsToStored($rows);

        $this->assertSame("Ja|Yes\nNee\nLater", $stored);
        $this->assertSame($stored, FormFieldOptions::toStored($stored), 'already canonical');
        $this->assertSame('', FormFieldOptions::rowsToStored([['nl' => '', 'en' => '']]));
    }

    /** A row is one line: a line break cannot split one option into two. */
    public function testAnOptionRowIsCleanedToOneLine(): void
    {
        $this->assertSame('Ja graag', FormFieldOptions::rowText("  Ja\r\ngraag \t"));
        $this->assertSame('', FormFieldOptions::rowText(['Ja']));
        $this->assertSame('', FormFieldOptions::rowText(null));

        $this->assertTrue(FormFieldOptions::holdsSeparator('Ja|Yes'));
        $this->assertFalse(FormFieldOptions::holdsSeparator('Ja / Yes'));
    }

    public function testStoredOptionsRoundTripThroughTheParser(): void
    {
        $stored = FormFieldOptions::toStored("  Particulier | Personal \r\nZakelijk|Zakelijk\n\n");

        $this->assertSame("Particulier|Personal\nZakelijk", $stored, 'an English half equal to the Dutch one is not written twice');
        $this->assertSame($stored, FormFieldOptions::toStored($stored), 'storing what was stored must change nothing');
    }

    // ------------------------------------------------------------- field keys

    public function testAKeyIsGeneratedFromTheLabelAndStaysUrlSafe(): void
    {
        $this->assertSame('naam', FormFieldKey::fromLabel('Naam'));
        $this->assertSame('voor-wie-is-de-aanvraag', FormFieldKey::fromLabel('Voor wie is de aanvraag?'));
        $this->assertSame('e-mail', FormFieldKey::fromLabel('E-mail'));
        $this->assertSame('telefoonnummer', FormFieldKey::fromLabel('Telefoonnummer'));
        $this->assertSame('mijn-idee', FormFieldKey::fromLabel('  Mijn idée!  '));
    }

    public function testAGeneratedKeyIsMadeUniqueWithinTheForm(): void
    {
        $this->assertSame('naam', FormFieldKey::fromLabel('Naam', []));
        $this->assertSame('naam-2', FormFieldKey::fromLabel('Naam', ['naam']));
        $this->assertSame('naam-3', FormFieldKey::fromLabel('Naam', ['naam', 'naam-2']));
    }

    /**
     * A generated key must never collide with the form's own control fields,
     * or a bot-detection input would arrive as an ordinary answer.
     */
    public function testAGeneratedKeyNeverCollidesWithTheFormsOwnControls(): void
    {
        foreach (FormFieldKey::RESERVED as $reserved) {
            $key = FormFieldKey::fromLabel($reserved);

            $this->assertNotSame($reserved, $key, '"' . $reserved . '" is reserved and must not be generated');
            $this->assertTrue(FormFieldKey::isValid($key));
        }
    }

    public function testKeyValidationRefusesEverythingThatIsNotASimpleSlug(): void
    {
        foreach (['naam', 'voor-wie', 'veld-2'] as $valid) {
            $this->assertTrue(FormFieldKey::isValid($valid), $valid);
        }

        foreach (['', 'Naam', 'met spatie', 'veld[]', 'veld.punt', '-begin', 'eind-', 'veld--dubbel', 'hp-note', str_repeat('a', 65)] as $invalid) {
            $this->assertFalse(FormFieldKey::isValid($invalid), $invalid . ' must not be a valid field key');
        }
    }

    // ------------------------------------------------------- bilingual text

    public function testAnEmptyEnglishValueFallsBackToTheDutchOne(): void
    {
        $this->assertSame('Naam', FormText::of('Naam', '')->en);
        $this->assertSame('Naam', FormText::of('Naam', null)->en);
        $this->assertSame('Naam', FormText::of('Naam', '   ')->en);
        $this->assertSame('Name', FormText::of('Naam', 'Name')->en);
    }

    public function testTextIsTrimmedAndKnowsWhenItIsEmpty(): void
    {
        $this->assertTrue(FormText::of('  ', '  ')->isEmpty());
        $this->assertSame('Naam', FormText::of('  Naam  ')->nl);
        $this->assertSame('Name', FormText::of('Naam', 'Name')->in('en'));
        $this->assertSame('Naam', FormText::of('Naam', 'Name')->in('de'), 'anything that is not English is Dutch');
    }

    // ------------------------------------------------------------ source path

    /**
     * The hidden source field is visitor input and ends up in a Location
     * header, so anything that is not a plain same-site path is refused.
     */
    public function testOnlyASameSiteRootRelativePathIsAccepted(): void
    {
        foreach (['/', '/contact.php', '/een/twee', '/pagina?a=1'] as $valid) {
            $this->assertSame($valid, FormSourcePath::clean($valid), $valid . ' should be accepted');
        }

        foreach ([
            '//evil.example.com',
            'https://evil.example.com',
            '/\\evil.example.com',
            "/contact.php\r\nLocation: https://evil.example.com",
            '/@evil.example.com',
            'contact.php',
            '',
            null,
            ['/'],
            str_repeat('/a', 200),
        ] as $invalid) {
            $this->assertNull(FormSourcePath::clean($invalid), var_export($invalid, true) . ' must be refused');
        }
    }

    public function testAFragmentIsDroppedFromASourcePath(): void
    {
        $this->assertSame('/contact.php', FormSourcePath::clean('/contact.php#form-abc'));
    }

    public function testTheStatusParametersReplaceEachOtherRatherThanAccumulate(): void
    {
        $once = FormSourcePath::withStatus('/contact.php', 'error', 'form-abc');
        $twice = FormSourcePath::withStatus($once, 'success', 'form-abc');

        $this->assertSame(1, substr_count($twice, 'form-status'), 'a second round must replace the first status');
        $this->assertStringContainsString('form-status=success', $twice);
        $this->assertStringStartsWith('/contact.php?', $twice);
    }

    public function testAnUnsafeSourcePathCannotSurviveIntoARedirect(): void
    {
        $target = FormSourcePath::withStatus('https://evil.example.com', 'error', 'form-abc');

        $this->assertStringStartsWith('/', $target);
        $this->assertStringNotContainsString('evil.example.com', $target);
    }

    public function testOtherQueryParametersOnThePageSurviveTheRoundTrip(): void
    {
        $target = FormSourcePath::withStatus('/pagina?bron=nieuwsbrief', 'success', 'form-abc');

        $this->assertStringContainsString('bron=nieuwsbrief', $target);
        $this->assertStringContainsString('form-status=success', $target);
    }

    // ------------------------------------------------------------- recipients

    public function testOnlyASafeAddressCanReachAMailHeader(): void
    {
        $this->assertSame('info@example.com', FormRecipient::validAddress(' info@example.com '));

        foreach ([
            '',
            null,
            'geen adres',
            "info@example.com\r\nBcc: evil@example.com",
            "info@example.com\nBcc: evil@example.com",
            str_repeat('a', 250) . '@example.com',
        ] as $invalid) {
            $this->assertNull(FormRecipient::validAddress($invalid), var_export($invalid, true) . ' must be refused');
        }
    }

    // ------------------------------------------------------------- spam guard

    public function testAFilledHoneypotIsDiscardedSilently(): void
    {
        $guard = new FormSpamGuard();
        $now = time();

        $this->assertSame(
            FormSpamGuard::VERDICT_SILENT_DISCARD,
            $guard->inspectRequest([
                FormSpamGuard::HONEYPOT_FIELD => 'ik ben een bot',
                FormSpamGuard::TIMESTAMP_FIELD => (string) ($now - 60),
            ], $now)
        );
    }

    public function testASubmissionThatArrivesTooFastIsDiscardedSilently(): void
    {
        $guard = new FormSpamGuard();
        $now = time();

        $this->assertSame(
            FormSpamGuard::VERDICT_SILENT_DISCARD,
            $guard->inspectRequest([FormSpamGuard::TIMESTAMP_FIELD => (string) $now], $now)
        );

        $this->assertSame(
            FormSpamGuard::VERDICT_SILENT_DISCARD,
            $guard->inspectRequest([], $now),
            'a POST without the timestamp at all did not come from a rendered page'
        );

        $this->assertSame(
            FormSpamGuard::VERDICT_SILENT_DISCARD,
            $guard->inspectRequest([FormSpamGuard::TIMESTAMP_FIELD => 'niet-een-getal'], $now)
        );
    }

    public function testAnOrdinarySubmissionPassesTheRequestChecks(): void
    {
        $guard = new FormSpamGuard();
        $now = time();

        $this->assertSame(
            FormSpamGuard::VERDICT_ACCEPT,
            $guard->inspectRequest([
                FormSpamGuard::HONEYPOT_FIELD => '',
                FormSpamGuard::TIMESTAMP_FIELD => (string) ($now - FormSpamGuard::MIN_SUBMIT_SECONDS - 1),
            ], $now)
        );
    }

    /**
     * The forms throttle has a salt of its own, so a visitor's enquiries and
     * their checkout attempts can never consume each other's budget.
     */
    public function testTheFormsRateLimitHasItsOwnBudget(): void
    {
        $this->assertNotSame(\App\Service\ContactRateLimiter::CONTACT_SALT, FormSpamGuard::RATE_LIMIT_SALT);
        $this->assertNotSame(\App\Service\ContactRateLimiter::PERSONALIZATION_UPLOAD_SALT, FormSpamGuard::RATE_LIMIT_SALT);
    }

    /**
     * @param string $typeKey a registered field type
     */
    private function field(string $typeKey, ?string $options = null): FormField
    {
        $field = FormField::fromRow([
            'id' => 1,
            'field_key' => 'veld',
            'field_type' => $typeKey,
            'label_nl' => 'Veld',
            'label_en' => 'Field',
            'is_required' => 0,
            'sort_order' => 0,
            'options' => $options,
        ]);

        $this->assertNotNull($field, 'the fixture field should have been built');

        return $field;
    }
}
