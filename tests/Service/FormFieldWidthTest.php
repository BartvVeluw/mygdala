<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Service\Forms\FormDefinition;
use App\Service\Forms\FormFieldTypes;
use App\Service\Forms\FormFieldWidth;
use App\Service\Forms\FormRenderState;
use App\Service\Routing\RequestLanguage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../partials/form.php';

/**
 * The width of a field (Forms 2.0 phase 1, FORMS.md "Breedte van een
 * veld"): a closed list of six shares, one fixed class per share, a
 * stylesheet that spans them on twelve columns and stacks them on a phone,
 * and the promise that no form stored before widths existed looks different.
 *
 * No database and no web server: the renderer is handed a definition built
 * in memory, the way the block library's sample form is built.
 */
final class FormFieldWidthTest extends TestCase
{
    private const FORM_CSS = __DIR__ . '/../../assets/css/blocks/form.css';

    private const CORE_CSS = __DIR__ . '/../../assets/css/core.css';

    protected function tearDown(): void
    {
        RequestLanguage::reset();
    }

    public function testTheListIsTheSixSharesWidestFirst(): void
    {
        $this->assertSame(['full', 'three_quarters', 'two_thirds', 'half', 'third', 'quarter'], FormFieldWidth::keys());
        $this->assertSame([12, 9, 8, 6, 4, 3], array_map([FormFieldWidth::class, 'span'], FormFieldWidth::keys()));
        $this->assertSame('full', FormFieldWidth::FULL);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function notAWidth(): iterable
    {
        yield 'empty' => [''];
        yield 'null' => [null];
        yield 'padded' => [' half'];
        yield 'capitals' => ['HALF'];
        yield 'a column count' => ['6'];
        yield 'an int' => [6];
        yield 'a percentage' => ['50%'];
        yield 'pixels' => ['320px'];
        yield 'a class' => ['form-field--half'];
        yield 'markup' => ['half"><script>alert(1)</script>'];
        yield 'css' => ['half; grid-column: 1 / 13'];
        yield 'an array' => [['half']];
    }

    #[DataProvider('notAWidth')]
    public function testAnythingElseIsNoWidthAndReadsAsFull(mixed $value): void
    {
        $this->assertFalse(FormFieldWidth::isValid($value));
        $this->assertSame('full', FormFieldWidth::fromStored($value));
    }

    /**
     * One fixed class per key and nothing a stored value can steer: an
     * unknown key is the full-width class fields have always had.
     */
    public function testEachWidthIsOneFixedClass(): void
    {
        $classes = array_map([FormFieldWidth::class, 'cssClass'], FormFieldWidth::keys());

        $this->assertSame([
            'form-field--full',
            'form-field--three-quarters',
            'form-field--two-thirds',
            'form-field--half',
            'form-field--third',
            'form-field--quarter',
        ], $classes);
        $this->assertSame('form-field--full', FormFieldWidth::cssClass('banana'));
    }

    /**
     * What the renderer did before widths existed, rebuilt from the types
     * themselves: a field whose label stands before its control was half a
     * row, unless it was a textarea or a dropdown. The migration that gave
     * every stored field a width wrote down exactly this.
     */
    public function testTheFormerDefaultIsWhatTheRendererUsedToDecide(): void
    {
        foreach (FormFieldTypes::all() as $key => $type) {
            // `file` did not exist before widths; its former default is the
            // one for every type the old renderer did not know: full.
            $wasFull = $type->labelPosition() !== 'before' || in_array($key, ['textarea', 'select', 'file'], true);

            $this->assertSame($wasFull ? 'full' : 'half', FormFieldWidth::formerDefaultFor($key), $key);
        }

        $this->assertSame('full', FormFieldWidth::formerDefaultFor('nonexistent'));
    }

    /**
     * The migration's list of half-width types is written out in SQL, so it
     * keeps meaning what it meant; this keeps it equal to the rule above.
     */
    public function testTheMigrationBackfillsTheFormerDefaults(): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../../db/migrations/20260924160000_give_form_fields_a_layout_width.php');

        $half = array_values(array_filter(
            array_keys(FormFieldTypes::all()),
            static fn (string $key): bool => FormFieldWidth::formerDefaultFor($key) === 'half'
        ));

        $this->assertMatchesRegularExpression(
            "/SET layout_width = 'half'\\s+WHERE layout_width IS NULL AND field_type IN \\('" . implode("', '", $half) . "'\\)/",
            $source
        );
        $this->assertStringContainsString("SET layout_width = 'full' WHERE layout_width IS NULL", $source);
        $this->assertMatchesRegularExpression("/'null' => false,\\s+'default' => 'full'/", $source, 'a new field is full width');
    }

