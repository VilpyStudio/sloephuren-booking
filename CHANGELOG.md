# Changelog

Alle noemenswaardige wijzigingen aan Sloephuren Booking worden hier bijgehouden.
Format volgt losjes [Keep a Changelog](https://keepachangelog.com/); versies volgen [SemVer](https://semver.org/lang/nl/).

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
