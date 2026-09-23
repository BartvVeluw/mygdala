<?php

declare(strict_types=1);

namespace App\Service\Media;

use App\Service\Language\AdminTranslator;

/**
 * An SVG that App\Service\Media\SvgSanitizer will not store, and why. The
 * reason is one of its REASON_* words; the message is the sentence an editor
 * reads, so the uploader can pass it on like any other refusal.
 */
final class SvgRefused extends \RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct(AdminTranslator::trans('media.upload.svg_refused', [
            'reason' => AdminTranslator::trans('media.upload.svg_reason.' . $reason),
        ]));
    }
}
