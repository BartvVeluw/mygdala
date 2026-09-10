# Mygdala — generic CMS

> **Mygdala is the canonical source repository for this CMS.** All generic CMS
> development happens here: new functionality is built, tested and committed in
> Mygdala first. Van Veluw Laserdesign is a downstream official site running
> this CMS — it is a stable production installation and a reference, not the
> development environment. A generic CMS change is proven in Mygdala and only
> then brought over to Van Veluw. Van Veluw's own content, media and project
> history stay in that repository and are deliberately absent here
> (`src/Install/FreshSiteCopyPolicy.php` is the boundary; see below).

> **Start with [PROJECT-MAP.md](PROJECT-MAP.md)** — what the application is, where everything lives, and which document to read for your task. From there: [MODULES.md](MODULES.md) for domain boundaries, [CONTENT-BLOCKS.md](CONTENT-BLOCKS.md) for building a content block, [TESTING.md](TESTING.md) for running tests, and [SETUP.md](SETUP.md) for what a brand-new installation of this CMS gets asked.
>
> This file covers practical developer docs: running the project locally, Docker, and the database.

> **`MAIN.MD` is not in this repository.** It is the Van Veluw Laserdesign
> project history, and `FreshSiteCopyPolicy` classes it as site history rather
> than application. Where a document below says "see MAIN.MD", the background
> is in the Van Veluw repository; the code and its docblocks are the reference
> here.

This CMS is a bilingual (NL default, EN toggle) marketing front-end plus webshop: home, services, portfolio, about, contact, shop, cart/checkout and order-status pages, all CMS-managed. No build step, no JS framework.

Public pages are PHP entry files (`.php`) rather than static HTML — this lets every page share one header/navigation and one footer via plain PHP `include`s instead of duplicating that markup on every page. There's no template engine or framework involved: just `require`. See "Shared layout (header/footer)" below.

## Structure

```
index.php             Home
diensten.php           Services (Hout / Metaal / Acryl & glas / Zakelijk + FAQ)
portfolio.php           Filterable gallery + lightbox
over-mij.php            About
contact.php             Quote-request form (→ api/contact.php)
shop.php, product.php, cart.php, checkout.php, bestelling-status.php   Webshop pages (see MAIN.MD)
partials/header.php     Shared <header>/nav, included by every public page — nav items are CMS-managed (/admin/navigation.php), see MAIN.MD
partials/footer.php     Shared <footer>, included by every public page
assets/css/core.css   Design tokens + everything every page uses
assets/js/core.js     Language switch, header/nav, scroll reveal
assets/{css,js}/blocks/  One file per content block that needs its own frontend
assets/{css,js}/shop/    Shop frontend: cart (mini-cart, site-wide), shop (catalogue/checkout), personalization
assets/images/        Optimised WebP images used on the site
assets/images/raw/    Original source photos (not linked from any page — safe to delete, or keep for re-export)
scripts/optimize_images.py  Re-run this if you swap in new source photos (needs Pillow: pip install pillow)
robots.txt, sitemap.xml
```

### Shared layout (header/footer)

Every public page follows the same pattern. In `<head>`, after the page-specific title/meta/canonical, it says which frontend assets it needs and then includes the partial that prints them (see `PROJECT-MAP.md`, "Frontend-assets"):

```php
<?php
// blocks on this page ask for their own CSS/JS; a route asks directly
\App\Service\SectionRegistry::collectPageAssets('index');
require __DIR__ . '/partials/page-assets.php';
?>
```

Then the body:

```php
<body>
<?php
$activeNav = 'shop'; // a RouteRegistry key, marks aria-current="page" on the matching nav item
require __DIR__ . '/partials/header.php';
?>
<main id="main">
  ... page-specific content ...
</main>
<?php require __DIR__ . '/partials/footer.php'; ?>
<?php require __DIR__ . '/partials/page-scripts.php'; ?>
</body>
```

To add/rename/reorder a nav item, use `/admin/navigation.php` — both the header nav and mobile menu read from the same `nav_items` table (see MAIN.MD, "Global Navigation + Footer"). The Shop's mini-cart always renders empty server-side and `assets/js/shop/cart.js` fills it in with the visitor's real cart on load.

## Before going live — checklist

