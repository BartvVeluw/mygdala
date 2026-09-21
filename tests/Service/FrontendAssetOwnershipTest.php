<?php

namespace Tests\Service;

use App\Module\ModuleRegistry;
use App\Service\Blocks\BlockDefinitions;
use App\Service\PageAssets;
use PHPUnit\Framework\TestCase;

/**
 * Step 4 gave the frontend owners. This test is what keeps them.
 *
 * Before it, assets/js/main.js and assets/css/style.css held the site shell,
 * every content block and the whole Shop, and every page downloaded all of
 * it. The split is only worth anything if it cannot quietly grow back
 * together, so the rules below are asserted rather than written down:
 *
 *   - a block declares its own files, and only files that really exist;
 *   - Core carries no block-specific and no Shop-specific behaviour;
 *   - the site-wide asset list holds no Shop file except the documented
 *     mini-cart seam;
 *   - a page that is not Shop does not ask for the Shop's route assets;
 *   - no template hand-writes an asset tag any more.
 *
 * Source-reading only: no database, no web server (tier `contract`).
 */
final class FrontendAssetOwnershipTest extends TestCase
{
    /** Every public page template. */
    private const PUBLIC_TEMPLATES = [
        'index.php', 'shop.php', 'diensten.php', 'portfolio.php', 'over-mij.php',
        'contact.php', 'pagina.php', 'collectie.php', 'product.php', 'personaliseren.php',
        'cart.php', 'checkout.php', 'bestelling-status.php', 'portfolio-detail.php',
        'cookiebeleid.php', 'herroeping.php',
        // The Blog's two HTML routes. blog-feed.php is not here: it renders
        // XML, not a page with a <head> and assets.
        'blog.php', 'blog-post.php',
    ];

    /** The templates that legitimately render Shop catalogue/checkout markup. */
    private const SHOP_TEMPLATES = [
        'collectie.php', 'product.php', 'personaliseren.php',
        'cart.php', 'checkout.php', 'bestelling-status.php',
    ];

    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    private static function sourceOf(string $relativePath): string
    {
        $path = self::root() . '/' . $relativePath;
        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }

    protected function setUp(): void
    {
        PageAssets::reset();
    }

    protected function tearDown(): void
    {
        PageAssets::reset();
    }

    /* ------------------------------------------------------------------ */
    /* A block declares its own assets                                     */
    /* ------------------------------------------------------------------ */

    /**
     * A block may only name a local file under assets/ that exists. This is
     * the same closed-list reasoning the registry applies to type keys: an
     * asset path is trusted, registered metadata, never something assembled
     * from a request.
     */
    public function testEveryBlockDeclaresOnlyRealLocalAssets(): void
    {
        $declared = 0;

        foreach (BlockDefinitions::all() as $type => $definition) {
            foreach ([...$definition->styles(), ...$definition->scripts()] as $path) {
                $declared++;
                $this->assertTrue(
                    PageAssets::isLoadable($path),
                    "block \"{$type}\" declares \"{$path}\", which is not an existing file under assets/"
                );
            }

            foreach ($definition->vendorScripts() as $vendor) {
                $this->assertContains(
                    $vendor,
                    PageAssets::vendorScriptNames(),
                    "block \"{$type}\" asks for third-party library \"{$vendor}\", which is not in PageAssets' closed list"
                );
            }
        }

        $this->assertGreaterThan(0, $declared, 'no block declares any asset at all — the mechanism is not in use');
    }

