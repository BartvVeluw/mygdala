<?php

declare(strict_types=1);

namespace App\Update;

/**
 * Exactly what an update will do to the files, decided before anything is
 * written (UpdatePlanner), stored with the update (work/<id>/plan.json) and
 * then carried out to the letter by FileBackup and FileApplier.
 *
 *   add        new in this release, not on disk yet
 *   replace    Core file whose content changes
 *   delete     Core file of the installed release that this release no
 *              longer ships — the reason obsolete PHP files do not stay on
 *              a server forever
 *   preserve   what the update deliberately leaves alone: the installation's
 *              own directories and files, and anything on disk no release
 *              ever owned (a summary, for the screen and the log)
 *   conflicts  a path the release wants to ADD where a file that no release
 *              owned is already sitting; the update refuses rather than
 *              overwrite it
 *
 * Unchanged files are counted, not listed: most of a release is the same as
 * the one before it, and an update that rewrote those would only widen the
 * window in which old and new code are mixed.
 */
final class UpdatePlan
{
    /**
     * @param array<string, string> $add       path => new sha256
     * @param array<string, string> $replace   path => new sha256
     * @param list<string>          $delete
     * @param list<string>          $conflicts
     * @param array<string, int>    $preserve  label => number of files
     * @param list<string>          $emptiedDirectories directories to remove if the deletes leave them empty, deepest first
     */
    public function __construct(
        public readonly array $add,
        public readonly array $replace,
        public readonly array $delete,
        public readonly array $conflicts,
        public readonly array $preserve,
        public readonly int $unchanged,
        public readonly array $emptiedDirectories
    ) {
    }

    /** @return array<string, int> */
    public function summary(): array
    {
        return [
            'add' => count($this->add),
            'replace' => count($this->replace),
            'delete' => count($this->delete),
            'unchanged' => $this->unchanged,
            'conflicts' => count($this->conflicts),
        ];
    }

    /** Paths whose current content the backup has to keep. */
    public function pathsToBackUp(): array
    {
        return [...array_keys($this->replace), ...$this->delete];
    }

    public function toJson(): string
    {
        return json_encode([
            'add' => $this->add,
            'replace' => $this->replace,
            'delete' => $this->delete,
            'conflicts' => $this->conflicts,
            'preserve' => $this->preserve,
            'unchanged' => $this->unchanged,
            'emptied_directories' => $this->emptiedDirectories,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * Reads a plan this updater wrote. Every path is checked again: the plan
     * file lives in private storage, but a path that could reach outside the
     * site root or into installation data is refused wherever it comes from.
     */
    public static function fromJson(string $json, bool $checkOwnership = true): self
    {
        $data = json_decode($json, true);

        if (!is_array($data)) {
            throw new UpdateException('update.error.plan_unreadable', [], 'plan.json is not valid JSON');
        }

        $paths = static function (mixed $list, bool $keyed) use ($checkOwnership): array {
            $clean = [];
            foreach ((array) $list as $key => $value) {
                $path = (string) ($keyed ? $key : $value);
                RelativePath::assertSafe($path);

                if ($checkOwnership && !Ownership::isShipped($path)) {
                    throw new UpdateException('update.error.plan_unreadable', [], 'Plan names a path no release owns: ' . $path);
                }

                if ($keyed) {
                    $clean[$path] = (string) $value;
                } else {
                    $clean[] = $path;
                }
            }

            return $clean;
        };

        $directories = [];
        foreach ((array) ($data['emptied_directories'] ?? []) as $directory) {
            $directories[] = RelativePath::assertSafe((string) $directory);
        }

        return new self(
            $paths($data['add'] ?? [], true),
            $paths($data['replace'] ?? [], true),
            $paths($data['delete'] ?? [], false),
            array_map('strval', (array) ($data['conflicts'] ?? [])),
            array_map('intval', (array) ($data['preserve'] ?? [])),
            (int) ($data['unchanged'] ?? 0),
            $directories
        );
    }
}
