# SINGLEWANDERN® Mediabuchung

Statische Buchungsseite für die Werbeformen laut Mediadaten 2025/2026. Kein Build, kein Backend.

## Inhalt
- `index.html` Seite mit Reichweite, Zielgruppe, Preisen, Buchungsformular, Technik
- `assets/config.js` Preise, Staffeln, Formate, Empfängeradresse, Formular-Endpunkt
- `assets/app.js` Preisrechner und Versand
- `assets/styles.css` Layout inkl. Dark Mode und Mobilansicht

## Werbeformen
| Werbeform | Einzelpreis | ab 3 | ab 6 | ab 12 |
|---|---|---|---|---|
| Newsletter-Banner (pro Versand) | 250 € | 225 € | 210 € | 190 € |
| Sidebar-Banner (pro Monat) | 150 € | 135 € | 120 € | 105 € |

## Anfragen empfangen
- Standard: Das Formular öffnet das Mailprogramm mit vorausgefüllter Anfrage an `bookingEmail`.
- Empfohlen: `formEndpoint` in `assets/config.js` setzen (z. B. Formspree oder ein PHP-Skript auf IONOS). Dann gehen Anfragen direkt als JSON-POST ein.

## Deep Links
`index.html?produkt=newsletter&anzahl=6#buchen` wählt das Produkt samt Menge vor. Werte für `produkt`: `newsletter`, `sidebar`.

## Hosting
Ordner per FTP auf IONOS hochladen, als Unterseite in WordPress einbinden oder über GitHub Pages veröffentlichen.
