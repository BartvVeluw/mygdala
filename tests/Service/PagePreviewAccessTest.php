<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Database;
use App\Repository\AdminUserRepository;
use App\Repository\PageRepository;
use App\Repository\PageSectionRepository;
use App\Repository\RichTextRepository;
use App\Service\AdminPermissions;
use App\Service\PageContent;
use App\Service\SectionRegistry;
use PHPUnit\Framework\TestCase;

/**
 * A draft page as a signed-in editor sees it, and as nobody else can.
 *
 * Over real HTTP, without the php_test container: for as long as this class
 * runs, it starts PHP's own built-in web server on this checkout, pointed at
 * the test database, and signs an editor in the way a browser is — with a
 * session the server finds through the cookie it is sent. That covers
 * everything the preview promises, because none of it lives in Apache:
 * admin/page-preview.php is a real file, and pagina.php and sitemap.php are
 * requested directly rather than through .htaccess. The rewrite itself is
 * Tests\Service\PageRoutingTest's business.
 *
 * The pages, their rich text blocks, the accounts and the sessions are this
 * test's own and are removed again in tearDown(); nothing on the site is
 * touched. When the server cannot be started the test skips itself, like the
 * HTTP tier does (TESTING.md).
 */
final class PagePreviewAccessTest extends TestCase
{
    /** @var resource|null */
    private static $server = null;

    private static int $port = 0;

    private PageRepository $pages;
    private AdminUserRepository $users;

    /** @var list<int> */
    private array $pageIds = [];

    /** @var list<int> */
    private array $userIds = [];

    /** @var list<string> */
    private array $sessionIds = [];

