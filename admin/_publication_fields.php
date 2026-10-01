<?php

declare(strict_types=1);

use App\Service\Csrf;
use App\Service\Publishing\PublicationStatus;
use App\Service\Publishing\PublicationVisibility;
use App\Service\Publishing\Publishable;
use App\Service\Publishing\PublishingClock;

require_once __DIR__ . '/_translate.php';

/**
 * The Publishing Engine's admin primitives (docs/publishing/ARCHITECTURE.md, "In het CMS"):
 * the fields every editor of something that publishes needs, so a blog post
 * and a future article ask the same thing in the same words.
 *
 *   admin_publication_fields()  status + date, as one split row, for INSIDE
 *                               an owner's own editor form. Field names are
 *                               fixed: `status` and `published_at`.
 *   admin_publication_byline()  the optional public byline (free text, no
 *                               account), `author_name` by default.
 *   admin_publication_card()    a whole card with its own form, posting to
 *                               api/admin/update-publication.php by type and
 *                               id — for a screen that is not the owner's
 *                               editor (an overview, a side panel).
 *   admin_publication_flash()   the answer of that endpoint, once.
 *
 * Markup only, escaped here. Nothing in this file decides what may be saved:
 * the owner's endpoint or PublishingService runs PublicationRules on what
 * arrives. The statuses offered are the provider's own, never a request's.
 */

/**
 * @param array{
 *     statuses: list<string>,
 *     status: string,
 *     published_at: string,
 *     labels?: array<string, string>,
 *     date_label?: string,
 * } $options `published_at` is the value for the input (PublishingClock::forFormInput()
 *            of what is stored, or what the editor sent back after a refusal);
 *            `labels` the owner's words per status, else the shared ones
 */
function admin_publication_fields(array $options): string
{
    $h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    $current = PublicationStatus::normalize($options['status']);

    $html = '<div class="admin-form-row admin-form-row--split">'
        . '<label>' . admin_te('common.status')
        . '<select name="status">';

    foreach ($options['statuses'] as $status) {
        if (!PublicationStatus::isValid($status)) {
            continue;
        }

        $label = $options['labels'][$status] ?? admin_t(PublicationStatus::labelKey($status));
        $html .= '<option value="' . $h($status) . '"' . ($current === $status ? ' selected' : '') . '>' . $h($label) . '</option>';
    }

    $html .= '</select></label>'
        . '<label>' . $h($options['date_label'] ?? admin_t('publishing.field.date'))
        . '<input type="datetime-local" name="published_at" value="' . $h($options['published_at']) . '">'
        . '</label>'
        . '</div>';

    return $html;
}

/**
 * The public byline: whose name stands under the piece. Free text on
 * purpose — not an account, so a guest author needs no login, and a
 * colleague fixing a typo does not become the author.
 */
function admin_publication_byline(string $value, int $maxLength, string $label, string $placeholder = '', string $name = 'author_name'): string
{
    $h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');

    return '<div class="admin-form-row">'
        . '<label>' . $h($label)
        . '<input type="text" name="' . $h($name) . '" maxlength="' . $maxLength . '" value="' . $h($value) . '"'
        . ($placeholder !== '' ? ' placeholder="' . $h($placeholder) . '"' : '') . '>'
        . '</label>'
        . '</div>';
}

/**
 * A complete Publicatie card for one record, with its own form to
 * api/admin/update-publication.php. The kind and id go along as hidden
 * fields; the endpoint checks both, and the kind's permission, again.
 *
 * @param array{status: string, published_at: ?string} $publication what Publishable::publication() returned
 * @param string|null $csrfToken the session's token; read from the session when left out
 */
function admin_publication_card(Publishable $provider, int $id, array $publication, ?string $csrfToken = null): string
{
    $h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    $now = PublishingClock::now();
    $status = PublicationStatus::normalize($publication['status']);

    $state = match (true) {
        PublicationVisibility::isListed($status, $publication['published_at'], $now) => admin_t('publishing.state.listed'),
        PublicationVisibility::isReachable($status, $publication['published_at'], $now) => admin_t('publishing.state.archived'),
        PublicationVisibility::isPending($status, $publication['published_at'], $now) => admin_t('publishing.state.pending', ['date' => PublishingClock::forAdmin($publication['published_at'])]),
        default => admin_t('publishing.state.hidden'),
    };

    return '<section class="admin-card admin-publication">'
        . '<h2>' . admin_te('publishing.card.title') . '</h2>'
        . '<p class="admin-text-muted" data-publication-state>' . $h($state) . '</p>'
        . '<form method="post" action="/api/admin/update-publication.php">'
        . '<input type="hidden" name="csrf_token" value="' . $h($csrfToken ?? Csrf::token()) . '">'
        . '<input type="hidden" name="type" value="' . $h($provider->type()) . '">'
        . '<input type="hidden" name="id" value="' . $id . '">'
        . admin_publication_fields([
            'statuses' => $provider->statuses(),
            'status' => $status,
            'published_at' => PublishingClock::forFormInput($publication['published_at']),
        ])
        . '<p class="admin-text-muted">' . admin_te('publishing.help') . '</p>'
        . '<button type="submit">' . admin_te('publishing.card.save') . '</button>'
        . '</form>'
        . '</section>';
}

/** What api/admin/update-publication.php reported, shown once. */
function admin_publication_flash(): string
{
    $flash = $_SESSION['admin_publishing_flash'] ?? null;
    unset($_SESSION['admin_publishing_flash']);

    if (!is_array($flash) || ($flash['type'] ?? '') !== 'error' || !is_array($flash['messages'] ?? null) || $flash['messages'] === []) {
        return '';
    }

    $items = '';
    foreach ($flash['messages'] as $message) {
        $items .= '<li>' . htmlspecialchars((string) $message, ENT_QUOTES, 'UTF-8') . '</li>';
    }

    return '<div class="admin-alert admin-alert--error" role="alert"><ul class="admin-error-list">' . $items . '</ul></div>';
}
