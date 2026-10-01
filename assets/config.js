// Zentrale Konfiguration. Preise entsprechen den Mediadaten 2025/2026.
window.SW_CONFIG = {
  // Empfängeradresse für Buchungsanfragen (mailto-Fallback)
  bookingEmail: "werbung@singlewandern.de",
  // Optional: Formular-Endpunkt (z. B. Formspree, Make, eigenes PHP-Skript).
  // Leer lassen, dann öffnet sich das Mailprogramm mit vorausgefüllter Anfrage.
  formEndpoint: "",
  vatRate: 0.19,
  products: {
    newsletter: {
      name: "Banner im Newsletter",
      short: "Newsletter-Banner",
      tierLabel: "Schaltungen",
      unit: "Versand",
      unitPlural: "Versände",
      basePrice: 250,
      tiers: [
        { min: 12, price: 190 },
        { min: 6, price: 210 },
        { min: 3, price: 225 },
        { min: 1, price: 250 }
      ],
      presets: [1, 3, 6, 12],
      max: 24,
      formats: ["600 × 200 px (Standard)", "600 × 300 px", "1048 × 250 px"]
    },
    sidebar: {
      name: "Sidebar-Banner auf singlewandern.de",
      short: "Sidebar-Banner",
      tierLabel: "Monaten Laufzeit",
      unit: "Monat",
      unitPlural: "Monate",
      basePrice: 150,
      tiers: [
        { min: 12, price: 105 },
        { min: 6, price: 120 },
        { min: 3, price: 135 },
        { min: 1, price: 150 }
      ],
      presets: [1, 3, 6, 12],
      max: 12,
      formats: ["300 × 250 px (Medium Rectangle)", "300 × 600 px (Half Page)", "160 × 600 px (Skyscraper)"]
    }
  }
};
