(function () {
  "use strict";

  const API = "api/";
  const form = document.getElementById("booking");
  const eur = new Intl.NumberFormat("de-DE", { style: "currency", currency: "EUR" });
  const monthFmt = new Intl.DateTimeFormat("de-DE", { month: "long", year: "numeric" });
  const dayFmt = new Intl.DateTimeFormat("de-DE", { weekday: "short", day: "2-digit", month: "2-digit" });
  const longFmt = new Intl.DateTimeFormat("de-DE", { weekday: "short", day: "2-digit", month: "2-digit", year: "numeric" });
  const $ = (sel, root = document) => root.querySelector(sel);
  const el = (tag, { dataset, ...props } = {}, text) => {
    const n = Object.assign(document.createElement(tag), props, text != null ? { textContent: text } : {});
    if (dataset) Object.assign(n.dataset, dataset);
    return n;
  };
  const bookingEmail = $("#mail-link").href.replace("mailto:", "");

  let P;                       // Preise aus pricing.json
  let apiOnline = false;       // Backend erreichbar
  let issues = [];             // [{date, status}]
  let sidebarFree = null;      // {"2026-11": 2, …} freie Plätze je Monat
  const picked = new Set();    // gewählte Newsletter-Ausgaben
  const fileState = {};        // Prüfergebnis je Upload

  // ---------- Hilfen ----------
  const parseISO = (s) => { const [y, m, d] = s.split("-").map(Number); return new Date(y, m - 1, d); };
  const toISO = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}-${String(d.getDate()).padStart(2, "0")}`;
  const unitPrice = (p, qty) => (p.tiers.find((t) => qty >= t.min) || { price: p.basePrice }).price;
  const nextTier = (p, qty) => p.tiers.filter((t) => t.min > qty).sort((a, b) => a.min - b.min)[0];
  const isOn = (key) => !!form.elements[key + "_on"] && form.elements[key + "_on"].checked;

  function nextMonth() {
    const d = new Date(); d.setDate(1); d.setMonth(d.getMonth() + 1);
    return toISO(d).slice(0, 7);
  }
  function sidebarPeriod(start, months) {
    if (!start) return "";
    const [y, m] = start.split("-").map(Number);
    const from = new Date(y, m - 1, 1), to = new Date(y, m - 2 + months, 1);
    return months === 1 ? monthFmt.format(from) : `${monthFmt.format(from)} bis ${monthFmt.format(to)}`;
  }
  function monthList() {
    const out = [], d = new Date(); d.setDate(1);
    for (let i = 0; i <= P.products.sidebar.horizonMonths; i++) {
      out.push(toISO(d).slice(0, 7));
      d.setMonth(d.getMonth() + 1);
    }
    return out;
  }
  function monthsFrom(start, n) {
    const [y, m] = start.split("-").map(Number), out = [];
    for (let i = 0; i < n; i++) out.push(toISO(new Date(y, m - 1 + i, 1)).slice(0, 7));
    return out;
  }
  const freeIn = (m) => (sidebarFree ? sidebarFree[m] ?? 0 : P.products.sidebar.slots);
  // Monate der gewählten Laufzeit ohne freien Platz
  function sidebarBlocked() {
    const start = form.elements.sidebar_start.value;
    return start ? monthsFrom(start, sidebarQty()).filter((m) => freeIn(m) < 1) : [];
  }
  function fillSidebarMonths() {
    const sel = form.elements.sidebar_start, keep = sel.value || nextMonth(), slots = P.products.sidebar.slots;
    sel.innerHTML = "";
    monthList().forEach((m) => {
      const f = freeIn(m);
      const o = new Option(`${monthFmt.format(parseISO(m + "-01"))} · ${f ? `${f} von ${slots} frei` : "ausgebucht"}`, m);
      o.disabled = !f;
      sel.add(o);
    });
    const firstFree = [...sel.options].find((o) => !o.disabled && o.value >= nextMonth());
    sel.value = [...sel.options].some((o) => o.value === keep && !o.disabled) ? keep : (firstFree ? firstFree.value : keep);
  }

  function sidebarQty() {
    const input = form.elements.sidebar_qty;
    let v = parseInt(input.value, 10);
    if (!Number.isFinite(v) || v < 1) v = 1;
    v = Math.min(v, P.products.sidebar.max);
    input.value = v;
    return v;
  }

  // Ersatzweise Termine lokal erzeugen, wenn das Backend fehlt
  function localIssues() {
    const nl = P.products.newsletter, out = [];
    const d = new Date(); d.setHours(0, 0, 0, 0); d.setDate(d.getDate() + nl.leadDays);
    const end = new Date(); end.setDate(end.getDate() + nl.horizonDays);
    for (; d <= end; d.setDate(d.getDate() + 1)) {
      if (nl.weekdays.includes(d.getDay())) out.push({ date: toISO(d), status: "frei" });
    }
    return out;
  }

  // ---------- Mediadaten ----------
  function renderMedia() {
    const m = P.media, num = new Intl.NumberFormat("de-DE");
    const kpis = [
      ["ca. " + num.format(m.subscribers), "Newsletter-Abonnentinnen und -Abonnenten"],
      [P.products.newsletter.weekdays.length === 2 ? "Di und So" : m.sendsPerWeek + " × pro Woche", `Newsletter-Versand, ${m.sendsPerWeek} × pro Woche`],
      ["bis " + m.openRate + " %", "Öffnungsrate"],
      ["1 Partner", "exklusiver Werbeplatz pro Newsletter-Ausgabe"]
    ];
    const ul = $("#reichweite");
    ul.innerHTML = "";
    kpis.forEach(([v, l]) => { const li = el("li"); li.append(el("strong", {}, v), el("span", {}, l)); ul.append(li); });
    const tags = $("#audience");
    tags.innerHTML = "";
    m.audience.forEach((a) => tags.append(el("span", {}, a)));
    $("#credibility").textContent = `${m.credibility} Ideal für ${m.idealFor}`;
    const specs = $("#specs");
    specs.innerHTML = "";
    Object.entries(m.specs).forEach(([title, lines]) => {
      const box = el("div");
      const list = el("ul", { className: "list" });
      lines.forEach((l) => list.append(el("li", {}, l)));
      box.append(el("h3", {}, title), list);
      specs.append(box);
    });
  }

  // ---------- Preiskarten ----------
  function renderCards() {
    const box = $("#price-cards");
    for (const [key, p] of Object.entries(P.products)) {
      const card = el("article", { className: "card" });
      card.append(el("h3", {}, p.name));
      const price = el("p", { className: "price" });
      price.append(el("strong", {}, eur.format(p.basePrice).replace(",00", "")), ` pro ${p.unit}`);
      card.append(price);
      const ul = el("ul", { className: "list" });
      const bullets = key === "newsletter"
        ? ["Exklusiver Werbeplatz pro Ausgabe", "Versand dienstags und sonntags", `ca. ${new Intl.NumberFormat("de-DE").format(P.media.subscribers)} Empfänger, bis ${P.media.openRate} % Öffnungsrate`]
        : ["Sichtbar auf den wichtigsten Buchungs- und Informationsseiten", "Konstante Präsenz bei einer engagierten Community", "Formate " + p.formats.map((f) => `${f.w} × ${f.h}`).join(", ") + " px"];
      bullets.forEach((b) => ul.append(el("li", {}, b)));
      card.append(ul);
      const t = el("table", { className: "tiers" });
      t.innerHTML = `<thead><tr><th>Staffel</th><th>Preis pro ${p.unit}</th></tr></thead>`;
      const tb = el("tbody");
      [...p.tiers].sort((a, b) => a.min - b.min).forEach((tier) => {
        const tr = el("tr");
        tr.append(el("td", {}, tier.min === 1 ? `1 ${p.unit}` : `ab ${tier.min} ${p.tierLabel}`), el("td", {}, eur.format(tier.price).replace(",00", "")));
        tb.append(tr);
      });
      t.append(tb);
      card.append(t);
      card.append(el("button", { className: "btn btn-block", type: "button", dataset: { pick: key } }, `${p.short} buchen`));
      box.append(card);
    }
    const rbox = $("#request-cards");
    for (const [key, r] of Object.entries(P.requestProducts)) {
      const card = el("article", { className: "card" });
      card.append(el("h3", {}, r.name), el("p", { className: "price" }, "Preis auf Anfrage"), el("p", {}, r.description));
      card.append(el("button", { className: "btn btn-ghost btn-block", type: "button", dataset: { pick: key } }, "Anfragen"));
      rbox.append(card);
    }
    $("#combo-pct").textContent = Math.round(P.comboDiscount * 100) + " %";
  }

  // ---------- Formular-Aufbau ----------
  function buildForm() {
    for (const [key, p] of Object.entries(P.products)) {
      const sel = form.elements[key + "_format"];
      p.formats.forEach((f) => sel.add(new Option(f.label, f.label)));
    }
    const sb = P.products.sidebar;
    const presets = $('.presets[data-for="sidebar_qty"]');
    sb.presets.forEach((n) => {
      const b = el("button", { type: "button", dataset: { value: n } }, `${n} × ${eur.format(unitPrice(sb, n)).replace(",00", "")}`);
      b.addEventListener("click", () => { form.elements.sidebar_qty.value = n; render(); });
      presets.append(b);
    });
    fillSidebarMonths();
    document.querySelectorAll("[data-hold]").forEach((n) => (n.textContent = P.holdDays));

    const box = $("#request-products");
    for (const [key, r] of Object.entries(P.requestProducts)) {
      const wrap = el("div", { className: "product", dataset: { product: key } });
      const toggle = el("label", { className: "toggle" });
      const span = el("span");
      span.append(el("strong", {}, r.name), " ", el("em", {}, "Preis auf Anfrage"));
      toggle.append(el("input", { type: "checkbox", name: key + "_on", value: "1" }), span);
      const body = el("div", { className: "product-body" });
      body.append(el("p", { className: "muted small" }, r.description));
      r.fields.forEach((f) => {
        const lab = el("label", {}, f.label);
        lab.append(f.multiline ? el("textarea", { name: `${key}_${f.key}`, rows: 3 }) : el("input", { type: "text", name: `${key}_${f.key}` }));
        body.append(lab);
      });
      wrap.append(toggle, body);
      box.append(wrap);
    }
  }

  // ---------- Newsletter-Kalender ----------
  function renderCalendar() {
    const cal = $("#nl-cal");
    cal.innerHTML = "";
    const byMonth = new Map();
    issues.forEach((i) => {
      const k = i.date.slice(0, 7);
      if (!byMonth.has(k)) byMonth.set(k, []);
      byMonth.get(k).push(i);
    });
    for (const [ym, list] of byMonth) {
      const group = el("div", { className: "cal-month" });
      group.append(el("p", { className: "cal-title" }, monthFmt.format(parseISO(ym + "-01"))));
      const row = el("div", { className: "cal-days" });
      list.forEach((i) => {
        const taken = i.status !== "frei";
        const b = el("button", {
          type: "button",
          className: "day" + (taken ? " taken" : "") + (picked.has(i.date) ? " sel" : ""),
          disabled: taken,
          title: taken ? "bereits belegt" : "frei",
          dataset: { date: i.date }
        }, dayFmt.format(parseISO(i.date)));
        b.setAttribute("aria-pressed", picked.has(i.date));
        row.append(b);
      });
      group.append(row);
      cal.append(group);
    }
    if (!apiOnline) {
      cal.append(el("p", { className: "muted small" }, "Live-Belegung derzeit nicht verfügbar. Wir prüfen Ihre Wunschtermine nach Eingang."));
    }
  }

  function toggleDate(date) {
    const max = P.products.newsletter.max;
    $("#form-error").textContent = "";
    if (picked.has(date)) picked.delete(date);
    else if (picked.size < max) picked.add(date);
    else { $("#form-error").textContent = `Maximal ${max} Ausgaben pro Anfrage.`; return; }
    if (picked.size && !isOn("newsletter")) form.elements.newsletter_on.checked = true;
    const btn = $(`#nl-cal .day[data-date="${date}"]`);
    btn.classList.toggle("sel", picked.has(date));
    btn.setAttribute("aria-pressed", picked.has(date));
    render();
  }

  async function loadIssues() {
    try {
      const res = await fetch(API + "availability.php", { cache: "no-store" });
      if (!res.ok) throw new Error(res.status);
      const json = await res.json();
      issues = json.newsletter;
      sidebarFree = json.sidebar.free;
      apiOnline = true;
    } catch (_) {
      issues = localIssues();
      sidebarFree = null;
      apiOnline = false;
    }
    fillSidebarMonths();
    // vergebene Termine aus der Auswahl entfernen
    const free = new Set(issues.filter((i) => i.status === "frei").map((i) => i.date));
    [...picked].forEach((d) => free.has(d) || picked.delete(d));
    renderCalendar();
    render();
  }

  // ---------- Upload-Prüfung ----------
  function checkFile(input) {
    const key = input.dataset.product;
    const out = $(`[data-check="${key}"]`);
    const file = input.files[0];
    delete fileState[key];
    out.textContent = "";
    out.className = "file-check small";
    if (!file) return render();

    const max = P.uploadMaxBytes;
    if (!["image/jpeg", "image/png"].includes(file.type)) {
      fileState[key] = { error: "Nur JPG oder PNG." };
    } else if (file.size > max) {
      fileState[key] = { error: `Datei hat ${Math.round(file.size / 1024)} KB, erlaubt sind ${Math.round(max / 1024)} KB.` };
    }
    if (fileState[key]) {
      out.textContent = fileState[key].error;
      out.classList.add("bad");
      return render();
    }
    const img = new Image();
    const url = URL.createObjectURL(file);
    img.onload = () => {
      URL.revokeObjectURL(url);
      const fmt = P.products[key].formats.find((f) => f.label === form.elements[key + "_format"].value);
      const exact = img.naturalWidth === fmt.w && img.naturalHeight === fmt.h;
      const ratio = Math.abs(img.naturalWidth / img.naturalHeight - fmt.w / fmt.h) < 0.02;
      fileState[key] = { w: img.naturalWidth, h: img.naturalHeight };
      out.textContent = `${file.name}: ${img.naturalWidth} × ${img.naturalHeight} px, ${Math.round(file.size / 1024)} KB. ` +
        (exact ? "Passt zum gewählten Format." : ratio ? "Seitenverhältnis passt, das Banner wird skaliert." : `Gewähltes Format ist ${fmt.w} × ${fmt.h} px. Bitte prüfen Sie Format oder Datei.`);
      out.classList.add(exact || ratio ? "ok" : "warn");
      render();
    };
    img.onerror = () => {
      URL.revokeObjectURL(url);
      fileState[key] = { error: "Bild lässt sich nicht lesen." };
      out.textContent = fileState[key].error;
      out.classList.add("bad");
      render();
    };
    img.src = url;
  }

  // ---------- Auswahl & Summe ----------
  function selection() {
    const items = [];
    if (isOn("newsletter") && picked.size) {
      const p = P.products.newsletter, qty = picked.size, price = unitPrice(p, qty);
      const dates = [...picked].sort();
      items.push({
        key: "newsletter", p, qty, price, net: qty * price, save: qty * (p.basePrice - price),
        format: form.elements.newsletter_format.value,
        when: dates.map((d) => longFmt.format(parseISO(d))).join(", "),
        dates
      });
    }
    if (isOn("sidebar")) {
      const p = P.products.sidebar, qty = sidebarQty(), price = unitPrice(p, qty);
      items.push({
        key: "sidebar", p, qty, price, net: qty * price, save: qty * (p.basePrice - price),
        format: form.elements.sidebar_format.value,
        when: sidebarPeriod(form.elements.sidebar_start.value, qty) || "Zeitraum nach Absprache"
      });
    }
    const requests = Object.entries(P.requestProducts).filter(([k]) => isOn(k)).map(([key, r]) => ({ key, r }));
    // in Cent rechnen, identisch zur Serverlogik
    const pct = (cents, rate) => Math.round(cents * Math.round(rate * 100) / 100);
    const sumC = items.reduce((s, i) => s + i.net * 100, 0);
    const comboC = items.length === 2 ? pct(sumC, P.comboDiscount) : 0;
    const vatC = pct(sumC - comboC, P.vatRate);
    const sum = sumC / 100, combo = comboC / 100, net = (sumC - comboC) / 100, vat = vatC / 100;
    const save = items.reduce((s, i) => s + i.save, 0) + combo;
    return { items, requests, sum, combo, net, vat, gross: net + vat, save };
  }

  function render() {
    for (const key of [...Object.keys(P.products), ...Object.keys(P.requestProducts)]) {
      $(`.product[data-product="${key}"]`).classList.toggle("on", isOn(key));
    }
    const q = sidebarQty();
    document.querySelectorAll('.presets[data-for="sidebar_qty"] button').forEach((b) => b.classList.toggle("active", Number(b.dataset.value) === q));
    const period = sidebarPeriod(form.elements.sidebar_start.value, q);
    const blocked = sidebarBlocked();
    const out = $('[data-out="sidebar_period"]');
    out.textContent = blocked.length
      ? `Ausgebucht im ${blocked.map((m) => monthFmt.format(parseISO(m + "-01"))).join(", ")}. Bitte Startmonat oder Laufzeit ändern.`
      : period ? "Laufzeit: " + period : "";
    out.classList.toggle("bad", blocked.length > 0);
    $("#nl-count").textContent = `${picked.size} gewählt`;
    form.elements.newsletter_dates.value = [...picked].sort().join(",");

    const s = selection();
    const lines = $("#summary-lines");
    lines.innerHTML = "";
    if (!s.items.length && !s.requests.length) lines.append(el("p", { className: "muted" }, "Noch keine Werbeform gewählt."));
    s.items.forEach((it) => {
      const line = el("div", { className: "line" });
      const head = el("div", { className: "line-head" });
      head.append(el("span", {}, it.p.name), el("span", {}, eur.format(it.net)));
      line.append(head,
        el("div", { className: "line-sub" }, `${it.qty} ${it.qty === 1 ? it.p.unit : it.p.unitPlural} × ${eur.format(it.price)} · ${it.format}`),
        el("div", { className: "line-sub" }, it.when));
      if (fileState[it.key] && !fileState[it.key].error) line.append(el("div", { className: "line-sub" }, "Banner angehängt"));
      lines.append(line);
    });
    if (s.combo) {
      const line = el("div", { className: "line line-head combo" });
      line.append(el("span", {}, `Kombirabatt ${Math.round(P.comboDiscount * 100)} %`), el("span", {}, "− " + eur.format(s.combo)));
      lines.append(line);
    }
    s.requests.forEach(({ r }) => {
      const line = el("div", { className: "line line-head" });
      line.append(el("span", {}, r.name), el("span", { className: "muted" }, "auf Anfrage"));
      lines.append(line);
    });

    $("#sum-net").textContent = eur.format(s.net);
    $("#sum-save").textContent = s.save ? "− " + eur.format(s.save) : eur.format(0);
    $("#sum-vat").textContent = eur.format(s.vat);
    $("#sum-gross").textContent = eur.format(s.gross);

    let hint = s.items.filter((i) => nextTier(i.p, i.qty))
      .map((i) => { const n = nextTier(i.p, i.qty); return `Ab ${n.min} ${i.p.tierLabel} sinkt der Preis für das ${i.p.short} auf ${eur.format(n.price)} pro ${i.p.unit}.`; })[0];
    if (s.items.length === 1) hint = `Mit ${s.items[0].key === "newsletter" ? "Sidebar-Banner" : "Newsletter-Banner"} dazu sparen Sie zusätzlich ${Math.round(P.comboDiscount * 100)} % Kombirabatt.`;
    $("#tier-hint").textContent = hint || "";

    const needsUrl = s.items.length > 0;
    form.elements.target_url.required = needsUrl;
    $("[data-req-url]").hidden = !needsUrl;
  }

  // ---------- Absenden ----------
  function mailtoText(s, data) {
    const o = ["Buchungsanfrage Werbung SINGLEWANDERN®", ""];
    s.items.forEach((it) => {
      o.push(it.p.name, `  ${it.qty} × ${eur.format(it.price)} = ${eur.format(it.net)} netto`, `  Format: ${it.format}`, `  ${it.when}`, "");
    });
    s.requests.forEach(({ key, r }) => {
      o.push(`${r.name} (Preis auf Anfrage)`);
      r.fields.forEach((f) => data[`${key}_${f.key}`] && o.push(`  ${f.label}: ${data[`${key}_${f.key}`]}`));
      o.push("");
    });
    if (s.items.length) {
      if (s.combo) o.push(`Kombirabatt: − ${eur.format(s.combo)}`);
      o.push(`Summe netto: ${eur.format(s.net)}`, `Gesamt brutto: ${eur.format(s.gross)}`, "");
    }
    o.push(`Ziel-URL: ${data.target_url || "–"}`);
    if (data.utm) o.push(`UTM: ${data.utm}`);
    o.push("", data.company, data.name, data.email);
    if (data.phone) o.push(data.phone);
    o.push(data.address.replace(/\n/g, ", "));
    if (data.vat_id) o.push(`USt-IdNr.: ${data.vat_id}`);
    if (data.message) o.push("", data.message);
    return o.join("\n");
  }

  async function submit(e) {
    e.preventDefault();
    const err = $("#form-error");
    err.textContent = "";
    const s = selection();

    if (isOn("newsletter") && !picked.size) {
      err.textContent = "Bitte wählen Sie mindestens eine Newsletter-Ausgabe.";
      $("#nl-cal").scrollIntoView({ behavior: "smooth", block: "center" });
      return;
    }
    if (!s.items.length && !s.requests.length) {
      err.textContent = "Bitte wählen Sie mindestens eine Werbeform.";
      $("#booking fieldset").scrollIntoView({ behavior: "smooth" });
      return;
    }
    if (isOn("sidebar") && sidebarBlocked().length) {
      err.textContent = "Der Sidebar-Banner ist in einem Monat Ihrer Laufzeit ausgebucht.";
      $('[data-out="sidebar_period"]').scrollIntoView({ behavior: "smooth", block: "center" });
      return;
    }
    const badFile = Object.entries(fileState).find(([k, v]) => v.error && isOn(k));
    if (badFile) {
      err.textContent = `Banner ${P.products[badFile[0]].short}: ${badFile[1].error}`;
      return;
    }
    if (!form.checkValidity()) {
      err.textContent = "Bitte füllen Sie alle Pflichtfelder korrekt aus.";
      form.querySelector(":invalid").focus();
      return;
    }

    const btn = $("#submit-btn");
    let note = "";
    let text = "Wir prüfen Ihre Anfrage und melden uns mit einer Bestätigung.";

    if (apiOnline) {
      const fd = new FormData(form);
      // Dateien nur für gewählte Produkte senden
      for (const key of Object.keys(P.products)) if (!isOn(key)) fd.delete(key + "_file");
      btn.disabled = true;
      btn.textContent = "Wird gesendet …";
      try {
        const res = await fetch(API + "book.php", { method: "POST", body: fd });
        const json = await res.json().catch(() => ({}));
        if (!res.ok) {
          err.textContent = json.error || "Senden fehlgeschlagen. Bitte versuchen Sie es erneut.";
          if (res.status === 409) {
            (json.conflicts || []).forEach((d) => picked.delete(d));
            await loadIssues();
            err.textContent = json.error;
          }
          return;
        }
        text = `Ihre Anfragenummer lautet ${json.id}. ` + (s.items.length ? `Ihre Termine sind bis ${json.holdUntil} vorgemerkt. ` : "") + "Sie erhalten eine Kopie per E-Mail.";
      } catch (_) {
        err.textContent = `Verbindung fehlgeschlagen. Bitte versuchen Sie es erneut oder schreiben Sie an ${bookingEmail}.`;
        return;
      } finally {
        btn.disabled = false;
        btn.textContent = "Verbindlich anfragen";
      }
    } else {
      const data = Object.fromEntries(new FormData(form).entries());
      const subject = `Buchungsanfrage Werbung: ${data.company}`;
      window.location.href = `mailto:${bookingEmail}?subject=${encodeURIComponent(subject)}&body=${encodeURIComponent(mailtoText(s, data))}`;
      note = `Ihr E-Mail-Programm öffnet sich mit der vorausgefüllten Anfrage. Bitte hängen Sie Ihre Banner dort an. Falls sich nichts öffnet, schreiben Sie an ${bookingEmail}.`;
    }

    form.hidden = true;
    $("#success-text").textContent = text;
    $("#success-note").textContent = note;
    $("#success").hidden = false;
    $("#success").scrollIntoView({ behavior: "smooth", block: "center" });
  }

  function reset() {
    form.reset();
    form.elements.sidebar_start.value = nextMonth();
    picked.clear();
    Object.keys(fileState).forEach((k) => delete fileState[k]);
    document.querySelectorAll(".file-check").forEach((n) => { n.textContent = ""; n.className = "file-check small"; });
    form.hidden = false;
    $("#success").hidden = true;
    loadIssues();
  }

  // ---------- Start ----------
  async function init() {
    try {
      P = await (await fetch("assets/pricing.json", { cache: "no-store" })).json();
    } catch (_) {
      $("#price-cards").textContent = "Preise konnten nicht geladen werden.";
      return;
    }
    renderMedia();
    renderCards();
    buildForm();

    document.addEventListener("click", (e) => {
      const pick = e.target.closest("[data-pick]");
      if (pick) {
        form.elements[pick.dataset.pick + "_on"].checked = true;
        render();
        $(`.product[data-product="${pick.dataset.pick}"]`).scrollIntoView({ behavior: "smooth", block: "center" });
      }
      const day = e.target.closest("#nl-cal .day");
      if (day && !day.disabled) toggleDate(day.dataset.date);
    });
    form.querySelectorAll(".stepper").forEach((st) => {
      const input = $("input", st);
      st.querySelectorAll("button").forEach((b) => b.addEventListener("click", () => {
        input.value = (parseInt(input.value, 10) || 1) + Number(b.dataset.step);
        render();
      }));
    });
    form.querySelectorAll('input[type="file"]').forEach((i) => i.addEventListener("change", () => checkFile(i)));
    form.querySelectorAll('select[name$="_format"]').forEach((sel) => sel.addEventListener("change", () => {
      const fi = form.elements[sel.name.replace("_format", "_file")];
      if (fi.files.length) checkFile(fi);
    }));

    // Vorauswahl per URL, z. B. ?produkt=newsletter,sidebar&anzahl=3&ausgaben=6#buchen
    const params = new URLSearchParams(location.search);
    (params.get("produkt") || "").split(",").forEach((key) => {
      if (form.elements[key + "_on"]) form.elements[key + "_on"].checked = true;
    });
    const n = parseInt(params.get("anzahl"), 10);
    if (n && isOn("sidebar")) form.elements.sidebar_qty.value = n;
    const ausgaben = parseInt(params.get("ausgaben"), 10);
    if (ausgaben && isOn("newsletter")) {
      const hint = $("#nl-hint");
      hint.textContent = `Empfohlen: ${ausgaben} Ausgaben. Klicken Sie Ihre Wunschtermine an.`;
      hint.hidden = false;
    }

    form.addEventListener("input", render);
    form.addEventListener("change", render);
    form.addEventListener("submit", submit);
    $("#new-booking").addEventListener("click", reset);

    render();
    await loadIssues();
  }

  init();
})();
