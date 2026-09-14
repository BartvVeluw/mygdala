<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Database;
use App\Repository\AdminUserRepository;
use App\Repository\PageRepository;
use App\Repository\PageSectionRepository;
use App\Service\AdminPermissions;
use App\Service\PageContent;
use App\Service\PageService;
use App\Service\PageTemplates\PageTemplateInstaller;
use App\Service\PageTemplates\PageTemplates;
use App\Service\SectionRegistry;
use PHPUnit\Framework\TestCase;

/**
 * The page builder's Inhoud tab as a signed-in editor really gets it, used
 * the way a browser uses it: what a new page starts with, the invitation
 * while it has no content, and the buttons on a block.
 *
 * WHY OVER HTTP. admin/page.php reads its page id with filter_input(), so it
 * cannot be rendered in-process, and the promises worth pinning are the ones
 * only the real screen keeps: that a hidden block's row says "Tonen" and its
 * own form really shows it again, that the delete form asks first and still
 * reaches an endpoint that refuses a request without its token, that the
 * reorder list still reaches its endpoint. The source-level contracts are
 * Tests\Service\AdminEditorNavigationTest's and the picker's own markup is
 * Tests\Service\BlockPickerTest's. What a click does in the browser — the
 * dialog, the picker panel — is checked by hand (PAGE-EDITOR.md).
 *
 * Same harness as Tests\Service\PagePreviewAccessTest: for as long as this
 * class runs, PHP's built-in web server serves this checkout against the test
 * database, and the editor is signed in with a real session. The pages, their
 * blocks, the account and the sessions are this test's own and are removed
 * again in tearDown(); nothing on the site is touched. When the server cannot
 * be started the test skips itself (TESTING.md).
 */
final class PageBuilderScreenTest extends TestCase
{
    /** @var resource|null */
    private static $server = null;

    private static int $port = 0;

