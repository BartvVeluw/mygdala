<?php

declare(strict_types=1);

namespace App\Update;

/**
 * Core files on this server that are not what the installed release shipped.
 *
 * A live Mygdala installation has no business with hand-edited Core files,
 * and an update that silently replaced one would destroy somebody's work and
 * hide that it had ever been there. So before an update, every file the
 * installed release.json lists is hashed against it:
 *
 *   modified   the file is there, with other content
 *   missing    the file is gone
 *
 * Either blocks the update in V1, with the paths listed (Preflight). Only
 * release-owned files are looked at — .env, uploads and everything else the
 * installation owns never appears in release.json (Ownership), so it is
 * never "changed" as far as the updater is concerned.
 */
final class LocalChanges
{
    /**
     * @param list<string> $modified
     * @param list<string> $missing
     */
    private function __construct(
        public readonly array $modified,
        public readonly array $missing
    ) {
    }

    public static function detect(string $root, ReleaseDescriptor $installed): self
    {
        $modified = [];
        $missing = [];

        foreach ($installed->files as $path => $hash) {
            $absolute = $root . '/' . $path;

            if (!is_file($absolute)) {
                $missing[] = $path;
                continue;
            }

            if (!hash_equals($hash, (string) hash_file('sha256', $absolute))) {
                $modified[] = $path;
            }
        }

        return new self($modified, $missing);
    }

    public function isClean(): bool
    {
        return $this->modified === [] && $this->missing === [];
    }
}
