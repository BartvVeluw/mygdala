<?php

declare(strict_types=1);

namespace Tests\Service\Personalization;

use App\Service\Personalization\PersonalizationRules;
use PHPUnit\Framework\TestCase;

/**
 * The rules every other part of product personalization defers to: what a
 * valid engraving area is, what a valid customer transform is, and what a
 * valid upload token looks like.
 *
 * Pure unit tests — no database, no HTTP — because these are exactly the
 * decisions that must hold identically in the CMS, on the product page, at
 * checkout and in the CMS order detail.
 */
final class PersonalizationRulesTest extends TestCase
{
    /* ------------------------------------------------------------------ */
    /* Transforms — clamped, never rejected                                */
    /* ------------------------------------------------------------------ */

    public function testTheDefaultTransformIsCentredAndUnscaled(): void
    {
        $this->assertSame(
            ['x' => 0.5, 'y' => 0.5, 'scale' => 1.0, 'rotation' => 0.0],
            PersonalizationRules::defaultTransform()
        );
    }

    public function testAPositionOutsideTheZoneIsClampedBackInside(): void
    {
        $transform = PersonalizationRules::normalizeTransform(['x' => 7.5, 'y' => -3.2]);

        $this->assertSame(1.0, $transform['x']);
        $this->assertSame(0.0, $transform['y']);
    }

    public function testAnAbsurdScaleIsClampedToTheAllowedRange(): void
    {
        $this->assertSame(
            PersonalizationRules::MAX_SCALE,
            PersonalizationRules::normalizeTransform(['scale' => 9999])['scale']
        );
        $this->assertSame(
            PersonalizationRules::MIN_SCALE,
            PersonalizationRules::normalizeTransform(['scale' => 0.0001])['scale']
        );
    }

    public function testRotationIsClampedToASingleTurn(): void
    {
        $this->assertSame(180.0, PersonalizationRules::normalizeTransform(['rotation' => 5000])['rotation']);
        $this->assertSame(-180.0, PersonalizationRules::normalizeTransform(['rotation' => -5000])['rotation']);
    }

    /**
     * A hand-crafted payload can send anything at all; none of it may produce
     * a NaN, a string or a null in stored order data.
     */
    public function testNonNumericTransformValuesFallBackToTheNeutralValue(): void
    {
        $transform = PersonalizationRules::normalizeTransform([
            'x' => 'drop table',
            'y' => null,
            'scale' => ['nested'],
            'rotation' => true,
        ]);

        $this->assertSame(PersonalizationRules::defaultTransform(), $transform);
    }

    public function testAnEntirelyMissingTransformIsTheNeutralOne(): void
    {
        $this->assertSame(PersonalizationRules::defaultTransform(), PersonalizationRules::normalizeTransform(null));
        $this->assertSame(PersonalizationRules::defaultTransform(), PersonalizationRules::normalizeTransform('nonsense'));
    }

    public function testATransformSetAlwaysHasBothLayers(): void
    {
        $set = PersonalizationRules::normalizeTransformSet(['text' => ['x' => 0.25]]);

        $this->assertArrayHasKey('text', $set);
        $this->assertArrayHasKey('image', $set);
        $this->assertSame(0.25, $set['text']['x']);
        $this->assertSame(PersonalizationRules::defaultTransform(), $set['image']);
    }

    /* ------------------------------------------------------------------ */
    /* Engraving areas — rejected, never silently corrected                */
    /* ------------------------------------------------------------------ */

    public function testAValidAreaIsAcceptedUnchanged(): void
    {
        $errors = [];
        $area = PersonalizationRules::validateArea(
            ['x' => '28', 'y' => '32', 'width' => '44', 'height' => '25'],
            $errors
        );

        $this->assertSame([], $errors);
        $this->assertSame(['x' => 28.0, 'y' => 32.0, 'width' => 44.0, 'height' => 25.0], $area);
    }

    public function testACommaDecimalIsAcceptedBecauseADutchKeyboardProducesOne(): void
    {
        $errors = [];
        $area = PersonalizationRules::validateArea(
            ['x' => '28,5', 'y' => '32', 'width' => '44', 'height' => '25'],
            $errors
        );

        $this->assertSame([], $errors);
        $this->assertSame(28.5, $area['x']);
    }

    public function testAnAreaThatRunsOffTheImageIsRejected(): void
    {
        $errors = [];
        PersonalizationRules::validateArea(
            ['x' => '80', 'y' => '10', 'width' => '40', 'height' => '10'],
            $errors
        );

        $this->assertNotSame([], $errors);
        $this->assertStringContainsString('buiten de afbeelding', implode(' ', $errors));
    }

    public function testANegativeOriginIsRejected(): void
    {
        $errors = [];
        PersonalizationRules::validateArea(
            ['x' => '-5', 'y' => '10', 'width' => '40', 'height' => '10'],
            $errors
        );

        $this->assertNotSame([], $errors);
    }

