<?php

declare(strict_types=1);

namespace App\Update;

/**
 * Release notes from the release host (ReleaseHistory) as safe HTML for the
 * Updates screen. The notes are Markdown written on the release page, and
 * they arrive unsigned, so this is a deliberately SMALL renderer that
 * escapes first and formats second:
 *
 *   - every line goes through htmlspecialchars() before any pattern looks
 *     at it, so an HTML tag in the notes is shown as text, never parsed;
 *   - the only markup it ever writes is its own closed list: h3–h5 for
 *     `#`–`###` (the screen's card already has its h2), ul/ol/li, p, strong,
 *     em, code, and a link — the last only for an https:// address, opened
 *     in a new tab with rel="noopener noreferrer";
 *   - nothing else of Markdown is supported (no images, tables, raw HTML or
 *     nested lists); an unknown construct stays readable plain text.
 *
 * There is no Markdown library in the project and no other Markdown on the
 * site, so this is not a general renderer and should not become one: rich
 * content for the public site goes through RichTextSanitizer.
 */
final class ReleaseNotesMarkdown
{
    public static function toHtml(string $markdown): string
    {
        $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $markdown));
        $html = [];
        $paragraph = [];
        $list = null; // 'ul' | 'ol' | null

        $flushParagraph = static function () use (&$paragraph, &$html): void {
            if ($paragraph !== []) {
                $html[] = '<p>' . implode('<br>', $paragraph) . '</p>';
                $paragraph = [];
            }
        };
        $closeList = static function () use (&$list, &$html): void {
            if ($list !== null) {
                $html[] = '</' . $list . '>';
                $list = null;
            }
        };

        foreach ($lines as $line) {
            $trimmed = trim($line);

            if ($trimmed === '') {
                $flushParagraph();
                $closeList();
                continue;
            }

            if (preg_match('/\A(#{1,6})\s+(.+?)\s*#*\z/u', $trimmed, $m) === 1) {
                $flushParagraph();
                $closeList();
                $level = min(5, strlen($m[1]) + 2);
                $html[] = '<h' . $level . '>' . self::inline($m[2]) . '</h' . $level . '>';
                continue;
            }

            if (preg_match('/\A(?:[-*+]|(\d{1,3})[.)])\s+(.+)\z/u', $trimmed, $m) === 1) {
                $flushParagraph();
                $kind = $m[1] !== '' ? 'ol' : 'ul';
                if ($list !== $kind) {
                    $closeList();
                    $html[] = '<' . $kind . '>';
                    $list = $kind;
                }
                $html[] = '<li>' . self::inline($m[2]) . '</li>';
                continue;
            }

            if (preg_match('/\A(?:-{3,}|\*{3,}|_{3,})\z/', $trimmed) === 1) {
                $flushParagraph();
                $closeList();
                continue;
            }

            $closeList();
            $paragraph[] = self::inline($trimmed);
        }

        $flushParagraph();
        $closeList();

        return implode("\n", $html);
    }

    /**
     * One line's inline formatting, on text that is escaped FIRST. Code spans
     * are taken out before the other patterns run, so `**` inside code stays
     * literal.
     */
    private static function inline(string $text): string
    {
        $escaped = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $codes = [];
        $escaped = (string) preg_replace_callback('/`([^`]+)`/', static function (array $m) use (&$codes): string {
            $codes[] = '<code>' . $m[1] . '</code>';

            return "\x00" . (count($codes) - 1) . "\x00";
        }, $escaped);

        // [text](https://…) — the address is already escaped, and only https
        // becomes a link; anything else stays the text it was.
        $escaped = (string) preg_replace_callback(
            '/\[([^\]]+)\]\((https:\/\/[^\s()]+)\)/u',
            static fn (array $m): string => '<a href="' . $m[2] . '" target="_blank" rel="noopener noreferrer">' . $m[1] . '</a>',
            $escaped
        );

        $escaped = (string) preg_replace('/\*\*(.+?)\*\*/u', '<strong>$1</strong>', $escaped);
        $escaped = (string) preg_replace('/(?<![\w*])\*(?!\s)(.+?)(?<!\s)\*(?![\w*])/u', '<em>$1</em>', $escaped);

        return (string) preg_replace_callback("/\x00(\\d+)\x00/", static fn (array $m): string => $codes[(int) $m[1]], $escaped);
    }
}
