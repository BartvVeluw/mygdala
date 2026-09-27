<?php

declare(strict_types=1);

namespace Tests\Service\Payment;

use App\Service\Payment\ShopPaymentMethods;
use App\Service\Routing\RequestLanguage;
use App\Service\SiteSettings;
use PHPUnit\Framework\TestCase;

/**
 * Which payment methods the checkout offers (App\Service\Payment\ShopPaymentMethods),
 * without a database or Mollie:
 *
 *  - an installation that never chose offers exactly what the checkout
 *    always offered, iDEAL and credit card, with the words it always had;
 *  - a saved choice is offered in its order, named as Mollie named it in
 *    the language of the request;
 *  - a request can only pick an offered method; the old "kaart" still means
 *    credit card, and only while credit card is offered.
 */
final class ShopPaymentMethodsTest extends TestCase
{
    protected function tearDown(): void
    {
        SiteSettings::overrideForTests(null);
        RequestLanguage::reset();
    }

    /** @param array<string, string> $settings */
    private function settings(array $settings): void
    {
        SiteSettings::overrideForTests($settings);
    }

    public function testWithoutAChoiceTheCheckoutOffersWhatItAlwaysOffered(): void
    {
        $this->settings([]);
        RequestLanguage::set('nl', false);

        $this->assertFalse(ShopPaymentMethods::isChosen());
        $this->assertSame(['ideal', 'creditcard'], ShopPaymentMethods::enabled());
        $this->assertSame([
            ['id' => 'ideal', 'name' => 'iDEAL', 'note' => 'Direct betalen via je eigen bank'],
            ['id' => 'creditcard', 'name' => 'Creditcard', 'note' => 'Visa, Mastercard'],
        ], ShopPaymentMethods::forCheckout());

        RequestLanguage::set('en', true);
        $this->assertSame('Credit card', ShopPaymentMethods::forCheckout()[1]['name']);
    }

    public function testASavedChoiceIsOfferedInItsOrderWithMolliesNames(): void
    {
        $this->settings([
            ShopPaymentMethods::SETTING_KEY => 'bancontact,ideal',
            ShopPaymentMethods::NAMES_SETTING_KEY => json_encode([
                'bancontact' => ['nl' => 'Bancontact', 'en' => 'Bancontact'],
                'ideal' => ['nl' => 'iDEAL | Wero', 'en' => 'iDEAL | Wero'],
            ]),
        ]);
        RequestLanguage::set('nl', false);

        $this->assertTrue(ShopPaymentMethods::isChosen());
        $this->assertSame(['bancontact', 'ideal'], ShopPaymentMethods::enabled());
        $this->assertSame(
            [['id' => 'bancontact', 'name' => 'Bancontact', 'note' => ''], ['id' => 'ideal', 'name' => 'iDEAL | Wero', 'note' => 'Direct betalen via je eigen bank']],
            ShopPaymentMethods::forCheckout()
        );
    }

    public function testASingleMethodIsASingleOffer(): void
    {
        $this->settings([ShopPaymentMethods::SETTING_KEY => 'paypal', ShopPaymentMethods::NAMES_SETTING_KEY => '{"paypal":{"nl":"PayPal"}}']);
        RequestLanguage::set('en', true);

        $this->assertSame([['id' => 'paypal', 'name' => 'PayPal', 'note' => '']], ShopPaymentMethods::forCheckout(), 'a name in another language beats no name');
    }

    public function testARequestCanOnlyPickAnOfferedMethod(): void
    {
        $this->settings([]);
        $this->assertSame('ideal', ShopPaymentMethods::resolveChoice('ideal'));
        $this->assertSame('creditcard', ShopPaymentMethods::resolveChoice('creditcard'));
        $this->assertSame('creditcard', ShopPaymentMethods::resolveChoice('kaart'), 'the old radio value');

        foreach (['klarna', 'bancontact', '', 'IDEAL', ' ideal', null, ['ideal'], 7] as $refused) {
            $this->assertNull(ShopPaymentMethods::resolveChoice($refused), var_export($refused, true));
        }

        $this->settings([ShopPaymentMethods::SETTING_KEY => 'ideal']);
        $this->assertNull(ShopPaymentMethods::resolveChoice('kaart'), 'credit card switched off: its old name is off too');
        $this->assertNull(ShopPaymentMethods::resolveChoice('creditcard'));
    }

    public function testAStoredValueOutsideTheShapeNeverReachesTheCheckout(): void
    {
        $this->settings([
            ShopPaymentMethods::SETTING_KEY => 'ideal,<script>,Bad Id,ideal',
            ShopPaymentMethods::NAMES_SETTING_KEY => '{"<script>":{"nl":"x"},"ideal":{"nl":""}}',
        ]);

        $this->assertSame(['ideal'], ShopPaymentMethods::enabled());
        $this->assertSame([], ShopPaymentMethods::storedNames(), 'an empty name and a bad id are dropped');

        $this->settings([ShopPaymentMethods::SETTING_KEY => ',,']);
        $this->assertSame(ShopPaymentMethods::DEFAULT, ShopPaymentMethods::enabled(), 'nothing usable is the default, never nothing');
    }
}
