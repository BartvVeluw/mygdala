<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Font files for the Font Library's tests, built from bytes here rather than
 * committed: a real font is somebody's work under somebody's licence, and
 * this repository ships none (THEMING.md, "Font Library").
 *
 * The files are STRUCTURALLY what App\Service\Theme\FontFileInspector reads:
 * an sfnt table directory with the tables a font cannot do without, the
 * `head` table's magic number, WOFF's header with zlib-compressed tables, and
 * WOFF2's header and variable-length table directory followed by a stream of
 * the declared length. They are not renderable glyphs; the browser tests use
 * real fonts made on the spot and deleted afterwards.
 *
 * The broken() variants each break exactly one rule, so a test can name the
 * rule it proves.
 */
final class FontFileFixture
{
    private const HEAD_MAGIC = 0x5F0F3CF5;

    /** A TrueType-flavoured sfnt (.ttf). */
    public static function ttf(): string
    {
        return self::sfnt("\x00\x01\x00\x00", self::tables(false));
    }

    /** A CFF-flavoured sfnt (.otf). */
    public static function otf(): string
    {
        return self::sfnt('OTTO', self::tables(true));
    }

    public static function woff(): string
    {
        return self::woffOf("\x00\x01\x00\x00", self::tables(false));
    }

    public static function woff2(): string
    {
        return self::woff2Of("\x00\x01\x00\x00", array_keys(self::tables(false)));
    }

    /** A TrueType collection: a font file, but not one a web page can use. */
    public static function ttc(): string
    {
        return 'ttcf' . pack('nnN', 1, 0, 1) . pack('N', 16) . self::ttf();
    }

    /** A PNG's first bytes: an image with a font's name. */
    public static function png(): string
    {
        return "\x89PNG\r\n\x1A\n" . pack('N', 13) . 'IHDR' . pack('NNCCCCC', 1, 1, 8, 6, 0, 0, 0) . str_repeat("\x00", 64);
    }

    /**
     * One rule broken per name:
     *   truncated         the table directory runs past the end of the file
     *   outside           a table record points past the end of the file
     *   no_cmap           a required table is missing
     *   head_magic        the head table's magic number is wrong
     *   woff_length       WOFF's declared length differs from the file
     *   woff2_base128     a WOFF2 length with a leading zero byte
     *   woff2_stream      WOFF2's compressed stream is shorter than declared
     */
    public static function broken(string $rule): string
    {
        switch ($rule) {
            case 'truncated':
                return substr(self::ttf(), 0, 40);
            case 'outside':
                $bytes = self::ttf();
                // First record's length field: far past the end.
                return substr_replace($bytes, pack('N', 0x00FFFFFF), 12 + 12, 4);
            case 'no_cmap':
                $tables = self::tables(false);
                unset($tables['cmap']);
                return self::sfnt("\x00\x01\x00\x00", $tables);
            case 'head_magic':
                $tables = self::tables(false);
                $tables['head'] = substr_replace($tables['head'], pack('N', 0x12345678), 12, 4);
                return self::sfnt("\x00\x01\x00\x00", $tables);
            case 'woff_length':
                $bytes = self::woff();
                return substr_replace($bytes, pack('N', strlen($bytes) + 10), 8, 4);
            case 'woff2_base128':
                $bytes = self::woff2();
                // The first table's origLength starts right after its flags byte.
                return substr_replace($bytes, "\x80", 49, 1);
            case 'woff2_stream':
                $bytes = self::woff2();
                return substr_replace($bytes, pack('N', 100000), 20, 4);
        }

        throw new \InvalidArgumentException('Unknown rule: ' . $rule);
    }

    /** Writes bytes to a temporary file and returns its path; the caller deletes it. */
    public static function file(string $bytes): string
    {
        $path = tempnam(sys_get_temp_dir(), 'font');
        file_put_contents($path, $bytes);

        return $path;
    }

    /**
     * One entry of $_FILES for bytes written to a temporary file.
     *
     * @return array{name: string, type: string, tmp_name: string, error: int, size: int}
     */
    public static function upload(string $name, string $bytes, string $type = 'application/octet-stream'): array
    {
        $path = self::file($bytes);

        return ['name' => $name, 'type' => $type, 'tmp_name' => $path, 'error' => UPLOAD_ERR_OK, 'size' => strlen($bytes)];
    }

