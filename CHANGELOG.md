# Changelog

Alle noemenswaardige wijzigingen aan Sloephuren Booking worden hier bijgehouden.
Format volgt losjes [Keep a Changelog](https://keepachangelog.com/); versies volgen [SemVer](https://semver.org/lang/nl/).

## [2.7.0] - 2026-07-12

### Added
- **Nette afzender** voor de mails: instelbare afzendernaam en afzender-e-mailadres (Sloephuren → Instellingen), i.p.v. de standaard "WordPress". Standaard de sitenaam + noreply@je-domein.
- **Meerdere beheerder-mailadressen**: de melding van een nieuwe boeking kan naar meerdere adressen (komma of nieuwe regel) zodat je niet hoeft door te sturen.
- Slimme Reply-To: klant beantwoordt de bevestiging naar de beheerder; de beheerder beantwoordt de melding rechtstreeks naar de klant.

## [2.6.0] - 2026-07-12

### Added
- **Financieel overzicht** (Sloephuren → Financieel): betaalde omzet, aantal boekingen, gemiddelde en openstaand, met uitsplitsing per maand, per pakket en per sloep. Filterbaar op periode.
- Boekingen kunnen nu **verwijderd** worden vanuit het boekingenoverzicht.

### Fixed
- De statuskeuze in de "Actie"-kolom van het boekingenoverzicht viel bij veel kolommen van de pagina. De tabel is nu horizontaal scrollbaar en de kolommen passen netjes.

## [2.5.2] - 2026-07-12

### Fixed
- **LiteSpeed cachete de beschikbaarheids-API 7 dagen**, waardoor bezoekers oude beschikbaarheid zagen en (ondanks de overlap-fix) alsnog geboekte dagdelen konden boeken. De dynamische endpoints (`/wp-json/sloephuren/v1/*`) sturen nu no-cache mee en zetten LiteSpeed's eigen no-cache via `litespeed_control_set_nocache`. Beschikbaarheid, status en boekingen zijn nu altijd live.

## [2.5.1] - 2026-07-12

### Fixed
- **Terugkeer na betaling toonde ten onrechte "niet afgerond".** Mollie geeft de betaalstatus mee via de webhook, niet in de terugkeer-URL; het scherm leunde op een ontbrekende URL-parameter. De widget vraagt nu de echte status bij de server op (met een status-endpoint) en pollt kort door voor de webhook. Betaalde boekingen tonen nu correct het succes-scherm; er is nooit geld of een boeking verloren gegaan.
- **Dubbelboek-lek bij overlappende dagdelen.** Beschikbaarheid telde alleen per exact tijdslot, waardoor een "hele dag"-boeking kon worden gemaakt terwijl de sloep 's middags al vergeven was (en andersom). Beschikbaarheid en de boek-lock rekenen nu op tijd-overlap, zodat overlappende dagdelen elkaar blokkeren.

## [2.5.0] - 2026-07-02

### Added
- Sloep-types kun je nu met één klik **aan/uit** zetten in de lijst (Sloephuren → Sloep-types). Een uitgezette sloep verdwijnt direct uit de widget en is niet boekbaar, zonder dat je hem hoeft te verwijderen. Handig als een boot tijdelijk niet te huur is.

## [2.4.3] - 2026-07-02

### Fixed
- Voorwaarden-link in stap 5 werkte niet: een klik op de link togglede het akkoord-vinkje en tekende de widget opnieuw, waardoor de navigatie verloren ging. Een klik op de link laat 'm nu gewoon openen (in een nieuw tabblad).

## [2.4.2] - 2026-07-02

### Fixed
- Widget-logo netjes gemaakt: de slate achtergrond van het merk-badge botste met de crème cirkel. Nu een uitgeknipt mark (ingekleurde boot + woordmerk) op de ronde crème cirkel met een dunne witte ring. De mail-header houdt het lichte volledige logo.

## [2.4.1] - 2026-07-02

### Changed
- Bevestigingsmail volledig herschreven: tabel-gebaseerd, mobiel-responsive, strakke padding, merkkleuren, logo in de header en een `color-scheme: light`-hint zodat mail-clients de mail niet donker inverteren.

## [2.4.0] - 2026-07-02

### Added
- Trigger-shortcode `[sloephuren_open]tekst[/sloephuren_open]` om de widget te openen vanaf je eigen knop of link (optioneel `sloep="..."` om die sloep voor te selecteren).
- Openen kan ook zonder shortcode: een link naar `#sloephuren` of een element met de CSS-class `shb-open` (met optioneel `data-shb-sloep`). Globale functie `window.shbOpenWidget(sloepnaam)` beschikbaar.
- Admin-scherm **Shortcodes**: overzicht van alle shortcodes met uitleg en kopieer-knoppen, plus tips voor Elementor-knoppen.

## [2.3.0] - 2026-07-02

### Added
- Blokkeren per **dagdeel**: in het Beschikbaarheid-scherm kies je naast "Hele dag" nu ook Ochtend of Middag. Tik daarna dagen aan zoals altijd; het periode-formulier heeft dezelfde dagdeel-keuze. Deels geblokkeerde dagen tonen amber met een letter (O/M).
- Slimme tijd-overlap: een ochtend-blokkade blokkeert ook "Hele dag varen" (die de ochtend nodig heeft), maar laat de middag gewoon boekbaar. Geldt overal: widget, REST en de boek-lock.

## [2.2.0] - 2026-07-02

### Added
- Admin-scherm **Beschikbaarheid**: mobiel-vriendelijke maandkalender waarop je per sloep (of alle sloepen) dagen aantikt om ze te blokkeren voor verhuur en weer vrij te geven. Plus een formulier voor langere periodes (met notitie) en een lijst met actieve blokkades.
- Blokkades tellen overal mee: geblokkeerde dagen/sloepen zijn niet boekbaar (ook afgedwongen binnen de boek-lock) en de widget-kalender toont niet-beschikbare dagen doorgestreept via het nieuwe REST-endpoint `/month`.
- Dagen met betaalde boekingen tonen een teller in de admin-kalender.

## [2.1.0] - 2026-07-01

### Added
- Instelling "Widget overal op de site tonen" (standaard aan): de zwevende widget verschijnt op elke pagina, niet alleen waar de shortcode staat. Uit te zetten onder Sloephuren → Instellingen wanneer je 'm alleen via de shortcode wilt plaatsen.

## [2.0.0] - 2026-07-01

### Changed
- **Volledige redesign van de frontend naar een zwevende boekingswidget** (Booqable-achtig) volgens de complete Sloephuren-designhandoff. Gesloten: een vaste launcher-pill rechtsonder (full-width onderaan op mobiel). Open: een zwevend paneel (bottom-sheet op mobiel) met marine header, klikbare voortgangsbalk, scrollbare inhoud en sticky actiebalk met totaalprijs.
- Nieuw designsysteem met exacte tokens (marine #15324F, crème, accentblauw), Bebas Neue / Hanken Grotesk / Space Grotesk (nu ook door de plugin geladen).
- Inline kalender in plaats van popup, personen-stepper, samenvatting met "wijzig"-knoppen, auto-doorgaan na een keuze, en een successcherm met boekingsnummer na terugkeer van de betaling.
- Shortcode-attributen: `sloep="Stout 650"` koppelt de widget aan één sloep (slaat stap 1 over), plus `start_open` en `auto_advance`.
- De widget wordt aan `<body>` gehangen zodat `position:fixed` betrouwbaar werkt naast thema-transforms.

### Notes
- Backend (REST-beschikbaarheid, GET_LOCK anti-dubbelboek, mock/Mollie-betaling, mails) ongewijzigd. De kalender is een datumkiezer; echte beschikbaarheid verschijnt bij de tijdslot-stap.

## [1.0.3] - 2026-07-01

### Added
- Automatische updates vanuit GitHub Releases (publieke repo, geen token). De plugin verschijnt in het normale WordPress update-scherm zodra er een nieuwere release-tag is, met een "Controleer op updates"-link in de pluginregel.

## [1.0.2] - 2026-07-01

### Fixed
- Kalender-widget werd door een thema-regel te breed gerenderd waardoor de rechterkolom achter de "Volgende"-knop verdween. Breedte nu hard vastgezet, z-index verhoogd en dag-cellen robuust gemaakt.

## [1.0.1] - 2026-07-01

### Changed
- Stap-volgorde aangepast op klantwens: **sloep → pakket → datum → tijdslot → gegevens** (sloepkeuze eerst).
- Native datumveld vervangen door een eigen moderne kalender-widget (Nederlandse maanden, week begint op maandag, verleden uitgegrijsd).
- `/timeslots` REST-endpoint en beschikbaarheidscheck accepteren nu een optionele `boat_type_id`-filter.

### Fixed
- Thema overschreef knop- en kaartkleuren; hard vastgezet zodat hover-kleuren kloppen en kaarttekst niet meer tegen de rand loopt.

## [1.0.0] - 2026-07-01

### Added
- Eerste release. Online sloepen boeken met beschikbaarheidscontrole op datum, tijdslot en sloep-type.
- Shortcode `[sloephuren_booking]` met stapsgewijs boekformulier.
- Eigen databasetabellen via `dbDelta` met seed-data (3 sloep-types, 2 pakketten, tijdsloten).
- Anti-dubbelboek via MySQL `GET_LOCK` + hercontrole binnen de lock.
- Pending-boekingen blokkeren max. 15 min; cron ruimt verlopen boekingen op.
- Betaling via mock-provider (werkt direct) of Mollie iDEAL (zodra API-sleutel is ingesteld).
- Admin: boekingen met filters, sloep-types, pakketten, tijdsloten, instellingen.
- Bevestigingsmail naar klant + melding naar beheerder na succesvolle betaling.
