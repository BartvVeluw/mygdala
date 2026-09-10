<?php

namespace App\Mail;

/**
 * Builds the subject/HTML/plain-text content for the shop notification
 * email sent from the "Offerte aanvragen" form on contact.html. Pure content
 * generation only — no sending, no validation (that's api/contact.php).
 * Mirrors OrderConfirmationBuilder's style (inline-styled HTML table email
 * with a plain-text fallback).
 */
class ContactRequestBuilder
{
    /**
     * @param array{naam:string,email:string,telefoon:string,voorWie:string,omschrijving:string,attachmentName:?string} $data
     * @return array{subject:string, html:string, text:string}
     */
    public static function build(array $data): array
    {
        $subject = 'Nieuwe offerteaanvraag – ' . $data['naam'];
        $voorWieLabel = $data['voorWie'] === 'zakelijk' ? 'Zakelijk' : 'Particulier';

        $rows = [
            ['Naam', $data['naam']],
            ['E-mail', $data['email']],
        ];
        if ($data['telefoon'] !== '') {
            $rows[] = ['Telefoonnummer', $data['telefoon']];
        }
        $rows[] = ['Voor wie', $voorWieLabel];

        $detailsHtml = '<table style="width:100%;border-collapse:collapse;font-size:14px;">';
        foreach ($rows as [$label, $value]) {
            $detailsHtml .= '<tr>'
                . '<td style="padding:4px 12px 4px 0;color:#555;white-space:nowrap;">' . self::esc($label) . '</td>'
                . '<td style="padding:4px 0;">' . self::esc($value) . '</td>'
                . '</tr>';
        }
        $detailsHtml .= '</table>';

        $detailsText = '';
        foreach ($rows as [$label, $value]) {
            $detailsText .= $label . ': ' . $value . "\n";
        }

        $omschrijvingHtml = nl2br(self::esc($data['omschrijving']), false);
        $attachmentNote = $data['attachmentName'] !== null
            ? '<p style="margin-top:16px;color:#555;">Bijlage: ' . self::esc($data['attachmentName']) . '</p>'
            : '';
        $attachmentNoteText = $data['attachmentName'] !== null ? "\nBijlage: {$data['attachmentName']}\n" : '';

        $html = self::wrapHtml(
            'Nieuwe offerteaanvraag',
            '<p>Er is een nieuwe offerteaanvraag binnengekomen via het contactformulier.</p>'
            . $detailsHtml
            . '<h2 style="font-size:16px;margin:24px 0 8px;">Omschrijving</h2>'
            . '<p style="white-space:pre-line;">' . $omschrijvingHtml . '</p>'
            . $attachmentNote
        );

        $text = "Er is een nieuwe offerteaanvraag binnengekomen via het contactformulier.\n\n"
            . $detailsText
            . "\nOmschrijving:\n" . $data['omschrijving'] . "\n"
            . $attachmentNoteText;

        return ['subject' => $subject, 'html' => $html, 'text' => $text];
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
