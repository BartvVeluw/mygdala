<?php

declare(strict_types=1);

namespace Tests\Service\Personalization;

use App\Service\Personalization\Money;
use PHPUnit\Framework\TestCase;

/**
 * Exact money arithmetic. These are the cases that make float money wrong:
 * a value whose decimal cannot be represented in binary, and a total built by
 * adding many of them together.
 */
final class MoneyTest extends TestCase
{
    /**
     * The classic: `(int) (0.29 * 100)` is 28 on a binary float. Parsing the
     * digits instead of the number is why this is 29.
     */
    public function testADecimalStringBecomesTheCentsItLooksLike(): void
    {
        $this->assertSame(29, Money::toCents('0.29'));
        $this->assertSame(70, Money::toCents('0.70'));
        $this->assertSame(750, Money::toCents('7.50'));
        $this->assertSame(2500, Money::toCents('25.00'));
        $this->assertSame(3495, Money::toCents('34.95'));
        $this->assertSame(100000, Money::toCents('1000'));
    }

    public function testADutchCommaIsAcceptedBecauseAnAdministratorTypesOne(): void
    {
        $this->assertSame(750, Money::toCents('7,50'));
        $this->assertSame(750, Money::toCents(' 7,50 '));
        $this->assertSame(5, Money::toCents('0,05'));
    }

    public function testAShortOrLongFractionIsHandledExactly(): void
    {
        $this->assertSame(700, Money::toCents('7.'));
        $this->assertSame(750, Money::toCents('7.5'));
        $this->assertSame(750, Money::toCents('7.499'), 'the third digit rounds, it does not truncate');
        $this->assertSame(749, Money::toCents('7.494'));
        $this->assertSame(1, Money::toCents('.005'));
    }

    public function testIntegersAndFloatsAreAccepted(): void
    {
        $this->assertSame(700, Money::toCents(7));
        $this->assertSame(750, Money::toCents(7.5));
        $this->assertSame(29, Money::toCents(0.29));
    }

    /**
     * A surcharge that cannot be read must never become an arbitrary amount a
     * customer is charged.
     */
    public function testAnythingUnreadableIsZero(): void
    {
        foreach (['', 'gratis', null, [], true, '7,50 euro', '--3'] as $value) {
            $this->assertSame(0, Money::toCents($value), 'unreadable: ' . var_export($value, true));
        }
    }

    public function testFormattingIsAlwaysTwoDecimals(): void
    {
        $this->assertSame('0.00', Money::format(0));
        $this->assertSame('0.05', Money::format(5));
        $this->assertSame('7.50', Money::format(750));
        $this->assertSame('25.00', Money::format(2500));
        $this->assertSame('1000.00', Money::format(100000));
        $this->assertSame('-7.50', Money::format(-750));
    }

    public function testTheDutchDisplayFormUsesAComma(): void
    {
        $this->assertSame('7,50', Money::formatDutch(750));
        $this->assertSame('0,05', Money::formatDutch(5));
    }

    public function testEveryAmountSurvivesARoundTrip(): void
    {
        foreach (['0.00', '0.01', '0.29', '7.50', '19.99', '34.95', '999.99'] as $amount) {
            $this->assertSame($amount, Money::format(Money::toCents($amount)));
        }
    }

    /**
     * The reason all of this exists: adding amounts up repeatedly, which is
     * exactly what a multi-zone surcharge on a multi-unit line does.
     */
    public function testRepeatedAdditionStaysExact(): void
    {
        $cents = 0;
        for ($i = 0; $i < 100; $i++) {
            $cents += Money::toCents('0.10');
        }
        $this->assertSame('10.00', Money::format($cents));

        // 3 x (25.00 product + 5.00 logo + 7.50 message)
        $unit = Money::toCents('25.00') + Money::toCents('5.00') + Money::toCents('7.50');
        $this->assertSame('112.50', Money::format($unit * 3));

        // The float version of the same sum is what this avoids.
        $this->assertSame('0.30', Money::format(Money::toCents('0.10') + Money::toCents('0.20')));
    }

    public function testAbsurdAmountsAreClampedRatherThanOverflowing(): void
    {
        $this->assertSame(Money::MAX_CENTS, Money::toCents('99999999999'));
        $this->assertSame(-Money::MAX_CENTS, Money::toCents('-99999999999'));
    }
}