    /**
     * Every file under assets/js/blocks/ and assets/css/blocks/ belongs to a
     * registered block, and normally to exactly one: a file nobody declares
     * is dead weight, and a file two blocks declare usually means the
     * boundary has already blurred.
     *
     * SHARED_BLOCK_ASSETS is the deliberate exception, and it is a short
     * list on purpose. Each pair renders the very same markup through the
     * very same renderer: `form` and `contact_form` share partials/form.php
     * (FORMS.md), and the Portfolio's `project_cards` draws the gallery's
     * partials/section-item-gallery.php (docs/content-blocks/DECISIONS.md).
     * Giving them a stylesheet and a script each would mean two copies of
     * one file, which is a worse answer to the same question. Anything not
     * named here still has to have exactly one owner.
     */
    /**
     * The block assets that more than one block may declare, and exactly
     * which blocks those are (sorted). A block of a switched-off module is
     * not registered and declares nothing, so only the sharers registered
     * right now are expected.
     *
     * @var array<string, list<string>>
     */
    private const SHARED_BLOCK_ASSETS = [
        'assets/css/blocks/form.css' => ['contact_form', 'form'],
        'assets/js/blocks/form.js' => ['contact_form', 'form'],
        'assets/css/blocks/item-gallery.css' => ['item_gallery', 'project_cards'],
        'assets/js/blocks/item-gallery.js' => ['item_gallery', 'project_cards'],
    ];

    public function testEveryBlockAssetFileIsOwnedByExactlyOneBlock(): void
    {
        $owners = [];

        foreach (BlockDefinitions::all() as $type => $definition) {
            foreach ([...$definition->styles(), ...$definition->scripts()] as $path) {
                $owners[$path][] = $type;
            }
        }

        $files = [
            ...glob(self::root() . '/assets/js/blocks/*.js'),
            ...glob(self::root() . '/assets/css/blocks/*.css'),
        ];

        $this->assertNotEmpty($files);

        foreach ($files as $absolute) {
            $relative = str_replace('\\', '/', substr($absolute, strlen(self::root()) + 1));
            $this->assertArrayHasKey($relative, $owners, "{$relative} is declared by no block definition");
            $expected = self::SHARED_BLOCK_ASSETS[$relative] ?? null;

            if ($expected === null) {
                $this->assertCount(1, $owners[$relative], "{$relative} is declared by more than one block");
                continue;
            }

            $expected = array_values(array_filter(
                $expected,
                static fn (string $type): bool => BlockDefinitions::has($type)
            ));

            sort($owners[$relative]);
            $this->assertSame(
                $expected,
                $owners[$relative],
                "{$relative} is shared, but not by exactly the blocks that are allowed to share it"
            );
        }
    }

    /**
     * The one third-party library on the frontend is wanted by the homepage
     * hero and by nothing else. Before step 4 its <script> tag was copied
     * into twelve templates, eleven of which never render a hero.
     */
    public function testGsapIsAskedForByTheHeroAloneAndByNoTemplate(): void
    {
        $askers = [];

        foreach (BlockDefinitions::all() as $type => $definition) {
            if (in_array('gsap', $definition->vendorScripts(), true)) {
                $askers[] = $type;
            }
        }

        $this->assertSame(['homepage_hero'], $askers);

        foreach (self::PUBLIC_TEMPLATES as $template) {
            $this->assertStringNotContainsString(
                'gsap',
                self::sourceOf($template),
                "{$template} still hardcodes a GSAP tag; the homepage hero block asks for it now"
            );
        }
    }

    /* ------------------------------------------------------------------ */
    /* Core owns nothing that belongs to a block or to the Shop            */
    /* ------------------------------------------------------------------ */

    /**
     * The exact regression this step undoes: a block-specific or Shop-specific
     * initialiser sitting in the file every page loads.
     */
    public function testCoreJavaScriptCarriesNoBlockOrShopBehaviour(): void
    {
        $core = self::sourceOf('assets/js/core.js');

        $forbidden = [
            // content blocks
            'initMarquee', 'initHeroMotion', 'spawnSparks', 'initFilters', 'initLightbox',
            'initOrbitCarousels', 'setupOrbitCarousel', 'initForm',
            // routes that are not the site shell
            'initProjectLightbox',
            // shop
            'initCartDropdown', 'initCartControls', 'initCartFeedback', 'renderCartUI',
            'renderCartHeader', 'renderCartPage', 'cartAdd', 'readCart', 'writeCart',
            'initQtySteppers', 'initProductTotal', 'initShopProducts', 'initProductDetail',
            'initCheckoutPage', 'initOrderStatusPage', 'VVLCart', 'vvl-cart',
        ];

        foreach ($forbidden as $needle) {
            $this->assertStringNotContainsString(
                $needle,
                $core,
                "assets/js/core.js mentions \"{$needle}\"; that behaviour belongs to a block, a route or the Shop"
            );
        }

        // ...and it does still own the site shell.
        foreach (['function initHeader(', 'function initNavDropdowns(', 'function initReveal('] as $needle) {
            $this->assertStringContainsString($needle, $core);
        }

        // ...but no language of its own: the server renders every page in
        // the language of its URL (Multilingual 2.0 phase 7).
        foreach (['function initLang(', 'function applyLang(', 'vvl-lang', 'data-primary-lang'] as $needle) {
            $this->assertStringNotContainsString($needle, $core, 'the client-side language swap is gone');
        }
    }

