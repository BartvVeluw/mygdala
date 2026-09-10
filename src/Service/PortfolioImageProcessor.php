<?php

namespace App\Service;

/**
 * Portfolio-only wrapper around App\Service\SectionImageUploader that adds
 * automatic optimization (App\Service\ImageOptimizer) and a matching CMS
 * thumbnail to every NEW upload. SectionImageUploader itself is completely
 * unchanged and still does all the actual security work (magic-byte type
 * check, random filename, 25 MB cap, safe delete) — this class only adds a
 * post-processing step on top, scoped to Portfolio uploads (main image +
 * Projectafbeeldingen) per the approved scope. Other SectionImageUploader
 * callers (e.g. Text + image split) are unaffected by this class existing.
 *
 * Existing images are never touched here — store() only ever processes a
 * brand new upload that SectionImageUploader has just safely stored.
 */
class PortfolioImageProcessor
{
    private const THUMBNAIL_SUBDIR = 'thumbs/';
    public const THUMBNAIL_PUBLIC_PREFIX = 'assets/images/sections/thumbs/';

    private SectionImageUploader $uploader;
    private string $thumbnailDir;

    public function __construct()
    {
        $this->uploader = new SectionImageUploader();
        $this->thumbnailDir = dirname(__DIR__, 2) . '/assets/images/sections/' . self::THUMBNAIL_SUBDIR;

        if (!is_dir($this->thumbnailDir)) {
            mkdir($this->thumbnailDir, 0755, true);
        }
    }

    /**
     * Validates and stores the upload exactly like SectionImageUploader
     * (same errors/limits), restricts the result to JPEG/PNG/WebP, then
     * optimizes it in place (same path, resized/re-encoded bytes) and
     * generates a thumbnail alongside it.
     *
     * @param array{name?:string,type?:string,tmp_name?:string,error?:int,size?:int} $file
     * @return array{path:string, thumbnail_path:string} thumbnail_path is always set on success — every supported format is optimized, there's no partial/passthrough success case
     * @throws \RuntimeException with a Dutch, user-facing message on any validation/format/optimization failure
     */
    public function store(array $file): array
    {
        $path = $this->uploader->store($file);
        $absolutePath = $this->absolutePath($path);

        $info = @getimagesize($absolutePath);
        $imageType = $info[2] ?? null;

        if ($imageType === null || !ImageOptimizer::isSupported($imageType)) {
            // New Portfolio uploads are restricted to JPEG/PNG/WebP (see
            // MAIN.MD) — SectionImageUploader's own magic-byte check already
            // rejects anything that isn't a real, recognized image, so in
            // practice only GIF can still reach this branch. Rejected
            // outright (not silently kept unoptimized): GD would flatten an
            // animated GIF to a single frame, which isn't what an admin
            // uploading one would expect, and this class's whole point is
            // an optimized result, not an unprocessed passthrough. This
            // only affects new uploads — any Portfolio image already
            // stored before this restriction (of any format) is never
            // touched, read, or removed by this check.
            $this->uploader->delete($path);

            throw new \RuntimeException('Alleen JPG, PNG of WEBP afbeeldingen worden ondersteund voor Portfolio-uploads.');
        }

        try {
            $processed = ImageOptimizer::process($absolutePath, $imageType);
        } catch (\RuntimeException $e) {
            // ImageOptimizer's own failures are the safety boundary against
            // decompression bombs (a tiny file whose header claims extreme
            // pixel dimensions) and undecodable/corrupt content that slipped
            // past getimagesize()'s lighter check above — these must be
            // rejected outright, not silently stored unoptimized. Clean up
            // the already-stored original and re-throw with the same
            // Dutch, user-facing message SectionImageUploader itself uses,
            // so the caller's existing RuntimeException handling (session
            // flash / JSON error, no DB row) applies unchanged.
            $this->uploader->delete($path);

            throw $e;
        }

        file_put_contents($absolutePath, $processed['full']['bytes']);
        chmod($absolutePath, 0644);

        $thumbnailFilename = basename($path);
        $thumbnailAbsolutePath = $this->thumbnailDir . $thumbnailFilename;
        file_put_contents($thumbnailAbsolutePath, $processed['thumbnail']['bytes']);
        chmod($thumbnailAbsolutePath, 0644);

        return [
            'path' => $path,
            'thumbnail_path' => self::THUMBNAIL_PUBLIC_PREFIX . $thumbnailFilename,
        ];
    }

    /**
     * Deletes a Portfolio image and its thumbnail (if any). Safe no-op for
     * anything outside assets/images/sections/ (main image, via
     * SectionImageUploader::delete()) or assets/images/sections/thumbs/
     * (thumbnail, checked here) — a shared/default site image can never be
     * removed by this method.
     */
    public function delete(?string $path, ?string $thumbnailPath): void
    {
        $this->uploader->delete($path);

        if ($thumbnailPath === null || !str_starts_with($thumbnailPath, self::THUMBNAIL_PUBLIC_PREFIX)) {
            return;
        }

        // basename() strips any directory traversal component, so this can
        // only ever resolve to a file directly inside thumbnailDir.
        $file = $this->thumbnailDir . basename($thumbnailPath);

        if (is_file($file)) {
            @unlink($file);
        }
    }

    private function absolutePath(string $relativePath): string
    {
        return dirname(__DIR__, 2) . '/' . $relativePath;
    }
}
