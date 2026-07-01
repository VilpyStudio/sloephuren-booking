# Sloephuren Booking

Custom WordPress-plugin voor **sloepverhuurzaanstad.nl**: bezoekers boeken online een sloep en rekenen direct af via iDEAL. Beschikbaarheid wordt gecontroleerd op datum, tijdslot en sloep-type, zodat dubbele boekingen onmogelijk zijn.

## Installatie

Installeer via de zip uit de [GitHub Releases](https://github.com/VilpyStudio/sloephuren-booking/releases), of:

1. Kopieer de map `sloephuren-booking` naar `wp-content/plugins/`.
2. Activeer de plugin in **Plugins**. Bij activatie worden de databasetabellen aangemaakt en gevuld met standaarddata (3 sloep-types, 2 pakketten, tijdsloten).
3. Plaats op een pagina de shortcode:

   ```
   [sloephuren_booking]
   ```

   De widget verschijnt als zwevende launcher rechtsonder op de pagina. Optionele attributen:

   ```
   [sloephuren_booking sloep="Stout 650"]   koppel aan één sloep (slaat stap 1 over)
   [sloephuren_booking start_open="1"]        widget direct geopend tonen
   [sloephuren_booking auto_advance="0"]      niet automatisch doorgaan na een keuze
   ```

## Standaarddata

**Sloep-types**
| Sloep | Voorraad | Max personen |
|-------|----------|--------------|
| Luxal Nautic | 1 | 8 |
| Stout 650 | 1 | 8 |
| Zaanse Sloep | 2 | 8 |

**Pakketten**
| Pakket | Prijs |
|--------|-------|
| Halve dag varen | € 265 |
| Hele dag varen | € 399 |

## Boekflow (frontend)

Zwevende widget (Booqable-achtig): een launcher-pill rechtsonder (full-width onderaan op mobiel) opent een boekingspaneel. Stappen: sloep → pakket → datum (inline kalender) → tijdslot → gegevens & samenvatting → *Boek & betaal*. Na terugkeer van de betaling toont de widget een successcherm met boekingsnummer.
- Alleen beschikbare tijdsloten worden getoond (echte beschikbaarheid bij de tijdslot-stap).
- Prijzen zonder brandstofkosten in het prijsblok.
- Zaanse Sloep = max. 2 boekingen per tijdslot; Luxal Nautic en Stout 650 = max. 1.
- Niet-betaalde (pending) boekingen blokkeren een plek maximaal 15 minuten.

## Betaling

- **Mock (standaard):** werkt direct zonder keys. De klant komt op een testpagina waar je de betaling laat "slagen" of "mislukken". Handig om de volledige flow te testen.
- **Mollie (iDEAL):** vul onder **Sloephuren → Instellingen** een Mollie API-sleutel in en zet de provider op Mollie. Zonder geldige sleutel valt de plugin automatisch terug op mock.

Flow: eerst een boeking met status `pending_payment` → betaling starten → `payment_id` en `checkout_url` opslaan → klant naar betaalpagina → pas op `paid` na een succesvolle webhook. Mislukte/verlopen betalingen worden nooit definitief.

## Admin (menu "Sloephuren")

- **Boekingen** — tabel met datum, tijdslot, sloep, klant, telefoon, e-mail, personen, status en bedrag. Filters op status, datumbereik en zoekterm. Status per boeking handmatig aanpasbaar.
- **Sloep-types** — naam, voorraad, max personen, actief/inactief.
- **Pakketten** — naam, prijs, actief/inactief.
- **Tijdsloten** — per pakket, met start/eindtijd en actief/inactief.
- **Instellingen** — betaalprovider, Mollie-sleutel, pending-minuten, beheerder-e-mail, voorwaarden-URL.

## E-mails

Na een succesvolle betaling gaat er automatisch een bevestiging naar de klant en een melding naar de beheerder, met boekingsnummer, datum, tijd, sloep-type, aantal personen, betaald bedrag en contactgegevens.

## Techniek

- Eigen tabellen via `dbDelta` (prefix `wp_shb_`).
- Beveiligd met nonces, sanitization en escaping.
- Beschikbaarheidschecks via de REST API (`/wp-json/sloephuren/v1/…`).
- Gelijktijdige boekingen worden veilig afgehandeld met een MySQL named lock (`GET_LOCK`), plus een hercontrole binnen de lock voordat de boeking wordt weggeschreven.
- WP-Cron (elke 5 min) markeert verlopen pending-boekingen als `expired`.
- Automatische updates vanuit GitHub Releases: nieuwe versies verschijnen in het normale WordPress update-scherm, met een "Controleer op updates"-link in de pluginregel.

## Bestandsstructuur

```
sloephuren-booking.php          Hoofdbestand (constanten, includes, activatie)
includes/class-install.php      Databaseschema, seed-data, cron
includes/class-bookings.php     Data-laag (types, pakketten, sloten, boekingen)
includes/class-availability.php Beschikbaarheid + veilige boeking-aanmaak
includes/class-payments.php     Mock + Mollie betaalproviders
includes/class-emails.php       Bevestigings- en beheerdersmails
includes/class-admin.php        Admin-menu en beheerschermen
includes/class-github-updater.php  Automatische updates vanuit GitHub Releases
includes/class-plugin.php       Shortcode, assets, REST API, callbacks
public/js/booking.js            Zwevende widget (launcher + paneel, stappen, kalender)
public/css/booking.css          Styling (Sloephuren-designsysteem)
public/img/logo.svg             Logo voor launcher en paneel-header
```
