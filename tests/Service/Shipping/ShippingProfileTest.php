<?php

declare(strict_types=1);

namespace Tests\Service\Shipping;

use App\Service\Shipping\ShippingProfile;
use PHPUnit\Framework\TestCase;

final class ShippingProfileTest extends TestCase
{
    public function testIsValidAcceptsOnlyTheThreeKnownProfiles(): void
    {
        $this->assertTrue(ShippingProfile::isValid('letter'));
        $this->assertTrue(ShippingProfile::isValid('letterbox'));
        $this->assertTrue(ShippingProfile::isValid('parcel'));
        $this->assertFalse(ShippingProfile::isValid('envelope'));
        $this->assertFalse(ShippingProfile::isValid(''));
    }

    public function testLabelsAreDutch(): void
    {
        $this->assertSame('Briefpost', ShippingProfile::label('letter'));
        $this->assertSame('Brievenbuspakket', ShippingProfile::label('letterbox'));
        $this->assertSame('Pakket', ShippingProfile::label('parcel'));
    }

    public function testLabelFallsBackToRawValueForUnknownProfile(): void
    {
        $this->assertSame('mystery', ShippingProfile::label('mystery'));
    }

    public function testHighestOfPicksParcelOverAnythingElse(): void
    {
        $this->assertSame('parcel', ShippingProfile::highestOf(['letter', 'parcel', 'letterbox']));
    }

    public function testHighestOfPicksLetterboxOverLetter(): void
    {
        $this->assertSame('letterbox', ShippingProfile::highestOf(['letter', 'letterbox']));
    }

    public function testHighestOfReturnsTheOnlyProfilePresent(): void
    {
        $this->assertSame('letter', ShippingProfile::highestOf(['letter', 'letter']));
    }
}
