<?php

declare(strict_types=1);

namespace App\Update;

use App\Service\AppUrl;

/**
 * The update, as a state machine that advances one short step per request.
 *
 *     download → verify → extract → preflight            nothing live changes
 *     → maintenance → backup_database → backup_files      site closed, backed up
 *     → apply → migrate → health → finish                 the site changes
 *
 *     on failure after apply:  rollback_database → rollback_files
 *
 * WHY ONE STEP PER REQUEST. A shared host ends a request after 30 to 60
 * seconds and nobody can promise an update fits in that. So the admin page
 * asks for the next step (api/admin/updates-step.php), the server runs exactly
 * the step UpdateState says is next — bounded by a time budget, resumable
 * where it is long — saves the state and answers. A closed tab loses nothing:
 * the state file says where the update is, and "Doorgaan" runs that step
 * again. Every step is written so that running it twice is harmless
 * (docs/updates/ARCHITECTURE.md, "Hervatbaar").
 *
 * WHO DECIDES WHAT. The request supplies only which update it means and
 * which step it expects to run; both must match the state, or nothing runs.
 * The release, its URL, its hash, every path: all from the signed manifest
 * and the files this class wrote itself.
 *
 * FAILURE MODEL (docs/updates/RECOVERY.md):
 *
 *   before `maintenance`        nothing changed → failed, work thrown away
 *   maintenance or backup       only the flag changed → flag off, failed
 *   during `apply`              files rolled back in the same request from
 *                               the backup → rolled_back
 *   during `migrate`/`health`   database restored from the dump, then files
 *                               → rolled_back
 *   during a rollback           recovery_required: the site STAYS in
 *                               maintenance and the Updates screen says
 *                               exactly what happened and where the backup is
 *
 * Nothing here reports a failure as a harmless one when the site might be
 * inconsistent.
 */
final class Updater
{
    public const CHECK_FILE = 'last-check.json';
    public const HISTORY_FILE = 'history.json';

    /** Backups of this many most recent updates are kept. */
    public const KEEP_BACKUPS = 3;

    /** A check older than this has to be repeated before an update may start. */
    public const CHECK_MAX_AGE = 86400;

    /** Steps before anything of the live site is touched. */
    private const PREPARATION = ['download', 'verify', 'extract', 'preflight'];

    /** Steps after which only the maintenance flag and backups exist. */
    private const SAFEGUARDING = ['maintenance', 'backup_database', 'backup_files'];

    private ?UpdateSource $source;

    /** Test seam: called by FileApplier after each operation. */
    private ?\Closure $applyHook = null;

    public function __construct(
        private readonly UpdateStateStore $store,
        private readonly string $root,
        ?UpdateSource $source = null,
        private readonly ?float $budgetSeconds = null
    ) {
        $this->source = $source;
    }

    public static function fromConfig(): self
    {
        return new self(UpdateStateStore::fromConfig(), UpdateConfig::projectRoot());
    }

    public function onApplyOperation(?\Closure $hook): void
    {
        $this->applyHook = $hook;
    }

    // ------------------------------------------------------------ reading

    public function state(): UpdateState
    {
        return $this->store->load();
    }

    public function store(): UpdateStateStore
    {
        return $this->store;
    }

    /** @return array<string, mixed>|null */
    public function lastCheck(): ?array
    {
        $path = $this->store->storagePath() . '/' . self::CHECK_FILE;
        $data = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;

        return is_array($data) ? $data : null;
    }

    public function log(string $updateId): UpdateLog
    {
        return new UpdateLog($this->store->logPath($updateId));
    }

    /** @return list<array<string, mixed>> newest first */
    public function history(): array
    {
        $path = $this->store->storagePath() . '/' . self::HISTORY_FILE;
        $data = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;

        return is_array($data) ? array_values($data) : [];
    }

    // ------------------------------------------------------------ check