    /**
     * Every type, at every width, renders with exactly its one class — and a
     * row that holds no width (or one nobody knows) is full width.
     */
    public function testEveryFieldRendersWithTheClassOfItsWidth(): void
    {
        $rows = [];
        $expected = [];
        $position = 0;

        foreach (array_keys(FormFieldTypes::all()) as $type) {
            foreach (FormFieldWidth::keys() as $width) {
                $key = $type . '-' . str_replace('_', '-', $width);
                $rows[] = $this->row(++$position, $key, $type, ['layout_width' => $width]);
                $expected[$key] = FormFieldWidth::cssClass($width);
            }
        }

        $rows[] = $this->row(++$position, 'geen-breedte', 'text');
        $expected['geen-breedte'] = 'form-field--full';
        $rows[] = $this->row(++$position, 'onbekende-breedte', 'text', ['layout_width' => 'fifth']);
        $expected['onbekende-breedte'] = 'form-field--full';
        $rows[] = $this->row(++$position, 'stijl', 'text', ['layout_width' => 'half" style="width:999px']);
        $expected['stijl'] = 'form-field--full';

        $html = $this->render($this->definition($rows));
        $xpath = $this->xpath($html);

        foreach ($expected as $key => $class) {
            $wrappers = $xpath->query('//*[@id="' . $this->state()->id($key) . '"]/ancestor::div[contains(concat(" ", @class, " "), " form-field ")][1]');
            $this->assertSame(1, $wrappers->length, $key);

            $classes = preg_split('/\s+/', trim($wrappers->item(0)->getAttribute('class')));
            $widthClasses = array_values(array_filter($classes, static fn (string $c): bool => $c !== 'form-field' && str_starts_with($c, 'form-field--')));
            $this->assertSame([$class], $widthClasses, $key);
        }

        $this->assertStringNotContainsString('style=', $html, 'a width is a class, never a style');
        $this->assertStringNotContainsString('999px', $html);
    }

    /**
     * The fields stay in the order the editor gave them, whatever their
     * widths: that is the tab order, on a desktop grid and on a phone.
     */
    public function testWidthsNeverChangeTheOrderOfTheFields(): void
    {
        $widths = ['third', 'full', 'quarter', 'two_thirds', 'half', 'three_quarters'];
        $rows = [];
        foreach ($widths as $index => $width) {
            $rows[] = $this->row($index + 1, 'veld-' . ($index + 1), 'text', ['layout_width' => $width]);
        }

        $xpath = $this->xpath($this->render($this->definition($rows)));
        $names = [];
        foreach ($xpath->query('//div[contains(@class, "form-grid")]//input[not(@type="hidden")]') as $input) {
            $names[] = $input->getAttribute('name');
        }

        $this->assertSame(['veld-1', 'veld-2', 'veld-3', 'veld-4', 'veld-5', 'veld-6'], $names);
        $this->assertSame(0, $xpath->query('//*[@tabindex and @tabindex != "-1"]')->length, 'no field is lifted out of the order');
    }

