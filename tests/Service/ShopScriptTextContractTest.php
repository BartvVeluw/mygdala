<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Service\Personalization\PersonalizationScriptText;
use App\Service\Routing\RequestLanguage;
use App\Service\ShopScriptText;
use PHPUnit\Framework\TestCase;
use Tests\Support\SiteLanguageFixture;

/**
 * THE SHOP'S SCRIPTS SPEAK ONE LANGUAGE, and it is the server's
 * (Multilingual 2.0 phase 7, wave B).
 *
 * The cart, the product page, the checkout and the personalization panel
 * build some of their markup in the browser. Every sentence they put in it
 * comes from a closed catalogue the server resolves for the request
 * (App\Service\ShopScriptText, App\Service\Personalization\PersonalizationScriptText);
 * a script asks for a key and never holds a Dutch/English pair.
 *
 * And the cart a customer keeps in localStorage is language-neutral where it
 * matters: a line is its product, variant and personalization; its name is
 * display only, re-read by id in another language, and the pre-phase-7 line
 * format (a Dutch `name` beside an English `name_en`) is read in exactly one
 * place.
 *
 * Source-level on purpose: these are the promises a browser test would take
 * minutes to make, and a regression here is a line of JavaScript.
 */
final class ShopScriptTextContractTest extends TestCase
{
    protected function setUp(): void
    {
        // Nothing but PHP: the fallback reads this registry, not the database.
        SiteLanguageFixture::useBilingual('nl');
    }

    protected function tearDown(): void
    {
        RequestLanguage::reset();
        SiteLanguageFixture::reset();
    }

    /* ------------------------------------------------------------------ */
    /* Every word a script shows is in the catalogue, and nothing else is   */
    /* ------------------------------------------------------------------ */

    public function testEveryWordTheShopScriptsAskForIsInTheCatalogue(): void
    {
        $asked = array_unique(array_merge(
            self::keysAskedIn('assets/js/shop/cart.js'),
            self::keysAskedIn('assets/js/shop/shop.js')
        ));
        sort($asked);
        $catalogue = ShopScriptText::keys();
        sort($catalogue);

        self::assertNotEmpty($asked);
        self::assertSame($catalogue, $asked, 'every key a script asks for exists, and the catalogue holds no sentence nobody shows');
    }

    public function testEveryWordThePersonalizationPanelAsksForIsInItsCatalogue(): void
    {
        $asked = self::keysAskedIn('assets/js/personalization.js');
        sort($asked);
        $catalogue = PersonalizationScriptText::keys();
        sort($catalogue);

        self::assertSame($catalogue, $asked);
        self::assertStringContainsString(
            "'text' => \\App\\Service\\Personalization\\PersonalizationScriptText::forRequest()",
            self::read('partials/product-personalization.php'),
            'the panel receives its sentences in its configuration'
        );
    }

    /**
     * Every sentence exists in the languages it is written in, and a
     * language it is not written in reads the default language's — a third
     * language is one more key per entry, never a branch.
     */
    public function testEverySentenceHasItsWordsAndAThirdLanguageFallsBackToTheDefault(): void
    {
        foreach (['nl', 'en'] as $language) {
            RequestLanguage::set($language, true);
            foreach ([ShopScriptText::forRequest(), PersonalizationScriptText::forRequest()] as $catalogue) {
                foreach ($catalogue as $key => $sentence) {
                    self::assertNotSame('', trim($sentence), $key . ' in ' . $language);
                }
            }
        }

        RequestLanguage::set('nl', false);
        $dutch = ShopScriptText::forRequest();
        RequestLanguage::set('de', true);
        self::assertSame($dutch, ShopScriptText::forRequest(), 'no German sentence: the default language, not English and not empty');

        SiteLanguageFixture::useLanguages([
            SiteLanguageFixture::language('en', isDefault: true, sortOrder: 0),
            SiteLanguageFixture::language('nl', sortOrder: 1),
        ]);
        RequestLanguage::set('de', true);
        self::assertSame('Your cart is empty.', ShopScriptText::forRequest()['cart_empty'], 'on an English-default site the fallback is English');
    }

    /** The data block stays data, whatever a sentence says. */
    public function testTheDataBlockCannotCloseItsElementOrStartMarkup(): void
    {
        RequestLanguage::set('en', true);
        $json = ShopScriptText::json();

        foreach (['<', '>', '&', "'"] as $character) {
            self::assertStringNotContainsString($character, $json, 'escaped as \\u sequence: ' . $character);
        }
        self::assertSame(ShopScriptText::forRequest(), json_decode($json, true));

        $partial = self::read('partials/header-cart.php');
        self::assertStringContainsString('<script type="application/json" id="shop-text"><?= \\App\\Service\\ShopScriptText::json() ?></script>', $partial);
    }

    /* ------------------------------------------------------------------ */
    /* No script picks a language                                          */
    /* ------------------------------------------------------------------ */

    public function testNoShopScriptHoldsALanguagePairOrPicksALanguage(): void
    {
        foreach (['assets/js/shop/cart.js', 'assets/js/shop/shop.js', 'assets/js/personalization.js'] as $script) {
            $code = self::withoutComments(self::read($script));
            if ($script === 'assets/js/shop/cart.js') {
                $code = self::withoutFunction($code, 'upgradeLegacyLines');
            }

            foreach (['bilingualAttrs', 'currentLangText', 'currentLangHtml', 'data-nl', 'data-en', 'data-lang-html', 'name_en', 'label_en', 'description_en', '"en"', '"nl"', 'docEl.lang', 'function t('] as $v1) {
                self::assertStringNotContainsString($v1, $code, $script . ' still knows a language (' . $v1 . ')');
            }
        }
    }

