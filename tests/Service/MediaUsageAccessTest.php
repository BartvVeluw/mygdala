<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Database;
use App\Repository\AdminUserRepository;
use App\Repository\MediaRepository;
use App\Repository\PageRepository;
use App\Repository\PageSectionRepository;
use App\Repository\TextImageSplitRepository;
use App\Service\AdminPermissions;
use App\Service\Media\MediaService;
use App\Service\SectionRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Where a media item is used, as the admin really answers it over HTTP — the
 * JSON of deleting a selection, a refused single delete, and the item view —
 * for an administrator who may manage the library but not the pages, and for
 * one who may do both.
 *
 * WHY OVER HTTP. Tests\Service\MediaUsageTest proves the rule itself
 * (App\Service\Media\VisibleMediaUsages). This proves that no response goes
 * around it: that a page's name reaches no JSON body and no HTML page by some
 * other road, which a string in a source file cannot show.
 *
 * For as long as this class runs, it starts PHP's own built-in web server on
 * this checkout, with the environment tests/bootstrap.php has already pointed
 * at the test database, and signs accounts in the way a browser is signed in:
 * a session the server finds through the cookie it is sent. The endpoints and
 * admin/media.php are real files, so none of this needs Apache. When the
 * server cannot be started the test skips itself, like the HTTP tier does
 * (TESTING.md).
 *
 * The page, its block, the media rows, the accounts and the sessions are this
 * test's own, under names nothing else uses, and tearDown() removes them.
 */
final class MediaUsageAccessTest extends TestCase
{
    /** @var resource|null */
    private static $server = null;

    private static int $port = 0;

    /** The page's key and slug: a token of its own, so a leak cannot pass unnoticed. */
    private string $pageKey = '';