    public function testAnAreaSmallerThanTheMinimumIsRejected(): void
    {
        $errors = [];
        PersonalizationRules::validateArea(
            ['x' => '10', 'y' => '10', 'width' => '0.5', 'height' => '0.5'],
            $errors
        );

        $this->assertNotSame([], $errors);
        $this->assertStringContainsString('te klein', implode(' ', $errors));
    }

    public function testAnIncompleteAreaIsRejected(): void
    {
        $errors = [];
        PersonalizationRules::validateArea(['x' => '10', 'y' => '10', 'width' => ''], $errors);

        $this->assertNotSame([], $errors);
    }

    public function testANonNumericAreaIsRejected(): void
    {
        $errors = [];
        PersonalizationRules::validateArea(
            ['x' => 'left', 'y' => '10', 'width' => '40', 'height' => '10'],
            $errors
        );

        $this->assertNotSame([], $errors);
    }

    /**
     * clampArea() is the read-side counterpart: it never rejects, because a
     * stored row must always render something sensible.
     */
    public function testClampAreaAlwaysProducesARectangleInsideTheImage(): void
    {
        $area = PersonalizationRules::clampArea(['x' => 95, 'y' => 95, 'width' => 60, 'height' => 60]);

        $this->assertLessThanOrEqual(100.0, $area['x'] + $area['width']);
        $this->assertLessThanOrEqual(100.0, $area['y'] + $area['height']);
        $this->assertGreaterThanOrEqual(0.0, $area['x']);
        $this->assertGreaterThanOrEqual(0.0, $area['y']);
    }

    /* ------------------------------------------------------------------ */
    /* Text length + tokens                                                */
    /* ------------------------------------------------------------------ */

    public function testTheConfiguredMaximumTextLengthIsClampedToTheAllowedRange(): void
    {
        $this->assertSame(PersonalizationRules::MAX_TEXT_LENGTH_SETTING, PersonalizationRules::clampMaxTextLength(100000));
        $this->assertSame(PersonalizationRules::MIN_TEXT_LENGTH_SETTING, PersonalizationRules::clampMaxTextLength(0));
        $this->assertSame(PersonalizationRules::MIN_TEXT_LENGTH_SETTING, PersonalizationRules::clampMaxTextLength(-40));
        $this->assertSame(25, PersonalizationRules::clampMaxTextLength('25'));
        $this->assertSame(
            PersonalizationRules::DEFAULT_TEXT_LENGTH_SETTING,
            PersonalizationRules::clampMaxTextLength('lang')
        );
    }

    /**
     * A token becomes a filename on the server, so anything that is not
     * exactly 32 lowercase hex characters must never get that far.
     */
    public function testOnlyA32CharacterHexStringIsAValidUploadToken(): void
    {
        $this->assertTrue(PersonalizationRules::isValidUploadToken(str_repeat('a', 32)));
        $this->assertTrue(PersonalizationRules::isValidUploadToken(PersonalizationRules::newUploadToken()));

        foreach ([
            '../../etc/passwd',
            str_repeat('a', 31),
            str_repeat('a', 33),
            str_repeat('A', 32),
            'a' . str_repeat('b', 30) . '/',
            'abc.png',
            '',
            null,
            12345,
            ['a'],
        ] as $invalid) {
            $this->assertFalse(
                PersonalizationRules::isValidUploadToken($invalid),
                'expected to reject: ' . var_export($invalid, true)
            );
        }
    }

    public function testEveryGeneratedTokenIsUnique(): void
    {
        $tokens = [];
        for ($i = 0; $i < 50; $i++) {
            $tokens[] = PersonalizationRules::newUploadToken();
        }

        $this->assertCount(50, array_unique($tokens));
    }

    /* ------------------------------------------------------------------ */
    /* Zone and view keys                                                  */
    /* ------------------------------------------------------------------ */

    /**
     * A key ends up in an order row and identifies that zone for the lifetime
     * of every order that used it, so it is a strict identifier — never a
     * label, never anything a customer typed.
     */
    public function testOnlyASafeIdentifierIsAValidZoneOrViewKey(): void
    {
        foreach (['front', 'back', 'name_2', 'logo-a', 'a', '0', str_repeat('a', 32)] as $valid) {
            $this->assertTrue(PersonalizationRules::isValidKey($valid), 'expected valid: ' . $valid);
        }

        foreach ([
            '',
            'Front',
            'voor kant',
            '_leading',
            '-leading',
            'naam!',
            '../../etc',
            'zone/1',
            str_repeat('a', 33),
            null,
            42,
            ['front'],
        ] as $invalid) {
            $this->assertFalse(
                PersonalizationRules::isValidKey($invalid),
                'expected invalid: ' . var_export($invalid, true)
            );
        }
    }

