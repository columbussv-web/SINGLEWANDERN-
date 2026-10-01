# SINGLEWANDERN® Mediabuchung

Buchungsseite für die Werbeformen laut Mediadaten 2025/2026. Läuft auf jedem PHP-Webspace (z. B. IONOS) ohne Datenbank.

## Funktionen
- Newsletter-Banner: Kalender mit allen Ausgaben (dienstags und sonntags), belegte Termine gesperrt, exklusiv pro Ausgabe
- Sidebar-Banner: Laufzeit und Startmonat, maximal 3 Partner pro Monat, ausgebuchte Monate gesperrt
- Vormerkfrist 7 Tage: Unbestätigte Anfragen verfallen automatisch, Kunde und Mediaberatung erhalten eine Mail. Kein Cronjob nötig, die Prüfung läuft bei jedem Aufruf mit.
- PDF-Auftragsbestätigung: Ein Klick auf "Bestätigen und PDF senden" im Admin erzeugt das PDF und mailt es an den Kunden, Kopie an die Mediaberatung
- Kombirabatt 10 % auf den Staffelpreis bei Newsletter plus Sidebar
- Gesponserte Wanderung, Partnerbeitrag und Partner-Gutscheincode als Anfrage ohne Preis. Anfrage-Felder unterstützen Auswahllisten (`options` in `pricing.json`)
- Banner-Upload mit Prüfung auf Dateityp, 150 KB und Pixelmaß, im Browser und auf dem Server
- Live-Preisrechner, Server rechnet jeden Preis selbst nach
- Mail an Mediaberatung und Kopie an den Kunden
- Banner nachreichen: persönlicher Link je Buchung (Eingangsmail, Auftragsbestätigung, PDF). Kunden laden fehlende Banner hoch oder ersetzen vorhandene, die Mediaberatung bekommt bei jedem Eingang eine Mail.
- Versandübersicht: 2 Tage vor jeder gebuchten Newsletter-Ausgabe eine Mail mit Partner, Status, Ziel-URL inklusive UTM, ALT-Text und Banner im Anhang. Fehlt das Banner, stehen Kontakt und Upload-Link darin.
- Banner-Erinnerung: 5 Tage vor dem ersten Termin automatisch an Kunden mit fehlendem Banner, einmal je Werbeform
- Kampagnenbericht: 3 Tage nach Kampagnenende an den Kunden, mit Versanddaten, optionalen Kennzahlen aus dem Admin und UTM-Hinweis
- Partner-Landingpage unter `/partner/` für die Kaltakquise, je Branche anpassbar über `?branche=outdoor|reisen|region|ernaehrung|events`. Mit Beispielrechnung aus den Mediadaten und empfohlenem Paket, das direkt in die Buchung führt. Echte Fallbeispiele in `assets/landing.json` unter `cases` eintragen, der Abschnitt erscheint erst dann.
- Mediadaten-PDF unter `/mediadaten/`, bei jedem Abruf aus `pricing.json` erzeugt. Preise, Reichweite, Zielgruppe und technische Angaben stehen nur dort.
- Adminbereich unter `/admin/`: Systemcheck für den Livegang, CSV-Export der Bestandskunden für die Akquise-Liste, nächste Newsletter-Ausgaben, Newsletter-Belegung, Sidebar-Auslastung, Bannervorschau, PDF-Vorschau, erneuter Versand. Status angefragt, bestätigt, abgelaufen, storniert. Stornierte und abgelaufene Termine sind sofort wieder buchbar. Reaktivieren klappt nur, solange die Termine noch frei sind.

