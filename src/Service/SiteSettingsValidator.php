<?php

declare(strict_types=1);

namespace App\Service;

use App\Service\Forms\FormRecipient;
use App\Service\Language\AdminTranslator;

/**
 * What Site-instellingen may submit, and what a submission needs before
 * api/admin/update-site-settings.php stores it.
 *
 * ONE CONTRACT FOR THE SCREEN AND THE ENDPOINT. FIELDS is the closed list of
 * keys the screen's forms carry, with the longest value each accepts.
 * admin/settings.php writes the same numbers into its maxlength attributes
 * and REQUIRED into its required attributes, and
 * Tests\Service\SiteSettingsValidatorTest holds the two together. A key that
 * is not listed is never written by that endpoint, whatever a request sends,
 * so a save here cannot touch a setting another screen owns.
 *
 * ONLY THE SITE NAME IS REQUIRED, which is what the Setup Wizard has always
 * asked (App\Install\SetupWizard). Every other value may stay empty because
 * every reader already copes with an empty one: the footer and the contact
 * block leave the line out, App\Mail\EmailIdentity omits the part, the
 * invoice skips the row. A field is required because the application needs
 * it, not because it always was.
 *
 * THE E-MAIL ADDRESS IS OPTIONAL, WITH ONE EXCEPTION. It may not be emptied
 * while an active form depends on it to deliver what visitors send: a form
 * with no address of its own that keeps no submissions would lose every one
 * of them (App\Service\Forms\FormRecipient::losesSubmissions()). That is
 * refused here, naming the forms, rather than discovered later in a log.
 *
 * LENGTHS ARE CHECKED ON CHANGED VALUES ONLY. A browser does not enforce
 * maxlength on a value it did not see typed, so an older stored value that
 * happens to be longer must never be the reason an unrelated save fails.
 *
 * Pure: no request, no session, no database. The endpoint hands in what it
 * read, and the branding images stay in the endpoint because they need the
 * Media Library.
 */
final class SiteSettingsValidator
{
    /** @var array<string, int> key => the longest value it accepts */
    public const FIELDS = [
        'site_name' => 150,
        'email' => 150,
        'company_phone' => 30,
        'city_nl' => 150,
        'city_en' => 150,
        'footer_description_nl' => 500,
        'footer_description_en' => 500,
        'company_street' => 150,
        'company_house_number' => 20,
        'company_postal_code' => 20,
        'company_city' => 150,
        'company_country' => 2,
        'kvk_number' => 20,
        'seo_default_description' => Seo::MAX_META_DESCRIPTION_LENGTH,
        'seo_robots_index_default' => 1,
    ];

    /** @var list<string> */
    public const REQUIRED = ['site_name'];

    /**
     * @param array<string, mixed>       $post    the request body
     * @param array<string, string>      $current App\Service\SiteSettings::all()
     * @param list<array<string, mixed>> $forms   App\Repository\FormRepository::all()
     *
     * @return array{values: array<string, string>, errors: list<string>}
     *         `values` holds only the listed keys the request carried, trimmed
     *         and normalised: exactly what may be written
     */
    public static function validate(array $post, array $current, array $forms): array
    {
        $values = [];

        foreach (self::FIELDS as $key => $maxLength) {
            if (array_key_exists($key, $post) && is_scalar($post[$key])) {
                $values[$key] = trim((string) $post[$key]);
            }
        }

        $errors = [];

        foreach (self::REQUIRED as $key) {
            if (($values[$key] ?? null) === '') {
                $errors[] = AdminTranslator::trans('validation.veld_verplicht');
                break;
            }
        }

        foreach ($values as $key => $value) {
            if ($value === ($current[$key] ?? '') || mb_strlen($value) <= self::FIELDS[$key]) {
                continue;
            }

            $errors[] = $key === 'seo_default_description'
                ? 'Standaard meta description mag maximaal ' . Seo::MAX_META_DESCRIPTION_LENGTH . ' tekens zijn.'
                : AdminTranslator::trans('validation.a_field_is_too_long');
            break;
        }

        if (array_key_exists('email', $values)) {
            $emailError = self::emailError($values['email'], $current, $forms);

            if ($emailError !== null) {
                $errors[] = $emailError;
            }
        }

        /*
         * The indexing switch is stored as a CLOSED two-value enum, never as
         * whatever the request sent: it ends up in <meta name="robots">, and
         * arbitrary text must never reach a meta attribute through a settings
         * row. Anything but an explicit "1" is "0", and the form's hidden
         * companion field is what makes an unticked switch arrive at all.
         */
        if (array_key_exists('seo_robots_index_default', $values)) {
            $values['seo_robots_index_default'] = $values['seo_robots_index_default'] === '1' ? '1' : '0';
        }

        return ['values' => $values, 'errors' => $errors];
    }

    /**
     * The active forms that would lose their submissions if the site had no
     * contact address, by name.
     *
     * @param list<array<string, mixed>> $forms App\Repository\FormRepository::all()
     *
     * @return list<string>
     */
    public static function formsNeedingTheSiteAddress(array $forms): array
    {
        $names = [];

        foreach ($forms as $form) {
            if (FormRecipient::losesSubmissions($form, null)) {
                $names[] = (string) ($form['name'] ?? '');
            }
        }

        return $names;
    }

    /**
     * @param array<string, string>      $current
     * @param list<array<string, mixed>> $forms
     */
    private static function emailError(string $email, array $current, array $forms): ?string
    {
        if ($email !== '') {
            return FormRecipient::validAddress($email) === null
                ? AdminTranslator::trans('validation.ongeldig_e_mailadres')
                : null;
        }

        // Only the step from a working address to none is refused. An install
        // that never had one is already in that state; its screen says so
        // instead of blocking every other save on it.
        if (FormRecipient::validAddress($current['email'] ?? '') === null) {
            return null;
        }

        $names = self::formsNeedingTheSiteAddress($forms);

        return $names === []
            ? null
            : AdminTranslator::trans('validation.site_email_needed_by_forms', ['forms' => implode(', ', $names)]);
    }
}
