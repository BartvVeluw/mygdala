---
name: admin-endpoint
description: Een admin-schrijfendpoint toevoegen of wijzigen onder api/admin/ in Mygdala - de vaste guardvolgorde (login, permissie, POST, CSRF), sectievalidatie, cache legen en de PRG-redirect met session-flash. Gebruik dit bij elk werk aan een bestand onder api/admin/.
---

# Admin-endpoint

Er zijn ruim 200 van deze bestanden, één per handeling, en ze volgen bijna
allemaal hetzelfde patroon. **Schrijf er nooit een vanaf nul.** Zoek er een
uit dezelfde familie en kopieer die.

```bash
ls api/admin | grep <domein>
```

## Het skelet

```php
<?php

/**
 * POST /api/admin/update-<thing>.php
 *
 * Wat dit opslaat, en waarom het zo werkt. Noem het endpoint waarvan dit
 * het patroon overneemt.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Service\AdminAuth;
use App\Service\Csrf;

AdminAuth::requireLoginForApi();
AdminAuth::requirePermissionForApi('pages.manage');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit('Method not allowed');
}

if (!Csrf::validate($_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    exit('Invalid or missing CSRF token.');
}
```

Daarna, in deze volgorde:

1. **Invoer valideren.** Gedeelde regels staan in de `_*.php`-includes in deze
   map. Bestaat er al een validatie-include voor jouw domein, gebruik die.
2. **De repository aanroepen.** Alle SQL zit daar, nooit hier.
3. **`<Type>Content::clearCache()`** aanroepen, anders blijft de per-request
   cache de oude waarde teruggeven.
4. **PRG-redirect** terug naar het adminscherm, met een session-flash als
   melding. Geen JSON-antwoord tenzij de aanroeper dat echt verwacht.

## De valkuil van dit domein

**Vertrouw nooit een `page_slug:section_key` uit het request.** Splits hem,
en controleer allebei de helften apart:

- bestaat de pagina, opgezocht op de onveranderlijke `pages.content_key`?
- bestaat de inhoudsrij al, aangemaakt door `SectionRegistry::create()`?

Zo niet: 404, vóór er iets geschreven wordt. `api/admin/update-faq-section.php`
laat zien hoe dat eruitziet.

## Permissies en modules

Vraag de permissie die bij het domein hoort, niet de eerste de beste. Een
endpoint van een module heeft **geen** `ModuleGuard` nodig zolang het een
permissie van die module vraagt: die wordt door niemand gehouden als de module
uit staat, ook niet door een Super Admin. Publieke endpoints onder `api/`
hebben die guard wél nodig.

## Testen

```bash
docker exec mygdala_php_test php vendor/bin/phpunit --testsuite http
docker exec mygdala_php php vendor/bin/phpunit --testsuite contract
```

De suite `contract` leest de broncode en bewaakt onder meer of elk endpoint
zijn guards heeft.
