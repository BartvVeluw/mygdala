<?php

declare(strict_types=1);

require_once __DIR__ . '/_translate.php';
require_once __DIR__ . '/_block_picker.php';
require_once __DIR__ . '/_admin_collapse.php';

use App\Service\SectionRegistry;

/**
 * THE block list of one page, shared by every screen that edits one
 * (Product & Portfolio Content Pages 1.0): an ordinary page (admin/page.php,
 * its Inhoud tab) and the content page of a product or project (their
 * Pagina-inhoud tab, content_blocks_owner_panel() below). One list, one
 * picker, one set of endpoints — no second editor per owner.
 *
 * The list itself is ONE draggable list of every block on the page (edit /
 * hide / delete), each row a <details> that folds down to one identifying
 * line (admin/_admin_collapse.php), with the "+ Contentblok toevoegen"
 * control always directly beneath it. Editing a block opens that block type's
 * own dedicated editor; there is no generic block form. What the rows mean
 * is documented where they are drawn, below.
 *
 * @param array<string, mixed> $page a `pages` row, or ContentPages::placeholder() for an owner without one yet
 * @param list<array<string, mixed>> $allSections the page's page_sections rows
 * @param array<string, \App\Service\Blocks\BlockDefinition> $availableBlocks SectionRegistry::availableDefinitionsForPage()
 * @param int $savedSectionId the block whose editor just saved (`?saved=<id>`, ContentBlockAccess::afterSaveUrl())
 */
