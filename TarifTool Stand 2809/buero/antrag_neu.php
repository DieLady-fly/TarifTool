<?php
// buero/antrag_neu.php  –  Antrag manuell anlegen (Einzelmitglied oder ganze TK)
require_once __DIR__ . '/../_backend/bootstrap.php';
require_once __DIR__ . '/../_backend/panel.php';
send_security_headers();
$auth = require_login('buero');

panel_head('Antrag anlegen');
panel_sidebar('antrag_neu', $auth['rolle'], $auth['user']);
panel_topbar('Antrag manuell anlegen');
?>

<div style="max-width:860px">

<div id="alert-global" style="display:none;margin-bottom:16px" class="alert"></div>

<!-- Modus-Umschalter -->
<div style="display:flex;gap:0;margin-bottom:22px;border:1px solid var(--border,#dde2ea);border-radius:8px;overflow:hidden;width:fit-content">
  <button id="mode-einzel" onclick="setModus('einzel')"
    style="padding:9px 24px;border:none;font-size:13px;font-weight:700;cursor:pointer;background:var(--navy2,#1a3a5c);color:#fff;transition:all .15s">
    👤 Einzelmitglied
  </button>
  <button id="mode-alle" onclick="setModus('alle')"
    style="padding:9px 24px;border:none;font-size:13px;font-weight:700;cursor:pointer;background:#f0f2f5;color:var(--muted,#7a8fa6);transition:all .15s">
    👥 Alle Mitglieder einer TK
  </button>
</div>

