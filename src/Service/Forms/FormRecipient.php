<?php

declare(strict_types=1);

namespace App\Service\Forms;

use App\Service\SiteSettings;

/**
 * Who a form's notification e-mail goes to.
 *
 * Two steps and no company written into the code:
 *
 *   1. the address the editor filled in on the form itself;
 *   2. otherwise the site's own contact address (Site-instellingen).
 *
 * A form therefore works the moment it is created, an owner who changes
 * their contact address changes it in one place, and a form that needs to
 * reach somebody else (the workshop, the accountant) says so on the form.
 * Both are validated: a typo in either falls through to the next step rather
 * than handing PHPMailer an address it will refuse.
 *
 * ONE RECIPIENT IN V1. No CC, no BCC, no routing on the answers, no
 * autoresponder — FORMS.md lists those as deliberately deferred.
 */
final class FormRecipient
{
    /**
     * The address to notify, or null when neither step yields a usable one —
     * in which case the submission is still accepted and stored, and the
     * missing configuration is logged for the owner
     * (App\Service\Forms\FormSubmissionHandler).
     */
    public static function forForm(FormDefinition $form): ?string
    {
        $configured = self::validAddress($form->notificationEmail);
        if ($configured !== null) {
            return $configured;
        }

        return self::siteFallback();
    }

    /** The site's own contact address, when it is a usable one. */
    public static function siteFallback(): ?string
    {
        try {
            return self::validAddress(SiteSettings::get('email'));
        } catch (\Throwable $e) {
            error_log('[FormRecipient] could not read the site contact address: ' . $e->getMessage());

            return null;
        }
    }

    /**
     * Whether a form sends its notification to the site's own contact
     * address: it is switched on and has no usable address of its own.
     *
     * @param array<string, mixed> $form a `forms` row, or the values the form
     *                                   editor is about to save
     */
    public static function reliesOnSiteAddress(array $form): bool
    {
        return !empty($form['is_active'])
            && self::validAddress((string) ($form['notification_email'] ?? '')) === null;
    }

    /**
     * Whether a submission to this form would reach nobody: there is no
     * address to notify, not even the site's, and the form does not keep its
     * submissions either. Nothing at runtime can recover such a submission,
     * so the admin screens refuse to create this state instead of leaving it
     * to a line in the server log (api/admin/update-form.php and
     * App\Service\SiteSettingsValidator).
     *
     * @param array<string, mixed> $form        see reliesOnSiteAddress()
     * @param string|null          $siteAddress siteFallback(), or the address
     *                                          Site-instellingen is about to store
     */
    public static function losesSubmissions(array $form, ?string $siteAddress): bool
    {
        return self::reliesOnSiteAddress($form)
            && self::validAddress($siteAddress) === null
            && empty($form['store_submissions']);
    }

    /**
     * Whether an address is safe to put in a mail header: a valid address
     * AND free of the control characters that would let it inject a second
     * one. filter_var alone already rejects those, but the check is written
     * out because it is the reason this method exists.
     */
    public static function validAddress(?string $address): ?string
    {
        $address = trim((string) $address);

        if ($address === '' || strlen($address) > 254) {
            return null;
        }

        if (preg_match('/[\x00-\x1F\x7F]/', $address) === 1) {
            return null;
        }

        return filter_var($address, FILTER_VALIDATE_EMAIL) !== false ? $address : null;
    }
}
