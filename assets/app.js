(function () {
  "use strict";

  const cfg = window.SW_CONFIG;
  const form = document.getElementById("booking");
  const eur = new Intl.NumberFormat("de-DE", { style: "currency", currency: "EUR" });
  const monthFmt = new Intl.DateTimeFormat("de-DE", { month: "long", year: "numeric" });
  const dateFmt = new Intl.DateTimeFormat("de-DE", { day: "2-digit", month: "2-digit", year: "numeric" });
  const $ = (sel, root = document) => root.querySelector(sel);

  // Staffelpreis: höchste Staffel, deren Mindestmenge erreicht ist
  function unitPrice(product, qty) {
    return product.tiers.find((t) => qty >= t.min).price;
  }
  function nextTier(product, qty) {
    return product.tiers.filter((t) => t.min > qty).sort((a, b) => a.min - b.min)[0];
  }

  function clampQty(key) {
    const input = form.elements[key + "_qty"];
    const max = cfg.products[key].max;
    let v = parseInt(input.value, 10);
    if (!Number.isFinite(v) || v < 1) v = 1;
    if (v > max) v = max;
    input.value = v;
    return v;
  }

  function isoToday(offsetDays = 0) {
    const d = new Date();
    d.setDate(d.getDate() + offsetDays);
    return d.toISOString().slice(0, 10);
  }
  function nextMonth() {
    const d = new Date();
    d.setDate(1);
    d.setMonth(d.getMonth() + 1);
    return d.toISOString().slice(0, 7);
  }
  function sidebarPeriod(start, months) {
    if (!start) return "";
    const [y, m] = start.split("-").map(Number);
    const from = new Date(y, m - 1, 1);
    const to = new Date(y, m - 1 + months - 1, 1);
    return months === 1 ? monthFmt.format(from) : monthFmt.format(from) + " bis " + monthFmt.format(to);
  }

  function selection() {
    const items = [];
    for (const key of Object.keys(cfg.products)) {
      if (!form.elements[key + "_on"].checked) continue;
      const p = cfg.products[key];
      const qty = clampQty(key);
      const price = unitPrice(p, qty);
      const item = {
        key, name: p.name, qty, price,
        unitLabel: qty === 1 ? p.unit : p.unitPlural,
        net: qty * price,
        save: qty * (p.basePrice - price),
        format: form.elements[key + "_format"].value,
        next: nextTier(p, qty)
      };
      if (key === "newsletter") {
        const s = form.elements.newsletter_start.value;
        item.when = s ? "Start ab " + dateFmt.format(new Date(s)) : "Start nach Absprache";
        item.notes = form.elements.newsletter_dates.value.trim();
      } else {
        item.when = sidebarPeriod(form.elements.sidebar_start.value, qty) || "Zeitraum nach Absprache";
      }
      items.push(item);
    }
    return items;
  }

  function render() {
    for (const key of Object.keys(cfg.products)) {
      const on = form.elements[key + "_on"].checked;
      $(`.product[data-product="${key}"]`).classList.toggle("on", on);
      const qty = clampQty(key);
      document.querySelectorAll(`.presets[data-for="${key}_qty"] button`).forEach((b) => {
        b.classList.toggle("active", Number(b.dataset.value) === qty);
      });
    }
    $('[data-out="sidebar_period"]').textContent =
      sidebarPeriod(form.elements.sidebar_start.value, clampQty("sidebar")) ? "Laufzeit: " + sidebarPeriod(form.elements.sidebar_start.value, clampQty("sidebar")) : "";

    const items = selection();
    const lines = $("#summary-lines");
    lines.innerHTML = "";
    if (!items.length) {
      lines.innerHTML = '<p class="muted">Noch keine Werbeform gewählt.</p>';
    }
    for (const it of items) {
      const el = document.createElement("div");
      el.className = "line";
      el.innerHTML =
        `<div class="line-head"><span></span><span>${eur.format(it.net)}</span></div>` +
        `<div class="line-sub"></div><div class="line-sub"></div>`;
      el.children[0].children[0].textContent = it.name;
      el.children[1].textContent = `${it.qty} ${it.unitLabel} × ${eur.format(it.price)} · ${it.format}`;
      el.children[2].textContent = it.when;
      lines.appendChild(el);
    }

    const net = items.reduce((s, i) => s + i.net, 0);
    const save = items.reduce((s, i) => s + i.save, 0);
    const vat = net * cfg.vatRate;
    $("#sum-net").textContent = eur.format(net);
    $("#sum-save").textContent = save ? "− " + eur.format(save) : eur.format(0);
    $("#sum-vat").textContent = eur.format(vat);
    $("#sum-gross").textContent = eur.format(net + vat);

    // Hinweis auf die nächste Staffel
    const hint = items
      .filter((i) => i.next)
      .map((i) => {
        const p = cfg.products[i.key];
        return `Ab ${i.next.min} ${p.tierLabel} sinkt der Preis für das ${p.short} auf ${eur.format(i.next.price)} pro ${p.unit}.`;
      })[0];
    $("#tier-hint").textContent = hint || "";
  }

  function buildText(items, data) {
    const net = items.reduce((s, i) => s + i.net, 0);
    const out = [];
    out.push("Buchungsanfrage Werbung SINGLEWANDERN®", "");
    items.forEach((it) => {
      out.push(`${it.name}`);
      out.push(`  ${it.qty} ${it.unitLabel} × ${eur.format(it.price)} = ${eur.format(it.net)} netto`);
      out.push(`  Format: ${it.format}`);
      out.push(`  ${it.when}`);
      if (it.notes) out.push(`  Terminwünsche: ${it.notes}`);
      out.push("");
    });
    out.push(`Summe netto: ${eur.format(net)}`);
    out.push(`zzgl. MwSt.: ${eur.format(net * cfg.vatRate)}`);
    out.push(`Gesamt brutto: ${eur.format(net * (1 + cfg.vatRate))}`, "");
    out.push("Kampagne");
    out.push(`  Ziel-URL: ${data.target_url}`);
    if (data.utm) out.push(`  UTM: ${data.utm}`);
    if (data.alt_text) out.push(`  ALT-Text: ${data.alt_text}`);
    out.push("", "Kunde");
    out.push(`  ${data.company}`, `  ${data.name}`, `  ${data.email}`);
    if (data.phone) out.push(`  ${data.phone}`);
    out.push(`  ${data.address.replace(/\n/g, ", ")}`);
    if (data.vat_id) out.push(`  USt-IdNr.: ${data.vat_id}`);
    if (data.po) out.push(`  Referenz: ${data.po}`);
    if (data.message) out.push("", "Nachricht", data.message);
    return out.join("\n");
  }

  async function submit(e) {
    e.preventDefault();
    const err = $("#form-error");
    err.textContent = "";
    const items = selection();
    if (!items.length) {
      err.textContent = "Bitte wählen Sie mindestens eine Werbeform.";
      $("#booking fieldset").scrollIntoView({ behavior: "smooth" });
      return;
    }
    if (!form.checkValidity()) {
      const first = form.querySelector(":invalid");
      err.textContent = "Bitte füllen Sie alle Pflichtfelder korrekt aus.";
      first.focus();
      return;
    }

    const data = Object.fromEntries(new FormData(form).entries());
    const text = buildText(items, data);
    const subject = `Buchungsanfrage Werbung: ${data.company}`;
    let note = "";

    if (cfg.formEndpoint) {
      try {
        const res = await fetch(cfg.formEndpoint, {
          method: "POST",
          headers: { "Content-Type": "application/json", Accept: "application/json" },
          body: JSON.stringify({ subject, _replyto: data.email, summary: text, items, customer: data })
        });
        if (!res.ok) throw new Error(res.status);
      } catch (_) {
        err.textContent = "Senden fehlgeschlagen. Bitte versuchen Sie es erneut oder schreiben Sie uns direkt.";
        return;
      }
    } else {
      window.location.href =
        `mailto:${cfg.bookingEmail}?subject=${encodeURIComponent(subject)}&body=${encodeURIComponent(text)}`;
      note = `Ihr E-Mail-Programm öffnet sich mit der vorausgefüllten Anfrage. Falls nicht, senden Sie die Angaben bitte an ${cfg.bookingEmail}.`;
    }

    form.hidden = true;
    $("#success-note").textContent = note;
    $("#success").hidden = false;
    $("#success").scrollIntoView({ behavior: "smooth", block: "center" });
  }

  function setDefaultDates() {
    form.elements.newsletter_start.value = isoToday(7);
    form.elements.sidebar_start.value = nextMonth();
  }

  function init() {
    for (const [key, p] of Object.entries(cfg.products)) {
      const sel = form.elements[key + "_format"];
      p.formats.forEach((f) => sel.add(new Option(f, f)));
      const box = $(`.presets[data-for="${key}_qty"]`);
      p.presets.forEach((n) => {
        const b = document.createElement("button");
        b.type = "button";
        b.dataset.value = n;
        b.textContent = `${n} × ${eur.format(unitPrice(p, n)).replace(",00", "")}`;
        b.addEventListener("click", () => {
          form.elements[key + "_qty"].value = n;
          render();
        });
        box.appendChild(b);
      });
    }

    form.elements.newsletter_start.min = isoToday(2);
    form.elements.sidebar_start.min = isoToday().slice(0, 7);
    setDefaultDates();

    form.querySelectorAll(".stepper").forEach((st) => {
      const input = $("input", st);
      st.querySelectorAll("button").forEach((b) =>
        b.addEventListener("click", () => {
          input.value = (parseInt(input.value, 10) || 1) + Number(b.dataset.step);
          render();
        })
      );
    });

    document.querySelectorAll("[data-pick]").forEach((b) =>
      b.addEventListener("click", () => {
        form.elements[b.dataset.pick + "_on"].checked = true;
        render();
        $("#buchen").scrollIntoView({ behavior: "smooth" });
      })
    );

    // Vorauswahl per URL, z. B. index.html?produkt=newsletter&anzahl=6#buchen
    const params = new URLSearchParams(location.search);
    const preset = params.get("produkt");
    if (preset && cfg.products[preset]) {
      form.elements[preset + "_on"].checked = true;
      const n = parseInt(params.get("anzahl"), 10);
      if (n) form.elements[preset + "_qty"].value = n;
    }

    form.addEventListener("input", render);
    form.addEventListener("change", render);
    form.addEventListener("submit", submit);
    $("#new-booking").addEventListener("click", () => {
      form.reset();
      setDefaultDates();
      form.hidden = false;
      $("#success").hidden = true;
      render();
    });

    const mail = $("#mail-link");
    mail.href = "mailto:" + cfg.bookingEmail;

    render();
  }

  init();
})();
