# SINGLEWANDERN® Mediabuchung

Buchungsseite für die Werbeformen laut Mediadaten 2025/2026. Läuft auf jedem PHP-Webspace (z. B. IONOS) ohne Datenbank.

## Funktionen
- Newsletter-Banner: Kalender mit allen Ausgaben (dienstags und sonntags), belegte Termine gesperrt, exklusiv pro Ausgabe
- Sidebar-Banner: Laufzeit und Startmonat, maximal 3 Partner pro Monat, ausgebuchte Monate gesperrt
- Vormerkfrist 7 Tage: Unbestätigte Anfragen verfallen automatisch, Kunde und Mediaberatung erhalten eine Mail. Kein Cronjob nötig, die Prüfung läuft bei jedem Aufruf mit.
- PDF-Auftragsbestätigung: Ein Klick auf "Bestätigen und PDF senden" im Admin erzeugt das PDF und mailt es an den Kunden, Kopie an die Mediaberatung
- Kombirabatt 10 % auf den Staffelpreis bei Newsletter plus Sidebar
- Gesponserte Wanderung und Partnerbeitrag als Anfrage ohne Preis
- Banner-Upload mit Prüfung auf Dateityp, 150 KB und Pixelmaß, im Browser und auf dem Server
- Live-Preisrechner, Server rechnet jeden Preis selbst nach
- Mail an Mediaberatung und Kopie an den Kunden
- Adminbereich unter `/admin/`: Newsletter-Belegung, Sidebar-Auslastung, Bannervorschau, PDF-Vorschau, erneuter Versand. Status angefragt, bestätigt, abgelaufen, storniert. Stornierte und abgelaufene Termine sind sofort wieder buchbar. Reaktivieren klappt nur, solange die Termine noch frei sind.

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
| `storage/` | Buchungen (`bookings.json`) und Banner, per `.htaccess` gesperrt |

## Einrichtung auf IONOS
1. Ordner per FTP hochladen, z. B. nach `/werbung/`.
2. `api/config.local.php` anlegen:
   ```php
   <?php
   return [
       'bookingEmail' => 'ihre-adresse@singlewandern.de',
       'mailFrom' => 'noreply@singlewandern.de',
       'adminPasswordHash' => '…',
       'adminUrl' => 'https://www.singlewandern.de/werbung/admin/',
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
5. Testbuchung abschicken, Mails und Adminbereich prüfen, Testbuchung stornieren.

## Preise ändern
Nur `assets/pricing.json` bearbeiten. Dort stehen auch Vormerkfrist (`holdDays`) und Sidebar-Plätze (`slots`). Preiskarten, Formular, Rechner und Server übernehmen die Werte automatisch.

## Deep Links
`?produkt=newsletter`, `?produkt=sidebar&anzahl=6`, `?produkt=wanderung`, `?produkt=beitrag`, jeweils mit `#buchen`.

## Ohne PHP
Auf reinem Static Hosting (GitHub Pages) funktioniert die Seite eingeschränkt: Kalender ohne Live-Belegung, Versand per Mailprogramm, kein Upload.
