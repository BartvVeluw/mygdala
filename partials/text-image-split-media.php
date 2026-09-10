<?php

/**
 * Renders the image side of a "Text + image split" section
 * (App\Service\TextImageSplitContent) — the theme owns which fixed markup
 * branch is used, picked purely from the image count:
 *
 *   - exactly 1 image  -> `.hero__media-frame` (single framed photo,
 *     aspect-ratio 4/5, object-fit cover) — same markup as over-mij.php's
 *     original "intro" section.
 *   - exactly 2 images -> `.service-detail__gallery` with the
 *     `grid-template-columns:1fr 1fr` inline-style override — same markup
 *     as over-mij.php's original "idee-naar-product" section.
 *   - 3+ images -> `.service-detail__gallery` using its own default 3-col
 *     grid (no override) — not used by any current instance, but keeps
 *     this section type reusable without another schema/template change.
 *
 * No markup/layout data is ever stored in the database — only the media
 * reference, alt_nl/alt_en and sort_order per image.
 *
 * width/height are printed whenever the Media Library knows them, so the
 * browser can reserve the space before the image arrives. Unknown means the
 * attributes are simply left off — never guessed, never zero. Lazy loading is
 * unchanged.
 *
 * @param array<int, array{image_path:string, alt_nl:string, alt_en:string, width:int|null, height:int|null}> $images
 * @param string|null $revealGroup optional `data-reveal-group` value for the gallery variant
 */
function render_text_image_split_media(array $images, ?string $revealGroup = null): void
{
    $h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    $count = count($images);

    if ($count === 1) {
        $image = $images[0];
        ?>
        <div class="hero__media-frame" style="aspect-ratio:4/5;" data-reveal>
          <img src="<?= $h($image['image_path']) ?>" alt="<?= $h($image['alt_nl']) ?>" data-nl-alt="<?= $h($image['alt_nl']) ?>" data-en-alt="<?= $h($image['alt_en']) ?>"<?= \App\Service\Media\BlockImage::dimensionAttributes($image) ?> loading="lazy" style="object-fit:cover; width:100%; height:100%;">
        </div>
        <?php
        return;
    }

    $galleryStyle = $count === 2 ? ' style="grid-template-columns:1fr 1fr; margin-top:0;"' : ' style="margin-top:0;"';
    $revealGroupAttr = $revealGroup !== null ? ' data-reveal-group="' . $h($revealGroup) . '"' : '';
    ?>
    <div class="service-detail__gallery"<?= $galleryStyle ?> data-reveal<?= $revealGroupAttr ?>>
      <?php foreach ($images as $image): ?>
        <img src="<?= $h($image['image_path']) ?>" alt="<?= $h($image['alt_nl']) ?>" data-nl-alt="<?= $h($image['alt_nl']) ?>" data-en-alt="<?= $h($image['alt_en']) ?>"<?= \App\Service\Media\BlockImage::dimensionAttributes($image) ?> loading="lazy">
      <?php endforeach; ?>
    </div>
    <?php
}
