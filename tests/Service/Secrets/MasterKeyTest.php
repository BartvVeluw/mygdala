<?php

declare(strict_types=1);

namespace Tests\Service\Secrets;

use App\Service\Secrets\MasterKey;
use App\Service\Secrets\SecretStoreException;
use PHPUnit\Framework\TestCase;

/**
 * The application key behind every secret stored in the CMS
 * (App\Service\Secrets\MasterKey), on a directory of the test's own:
 *
 *  - APP_KEY decides when it is set, and a malformed one is refused rather
 *    than replaced by the key file;
 *  - reading never writes: without APP_KEY and without a file, load() makes
 *    nothing;
 *  - the key file is made once, atomically, 0600 in a 0700 directory, in the
 *    APP_KEY format, and never replaced — not even when it is broken;
 *  - storage that cannot be written means no key, never a fallback;
 *  - seal/open: a fresh nonce every time, bound to the slot and to the key,
 *    and a changed byte or another key is UNREADABLE;
 *  - the key bytes never show in a dump.
 */
final class MasterKeyTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/mygdala-masterkey-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        $this->remove($this->directory);
    }

    /** @return array<string, string> */
    private function environment(string $appKey = ''): array
    {
        return ['APP_KEY' => $appKey, 'SECRETS_STORAGE_PATH' => $this->directory];
    }

    private static function appKey(): string
    {
        return 'base64:' . base64_encode(random_bytes(32));
    }

    public function testAnAppKeyInTheEnvironmentDecidesAndNoFileIsMade(): void
    {
        $key = MasterKey::loadOrCreate($this->environment(self::appKey()));

        $this->assertSame(MasterKey::SOURCE_ENVIRONMENT, $key->source);
        $this->assertFileDoesNotExist(MasterKey::filePath($this->environment()));
        $this->assertSame('geheim', $key->open('shop.x', $key->seal('shop.x', 'geheim')));
    }

    public function testAMalformedAppKeyIsRefusedAndNeverReplacedByTheFile(): void
    {
        MasterKey::loadOrCreate($this->environment());
        $this->assertFileExists(MasterKey::filePath($this->environment()));

        foreach (['not-a-key', 'base64:' . base64_encode('too short'), base64_encode(random_bytes(32))] as $malformed) {
            try {
                MasterKey::load($this->environment($malformed));
                $this->fail('a malformed APP_KEY must be refused: ' . $malformed);
            } catch (SecretStoreException $e) {
                $this->assertSame(SecretStoreException::KEY_INVALID, $e->reason);
                $this->assertStringNotContainsString($malformed, $e->getMessage());
            }
        }
    }

    public function testReadingNeverMakesAKey(): void
    {
        $this->assertNull(MasterKey::load($this->environment()));
        $this->assertDirectoryDoesNotExist($this->directory);
    }

    public function testTheKeyFileIsMadeOnceRestrictedAndInTheAppKeyFormat(): void
    {
        $first = MasterKey::loadOrCreate($this->environment());
        $path = MasterKey::filePath($this->environment());

        $this->assertSame(MasterKey::SOURCE_FILE, $first->source);
        $this->assertMatchesRegularExpression('#^base64:[A-Za-z0-9+/]{43}=\n$#', (string) file_get_contents($path));
        if (DIRECTORY_SEPARATOR === '/') {
            $this->assertSame('600', substr(sprintf('%o', fileperms($path)), -3), 'only the owner may read the key');
            $this->assertSame('700', substr(sprintf('%o', fileperms(dirname($path))), -3));
        }

        $contents = file_get_contents($path);
        $second = MasterKey::loadOrCreate($this->environment());

        $this->assertSame($first->id(), $second->id(), 'a second save uses the key the first one made');
        $this->assertSame($contents, file_get_contents($path), 'the file is never rewritten');
        $this->assertSame([], glob(dirname($path) . '/*.tmp') ?: [], 'no temporary file is left behind');
        $this->assertSame('ok', $second->open('slot', $first->seal('slot', 'ok')));
    }

    public function testTheFileLineWorksAsAppKey(): void
    {
        $fromFile = MasterKey::loadOrCreate($this->environment());
        $line = trim((string) file_get_contents(MasterKey::filePath($this->environment())));

        $this->assertSame($fromFile->id(), MasterKey::load($this->environment($line))?->id());
    }

    public function testABrokenKeyFileIsAnErrorAndStaysAsItIs(): void
    {
        mkdir($this->directory . '/secrets', 0700, true);
        file_put_contents(MasterKey::filePath($this->environment()), "broken\n");

        foreach (['load', 'loadOrCreate'] as $method) {
            try {
                MasterKey::$method($this->environment());
                $this->fail($method . ' must refuse a broken key file');
            } catch (SecretStoreException $e) {
                $this->assertSame(SecretStoreException::KEY_INVALID, $e->reason);
            }
        }

        $this->assertSame("broken\n", file_get_contents(MasterKey::filePath($this->environment())), 'never replaced');
    }

    public function testStorageThatCannotBeWrittenMeansNoKey(): void
    {
        // A FILE where the directory should go: nothing can be made under it.
        file_put_contents($this->directory, 'in the way');

        try {
            MasterKey::loadOrCreate($this->environment());
            $this->fail('no key can be made here');
        } catch (SecretStoreException $e) {
            $this->assertSame(SecretStoreException::KEY_NOT_CREATED, $e->reason);
        } finally {
            unlink($this->directory);
        }
    }

    public function testEverySealHasItsOwnNonceAndOpensOnlyInItsSlotUnderItsKey(): void
    {
        $key = MasterKey::loadOrCreate($this->environment(self::appKey()));
        $secret = 'live_abcdefghijklmnopqrstuvwxyz0123456789';

        $one = $key->seal('shop.mollie.live_api_key', $secret);
        $two = $key->seal('shop.mollie.live_api_key', $secret);

        $this->assertNotSame($one, $two, 'a fresh random nonce per seal');
        $this->assertStringStartsWith('v1:', $one);
        $this->assertStringNotContainsString($secret, $one);
        $this->assertStringNotContainsString(base64_encode($secret), $one);
        $this->assertSame($secret, $key->open('shop.mollie.live_api_key', $one));

        $this->assertUnreadable(fn () => $key->open('shop.mollie.test_api_key', $one), 'another slot');
        $this->assertUnreadable(fn () => MasterKey::load($this->environment(self::appKey()))?->open('shop.mollie.live_api_key', $one), 'another key');

        $raw = base64_decode(substr($one, 3), true);
        $raw[30] = chr(ord($raw[30]) ^ 1);
        $this->assertUnreadable(fn () => $key->open('shop.mollie.live_api_key', 'v1:' . base64_encode($raw)), 'a changed byte');
        $this->assertUnreadable(fn () => $key->open('shop.mollie.live_api_key', 'plaintext'), 'no seal at all');
    }

    public function testTheKeyIdRevealsNothingAndTheBytesNeverShowInADump(): void
    {
        $appKey = self::appKey();
        $key = MasterKey::load($this->environment($appKey));
        $bytes = base64_decode(substr($appKey, 7), true);

        $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $key->id());
        $this->assertStringNotContainsString(bin2hex($bytes), $key->id());

        ob_start();
        var_dump($key);
        $dump = (string) ob_get_clean();
        $this->assertStringNotContainsString($bytes, $dump);
        $this->assertStringNotContainsString(substr($appKey, 7), $dump);
    }

    private function assertUnreadable(callable $open, string $case): void
    {
        try {
            $open();
            $this->fail($case . ' must not open');
        } catch (SecretStoreException $e) {
            $this->assertSame(SecretStoreException::UNREADABLE, $e->reason, $case);
        }
    }

    private function remove(string $path): void
    {
        if (is_file($path) || is_link($path)) {
            unlink($path);

            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->remove($path . '/' . $entry);
            }
        }
        rmdir($path);
    }
}