    public static function setUpBeforeClass(): void
    {
        $probe = @stream_socket_server('tcp://127.0.0.1:0');
        if ($probe === false) {
            return;
        }

        $address = (string) stream_socket_get_name($probe, false);
        fclose($probe);
        self::$port = (int) substr($address, (int) strrpos($address, ':') + 1);

        $root = dirname(__DIR__, 2);
        $discard = DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null';

        // The server inherits this process's environment, which
        // tests/bootstrap.php has already pointed at the test database.
        $environment = array_map('strval', getenv());

        $process = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . self::$port, '-t', $root],
            [0 => ['pipe', 'r'], 1 => ['file', $discard, 'w'], 2 => ['file', $discard, 'w']],
            $pipes,
            $root,
            $environment
        );

        if (!is_resource($process)) {
            return;
        }

        self::$server = $process;

        for ($attempt = 0; $attempt < 50 && !self::answers(); $attempt++) {
            usleep(100000);
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$server)) {
            proc_terminate(self::$server);
            proc_close(self::$server);
        }

        self::$server = null;
    }

    protected function setUp(): void
    {
        if (self::$server === null || !self::answers()) {
            $this->markTestSkipped("could not start PHP's built-in web server for this test");
        }

        $this->pages = new PageRepository();
        $this->users = new AdminUserRepository();
    }

    protected function tearDown(): void
    {
        $sections = new PageSectionRepository();

        foreach ($this->pageIds as $id) {
            foreach ($sections->findForPage($id) as $section) {
                SectionRegistry::delete($section, $sections);
            }

            $this->pages->delete($id);
        }

        $db = Database::connection();
        foreach ($this->userIds as $id) {
            $db->prepare('DELETE FROM admin_users WHERE id = :id')->execute(['id' => $id]);
        }

        foreach ($this->sessionIds as $sessionId) {
            self::destroySession($sessionId);
        }

        $this->pageIds = [];
        $this->userIds = [];
        $this->sessionIds = [];

        PageContent::clearCache();
    }

    private static function answers(): bool
    {
        $socket = @fsockopen('127.0.0.1', self::$port, $errorCode, $errorMessage, 0.2);

        if ($socket === false) {
            return false;
        }

        fclose($socket);

        return true;
    }

    /**
     * A page of this test's own with one rich text block on it. The title is a
     * token of its own, so a response that merely echoes the requested address
     * can never pass for one that shows the page.
     *
     * @return array<string, mixed>
     */
    private function page(string $status): array
    {
        $key = 'zz-voorbeeld-' . bin2hex(random_bytes(4));

        $title = 'ZZ Voorbeeldtest ' . bin2hex(random_bytes(4));

        $id = $this->pages->create([
            'content_key' => $key,
            'slug' => $key,
            'status' => $status,
        ]);
        $this->pageIds[] = $id;
        \App\Service\PageLocalization::save($id, \App\Service\PageLocalization::defaultLanguage(), [
            \App\Service\PageTranslation::TITLE => $title,
        ]);

        // A rich text block with words of its own: a new, empty body renders
        // nothing at all (partials/section-rich-text.php), and a preview that
        // left the blocks out must not pass for one that shows them.
        [$sectionId, $sectionKey] = SectionRegistry::create('rich_text', $key);
        (new PageSectionRepository())->create($id, $key, 'rich_text', $sectionKey, $sectionId);

        $blockText = 'ZZ blokinhoud ' . bin2hex(random_bytes(4));
        (new RichTextRepository())->upsertSection($key, $sectionKey, [
            'content_html' => '<p>' . $blockText . '</p>',
            'is_active' => true,
        ]);

        PageContent::clearCache();

        // The row carries no text of its own; the title travels along so the
        // assertions can look for the page's own words in a response.
        return (array) $this->pages->findById($id) + ['block_text' => $blockText, 'title' => $title];
    }

    /**
     * Signs an account in the way App\Service\AdminAuth does at login — the
     * session holds the account id and nothing else — and returns the session
     * id a browser would send back as its cookie.
     *
     * @param list<string> $permissions
     */
    private function signIn(array $permissions): string
    {
        $username = '__test_admin_user_preview_' . bin2hex(random_bytes(3));

        $userId = $this->users->create([
            'name' => 'Voorbeeldtest',
            'username' => $username,
            'email' => $username . '@example.test',
            'password_hash' => password_hash(bin2hex(random_bytes(12)), PASSWORD_DEFAULT),
            'is_super_admin' => false,
            'is_active' => true,
        ]);
        $this->userIds[] = $userId;
        $this->users->setPermissions($userId, $permissions);

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        session_name('vvl_admin_session');
        session_id(bin2hex(random_bytes(16)));
        session_start();
        $_SESSION = ['admin_logged_in' => true, 'admin_user_id' => $userId];
        $sessionId = session_id();
        session_write_close();
        $_SESSION = [];

        $this->sessionIds[] = $sessionId;

        return $sessionId;
    }

    /** What signing out does to the stored session. */
    private static function destroySession(string $sessionId): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        session_name('vvl_admin_session');
        session_id($sessionId);
        session_start();
        session_destroy();
        $_SESSION = [];
    }

    /**
     * @return array{status: int, headers: list<string>, body: string}
     */
    private function get(string $path, ?string $sessionId = null): array
    {
        $context = stream_context_create(['http' => [
            'header' => $sessionId === null ? '' : 'Cookie: vvl_admin_session=' . $sessionId . "\r\n",
            'ignore_errors' => true,
            'follow_location' => 0,
            'timeout' => 15,
        ]]);

        $body = @file_get_contents('http://127.0.0.1:' . self::$port . $path, false, $context);
        $headers = $http_response_header ?? [];
        preg_match('#^HTTP/\S+\s+(\d{3})#', (string) ($headers[0] ?? ''), $status);

        return ['status' => (int) ($status[1] ?? 0), 'headers' => $headers, 'body' => (string) $body];
    }

    /** @param list<string> $headers */
    private static function header(array $headers, string $name): string
    {
        foreach ($headers as $line) {
            if (stripos($line, $name . ':') === 0) {
                return trim(substr($line, strlen($name) + 1));
            }
        }

        return '';
    }

    // ------------------------------------------------------ the public side

    public function testAPublishedPageIsPublicAsBefore(): void
    {
        $page = $this->page(PageContent::STATUS_PUBLISHED);

        $response = $this->get('/pagina.php?slug=' . $page['slug']);

        $this->assertSame(200, $response['status']);
        $this->assertStringContainsString((string) $page['title'], $response['body']);
    }

    public function testADraftIsNotPublic(): void
    {
        $page = $this->page(PageContent::STATUS_DRAFT);

        $response = $this->get('/pagina.php?slug=' . $page['slug']);

        $this->assertSame(404, $response['status']);
        $this->assertStringNotContainsString((string) $page['title'], $response['body']);
        $this->assertStringNotContainsString((string) $page['block_text'], $response['body']);
    }

    /**
     * No parameter opens a draft on its public address, and neither does an
     * editor's own session: the public side does not look at either.
     */
    public function testNeitherAParameterNorAnEditorsSessionOpensADraftOnItsPublicAddress(): void
    {
        $page = $this->page(PageContent::STATUS_DRAFT);
        $editor = $this->signIn([AdminPermissions::PAGES_MANAGE]);

        foreach (['&preview=1', '&voorbeeld=1', '&preview=' . $page['id']] as $parameter) {
            foreach ([null, $editor] as $session) {
                $response = $this->get('/pagina.php?slug=' . $page['slug'] . $parameter, $session);

                $this->assertSame(404, $response['status'], $parameter . ($session === null ? '' : ' (signed in)'));
                $this->assertStringNotContainsString((string) $page['title'], $response['body']);
            }
        }
    }

    public function testTheSitemapLeavesTheDraftOut(): void
    {
        $draft = $this->page(PageContent::STATUS_DRAFT);
        $published = $this->page(PageContent::STATUS_PUBLISHED);

        $sitemap = $this->get('/sitemap.php')['body'];

        $this->assertStringContainsString('/' . $published['slug'] . '</loc>', $sitemap);
        $this->assertStringNotContainsString('/' . $draft['slug'] . '</loc>', $sitemap);
    }

    // ------------------------------------------------------- the preview

    public function testAVisitorIsSentToTheLoginScreenInsteadOfThePreview(): void
    {
        $page = $this->page(PageContent::STATUS_DRAFT);

        $response = $this->get('/admin/page-preview.php?id=' . $page['id']);

        $this->assertSame(302, $response['status']);
        $this->assertSame('/admin/login.php', self::header($response['headers'], 'Location'));
        $this->assertStringNotContainsString((string) $page['title'], $response['body']);
    }

    public function testAnAccountThatMayNotManagePagesIsRefused(): void
    {
        $page = $this->page(PageContent::STATUS_DRAFT);

        $response = $this->get(
            '/admin/page-preview.php?id=' . $page['id'],
            $this->signIn([AdminPermissions::DASHBOARD_VIEW])
        );

        $this->assertSame(403, $response['status']);
        $this->assertStringNotContainsString((string) $page['title'], $response['body']);
    }

    public function testASignedInEditorSeesTheDraftWithItsBlocksAndKnowsItIsAPreview(): void
    {
        $page = $this->page(PageContent::STATUS_DRAFT);

        $response = $this->get(
            '/admin/page-preview.php?id=' . $page['id'],
            $this->signIn([AdminPermissions::PAGES_MANAGE])
        );

        $this->assertSame(200, $response['status']);
        $this->assertStringContainsString((string) $page['title'], $response['body'], 'the page\'s own head');
        $this->assertStringContainsString('class="site-header"', $response['body'], 'the site shell');
        $this->assertStringContainsString('class="rich-content"', $response['body'], 'the page\'s blocks');
        $this->assertStringContainsString((string) $page['block_text'], $response['body'], 'with their stored content');
        $this->assertStringContainsString('class="page-preview-bar"', $response['body'], 'recognisable as a preview');
        $this->assertMatchesRegularExpression('#<meta name="robots" content="noindex#', $response['body']);
        $this->assertSame('noindex, nofollow', self::header($response['headers'], 'X-Robots-Tag'));
        $this->assertStringContainsString('no-store', self::header($response['headers'], 'Cache-Control'));
    }

    public function testPreviewingPublishesNothing(): void
    {
        $page = $this->page(PageContent::STATUS_DRAFT);
        $editor = $this->signIn([AdminPermissions::PAGES_MANAGE]);

        $this->assertSame(200, $this->get('/admin/page-preview.php?id=' . $page['id'], $editor)['status']);

        PageContent::clearCache();
        $after = $this->pages->findById((int) $page['id']);

        $this->assertNotNull($after);
        $this->assertSame(PageContent::STATUS_DRAFT, $after['status']);
        $this->assertSame($page['updated_at'], $after['updated_at']);
        $this->assertSame(404, $this->get('/pagina.php?slug=' . $page['slug'])['status'], 'and it is still not public');
    }

    public function testAfterSigningOutThePreviewIsGone(): void
    {
        $page = $this->page(PageContent::STATUS_DRAFT);
        $editor = $this->signIn([AdminPermissions::PAGES_MANAGE]);

        $this->assertSame(200, $this->get('/admin/page-preview.php?id=' . $page['id'], $editor)['status']);

        self::destroySession($editor);
        $response = $this->get('/admin/page-preview.php?id=' . $page['id'], $editor);

        $this->assertSame(302, $response['status']);
        $this->assertStringNotContainsString((string) $page['title'], $response['body']);
    }

    public function testAPageThatDoesNotExistIsNotFound(): void
    {
        $response = $this->get('/admin/page-preview.php?id=999999999', $this->signIn([AdminPermissions::PAGES_MANAGE]));

        $this->assertSame(404, $response['status']);
    }
}
