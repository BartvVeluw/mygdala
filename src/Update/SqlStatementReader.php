<?php

declare(strict_types=1);

namespace App\Update;

/**
 * Reads a gzip-compressed SQL dump one statement at a time, and can start
 * again at any statement boundary it handed out.
 *
 * A statement ends at a semicolon that is not inside a quoted string, a
 * quoted identifier or a comment — the same rule the mysql client follows,
 * so a dump a support engineer imports by hand through phpMyAdmin and the
 * one DatabaseRestore replays are read the same way.
 *
 * The offset it reports is a position in the UNCOMPRESSED stream; gzseek()
 * can return there on the next request, which is what makes a restore
 * resumable across short requests.
 */
final class SqlStatementReader
{
    /** @var resource */
    private $handle;

    private int $offset;

    public function __construct(string $path, int $offset = 0)
    {
        $handle = @gzopen($path, 'rb');

        if ($handle === false) {
            throw new UpdateException('update.error.backup_unreadable', [], 'Cannot open ' . basename($path));
        }

        if ($offset > 0 && gzseek($handle, $offset) !== 0) {
            gzclose($handle);
            throw new UpdateException('update.error.backup_unreadable', [], 'Cannot seek to ' . $offset);
        }

        $this->handle = $handle;
        $this->offset = $offset;
    }

    public function __destruct()
    {
        if (is_resource($this->handle)) {
            gzclose($this->handle);
        }
    }

    /** Where the next statement starts (uncompressed bytes). */
    public function offset(): int
    {
        return $this->offset;
    }

    /**
     * The next complete statement without its terminating semicolon, or
     * null at the end of the dump.
     *
     * @throws UpdateException when the dump ends inside a statement
     */
    public function next(): ?string
    {
        $statement = '';
        $quote = null;          // ', " or ` while inside one
        $blockComment = false;

        while (($line = gzgets($this->handle)) !== false) {
            $this->offset += strlen($line);
            $length = strlen($line);

            // A whole-line comment outside any statement: skip it.
            if ($statement === '' && $quote === null && !$blockComment) {
                $trimmed = ltrim($line);
                if ($trimmed === '' || str_starts_with($trimmed, '-- ') || str_starts_with($trimmed, '#') || rtrim($trimmed) === '--') {
                    continue;
                }
            }

            for ($i = 0; $i < $length; $i++) {
                $char = $line[$i];

                if ($blockComment) {
                    if ($char === '*' && ($line[$i + 1] ?? '') === '/') {
                        $blockComment = false;
                        $statement .= '*/';
                        $i++;
                    } else {
                        $statement .= $char;
                    }
                    continue;
                }

                if ($quote !== null) {
                    $statement .= $char;
                    if ($char === '\\' && $quote !== '`') {
                        $statement .= $line[$i + 1] ?? '';
                        $i++;
                    } elseif ($char === $quote) {
                        $quote = null;
                    }
                    continue;
                }

                if ($char === '\'' || $char === '"' || $char === '`') {
                    $quote = $char;
                    $statement .= $char;
                    continue;
                }

                if ($char === '/' && ($line[$i + 1] ?? '') === '*') {
                    $blockComment = true;
                    $statement .= '/*';
                    $i++;
                    continue;
                }

                if ($char === ';') {
                    // The rest of this line belongs to the next statement;
                    // the dump writer never puts two on one line, so rewind
                    // the offset to just after the semicolon.
                    $rest = substr($line, $i + 1);
                    if (trim($rest) !== '') {
                        $this->offset -= strlen($rest);
                        gzseek($this->handle, $this->offset);
                    }

                    return trim($statement);
                }

                $statement .= $char;
            }
        }

        if (trim($statement) !== '') {
            throw new UpdateException('update.error.backup_unreadable', [], 'The dump ends inside a statement');
        }

        return null;
    }
}
