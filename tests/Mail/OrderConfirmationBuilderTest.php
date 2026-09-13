<?php

declare(strict_types=1);

namespace Tests\Mail;

use App\Mail\OrderConfirmationBuilder;
use App\Service\SiteSettings;
use PHPUnit\Framework\TestCase;

/**
 * Pure unit tests (no database) for App\Mail\OrderConfirmationBuilder —
 * covers MAIN.MD scenarios "customer email contains the expected order
 * summary" / "... the correct order number" / "CMS email placeholders
 * render safely" / "unknown placeholders fail safely".
 *
 * Site settings are the generic defaults for every test, so the order number
 * is the one a new installation shows, whatever the test database holds.
 */
final class OrderConfirmationBuilderTest extends TestCase
{
    protected function setUp(): void
    {
        SiteSettings::overrideForTests([]);
    }

    protected function tearDown(): void
    {
        SiteSettings::overrideForTests(null);
    }

    private function order(array $overrides = []): array
    {
        return array_merge([
            'id' => 42,
            'total' => '54.90',
            'shipping_cost' => '4.95',
            'shipping_method' => 'verzenden',
            'currency' => 'EUR',
            'created_at' => '2026-03-14 10:00:00',
            'billing_same_as_shipping' => 1,
            'shipping_first_name' => 'Jan',
            'shipping_last_name' => 'Jansen',
            'shipping_company' => null,
            'shipping_country' => 'NL',
            'shipping_postal_code' => '1234AB',
            'shipping_house_number' => '1',
            'shipping_house_number_addition' => null,
            'shipping_street' => 'Teststraat',
            'shipping_city' => 'Teststad',
        ], $overrides);
    }

    private function customer(): array
    {
        return [
            'name' => 'Jan Jansen',
            'email' => 'jan@example.invalid',
            'phone' => null,
            'address_line' => null,
            'postal_code' => null,
            'city' => null,
            'country' => null,
        ];
    }

    private function items(): array
    {
        return [[
            'name' => 'Sleutelhanger - Acryl',
            'name_en' => 'Keychain - Acrylic',
            'variant_label' => 'Kleur: Blauw',
            'quantity' => 2,
            'unit_price' => '24.975',
        ]];
    }

    public function testCustomerEmailContainsOrderNumberProductsAndTotal(): void
    {
        $emails = OrderConfirmationBuilder::build($this->order(), $this->customer(), $this->items());

        $this->assertStringContainsString('ORD-2026-000042', $emails['customer']['subject']);
        $this->assertStringContainsString('ORD-2026-000042', $emails['customer']['html']);
        $this->assertStringContainsString('Sleutelhanger - Acryl', $emails['customer']['html']);
        $this->assertStringContainsString('Kleur: Blauw', $emails['customer']['html']);
        $this->assertStringContainsString('54,90', $emails['customer']['html']);
        $this->assertStringContainsString('ORD-2026-000042', $emails['customer']['text']);
        $this->assertStringContainsString('Sleutelhanger - Acryl', $emails['customer']['text']);
    }

    public function testCmsSubjectHeadingIntroClosingAndSignatureAreUsedWithPlaceholdersSubstituted(): void
    {
        $emailSettings = [
            'order_email_subject' => 'Order {{order_number}} confirmed',
            'order_email_heading' => 'Thanks, {{customer_name}}!',
            'order_email_intro' => 'Hi {{customer_name}}, your total was {{order_total}}.',
            'order_email_before_items' => 'Before items text.',
            'order_email_after_items' => 'After items text.',
            'order_email_closing' => 'See you soon.',
            'order_email_signature' => 'Team VVL',
        ];

        $emails = OrderConfirmationBuilder::build($this->order(), $this->customer(), $this->items(), $emailSettings);

        $this->assertSame('Order ORD-2026-000042 confirmed', $emails['customer']['subject']);
        $this->assertStringContainsString('Thanks, Jan Jansen!', $emails['customer']['html']);
        $this->assertStringContainsString('Hi Jan Jansen, your total was', $emails['customer']['html']);
        $this->assertStringContainsString('Before items text.', $emails['customer']['html']);
        $this->assertStringContainsString('After items text.', $emails['customer']['html']);
        $this->assertStringContainsString('See you soon.', $emails['customer']['html']);
        $this->assertStringContainsString('Team VVL', $emails['customer']['html']);
        $this->assertStringContainsString('Before items text.', $emails['customer']['text']);
        $this->assertStringContainsString('Team VVL', $emails['customer']['text']);
    }

    public function testUnknownPlaceholderInCmsTextDoesNotBreakRenderingOrExecuteAnything(): void
    {
        $emailSettings = [
            'order_email_intro' => 'Hi {{customer_name}}, {{totally_unknown}} and <?php echo "x"; ?> stay literal.',
        ];

        $emails = OrderConfirmationBuilder::build($this->order(), $this->customer(), $this->items(), $emailSettings);

        $this->assertStringContainsString('{{totally_unknown}}', $emails['customer']['html']);
        $this->assertStringContainsString('&lt;?php echo &quot;x&quot;; ?&gt;', $emails['customer']['html']);
        $this->assertStringNotContainsString('<?php', $emails['customer']['html']);
    }

    public function testCmsTextCannotInjectHtmlIntoTheEmail(): void
    {
        $emailSettings = [
            'order_email_closing' => '<img src=x onerror=alert(1)>',
        ];

        $emails = OrderConfirmationBuilder::build($this->order(), $this->customer(), $this->items(), $emailSettings);

        $this->assertStringNotContainsString('<img', $emails['customer']['html']);
        $this->assertStringContainsString('&lt;img', $emails['customer']['html']);
    }

    public function testShopNotificationEmailIsUnaffectedByCmsSettings(): void
    {
        $emailSettings = ['order_email_heading' => 'Should never appear in shop email'];

        $emails = OrderConfirmationBuilder::build($this->order(), $this->customer(), $this->items(), $emailSettings);

        $this->assertSame('Nieuwe betaalde bestelling ORD-2026-000042', $emails['shop']['subject']);
        $this->assertStringNotContainsString('Should never appear in shop email', $emails['shop']['html']);
    }

    public function testMissingEmailSettingsFallBackToTheDefaultDutchCopy(): void
    {
        $emails = OrderConfirmationBuilder::build($this->order(), $this->customer(), $this->items());

        $this->assertStringContainsString('Bedankt voor je bestelling!', $emails['customer']['html']);
        $this->assertStringContainsString('Beste Jan Jansen', $emails['customer']['html']);
    }
}
