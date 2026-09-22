<?php

declare(strict_types=1);

/**
 * Test support, never part of the application: one request's worth of
 * FileApplier::apply() in a process of its own, so a test can kill it for
 * real — SIGKILL, no finally, no shutdown function — the way a host ends a
 * request that ran too long (tests/Update/ApplyAndMaintenanceTest.php).
 *
 *     php apply-in-child.php <autoload> <root> <staging> <backup> <journal>
 *                            <plan.json> <from> <kill after operation|-1>
 */

require $argv[1];

[$root, $staging, $backup, $journal, $planPath, $from, $killAfter] = array_slice($argv, 2);

$plan = App\Update\UpdatePlan::fromJson((string) file_get_contents($planPath));
$applier = new App\Update\FileApplier($root, $staging, new App\Update\FileBackup($root, $backup), $journal, 'test');

if ((int) $killAfter >= 0) {
    $applier->onEachOperation(static function (int $number) use ($killAfter): void {
        if ($number === (int) $killAfter) {
            posix_kill(getmypid(), 9);
        }
    });
}

$applier->apply($plan, [], (int) $from, 60.0, 1000);
echo "finished\n";
