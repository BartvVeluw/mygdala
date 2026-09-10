<?php

namespace App\Service;

/**
 * Validates and stores uploaded videos for CMS page sections that support a
 * video media type (currently only the Homepage Hero — see
 * App\Service\HomepageHeroContent). Same conventions as
 * App\Service\SectionImageUploader (random safe filename, magic-byte type
 * check — never the client-supplied extension/MIME type, chmod 0644) but its
 * own upload folder (assets/videos/sections/) and its own, much larger size
 * cap, since video files are inherently bigger than the images the sibling
 * uploader handles.
 *
 * Only MP4 and WEBM are accepted, detected purely from the file's own bytes:
 * an MP4/ISO-BMFF file has an `ftyp` box starting at byte offset 4; a WEBM
 * (Matroska/EBML container) starts with the fixed 4-byte EBML magic number.
 * Anything else (including a renamed non-video file, or a QuickTime .mov
 * that happens to share the ISO-BMFF container but isn't broadly
 * browser-playable) is rejected.
 */
class SectionVideoUploader
{
    private const MAX_BYTES = 30 * 1024 * 1024; // 30 MB — a hero clip should be short and web-optimized

    private const PUBLIC_PREFIX = 'assets/videos/sections/';

    private string $uploadDir;

    public function __construct()
    {
        $this->uploadDir = dirname(__DIR__, 2) . '/assets/videos/sections/';

        if (!is_dir($this->uploadDir)) {
            mkdir($this->uploadDir, 0755, true);
        }
    }

    /**
     * @param array{name?:string,type?:string,tmp_name?:string,error?:int,size?:int} $file one entry of $_FILES
     * @return string the video_path to store (relative, web-servable)
     * @throws \RuntimeException with a Dutch, user-facing message on invalid input
     */
    public function store(array $file): string
    {
        $error = $file['error'] ?? UPLOAD_ERR_NO_FILE;

        if ($error === UPLOAD_ERR_NO_FILE) {
            throw new \RuntimeException('Geen bestand geselecteerd.');
        }

        if ($error !== UPLOAD_ERR_OK) {
            throw new \RuntimeException('Uploaden van de video is mislukt. Probeer het opnieuw.');
        }

        $tmpName = $file['tmp_name'] ?? '';

        if ($tmpName === '' || !is_uploaded_file($tmpName)) {
            throw new \RuntimeException('Ongeldige upload.');
        }

        if ((int) ($file['size'] ?? 0) > self::MAX_BYTES) {
            throw new \RuntimeException('Video is te groot (max. 30 MB) — comprimeer de video of gebruik een kortere clip.');
        }

        $handle = fopen($tmpName, 'rb');
        $header = $handle !== false ? fread($handle, 12) : false;
        if ($handle !== false) {
            fclose($handle);
        }

        $extension = is_string($header) ? self::detectExtension($header) : null;

        if ($extension === null) {
            throw new \RuntimeException('Alleen MP4 of WEBM video\'s zijn toegestaan.');
        }

        $filename = bin2hex(random_bytes(16)) . '.' . $extension;
        $destination = $this->uploadDir . $filename;

        if (!move_uploaded_file($tmpName, $destination)) {
            throw new \RuntimeException('Video kon niet worden opgeslagen.');
        }

        chmod($destination, 0644);

        return self::PUBLIC_PREFIX . $filename;
    }

    /**
     * Deletes a previously admin-uploaded section video, but only when it
     * actually lives in the sections upload folder — same
     * never-touch-anything-else guarantee as
     * SectionImageUploader::delete().
     */
    public function delete(?string $videoPath): void
    {
        if ($videoPath === null || $videoPath === '' || !str_starts_with($videoPath, self::PUBLIC_PREFIX)) {
            return;
        }

        // basename() strips any directory traversal component, so this can
        // only ever resolve to a file directly inside uploadDir.
        $path = $this->uploadDir . basename($videoPath);

        if (is_file($path)) {
            @unlink($path);
        }
    }

    /**
     * Reads the first bytes of a file (already known to be at least
     * non-empty) and returns 'mp4'/'webm' when they match that format's
     * magic bytes, or null when neither matches.
     */
    private static function detectExtension(string $header): ?string
    {
        // WEBM/Matroska: fixed 4-byte EBML magic number.
        if (strncmp($header, "\x1A\x45\xDF\xA3", 4) === 0) {
            return 'webm';
        }

        // MP4/ISO-BMFF: a box of some size, then the 4-byte ASCII type
        // 'ftyp', at a fixed offset — the size bytes themselves vary and are
        // deliberately not checked here.
        if (strlen($header) >= 8 && substr($header, 4, 4) === 'ftyp') {
            return 'mp4';
        }

        return null;
    }
}