    private PageRepository $pages;
    private PageSectionRepository $sections;
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
        $this->sections = new PageSectionRepository();
        $this->users = new AdminUserRepository();
    }

    protected function tearDown(): void
    {
        $sections = new PageSectionRepository();
        $pages = new PageRepository();

        foreach ($this->pageIds as $id) {
            foreach ($sections->findForPage($id) as $section) {
                SectionRegistry::delete($section, $sections);
            }

            $pages->delete($id);
        }

        if ($this->userIds !== []) {
            $db = Database::connection();
            foreach ($this->userIds as $id) {
                $db->prepare('DELETE FROM admin_users WHERE id = :id')->execute(['id' => $id]);
            }
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
     * Signs an editor in the way App\Service\AdminAuth does at login — the
     * session holds the account id and nothing else — with the one permission
     * the page builder asks for, and returns the session id a browser would
     * send back as its cookie.
     */
    private function signIn(): string
    {
        $username = '__test_admin_user_builder_' . bin2hex(random_bytes(3));

        $userId = $this->users->create([
            'name' => 'Paginabouwertest',
            'username' => $username,
            'email' => $username . '@example.test',
            'password_hash' => password_hash(bin2hex(random_bytes(12)), PASSWORD_DEFAULT),
            'is_super_admin' => false,
            'is_active' => true,
        ]);
        $this->userIds[] = $userId;
        $this->users->setPermissions($userId, [AdminPermissions::PAGES_MANAGE]);

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
     * @param array<string, string> $fields sent form-encoded with a POST
     *
     * @return array{status: int, headers: list<string>, body: string}
     */
    private function request(string $method, string $path, string $sessionId, array $fields = []): array
    {
        $options = [
            'method' => $method,
            'header' => 'Cookie: vvl_admin_session=' . $sessionId . "\r\n",
            'ignore_errors' => true,
            'follow_location' => 0,
            'timeout' => 15,
        ];

        if ($method === 'POST') {
            $options['header'] .= "Content-Type: application/x-www-form-urlencoded\r\n";
            $options['content'] = http_build_query($fields);
        }

        $body = @file_get_contents('http://127.0.0.1:' . self::$port . $path, false, stream_context_create(['http' => $options]));
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

    /**
     * A page of this test's own, made the way "Nieuwe pagina" makes one when
     * the editor leaves the template on "Lege pagina".
     *
     * @return array<string, mixed>
     */
    private function blankPage(): array
    {
        $slug = 'zz-bouwer-' . bin2hex(random_bytes(4));

        $id = PageTemplateInstaller::install(PageTemplates::get(PageTemplates::DEFAULT_KEY), [
            'content_key' => PageService::generateContentKey($this->pages, $slug),
            'slug' => $slug,
            'title' => 'ZZ Paginabouwertest ' . bin2hex(random_bytes(4)),
            'status' => PageContent::STATUS_DRAFT,
            'meta_title' => null,
            'meta_title_en' => null,
            'meta_description' => null,
            'meta_description_en' => null,
        ]);
        $this->pageIds[] = $id;

        return (array) $this->pages->findById($id);
    }

    /**
     * Attaches a new block the way the picker's endpoint does, and returns
     * its page_sections id.
     *
     * @param array<string, mixed> $page
     */
    private function addBlock(array $page, string $type): int
    {
        $contentKey = (string) $page['content_key'];

        [$sectionId, $sectionKey] = SectionRegistry::create($type, $contentKey);
        $this->sections->create((int) $page['id'], $contentKey, $type, $sectionKey, $sectionId);
        PageContent::clearCache();

        // create() appends to the bottom of the list, so the new row is the last.
        $rows = $this->sections->findForPage((int) $page['id']);

        return (int) end($rows)['id'];
    }

    /**
     * @param array<string, mixed> $page
     *
     * @return list<string> the page's block types, in order
     */
    private function types(array $page): array
    {
        return array_map(
            static fn (array $row): string => (string) $row['section_type'],
            $this->sections->findForPage((int) $page['id'])
        );
    }

    /**
     * The page builder as the signed-in editor gets it.
     *
     * @param array<string, mixed> $page
     */
    private function builder(array $page, string $sessionId): \DOMXPath
    {
        $response = $this->request('GET', '/admin/page.php?id=' . (int) $page['id'], $sessionId);
        $this->assertSame(200, $response['status'], 'the page builder did not open: ' . substr($response['body'], 0, 300));

        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="utf-8"?>' . $response['body']);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new \DOMXPath($document);
    }

    private function one(\DOMXPath $xpath, string $query, ?\DOMNode $context = null): \DOMElement
    {
        $nodes = $xpath->query($query, $context);
        $this->assertNotFalse($nodes, $query);
        $this->assertSame(1, $nodes->length, 'expected exactly one match for ' . $query);

        $node = $nodes->item(0);
        $this->assertInstanceOf(\DOMElement::class, $node);

        return $node;
    }

    /** How many nodes a query finds. Not count() or matches(): PHPUnit already has final ones. */
    private function nodesFound(\DOMXPath $xpath, string $query, ?\DOMNode $context = null): int
    {
        $nodes = $xpath->query($query, $context);
        $this->assertNotFalse($nodes, $query);

        return $nodes->length;
    }

    /** One block's own form, found by the endpoint it posts to. */
    private function rowForm(\DOMXPath $xpath, int $sectionId, string $endpoint): \DOMElement
    {
        return $this->one($xpath, '//*[@id="blok-' . $sectionId . '"]//form[@action="/api/admin/' . $endpoint . '"]');
    }

    /**
     * Sends a form exactly as the screen renders it: its own action and the
     * fields it carries, nothing added.
     *
     * @return array{status: int, headers: list<string>, body: string}
     */
    private function submit(\DOMXPath $xpath, \DOMElement $form, string $sessionId): array
    {
        $fields = [];
        foreach ($xpath->query('.//input[@name]', $form) ?: [] as $input) {
            $this->assertInstanceOf(\DOMElement::class, $input);
            $fields[$input->getAttribute('name')] = $input->getAttribute('value');
        }

        return $this->request('POST', $form->getAttribute('action'), $sessionId, $fields);
    }

    // ------------------------------------------------------ a new, empty page

    public function testANewBlankPageShowsItsHeadingAndInvitesTheFirstBlock(): void
    {
        $session = $this->signIn();
        $page = $this->blankPage();

        $this->assertSame(['page_hero'], $this->types($page), '"Lege pagina" starts with its heading and nothing else');

        $xpath = $this->builder($page, $session);

        $rows = $xpath->query('//*[@data-page-section-zone]/*[@data-page-section-id]');
        $this->assertNotFalse($rows);
        $this->assertSame(1, $rows->length);
        $this->assertStringContainsString('Paginakop', (string) $rows->item(0)?->textContent);

        $empty = $this->one($xpath, '//*[@data-block-picker-empty]');
        $this->assertStringContainsString('Je pagina heeft nog geen inhoud.', $empty->textContent);
        $this->assertStringContainsString('Voeg hieronder je eerste contentblok toe.', $empty->textContent);

        $invitation = $this->one($xpath, './/button[@data-block-picker-open]', $empty);
        $this->assertSame('button', $invitation->getAttribute('type'));
        $this->assertStringContainsString('Contentblok toevoegen', $invitation->textContent);

        // In place of the ordinary opener, and beside the one picker the screen has.
        $this->assertSame(0, $this->nodesFound($xpath, '//*[contains(@class, "admin-add-block__button")]'));
        $this->assertSame(1, $this->nodesFound($xpath, '//button[@data-block-picker-open]'));
        $this->assertSame(1, $this->nodesFound($xpath, '//*[@data-block-picker]'));
    }

    public function testTheInvitationMakesWayOnceThePageHasABlockEvenAHiddenOne(): void
    {
        $session = $this->signIn();
        $page = $this->blankPage();
        $block = $this->addBlock($page, 'rich_text');

        $xpath = $this->builder($page, $session);
        $this->assertSame(0, $this->nodesFound($xpath, '//*[@data-block-picker-empty]'));
        $this->assertSame(1, $this->nodesFound($xpath, '//button[contains(@class, "admin-add-block__button")][@data-block-picker-open]'));

        // A hidden block is still the editor's content.
        $this->sections->setActive($block, false);

        $xpath = $this->builder($page, $session);
        $this->assertSame(0, $this->nodesFound($xpath, '//*[@data-block-picker-empty]'));
    }

    // ------------------------------------------------------ hiding and showing

    public function testAVisibleBlockOffersVerbergenAndAHiddenOneOffersTonen(): void
    {
        $session = $this->signIn();
        $page = $this->blankPage();
        $visible = $this->addBlock($page, 'rich_text');
        $hidden = $this->addBlock($page, 'faq');
        $this->sections->setActive($hidden, false);

        $xpath = $this->builder($page, $session);

        foreach ([$visible => ['Verbergen', '0'], $hidden => ['Tonen', '1']] as $sectionId => [$word, $sends]) {
            $form = $this->rowForm($xpath, $sectionId, 'toggle-page-section.php');
            $button = $this->one($xpath, './/button', $form);

            $this->assertSame('submit', $button->getAttribute('type'));
            $this->assertSame($word, trim($button->textContent));
            $this->assertContains('admin-btn-secondary', explode(' ', $button->getAttribute('class')), 'a real button, not a text link');
            $this->assertSame($sends, $this->one($xpath, './/input[@name="is_active"]', $form)->getAttribute('value'));
        }

        // The state is written on the row, not only coloured.
        $hiddenRow = $this->one($xpath, '//*[@id="blok-' . $hidden . '"]');
        $this->assertContains('is-hidden-section', explode(' ', $hiddenRow->getAttribute('class')));
        $this->assertStringContainsString('Verborgen', $this->one($xpath, './/summary', $hiddenRow)->textContent);

        $visibleRow = $this->one($xpath, '//*[@id="blok-' . $visible . '"]');
        $this->assertStringNotContainsString('Verborgen', $this->one($xpath, './/summary', $visibleRow)->textContent);
    }

    public function testTheRowsOwnFormHidesTheBlockAndShowsItAgain(): void
    {
        $session = $this->signIn();
        $page = $this->blankPage();
        $block = $this->addBlock($page, 'rich_text');

        $xpath = $this->builder($page, $session);
        $response = $this->submit($xpath, $this->rowForm($xpath, $block, 'toggle-page-section.php'), $session);

        $this->assertSame(302, $response['status']);
        $this->assertStringEndsWith('#blok-' . $block, self::header($response['headers'], 'Location'), 'back to the row, not to the top');
        $this->assertSame(0, (int) ($this->sections->findById($block)['is_active'] ?? -1));

        $xpath = $this->builder($page, $session);
        $form = $this->rowForm($xpath, $block, 'toggle-page-section.php');
        $this->assertSame('Tonen', trim($this->one($xpath, './/button', $form)->textContent));

        $this->assertSame(302, $this->submit($xpath, $form, $session)['status']);
        $this->assertSame(1, (int) ($this->sections->findById($block)['is_active'] ?? -1));
    }

    // ------------------------------------------------------ deleting

    public function testDeletingABlockAsksFirstAndTheEndpointStillGuardsIt(): void
    {
        $session = $this->signIn();
        $page = $this->blankPage();
        $block = $this->addBlock($page, 'rich_text');

        $xpath = $this->builder($page, $session);
        $form = $this->rowForm($xpath, $block, 'delete-page-section.php');

        // Asks in the shared dialog and names the block; no inline confirm() left.
        $this->assertFalse($form->hasAttribute('onsubmit'));
        $this->assertSame('Contentblok verwijderen?', $form->getAttribute('data-admin-confirm-title'));
        $this->assertStringContainsString('Tekstblok', $form->getAttribute('data-admin-confirm'));
        $this->assertStringContainsString('definitief verwijderd', $form->getAttribute('data-admin-confirm'));
        $this->assertSame('Verwijderen', $form->getAttribute('data-admin-confirm-action'));
        $this->assertSame(1, $this->nodesFound($xpath, '//dialog[@data-admin-confirm-dialog]'));

        $button = $this->one($xpath, './/button[@type="submit"]', $form);
        $this->assertSame('Verwijderen', trim($button->textContent));
        $this->assertContains('admin-btn-danger', explode(' ', $button->getAttribute('class')));

        // The dialog is a courtesy, not a guard: without the token, or as a GET, nothing happens.
        $this->assertSame(403, $this->request('POST', '/api/admin/delete-page-section.php', $session, ['id' => (string) $block])['status']);
        $this->assertSame(405, $this->request('GET', '/api/admin/delete-page-section.php?id=' . $block, $session)['status']);
        $this->assertNotNull($this->sections->findById($block), 'a refused request deleted the block anyway');

        // The form as the screen renders it is what deletes the block.
        $this->assertSame(302, $this->submit($xpath, $form, $session)['status']);
        $this->assertNull($this->sections->findById($block));
        $this->assertSame(['page_hero'], $this->types($page));
    }

    // ------------------------------------------------------ reordering

    public function testTheBlockListIsStillOneReorderableZoneWiredToItsEndpoint(): void
    {
        $session = $this->signIn();
        $page = $this->blankPage();
        $first = $this->addBlock($page, 'rich_text');
        $second = $this->addBlock($page, 'faq');

        $xpath = $this->builder($page, $session);
        $zone = $this->one($xpath, '//*[@data-page-section-zone]');
        $this->assertSame('/api/admin/reorder-page-sections.php', $zone->getAttribute('data-reorder-url'));

        $ids = [];
        foreach ($xpath->query('./*[@data-page-section-id]', $zone) ?: [] as $row) {
            $this->assertInstanceOf(\DOMElement::class, $row);
            $ids[] = (int) $row->getAttribute('data-page-section-id');

            // Dragged by its handle alone, outside the part that folds away:
            // nothing else in the row, no button included, can start a drag.
            $this->assertSame(1, $this->nodesFound($xpath, './*[contains(@class, "admin-drag-handle")][@draggable="true"]', $row));
            $this->assertSame(1, $this->nodesFound($xpath, './/*[@draggable="true"]', $row));
        }

        $this->assertCount(3, $ids);
        $this->assertSame([$first, $second], array_slice($ids, 1));

        // What a drop sends: the zone's own token and page, and the new order.
        $reordered = [$second, $ids[0], $first];
        $response = $this->request('POST', $zone->getAttribute('data-reorder-url'), $session, [
            'csrf_token' => $zone->getAttribute('data-csrf-token'),
            'page_id' => $zone->getAttribute('data-page-id'),
            'section_ids' => implode(',', $reordered),
        ]);

        $this->assertSame(200, $response['status']);
        $this->assertTrue((bool) (json_decode($response['body'], true)['ok'] ?? false), $response['body']);
        $this->assertSame(
            $reordered,
            array_map(static fn (array $row): int => (int) $row['id'], $this->sections->findForPage((int) $page['id']))
        );
    }
}