    public function testALabelCanBeTurnedIntoAUsableKey(): void
    {
        $this->assertSame('voorkant', PersonalizationRules::toKey('Voorkant', 'view'));
        $this->assertSame('korte_boodschap', PersonalizationRules::toKey('Korte boodschap', 'zone'));
        $this->assertSame('naam_datum', PersonalizationRules::toKey('Naam & datum', 'zone'));
        // Nothing usable left over falls back rather than producing an
        // invalid key.
        $this->assertSame('zone', PersonalizationRules::toKey('!!!', 'zone'));
        $this->assertSame('zone', PersonalizationRules::toKey('', 'zone'));
        $this->assertSame('zone', PersonalizationRules::toKey(null, 'zone'));

        $this->assertTrue(PersonalizationRules::isValidKey(PersonalizationRules::toKey('Héél lange náám met accenten', 'zone')));
    }

    /* ------------------------------------------------------------------ */
    /* Zone content mode                                                   */
    /* ------------------------------------------------------------------ */

    public function testTheContentModeFollowsWhatTheZoneAllows(): void
    {
        $this->assertSame(PersonalizationRules::MODE_BOTH, PersonalizationRules::contentMode(true, true));
        $this->assertSame(PersonalizationRules::MODE_TEXT, PersonalizationRules::contentMode(true, false));
        $this->assertSame(PersonalizationRules::MODE_IMAGE, PersonalizationRules::contentMode(false, true));
    }

    /* ------------------------------------------------------------------ */
    /* Rotation                                                            */
    /* ------------------------------------------------------------------ */

    /**
     * A zone whose administrator disabled rotation must come out unrotated
     * whatever the request claims — hiding the slider is not the enforcement.
     */
    public function testRotationIsForcedToZeroWhenTheZoneForbidsIt(): void
    {
        $transform = PersonalizationRules::normalizeTransform(['rotation' => 90], false);
        $this->assertSame(0.0, $transform['rotation']);

        $set = PersonalizationRules::normalizeTransformSet(
            ['text' => ['rotation' => 90], 'image' => ['rotation' => -90]],
            false
        );
        $this->assertSame(0.0, $set['text']['rotation']);
        $this->assertSame(0.0, $set['image']['rotation']);

        // ...and is kept when the zone does allow it.
        $allowed = PersonalizationRules::normalizeTransformSet(['text' => ['rotation' => 90]], true);
        $this->assertSame(90.0, $allowed['text']['rotation']);
    }

    /* ------------------------------------------------------------------ */
    /* Surcharges                                                          */
    /* ------------------------------------------------------------------ */

    public function testAValidSurchargeIsAcceptedAsWholeCents(): void
    {
        $errors = [];

        $this->assertSame(750, PersonalizationRules::validateSurcharge('7.50', $errors));
        $this->assertSame(750, PersonalizationRules::validateSurcharge('7,50', $errors));
        $this->assertSame(0, PersonalizationRules::validateSurcharge('', $errors));
        $this->assertSame(0, PersonalizationRules::validateSurcharge(null, $errors));
        $this->assertSame(0, PersonalizationRules::validateSurcharge('0.00', $errors));
        $this->assertSame(29, PersonalizationRules::validateSurcharge('0.29', $errors));

        $this->assertSame([], $errors);
    }

    /**
     * An administrator typing a price deserves to be told when it is wrong,
     * not silently corrected into an amount they never chose.
     */
    public function testAnInvalidSurchargeIsRejectedRatherThanClamped(): void
    {
        foreach (['gratis', '7.50 euro', 'abc'] as $invalid) {
            $errors = [];
            PersonalizationRules::validateSurcharge($invalid, $errors);
            $this->assertNotSame([], $errors, 'expected a rejection for: ' . $invalid);
        }

        $errors = [];
        PersonalizationRules::validateSurcharge('-5.00', $errors);
        $this->assertNotSame([], $errors);
        $this->assertStringContainsString('negatief', implode(' ', $errors));

        $errors = [];
        PersonalizationRules::validateSurcharge('999999.00', $errors);
        $this->assertNotSame([], $errors);
        $this->assertStringContainsString('te hoog', implode(' ', $errors));
    }

    /* ------------------------------------------------------------------ */
    /* Version 1 upload policy                                             */
    /* ------------------------------------------------------------------ */

    /**
     * SVG is intentionally unsupported in Version 1: it is an XML document
     * that can carry scripts and external references, and accepting one
     * safely needs a sanitisation architecture this project does not have.
     * This test exists so adding it can never be an accident.
     */
    public function testOnlyPngAndJpegAreAcceptedUploadTypes(): void
    {
        $this->assertSame(
            [IMAGETYPE_JPEG, IMAGETYPE_PNG],
            array_keys(PersonalizationRules::ALLOWED_UPLOAD_TYPES)
        );

        foreach ([IMAGETYPE_SWF, IMAGETYPE_GIF, IMAGETYPE_WEBP, IMAGETYPE_BMP, IMAGETYPE_ICO] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, PersonalizationRules::ALLOWED_UPLOAD_TYPES);
        }
    }
}
