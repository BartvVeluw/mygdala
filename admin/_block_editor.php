<?php

declare(strict_types=1);

require_once __DIR__ . '/_translate.php';

use App\Repository\ContentBlockDraftRepository;
use App\Repository\PageRepository;

/**
 * The top of a block editor while the block is NEW (Content Blocks Lifecycle
 * 1.0, CONTENT-BLOCKS.md, "De levensloop van een nieuw blok"): chosen in the
 * picker, not saved yet, and therefore not on its page
 * (App\Service\Blocks\ContentBlockDrafts). It says so, and its Annuleren
 * posts api/admin/discard-block-draft.php, which removes the block and lands
 * on the page as it was. Opslaan in the editor's own form places the block
 * and lands there too.
 *
 * HOW AN EDITOR USES IT — around the "terug naar …" link it already had:
 *
 *     <?php if (!block_editor_draft_notice('rich_text', $csrfToken)): ?>
 *       <p class="admin-text-muted"><a href="…">Terug naar …</a></p>
 *     <?php endif; ?>
 *
 * For an existing block it prints nothing and returns false, so the editor
 * is exactly what it was: leaving it is leaving without saving, and nothing
 * is ever removed. The block is read from the editor's own `?section=`,
 * which the editor has already checked (the list exists, the signed-in user
 * may manage it); only a draft of this type with this key on that list
 * counts.
 */
function block_editor_draft_notice(string $sectionType, string $csrfToken): bool
{
    $sectionParam = (string) ($_GET['section'] ?? '');
    [$pageSlug, $sectionKey] = array_pad(explode(':', $sectionParam, 2), 2, null);

    if ($pageSlug === null || $sectionKey === null || $pageSlug === '' || $sectionKey === '') {
        return false;
    }

    $page = (new PageRepository())->findByContentKey($pageSlug);
    $draft = $page === null ? null : (new ContentBlockDraftRepository())->findOnPage((int) $page['id'], $sectionType, $sectionKey);

    if ($draft === null) {
        return false;
    }

    $h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    ?>
  <?php /* One line, in the note style of the CMS: information to act on,
           not a warning. Annuleren is a form of its own (never inside the
           editor's form) and an .admin-inline-form, so the save bar neither
           watches it nor asks "leave this page?" when it is the answer. */ ?>
  <div class="admin-alert admin-alert--note admin-block-draft" data-block-draft>
    <p class="admin-block-draft__text"><strong><?= admin_te('blocks.draft_title') ?></strong> <?= admin_te('blocks.draft_text') ?></p>
    <form method="post" action="/api/admin/discard-block-draft.php" class="admin-inline-form admin-block-draft__cancel">
      <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
      <input type="hidden" name="section_type" value="<?= $h($sectionType) ?>">
      <input type="hidden" name="section" value="<?= $h($sectionParam) ?>">
      <button type="submit" class="admin-btn-secondary" data-save-bar-discard><?= admin_te('blocks.draft_cancel') ?></button>
    </form>
  </div>
    <?php

    return true;
}