    /**
     * The same rule for CSS. A selector family that belongs to one block or to
     * the Shop must not be in the stylesheet every page downloads.
     */
    public function testCoreCssCarriesNoBlockOrShopStyling(): void
    {
        $core = self::sourceOf('assets/css/core.css');
        $rules = preg_replace('#/\*.*?\*/#s', '', $core);

        $forbidden = [
            '.orbit-carousel', '.orbit-card', '.marquee__track', '.gallery-item', '.filter-bar',
            '.cart-dropdown', '.cart-trigger', '.cart-row', '.cart-toast', '.product-card',
            '.product-detail', '.collection-tile', '.checkout-layout', '.checkout-section',
            '.personalizer', '.qty-stepper',
        ];

        foreach ($forbidden as $selector) {
            $this->assertStringNotContainsString(
                $selector,
                (string) $rules,
                "assets/css/core.css styles \"{$selector}\"; that belongs to a block or to the Shop"
            );
        }

        // ...and it does still own the shared foundations.
        foreach ([':root{', '.btn{', '.site-header', '.site-footer', '.lightbox{', '.form-field'] as $needle) {
            $this->assertStringContainsString($needle, $core);
        }
    }

    /* ------------------------------------------------------------------ */
    /* The Shop is not part of the global bundle                           */
    /* ------------------------------------------------------------------ */

    /**
     * What the site shell loads on every page. Core's own half names Core and
     * nothing else — no Shop file appears in src/Service/PageAssets.php at
     * all any more.
     *
     * The mini-cart pair is still there at runtime, because
     * partials/header.php renders the Shop's mini-cart on every page; it is
     * the SHOP that asks for it now (App\Module\ShopModule::shellStyles()),
     * which is what lets it disappear when the module does. The Shop's
     * catalogue and checkout code must never be in this list either way.
     */
    public function testTheSiteWideAssetListHoldsNoShopCodeBeyondTheMiniCart(): void
    {
        $source = self::sourceOf('src/Service/PageAssets.php');

        $shell = [];
        if (preg_match_all('/SHELL_(?:STYLES|SCRIPTS) = \[(.*?)\];/s', $source, $matches) !== false) {
            foreach ($matches[1] as $body) {
                preg_match_all("/'([^']+)'/", $body, $paths);
                $shell = [...$shell, ...$paths[1]];
            }
        }

        sort($shell);

        $this->assertSame(
            [
                'assets/css/core.css',
                'assets/js/core.js',
            ],
            $shell,
            "Core's half of the site shell must name no module file"
        );

        // What a page actually starts from, with the modules this deployment
        // runs: Core plus the Shop's mini-cart, and nothing else of the Shop.
        PageAssets::reset();
        $collected = PageAssets::collected();
        $this->assertSame(['assets/css/core.css', 'assets/css/shop/cart.css'], $collected['styles']);
        $this->assertSame(['assets/js/core.js', 'assets/js/shop/cart.js'], $collected['scripts']);
        $this->assertNotContains('assets/css/shop/shop.css', $collected['styles']);
        $this->assertNotContains('assets/js/shop/shop.js', $collected['scripts']);
        $this->assertNotContains('assets/css/shop/personalization.css', $collected['styles']);
        $this->assertNotContains('assets/js/personalization.js', $collected['scripts']);
    }

