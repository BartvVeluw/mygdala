<?php

declare(strict_types=1);

/**
 * The two identity tags every public page carries in its <head>: the browser
 * theme colour and the favicon.
 *
 * Both used to be copied into fifteen templates, and both were wrong there.
 * `<meta name="theme-color" content="#120D09">` was the palette's background
 * written out by hand, so a themed site would have kept a Van Veluw-coloured
 * browser chrome; `type="image/png"` was asserted whatever the favicon
 * actually was. They now come from one source each —
 * App\Service\Theme\ThemeCss for the colour it really renders, and
 * App\Service\Branding for the icon and its real type.
 *
 * Included by partials/page-assets.php rather than by each template, so
 * there is one include point and no template can forget it or drift.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use App\Service\Branding;
use App\Service\Theme\ThemeCss;

$brandingH = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');

$brandingFavicon = Branding::faviconPath();
$brandingFaviconType = Branding::faviconType();
?>
<meta name="theme-color" content="<?= $brandingH(ThemeCss::backgroundColor()) ?>">
<?php if ($brandingFavicon !== ''): ?>
<link rel="icon" href="<?= $brandingH($brandingFavicon) ?>"<?= $brandingFaviconType !== null ? ' type="' . $brandingH($brandingFaviconType) . '"' : '' ?>>
<?php endif; ?>