function content_blocks_list(array $page, array $allSections, array $availableBlocks, string $csrfToken, int $addedSectionId, int $savedSectionId = 0): void
{
    $h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    $pageId = (int) $page['id'];

    /**
     * The badge a fixed block wears, per SectionRegistry::kind() — the same
     * visual language the page builder already used, now driven by the one
     * registry instead of a second one alongside it.
     */
    $kindMeta = [
        SectionRegistry::KIND_FUNCTIONAL => ['label' => 'Functioneel (thema)', 'badge' => 'admin-badge--theme'],
        SectionRegistry::KIND_DYNAMIC => ['label' => 'Beheerd elders', 'badge' => 'admin-badge--info'],
    ];
    ?>
  <section class="admin-card">
    <?php /* Both roles on one element: the drop zone the reorder script
             already used, and the collapse group whose open rows and return
             target are remembered per page (admin/_admin_collapse.php). */ ?>
    <div class="admin-page-sections" data-page-section-zone data-reorder-url="/api/admin/reorder-page-sections.php" data-csrf-token="<?= $h($csrfToken) ?>" data-page-id="<?= $pageId ?>" data-admin-collapse-group="page-blocks" data-admin-collapse-scope="<?= $pageId ?>">
      <?php foreach ($allSections as $pageSection): ?>
        <?php
          $sectionType = (string) $pageSection['section_type'];
          $isHidden = !(bool) $pageSection['is_active'];
          $note = SectionRegistry::note($sectionType);
          $kind = SectionRegistry::kind($sectionType);
          // A row naming a block type this CMS does not currently register.
          // The public page skips it — see SectionRegistry::render() — so
          // without this the editor would see an ordinary-looking row that
          // simply never appears on the site.
          //
          // Two different reasons, and the editor must not confuse them. A
          // block belonging to a module that is switched OFF is expected and
          // fully reversible: turning the module back on restores it exactly
          // as it was. A type nothing declares at all is data that has
          // outlived its code and is worth reporting. Neither is ever
          // deleted, and neither uses the stored type for anything but
          // printing it.
          $isUnsupported = !SectionRegistry::exists($sectionType);
          $disabledModule = $isUnsupported ? SectionRegistry::disabledModuleFor($sectionType) : null;
          $isJustAdded = $addedSectionId === (int) $pageSection['id'];

          // The block whose editor just saved (Content Blocks Lifecycle 1.0):
          // it opens, glows once like a new row, wears "Opgeslagen" and is
          // the row admin-collapse.js brings into view, on its tab.
          $isJustSaved = $savedSectionId === (int) $pageSection['id'];

          // The one line a collapsed row shows. For an ordinary block that
          // is the registry's own instance label — "Tekstblok — Over onze
          // diensten" — so no block type has to invent a summary of its own,
          // and a type without a title simply shows its name.
          if ($disabledModule !== null) {
              $rowLabel = 'Blok van een uitgeschakeld onderdeel';
          } elseif ($isUnsupported) {
              $rowLabel = 'Niet-ondersteund contentblok';
          } else {
              $rowLabel = SectionRegistry::instanceLabel($pageSection);
          }
        ?>
        <?php /* One id per row, and always the same one: #blok-<id> is what
                 api/admin/add-page-section.php sends a new block to, what a
                 link from anywhere else can point at, and what
                 admin-collapse.js scrolls back to after an edit. */ ?>
        <div class="admin-section-row admin-page-section-row<?= $isHidden ? ' is-hidden-section' : '' ?><?= $isJustAdded ? ' is-just-added' : '' ?><?= $isJustSaved ? ' is-just-saved' : '' ?>" id="blok-<?= (int) $pageSection['id'] ?>" data-page-section-id="<?= (int) $pageSection['id'] ?>">
          <?php /* Outside the <details> on purpose: a collapsed row must
                   still be draggable, and that is most of the reason to
                   collapse rows at all. */ ?>
          <span class="admin-drag-handle" draggable="true" role="button" tabindex="0" aria-label="Sleep om te herordenen">&#8801;</span>
          <?php /* One generic disclosure per block, whatever its type: the
                   browser gives us click, Enter/Space, the tab order and the
                   expanded/collapsed state for free, and no block type has
                   to know it exists (admin/_admin_collapse.php). A block
                   that was just added opens itself. */ ?>
          <details class="admin-collapse" data-admin-collapse-id="<?= (int) $pageSection['id'] ?>"<?= $isJustAdded ? ' open data-admin-collapse-open' : '' ?><?= $isJustSaved ? ' open data-admin-collapse-open data-admin-collapse-focus' : '' ?>>
            <summary class="admin-collapse__summary">
              <span class="admin-collapse__caret" aria-hidden="true"></span>
              <span class="admin-section-row__name admin-collapse__title"><?= $h($rowLabel) ?></span>
              <span class="admin-collapse__badges">
                <?php if ($disabledModule !== null): ?>
                  <span class="admin-badge admin-badge--info">Onderdeel uit</span>
                <?php elseif ($isUnsupported): ?>
                  <span class="admin-badge admin-badge--warning"><?= admin_te('page.not_supported') ?></span>
                <?php endif; ?>
                <?php if ($isJustSaved): ?>
                  <span class="admin-badge admin-badge--saved"><?= admin_te('blocks.saved_badge') ?></span>
                <?php endif; ?>
                <?php if ($isHidden): ?>
                  <span class="admin-badge admin-badge--muted">Verborgen</span>
                <?php endif; ?>
                <?php if ($kind !== null && isset($kindMeta[$kind])): ?>
                  <span class="admin-badge <?= $h($kindMeta[$kind]['badge']) ?>"><?= $h(SectionRegistry::badgeLabel($sectionType) ?? $kindMeta[$kind]['label']) ?></span>
                <?php endif; ?>
              </span>
            </summary>
            <div class="admin-collapse__body">
              <div class="admin-section-row__body">
                <?php if ($disabledModule !== null): ?>
                  <p class="admin-section-row__note"><?= admin_t('page.type_onderdeel', ['v1' => $h($sectionType), 'v2' => $h(\App\Module\ModuleRegistry::label($disabledModule))]) ?></p>
                  <p class="admin-section-row__note"><?= admin_te('page.blok_hoort_onderdeel_moment') ?></p>
                <?php elseif ($isUnsupported): ?>
                  <p class="admin-section-row__note"><?= admin_t('page.type', ['v1' => $h($sectionType)]) ?></code></p>
                  <p class="admin-section-row__note"><?= admin_te('page.blok_kon_geladen_pagina') ?></p>
                <?php elseif ($note !== null): ?>
                  <p class="admin-section-row__note"><?= $h($note) ?></p>
                <?php endif; ?>
                <?php if ($isHidden): ?>
                  <p class="admin-section-row__note"><?= admin_t('page.verborgen_getoond_pagina') ?></p>
                <?php endif; ?>
              </div>
              <div class="admin-section-row__actions">
                <?php foreach (SectionRegistry::editLinks($pageSection) as $editLink): ?>
                  <a href="<?= $h($editLink['url']) ?>" class="admin-section-row__edit"><?= $h($editLink['label']) ?> &#8594;</a>
                <?php endforeach; ?>
                <?php /* Real buttons from the admin family, not text links:
                         both change what visitors see. The toggle's word says
                         what pressing it does; the badge in the summary says
                         the state, so neither rests on colour alone. */ ?>
                <form method="post" action="/api/admin/toggle-page-section.php" class="admin-inline-form">
                  <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
                  <input type="hidden" name="id" value="<?= (int) $pageSection['id'] ?>">
                  <input type="hidden" name="is_active" value="<?= $isHidden ? '1' : '0' ?>">
                  <button type="submit" class="admin-btn-secondary admin-section-row__button"><?= $isHidden ? admin_te('common.show') : admin_te('common.hide') ?></button>
                </form>
                <?php if (SectionRegistry::isDeletable($sectionType)): ?>
                <?php /* Asks first, in the CMS's shared dialog printed at the end
                         of this screen, and names the block that would go. The
                         form, its token and the endpoint's guards are exactly
                         what they were. */ ?>
                <form method="post" action="/api/admin/delete-page-section.php" class="admin-inline-form admin-section-row__delete"<?= admin_confirm_attributes(
                    admin_t('page.block_delete_title'),
                    admin_t('page.block_delete_message', ['block' => $rowLabel]),
                    admin_t('common.delete')
                ) ?>>
                  <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
                  <input type="hidden" name="id" value="<?= (int) $pageSection['id'] ?>">
                  <button type="submit" class="admin-btn-danger admin-section-row__button"><?= admin_te('common.delete') ?></button>
                </form>
                <?php endif; ?>
              </div>
            </div>
          </details>
        </div>
      <?php endforeach; ?>
    </div>

    <?php /* Always the LAST thing under the block list, so "toevoegen" adds
             where the editor is looking — api/admin/add-page-section.php
             appends to the bottom of that same list. One button, and the
             choice itself happens in the picker it opens
             (admin/_block_picker.php): the old "kies eerst een type uit een
             lijst namen, druk dán op toevoegen" is gone.

             While the page has nothing below its heading, that button is an
             invitation instead: a sentence saying so, and the same opener.
             A hidden block counts as content — it is the editor's own. */ ?>
    <?php if (!SectionRegistry::hasContentBlocks($allSections)): ?>
      <?php block_picker_empty_state($availableBlocks !== []); ?>
    <?php elseif ($availableBlocks !== []): ?>
      <?php block_picker_button(); ?>
    <?php else: ?>
      <p class="admin-text-muted"><?= admin_te('page.er_pagina_moment_contentblok') ?></p>
    <?php endif; ?>
  </section>
<?php
}