1. **Confirm the email address.** `info@vanveluwlaserdesign.nl` is still a placeholder (guessed from the domain, not confirmed). It's now a single global setting — edit it once in `/admin/settings.php` and every page (header, footer, contact) picks it up automatically. See MAIN.MD, "CMS-fundament stap 1: centrale site-instellingen".
2. **Contact form — done.** The quote form on `contact.php` is wired up to its own PHP backend (`api/contact.php`): every valid submission is persisted in `contact_requests` (+ its attachment, if any, in `contact_request_attachments`) first, then a best-effort notification email goes out — see MAIN.MD, "Persistente Contactaanvragen". Submissions are manageable in `/admin/contact-requests.php`. No further action needed here before going live.
3. **Add real social links** (Instagram etc.) if you have them — there's a `.social-row` component ready in the CSS, just not used yet since no account was confirmed.
4. **Double-check KVK number and address.** Also now a global setting, editable in `/admin/settings.php` (`KVK 97749540`, "Nijmegen, Nederland") — no need to hunt through individual pages.
5. **Consider a personal photo** on the About page — it currently uses workshop/process photos only.

## Running it locally

All public pages are PHP (see "Shared layout (header/footer)" above), so previewing them needs something that actually executes PHP — a plain static file server (`live-server`, `python -m http.server`, etc.) will not render `index.php`/`shop.php`/etc., it can only serve `assets/css`/`assets/js` as static files. Use the Docker PHP/Apache stack for all page previews — see "Local development environment (Docker)" below, then open **http://localhost:8000**.

There's no separate build step either way: edit any `.php`, `.css`, or `.js` file and reload the browser — the Docker `php` container mounts this whole folder as a volume, so changes are picked up immediately, no rebuild/restart needed. (A `.claude/launch.json` entry named `van-veluw-site` attaches to that running container if you're continuing work in Claude Code.)

## Local development environment (Docker)

The webshop backend (PHP + MySQL) runs entirely in Docker — you don't need PHP, Composer, or MySQL installed on Windows at all, only Docker Desktop.

Three containers, started together:

- **php** — PHP 8.2 + Apache, serving this whole folder. Composer and Phinx run inside it. On every start it installs Composer packages (first run only) and runs any pending database migrations automatically.
- **mysql** — MySQL 8.
- **adminer** — a web UI for browsing the database, no MySQL knowledge needed.