<div class="card">
  <div class="card-head"><h2 id="card-titel">Antrag für Einzelmitglied</h2></div>
  <div class="card-body">

    <!-- TK-Auswahl (immer sichtbar) -->
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:16px">
      <div class="fg" style="margin:0">
        <label>Tarifkommission <span style="color:#e63946">*</span></label>
        <select id="sel-tk" onchange="ladeMitglieder()">
          <option value="">– Bitte wählen –</option>
        </select>
      </div>
      <!-- Einzelmitglied: Mitglied-Dropdown -->
      <div class="fg" style="margin:0" id="wrap-mitglied">
        <label>Mitglied <span style="color:#e63946">*</span></label>
        <select id="sel-mitglied" onchange="ladeMitgliedDaten()" disabled>
          <option value="">– Erst TK wählen –</option>
        </select>
      </div>
    </div>

    <!-- Einzelmitglied: Stammdaten-Anzeige -->
    <div id="mitglied-info" style="display:none;background:#f8f9fb;border:1px solid var(--border,#dde2ea);border-radius:8px;padding:14px;margin-bottom:16px">
      <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--muted);margin-bottom:10px">Stammdaten</div>
      <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:10px;font-size:13px">
        <div><strong style="display:block;font-size:11px;color:var(--muted);text-transform:uppercase;letter-spacing:.04em;margin-bottom:2px">Airline</strong><span id="info-airline">–</span></div>
        <div><strong style="display:block;font-size:11px;color:var(--muted);text-transform:uppercase;letter-spacing:.04em;margin-bottom:2px">Position</strong><span id="info-position">–</span></div>
        <div><strong style="display:block;font-size:11px;color:var(--muted);text-transform:uppercase;letter-spacing:.04em;margin-bottom:2px">Flugzeugmuster</strong><span id="info-muster">–</span></div>
      </div>
    </div>

    <!-- Freistellungscode - nur sichtbar/Pflicht, wenn für die betroffene(n)
         Airline(s) welche konfiguriert sind. Bei gemischten Airlines im
         Alle-Modus bleibt es ausgeblendet (automatischer Lookup je Antrag
         greift dann wie gehabt). -->
    <div class="fg" id="wrap-fscode" style="display:none;margin-bottom:16px">
      <label>Freistellungscode <span style="color:#e63946">*</span></label>
      <select id="sel-fscode" onchange="updateEinreichenBtn()">
        <option value="">– bitte wählen –</option>
      </select>
    </div>

    <!-- Alle-Modus: Mitglieder-Checkliste -->
    <div id="wrap-alle" style="display:none;margin-bottom:16px">
      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px">
        <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--muted)">
          Mitglieder auswählen
        </div>
        <div style="display:flex;gap:8px">
          <button type="button" class="btn btn-outline btn-sm" onclick="alleAnwaehlen(true)">Alle auswählen</button>
          <button type="button" class="btn btn-outline btn-sm" onclick="alleAnwaehlen(false)">Alle abwählen</button>
        </div>
      </div>
      <div id="mitglieder-checks" style="display:flex;flex-direction:column;gap:0;border:1px solid var(--border,#dde2ea);border-radius:8px;overflow:hidden">
        <div style="text-align:center;padding:20px;color:var(--muted);font-size:13px">Erst TK wählen …</div>
      </div>
    </div>

    <!-- Termine -->
    <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--muted);margin-bottom:10px;padding-bottom:6px;border-bottom:1px solid var(--border,#dde2ea)">
      Termine
    </div>
    <div id="termine-list" style="display:flex;flex-direction:column;gap:8px;margin-bottom:12px"></div>
    <button type="button" class="btn btn-outline btn-sm" onclick="terminHinzufuegen()">+ Termin hinzufügen</button>

    <!-- Konflikt-Panel (nur sichtbar, wenn Überschneidungen gefunden wurden) -->
    <div id="konflikt-panel" style="display:none;margin-top:16px"></div>

    <!-- Footer -->
    <div style="margin-top:24px;padding-top:16px;border-top:1px solid var(--border,#dde2ea);display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px">
      <div id="submit-info" style="font-size:13px;color:var(--muted)"></div>
      <div style="display:flex;gap:10px">
        <a href="antraege.php" class="btn btn-outline">Abbrechen</a>
        <button class="btn btn-primary" id="btn-einreichen" onclick="einreichen()" disabled>
          Antrag einreichen
        </button>
      </div>
    </div>

  </div>
</div>
</div>

<script>
const CSRF = '<?= csrf_token() ?>';
let modus         = 'einzel'; // 'einzel' | 'alle'
let mitgliedDaten = null;     // für Einzel-Modus
let alleMitglieder= [];       // für Alle-Modus

const VERANSTALTUNGEN_BASIS = [
  { value: 'Verhandlung', label: 'Verhandlung' },
  { value: 'TK Sitzung',  label: 'TK-Sitzung'  },
  { value: 'sonstige',    label: 'Sonstiges'    },
];
let VERANSTALTUNGEN = [...VERANSTALTUNGEN_BASIS];

// Mapping unserer Veranstaltungsart-Werte auf die internen Keys der
// Umwidmungs-Funktion (api/buero.php: case 'umwidmen_tage')
const TYP_ZU_UW_BASIS = { 'Verhandlung': 'verhandlung', 'TK Sitzung': 'tk_sitzung', 'sonstige': 'sonstiges' };
let TYP_ZU_UW = { ...TYP_ZU_UW_BASIS };

// Lädt Zusatz-Typen für eine Airline und aktualisiert alle Termin-Dropdowns
async function ladeVeranstaltungenFuerAirline(airline) {
  VERANSTALTUNGEN = [...VERANSTALTUNGEN_BASIS];
  TYP_ZU_UW = { ...TYP_ZU_UW_BASIS };
  if (airline) {
    const d = await apiBuero('get_veranstaltungen_fuer_airline', { airline });
    if (d.ok && d.data.zusatz_typen?.length) {
      for (const z of d.data.zusatz_typen) {
        VERANSTALTUNGEN.push({ value: z.veranstaltung, label: z.label });
        TYP_ZU_UW[z.veranstaltung] = z.uw_key;
      }
    }
  }
  // Alle bereits gerenderten Termin-Dropdowns aktualisieren
  document.querySelectorAll('[id^="t"][id$="-typ"]').forEach(sel => {
    const current = sel.value;
    sel.innerHTML = '<option value="">– Wählen –</option>' +
      VERANSTALTUNGEN.map(v => `<option value="${v.value}"${v.value === current ? ' selected' : ''}>${v.label}</option>`).join('');
  });
}

function toIso(d) { return `${d.getFullYear()}-${String(d.getMonth()+1).padStart(2,'0')}-${String(d.getDate()).padStart(2,'0')}`; }
function alleTageZwischen(von, bis) {
  const out = []; let d = new Date(von + 'T00:00:00'); const end = new Date(bis + 'T00:00:00');
  while (d <= end) { out.push(toIso(d)); d.setDate(d.getDate() + 1); }
  return out;
}
function toRanges(tage) {
  if (!tage.length) return [];
  const ranges = []; let von = tage[0], prev = tage[0];
  for (let i = 1; i < tage.length; i++) {
    const exp = new Date(prev + 'T00:00:00'); exp.setDate(exp.getDate() + 1);
    if (tage[i] === toIso(exp)) { prev = tage[i]; }
    else { ranges.push({ von, bis: prev }); von = tage[i]; prev = tage[i]; }
  }
  ranges.push({ von, bis: prev });
  return ranges;
}
function fmtD(iso) { return iso.substring(0,10).split('-').reverse().join('.'); }

// ── Modus-Umschalter ─────────────────────────────────────────
function setModus(m) {
  modus = m;
  const einzel = m === 'einzel';
  document.getElementById('mode-einzel').style.background = einzel ? 'var(--navy2,#1a3a5c)' : '#f0f2f5';
  document.getElementById('mode-einzel').style.color      = einzel ? '#fff' : 'var(--muted,#7a8fa6)';
  document.getElementById('mode-alle').style.background   = einzel ? '#f0f2f5' : 'var(--navy2,#1a3a5c)';
  document.getElementById('mode-alle').style.color        = einzel ? 'var(--muted,#7a8fa6)' : '#fff';
  document.getElementById('card-titel').textContent       = einzel ? 'Antrag für Einzelmitglied' : 'Antrag für alle Mitglieder der TK';
  document.getElementById('wrap-mitglied').style.display  = einzel ? '' : 'none';
  document.getElementById('mitglied-info').style.display  = 'none';
  document.getElementById('wrap-alle').style.display      = einzel ? 'none' : '';
  mitgliedDaten = null;
  updateEinreichenBtn();

  // Mitglieder neu laden falls TK bereits gewählt
  if (document.getElementById('sel-tk').value) ladeMitglieder();
}

// ── API-Helper ────────────────────────────────────────────────
async function apiBuero(action, extra = {}) {
  const fd = new FormData();
  fd.append('action', action); fd.append('csrf', CSRF);
  Object.entries(extra).forEach(([k,v]) => fd.append(k, v));
  return fetch('../api/buero.php', { method:'POST', body:fd }).then(r=>r.json());
}

function linkify(text) {
  const esc = document.createElement('div');
  esc.textContent = text;
  return esc.innerHTML.replace(
    /(https?:\/\/[^\s]+)/g,
    '<a href="$1" target="_blank" rel="noopener" style="color:inherit;text-decoration:underline;font-weight:700">$1</a>'
  );
}
function showMsg(msg, ok = true) {
  const b = document.getElementById('alert-global');
  b.className = 'alert ' + (ok ? 'alert-success' : 'alert-error');
  b.innerHTML = (ok ? '✓ ' : '⚠ ') + linkify(msg);
  b.style.display = 'block';
  if (ok) setTimeout(() => b.style.display = 'none', 5000);
}

// ── TKs laden ──────────────────────────────────────────────
fetch('../api/tks.php').then(r=>r.json()).then(d => {
  const sel = document.getElementById('sel-tk');
  if (!d.ok || !d.data.length) { sel.innerHTML = '<option value="">Keine TKs verfügbar</option>'; return; }
  d.data.forEach(tk => {
    const o = document.createElement('option');
    o.value = tk.id;
    o.textContent = tk.kuerzel + ' – ' + tk.bezeichnung;
    sel.appendChild(o);
  });
});

// ── Mitglieder laden (beide Modi) ───────────────────────────
async function ladeMitglieder() {
  const tkId = document.getElementById('sel-tk').value;
  mitgliedDaten = null;
  alleMitglieder = [];
  document.getElementById('mitglied-info').style.display = 'none';

  if (!tkId) {
    document.getElementById('sel-mitglied').innerHTML = '<option value="">– Erst TK wählen –</option>';
    document.getElementById('sel-mitglied').disabled = true;
    document.getElementById('mitglieder-checks').innerHTML = '<div style="text-align:center;padding:20px;color:var(--muted);font-size:13px">Erst TK wählen …</div>';
    updateEinreichenBtn();
    return;
  }

  const d = await apiBuero('list_mitglieder', { tk_id: tkId });
  const aktive = (d.ok ? d.data : []).filter(m => m.aktiv);

  if (modus === 'einzel') {
    const sel = document.getElementById('sel-mitglied');
    sel.innerHTML = '<option value="">– Mitglied wählen –</option>';
    if (!aktive.length) { sel.innerHTML = '<option value="">Keine aktiven Mitglieder</option>'; sel.disabled = true; }
    else {
      aktive.forEach(m => {
        const o = document.createElement('option');
        o.value = m.id; o.textContent = m.vorname + ' ' + (m.nachname||'');
        sel.appendChild(o);
      });
      sel.disabled = false;
    }
  } else {
    // Alle-Modus: Stammdaten für jedes Mitglied laden
    const container = document.getElementById('mitglieder-checks');
    container.innerHTML = '<div style="text-align:center;padding:16px;color:var(--muted);font-size:13px">Wird geladen …</div>';

    if (!aktive.length) {
      container.innerHTML = '<div style="text-align:center;padding:20px;color:var(--muted);font-size:13px">Keine aktiven Mitglieder in dieser TK.</div>';
      updateEinreichenBtn(); return;
    }

    // Stammdaten parallel laden
    const details = await Promise.all(aktive.map(m => apiBuero('get_mitglied', { id: m.id })));
    alleMitglieder = details.map(d => d.ok ? d.data : null).filter(Boolean);

    // Warnung für Mitglieder ohne vollständige Stammdaten
    const unvollst = alleMitglieder.filter(m => !m.airline || !m.position || !m.flugzeugmuster);
    if (unvollst.length) {
      showMsg(`⚠ ${unvollst.length} Mitglied(er) ohne vollständige Stammdaten (Airline/Position/Muster). Diese werden mit „k.A." eingetragen: ${unvollst.map(m => m.vorname + ' ' + (m.nachname||'')).join(', ')}`, false);
    }

    container.innerHTML = '';
    alleMitglieder.forEach((m, idx) => {
      const row = document.createElement('div');
      row.style.cssText = `display:flex;align-items:center;gap:12px;padding:11px 14px;font-size:13px;${idx % 2 === 0 ? 'background:#fff' : 'background:#f8f9fb'};border-bottom:1px solid var(--border,#dde2ea)`;
      row.innerHTML = `
        <input type="checkbox" id="chk-${m.id}" value="${m.id}" checked
          onchange="updateEinreichenBtn()"
          style="width:16px;height:16px;cursor:pointer;accent-color:var(--navy2,#1a3a5c)">
        <label for="chk-${m.id}" style="flex:1;cursor:pointer;font-weight:600;color:var(--navy2,#1a3a5c)">
          ${m.vorname} ${m.nachname || ''}
        </label>
        <span style="font-size:12px;color:var(--muted)">${m.airline || '–'}</span>
        <span style="font-size:11px;background:#eef2ff;color:#3730a3;padding:2px 8px;border-radius:12px;font-weight:700">${m.position || '–'}</span>
      `;
      container.appendChild(row);
    });
  }
  updateEinreichenBtn();
}

// ── Alle an-/abwählen ─────────────────────────────────────────
function alleAnwaehlen(checked) {
  document.querySelectorAll('[id^="chk-"]').forEach(cb => cb.checked = checked);
  updateEinreichenBtn();
}

// ── Einzelmitglied: Stammdaten ────────────────────────────────
async function ladeMitgliedDaten() {
  const id = document.getElementById('sel-mitglied').value;
  document.getElementById('mitglied-info').style.display = 'none';
  mitgliedDaten = null;
  if (!id) { updateEinreichenBtn(); return; }
  const d = await apiBuero('get_mitglied', { id });
  if (!d.ok) { showMsg('Fehler: ' + d.error, false); return; }
  mitgliedDaten = d.data;
  document.getElementById('info-airline').textContent   = d.data.airline        || '–';
  document.getElementById('info-position').textContent  = d.data.position       || '–';
  document.getElementById('info-muster').textContent    = d.data.flugzeugmuster || '–';
  document.getElementById('mitglied-info').style.display = 'block';
  await ladeVeranstaltungenFuerAirline(d.data.airline || null);
  updateEinreichenBtn();
}

// ── Termin-Verwaltung ─────────────────────────────────────────
let terminCount = 0;
function terminHinzufuegen() {
  const id   = ++terminCount;
  const list = document.getElementById('termine-list');
  const item = document.createElement('div');
  item.id = 'termin-' + id;
  item.style.cssText = 'display:flex;flex-wrap:wrap;gap:8px;align-items:end;background:#f8f9fb;border:1px solid var(--border,#dde2ea);border-radius:8px;padding:10px';
  item.innerHTML = `
    <div style="display:grid;grid-template-columns:1fr 1fr 1fr auto;gap:8px;align-items:end;flex:1 1 100%">
      <div class="fg" style="margin:0">
        <label style="font-size:12px;font-weight:600;color:var(--navy2,#1a3a5c);display:block;margin-bottom:3px">Von <span style="color:#e63946">*</span></label>
        <input type="date" id="t${id}-von" onchange="onVonChange(${id})">
      </div>
      <div class="fg" style="margin:0">
        <label style="font-size:12px;font-weight:600;color:var(--navy2,#1a3a5c);display:block;margin-bottom:3px">Bis <span style="color:#e63946">*</span></label>
        <input type="date" id="t${id}-bis" onchange="onBisChange(${id})">
      </div>
      <div class="fg" style="margin:0">
        <label style="font-size:12px;font-weight:600;color:var(--navy2,#1a3a5c);display:block;margin-bottom:3px">Ereignisart <span style="color:#e63946">*</span></label>
        <select id="t${id}-typ" onchange="onTypChange(${id})">
          <option value="">– Wählen –</option>
          ${VERANSTALTUNGEN.map(v => `<option value="${v.value}">${v.label}</option>`).join('')}
        </select>
      </div>
      <button type="button" class="btn btn-outline btn-sm" style="color:#c0392b;align-self:end" onclick="terminEntfernen(${id})">✕</button>
    </div>
    <div class="fg" id="t${id}-bez-wrap" style="margin:0;flex:1 1 100%;display:none">
      <label style="font-size:12px;font-weight:600;color:var(--navy2,#1a3a5c);display:block;margin-bottom:3px">Bezeichnung der Veranstaltung <span style="color:#e63946">*</span></label>
      <input type="text" id="t${id}-bez" maxlength="150" placeholder="z. B. Sommerfest 2026" oninput="updateEinreichenBtn()">
    </div>
    <div id="t${id}-warn" style="display:none;flex:1 1 100%;font-size:12px;font-weight:600;color:#92400e;background:#fef3c7;border:1px solid #fde68a;border-radius:6px;padding:6px 10px">
      ⚠ Dieser Zeitraum liegt (teilweise) in der Vergangenheit.
    </div>
  `;
  list.appendChild(item);
  updateEinreichenBtn();
}

// ── Heutiges Datum als ISO-String ───────────────────────────────
function todayIso() {
  const d = new Date();
  return `${d.getFullYear()}-${String(d.getMonth()+1).padStart(2,'0')}-${String(d.getDate()).padStart(2,'0')}`;
}

// ── Von geändert: Bis nachziehen (Min + Vorbefüllung fürs Kalender-Popup) ──
function onVonChange(id) {
  const vonEl = document.getElementById(`t${id}-von`);
  const bisEl = document.getElementById(`t${id}-bis`);
  const von   = vonEl.value;
  if (von) {
    bisEl.min = von;
    // Bis leer oder vor Von: auf Von vorbefüllen, damit sich beim Öffnen
    // des Bis-Datepickers direkt der passende Monat öffnet (z.B. Von=1.8.
    // -> Bis-Kalender zeigt August statt des aktuellen Monats).
    if (!bisEl.value || bisEl.value < von) bisEl.value = von;
  } else {
    bisEl.min = '';
  }
  updateVergangenheitWarnung(id);
  updateEinreichenBtn();
}

// ── Bis geändert: Von-Max nachziehen (kein Bis vor Von möglich) ─────
function onBisChange(id) {
  const vonEl = document.getElementById(`t${id}-von`);
  const bisEl = document.getElementById(`t${id}-bis`);
  const bis   = bisEl.value;
  if (bis) {
    vonEl.max = bis;
    if (vonEl.value && vonEl.value > bis) vonEl.value = bis;
  } else {
    vonEl.max = '';
  }
  updateVergangenheitWarnung(id);
  updateEinreichenBtn();
}

// ── Warnhinweis, wenn der Zeitraum (teilweise) in der Vergangenheit liegt ──
function updateVergangenheitWarnung(id) {
  const von  = document.getElementById(`t${id}-von`)?.value;
  const warn = document.getElementById(`t${id}-warn`);
  if (!warn) return;
  warn.style.display = (von && von < todayIso()) ? 'block' : 'none';
}

function onTypChange(id) {
  const typ    = document.getElementById(`t${id}-typ`).value;
  const wrap   = document.getElementById(`t${id}-bez-wrap`);
  if (typ === 'sonstige') {
    wrap.style.display = '';
  } else {
    wrap.style.display = 'none';
    document.getElementById(`t${id}-bez`).value = '';
  }
  updateEinreichenBtn();
}

function terminEntfernen(id) {
  document.getElementById('termin-' + id)?.remove();
  updateEinreichenBtn();
}

function getTermine() {
  return [...document.querySelectorAll('[id^="termin-"]')].map(el => {
    const id  = el.id.replace('termin-', '');
    const typ = document.getElementById(`t${id}-typ`)?.value || '';
    return {
      id,
      von: document.getElementById(`t${id}-von`)?.value || '',
      bis: document.getElementById(`t${id}-bis`)?.value || '',
      typ,
      bezeichnung: typ === 'sonstige' ? (document.getElementById(`t${id}-bez`)?.value || '').trim() : '',
    };
  });
}

function getGewaehlte() {
  return alleMitglieder.filter(m => document.getElementById('chk-' + m.id)?.checked);
}

// ── Submit-Button + Info-Text ─────────────────────────────────
function updateEinreichenBtn() {
  const termine    = getTermine();
  const alleVollst = termine.length > 0 && termine.every(t => t.von && t.bis && t.typ && (t.typ !== 'sonstige' || t.bezeichnung));

  // Formular hat sich geändert -> ein evtl. vorher geprüfter Konflikt-Plan
  // ist nicht mehr gültig, zurück auf Phase 1 (erneute Prüfung nötig).
  if (aktuellerPlan) {
    aktuellerPlan = null;
    document.getElementById('konflikt-panel').style.display = 'none';
    document.getElementById('konflikt-panel').innerHTML = '';
    document.getElementById('btn-einreichen').textContent = 'Antrag einreichen';
  }

  let bereit = false;
  let info   = '';
  let mitglieder = [];

  if (modus === 'einzel') {
    bereit = !!mitgliedDaten && alleVollst;
    info   = mitgliedDaten ? `1 Mitglied · ${termine.length} Termin(e)` : '';
    mitglieder = mitgliedDaten ? [mitgliedDaten] : [];
  } else {
    const gew = getGewaehlte();
    bereit = gew.length > 0 && alleVollst;
    info   = gew.length > 0 ? `${gew.length} Mitglied(er) · ${termine.length} Termin(e) → ${gew.length * termine.length} Einträge` : 'Kein Mitglied ausgewählt';
    mitglieder = gew;
  }

  aktualisiereFreistellungscodeFeld(mitglieder);
  if (fscodePflicht && !document.getElementById('sel-fscode').value) bereit = false;

  document.getElementById('btn-einreichen').disabled = !bereit;
  document.getElementById('submit-info').textContent  = info;
}

// ── Freistellungscode-Feld: ein-/ausblenden je nach Airline(n) ─
let letzteFscodeAirline = null;
let fscodePflicht = false;
async function aktualisiereFreistellungscodeFeld(mitglieder) {
  const wrap = document.getElementById('wrap-fscode');
  const sel  = document.getElementById('sel-fscode');

  const airlines = [...new Set(mitglieder.map(m => m.airline).filter(Boolean))];
  // Nur bei genau EINER eindeutigen Airline anzeigen - bei gemischten
  // Airlines im Alle-Modus wäre ein einzelner Code nicht sinnvoll
  // zuordenbar, dort greift der automatische Lookup je Antrag wie gehabt.
  const airline = airlines.length === 1 ? airlines[0] : null;

  if (!airline) {
    wrap.style.display = 'none';
    fscodePflicht = false;
    letzteFscodeAirline = null;
    return;
  }
  if (airline === letzteFscodeAirline) return; // schon geladen, nichts tun
  letzteFscodeAirline = airline;

  const d = await apiBuero('list_freistellungscodes', { airline });
  // Bei genau einem konfigurierten Code: Feld ausblenden, der automatische
  // Lookup in api/antrag.php trägt ihn ohnehin korrekt ein - keine
  // manuelle Interaktion nötig. Nur ab zwei Codes ist eine echte
  // Auswahlentscheidung zu treffen, dafür bleibt es Pflichtfeld.
  if (!d.ok || d.data.codes.length < 2) {
    wrap.style.display = 'none';
    fscodePflicht = false;
    return;
  }
  sel.innerHTML = '<option value="">– bitte wählen –</option>' +
    d.data.codes.map(c => `<option value="${c}">${c}</option>`).join('');
  wrap.style.display = 'block';
  fscodePflicht = true;
  updateEinreichenBtn(); // erneut prüfen, jetzt mit sichtbarem Pflichtfeld
}

// ── Konfliktprüfung ──────────────────────────────────────────────
let aktuellerPlan = null; // gemerkter Plan zwischen 1. und 2. Klick auf "Einreichen"

async function pruefeKonflikte(mitglieder, termine) {
  const plan = [];
  for (const m of mitglieder) {
    for (const t of termine) {
      const res = await apiBuero('pruefe_ueberschneidung', { tk_id: m.tk_id, email: m.email, von: t.von, bis: t.bis });
      const konflikte = (res.ok && res.data.konflikte) ? res.data.konflikte : [];
      const konfliktMap = new Map(konflikte.map(k => [k.tag, k]));

      const alleTage = alleTageZwischen(t.von, t.bis);
      const freiTage   = alleTage.filter(tag => !konfliktMap.has(tag));
      const belegtTage = alleTage.filter(tag =>  konfliktMap.has(tag));

      const gruppen = {};
      belegtTage.forEach(tag => {
        const k = konfliktMap.get(tag);
        if (!gruppen[k.antrag_id]) {
          gruppen[k.antrag_id] = { antrag_id: k.antrag_id, ereignis_id: k.ereignis_id, veranstaltung: k.veranstaltung, tage: [] };
        }
        gruppen[k.antrag_id].tage.push(tag);
      });

      plan.push({ mitglied: m, termin: t, freiRanges: toRanges(freiTage), konfliktGruppen: Object.values(gruppen) });
    }
  }
  return plan;
}

function renderKonfliktPanel(plan) {
  const panel = document.getElementById('konflikt-panel');
  const konfliktEintraege = plan.filter(p => p.konfliktGruppen.length > 0);

  if (!konfliktEintraege.length) { panel.style.display = 'none'; panel.innerHTML = ''; return; }

  panel.style.display = 'block';
  panel.innerHTML = `
    <div style="background:#fff3cd;border:1px solid #ffc107;border-radius:8px;padding:14px 18px;margin-bottom:14px">
      <strong style="color:#856404">⚠ Überschneidungen gefunden</strong>
      <p style="font-size:13px;color:#856404;margin:6px 0 0">
        Für die unten aufgeführten Tage besteht bereits ein Antrag desselben Mitglieds.
        Diese Tage werden nicht als neuer Antrag angelegt, sondern per <strong>Umwidmung</strong>
        auf die neue Veranstaltungsart umgestellt. Bitte je Konflikt einen Grund angeben.
        Freie Tage im selben Termin werden ganz normal als neuer Antrag eingereicht.
      </p>
    </div>
    ${konfliktEintraege.map(p => p.konfliktGruppen.map(g => `
      <div style="border:1px solid var(--border,#dde2ea);border-radius:8px;padding:12px 16px;margin-bottom:10px;background:#f8f9fb">
        <div style="font-size:13px;margin-bottom:8px">
          <strong>${p.mitglied.vorname} ${p.mitglied.nachname || ''}</strong> ·
          bestehender Antrag <span class="mono">#${g.antrag_id}</span> (${g.veranstaltung}) →
          wird umgewidmet auf <strong>${VERANSTALTUNGEN.find(v => v.value === p.termin.typ)?.label || p.termin.typ}</strong>${p.termin.typ === 'sonstige' && p.termin.bezeichnung ? ' „' + p.termin.bezeichnung + '"' : ''}
          <br><span style="color:var(--muted)">Betroffene Tage: ${g.tage.map(fmtD).join(', ')}</span>
        </div>
        <label style="font-size:12px;font-weight:600;color:var(--navy2,#1a3a5c);display:block;margin-bottom:3px">Grund der Umwidmung <span style="color:#e63946">*</span></label>
        <input type="text" id="kw-grund-${p.mitglied.id}-${p.termin.id}-${g.antrag_id}"
               placeholder="z. B. Terminverschiebung" style="width:100%;padding:8px 10px;border:1px solid var(--border,#dde2ea);border-radius:6px;font-size:13px;box-sizing:border-box">
      </div>
    `).join('')).join('')}
  `;
}

function konfliktGruendeVollstaendig(plan) {
  for (const p of plan) {
    for (const g of p.konfliktGruppen) {
      const el = document.getElementById(`kw-grund-${p.mitglied.id}-${p.termin.id}-${g.antrag_id}`);
      if (!el || !el.value.trim()) return false;
    }
  }
  return true;
}

async function fuehreEinreichungAus(plan) {
  let ok = 0, fehler = [];

  for (const p of plan) {
    const m = p.mitglied, t = p.termin;

    // Sonderfall: genau EINE Konfliktgruppe + freie Tage vorhanden ->
    // freie Tage werden NACH der Umwidmung in denselben Antrag integriert,
    // statt einen zweiten, separaten Antrag/Ereignis anzulegen.
    const zusammenfuehrbar = p.konfliktGruppen.length === 1 && p.freiRanges.length > 0;

    // 1) Konfliktgruppen per Umwidmung umstellen
    let erweiterterAntragId = null;
    for (const g of p.konfliktGruppen) {
      const grundEl = document.getElementById(`kw-grund-${m.id}-${t.id}-${g.antrag_id}`);
      const grund = grundEl ? grundEl.value.trim() : '';
      const fd = new FormData();
      fd.append('action', 'umwidmen_tage');
      fd.append('csrf', CSRF);
      fd.append('ereignis_id', g.ereignis_id);
      fd.append('antrag_ids[]', g.antrag_id);
      fd.append('neuer_typ', TYP_ZU_UW[t.typ]);
      fd.append('neuer_typ_text', t.typ === 'sonstige' ? (t.bezeichnung || '') : '');
      g.tage.forEach(tag => fd.append('tage[]', tag));
      fd.append('grund', grund);
      try {
        const j = await (await fetch('../api/buero.php', { method:'POST', body:fd })).json();
        if (j.ok) {
          ok++;
          if (zusammenfuehrbar && j.data?.neue_antrag_ids?.length) {
            erweiterterAntragId = j.data.neue_antrag_ids[0];
          }
        } else {
          fehler.push(`${m.vorname} ${m.nachname || ''} (Umwidmung #${g.antrag_id}): ${j.error}`);
        }
      } catch(e) {
        fehler.push(`${m.vorname} ${m.nachname || ''} (Umwidmung #${g.antrag_id}): Verbindungsfehler`);
      }
    }

    // 2a) Freie Tage in den umgewidmeten Antrag integrieren (Sonderfall)
    if (zusammenfuehrbar && erweiterterAntragId) {
      const alleFreienTage = p.freiRanges.flatMap(r => alleTageZwischen(r.von, r.bis));
      const fd = new FormData();
      fd.append('action', 'antrag_erweitern');
      fd.append('csrf', CSRF);
      fd.append('antrag_id', erweiterterAntragId);
      alleFreienTage.forEach(tag => fd.append('tage[]', tag));
      try {
        const j = await (await fetch('../api/buero.php', { method:'POST', body:fd })).json();
        if (j.ok) ok++;
        else fehler.push(`${m.vorname} ${m.nachname || ''} (Erweiterung #${erweiterterAntragId}): ${j.error}`);
      } catch(e) {
        fehler.push(`${m.vorname} ${m.nachname || ''} (Erweiterung #${erweiterterAntragId}): Verbindungsfehler`);
      }
    } else if (p.freiRanges.length) {
      // 2b) Regulärer Fall: freie Tage als eigenständigen neuen Antrag einreichen
      const fd = new FormData();
      fd.append('csrf', CSRF);
      fd.append('mitglied_id_override', m.id);
      fd.append('vorname',        m.vorname);
      fd.append('nachname',       m.nachname       || '');
      fd.append('tk_id',          m.tk_id);
      fd.append('vc_email',       m.email          || '');
      fd.append('airline',        m.airline        || '');
      fd.append('position',       m.position       || '');
      fd.append('flugzeugmuster', m.flugzeugmuster || '');
      fd.append('freistellungscode', document.getElementById('sel-fscode').value || '');
      p.freiRanges.forEach((r, idx) => {
        fd.append(`termine[${idx}][von]`, r.von);
        fd.append(`termine[${idx}][bis]`, r.bis);
        fd.append(`termine[${idx}][typ]`, t.typ);
        fd.append(`termine[${idx}][bezeichnung]`, t.typ === 'sonstige' ? (t.bezeichnung || '') : '');
      });
      try {
        const j = await (await fetch('../api/antrag.php', { method:'POST', body:fd })).json();
        if (j.ok) ok++; else fehler.push(`${m.vorname} ${m.nachname || ''}: ${j.error}`);
      } catch(e) {
        fehler.push(`${m.vorname} ${m.nachname || ''}: Verbindungsfehler`);
      }
    }
  }

  return { ok, fehler };
}

// ── Einreichen ────────────────────────────────────────────────
async function einreichen() {
  const termine = getTermine();
  if (!termine.length || termine.some(t => !t.von || !t.bis || !t.typ)) {
    showMsg('Bitte alle Termine vollständig ausfüllen.', false); return;
  }
  if (termine.some(t => t.typ === 'sonstige' && !t.bezeichnung)) {
    showMsg('Bitte für jeden Termin mit Ereignisart „Sonstiges" eine Bezeichnung angeben.', false); return;
  }
  const heute = todayIso();
  if (termine.some(t => t.von < heute)) {
    if (!confirm('Ein oder mehrere Zeiträume liegen in der Vergangenheit.\n\nTrotzdem einreichen?')) return;
  }

  const mitglieder = modus === 'einzel'
    ? (mitgliedDaten ? [mitgliedDaten] : [])
    : getGewaehlte();

  if (!mitglieder.length) { showMsg('Kein Mitglied ausgewählt.', false); return; }

  const btn = document.getElementById('btn-einreichen');

  // ── Phase 2: Plan liegt schon vor (Konflikt-Panel wurde angezeigt) ──
  if (aktuellerPlan) {
    if (!konfliktGruendeVollstaendig(aktuellerPlan)) {
      showMsg('Bitte für jede Umwidmung einen Grund angeben.', false);
      return;
    }
    btn.disabled = true;
    btn.textContent = 'Wird eingereicht …';
    const { ok, fehler } = await fuehreEinreichungAus(aktuellerPlan);
    abschliessen(ok, fehler);
    return;
  }

  // ── Phase 1: auf Überschneidungen prüfen ──
  btn.disabled = true;
  btn.textContent = 'Prüfe auf Überschneidungen …';
  const plan = await pruefeKonflikte(mitglieder, termine);
  const hatKonflikte = plan.some(p => p.konfliktGruppen.length > 0);

  if (hatKonflikte) {
    aktuellerPlan = plan;
    renderKonfliktPanel(plan);
    btn.textContent = 'Umwidmung(en) bestätigen & einreichen';
    btn.disabled = false;
    showMsg('Für einen Teil des Zeitraums besteht bereits ein Antrag – bitte Grund für die Umwidmung angeben und erneut auf „Einreichen" klicken.', false);
    return;
  }

  // Keine Konflikte -> direkt einreichen
  btn.textContent = 'Wird eingereicht …';
  const { ok, fehler } = await fuehreEinreichungAus(plan);
  abschliessen(ok, fehler);
}

function abschliessen(ok, fehler) {
  const btn = document.getElementById('btn-einreichen');
  btn.textContent = 'Antrag einreichen';
  document.getElementById('konflikt-panel').style.display = 'none';
  document.getElementById('konflikt-panel').innerHTML = '';
  aktuellerPlan = null;

  if (fehler.length === 0) {
    showMsg(`✓ ${ok} Eintrag/Einträge erfolgreich verarbeitet.`);
    // Formular zurücksetzen
    document.getElementById('sel-tk').value = '';
    document.getElementById('sel-mitglied').innerHTML = '<option value="">– Erst TK wählen –</option>';
    document.getElementById('sel-mitglied').disabled = true;
    document.getElementById('mitglied-info').style.display = 'none';
    document.getElementById('mitglieder-checks').innerHTML = '<div style="text-align:center;padding:20px;color:var(--muted);font-size:13px">Erst TK wählen …</div>';
    document.getElementById('termine-list').innerHTML = '';
    document.getElementById('submit-info').textContent = '';
    terminCount = 0; mitgliedDaten = null; alleMitglieder = [];
    terminHinzufuegen();
  } else {
    const msg = ok > 0
      ? `${ok} erfolgreich, ${fehler.length} Fehler:\n` + fehler.join('\n')
      : 'Fehler:\n' + fehler.join('\n');
    showMsg(msg, false);
    btn.disabled = false;
    updateEinreichenBtn();
  }
}

// Ersten Termin gleich anzeigen
terminHinzufuegen();
</script>

<?php panel_foot(); ?>
