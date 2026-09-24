<?php

namespace App\Mail;

use App\Service\Forms\FormDefinition;

/**
 * The subject, HTML and plain-text body of the notification e-mail a form
 * sends its owner. Content generation only — no sending, no validation, no
 * database (App\Service\Forms\FormSubmissionHandler does all three).
 *
 * ONE BUILDER FOR EVERY FORM. There is no per-form template, no e-mail
 * designer and no {{placeholder}} language here: an enquiry notification is
 * read once by one person who wants to know what somebody asked, and a form
 * that can be built in the CMS cannot have a hand-written e-mail layout
 * waiting for it. The layout mirrors App\Mail\OrderConfirmationBuilder's —
 * an inline-styled table with a plain-text alternative — so the owner's
 * inbox stays consistent.
 *
 * EVERY SUBMITTED VALUE IS ESCAPED, and no submitted value is ever treated
 * as HTML. The visitor's own text reaches this class already stripped of
 * control characters (App\Service\Forms\FieldTypes\FormFieldType::clean()),
 * so it cannot add a header either; here it is escaped again on the way into
 * the body, because the two are different problems.
 */
class FormSubmissionBuilder
{
    /**
     * @param list<array{field_key: string, field_label: string, field_type: string, value: string}> $values
     * FILES. An upload field's answer is the file's name and size, and the
     * file itself is attached to the message under a generic name (the
     * field's key, never the visitor's file name). A file that did not fit
     * the message (FormSubmissionHandler::MAIL_ATTACHMENT_BUDGET) is named in
     * its answer with where it is instead: in the CMS when the form keeps its
     * submissions, and nowhere when it does not — the owner is told plainly.
     *
     * @param array{source_path?: ?string, submitted_at?: ?string, attachment_names?: list<string>, not_attached?: list<string>, kept_in_cms?: bool} $context
     * @return array{subject: string, html: string, text: string}
     */
    public static function build(FormDefinition $form, array $values, array $context = []): array
    {
        $formName = $form->name !== '' ? $form->name : 'Formulier';
        $subject = 'Nieuwe inzending – ' . $formName;

        $submittedAt = $context['submitted_at'] ?? date('d-m-Y H:i');
        $sourcePath = $context['source_path'] ?? null;
        $attachmentNames = $context['attachment_names'] ?? [];
        $notAttached = $context['not_attached'] ?? [];
        $keptInCms = (bool) ($context['kept_in_cms'] ?? false);

        $meta = [['Formulier', $formName], ['Ontvangen', $submittedAt]];
        if (is_string($sourcePath) && $sourcePath !== '') {
            $meta[] = ['Pagina', $sourcePath];
        }
        if ($attachmentNames !== []) {
            $meta[] = [count($attachmentNames) === 1 ? 'Bijlage' : 'Bijlagen', implode(', ', $attachmentNames)];
        }

        foreach ($values as $index => $value) {
            if (in_array($value['field_key'], $notAttached, true) && $value['value'] !== '') {
                $values[$index]['value'] .= $keptInCms
                    ? ' — niet bijgevoegd, te groot voor deze e-mail; te downloaden bij de inzending in het CMS'
                    : ' — niet bijgevoegd, te groot voor deze e-mail; dit formulier bewaart geen inzendingen, dus het bestand is niet bewaard';
            }
        }

        return [
            'subject' => $subject,
            'html' => self::wrapHtml($subject, self::metaHtml($meta) . self::answersHtml($values)),
            'text' => self::metaText($meta) . "\n" . self::answersText($values),
        ];
    }

    /**
     * @param list<array{0: string, 1: string}> $meta
     */
    private static function metaHtml(array $meta): string
    {
        $html = '<table style="width:100%;border-collapse:collapse;font-size:13px;color:#555;">';
        foreach ($meta as [$label, $value]) {
            $html .= '<tr>'
                . '<td style="padding:2px 12px 2px 0;white-space:nowrap;">' . self::esc($label) . '</td>'
                . '<td style="padding:2px 0;">' . self::esc($value) . '</td>'
                . '</tr>';
        }

        return $html . '</table>';
    }

    /**
     * @param list<array{0: string, 1: string}> $meta
     */
    private static function metaText(array $meta): string
    {
        $text = '';
        foreach ($meta as [$label, $value]) {
            $text .= $label . ': ' . $value . "\n";
        }

        return $text;
    }

    /**
     * @param list<array{field_key: string, field_label: string, field_type: string, value: string}> $values
     */
    private static function answersHtml(array $values): string
    {
        $html = '<h2 style="font-size:16px;margin:24px 0 8px;">Antwoorden</h2>'
            . '<table style="width:100%;border-collapse:collapse;font-size:14px;">';

        foreach ($values as $value) {
            $html .= '<tr>'
                . '<td style="padding:6px 12px 6px 0;color:#555;vertical-align:top;white-space:nowrap;">'
                . self::esc($value['field_label']) . '</td>'
                // white-space:pre-line keeps a textarea's line breaks
                // without nl2br() turning escaped text back into markup.
                . '<td style="padding:6px 0;white-space:pre-line;">'
                . self::esc(self::displayValue($value)) . '</td>'
                . '</tr>';
        }

        return $html . '</table>';
    }

    /**
     * @param list<array{field_key: string, field_label: string, field_type: string, value: string}> $values
     */
    private static function answersText(array $values): string
    {
        $text = "Antwoorden:\n";

        foreach ($values as $value) {
            $text .= "\n" . $value['field_label'] . ":\n" . self::displayValue($value) . "\n";
        }

        return $text;
    }

    /**
     * What to print for an answer. Only one case is not the value itself: a
     * field that was left blank reads better as an explicit dash than as a
     * label with nothing after it.
     *
     * @param array{field_key: string, field_label: string, field_type: string, value: string} $value
     */
    private static function displayValue(array $value): string
    {
        return $value['value'] !== '' ? $value['value'] : '—';
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
