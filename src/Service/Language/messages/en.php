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
    'language.primary' => 'Primary website language',
    'language.primary_help' => 'The language you write this website\'s content in. Everything falls back to it when a translation is missing.',
    'language.secondary' => 'Additional website language',
    'language.secondary_help' => 'Optional. Turn this on if you want to offer the website in a second language as well. With it off you edit one language only, and existing translations stay stored.',
    'language.secondary_none' => 'No additional language',
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

    // --- The sidebar, one key per navigation entry -------------------------
    'nav.dashboard' => 'Dashboard',
    'nav.pages' => 'Pages',
    'nav.media' => 'Media',
    'nav.forms' => 'Forms',
    'nav.content_blocks' => 'Content blocks',
    'nav.portfolio' => 'Portfolio',
    'nav.contact_requests' => 'Contact requests',
    'nav.form_submissions' => 'Submissions',
    'nav.navigation' => 'Navigation',
    'nav.footer' => 'Footer',
    'nav.header_footer' => 'Header & footer',
    'nav.settings' => 'Site settings',
    'nav.theme' => 'Design',
    'nav.redirects' => 'Redirects',
    'nav.users' => 'Users',
    'nav.blog_posts' => 'Blog posts',
    'nav.blog_categories' => 'Blog categories',
    'nav.blog_tags' => 'Blog tags',
    'nav.blog_settings' => 'Blog settings',
    'nav.personalization' => 'Personalisation',
    'nav.catalog' => 'Products',
    'nav.collections' => 'Collections',
    'nav.related_products' => 'Related products',
    'nav.shipping' => 'Shipping settings',
    'nav.carrier_rates' => 'Carrier rates',
    'nav.orders' => 'Orders',
    'nav.withdrawal_requests' => 'Return requests',

    // --- More words that appear on more than one screen --------------------
    'common.add' => 'Add',
    'common.new' => 'New',
    'common.status' => 'Status',
    'common.title' => 'Title',
    'common.url' => 'URL',
    'common.type' => 'Type',
    'common.saving' => 'Saving…',
    'common.save_failed' => 'Saving failed',
    'common.unsaved_changes' => 'Unsaved changes',
    'common.all_saved' => 'All changes saved',

    // --- The dashboard (admin/index.php) -----------------------------------
    'dashboard.title' => 'Dashboard',
    'dashboard.intro' => 'Welcome back. Below you can see how the site is doing and what still needs attention.',
    'dashboard.pages_failed' => 'The page data could not be loaded. Everything below still works.',
    'dashboard.no_permissions' => 'You currently have no permissions for any part of the CMS. Ask an administrator for access.',
    'dashboard.content_label' => 'Website content',
    'dashboard.published_pages' => 'Published pages',
    'dashboard.published_pages_note' => 'Visible to visitors.',
    'dashboard.drafts' => 'Drafts',
    'dashboard.drafts_note' => 'Not published yet — visible only inside the CMS.',
    'dashboard.where_to_work' => 'What would you like to work on?',
    'dashboard.card_content_title' => 'Website content',
    'dashboard.card_content_desc' => 'Change texts, sections and page content.',
    'dashboard.card_content_cta' => 'Manage pages',
    'dashboard.card_settings_title' => 'Site settings',
    'dashboard.card_settings_desc' => 'Manage general website settings.',
    'dashboard.card_settings_cta' => 'Open settings',
    'dashboard.card_users_title' => 'Users',
    'dashboard.card_users_desc' => 'Manage CMS accounts and their permissions.',
    'dashboard.card_users_cta' => 'Manage users',

    // --- The pages overview (admin/pages.php) ------------------------------
    'pages.title' => 'Pages',
    'pages.intro' => 'Every page on the website. Open a page to change its title, URL, status and SEO data, and to build its content with the page builder.',
    'pages.new' => 'New page',
    'pages.created' => 'Page created.',
    'pages.deleted' => 'Page deleted.',
    'pages.load_failed' => 'The pages could not be loaded.',
    'pages.empty' => 'No pages yet.',
    'pages.empty_link' => 'Create the first page',
    'pages.protected' => 'Protected',
    'pages.protected_hint' => 'The shop needs this page — title, SEO and content are editable, status and deletion are not.',
    'pages.fixed_url' => 'Content page (fixed URL)',
    'pages.fixed_url_hint' => 'An ordinary content page on a fixed URL — only the slug is set.',
    'pages.content_page' => 'Content page',
    'pages.delete_confirm' => 'Permanently delete this page and every section on it? This cannot be undone.',

    // --- The page editor (admin/page.php, admin/page-new.php) --------------
    'page.seo' => 'SEO',
    'page.meta_title' => 'SEO title',
    'page.meta_description' => 'Meta description',
    'page.google_preview' => 'Google preview',
    'page.no_description' => 'No meta description — Google will pick a piece of the page text itself.',
    'page.visibility' => 'Visibility',
    'page.noindex' => 'Keep this page out of search engine indexes',
    'page.save_settings' => 'Save settings',
    'page.status_draft' => 'Draft',
    'page.status_published' => 'Published',

    // --- Site settings (admin/settings.php) --------------------------------
    'settings.title' => 'Site settings',
    'settings.intro' => 'This information is used everywhere on the website (header, footer, contact page). Changes are visible on every page immediately.',
    'settings.saved' => 'Settings saved.',
    'settings.tabs_label' => 'Setting groups',
    'settings.tab_general' => 'General',
    'settings.tab_seo' => 'SEO',
    'settings.tab_invoices' => 'Invoices',
    'settings.tab_emails' => 'Emails',
    'settings.tab_dashboard' => 'Dashboard',

    // --- The save bar (admin/_save_bar.php + admin/assets/save-bar.js) -----
    'savebar.error_in' => 'Saving “:form” failed. Not everything was saved — please try again.',
];
