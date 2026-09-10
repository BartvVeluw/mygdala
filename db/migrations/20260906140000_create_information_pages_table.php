<?php

declare(strict_types=1);

use App\Install\InstallState;
use Phinx\Migration\AbstractMigration;

/**
 * Generic, reusable CMS content model for standalone information/legal pages
 * (Verzenden & retourneren, Algemene voorwaarden, Privacyverklaring, and any
 * future page of the same shape — e.g. a later "Garantie" or "FAQ juridisch"
 * page needs only a new row here, a matching admin/_richtext_field.php-based
 * edit screen already exists generically at admin/information-page.php, and
 * one new bare-slug RewriteRule line in .htaccess; see that file's docblock).
 *
 * Deliberately NOT reusing/extending one of the existing page-section models
 * (PageHeroContent, TextImageSplitContent, ...) — those are fixed sections
 * bolted onto specific existing templates (index.php, diensten.php, ...),
 * whereas an information page is a whole standalone page: its own title,
 * slug/URL, one long-form rich-text body, its own SEO fields and its own
 * publish state. `App\Service\RichTextSanitizer` (already used for Portfolio
 * project write-ups) is reused unchanged for `content_html` — same allowlist
 * (p, br, strong, em, b, i, h2, h3, ul, ol, li, a[href|title]), same
 * sanitize-on-write-and-again-on-read pattern.
 *
 * `slug` is the public URL segment (see .htaccess's bare-slug RewriteRules
 * and informatiepagina.php) — editable in the admin like Portfolio item
 * slugs, unique, never auto-changed on title edits after creation.
 *
 * `is_published` gates public visibility only; the admin can always see and
 * edit a page regardless of its published state (same convention as
 * products.active / portfolio_gallery_items.is_active).
 *
 * `sort_order` exists purely for a stable, admin-controllable order in the
 * admin list and the public footer's "Informatie" column (see
 * partials/footer.php) — seeded 10 apart so a future page can be inserted
 * between two existing ones without renumbering everything.
 *
 * meta_title/meta_description are optional per-page SEO overrides, nullable
 * so a page can simply fall back to page-wide defaults (see
 * informatiepagina.php) when left empty — same "empty means: no override"
 * convention as this project's other optional bilingual/SEO fields.
 *
 * Seeds the three pages requested for launch (2026-09-06) with a clearly
 * editable placeholder heading structure, not fabricated final legal text —
 * see MAIN.MD. This only ever runs once, on a fresh migration; it never
 * touches or overwrites rows again after this migration has run.
 */
