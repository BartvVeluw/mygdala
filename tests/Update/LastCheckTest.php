<?php

declare(strict_types=1);

namespace Tests\Update;

use App\Update\AppVersion;
use App\Update\UpdateException;
use App\Update\Updater;
use App\Update\UpdateState;
use App\Update\UpdateStateStore;
use PHPUnit\Framework\TestCase;
use Tests\Support\FeedFixture;

/**
 * What the Updates screen and Updater::start() read back from
 * last-check.json. The record says what was newer when the check ran; once an
 * update has installed that release, the same record must not offer it again.
 * Before this, the screen showed "Update beschikbaar" and an enabled "Update
 * installeren" for the version it had just installed, until someone checked
 * again. Updater::lastCheck() judges `available` anew against VERSION,
 * without asking the feed; the preflight's own version_not_newer refusal
 * stays behind it. End to end: UpgradeEndToEndTest, scenario A.
 */
final class LastCheckTest extends TestCase
{
    private string $base = '';

    private string $root = '';

    private string $storage = '';

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/mygdala-last-check-' . bin2hex(random_bytes(4));
        $this->root = $this->base . '/site';
        $this->storage = $this->base . '/storage';
        mkdir($this->root, 0777, true);
        mkdir($this->storage, 0777, true);
    }

    protected function tearDown(): void
    {
        FeedFixture::removeDirectory($this->base);
        AppVersion::clearCache();
    }

    private function installed(string $version): Updater
    {
        file_put_contents($this->root . '/VERSION', $version . "\n");
        AppVersion::clearCache();

        return new Updater(new UpdateStateStore($this->storage, $this->root), $this->root);
    }

    /**
     * A record the way Updater::check() writes it.
     *
     * @return array<string, mixed>
     */
    private function recordCheck(string $offered, bool $available = true): array
    {
        $record = [
            'checked_at' => UpdateState::now(),
            'source' => 'test feed',
            'manifest' => ['version' => $offered, 'released_at' => '2026-09-22T16:07:09Z', 'size' => 1],
            'available' => $available,
            'checks' => [],
        ];
        file_put_contents($this->storage . '/' . Updater::CHECK_FILE, json_encode($record));

        return $record;
    }

    public function testANewerReleaseIsStillOffered(): void
    {
        $record = $this->recordCheck('0.1.1');

        $this->assertSame($record, $this->installed('0.1.0')->lastCheck(), 'the record, unchanged');
    }

    public function testOnceInstalledTheSameCheckNoLongerOffersIt(): void
    {
        $this->recordCheck('0.1.1');
        $check = $this->installed('0.1.1')->lastCheck();

        $this->assertFalse($check['available']);
        $this->assertSame('0.1.1', $check['manifest']['version'], 'what the feed said is still shown');
        $stored = json_decode((string) file_get_contents($this->storage . '/' . Updater::CHECK_FILE), true);
        $this->assertTrue($stored['available'], 'reading writes nothing');
    }

    public function testAnOlderReleaseIsNotOffered(): void
    {
        $this->recordCheck('0.1.1');

        $this->assertFalse($this->installed('0.2.0')->lastCheck()['available']);
    }

    public function testStartRefusesAStaleCheckBeforeAnythingHappens(): void
    {
        $this->recordCheck('0.1.1');
        $updater = $this->installed('0.1.1');

        try {
            $updater->start('test');
            $this->fail('a stale check started an update');
        } catch (UpdateException $e) {
            $this->assertSame('update.error.nothing_to_install', $e->messageKey);
        }

        $this->assertFileDoesNotExist($this->storage . '/state.json');
        $this->assertDirectoryDoesNotExist($this->storage . '/work');
    }

    public function testOnlyANewCheckOffersWhatTheLastOneDidNot(): void
    {
        $this->recordCheck('0.1.1', false);

        $this->assertFalse($this->installed('0.1.0')->lastCheck()['available']);
    }

    public function testWithoutAReadableVersionNothingIsOffered(): void
    {
        $this->recordCheck('0.1.1');
        $updater = $this->installed('0.1.0');
        file_put_contents($this->root . '/VERSION', "not a version\n");
        AppVersion::clearCache();

        $this->assertFalse($updater->lastCheck()['available']);
    }
}
