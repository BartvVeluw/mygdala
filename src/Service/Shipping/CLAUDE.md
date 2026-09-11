# src/Service/Shipping

Zones, tarieven, berekening en de PostNL-synchronisatie. Een deelgebied
**binnen** de Shop, geen eigen module: een tarief zonder winkelmandje bestaat
niet.

De verzendkosten die de klant ziet komen van `api/shipping-quote.php` en
worden bij het afrekenen opnieuw berekend. Vertrouw nooit een bedrag dat het
request meestuurt.

Lees `../../../MODULES.md`, of roep `/shop` aan. Test met `--testsuite shop`.