    /**
     * And with the Shop switched off, the site shell is Core alone: no cart
     * CSS, no cart JS, nothing shop-shaped on a CMS-only page.
     */
    public function testTheSiteShellLoadsNoShopAssetAtAllWithTheShopDisabled(): void
    {
        ModuleRegistry::overrideForTests(['shop' => false, 'personalization' => false, 'multilingual' => true]);

        try {
            PageAssets::reset();
            $collected = PageAssets::collected();

            $this->assertSame(['assets/css/core.css'], $collected['styles']);
            $this->assertSame(['assets/js/core.js'], $collected['scripts']);
        } finally {
            ModuleRegistry::overrideForTests(null);
        }
    }

    /** A page that is not the Shop never asks for the Shop's route assets. */
    public function testNonShopTemplatesDoNotAskForShopAssets(): void
    {
        foreach (self::PUBLIC_TEMPLATES as $template) {
            if (in_array($template, self::SHOP_TEMPLATES, true)) {
                continue;
            }

            $source = self::sourceOf($template);

            foreach (['assets/css/shop/shop.css', 'assets/js/shop/shop.js', 'assets/js/personalization.js'] as $shopAsset) {
                $this->assertStringNotContainsString(
                    $shopAsset,
                    $source,
                    "{$template} is not a Shop page but asks for {$shopAsset}"
                );
            }
        }
    }

    /**
     * shop.php asks for nothing itself: its product grid and collection tiles
     * are content blocks, and those blocks declare the Shop's assets. That is
     * what makes "a Shop block on any page brings its own frontend" true.
     */
    public function testTheShopBlocksAreWhatBringTheShopFrontendToAPage(): void
    {
        $productGrid = BlockDefinitions::get('product_grid');
        $collections = BlockDefinitions::get('shop_collections');

        $this->assertNotNull($productGrid);
        $this->assertNotNull($collections);

        $this->assertContains('assets/css/shop/shop.css', $productGrid->styles());
        $this->assertContains('assets/js/shop/shop.js', $productGrid->scripts());
        $this->assertContains('assets/css/shop/shop.css', $collections->styles());

        $this->assertStringNotContainsString('assets/js/shop/shop.js', self::sourceOf('shop.php'));
    }

    /* ------------------------------------------------------------------ */
    /* Every page goes through the one mechanism                           */
    /* ------------------------------------------------------------------ */

    public function testEveryPublicTemplateUsesTheAssetPartials(): void
    {
        foreach (self::PUBLIC_TEMPLATES as $template) {
            $source = self::sourceOf($template);

            $this->assertStringContainsString("partials/page-assets.php", $source, "{$template} does not include the head assets partial");
            $this->assertStringContainsString("partials/page-scripts.php", $source, "{$template} does not include the footer scripts partial");
        }
    }

    /**
     * No template hand-writes an asset tag any more. checkout.php's Turnstile
     * tag is the one documented exception: it is third-party, needs query
     * parameters and async/defer, and must come after the page's own scripts.
     */
    public function testNoPublicTemplateHardcodesAnAssetTag(): void
    {
        foreach (self::PUBLIC_TEMPLATES as $template) {
            $source = self::sourceOf($template);

            $this->assertDoesNotMatchRegularExpression(
                '/<link[^>]+rel="stylesheet"/',
                $source,
                "{$template} hardcodes a stylesheet; it should ask App\\Service\\PageAssets instead"
            );

            preg_match_all('/<script[^>]+src="([^"]+)"/', $source, $tags);

            foreach ($tags[1] as $src) {
                $this->assertStringContainsString(
                    'challenges.cloudflare.com/turnstile',
                    $src,
                    "{$template} hardcodes <script src=\"{$src}\">; it should ask App\\Service\\PageAssets instead"
                );
            }
        }
    }

    /* ------------------------------------------------------------------ */
    /* PageAssets itself                                                   */
    /* ------------------------------------------------------------------ */

