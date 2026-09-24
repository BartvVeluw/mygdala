<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Database;
use App\Service\AdminPermissions;
use App\Service\Blocks\BlockDefinitions;
use App\Service\Blocks\BlockSamples;
use PHPUnit\Framework\TestCase;
use Tests\Support\AdminTestSession;
use Tests\Support\BuiltInServer;

/**
 * The preview of one content block, as a signed-in editor sees it and as
 * nobody else can, over real HTTP.
 *
 * Two of PHP's own web servers on this checkout, pointed at the test
 * database (Tests\Support\BuiltInServer): one as the site is configured, and
 * one with the Portfolio and the Shop switched off in its environment, which
 * is the real route to a module being off after installation (MODULES.md).
 * The editor signs in with a real session (Tests\Support\AdminTestSession).
 *
 * What it proves, beyond the guard: the page is the block's own markup with
 * the block's own stylesheet, it cannot be indexed, cached or framed
 * elsewhere, a switched-off module's block is not there, and previewing every
 * block writes nothing at all to the database. The accounts and sessions are
 * this test's own and are removed again. When a server cannot be started the
 * test skips itself, like the HTTP tier does (TESTING.md).
 */
final class BlockPreviewAccessTest extends TestCase
{
    private static ?BuiltInServer $site = null;

    private static ?BuiltInServer $modulesOff = null;

    private AdminTestSession $session;

    public static function setUpBeforeClass(): void
    {
        self::$site = BuiltInServer::start();
        self::$modulesOff = BuiltInServer::start([
            'MODULE_PORTFOLIO_ENABLED' => 'false',
            'MODULE_SHOP_ENABLED' => 'false',
            'MODULE_PERSONALIZATION_ENABLED' => 'false',
        ]);
    }

    public static function tearDownAfterClass(): void
    {
        self::$site?->stop();
        self::$modulesOff?->stop();
        self::$site = null;
        self::$modulesOff = null;
    }

    protected function setUp(): void
    {
        foreach ([self::$site, self::$modulesOff] as $server) {
            if ($server === null || !$server->answers()) {
                $this->markTestSkipped("could not start PHP's built-in web server for this test");
            }
        }

        $this->session = new AdminTestSession();
    }

    protected function tearDown(): void
    {
        $this->session->forget();
    }

    private function editor(): string
    {
        return $this->session->signIn([AdminPermissions::PAGES_MANAGE])[0];
    }

    /** @return array{status: int, location: string, body: string, headers: string} */
    private static function preview(string $query, ?string $sessionId, ?BuiltInServer $server = null): array
    {
        return ($server ?? self::$site)->request('GET', '/admin/block-preview.php' . $query, $sessionId);
    }

    /**
     * A checksum of every table in the test database: what "nothing was
     * written" means, without trusting any one table to be the one that would
     * have changed.
     *
     * @return array<string, string>
     */
    private static function databaseChecksums(): array
    {
        $db = Database::connection();
        $tables = $db->query(
            "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME"
        )->fetchAll(\PDO::FETCH_COLUMN);

        $checksums = [];
        foreach ($tables as $table) {
            $row = $db->query('CHECKSUM TABLE `' . str_replace('`', '', (string) $table) . '`')->fetch();
            $checksums[(string) $table] = (string) ($row['Checksum'] ?? '');
        }

        return $checksums;
    }

    /** @return list<string> every block type the library can preview in this process */
    private static function previewableTypes(): array
    {
        $types = [];
        foreach (BlockDefinitions::all() as $type => $definition) {
            if ($definition->sampleContent(new BlockSamples()) !== null) {
                $types[] = $type;
            }
        }

        return $types;
    }

    // ---------------------------------------------------------- the guard

    public function testAVisitorIsSentToTheLoginScreen(): void
    {
        $response = self::preview('?type=cta_band', null);

        $this->assertSame(302, $response['status']);
        $this->assertSame('/admin/login.php', $response['location']);
        $this->assertStringNotContainsString('cta-band', $response['body']);
    }

    public function testAnAccountThatMayNotManagePagesIsRefused(): void
    {
        [$sessionId] = $this->session->signIn([AdminPermissions::DASHBOARD_VIEW]);
        $response = self::preview('?type=cta_band', $sessionId);

        $this->assertSame(403, $response['status']);
        $this->assertStringNotContainsString('cta-band', $response['body']);
    }

    public function testAfterSigningOutThePreviewIsGone(): void
    {
        $editor = $this->editor();
        $this->assertSame(200, self::preview('?type=cta_band', $editor)['status']);

        $this->session->forget();

        $this->assertSame(302, self::preview('?type=cta_band', $editor)['status']);
    }

    // ---------------------------------------------------------- the block

