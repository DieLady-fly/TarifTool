<?php
// buero/ag_rueckmeldung.php  –  AG-Rückmeldung: genehmigt / abgelehnt_ag setzen
require_once __DIR__ . '/../_backend/bootstrap.php';
require_once __DIR__ . '/../_backend/panel.php';
send_security_headers();
$auth = require_login('buero');

panel_head('AG-Rückmeldung');
panel_sidebar('ag_rueckmeldung', $auth['rolle'], $auth['user']);
panel_topbar('AG-Rückmeldung');
?>

<style>
/* ── Stepper ────────────────────────────────────────────────── */
.stepper { display:flex; gap:0; margin-bottom:28px; }
.step { flex:1; text-align:center; padding:13px 8px; background:#f8f9fb;
  border:1px solid var(--border,#dde2ea); border-right:none; font-size:13px;
  color:var(--muted,#7a8fa6); position:relative; cursor:default; }
.step:last-child { border-right:1px solid var(--border,#dde2ea); border-radius:0 8px 8px 0; }
.step:first-child { border-radius:8px 0 0 8px; }
.step.active { background:#0f2744; color:#fff; font-weight:700; border-color:#0f2744; }
.step.done   { background:#e6f4ea; color:#1e7e34; border-color:#81c784; }
.step .sn    { display:block; font-size:18px; font-weight:700; margin-bottom:2px; }

/* ── Panels ────────────────────────────────────────────────── */
.panel { display:none; }
.panel.active { display:block; }

/* ── Ereignis-Karte ─────────────────────────────────────────── */
.erg-card { border:1px solid var(--border,#dde2ea); border-radius:10px;
  padding:14px 18px; margin-bottom:10px; cursor:pointer;
  display:flex; align-items:center; gap:14px; transition:background .15s; }
.erg-card:hover { background:#f0f4ff; border-color:#a5b4fc; }
.erg-card.selected { background:#eef2ff; border-color:#6366f1; }
.erg-card .erg-main { flex:1; min-width:0; }
.erg-card .erg-titel { font-size:14px; font-weight:700; color:#1c2b3a; }
.erg-card .erg-sub   { font-size:12px; color:var(--muted,#7a8fa6); margin-top:3px; }
.tk-badge { display:inline-block; padding:2px 9px; border-radius:20px;
  font-size:12px; font-weight:700; background:#eef2ff; color:#3730a3; }

/* ── Status-Badge ───────────────────────────────────────────── */
/* Nutzt jetzt statusBadge() aus assets/status.js statt eigener,
   lokaler Farb-/Label-Definitionen (siehe unten im Script). */

/* ── Modus-Umschaltung Manuell / DOC-Upload ──────────────────── */
.mode-tabs { display:flex; gap:0; margin-bottom:24px; border:1px solid var(--border,#dde2ea);
  border-radius:8px; overflow:hidden; width:fit-content; }
.mode-tab { padding:10px 24px; background:#f8f9fb; border:none; border-right:1px solid var(--border,#dde2ea);
  font-size:14px; font-weight:600; color:var(--muted,#7a8fa6); cursor:pointer;
  display:flex; align-items:center; gap:8px; transition:background .15s,color .15s; }
.mode-tab:last-child { border-right:none; }
.mode-tab:hover { background:#eef2ff; color:#3730a3; }
.mode-tab.active { background:var(--navy2,#1a3a5c); color:#fff; }

/* ── Mitglieder-Tabelle ─────────────────────────────────────── */
.mgl-table { width:100%; border-collapse:collapse; font-size:14px; }
.mgl-table th { padding:9px 12px; text-align:left; background:#f8f9fb;
  border-bottom:2px solid var(--border,#dde2ea); font-size:11px; font-weight:700;
  text-transform:uppercase; letter-spacing:.04em; color:var(--muted,#7a8fa6); }
.mgl-table td { padding:10px 12px; border-bottom:1px solid var(--border,#dde2ea);
  vertical-align:middle; }
.mgl-table tr:last-child td { border-bottom:none; }
.mgl-table tr:hover td { background:#fafafa; }
.mgl-table tr.selected td { background:#eef2ff; }

/* Checkbox-Spalte */
.cb-col { width:40px; text-align:center; }
input[type=checkbox].mgl-cb {
  width:16px; height:16px; cursor:pointer; accent-color:#6366f1; }

/* ── Aktionsleiste ──────────────────────────────────────────── */
.aktion-bar { display:flex; gap:12px; align-items:flex-end; flex-wrap:wrap;
  padding:16px 18px; background:#f8f9fb; border-top:1px solid var(--border,#dde2ea);
  border-radius:0 0 10px 10px; }
.aktion-bar .fg { display:flex; flex-direction:column; gap:4px; }
.aktion-bar label { font-size:11px; font-weight:700; color:var(--muted,#7a8fa6);
  text-transform:uppercase; letter-spacing:.04em; }
.aktion-bar select, .aktion-bar textarea {
  padding:8px 10px; border:1px solid var(--border,#dde2ea);
  border-radius:7px; font-size:14px; }
.aktion-bar textarea { min-width:260px; resize:vertical; }
.sel-info { font-size:13px; color:var(--muted,#7a8fa6); align-self:center; }

/* ── Erfolg ─────────────────────────────────────────────────── */
.erfolg-box { text-align:center; padding:40px 24px; }
.erfolg-icon { font-size:48px; margin-bottom:12px; }
.erfolg-text { font-size:16px; font-weight:600; color:#1c2b3a; margin-bottom:8px; }
.erfolg-sub  { font-size:14px; color:var(--muted,#7a8fa6); }

.alert { padding:10px 14px; border-radius:7px; font-size:13px;
  margin-bottom:14px; display:none; }
.alert-error { background:#fde8e8; color:#c0392b; border:1px solid #e57373; }
.alert-success { background:#e6f4ea; color:#1e7e34; border:1px solid #81c784; }

/* ── DOC-Upload-Modus (ehemals eigene Seite ag_doc_upload.php) ── */
.hint { font-size:12px; color:var(--muted,#7a8fa6); margin-top:4px; }
.au-table { width:100%; border-collapse:collapse; font-size:13px; }
.au-table th { padding:8px 10px; text-align:left; background:#f8f9fb;
  border-bottom:2px solid var(--border,#dde2ea); font-size:11px; font-weight:700;
  text-transform:uppercase; letter-spacing:.04em; color:var(--muted,#7a8fa6); }
.au-table td { padding:8px 10px; border-bottom:1px solid var(--border,#dde2ea); vertical-align:top; }
.tag-ok      { display:inline-block; padding:2px 9px; border-radius:20px; font-size:11px; font-weight:700; background:#e6f4ea; color:#1e7e34; }
.tag-abgelehnt { display:inline-block; padding:2px 9px; border-radius:20px; font-size:11px; font-weight:700; background:#fde8e8; color:#c0392b; }
.tag-skip    { display:inline-block; padding:2px 9px; border-radius:20px; font-size:11px; font-weight:700; background:#fff3cd; color:#856404; }
.summary-box { display:flex; gap:20px; margin:14px 0; font-size:14px; flex-wrap:wrap; }
.summary-item strong { font-size:18px; display:block; }
</style>

<!-- Modus-Umschaltung -->
<div class="mode-tabs">
  <button class="mode-tab active" id="mode-btn-manuell" onclick="modusWechseln('manuell')">✓ Manuell</button>
  <button class="mode-tab" id="mode-btn-doc" onclick="modusWechseln('doc')">📄 Per DOC-Upload</button>
</div>

<div id="mode-manuell">

<!-- Stepper -->
<div class="stepper">
  <div class="step active" id="step1-ind"><span class="sn">1</span>Ereignis wählen</div>
  <div class="step"        id="step2-ind"><span class="sn">2</span>Rückmeldung eingeben</div>
  <div class="step"        id="step3-ind"><span class="sn">3</span>Fertig</div>
</div>

<!-- Filter (Layout analog zu "Beantragung beim Arbeitgeber") -->
<div class="card" style="margin-bottom:18px">
  <div class="card-body" style="padding:16px 22px">
    <div style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end">
      <div class="fg" style="flex:0 0 170px">
        <label>Airline</label>
        <select id="f-airline">
          <option value="">Alle</option>
        </select>
      </div>
      <div class="fg" style="flex:0 0 200px">
        <label>Tarifkommission</label>
        <select id="f-tk">
          <option value="">Alle TKs</option>
        </select>
      </div>
      <div class="fg" style="flex:0 0 170px">
        <label>Veranstaltungsart</label>
        <select id="f-suche">
          <option value="">Alle</option>
          <option value="Verhandlung">Verhandlung</option>
          <option value="TK Sitzung">TK-Sitzung</option>
          <option value="sonstige">Sonstiges</option>
        </select>
      </div>
      <div class="fg" style="flex:0 0 170px">
        <label>Freistellungszeitraum von</label>
        <input type="date" id="f-zeitraum-von">
      </div>
      <div class="fg" style="flex:0 0 170px">
        <label>Freistellungszeitraum bis</label>
        <input type="date" id="f-zeitraum-bis">
      </div>
      <button class="btn btn-primary" onclick="filtereEreignisse()">Filtern</button>
      <button class="btn btn-outline" onclick="ereignisFilterZuruecksetzen()">Zurücksetzen</button>
    </div>
    <p style="font-size:12px;color:var(--muted,#7a8fa6);margin:12px 0 0">
      Es werden nur Ereignisse angezeigt, die mindestens einen Antrag mit Status
      „Beantragt bei AG" oder „Storno Büro" enthalten – also solche, die tatsächlich
      auf eine Rückmeldung/Bestätigung des Arbeitgebers warten.
    </p>
  </div>
</div>

<!-- Schritt 1: Ereignis wählen -->
<div class="panel active" id="panel1">
  <div class="card">
    <div class="card-head">
      <h2>Ereignis wählen</h2>
    </div>
    <div class="card-body">
      <div id="erg-liste">
        <div style="text-align:center;padding:32px;color:var(--muted)">Wird geladen …</div>
      </div>
    </div>
  </div>
</div>

<!-- Schritt 2: Rückmeldung eingeben -->
<div class="panel" id="panel2">
  <div class="card">
    <div class="card-head">
      <h2 id="panel2-titel">Rückmeldung</h2>
      <button class="btn btn-outline btn-sm" onclick="zurueck()">← Zurück</button>
    </div>
    <div class="card-body" style="padding:0">
      <div style="padding:14px 18px 0">
        <div class="alert alert-error" id="alert-save"></div>
        <div style="display:flex;align-items:center;gap:8px;margin-bottom:12px">
          <label style="font-size:12px;font-weight:700;color:var(--muted,#7a8fa6);text-transform:uppercase;letter-spacing:.04em">Status</label>
          <select id="mgl-status-filter" style="padding:7px 10px;border:1px solid var(--border,#dde2ea);border-radius:6px;font-size:13px" onchange="ereignisWaehlen(gewaehltesEreignis.ereignis_id, false)">
            <option value="beantragt_ag" selected>Beantragt bei AG (Standard)</option>
            <option value="">Alle Status</option>
            <option value="ausstehend">Ausstehend</option>
            <option value="freigabe_buero">Freigabe Büro</option>
            <option value="genehmigt">Genehmigt</option>
            <option value="abgelehnt">Abgelehnt</option>
            <option value="abgelehnt_ag">Abgelehnt (AG)</option>
            <option value="storno_buero">Storno Büro (wartet auf AG-Bestätigung)</option>
            <option value="storno_bestaetigt_ag">Storno bestätigt (AG)</option>
          </select>
        </div>
        <div style="display:flex;align-items:center;gap:8px;margin-bottom:12px">
          <label style="font-size:12px;font-weight:700;color:var(--muted,#7a8fa6);text-transform:uppercase;letter-spacing:.04em">Freistellungscode</label>
          <select id="mgl-fscode-filter" style="padding:7px 10px;border:1px solid var(--border,#dde2ea);border-radius:6px;font-size:13px" onchange="renderMitglieder()">
            <option value="">Alle</option>
          </select>
        </div>
        <!-- Alle auswählen -->
        <div style="display:flex;align-items:center;gap:10px;margin-bottom:10px">
          <input type="checkbox" id="cb-alle" style="width:16px;height:16px;accent-color:#6366f1;cursor:pointer"
            onchange="alleUmschalten(this.checked)">
          <label for="cb-alle" style="font-size:13px;cursor:pointer;user-select:none;font-weight:600">
            Alle auswählen / abwählen
          </label>
          <span id="sel-count" class="sel-info"></span>
        </div>
      </div>

      <div class="table-scroll">
        <table class="mgl-table">
          <thead><tr>
            <th class="cb-col"></th>
            <th>Name</th>
            <th>Position</th>
            <th class="hide-mobile">Zeitraum</th>
            <th class="hide-mobile">Tage</th>
            <th class="hide-mobile">Freistellungscode</th>
            <th>Status</th>
          </tr></thead>
          <tbody id="mgl-tbody">
            <tr><td colspan="7" style="text-align:center;padding:32px;color:var(--muted)">Wird geladen …</td></tr>
          </tbody>
        </table>
      </div>

      <!-- Aktionsleiste -->
      <div class="aktion-bar">
        <div class="fg">
          <label>Rückmeldung AG</label>
          <select id="ag-status">
            <option value="genehmigt">✓ Genehmigt</option>
            <option value="abgelehnt_ag">✕ Abgelehnt durch AG</option>
            <option value="storno_bestaetigt_ag">✓ Storno bestätigt durch AG</option>
          </select>
        </div>
        <div class="fg">
          <label>Notiz / Begründung</label>
          <textarea id="ag-notiz" rows="2" placeholder="Optional …"></textarea>
        </div>
        <div class="fg" style="justify-content:flex-end">
          <label>&nbsp;</label>
          <button class="btn btn-primary" id="btn-speichern" onclick="speichern()">
            Speichern &amp; Mitglieder benachrichtigen
          </button>
        </div>
      </div>

    </div>
  </div>
</div>

<!-- Schritt 3: Fertig -->
<div class="panel" id="panel3">
  <div class="card">
    <div class="card-body">
      <div class="erfolg-box">
        <div class="erfolg-icon">✓</div>
        <div class="erfolg-text" id="erfolg-text">Gespeichert.</div>
        <div class="erfolg-sub">Die betroffenen Mitglieder wurden per E-Mail informiert.</div>
        <div style="margin-top:24px;display:flex;gap:10px;justify-content:center">
          <button class="btn btn-outline" onclick="neueRueckmeldung()">Weitere Rückmeldung</button>
          <a href="antraege.php" class="btn btn-primary">Zur Antragsliste</a>
        </div>
      </div>
    </div>
  </div>
</div>

</div><!-- /mode-manuell -->

<div id="mode-doc" style="display:none">
  <div class="card" style="max-width:1100px">
    <div class="card-head"><h2>Ausgefülltes AG-Formular hochladen</h2></div>
    <div class="card-body">
      <div id="upload-alert" class="alert" style="display:none"></div>

      <p style="font-size:14px;color:var(--muted,#7a8fa6);margin-bottom:16px">
        Lade hier das von der Airline ausgefüllte Word-Formular hoch (die Datei, die bei
        „Beantragung beim AG" mit angehängt wurde, sofern für die jeweilige Airline
        „Auch als Word-DOC anhängen" aktiviert war). Die Checkboxen „gewährt" / „nicht gewährt"
        werden automatisch ausgelesen und als Vorschau angezeigt – <strong>es wird noch nichts
        in der Datenbank geändert</strong>, bis du unten ausdrücklich auf „Übernehmen" klickst.
      </p>

      <div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap">
        <input type="file" id="docx-input" accept=".docx">
        <button class="btn btn-primary" id="btn-analysieren" onclick="datenAnalysieren()">Datei hochladen &amp; analysieren</button>
      </div>
      <div class="hint">Erlaubt: .docx-Dateien, die von diesem System erzeugt wurden (enthalten versteckte Formular-Markierungen je Antrag).</div>
    </div>
  </div>

  <div class="card" id="card-vorschau" style="max-width:1100px;margin-top:20px;display:none">
    <div class="card-head"><h2>Vorschau der erkannten Änderungen</h2></div>
    <div class="card-body">
      <div class="summary-box" id="summary-box"></div>

      <div class="table-scroll">
        <table class="au-table">
          <thead><tr>
            <th>Name</th>
            <th>Airline</th>
            <th>Ereignis / Zeitraum</th>
            <th>Aktueller Status</th>
            <th>Erkannt</th>
            <th>Neuer Status</th>
            <th>Hinweis</th>
          </tr></thead>
          <tbody id="vorschau-tbody"></tbody>
        </table>
      </div>

      <div style="display:flex;gap:12px;align-items:center;margin-top:18px;flex-wrap:wrap">
        <button class="btn btn-primary" id="btn-uebernehmen" onclick="aenderungenUebernehmen()">✓ Änderungen übernehmen</button>
        <button class="btn btn-outline" onclick="vorschauVerwerfen()">Verwerfen</button>
      </div>
    </div>
  </div>
</div><!-- /mode-doc -->

<script src="../assets/status.js"></script>
<script>
const CSRF = '<?= csrf_token() ?>';
let ereignisListe  = [];
let gewaehltesEreignis = null;
let mitgliederDaten    = [];

async function api(action, extra = {}) {
  const fd = new FormData();
  fd.append('action', action);
  fd.append('csrf', CSRF);
  for (const [k, v] of Object.entries(extra)) {
    if (Array.isArray(v)) v.forEach(x => fd.append(k + '[]', x));
    else fd.append(k, v);
  }
  return fetch('../api/buero.php', { method: 'POST', body: fd }).then(r => r.json());
}

function esc(s) {
  return String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}
function fmt(iso) {
  if (!iso) return '–';
  const [y,m,d] = iso.split('-');
  return d + '.' + m + '.' + y;
}

// statusBadge() kommt jetzt aus der gemeinsamen assets/status.js
// (einheitliche Bezeichnungen/Farben) - keine eigene, hier zuvor
// abweichende Definition mehr ("Abgelehnt Büro", "Abgel. durch AG", …).

function setStep(n) {
  [1,2,3].forEach(i => {
    document.getElementById('panel' + i).className = 'panel' + (i === n ? ' active' : '');
    const ind = document.getElementById('step' + i + '-ind');
    ind.className = 'step' + (i === n ? ' active' : (i < n ? ' done' : ''));
  });
}

// ── Schritt 1: Ereignisse laden ───────────────────────────────
async function ladeEreignisse() {
  const d = await api('list_ereignisse', { nur_ag_relevante: '1' });
  if (!d.ok) return;
  ereignisListe = d.data || [];

  // Airline-Filter befüllen
  const airlines = [...new Set(ereignisListe.map(e => e.airline).filter(Boolean))].sort();
  const airlineSel = document.getElementById('f-airline');
  airlines.forEach(al => {
    const o = document.createElement('option');
    o.value = al; o.textContent = al;
    airlineSel.appendChild(o);
  });

  // TK-Filter befüllen
  const tks = [...new Map(ereignisListe.map(e => [e.tk_id, {id:e.tk_id, kuerzel:e.tk_kuerzel, bez:e.tk_name}])).values()];
  const sel = document.getElementById('f-tk');
  tks.forEach(tk => {
    const o = document.createElement('option');
    o.value = tk.id; o.textContent = tk.kuerzel + ' – ' + tk.bez;
    sel.appendChild(o);
  });
  sel.addEventListener('change', filtereEreignisse);

  filtereEreignisse();
}

function filtereEreignisse() {
  const airlineFilter = document.getElementById('f-airline').value;
  const tkFilter    = document.getElementById('f-tk').value;
  const suchFilter  = document.getElementById('f-suche').value;
  const zrVon       = document.getElementById('f-zeitraum-von').value;
  const zrBis       = document.getElementById('f-zeitraum-bis').value;
  const gefiltert   = ereignisListe.filter(e =>
    (!airlineFilter || e.airline === airlineFilter) &&
    (!tkFilter    || String(e.tk_id) === tkFilter) &&
    (!suchFilter  || e.veranstaltung === suchFilter) &&
    // Überlappung: Ereignis-Zeitraum überschneidet sich mit dem Filter-Zeitraum
    (!zrVon || e.zeitraum_bis >= zrVon) &&
    (!zrBis || e.zeitraum_von <= zrBis)
  );
  renderEreignisse(gefiltert);
}

function ereignisFilterZuruecksetzen() {
  document.getElementById('f-airline').value = '';
  document.getElementById('f-tk').value = '';
  document.getElementById('f-suche').value = '';
  document.getElementById('f-zeitraum-von').value = '';
  document.getElementById('f-zeitraum-bis').value = '';
  filtereEreignisse();
}

function renderEreignisse(liste) {
  const wrap = document.getElementById('erg-liste');
  if (!liste.length) {
    wrap.innerHTML = '<div style="text-align:center;padding:32px;color:var(--muted)">Keine Ereignisse gefunden.</div>';
    return;
  }
  wrap.innerHTML = liste.map(e => `
    <div class="erg-card" onclick="ereignisWaehlen(${e.ereignis_id})">
      <div class="erg-main">
        <div class="erg-titel">${esc(e.veranstaltung)}</div>
        <div class="erg-sub">
          <span class="tk-badge">${esc(e.tk_kuerzel)}</span>
          &nbsp;${esc(e.tk_name)}
          ${e.airline ? '&nbsp;·&nbsp;<strong>' + esc(e.airline) + '</strong>' : ''}
          &nbsp;·&nbsp; ${fmt(e.zeitraum_von)} – ${fmt(e.zeitraum_bis)}
        </div>
      </div>
      <span style="color:var(--muted,#7a8fa6);font-size:20px">&rsaquo;</span>
    </div>`).join('');
}

// ── Schritt 2: Mitglieder laden ───────────────────────────────
// autoDefault=true (Standard, z.B. bei Klick auf eine Ereignis-Karte):
// wählt den Status-Filter automatisch passend zum Ereignis vor. Bei
// manueller Änderung des Filters durch den Nutzer (onchange) wird
// autoDefault=false übergeben, damit die eigene Auswahl nicht
// überschrieben wird.
async function ereignisWaehlen(ereignis_id, autoDefault = true) {
  gewaehltesEreignis = ereignisListe.find(e => e.ereignis_id === ereignis_id);
  if (!gewaehltesEreignis) return;

  document.getElementById('panel2-titel').textContent =
    gewaehltesEreignis.veranstaltung + ' · ' + gewaehltesEreignis.tk_kuerzel;

  const statusSel = document.getElementById('mgl-status-filter');
  if (autoDefault) {
    // Wurden bei diesem Ereignis bereits ein oder mehrere Tage storniert
    // (Storno Büro), braucht das am dringendsten eine AG-Rückmeldung -
    // dann direkt darauf filtern statt auf "Beantragt bei AG".
    statusSel.value = (gewaehltesEreignis.tage_storniert > 0) ? 'storno_buero' : 'beantragt_ag';
  }

  const statusFilter = statusSel.value;
  const d = await api('list_ag_rueckmeldung', { ereignis_id, status: statusFilter });
  if (!d.ok) { alert('Fehler beim Laden.'); return; }

  mitgliederDaten = d.data;
  renderMitglieder();
  setStep(2);
}

function renderMitglieder() {
  const tbody = document.getElementById('mgl-tbody');
  if (!mitgliederDaten.length) {
    tbody.innerHTML = '<tr><td colspan="7" style="text-align:center;padding:32px;color:var(--muted)">Keine offenen Anträge für dieses Ereignis.</td></tr>';
    return;
  }
  tbody.innerHTML = mitgliederDaten.map(m => {
    const tagZeilen = m.tage.map(t => {
      const tagFmt = t.tag ? t.tag.substring(8,10) + '.' + t.tag.substring(5,7) + '.' + t.tag.substring(0,4) : '–';
      return `<label style="display:flex;align-items:center;gap:6px;font-size:12px;cursor:pointer;padding:2px 0">
        <input type="checkbox" class="tag-cb" data-tag-id="${t.tag_id}" data-antrag-id="${m.id}" onchange="updateSel()">
        <span>${tagFmt}</span>
        <span style="font-size:11px;color:var(--muted)">${tagStatusLabel(t.tag_status)}</span>
      </label>`;
    }).join('');
    return `
    <tr id="row-${m.id}">
      <td class="cb-col" style="vertical-align:top;padding-top:10px">
        <input type="checkbox" class="mgl-cb" data-id="${m.id}"
          onchange="toggleAntragTage(this, '${m.id}')" title="Alle Tage dieses Antrags">
      </td>
      <td style="font-weight:600;vertical-align:top">${esc(m.vorname)} ${esc(m.nachname)}</td>
      <td style="font-size:12px;color:var(--muted);vertical-align:top">${esc(m.position||'–')}</td>
      <td class="hide-mobile" style="vertical-align:top">
        <div style="display:flex;flex-direction:column;gap:2px">${tagZeilen}</div>
      </td>
      <td class="hide-mobile" style="font-size:13px;vertical-align:top">${m.anzahl_tage||'–'}</td>
      <td class="hide-mobile" style="vertical-align:top">
        ${m.freistellungscode
          ? `<span style="display:inline-block;padding:2px 8px;border-radius:20px;font-size:11px;font-weight:700;background:#eef2ff;color:#3730a3">${esc(m.freistellungscode)}</span>`
          : '<span style="color:var(--muted)">–</span>'}
      </td>
      <td style="vertical-align:top">${statusBadge(m.antrag_status)}</td>
    </tr>`;
  }).join('');
  updateSel();
}

function tagStatusLabel(s) {
  const map = { beantragt_ag: 'beantragt', genehmigt: '✓', abgelehnt_ag: '✗', freigabe_buero: 'freigabe' };
  return map[s] || s;
}

function toggleAntragTage(cb, antragId) {
  document.querySelectorAll(`.tag-cb[data-antrag-id="${antragId}"]`)
    .forEach(tagCb => tagCb.checked = cb.checked);
  updateSel();
}

function alleUmschalten(checked) {
  document.querySelectorAll('.mgl-cb').forEach(cb => cb.checked = checked);
  document.querySelectorAll('.tag-cb').forEach(cb => cb.checked = checked);
  updateSel();
}

function updateSel() {
  const gewählt = document.querySelectorAll('.tag-cb:checked').length;
  const gesamt  = document.querySelectorAll('.tag-cb').length;
  document.getElementById('sel-count').textContent =
    gewählt + ' von ' + gesamt + ' Tagen ausgewählt';
  document.getElementById('cb-alle').indeterminate = gewählt > 0 && gewählt < gesamt;
  document.getElementById('cb-alle').checked = gewählt === gesamt && gesamt > 0;

  // Antrags-Checkbox: checked wenn alle Tage gewählt, indeterminate wenn teilweise
  mitgliederDaten.forEach(m => {
    const alle  = document.querySelectorAll(`.tag-cb[data-antrag-id="${m.id}"]`);
    const check = document.querySelectorAll(`.tag-cb[data-antrag-id="${m.id}"]:checked`);
    const mglCb = document.querySelector(`.mgl-cb[data-id="${m.id}"]`);
    if (!mglCb) return;
    mglCb.checked = check.length === alle.length && alle.length > 0;
    mglCb.indeterminate = check.length > 0 && check.length < alle.length;
    document.getElementById('row-' + m.id)?.classList.toggle('selected', check.length > 0);
  });
}

// ── Speichern ─────────────────────────────────────────────────
async function speichern() {
  const checked = [...document.querySelectorAll('.tag-cb:checked')].map(cb => cb.dataset.tagId);
  const status  = document.getElementById('ag-status').value;
  const notiz   = document.getElementById('ag-notiz').value.trim();
  const alertBox = document.getElementById('alert-save');

  if (!checked.length) {
    alertBox.textContent = 'Bitte mindestens einen Tag auswählen.';
    alertBox.style.display = 'block';
    return;
  }
  alertBox.style.display = 'none';

  const btn = document.getElementById('btn-speichern');
  btn.disabled = true; btn.textContent = 'Wird gespeichert …';

  const d = await api('save_ag_rueckmeldung', {
    tag_ids: checked,
    status,
    notiz,
  });

  btn.disabled = false;
  btn.textContent = 'Speichern & Mitglieder benachrichtigen';

  if (!d.ok) {
    alertBox.textContent = '⚠ ' + (d.error || 'Fehler beim Speichern.');
    alertBox.style.display = 'block';
    return;
  }

  const statusLabel = status === 'genehmigt' ? 'genehmigt' : 'als abgelehnt markiert';
  document.getElementById('erfolg-text').textContent =
    d.data.aktualisiert + ' Antrag/Anträge erfolgreich ' + statusLabel + '.';

  setStep(3);
}

function zurueck() { setStep(1); }

function neueRueckmeldung() {
  gewaehltesEreignis = null;
  mitgliederDaten    = [];
  document.getElementById('cb-alle').checked = false;
  document.getElementById('ag-notiz').value  = '';
  document.getElementById('ag-status').value = 'genehmigt';
  document.getElementById('alert-save').style.display = 'none';
  setStep(1);
}

ladeEreignisse();

// ══════════════════════════════════════════════════════════
// Modus-Umschaltung "Manuell" ⇄ "Per DOC-Upload"
// ══════════════════════════════════════════════════════════
function modusWechseln(modus) {
  document.getElementById('mode-manuell').style.display = (modus === 'manuell') ? '' : 'none';
  document.getElementById('mode-doc').style.display      = (modus === 'doc') ? '' : 'none';
  document.getElementById('mode-btn-manuell').classList.toggle('active', modus === 'manuell');
  document.getElementById('mode-btn-doc').classList.toggle('active', modus === 'doc');
}

// ══════════════════════════════════════════════════════════
// DOC-Upload (ehemals eigene Seite ag_doc_upload.php). CSRF und
// esc() werden bereits oben im "Manuell"-Teil deklariert, hier
// nicht erneut definieren.
// ══════════════════════════════════════════════════════════
let aktuellerToken = null;

async function apiFormData(action, fd) {
  fd.append('action', action);
  fd.append('csrf', CSRF);
  try {
    const r = await fetch('../api/buero.php', { method: 'POST', body: fd });
    return await r.json();
  } catch (err) {
    return { ok: false, error: 'Serverfehler oder keine Verbindung (' + err.message + ')' };
  }
}

function formatDatum2(iso) {
  if (!iso) return '–';
  const d = new Date(iso);
  return d.toLocaleDateString('de-DE', { day:'2-digit', month:'2-digit', year:'numeric' });
}
function zeigeAlert(id, text, ok) {
  const el = document.getElementById(id);
  el.className = 'alert ' + (ok ? 'alert-success' : 'alert-error');
  el.textContent = text;
  el.style.display = 'block';
}

async function datenAnalysieren() {
  const input = document.getElementById('docx-input');
  const alertBox = document.getElementById('upload-alert');
  alertBox.style.display = 'none';

  if (!input.files || !input.files.length) {
    zeigeAlert('upload-alert', 'Bitte zuerst eine .docx-Datei auswählen.', false);
    return;
  }

  const btn = document.getElementById('btn-analysieren');
  btn.disabled = true;
  btn.textContent = 'Wird analysiert …';

  const fd = new FormData();
  fd.append('docx', input.files[0]);
  const d = await apiFormData('ag_doc_analysieren', fd);

  btn.disabled = false;
  btn.textContent = 'Datei hochladen & analysieren';

  if (!d.ok) {
    zeigeAlert('upload-alert', '⚠ ' + (d.error || 'Fehler beim Analysieren.'), false);
    document.getElementById('card-vorschau').style.display = 'none';
    return;
  }

  aktuellerToken = d.data.token;
  manuelleEntscheidungen = {};
  vorschauRendern(d.data);
  document.getElementById('card-vorschau').style.display = 'block';
  document.getElementById('card-vorschau').scrollIntoView({ behavior: 'smooth' });
}

function vorschauRendern(d) {
  document.getElementById('summary-box').innerHTML = `
    <div class="summary-item" style="color:#1e7e34"><strong>${d.anzahl_genehmigt}</strong>werden genehmigt</div>
    <div class="summary-item" style="color:#c0392b"><strong>${d.anzahl_abgelehnt}</strong>werden abgelehnt (AG)</div>
    <div class="summary-item" style="color:#856404"><strong>${d.anzahl_uebersprungen}</strong>werden übersprungen</div>
  `;

  const tbody = document.getElementById('vorschau-tbody');
  if (!d.ergebnisse.length) {
    tbody.innerHTML = '<tr><td colspan="7" style="text-align:center;padding:24px;color:var(--muted)">Keine Einträge erkannt.</td></tr>';
    return;
  }

  tbody.innerHTML = d.ergebnisse.map(e => {
    const erkannt = e.gewaehrt && e.nicht_gewaehrt ? '⚠ beide'
                  : e.gewaehrt ? '✓ gewährt'
                  : e.nicht_gewaehrt ? '✗ nicht gewährt'
                  : '– keines –';
    const ereignisZeile = e.veranstaltung
      ? `${esc(e.veranstaltung)}<br><span style="color:var(--muted)">${formatDatum2(e.zeitraum_von)} – ${formatDatum2(e.zeitraum_bis)}</span>`
      : '–';

    // Manuelle Nachpflege nur anbieten, wenn automatisch nichts erkannt
    // wurde UND der Antrag noch im Status "Beantragt bei AG" ist (sonst
    // macht eine Statusänderung ohnehin keinen Sinn - siehe Hinweis).
    const kannManuell = !e.neuer_status && e.aktueller_status === 'beantragt_ag';

    let statusZelle;
    if (e.neuer_status === 'genehmigt') {
      statusZelle = '<span class="tag-ok">Genehmigt</span>';
    } else if (e.neuer_status === 'abgelehnt_ag') {
      statusZelle = '<span class="tag-abgelehnt">Abgelehnt (AG)</span>';
    } else if (kannManuell) {
      const name = `manuell_${e.antrag_id}`;
      statusZelle = `
        <div style="display:flex;flex-direction:column;gap:3px">
          <label style="display:flex;align-items:center;gap:5px;cursor:pointer">
            <input type="radio" name="${name}" value="" checked onchange="manuelleAuswahlGeaendert(${e.antrag_id}, this)"> Keine Änderung
          </label>
          <label style="display:flex;align-items:center;gap:5px;cursor:pointer;color:#1e7e34">
            <input type="radio" name="${name}" value="genehmigt" onchange="manuelleAuswahlGeaendert(${e.antrag_id}, this)"> Genehmigt
          </label>
          <label style="display:flex;align-items:center;gap:5px;cursor:pointer;color:#c0392b">
            <input type="radio" name="${name}" value="abgelehnt_ag" onchange="manuelleAuswahlGeaendert(${e.antrag_id}, this)"> Abgelehnt (AG)
          </label>
        </div>`;
    } else {
      statusZelle = '<span class="tag-skip">Keine Änderung</span>';
    }

    return `
      <tr>
        <td>${esc(e.name || ('Antrag #' + e.antrag_id))}</td>
        <td>${esc(e.airline || '–')}</td>
        <td>${ereignisZeile}</td>
        <td>${statusBadge(e.aktueller_status)}</td>
        <td>${erkannt}</td>
        <td>${statusZelle}</td>
        <td style="color:var(--muted);font-size:12px">${esc(e.hinweis || '')}</td>
      </tr>`;
  }).join('');
}

// Sammelt die manuellen Auswahlen (antrag_id -> 'genehmigt'|'abgelehnt_ag')
let manuelleEntscheidungen = {};
function manuelleAuswahlGeaendert(antragId, input) {
  if (input.value) {
    manuelleEntscheidungen[antragId] = input.value;
  } else {
    delete manuelleEntscheidungen[antragId];
  }
}

function vorschauVerwerfen() {
  aktuellerToken = null;
  manuelleEntscheidungen = {};
  document.getElementById('card-vorschau').style.display = 'none';
  document.getElementById('docx-input').value = '';
}

async function aenderungenUebernehmen() {
  if (!aktuellerToken) return;
  const anzahlManuell = Object.keys(manuelleEntscheidungen).length;
  const hinweisManuell = anzahlManuell
    ? `\n\nDavon ${anzahlManuell} manuell ausgewählt.`
    : '';
  if (!confirm(`Änderungen jetzt wirklich übernehmen? Betroffene Anträge werden auf „Genehmigt" bzw. „Abgelehnt (AG)" gesetzt, und die Mitglieder erhalten automatisch eine Ergebnis-Mail.${hinweisManuell}`)) return;

  const btn = document.getElementById('btn-uebernehmen');
  btn.disabled = true;
  btn.textContent = 'Wird übernommen …';

  const fd = new FormData();
  fd.append('token', aktuellerToken);
  fd.append('manuelle_entscheidungen', JSON.stringify(manuelleEntscheidungen));
  let d;
  try {
    d = await apiFormData('ag_doc_uebernehmen', fd);
  } finally {
    btn.disabled = false;
    btn.textContent = '✓ Änderungen übernehmen';
  }

  if (!d.ok) {
    zeigeAlert('upload-alert', '⚠ ' + (d.error || 'Fehler beim Übernehmen.'), false);
    return;
  }

  const manuellText = d.data.manuell_angewendet ? ` (davon ${d.data.manuell_angewendet} manuell)` : '';
  zeigeAlert('upload-alert', `✓ Übernommen: ${d.data.angewendet} Antrag/Anträge aktualisiert${manuellText}, ${d.data.uebersprungen} übersprungen.`, true);
  document.getElementById('card-vorschau').style.display = 'none';
  document.getElementById('docx-input').value = '';
  manuelleEntscheidungen = {};
  aktuellerToken = null;
  window.scrollTo({ top: 0, behavior: 'smooth' });
}

// Alte Lesezeichen auf ag_doc_upload.php landen jetzt hier mit ?modus=doc -
// direkt den passenden Modus öffnen.
<?php if (($_GET['modus'] ?? '') === 'doc'): ?>
modusWechseln('doc');
<?php endif; ?>
</script>

<?php panel_foot(); ?>
