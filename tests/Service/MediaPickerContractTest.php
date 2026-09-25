<?php

declare(strict_types=1);

namespace Tests\Service;

use PHPUnit\Framework\TestCase;

/**
 * The Media picker's contract (MEDIA.md, "De mediakiezer"), read from the
 * source: ONE USER ACTION, ONE FLOW.
 *
 *   - "Kies uit mediabibliotheek" opens the library and nothing else: the
 *     open handler cancels the click's default, opens only a closed modal,
 *     and the script binds itself once per page;
 *   - the operating system's file dialog has exactly one door: the modal's
 *     own upload button, whose handler is the only code that calls click()
 *     on the file input — an input that is hidden and in no <label>;
 *   - a choice reaches the editor only through "Selecteren"; closing
 *     (Annuleren, Escape, ×, backdrop) writes nothing;
 *   - regular site images have no file input of their own anywhere in the
 *     CMS: the closed list at the bottom names every screen that still has
 *     one, and why.
 *
 * No database and no browser, so it runs in the fast tier. What a real click
 * does is proven in the browser (MEDIA.md, "Handmatig controleren").
 */
final class MediaPickerContractTest extends TestCase
{
    /**
     * Every CMS file that renders a file control of its own, and why that
     * file is not a regular site image chosen through the library. A new
     * entry needs a reason; a regular image field belongs on the picker.
     */
    private const OWN_FILE_CONTROLS = [
        'admin/_admin_ui.php' => 'the shared file control itself (admin_file_input())',
        'admin/_media_picker.php' => 'the picker modal\'s own upload, behind its "Nieuw bestand uploaden" button',
        'admin/media.php' => 'the library\'s own upload queue: this IS the Media Library',
        'admin/personalization-fonts.php' => 'a font file for the personalisation module, not an image',
        'admin/_personalization_builder.php' => 'a configurator view\'s preview canvas: its coordinates belong to that one image (MEDIA.md, "Nog op een eigen pad")',
    ];

    public function testOpeningAPickerOpensTheLibraryAndNothingElse(): void
    {
        $script = self::source('admin/assets/media-picker.js');
        $handler = self::between($script, '// Delegated, so a field that arrives later works too', 'grid.addEventListener("click"');

        $this->assertStringContainsString('event.preventDefault();', $handler, 'no label activation, no submit on the same click');
        $this->assertStringContainsString("if (modal.hidden) {\n        openModal(field);", $handler, 'a second click cannot open it again');
        $this->assertStringNotContainsString('.click()', $handler);

        $this->assertStringContainsString('modal.hasAttribute("data-media-picker-ready")', $script, 'bound once per page');
        $this->assertStringContainsString('modal.setAttribute("data-media-picker-ready", "")', $script);
    }

    public function testTheFileDialogHasExactlyOneDoor(): void
    {
        $script = self::source('admin/assets/media-picker.js');

        $this->assertSame(1, substr_count($script, '.click()'), 'one programmatic click in the whole picker');
        $this->assertMatchesRegularExpression('/function openFileDialog\(\) \{\s*uploadInput\.value = "";\s*uploadInput\.click\(\);\s*\}/', $script);
        $this->assertSame(1, substr_count($script, 'function openFileDialog()'), 'defined once');
        $this->assertSame(1, substr_count($script, ', openFileDialog)'), 'bound once');
        $this->assertSame(0, substr_count($script, 'openFileDialog();'), 'and never called by anything but that binding');
        $this->assertStringContainsString('uploadButton.addEventListener("click", openFileDialog);', $script);

        $modal = self::between(self::source('admin/_media_picker.php'), 'function media_picker_modal(): void', 'data-media-picker-config');
        // To the end of the line: the tag holds a PHP block, whose closing
        // tag would end a [^>]* match early.
        preg_match('/<input type="file".*$/m', $modal, $input);
        $this->assertNotEmpty($input, 'the modal has its upload input');
        $this->assertStringContainsString(' hidden', $input[0], 'the input itself is never a visible target');
        $this->assertStringContainsString('data-media-modal-upload', $input[0]);

        $before = substr($modal, 0, (int) strpos($modal, $input[0]));
        $this->assertSame(
            substr_count($before, '<label '),
            substr_count($before, '</label>'),
            'the file input sits in no <label>, so no click on a word can reach it'
        );
        $this->assertStringContainsString('data-media-modal-upload-button', $modal);
    }

