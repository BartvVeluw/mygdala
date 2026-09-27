<?php

declare(strict_types=1);

namespace Tests\Service\Secrets;

use App\Database;
use App\Service\Secrets\MasterKey;
use App\Service\Secrets\SecretStore;
use App\Service\Secrets\SecretStoreException;
use PHPUnit\Framework\TestCase;

/**
 * App\Service\Secrets\SecretStore on the test database, with a key directory
 * of the test's own:
 *
 *  - what reaches `secret_settings` is sealed: neither the value nor its
 *    base64 is in the row, only the hint the owner gave;
 *  - the first put() makes the key file, get() opens the value again, and a
 *    replacement replaces;
 *  - fail closed: with the key file gone, or under another APP_KEY, a stored
 *    value is UNREADABLE — get() throws, describe() says so — and storing a
 *    new value repairs that one slot only;
 *  - a row copied into another slot does not open there;
 *  - nothing stored is null, and a slot name outside the shape is refused.
 */
final class SecretStoreTest extends TestCase
{
    private string $directory;

    /** @var list<string> */
    private array $slots = [];

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/mygdala-secretstore-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        $statement = Database::connection()->prepare('DELETE FROM secret_settings WHERE slot = :slot');
        foreach ($this->slots as $slot) {
            $statement->execute(['slot' => $slot]);
        }

        foreach (glob($this->directory . '/secrets/{,.}*', GLOB_BRACE) ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        @rmdir($this->directory . '/secrets');
        @rmdir($this->directory);
    }

    private function slot(): string
    {
        return $this->slots[] = 'test.zz_' . bin2hex(random_bytes(5));
    }

    /** @param array<string, string> $extra */
    private function store(array $extra = []): SecretStore
    {
        return new SecretStore(null, $extra + ['APP_KEY' => '', 'SECRETS_STORAGE_PATH' => $this->directory]);
    }

    /** @return array<string, mixed> */
    private function row(string $slot): array
    {
        $statement = Database::connection()->prepare('SELECT * FROM secret_settings WHERE slot = :slot');
        $statement->execute(['slot' => $slot]);

        return (array) $statement->fetch();
    }

    public function testAStoredSecretIsSealedAtRestAndOpensAgain(): void
    {
        $slot = $this->slot();
        $secret = 'test_Zq8secretvalue0123456789abcdefghij';

        $this->store()->put($slot, $secret, 'test_••••••••ghij');

        $row = $this->row($slot);
        $this->assertStringNotContainsString($secret, (string) $row['ciphertext']);
        $this->assertStringNotContainsString(base64_encode($secret), (string) $row['ciphertext']);
        $this->assertStringNotContainsString('Zq8secret', implode('|', array_map('strval', $row)));
        $this->assertSame('test_••••••••ghij', $row['hint']);
        $this->assertFileExists($this->directory . '/secrets/app.key', 'the first save made the key');

        $this->assertSame($secret, $this->store()->get($slot));
        $this->assertSame(['hint' => 'test_••••••••ghij', 'readable' => true, 'reason' => ''], array_diff_key((array) $this->store()->describe($slot), ['updated_at' => true]));

        $this->store()->put($slot, 'test_Replacement0123456789abcdefghijkl', 'test_••••••••ijkl');
        $this->assertSame('test_Replacement0123456789abcdefghijkl', $this->store()->get($slot));
    }

    public function testNothingStoredIsNullAndMakesNoKey(): void
    {
        $slot = $this->slot();

        $this->assertNull($this->store()->get($slot));
        $this->assertNull($this->store()->describe($slot));
        $this->assertDirectoryDoesNotExist($this->directory);
    }

    public function testALostKeyFileMakesTheSecretUnreadableAndANewValueRepairsItsOwnSlot(): void
    {
        $kept = $this->slot();
        $repaired = $this->slot();
        $this->store()->put($kept, 'value-one', 'one');
        $this->store()->put($repaired, 'value-two', 'two');

        unlink($this->directory . '/secrets/app.key');

        foreach ([$kept, $repaired] as $slot) {
            try {
                $this->store()->get($slot);
                $this->fail('a secret without its key must never be returned');
            } catch (SecretStoreException $e) {
                $this->assertSame(SecretStoreException::UNREADABLE, $e->reason);
                $this->assertStringNotContainsString('value-', $e->getMessage());
            }
            $this->assertFalse($this->store()->describe($slot)['readable'] ?? true);
        }

        $this->store()->put($repaired, 'value-two-again', 'two');

        $this->assertSame('value-two-again', $this->store()->get($repaired));
        $this->assertFalse($this->store()->describe($kept)['readable'] ?? true, 'the other slot stays unreadable until it is entered again');
    }

    public function testAnotherAppKeyCannotOpenWhatThisOneSealed(): void
    {
        $slot = $this->slot();
        $this->store(['APP_KEY' => 'base64:' . base64_encode(random_bytes(32))])->put($slot, 'sealed', 'hint');

        $this->expectException(SecretStoreException::class);
        $this->store(['APP_KEY' => 'base64:' . base64_encode(random_bytes(32))])->get($slot);
    }

    public function testARowCopiedIntoAnotherSlotDoesNotOpenThere(): void
    {
        $from = $this->slot();
        $to = $this->slot();
        $this->store()->put($from, 'belongs to the first slot', 'hint');
        $this->store()->put($to, 'something else', 'hint');

        $source = $this->row($from);
        Database::connection()->prepare('UPDATE secret_settings SET ciphertext = :ciphertext WHERE slot = :slot')
            ->execute(['ciphertext' => $source['ciphertext'], 'slot' => $to]);

        try {
            $this->store()->get($to);
            $this->fail('a sealed value only opens in its own slot');
        } catch (SecretStoreException $e) {
            $this->assertSame(SecretStoreException::UNREADABLE, $e->reason);
        }
    }

    public function testASlotNameOutsideTheShapeIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->store()->get('Shop Mollie/Key');
    }

    public function testTheKeyFileIsWhereTheEnvironmentSays(): void
    {
        $this->assertSame($this->directory . '/secrets/app.key', MasterKey::filePath(['SECRETS_STORAGE_PATH' => $this->directory]));
        $this->assertStringEndsWith('/storage/secrets/app.key', MasterKey::filePath(['SECRETS_STORAGE_PATH' => '']));
        $this->assertStringNotContainsString(dirname(__DIR__, 3) . '/', MasterKey::filePath(['SECRETS_STORAGE_PATH' => '']), 'by default outside the project root');
    }
}
