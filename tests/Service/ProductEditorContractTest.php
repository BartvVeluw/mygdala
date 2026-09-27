<?php

declare(strict_types=1);

namespace Tests\Service;

use PHPUnit\Framework\TestCase;

/**
 * The product editor's scripts and markup as one dynamic editor
 * (admin/product-form.php, admin/_product_variants.php, and the shared
 * row-list.js, product-gallery.js, admin.js it relies on). What is pinned is
 * what makes "Optie toevoegen" a row on the screen instead of a page load,
 * and what keeps a row working after the editor draws its section again:
 *
 *  - rows by key, with a template per list and a nested one per option;
 *  - a list, a variant's pictures and a rich-text field that arrive later
 *    (a row just added, a section drawn again) are wired as they come;
 *  - folding a section is a <details> remembered per browser tab, never a
 *    change and never a database column;
 *  - no per-row form, no inline confirm(), nothing posted on its own.
 *
 * No database, no server; ProductEditorHttpTest renders the real screen.
 */
final class ProductEditorContractTest extends TestCase
{
    private static function source(string $path): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/' . $path);
    }

    public function testTheEditorIsOneFormWithFoldingSectionsAndNoRowForms(): void
    {
        $form = self::source('admin/product-form.php');

        $this->assertSame(1, substr_count($form, '<form method="post"'), 'one form for the whole product');
        $this->assertStringContainsString('data-admin-editor data-admin-collapse-group="product-editor" data-admin-collapse-scope="product" data-admin-collapse-no-return', $form);
        $this->assertStringContainsString('data-admin-collapse-id="images" open', $form);
        $this->assertStringContainsString('data-admin-collapse-id="variants" open', $form);
        $this->assertStringContainsString('<?php admin_collapse_script(); ?>', $form);
        $this->assertStringContainsString('data-admin-editor-region="images"', $form);
        $this->assertStringContainsString('data-admin-editor-region="variants"', $form);
        $this->assertStringNotContainsString('confirm(', $form);
        $this->assertStringNotContainsString('onsubmit', $form);
        $this->assertStringNotContainsString('admin-inline-form', $form);

        $partial = self::source('admin/_product_variants.php');
        $this->assertStringNotContainsString('<form', $partial, 'nothing in Varianten posts on its own');
        $this->assertStringNotContainsString('type="submit"', $partial);
    }

    public function testRowsAreAddedOnTheScreenByKeyWithANestedTemplatePerOption(): void
    {
        $rowList = self::source('admin/assets/row-list.js');
        $this->assertStringContainsString('var placeholder = template.getAttribute("data-row-list-key") || "__KEY__";', $rowList);
        $this->assertStringContainsString('template.innerHTML.split(placeholder).join(key)', $rowList);
        $this->assertStringContainsString('["row-list:added", "admin-editor:replaced"].forEach', $rowList);
        $this->assertStringContainsString('if (list.hasAttribute("data-row-list-ready")) return;', $rowList, 'a list is wired once');

        $partial = self::source('admin/_product_variants.php');
        $this->assertStringContainsString("product_variants_option_row('__KEY__', '', 'standard', [])", $partial);
        $this->assertStringContainsString('data-row-list-key="__VKEY__"><?php product_variants_value_row($key, \'__VKEY__\'', $partial);
        $this->assertStringContainsString('name="options[<?= $h($key) ?>][name]"', $partial);
        $this->assertStringContainsString("\$name = 'option_values[' . \$optionKey . '][' . \$key . ']';", $partial);
        $this->assertStringContainsString('name="variants[<?= $h($key) ?>][price]"', $partial);
    }

    public function testANewVariantChoosesFromTheOptionsOnScreen(): void
    {
        $script = self::source('admin/assets/product-variants.js');

        $this->assertStringContainsString('select.name = "variants[" + key + "][values][" + option.key + "]";', $script);
        $this->assertStringContainsString('item.value = value.key;', $script, 'by row key, so a value typed a moment ago can be chosen');
        $this->assertStringContainsString('used[option.key + ":" + value.key] === true', $script);
        $this->assertStringNotContainsString('fetch(', $script, 'nothing is asked of the server');
        $this->assertStringNotContainsString('innerHTML', $script);
    }

    public function testWhatArrivesLaterIsWiredAsItComes(): void
    {
        $gallery = self::source('admin/assets/product-gallery.js');
        $this->assertStringContainsString('var stopping = new AbortController();', $gallery);
        $this->assertStringContainsString('document.addEventListener("admin-editor:replaced", boot);', $gallery);
        $this->assertStringContainsString('document.addEventListener("row-list:added", function (event) {', $gallery);
        $this->assertStringContainsString('current.addVariant', $gallery);

        $admin = self::source('admin/assets/admin.js');
        $this->assertSame(2, substr_count($admin, '["row-list:added", "admin-editor:replaced"].forEach'), 'the rich-text editor in a new row and in a redrawn section');
        $this->assertStringContainsString('var pair = target && target.closest ? target.closest("[data-color-sync]") : null;', $admin);
        $this->assertStringNotContainsString('data-variant-image-grid', $admin, 'the old per-variant photo grid that reloaded the page');
    }

    /** A folded section is remembered in the browser tab only. */
    public function testFoldingIsNoChangeAndNoColumn(): void
    {
        $collapse = self::source('admin/assets/admin-collapse.js');
        $this->assertStringContainsString('window.sessionStorage.setItem(key, value);', $collapse);

        foreach ((array) glob(dirname(__DIR__, 2) . '/db/migrations/*.php') as $migration) {
            $this->assertStringNotContainsString('product-editor', (string) file_get_contents((string) $migration));
        }
    }
}
