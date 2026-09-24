<?php

declare(strict_types=1);

namespace Tests\Service\Media;

use PHPUnit\Framework\TestCase;

/**
 * Every route in this project that stores an uploaded file, and why none of
 * them can store an SVG except through App\Service\Media\SvgSanitizer
 * (MEDIA.md, "SVG"). No database, no files of the site: it reads the source.
 *
 * THE CLOSED LIST. A class that moves an uploaded file into place is on
 * STORING_CLASSES with the rule that decides its type. A new one fails the
 * first test until somebody has looked at it and added it here — which is
 * the moment to ask whether it can store an SVG.
 *
 * The rule for all of them but the Media Library: the type is decided by
 * getimagesize() against a list of IMAGETYPE_* raster types (or by a PDF or
 * font signature), and getimagesize() never recognises an SVG. So an SVG is
 * refused on its content there, whatever it is called. The logo, the second
 * logo, the favicon and the share images are no upload of their own: they are
 * Media Library items chosen with the picker.
 */
final class SvgUploadRoutesTest extends TestCase
{
    /** Class file => how it decides what a file is. */
    private const STORING_CLASSES = [
        'src/Service/Media/MediaUploader.php' => 'media',
        'src/Service/SectionImageUploader.php' => 'raster',
        'src/Service/ProductImageUploader.php' => 'raster',
        'src/Service/Personalization/PersonalizationPreviewImageUploader.php' => 'raster',
        'src/Service/Personalization/PersonalizationUploadStorage.php' => 'validated',
        'src/Service/Personalization/PersonalizationFontUploader.php' => 'font',
        'src/Service/SectionVideoUploader.php' => 'video',
        'src/Service/ContactAttachmentStorage.php' => 'validated',
    ];

    public function testEveryClassThatStoresAnUploadIsOnTheList(): void
    {
        $root = dirname(__DIR__, 3);
        $found = [];

        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/src', \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ($file->getExtension() === 'php' && str_contains((string) file_get_contents($file->getPathname()), 'move_uploaded_file(')) {
                $found[] = substr(str_replace('\\', '/', $file->getPathname()), strlen($root) + 1);
            }
        }
        sort($found);

        $listed = array_keys(self::STORING_CLASSES);
        sort($listed);

        self::assertSame($listed, $found, 'a new class that stores uploads must be reviewed for SVG and added here');
    }

    public function testNoRasterUploaderCanTakeAnSvg(): void
    {
        $root = dirname(__DIR__, 3);

        foreach (self::STORING_CLASSES as $file => $rule) {
            if ($rule !== 'raster') {
                continue;
            }
            $source = (string) file_get_contents($root . '/' . $file);
            self::assertStringContainsString('getimagesize(', $source, $file);
            self::assertStringNotContainsStringIgnoringCase('svg', preg_replace('#/\*.*?\*/|//[^\n]*#s', '', $source) ?? '', $file . ' must not name SVG in its code');
        }

        // And the reason that is enough: getimagesize() does not recognise an SVG.
        $svg = tempnam(sys_get_temp_dir(), 'svgroute');
        file_put_contents($svg, '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"><script>alert(1)</script></svg>');
        try {
            self::assertFalse(@getimagesize($svg));
        } finally {
            unlink($svg);
        }
    }

    /**
     * The validators behind the two storage classes that do not look at
     * images themselves. A form upload (Forms 2.0 phase 2) is judged by
     * App\Service\Forms\FormUploadInspector against the closed list
     * App\Service\Forms\FormFileTypes, which has no SVG (FORMS.md says why).
     */
    public function testTheOtherStoringRoutesDecideByContentAndTakeNoSvg(): void
    {
        $root = dirname(__DIR__, 3);

        foreach (['src/Service/Forms/FormUploadInspector.php', 'src/Service/Personalization/PersonalizationUploadValidator.php'] as $file) {
            $source = (string) file_get_contents($root . '/' . $file);
            self::assertStringContainsString('getimagesize(', $source, $file);
        }

        self::assertStringContainsString("=== '%PDF-'", (string) file_get_contents($root . '/src/Service/Forms/FormUploadInspector.php'));
        foreach (\App\Service\Forms\FormFileTypes::keys() as $key) {
            self::assertNotContains('svg', \App\Service\Forms\FormFileTypes::extensions($key), $key);
            self::assertStringNotContainsString('svg', \App\Service\Forms\FormFileTypes::mime($key), $key);
        }
        self::assertSame(
            [IMAGETYPE_JPEG, IMAGETYPE_PNG],
            array_keys(\App\Service\Personalization\PersonalizationRules::ALLOWED_UPLOAD_TYPES),
            'a customer upload is a PNG or a JPEG'
        );
    }

    /**
     * The Media Library stores an SVG only as the sanitizer wrote it, and the
     * sanitizer is the one place in the project that parses one.
     */
    public function testTheMediaLibraryWritesOnlyWhatTheOneSanitizerRebuilt(): void
    {
        $root = dirname(__DIR__, 3);
        $uploader = (string) file_get_contents($root . '/src/Service/Media/MediaUploader.php');

        self::assertMatchesRegularExpression('/\$clean = SvgSanitizer::sanitize\(\$source\);.*?file_put_contents\(\$destination, \$clean\[\x27svg\x27\]\)/s', $uploader);

        $parsers = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/src', \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ($file->getExtension() === 'php' && preg_match('/->loadXML\(|simplexml_load/', (string) file_get_contents($file->getPathname())) === 1) {
                $parsers[] = substr(str_replace('\\', '/', $file->getPathname()), strlen($root) + 1);
            }
        }

        self::assertSame(['src/Service/Media/SvgSanitizer.php'], $parsers, 'one XML parser for uploaded content, no second sanitizer');
    }
}
