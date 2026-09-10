<?php

declare(strict_types=1);

/**
 * Shared server-side validation and PRG plumbing for the personalization
 * builder endpoints (views and zones). Included by every
 * api/admin/*-personalization-*.php endpoint so all of them apply exactly the
 * same rules — the same reason api/admin/_product_validation.php exists.
 *
 * Nothing here decides what a valid font, area, key or surcharge is; those
 * live in App\Service\Personalization\PersonalizationRules and
 * ...\PersonalizationFonts, and this file is the request-parsing glue around
 * them.
 */

use App\Service\Personalization\PersonalizationRules;

/**
 * Back to the product's personalization editor — always a literal local path
 * built from a server-validated integer id, never anything from the request,
 * so this can't become an open redirect.
 *
 * Since Phase 3 that editor is admin/personalization-product.php, not the
 * product form: personalization has its own CMS section, and every one of
 * these endpoints belongs to it.
 */
function personalizationRedirect(int $productId, string $query = ''): never
{
    header('Location: /admin/personalization-product.php?product_id=' . $productId . ($query === '' ? '' : '&' . $query) . '#personalisatie');
    exit;
}

/**
 * Re-renders the card with the administrator's own errors, and (when given)
 * the input they just submitted so a rejected save does not throw their work
 * away. `$context` says WHICH form the input belongs to, so only that form
 * repopulates — a rejected zone edit must not spill into its neighbour.
 *
 * @param list<string> $errors
 * @param array<string, mixed> $old
 */
function personalizationFail(int $productId, array $errors, array $context = [], array $old = []): never
{
    $_SESSION['admin_personalization_errors'] = $errors;
    $_SESSION['admin_personalization_old'] = $old === [] ? null : $context + ['fields' => $old];

    personalizationRedirect($productId);
}

/**
 * A zone or view key as typed by the administrator. Strict on purpose: the
 * key ends up in order rows and identifies that zone for the lifetime of
 * every order that used it.
 *
 * @param list<string> $errors
 */
function normalizePersonalizationKey(mixed $submitted, string $what, array &$errors): string
{
    $key = is_string($submitted) ? strtolower(trim($submitted)) : '';

    if ($key === '') {
        $errors[] = "Geef {$what} een sleutel (bijvoorbeeld \"voorkant\" of \"naam\").";
        return '';
    }

    if (!PersonalizationRules::isValidKey($key)) {
        $errors[] = "De sleutel van {$what} mag alleen kleine letters, cijfers, - en _ bevatten (maximaal 32 tekens) en moet met een letter of cijfer beginnen.";
        return '';
    }

    return $key;
}

/**
 * @param array<string, mixed> $input raw $_POST
 * @param list<string> $errors
 * @return array{label: ?string, label_en: ?string}
 */
function normalizePersonalizationViewInput(array $input, array &$errors): array
{
    $label = trim((string) ($input['label'] ?? ''));
    $labelEn = trim((string) ($input['label_en'] ?? ''));

    if (mb_strlen($label) > 100 || mb_strlen($labelEn) > 100) {
        $errors[] = 'De naam van een weergave mag maximaal 100 tekens zijn.';
    }

    return [
        'label' => $label === '' ? null : $label,
        'label_en' => $labelEn === '' ? null : $labelEn,
    ];
}

/**
 * Everything a zone owns except its key and its view, both of which are set
 * once at creation and never rewritten.
 *
 * @param array<string, mixed> $input raw $_POST
 * @param list<string> $errors
 * @return array<string, mixed>
 */
function normalizePersonalizationZoneInput(array $input, array &$errors): array
{
    $label = trim((string) ($input['label'] ?? ''));
    $labelEn = trim((string) ($input['label_en'] ?? ''));
    $instructions = trim((string) ($input['instructions'] ?? ''));
    $instructionsEn = trim((string) ($input['instructions_en'] ?? ''));
    $placeholder = trim((string) ($input['placeholder'] ?? ''));
    $placeholderEn = trim((string) ($input['placeholder_en'] ?? ''));

    if (mb_strlen($label) > 100 || mb_strlen($labelEn) > 100) {
        $errors[] = 'De naam van een zone mag maximaal 100 tekens zijn.';
    }

    if (mb_strlen($placeholder) > 100 || mb_strlen($placeholderEn) > 100) {
        $errors[] = 'De voorbeeldtekst mag maximaal 100 tekens zijn.';
    }

    if (mb_strlen($instructions) > 500 || mb_strlen($instructionsEn) > 500) {
        $errors[] = 'De uitleg bij een zone mag maximaal 500 tekens zijn.';
    }

    $allowText = ($input['allow_text'] ?? null) === '1';
    $allowImage = ($input['allow_image'] ?? null) === '1';

    if (!$allowText && !$allowImage) {
        $errors[] = 'Kies wat er in deze zone mag: tekst, een afbeelding, of allebei.';
    }

    $area = PersonalizationRules::validateArea([
        'x' => $input['area_x'] ?? null,
        'y' => $input['area_y'] ?? null,
        'width' => $input['area_width'] ?? null,
        'height' => $input['area_height'] ?? null,
    ], $errors);

    $surchargeCents = PersonalizationRules::validateSurcharge($input['surcharge'] ?? null, $errors);

    // No font fields: since Phase 3 fonts are a GLOBAL library the customer
    // picks from (App\Service\Personalization\PersonalizationFonts), not
    // something a zone configures. The zone's legacy font columns are written
    // as NULL by the repository and read by nothing.

    return [
        'label' => $label === '' ? null : $label,
        'label_en' => $labelEn === '' ? null : $labelEn,
        'instructions' => $instructions === '' ? null : $instructions,
        'instructions_en' => $instructionsEn === '' ? null : $instructionsEn,
        'placeholder' => $placeholder === '' ? null : $placeholder,
        'placeholder_en' => $placeholderEn === '' ? null : $placeholderEn,
        'allow_text' => $allowText,
        'allow_image' => $allowImage,
        'is_enabled' => ($input['is_enabled'] ?? null) === '1',
        'is_required' => ($input['is_required'] ?? null) === '1',
        'allow_rotation' => ($input['allow_rotation'] ?? null) === '1',
        'max_text_length' => PersonalizationRules::clampMaxTextLength($input['max_text_length'] ?? null),
        'default_font' => null,
        'allowed_fonts' => [],
        'surcharge_cents' => $surchargeCents,
        'area_x' => $area['x'],
        'area_y' => $area['y'],
        'area_width' => $area['width'],
        'area_height' => $area['height'],
    ];
}
