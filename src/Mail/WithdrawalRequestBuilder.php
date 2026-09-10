<?php

namespace App\Mail;

/**
 * Builds the subject/HTML/plain-text content for the two emails sent when a
 * customer submits a herroeping.php withdrawal request: a shop notification
 * (so the owner can review it — see admin/withdrawal-requests.php) and a
 * customer confirmation (so the customer has a dated record that their
 * request was received). Pure content generation only — no sending, no
 * validation (that's api/withdrawal-request.php). Mirrors
 * ContactRequestBuilder/OrderConfirmationBuilder's inline-styled HTML table
 * email + plain-text fallback style.
 */
class WithdrawalRequestBuilder
{
    /**
     * @param array{orderId:int, customerEmail:string, reason:string} $data
     * @return array{shop: array{subject:string, html:string, text:string}, customer: array{subject:string, html:string, text:string}}
     */
    public static function build(array $data): array
    {
        $orderId = $data['orderId'];
        $reason = trim($data['reason']);

        $shopSubject = 'Nieuw herroepingsverzoek - bestelling #' . $orderId;
        $shopBodyHtml = '<p>Er is een nieuw herroepingsverzoek binnengekomen.</p>'
            . '<table style="width:100%;border-collapse:collapse;font-size:14px;">'
            . '<tr><td style="padding:4px 12px 4px 0;color:#555;white-space:nowrap;">Bestelling</td><td style="padding:4px 0;">#' . $orderId . '</td></tr>'
            . '<tr><td style="padding:4px 12px 4px 0;color:#555;white-space:nowrap;">E-mail</td><td style="padding:4px 0;">' . self::esc($data['customerEmail']) . '</td></tr>'
            . '</table>'
            . ($reason !== ''
                ? '<h2 style="font-size:16px;margin:24px 0 8px;">Toelichting van de klant</h2><p style="white-space:pre-line;">' . nl2br(self::esc($reason), false) . '</p>'
                : '')
            . '<p style="margin-top:24px;">Beoordeel dit verzoek in het adminpaneel — controleer zelf of (een deel van) deze bestelling gepersonaliseerd/op maat gemaakt is, voordat je het verzoek afhandelt.</p>';

        $shopBodyText = "Er is een nieuw herroepingsverzoek binnengekomen.\n\n"
            . "Bestelling: #{$orderId}\n"
            . "E-mail: {$data['customerEmail']}\n"
            . ($reason !== '' ? "\nToelichting van de klant:\n{$reason}\n" : '')
            . "\nBeoordeel dit verzoek in het adminpaneel.\n";

        $customerSubject = 'Ontvangstbevestiging herroepingsverzoek - bestelling #' . $orderId;
        $customerBodyHtml = '<p>We hebben je verzoek tot herroeping voor bestelling #' . $orderId . ' ontvangen.</p>'
            . '<p>We nemen dit verzoek in behandeling en nemen zo nodig contact met je op.</p>';
        $customerBodyText = "We hebben je verzoek tot herroeping voor bestelling #{$orderId} ontvangen.\n\n"
            . "We nemen dit verzoek in behandeling en nemen zo nodig contact met je op.\n";

        return [
            'shop' => [
                'subject' => $shopSubject,
                'html' => self::wrapHtml('Nieuw herroepingsverzoek', $shopBodyHtml),
                'text' => $shopBodyText,
            ],
            'customer' => [
                'subject' => $customerSubject,
                'html' => self::wrapHtml('Herroepingsverzoek ontvangen', $customerBodyHtml),
                'text' => $customerBodyText,
            ],
        ];
    }

    private static function wrapHtml(string $heading, string $bodyHtml): string
    {
        return '<!doctype html><html lang="nl"><head><meta charset="utf-8"></head>'
            . '<body style="margin:0;padding:24px;background:#f5f3f0;font-family:Arial,Helvetica,sans-serif;color:#222;">'
            . '<table role="presentation" style="max-width:560px;margin:0 auto;background:#fff;border-radius:8px;padding:32px;">'
            . '<tr><td>'
            . '<h1 style="font-size:20px;margin:0 0 16px;">' . self::esc($heading) . '</h1>'
            . $bodyHtml
            . '<p style="margin-top:32px;font-size:12px;color:#999;">' . EmailIdentity::footerLine() . '</p>'
            . '</td></tr></table>'
            . '</body></html>';
    }

    private static function esc(?string $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}
