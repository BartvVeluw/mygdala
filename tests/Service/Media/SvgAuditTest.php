<?php

declare(strict_types=1);

namespace Tests\Service\Media;

use App\Repository\MediaRepository;
use App\Service\Media\MediaService;
use App\Service\Media\SvgAudit;
use App\Service\Media\SvgSanitizer;
use PHPUnit\Framework\TestCase;

/**
 * App\Service\Media\SvgAudit: every stored SVG judged by the one sanitizer,
 * and never changed. The files live in a temporary root of this test's own;
 * the one media row it adds is removed in tearDown().
 */
final class SvgAuditTest extends TestCase
{
    private string $root = '';

    private int $mediaId = 0;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/svg-audit-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/assets/media', 0777, true);
        mkdir($this->root . '/assets/images/branding', 0777, true);
    }

    protected function tearDown(): void
    {
        if ($this->mediaId > 0) {
            (new MediaRepository())->delete($this->mediaId);
            MediaService::clearCache();
        }

        foreach (['assets/media/zz-safe.svg', 'assets/images/branding/zz-evil.svg', 'assets/media/zz-note.txt'] as $file) {
            @unlink($this->root . '/' . $file);
        }
        @rmdir($this->root . '/assets/images/branding');
        @rmdir($this->root . '/assets/images');
        @rmdir($this->root . '/assets/media');
        @rmdir($this->root . '/assets');
        @rmdir($this->root);
    }

    public function testEveryStoredSvgIsJudgedAndNoneIsChanged(): void
    {
        $safe = '<?xml version="1.0"?><!-- Inkscape --><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 4 4"><rect width="4" height="4"/></svg>';
        $evil = '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(document.cookie)"><rect width="4" height="4"/></svg>';
        file_put_contents($this->root . '/assets/media/zz-safe.svg', $safe);
        file_put_contents($this->root . '/assets/images/branding/zz-evil.svg', $evil);
        file_put_contents($this->root . '/assets/media/zz-note.txt', 'not an svg');

        $this->mediaId = (new MediaRepository())->create(['path' => 'assets/media/zz-gone.svg', 'mime_type' => 'image/svg+xml']);

        $report = array_column(SvgAudit::run($this->root), null, 'path');

        self::assertSame('ok', $report['assets/media/zz-safe.svg']['verdict']);
        self::assertTrue($report['assets/media/zz-safe.svg']['rebuilt_differs'], 'the comment would go; that is reported, not done');
        self::assertSame('refused', $report['assets/images/branding/zz-evil.svg']['verdict']);
        self::assertSame(SvgSanitizer::REASON_EVENT, $report['assets/images/branding/zz-evil.svg']['reason']);
        self::assertSame('missing', $report['assets/media/zz-gone.svg']['verdict']);
        self::assertSame(['media #' . $this->mediaId], $report['assets/media/zz-gone.svg']['sources']);
        self::assertArrayNotHasKey('assets/media/zz-note.txt', $report);

        self::assertSame($safe, file_get_contents($this->root . '/assets/media/zz-safe.svg'), 'the audit changes nothing');
        self::assertSame($evil, file_get_contents($this->root . '/assets/images/branding/zz-evil.svg'), 'a refused file is reported, never rewritten');
    }
}
