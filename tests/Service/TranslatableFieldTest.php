<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Service\Blocks\TranslatableField;
use PHPUnit\Framework\TestCase;

/**
 * One declared block field (Multilingual 2.0 phase 3,
 * docs/multilingual/ARCHITECTURE.md): what a key may look like, what is
 * stored for a submitted value, and which problems the validator reports.
 * No database.
 */
final class TranslatableFieldTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function refusedKeys(): array
    {
        return [
            'a V1 language suffix' => ['title_nl'],
            'any language-shaped suffix' => ['title_de'],
            'capitals' => ['Title'],
            'a space' => ['primary label'],
            'a dash' => ['primary-label'],
            'SQL' => ["title'; DROP TABLE x"],
            'empty' => [''],
            'too long' => [str_repeat('a', 65)],
        ];
    }

    /**
     * @dataProvider refusedKeys
     */
    public function testAKeyIsAPlainIdentifierWithoutALanguage(string $key): void
    {
        $this->expectException(\InvalidArgumentException::class);

        TranslatableField::plain($key, 10);
    }

    public function testOrdinaryKeysAreAccepted(): void
    {
        foreach (['title', 'primary_label', 'body', 'image_alt', 'secondary_text'] as $key) {
            self::assertSame($key, TranslatableField::plain($key, 10)->key);
        }
    }

    public function testRequiredIsANewDeclarationAndOnlyInTheDefaultLanguage(): void
    {
        $optional = TranslatableField::plain('title', 20);
        $required = $optional->required();

        self::assertFalse($optional->required);
        self::assertTrue($required->required);
        self::assertSame(TranslatableField::MISSING, $required->problem('   ', true));
        self::assertNull($required->problem('', false), 'a translation is never required');
        self::assertNull($optional->problem('', true));
    }

    public function testTheLengthIsCountedInCharactersInEveryLanguage(): void
    {
        $field = TranslatableField::plain('title', 5);

        self::assertNull($field->problem('ééééé', true));
        self::assertSame(TranslatableField::TOO_LONG, $field->problem('éééééé', false));
        self::assertNull($field->problem('  abcde  ', true), 'surrounding spaces are not counted');
    }

    public function testPlainTextIsTrimmedAndOtherwiseStoredAsTyped(): void
    {
        $field = TranslatableField::plain('title', 100);

        self::assertSame('<b>Titel</b> & meer', $field->normalise("  <b>Titel</b> & meer \n"));
        self::assertSame('', $field->normalise(" \t\n "));
        self::assertSame('', $field->normalise(null));
        self::assertFalse($field->isRich());
    }

    public function testRichTextGoesThroughTheSanitizer(): void
    {
        $field = TranslatableField::rich('body', 1000);

        self::assertTrue($field->isRich());
        self::assertSame('<p><strong>Vet</strong></p>', $field->normalise('<p><strong>Vet</strong></p>'));

        $stored = $field->normalise('<p onclick="steal()">Tekst<script>alert(1)</script><img src=x onerror=alert(1)></p>');
        self::assertStringNotContainsString('script', $stored);
        self::assertStringNotContainsString('onclick', $stored);
        self::assertStringNotContainsString('onerror', $stored);
        self::assertStringContainsString('Tekst', $stored);

        self::assertSame('', $field->normalise('<script>alert(1)</script>'), 'nothing left after sanitizing is no words');
    }

    public function testRichTextThatSanitizesToNothingIsMissingInTheDefaultLanguage(): void
    {
        $field = TranslatableField::rich('body', 1000)->required();

        self::assertSame(TranslatableField::MISSING, $field->problem('<script>x</script>', true));
    }
}
