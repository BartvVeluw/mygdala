<?php

require_once __DIR__ . '/eyebrow.php';

/**
 * The optional editorial head of a block (App\Service\Blocks\BlockHead): the
 * shared `.section-head` with an eyebrow, an <h2> title and a `.lead` text,
 * the markup every block heading on the site already uses, so the heading
 * tokens, the spacing and a page theme's colours apply on their own.
 *
 * Prints nothing at all when the three are empty: no wrapper, no gap. Plain
 * text only; escaped here.
 *
 * @param array<string, mixed> $head 'eyebrow', 'title', 'lead' in the request's language
 */
function render_section_head(array $head, string $extraClass = ''): void
{
    if (!\App\Service\Blocks\BlockHead::has($head)) {
        return;
    }

    $h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    $title = trim((string) ($head['title'] ?? ''));
    $lead = trim((string) ($head['lead'] ?? ''));
    $class = trim('section-head ' . $extraClass);
    ?>
      <div class="<?= $h($class) ?>" data-reveal>
        <?php render_eyebrow((string) ($head['eyebrow'] ?? '')); ?>
        <?php if ($title !== ''): ?>
        <h2><?= $h($title) ?></h2>
        <?php endif; ?>
        <?php if ($lead !== ''): ?>
        <p class="lead"><?= $h($lead) ?></p>
        <?php endif; ?>
      </div>
<?php
}