    /**
     * Asks the feed for the newest release and records the answer — the
     * verified manifest and the environment checks, or the reason there is
     * none. Never throws: "the feed could not be reached" is an answer too.
     *
     * @return array<string, mixed>
     */
    public function check(): array
    {
        $record = ['checked_at' => UpdateState::now()];

        try {
            $source = $this->source();
            $record['source'] = $source->describe();
            $manifest = $source->latest();
            $checks = (new Preflight($this->root, $this->store->storagePath()))->environment($manifest);

            $record['manifest'] = $manifest->toArray();
            $record['available'] = $manifest->semver()->isNewerThan(AppVersion::semver($this->root));
            $record['checks'] = array_map(static fn (PreflightCheck $check): array => $check->toArray(), $checks);
        } catch (UpdateException $e) {
            $record['error'] = ['key' => $e->messageKey, 'params' => $e->params, 'detail' => $e->getMessage()];
        } catch (\Throwable $e) {
            $record['error'] = ['key' => 'update.error.unexpected', 'params' => [], 'detail' => get_class($e) . ': ' . $e->getMessage()];
        }

        $this->store->ensureDirectory($this->store->storagePath());
        UpdateStateStore::writeAtomically(
            $this->store->storagePath() . '/' . self::CHECK_FILE,
            json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n"
        );

        return $record;
    }

    // ------------------------------------------------------------ lifecycle

    /**
     * Starts an update towards the release the last check found — the
     * manifest verified then and kept in private storage, so what is
     * installed is exactly what the administrator was shown.
     *
     * @throws UpdateException when an update is running, or nothing newer was found recently
     */
    public function start(string $startedBy): UpdateState
    {
        return $this->store->withLock(function () use ($startedBy): UpdateState {
            $current = $this->store->load();
            if (!$current->isSettled()) {
                throw new UpdateException('update.error.already_running', [], 'An update is already in progress');
            }

            $check = $this->lastCheck();
            if ($check === null || !is_array($check['manifest'] ?? null) || ($check['available'] ?? false) !== true) {
                throw new UpdateException('update.error.nothing_to_install', [], 'No newer release in the last check');
            }

            $age = time() - (int) strtotime((string) ($check['checked_at'] ?? ''));
            if ($age > self::CHECK_MAX_AGE) {
                throw new UpdateException('update.error.check_too_old', [], 'The last check is ' . $age . ' seconds old');
            }

            $checks = array_map([PreflightCheck::class, 'fromArray'], (array) ($check['checks'] ?? []));
            if (!Preflight::passes($checks)) {
                throw new UpdateException('update.error.requirements_not_met', [], 'The last check found requirements that are not met');
            }

            $manifest = ReleaseManifest::fromStoredArray($check['manifest']);
            $state = UpdateState::start($manifest, AppVersion::current($this->root), $this->root, $startedBy);

            Filesystem::remove($this->store->workDirectory($state->updateId()));
            $this->store->save($state);
            $this->log($state->updateId())->write('start', 'started', 'update.log.started', [
                'from' => $state->fromVersion(),
                'to' => $state->toVersion(),
                'by' => $startedBy,
            ], 'package ' . $manifest->sha256 . ' (' . $manifest->size . ' bytes) from ' . UpdateConfig::describeUrl($manifest->packageUrl));

            return $state;
        });
    }

    /**
     * Runs the next step of update $updateId, which must be $expectedStep.
     *
     * @throws UpdateException when the request does not match the state, or
     *                         another step is running right now
     */
    public function step(string $updateId, string $expectedStep): UpdateState
    {
        return $this->store->withLock(function () use ($updateId, $expectedStep): UpdateState {
            self::prepareRequest();

            $state = $this->store->load();
            $this->assertExpected($state, $updateId, $expectedStep);

            // A step that finds its own name in `in_step` knows the previous
            // attempt at it died halfway (timeout, killed process).
            $interrupted = $state->get('in_step') === $state->step();
            $state = $state->with('in_step', $state->step())->with('delay_ms', 0)->touched();
            $this->store->save($state);

            $log = $this->log($updateId);
            $log->write($state->step(), $interrupted ? 'resumed' : 'begin');

            try {
                $state = $this->runStep($state, $interrupted, $log);
            } catch (\Throwable $e) {
                $state = $this->failed($state, $e, $log);
            }

            $state = $state->with('in_step', null);
            $this->store->save($state);

            if (!$state->isRunning()) {
                $this->remember($state);
            }

            return $state;
        });
    }

