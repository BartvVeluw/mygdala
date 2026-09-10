<?php

declare(strict_types=1);

use App\Install\InstallState;
use Phinx\Migration\AbstractMigration;

/**
 * Gallery images for the services repeater (see
 * 20260905020000_create_services_table.php's docblock) —
 * `.service-detail__gallery` under Hout and Metaal today (3 images each);
 * Acryl & glas and Zakelijk currently have none.
 *
 * Deliberately its own table, NOT a reuse of portfolio_gallery_items: a
 * service gallery image has no independent identity (no title/subtitle/
 * category, never appears in the standalone Portfolio grid or its lightbox)
 * — it is passive supporting media for one specific service's text, same
 * semantics as text_image_split_images. Reusing the Portfolio Gallery would
 * force meaningless fields onto this content and let an image accidentally
 * show up filtered into the public portfolio grid. See this migration's
 * sibling text_image_split_images for the identical reasoning.
 *
 * No is_active column — same reasoning as text_image_split_images and
 * service_paragraphs: there is no meaningful "hidden but kept" state for a
 * single gallery image, deleting it is enough. sort_order uses the same
 * "swap with neighbour" convention as every other repeater here.
 *
 * image_path values are seeded with the site's EXISTING shared image paths
 * (assets/images/*.webp) — not admin-uploaded files, so
 * App\Service\SectionImageUploader::delete() (which only ever touches files
 * under assets/images/sections/) will never remove them, even if an admin
 * later replaces one via the admin editor.
 */
final class CreateServiceImagesTable extends AbstractMigration
{
    public function up(): void
    {
        $table = $this->table('service_images', ['id' => true]);
        $table
            ->addColumn('service_id', 'integer', ['signed' => false])
            ->addColumn('image_path', 'string', ['limit' => 255])
            ->addColumn('alt_nl', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('alt_en', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('sort_order', 'integer', ['default' => 0])
            ->addColumn('created_at', 'datetime', ['null' => true])
            ->addColumn('updated_at', 'datetime', ['null' => true])
            ->addForeignKey('service_id', 'services', 'id', [
                'delete' => 'CASCADE',
                'update' => 'CASCADE',
            ])
            ->addIndex(['service_id'])
            ->create();

        if (InstallState::isFreshInstall($this)) {
            // Everything below is Van Veluw Laserdesign's own page
            // content, lifted out of the templates it used to be
            // hardcoded in. An installation with no history to preserve
            // gets the empty table and builds its own pages.
            // See src/Install/InstallState.php.
            return;
        }

        $now = date('Y-m-d H:i:s');

        $houtId = (int) $this->fetchRow("SELECT id FROM services WHERE service_key = 'hout'")['id'];
        $metaalId = (int) $this->fetchRow("SELECT id FROM services WHERE service_key = 'metaal'")['id'];

        $rows = [
            ['service_id' => $houtId, 'image_path' => 'assets/images/snijplank-just-married.webp', 'alt_nl' => 'Gegraveerde snijplank met tekst Just Married', 'alt_en' => 'Engraved cutting board reading Just Married', 'sort_order' => 0],
            ['service_id' => $houtId, 'image_path' => 'assets/images/mama-puzzelbord.webp', 'alt_nl' => 'Houten puzzelbord met persoonlijke tekst, cadeau voor Moederdag', 'alt_en' => "Wooden puzzle board with personal text, a Mother's Day gift", 'sort_order' => 1],
            ['service_id' => $houtId, 'image_path' => 'assets/images/skyline-nijmegen-hout.webp', 'alt_nl' => 'Houten wanddecoratie met skyline van Nijmegen', 'alt_en' => 'Wooden wall decor featuring the Nijmegen skyline', 'sort_order' => 2],
            ['service_id' => $metaalId, 'image_path' => 'assets/images/visitekaartje-metaal-barbershop.webp', 'alt_nl' => 'Metalen visitekaartje met lasergravure voor een barbershop', 'alt_en' => 'Laser-engraved metal business card for a barbershop', 'sort_order' => 0],
            ['service_id' => $metaalId, 'image_path' => 'assets/images/hero-collage-c.webp', 'alt_nl' => 'Set gereedschap gegraveerd met naam en logo', 'alt_en' => 'Set of tools engraved with a name and logo', 'sort_order' => 1],
            ['service_id' => $metaalId, 'image_path' => 'assets/images/medaille-heuvelenloop.webp', 'alt_nl' => 'Gepersonaliseerde medaille van de Zevenheuvelenloop', 'alt_en' => 'Personalised Zevenheuvelenloop medal', 'sort_order' => 2],
        ];

        foreach ($rows as &$row) {
            $row['created_at'] = $now;
            $row['updated_at'] = $now;
        }
        unset($row);

        $table->insert($rows)->saveData();
    }

    public function down(): void
    {
        $this->table('service_images')->drop()->save();
    }
}