## Dateien
| Pfad | Zweck |
|---|---|
| `index.html`, `assets/` | Seite, Styles, Logik |
| `assets/pricing.json` | Einzige Quelle für Preise, Staffeln, Formate, Versandtage, Anfrage-Produkte |
| `api/availability.php` | Liefert freie und belegte Newsletter-Ausgaben |
| `api/book.php` | Nimmt Buchungen inklusive Uploads an |
| `api/config.php` | Standardeinstellungen |
| `api/confirmation.php`, `api/pdf.php` | Auftragsbestätigung, PDF-Generator ohne externe Bibliothek |
| `admin/index.php` | Verwaltung |
| `banner/index.php` | Kundenseite zum Nachreichen der Banner |
| `api/digest.php`, `api/cron.php` | Zeitgesteuerte Aufgaben: Versandübersicht, Erinnerung, Bericht |
| `api/config.local.example.php` | Vorlage für die eigene Konfiguration |
| `partner/`, `assets/landing.json` | Landingpage und Branchentexte |
| `mediadaten/`, `api/mediadaten.php` | Mediadaten als PDF |
| `tools/build-release.sh` | Baut das Upload-Paket nach `dist/` |
| `DEPLOY.md` | Checkliste für den Livegang |
| `storage/` | Buchungen (`bookings.json`) und Banner, per `.htaccess` gesperrt |

## Einrichtung auf IONOS
Schritt für Schritt in `DEPLOY.md`. Paket bauen mit `tools/build-release.sh`. Kurzfassung:

1. Ordner per FTP hochladen, z. B. nach `/werbung/`.
2. `api/config.local.php` anlegen:
   ```php
   <?php
   return [
       'bookingEmail' => 'ihre-adresse@singlewandern.de',
       'mailFrom' => 'noreply@singlewandern.de',
       'adminPasswordHash' => '…',
       'adminUrl' => 'https://www.singlewandern.de/werbung/admin/',
       'siteUrl' => 'https://www.singlewandern.de/werbung/',
       'cronKey' => '…',   // php -r 'echo bin2hex(random_bytes(16)), "\n";'
       'company' => [
           'name' => 'SINGLEWANDERN®',
           'lines' => ['Straße Nr.', 'PLZ Ort'],
           'email' => 'werbung@singlewandern.de',
           'web' => 'www.singlewandern.de',
           'vatId' => 'DE…',
       ],
       'paymentTerms' => 'Die Rechnung erhalten Sie gesondert.',
   ];
   ```
   Den Hash erzeugen: `php -r 'echo password_hash("IhrPasswort", PASSWORD_DEFAULT), "\n";'`
3. Schreibrechte für `storage/` prüfen.
4. Empfohlen: `storageDir` auf einen Ordner außerhalb des Webroots setzen.
5. Zeitgeber für die Versandübersicht einrichten, eine der beiden Varianten:
   - IONOS-Cronjob (je nach Tarif): stündlich `php /pfad/zu/werbung/api/cron.php`
   - Externer Dienst wie cron-job.org: stündlich `https://www.singlewandern.de/werbung/api/cron.php?key=IHR_CRONKEY`
   Ohne Zeitgeber läuft der Versand nur bei Seitenaufrufen und kann sich dann verspäten.
6. Testbuchung abschicken, Mails und Adminbereich prüfen, Testbuchung stornieren.

## Preise ändern
Nur `assets/pricing.json` bearbeiten. Unter `media` stehen Reichweite, Öffnungsrate, Profil, Zielgruppe und technische Angaben. Dort stehen auch Vormerkfrist (`holdDays`) und Sidebar-Plätze (`slots`). Preiskarten, Formular, Rechner und Server übernehmen die Werte automatisch.

## Deep Links
`?produkt=newsletter`, `?produkt=sidebar&anzahl=6`, `?produkt=newsletter,sidebar&anzahl=3&ausgaben=6`, `?produkt=wanderung`, `?produkt=beitrag`, jeweils mit `#buchen`.

## Ohne PHP
Auf reinem Static Hosting (GitHub Pages) funktioniert die Seite eingeschränkt: Kalender ohne Live-Belegung, Versand per Mailprogramm, kein Upload.
