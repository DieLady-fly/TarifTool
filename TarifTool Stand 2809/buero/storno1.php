<?php
// buero/storno.php  –  Tages-Storno für Ereignisse
require_once __DIR__ . '/../_backend/bootstrap.php';
require_once __DIR__ . '/../_backend/panel.php';
send_security_headers();
$auth = require_login('buero');

panel_head('Storno');
panel_sidebar('storno', $auth['rolle'], $auth['user']);
panel_topbar('Storno');
?>

<style>
/* ── Modus-Tabs ──────────────────────────────────────────────── */
.mode-tabs { display:flex; gap:0; margin-bottom:24px; border:1px solid var(--border,#dde2ea);
  border-radius:8px; overflow:hidden; width:fit-content; }
.mode-tab { padding:10px 24px; background:#f8f9fb; border:none; border-right:1px solid var(--border,#dde2ea);
  font-size:14px; font-weight:600; color:var(--muted,#7a8fa6); cursor:pointer;
  display:flex; align-items:center; gap:8px; transition:background .15s,color .15s; }
.mode-tab:last-child { border-right:none; }
.mode-tab:hover { background:#eef2ff; color:#3730a3; }
.mode-tab.active { background:var(--navy2,#1a3a5c); color:#fff; }

/* ── Umwidmen-spezifisch ─────────────────────────────────────── */
.umwidmen-type-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(160px,1fr));
  gap:12px; margin-bottom:20px; }
.umwidmen-type-card { border:2px solid var(--border,#dde2ea); border-radius:10px;
  padding:14px 16px; cursor:pointer; transition:border-color .15s,background .15s;
  text-align:center; }
.umwidmen-type-card:hover { border-color:#4aa0e0; background:#f0f8ff; }
.umwidmen-type-card.selected { border-color:var(--navy2,#1a3a5c); background:#eef2ff; }
.umwidmen-type-card .ico { font-size:26px; margin-bottom:6px; }
.umwidmen-type-card .lbl { font-size:13px; font-weight:700; color:var(--navy2,#1a3a5c); }
.umwidmen-type-card .sub { font-size:11px; color:var(--muted,#7a8fa6); margin-top:3px; }

.uw-confirm-box { background:#eef2ff; border:1px solid #c7d2fe; border-radius:8px;
  padding:16px 20px; margin-bottom:20px; font-size:14px; }
.uw-confirm-box strong { color:#3730a3; }

.uw-arrow { font-size:22px; color:var(--muted,#7a8fa6); margin:0 6px; vertical-align:middle; }
.uw-badge-from { display:inline-block; padding:3px 10px; border-radius:20px; font-size:12px;
  font-weight:700; background:#fde8e8; color:#c0392b; }
.uw-badge-to { display:inline-block; padding:3px 10px; border-radius:20px; font-size:12px;
  font-weight:700; background:#e6f4ea; color:#1e7e34; }

/* ── Kalender-Grid ───────────────────────────────────────────── */
.kal-wrap { user-select: none; }
.kal-nav  { display:flex; align-items:center; justify-content:space-between; margin-bottom:12px; }
.kal-nav button { background:none; border:1px solid var(--border,#dde2ea); border-radius:6px;
  padding:4px 12px; cursor:pointer; font-size:15px; color:var(--navy2,#1a3a5c); }
.kal-nav button:hover { background:var(--navy2,#1a3a5c); color:#fff; }
.kal-nav .kal-month { font-weight:700; font-size:14px; color:var(--navy2,#1a3a5c);
  min-width:150px; text-align:center; }
.kal-grid { display:grid; grid-template-columns:repeat(7,1fr); gap:3px; }
.kal-head-cell { text-align:center; font-size:11px; font-weight:700;
  color:var(--muted,#7a8fa6); padding:4px 0 6px; text-transform:uppercase; }
.kal-day { aspect-ratio:1; display:flex; align-items:center; justify-content:center;
  border-radius:6px; font-size:13px; border:1px solid transparent;
  cursor:pointer; transition:background .1s; position:relative; }
.kal-day.empty   { cursor:default; }
.kal-day.outside { color:#d0d7e0; background:#f9fafb; cursor:default; }
.kal-day.selected { background:var(--navy2,#1a3a5c) !important; color:#fff !important;
  border-color:var(--navy2,#1a3a5c) !important; font-weight:700; }
.kal-day.teilstorno { background:#fff3cd; border-color:#ffc107; color:#856404; }
.kal-day.vollstorno { background:#fde8e8; border-color:#e74c3c; color:#c0392b;
  text-decoration:line-through; cursor:not-allowed; }
.kal-day.kein-antrag { color:#c5cdd7; cursor:not-allowed; }
.kal-day.hover-select:hover:not(.vollstorno):not(.kein-antrag):not(.outside):not(.empty) {
  background:#d0e8ff; border-color:#4aa0e0; }

/* ── Mitglieder-Tabelle ──────────────────────────────────────── */
.mitglieder-table { width:100%; border-collapse:collapse; font-size:14px; }
.mitglieder-table th { padding:8px 12px; text-align:left; background:#f8f9fb;
  border-bottom:2px solid var(--border,#dde2ea); font-weight:600; font-size:12px;
  text-transform:uppercase; letter-spacing:.04em; color:var(--muted,#7a8fa6); }
.mitglieder-table td { padding:9px 12px; border-bottom:1px solid var(--border,#dde2ea); vertical-align:middle; }
.mitglieder-table tr:last-child td { border-bottom:none; }
.mitglieder-table tr.storniert td { opacity:.5; text-decoration:line-through; }
.mitglieder-table tr:hover td { background:#f8f9fb; }
.cb-row { display:flex; align-items:center; gap:8px; }
.cb-row input[type=checkbox] { width:16px; height:16px; cursor:pointer; accent-color:var(--navy2,#1a3a5c); }

/* ── Tages-Matrix ────────────────────────────────────────────── */
.tage-matrix { width:100%; border-collapse:collapse; font-size:13px; }
.tage-matrix th, .tage-matrix td { padding:6px 10px; border:1px solid var(--border,#dde2ea);
  text-align:center; white-space:nowrap; }
.tage-matrix th { background:#f8f9fb; font-weight:600; font-size:11px; }
.tage-matrix td.name-cell { text-align:left; font-weight:500; white-space:nowrap; }
.dot { display:inline-block; width:22px; height:22px; border-radius:5px; line-height:22px;
  font-size:12px; font-weight:700; cursor:pointer; transition:background .1s; }
.dot.ausstehend { background:#fff3cd; color:#856404; border:1px solid #ffc107; }
.dot.genehmigt  { background:#e6f4ea; color:#1e7e34; border:1px solid #81c784; }
.dot.storno     { background:#fde8e8; color:#c0392b; border:1px solid #e57373; cursor:not-allowed; }
.dot.leer       { background:#f0f2f5; color:#c5cdd7; border:1px solid #e0e4ea; cursor:not-allowed; }
.dot.selected   { background:var(--navy2,#1a3a5c) !important; color:#fff !important;
  border-color:var(--navy2,#1a3a5c) !important; }

/* ── Steps ───────────────────────────────────────────────────── */
.steps { display:flex; gap:0; margin-bottom:28px; }
.step { flex:1; padding:12px 16px; background:#f8f9fb; border:1px solid var(--border,#dde2ea);
  border-right:none; font-size:13px; color:var(--muted,#7a8fa6); position:relative; }
.step:last-child { border-right:1px solid var(--border,#dde2ea); border-radius:0 8px 8px 0; }
.step:first-child { border-radius:8px 0 0 8px; }
.step.active { background:var(--navy2,#1a3a5c); color:#fff; border-color:var(--navy2,#1a3a5c); }
.step.done   { background:#e6f4ea; color:#1e7e34; border-color:#81c784; }
.step-num { font-weight:700; font-size:16px; display:block; }
.step-label { font-size:11px; margin-top:2px; opacity:.8; }

/* ── Panels ──────────────────────────────────────────────────── */
.panel { display:none; }
.panel.active { display:block; }

.storno-legend { display:flex; gap:16px; flex-wrap:wrap; margin-top:10px; font-size:12px;
  color:var(--muted,#7a8fa6); }
.storno-legend span { display:flex; align-items:center; gap:5px; }
.storno-legend .dot-s { width:14px; height:14px; border-radius:4px; flex-shrink:0; }

.confirm-box { background:#fff8e1; border:1px solid #ffc107; border-radius:8px;
  padding:16px 20px; margin-bottom:20px; font-size:14px; }
.confirm-box strong { color:#856404; }

/* ── Übergeordnete Tabs (Aktion / Protokolle) ────────────────── */
.haupt-tabs { display:flex; gap:8px; margin-bottom:20px; border-bottom:2px solid var(--border,#dde2ea); }
.haupt-tab { padding:10px 18px; font-size:14px; font-weight:600; color:var(--muted,#7a8fa6);
  background:none; border:none; border-bottom:3px solid transparent; cursor:pointer; margin-bottom:-2px; }
.haupt-tab.active { color:var(--navy2,#1a3a5c); border-bottom-color:var(--navy2,#1a3a5c); }

/* ── Protokoll-Listen (Umwidmung/E-Mail), wiederverwendet aus den
   ehemals eigenen Seiten umwidmungsliste.php / storno_dokumentation.php ── */
.filter-bar { display:flex; gap:12px; flex-wrap:wrap; margin-bottom:24px; align-items:flex-end; }
.filter-bar .fg { display:flex; flex-direction:column; gap:4px; }
.filter-bar label { font-size:12px; font-weight:600; color:var(--muted,#7a8fa6); }
.filter-bar input, .filter-bar select {
  padding:8px 10px; border:1px solid var(--border,#dde2ea);
  border-radius:6px; font-size:14px; min-width:160px; }

.uw-card { border:1px solid #c7d2fe; border-radius:10px; margin-bottom:14px; overflow:hidden; }
.uw-card-head { display:flex; align-items:center; gap:14px; padding:13px 18px;
  background:#f5f7ff; cursor:pointer; user-select:none; transition:background .15s; }
.uw-card-head:hover { background:#e8ecff; }
.uw-badge-from2 { background:#fde8e8; color:#c0392b; border-radius:6px;
  font-size:11px; font-weight:700; padding:3px 9px; white-space:nowrap; flex-shrink:0; }
.uw-badge-to2   { background:#e6f4ea; color:#1e7e34; border-radius:6px;
  font-size:11px; font-weight:700; padding:3px 9px; white-space:nowrap; flex-shrink:0; }
.uw-arrow2 { font-size:16px; color:var(--muted,#7a8fa6); flex-shrink:0; }
.uw-meta { flex:1; min-width:0; }
.uw-meta .titel { font-size:14px; font-weight:700; color:#1c2b3a; }
.uw-meta .sub   { font-size:12px; color:var(--muted,#7a8fa6); margin-top:2px; }
.uw-chevron { font-size:20px; color:var(--muted,#7a8fa6); transition:transform .2s;
  flex-shrink:0; line-height:1; }
.uw-chevron.open { transform:rotate(90deg); }
.uw-body { display:none; border-top:1px solid #c7d2fe; }
.uw-body.open { display:block; }
.tage-chips { display:flex; flex-wrap:wrap; gap:6px; padding:14px 18px 0; }
.tage-chip  { background:#eef2ff; color:#3730a3; border:1px solid #c7d2fe;
  border-radius:20px; font-size:12px; font-weight:700; padding:3px 11px; }
.grund-box { margin:12px 18px; padding:10px 14px; background:#fffbea;
  border:1px solid #ffe58f; border-radius:8px; font-size:13px; color:#7d6608; }
.grund-box .gl { font-size:11px; font-weight:700; text-transform:uppercase;
  letter-spacing:.04em; color:#856404; display:block; margin-bottom:3px; }
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
.empty-hint { text-align:center; padding:48px 24px; color:var(--muted,#7a8fa6); }
.empty-hint .ico { font-size:36px; margin-bottom:10px; }

.log-table { width:100%; border-collapse:collapse; font-size:13px; }
.log-table th { padding:8px 12px; text-align:left; background:#f8f9fb;
  border-bottom:2px solid var(--border,#dde2ea); font-size:11px; font-weight:700;
  text-transform:uppercase; letter-spacing:.04em; color:var(--muted,#7a8fa6); white-space:nowrap; }
.log-table td { padding:9px 12px; border-bottom:1px solid var(--border,#dde2ea); vertical-align:top; }
.log-table tr:last-child td { border-bottom:none; }
.log-table tr:hover td { background:#f8f9fb; }
.badge-gesendet { display:inline-block; padding:2px 9px; border-radius:20px;
  font-size:11px; font-weight:700; background:#e6f4ea; color:#1e7e34; }
.badge-fehler   { display:inline-block; padding:2px 9px; border-radius:20px;
  font-size:11px; font-weight:700; background:#fde8e8; color:#c0392b; }
</style>

<!-- ── Übergeordnete Tabs: Aktion durchführen / Protokolle ─────── -->
<div class="haupt-tabs">
  <button class="haupt-tab active" id="haupt-btn-aktion" onclick="hauptTabWechseln('aktion')">Aktion durchführen</button>
  <button class="haupt-tab" id="haupt-btn-emailprotokoll" onclick="hauptTabWechseln('emailprotokoll')">Storno-Protokoll</button>
  <button class="haupt-tab" id="haupt-btn-uweprotokoll" onclick="hauptTabWechseln('uweprotokoll')">Umwidmungs-Protokoll</button>
</div>

<div id="haupt-tab-aktion">

<!-- ── Modus-Tabs ────────────────────────────────────────────── -->
<div class="mode-tabs" id="mode-tabs">
  <button class="mode-tab active" id="tab-storno" onclick="switchMode('storno')">
    <span style="font-size:16px">✕</span> Stornieren
  </button>
  <button class="mode-tab" id="tab-umwidmen" onclick="switchMode('umwidmen')">
    <span style="font-size:16px">⇄</span> Umwidmen
  </button>
</div>

<!-- Steps -->
<div class="steps" id="steps">
  <div class="step active" id="step1-ind">
    <span class="step-num">1</span>
    <div class="step-label">Ereignis wählen</div>
  </div>
  <div class="step" id="step2-ind">
    <span class="step-num">2</span>
    <div class="step-label">Tage &amp; Mitglieder</div>
  </div>
  <div class="step" id="step3-ind">
    <span class="step-num">3</span>
    <div class="step-label">Bestätigen</div>
  </div>
</div>

<div id="storno-mode">
<!-- ── SCHRITT 1: Ereignis wählen ──────────────────────────────── -->
<div class="panel active" id="panel1">
  <div class="card">
    <div class="card-head">
      <h2>Ereignis auswählen</h2>
    </div>
    <div class="card-body">

      <!-- Filter -->
      <div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:20px">
        <div style="flex:1;min-width:160px">
          <label style="font-size:12px;font-weight:600;color:var(--muted);display:block;margin-bottom:4px">Airline</label>
          <select id="filter-airline" style="width:100%;padding:8px 10px;border:1px solid var(--border,#dde2ea);border-radius:6px;font-size:14px">
            <option value="">Alle Airlines</option>
          </select>
        </div>
        <div style="flex:1;min-width:160px">
          <label style="font-size:12px;font-weight:600;color:var(--muted);display:block;margin-bottom:4px">Tarifkommission</label>
          <select id="filter-tk" style="width:100%;padding:8px 10px;border:1px solid var(--border,#dde2ea);border-radius:6px;font-size:14px">
            <option value="">Alle TKs</option>
          </select>
        </div>
        <div style="flex:1;min-width:160px">
          <label style="font-size:12px;font-weight:600;color:var(--muted);display:block;margin-bottom:4px">Freistellungszeitraum von</label>
          <input type="date" id="filter-von" style="width:100%;padding:8px 10px;border:1px solid var(--border,#dde2ea);border-radius:6px;font-size:14px;box-sizing:border-box">
        </div>
        <div style="flex:1;min-width:160px">
          <label style="font-size:12px;font-weight:600;color:var(--muted);display:block;margin-bottom:4px">Freistellungszeitraum bis</label>
          <input type="date" id="filter-bis" style="width:100%;padding:8px 10px;border:1px solid var(--border,#dde2ea);border-radius:6px;font-size:14px;box-sizing:border-box">
        </div>
        <div style="flex:1;min-width:160px">
          <label style="font-size:12px;font-weight:600;color:var(--muted);display:block;margin-bottom:4px">Status</label>
          <select id="filter-status" style="width:100%;padding:8px 10px;border:1px solid var(--border,#dde2ea);border-radius:6px;font-size:14px">
            <option value="">Alle Status</option>
            <option value="beantragt_ag,genehmigt" selected>Nur stornierbare (Beantragt bei AG / Genehmigt)</option>
            <option value="beantragt_ag">Beantragt bei AG</option>
            <option value="genehmigt">Genehmigt</option>
            <option value="ausstehend">Ausstehend</option>
            <option value="freigabe_buero">Freigabe Büro erl.</option>
            <option value="abgelehnt">Abgelehnt</option>
            <option value="abgelehnt_ag">Abgelehnt (AG)</option>
            <option value="storno_buero">Storno Büro</option>
          </select>
        </div>
        <div style="display:flex;align-items:flex-end">
          <button class="btn btn-outline" onclick="ladeEreignisse()">Suchen</button>
        </div>
      </div>

      <!-- Ereignisliste -->
      <div class="table-scroll">
        <table style="width:100%;border-collapse:collapse;font-size:14px">
          <thead><tr>
            <th style="padding:8px 12px;text-align:left;background:#f8f9fb;border-bottom:2px solid var(--border,#dde2ea);font-size:12px;font-weight:600;text-transform:uppercase;letter-spacing:.04em;color:var(--muted)">TK</th>
            <th style="padding:8px 12px;text-align:left;background:#f8f9fb;border-bottom:2px solid var(--border,#dde2ea);font-size:12px;font-weight:600;text-transform:uppercase;letter-spacing:.04em;color:var(--muted)">Veranstaltung</th>
            <th style="padding:8px 12px;text-align:left;background:#f8f9fb;border-bottom:2px solid var(--border,#dde2ea);font-size:12px;font-weight:600;text-transform:uppercase;letter-spacing:.04em;color:var(--muted)">Zeitraum</th>
            <th style="padding:8px 12px;text-align:center;background:#f8f9fb;border-bottom:2px solid var(--border,#dde2ea);font-size:12px;font-weight:600;text-transform:uppercase;letter-spacing:.04em;color:var(--muted)">Mitglieder</th>
            <th style="padding:8px 12px;text-align:center;background:#f8f9fb;border-bottom:2px solid var(--border,#dde2ea);font-size:12px;font-weight:600;text-transform:uppercase;letter-spacing:.04em;color:var(--muted)">Storniert</th>
            <th style="padding:8px 12px;background:#f8f9fb;border-bottom:2px solid var(--border,#dde2ea)"></th>
          </tr></thead>
          <tbody id="ereignis-tbody">
            <tr><td colspan="6" style="text-align:center;padding:32px;color:var(--muted)">Wird geladen …</td></tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<!-- ── SCHRITT 2: Tage & Mitglieder ───────────────────────────── -->
<div class="panel" id="panel2">
  <div style="display:flex;gap:12px;align-items:center;margin-bottom:20px">
    <button class="btn btn-outline btn-sm" onclick="zurueck(1)">← Zurück</button>
    <div style="flex:1">
      <div id="ereignis-titel" style="font-weight:700;font-size:16px;color:var(--navy2,#1a3a5c)"></div>
      <div id="ereignis-sub"   style="font-size:13px;color:var(--muted)"></div>
    </div>
  </div>

  <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px" id="step2-grid">

    <!-- Tages-Matrix -->
    <div class="card">
      <div class="card-head">
        <h2>Tage auswählen</h2>
        <div style="display:flex;gap:8px">
          <button class="btn btn-outline btn-sm" onclick="alleTageWaehlen()">Alle</button>
          <button class="btn btn-outline btn-sm" onclick="keineTagWaehlen()">Keine</button>
        </div>
      </div>
      <div class="card-body">
        <div id="matrix-wrap">
          <div style="text-align:center;padding:24px;color:var(--muted)">Wird geladen …</div>
        </div>
        <div class="storno-legend">
          <span><span class="dot-s" style="background:#e6f4ea;border:1px solid #81c784"></span> Genehmigt</span>
          <span><span class="dot-s" style="background:#fff3cd;border:1px solid #ffc107"></span> Ausstehend</span>
          <span><span class="dot-s" style="background:#fde8e8;border:1px solid #e57373"></span> Bereits storniert</span>
          <span><span class="dot-s" style="background:var(--navy2,#1a3a5c)"></span> Ausgewählt</span>
        </div>
      </div>
    </div>

    <!-- Mitglieder -->
    <div class="card">
      <div class="card-head">
        <h2>Mitglieder</h2>
        <div style="display:flex;gap:8px;align-items:center">
          <select id="mgl-status-filter" onchange="renderMitglieder()" style="padding:6px 10px;border:1px solid var(--border,#dde2ea);border-radius:6px;font-size:13px">
            <option value="">Alle Status</option>
            <option value="beantragt_ag,genehmigt" selected>Nur stornierbare (Beantragt bei AG / Genehmigt)</option>
            <option value="beantragt_ag">Beantragt bei AG</option>
            <option value="genehmigt">Genehmigt</option>
            <option value="ausstehend">Ausstehend</option>
            <option value="freigabe_buero">Freigabe Büro erl.</option>
            <option value="abgelehnt">Abgelehnt</option>
            <option value="abgelehnt_ag">Abgelehnt (AG)</option>
            <option value="storno_buero">Storno Büro</option>
          </select>
          <button class="btn btn-outline btn-sm" onclick="alleMitgliederWaehlen()">Alle</button>
          <button class="btn btn-outline btn-sm" onclick="keineMitgliederWaehlen()">Keine</button>
        </div>
      </div>
      <div class="card-body" style="padding:0">
        <table class="mitglieder-table" id="mitglieder-table">
          <thead><tr>
            <th style="width:36px"></th>
            <th>Name</th>
            <th>Airline</th>
            <th>Pos.</th>
            <th>Status</th>
            <th style="text-align:center">Tage</th>
          </tr></thead>
          <tbody id="mitglieder-tbody">
            <tr><td colspan="6" style="text-align:center;padding:24px;color:var(--muted)">Wird geladen …</td></tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div style="margin-top:20px;text-align:right">
    <button class="btn btn-primary" onclick="zuWeiter()" id="btn-weiter">
      Weiter zur Bestätigung →
    </button>
  </div>
</div>

<!-- ── SCHRITT 3: Bestätigen ───────────────────────────────────── -->
<div class="panel" id="panel3">
  <div style="display:flex;gap:12px;align-items:center;margin-bottom:20px">
    <button class="btn btn-outline btn-sm" onclick="zurueck(2)">← Zurück</button>
    <div style="font-weight:700;font-size:16px;color:var(--navy2,#1a3a5c)">Storno bestätigen</div>
  </div>

  <div class="card">
    <div class="card-body">

      <div class="confirm-box" id="confirm-box"></div>

      <div style="margin-bottom:20px">
        <label style="font-size:13px;font-weight:600;display:block;margin-bottom:6px">
          Stornierungsgrund <span style="color:var(--accent,#e63946)">*</span>
        </label>
        <textarea id="storno-grund" rows="3"
          style="width:100%;padding:10px 12px;border:1px solid var(--border,#dde2ea);border-radius:8px;font-size:14px;resize:vertical;box-sizing:border-box"
          placeholder="z.B. Verhandlung entfällt, Terminverschiebung …"></textarea>
      </div>

      <div id="confirm-details" style="margin-bottom:20px;font-size:14px"></div>

      <!-- E-Mail-Vorschau -->
      <div id="email-preview-wrap" style="margin-bottom:20px;display:none">
        <div style="font-size:13px;font-weight:700;color:var(--navy2,#1a3a5c);margin-bottom:8px;display:flex;align-items:center;gap:8px">
          <span style="font-size:16px">✉</span> Storno-E-Mail an Flugbetrieb
        </div>
        <div style="border:1px solid var(--border,#dde2ea);border-radius:8px;overflow:hidden;font-size:13px">
          <div style="background:#f8f9fb;padding:10px 14px;border-bottom:1px solid var(--border,#dde2ea)">
            <table style="width:100%;border-collapse:collapse">
              <tr>
                <td style="width:60px;font-weight:700;color:var(--muted);padding:2px 0">Von:</td>
                <td id="email-from" style="padding:2px 0"></td>
              </tr>
              <tr>
                <td style="font-weight:700;color:var(--muted);padding:2px 0">An:</td>
                <td style="padding:2px 0">
                  <span id="email-to"></span>
                  <span id="email-kein-kontakt" style="display:none;color:#856404;background:#fff3cd;border:1px solid #ffc107;border-radius:4px;padding:1px 8px;font-size:11px;font-weight:700">
                    ⚠ Kein Kontakt hinterlegt
                  </span>
                </td>
              </tr>
              <tr id="email-symbol-row" style="display:none">
                <td style="font-weight:700;color:var(--muted);padding:2px 0">Symbol:</td>
                <td id="email-symbol" style="padding:2px 0;font-weight:600;color:var(--navy2,#1a3a5c)"></td>
              </tr>
              <tr id="email-cc-row" style="display:none">
                <td style="font-weight:700;color:var(--muted);padding:2px 0">CC:</td>
                <td id="email-cc" style="padding:2px 0;color:var(--muted)"></td>
              </tr>
              <tr>
                <td style="font-weight:700;color:var(--muted);padding:2px 0">Betreff:</td>
                <td style="padding:2px 0">
                  <input type="text" id="email-subject" style="width:100%;box-sizing:border-box;padding:4px 6px;border:1px solid var(--border,#dde2ea);border-radius:5px;font-size:13px;font-weight:600">
                </td>
              </tr>
            </table>
          </div>
          <div style="padding:14px;background:#fff">
            <textarea id="email-body" rows="10" style="width:100%;box-sizing:border-box;padding:10px;border:1px solid var(--border,#dde2ea);border-radius:6px;font-size:13px;line-height:1.6;font-family:inherit;resize:vertical"></textarea>
          </div>
        </div>
        <div style="font-size:12px;color:var(--muted,#7a8fa6);margin-top:6px">
          Betreff und Text können vor dem Versand angepasst werden.
        </div>
        <div style="margin-top:8px;display:flex;align-items:center;gap:8px">
          <input type="checkbox" id="email-senden" checked style="width:15px;height:15px;accent-color:var(--navy2,#1a3a5c);cursor:pointer">
          <label for="email-senden" style="font-size:13px;cursor:pointer;user-select:none">
            E-Mail nach Stornierung automatisch versenden
          </label>
        </div>
      </div>

      <div style="display:flex;gap:12px;justify-content:flex-end">
        <button class="btn btn-outline" onclick="zurueck(2)">Abbrechen</button>
        <button class="btn btn-primary" style="background:#c0392b;border-color:#c0392b" onclick="stornoDurchfuehren()" id="btn-storno">
          ✕ Jetzt stornieren
        </button>
      </div>
    </div>
  </div>
</div>

<!-- ── Erfolg ──────────────────────────────────────────────────── -->
<div class="panel" id="panel-erfolg">
  <div class="card">
    <div class="card-body" style="text-align:center;padding:48px">
      <div style="width:72px;height:72px;border-radius:50%;background:#e6f4ea;margin:0 auto 20px;display:flex;align-items:center;justify-content:center;font-size:32px">✓</div>
      <div style="font-size:22px;font-weight:700;color:#1e7e34;margin-bottom:8px">Storno erfolgreich</div>
      <p id="erfolg-text" style="color:var(--muted);margin-bottom:24px"></p>
      <div style="display:flex;gap:12px;justify-content:center">
        <button class="btn btn-outline" onclick="neuerStorno()">Weiteren Storno durchführen</button>
        <a href="antraege.php" class="btn btn-primary">Zur Antragsliste</a>
      </div>
    </div>
  </div>
</div>

</div><!-- /storno-mode -->

<!-- ════════════════════════════════════════════════════════════
     UMWIDMEN-MODUS (initially hidden)
     ════════════════════════════════════════════════════════════ -->
<div id="umwidmen-mode" style="display:none">

  <!-- Steps Umwidmen -->
  <div class="steps" id="uw-steps">
    <div class="step active" id="uw-step1-ind">
      <span class="step-num">1</span>
      <div class="step-label">Ereignis wählen</div>
    </div>
    <div class="step" id="uw-step2-ind">
      <span class="step-num">2</span>
      <div class="step-label">Tage &amp; Typ</div>
    </div>
    <div class="step" id="uw-step3-ind">
      <span class="step-num">3</span>
      <div class="step-label">Bestätigen</div>
    </div>
  </div>

  <!-- UW Schritt 1: Ereignis wählen -->
  <div class="panel active" id="uw-panel1">
    <div class="card">
      <div class="card-head"><h2>Ereignis auswählen</h2></div>
      <div class="card-body">
        <div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:20px">
          <div style="flex:1;min-width:160px">
            <label style="font-size:12px;font-weight:600;color:var(--muted);display:block;margin-bottom:4px">Airline</label>
            <select id="uw-filter-airline" style="width:100%;padding:8px 10px;border:1px solid var(--border,#dde2ea);border-radius:6px;font-size:14px">
              <option value="">Alle Airlines</option>
            </select>
          </div>
          <div style="flex:1;min-width:160px">
            <label style="font-size:12px;font-weight:600;color:var(--muted);display:block;margin-bottom:4px">Tarifkommission</label>
            <select id="uw-filter-tk" style="width:100%;padding:8px 10px;border:1px solid var(--border,#dde2ea);border-radius:6px;font-size:14px">
              <option value="">Alle TKs</option>
            </select>
          </div>
          <div style="flex:1;min-width:160px">
            <label style="font-size:12px;font-weight:600;color:var(--muted);display:block;margin-bottom:4px">Freistellungszeitraum von</label>
            <input type="date" id="uw-filter-von" style="width:100%;padding:8px 10px;border:1px solid var(--border,#dde2ea);border-radius:6px;font-size:14px;box-sizing:border-box">
          </div>
          <div style="flex:1;min-width:160px">
            <label style="font-size:12px;font-weight:600;color:var(--muted);display:block;margin-bottom:4px">Freistellungszeitraum bis</label>
            <input type="date" id="uw-filter-bis" style="width:100%;padding:8px 10px;border:1px solid var(--border,#dde2ea);border-radius:6px;font-size:14px;box-sizing:border-box">
          </div>
          <div style="flex:1;min-width:160px">
            <label style="font-size:12px;font-weight:600;color:var(--muted);display:block;margin-bottom:4px">Status</label>
            <select id="uw-filter-status" style="width:100%;padding:8px 10px;border:1px solid var(--border,#dde2ea);border-radius:6px;font-size:14px">
              <option value="">Alle Status</option>
              <option value="beantragt_ag,genehmigt" selected>Nur umwidmbare (Beantragt bei AG / Genehmigt)</option>
              <option value="beantragt_ag">Beantragt bei AG</option>
              <option value="genehmigt">Genehmigt</option>
              <option value="ausstehend">Ausstehend</option>
              <option value="freigabe_buero">Freigabe Büro erl.</option>
              <option value="abgelehnt">Abgelehnt</option>
              <option value="abgelehnt_ag">Abgelehnt (AG)</option>
              <option value="storno_buero">Storno Büro</option>
            </select>
          </div>
          <div style="display:flex;align-items:flex-end">
            <button class="btn btn-outline" onclick="uwLadeEreignisse()">Suchen</button>
          </div>
        </div>
        <div class="table-scroll">
          <table style="width:100%;border-collapse:collapse;font-size:14px">
            <thead><tr>
              <th style="padding:8px 12px;text-align:left;background:#f8f9fb;border-bottom:2px solid var(--border,#dde2ea);font-size:12px;font-weight:600;text-transform:uppercase;letter-spacing:.04em;color:var(--muted)">TK</th>
              <th style="padding:8px 12px;text-align:left;background:#f8f9fb;border-bottom:2px solid var(--border,#dde2ea);font-size:12px;font-weight:600;text-transform:uppercase;letter-spacing:.04em;color:var(--muted)">Veranstaltung</th>
              <th style="padding:8px 12px;text-align:left;background:#f8f9fb;border-bottom:2px solid var(--border,#dde2ea);font-size:12px;font-weight:600;text-transform:uppercase;letter-spacing:.04em;color:var(--muted)">Zeitraum</th>
              <th style="padding:8px 12px;text-align:center;background:#f8f9fb;border-bottom:2px solid var(--border,#dde2ea);font-size:12px;font-weight:600;text-transform:uppercase;letter-spacing:.04em;color:var(--muted)">Mitglieder</th>
              <th style="padding:8px 12px;background:#f8f9fb;border-bottom:2px solid var(--border,#dde2ea)"></th>
            </tr></thead>
            <tbody id="uw-ereignis-tbody">
              <tr><td colspan="5" style="text-align:center;padding:32px;color:var(--muted)">Wird geladen …</td></tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>

  <!-- UW Schritt 2: Tage & neuer Typ -->
  <div class="panel" id="uw-panel2">
    <div style="display:flex;gap:12px;align-items:center;margin-bottom:20px">
      <button class="btn btn-outline btn-sm" onclick="uwZurueck(1)">← Zurück</button>
      <div style="flex:1">
        <div id="uw-ereignis-titel" style="font-weight:700;font-size:16px;color:var(--navy2,#1a3a5c)"></div>
        <div id="uw-ereignis-sub"   style="font-size:13px;color:var(--muted)"></div>
      </div>
    </div>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px">

      <!-- Tage-Auswahl -->
      <div class="card">
        <div class="card-head">
          <h2>Tage auswählen</h2>
          <div style="display:flex;gap:8px">
            <button class="btn btn-outline btn-sm" onclick="uwAlleTage()">Alle</button>
            <button class="btn btn-outline btn-sm" onclick="uwKeineTage()">Keine</button>
          </div>
        </div>
        <div class="card-body">
          <div id="uw-tage-wrap">
            <div style="text-align:center;padding:24px;color:var(--muted)">Wird geladen …</div>
          </div>
          <div class="storno-legend" style="margin-top:10px">
            <span><span class="dot-s" style="background:#e6f4ea;border:1px solid #81c784"></span> Genehmigt</span>
            <span><span class="dot-s" style="background:#fff3cd;border:1px solid #ffc107"></span> Ausstehend</span>
            <span><span class="dot-s" style="background:var(--navy2,#1a3a5c)"></span> Ausgewählt</span>
          </div>
        </div>
      </div>

      <!-- Neuer Ereignistyp & Mitglieder -->
      <div style="display:flex;flex-direction:column;gap:16px">

        <div class="card">
          <div class="card-head"><h2>Umwidmen in …</h2></div>
          <div class="card-body">
            <div class="umwidmen-type-grid" id="uw-type-grid">
              <!-- befüllt per JS -->
            </div>
            <div id="uw-custom-wrap" style="display:none;margin-top:4px">
              <label style="font-size:12px;font-weight:600;color:var(--muted);display:block;margin-bottom:4px">
                Bezeichnung (Freitext)
              </label>
              <input type="text" id="uw-custom-input"
                style="width:100%;padding:8px 10px;border:1px solid var(--border,#dde2ea);border-radius:6px;font-size:14px;box-sizing:border-box"
                placeholder="z.B. Schlichtung, Einigungsstelle …">
            </div>
            <div style="font-size:12px;color:var(--muted);margin-top:8px">Optional – kann auch leer bleiben, wenn sich nur der Freistellungscode ändern soll.</div>
          </div>
        </div>

        <div class="card" id="uw-symbol-card" style="display:none">
          <div class="card-head"><h2>Freistellungscode</h2></div>
          <div class="card-body">
            <div id="uw-symbol-optionen" style="font-size:13px;color:var(--muted)">Wird geladen …</div>
          </div>
        </div>

        <div class="card">
          <div class="card-head">
            <h2>Mitglieder</h2>
            <div style="display:flex;gap:8px;align-items:center">
              <select id="uw-mgl-status-filter" onchange="uwRenderMitglieder()" style="padding:6px 10px;border:1px solid var(--border,#dde2ea);border-radius:6px;font-size:13px">
                <option value="">Alle Status</option>
                <option value="beantragt_ag,genehmigt" selected>Nur umwidmbare (Beantragt bei AG / Genehmigt)</option>
                <option value="beantragt_ag">Beantragt bei AG</option>
                <option value="genehmigt">Genehmigt</option>
                <option value="ausstehend">Ausstehend</option>
                <option value="freigabe_buero">Freigabe Büro erl.</option>
                <option value="abgelehnt">Abgelehnt</option>
                <option value="abgelehnt_ag">Abgelehnt (AG)</option>
                <option value="storno_buero">Storno Büro</option>
              </select>
              <button class="btn btn-outline btn-sm" onclick="uwAlleMitglieder()">Alle</button>
              <button class="btn btn-outline btn-sm" onclick="uwKeineMitglieder()">Keine</button>
            </div>
          </div>
          <div class="card-body" style="padding:0">
            <table class="mitglieder-table">
              <thead><tr>
                <th style="width:36px"></th>
                <th>Name</th>
                <th>Airline</th>
                <th>Pos.</th>
                <th>Aktueller Code</th>
                <th>Status</th>
              </tr></thead>
              <tbody id="uw-mitglieder-tbody">
                <tr><td colspan="6" style="text-align:center;padding:24px;color:var(--muted)">Wird geladen …</td></tr>
              </tbody>
            </table>
          </div>
        </div>

      </div>
    </div>

    <div style="margin-top:20px;text-align:right">
      <button class="btn btn-primary" onclick="uwZuWeiter()" id="uw-btn-weiter"
        style="background:#3730a3;border-color:#3730a3">
        Weiter zur Bestätigung →
      </button>
    </div>
  </div>

  <!-- UW Schritt 3: Bestätigen -->
  <div class="panel" id="uw-panel3">
    <div style="display:flex;gap:12px;align-items:center;margin-bottom:20px">
      <button class="btn btn-outline btn-sm" onclick="uwZurueck(2)">← Zurück</button>
      <div style="font-weight:700;font-size:16px;color:var(--navy2,#1a3a5c)">Umwidmung bestätigen</div>
    </div>

    <div class="card">
      <div class="card-body">

        <div class="uw-confirm-box" id="uw-confirm-box"></div>

        <div id="uw-email-preview-wrap" style="margin-bottom:20px;display:none">
          <div style="font-size:13px;font-weight:700;color:var(--navy2,#1a3a5c);margin-bottom:8px;display:flex;align-items:center;gap:8px">
            <span style="font-size:16px">✉</span> Umwidmungs-E-Mail an Flugbetrieb
          </div>
          <div style="border:1px solid var(--border,#dde2ea);border-radius:8px;overflow:hidden;font-size:13px">
            <div style="background:#f8f9fb;padding:10px 14px;border-bottom:1px solid var(--border,#dde2ea)">
              <table style="width:100%;border-collapse:collapse">
                <tr>
                  <td style="width:60px;font-weight:700;color:var(--muted);padding:2px 0">Von:</td>
                  <td id="uw-email-from" style="padding:2px 0"></td>
                </tr>
                <tr>
                  <td style="font-weight:700;color:var(--muted);padding:2px 0">An:</td>
                  <td style="padding:2px 0">
                    <span id="uw-email-to"></span>
                    <span id="uw-email-kein-kontakt" style="display:none;color:#856404;background:#fff3cd;border:1px solid #ffc107;border-radius:4px;padding:1px 8px;font-size:11px;font-weight:700">
                      ⚠ Kein Kontakt hinterlegt
                    </span>
                  </td>
                </tr>
                <tr id="uw-email-cc-row" style="display:none">
                  <td style="font-weight:700;color:var(--muted);padding:2px 0">CC:</td>
                  <td id="uw-email-cc" style="padding:2px 0;color:var(--muted)"></td>
                </tr>
                <tr>
                  <td style="font-weight:700;color:var(--muted);padding:2px 0">Betreff:</td>
                  <td style="padding:2px 0">
                    <input type="text" id="uw-email-subject" style="width:100%;box-sizing:border-box;padding:4px 6px;border:1px solid var(--border,#dde2ea);border-radius:5px;font-size:13px;font-weight:600">
                  </td>
                </tr>
              </table>
            </div>
            <div style="padding:14px;background:#fff">
              <textarea id="uw-email-body" rows="10" style="width:100%;box-sizing:border-box;padding:10px;border:1px solid var(--border,#dde2ea);border-radius:6px;font-size:13px;line-height:1.6;font-family:inherit;resize:vertical"></textarea>
            </div>
          </div>
          <div style="font-size:12px;color:var(--muted,#7a8fa6);margin-top:6px">
            Betreff und Text können vor dem Versand angepasst werden.
          </div>
          <div style="margin-top:8px;display:flex;align-items:center;gap:8px">
            <input type="checkbox" id="uw-email-senden" checked style="width:15px;height:15px;accent-color:#3730a3;cursor:pointer">
            <label for="uw-email-senden" style="font-size:13px;cursor:pointer;user-select:none">
              E-Mail nach Umwidmung automatisch versenden
            </label>
          </div>
        </div>

        <div style="margin-bottom:20px">
          <label style="font-size:13px;font-weight:600;display:block;margin-bottom:6px">
            Begründung / Notiz <span style="color:var(--muted,#7a8fa6);font-weight:400">(optional)</span>
          </label>
          <textarea id="uw-grund" rows="3"
            style="width:100%;padding:10px 12px;border:1px solid var(--border,#dde2ea);border-radius:8px;font-size:14px;resize:vertical;box-sizing:border-box"
            placeholder="z.B. Verhandlung wurde zu TK-Sitzung umgewandelt …"></textarea>
        </div>

        <div style="display:flex;gap:12px;justify-content:flex-end">
          <button class="btn btn-outline" onclick="uwZurueck(2)">Abbrechen</button>
          <button class="btn btn-primary" style="background:#3730a3;border-color:#3730a3"
            onclick="uwDurchfuehren()" id="uw-btn-submit">
            ⇄ Jetzt umwidmen
          </button>
        </div>
      </div>
    </div>
  </div>

  <!-- UW Erfolg -->
  <div class="panel" id="uw-panel-erfolg">
    <div class="card">
      <div class="card-body" style="text-align:center;padding:48px">
        <div style="width:72px;height:72px;border-radius:50%;background:#eef2ff;margin:0 auto 20px;display:flex;align-items:center;justify-content:center;font-size:32px">⇄</div>
        <div style="font-size:22px;font-weight:700;color:#3730a3;margin-bottom:8px">Umwidmung erfolgreich</div>
        <p id="uw-erfolg-text" style="color:var(--muted);margin-bottom:24px"></p>
        <div style="display:flex;gap:12px;justify-content:center">
          <button class="btn btn-outline" onclick="uwNeu()">Weitere Umwidmung</button>
          <a href="antraege.php" class="btn btn-primary">Zur Antragsliste</a>
        </div>
      </div>
    </div>
  </div>

</div><!-- /umwidmen-mode -->

<!-- Mailvorlagen bearbeiten (Storno / Umwidmung) -->
<div class="card" style="margin-top:18px">
  <div class="card-head">
    <h2>Mailvorlagen bearbeiten</h2>
  </div>
  <div class="card-body">
    <p style="font-size:13px;color:var(--muted,#7a8fa6);margin-bottom:14px">
      Platzhalter: <code>{{TAGE}}</code> <code>{{SYMBOL}}</code> <code>{{MITGLIEDER}}</code>
      <code>{{BEARBEITER}}</code> <code>{{TK}}</code> <code>{{VERANSTALTUNG}}</code>
      <code>{{ZIELTYP}}</code> (nur Umwidmung) – werden beim Erstellen der Mail automatisch
      ersetzt. <code>{{MITGLIEDER}}</code> wird durch die Liste der betroffenen Mitglieder
      ersetzt und <strong>kann aus dem Standardtext nicht entfernt werden</strong> (Speichern
      schlägt sonst fehl).
    </p>

    <div class="mode-tabs" id="svorlage-tabs" style="width:fit-content;margin-bottom:18px">
      <button type="button" class="svorlage-tab-btn mode-tab active" data-typ="storno" onclick="svorlageTabWechseln('storno')">Storno</button>
      <button type="button" class="svorlage-tab-btn mode-tab" data-typ="umwidmung" onclick="svorlageTabWechseln('umwidmung')">Umwidmung</button>
    </div>

    <div id="svorlage-alert" class="alert" style="display:none;margin-bottom:14px"></div>
    <div class="fg" style="margin-bottom:14px">
      <label>Betreff</label>
      <input type="text" id="svorlage-subject" style="width:100%;padding:8px 10px;border:1px solid var(--border,#dde2ea);border-radius:6px;font-size:14px;box-sizing:border-box">
    </div>
    <div class="fg" style="margin-bottom:14px">
      <label>Text</label>
      <textarea id="svorlage-body" rows="9" style="width:100%;padding:10px 12px;border:1px solid var(--border,#dde2ea);border-radius:6px;font-size:13px;font-family:monospace;box-sizing:border-box"></textarea>
    </div>
    <button class="btn btn-primary" id="btn-svorlage-speichern" onclick="svorlageSpeichern()">Vorlage speichern</button>
    <span style="font-size:12px;color:var(--muted);margin-left:10px">Gilt nur für die aktuell ausgewählte Vorlage.</span>
  </div>
</div>

</div><!-- /haupt-tab-aktion -->

<div id="haupt-tab-emailprotokoll" style="display:none">
  <div class="card">
    <div class="card-head">
      <h2>Storno-E-Mail-Protokoll</h2>
      <button class="btn btn-outline btn-sm" onclick="sdokExportLog()">&#11015; CSV</button>
    </div>
    <div class="card-body">
      <div class="filter-bar">
        <div class="fg">
          <label>Airline</label>
          <select id="sdok-f-airline"><option value="">Alle Airlines</option></select>
        </div>
        <div class="fg">
          <label>Von</label>
          <input type="date" id="sdok-f-von">
        </div>
        <div class="fg">
          <label>Bis</label>
          <input type="date" id="sdok-f-bis">
        </div>
        <div class="fg">
          <label>Bearbeiter</label>
          <select id="sdok-f-user"><option value="">Alle</option></select>
        </div>
        <div class="fg">
          <label>&nbsp;</label>
          <button class="btn btn-outline" onclick="sdokLadeLog()">Suchen</button>
        </div>
      </div>

      <div id="sdok-log-info" style="font-size:12px;color:var(--muted);margin-bottom:10px"></div>

      <div class="table-scroll">
        <table class="log-table">
          <thead><tr>
            <th>Datum &amp; Uhrzeit</th>
            <th>Bearbeiter</th>
            <th>An</th>
            <th>CC</th>
            <th>Betreff</th>
            <th>TK</th>
            <th>Status</th>
          </tr></thead>
          <tbody id="sdok-log-tbody">
            <tr><td colspan="7" style="text-align:center;padding:32px;color:var(--muted)">Wird geladen …</td></tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div><!-- /haupt-tab-emailprotokoll -->

<div id="haupt-tab-uweprotokoll" style="display:none">
  <div class="card">
    <div class="card-head">
      <h2>Umwidmungs-E-Mail-Protokoll</h2>
      <button class="btn btn-outline btn-sm" onclick="uweExportLog()">&#11015; CSV</button>
    </div>
    <div class="card-body">
      <div class="filter-bar">
        <div class="fg">
          <label>Airline</label>
          <select id="uwe-f-airline"><option value="">Alle Airlines</option></select>
        </div>
        <div class="fg">
          <label>Von</label>
          <input type="date" id="uwe-f-von">
        </div>
        <div class="fg">
          <label>Bis</label>
          <input type="date" id="uwe-f-bis">
        </div>
        <div class="fg">
          <label>Bearbeiter</label>
          <select id="uwe-f-user"><option value="">Alle</option></select>
        </div>
        <div class="fg">
          <label>&nbsp;</label>
          <button class="btn btn-outline" onclick="uweLadeLog()">Suchen</button>
        </div>
      </div>

      <div id="uwe-log-info" style="font-size:12px;color:var(--muted);margin-bottom:10px"></div>

      <div class="table-scroll">
        <table class="log-table">
          <thead><tr>
            <th>Datum &amp; Uhrzeit</th>
            <th>Bearbeiter</th>
            <th>An</th>
            <th>CC</th>
            <th>Betreff</th>
            <th>TK</th>
            <th>Zieltyp</th>
            <th>Status</th>
          </tr></thead>
          <tbody id="uwe-log-tbody">
            <tr><td colspan="8" style="text-align:center;padding:32px;color:var(--muted)">Wird geladen …</td></tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div><!-- /haupt-tab-uweprotokoll -->

<script src="../assets/status.js"></script>
<script>
const CSRF = '<?= csrf_token() ?>';

// ── Shared Hilfsfunktionen ────────────────────────────────────
async function api(action, extra = {}) {
  const fd = new FormData();
  fd.append('action', action);
  fd.append('csrf',   CSRF);
  for (const [k, v] of Object.entries(extra)) {
    if (Array.isArray(v)) v.forEach(x => fd.append(k + '[]', x));
    else fd.append(k, v);
  }
  const r = await fetch('../api/buero.php', { method: 'POST', body: fd });
  return r.json();
}
function fmtDatum(iso) {
  if (!iso) return '–';
  const [y,m,d] = iso.split('-');
  return `${d}.${m}.${y}`;
}
function fmtZeitraum(von, bis) {
  return von === bis ? fmtDatum(von) : `${fmtDatum(von)} – ${fmtDatum(bis)}`;
}

// ── Modus-Steuerung ───────────────────────────────────────────
let aktuellerModus = 'storno';

function switchMode(modus) {
  aktuellerModus = modus;
  document.getElementById('storno-mode').style.display  = modus === 'storno'  ? '' : 'none';
  document.getElementById('umwidmen-mode').style.display = modus === 'umwidmen' ? '' : 'none';
  document.getElementById('tab-storno').classList.toggle('active',  modus === 'storno');
  document.getElementById('tab-umwidmen').classList.toggle('active', modus === 'umwidmen');

  if (modus === 'umwidmen' && !uw_tksGeladen) uwInit();
}

// ════════════════════════════════════════════════════════════════
//  UMWIDMEN-LOGIK
// ════════════════════════════════════════════════════════════════
let uw_tksGeladen      = false;
let uw_ereignis        = null;
let uw_ereignisDaten   = null;
let uw_gewaehlte_tage  = new Set();
let uw_gewaehlte_aids  = new Set();
let uw_gewaehlter_typ  = null;   // z.B. 'tk_sitzung'
let uw_custom_text     = '';

const UW_TYPEN_BASIS = [
  { key: 'verhandlung', ico: '⚖️', lbl: 'Verhandlung',  sub: 'Tarifverhandlung' },
  { key: 'tk_sitzung',  ico: '🏛️', lbl: 'TK-Sitzung',   sub: 'Reguläre Sitzung' },
  { key: 'sonstiges',   ico: '✏️', lbl: 'Sonstiges',     sub: 'Freitext eingeben' },
];
let UW_TYPEN = [...UW_TYPEN_BASIS];

// Lädt Zusatz-Kacheln für eine Airline und rendert das Typ-Grid neu
async function uwLadeTypenFuerAirline(airline) {
  UW_TYPEN = [...UW_TYPEN_BASIS];
  if (airline) {
    const d = await api('get_veranstaltungen_fuer_airline', { airline });
    if (d.ok && d.data.zusatz_typen?.length) {
      for (const z of d.data.zusatz_typen) {
        UW_TYPEN.push({ key: z.uw_key, ico: '📋', lbl: z.label, sub: '' });
      }
    }
  }
  const grid = document.getElementById('uw-type-grid');
  grid.innerHTML = UW_TYPEN.map(t =>
    `<div class="umwidmen-type-card" id="uwt-${t.key}" onclick="uwWaehlTyp('${t.key}')">
      <div class="ico">${t.ico}</div>
      <div class="lbl">${t.lbl}</div>
      ${t.sub ? `<div class="sub">${t.sub}</div>` : ''}
    </div>`
  ).join('');
}

function uwSetStep(n) {
  [1,2,3].forEach(i => {
    document.getElementById(`uw-step${i}-ind`).className =
      'step' + (i < n ? ' done' : i === n ? ' active' : '');
    const p = document.getElementById('uw-panel' + i);
    if (p) p.className = 'panel' + (i === n ? ' active' : '');
  });
  document.getElementById('uw-panel-erfolg').className = 'panel';
}
function uwZurueck(n) { uwSetStep(n); }

async function uwInit() {
  uw_tksGeladen = true;
  const [d, dAirlines] = await Promise.all([api('list_tks'), api('list_finance_airlines')]);
  if (d.ok) {
    const sel = document.getElementById('uw-filter-tk');
    // TK-Optionen befüllen (falls noch leer)
    if (sel.options.length <= 1) {
      d.data.forEach(tk => {
        const o = document.createElement('option');
        o.value = tk.id;
        o.textContent = `${tk.kuerzel} – ${tk.bezeichnung}`;
        sel.appendChild(o);
      });
    }
  }
  if (dAirlines.ok) {
    const aSel = document.getElementById('uw-filter-airline');
    if (aSel.options.length <= 1) {
      dAirlines.data.airlines.forEach(a => {
        const o = document.createElement('option');
        o.value = a; o.textContent = a;
        aSel.appendChild(o);
      });
    }
  }
  // Typ-Kacheln initial rendern (Basis-Typen, ohne airline-spezifische Extras)
  await uwLadeTypenFuerAirline(null);

  await uwLadeEreignisse();
}

async function uwLadeEreignisse() {
  const tbody = document.getElementById('uw-ereignis-tbody');
  tbody.innerHTML = '<tr><td colspan="5" style="text-align:center;padding:32px;color:var(--muted)">Wird geladen …</td></tr>';

  const d = await api('list_ereignisse', {
    tk_id:        document.getElementById('uw-filter-tk').value,
    airline:      document.getElementById('uw-filter-airline').value,
    zeitraum_von: document.getElementById('uw-filter-von').value,
    zeitraum_bis: document.getElementById('uw-filter-bis').value,
    status:       document.getElementById('uw-filter-status').value,
  });

  if (!d.ok || !d.data.length) {
    tbody.innerHTML = '<tr><td colspan="5" style="text-align:center;padding:32px;color:var(--muted)">Keine Ereignisse gefunden.</td></tr>';
    return;
  }

  tbody.innerHTML = d.data.map(e => `
    <tr>
      <td style="padding:9px 12px"><span class="mono" style="font-size:12px">${e.tk_kuerzel}</span></td>
      <td style="padding:9px 12px;font-weight:600">${e.veranstaltung}
        ${e.ereignis_bezeichnung ? `<br><span style="font-size:12px;font-weight:400;color:var(--muted)">${e.ereignis_bezeichnung}</span>` : ''}
      </td>
      <td style="padding:9px 12px;font-size:13px;color:var(--muted)">${fmtZeitraum(e.zeitraum_von, e.zeitraum_bis)}</td>
      <td style="padding:9px 12px;text-align:center">${e.anzahl_mitglieder}</td>
      <td style="padding:9px 12px">
        <button class="btn btn-outline btn-sm" onclick="uwEreignisWaehlen(${e.ereignis_id})">
          Auswählen →
        </button>
      </td>
    </tr>`).join('');
}

async function uwEreignisWaehlen(id) {
  uwSetStep(2);
  uw_gewaehlte_tage.clear();
  uw_gewaehlte_aids.clear();
  uw_gewaehlter_typ = null;
  document.querySelectorAll('.umwidmen-type-card').forEach(c => c.classList.remove('selected'));
  document.getElementById('uw-custom-wrap').style.display = 'none';
  document.getElementById('uw-symbol-card').style.display = 'none';

  document.getElementById('uw-tage-wrap').innerHTML =
    '<div style="text-align:center;padding:24px;color:var(--muted)">Wird geladen …</div>';
  document.getElementById('uw-mitglieder-tbody').innerHTML =
    '<tr><td colspan="6" style="text-align:center;padding:24px;color:var(--muted)">Wird geladen …</td></tr>';

  const d = await api('get_ereignis', { ereignis_id: id });
  if (!d.ok) { alert('Fehler beim Laden des Ereignisses.'); uwSetStep(1); return; }

  uw_ereignis      = d.data.ereignis;
  uw_ereignisDaten = d.data;

  // Airline aus Ereignis-Daten ermitteln und Typ-Kacheln airline-spezifisch neu laden
  const uwAirline = d.data.ereignis.airline
    || (d.data.mitglieder?.[0]?.airline)
    || document.getElementById('uw-filter-airline').value
    || null;
  await uwLadeTypenFuerAirline(uwAirline);

  document.getElementById('uw-ereignis-titel').textContent =
    `${d.data.ereignis.veranstaltung} · ${d.data.ereignis.tk_name}`;
  document.getElementById('uw-ereignis-sub').textContent =
    fmtZeitraum(d.data.ereignis.zeitraum_von, d.data.ereignis.zeitraum_bis);

  uwLadeSymbole(); // direkt bei Schritt 2 anzeigen, nicht erst bei "Weiter"

  uwRenderTage();
  uwRenderMitglieder();
}

function uwRenderTage() {
  const tage = uw_ereignisDaten.tage;
  const wrap = document.getElementById('uw-tage-wrap');
  if (!tage.length) { wrap.innerHTML = '<p style="color:var(--muted)">Keine Tage gefunden.</p>'; return; }

  let html = '<div style="display:flex;flex-wrap:wrap;gap:8px">';
  tage.forEach(t => {
    const gewaehlt = uw_gewaehlte_tage.has(t.tag);
    let cls = 'kal-day hover-select';
    if (gewaehlt) cls += ' selected';
    html += `<div class="${cls}" style="width:64px;flex-direction:column;gap:2px;padding:6px 4px;border:1px solid transparent"
               onclick="uwToggleTag('${t.tag}')">
      <div style="font-weight:700;font-size:13px">${fmtDatum(t.tag).slice(0,5)}</div>
      <div style="font-size:10px;opacity:.7">${t.mitglieder_gesamt} Mitgl.</div>
    </div>`;
  });
  html += '</div>';
  wrap.innerHTML = html;
}

function uwToggleTag(tag) {
  if (uw_gewaehlte_tage.has(tag)) uw_gewaehlte_tage.delete(tag);
  else uw_gewaehlte_tage.add(tag);
  uwRenderTage();
}
function uwAlleTage() {
  uw_ereignisDaten.tage.forEach(t => uw_gewaehlte_tage.add(t.tag));
  uwRenderTage();
}
function uwKeineTage() {
  uw_gewaehlte_tage.clear();
  uwRenderTage();
}

function uwRenderMitglieder() {
  const alleMitglieder = uw_ereignisDaten.mitglieder;
  const tbody = document.getElementById('uw-mitglieder-tbody');
  if (!alleMitglieder.length) {
    tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;padding:24px;color:var(--muted)">Keine Mitglieder.</td></tr>';
    return;
  }

  const filterWert = document.getElementById('uw-mgl-status-filter')?.value || '';
  const filterStati = filterWert ? filterWert.split(',') : null;
  const mitglieder = filterStati
    ? alleMitglieder.filter(m => filterStati.includes(m.antrag_status))
    : alleMitglieder;

  if (!mitglieder.length) {
    tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;padding:24px;color:var(--muted)">Keine Mitglieder mit diesem Status.</td></tr>';
    return;
  }

  mitglieder.forEach(m => {
    if (['beantragt_ag', 'genehmigt'].includes(m.antrag_status)) uw_gewaehlte_aids.add(m.antrag_id);
  });
  uwZeigeAktuelleCodes();
  tbody.innerHTML = mitglieder.map(m => {
    const nichtBerechtigt = !['beantragt_ag', 'genehmigt'].includes(m.antrag_status);
    return `
    <tr ${nichtBerechtigt ? 'style="opacity:.5"' : ''}>
      <td>
        <div class="cb-row">
          <input type="checkbox" id="uw-cb-${m.antrag_id}" ${nichtBerechtigt ? '' : 'checked'}
            ${nichtBerechtigt ? 'disabled' : ''}
            onchange="uwToggleMitglied(${m.antrag_id}, this.checked)"
            style="width:16px;height:16px;cursor:pointer;accent-color:var(--navy2,#1a3a5c)">
        </div>
      </td>
      <td><label for="uw-cb-${m.antrag_id}" style="cursor:pointer;font-weight:500" title="${nichtBerechtigt ? 'Umwidmung erst ab Status „Beantragt bei AG" möglich (aktuell: ' + statusLabel(m.antrag_status) + ')' : ''}">
        ${m.vorname} ${m.nachname}
      </label></td>
      <td style="font-size:13px;color:var(--muted)">${m.airline}</td>
      <td><span style="display:inline-block;padding:2px 8px;border-radius:20px;font-size:11px;font-weight:700;background:#eef2ff;color:#3730a3">${m.position}</span></td>
      <td>${m.freistellungscode ? `<span style="font-family:monospace;font-weight:700">${esc(m.freistellungscode)}</span>` : '<span style="color:var(--muted)">–</span>'}</td>
      <td>${statusBadge(m.antrag_status)}</td>
    </tr>`;
  }).join('');
}
function uwToggleMitglied(aid, checked) {
  if (checked) uw_gewaehlte_aids.add(aid);
  else uw_gewaehlte_aids.delete(aid);
  uwZeigeAktuelleCodes();
}
function uwAlleMitglieder() {
  uw_ereignisDaten.mitglieder.forEach(m => {
    if (!['beantragt_ag', 'genehmigt'].includes(m.antrag_status)) return;
    uw_gewaehlte_aids.add(m.antrag_id);
    const cb = document.getElementById('uw-cb-' + m.antrag_id);
    if (cb) cb.checked = true;
  });
  uwZeigeAktuelleCodes();
}
function uwKeineMitglieder() {
  uw_gewaehlte_aids.clear();
  document.querySelectorAll('[id^="uw-cb-"]').forEach(cb => cb.checked = false);
  uwZeigeAktuelleCodes();
}

function uwWaehlTyp(key) {
  // Erneuter Klick auf die bereits gewählte Karte hebt die Auswahl wieder
  // auf - der Zieltyp ist jetzt optional (nur Freistellungscode-Umwidmung
  // möglich, ohne dass sich die Ereignisart ändert).
  if (uw_gewaehlter_typ === key) {
    uw_gewaehlter_typ = null;
    document.querySelectorAll('.umwidmen-type-card').forEach(c => c.classList.remove('selected'));
    document.getElementById('uw-custom-wrap').style.display = 'none';
    document.getElementById('uw-custom-input').value = '';
    return;
  }
  uw_gewaehlter_typ = key;
  document.querySelectorAll('.umwidmen-type-card').forEach(c => c.classList.remove('selected'));
  document.getElementById('uwt-' + key).classList.add('selected');
  const customWrap = document.getElementById('uw-custom-wrap');
  customWrap.style.display = key === 'sonstiges' ? '' : 'none';
  if (key !== 'sonstiges') document.getElementById('uw-custom-input').value = '';
}

async function uwZuWeiter() {
  if (uw_gewaehlte_tage.size === 0) { alert('Bitte mindestens einen Tag auswählen.'); return; }
  if (uw_gewaehlte_aids.size  === 0) { alert('Bitte mindestens ein Mitglied auswählen.'); return; }
  // Zieltyp (Ereignisart) ist bewusst optional - eine Umwidmung kann sich
  // auch NUR auf den Freistellungscode beziehen (z.B. V4 -> FS), ohne dass
  // sich Verhandlung/TK-Sitzung/Sonstiges ändert.
  if (uw_gewaehlter_typ === 'sonstiges' && !document.getElementById('uw-custom-input').value.trim()) {
    alert('Bitte die Bezeichnung für „Sonstiges" eingeben.');
    document.getElementById('uw-custom-input').focus();
    return;
  }
  uw_custom_text = document.getElementById('uw-custom-input').value.trim();

  const typLabel = !uw_gewaehlter_typ
    ? 'unverändert'
    : uw_gewaehlter_typ === 'sonstiges'
      ? uw_custom_text
      : UW_TYPEN.find(t => t.key === uw_gewaehlter_typ).lbl;

  const tageList = [...uw_gewaehlte_tage].sort().map(fmtDatum).join(', ');
  const names = uw_ereignisDaten.mitglieder
    .filter(m => uw_gewaehlte_aids.has(m.antrag_id))
    .map(m => `${m.vorname} ${m.nachname}`).join(', ');

  const vonLabel = uw_ereignis.veranstaltung || uw_ereignis.ereignis_bezeichnung || '(aktuell)';

  document.getElementById('uw-confirm-box').innerHTML = `
    <strong>Folgende Umwidmung wird durchgeführt:</strong><br><br>
    <b>Ereignis:</b> ${uw_ereignis.veranstaltung} · ${uw_ereignis.tk_name}<br>
    <b>Von:</b> <span class="uw-badge-from">${vonLabel}</span>
    <span class="uw-arrow">⇄</span>
    <span class="uw-badge-to">${typLabel}</span><br>
    <b>Tage (${uw_gewaehlte_tage.size}):</b> ${tageList}<br>
    <b>Mitglieder (${uw_gewaehlte_aids.size}):</b> ${names}
  `;
  document.getElementById('uw-grund').value = '';

  await uwLadeEmailVorschau();

  uwSetStep(3);
}

let uw_symbol_pflicht = false;
async function uwLadeSymbole() {
  const wrap = document.getElementById('uw-symbol-optionen');
  const outer = document.getElementById('uw-symbol-card');
  const airline = uw_ereignis.airline || (uw_ereignisDaten.mitglieder[0] && uw_ereignisDaten.mitglieder[0].airline) || '';
  outer.style.display = 'block';
  wrap.textContent = 'Wird geladen …';
  uw_symbol_pflicht = false;

  if (!airline) {
    wrap.innerHTML = `<div style="font-size:12px;color:var(--muted)">Keine Airline zur Codeermittlung gefunden.</div>`;
    return;
  }

  // Dieselbe Action wie bei "Antrag manuell anlegen" - liest die dort in
  // Finance → Konfiguration hinterlegten Freistellungscodes je Airline.
  const d = await api('list_freistellungscodes', { airline });
  const codes = (d.ok && d.data && d.data.codes) ? d.data.codes : [];

  if (!codes.length) {
    wrap.innerHTML = `<div style="font-size:12px;color:var(--muted)">Für „${esc(airline)}" sind in Finance → Konfiguration keine Freistellungscodes hinterlegt.</div>`;
    return;
  }

  // Bei genau einem Code: automatisch vorausgewählt, keine Pflicht-Interaktion
  // nötig. Pflicht nur ab zwei Codes, wo eine echte Auswahl zu treffen ist -
  // genau wie in Finance hinterlegt (dieselbe Datenquelle, dieselbe Liste).
  uw_symbol_pflicht = codes.length > 1;

  wrap.innerHTML = `
    <div id="uw-aktuelle-codes" style="font-size:12px;color:var(--muted);margin-bottom:8px"></div>
    <select id="uw-symbol-select" style="width:100%;padding:8px 10px;border:1px solid var(--border,#dde2ea);border-radius:6px;font-size:14px;box-sizing:border-box">
      ${codes.length > 1 ? '<option value="">– bitte wählen –</option>' : ''}
      ${codes.map(code => `<option value="${esc(code)}">${esc(code)}</option>`).join('')}
    </select>
    ${codes.length > 1 ? '<div style="font-size:12px;color:var(--muted);margin-top:4px">Pflichtfeld – bitte den Ziel-Freistellungscode auswählen.</div>' : ''}
  `;
  uwZeigeAktuelleCodes();
}

// Zeigt, welche(r) Freistellungscode(s) bei den aktuell ausgewählten
// Anträgen bereits hinterlegt ist/sind - damit sofort erkennbar ist, ob
// eine Umwidmung überhaupt nötig ist (z.B. wenn schon alle "FS" haben).
// Bewusst synchron und von uwLadeSymbole() getrennt, damit es bei jeder
// Änderung der Mitgliederauswahl ohne Server-Roundtrip aktualisiert
// werden kann.
function uwZeigeAktuelleCodes() {
  const el = document.getElementById('uw-aktuelle-codes');
  if (!el || !uw_ereignisDaten.mitglieder) return;
  const aktuelleCodes = [...new Set(
    uw_ereignisDaten.mitglieder
      .filter(m => uw_gewaehlte_aids.has(m.antrag_id))
      .map(m => m.freistellungscode || '(kein Code hinterlegt)')
  )];
  el.innerHTML = aktuelleCodes.length
    ? `Aktuell hinterlegt: <strong style="font-family:monospace;color:var(--navy2,#1a3a5c)">${esc(aktuelleCodes.join(', '))}</strong>`
    : '';
}

async function uwLadeEmailVorschau() {
  const wrap = document.getElementById('uw-email-preview-wrap');
  wrap.style.display = 'none';

  const typLabel = uw_gewaehlter_typ === 'sonstiges'
    ? uw_custom_text
    : UW_TYPEN.find(t => t.key === uw_gewaehlter_typ).lbl;

  const d = await api('get_umwidmung_email_vorschau', {
    ereignis_id:    uw_ereignis.id,
    antrag_ids:     [...uw_gewaehlte_aids],
    tage:           [...uw_gewaehlte_tage],
    neuer_typ:      uw_gewaehlter_typ || '',
    neuer_typ_text: uw_custom_text,
  });

  if (!d.ok) return; // Vorschau nicht zeigen wenn Fehler

  const v = d.data;
  wrap.style.display = 'block';

  document.getElementById('uw-email-from').textContent    = v.from_name + ' <' + v.from_email + '>';
  document.getElementById('uw-email-subject').value        = v.subject;
  document.getElementById('uw-email-body').value            = v.body;

  const toEl      = document.getElementById('uw-email-to');
  const noKontakt = document.getElementById('uw-email-kein-kontakt');
  if (v.to_email) {
    toEl.textContent       = (v.to_bezeichnung ? v.to_bezeichnung + ' <' : '') + v.to_email + (v.to_bezeichnung ? '>' : '');
    noKontakt.style.display = 'none';
  } else {
    toEl.textContent        = '';
    noKontakt.style.display = 'inline';
    const cb = document.getElementById('uw-email-senden');
    cb.checked  = false;
    cb.disabled = true;
  }

  const ccRow = document.getElementById('uw-email-cc-row');
  const ccEl  = document.getElementById('uw-email-cc');
  if (v.cc_email) {
    ccEl.textContent    = v.cc_email;
    ccRow.style.display = '';
  } else {
    ccRow.style.display = 'none';
  }
}

async function uwDurchfuehren() {
  const grund = document.getElementById('uw-grund').value.trim();

  const symbolSelect = document.getElementById('uw-symbol-select');
  const gewaehltesSymbol = symbolSelect ? symbolSelect.value : '';
  if (uw_symbol_pflicht && !gewaehltesSymbol) {
    alert('Bitte ein Freistellungssymbol auswählen (für diese Airline stehen mehrere zur Auswahl).');
    return;
  }

  const btn = document.getElementById('uw-btn-submit');
  btn.disabled = true;
  btn.textContent = 'Wird ausgeführt …';

  const d = await api('umwidmen_tage', {
    ereignis_id: uw_ereignis.id,
    tage:        [...uw_gewaehlte_tage],
    antrag_ids:  [...uw_gewaehlte_aids],
    neuer_typ:   uw_gewaehlter_typ || '',
    neuer_typ_text: uw_custom_text,
    grund:       grund,
    freistellungscode: gewaehltesSymbol,
    email_senden: document.getElementById('uw-email-senden')?.checked ? '1' : '0',
    subject:      document.getElementById('uw-email-subject')?.value ?? '',
    body:         document.getElementById('uw-email-body')?.value ?? '',
  });

  btn.disabled = false;
  btn.textContent = '⇄ Jetzt umwidmen';

  if (!d.ok) {
    alert('Fehler: ' + (d.error || 'Unbekannter Fehler'));
    return;
  }

  const typLabel = uw_gewaehlter_typ === 'sonstiges'
    ? uw_custom_text
    : UW_TYPEN.find(t => t.key === uw_gewaehlter_typ).lbl;

  let uw_erfolg = `Antrag auf Umwidmung wurde ins Tariffreistellungssystem übernommen. ` +
    `${d.data?.betroffene_tage ?? uw_gewaehlte_tage.size} Tag(e) für ` +
    `${d.data?.betroffene_antraege ?? uw_gewaehlte_aids.size} Mitglied(er) ` +
    `wurden zu „${typLabel}" umgewidmet.`;
  if (d.data?.email_gesendet)    uw_erfolg += ' ✉ Umwidmungs-E-Mail wurde versandt.';
  else if (d.data?.email_fehler) uw_erfolg += ' ⚠ E-Mail konnte nicht gesendet werden: ' + d.data.email_fehler;

  document.getElementById('uw-erfolg-text').textContent = uw_erfolg;

  [1,2,3].forEach(i => {
    const p = document.getElementById('uw-panel' + i);
    if (p) p.className = 'panel';
    document.getElementById(`uw-step${i}-ind`).className = 'step done';
  });
  document.getElementById('uw-panel-erfolg').className = 'panel active';
}

function uwNeu() {
  uw_ereignis       = null;
  uw_ereignisDaten  = null;
  uw_gewaehlte_tage.clear();
  uw_gewaehlte_aids.clear();
  uw_gewaehlter_typ = null;
  document.getElementById('uw-email-preview-wrap').style.display = 'none';
  const cb = document.getElementById('uw-email-senden');
  if (cb) { cb.checked = true; cb.disabled = false; }
  uwSetStep(1);
  uwLadeEreignisse();
}
</script>

<script>
// ── State (Storno) ────────────────────────────────────────────
let gewaehltes_ereignis = null;   // Ereignis-Objekt
let ereignis_daten      = null;   // {ereignis, mitglieder, tage}
let gewaehlte_tage      = new Set();  // ISO-Datum-Strings
let gewaehlte_aids      = new Set();  // antrag_ids

// api(), fmtDatum(), fmtZeitraum() sind im ersten Script-Block definiert

function setStep(n) {
  [1,2,3].forEach(i => {
    document.getElementById(`step${i}-ind`).className =
      'step' + (i < n ? ' done' : i === n ? ' active' : '');
    const p = document.getElementById('panel' + i);
    if (p) p.className = 'panel' + (i === n ? ' active' : '');
  });
  document.getElementById('panel-erfolg').className = 'panel';
}
function zurueck(n) { setStep(n); }

// ── SCHRITT 1: TKs/Airlines laden & Ereignisse ────────────────
(async () => {
  const [d, dAirlines] = await Promise.all([api('list_tks'), api('list_finance_airlines')]);
  if (d.ok) {
    const sel = document.getElementById('filter-tk');
    d.data.forEach(tk => {
      const o = document.createElement('option');
      o.value = tk.id;
      o.textContent = `${tk.kuerzel} – ${tk.bezeichnung}`;
      sel.appendChild(o);
    });
  }
  if (dAirlines.ok) {
    const sel = document.getElementById('filter-airline');
    dAirlines.data.airlines.forEach(a => {
      const o = document.createElement('option');
      o.value = a; o.textContent = a;
      sel.appendChild(o);
    });
  }
  await ladeEreignisse();
})();

async function ladeEreignisse() {
  const tbody = document.getElementById('ereignis-tbody');
  tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;padding:32px;color:var(--muted)">Wird geladen …</td></tr>';

  const d = await api('list_ereignisse', {
    tk_id:         document.getElementById('filter-tk').value,
    airline:       document.getElementById('filter-airline').value,
    zeitraum_von:  document.getElementById('filter-von').value,
    zeitraum_bis:  document.getElementById('filter-bis').value,
    status:        document.getElementById('filter-status').value,
  });

  if (!d.ok || !d.data.length) {
    tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;padding:32px;color:var(--muted)">Keine Ereignisse gefunden.</td></tr>';
    return;
  }

  tbody.innerHTML = d.data.map(e => {
    const pct = e.anzahl_tage_gesamt > 0
      ? Math.round(e.tage_storniert / e.anzahl_tage_gesamt * 100) : 0;
    const vollStorno = pct === 100;
    return `<tr style="${vollStorno ? 'opacity:.5' : ''}">
      <td style="padding:9px 12px"><span class="mono" style="font-size:12px">${e.tk_kuerzel}</span></td>
      <td style="padding:9px 12px;font-weight:600">${e.veranstaltung}${e.ereignis_bezeichnung ? `<br><span style="font-size:12px;font-weight:400;color:var(--muted)">${e.ereignis_bezeichnung}</span>` : ''}</td>
      <td style="padding:9px 12px;font-size:13px;color:var(--muted)">${fmtZeitraum(e.zeitraum_von, e.zeitraum_bis)}</td>
      <td style="padding:9px 12px;text-align:center">${e.anzahl_mitglieder}</td>
      <td style="padding:9px 12px;text-align:center">
        ${e.tage_storniert > 0
          ? `<span style="color:#c0392b;font-weight:700">${e.tage_storniert}</span>/<span style="color:var(--muted)">${e.anzahl_tage_gesamt}</span>`
          : `<span style="color:var(--muted)">0/${e.anzahl_tage_gesamt}</span>`}
      </td>
      <td style="padding:9px 12px">
        ${vollStorno
          ? '<span style="font-size:12px;color:var(--muted)">Vollständig storniert</span>'
          : `<button class="btn btn-outline btn-sm" onclick="ereignisWaehlen(${e.ereignis_id})">Auswählen →</button>`}
      </td>
    </tr>`;
  }).join('');
}

// ── SCHRITT 2: Ereignis laden ─────────────────────────────────
async function ereignisWaehlen(id) {
  setStep(2);
  gewaehlte_tage.clear();
  gewaehlte_aids.clear();

  document.getElementById('matrix-wrap').innerHTML =
    '<div style="text-align:center;padding:24px;color:var(--muted)">Wird geladen …</div>';
  document.getElementById('mitglieder-tbody').innerHTML =
    '<tr><td colspan="5" style="text-align:center;padding:24px;color:var(--muted)">Wird geladen …</td></tr>';

  const d = await api('get_ereignis', { ereignis_id: id });
  if (!d.ok) { alert('Fehler beim Laden des Ereignisses.'); setStep(1); return; }

  gewaehltes_ereignis = d.data.ereignis;
  ereignis_daten      = d.data;

  document.getElementById('ereignis-titel').textContent =
    `${d.data.ereignis.veranstaltung} · ${d.data.ereignis.tk_name}`;
  document.getElementById('ereignis-sub').textContent =
    fmtZeitraum(d.data.ereignis.zeitraum_von, d.data.ereignis.zeitraum_bis);

  renderMatrix();
  renderMitglieder();
}

// ── Matrix (Tage × Mitglieder) ────────────────────────────────
function renderMatrix() {
  const tage      = ereignis_daten.tage;        // [{tag, mitglieder_gesamt, mitglieder_storniert, ...}]
  const mitglieder= ereignis_daten.mitglieder;
  const wrap      = document.getElementById('matrix-wrap');

  if (!tage.length) { wrap.innerHTML = '<p style="color:var(--muted)">Keine Tage gefunden.</p>'; return; }

  // Tabelle: Zeilen = Mitglieder, Spalten = Tage
  const tagDates = tage.map(t => t.tag);

  // Lookup: antrag_id → tage mit status
  // Wir bauen eine Map: antrag_id → Set von stornierten Tags
  const storniertMap = {};
  mitglieder.forEach(m => {
    storniertMap[m.antrag_id] = m.tage_storniert;
  });

  let html = '<table class="tage-matrix"><thead><tr><th>Mitglied</th>';
  tagDates.forEach(t => {
    html += `<th title="${t}">${fmtDatum(t).slice(0,5)}</th>`;
  });
  html += '</tr></thead><tbody>';

  mitglieder.forEach(m => {
    const alleStorniert = m.tage_storniert === m.anzahl_tage;
    html += `<tr>
      <td class="name-cell" style="${alleStorniert ? 'opacity:.5;text-decoration:line-through' : ''}">
        ${m.vorname} ${m.nachname}<br>
        <span style="font-size:11px;color:var(--muted)">${m.airline}</span>
      </td>`;
    tagDates.forEach(tag => {
      // Prüfen ob dieser Antrag diesen Tag hat
      const hatTag = m.zeitraum_von <= tag && tag <= m.zeitraum_bis;
      if (!hatTag) {
        html += `<td><span class="dot leer" title="Kein Antrag">·</span></td>`;
        return;
      }
      // Status ermitteln: vereinfacht — wir schauen ob der Antrag komplett storniert ist
      // Für den genauen Tagesstatus nutzen wir die tage-Aggregation
      const tageInfo = tage.find(t => t.tag === tag);
      const istStorniert = alleStorniert || false; // vereinfacht; genauer: per antrag_tag
      const cls = istStorniert ? 'storno' : (gewaehlte_tage.has(tag) && gewaehlte_aids.has(m.antrag_id) ? 'selected' : 'ausstehend');
      html += `<td><span class="dot ${cls}" title="${tag}">✓</span></td>`;
    });
    html += '</tr>';
  });

  html += '</tbody></table>';
  wrap.innerHTML = html;

  // Einfachere Darstellung: Tage als klickbare Kacheln
  renderTageKacheln();
}

function renderTageKacheln() {
  const tage = ereignis_daten.tage;
  const wrap = document.getElementById('matrix-wrap');

  let html = '<div style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:12px">';
  tage.forEach(t => {
    const alleStorniert = t.mitglieder_storniert >= t.mitglieder_gesamt && t.mitglieder_gesamt > 0;
    const teilStorniert = t.mitglieder_storniert > 0 && !alleStorniert;
    const gewaehlt      = gewaehlte_tage.has(t.tag);

    let cls = 'kal-day hover-select';
    let title = `${fmtDatum(t.tag)}: ${t.mitglieder_gesamt} Mitglied(er)`;
    if (alleStorniert)  { cls += ' vollstorno'; title += ' (vollständig storniert)'; }
    else if (teilStorniert) { cls += ' teilstorno'; title += ` (${t.mitglieder_storniert} storniert)`; }
    else if (gewaehlt)  { cls += ' selected'; }

    html += `<div class="${cls}" style="width:64px;flex-direction:column;gap:2px;padding:6px 4px;border:1px solid transparent"
               title="${title}"
               onclick="${alleStorniert ? '' : `toggleTag('${t.tag}')`}">
      <div style="font-weight:700;font-size:13px">${fmtDatum(t.tag).slice(0,5)}</div>
      <div style="font-size:10px;opacity:.7">${t.mitglieder_gesamt} Mitgl.</div>
      ${teilStorniert ? `<div style="font-size:9px;color:#c0392b">${t.mitglieder_storniert} St.</div>` : ''}
    </div>`;
  });
  html += '</div>';
  wrap.innerHTML = html;
}

function toggleTag(tag) {
  if (gewaehlte_tage.has(tag)) gewaehlte_tage.delete(tag);
  else gewaehlte_tage.add(tag);
  renderTageKacheln();
  updateMitgliederHighlight();
}
function alleTageWaehlen() {
  ereignis_daten.tage
    .filter(t => t.mitglieder_storniert < t.mitglieder_gesamt)
    .forEach(t => gewaehlte_tage.add(t.tag));
  renderTageKacheln();
}
function keineTagWaehlen() {
  gewaehlte_tage.clear();
  renderTageKacheln();
}

// ── Mitglieder-Liste ──────────────────────────────────────────
function renderMitglieder() {
  const alleMitglieder = ereignis_daten.mitglieder;
  const tbody = document.getElementById('mitglieder-tbody');

  if (!alleMitglieder.length) {
    tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;padding:24px;color:var(--muted)">Keine Mitglieder gefunden.</td></tr>';
    return;
  }

  const filterWert = document.getElementById('mgl-status-filter')?.value || '';
  const filterStati = filterWert ? filterWert.split(',') : null;
  const mitglieder = filterStati
    ? alleMitglieder.filter(m => filterStati.includes(m.antrag_status))
    : alleMitglieder;

  if (!mitglieder.length) {
    tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;padding:24px;color:var(--muted)">Keine Mitglieder mit diesem Status.</td></tr>';
    return;
  }

  // Alle nicht-vollständig-stornierten, berechtigten vorauswählen
  mitglieder.forEach(m => {
    const berechtigt = ['beantragt_ag', 'genehmigt'].includes(m.antrag_status);
    if (berechtigt && m.tage_storniert < m.anzahl_tage) gewaehlte_aids.add(m.antrag_id);
  });

  tbody.innerHTML = mitglieder.map(m => {
    const alleStorniert = m.tage_storniert === m.anzahl_tage;
    const nichtBerechtigt = !['beantragt_ag', 'genehmigt'].includes(m.antrag_status);
    const gesperrt = alleStorniert || nichtBerechtigt;
    const gewaehlt = gewaehlte_aids.has(m.antrag_id);
    return `<tr class="${alleStorniert ? 'storniert' : ''}" ${nichtBerechtigt ? 'style="opacity:.5"' : ''}>
      <td>
        <div class="cb-row">
          <input type="checkbox" id="cb-${m.antrag_id}"
            ${gewaehlt && !gesperrt ? 'checked' : ''}
            ${gesperrt ? 'disabled' : ''}
            onchange="toggleMitglied(${m.antrag_id}, this.checked)">
        </div>
      </td>
      <td><label for="cb-${m.antrag_id}" style="cursor:pointer;font-weight:500" title="${nichtBerechtigt ? 'Storno erst ab Status „Beantragt bei AG" möglich (aktuell: ' + statusLabel(m.antrag_status) + ')' : ''}">
        ${m.vorname} ${m.nachname}
      </label></td>
      <td style="font-size:13px;color:var(--muted)">${m.airline}</td>
      <td><span style="display:inline-block;padding:2px 8px;border-radius:20px;font-size:11px;font-weight:700;background:#eef2ff;color:#3730a3">${m.position}</span></td>
      <td>${statusBadge(m.antrag_status)}</td>
      <td style="text-align:center;font-size:13px">
        ${m.tage_storniert > 0
          ? `<span style="color:#c0392b">${m.tage_storniert}</span>/<span style="color:var(--muted)">${m.anzahl_tage}</span>`
          : m.anzahl_tage}
      </td>
    </tr>`;
  }).join('');
}

function toggleMitglied(aid, checked) {
  if (checked) gewaehlte_aids.add(aid);
  else gewaehlte_aids.delete(aid);
}
function alleMitgliederWaehlen() {
  ereignis_daten.mitglieder
    .filter(m => m.tage_storniert < m.anzahl_tage)
    .forEach(m => {
      gewaehlte_aids.add(m.antrag_id);
      const cb = document.getElementById('cb-' + m.antrag_id);
      if (cb) cb.checked = true;
    });
}
function keineMitgliederWaehlen() {
  gewaehlte_aids.clear();
  document.querySelectorAll('[id^="cb-"]').forEach(cb => cb.checked = false);
}
function updateMitgliederHighlight() { /* optional: Mitglieder-Zeilen hervorheben */ }

// ── SCHRITT 3: Bestätigung ────────────────────────────────────
async function zuWeiter() {
  if (gewaehlte_tage.size === 0) { alert('Bitte mindestens einen Tag auswählen.'); return; }
  if (gewaehlte_aids.size  === 0) { alert('Bitte mindestens ein Mitglied auswählen.'); return; }

  const tageList = [...gewaehlte_tage].sort().map(fmtDatum).join(', ');
  const mgl = ereignis_daten.mitglieder.filter(m => gewaehlte_aids.has(m.antrag_id));
  const names = mgl.map(m => `${m.vorname} ${m.nachname}`).join(', ');

  document.getElementById('confirm-box').innerHTML = `
    <strong>Folgende Stornierung wird durchgeführt:</strong><br><br>
    <b>Ereignis:</b> ${gewaehltes_ereignis.veranstaltung} · ${gewaehltes_ereignis.tk_name}<br>
    <b>Tage (${gewaehlte_tage.size}):</b> ${tageList}<br>
    <b>Mitglieder (${gewaehlte_aids.size}):</b> ${names}
  `;
  document.getElementById('confirm-details').innerHTML = `
    <p style="color:var(--muted);font-size:13px">
      Betroffene Mitglieder erhalten eine automatische E-Mail-Benachrichtigung,
      wenn alle ihre Tage storniert werden. Teilstornierungen werden als Notiz vermerkt.
    </p>`;

  document.getElementById('storno-grund').value = '';

  // E-Mail-Vorschau laden
  await ladeEmailVorschau(mgl);

  setStep(3);
}

async function ladeEmailVorschau(mgl) {
  const wrap = document.getElementById('email-preview-wrap');
  wrap.style.display = 'none';

  // tk_id kommt direkt vom Ereignis – kein Airline-String nötig
  const d = await api('get_email_vorschau', {
    ereignis_id: gewaehltes_ereignis.id,
    antrag_ids:  [...gewaehlte_aids],
    tage:        [...gewaehlte_tage],
  });

  if (!d.ok) return; // Vorschau nicht zeigen wenn Fehler

  const v = d.data;
  wrap.style.display = 'block';

  document.getElementById('email-from').textContent    = v.from_name + ' <' + v.from_email + '>';
  document.getElementById('email-subject').value = v.subject;
  document.getElementById('email-body').value    = v.body;

  const toEl      = document.getElementById('email-to');
  const noKontakt = document.getElementById('email-kein-kontakt');
  if (v.to_email) {
    toEl.textContent       = (v.to_bezeichnung ? v.to_bezeichnung + ' <' : '') + v.to_email + (v.to_bezeichnung ? '>' : '');
    noKontakt.style.display = 'none';
  } else {
    toEl.textContent        = '';
    noKontakt.style.display = 'inline';
    // Checkbox deaktivieren wenn kein Kontakt
    const cb = document.getElementById('email-senden');
    cb.checked  = false;
    cb.disabled = true;
  }

  // Symbol-Zeile
  const symRow = document.getElementById('email-symbol-row');
  const symEl  = document.getElementById('email-symbol');
  if (v.freistellungscodes && v.freistellungscodes.length) {
    symEl.textContent    = v.freistellungscodes.join(', ');
    symRow.style.display = '';
  } else {
    symRow.style.display = 'none';
  }

  // CC-Zeile
  const ccRow = document.getElementById('email-cc-row');
  const ccEl  = document.getElementById('email-cc');
  if (v.cc_email) {
    ccEl.textContent      = v.cc_email;
    ccRow.style.display   = '';
  } else {
    ccRow.style.display   = 'none';
  }
}

async function stornoDurchfuehren() {
  const grund = document.getElementById('storno-grund').value.trim();
  if (!grund) {
    document.getElementById('storno-grund').focus();
    document.getElementById('storno-grund').style.borderColor = '#c0392b';
    return;
  }
  document.getElementById('storno-grund').style.borderColor = '';

  const btn = document.getElementById('btn-storno');
  btn.disabled = true;
  btn.textContent = 'Wird ausgeführt …';

  const emailSenden = document.getElementById('email-senden')?.checked ? '1' : '0';
  const emailSubject = document.getElementById('email-subject')?.value ?? '';
  const emailBody     = document.getElementById('email-body')?.value ?? '';

  const d = await api('storno_tage', {
    ereignis_id:  gewaehltes_ereignis.id,
    tage:         [...gewaehlte_tage],
    antrag_ids:   [...gewaehlte_aids],
    grund:        grund,
    email_senden: emailSenden,
    subject:      emailSubject,
    body:         emailBody,
  });

  btn.disabled = false;
  btn.textContent = '✕ Jetzt stornieren';

  if (!d.ok) {
    alert('Fehler: ' + (d.error || 'Unbekannter Fehler'));
    return;
  }

  let erfolg = `Antrag auf Storno wurde ins Tariffreistellungssystem übernommen. ` +
    `${d.data.storniert_tage} Tag(e) wurden für ${d.data.betroffene_antraege} Mitglied(er) storniert.`;
  if (d.data.email_gesendet)       erfolg += ' Antrag auf Storno wurde an den Arbeitgeber gesendet.';
  else if (d.data.email_fehler)    erfolg += ' ⚠ E-Mail konnte nicht gesendet werden: ' + d.data.email_fehler;

  document.getElementById('erfolg-text').textContent = erfolg;

  [1,2,3].forEach(i => {
    const p = document.getElementById('panel' + i);
    if (p) p.className = 'panel';
    document.getElementById(`step${i}-ind`).className = 'step done';
  });
  document.getElementById('panel-erfolg').className = 'panel active';
}

function neuerStorno() {
  gewaehltes_ereignis = null;
  ereignis_daten      = null;
  gewaehlte_tage.clear();
  gewaehlte_aids.clear();
  document.getElementById('email-preview-wrap').style.display = 'none';
  const cb = document.getElementById('email-senden');
  if (cb) { cb.checked = true; cb.disabled = false; }
  setStep(1);
  ladeEreignisse();
}

function esc(s) {
  return String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

// ══════════════════════════════════════════════════════════
// Mailvorlagen (Storno / Umwidmung)
// ══════════════════════════════════════════════════════════
let svorlagenAlle = {};
let aktuellerSvorlageTyp = 'storno';

async function svorlageLaden() {
  const d = await api('get_storno_mail_vorlagen');
  if (!d.ok) return;
  svorlagenAlle = d.data.vorlagen;
  svorlageAnzeigen(aktuellerSvorlageTyp);
}

function svorlageAnzeigen(typ) {
  const v = svorlagenAlle[typ];
  if (!v) return;
  document.getElementById('svorlage-subject').value = v.subject || '';
  document.getElementById('svorlage-body').value    = v.body    || '';
}

function svorlageTabWechseln(typ) {
  aktuellerSvorlageTyp = typ;
  document.querySelectorAll('.svorlage-tab-btn').forEach(btn => {
    btn.classList.toggle('active', btn.dataset.typ === typ);
  });
  document.getElementById('svorlage-alert').style.display = 'none';
  svorlageAnzeigen(typ);
}

async function svorlageSpeichern() {
  const subject = document.getElementById('svorlage-subject').value.trim();
  const body    = document.getElementById('svorlage-body').value.trim();
  const alertBox = document.getElementById('svorlage-alert');
  const btn = document.getElementById('btn-svorlage-speichern');

  if (!subject || !body) {
    alertBox.className = 'alert alert-error';
    alertBox.textContent = '⚠ Betreff und Text dürfen nicht leer sein.';
    alertBox.style.display = 'block';
    return;
  }

  btn.disabled = true; btn.textContent = '…';
  const d = await api('save_storno_mail_vorlage', { typ: aktuellerSvorlageTyp, subject, body });
  btn.disabled = false; btn.textContent = 'Vorlage speichern';

  alertBox.className = d.ok ? 'alert alert-success' : 'alert alert-error';
  alertBox.textContent = d.ok ? '✓ Vorlage gespeichert.' : '⚠ ' + (d.error || 'Fehler beim Speichern.');
  alertBox.style.display = 'block';
  if (d.ok) { svorlagenAlle[aktuellerSvorlageTyp] = { subject, body }; }
}

svorlageLaden();


// ══════════════════════════════════════════════════════════
// Übergeordnete Tab-Umschaltung: Aktion durchführen / Protokolle
// ══════════════════════════════════════════════════════════
let sdokInitialisiert   = false;
let uweInitialisiert    = false;

function hauptTabWechseln(tab) {
  document.getElementById('haupt-tab-aktion').style.display         = (tab === 'aktion') ? '' : 'none';
  document.getElementById('haupt-tab-emailprotokoll').style.display = (tab === 'emailprotokoll') ? '' : 'none';
  document.getElementById('haupt-tab-uweprotokoll').style.display   = (tab === 'uweprotokoll') ? '' : 'none';
  document.getElementById('haupt-btn-aktion').classList.toggle('active', tab === 'aktion');
  document.getElementById('haupt-btn-emailprotokoll').classList.toggle('active', tab === 'emailprotokoll');
  document.getElementById('haupt-btn-uweprotokoll').classList.toggle('active', tab === 'uweprotokoll');

  if (tab === 'emailprotokoll' && !sdokInitialisiert) { sdokInitialisiert = true; sdokInit(); }
  if (tab === 'uweprotokoll' && !uweInitialisiert) { uweInitialisiert = true; uweInit(); }
}

// ══════════════════════════════════════════════════════════
// E-Mail-Protokoll (ehemals eigene Seite storno_dokumentation.php).
// Eigene Hilfsfunktionen mit "sdok"-Präfix.
// ══════════════════════════════════════════════════════════
let sdokRohdaten = [];

function sdokFmtDt(iso) {
  if (!iso) return '–';
  return new Date(iso).toLocaleString('de-DE',
    { day:'2-digit', month:'2-digit', year:'numeric', hour:'2-digit', minute:'2-digit' });
}

async function sdokInit() {
  const dAirlines = await api('list_finance_airlines');
  if (dAirlines.ok) {
    const sel = document.getElementById('sdok-f-airline');
    dAirlines.data.airlines.forEach(a => {
      const o = document.createElement('option');
      o.value = a; o.textContent = a;
      sel.appendChild(o);
    });
  }
  // Bearbeiter-Dropdown wird in sdokLadeLog() aus den tatsächlich
  // vorhandenen Log-Einträgen befüllt - unabhängig von api/admin.php.
  await sdokLadeLog();
}

async function sdokLadeLog() {
  const tbody = document.getElementById('sdok-log-tbody');
  tbody.innerHTML = '<tr><td colspan="7" style="text-align:center;padding:24px;color:var(--muted)">Wird geladen …</td></tr>';
  document.getElementById('sdok-log-info').textContent = '';

  const userSel = document.getElementById('sdok-f-user');
  const bisherGewaehlterUser = userSel.value;

  const d = await api('list_storno_email_log', {
    von:          document.getElementById('sdok-f-von').value,
    bis:          document.getElementById('sdok-f-bis').value,
    gesendet_von: bisherGewaehlterUser,
    airline:      document.getElementById('sdok-f-airline').value,
  });

  if (!d.ok) {
    tbody.innerHTML = '<tr><td colspan="7" style="text-align:center;padding:24px;color:var(--muted)">Fehler beim Laden.</td></tr>';
    return;
  }

  sdokRohdaten = d.data;
  document.getElementById('sdok-log-info').textContent =
    d.data.length + ' Eintr' + (d.data.length === 1 ? 'ag' : 'äge');

  if (!bisherGewaehlterUser && userSel.options.length <= 1) {
    const bearbeiter = [...new Set(d.data.map(r => r.gesendet_von).filter(Boolean))].sort();
    bearbeiter.forEach(u => {
      const o = document.createElement('option');
      o.value = u; o.textContent = u;
      userSel.appendChild(o);
    });
  }

  if (!d.data.length) {
    tbody.innerHTML = '<tr><td colspan="7" style="text-align:center;padding:32px;color:var(--muted)">Keine Einträge gefunden.</td></tr>';
    return;
  }

  tbody.innerHTML = d.data.map(row => `
    <tr>
      <td class="mono" style="font-size:12px;white-space:nowrap">${sdokFmtDt(row.gesendet_am)}</td>
      <td style="font-weight:600">${esc(row.gesendet_von)}</td>
      <td style="font-size:12px">
        ${row.to_bezeichnung ? '<span style="color:var(--muted)">' + esc(row.to_bezeichnung) + '</span><br>' : ''}
        <a href="mailto:${esc(row.to_email)}" style="color:var(--navy2,#1a3a5c)">${esc(row.to_email)}</a>
      </td>
      <td style="font-size:12px">
        ${row.cc_email
          ? '<a href="mailto:' + esc(row.cc_email) + '" style="color:var(--navy2,#1a3a5c)">' + esc(row.cc_email) + '</a>'
          : '<span style="color:var(--muted)">–</span>'}
      </td>
      <td style="font-size:12px;max-width:280px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"
          title="${esc(row.subject)}">${esc(row.subject)}</td>
      <td style="font-size:12px">${esc(row.tk_name || '–')}</td>
      <td>
        ${row.status === 'gesendet'
          ? '<span class="badge-gesendet">✓ Gesendet</span>'
          : '<span class="badge-fehler" title="' + esc(row.fehler_meldung || '') + '">✗ Fehler</span>'}
      </td>
    </tr>`).join('');
}

function sdokExportLog() {
  if (!sdokRohdaten.length) return;
  const header = ['Datum','Uhrzeit','Bearbeiter','An (Bezeichnung)','An (E-Mail)','CC','Betreff','TK','Status','Fehlermeldung'];
  const rows = sdokRohdaten.map(r => {
    const dt = r.gesendet_am ? new Date(r.gesendet_am) : null;
    const datum   = dt ? dt.toLocaleDateString('de-DE') : '';
    const uhrzeit = dt ? dt.toLocaleTimeString('de-DE', {hour:'2-digit',minute:'2-digit'}) : '';
    return [datum, uhrzeit, r.gesendet_von, r.to_bezeichnung||'', r.to_email,
            r.cc_email||'', r.subject, r.tk_name||'', r.status, r.fehler_meldung||'']
      .map(v => '"' + String(v).replace(/"/g,'""') + '"');
  });
  const csv  = [header.map(h => `"${h}"`).join(';'), ...rows.map(r => r.join(';'))].join('\n');
  const blob = new Blob(['\uFEFF' + csv], { type: 'text/csv;charset=utf-8' });
  const url  = URL.createObjectURL(blob);
  const a    = document.createElement('a');
  a.href = url; a.download = 'storno_email_log_' + new Date().toISOString().slice(0,10) + '.csv';
  a.click();
  URL.revokeObjectURL(url);
}

// ══════════════════════════════════════════════════════════
// Umwidmungs-E-Mail-Protokoll (neu). Eigene Hilfsfunktionen mit
// "uwe"-Präfix.
// ══════════════════════════════════════════════════════════
let uweRohdaten = [];

function uweFmtDt(iso) {
  if (!iso) return '–';
  return new Date(iso).toLocaleString('de-DE',
    { day:'2-digit', month:'2-digit', year:'numeric', hour:'2-digit', minute:'2-digit' });
}

async function uweInit() {
  const dAirlines = await api('list_finance_airlines');
  if (dAirlines.ok) {
    const sel = document.getElementById('uwe-f-airline');
    dAirlines.data.airlines.forEach(a => {
      const o = document.createElement('option');
      o.value = a; o.textContent = a;
      sel.appendChild(o);
    });
  }
  // Bearbeiter-Dropdown wird in uweLadeLog() aus den tatsächlich
  // vorhandenen Log-Einträgen befüllt - unabhängig von api/admin.php.
  await uweLadeLog();
}

async function uweLadeLog() {
  const tbody = document.getElementById('uwe-log-tbody');
  tbody.innerHTML = '<tr><td colspan="8" style="text-align:center;padding:24px;color:var(--muted)">Wird geladen …</td></tr>';
  document.getElementById('uwe-log-info').textContent = '';

  const userSel = document.getElementById('uwe-f-user');
  const bisherGewaehlterUser = userSel.value;

  const d = await api('list_umwidmung_email_log', {
    von:          document.getElementById('uwe-f-von').value,
    bis:          document.getElementById('uwe-f-bis').value,
    gesendet_von: bisherGewaehlterUser,
    airline:      document.getElementById('uwe-f-airline').value,
  });

  if (!d.ok) {
    tbody.innerHTML = '<tr><td colspan="8" style="text-align:center;padding:24px;color:var(--muted)">Fehler beim Laden.</td></tr>';
    return;
  }

  uweRohdaten = d.data;
  document.getElementById('uwe-log-info').textContent =
    d.data.length + ' Eintr' + (d.data.length === 1 ? 'ag' : 'äge');

  if (!bisherGewaehlterUser && userSel.options.length <= 1) {
    const bearbeiter = [...new Set(d.data.map(r => r.gesendet_von).filter(Boolean))].sort();
    bearbeiter.forEach(u => {
      const o = document.createElement('option');
      o.value = u; o.textContent = u;
      userSel.appendChild(o);
    });
  }

  if (!d.data.length) {
    tbody.innerHTML = '<tr><td colspan="8" style="text-align:center;padding:32px;color:var(--muted)">Keine Einträge gefunden.</td></tr>';
    return;
  }

  tbody.innerHTML = d.data.map(row => `
    <tr>
      <td class="mono" style="font-size:12px;white-space:nowrap">${uweFmtDt(row.gesendet_am)}</td>
      <td style="font-weight:600">${esc(row.gesendet_von)}</td>
      <td style="font-size:12px">
        ${row.to_bezeichnung ? '<span style="color:var(--muted)">' + esc(row.to_bezeichnung) + '</span><br>' : ''}
        <a href="mailto:${esc(row.to_email)}" style="color:var(--navy2,#1a3a5c)">${esc(row.to_email)}</a>
      </td>
      <td style="font-size:12px">
        ${row.cc_email
          ? '<a href="mailto:' + esc(row.cc_email) + '" style="color:var(--navy2,#1a3a5c)">' + esc(row.cc_email) + '</a>'
          : '<span style="color:var(--muted)">–</span>'}
      </td>
      <td style="font-size:12px;max-width:280px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"
          title="${esc(row.subject)}">${esc(row.subject)}</td>
      <td style="font-size:12px">${esc(row.tk_name || '–')}</td>
      <td><span class="typ-pill">${esc(row.neuer_typ_label || row.neuer_typ || '–')}</span></td>
      <td>
        ${row.status === 'gesendet'
          ? '<span class="badge-gesendet">✓ Gesendet</span>'
          : '<span class="badge-fehler" title="' + esc(row.fehler_meldung || '') + '">✗ Fehler</span>'}
      </td>
    </tr>`).join('');
}

function uweExportLog() {
  if (!uweRohdaten.length) return;
  const header = ['Datum','Uhrzeit','Bearbeiter','An (Bezeichnung)','An (E-Mail)','CC','Betreff','TK','Zieltyp','Status','Fehlermeldung'];
  const rows = uweRohdaten.map(r => {
    const dt = r.gesendet_am ? new Date(r.gesendet_am) : null;
    const datum   = dt ? dt.toLocaleDateString('de-DE') : '';
    const uhrzeit = dt ? dt.toLocaleTimeString('de-DE', {hour:'2-digit',minute:'2-digit'}) : '';
    return [datum, uhrzeit, r.gesendet_von, r.to_bezeichnung||'', r.to_email,
            r.cc_email||'', r.subject, r.tk_name||'', r.neuer_typ_label||r.neuer_typ||'', r.status, r.fehler_meldung||'']
      .map(v => '"' + String(v).replace(/"/g,'""') + '"');
  });
  const csv  = [header.map(h => `"${h}"`).join(';'), ...rows.map(r => r.join(';'))].join('\n');
  const blob = new Blob(['\uFEFF' + csv], { type: 'text/csv;charset=utf-8' });
  const url  = URL.createObjectURL(blob);
  const a    = document.createElement('a');
  a.href = url; a.download = 'umwidmung_email_log_' + new Date().toISOString().slice(0,10) + '.csv';
  a.click();
  URL.revokeObjectURL(url);
}

// Alte Lesezeichen auf storno_dokumentation.php landen jetzt hier mit
// ?tab=emailprotokoll - direkt den passenden Reiter öffnen. Umwidmungsliste
// ist jetzt in antraege.php integriert (siehe dort).
<?php if (($_GET['tab'] ?? '') === 'emailprotokoll'): ?>
hauptTabWechseln('emailprotokoll');
<?php elseif (($_GET['tab'] ?? '') === 'uweprotokoll'): ?>
hauptTabWechseln('uweprotokoll');
<?php endif; ?>
</script>

<?php panel_foot(); ?>