    /**
     * Stops an update that has not changed the site yet: the flag goes, the
     * work and any partial backup are thrown away.
     */
    public function abort(string $updateId): UpdateState
    {
        return $this->store->withLock(function () use ($updateId): UpdateState {
            $state = $this->store->load();

            if (!$state->isRunning() || $state->updateId() !== $updateId) {
                throw new UpdateException('update.error.wrong_update', [], 'Nothing to abort');
            }

            if ($state->filesChanged() || $state->databaseChanged()) {
                throw new UpdateException('update.error.cannot_abort', [], 'The site has already been changed');
            }

            (new MaintenanceMode($this->root))->disable();
            Filesystem::remove($this->store->workDirectory($updateId));
            Filesystem::remove($this->store->backupDirectory($updateId));

            $state = $state->withMessage('update.message.aborted')->settle(UpdateState::FAILED);
            $this->store->save($state);
            $this->log($updateId)->write($state->step(), 'aborted', 'update.message.aborted');
            $this->remember($state);

            return $state;
        });
    }

    /**
     * After recovery_required: the administrator confirms the site was put
     * right by hand (docs/updates/RECOVERY.md), so the flag may go and a new
     * update may start. The backup is kept.
     */
    public function resolve(string $updateId, string $resolvedBy): UpdateState
    {
        return $this->store->withLock(function () use ($updateId, $resolvedBy): UpdateState {
            $state = $this->store->load();

            if ($state->status() !== UpdateState::RECOVERY_REQUIRED || $state->updateId() !== $updateId) {
                throw new UpdateException('update.error.wrong_update', [], 'Nothing to resolve');
            }

            (new MaintenanceMode($this->root))->disable();
            $state = $state->with('resolved_by', $resolvedBy)->withMessage('update.message.resolved', ['by' => $resolvedBy])->settle(UpdateState::FAILED);
            $this->store->save($state);
            $this->log($updateId)->write($state->step(), 'resolved', 'update.message.resolved', ['by' => $resolvedBy]);
            $this->remember($state);

            return $state;
        });
    }

    // ------------------------------------------------------------ the steps

    private function runStep(UpdateState $state, bool $interrupted, UpdateLog $log): UpdateState
    {
        return match ($state->step()) {
            'download' => $this->download($state, $log),
            'verify' => $this->verify($state, $log),
            'extract' => $this->extract($state, $log),
            'preflight' => $this->preflight($state, $log),
            'maintenance' => $this->maintenance($state, $log),
            'backup_database' => $this->backupDatabase($state, $interrupted, $log),
            'backup_files' => $this->backupFiles($state, $log),
            'apply' => $this->apply($state, $log),
            'migrate' => $this->migrate($state, $log),
            'health' => $this->health($state, $log),
            'finish' => $this->finish($state, $log),
            'rollback_database' => $this->rollbackDatabase($state, $interrupted, $log),
            'rollback_files' => $this->rollbackFiles($state, $log),
            default => throw new UpdateException('update.error.state_unreadable', [], 'Unknown step ' . $state->step()),
        };
    }

    private function download(UpdateState $state, UpdateLog $log): UpdateState
    {
        $manifest = $state->manifest();
        $work = $this->work($state);
        $this->store->ensureDirectory($work);

        $this->source()->download($manifest, $work . '/package.zip');
        $log->write('download', 'done', 'update.log.downloaded', ['bytes' => (int) filesize($work . '/package.zip')]);

        return $state->advanceTo('verify');
    }

    private function verify(UpdateState $state, UpdateLog $log): UpdateState
    {
        $manifest = $state->manifest();
        (new ReleasePackage($this->work($state) . '/package.zip', $manifest))->verify();
        $log->write('verify', 'done', 'update.log.verified', [], 'sha256 ' . $manifest->sha256);

        return $state->advanceTo('extract');
    }

    private function extract(UpdateState $state, UpdateLog $log): UpdateState
    {
        $work = $this->work($state);
        $extractor = new PackageExtractor($work . '/package.zip', $work . '/staging');
        $cursor = $state->cursor();

        if (!isset($cursor['next'])) {
            Filesystem::remove($work . '/staging');
            $cursor = ['next' => 0, 'entries' => $extractor->inspect()];
        }

        $next = $extractor->extract((int) $cursor['next'], $this->budget());
        if ($next !== null) {
            return $state->withCursor(['next' => $next] + $cursor);
        }

        $descriptor = (new PackageValidator($work . '/staging'))->validate($state->manifest());
        $log->write('extract', 'done', 'update.log.extracted', ['files' => count($descriptor->files)]);

        return $state->advanceTo('preflight');
    }

