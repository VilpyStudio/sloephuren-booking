# Ontwikkelaars-handleiding

Korte referentie om de plugin te onderhouden en uit te breiden. De `README.md` is voor de gebruiker; dit bestand is voor wie aan de code werkt.

## Snel starten

```bash
git clone https://github.com/VilpyStudio/sloephuren-booking.git
cd sloephuren-booking
```

De plugin draait zonder build-stap — `php`/`js`/`css` lopen rechtstreeks. Test in een lokale WordPress (LocalWP / DDEV) of upload de zip in een staging-site.

## Architectuur

```
sloephuren-booking.php          Bootstrap: versie, constants, require_once, init-hooks
includes/
  class-install.php             Databaseschema (dbDelta), seed-data, cron-schedule
  class-bookings.php            Data-laag: sloep-types, pakketten, tijdsloten, boekingen
  class-availability.php        Beschikbaarheid + veilige boeking-aanmaak (GET_LOCK)
  class-payments.php            Betaalproviders: mock + Mollie (iDEAL)
  class-emails.php              Bevestigings- en beheerdersmails
  class-admin.php               Admin-menu en beheerschermen (boekingen, types, etc.)
  class-plugin.php              Shortcode, assets, REST API, betaal-callbacks
public/
  js/booking.js                 Frontend stepper (sloep -> pakket -> datum -> tijdslot -> gegevens)
  css/booking.css               Styling in de huisstijl (zaans-blauw/marine/zand)
```

## Databasetabellen

Prefix `wp_shb_`: `boat_types`, `products`, `timeslots`, `bookings`. Aangemaakt bij plugin-activatie via `SHB_Install::activate()`.

## REST-endpoints

Namespace `sloephuren/v1`:

- `GET  /timeslots`      — beschikbare tijdsloten (params: `product_id`, `date`, optioneel `boat_type_id`)
- `GET  /boats`          — beschikbare sloep-types (params: `product_id`, `date`, `timeslot_id`)
- `POST /book`           — boeking + betaling starten (nonce vereist)
- `POST /webhook/mollie` — Mollie-webhook

## Belangrijke aandachtspunten

- **Concurrency:** boekingen worden aangemaakt binnen een MySQL named lock (`GET_LOCK`) met een hercontrole van de beschikbaarheid; nooit deze volgorde omdraaien.
- **Betaling pas definitief na webhook:** een boeking gaat alleen op `paid` via de webhook / betaal-callback, nooit direct bij aanmaken.
- **Security:** alle input via nonces, `sanitize_*` en escaping bij output.
- **Versie ophogen:** bij elke wijziging aan js/css de `Version`-header én de `SHB_VERSION`-constante in `sloephuren-booking.php` bumpen zodat browsercache breekt.

## Releasen

De release loopt via GitHub Actions (`.github/workflows/release.yml`) op een tag:

```bash
# na het committen van je wijzigingen + changelog:
git tag v1.0.3
git push origin v1.0.3
```

De workflow bouwt automatisch `sloephuren-booking.zip` en publiceert een GitHub Release met die zip als asset. Zorg dat de tag-versie overeenkomt met de `Version`-header in het hoofdbestand.
