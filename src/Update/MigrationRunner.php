<?php

declare(strict_types=1);

namespace App\Update;

use Phinx\Config\Config;
use Phinx\Migration\Manager;
use Phinx\Migration\MigrationInterface;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The project's own Phinx migrations, run from PHP instead of a shell.
 *
 * NOT a second migration system. It loads the same phinx.php, reads the same
 * `phinx_migration_log` and executes the same migration classes through
 * Phinx's Manager — the library half of `vendor/bin/phinx migrate`, minus
 * the console. A shared host has no shell and often no exec(), and an
 * update that depended on either would not be one (docs/updates/ARCHITECTURE.md,
 * "Migraties").
 *
 * One migration at a time, within a time budget: runPending() stops starting
 * new migrations once the budget is spent, so a release that brings twenty
 * migrations is spread over several short requests instead of one that a
 * shared host kills halfway. A single migration is never split — Phinx
 * records it as done only after it returns.
 *
 * Every word Phinx prints is kept and returned, for the update log.
 */
final class MigrationRunner
{
    public function __construct(
        private readonly string $root
    ) {
    }

    /**
     * Where this database stands against the migrations on disk.
     *
     * @return array{pending: list<string>, missing: list<string>, latest: string, count: int}
     *         pending: on disk, not yet run; missing: recorded as run, but
     *         no longer on disk; latest: the newest version on disk
     */
    public function status(): array
    {
        [$manager, $environment] = $this->manager(new BufferedOutput());

        $available = array_map('strval', array_keys($manager->getMigrations($environment)));
        $ran = array_map('strval', $manager->getEnvironment($environment)->getVersions());
        sort($available, SORT_STRING);

        return [
            'pending' => array_values(array_diff($available, $ran)),
            'missing' => array_values(array_diff($ran, $available)),
            'latest' => $available === [] ? '' : (string) end($available),
            'count' => count($available),
        ];
    }

    /**
     * Runs pending migrations in version order until none is left or the
     * budget is spent.
     *
     * @return array{ran: list<string>, remaining: int, output: string}
     *
     * @throws UpdateException when a migration fails; its output is in the
     *                         exception detail, and it is NOT recorded as run
     */
    public function runPending(float $budgetSeconds): array
    {
        $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, false);
        [$manager, $environment] = $this->manager($output);

        $migrations = $manager->getMigrations($environment);
        ksort($migrations);
        $ran = array_map('strval', $manager->getEnvironment($environment)->getVersions());

        $started = microtime(true);
        $done = [];
        $remaining = 0;

        foreach ($migrations as $version => $migration) {
            if (in_array((string) $version, $ran, true)) {
                continue;
            }

            if ($done !== [] && (microtime(true) - $started) >= $budgetSeconds) {
                $remaining++;
                continue;
            }

            try {
                $manager->executeMigration($environment, $migration, MigrationInterface::UP);
            } catch (\Throwable $e) {
                throw new UpdateException(
                    'update.error.migration_failed',
                    ['version' => (string) $version, 'name' => $migration->getName()],
                    trim($output->fetch()) . "\n" . get_class($e) . ': ' . $e->getMessage(),
                    $e
                );
            }

            $done[] = (string) $version;
        }

        return ['ran' => $done, 'remaining' => $remaining, 'output' => trim($output->fetch())];
    }

    /** @return array{0: Manager, 1: string} */
    private function manager(OutputInterface $output): array
    {
        $config = Config::fromPhp($this->root . '/phinx.php');
        $manager = new Manager($config, new ArrayInput([]), $output);

        return [$manager, $config->getDefaultEnvironment()];
    }
}
