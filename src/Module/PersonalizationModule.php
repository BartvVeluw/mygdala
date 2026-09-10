<?php

declare(strict_types=1);

namespace App\Module;

use App\Service\AppUrl;
use App\Service\Personalization\PersonalizationCatalog;
use App\Service\Sitemap;

/**
 * Personalisatie as a first-party module: the customer designs text and
 * images on a product before ordering it.
 *
 * DEPENDS ON THE SHOP, and that dependency is the reason this class exists
 * separately rather than being folded into ShopModule. A personalization is
 * configured ON A PRODUCT and its result is recorded ON AN ORDER LINE
 * (App\Repository\OrderItemPersonalizationRepository); without products and
 * orders the domain has no meaning at all. So it is never enabled on its own:
 * ModuleRegistry::enabled() drops it whenever the Shop is off, whatever
 * MODULE_PERSONALIZATION_ENABLED says, and logs one line explaining why.
 * Running it half-on — an admin section configuring products that do not
 * exist, a checkout writing personalization rows for orders nothing reads —
 * is the failure mode that rule exists to prevent.
 *
 * The reverse is not true: the Shop knows nothing about personalization
 * beyond a per-order-line price surcharge, so the Shop runs perfectly well
 * with this module off.
 */
final class PersonalizationModule extends ModuleDefinition
{
    public const PERSONALIZATION_MANAGE = 'personalization.manage';

    public function key(): string
    {
        return 'personalization';
    }

    public function label(): string
    {
        return 'Personalisatie';
    }

    public function description(): string
    {
        return 'Laat klanten tekst of een afbeelding op een product ontwerpen voordat ze bestellen.';
    }

    public function dependencies(): array
    {
        return ['shop'];
    }

    public function adminNavigationItems(): array
    {
        return [
            [
                // Its own top-level section, deliberately NOT a card inside
                // the product editor: personalization has an overview, a
                // per-product configuration screen and a global font library
                // of its own. It sits inside the Shop's group because that is
                // where the products it configures are.
                'key' => 'personalization',
                'label' => 'Personalisatie',
                'url' => '/admin/personalization.php',
                'icon' => 'personalization',
                'permission' => self::PERSONALIZATION_MANAGE,
                'order' => 330,
                'scripts' => [
                    'personalization.php',
                    'personalization-product.php',
                    'personalization-fonts.php',
                ],
            ],
        ];
    }

    public function permissionGroups(): array
    {
        return [
            [
                'label' => 'Personalisatie',
                'order' => 250,
                'permissions' => [
                    self::PERSONALIZATION_MANAGE => [
                        'label' => 'Personalisatie beheren',
                        'description' => 'Instellen welke producten gepersonaliseerd kunnen worden, met hun eigen voorbeeldafbeeldingen en zones, plus de globale lettertypebibliotheek.',
                    ],
                ],
            ],
        ];
    }

    public function reservedSlugs(): array
    {
        return ['personaliseren'];
    }

    public function sitemapCollectors(): array
    {
        return [
            'personalization' => static function (): array {
                // The Personalisatie catalogue is a real public page, but a
                // FIXED template with no `pages` row, so it cannot come out of
                // the pages query. It is listed only while it actually has
                // something on it — an empty catalogue page has no business
                // being advertised to a crawler. No lastmod: the page has no
                // row of its own to take one from.
                if (PersonalizationCatalog::forPublicPage() === null) {
                    return [];
                }

                return [Sitemap::entryFor(AppUrl::canonical(PersonalizationCatalog::publicPath()), null)];
            },
        ];
    }
}