final class CreateInformationPagesTable extends AbstractMigration
{
    public function up(): void
    {
        $table = $this->table('information_pages', ['id' => true]);
        $table
            ->addColumn('slug', 'string', ['limit' => 170])
            ->addColumn('title', 'string', ['limit' => 200])
            ->addColumn('content_html', 'text', ['null' => true])
            ->addColumn('meta_title', 'string', ['limit' => 200, 'null' => true])
            ->addColumn('meta_description', 'string', ['limit' => 300, 'null' => true])
            ->addColumn('is_published', 'boolean', ['default' => true])
            ->addColumn('sort_order', 'integer', ['default' => 0])
            ->addColumn('created_at', 'datetime', ['null' => true])
            ->addColumn('updated_at', 'datetime', ['null' => true])
            ->addIndex(['slug'], ['unique' => true])
            ->create();

        if (InstallState::isFreshInstall($this)) {
            // Verzenden & retourneren, Algemene voorwaarden and
            // Privacyverklaring were seeded for the Van Veluw Laserdesign
            // launch in 2026-09. Which legal pages a site needs is a
            // business decision, not a CMS requirement, so a generic
            // install gets none and creates its own — see
            // src/Install/InstallState.php and INSTALL-BOOTSTRAP.md.
            return;
        }

        $now = date('Y-m-d H:i:s');

        $shippingReturnsHtml = '<p><em>Concept — pas deze tekst aan via de admin (Pagina\'s &rarr; Informatiepagina\'s) voordat de shop live gaat. Dit is bewust nog geen definitieve juridische tekst.</em></p>'
            . '<h2>Verzending</h2><p>Beschrijf hier hoe en met welke vervoerder(s) bestellingen worden verzonden.</p>'
            . '<h2>Verzendkosten</h2><p>Beschrijf hier de verzendkosten. Zie ook de actuele tarieven onder Verzendinstellingen in de admin.</p>'
            . '<h2>Levertijd</h2><p>Beschrijf hier de gebruikelijke levertijd, en eventuele langere levertijd bij gepersonaliseerde producten.</p>'
            . '<h2>Afhalen</h2><p>Beschrijf hier de afhaalmogelijkheid in Nijmegen: hoe, waar en wanneer.</p>'
            . '<h2>Bedenktijd</h2><p>Leg hier het wettelijke herroepingsrecht (bedenktijd van 14 dagen) uit voor bestellingen waarop dit van toepassing is. Zie ook <a href="/herroeping.php">de herroepingspagina</a> waar een klant een bestelling kan aanmelden voor herroeping.</p>'
            . '<h2>Retourneren</h2><p>Beschrijf hier de retourprocedure: hoe een klant een retour aanmeldt en waar een pakket naartoe moet.</p>'
            . '<h2>Gepersonaliseerde en op maat gemaakte producten</h2><p><strong>Belangrijk:</strong> leg hier zelf duidelijk uit welke producten wel en welke niet in aanmerking komen voor herroeping/retour, bijvoorbeeld omdat ze speciaal voor de klant zijn gepersonaliseerd of op maat gemaakt (art. 6:230p BW). Dit onderscheid wordt bewust niet automatisch door de webshopsoftware bepaald — vul dit hier zelf in.</p>'
            . '<h2>Beschadigd of verkeerd geleverd product</h2><p>Beschrijf hier wat een klant moet doen bij een beschadigd of verkeerd geleverd product.</p>'
            . '<h2>Retourkosten</h2><p>Beschrijf hier wie de retourkosten draagt.</p>'
            . '<h2>Terugbetaling</h2><p>Beschrijf hier binnen welke termijn en op welke manier wordt terugbetaald.</p>'
            . '<h2>Contact</h2><p>Beschrijf hier hoe een klant contact kan opnemen over verzending of een retour.</p>';

        $termsHtml = '<p><em>Concept — pas deze tekst aan via de admin (Pagina\'s &rarr; Informatiepagina\'s) voordat de shop live gaat. Dit is bewust nog geen definitieve juridische tekst.</em></p>'
            . '<h2>1. Identiteit van de ondernemer</h2><p>Vul hier bedrijfsnaam, adres, KVK-nummer, btw-nummer en contactgegevens in.</p>'
            . '<h2>2. Toepasselijkheid</h2><p>Beschrijf hier op welke overeenkomsten deze voorwaarden van toepassing zijn.</p>'
            . '<h2>3. Aanbod en overeenkomst</h2><p>Beschrijf hier hoe een aanbod en de overeenkomst tot stand komen.</p>'
            . '<h2>4. Prijzen en betaling</h2><p>Beschrijf hier de prijzen (incl./excl. btw), betaalmethoden en het betaalmoment.</p>'
            . '<h2>5. Levering en uitvoering</h2><p>Beschrijf hier de levertijd en werkwijze; zie ook de pagina Verzenden &amp; retourneren.</p>'
            . '<h2>6. Herroepingsrecht</h2><p>Beschrijf hier het wettelijke herroepingsrecht en de uitzondering voor gepersonaliseerde/op maat gemaakte producten; zie ook de pagina Verzenden &amp; retourneren en <a href="/herroeping.php">de herroepingspagina</a>.</p>'
            . '<h2>7. Garantie en conformiteit</h2><p>Beschrijf hier de wettelijke garantie/conformiteit.</p>'
            . '<h2>8. Klachtenregeling</h2><p>Beschrijf hier hoe een klant een klacht kan indienen en binnen welke termijn wordt gereageerd.</p>'
            . '<h2>9. Aansprakelijkheid</h2><p>Beschrijf hier de aansprakelijkheid van de ondernemer.</p>'
            . '<h2>10. Toepasselijk recht en geschillen</h2><p>Beschrijf hier het toepasselijke recht en de bevoegde rechter.</p>'
            . '<h2>11. Wijzigingen van deze voorwaarden</h2><p>Beschrijf hier hoe en wanneer deze voorwaarden kunnen wijzigen.</p>';

        $privacyHtml = '<p><em>Concept — pas deze tekst aan via de admin (Pagina\'s &rarr; Informatiepagina\'s) voordat de shop live gaat. Dit is bewust nog geen definitieve juridische tekst.</em></p>'
            . '<h2>Welke persoonsgegevens worden verwerkt</h2><p>Beschrijf hier welke gegevens worden verzameld (bijv. naam, adres, e-mailadres, telefoonnummer, bestelgegevens).</p>'
            . '<h2>Waarom deze gegevens worden verwerkt</h2><p>Beschrijf hier het doel van de verwerking.</p>'
            . '<h2>Bestellingen en betalingen</h2><p>Beschrijf hier de verwerking rond het plaatsen en afhandelen van bestellingen.</p>'
            . '<h2>Verzending</h2><p>Beschrijf hier welke gegevens worden gedeeld met de vervoerder om een bestelling te kunnen bezorgen.</p>'
            . '<h2>Mollie/betaalproviders</h2><p>Deze webshop gebruikt Mollie voor het verwerken van betalingen. Beschrijf hier welke gegevens hiervoor met Mollie worden gedeeld.</p>'
            . '<h2>E-mail</h2><p>Beschrijf hier voor welke e-mails (bijv. orderbevestiging) het opgegeven e-mailadres wordt gebruikt.</p>'
            . '<h2>Hosting</h2><p>Deze website draait bij Vimexx. Beschrijf hier kort wat dit voor de verwerking van gegevens betekent.</p>'
            . '<h2>Cookies</h2><p>Zie de <a href="/cookiebeleid.php">cookiebeleid-pagina</a> voor een volledig overzicht van cookies en lokale opslag.</p>'
            . '<h2>Bewaartermijnen</h2><p>Beschrijf hier hoe lang gegevens worden bewaard (bijv. i.v.m. de fiscale bewaarplicht voor orderadministratie).</p>'
            . '<h2>Delen met derden/verwerkers</h2><p>Beschrijf hier met welke partijen (bijv. Mollie, de bezorgdienst, de hostingpartij) gegevens worden gedeeld en waarom.</p>'
            . '<h2>Privacyrechten</h2><p>Beschrijf hier de rechten van de klant (inzage, correctie, verwijdering, bezwaar) en hoe deze uit te oefenen.</p>'
            . '<h2>Beveiliging</h2><p>Beschrijf hier welke maatregelen worden genomen om persoonsgegevens te beveiligen.</p>'
            . '<h2>Contact</h2><p>Beschrijf hier hoe een klant contact kan opnemen over deze privacyverklaring.</p>'
            . '<h2>Wijzigingen</h2><p>Beschrijf hier hoe en wanneer deze privacyverklaring kan wijzigen.</p>';

        $table->insert([
            [
                'slug' => 'verzenden-retourneren',
                'title' => 'Verzenden & retourneren',
                'content_html' => $shippingReturnsHtml,
                'meta_title' => 'Verzenden & retourneren',
                'meta_description' => 'Alles over verzending, levertijd, afhalen en retourneren bij Van Veluw Laserdesign.',
                'is_published' => true,
                'sort_order' => 10,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'slug' => 'algemene-voorwaarden',
                'title' => 'Algemene voorwaarden',
                'content_html' => $termsHtml,
                'meta_title' => 'Algemene voorwaarden',
                'meta_description' => 'De algemene voorwaarden van Van Veluw Laserdesign.',
                'is_published' => true,
                'sort_order' => 20,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'slug' => 'privacyverklaring',
                'title' => 'Privacyverklaring',
                'content_html' => $privacyHtml,
                'meta_title' => 'Privacyverklaring',
                'meta_description' => 'Hoe Van Veluw Laserdesign omgaat met persoonsgegevens.',
                'is_published' => true,
                'sort_order' => 30,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ])->saveData();
    }

    public function down(): void
    {
        $this->table('information_pages')->drop()->save();
    }
}