    private function preflight(UpdateState $state, UpdateLog $log): UpdateState
    {
        $work = $this->work($state);
        $preflight = new Preflight($this->root, $this->store->storagePath());
        $checks = $preflight->environment($state->manifest());

        $installed = ReleaseDescriptor::installed($this->root);
        $target = ReleaseDescriptor::fromJson((string) file_get_contents($work . '/staging/' . Ownership::RELEASE_MANIFEST));

        if ($installed !== null) {
            $plan = (new UpdatePlanner($this->root))->plan($installed, $target);
            UpdateStateStore::writeAtomically($work . '/plan.json', $plan->toJson());

            $checks = [...$checks, ...$preflight->installation(
                $plan,
                LocalChanges::detect($this->root, $installed),
                (new MigrationRunner($this->root))->status(),
                $work . '/staging'
            )];
            $state = $state->with('plan', $plan->summary());
        }

        $state = $state->with('checks', array_map(static fn (PreflightCheck $check): array => $check->toArray(), $checks));

        foreach ($checks as $check) {
            if ($check->status !== PreflightCheck::OK) {
                $log->write('preflight', $check->status, $check->messageKey, $check->params, $check->detail);
            }
        }

        if (!Preflight::passes($checks)) {
            Filesystem::remove($work . '/staging');
            @unlink($work . '/package.zip');

            return $state->withMessage('update.message.preflight_failed')->settle(UpdateState::FAILED);
        }

        $log->write('preflight', 'done', 'update.log.planned', (array) $state->get('plan', []));

        // What the site answers BEFORE the update, for the health check to
        // compare against; [] where it cannot reach itself.
        return $state->with('baseline', HealthCheck::probe(AppUrl::base()))->advanceTo('maintenance');
    }

    private function maintenance(UpdateState $state, UpdateLog $log): UpdateState
    {
        $token = bin2hex(random_bytes(24));
        (new MaintenanceMode($this->root))->enable($state->updateId(), $token);
        $log->write('maintenance', 'done', 'update.log.maintenance_on');

        return $state->with('health_token', $token)->with('maintenance', true)->advanceTo('backup_database');
    }

    private function backupDatabase(UpdateState $state, bool $interrupted, UpdateLog $log): UpdateState
    {
        $directory = $this->store->backupDirectory($state->updateId());
        $backup = new DatabaseBackup($directory);
        $cursor = $state->cursor();

        if ($cursor === []) {
            $cursor = $backup->start();
            $cursor['bytes'] = (int) @filesize($directory . '/database.sql.part');
        } elseif ($interrupted) {
            // Whatever the dead attempt appended after the last saved cursor
            // is cut off, so the dump continues exactly where it was.
            $part = $directory . '/database.sql.part';
            $handle = @fopen($part, 'r+');
            if ($handle !== false) {
                ftruncate($handle, (int) $cursor['bytes']);
                fclose($handle);
            }
        }

        $cursor = $backup->step($cursor, $this->budget());
        clearstatcache();
        $cursor['bytes'] = (int) @filesize($directory . '/database.sql.part');

        if (($cursor['done'] ?? false) !== true) {
            return $state->withCursor($cursor);
        }

        $metadata = $backup->finish($cursor);
        $log->write('backup_database', 'done', 'update.log.database_backed_up', [
            'tables' => count((array) $metadata['tables']),
            'bytes' => (int) $metadata['bytes'],
        ], 'sha256 ' . $metadata['sha256']);

        return $state->with('database_backup', DatabaseBackup::FILE)->advanceTo('backup_files');
    }

    private function backupFiles(UpdateState $state, UpdateLog $log): UpdateState
    {
        $plan = $this->plan($state);
        $installed = ReleaseDescriptor::installed($this->root)
            ?? throw new UpdateException('update.error.release_descriptor_invalid', [], 'release.json disappeared');
        $directory = $this->store->backupDirectory($state->updateId());
        $backup = new FileBackup($this->root, $directory);

        $paths = $plan->pathsToBackUp();
        $next = $backup->copy($paths, $installed, (int) ($state->cursor()['next'] ?? 0), $this->budget());

        if ($next !== null) {
            return $state->withCursor(['next' => $next]);
        }

        $backup->writeManifest([
            'format' => 1,
            'update_id' => $state->updateId(),
            'from_version' => $state->fromVersion(),
            'to_version' => $state->toVersion(),
            'created_at' => UpdateState::now(),
            'package_sha256' => $state->manifest()->sha256,
            'database_backup' => DatabaseBackup::FILE,
            'files_replaced' => array_keys($plan->replace),
            'files_deleted' => $plan->delete,
            'files_added' => array_keys($plan->add),
        ]);
        $log->write('backup_files', 'done', 'update.log.files_backed_up', ['count' => count($paths)]);

        return $state->advanceTo('apply');
    }

