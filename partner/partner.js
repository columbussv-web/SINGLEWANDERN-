(function () {
  "use strict";

  const $ = (s) => document.querySelector(s);
  const el = (tag, cls, text) => { const n = document.createElement(tag); if (cls) n.className = cls; if (text != null) n.textContent = text; return n; };
  const eur = new Intl.NumberFormat("de-DE", { style: "currency", currency: "EUR", maximumFractionDigits: 0 });
  const eur2 = new Intl.NumberFormat("de-DE", { style: "currency", currency: "EUR" });
  const num = new Intl.NumberFormat("de-DE");
  const unitPrice = (p, q) => (p.tiers.find((t) => q >= t.min) || { price: p.basePrice }).price;

  let P, L;

  function bookingLink(nl, sb) {
    const prod = [nl && "newsletter", sb && "sidebar"].filter(Boolean);
    if (!prod.length) return "../#buchen";
    const q = new URLSearchParams({ produkt: prod.join(",") });
    if (sb) q.set("anzahl", sb);
    if (nl) q.set("ausgaben", nl);
    return "../?" + q.toString() + "#buchen";
  }

  // gleiche Logik wie im Buchungsformular, in Cent
  function calc(nl, sb) {
    const pct = (c, r) => Math.round(c * Math.round(r * 100) / 100);
    const pn = P.products.newsletter, ps = P.products.sidebar;
    const nlC = nl ? nl * unitPrice(pn, nl) * 100 : 0;
    const sbC = sb ? sb * unitPrice(ps, sb) * 100 : 0;
    const combo = nl && sb;
    const comboC = combo ? pct(nlC + sbC, P.comboDiscount) : 0;
    const nlNetC = combo ? nlC - pct(nlC, P.comboDiscount) : nlC;
    const fullC = (nl * pn.basePrice + sb * ps.basePrice) * 100;
    return { net: (nlC + sbC - comboC) / 100, save: (fullC - (nlC + sbC - comboC)) / 100, nlNet: nlNetC / 100 };
  }

  function update() {
    const nl = Number($("#c-nl").value), sb = Number($("#c-sb").value);
    const m = P.media;
    const reach = nl * m.subscribers, opens = Math.round(reach * m.openRate / 100);
    const r = calc(nl, sb);
    $("#o-reach").textContent = num.format(reach);
    $("#o-opens").textContent = num.format(opens);
    $("#o-months").textContent = sb;
    $("#o-tkp").textContent = opens ? eur2.format(r.nlNet / opens * 1000) : "–";
    $("#o-save").textContent = eur.format(r.save);
    $("#o-net").textContent = eur.format(r.net);
    document.querySelectorAll("[data-book]").forEach((a) => (a.href = bookingLink(nl, sb)));
  }

  function render() {
    const params = new URLSearchParams(location.search);
    const key = params.get("branche");
    const seg = (key && L.segments[key]) || L.default;
    const m = P.media;

    $("#seg-label").textContent = "Für " + seg.label;
    $("#seg-headline").textContent = seg.headline;
    $("#seg-intro").textContent = seg.intro;
    document.title = seg.headline + " | SINGLEWANDERN®";

    const kpis = [
      ["ca. " + num.format(m.subscribers), "Newsletter-Abonnentinnen und -Abonnenten"],
      [m.sendsPerWeek + " × pro Woche", "Newsletter-Versand"],
      ["bis " + m.openRate + " %", "Öffnungsrate"],
      ["1 Partner", "exklusiver Werbeplatz pro Ausgabe"]
    ];
    kpis.forEach(([v, l]) => { const li = el("li"); li.append(el("strong", null, v), el("span", null, l)); $("#kpis").append(li); });
    m.audience.forEach((a) => $("#audience").append(el("span", null, a)));
    $("#credibility").textContent = m.credibility + " Ideal für " + m.idealFor;
    $("#w-cred").textContent = m.credibility;
    $("#w-slots").textContent = P.products.sidebar.slots;

    const nlSel = $("#c-nl"), sbSel = $("#c-sb");
    [0, 1, 3, 6, 12].forEach((n) => {
      nlSel.add(new Option(n ? `${n} × ${eur.format(unitPrice(P.products.newsletter, n))}` : "keine", n));
      sbSel.add(new Option(n ? `${n} × ${eur.format(unitPrice(P.products.sidebar, n))}` : "keine", n));
    });
    nlSel.value = seg.package.newsletter;
    sbSel.value = seg.package.sidebar;
    nlSel.addEventListener("change", update);
    sbSel.addEventListener("change", update);

    if (L.cases && L.cases.length) {
      $("#cases").hidden = false;
      L.cases.forEach((c) => {
        const box = el("article", "case");
        box.append(el("blockquote", null, c.quote));
        if (c.result) box.append(el("p", null, c.result));
        box.append(el("p", "muted small", [c.person, c.company, c.segment].filter(Boolean).join(", ")));
        $("#case-list").append(box);
      });
    }

    const links = $("#seg-links");
    Object.entries(L.segments).forEach(([k, s]) => {
      const a = el("a", k === key ? "on" : "", s.label);
      a.href = "?branche=" + k;
      links.append(a);
    });
    update();
  }

  Promise.all([
    fetch("../assets/pricing.json", { cache: "no-store" }).then((r) => r.json()),
    fetch("../assets/landing.json", { cache: "no-store" }).then((r) => r.json())
  ]).then(([p, l]) => { P = p; L = l; render(); })
    .catch(() => { $("#seg-intro").textContent = "Daten konnten nicht geladen werden."; });
})();
