<?php
// buero/beantragung_ag.php  –  Beantragung beim Arbeitgeber
require_once __DIR__ . '/../_backend/bootstrap.php';
require_once __DIR__ . '/../_backend/panel.php';
send_security_headers();
$auth = require_login('buero');

panel_head('Beantragung beim Arbeitgeber');
panel_sidebar('beantragung_ag', $auth['rolle'], $auth['user']);
panel_topbar('Beantragung beim Arbeitgeber');
?>

<!-- Auswahlkarte für den Fall, dass man von einem einzelnen Antrag/Ereignis
     kommt (Link "Bei Arbeitgeber beantragen" auf buero/antrag.php) - zeigt
     ausschließlich die Mitglieder dieses einen Ereignisses mit Checkboxen,
     statt aller Anträge im Airline/Zeitraum-Filter unten. -->
<div class="card" id="ereignis-auswahl-card" style="margin-bottom:18px;display:none">
  <div class="card-head">
    <h2>Mitglieder für dieses Ereignis</h2>
    <span style="font-size:12px;color:var(--muted);font-weight:400" id="ereignis-auswahl-sub"></span>
  </div>
  <div class="card-body">
    <div style="display:flex;gap:10px;margin-bottom:12px">
      <button class="btn btn-outline btn-sm" onclick="ereignisAuswahlAlle(true)">Alle</button>
      <button class="btn btn-outline btn-sm" onclick="ereignisAuswahlAlle(false)">Keine</button>
    </div>
    <div id="ereignis-auswahl-liste" style="margin-bottom:16px">Wird geladen …</div>
    <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;padding-top:8px;border-top:1px solid var(--border,#dde2ea)">
      <strong id="ereignis-auswahl-airline" style="min-width:140px"></strong>
      <button class="btn btn-primary btn-sm" onclick="ereignisAuswahlBeantragen('standard')">✉ Beantragung</button>
      <button class="btn btn-outline btn-sm" onclick="ereignisAuswahlBeantragen('nachstichtag')">✉ Nach Stichtag</button>
      <button class="btn btn-outline btn-sm" onclick="ereignisAuswahlBeantragen('kurzfristig')">✉ Kurzfristig</button>
      <span style="display:inline-flex;align-items:center;gap:10px;padding:4px 10px;background:#f8f9fb;border:1px solid var(--border,#dde2ea);border-radius:6px;font-size:12px">
        <label style="display:flex;align-items:center;gap:4px;cursor:pointer;white-space:nowrap">
          <input type="checkbox" id="cb-excel-ea" checked style="width:13px;height:13px"> 📊 Excel
        </label>
        <label id="cb-doc-ea-wrap" style="display:none;align-items:center;gap:4px;cursor:pointer;white-space:nowrap">
          <input type="checkbox" id="cb-doc-ea" checked style="width:13px;height:13px"> 📄 DOC
        </label>
        <label style="display:flex;align-items:center;gap:4px;cursor:pointer;white-space:nowrap">
          <input type="checkbox" id="cb-en-ea" style="width:13px;height:13px"> 🇬🇧 EN
        </label>
      </span>
    </div>
    <div style="font-size:12px;color:var(--muted);margin-top:10px">
      Berücksichtigt nur Anträge mit Status „Intern genehmigt" - andere werden hier nicht angezeigt.
      Nach Klick öffnet sich die gewohnte Vorschau unten zur Prüfung vor dem Versand.
    </div>
  </div>
</div>

<!-- Filter -->
<div class="card" style="margin-bottom:18px">
  <div class="card-body" style="padding:16px 22px">
    <div style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end">
      <div class="fg" style="flex:0 0 170px">
        <label>Status</label>
        <select id="f-status">
          <option value="">Alle</option>
          <option value="ausstehend">Ausstehend</option>
          <option value="freigabe_buero" selected>Freigabe Büro</option>
          <option value="beantragt_ag">Beantragt bei AG</option>
          <option value="genehmigt">Genehmigt</option>
          <option value="abgelehnt">Abgelehnt</option>
          <option value="abgelehnt_ag">Abgelehnt (AG)</option>
          <option value="storno_buero">Storno Büro</option>
          <option value="storno_bestaetigt_ag">Storno bestätigt (AG)</option>
        </select>
      </div>
      <div class="fg" style="flex:0 0 170px">
        <label>Airline</label>
        <select id="f-airline">
          <option value="">Alle</option>
        </select>
      </div>
      <div class="fg" style="flex:0 0 170px">
        <label>Freistellungszeitraum von</label>
        <input type="date" id="f-von">
      </div>
      <div class="fg" style="flex:0 0 170px">
        <label>Freistellungszeitraum bis</label>
        <input type="date" id="f-bis">
      </div>
      <button class="btn btn-primary" onclick="loadList()">Filtern</button>
      <button class="btn btn-outline" onclick="resetFilter()">Zurücksetzen</button>
    </div>
    <p style="font-size:12px;color:var(--muted,#7a8fa6);margin:12px 0 0">
      Der oben gewählte Zeitraum wird auch für die Antragsmails unten verwendet.
      Für die Mails werden dabei <strong>immer nur Anträge mit Status „Intern genehmigt"</strong> berücksichtigt,
      unabhängig vom Status-Filter der Liste.
    </p>
  </div>
</div>

<!-- Airline-Buttons -->
<div class="card" style="margin-bottom:18px">
  <div class="card-head"><h2>Antragsmails je Airline erstellen</h2></div>
  <div class="card-body" style="padding:16px 22px">
    <div id="airline-buttons" style="display:flex;flex-direction:column;gap:10px">
      <span style="color:var(--muted)">Wird geladen …</span>
    </div>
  </div>
</div>

<!-- Mail-Vorschau (nur sichtbar nach Klick auf Airline-Button) -->
<div class="card" id="vorschau-card" style="display:none;margin-bottom:18px;border:2px solid var(--navy2,#1a3a5c)">
  <div class="card-head"><h2 id="vorschau-titel">Mail-Vorschau</h2></div>
  <div class="card-body">
    <div style="font-size:13px;line-height:1.9;background:#f8f9fb;border:1px solid var(--border,#dde2ea);border-radius:8px;padding:14px 18px;margin-bottom:16px">
      <div><strong>Von:</strong> <span id="pv-from"></span></div>
      <div><strong>An:</strong> <span id="pv-to"></span> <span id="pv-kein-kontakt" style="display:none;color:#c0392b;font-weight:700">⚠ Kein aktiver Flugbetrieb-Kontakt für diese Airline hinterlegt!</span></div>
      <div id="pv-cc-row" style="display:none"><strong>CC:</strong> <span id="pv-cc"></span></div>
      <div><strong>Betreff:</strong>
        <input type="text" id="pv-subject" style="width:100%;box-sizing:border-box;padding:5px 8px;border:1px solid var(--border,#dde2ea);border-radius:6px;font-size:14px;font-weight:600;margin-top:4px">
      </div>
    </div>
    <textarea id="pv-body" rows="10" style="width:100%;box-sizing:border-box;background:#fff;border:1px solid var(--border,#dde2ea);border-radius:8px;padding:14px 18px;margin-bottom:16px;font-family:monospace;font-size:13px;line-height:1.6;resize:vertical"></textarea>
    <div style="font-size:12px;color:var(--muted,#7a8fa6);margin:-12px 0 16px">Betreff und Text können vor dem Versand angepasst werden.</div>
    <div id="pv-anhang" style="display:none;font-size:13px;color:var(--muted,#7a8fa6);margin-bottom:6px">📎 <span id="pv-anhang-text"></span></div>
    <div id="pv-docs" style="display:none;font-size:13px;color:var(--muted,#7a8fa6);margin-bottom:12px">📄 <span id="pv-docs-text"></span></div>
    <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">
      <button class="btn btn-primary" id="btn-senden" onclick="sendenBestaetigen()">✉ Jetzt senden &amp; Status auf „Beantragt bei AG" setzen</button>
      <button class="btn btn-outline" onclick="vorschauSchliessen()">Abbrechen</button>
      <span id="pv-anzahl" style="color:var(--muted);font-size:13px"></span>
    </div>
  </div>
</div>

<div class="card">
  <div class="card-head">
    <h2>Anträge (<span id="count">0</span>)</h2>
  </div>
  <div class="card-body-raw">
    <div class="table-scroll">
      <table>
        <thead><tr>
          <th>#</th><th>Name</th><th>TK</th><th>Airline</th><th>Pos.</th>
          <th>Veranstaltung</th><th>Zeitraum</th><th>Status</th><th></th>
        </tr></thead>
        <tbody id="tbody"><tr><td colspan="9" style="text-align:center;padding:24px;color:var(--muted)">Wird geladen …</td></tr></tbody>
      </table>
    </div>
  </div>
</div>

<!-- Mailvorlagen bearbeiten (drei Varianten) -->
<div class="card" style="margin-top:18px">
  <div class="card-head">
    <h2>Mailvorlagen bearbeiten</h2>
  </div>
  <div class="card-body">
    <p style="font-size:13px;color:var(--muted,#7a8fa6);margin-bottom:14px">
      Platzhalter: <code>{{AIRLINE}}</code> <code>{{VON}}</code> <code>{{BIS}}</code>
      <code>{{LISTE}}</code> <code>{{BEARBEITER}}</code> <code>{{ANZAHL}}</code> –
      werden beim Erstellen der Mail automatisch ersetzt. <code>{{LISTE}}</code> wird
      durch die Liste der betroffenen Mitglieder (Name, Zeitraum, Veranstaltung) ersetzt.
    </p>

    <div class="mode-tabs" id="vorlage-tabs" style="display:flex;gap:0;margin-bottom:18px;border:1px solid var(--border,#dde2ea);border-radius:8px;overflow:hidden;width:fit-content">
      <button type="button" class="vorlage-tab-btn active" data-typ="standard"
        style="padding:9px 20px;border:none;font-size:13px;font-weight:700;cursor:pointer;background:var(--navy2,#1a3a5c);color:#fff" onclick="vorlageTabWechseln('standard')">
        Beantragung
      </button>
      <button type="button" class="vorlage-tab-btn" data-typ="nachstichtag"
        style="padding:9px 20px;border:none;border-left:1px solid var(--border,#dde2ea);font-size:13px;font-weight:700;cursor:pointer;background:#f0f2f5;color:var(--muted,#7a8fa6)" onclick="vorlageTabWechseln('nachstichtag')">
        Nach Stichtag
      </button>
      <button type="button" class="vorlage-tab-btn" data-typ="kurzfristig"
        style="padding:9px 20px;border:none;border-left:1px solid var(--border,#dde2ea);font-size:13px;font-weight:700;cursor:pointer;background:#f0f2f5;color:var(--muted,#7a8fa6)" onclick="vorlageTabWechseln('kurzfristig')">
        Kurzfristig
      </button>
    </div>

    <div id="vorlage-alert" class="alert" style="display:none;margin-bottom:14px"></div>
    <div class="fg" style="margin-bottom:14px">
      <label>Betreff</label>
      <input type="text" id="vorlage-subject" style="width:100%;padding:8px 10px;border:1px solid var(--border,#dde2ea);border-radius:6px;font-size:14px;box-sizing:border-box">
    </div>
    <div class="fg" style="margin-bottom:14px">
      <label>Text</label>
      <textarea id="vorlage-body" rows="9" style="width:100%;padding:10px 12px;border:1px solid var(--border,#dde2ea);border-radius:6px;font-size:13px;font-family:monospace;box-sizing:border-box"></textarea>
    </div>
    <button class="btn btn-primary" id="btn-vorlage-speichern" onclick="vorlageSpeichern()">Vorlage speichern</button>
    <span style="font-size:12px;color:var(--muted);margin-left:10px">Gilt nur für die aktuell ausgewählte Vorlage.</span>
  </div>
</div>

<script src="../assets/status.js"></script>
<script>
const CSRF = '<?= csrf_token() ?>';

// badge() nutzt jetzt die gemeinsame, einheitliche Liste aus
// assets/status.js (statusBadge()) statt einer eigenen, hier zuvor
// abweichenden Bezeichnung (z.B. "Intern genehmigt"/"Abgelehnt AG").
function badge(status) { return statusBadge(status); }

function formatDatum(iso) {
  if (!iso || iso.startsWith('0000')) return '–';
  return iso.substring(0,10).split('-').reverse().join('.');
}
function esc(s) { return String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

async function api(action, extra = {}) {
  const fd = new FormData();
  fd.append('action', action);
  fd.append('csrf', CSRF);
  for (const [k, v] of Object.entries(extra)) {
    if (Array.isArray(v)) v.forEach(x => fd.append(k + '[]', x));
    else fd.append(k, v);
  }
  // Robust gegen Server-Fehler/kaputtes JSON: ein Request darf NIE eine
  // Exception werfen, sonst bleibt der aufrufende Button (z.B. "Wird
  // gesendet …") für den Benutzer sichtbar hängen, weil der Code danach
  // (btn.disabled = false; …) nicht mehr ausgeführt wird.
  try {
    const r = await fetch('../api/buero.php', { method: 'POST', body: fd });
    return await r.json();
  } catch (err) {
    return { ok: false, error: 'Serverfehler oder keine Verbindung (' + err.message + ')' };
  }
}

// ── Liste ────────────────────────────────────────────────────
async function loadList() {
  const tbody = document.getElementById('tbody');
  tbody.innerHTML = '<tr><td colspan="9" style="text-align:center;padding:24px;color:var(--muted)">Wird geladen …</td></tr>';

  const d = await api('list_antraege', {
    status:           document.getElementById('f-status').value,
    airline:          document.getElementById('f-airline').value,
    freistellung_von: document.getElementById('f-von').value,
    freistellung_bis: document.getElementById('f-bis').value,
  });
  document.getElementById('count').textContent = d.data ? d.data.length : 0;

  if (!d.ok || !d.data.length) {
    tbody.innerHTML = '<tr><td colspan="9" style="text-align:center;padding:32px;color:var(--muted)">Keine Anträge gefunden.</td></tr>';
    return;
  }
  tbody.innerHTML = d.data.map(a => `
    <tr>
      <td class="mono">${a.id}</td>
      <td>${esc(a.vorname)} ${esc(a.nachname)}</td>
      <td class="mono" style="font-size:12px">${esc(a.tk_kuerzel)}</td>
      <td>${esc(a.airline)}</td>
      <td><span style="display:inline-block;padding:3px 9px;border-radius:20px;font-size:12px;font-weight:700;background:#eef2ff;color:#3730a3">${esc(a.position)}</span></td>
      <td style="font-size:13px">${esc(a.veranstaltung) || '–'}</td>
      <td class="td-nowrap mono" style="font-size:12px">${formatDatum(a.zeitraum_von)}${a.zeitraum_von !== a.zeitraum_bis ? ' –<br>' + formatDatum(a.zeitraum_bis) : ''}</td>
      <td>${badge(a.status)}</td>
      <td><a href="antrag.php?id=${a.id}" class="btn btn-outline btn-sm">Öffnen</a></td>
    </tr>`).join('');
}

function resetFilter() {
  document.getElementById('f-status').value = 'freigabe_buero';
  document.getElementById('f-airline').value = '';
  document.getElementById('f-von').value = '';
  document.getElementById('f-bis').value = '';
  loadList();
}

// ── Mailvorlagen (drei Typen) ────────────────────────────────
let vorlagenAlle = {};
let vorlagenTypen = {};
let aktuellerVorlageTyp = 'standard';

async function ladeVorlage() {
  const d = await api('get_ag_mail_vorlagen');
  if (!d.ok) return;
  vorlagenAlle  = d.data.vorlagen;
  vorlagenTypen = d.data.typen;
  vorlageAnzeigen(aktuellerVorlageTyp);
}

function vorlageAnzeigen(typ) {
  const v = vorlagenAlle[typ];
  if (!v) return;
  document.getElementById('vorlage-subject').value = v.subject || '';
  document.getElementById('vorlage-body').value    = v.body    || '';
}

function vorlageTabWechseln(typ) {
  aktuellerVorlageTyp = typ;
  document.querySelectorAll('.vorlage-tab-btn').forEach(btn => {
    const aktiv = btn.dataset.typ === typ;
    btn.style.background = aktiv ? 'var(--navy2,#1a3a5c)' : '#f0f2f5';
    btn.style.color      = aktiv ? '#fff' : 'var(--muted,#7a8fa6)';
  });
  document.getElementById('vorlage-alert').style.display = 'none';
  vorlageAnzeigen(typ);
}

async function vorlageSpeichern() {
  const subject = document.getElementById('vorlage-subject').value.trim();
  const body    = document.getElementById('vorlage-body').value.trim();
  const alertBox = document.getElementById('vorlage-alert');
  const btn = document.getElementById('btn-vorlage-speichern');

  if (!subject || !body) {
    alertBox.className = 'alert alert-error';
    alertBox.textContent = '⚠ Betreff und Text dürfen nicht leer sein.';
    alertBox.style.display = 'block';
    return;
  }

  btn.disabled = true; btn.textContent = '…';
  const d = await api('save_ag_mail_vorlage', { typ: aktuellerVorlageTyp, subject, body });
  btn.disabled = false; btn.textContent = 'Vorlage speichern';

  alertBox.className = d.ok ? 'alert alert-success' : 'alert alert-error';
  alertBox.textContent = d.ok ? '✓ Vorlage gespeichert.' : '⚠ ' + (d.error || 'Fehler beim Speichern.');
  alertBox.style.display = 'block';
  if (d.ok) {
    vorlagenAlle[aktuellerVorlageTyp] = { subject, body };
    setTimeout(() => { alertBox.style.display = 'none'; }, 3000);
  }
}

// ── Airline-Buttons ──────────────────────────────────────────
async function ladeAirlines() {
  const d = await api('list_finance_airlines');
  const wrap = document.getElementById('airline-buttons');
  if (!d.ok || !d.data.airlines || !d.data.airlines.length) {
    wrap.innerHTML = '<span style="color:var(--muted)">Keine Airlines im System.</span>';
    return;
  }

  const filterSel = document.getElementById('f-airline');
  filterSel.innerHTML = '<option value="">Alle</option>' +
    d.data.airlines.map(a => `<option value="${esc(a)}">${esc(a)}</option>`).join('');

  const docSettings = await api('list_airline_doc_einstellungen');
  const docMap = (docSettings.ok && docSettings.data.einstellungen) ? docSettings.data.einstellungen : {};

  const typLabels = { standard: 'Beantragung', nachstichtag: 'Nach Stichtag', kurzfristig: 'Kurzfristig' };
  wrap.innerHTML = d.data.airlines.map(al => {
    const alEsc = esc(al).replace(/'/g, "\\'");
    const alId  = al.replace(/[^a-zA-Z0-9]/g, '_');
    const docAktiv = !!docMap[al];
    return `
    <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;padding:10px 0;border-bottom:1px solid var(--border,#dde2ea)">
      <strong style="min-width:140px">${esc(al)}</strong>
      ${Object.keys(typLabels).map(typ => `
        <button class="btn btn-outline btn-sm" onclick="antragsmailVorschau('${alEsc}', '${typ}', [],
            document.getElementById('cb-en-${alId}').checked,
            document.getElementById('cb-excel-${alId}').checked,
            document.getElementById('cb-doc-${alId}') && document.getElementById('cb-doc-${alId}').checked)">
          ✉ ${typLabels[typ]}
        </button>
      `).join('')}
      <span style="display:inline-flex;align-items:center;gap:10px;padding:4px 10px;background:#f8f9fb;border:1px solid var(--border,#dde2ea);border-radius:6px;font-size:12px">
        <label style="display:flex;align-items:center;gap:4px;cursor:pointer;white-space:nowrap" title="Excel-Freistellungsliste anhängen">
          <input type="checkbox" id="cb-excel-${alId}" checked style="width:13px;height:13px"> 📊 Excel
        </label>
        ${docAktiv ? `
        <label style="display:flex;align-items:center;gap:4px;cursor:pointer;white-space:nowrap" title="Word-Formular anhängen">
          <input type="checkbox" id="cb-doc-${alId}" checked style="width:13px;height:13px"> 📄 DOC
        </label>` : `<span id="cb-doc-${alId}" style="display:none"></span>`}
        <label style="display:flex;align-items:center;gap:4px;cursor:pointer;white-space:nowrap" title="E-Mail und Excel auf Englisch">
          <input type="checkbox" id="cb-en-${alId}" style="width:13px;height:13px"> 🇬🇧 EN
        </label>
      </span>
      <label style="display:flex;align-items:center;gap:5px;font-size:11px;color:var(--muted,#7a8fa6);margin-left:auto;cursor:pointer" title="DOC-Anhang für diese Airline dauerhaft aktivieren/deaktivieren">
        <input type="checkbox" ${docAktiv ? 'checked' : ''} onchange="docEinstellungSpeichern('${alEsc}', this)">
        DOC&nbsp;aktiv
      </label>
    </div>`;
  }).join('');
}

// ── DOC-Einstellung pro Airline speichern ────────────────────
async function docEinstellungSpeichern(airline, checkbox) {
  checkbox.disabled = true;
  const d = await api('save_airline_doc_einstellung', { airline, aktiv: checkbox.checked ? 1 : 0 });
  checkbox.disabled = false;
  if (!d.ok) {
    checkbox.checked = !checkbox.checked; // zurücksetzen bei Fehler
    alert('Fehler beim Speichern: ' + d.error);
  }
}

// ── Mail-Vorschau ────────────────────────────────────────────
let aktuelleAirline = null;
let aktuellerMailTyp = null;

async function antragsmailVorschau(airline, typ, nurIds, englisch, excelSenden, docSenden) {
  const von = document.getElementById('f-von').value;
  const bis = document.getElementById('f-bis').value;
  if (!von || !bis) {
    alert('Bitte zuerst „Freistellungszeitraum von/bis" oben auswählen.');
    return;
  }

  // Flags für den späteren Versand merken
  window._agExcelSenden = excelSenden !== false;
  window._agDocSenden   = docSenden   === true;
  window._agSprache     = englisch ? 'en' : 'de';

  const extra = { airline, zeitraum_von: von, zeitraum_bis: bis, typ,
                  sprache: window._agSprache,
                  excel_senden: window._agExcelSenden ? 1 : 0,
                  doc_senden:   window._agDocSenden   ? 1 : 0 };
  if (Array.isArray(nurIds) && nurIds.length) extra.antrag_ids = nurIds;
  const d = await api('get_ag_antragsmail_vorschau', extra);
  if (!d.ok) { alert('Fehler: ' + d.error); return; }

  aktuelleAirline  = airline;
  aktuellerMailTyp = typ;
  const v = d.data;

  document.getElementById('vorschau-titel').textContent = `Mail-Vorschau – ${airline} (${v.typ_label})`;
  document.getElementById('pv-from').textContent = v.from_name + (v.from_email ? ' <' + v.from_email + '>' : '');
  document.getElementById('pv-subject').value = v.subject;
  document.getElementById('pv-body').value = v.body;
  document.getElementById('pv-anzahl').textContent = `${v.anzahl} Antrag/Anträge werden auf „Beantragt bei AG" gesetzt.`;

  const anhangBox = document.getElementById('pv-anhang');
  window._agAnhangToken = v.anhang_token || null;
  if (v.hat_anhang && v.anhang_token) {
    document.getElementById('pv-anhang-text').innerHTML =
      `Freistellungsliste wird als Excel angehängt – <a href="../api/ag_antrag_anhang_download.php?token=${encodeURIComponent(v.anhang_token)}" target="_blank">jetzt prüfen/herunterladen</a> ` +
      anhangErsetzenControl(v.anhang_token, 'excel-ersetzen');
    anhangBox.style.display = 'block';
  } else if (v.hat_anhang) {
    document.getElementById('pv-anhang-text').textContent =
      'Die Freistellungsliste wird als Excel-Datei angehängt (Vorschau der Datei aktuell nicht verfügbar).';
    anhangBox.style.display = 'block';
  } else {
    anhangBox.style.display = 'none';
  }

  const docsBox = document.getElementById('pv-docs');
  window._agDocTokens = (v.doc_anhaenge || []).map(d => d.token);
  if (v.doc_aktiv && v.doc_anhaenge && v.doc_anhaenge.length) {
    document.getElementById('pv-docs-text').innerHTML =
      'Word-Formular(e) werden angehängt – ' +
      v.doc_anhaenge.map((d, i) =>
        `<a href="../api/ag_antrag_anhang_download.php?token=${encodeURIComponent(d.token)}" target="_blank">${esc(d.filename)}</a> ` +
        anhangErsetzenControl(d.token, 'doc-ersetzen-' + i)
      ).join(', ');
    docsBox.style.display = 'block';
  } else if (v.doc_aktiv) {
    document.getElementById('pv-docs-text').textContent =
      'Word-Formular(e) werden angehängt (Vorschau aktuell nicht verfügbar).';
    docsBox.style.display = 'block';
  } else {
    docsBox.style.display = 'none';
  }

  const noKontakt = document.getElementById('pv-kein-kontakt');
  const btnSenden = document.getElementById('btn-senden');
  if (v.to_email) {
    const liste = Array.isArray(v.to_kontakte) && v.to_kontakte.length
      ? v.to_kontakte.map(k => k.bezeichnung ? `${k.bezeichnung} <${k.email}>` : k.email).join(', ')
      : (v.to_bezeichnung ? v.to_bezeichnung + ' <' + v.to_email + '>' : v.to_email);
    document.getElementById('pv-to').textContent = liste;
    noKontakt.style.display = 'none';
    btnSenden.disabled = false;
  } else {
    document.getElementById('pv-to').textContent = '';
    noKontakt.style.display = 'inline';
    btnSenden.disabled = true;
  }

  const ccRow = document.getElementById('pv-cc-row');
  if (v.cc_email) { document.getElementById('pv-cc').textContent = v.cc_email; ccRow.style.display = 'block'; }
  else { ccRow.style.display = 'none'; }

  window._agAntragIds = v.antrag_ids;
  window._agVon = von;
  window._agBis = bis;

  document.getElementById('vorschau-card').style.display = 'block';
  document.getElementById('vorschau-card').scrollIntoView({ behavior: 'smooth', block: 'start' });
}

function vorschauSchliessen() {
  document.getElementById('vorschau-card').style.display = 'none';
  aktuelleAirline = null;
  aktuellerMailTyp = null;
  window._agAntragIds = null;
  window._agAnhangToken = null;
  window._agDocTokens = null;
}

// ── Anhang (Excel/DOC) vor dem Versand durch bearbeitete Version ersetzen ──
function anhangErsetzenControl(token, inputId) {
  return `<label style="font-size:12px;cursor:pointer;color:var(--navy2,#1a3a5c);text-decoration:underline">
    ✏️ ersetzen
    <input type="file" id="${inputId}" style="display:none" onchange="anhangErsetzen('${token}', this)">
  </label> <span id="${inputId}-status" style="font-size:12px"></span>`;
}

async function anhangErsetzen(token, input) {
  if (!input.files || !input.files.length) return;
  const statusEl = document.getElementById(input.id + '-status');
  statusEl.textContent = ' Wird hochgeladen …';

  const fd = new FormData();
  fd.append('action', 'ersetze_ag_anhang');
  fd.append('csrf', CSRF);
  fd.append('token', token);
  fd.append('datei', input.files[0]);

  let d;
  try {
    const r = await fetch('../api/buero.php', { method: 'POST', body: fd });
    d = await r.json();
  } catch (err) {
    d = { ok: false, error: 'Serverfehler oder keine Verbindung (' + err.message + ')' };
  }

  statusEl.textContent = d.ok ? ' ✓ ersetzt' : ' ⚠ ' + (d.error || 'Fehler');
  statusEl.style.color = d.ok ? '#1e7e34' : '#c0392b';
}

async function sendenBestaetigen() {
  if (!aktuelleAirline || !window._agAntragIds || !window._agAntragIds.length) return;
  if (!confirm(`Antragsmail für „${aktuelleAirline}" jetzt wirklich versenden?\n\nDanach werden ${window._agAntragIds.length} Antrag/Anträge auf „Beantragt bei AG" gesetzt.`)) return;

  const btn = document.getElementById('btn-senden');
  btn.disabled = true;
  btn.textContent = 'Wird gesendet …';

  let d;
  try {
    d = await api('sende_ag_antragsmail', {
      airline: aktuelleAirline,
      antrag_ids: window._agAntragIds,
      zeitraum_von: window._agVon,
      zeitraum_bis: window._agBis,
      typ: aktuellerMailTyp,
      sprache:      window._agSprache     || 'de',
      excel_senden: window._agExcelSenden ? 1 : 0,
      doc_senden:   window._agDocSenden   ? 1 : 0,
      anhang_token: window._agAnhangToken || '',
      doc_tokens: window._agDocTokens || [],
      subject: document.getElementById('pv-subject')?.value ?? '',
      body: document.getElementById('pv-body')?.value ?? '',
    });
  } finally {
    // Button IMMER wieder freigeben, egal was oben passiert ist –
    // verhindert den "hängt für immer"-Zustand bei Fehlern.
    btn.disabled = false;
    btn.textContent = '✉ Jetzt senden & Status auf „Beantragt bei AG" setzen';
  }

  if (!d.ok) { alert('Fehler: ' + d.error); return; }

  const teile = [];
  if (d.data.anzahl_neu)        teile.push(`${d.data.anzahl_neu} Antrag/Anträge neu auf „Beantragt bei AG" gesetzt`);
  if (d.data.anzahl_erinnerung) teile.push(`${d.data.anzahl_erinnerung} als Erinnerung erneut gesendet (Status unverändert)`);
  const zusatz = teile.length ? teile.join(', ') + '.' : `${d.data.anzahl} Antrag/Anträge verarbeitet.`;
  alert(`✓ Mail versendet an ${d.data.to_email}. ${zusatz}`);
  vorschauSchliessen();
  loadList();
}

loadList();
ladeAirlines().then(() => {
  // Vorbefüllung per URL-Parameter (Link "Bei Arbeitgeber beantragen" von
  // der Einzelantrags-Seite, buero/antrag.php). Bei vorhandener ereignis_id
  // wird die neue Checkbox-Auswahlkarte gezeigt (nur Mitglieder dieses
  // einen Ereignisses); ohne ereignis_id (älterer Link/Lesezeichen) fällt
  // es auf die bisherige, breitere Filterung zurück.
  const p = new URLSearchParams(window.location.search);
  const airline    = p.get('airline');
  const von        = p.get('von');
  const bis        = p.get('bis');
  const ereignisId = p.get('ereignis_id');
  if (!airline && !von && !bis) return;

  if (airline) document.getElementById('f-airline').value = airline;
  if (von)     document.getElementById('f-von').value     = von;
  if (bis)     document.getElementById('f-bis').value     = bis;

  if (ereignisId) {
    ladeEreignisAuswahl(ereignisId, airline, von, bis);
  } else {
    loadList();
  }
});

// ── Checkbox-Auswahl für ein einzelnes Ereignis ────────────────
let eaAirline = null, eaVon = null, eaBis = null;

async function ladeEreignisAuswahl(ereignisId, airline, von, bis) {
  eaAirline = airline; eaVon = von; eaBis = bis;
  const card = document.getElementById('ereignis-auswahl-card');
  const liste = document.getElementById('ereignis-auswahl-liste');
  card.style.display = 'block';
  document.getElementById('ereignis-auswahl-airline').textContent = airline || '';
  document.getElementById('ereignis-auswahl-sub').textContent =
    (von && bis) ? `${formatDatum(von)} – ${formatDatum(bis)}` : '';

  // DOC-Checkbox nur zeigen wenn für diese Airline aktiv
  const docSettings = await api('list_airline_doc_einstellungen');
  const docMap = (docSettings.ok && docSettings.data.einstellungen) ? docSettings.data.einstellungen : {};
  const docWrap = document.getElementById('cb-doc-ea-wrap');
  if (docWrap) docWrap.style.display = docMap[airline] ? 'flex' : 'none';

  const d = await api('list_antraege', { ereignis_id: ereignisId, status: 'freigabe_buero,beantragt_ag' });
  if (!d.ok || !d.data.length) {
    liste.innerHTML = '<p style="color:var(--muted);font-size:13px">Keine Anträge mit Status „Genehmigt durch Büro" oder „Beantragt bei AG" für dieses Ereignis gefunden.</p>';
    return;
  }
  liste.innerHTML = d.data.map(a => {
    const istErinnerung = a.status === 'beantragt_ag';
    const badge = istErinnerung
      ? `<span style="font-size:11px;font-weight:700;padding:1px 8px;border-radius:20px;background:#e0ecff;color:#2851a3">Erinnerung</span>`
      : `<span style="font-size:11px;font-weight:700;padding:1px 8px;border-radius:20px;background:#dbeafe;color:#1d4ed8">Neu</span>`;
    return `
    <label style="display:flex;align-items:center;gap:8px;padding:6px 4px;border-bottom:1px solid #f0f2f5;cursor:pointer;font-size:13px">
      <input type="checkbox" class="ea-cb" value="${a.id}" checked style="width:15px;height:15px">
      <span style="min-width:180px">${esc(a.vorname)} ${esc(a.nachname)}</span>
      <span style="color:var(--muted);font-size:12px">${esc(a.tk_kuerzel)} · ${esc(a.position)}</span>
      ${badge}
    </label>`;
  }).join('');
}

function ereignisAuswahlAlle(checked) {
  document.querySelectorAll('.ea-cb').forEach(cb => cb.checked = checked);
}

async function ereignisAuswahlBeantragen(typ) {
  const ids = [...document.querySelectorAll('.ea-cb:checked')].map(cb => cb.value);
  if (!ids.length) { alert('Bitte mindestens ein Mitglied auswählen.'); return; }
  const englisch  = document.getElementById('cb-en-ea')?.checked    ?? false;
  const excelSend = document.getElementById('cb-excel-ea')?.checked ?? true;
  const docSend   = document.getElementById('cb-doc-ea')?.checked   ?? false;
  await antragsmailVorschau(eaAirline, typ, ids, englisch, excelSend, docSend);
}
ladeVorlage();

// "Bis"-Feld: kein Datum vor "Von" erlauben
(function () {
  const von = document.getElementById('f-von');
  const bis = document.getElementById('f-bis');
  von.addEventListener('change', function () { bis.min = this.value || ''; if (bis.value && bis.value < this.value) bis.value = this.value; });
  bis.addEventListener('change', function () { von.max = this.value || ''; if (von.value && von.value > this.value) von.value = this.value; });
})();
</script>

<?php panel_foot(); ?>