    private function apply(UpdateState $state, UpdateLog $log): UpdateState
    {
        $plan = $this->plan($state);
        self::preloadForApply();

        // The files this request is made of go last, and the list is fixed
        // at the first attempt so a resumed apply numbers its operations the
        // same way.
        $critical = $state->cursor()['critical'] ?? null;
        if (!is_array($critical)) {
            $critical = $this->includedReleaseFiles();
            $state = $state->withCursor(['critical' => $critical])->with('files_changed', true);
            $this->store->save($state);
        }

        $applier = $this->applier($state);
        $applier->onEachOperation($this->applyHook);

        try {
            $applier->apply($plan, $critical);
        } catch (\Throwable $e) {
            $log->write('apply', 'failed', $e instanceof UpdateException ? $e->messageKey : 'update.error.unexpected', $e instanceof UpdateException ? $e->params : [], $e->getMessage());

            return $this->rollBackFilesNow($state->withMessage(
                $e instanceof UpdateException ? $e->messageKey : 'update.error.unexpected',
                $e instanceof UpdateException ? $e->params : [],
                $e->getMessage()
            ), $log);
        }

        AppVersion::clearCache();
        $log->write('apply', 'done', 'update.log.applied', $plan->summary());

        // Give OPcache's timestamp check a moment before the next request
        // compiles the new files, where opcache_invalidate() was not allowed.
        return $state->with('delay_ms', 2500)->advanceTo('migrate');
    }

    private function migrate(UpdateState $state, UpdateLog $log): UpdateState
    {
        $runner = new MigrationRunner($this->root);

        if (!$state->databaseChanged()) {
            if ($runner->status()['pending'] === []) {
                $log->write('migrate', 'done', 'update.log.no_migrations');

                return $state->advanceTo('health');
            }

            $state = $state->with('database_changed', true);
            $this->store->save($state);
        }

        try {
            $result = $runner->runPending($this->budget());
        } catch (UpdateException $e) {
            $log->write('migrate', 'failed', $e->messageKey, $e->params, $e->getMessage());

            return $state->withMessage($e->messageKey, $e->params, $e->getMessage())->advanceTo('rollback_database');
        }

        $ran = [...(array) $state->get('migrations_ran', []), ...$result['ran']];
        $state = $state->with('migrations_ran', $ran);
        $log->write('migrate', $result['remaining'] > 0 ? 'progress' : 'done', 'update.log.migrated', [
            'count' => count($result['ran']),
            'remaining' => $result['remaining'],
        ], $result['output']);

        return $result['remaining'] > 0 ? $state->touched() : $state->advanceTo('health');
    }

    private function health(UpdateState $state, UpdateLog $log): UpdateState
    {
        $checks = (new HealthCheck($this->root))->run(
            $state->manifest(),
            AppUrl::base(),
            (array) $state->get('baseline', []),
            (string) $state->get('health_token', '')
        );

        $state = $state->with('health', array_map(static fn (PreflightCheck $check): array => $check->toArray(), $checks));

        foreach ($checks as $check) {
            $log->write('health', $check->status, $check->messageKey, $check->params, $check->detail);
        }

        if (!Preflight::passes($checks)) {
            return $state->withMessage('update.message.health_failed')
                ->advanceTo($state->databaseChanged() ? 'rollback_database' : 'rollback_files');
        }

        return $state->advanceTo('finish');
    }

    private function finish(UpdateState $state, UpdateLog $log): UpdateState
    {
        (new MaintenanceMode($this->root))->disable();
        Filesystem::remove($this->work($state));
        $this->pruneBackups();

        $log->write('finish', 'done', 'update.log.completed', ['version' => $state->toVersion()]);

        return $state->with('maintenance', false)->withMessage('update.message.completed', ['version' => $state->toVersion()])->settle(UpdateState::COMPLETED);
    }

