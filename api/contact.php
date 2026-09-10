<?php

/**
 * POST /api/contact.php — COMPATIBILITY ONLY.
 *
 * This used to be the whole contact form: its field list, its validation,
 * its recipient and its e-mail all lived here. Since Core Forms none of that
 * does. The quote form is an ordinary Form definition, every form on the
 * site is validated and delivered by one pipeline, and the endpoint that
 * pipeline sits behind is /api/form-submit.php (FORMS.md).
 *
 * WHY THIS FILE STILL EXISTS: a visitor may have the contact page open from
 * before the change, and that page posts here. Their enquiry must arrive, not
 * hit a 404. So this is a SHIM and nothing else — it renames the handful of
 * fields the old markup used, points the request at the Form the old form
 * was migrated onto, and hands it to the real endpoint. There is no
 * validation, no storing and no mailing in this file, and there must never
 * be any again: two engines that both "handle the contact form" is exactly
 * the situation Core Forms was built to end.
 *
 * The old field names are unchanged on purpose — the migration gave the Form
 * definition the same field keys (`naam`, `email`, `telefoon`, `voor-wie`,
 * `omschrijving`) the hardcoded markup posted, so an old page's POST body is
 * already in the right shape. Only this file's own three control fields
 * differ, and they are translated below.
 *
 * It can be deleted once no cached page can still reach it.
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Repository\ContactFormRepository;
use App\Service\ContactFormContent;
use App\Service\Forms\FormCatalog;
use App\Service\Forms\FormRenderState;

// Which form an old POST belongs to: the one this site's quote form was
// migrated onto. An install that never had it simply has no such form, and
// /api/form-submit.php answers with its ordinary generic failure.
$_POST['form-key'] = ContactFormContent::MIGRATED_FORM_KEY;

// The old markup's minimum-submit-time field was `form_ts`; it is `form-ts`
// now. Without this rename every legacy POST would look like a bot to
// App\Service\Forms\FormSpamGuard and be silently discarded.
if (!isset($_POST['form-ts']) && isset($_POST['form_ts'])) {
    $_POST['form-ts'] = $_POST['form_ts'];
}

// The old form had no source field: it always sat on /contact.php and the
// endpoint always redirected back there.
if (!isset($_POST['form-source'])) {
    $_POST['form-source'] = '/contact.php';
}

// Which placement to send a no-JS visitor back to. Looked up rather than
// assumed, so a site whose contact block lives on a differently named page
// still lands on the right form. A form that no longer exists, or a lookup
// that fails, simply means no token — the visitor lands on the page with a
// fresh form instead of on the message, which is a mild degradation of a
// path almost nobody is on.
if (!isset($_POST['form-instance'])) {
    try {
        $form = FormCatalog::findByInternalKey(ContactFormContent::MIGRATED_FORM_KEY);

        if ($form !== null) {
            $section = (new ContactFormRepository())->findActiveSectionForForm($form->id);

            if ($section !== null) {
                $_POST['form-instance'] = FormRenderState::tokenFor(
                    (string) $section['page_slug'],
                    (string) $section['section_key']
                );
            }
        }
    } catch (\Throwable $e) {
        error_log('[api/contact.php] could not resolve the legacy form placement: ' . $e->getMessage());
    }
}

require __DIR__ . '/form-submit.php';
