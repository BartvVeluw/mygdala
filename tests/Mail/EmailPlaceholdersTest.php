<?php

declare(strict_types=1);

namespace Tests\Mail;

use App\Mail\EmailPlaceholders;
use PHPUnit\Framework\TestCase;

/**
 * Covers MAIN.MD "CMS-editable email template" placeholder requirements:
 * known placeholders substitute correctly, unknown placeholders are left as
 * safe literal text rather than breaking rendering or executing anything,
 * and HTML-mode substitution can never let a placeholder value re-open
 * markup (a customer name containing "<script>" must render as inert text).
 */
final class EmailPlaceholdersTest extends TestCase
{
    public function testKnownPlaceholdersAreSubstituted(): void
    {
        $result = EmailPlaceholders::render(
            'Beste {{customer_name}}, bestelling {{order_number}} van {{order_date}} — totaal {{order_total}}.',
            [
                'customer_name' => 'Jan Jansen',
                'order_number' => 'ORD-2026-000001',
                'order_date' => '07-09-2026',
                'order_total' => '€ 12,34',
            ],
            false
        );

        $this->assertSame('Beste Jan Jansen, bestelling ORD-2026-000001 van 07-09-2026 — totaal € 12,34.', $result);
    }

    public function testUnknownPlaceholderIsLeftAsLiteralText(): void
    {
        $result = EmailPlaceholders::render('Hallo {{customer_name}}, {{not_a_real_placeholder}}!', [
            'customer_name' => 'Jan',
        ], false);

        $this->assertSame('Hallo Jan, {{not_a_real_placeholder}}!', $result);
    }

    public function testHtmlModeEscapesTemplateAndSubstitutedValues(): void
    {
        $result = EmailPlaceholders::render(
            'Beste {{customer_name}} <b>bold</b>',
            ['customer_name' => '<script>alert(1)</script>'],
            true
        );

        $this->assertStringNotContainsString('<script>', $result);
        $this->assertStringNotContainsString('<b>', $result);
        $this->assertStringContainsString('&lt;script&gt;', $result);
    }

    public function testMissingValueForAKnownPlaceholderSubstitutesEmptyStringNotAnErrorOrLiteralToken(): void
    {
        $result = EmailPlaceholders::render('Totaal: {{order_total}}.', [], false);

        $this->assertSame('Totaal: .', $result);
    }
}