Database access is via plain PHP **PDO** (`src/Database.php`), not an ORM — kept deliberately simple for a small shop. Database structure/tables are managed by [Phinx](https://book.cakephp.org/phinx/0/en/index.html) migrations (`db/migrations/`) and a seed (`db/seeds/`). `src/Repository/Repository.php` is a small base class for table-specific repositories — `ProductRepository` (`src/Repository/ProductRepository.php`) is the first one built, backing `GET /api/products.php`.

> If `phinx migrate` ever fails with "table already exists" on a fresh database: this bit us once (2026-09-03) — `orders`/`order_items` had signed FK columns pointing at Phinx's unsigned `id` columns, which MySQL rejects, so those two migrations never actually completed. Already fixed in `db/migrations/`; mentioned here in case a similarly-shaped FK type mismatch ever resurfaces in a new migration.

### 1. Start everything

```bash
docker compose up -d
```

First run builds the PHP image and installs dependencies, so it takes a minute or two; after that it's seconds. This single command gets you:

- the website at **http://localhost:8000**
- MySQL, with tables already migrated automatically
- Adminer at **http://localhost:8080**

Run the seed once, to add a few test products (safe to skip; running it again just adds duplicate rows):

```bash
docker compose exec php php vendor/bin/phinx seed:run
```

### 2. Stop it

```bash
docker compose stop
```

Stops the containers but keeps your data. `docker compose down` also removes the containers (data is kept, in a Docker volume) if you want to fully clear resources.

### 3. Reset the database

For a completely clean slate (e.g. schema got messy while experimenting):

```bash
docker compose down -v
docker compose up -d
docker compose exec php php vendor/bin/phinx seed:run
```

`-v` deletes the MySQL data volume — migrations re-run automatically on the next `up -d`. Only use this locally.

### 4. Verify the website is connected to MySQL

```bash
docker compose exec php php -r "require 'vendor/autoload.php'; App\Database::connection(); echo \"Connected to MySQL.\n\";"
```

There used to be a `db-test.php` at the project root for this. It was
removed: the project root is the site root, so it was a publicly reachable
page that printed the product count, a product name and, on failure, the
database error — a diagnostic that ships to visitors is a diagnostic in the
wrong place.

You can also browse the data visually: open `http://localhost:8080` (Adminer) and log in with System: MySQL, Server: `mysql`, and the `DB_USERNAME`/`DB_PASSWORD`/`DB_DATABASE` values from `.env`.

### 5. Run the tests

The suite runs against its own database and its own web container, never
against your development data. One-time setup, then the usual command:

```bash
docker exec vvld_php php scripts/test-db.php
docker compose --profile test up -d php_test
docker exec vvld_php_test php vendor/bin/phpunit
```

See **TESTING.md** for the targeted suites (`--testsuite blocks`, `shop`,
`fast`, ...) and when to run which.

### Other commands you might need

```bash
docker compose exec php composer require some/package   # add a PHP dependency
docker compose exec php php vendor/bin/phinx migrate     # run migrations manually
docker compose exec php php vendor/bin/phinx create MyNewMigration   # new migration
docker compose logs php                                  # see what the php container is doing
```

### Schema overview

- **products** — `name`/`name_en`, slug, `description`/`description_en`, `eyebrow`/`eyebrow_en` (short bilingual label shown on the shop card, e.g. "Sleutelhangers · Hout"), price, `image_path`, stock, active. All `_en` fields and `eyebrow`/`eyebrow_en` are nullable — the shop page falls back to the Dutch value when no translation is set yet, same as the rest of the site's `data-nl`/`data-en` system. Read via `src/Repository/ProductRepository.php` → `GET /api/products.php` → `shop.php` (see MAIN.MD status for what's wired up so far).
- **customers** — name, email, phone, address.
- **orders** — linked to a customer; `status` (our own order lifecycle: pending/paid/failed/...), plus `mollie_payment_id` and `mollie_status` so each order can be tied to its Mollie payment and reflect Mollie's own reported state.
- **order_items** — links products to orders with quantity and a price snapshot (`unit_price`), so later price changes never alter historical orders.

### Deploying to Vimexx later

Same PHP + MySQL, plain PDO, no framework — this maps directly onto Vimexx's shared hosting (PHP + unlimited MySQL databases, SSH access). When that day comes: upload the folder via SSH/FTP, create a MySQL database in the Vimexx panel, put real credentials in a `.env` on the server (never commit them), and run `composer install --no-dev` and `vendor/bin/phinx migrate` over SSH. The root `.htaccess` already blocks web access to `.env`, `vendor/`, `db/`, `src/`, and `docker/` so they're safe to leave in place on shared hosting.

## Version control

This project lives in a private GitHub repo: [BartvVeluw/van-veluw-laserdesign](https://github.com/BartvVeluw/van-veluw-laserdesign). To clone it:

```bash
git clone https://github.com/BartvVeluw/van-veluw-laserdesign.git
```

`.env` and `/vendor/` are gitignored and never committed — after cloning, copy `.env.example` to `.env` and fill in real local values (see "Local development environment (Docker)" above), then run `docker compose up -d`, which installs Composer packages automatically.

## Design notes

- **Palette & type** were pulled directly from the existing vanveluwlaserdesign.nl store (cream `#F2E6D7`, ink `#252525`, forest `#2C332F`, Trirong + Quattrocento Sans) so the new site feels like a natural evolution, not a reskin.
- **Photos** are real product photography pulled from the existing store (business cards, cutting boards, name signs, the Zevenheuvelenloop medal, the Nijmegen skyline piece, etc.), optimised to WebP.
- **Bilingual content** works via `data-nl` / `data-en` attributes on elements (see any page for examples) — `assets/js/core.js` swaps `innerHTML`/`alt`/`placeholder` on toggle and remembers the choice in `localStorage`. To add a new translatable string, just add matching `data-nl="…" data-en="…"` attributes.
- **Animation** respects `prefers-reduced-motion` throughout (GSAP hero entrance skips entirely; CSS scroll-reveals render in their final state immediately).
- No tracking/analytics script is included — add one (e.g. Plausible or GA4) if you want visitor stats.