/**
 * The Pagina-inhoud tab of a product or project: the owner's content page
 * through content_blocks_list(), with the notices admin/page.php would have
 * shown for it (a block deleted, a block added, a refused add), since the
 * endpoints send an editor who lands on a content page to the owner's own
 * editor. An owner without a content page yet gets the empty list and the
 * picker: its first block makes one (api/admin/add-page-section.php).
 *
 * Print content_blocks_owner_modals() once near the end of the same screen.
 */
function content_blocks_owner_panel(string $kind, int $ownerId, string $csrfToken): void
{
    $state = content_blocks_owner_state($kind, $ownerId);
    $error = $_SESSION['admin_pages_error'] ?? null;
    unset($_SESSION['admin_pages_error']);

    if (isset($_GET['deleted'])) {
        echo '<p class="admin-alert admin-alert--success">' . admin_te('page.sectie_verwijderd') . '</p>';
    }

    $saved = content_blocks_saved_section($state['sections']);
    if ($saved !== 0) {
        echo '<p class="admin-alert admin-alert--success">' . admin_te('blocks.saved_notice') . '</p>';
    }

    if (is_string($error) && $error !== '') {
        echo '<p class="admin-alert admin-alert--error">' . htmlspecialchars($error, ENT_QUOTES, 'UTF-8') . '</p>';
    }

    $added = filter_input(INPUT_GET, 'added', FILTER_VALIDATE_INT) ?: 0;
    content_blocks_list($state['page'], $state['sections'], $state['available'], $csrfToken, (int) $added, $saved);
}

/**
 * The block a block editor's save just landed here with (`?saved=<id>`,
 * ContentBlockAccess::afterSaveUrl()), or 0 — also when the id is not one of
 * THIS list's rows, so a hand-made URL cannot make the list claim a save.
 *
 * @param list<array<string, mixed>> $sections the list's page_sections rows
 */
function content_blocks_saved_section(array $sections): int
{
    $saved = filter_input(INPUT_GET, 'saved', FILTER_VALIDATE_INT) ?: 0;

    foreach ($sections as $section) {
        if ((int) $section['id'] === $saved) {
            return $saved;
        }
    }

    return 0;
}

/**
 * The block picker and the shared confirmation dialog for an owner's panel,
 * posting the owner rather than a page id while it has no content page.
 */
function content_blocks_owner_modals(string $kind, int $ownerId, string $csrfToken): void
{
    $state = content_blocks_owner_state($kind, $ownerId);
    $pageId = (int) $state['page']['id'];

    block_picker_modal(
        $state['available'],
        $pageId,
        $csrfToken,
        $pageId > 0 ? [] : ['content_owner' => $kind, 'content_owner_id' => (string) $ownerId]
    );
    echo admin_confirm_dialog();
}

/** The scripts the block list needs: the picker, its previews and the collapsing rows. */
function content_blocks_scripts(): void
{
    ?>
<script src="<?= \App\Service\AssetVersion::url('/admin/assets/block-picker.js') ?>" defer></script>
<script src="<?= \App\Service\AssetVersion::url('/admin/assets/block-library.js') ?>" defer></script>
    <?php
}

/**
 * @return array{page: array<string, mixed>, sections: list<array<string, mixed>>, available: array<string, \App\Service\Blocks\BlockDefinition>}
 */
function content_blocks_owner_state(string $kind, int $ownerId): array
{
    static $states = [];
    $key = $kind . ':' . $ownerId;

    if (!isset($states[$key])) {
        $page = \App\Service\ContentOwners\ContentPages::pageFor($kind, $ownerId)
            ?? \App\Service\ContentOwners\ContentPages::placeholder($kind, $ownerId);
        $repository = new \App\Repository\PageSectionRepository();
        $sections = (int) $page['id'] > 0 ? $repository->findForPage((int) $page['id']) : [];
        \App\Service\Blocks\BlockLocalization::preloadSections($sections);

        $states[$key] = [
            'page' => $page,
            'sections' => $sections,
            'available' => SectionRegistry::availableDefinitionsForPage($page, $repository),
        ];
    }

    return $states[$key];
}
