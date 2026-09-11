# api/admin

Ruim 200 schrijfendpoints, één per handeling. Ze volgen bijna allemaal
hetzelfde patroon. Wijk daar niet van af, en schrijf er nooit een vanaf nul:
kopieer er een uit dezelfde familie.

**De vier guards, in deze volgorde, vóór er iets gelezen of geschreven wordt:**

```php
AdminAuth::requireLoginForApi();
AdminAuth::requirePermissionForApi('pages.manage');
// POST-check  -> header('Allow: POST') + 405
// Csrf::validate($_POST['csrf_token'] ?? null) -> 403
```

Daarna pas: invoer valideren, repository aanroepen,
`<Type>Content::clearCache()`, en afsluiten met een PRG-redirect plus een
session-flash.

- **Vertrouw nooit een `page_slug:section_key` uit het request.** Controleer
  dat de pagina bestaat (op de onveranderlijke `pages.content_key`) én dat de
  inhoudsrij al bestaat.
- Bestanden die met `_` beginnen zijn gedeelde validatie-includes, geen
  endpoints.
- Een endpoint van een module heeft geen `ModuleGuard` nodig zolang het een
  permissie van die module vraagt: die wordt door niemand gehouden als de
  module uit staat.

Roep `/admin-endpoint` aan voor het volledige recept.