    /**
     * Five instances of the same block on one page ask five times and load
     * one file. Without this the split would trade one oversized download for
     * a pile of duplicated ones.
     */
    public function testTheSameAssetIsLoadedOnceHoweverOftenItIsAskedFor(): void
    {
        PageAssets::requireStyle('assets/css/blocks/item-gallery.css');
        PageAssets::requireStyle('assets/css/blocks/item-gallery.css');
        PageAssets::requireStyle('/assets/css/blocks/item-gallery.css');
        PageAssets::requireScript('assets/js/blocks/item-gallery.js');
        PageAssets::requireScript('assets/js/blocks/item-gallery.js');
        PageAssets::requireVendorScript('gsap');
        PageAssets::requireVendorScript('gsap');

        $collected = PageAssets::collected();

        $this->assertSame(1, count(array_keys($collected['styles'], 'assets/css/blocks/item-gallery.css', true)));
        $this->assertSame(1, count(array_keys($collected['scripts'], 'assets/js/blocks/item-gallery.js', true)));
        $this->assertSame(['gsap'], $collected['vendor']);
    }

    /** The site shell is always in front, whoever asked first. */
    public function testTheSiteShellIsAlwaysLoadedBeforeAnythingElse(): void
    {
        PageAssets::requireStyle('assets/css/shop/shop.css');
        PageAssets::requireScript('assets/js/shop/shop.js');

        $collected = PageAssets::collected();

        $this->assertSame('assets/css/core.css', $collected['styles'][0]);
        $this->assertSame('assets/js/core.js', $collected['scripts'][0]);
        $this->assertSame('assets/css/shop/shop.css', end($collected['styles']));
        $this->assertSame('assets/js/shop/shop.js', end($collected['scripts']));
    }

    /**
     * A path that is not a real local asset is dropped, not printed. Nothing
     * a caller passes can become a URL the browser fetches from elsewhere, or
     * a route out of assets/.
     */
    public function testAPathThatIsNotALocalAssetIsRefused(): void
    {
        $refused = [
            'https://example.invalid/evil.js',
            '//example.invalid/evil.js',
            'assets/../.env',
            '../.env',
            'assets/css/does-not-exist.css',
            'src/Service/PageAssets.php',
            'assets/images/logo.svg',
        ];

        foreach ($refused as $path) {
            $this->assertFalse(PageAssets::isLoadable($path), "\"{$path}\" must not be loadable");
        }

        foreach ($refused as $path) {
            PageAssets::requireStyle($path);
            PageAssets::requireScript($path);
        }

        PageAssets::requireVendorScript('not-a-library');

        $collected = PageAssets::collected();

        $this->assertSame(['assets/css/core.css', 'assets/css/shop/cart.css'], $collected['styles']);
        $this->assertSame(['assets/js/core.js', 'assets/js/shop/cart.js'], $collected['scripts']);
        $this->assertSame([], $collected['vendor']);
    }

    /**
     * The whole point of the exercise, stated as a number: adding an
     * interactive content block touches its own files plus the one
     * registration line, and no global asset file.
     */
    public function testAddingAnInteractiveBlockTouchesNoGlobalAssetFile(): void
    {
        $globalFiles = ['assets/css/core.css', 'assets/js/core.js'];

        foreach ($globalFiles as $file) {
            $this->assertFileExists(self::root() . '/' . $file);
        }

        // A block's frontend is reachable entirely through its own definition.
        $gallery = BlockDefinitions::get('item_gallery');
        $this->assertNotNull($gallery);
        $this->assertSame(['assets/css/blocks/item-gallery.css'], $gallery->styles());
        $this->assertSame(['assets/js/blocks/item-gallery.js'], $gallery->scripts());

        // And a block with no behaviour of its own declares nothing, rather
        // than owning an empty file.
        $richText = BlockDefinitions::get('rich_text');
        $this->assertNotNull($richText);
        $this->assertSame([], $richText->styles());
        $this->assertSame([], $richText->scripts());
        $this->assertSame([], $richText->vendorScripts());
    }
}