    /** @return array<string, string> tag => table bytes, in tag order */
    private static function tables(bool $cff): array
    {
        $head = pack('nnNNN', 1, 0, 0x00010000, 0, self::HEAD_MAGIC)
            . pack('nn', 0x000B, 1000)
            . str_repeat("\x00", 16)
            . pack('nnnn', 0, 0, 1000, 1000)
            . pack('nnnnn', 0, 8, 2, 0, 0);

        $tables = [
            'OS/2' => pack('n', 4) . str_repeat("\x00", 94),
            'cmap' => pack('nn', 0, 0),
            'head' => $head,
            'hhea' => pack('nn', 1, 0) . str_repeat("\x00", 30) . pack('n', 1),
            'hmtx' => pack('nn', 500, 0),
            'maxp' => pack('Nn', 0x00005000, 1),
            'name' => pack('nnn', 0, 0, 6),
            'post' => pack('N', 0x00030000) . str_repeat("\x00", 28),
        ];

        if ($cff) {
            $tables['CFF '] = "\x01\x00\x04\x01" . str_repeat("\x00", 12);
        } else {
            $tables['glyf'] = str_repeat("\x00", 12);
            $tables['loca'] = pack('nn', 0, 6);
        }

        ksort($tables, SORT_STRING);

        return $tables;
    }

    /** @param array<string, string> $tables */
    private static function sfnt(string $version, array $tables): string
    {
        $count = count($tables);
        $offset = 12 + 16 * $count;
        $directory = '';
        $data = '';

        foreach ($tables as $tag => $bytes) {
            $directory .= $tag . pack('NNN', 0, $offset + strlen($data), strlen($bytes));
            $data .= $bytes . str_repeat("\x00", (4 - strlen($bytes) % 4) % 4);
        }

        return $version . pack('nnnn', $count, 0, 0, 0) . $directory . $data;
    }

    /** @param array<string, string> $tables */
    private static function woffOf(string $flavor, array $tables): string
    {
        $count = count($tables);
        $offset = 44 + 20 * $count;
        $directory = '';
        $data = '';
        $sfntSize = 12 + 16 * $count;

        foreach ($tables as $tag => $bytes) {
            $compressed = gzcompress($bytes);
            if ($compressed === false || strlen($compressed) >= strlen($bytes)) {
                $compressed = $bytes;
            }

            $directory .= $tag . pack('NNNN', $offset + strlen($data), strlen($compressed), strlen($bytes), 0);
            $data .= $compressed . str_repeat("\x00", (4 - strlen($compressed) % 4) % 4);
            $sfntSize += strlen($bytes) + (4 - strlen($bytes) % 4) % 4;
        }

        $length = $offset + strlen($data);

        return 'wOFF' . $flavor . pack('NnnN', $length, $count, 0, $sfntSize)
            . pack('nnNNNNN', 1, 0, 0, 0, 0, 0, 0)
            . $directory . $data;
    }

    /** @param list<string> $tags */
    private static function woff2Of(string $flavor, array $tags): string
    {
        $known = ['cmap' => 0, 'head' => 1, 'hhea' => 2, 'hmtx' => 3, 'maxp' => 4, 'name' => 5, 'OS/2' => 6, 'post' => 7, 'glyf' => 10, 'loca' => 11, 'CFF ' => 13];
        $directory = '';
        foreach ($tags as $tag) {
            // Transform 3 for glyf/loca ("none"), 0 for the rest: no
            // transform length in either case.
            $transform = in_array($tag, ['glyf', 'loca'], true) ? 3 << 6 : 0;
            $directory .= chr(($known[$tag] ?? 63) | $transform) . (isset($known[$tag]) ? '' : $tag) . "\x20";
        }

        $stream = str_repeat("\x1B", 24);
        $length = 48 + strlen($directory) + strlen($stream);

        return 'wOF2' . $flavor . pack('NnnNN', $length, count($tags), 0, 12 + 16 * count($tags) + 32 * count($tags), strlen($stream))
            . pack('nnNNNNN', 1, 0, 0, 0, 0, 0, 0)
            . $directory . $stream;
    }
}
