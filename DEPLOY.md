# Livegang auf IONOS

Ziel: Buchungsseite unter `https://www.singlewandern.de/werbung/`. Dauer etwa 45 Minuten.

## 1. Vorbereitung
- [ ] Im IONOS-Kundenbereich unter Hosting, PHP-Einstellungen: PHP 8.1 oder neuer für die Domain wählen (8.3 empfohlen)
- [ ] Postfach oder Weiterleitung für die Empfängeradresse anlegen, z. B. `werbung@singlewandern.de`
- [ ] Absenderadresse `noreply@singlewandern.de` als Postfach oder Alias anlegen. IONOS verschickt Mails von PHP nur zuverlässig mit einer Absenderadresse der eigenen Domain.
- [ ] Liegt das Mail-DNS der Domain nicht bei IONOS: im SPF-Eintrag IONOS ergänzen (`include:_spf-eu.ionos.com`), sonst landen die Mails im Spam

## 2. Hochladen
- [ ] Paket `singlewandern-werbung-JJJJMMTT.zip` entpacken
- [ ] Per SFTP (Zugangsdaten unter Hosting, SFTP & SSH) den Inhalt des Ordners nach `/werbung/` im Webroot von singlewandern.de hochladen
- [ ] Prüfen, dass die versteckten Dateien `.htaccess` mit hochgeladen wurden: im Hauptordner, in `api/` und in `storage/`. Manche FTP-Programme blenden sie aus.
- [ ] Schreibrechte für `storage/` setzen (Ordner 750 oder 755)

WordPress stört nicht: Ein echter Ordner `/werbung/` hat Vorrang vor den WordPress-Umleitungen.

## 3. Konfiguration
- [ ] Lokal das Admin-Passwort hashen: `php -r 'echo password_hash("IhrPasswort", PASSWORD_DEFAULT), "\n";'`. Ohne lokales PHP: den Hash nach dem Hochladen per SSH auf dem Webspace erzeugen.
- [ ] Einen Cron-Schlüssel erzeugen: `php -r 'echo bin2hex(random_bytes(16)), "\n";'`
- [ ] `api/config.local.example.php` als `api/config.local.php` speichern und ausfüllen: Empfänger, Absender, Passwort-Hash, cronKey, Firmenanschrift, USt-IdNr., Zahlungshinweis
- [ ] Empfohlen: `storageDir` auf einen Ordner außerhalb des Webroots setzen

## 4. Zeitgeber
Eine der beiden Varianten, Intervall stündlich:
- [ ] IONOS-Cronjob (Hosting, Cronjobs, je nach Tarif): Befehl `php /homepages/…/werbung/api/cron.php`. Den genauen Pfad zeigt IONOS im Cronjob-Dialog.
- [ ] Oder cron-job.org (kostenlos): URL `https://www.singlewandern.de/werbung/api/cron.php?key=IHR_CRONKEY`

## 5. Systemcheck
- [ ] `https://www.singlewandern.de/werbung/admin/` aufrufen, anmelden, Systemcheck ausführen
- [ ] Alle Punkte auf OK. „Zeitgeber läuft“ wird erst nach dem ersten Cron-Lauf grün.

## 6. Testlauf
- [ ] Testbuchung mit eigener Mailadresse: Newsletter-Ausgabe plus Sidebar, ohne Banner
- [ ] Eingangsmail beim Kunden und bei der Mediaberatung prüfen, Upload-Link öffnen, Banner hochladen
- [ ] Im Admin bestätigen, PDF-Auftragsbestätigung im Postfach prüfen (Anschrift, Beträge, Link)
- [ ] Testbuchung stornieren, Termin ist danach wieder frei

## 7. Einbindung
- [ ] In WordPress einen Menüpunkt „Werben“ oder „Mediadaten“ mit Link auf `/werbung/` anlegen
- [ ] In der Mediadaten-PDF und in E-Mail-Signaturen den Link ergänzen, gern mit Vorauswahl, z. B. `/werbung/?produkt=newsletter#buchen`
- [ ] Datenschutzerklärung um das Buchungsformular ergänzen: Zweck Vertragsanbahnung, gespeicherte Daten, Speicherdauer

## Updates
Neue Version hochladen und dabei `api/config.local.php` und `storage/` nicht überschreiben. Preise ändern: nur `assets/pricing.json` ersetzen.