    private function rollbackDatabase(UpdateState $state, bool $interrupted, UpdateLog $log): UpdateState
    {
        $restore = new DatabaseRestore($this->store->backupDirectory($state->updateId()));
        $cursor = $state->cursor();

        if ($cursor === []) {
            $cursor = $restore->start();
        } elseif ($interrupted) {
            $cursor = DatabaseRestore::resumeAfterInterruption($cursor);
        }

        $cursor = $restore->step($cursor, $this->budget());

        if (($cursor['done'] ?? false) !== true) {
            return $state->withCursor($cursor);
        }

        $restore->verify();
        $log->write('rollback_database', 'done', 'update.log.database_restored', ['statements' => (int) $cursor['statements']]);

        return $state->with('database_changed', false)->advanceTo('rollback_files');
    }

    private function rollbackFiles(UpdateState $state, UpdateLog $log): UpdateState
    {
        return $this->rollBackFilesNow($state, $log);
    }

    /**
     * Puts the old files back, proves they are the old release, lifts the
     * flag and ends the update as rolled back. Runs inside the failed
     * `apply` request itself, or as its own step after a database restore.
     */
    private function rollBackFilesNow(UpdateState $state, UpdateLog $log): UpdateState
    {
        $plan = $this->plan($state);
        $applier = $this->applier($state);
        $backup = new FileBackup($this->root, $this->store->backupDirectory($state->updateId()));

        $applier->rollback($plan);
        AppVersion::clearCache();

        $previous = ReleaseDescriptor::fromJson((string) file_get_contents($backup->releaseManifestPath()));
        $changes = LocalChanges::detect($this->root, $previous);
        if (!$changes->isClean()) {
            throw new UpdateException('update.error.rollback_failed', ['path' => implode(', ', array_slice([...$changes->modified, ...$changes->missing], 0, 5))], 'Files differ from the previous release after the rollback');
        }

        foreach (array_keys($plan->add) as $path) {
            if (is_file($this->root . '/' . $path)) {
                throw new UpdateException('update.error.rollback_failed', ['path' => $path], 'An added file is still there after the rollback');
            }
        }

        (new MaintenanceMode($this->root))->disable();
        $log->write('rollback_files', 'done', 'update.log.rolled_back', ['version' => $previous->version]);

        return $state->with('files_changed', false)->with('maintenance', false)->settle(UpdateState::ROLLED_BACK);
    }

    /**
     * Turns an exception from a step into the state the failure model
     * prescribes for that step.
     */
    private function failed(UpdateState $state, \Throwable $e, UpdateLog $log): UpdateState
    {
        $key = $e instanceof UpdateException ? $e->messageKey : 'update.error.unexpected';
        $params = $e instanceof UpdateException ? $e->params : [];
        $detail = $e instanceof UpdateException ? $e->getMessage() : get_class($e) . ': ' . $e->getMessage();
        $step = $state->step();

        $log->write($step, 'failed', $key, $params, $detail);
        $state = $state->withMessage($key, $params, $detail)->with('failed_step', $step);

        if (in_array($step, self::PREPARATION, true)) {
            Filesystem::remove($this->work($state) . '/staging');

            return $state->settle(UpdateState::FAILED);
        }

        if (in_array($step, self::SAFEGUARDING, true)) {
            try {
                (new MaintenanceMode($this->root))->disable();
                Filesystem::remove($this->store->backupDirectory($state->updateId()));
            } catch (\Throwable $inner) {
                $log->write($step, 'failed', 'update.error.maintenance_stuck', [], $inner->getMessage());

                return $state->settle(UpdateState::RECOVERY_REQUIRED);
            }

            return $state->with('maintenance', false)->settle(UpdateState::FAILED);
        }

        if (in_array($step, ['migrate', 'health'], true)) {
            return $state->advanceTo($state->databaseChanged() ? 'rollback_database' : 'rollback_files');
        }

        // apply is handled in apply() itself; what lands here is a failed
        // rollback, a failed finish, or a failed apply-rollback: the site
        // may be inconsistent, so it stays closed.
        return $state->settle(UpdateState::RECOVERY_REQUIRED);
    }

    // ------------------------------------------------------------ helpers