    /** @var list<int> */
    private array $mediaIds = [];

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
        $process = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . self::$port, '-t', $root],
            [0 => ['pipe', 'r'], 1 => ['file', $discard, 'w'], 2 => ['file', $discard, 'w']],
            $pipes,
            $root,
            array_map('strval', getenv())
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

        MediaService::clearCache();
    }

    protected function tearDown(): void
    {
        $page = $this->pageKey === '' ? null : (new PageRepository())->findByContentKey($this->pageKey);
        $db = Database::connection();

        // The block first: it holds the reference that keeps the media row
        // from being deleted. Then the rows, then the page.
        if ($page !== null) {
            $sections = new PageSectionRepository();
            foreach ($sections->findForPage((int) $page['id']) as $row) {
                SectionRegistry::delete($row, $sections);
            }
        }

        $media = new MediaRepository();
        foreach ($this->mediaIds as $id) {
            $media->delete($id);
        }

        if ($page !== null) {
            $db->prepare('DELETE FROM pages WHERE id = :id')->execute(['id' => (int) $page['id']]);
        }

        foreach ($this->userIds as $id) {
            $db->prepare('DELETE FROM admin_users WHERE id = :id')->execute(['id' => $id]);
        }

        foreach ($this->sessionIds as $sessionId) {
            self::destroySession($sessionId);
        }

        $this->pageKey = '';
        $this->mediaIds = $this->userIds = $this->sessionIds = [];

        MediaService::clearCache();
    }

    // ------------------------------------------------ deleting a selection

    public function testASelectionTellsAManagerWithoutPageAccessThatAnItemIsUsedButNotWhere(): void
    {
        $mediaId = $this->itemUsedOnAPage();
        $account = $this->signIn([AdminPermissions::MEDIA_MANAGE]);

        $response = $this->deleteSelection($account, $mediaId);
        $answer = json_decode($response['body'], true);

        $this->assertSame(200, $response['status'], $response['body']);
        $this->assertIsArray($answer);
        $this->assertTrue($answer['ok']);
        $this->assertSame([], $answer['deleted']);
        $this->assertSame([$mediaId], $answer['kept'], 'still used, so still kept');
        $this->assertCount(1, $answer['details'], 'and the answer says why');
        $this->assertStringContainsString('toegangstest.png', $answer['details'][0]);
        $this->assertStringNotContainsString($this->pageKey, $response['body'], 'but names no page anywhere in the response');
        $this->assertStringNotContainsString('Tekst + afbeelding', $response['body']);

        MediaService::clearCache();
        $this->assertNotNull(MediaService::find($mediaId), 'and the item is still there');
    }

    public function testASelectionTellsAManagerWhoMayEditPagesWhichPageUsesTheItem(): void
    {
        $mediaId = $this->itemUsedOnAPage();
        $account = $this->signIn([AdminPermissions::MEDIA_MANAGE, AdminPermissions::PAGES_MANAGE]);

        $response = $this->deleteSelection($account, $mediaId);
        $answer = json_decode($response['body'], true);

        $this->assertSame(200, $response['status'], $response['body']);
        $this->assertSame([$mediaId], $answer['kept']);
        $this->assertStringContainsString($this->pageKey, $answer['details'][0]);
    }

    // ------------------------------------------------------- the item view

    public function testTheItemViewCountsAPlaceAManagerWithoutPageAccessCannotOpen(): void
    {
        $mediaId = $this->itemUsedOnAPage();
        $account = $this->signIn([AdminPermissions::MEDIA_MANAGE]);

        $view = $this->request('GET', '/admin/media.php?id=' . $mediaId, $account['session']);

        $this->assertSame(200, $view['status']);
        $this->assertStringContainsString('class="admin-media-usage"', $view['body'], 'the item is listed as used');
        $this->assertStringNotContainsString($this->pageKey, $view['body']);
        $this->assertStringNotContainsString('/admin/text-image-split.php', $view['body'], 'with no link to the block');
        $this->assertStringNotContainsString('action="/api/admin/delete-media.php"', $view['body'], 'and nothing to delete it with');
    }

    public function testTheItemViewNamesThePageToAManagerWhoMayEditPages(): void
    {
        $mediaId = $this->itemUsedOnAPage();
        $account = $this->signIn([AdminPermissions::MEDIA_MANAGE, AdminPermissions::PAGES_MANAGE]);

        $view = $this->request('GET', '/admin/media.php?id=' . $mediaId, $account['session']);

        $this->assertSame(200, $view['status']);
        $this->assertStringContainsString('href="/admin/text-image-split.php?section=' . $this->pageKey, $view['body']);
    }

    // ------------------------------------------------------ a single delete

    public function testARefusedSingleDeleteNamesNoPageOnTheScreenItReturnsTo(): void
    {
        $mediaId = $this->itemUsedOnAPage();
        $account = $this->signIn([AdminPermissions::MEDIA_MANAGE]);

        $refused = $this->request('POST', '/api/admin/delete-media.php', $account['session'], [
            'csrf_token' => $account['csrf'],
            'media_id' => (string) $mediaId,
        ]);

        $this->assertSame(302, $refused['status'], $refused['body']);
        $this->assertSame('/admin/media.php?id=' . $mediaId, self::header($refused['headers'], 'Location'));

        $view = $this->request('GET', '/admin/media.php?id=' . $mediaId, $account['session']);

        $this->assertStringContainsString('admin-alert--error', $view['body'], 'the refusal is shown');
        $this->assertStringNotContainsString($this->pageKey, $view['body']);

        MediaService::clearCache();
        $this->assertNotNull(MediaService::find($mediaId), 'and the item is still there');
    }

    // ------------------------------------------------------------- helpers

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
     * A media item used by a Tekst + afbeelding block on a page of this
     * test's own, made the way the page builder makes one.
     */
    private function itemUsedOnAPage(): int
    {
        $this->pageKey = 'zz-mediagebruik-' . bin2hex(random_bytes(4));

        $pageId = \Tests\Support\PageFixture::create([
            'content_key' => $this->pageKey,
            'slug' => $this->pageKey,
            'status' => 'draft',
        ], 'ZZ Mediagebruiktest');

        $path = 'assets/media/__usage_access_' . bin2hex(random_bytes(4)) . '__.png';

        $mediaId = (new MediaRepository())->create([
            'path' => $path,
            'original_filename' => 'toegangstest.png',
            'mime_type' => 'image/png',
            'width' => 10,
            'height' => 10,
            'file_size' => 100,
            'alt_text' => '',
            'checksum' => null,
        ]);
        $this->mediaIds[] = $mediaId;

        [$sectionId, $sectionKey] = SectionRegistry::create('text_image_split', $this->pageKey);
        (new PageSectionRepository())->create($pageId, $this->pageKey, 'text_image_split', $sectionKey, $sectionId);

        (new TextImageSplitRepository())->createItem($sectionId, \App\Service\TextImageSplitContent::DEFAULTS + [
            'media_id' => $mediaId,
            'image_path' => $path,
        ]);

        MediaService::clearCache();

        return $mediaId;
    }

    /**
     * Signs an account in the way App\Service\AdminAuth does at login — the
     * session holds the account id — together with the CSRF token a form on
     * the page would carry.
     *
     * @param list<string> $permissions
     *
     * @return array{session: string, csrf: string}
     */
    private function signIn(array $permissions): array
    {
        $users = new AdminUserRepository();
        $username = '__test_admin_user_media_' . bin2hex(random_bytes(3));

        $userId = $users->create([
            'name' => 'Mediagebruiktest',
            'username' => $username,
            'email' => $username . '@example.test',
            'password_hash' => password_hash(bin2hex(random_bytes(12)), PASSWORD_DEFAULT),
            'is_super_admin' => false,
            'is_active' => true,
        ]);
        $this->userIds[] = $userId;
        $users->setPermissions($userId, $permissions);

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        $csrf = bin2hex(random_bytes(32));

        session_name('vvl_admin_session');
        session_id(bin2hex(random_bytes(16)));
        session_start();
        $_SESSION = ['admin_logged_in' => true, 'admin_user_id' => $userId, 'csrf_token' => $csrf];
        $sessionId = session_id();
        session_write_close();
        $_SESSION = [];

        $this->sessionIds[] = $sessionId;

        return ['session' => $sessionId, 'csrf' => $csrf];
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
     * A selection of one, posted the way the confirmation dialog posts it.
     *
     * @param array{session: string, csrf: string} $account
     *
     * @return array{status: int, headers: list<string>, body: string}
     */
    private function deleteSelection(array $account, int $mediaId): array
    {
        return $this->request('POST', '/api/admin/delete-media-items.php', $account['session'], [
            'csrf_token' => $account['csrf'],
            'media_ids' => [(string) $mediaId],
            'ajax' => '1',
        ]);
    }

    /**
     * @param array<string, string|list<string>> $fields
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
}
