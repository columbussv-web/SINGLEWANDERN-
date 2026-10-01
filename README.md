# SINGLEWANDERN® Mediabuchung

Buchungsseite für die Werbeformen laut Mediadaten 2025/2026. Läuft auf jedem PHP-Webspace (z. B. IONOS) ohne Datenbank.

## Funktionen
- Newsletter-Banner: Kalender mit allen Ausgaben (dienstags und sonntags), belegte Termine gesperrt, exklusiv pro Ausgabe
- Sidebar-Banner: Laufzeit und Startmonat
- Kombirabatt 10 % auf den Staffelpreis bei Newsletter plus Sidebar
- Gesponserte Wanderung und Partnerbeitrag als Anfrage ohne Preis
- Banner-Upload mit Prüfung auf Dateityp, 150 KB und Pixelmaß, im Browser und auf dem Server
- Live-Preisrechner, Server rechnet jeden Preis selbst nach
- Mail an Mediaberatung und Kopie an den Kunden
- Adminbereich unter `/admin/`: Belegungsübersicht, Bannervorschau, Status angefragt, bestätigt, storniert. Stornierte Termine sind sofort wieder buchbar.

## Dateien
| Pfad | Zweck |
|---|---|
| `index.html`, `assets/` | Seite, Styles, Logik |
| `assets/pricing.json` | Einzige Quelle für Preise, Staffeln, Formate, Versandtage, Anfrage-Produkte |
| `api/availability.php` | Liefert freie und belegte Newsletter-Ausgaben |
| `api/book.php` | Nimmt Buchungen inklusive Uploads an |
| `api/config.php` | Standardeinstellungen |
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
   ];
   ```
   Den Hash erzeugen: `php -r 'echo password_hash("IhrPasswort", PASSWORD_DEFAULT), "\n";'`
3. Schreibrechte für `storage/` prüfen.
4. Empfohlen: `storageDir` auf einen Ordner außerhalb des Webroots setzen.
5. Testbuchung abschicken, Mails und Adminbereich prüfen, Testbuchung stornieren.

## Preise ändern
Nur `assets/pricing.json` bearbeiten. Preiskarten, Formular, Rechner und Server übernehmen die Werte automatisch.

## Deep Links
`?produkt=newsletter`, `?produkt=sidebar&anzahl=6`, `?produkt=wanderung`, `?produkt=beitrag`, jeweils mit `#buchen`.

## Ohne PHP
Auf reinem Static Hosting (GitHub Pages) funktioniert die Seite eingeschränkt: Kalender ohne Live-Belegung, Versand per Mailprogramm, kein Upload.