    public function testAnEditorSeesTheRealBlockWithItsOwnStylesheetAndNothingAroundIt(): void
    {
        $response = self::preview('?type=page_hero', $this->editor());
        $body = $response['body'];

        $this->assertSame(200, $response['status']);
        $this->assertStringContainsString('<section class="page-hero page-hero--background">', $body, 'the partial\'s own markup');
        $this->assertStringContainsString(BlockSamples::IMAGE_PATH, $body, 'with the sample picture');
        $this->assertMatchesRegularExpression('#<link rel="stylesheet" href="/assets/css/core\.css#', $body, 'after the site shell');
        $this->assertMatchesRegularExpression('#<link rel="stylesheet" href="/assets/css/blocks/page-hero\.css#', $body, 'and its own stylesheet');
        $this->assertMatchesRegularExpression('#<link rel="stylesheet" href="/assets/css/block-preview\.css#', $body);
        $this->assertMatchesRegularExpression('#<script src="/assets/js/block-preview\.js#', $body);

        $this->assertStringNotContainsString('class="site-header"', $body, 'no header, so no page view is counted');
        $this->assertStringNotContainsString('cookie-consent.js', $body, 'no cookie banner over the block');

        $this->assertMatchesRegularExpression('#<meta name="robots" content="noindex, nofollow">#', $body);
        $this->assertSame('noindex, nofollow', BuiltInServer::header($response, 'X-Robots-Tag'));
        $this->assertStringContainsString('no-store', BuiltInServer::header($response, 'Cache-Control'));
        $this->assertSame("form-action 'none'; frame-ancestors 'self'", BuiltInServer::header($response, 'Content-Security-Policy'));
    }

    public function testEveryBlockWithASampleAnswersWithItsOwnAssets(): void
    {
        $editor = $this->editor();
        $types = self::previewableTypes();

        $this->assertNotSame([], $types);

        foreach ($types as $type) {
            $definition = BlockDefinitions::get($type);
            $this->assertNotNull($definition);

            $response = self::preview('?type=' . $type, $editor);

            $this->assertSame(200, $response['status'], $type);
            $this->assertMatchesRegularExpression('#<main id="main" class="block-preview">\s*\S#', $response['body'], "{$type} renders an empty preview");

            foreach ([...$definition->styles(), ...$definition->scripts()] as $asset) {
                $this->assertStringContainsString('"/' . $asset . '?v=', $response['body'], "{$type} is previewed without {$asset}");
            }
        }
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function refusedTypes(): array
    {
        return [
            'unknown' => ['?type=no_such_block'],
            'empty' => ['?type='],
            'missing' => [''],
            'an array' => ['?type[]=rich_text'],
            'a path' => ['?type=../rich_text'],
            'not a key' => ['?type=Rich_Text'],
            'no sample' => ['?type=product_grid'],
        ];
    }

    /**
     * @dataProvider refusedTypes
     */
    public function testAnythingButAPreviewableBlockIsNotFound(string $query): void
    {
        $response = self::preview($query, $this->editor());

        $this->assertSame(404, $response['status'], $query);
        $this->assertStringNotContainsString('<main', $response['body']);
    }

    public function testABlockOfASwitchedOffModuleIsNotThere(): void
    {
        $editor = $this->editor();

        $this->assertSame(200, self::preview('?type=project_cards', $editor)['status'], 'the Portfolio is on here');

        foreach (['project_cards', 'shop_collections'] as $type) {
            $this->assertSame(404, self::preview('?type=' . $type, $editor, self::$modulesOff)['status'], $type);
        }

        $this->assertSame(200, self::preview('?type=rich_text', $editor, self::$modulesOff)['status'], 'Core is unaffected');
    }

    // ---------------------------------------------------------- no side effects

    public function testPreviewingEveryBlockWritesNothing(): void
    {
        $editor = $this->editor();
        $before = self::databaseChecksums();

        foreach (self::previewableTypes() as $type) {
            $this->assertSame(200, self::preview('?type=' . $type, $editor)['status'], $type);
        }

        $this->assertSame($before, self::databaseChecksums());
    }

    /**
     * Even sent by hand, past the browser's refusal, the sample form reaches
     * nothing: its key belongs to no stored form, so the endpoint answers it
     * like any unknown form and stores nothing.
     */
    public function testTheSampleFormDeliversNothingEvenWhenSentByHand(): void
    {
        $before = self::databaseChecksums();

        $response = self::$site?->request('POST', '/api/form-submit.php', null, [
            'form-key' => BlockSamples::FORM_KEY,
            'form-instance' => 'form-0123456789',
            'form-source' => '/',
            'naam' => 'Voorbeeld',
            'email' => BlockSamples::EMAIL,
            'bericht' => 'Voorbeeld',
        ]);

        $this->assertNotNull($response);
        $this->assertSame(303, $response['status']);
        $this->assertStringContainsString('form-status=error', $response['location']);
        $this->assertSame($before, self::databaseChecksums());
    }
}
