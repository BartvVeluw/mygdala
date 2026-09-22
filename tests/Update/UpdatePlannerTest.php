<?php

declare(strict_types=1);

namespace Tests\Update;

use App\Update\LocalChanges;
use App\Update\ReleaseDescriptor;
use App\Update\UpdatePlan;
use App\Update\UpdatePlanner;
use App\Update\UpdateException;
use PHPUnit\Framework\TestCase;
use Tests\Support\FeedFixture;

/**
 * The update plan (UpdatePlanner) and the check that runs before it
 * (LocalChanges): what is added, replaced, deleted and preserved, and what
 * blocks an update. Scenario H of the upgrade tests (a Core file changed by
 * hand) is proven here at the unit level.
 */
final class UpdatePlannerTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/mygdala-plan-' . bin2hex(random_bytes(4));
        mkdir($this->root, 0777, true);
    }

    protected function tearDown(): void
    {
        FeedFixture::removeDirectory($this->root);
    }

    /** @param array<string, string> $files */
    private function put(array $files): void
    {
        foreach ($files as $path => $content) {
            @mkdir(dirname($this->root . '/' . $path), 0777, true);
            file_put_contents($this->root . '/' . $path, $content);
        }
    }

    /** @param array<string, string> $files path => content */
    private static function release(string $version, array $files): ReleaseDescriptor
    {
        return new ReleaseDescriptor($version, $version, '2026-10-01T00:00:00Z', '8.1', '5.7', '10.3', [], 0, '', 1, array_map(
            static fn (string $content): string => hash('sha256', $content),
            $files
        ));
    }

    public function testThePlanAddsReplacesDeletesAndLeavesTheRestAlone(): void
    {
        $old = ['index.php' => 'v1', 'src/Same.php' => 'same', 'src/Old/Gone.php' => 'gone', 'assets/css/core.css' => 'css1'];
        $new = ['index.php' => 'v2', 'src/Same.php' => 'same', 'src/New.php' => 'new', 'assets/css/core.css' => 'css2'];
        $this->put($old + ['.env' => 'SECRET=1', 'assets/media/photo.jpg' => 'jpg', 'admin/error_log' => 'log']);

        $plan = (new UpdatePlanner($this->root))->plan(self::release('0.1.0', $old), self::release('0.2.0', $new));

        $this->assertSame(['src/New.php'], array_keys($plan->add));
        $this->assertSame(['index.php', 'assets/css/core.css'], array_keys($plan->replace));
        $this->assertSame(['src/Old/Gone.php'], $plan->delete);
        $this->assertSame(1, $plan->unchanged);
        $this->assertSame([], $plan->conflicts);
        $this->assertSame(['src/Old'], $plan->emptiedDirectories);
        $this->assertSame(1, $plan->preserve['.env']);
        $this->assertSame(1, $plan->preserve['assets/media/']);
        $this->assertSame(1, $plan->preserve['unlisted'], 'the host error_log is left where it is');
    }

    public function testAFileNoReleaseOwnedWhereTheReleaseWantsToAddOneIsAConflict(): void
    {
        $this->put(['index.php' => 'v1', 'src/New.php' => 'somebody else wrote this']);

        $plan = (new UpdatePlanner($this->root))->plan(
            self::release('0.1.0', ['index.php' => 'v1']),
            self::release('0.2.0', ['index.php' => 'v1', 'src/New.php' => 'new'])
        );

        $this->assertSame(['src/New.php'], $plan->conflicts);
        $this->assertSame([], $plan->add);
    }

    public function testAnIdenticalFileAlreadyInPlaceIsAdoptedNotRewritten(): void
    {
        $this->put(['index.php' => 'v1', 'src/New.php' => 'new']);

        $plan = (new UpdatePlanner($this->root))->plan(
            self::release('0.1.0', ['index.php' => 'v1']),
            self::release('0.2.0', ['index.php' => 'v1', 'src/New.php' => 'new'])
        );

        $this->assertSame([], $plan->add);
        $this->assertSame([], $plan->conflicts);
        $this->assertSame(2, $plan->unchanged);
    }

    public function testADirectoryThatStillHoldsReleaseFilesIsNeverAnEmptiedCandidate(): void
    {
        $this->put(['src/A/Keep.php' => 'k', 'src/A/Drop.php' => 'd']);

        $plan = (new UpdatePlanner($this->root))->plan(
            self::release('0.1.0', ['src/A/Keep.php' => 'k', 'src/A/Drop.php' => 'd']),
            self::release('0.2.0', ['src/A/Keep.php' => 'k'])
        );

        $this->assertSame(['src/A/Drop.php'], $plan->delete);
        $this->assertSame([], $plan->emptiedDirectories);
    }

    public function testAHandEditedCoreFileAndAMissingOneAreBothFound(): void
    {
        $release = self::release('0.1.0', ['index.php' => 'v1', 'src/A.php' => 'a', 'src/B.php' => 'b']);
        $this->put(['index.php' => 'v1', 'src/A.php' => 'a — edited on the server']);

        $changes = LocalChanges::detect($this->root, $release);

        $this->assertSame(['src/A.php'], $changes->modified);
        $this->assertSame(['src/B.php'], $changes->missing);
        $this->assertFalse($changes->isClean());
    }

    public function testInstallationFilesNeverCountAsLocalChanges(): void
    {
        $release = self::release('0.1.0', ['index.php' => 'v1']);
        $this->put(['index.php' => 'v1', '.env' => 'X=1', 'assets/media/a.jpg' => 'jpg']);

        $this->assertTrue(LocalChanges::detect($this->root, $release)->isClean());
    }

    public function testAPlanReadBackRefusesAPathOutsideTheRelease(): void
    {
        $plan = new UpdatePlan(['src/A.php' => str_repeat('a', 64)], [], ['.env'], [], [], 0, []);

        $this->expectException(UpdateException::class);
        UpdatePlan::fromJson($plan->toJson());
    }

    public function testARollbackReadsAnOldPlanWithoutTodaysOwnershipRules(): void
    {
        // What an older release shipped and the new code's contract no longer
        // calls release-owned: the rollback must still be able to restore it.
        $plan = new UpdatePlan([], [], ['scripts/release.php'], [], [], 0, []);

        $this->assertSame(['scripts/release.php'], UpdatePlan::fromJson($plan->toJson(), false)->delete);

        $this->expectException(UpdateException::class);
        UpdatePlan::fromJson((new UpdatePlan([], [], ['../escape.php'], [], [], 0, []))->toJson(), false);
    }

    public function testAPlanSurvivesTheRoundTripThroughItsFile(): void
    {
        $plan = new UpdatePlan(['src/A.php' => str_repeat('a', 64)], ['index.php' => str_repeat('b', 64)], ['src/Old.php'], [], ['.env' => 1], 3, ['src/Old']);

        $this->assertEquals($plan, UpdatePlan::fromJson($plan->toJson()));
    }
}
