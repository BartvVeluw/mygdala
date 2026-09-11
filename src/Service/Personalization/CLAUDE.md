# src/Service/Personalization

De Personalisatie-module: views en zones per product, fonts, uploads, preview
en het ordersnapshot.

- **Hangt van de Shop af, en dat is afgedwongen.** De poort zit op één plek:
  `ProductPersonalizationContent::resolve()`. Staat de module uit, dan meldt
  élk product "niet te personaliseren", en weigert `api/checkout.php` een
  regel die tóch personalisatiegegevens meestuurt.
- Uploads gaan naar `storage/`, buiten de webroot. Nooit naar `assets/`.
- Een personalisatie wordt als **snapshot** vastgelegd op een orderregel. Een
  latere wijziging aan het product mag een bestaande order nooit raken.
- Andersom hoeft de Shop niets van personalisatie te weten, behalve de
  prijsopslag.

Lees `../../../MODULES.md`. Test met `--testsuite personalization`.