    /* ------------------------------------------------------------------ */
    /* The cart in localStorage                                            */
    /* ------------------------------------------------------------------ */

    public function testANewCartLineStoresOneNameAndTheLanguageItIsIn(): void
    {
        $cart = self::withoutComments(self::read('assets/js/shop/cart.js'));

        self::assertMatchesRegularExpression('/var line = \{\s*id: product\.id,\s*name: product\.name \|\| "",\s*lang: PAGE_LANG,/', $cart);
        self::assertStringNotContainsString('name_en: product', $cart, 'a new line never needs a Dutch/English pair');
    }

    /**
     * A line is identified by what was bought, never by what it is called, so
     * a language switch changes nothing about the cart's contents.
     */
    public function testALinesIdentityIsLanguageNeutral(): void
    {
        $cart = self::withoutComments(self::read('assets/js/shop/cart.js'));

        self::assertMatchesRegularExpression(
            '/function cartLineKey\(item\) \{\s*if \(item\.line_id\) return "L:" \+ String\(item\.line_id\);\s*return String\(item\.id\) \+ "::" \+ \(item\.variant_id != null \? String\(item\.variant_id\) : ""\);\s*\}/',
            $cart
        );
        self::assertMatchesRegularExpression(
            '/if \(String\(candidate\.id\) === String\(product\.id\) && sameVariant &&\s*samePersonalization\(/',
            $cart,
            'two lines merge on product, variant and personalization — never on a name'
        );

        $checkout = self::withoutComments(self::read('assets/js/shop/shop.js'));
        self::assertStringContainsString('var line = { id: item.id, qty: item.qty, variant_id: item.variant_id || null };', $checkout, 'checkout sends identities, never a name');
    }

    /** The old format (name + name_en, label + label_en) is read in one place and written back new. */
    public function testTheOldCartFormatIsReadInOnePlaceAndWrittenBackInTheNewShape(): void
    {
        $cart = self::withoutComments(self::read('assets/js/shop/cart.js'));
        $upgrade = self::functionBody($cart, 'upgradeLegacyLines');

        self::assertStringContainsString('delete item.name_en;', $upgrade);
        self::assertStringContainsString('delete zone.label_en;', $upgrade);
        self::assertStringContainsString('item.lang = ', $upgrade, 'an upgraded line knows its language, so a page in another language re-reads it');
        self::assertMatchesRegularExpression('/function readCart\(\) \{.*?if \(upgradeLegacyLines\(items\)\) \{\s*try \{ localStorage\.setItem\(CART_KEY, JSON\.stringify\(items\)\); \} catch \(e\) \{\}/s', $cart);
    }

    /** A page in another language re-reads the names by id, once, through the products API. */
    public function testNamesInAnotherLanguageAreReReadByIdThroughTheApi(): void
    {
        $cart = self::withoutComments(self::read('assets/js/shop/cart.js'));
        $refresh = self::functionBody($cart, 'refreshCartNames');

        self::assertStringContainsString('fetch(apiUrl("/api/products.php?ids=" + ids.join(",")))', $refresh);
        self::assertStringContainsString('item.lang = PAGE_LANG;', $refresh, 'marked as read, so the next page costs no request');
        self::assertStringNotContainsString('price', $refresh, 'a refresh touches the words, never the price');
        self::assertStringContainsString('refreshCartNames();', $cart);
    }

    public function testEveryShopApiCallSaysWhichLanguageThePageIsIn(): void
    {
        $shop = self::withoutComments(self::read('assets/js/shop/shop.js'));

        preg_match_all('/fetch\(([^,)]*\/api\/[^)]*\))/', $shop, $calls);
        self::assertNotEmpty($calls[1]);
        foreach ($calls[1] as $call) {
            if (str_contains($call, 'checkout.php') || str_contains($call, 'address-lookup-nl.php')) {
                continue; // checkout sends `language` in its body; the address lookup has no words
            }
            self::assertStringStartsWith('S.apiUrl(', $call, $call);
        }
    }

    /* ------------------------------------------------------------------ */

    /** @return list<string> the catalogue keys a script asks for with text("…") */
    private static function keysAskedIn(string $script): array
    {
        $code = self::withoutComments(self::read($script));
        preg_match_all('/\btext\("([a-z_]+)"/', $code, $matches);
        // text(condition ? "one" : "other", …) asks for both.
        preg_match_all('/\btext\([^?\n]*\?\s*"([a-z_]+)"\s*:\s*"([a-z_]+)"/', $code, $ternaries);

        return array_values(array_unique(array_merge($matches[1], $ternaries[1], $ternaries[2])));
    }

    private static function read(string $relative): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/' . $relative);
    }

    private static function withoutComments(string $script): string
    {
        return (string) preg_replace(['~/\*.*?\*/~s', '~^\s*//.*$~m'], '', $script);
    }

    private static function functionBody(string $script, string $name): string
    {
        $start = strpos($script, 'function ' . $name . '(');
        self::assertNotFalse($start, $name . ' exists');

        $open = strpos($script, '{', $start);
        $depth = 0;
        for ($i = $open, $length = strlen($script); $i < $length; $i++) {
            if ($script[$i] === '{') {
                $depth++;
            } elseif ($script[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($script, $start, $i - $start + 1);
                }
            }
        }

        self::fail($name . ' has no end');
    }

    private static function withoutFunction(string $script, string $name): string
    {
        return str_replace(self::functionBody($script, $name), '', $script);
    }
}
