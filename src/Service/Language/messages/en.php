<?php

declare(strict_types=1);

/**
 * The CMS interface in English.
 *
 * Curated by people, never by a translation API — see the class docblock of
 * App\Service\Language\AdminTranslator for why. A key that is missing here
 * renders its Dutch text rather than an empty string, and
 * Tests\Service\AdminTranslatorTest fails so the gap is found in the suite
 * instead of on somebody's screen.
 *
 * Keep the keys in the same order as messages/nl.php.
 */
return [
    // --- The shell every admin screen renders -----------------------------
    'shell.menu' => 'Menu',
    'shell.nav_label' => 'Admin navigation',
    'shell.logout' => 'Sign out',
    'shell.role.super_admin' => 'Super Admin',
    'shell.role.user' => 'CMS user',
    'shell.my_account' => 'My account',

    // --- Words that appear on more than one screen -------------------------
    'common.save' => 'Save',
    'common.saved' => 'Saved',
    'common.cancel' => 'Cancel',
    'common.back' => 'Back',
    'common.delete' => 'Delete',
    'common.edit' => 'Edit',
    'common.optional' => 'Optional',
    'common.required' => 'Required',
    'common.yes' => 'Yes',
    'common.no' => 'No',
    'common.settings' => 'Settings',

    // --- The account screen (admin/account.php) ----------------------------
    'account.title' => 'My account',
    'account.intro' => 'Preferences that apply to your account only. They change nothing about the website or about what your colleagues see.',
    'account.signed_in_as' => 'Signed in as',
    'account.interface_language' => 'CMS language',
    'account.interface_language_help' => 'The language this admin panel is shown to you in. It changes nothing about the language of the website.',
    'account.saved' => 'Your preferences have been saved.',
    'account.break_glass' => 'You are signed in with the emergency access from the server configuration. That session has no account to store a preference on, so the CMS is running in its default language.',
    'account.website_language_hint' => 'Looking for the language of the website itself? That lives under Settings → Site settings.',

    // --- Language concepts, used on several screens ------------------------
    'language.website' => 'Website language',
    'language.cms' => 'CMS language',
    'language.primary' => 'Primary language',
    'language.primary_help' => 'The language you write this website\'s content in. Everything falls back to it when a translation is missing.',
    'language.secondary' => 'Second language',
    'language.secondary_help' => 'Optional. Turn this on if you want to offer the website in a second language as well. With it off you edit one language only, and existing translations stay stored.',
    'language.secondary_none' => 'No second language',
    'language.tab_label' => 'Choose a language',
    'language.not_translated' => 'Not translated yet',
    'language.settings_title' => 'Languages',
    'language.settings_intro' => 'Decide which language this website is written in and whether a translation joins it. The language of the CMS itself is chosen per person under My account.',
    'language.disabled_preserved' => 'Turning a language off deletes nothing. Whatever is already translated stays stored and comes back the moment you turn the language on again.',

    // --- Automatic translation --------------------------------------------
    'translate.action' => 'Translate to :language',
    'translate.missing_only' => 'Translate missing fields',
    'translate.section' => 'Translate this section',
    'translate.busy' => 'Translating…',
    'translate.done' => 'Translated. Check the text before you save.',
    'translate.failed' => 'Automatic translation failed: :reason',
    'translate.unavailable' => 'Automatic translation is not configured on this installation.',
    'translate.unavailable_help' => 'A server administrator can configure a translation service in the .env file. Translating by hand works as usual.',
    'translate.outdated' => 'The source text changed after this translation was made.',
    'translate.outdated_action' => 'Translate again',
    'translate.manual' => 'Edited by hand',
    'translate.machine' => 'Machine translated',
    'translate.confirm_overwrite' => 'This translation was edited by hand. Are you sure you want to overwrite it?',
];