    private function assertExpected(UpdateState $state, string $updateId, string $expectedStep): void
    {
        if (!$state->isRunning()) {
            throw new UpdateException('update.error.not_running', [], 'No update is running');
        }

        if (!hash_equals($state->updateId(), $updateId)) {
            throw new UpdateException('update.error.wrong_update', [], 'Update id does not match the running update');
        }

        if ($state->step() !== $expectedStep) {
            throw new UpdateException('update.error.wrong_step', ['step' => $state->step()], 'Expected ' . $expectedStep . ', the update is at ' . $state->step());
        }
    }

    private function source(): UpdateSource
    {
        return $this->source ??= HttpUpdateSource::fromConfig();
    }

    private function work(UpdateState $state): string
    {
        return $this->store->workDirectory($state->updateId());
    }

    private function plan(UpdateState $state): UpdatePlan
    {
        $path = $this->work($state) . '/plan.json';

        if (!is_file($path)) {
            throw new UpdateException('update.error.plan_unreadable', [], 'plan.json is missing');
        }

        return UpdatePlan::fromJson((string) file_get_contents($path));
    }

    private function applier(UpdateState $state): FileApplier
    {
        return new FileApplier(
            $this->root,
            $this->work($state) . '/staging',
            new FileBackup($this->root, $this->store->backupDirectory($state->updateId())),
            $this->work($state) . '/' . FileApplier::JOURNAL,
            substr(hash('sha256', $state->updateId()), 0, 8)
        );
    }

    /** @return list<string> the release files this request has loaded */
    private function includedReleaseFiles(): array
    {
        $base = rtrim(str_replace('\\', '/', (string) realpath($this->root)), '/') . '/';
        $files = [];

        foreach (get_included_files() as $file) {
            $file = str_replace('\\', '/', $file);
            if (str_starts_with($file, $base)) {
                $files[] = substr($file, strlen($base));
            }
        }

        sort($files, SORT_STRING);

        return $files;
    }

    /**
     * Loads every class the apply and its rollback can reach BEFORE the first
     * file moves, so none of them is autoloaded from the new release halfway.
     */
    private static function preloadForApply(): void
    {
        foreach ([
            FileApplier::class, FileBackup::class, UpdatePlan::class, RelativePath::class, Ownership::class,
            AppVersion::class, SemVer::class, LocalChanges::class, ReleaseDescriptor::class, ReleaseManifest::class,
            MaintenanceMode::class, UpdateState::class, UpdateStateStore::class, UpdateLog::class,
            PreflightCheck::class, UpdateException::class, Filesystem::class, UpdateConfig::class,
        ] as $class) {
            class_exists($class);
        }
    }

    private static function prepareRequest(): void
    {
        ignore_user_abort(true);

        if (function_exists('set_time_limit')) {
            @set_time_limit(max(120, (int) ini_get('max_execution_time')));
        }
    }

    /**
     * How long one step may keep going before it hands back: a third of the
     * host's execution limit, never more than 15 seconds.
     */
    private function budget(): float
    {
        if ($this->budgetSeconds !== null) {
            return $this->budgetSeconds;
        }

        $limit = (int) ini_get('max_execution_time');

        return $limit <= 0 ? 15.0 : max(2.0, min(15.0, $limit / 3));
    }

    private function pruneBackups(): void
    {
        $root = $this->store->storagePath() . '/backups';
        $directories = is_dir($root) ? array_values(array_filter(
            scandir($root) ?: [],
            static fn (string $name): bool => preg_match('/^\d{8}-\d{6}-[0-9a-f]{6}$/', $name) === 1
        )) : [];
        sort($directories, SORT_STRING);

        foreach (array_slice($directories, 0, max(0, count($directories) - self::KEEP_BACKUPS)) as $old) {
            Filesystem::remove($root . '/' . $old);
        }
    }

    /** Adds a settled update to the short history the Updates screen shows. */
    private function remember(UpdateState $state): void
    {
        $history = array_filter($this->history(), static fn (array $entry): bool => ($entry['update_id'] ?? null) !== $state->updateId());
        array_unshift($history, [
            'update_id' => $state->updateId(),
            'from_version' => $state->fromVersion(),
            'to_version' => $state->toVersion(),
            'status' => $state->status(),
            'started_at' => $state->get('started_at'),
            'finished_at' => $state->get('finished_at'),
            'message' => $state->message(),
        ]);

        UpdateStateStore::writeAtomically(
            $this->store->storagePath() . '/' . self::HISTORY_FILE,
            json_encode(array_slice(array_values($history), 0, 20), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n"
        );
    }
}