    public function testOnlySelecterenHandsAChoiceToTheEditor(): void
    {
        $script = self::source('admin/assets/media-picker.js');

        $this->assertStringContainsString('confirmButton.addEventListener("click", confirmSelection);', $script);
        $this->assertSame(2, substr_count($script, 'applyToField('), 'defined once, called once');
        $confirm = self::between($script, 'function confirmSelection()', 'function linkedAlt(');
        $this->assertStringContainsString('applyToField(field, items[0]);', $confirm);
        $this->assertStringContainsString('new CustomEvent("media-picker:choose"', $confirm);

        $close = self::between($script, 'function closeModal()', '// --- Listing');
        foreach (['input.value', 'applyToField', 'media-picker:choose', 'fillAlt'] as $write) {
            $this->assertStringNotContainsString($write, $close, 'closing writes nothing: ' . $write);
        }

        // An upload selects the new item; it does not choose it for the field.
        $upload = self::between($script, 'function uploadAll(files)', '// --- Wiring');
        $this->assertStringContainsString('toggle(item, true);', $upload);
        $this->assertStringNotContainsString('applyToField', $upload);
        $this->assertStringNotContainsString('media-picker:choose', $upload);
    }

    public function testTheSelectionSurvivesAnotherFolderASearchAndMoreResults(): void
    {
        $script = self::source('admin/assets/media-picker.js');

        $this->assertStringContainsString('var selectedIds = [];', $script);
        $load = self::between($script, 'function load(page, replace)', 'function videoIcon()');
        $this->assertStringNotContainsString('selectedIds = []', $load, 'loading a folder or a search never clears the selection');
        $this->assertStringContainsString('"&folder=" + encodeURIComponent(currentFolder)', $load);
        $this->assertStringContainsString('markCard(button);', $script, 'a card is drawn selected wherever it reappears');
        $this->assertStringContainsString('body.append("folder_id"', $script, 'an upload is filed under the folder the picker shows');
    }

    public function testThePickerSaysNothingOfItsOwnAndWritesNoMarkupFromStrings(): void
    {
        $script = self::source('admin/assets/media-picker.js');

        $this->assertStringNotContainsString('innerHTML', $script);
        $this->assertStringNotContainsString('insertAdjacentHTML', $script);
        $this->assertDoesNotMatchRegularExpression('/"(Laden|Uploaden|Geen resultaten|De mediabibliotheek)/', $script, 'every word comes from the catalog');
    }

    public function testEveryScreenWithAPickerPrintsTheModalAndLoadsTheScriptOnce(): void
    {
        foreach ((array) glob(dirname(__DIR__, 2) . '/admin/*.php') as $file) {
            $relative = 'admin/' . basename($file);
            $source = (string) file_get_contents($file);

            if (str_starts_with(basename($file), '_') || !str_contains($source, 'media_picker_field(')) {
                continue;
            }

            $this->assertSame(1, substr_count($source, 'media_picker_modal();'), $relative . ' prints the modal once');
            $this->assertSame(
                1,
                substr_count($source, 'media_picker_script();') + substr_count($source, "/admin/assets/media-picker.js'"),
                $relative . ' loads the picker once'
            );
        }
    }

    /**
     * Regular site images are chosen through the library, never through a
     * file input of the editor's own (Media Library 2.0): the closed list.
     */
    public function testNoRegularImageFieldHasAFileInputOfItsOwn(): void
    {
        $root = dirname(__DIR__, 2);
        $found = [];

        foreach (array_merge((array) glob($root . '/admin/*.php'), (array) glob($root . '/admin/*/*.php')) as $file) {
            $source = (string) file_get_contents($file);

            if (preg_match('/type="file"|admin_file_input\(/', $source) === 1) {
                $found[] = substr($file, strlen($root) + 1);
            }
        }

        sort($found);
        $allowed = array_keys(self::OWN_FILE_CONTROLS);
        sort($allowed);

        $this->assertSame($allowed, $found, 'a new file control in the CMS: choose the image through the library, or add it here with its reason');

        foreach (['admin/product-form.php', 'admin/collection.php', 'admin/portfolio-item.php'] as $screen) {
            $this->assertStringContainsString('media_picker_field(', self::source($screen), $screen . ' chooses its images through the library');
        }
    }

    /* ------------------------------------------------------------------ */

    private static function between(string $source, string $from, string $to): string
    {
        $start = strpos($source, $from);
        self::assertNotFalse($start, 'missing: ' . $from);
        $end = strpos($source, $to, $start + strlen($from));
        self::assertNotFalse($end, 'missing: ' . $to);

        return substr($source, $start, $end - $start);
    }

    private static function source(string $relative): string
    {
        $path = dirname(__DIR__, 2) . '/' . $relative;
        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }
}
