<?php
// buero/antraege.php  –  Antragsübersicht mit Filtern
require_once __DIR__ . '/../_backend/bootstrap.php';
require_once __DIR__ . '/../_backend/panel.php';
send_security_headers();
$auth = require_login('buero');

$tks      = db()->query("SELECT id, kuerzel FROM tks ORDER BY kuerzel")->fetchAll();
$airlines = db()->query("SELECT DISTINCT airline FROM antraege WHERE airline IS NOT NULL AND airline <> '' ORDER BY airline")->fetchAll(PDO::FETCH_COLUMN);

panel_head('Anträge');
panel_sidebar('antraege', $auth['rolle'], $auth['user']);
panel_topbar('Antragsübersicht');
?>

<style>
/* ── Stornoliste (zweiter Reiter, siehe unten) ───────────────── */
.filter-bar { display:flex; gap:12px; flex-wrap:wrap; margin-bottom:24px; align-items:flex-end; }
.filter-bar .fg { display:flex; flex-direction:column; gap:4px; }
.filter-bar label { font-size:12px; font-weight:600; color:var(--muted,#7a8fa6); }
.filter-bar input, .filter-bar select {
  padding:8px 10px; border:1px solid var(--border,#dde2ea);
  border-radius:6px; font-size:14px; min-width:160px; }

.sl-card { border:1px solid #f5c6c6; border-radius:10px; margin-bottom:14px; overflow:hidden; }
.sl-card-head { display:flex; align-items:center; gap:14px; padding:13px 18px;
  background:#fdf6f6; cursor:pointer; user-select:none; transition:background .15s; }
.sl-card-head:hover { background:#fce8e8; }
.sl-badge { background:#c0392b; color:#fff; border-radius:6px;
  font-size:11px; font-weight:700; padding:3px 9px; white-space:nowrap; flex-shrink:0; }
.sl-meta { flex:1; min-width:0; }
.sl-meta .titel { font-size:14px; font-weight:700; color:#1c2b3a; }
.sl-meta .sub   { font-size:12px; color:var(--muted,#7a8fa6); margin-top:2px; }
.sl-chevron { font-size:20px; color:var(--muted,#7a8fa6); transition:transform .2s;
  flex-shrink:0; line-height:1; }
.sl-chevron.open { transform:rotate(90deg); }
.sl-body { display:none; border-top:1px solid #f5c6c6; }
.sl-body.open { display:block; }

.tage-chips { display:flex; flex-wrap:wrap; gap:6px; padding:14px 18px 0; }
.tage-chip  { background:#fde8e8; color:#c0392b; border:1px solid #f5c6c6;
  border-radius:20px; font-size:12px; font-weight:700; padding:3px 11px; }

.grund-box { margin:12px 18px; padding:10px 14px; background:#fffbea;
  border:1px solid #ffe58f; border-radius:8px; font-size:13px; color:#7d6608; }
.grund-box .gl { font-size:11px; font-weight:700; text-transform:uppercase;
  letter-spacing:.04em; color:#856404; display:block; margin-bottom:3px; }

.sl-sub-table { width:100%; border-collapse:collapse; font-size:13px; }
.sl-sub-table th { padding:7px 18px; background:#f8f9fb; font-size:11px; font-weight:700;
  text-transform:uppercase; letter-spacing:.04em; color:var(--muted,#7a8fa6);
  text-align:left; border-top:1px solid var(--border,#dde2ea);
  border-bottom:1px solid var(--border,#dde2ea); }
.sl-sub-table td { padding:8px 18px; border-bottom:1px solid var(--border,#dde2ea); }
.sl-sub-table tr:last-child td { border-bottom:none; }
.sl-sub-table tr:hover td { background:#fafafa; }

.empty-hint { text-align:center; padding:48px 24px; color:var(--muted,#7a8fa6); }
.empty-hint .ico { font-size:36px; margin-bottom:10px; }

/* ── Tab-Umschaltung ──────────────────────────────────────────── */
.uebersicht-tabs { display:flex; gap:8px; margin-bottom:18px; border-bottom:2px solid var(--border,#dde2ea); }
.uebersicht-tab { padding:10px 18px; font-size:14px; font-weight:600; color:var(--muted,#7a8fa6);
  background:none; border:none; border-bottom:3px solid transparent; cursor:pointer; margin-bottom:-2px; }
.uebersicht-tab.active { color:var(--navy2,#1a3a5c); border-bottom-color:var(--navy2,#1a3a5c); }
.uw-card { border:1px solid #c7d2fe; border-radius:10px; margin-bottom:14px; overflow:hidden; }
.uw-card-head { display:flex; align-items:center; gap:14px; padding:13px 18px;
  background:#f5f7ff; cursor:pointer; user-select:none; transition:background .15s; }
.uw-card-head:hover { background:#e8ecff; }
.uw-badge-from { background:#fde8e8; color:#c0392b; border-radius:6px;
  font-size:11px; font-weight:700; padding:3px 9px; white-space:nowrap; flex-shrink:0; }
.uw-badge-to   { background:#e6f4ea; color:#1e7e34; border-radius:6px;
  font-size:11px; font-weight:700; padding:3px 9px; white-space:nowrap; flex-shrink:0; }
.uw-arrow { font-size:16px; color:var(--muted,#7a8fa6); flex-shrink:0; }
.uw-meta { flex:1; min-width:0; }
.uw-meta .titel { font-size:14px; font-weight:700; color:#1c2b3a; }
.uw-meta .sub   { font-size:12px; color:var(--muted,#7a8fa6); margin-top:2px; }
.uw-chevron { font-size:20px; color:var(--muted,#7a8fa6); transition:transform .2s;
  flex-shrink:0; line-height:1; }
.uw-chevron.open { transform:rotate(90deg); }
.uw-body { display:none; border-top:1px solid #c7d2fe; }
.uw-body.open { display:block; }
.uw-sub-table { width:100%; border-collapse:collapse; font-size:13px; }
.uw-sub-table th { padding:7px 18px; background:#f8f9fb; font-size:11px; font-weight:700;
  text-transform:uppercase; letter-spacing:.04em; color:var(--muted,#7a8fa6);
  text-align:left; border-top:1px solid var(--border,#dde2ea);
  border-bottom:1px solid var(--border,#dde2ea); }
.uw-sub-table td { padding:8px 18px; border-bottom:1px solid var(--border,#dde2ea); }
.uw-sub-table tr:last-child td { border-bottom:none; }
.uw-sub-table tr:hover td { background:#fafafa; }
.typ-pill { display:inline-block; padding:2px 10px; border-radius:20px; font-size:11px;
  font-weight:700; background:#eef2ff; color:#3730a3; }
</style>

<div class="uebersicht-tabs">
  <button class="uebersicht-tab active" id="tab-btn-alle" onclick="tabWechseln('alle')">Alle Anträge</button>
  <button class="uebersicht-tab" id="tab-btn-storno" onclick="tabWechseln('storno')">Stornierte Anträge (Protokoll)</button>
  <button class="uebersicht-tab" id="tab-btn-uwl" onclick="tabWechseln('uwl')">Umgewidmete Anträge (Protokoll)</button>
  <button class="uebersicht-tab" id="tab-btn-ml" onclick="tabWechseln('ml')">Gelöschte Anträge (Mitglieder)</button>
</div>

<div id="tab-alle">

<!-- Filter -->
<div class="card" style="margin-bottom:18px">
  <div class="card-body" style="padding:16px 22px">
    <div style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end">
      <div class="fg" style="flex:0 0 150px">
        <label>Status</label>
        <select id="f-status">
          <option value="">Alle</option>
          <option value="ausstehend">Ausstehend</option>
          <option value="freigabe_buero">Freigabe Büro erl.</option>
          <option value="beantragt_ag">Beantragt bei AG</option>
          <option value="genehmigt">Genehmigt durch AG</option>
          <option value="abgelehnt">Abgelehnt</option>
          <option value="abgelehnt_ag">Abgelehnt (AG)</option>
          <option value="storno_buero">Storno Büro</option>
          <option value="storno_bestaetigt_ag">Storno bestätigt (AG)</option>
        </select>
      </div>
      <div class="fg" style="flex:0 0 160px">
        <label>TK</label>
        <select id="f-tk">
          <option value="">Alle TKs</option>
          <?php foreach ($tks as $tk): ?>
          <option value="<?= $tk['id'] ?>"><?= htmlspecialchars($tk['kuerzel']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="fg" style="flex:0 0 160px">
        <label>Airline</label>
        <select id="f-airline">
          <option value="">Alle Airlines</option>
          <?php foreach ($airlines as $airline): ?>
          <option value="<?= htmlspecialchars($airline) ?>"><?= htmlspecialchars($airline) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="fg" style="flex:0 0 160px">
        <label>Freistellungscode</label>
        <select id="f-fscode">
          <option value="">Alle</option>
        </select>
      </div>
    </div>
    <div style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;margin-top:12px;padding-top:12px;border-top:1px solid var(--border,#dde2ea)">
      <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--muted,#7a8fa6);align-self:center;white-space:nowrap">Freistellungszeitraum</div>
      <div class="fg" style="flex:0 0 160px">
        <label>Von</label>
        <input type="date" id="f-freistellung-von">
      </div>
      <div class="fg" style="flex:0 0 160px">
        <label>Bis</label>
        <input type="date" id="f-freistellung-bis">
      </div>
      <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--muted,#7a8fa6);align-self:center;white-space:nowrap;margin-left:8px">Antragsdatum</div>
      <div class="fg" style="flex:0 0 160px">
        <label>Von</label>
        <input type="date" id="f-antrag-von">
      </div>
      <div class="fg" style="flex:0 0 160px">
        <label>Bis</label>
        <input type="date" id="f-antrag-bis">
      </div>
      <button class="btn btn-primary" onclick="loadList()">Filtern</button>
      <button class="btn btn-outline" onclick="resetFilter()">Zurücksetzen</button>
      <div class="fg" style="flex:1;min-width:180px;margin-left:auto">
        <label>Name suchen</label>
        <input type="text" id="f-name" placeholder="Nachname oder Vorname …"
          oninput="filterNachName()"
          style="width:100%;padding:7px 10px;border:1px solid var(--border,#dde2ea);border-radius:6px;font-size:13px">
      </div>
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
          <th>Veranstaltung</th><th>Bezeichnung</th><th>Zeitraum</th><th>Freistellungscode</th><th>Eingereicht</th><th>Status</th><th></th><th></th>
        </tr></thead>
        <tbody id="tbody"><tr><td colspan="13" style="text-align:center;padding:24px;color:var(--muted)">Wird geladen …</td></tr></tbody>
      </table>
    </div>
  </div>
</div>
</div><!-- /tab-alle -->

<div id="tab-storno" style="display:none">
  <div class="filter-bar">
    <div class="fg">
      <label>Airline</label>
      <select id="sl-f-airline"><option value="">Alle Airlines</option></select>
    </div>
    <div class="fg">
      <label>TK</label>
      <select id="sl-f-tk"><option value="">Alle TKs</option></select>
    </div>
    <div class="fg">
      <label>Stornodatum von</label>
      <input type="date" id="sl-f-von">
    </div>
    <div class="fg">
      <label>Stornodatum bis</label>
      <input type="date" id="sl-f-bis">
    </div>
    <div class="fg">
      <label>Freistellungszeitraum von</label>
      <input type="date" id="sl-f-freistellung-von">
    </div>
    <div class="fg">
      <label>Freistellungszeitraum bis</label>
      <input type="date" id="sl-f-freistellung-bis">
    </div>
    <div class="fg">
      <label>Storniert von (User)</label>
      <select id="sl-f-user" style="min-width:180px">
        <option value="">Alle Benutzer</option>
      </select>
    </div>
    <div class="fg">
      <label>&nbsp;</label>
      <button class="btn btn-outline" onclick="slLaden()">Suchen</button>
    </div>
  </div>

  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px">
    <div id="sl-treffer-info" style="font-size:13px;color:var(--muted)"></div>
    <button class="btn btn-outline btn-sm" onclick="slExportCSV()">&#11015; CSV exportieren</button>
  </div>

  <div id="sl-liste-wrap">
    <div class="empty-hint"><div class="ico">&#9203;</div>Wird geladen &#8230;</div>
  </div>
</div><!-- /tab-storno -->

<div id="tab-uwl" style="display:none">
  <div class="filter-bar">
    <div class="fg">
      <label>Airline</label>
      <select id="uwl-f-airline"><option value="">Alle Airlines</option></select>
    </div>
    <div class="fg">
      <label>TK</label>
      <select id="uwl-f-tk"><option value="">Alle TKs</option></select>
    </div>
    <div class="fg">
      <label>Umwidmungsdatum von</label>
      <input type="date" id="uwl-f-von">
    </div>
    <div class="fg">
      <label>Umwidmungsdatum bis</label>
      <input type="date" id="uwl-f-bis">
    </div>
    <div class="fg">
      <label>Freistellungszeitraum von</label>
      <input type="date" id="uwl-f-freistellung-von">
    </div>
    <div class="fg">
      <label>Freistellungszeitraum bis</label>
      <input type="date" id="uwl-f-freistellung-bis">
    </div>
    <div class="fg">
      <label>Zieltyp</label>
      <select id="uwl-f-typ">
        <option value="">Alle Typen</option>
        <option value="verhandlung">Verhandlung</option>
        <option value="tk_sitzung">TK-Sitzung</option>
        <option value="sonstiges">Sonstiges</option>
      </select>
    </div>
    <div class="fg">
      <label>Umgewidmet von (User)</label>
      <select id="uwl-f-user" style="min-width:180px">
        <option value="">Alle Benutzer</option>
      </select>
    </div>
    <div class="fg">
      <label>&nbsp;</label>
      <button class="btn btn-outline" onclick="uwlLaden()">Suchen</button>
    </div>
  </div>

  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px">
    <div id="uwl-treffer-info" style="font-size:13px;color:var(--muted)"></div>
    <button class="btn btn-outline btn-sm" onclick="uwlExportCSV()">&#11015; CSV exportieren</button>
  </div>

  <div id="uwl-liste-wrap">
    <div class="empty-hint"><div class="ico">&#9203;</div>Wird geladen &#8230;</div>
  </div>
</div><!-- /tab-uwl -->

<div id="tab-ml" style="display:none">
  <div class="filter-bar">
    <div class="fg">
      <label>Airline</label>
      <select id="ml-f-airline"><option value="">Alle Airlines</option></select>
    </div>
    <div class="fg">
      <label>TK</label>
      <select id="ml-f-tk"><option value="">Alle TKs</option></select>
    </div>
    <div class="fg">
      <label>Löschdatum von</label>
      <input type="date" id="ml-f-von">
    </div>
    <div class="fg">
      <label>Löschdatum bis</label>
      <input type="date" id="ml-f-bis">
    </div>
    <div class="fg">
      <label>Freistellungszeitraum von</label>
      <input type="date" id="ml-f-freistellung-von">
    </div>
    <div class="fg">
      <label>Freistellungszeitraum bis</label>
      <input type="date" id="ml-f-freistellung-bis">
    </div>
    <div class="fg">
      <label>&nbsp;</label>
      <button class="btn btn-outline" onclick="mlLaden()">Suchen</button>
    </div>
  </div>

  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px">
    <div id="ml-treffer-info" style="font-size:13px;color:var(--muted)"></div>
    <button class="btn btn-outline btn-sm" onclick="mlExportCSV()">&#11015; CSV exportieren</button>
  </div>

  <div id="ml-liste-wrap">
    <div class="empty-hint"><div class="ico">&#9203;</div>Wird geladen &#8230;</div>
  </div>
</div><!-- /tab-ml -->

<script src="../assets/status.js"></script>
<script>
const CSRF = '<?= csrf_token() ?>';

function esc(s) {
  return String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

async function api(action, extra = {}) {
  const fd = new FormData();
  fd.append('action', action);
  fd.append('csrf', CSRF);
  for (const [k, v] of Object.entries(extra)) {
    if (Array.isArray(v)) v.forEach(x => fd.append(k + '[]', x));
    else fd.append(k, v);
  }
  // Robust gegen Server-Fehler/kaputtes JSON: darf NIE eine Exception werfen,
  // sonst bleibt der aufrufende Bereich (z.B. "Wird geladen …") für den
  // Nutzer sichtbar hängen, weil der Code danach nicht mehr ausgeführt wird.
  try {
    const r = await fetch('../api/buero.php', { method: 'POST', body: fd });
    return await r.json();
  } catch (err) {
    return { ok: false, error: 'Serverfehler oder keine Verbindung (' + err.message + ')' };
  }
}

// badge() nutzt die gemeinsame, einheitliche Liste aus assets/status.js
// (statusBadge()) - keine eigene, hier zuvor unvollständige/abweichende
// Label-Liste mehr (fehlten z.B. "Freigabe Büro", "Beantragt bei AG").
function badge(status) { return statusBadge(status); }

function formatDatum(iso) {
  if (!iso || iso.startsWith('0000')) return '–';
  return iso.substring(0,10).split('-').reverse().join('.');
}

async function deleteAntrag(id) {
  if (!confirm('Antrag #' + id + ' wirklich löschen?\n\nDieser Vorgang kann nicht rückgängig gemacht werden.')) return;
  const fd = new FormData();
  fd.append('action', 'delete_antrag');
  fd.append('csrf', CSRF);
  fd.append('id', id);
  const d = await fetch('../api/buero.php', { method: 'POST', body: fd }).then(r => r.json());
  if (d.ok) {
    loadList();
  } else {
    alert('Fehler: ' + d.error);
  }
}

async function loadList() {
  const tbody = document.getElementById('tbody');
  tbody.innerHTML = '<tr><td colspan="13" style="text-align:center;padding:24px;color:var(--muted)">Wird geladen …</td></tr>';

  const fd = new FormData();
  fd.append('action', 'list_antraege');
  fd.append('csrf', CSRF);
  fd.append('status',           document.getElementById('f-status').value);
  fd.append('tk_id',            document.getElementById('f-tk').value);
  fd.append('airline',          document.getElementById('f-airline').value);
  fd.append('freistellungscode', document.getElementById('f-fscode').value);
  fd.append('freistellung_von', document.getElementById('f-freistellung-von').value);
  fd.append('freistellung_bis', document.getElementById('f-freistellung-bis').value);
  fd.append('antrag_von',       document.getElementById('f-antrag-von').value);
  fd.append('antrag_bis',       document.getElementById('f-antrag-bis').value);

  const d = await fetch('../api/buero.php', { method:'POST', body:fd }).then(r=>r.json());
  document.getElementById('count').textContent = d.data ? d.data.length : 0;

  if (!d.ok || !d.data.length) {
    tbody.innerHTML = '<tr><td colspan="13" style="text-align:center;padding:32px;color:var(--muted)">Keine Anträge gefunden.</td></tr>';
    return;
  }
  tbody.innerHTML = d.data.map(a => `
    <tr>
      <td class="mono">${a.id}</td>
      <td>${a.vorname} ${a.nachname}</td>
      <td class="mono" style="font-size:12px">${a.tk_kuerzel}</td>
      <td>${a.airline}</td>
      <td><span style="display:inline-block;padding:3px 9px;border-radius:20px;font-size:12px;font-weight:700;background:#eef2ff;color:#3730a3">${a.position}</span></td>
      <td style="font-size:13px">${a.veranstaltung || '–'}</td>
      <td style="font-size:13px;color:var(--muted)">${a.veranstaltung === 'sonstige' ? (a.ereignis_bezeichnung || '–') : '–'}</td>
      <td class="td-nowrap mono" style="font-size:12px">${formatDatum(a.zeitraum_von)}${a.zeitraum_von !== a.zeitraum_bis ? ' –<br>' + formatDatum(a.zeitraum_bis) : ''}</td>
      <td class="mono" style="font-size:12px">${a.freistellungscode ? `<span style="display:inline-block;padding:2px 8px;border-radius:6px;background:#f0f2f5;font-weight:700">${esc(a.freistellungscode)}</span>` : '<span style="color:var(--muted)">–</span>'}</td>
      <td class="td-nowrap" style="font-size:12px;color:var(--muted)">${a.erstellt_am ? a.erstellt_am.substring(0,16) : ''}</td>
      <td>${badge(a.status)}${a.freigabe_erteilt_von ? `<div style="font-size:11px;color:var(--muted);margin-top:3px">Freigabe: ${a.freigabe_erteilt_von}</div>` : ''}</td>
      <td><a href="antrag.php?id=${a.id}" class="btn btn-outline btn-sm">Bearbeiten</a></td>
      <td><button class="btn btn-danger btn-sm" onclick="deleteAntrag(${a.id})">✕</button></td>
    </tr>`).join('');
  filterNachName(); // Namensfilter nach Neurender anwenden
}

function resetFilter() {
  document.getElementById('f-status').value = '';
  document.getElementById('f-tk').value = '';
  document.getElementById('f-airline').value = '';
  document.getElementById('f-fscode').value = '';
  ['f-freistellung-von','f-freistellung-bis','f-antrag-von','f-antrag-bis'].forEach(id => {
    const el = document.getElementById(id);
    el.value = '';
    el.min   = '';
    el.max   = '';
  });
  const fn = document.getElementById('f-name');
  if (fn) fn.value = '';
  filterNachName();
  loadList();
}

// Live-Namensfilter: blendet Tabellenzeilen clientseitig aus
function filterNachName() {
  const q = (document.getElementById('f-name')?.value ?? '').toLowerCase().trim();
  const rows = document.querySelectorAll('#tbody tr');
  let sichtbar = 0;
  rows.forEach(row => {
    if (!q) { row.style.display = ''; sichtbar++; return; }
    const zeigen = row.textContent.toLowerCase().includes(q);
    row.style.display = zeigen ? '' : 'none';
    if (zeigen) sichtbar++;
  });
}

async function loadFreistellungscodesAlle() {
  const fd = new FormData();
  fd.append('action', 'list_freistellungscodes_alle'); fd.append('csrf', CSRF);
  const d = await fetch('../api/buero.php', { method:'POST', body:fd }).then(r=>r.json());
  if (!d.ok) return;
  const sel = document.getElementById('f-fscode');
  sel.innerHTML = '<option value="">Alle</option>' +
    d.data.codes.map(c => `<option value="${c}">${c}</option>`).join('');
}
loadFreistellungscodesAlle();

loadList();

// "Bis"-Felder: kein Datum vor "Von" erlauben
function linkDateRange(vonId, bisId) {
  const von = document.getElementById(vonId);
  const bis = document.getElementById(bisId);
  von.addEventListener('change', function () {
    bis.min = this.value || '';
    if (bis.value && bis.value < this.value) bis.value = this.value;
  });
  bis.addEventListener('change', function () {
    von.max = this.value || '';
    if (von.value && von.value > this.value) von.value = this.value;
  });
}
linkDateRange('f-freistellung-von', 'f-freistellung-bis');
linkDateRange('f-antrag-von', 'f-antrag-bis');

// ══════════════════════════════════════════════════════════
// Tab-Umschaltung "Alle Anträge" ⇄ "Stornierte Anträge"
// ══════════════════════════════════════════════════════════
let slInitialisiert = false;
let uwlInitialisiert = false;
let mlInitialisiert = false;

function tabWechseln(tab) {
  document.getElementById('tab-alle').style.display          = (tab === 'alle') ? '' : 'none';
  document.getElementById('tab-storno').style.display         = (tab === 'storno') ? '' : 'none';
  document.getElementById('tab-uwl').style.display            = (tab === 'uwl') ? '' : 'none';
  document.getElementById('tab-ml').style.display              = (tab === 'ml') ? '' : 'none';
  document.getElementById('tab-btn-alle').classList.toggle('active', tab === 'alle');
  document.getElementById('tab-btn-storno').classList.toggle('active', tab === 'storno');
  document.getElementById('tab-btn-uwl').classList.toggle('active', tab === 'uwl');
  document.getElementById('tab-btn-ml').classList.toggle('active', tab === 'ml');

  // Protokolle erst beim ersten Öffnen laden (nicht beim initialen
  // Seitenaufruf mitladen, spart unnötige Requests).
  if (tab === 'storno' && !slInitialisiert) {
    slInitialisiert = true;
    slInit();
  }
  if (tab === 'uwl' && !uwlInitialisiert) {
    uwlInitialisiert = true;
    uwlInit();
  }
  if (tab === 'ml' && !mlInitialisiert) {
    mlInitialisiert = true;
    mlInit();
  }
}

// ══════════════════════════════════════════════════════════
// Stornoliste (ehemals eigene Seite stornoliste.php, jetzt als
// zweiter Reiter hier integriert). Eigene Hilfsfunktionen mit
// "sl"-Präfix, um Namens-/ID-Kollisionen mit der "Alle Anträge"-
// Ansicht oben zu vermeiden.
// ══════════════════════════════════════════════════════════
let slRohdaten = [];

async function slApi(action, extra = {}) {
  const fd = new FormData();
  fd.append('action', action);
  fd.append('csrf', CSRF);
  for (const [k, v] of Object.entries(extra)) {
    if (Array.isArray(v)) v.forEach(x => fd.append(k + '[]', x));
    else fd.append(k, v);
  }
  try {
    const r = await fetch('../api/buero.php', { method: 'POST', body: fd });
    return await r.json();
  } catch (err) {
    return { ok: false, error: 'Serverfehler oder keine Verbindung (' + err.message + ')' };
  }
}

function slFmt(iso) {
  if (!iso) return '&ndash;';
  const [y,m,d] = iso.split('-');
  return d + '.' + m + '.' + y;
}
function slFmtDt(iso) {
  if (!iso) return '&ndash;';
  return new Date(iso).toLocaleString('de-DE',
    { day:'2-digit', month:'2-digit', year:'numeric', hour:'2-digit', minute:'2-digit' });
}
function slFmtZR(von, bis) {
  if (!von) return '&ndash;';
  return von === bis ? slFmt(von) : slFmt(von) + ' &ndash; ' + slFmt(bis);
}
function slEsc(s) {
  return String(s||'')
    .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

async function slInit() {
  const [dTks, dAirlines] = await Promise.all([slApi('list_tks'), slApi('list_finance_airlines')]);
  if (dTks.ok) {
    const sel = document.getElementById('sl-f-tk');
    dTks.data.forEach(tk => {
      const o = document.createElement('option');
      o.value = tk.id;
      o.textContent = tk.kuerzel + ' \u2013 ' + tk.bezeichnung;
      sel.appendChild(o);
    });
  }
  if (dAirlines.ok) {
    const sel = document.getElementById('sl-f-airline');
    dAirlines.data.airlines.forEach(a => {
      const o = document.createElement('option');
      o.value = a; o.textContent = a;
      sel.appendChild(o);
    });
  }
  // Bearbeiter-Dropdown wird in slLaden() aus den tatsächlich
  // vorhandenen Storno-Einträgen befüllt (siehe dort) - unabhängig
  // von api/admin.php, dessen list_users-Verhalten nicht geprüft war.
  await slLaden();
}

async function slLaden() {
  const wrap = document.getElementById('sl-liste-wrap');
  wrap.innerHTML = '<div class="empty-hint"><div class="ico">&#9203;</div>Wird geladen &hellip;</div>';
  document.getElementById('sl-treffer-info').textContent = '';

  const userSel = document.getElementById('sl-f-user');
  const bisherGewaehlterUser = userSel.value;

  const d = await slApi('list_storno_log', {
    tk_id:            document.getElementById('sl-f-tk').value,
    airline:          document.getElementById('sl-f-airline').value,
    von:              document.getElementById('sl-f-von').value,
    bis:              document.getElementById('sl-f-bis').value,
    freistellung_von: document.getElementById('sl-f-freistellung-von').value,
    freistellung_bis: document.getElementById('sl-f-freistellung-bis').value,
    storniert_von:    bisherGewaehlterUser.trim(),
  });

  if (!d.ok) {
    wrap.innerHTML = '<div class="empty-hint"><div class="ico">&#9888;</div>Fehler beim Laden.</div>';
    return;
  }

  slRohdaten = d.data;

  // Bearbeiter-Dropdown aus den geladenen Daten auffüllen (nur beim
  // ersten, ungefilterten Laden - danach bleibt die Liste stabil,
  // damit sie beim Filtern nicht auf die gerade sichtbare Auswahl
  // schrumpft).
  if (!bisherGewaehlterUser && userSel.options.length <= 1) {
    const bearbeiter = [...new Set(d.data.map(r => r.storniert_von).filter(Boolean))].sort();
    bearbeiter.forEach(u => {
      const o = document.createElement('option');
      o.value = u; o.textContent = u;
      userSel.appendChild(o);
    });
  }

  if (!d.data.length) {
    wrap.innerHTML = '<div class="empty-hint"><div class="ico">&#10003;</div>Keine Storno-Eintr&auml;ge gefunden.</div>';
    return;
  }

  // Nach storno_aktion_id gruppieren
  const aktionenMap = new Map();
  d.data.forEach(row => {
    const key = row.storno_aktion_id || ('fallback-' + row.id);
    if (!aktionenMap.has(key)) {
      aktionenMap.set(key, { meta: row, tage: new Set(), mitglieder: new Map() });
    }
    const ak = aktionenMap.get(key);
    ak.tage.add(row.betroffene_tage);
    (row.betroffene_antraege_details || []).forEach(m => {
      if (!ak.mitglieder.has(m.antrag_id)) ak.mitglieder.set(m.antrag_id, m);
    });
  });

  document.getElementById('sl-treffer-info').textContent =
    aktionenMap.size + ' Storno-Aktion(en) gefunden';

  let html = '';
  let idx  = 0;
  aktionenMap.forEach((ak) => {
    const id   = 'sl-card-' + (idx++);
    const meta = ak.meta;
    const tage = [...ak.tage].sort();
    const mgl  = [...ak.mitglieder.values()];

    const chips = tage.map(t => '<span class="tage-chip">' + slFmt(t) + '</span>').join('');

    const mglRows = mgl.map(m =>
      '<tr>' +
      '<td>' + slEsc(m.vorname) + ' ' + slEsc(m.nachname) + '</td>' +
      '<td style="color:var(--muted)">' + slEsc(m.airline||'&ndash;') + '</td>' +
      '<td><span style="display:inline-block;padding:2px 8px;border-radius:20px;font-size:11px;font-weight:700;background:#eef2ff;color:#3730a3">' + slEsc(m.position||'&ndash;') + '</span></td>' +
      '<td class="mono" style="font-size:12px">' + slEsc(m.tk_kuerzel||'&ndash;') + '</td>' +
      '<td style="color:var(--muted);font-size:12px">#' + m.antrag_id + '</td>' +
      '</tr>'
    ).join('');

    html +=
      '<div class="sl-card">' +
        '<div class="sl-card-head" onclick="slToggle(\'' + id + '\')">' +
          '<span class="sl-badge">Storno</span>' +
          '<div class="sl-meta">' +
            '<div class="titel">' + slEsc(meta.veranstaltung) + ' &nbsp;&middot;&nbsp; <span style="font-weight:400">' + slEsc(meta.tk_name) + '</span></div>' +
            '<div class="sub">' +
              'Ereignis: ' + slFmtZR(meta.ereignis_von, meta.ereignis_bis) +
              ' &nbsp;&middot;&nbsp; <strong>' + tage.length + '</strong> Tag(e)' +
              ' &nbsp;&middot;&nbsp; <strong>' + mgl.length + '</strong> Mitglied(er)' +
              ' &nbsp;&middot;&nbsp; von <strong>' + slEsc(meta.storniert_von) + '</strong>' +
              ' am ' + slFmtDt(meta.storniert_am) +
            '</div>' +
          '</div>' +
          '<span class="sl-chevron" id="chev-' + id + '">&rsaquo;</span>' +
        '</div>' +
        '<div class="sl-body" id="body-' + id + '">' +
          '<div class="tage-chips">' + chips + '</div>' +
          '<div class="grund-box"><span class="gl">Stornierungsgrund</span>' +
            (meta.grund ? slEsc(meta.grund) : '<em style="opacity:.6">Kein Grund angegeben</em>') +
          '</div>' +
          '<table class="sl-sub-table"><thead><tr>' +
            '<th>Name</th><th>Airline</th><th>Position</th><th>TK</th><th>Antrags-ID</th>' +
          '</tr></thead><tbody>' +
            (mglRows || '<tr><td colspan="5" style="padding:12px 18px;color:var(--muted)">Keine Mitglieder-Details.</td></tr>') +
          '</tbody></table>' +
        '</div>' +
      '</div>';
  });

  wrap.innerHTML = html;
}

function slToggle(id) {
  document.getElementById('body-' + id).classList.toggle('open');
  document.getElementById('chev-' + id).classList.toggle('open');
}

function slExportCSV() {
  if (!slRohdaten.length) return;
  const header = ['Aktion-ID','Storniert von','Stornozeitpunkt','Grund',
                   'TK','Airline','Veranstaltung','Ereignis von','Ereignis bis',
                   'Stornierte Freistellungstage',
                   'Antrags-ID','Name','Position'];

  // Nach (Aktion, Mitglied) gruppieren, alle stornierten Tage je Mitglied
  // in einem Feld zusammenfassen - statt einer Zeile pro Einzeltag.
  const gruppen = new Map();
  slRohdaten.forEach(row => {
    const details = row.betroffene_antraege_details || [{}];
    details.forEach(m => {
      const key = (row.storno_aktion_id || row.id) + '|' + (m.antrag_id || '');
      if (!gruppen.has(key)) {
        gruppen.set(key, { meta: row, mitglied: m, tage: [] });
      }
      gruppen.get(key).tage.push(row.betroffene_tage);
    });
  });

  const rows = [...gruppen.values()].map(g => {
    const row = g.meta, m = g.mitglied;
    const tageStr = [...new Set(g.tage)].sort().map(slFmt).join('; ');
    return [
      row.storno_aktion_id||'', row.storniert_von, slFmtDt(row.storniert_am),
      row.grund||'',
      row.tk_name||'', row.airline||'', row.veranstaltung||'',
      slFmt(row.ereignis_von||''), slFmt(row.ereignis_bis||''),
      tageStr,
      m.antrag_id||'',
      ((m.vorname||'')+' '+(m.nachname||'')).trim(),
      m.position||''
    ].map(v => '"' + String(v).replace(/"/g,'""') + '"');
  });

  const csv  = [header.map(h => '"'+h+'"').join(';'), ...rows.map(r => r.join(';'))].join('\n');
  const blob = new Blob(['\uFEFF'+csv], {type:'text/csv;charset=utf-8'});
  const url  = URL.createObjectURL(blob);
  const a    = document.createElement('a');
  a.href     = url;
  a.download = 'stornoliste_' + new Date().toISOString().slice(0,10) + '.csv';
  a.click();
  URL.revokeObjectURL(url);
}

// "Bis"-Felder auch im Storno-Reiter verknüpfen
linkDateRange('sl-f-von', 'sl-f-bis');
linkDateRange('sl-f-freistellung-von', 'sl-f-freistellung-bis');

// ══════════════════════════════════════════════════════════
// Umwidmungs-Protokoll (ehemals eigene Seite umwidmungsliste.php).
// Eigene Hilfsfunktionen mit "uwl"-Präfix, um Kollisionen mit den
// obigen Storno-/Umwidmen-Aktions-Funktionen zu vermeiden.
// ══════════════════════════════════════════════════════════
let uwlRohdaten = [];

const UWL_TYP_LABELS = {
  tk_sitzung:      'TK-Sitzung',
  verhandlung:     'Verhandlung',
  schlichtung:     'Schlichtung',
  einigungsstelle: 'Einigungsstelle',
  vorbesprechung:  'Vorbesprechung',
  sonstiges:       'Sonstiges',
};

function uwlFmt(iso) {
  if (!iso) return '&ndash;';
  const [y,m,d] = iso.split('-');
  return d + '.' + m + '.' + y;
}
function uwlFmtDt(iso) {
  if (!iso) return '&ndash;';
  return new Date(iso).toLocaleString('de-DE',
    { day:'2-digit', month:'2-digit', year:'numeric', hour:'2-digit', minute:'2-digit' });
}
function uwlFmtZR(von, bis) {
  if (!von) return '&ndash;';
  return von === bis ? uwlFmt(von) : uwlFmt(von) + ' &ndash; ' + uwlFmt(bis);
}

async function uwlInit() {
  const [dTks, dAirlines] = await Promise.all([api('list_tks'), api('list_finance_airlines')]);
  if (dTks.ok) {
    const sel = document.getElementById('uwl-f-tk');
    dTks.data.forEach(tk => {
      const o = document.createElement('option');
      o.value = tk.id;
      o.textContent = tk.kuerzel + ' \u2013 ' + tk.bezeichnung;
      sel.appendChild(o);
    });
  }
  if (dAirlines.ok) {
    const sel = document.getElementById('uwl-f-airline');
    dAirlines.data.airlines.forEach(a => {
      const o = document.createElement('option');
      o.value = a; o.textContent = a;
      sel.appendChild(o);
    });
  }
  // Bearbeiter-Dropdown wird in uwlLaden() aus den tatsächlich
  // vorhandenen Umwidmungs-Einträgen befüllt - unabhängig von
  // api/admin.php.
  await uwlLaden();
}

async function uwlLaden() {
  const wrap = document.getElementById('uwl-liste-wrap');
  wrap.innerHTML = '<div class="empty-hint"><div class="ico">&#9203;</div>Wird geladen &hellip;</div>';
  document.getElementById('uwl-treffer-info').textContent = '';

  const userSel = document.getElementById('uwl-f-user');
  const bisherGewaehlterUser = userSel.value;

  const d = await api('list_umwidmung_log', {
    tk_id:            document.getElementById('uwl-f-tk').value,
    airline:          document.getElementById('uwl-f-airline').value,
    von:              document.getElementById('uwl-f-von').value,
    bis:              document.getElementById('uwl-f-bis').value,
    freistellung_von: document.getElementById('uwl-f-freistellung-von').value,
    freistellung_bis: document.getElementById('uwl-f-freistellung-bis').value,
    neuer_typ:        document.getElementById('uwl-f-typ').value,
    umgewidmet_von:   bisherGewaehlterUser.trim(),
  });

  if (!d.ok) {
    wrap.innerHTML = '<div class="empty-hint"><div class="ico">&#9888;</div>Fehler beim Laden.</div>';
    return;
  }

  uwlRohdaten = d.data;

  if (!bisherGewaehlterUser && userSel.options.length <= 1) {
    const bearbeiter = [...new Set(d.data.map(r => r.storniert_von).filter(Boolean))].sort();
    bearbeiter.forEach(u => {
      const o = document.createElement('option');
      o.value = u; o.textContent = u;
      userSel.appendChild(o);
    });
  }

  if (!d.data.length) {
    wrap.innerHTML = '<div class="empty-hint"><div class="ico">&#10003;</div>Keine Umwidmungs-Eintr&auml;ge gefunden.</div>';
    return;
  }

  const aktionenMap = new Map();
  d.data.forEach(row => {
    const key = row.storno_aktion_id || ('fallback-' + row.id);
    if (!aktionenMap.has(key)) {
      aktionenMap.set(key, { meta: row, tage: new Set(), mitglieder: new Map() });
    }
    const ak = aktionenMap.get(key);
    ak.tage.add(row.betroffene_tage);
    (row.betroffene_antraege_details || []).forEach(m => {
      if (!ak.mitglieder.has(m.antrag_id)) ak.mitglieder.set(m.antrag_id, m);
    });
  });

  document.getElementById('uwl-treffer-info').textContent =
    aktionenMap.size + ' Umwidmungs-Aktion(en) gefunden';

  let html = '';
  let idx  = 0;
  aktionenMap.forEach((ak) => {
    const id   = 'uwl-card-' + (idx++);
    const meta = ak.meta;
    const tage = [...ak.tage].sort();
    const mgl  = [...ak.mitglieder.values()];

    const typLabel = meta.neuer_typ_label
      || UWL_TYP_LABELS[meta.neuer_typ]
      || meta.neuer_typ
      || 'Unbekannt';

    const vonLabel = esc(meta.veranstaltung || '(Ereignis)');
    const chips = tage.map(t => '<span class="tage-chip">' + uwlFmt(t) + '</span>').join('');

    const mglRows = mgl.map(m =>
      '<tr>' +
      '<td>' + esc(m.vorname) + ' ' + esc(m.nachname) + '</td>' +
      '<td style="color:var(--muted)">' + esc(m.airline||'&ndash;') + '</td>' +
      '<td><span style="display:inline-block;padding:2px 8px;border-radius:20px;font-size:11px;font-weight:700;background:#eef2ff;color:#3730a3">' + esc(m.position||'&ndash;') + '</span></td>' +
      '<td class="mono" style="font-size:12px">' + esc(m.tk_kuerzel||'&ndash;') + '</td>' +
      '<td style="color:var(--muted);font-size:12px">#' + m.antrag_id + '</td>' +
      '</tr>'
    ).join('');

    html +=
      '<div class="uw-card">' +
        '<div class="uw-card-head" onclick="uwlToggle(\'' + id + '\')">' +
          '<span class="uw-badge-from">' + vonLabel + '</span>' +
          '<span class="uw-arrow">&#8644;</span>' +
          '<span class="uw-badge-to">' + esc(typLabel) + '</span>' +
          '<div class="uw-meta">' +
            '<div class="titel">' + esc(meta.veranstaltung) + ' &nbsp;&middot;&nbsp; <span style="font-weight:400">' + esc(meta.tk_name) + '</span></div>' +
            '<div class="sub">' +
              'Ereignis: ' + uwlFmtZR(meta.ereignis_von, meta.ereignis_bis) +
              ' &nbsp;&middot;&nbsp; <strong>' + tage.length + '</strong> Tag(e)' +
              ' &nbsp;&middot;&nbsp; <strong>' + mgl.length + '</strong> Mitglied(er)' +
              ' &nbsp;&middot;&nbsp; von <strong>' + esc(meta.storniert_von) + '</strong>' +
              ' am ' + uwlFmtDt(meta.storniert_am) +
            '</div>' +
          '</div>' +
          '<span class="uw-chevron" id="uwl-chev-' + id + '">&rsaquo;</span>' +
        '</div>' +
        '<div class="uw-body" id="uwl-body-' + id + '">' +
          '<div class="tage-chips">' + chips + '</div>' +
          '<div class="grund-box"><span class="gl">Begründung</span>' +
            (meta.grund ? esc(meta.grund) : '<em style="opacity:.6">Keine Begründung angegeben</em>') +
          '</div>' +
          '<table class="uw-sub-table"><thead><tr>' +
            '<th>Name</th><th>Airline</th><th>Position</th><th>TK</th><th>Antrags-ID</th>' +
          '</tr></thead><tbody>' +
            (mglRows || '<tr><td colspan="5" style="padding:12px 18px;color:var(--muted)">Keine Mitglieder-Details.</td></tr>') +
          '</tbody></table>' +
        '</div>' +
      '</div>';
  });

  wrap.innerHTML = html;
}

function uwlToggle(id) {
  document.getElementById('uwl-body-' + id).classList.toggle('open');
  document.getElementById('uwl-chev-' + id).classList.toggle('open');
}

function uwlExportCSV() {
  if (!uwlRohdaten.length) return;
  const header = ['Aktion-ID','Umgewidmet von','Umwidmungszeitpunkt','Begründung',
                   'TK','Airline','Veranstaltung','Ereignis von','Ereignis bis',
                   'Zieltyp','Zieltyp-Label',
                   'Umgewidmete Freistellungstage',
                   'Antrags-ID','Name','Position'];

  const gruppen = new Map();
  uwlRohdaten.forEach(row => {
    const details = row.betroffene_antraege_details || [{}];
    details.forEach(m => {
      const key = (row.storno_aktion_id || row.id) + '|' + (m.antrag_id || '');
      if (!gruppen.has(key)) {
        gruppen.set(key, { meta: row, mitglied: m, tage: [] });
      }
      gruppen.get(key).tage.push(row.betroffene_tage);
    });
  });

  const rows = [...gruppen.values()].map(g => {
    const row = g.meta, m = g.mitglied;
    const tageStr = [...new Set(g.tage)].sort().map(uwlFmt).join('; ');
    return [
      row.storno_aktion_id||'', row.storniert_von, uwlFmtDt(row.storniert_am),
      row.grund||'',
      row.tk_name||'', row.airline||'', row.veranstaltung||'',
      uwlFmt(row.ereignis_von||''), uwlFmt(row.ereignis_bis||''),
      row.neuer_typ||'', row.neuer_typ_label||'',
      tageStr,
      m.antrag_id||'',
      ((m.vorname||'')+' '+(m.nachname||'')).trim(),
      m.position||''
    ].map(v => '"' + String(v).replace(/"/g,'""') + '"');
  });

  const csv  = [header.map(h => '"'+h+'"').join(';'), ...rows.map(r => r.join(';'))].join('\n');
  const blob = new Blob(['\uFEFF'+csv], {type:'text/csv;charset=utf-8'});
  const url  = URL.createObjectURL(blob);
  const a    = document.createElement('a');
  a.href     = url;
  a.download = 'umwidmungsliste_' + new Date().toISOString().slice(0,10) + '.csv';
  a.click();
  URL.revokeObjectURL(url);
}


// ══════════════════════════════════════════════════════════
// Gelöschte Anträge (Mitglieder) - Anträge, die TK-Mitglieder selbst
// im Mitgliederportal gelöscht haben (api/antrag_loeschen.php). Anders
// als beim Storno gibt es hierfür keine eigene Protokoll-Tabelle, die
// Daten kommen direkt (geparst) aus dem Audit-Log.
// ══════════════════════════════════════════════════════════
let mlRohdaten = [];

function mlFmt(iso) {
  if (!iso) return '&ndash;';
  const [y,m,d] = iso.split('-');
  return d + '.' + m + '.' + y;
}
function mlFmtZR(von, bis) {
  if (!von) return '&ndash;';
  return von === bis ? mlFmt(von) : mlFmt(von) + ' &ndash; ' + mlFmt(bis);
}
function mlFmtDt(iso) {
  if (!iso) return '&ndash;';
  return new Date(iso).toLocaleString('de-DE',
    { day:'2-digit', month:'2-digit', year:'numeric', hour:'2-digit', minute:'2-digit' });
}

async function mlInit() {
  const [dTks, dAirlines] = await Promise.all([api('list_tks'), api('list_finance_airlines')]);
  if (dTks.ok) {
    const sel = document.getElementById('ml-f-tk');
    dTks.data.forEach(tk => {
      const o = document.createElement('option');
      o.value = tk.id;
      o.textContent = tk.kuerzel + ' \u2013 ' + tk.bezeichnung;
      sel.appendChild(o);
    });
  }
  if (dAirlines.ok) {
    const sel = document.getElementById('ml-f-airline');
    dAirlines.data.airlines.forEach(a => {
      const o = document.createElement('option');
      o.value = a; o.textContent = a;
      sel.appendChild(o);
    });
  }
  await mlLaden();
}

async function mlLaden() {
  const wrap = document.getElementById('ml-liste-wrap');
  wrap.innerHTML = '<div class="empty-hint"><div class="ico">&#9203;</div>Wird geladen &hellip;</div>';
  document.getElementById('ml-treffer-info').textContent = '';

  const d = await api('list_mitglied_loeschungen', {
    tk_id:            document.getElementById('ml-f-tk').value,
    airline:          document.getElementById('ml-f-airline').value,
    von:              document.getElementById('ml-f-von').value,
    bis:              document.getElementById('ml-f-bis').value,
    freistellung_von: document.getElementById('ml-f-freistellung-von').value,
    freistellung_bis: document.getElementById('ml-f-freistellung-bis').value,
  });

  if (!d.ok) {
    wrap.innerHTML = '<div class="empty-hint"><div class="ico">&#9888;</div>Fehler beim Laden.</div>';
    return;
  }

  mlRohdaten = d.data;

  if (!d.data.length) {
    wrap.innerHTML = '<div class="empty-hint"><div class="ico">&#10003;</div>Keine gelöschten Anträge gefunden.</div>';
    return;
  }

  document.getElementById('ml-treffer-info').textContent = d.data.length + ' gelöschte(r) Antrag/Anträge';

  const rows = d.data.map(r => (
    '<tr>' +
      '<td>' + mlFmtDt(r.geloescht_am) + '</td>' +
      '<td>' + esc(r.mitglied_name) + '</td>' +
      '<td style="color:var(--muted)">' + esc(r.tk_name) + '</td>' +
      '<td style="color:var(--muted)">' + esc(r.airline || '&ndash;') + '</td>' +
      '<td>' + esc(r.veranstaltung || '&ndash;') + '</td>' +
      '<td>' + mlFmtZR(r.freistellung_von, r.freistellung_bis) + '</td>' +
      '<td>' + (r.status_vor_loeschung
                  ? '<span style="display:inline-block;padding:2px 8px;border-radius:20px;font-size:11px;font-weight:700;background:#eef2ff;color:#3730a3">' + esc(statusLabel(r.status_vor_loeschung)) + '</span>'
                  : '<span style="color:var(--muted)">&ndash;</span>') +
      '</td>' +
      '<td style="color:var(--muted);font-size:12px">#' + r.antrag_id + '</td>' +
    '</tr>'
  )).join('');

  wrap.innerHTML =
    '<table class="sl-sub-table" style="border:1px solid var(--border,#dde2ea);border-radius:10px;overflow:hidden">' +
      '<thead><tr>' +
        '<th>Gelöscht am</th><th>Gelöscht von</th><th>TK</th><th>Airline</th>' +
        '<th>Veranstaltung</th><th>Zeitraum</th><th>Status vor Löschung</th>' +
        '<th>Antrags-ID</th>' +
      '</tr></thead><tbody>' + rows + '</tbody></table>';
}

function mlExportCSV() {
  if (!mlRohdaten.length) return;
  const header = ['Gelöscht am','Gelöscht von','TK','Airline','Veranstaltung','Freistellung von','Freistellung bis',
                   'Status vor Löschung','Antrags-ID','Mitglied-ID'];
  const rows = mlRohdaten.map(r => [
    mlFmtDt(r.geloescht_am), r.mitglied_name, r.tk_name, r.airline||'',
    r.veranstaltung||'', mlFmt(r.freistellung_von||''), mlFmt(r.freistellung_bis||''),
    r.status_vor_loeschung ? statusLabel(r.status_vor_loeschung) : '',
    r.antrag_id||'', r.mitglied_id||''
  ].map(v => '"' + String(v).replace(/"/g,'""') + '"'));

  const csv  = [header.map(h => '"'+h+'"').join(';'), ...rows.map(r => r.join(';'))].join('\n');
  const blob = new Blob(['\uFEFF'+csv], {type:'text/csv;charset=utf-8'});
  const url  = URL.createObjectURL(blob);
  const a    = document.createElement('a');
  a.href     = url;
  a.download = 'geloeschte_antraege_mitglieder_' + new Date().toISOString().slice(0,10) + '.csv';
  a.click();
  URL.revokeObjectURL(url);
}

linkDateRange('ml-f-von', 'ml-f-bis');
linkDateRange('ml-f-freistellung-von', 'ml-f-freistellung-bis');

// Alte Lesezeichen auf stornoliste.php landen jetzt hier mit ?tab=storno -
// direkt den passenden Reiter öffnen.
<?php if (($_GET['tab'] ?? '') === 'storno'): ?>
tabWechseln('storno');
<?php elseif (($_GET['tab'] ?? '') === 'uwl'): ?>
tabWechseln('uwl');
<?php endif; ?>
</script>

<?php panel_foot(); ?>