    /**
     * The stylesheet spans each class over its share of twelve columns, and
     * puts every field on a row of its own on a phone — at the breakpoint
     * the shared two-column grid has always used.
     */
    public function testTheStylesheetSpansEachWidthAndStacksOnAPhone(): void
    {
        $css = (string) file_get_contents(self::FORM_CSS);
        $core = (string) file_get_contents(self::CORE_CSS);

        $this->assertMatchesRegularExpression('/\.vvl-form \.form-grid \{\s*grid-template-columns: repeat\(12, minmax\(0, 1fr\)\);/', $css);

        foreach (FormFieldWidth::keys() as $key) {
            if ($key === 'full') {
                continue;
            }

            $this->assertMatchesRegularExpression(
                '/\.vvl-form \.form-grid > \.' . preg_quote(FormFieldWidth::cssClass($key), '/') . ' \{ grid-column: span ' . FormFieldWidth::span($key) . '; \}/',
                $css,
                $key
            );
        }

        // Everything else in the grid, the full width included, is a whole row.
        $this->assertMatchesRegularExpression('/\.vvl-form \.form-grid > \* \{\s*grid-column: 1 \/ -1;/', $css);
        $this->assertMatchesRegularExpression('/\.form-field--full\{ grid-column: 1 \/ -1; \}/', $core);

        // The same breakpoint as the shared grid, and a rule there that
        // out-ranks the spans.
        $this->assertMatchesRegularExpression('/@media \(max-width: 640px\)\{ \.form-grid\{ grid-template-columns: 1fr; \} \}/', $core);
        $this->assertMatchesRegularExpression(
            '/@media \(max-width: 640px\) \{\s*\.vvl-form \.form-grid \{\s*grid-template-columns: minmax\(0, 1fr\);\s*\}.*?\.vvl-form \.form-grid > \.form-field \{\s*grid-column: 1 \/ -1;/s',
            $css
        );
        $this->assertLessThan(
            strpos($css, '@media (max-width: 640px)'),
            strpos($css, '.form-field--quarter { grid-column'),
            'the phone rule comes after the spans, so it wins at equal specificity'
        );
    }

    /**
     * The width is structure: the same field shows the same width in every
     * website language, whatever its words are.
     */
    public function testTheWidthIsTheSameInEveryLanguage(): void
    {
        $rows = [$this->row(1, 'voornaam', 'text', ['layout_width' => 'third'])];

        foreach (['nl', 'en'] as $language) {
            RequestLanguage::set($language, true);
            $field = $this->definition($rows)->fields[0];

            $this->assertSame('third', $field->width, $language);
            $this->assertSame($language === 'nl' ? 'Voornaam' : 'First name', $field->label);
        }
    }

    /* ------------------------------------------------------------------ */

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function row(int $position, string $key, string $type, array $overrides = []): array
    {
        $choices = [];
        if (FormFieldTypes::get($type)?->usesOptions()) {
            $choices = [
                ['id' => $position * 10 + 1, 'value' => 'Ja', 'sort_order' => 0, 'labels' => ['nl' => 'Ja', 'en' => 'Yes']],
                ['id' => $position * 10 + 2, 'value' => 'Nee', 'sort_order' => 1, 'labels' => ['nl' => 'Nee', 'en' => 'No']],
            ];
        }

        return $overrides + [
            'id' => $position,
            'field_key' => $key,
            'field_type' => $type,
            'is_required' => $position % 2 === 0,
            'sort_order' => $position,
            'translations' => [
                'nl' => ['label' => $key === 'voornaam' ? 'Voornaam' : 'Veld ' . $key],
                'en' => ['label' => $key === 'voornaam' ? 'First name' : 'Field ' . $key],
            ],
            'choices' => $choices,
        ];
    }

    /** @param list<array<string, mixed>> $rows */
    private function definition(array $rows): FormDefinition
    {
        return FormDefinition::fromRows([
            'id' => 1,
            'name' => 'Breedtes',
            'internal_key' => 'breedtes',
            'is_active' => 1,
            'store_submissions' => 0,
            'translations' => [],
        ], $rows);
    }

    private function state(): FormRenderState
    {
        return FormRenderState::fresh('breedte-test');
    }

    private function render(FormDefinition $form): string
    {
        set_error_handler(static function (int $level, string $message, string $file, int $line): never {
            throw new \ErrorException($message, 0, $level, $file, $line);
        });

        ob_start();

        try {
            render_form($form, $this->state());

            return (string) ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        } finally {
            restore_error_handler();
        }
    }

    private function xpath(string $html): \DOMXPath
    {
        $document = new \DOMDocument();
        libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">' . $html);
        libxml_clear_errors();

        return new \DOMXPath($document);
    }
}
