<?php

declare(strict_types=1);

namespace Tests\Service;

use PHPUnit\Framework\TestCase;

/**
 * The order questions on a product page wear the site's own form style
 * (Shop Admin UX & Order Fields 2.0): core.css .form-field for a text, long
 * text, dropdown and image question, .checkbox-field for a tick box and each
 * radio, .hint for help, .req for the required mark. shop.css only fits them
 * into the purchase column and never draws a second input style.
 */
final class OrderFieldStylingTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';

    public function testEveryQuestionIsTheSitesOwnFormField(): void
    {
        $html = $this->render([
            $this->question(1, 'text', true, 'Naam op het bord', 'Maximaal 12 letters'),
            $this->question(2, 'textarea', false, 'Opmerking'),
            $this->question(3, 'select', true, 'Maat', '', [['id' => 31, 'label' => 'S'], ['id' => 32, 'label' => 'M']]),
            $this->question(4, 'radio', true, 'Houtsoort', '', [['id' => 41, 'label' => 'Eiken'], ['id' => 42, 'label' => 'Noten']]),
            $this->question(5, 'checkbox', false, 'Cadeauverpakking'),
        ]);

        self::assertSame(5, substr_count($html, 'class="product-order-field form-field'));
        self::assertSame(2, substr_count($html, 'product-order-field--choice'), 'a tick box and a radio group are choice rows');
        self::assertSame(3, substr_count($html, 'class="checkbox-field product-order-field__choice"'), 'two radios and one tick box');
        self::assertStringContainsString('<p class="hint product-order-field__help" id="order-field-1-help">Maximaal 12 letters</p>', $html);
        self::assertSame(3, substr_count($html, 'class="req product-order-field__required" aria-hidden="true"'));
        self::assertStringContainsString('aria-describedby="order-field-1-help order-field-1-error"', $html, 'help and error are tied to the control');
        self::assertStringContainsString('<label for="order-field-1">Naam op het bord', $html);
        self::assertStringContainsString('<fieldset class="product-order-field__group" aria-describedby="order-field-4-error">', $html);
        self::assertStringContainsString('<legend>Houtsoort', $html);
    }

    public function testShopCssFitsTheFieldsWithoutASecondFormStyle(): void
    {
        $css = (string) file_get_contents(self::ROOT . '/assets/css/shop/shop.css');
        $start = strpos($css, '/* Bestelvelden (partials/product-order-fields.php)');
        $end = strpos($css, "/* A cart line's answers");
        self::assertNotFalse($start);
        self::assertNotFalse($end);
        $block = substr($css, (int) $start, (int) $end - (int) $start);

        // The border, surface and focus ring of a field are core.css's.
        self::assertStringNotContainsString('background:', $block);
        self::assertStringNotContainsString('border:', $block);
        self::assertMatchesRegularExpression('/product-order-field__choice\{[^}]*min-height: 44px/', $block, 'a choice is a row big enough to tap');
        self::assertStringContainsString('overflow-wrap: anywhere', $block, 'a long label wraps on a phone');
        self::assertStringContainsString('select[aria-invalid="true"]', $block);
        self::assertStringContainsString(':focus-visible', $block);

        $core = (string) file_get_contents(self::ROOT . '/assets/css/core.css');
        foreach (['.form-field input:focus', '.form-field input[aria-invalid="true"]', '.checkbox-field{', '.form-field .hint', '.form-field .req'] as $rule) {
            self::assertStringContainsString($rule, $core, $rule . ' is the shared style the questions use');
        }

        $script = (string) file_get_contents(self::ROOT . '/assets/js/shop/shop.js');
        self::assertStringContainsString('control.setAttribute("aria-invalid", "true")', $script, 'an unanswered question shows the site\'s error look');
    }

    /**
     * @param list<array<string, mixed>> $questions
     */
    private function render(array $questions): string
    {
        require_once self::ROOT . '/partials/product-order-fields.php';
        ob_start();
        render_product_order_fields($questions);

        return (string) ob_get_clean();
    }

    /**
     * @param list<array{id: int, label: string}> $options
     * @return array<string, mixed>
     */
    private function question(int $id, string $type, bool $required, string $label, string $help = '', array $options = []): array
    {
        return [
            'id' => $id, 'type' => $type, 'required' => $required, 'max_length' => $type === 'text' ? 100 : ($type === 'textarea' ? 1000 : 0),
            'label' => $label, 'help' => $help, 'options' => $options, 'max_bytes' => 0,
        ];
    }
}
