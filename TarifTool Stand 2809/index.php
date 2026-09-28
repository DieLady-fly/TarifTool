<?php
// ============================================================
// index.php  –  Mitglieder-Portal
// ============================================================
require_once __DIR__ . '/_backend/bootstrap.php';
require_once __DIR__ . '/_backend/mitglieder_auth.php';
send_security_headers();
session_start_secure();
ini_set('display_errors', 0);

$mitglied = mitglied_aus_session();
if (!$mitglied) {
    header('Location: mitglieder_login.php');
    exit;
}

$mk_vorname  = $mitglied['vorname'];
$mk_nachname = decrypt($mitglied['nachname_enc']);

// Airlines des Mitglieds aus TK-Zuordnung laden (mitglieder.airline ist verschlüsselt,
// tks.airline enthält den Klartextnamen der für airline_veranstaltungen benötigt wird)
// Ein Mitglied kann mehreren TKs/Airlines angehören.
$mk_airline_stmt = db()->prepare(
    "SELECT DISTINCT t.airline FROM tks t
      JOIN mitglied_tks mt ON mt.tk_id = t.id
     WHERE mt.mitglied_id = ? AND t.airline IS NOT NULL AND t.airline != ''
     UNION
     SELECT DISTINCT t.airline FROM tks t
     WHERE t.id = ? AND t.airline IS NOT NULL AND t.airline != ''"
);
$mk_airline_stmt->execute([$mitglied['id'], $mitglied['tk_id']]);
$mk_airlines = $mk_airline_stmt->fetchAll(PDO::FETCH_COLUMN);
$mk_airline = implode(',', $mk_airlines); // kommagetrennt für JS
// Mehrfachmitgliedschaft: ein Mitglied kann mehreren TKs angehören
// (siehe mitglied_tk_ids()/mitglied_tks_details()). $mk_tks enthält immer
// mindestens die Heim-TK, ggf. zusätzliche. Wird sowohl für die
// Kopfzeilen-Badges als auch als JS-Datenquelle für die TK-Auswahl beim
// Antrag-Stellen genutzt.
$mk_tks = mitglied_tks_details((int)$mitglied['id']);
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Meine Anträge – Freistellungssystem Tarif</title>
<link rel="stylesheet" href="assets/style.css">
<style>
/* ── Reset & Basis ──────────────────────────────────────── */
*, *::before, *::after { box-sizing: border-box; }

/* ── Seiten-Layout ──────────────────────────────────────── */
.portal-wrap {
  max-width: 1100px;
  margin: 0 auto;
  padding: 24px 20px 60px;
}

/* ── Portal-Header ──────────────────────────────────────── */
.portal-topbar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  flex-wrap: wrap;
  margin-bottom: 32px;
  padding-bottom: 20px;
  border-bottom: 2px solid var(--border, #dde2ea);
}
.portal-topbar-left h1 {
  margin: 0 0 4px;
  font-size: 22px;
  color: var(--navy2, #1a3a5c);
}
.portal-topbar-left .sub {
  font-size: 13px;
  color: var(--muted, #7a8fa6);
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
}
.tk-badge {
  background: var(--navy2, #1a3a5c);
  color: #fff;
  padding: 2px 10px;
  border-radius: 20px;
  font-size: 11px;
  font-weight: 700;
  letter-spacing: .03em;
}
.portal-topbar-right {
  display: flex;
  align-items: center;
  gap: 14px;
  flex-shrink: 0;
}
#session-timer {
  font-size: 12px;
  color: var(--muted, #7a8fa6);
  white-space: nowrap;
}
#session-timer.warn { color: #c0392b; font-weight: 700; }
#btn-logout {
  background: #c0392b;
  color: #fff;
  border: none;
  border-radius: 8px;
  padding: 9px 20px;
  font-size: 14px;
  font-weight: 700;
  cursor: pointer;
  transition: background .15s;
  white-space: nowrap;
}
#btn-logout:hover { background: #922b21; }

/* ── Tab-Navigation ─────────────────────────────────────── */
.tab-nav {
  display: flex;
  gap: 4px;
  margin-bottom: 24px;
  border-bottom: 2px solid var(--border, #dde2ea);
}
.tab-btn {
  background: none;
  border: none;
  border-bottom: 3px solid transparent;
  margin-bottom: -2px;
  padding: 10px 20px;
  font-size: 14px;
  font-weight: 600;
  color: var(--muted, #7a8fa6);
  cursor: pointer;
  transition: color .15s, border-color .15s;
  white-space: nowrap;
}
.tab-btn:hover { color: var(--navy2, #1a3a5c); }
.tab-btn.active {
  color: var(--navy2, #1a3a5c);
  border-bottom-color: var(--navy2, #1a3a5c);
}
.tab-panel { display: none; }
.tab-panel.active { display: block; }

/* ── Zwei-Spalten-Grid (Kalender | Formular) ────────────── */
.antrag-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 28px;
  align-items: start;
}
@media (max-width: 780px) {
  .antrag-grid { grid-template-columns: 1fr; }
}

/* ── Card ───────────────────────────────────────────────── */
.mk-card {
  background: #fff;
  border: 1px solid var(--border, #dde2ea);
  border-radius: 12px;
  overflow: hidden;
  box-shadow: 0 1px 4px rgba(0,0,0,.05);
}
.mk-card-head {
  background: var(--navy2, #1a3a5c);
  color: #fff;
  padding: 14px 20px;
  display: flex;
  align-items: center;
  gap: 8px;
}
.mk-card-head h2 {
  margin: 0;
  font-size: 15px;
  font-weight: 700;
  color: #fff;
}
.mk-card-body { padding: 20px; }

/* ── Kalender ───────────────────────────────────────────── */
.kal-nav {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 16px;
}
.kal-nav button {
  background: none;
  border: 1px solid var(--border, #dde2ea);
  border-radius: 6px;
  padding: 6px 16px;
  cursor: pointer;
  font-size: 16px;
  color: var(--navy2, #1a3a5c);
  transition: background .15s;
}
.kal-nav button:hover { background: var(--navy2, #1a3a5c); color: #fff; }
.kal-nav .kal-month {
  font-weight: 700;
  font-size: 15px;
  color: var(--navy2, #1a3a5c);
  min-width: 170px;
  text-align: center;
}
.kal-grid {
  display: grid;
  grid-template-columns: repeat(7, 1fr);
  gap: 4px;
}
.kal-head-cell {
  text-align: center;
  font-size: 11px;
  font-weight: 700;
  color: var(--muted, #7a8fa6);
  padding: 4px 0 8px;
  text-transform: uppercase;
  letter-spacing: .05em;
}
.kal-day {
  aspect-ratio: 1;
  display: flex;
  align-items: center;
  justify-content: center;
  border-radius: 8px;
  font-size: 13px;
  cursor: default;
  user-select: none;
  border: 1px solid transparent;
  position: relative;
  transition: background .12s, color .12s, transform .1s;
}
.kal-day.kal-past  { color: #d0d8e4; }
.kal-day.kal-vergangen[data-status] {
  opacity: .45;
  cursor: default;
  filter: grayscale(35%);
}
.kal-day.kal-vergangen[data-status]:hover { opacity: .45; transform: none; }
.kal-day.kal-today { font-weight: 700; border-color: var(--navy2, #1a3a5c); }
.kal-day.kal-free  { cursor: pointer; }
.kal-day.kal-free:hover {
  background: #e8eff7;
  border-color: var(--navy2, #1a3a5c);
}
.kal-day.kal-range-start,
.kal-day.kal-range-end {
  background: var(--navy2, #1a3a5c) !important;
  color: #fff !important;
  border-color: var(--navy2, #1a3a5c) !important;
  font-weight: 700;
}
.kal-day.kal-in-range {
  background: #d0e8ff;
  border-radius: 0;
  border-color: transparent;
}
.kal-day.kal-range-start { border-radius: 8px 0 0 8px; }
.kal-day.kal-range-end   { border-radius: 0 8px 8px 0; }
.kal-day.kal-range-start.kal-range-end { border-radius: 8px; }
.kal-day.status-ausstehend  { background: #f0a500 !important; color: #fff !important; cursor: pointer; }
.kal-day.status-freigabe_buero{ background: #1d4ed8 !important; color: #fff !important; cursor: pointer; }
.kal-day.status-beantragt_ag{
  background: repeating-linear-gradient(45deg, #2851a3, #2851a3 6px, #ffffff 6px, #ffffff 12px) !important;
  color: #fff !important; cursor: pointer;
}
.kal-day.status-genehmigt   { background: #1e7e34 !important; color: #fff !important; cursor: pointer; }
.kal-day.status-abgelehnt   { background: #8e44ad !important; color: #fff !important; cursor: pointer; }
.kal-day.status-abgelehnt_ag{
  background: repeating-linear-gradient(45deg, #c0392b, #c0392b 6px, #ffffff 6px, #ffffff 12px) !important;
  color: #fff !important; cursor: pointer;
}
.kal-day.status-storno_buero{ background: #aaa    !important; color: #fff !important; text-decoration: line-through; cursor: pointer; }
.kal-day[data-status]:hover { opacity: .85; transform: scale(1.06); z-index: 2; }
/* Gesperrte Tage (Deadline abgelaufen) */
.kal-day.kal-gesperrt {
  background: repeating-linear-gradient(
    45deg, #f5f5f5, #f5f5f5 3px, #e0e0e0 3px, #e0e0e0 6px
  ) !important;
  color: #bbb !important;
  cursor: not-allowed !important;
  border-color: #ccc !important;
}
.kal-day .kal-tooltip {
  display: none;
  position: absolute;
  bottom: calc(100% + 7px);
  left: 50%;
  transform: translateX(-50%);
  background: #1c2b3a;
  color: #fff;
  font-size: 11px;
  padding: 5px 9px;
  border-radius: 6px;
  white-space: nowrap;
  z-index: 20;
  pointer-events: none;
  box-shadow: 0 2px 8px rgba(0,0,0,.2);
}
.kal-day[data-status]:hover .kal-tooltip,
.kal-day.kal-free:hover .kal-tooltip { display: block; }
.kal-hint {
  font-size: 12px;
  color: var(--muted, #7a8fa6);
  margin-top: 10px;
  text-align: center;
  min-height: 18px;
  font-style: italic;
}
.kal-legend {
  display: flex;
  gap: 12px;
  flex-wrap: wrap;
  margin-top: 14px;
  padding-top: 14px;
  border-top: 1px solid var(--border, #dde2ea);
  font-size: 11px;
  color: var(--muted, #7a8fa6);
}
.kal-legend span { display: flex; align-items: center; gap: 5px; }
.kal-legend .dot { width: 12px; height: 12px; border-radius: 4px; flex-shrink: 0; }

/* ── Antrags-Formular ───────────────────────────────────── */
.form-hint {
  font-size: 13px;
  color: var(--muted, #7a8fa6);
  margin: 0 0 16px;
  line-height: 1.5;
}
.termine-list { display: flex; flex-direction: column; gap: 10px; margin-bottom: 4px; }
.termin-chip {
  display: flex;
  align-items: center;
  gap: 10px;
  background: #f4f7fb;
  border: 1px solid #d0dcea;
  border-radius: 8px;
  padding: 10px 12px;
  flex-wrap: wrap;
}
.termin-chip .tc-dates {
  font-weight: 700;
  color: var(--navy2, #1a3a5c);
  font-size: 13px;
  flex-shrink: 0;
  min-width: 120px;
}
.termin-chip .tc-type { flex: 1; min-width: 160px; }
.termin-chip .tc-type select {
  width: 100%;
  padding: 7px 10px;
  border: 1px solid #c3cfd9;
  border-radius: 6px;
  font-size: 13px;
  background: #fff;
}
.termin-chip .tc-remove {
  background: none;
  border: none;
  color: #b0bec8;
  cursor: pointer;
  font-size: 20px;
  line-height: 1;
  padding: 0 4px;
  flex-shrink: 0;
  transition: color .15s;
}
.termin-chip .tc-remove:hover { color: #c0392b; }

.section-label {
  font-size: 11px;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: .07em;
  color: var(--muted, #7a8fa6);
  margin: 20px 0 10px;
  padding-bottom: 6px;
  border-bottom: 1px solid var(--border, #dde2ea);
}
.field-row {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 12px;
  margin-bottom: 12px;
}
.field-row.full { grid-template-columns: 1fr; }
@media (max-width: 420px) { .field-row { grid-template-columns: 1fr; } }
.fg label {
  display: block;
  font-size: 12px;
  font-weight: 600;
  color: var(--navy2, #1a3a5c);
  margin-bottom: 4px;
}
.fg input, .fg select {
  width: 100%;
  padding: 9px 11px;
  border: 1px solid var(--border, #dde2ea);
  border-radius: 7px;
  font-size: 13px;
  transition: border-color .15s;
}
.fg input:focus, .fg select:focus {
  outline: none;
  border-color: var(--navy2, #1a3a5c);
}
.btn-einreichen {
  width: 100%;
  padding: 12px;
  background: var(--navy2, #1a3a5c);
  color: #fff;
  border: none;
  border-radius: 8px;
  font-size: 15px;
  font-weight: 700;
  cursor: pointer;
  margin-top: 16px;
  transition: background .15s, opacity .15s;
}
.btn-einreichen:hover:not(:disabled) { background: #0f2744; }
.btn-einreichen:disabled { opacity: .45; cursor: not-allowed; }

/* ── Success-State ──────────────────────────────────────── */
.success-state {
  text-align: center;
  padding: 32px 20px;
}
.success-state .ico {
  font-size: 52px;
  display: block;
  margin-bottom: 12px;
  color: #1e7e34;
}
.success-state h2 { margin: 0 0 8px; font-size: 20px; color: var(--navy2, #1a3a5c); }
.success-state p  { font-size: 14px; color: var(--muted, #7a8fa6); margin: 0 0 16px; }
.btn-weiterer {
  padding: 10px 24px;
  background: var(--navy2, #1a3a5c);
  color: #fff;
  border: none;
  border-radius: 8px;
  font-size: 14px;
  font-weight: 700;
  cursor: pointer;
  transition: background .15s;
}
.btn-weiterer:hover { background: #0f2744; }

/* ── Alert ──────────────────────────────────────────────── */
.mk-alert {
  display: none;
  align-items: flex-start;
  gap: 10px;
  background: #f8d7da;
  border: 1px solid #f5c6cb;
  color: #721c24;
  border-radius: 8px;
  padding: 12px 16px;
  font-size: 13px;
  margin-bottom: 16px;
}
.mk-alert.show { display: flex; }

/* ── Antrags-Liste ──────────────────────────────────────── */
.antrag-list { display: flex; flex-direction: column; gap: 16px; }
.antrag-item {
  background: #fff;
  border: 1px solid var(--border, #dde2ea);
  border-radius: 12px;
  overflow: hidden;
  box-shadow: 0 1px 4px rgba(0,0,0,.04);
}
.antrag-item-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 14px 18px;
  background: #f8f9fb;
  border-bottom: 1px solid var(--border, #dde2ea);
  gap: 12px;
  flex-wrap: wrap;
}
.ai-titel   { font-weight: 700; color: var(--navy2, #1a3a5c); font-size: 15px; }
.ai-zeitraum{ font-size: 13px; color: var(--muted, #7a8fa6); margin-top: 2px; }
.status-badge {
  padding: 4px 12px;
  border-radius: 20px;
  font-size: 11px;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: .05em;
  flex-shrink: 0;
}
.sb-ausstehend   { background: #fff3cd; color: #856404; }
.sb-freigabe_buero { background: #dbeafe; color: #1d4ed8; }
.sb-beantragt_ag { background: #e0ecff; color: #2851a3; }
.sb-genehmigt    { background: #d4edda; color: #155724; }
.sb-abgelehnt    { background: #f8d7da; color: #721c24; }
.sb-abgelehnt_ag { background: #f3e5f5; color: #6a1b9a; }
.sb-storno_buero { background: #e2e3e5; color: #383d41; }
.antrag-item-body {
  padding: 14px 18px;
  font-size: 13px;
  color: #444;
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
  gap: 8px 20px;
}
.ai-field strong {
  display: block;
  font-size: 10px;
  text-transform: uppercase;
  letter-spacing: .06em;
  color: var(--muted, #7a8fa6);
  margin-bottom: 2px;
  font-weight: 700;
}
.antrag-tage-list {
  padding: 0 18px 14px;
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
}
.tag-chip {
  padding: 3px 9px;
  border-radius: 5px;
  font-size: 11px;
  font-weight: 600;
}
.tc-ausstehend   { background: #fff3cd; color: #856404; }
.tc-beantragt_ag { background: #e0ecff; color: #2851a3; }
.tc-genehmigt    { background: #d4edda; color: #155724; }
.tc-abgelehnt    { background: #f8d7da; color: #721c24; }
.tc-abgelehnt_ag { background: #f3e5f5; color: #6a1b9a; }
.tc-storno_buero { background: #e2e3e5; color: #383d41; text-decoration: line-through; }

.empty-state {
  text-align: center;
  padding: 56px 20px;
  color: var(--muted, #7a8fa6);
}
.empty-state .ico { font-size: 48px; margin-bottom: 14px; display: block; }
.empty-state p { margin: 0 0 6px; font-size: 15px; }
.empty-state small { font-size: 13px; }
</style>
</head>
<body>

<header class="site-header">
  <div class="brand">Tarif<span>Freistellung</span></div>
  <div class="tagline">Mitglieder-Portal</div>
</header>

<main class="portal-wrap">

  <!-- ── Topbar ──────────────────────────────────────────── -->
  <div class="portal-topbar">
    <div class="portal-topbar-left">
      <h1>Meine Anträge</h1>
      <div class="sub">
        Willkommen, <strong><?= htmlspecialchars($mk_vorname . ' ' . $mk_nachname) ?></strong>
        <?php foreach ($mk_tks as $mk_tk): ?>
          &nbsp;<span class="tk-badge"><?= htmlspecialchars($mk_tk['kuerzel'] . ' – ' . $mk_tk['bezeichnung']) ?></span>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="portal-topbar-right">
      <span id="session-timer"></span>
      <button id="btn-logout">⏻ Abmelden</button>
    </div>
  </div>

  <div id="alert-box" class="mk-alert"></div>

  <!-- ── Tab-Navigation ──────────────────────────────────── -->
  <div class="tab-nav">
    <button class="tab-btn active" data-tab="tab-kalender">📅 Kalender</button>
    <button class="tab-btn" data-tab="tab-antrag">✚ Neuer Antrag</button>
    <button class="tab-btn" data-tab="tab-liste">📋 Alle Anträge</button>
  </div>

  <!-- ══ TAB: KALENDER ═══════════════════════════════════════ -->
  <div id="tab-kalender" class="tab-panel active">
    <div class="mk-card">
      <div class="mk-card-head">
        <h2>📅 Kalenderübersicht – Alle Anträge</h2>
      </div>
      <div class="mk-card-body" style="max-width:480px;margin:0 auto">
        <div class="kal-nav">
          <button type="button" id="kal2-prev">‹</button>
          <div class="kal-month" id="kal2-month-label"></div>
          <button type="button" id="kal2-next">›</button>
        </div>
        <div class="kal-grid" id="kal2-grid"></div>
        <div class="kal-legend" style="margin-top:20px">
          <span><span class="dot" style="background:#f0a500"></span> Beantragt durch Mitglied</span>
          <span><span class="dot" style="background:#1d4ed8"></span> Genehmigt durch Büro</span>
          <span><span class="dot" style="background:repeating-linear-gradient(45deg,#2851a3,#2851a3 6px,#ffffff 6px,#ffffff 12px)"></span> Beantragt bei AG</span>
          <span><span class="dot" style="background:#1e7e34"></span> Genehmigt durch AG</span>
          <span><span class="dot" style="background:#8e44ad"></span> Abgelehnt durch Büro</span>
          <span><span class="dot" style="background:repeating-linear-gradient(45deg,#c0392b,#c0392b 6px,#ffffff 6px,#ffffff 12px)"></span> Abgelehnt durch Arbeitgeber</span>
          <span><span class="dot" style="background:#aaa"></span> Storniert</span>
          <span><span class="dot" style="background:repeating-linear-gradient(45deg,#f5f5f5,#f5f5f5 3px,#e0e0e0 3px,#e0e0e0 6px);border:1px solid #ccc"></span> 🔒 Gesperrt</span>
        </div>
      </div>
    </div>
  </div>

  <!-- ══ TAB: NEUER ANTRAG ═══════════════════════════════════ -->
  <div id="tab-antrag" class="tab-panel">
    <div class="antrag-grid">

      <!-- Linke Spalte: Kalender -->
      <div class="mk-card">
        <div class="mk-card-head">
          <h2>📅 Zeitraum wählen</h2>
        </div>
        <div class="mk-card-body">
          <div class="kal-nav">
            <button type="button" id="kal-prev">‹</button>
            <div class="kal-month" id="kal-month-label"></div>
            <button type="button" id="kal-next">›</button>
          </div>
          <div class="kal-grid" id="kal-grid"></div>
          <div class="kal-hint" id="kal-hint">Auf einen freien Tag klicken, um einen Zeitraum zu wählen</div>
          <div class="kal-legend">
            <span><span class="dot" style="background:var(--navy2,#1a3a5c)"></span> Ausgewählt</span>
            <span><span class="dot" style="background:#d0e8ff;border:1px solid #4aa0e0"></span> Zeitraum</span>
            <span><span class="dot" style="background:#f0a500"></span> Beantragt durch Mitglied</span>
            <span><span class="dot" style="background:#1d4ed8"></span> Intern genehmigt</span>
            <span><span class="dot" style="background:repeating-linear-gradient(45deg,#2851a3,#2851a3 6px,#ffffff 6px,#ffffff 12px)"></span> Beantragt bei AG</span>
            <span><span class="dot" style="background:#1e7e34"></span> Genehmigt durch AG</span>
            <span><span class="dot" style="background:#8e44ad"></span> Abgelehnt durch Büro</span>
            <span><span class="dot" style="background:repeating-linear-gradient(45deg,#c0392b,#c0392b 6px,#ffffff 6px,#ffffff 12px)"></span> Abgelehnt durch Arbeitgeber</span>
            <span><span class="dot" style="background:#aaa"></span> Storniert</span>
            <span><span class="dot" style="background:repeating-linear-gradient(45deg,#f5f5f5,#f5f5f5 3px,#e0e0e0 3px,#e0e0e0 6px);border:1px solid #ccc"></span> 🔒 Gesperrt</span>
          </div>
        </div>
      </div>

      <!-- Rechte Spalte: Formular -->
      <div class="mk-card" style="position:relative;z-index:10">
        <div class="mk-card-head">
          <h2>✚ Antrag einreichen</h2>
        </div>
        <div class="mk-card-body">

          <div id="antrag-form-wrap">
            <p class="form-hint">
              Klicken Sie im Kalender auf einen Starttag, dann auf einen Endtag.
              Mehrere Zeiträume können hinzugefügt werden.
            </p>
            <div id="tk-auswahl-wrap" style="display:none;margin-bottom:16px">
              <label style="display:block;font-size:12px;font-weight:600;color:var(--navy2,#1a3a5c);margin-bottom:4px">
                Für welche TK? <span style="color:#c0392b">*</span>
              </label>
              <select id="tk-auswahl" style="width:100%;padding:9px 11px;border:1px solid var(--border,#dde2ea);border-radius:7px;font-size:13px">
                <option value="">– Bitte wählen –</option>
              </select>
              <div style="font-size:12px;color:var(--muted,#7a8fa6);margin-top:4px">Sie sind Mitglied in mehreren Tarifkommissionen - bitte auswählen, für welche dieser Antrag gilt.</div>
            </div>
            <div id="termine-list" class="termine-list"></div>
            <button type="button" class="btn-einreichen" id="submit-btn" disabled>
              Antrag einreichen →
            </button>
          </div>

          <div id="antrag-success" style="display:none">
            <div class="success-state">
              <span class="ico">✓</span>
              <h2>Antrag eingereicht!</h2>
              <p>Die Termine sind im Kalender sichtbar und werden geprüft.</p>
              <button type="button" class="btn-weiterer" id="btn-weiterer">+ Weiteren Antrag anlegen</button>
            </div>
          </div>

        </div>
      </div>

    </div>
  </div>

  <!-- ══ TAB: ALLE ANTRÄGE ═══════════════════════════════════ -->
  <div id="tab-liste" class="tab-panel">
    <div id="antrag-list">
      <div class="empty-state">
        <span class="ico">⏳</span>
        <p>Anträge werden geladen …</p>
      </div>
    </div>
  </div>

</main>

<footer>© <?= date('Y') ?> Freistellungssystem Tarif
  &nbsp;·&nbsp;<a href="#" id="footer-logout" style="color:var(--muted)">Abmelden</a>
</footer>

<script>
/* ── Hilfsfunktionen ──────────────────────────────────────── */
function toIso(d){return`${d.getFullYear()}-${String(d.getMonth()+1).padStart(2,'0')}-${String(d.getDate()).padStart(2,'0')}`;}
function formatDt(iso){if(!iso)return'–';return new Date(iso.substring(0,10)).toLocaleDateString('de-DE',{day:'2-digit',month:'2-digit',year:'numeric'});}
function htmlEsc(s){return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');}
function veranstaltungLabel(info){
  if(!info)return'';
  if(info.veranstaltung==='sonstige'&&info.ereignis_bezeichnung)return info.ereignis_bezeichnung;
  return info.veranstaltung||'';
}
// TK-Hinweis für Kalender-Tooltips - nur bei Mehrfachmitgliedschaft, sonst
// überflüssig (steht schon im Header-Badge).
function tkSuffix(info){
  return (MK_TKS.length>1 && info && info.tk_kuerzel) ? (' · '+info.tk_kuerzel) : '';
}
function showAlert(msg){const b=document.getElementById('alert-box');b.innerHTML='⚠ '+htmlEsc(msg);b.classList.add('show');setTimeout(()=>b.classList.remove('show'),5000);}

const VERANSTALTUNGEN_BASIS=[{value:'Verhandlung',label:'Verhandlung'},{value:'TK Sitzung',label:'TK-Sitzung'},{value:'sonstige',label:'Sonstiges'}];
let VERANSTALTUNGEN=[...VERANSTALTUNGEN_BASIS];
const MK_AIRLINES=<?= json_encode($mk_airlines, JSON_UNESCAPED_UNICODE) ?>; // Array aller Airlines des Mitglieds

// Promise das resolved sobald Zusatz-Typen für alle Airlines geladen sind
const zusatzTypenGeladen = (async function ladeZusatzTypen(){
  if(!MK_AIRLINES.length) return;
  const bereitsGeladen = new Set(VERANSTALTUNGEN_BASIS.map(v => v.value));
  for(const airline of MK_AIRLINES){
    if(!airline) continue;
    const fd=new FormData();
    fd.append('action','get_veranstaltungen_fuer_airline');
    fd.append('csrf','<?= csrf_token() ?>');
    fd.append('airline',airline);
    try{
      const d=await(await fetch('api/antrag.php',{method:'POST',body:fd,credentials:'same-origin'})).json();
      if(d.ok && d.data.zusatz_typen && d.data.zusatz_typen.length){
        for(const z of d.data.zusatz_typen){
          if(!bereitsGeladen.has(z.veranstaltung)){
            VERANSTALTUNGEN.push({value:z.veranstaltung,label:z.label});
            bereitsGeladen.add(z.veranstaltung);
          }
        }
      }
    }catch(e){ console.warn('Zusatz-Typen konnten nicht geladen werden für',airline,':',e); }
  }
})();
const STATUS_LABEL={ausstehend:'Beantragt durch Mitglied',freigabe_buero:'Genehmigt durch Büro',beantragt_ag:'Beantragt bei AG',genehmigt:'Genehmigt durch AG',abgelehnt:'Abgelehnt durch Büro',abgelehnt_ag:'Abgelehnt durch Arbeitgeber',storno_buero:'Storniert'};
// Alle TKs, denen das eingeloggte Mitglied angehört (Mehrfachmitgliedschaft
// möglich). Bei genau einer TK wird beim Antrag-Stellen keine Auswahl
// angezeigt (automatisch übernommen); bei mehreren ist die Auswahl Pflicht.
const MK_TKS=<?= json_encode(array_map(fn($t)=>['id'=>(int)$t['id'],'label'=>$t['kuerzel'].' – '.$t['bezeichnung']], $mk_tks), JSON_UNESCAPED_UNICODE) ?>;
const MONATE=['Januar','Februar','März','April','Mai','Juni','Juli','August','September','Oktober','November','Dezember'];

let meineTage={}, deadlines=[], selStart=null, hoveredDate=null, termine=[];
// Diese Status geben den Tag wieder für einen neuen Antrag frei -
// intern abgelehnt, vom Arbeitgeber abgelehnt, oder eine vom Arbeitgeber
// BESTÄTIGTE Stornierung. Eine reine Büro-Stornierung (storno_buero)
// reicht bewusst NICHT aus, solange der Arbeitgeber sie nicht bestätigt
// hat (storno_bestaetigt_ag) - der vorherige Antrag bleibt dabei erhalten
// und in "Alle Anträge" sichtbar, blockiert aber ab dem jeweiligen Status
// keine erneute Antragstellung mehr für denselben Tag.
const TAG_WIEDER_FREI_STATUS = ['abgelehnt', 'abgelehnt_ag', 'storno_bestaetigt_ag'];
// Status, bei denen ein Klick auf den Kalendertag ein Aktions-Popup öffnet
// (Löschen bei ausstehend/freigabe_buero, Stornieren bei beantragt_ag/
// genehmigt - siehe zeigeKalenderAktionen). Alle anderen Status (abgelehnt,
// abgelehnt_ag, storno_buero, storno_bestaetigt_ag) sind bereits final/
// storniert und bieten stattdessen nur "In Liste anzeigen".
const KAL_AKTION_STATUS = ['ausstehend', 'freigabe_buero', 'beantragt_ag', 'genehmigt'];
let kal1Year, kal1Month, kal2Year, kal2Month;

/* ── Tab-Logik ────────────────────────────────────────────── */
document.querySelectorAll('.tab-btn').forEach(btn=>{
  btn.addEventListener('click',()=>{
    document.querySelectorAll('.tab-btn').forEach(b=>b.classList.remove('active'));
    document.querySelectorAll('.tab-panel').forEach(p=>p.classList.remove('active'));
    btn.classList.add('active');
    document.getElementById(btn.dataset.tab).classList.add('active');
    if(btn.dataset.tab==='tab-kalender') renderKal2();
    if(btn.dataset.tab==='tab-liste') ladeAntraege();
  });
});

/* ── Deadline-Hilfsfunktion ───────────────────────────────── */
function isGesperrt(iso) {
  const now = new Date();
  return deadlines.some(d => {
    if (!d.gesperrt) return false;
    return iso <= d.zeitraum_bis; // gesperrt = alles bis einschließlich diesem Datum
  });
}
function getDeadlineHinweis(iso) {
  const d = deadlines.find(d => d.gesperrt && iso <= d.zeitraum_bis);
  if (!d) return null;
  return (d.notiz ? d.notiz + ' – ' : '') +
    'Eingabefrist abgelaufen. Bei dringenden Änderungen bitte per E-Mail ans Tarifsekretariat wenden.';
}
// Aktualisiert nur die Hervorhebung (CSS-Klassen) der bereits vorhandenen
// "freien" Tage-Zellen, OHNE das DOM neu aufzubauen - anders als ein voller
// renderKal()-Aufruf. Grund: renderKal() ersetzt bei jedem Hover ALLE
// Zellen im Kalender neu, wodurch das Element unter dem Mauszeiger laufend
// ausgetauscht wird. In Chromium-basierten Browsern (Chrome, Edge, Safari)
// kann das dazu führen, dass der eigentliche Klick auf den Endtag verloren
// geht, weil die Zelle in dem Moment gerade neu erzeugt wurde - in Firefox
// trat das nicht auf. Diese Funktion ändert nur Klassen an bestehenden
// Elementen, sodass der Klick-Listener durchgehend an derselben Zelle hängt.
//
// Wichtig: fasst NUR Zellen an, die tatsächlich frei/wählbar sind (also
// auch durch Ablehnung/Storno wieder freigegebene Tage) - blockierte Tage
// (status-ausstehend/genehmigt/etc.) und gesperrte Tage bleiben unberührt.
function istZelleFrei(iso){
  const info = meineTage[iso];
  const infoBlockiert = info && !TAG_WIEDER_FREI_STATUS.includes(info.status);
  const isNew = termine.some(t=>iso>=t.von&&iso<=t.bis);
  const today = new Date(); today.setHours(0,0,0,0);
  const vergangen = new Date(iso) < today;
  return !infoBlockiert && !isNew && !vergangen;
}
function updateHoverPreview(){
  document.querySelectorAll('#kal-grid .kal-day[data-iso]').forEach(cell=>{
    const iso=cell.dataset.iso;
    if(!istZelleFrei(iso)) return;
    if(cell.classList.contains('kal-gesperrt')) return; // nie anfassen
    cell.classList.remove('kal-range-start','kal-range-end','kal-in-range');
    cell.classList.add('kal-free');
  });
  if(!selStart) return;
  if(hoveredDate){
    const rs2=selStart<=hoveredDate?toIso(selStart):toIso(hoveredDate);
    const re2=selStart<=hoveredDate?toIso(hoveredDate):toIso(selStart);
    document.querySelectorAll('#kal-grid .kal-day[data-iso]').forEach(cell=>{
      const iso=cell.dataset.iso;
      if(!istZelleFrei(iso)) return;
      if(cell.classList.contains('kal-gesperrt')) return;
      if(iso===rs2){cell.classList.remove('kal-free');cell.classList.add('kal-range-start');}
      if(iso===re2){cell.classList.remove('kal-free');cell.classList.add('kal-range-end');}
      if(iso>rs2&&iso<re2){cell.classList.remove('kal-free');cell.classList.add('kal-in-range');}
    });
  } else {
    const startIso=toIso(selStart);
    const cell=document.querySelector(`#kal-grid .kal-day[data-iso="${startIso}"]`);
    if(cell && !cell.classList.contains('kal-gesperrt')){cell.classList.remove('kal-free');cell.classList.add('kal-range-start');}
  }
}
function renderKal(){
  const grid=document.getElementById('kal-grid'),label=document.getElementById('kal-month-label');
  label.textContent=`${MONATE[kal1Month]} ${kal1Year}`;
  grid.innerHTML='';
  ['Mo','Di','Mi','Do','Fr','Sa','So'].forEach(d=>{const h=document.createElement('div');h.className='kal-head-cell';h.textContent=d;grid.appendChild(h);});
  const first=new Date(kal1Year,kal1Month,1),last=new Date(kal1Year,kal1Month+1,0);
  const today=new Date();today.setHours(0,0,0,0);
  let off=(first.getDay()+6)%7;
  for(let i=0;i<off;i++){const e=document.createElement('div');e.className='kal-day kal-empty';grid.appendChild(e);}
  for(let day=1;day<=last.getDate();day++){
    const date=new Date(kal1Year,kal1Month,day),iso=toIso(date);
    const cell=document.createElement('div');cell.className='kal-day';cell.textContent=day;cell.dataset.iso=iso;
    if(date.getTime()===today.getTime())cell.classList.add('kal-today');
    const info=meineTage[iso];
    const infoBlockiert = info && !TAG_WIEDER_FREI_STATUS.includes(info.status);
    const isNew=termine.some(t=>iso>=t.von&&iso<=t.bis);
    const vergangen=date<today;
    if(vergangen&&info){
      // Vergangener Tag MIT Status (egal ob ausstehend/genehmigt/abgelehnt/
      // storniert/...) - Status farblich weiterhin erkennbar, aber
      // ausgegraut (gedimmt) und nicht mehr anklickbar, da an einem
      // vergangenen Tag ohnehin nichts mehr bearbeitet werden kann.
      cell.classList.add('status-'+info.status,'kal-vergangen');
      cell.dataset.status=info.status;
      const tip=document.createElement('span');tip.className='kal-tooltip';
      tip.textContent=veranstaltungLabel(info)+' · '+(STATUS_LABEL[info.status]||info.status)+tkSuffix(info)+' · vergangen';
      cell.appendChild(tip);
    } else if(vergangen){
      cell.classList.add('kal-past');
    } else if(infoBlockiert){
      cell.classList.add('status-'+info.status);cell.dataset.status=info.status;
      const tip=document.createElement('span');tip.className='kal-tooltip';
      tip.textContent=veranstaltungLabel(info)+' · '+(STATUS_LABEL[info.status]||info.status)+tkSuffix(info)
        +(KAL_AKTION_STATUS.includes(info.status)?' · Klick für Aktionen':'');
      cell.appendChild(tip);
      cell.addEventListener('click',()=>{
        if(KAL_AKTION_STATUS.includes(info.status)){
          zeigeKalenderAktionen(cell, info);
        } else {
          document.querySelectorAll('.tab-btn')[2].click();
          setTimeout(()=>{const el=document.getElementById('antrag-'+info.antrag_id);if(el)el.scrollIntoView({behavior:'smooth',block:'center'});},100);
        }
      });
    } else if(isNew){
      const allVon=termine.map(t=>t.von).sort(),allBis=termine.map(t=>t.bis).sort();
      const rs=allVon[0],re=allBis[allBis.length-1];
      if(iso===rs&&iso===re)cell.classList.add('kal-range-start','kal-range-end');
      else if(iso===rs)cell.classList.add('kal-range-start');
      else if(iso===re)cell.classList.add('kal-range-end');
      else cell.classList.add('kal-in-range');
    } else {
      cell.classList.add('kal-free');
      // Deadline-Prüfung
      if(isGesperrt(iso)){
        cell.classList.remove('kal-free');
        cell.classList.add('kal-gesperrt');
        const dtip=document.createElement('span');dtip.className='kal-tooltip';
        dtip.textContent='🔒 '+(getDeadlineHinweis(iso)||'Eingabefrist abgelaufen');
        cell.innerHTML='';cell.textContent=day;cell.appendChild(dtip);
      } else {
        if(selStart&&hoveredDate){
          const rs2=selStart<=hoveredDate?toIso(selStart):toIso(hoveredDate);
          const re2=selStart<=hoveredDate?toIso(hoveredDate):toIso(selStart);
          if(iso===rs2){cell.classList.remove('kal-free');cell.classList.add('kal-range-start');}
          if(iso===re2){cell.classList.remove('kal-free');cell.classList.add('kal-range-end');}
          if(iso>rs2&&iso<re2){cell.classList.remove('kal-free');cell.classList.add('kal-in-range');}
        } else if(selStart&&iso===toIso(selStart)){
          cell.classList.remove('kal-free');cell.classList.add('kal-range-start');
        }
        cell.addEventListener('click',()=>onDayClick(date,iso));
        cell.addEventListener('mouseenter',()=>{if(selStart){hoveredDate=date;updateHoverPreview();}});
        cell.addEventListener('mouseleave',()=>{if(selStart){hoveredDate=null;updateHoverPreview();}});
      }
    }
    grid.appendChild(cell);
  }
}

/* ── Kalender 2 (Übersicht-Tab) ───────────────────────────── */
function renderKal2(){
  const grid=document.getElementById('kal2-grid'),label=document.getElementById('kal2-month-label');
  label.textContent=`${MONATE[kal2Month]} ${kal2Year}`;
  grid.innerHTML='';
  ['Mo','Di','Mi','Do','Fr','Sa','So'].forEach(d=>{const h=document.createElement('div');h.className='kal-head-cell';h.textContent=d;grid.appendChild(h);});
  const first=new Date(kal2Year,kal2Month,1),last=new Date(kal2Year,kal2Month+1,0);
  const today=new Date();today.setHours(0,0,0,0);
  let off=(first.getDay()+6)%7;
  for(let i=0;i<off;i++){const e=document.createElement('div');e.className='kal-day kal-empty';grid.appendChild(e);}
  for(let day=1;day<=last.getDate();day++){
    const date=new Date(kal2Year,kal2Month,day),iso=toIso(date);
    const cell=document.createElement('div');cell.className='kal-day';cell.textContent=day;
    if(date.getTime()===today.getTime())cell.classList.add('kal-today');
    const info=meineTage[iso];
    if(info){
      cell.classList.add('status-'+info.status);cell.dataset.status=info.status;
      const tip=document.createElement('span');tip.className='kal-tooltip';
      tip.textContent=veranstaltungLabel(info)+' · '+(STATUS_LABEL[info.status]||info.status)+tkSuffix(info)
        +(KAL_AKTION_STATUS.includes(info.status)?' · Klick für Aktionen':'');
      cell.appendChild(tip);
      if(KAL_AKTION_STATUS.includes(info.status)){
        cell.addEventListener('click',()=>zeigeKalenderAktionen(cell,info));
      }
    } else if(isGesperrt(iso)&&date>=today){
      cell.classList.add('kal-gesperrt');
      const tip=document.createElement('span');tip.className='kal-tooltip';
      tip.textContent='🔒 Eingabefrist abgelaufen';cell.appendChild(tip);
    } else if(date<today){
      cell.classList.add('kal-past');
    }
    grid.appendChild(cell);
  }
}

/* ── Tag-Klick ────────────────────────────────────────────── */
function onDayClick(date,iso){
  if(isGesperrt(iso)){
    showAlert(getDeadlineHinweis(iso)||'Eingabefrist für diesen Zeitraum abgelaufen.');
    return;
  }
  if(!selStart){
    selStart=date;
    document.getElementById('kal-hint').textContent='Enddatum wählen – oder gleichen Tag erneut für Einzeltag';
    renderKal();
  } else {
    const von=selStart<=date?toIso(selStart):iso,bis=selStart<=date?iso:toIso(selStart);
    const overlap=termine.some(t=>von<=t.bis&&bis>=t.von)||Object.keys(meineTage).some(d=>d>=von&&d<=bis&&!TAG_WIEDER_FREI_STATUS.includes(meineTage[d].status));
    if(overlap){
      showAlert('Dieser Zeitraum überschneidet sich mit einem bereits eingetragenen Termin.');
    } else {
      termine.push({id:Date.now(),von,bis,typ:'',bezeichnung:''});
      renderTermine();updateSubmitBtn();
      document.getElementById('kal-hint').textContent='Weiteren Zeitraum hinzufügen oder Antrag einreichen';
    }
    selStart=null;hoveredDate=null;renderKal();
  }
}

/* ── Termin-Chips ─────────────────────────────────────────── */
async function renderTermine(){
  // Sicherstellen dass Zusatz-Typen geladen sind bevor das Dropdown gerendert wird
  await zusatzTypenGeladen;
  const list=document.getElementById('termine-list');list.innerHTML='';
  if(!termine.length){
    document.getElementById('kal-hint').textContent='Auf einen freien Tag klicken, um einen Zeitraum zu wählen';
    return;
  }
  termine.forEach(t=>{
    const chip=document.createElement('div');chip.className='termin-chip';
    chip.style.flexWrap='wrap';
    const lbl=t.von===t.bis?`📅 ${formatDt(t.von)}`:`📅 ${formatDt(t.von)} – ${formatDt(t.bis)}`;
    const opts=VERANSTALTUNGEN.map(v=>`<option value="${v.value}"${t.typ===v.value?' selected':''}>${v.label}</option>`).join('');
    const istSonstige=t.typ==='sonstige';
    const bezHtml=t.typ!==''?`<div style="flex:1 1 100%;margin-top:6px;display:flex;align-items:center;gap:6px"><input type="text" data-bez-id="${t.id}" placeholder="${istSonstige?'Bezeichnung der Veranstaltung *':'Optionaler Hinweis …'}" maxlength="150" value="${htmlEsc(t.bezeichnung||'')}" style="flex:1;padding:7px 10px;border:1px ${istSonstige?'solid':'dashed'} var(--border,#dde2ea);border-radius:6px;font-size:13px"><span style="font-size:11px;white-space:nowrap;color:${istSonstige?'#c0392b':'var(--muted,#7a8fa6)'}"> ${istSonstige?'Pflichtfeld':'Optional'}</span></div>`:'';
    chip.innerHTML=`<span class="tc-dates">${lbl}</span><span class="tc-type"><select data-id="${t.id}"><option value="">– Ereignisart –</option>${opts}</select></span><button type="button" class="tc-remove" data-id="${t.id}" title="Entfernen">×</button>${bezHtml}`;
    chip.querySelector('select').addEventListener('change',function(){const x=termine.find(x=>x.id==this.dataset.id);if(x){const altTyp=x.typ;x.typ=this.value;if(altTyp==='sonstige')x.bezeichnung='';renderTermine();updateSubmitBtn();}});
    chip.querySelector('.tc-remove').addEventListener('click',function(){termine=termine.filter(x=>x.id!=parseInt(this.dataset.id));renderTermine();updateSubmitBtn();renderKal();});
    const bezInput=chip.querySelector('[data-bez-id]');
    if(bezInput){bezInput.addEventListener('input',function(){const x=termine.find(x=>x.id==this.dataset.bezId);if(x){x.bezeichnung=this.value;updateSubmitBtn();}});}
    list.appendChild(chip);
  });
}
function updateSubmitBtn(){
  const btn=document.getElementById('submit-btn');
  const tkOk = MK_TKS.length<=1 || document.getElementById('tk-auswahl').value!=='';
  const ok=termine.length>0&&termine.every(t=>t.typ!==''&&(t.typ!=='sonstige'||t.bezeichnung.trim()!==''))&&tkOk;
  btn.disabled=!ok;
}

/* ── TK-Auswahl (nur bei Mehrfachmitgliedschaft) ─────────────── */
if(MK_TKS.length>1){
  const wrap=document.getElementById('tk-auswahl-wrap'),sel=document.getElementById('tk-auswahl');
  wrap.style.display='block';
  MK_TKS.forEach(tk=>{const o=document.createElement('option');o.value=tk.id;o.textContent=tk.label;sel.appendChild(o);});
  sel.addEventListener('change',updateSubmitBtn);
}

/* ── Antrag einreichen ───────────────────────────────────── */
document.getElementById('submit-btn').addEventListener('click',async()=>{
  if(!termine.length){showAlert('Bitte mindestens einen Termin eintragen.');return;}
  if(termine.some(t=>!t.typ)){showAlert('Bitte für jeden Termin eine Ereignisart auswählen.');return;}
  if(termine.some(t=>t.typ==='sonstige'&&!t.bezeichnung.trim())){showAlert('Bitte für jeden Termin mit Ereignisart „Sonstiges" eine Bezeichnung angeben.');return;}
  const tkGewaehlt = MK_TKS.length>1 ? document.getElementById('tk-auswahl').value : (MK_TKS[0]?.id ?? '');
  if(MK_TKS.length>1 && !tkGewaehlt){showAlert('Bitte auswählen, für welche Tarifkommission dieser Antrag gilt.');return;}
  const btn=document.getElementById('submit-btn');
  btn.disabled=true;btn.textContent='Wird gesendet …';
  const fd=new FormData();
  fd.append('csrf','<?= csrf_token() ?>');
  if(tkGewaehlt)fd.append('tk_id',tkGewaehlt);
  termine.forEach((t,i)=>{fd.append(`termine[${i}][von]`,t.von);fd.append(`termine[${i}][bis]`,t.bis);fd.append(`termine[${i}][typ]`,t.typ);fd.append(`termine[${i}][bezeichnung]`,t.bezeichnung.trim());});
  try{
    const j=await(await fetch('api/antrag.php',{method:'POST',body:fd,credentials:'same-origin'})).json();
    if(j.ok){
      document.getElementById('antrag-form-wrap').style.display='none';
      document.getElementById('antrag-success').style.display='block';
      termine=[];await ladeTage();renderKal();renderKal2();
    } else {
      showAlert(j.error||'Fehler beim Einreichen.');
      btn.disabled=false;btn.textContent='Antrag einreichen →';updateSubmitBtn();
    }
  }catch(e){
    showAlert('Verbindungsfehler. Bitte Seite neu laden.');
    btn.disabled=false;btn.textContent='Antrag einreichen →';updateSubmitBtn();
  }
});
document.getElementById('btn-weiterer').addEventListener('click',()=>{
  document.getElementById('antrag-form-wrap').style.display='block';
  document.getElementById('antrag-success').style.display='none';
  document.getElementById('submit-btn').disabled=true;
  document.getElementById('submit-btn').textContent='Antrag einreichen →';
  document.getElementById('kal-hint').textContent='Auf einen freien Tag klicken, um einen Zeitraum zu wählen';
});

/* ── Antrags-Liste ───────────────────────────────────────── */
function ladeAntraege(){
  return fetch('api/meine_antraege.php',{credentials:'same-origin'}).then(r=>r.json()).then(d=>{
    if(!d.ok){document.getElementById('antrag-list').innerHTML='<div class="empty-state"><span class="ico">⚠️</span><p>Fehler beim Laden.</p></div>';return;}
    renderAntraege(d.data);
  }).catch(()=>{document.getElementById('antrag-list').innerHTML='<div class="empty-state"><span class="ico">⚠️</span><p>Verbindungsfehler.</p></div>';});
}
function htmlEsc(s){return String(s??'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');}
function renderAntraege(antraege){
  window._meineAntraege = antraege; // für oeffneStornoDialog() - Zugriff auf a.tage per ID
  const list=document.getElementById('antrag-list');
  if(!antraege.length){
    list.innerHTML='<div class="empty-state"><span class="ico">📋</span><p>Noch keine Anträge eingereicht.</p><small>Klicken Sie auf „Neuer Antrag" um zu starten.</small></div>';
    return;
  }
  list.innerHTML='';
  antraege.forEach(a=>{
    const item=document.createElement('div');item.className='antrag-item';item.id='antrag-'+a.id;
    const von=formatDt(a.zeitraum_von),bis=formatDt(a.zeitraum_bis);
    const zeitraum=a.zeitraum_von===a.zeitraum_bis?von:`${von} – ${bis}`;
    // TK-Kürzel nur anzeigen, wenn das Mitglied mehreren TKs angehört -
    // bei nur einer TK ist es redundant (steht schon im Header-Badge).
    const tkZeile = MK_TKS.length>1 ? `<div class="ai-zeitraum" style="margin-top:2px"><span class="tk-badge" style="font-size:10px;padding:1px 8px">${htmlEsc(a.tk_kuerzel||'')}</span></div>` : '';
    const bc={ausstehend:'sb-ausstehend',freigabe_buero:'sb-freigabe_buero',beantragt_ag:'sb-beantragt_ag',genehmigt:'sb-genehmigt',abgelehnt:'sb-abgelehnt',abgelehnt_ag:'sb-abgelehnt_ag',storno_buero:'sb-storno_buero'}[a.status]||'sb-ausstehend';
    const entsch=a.entschieden_am?`<div class="ai-field"><strong>Entschieden am</strong>${formatDt(a.entschieden_am)}</div>`:'';
    const chips=a.tage.map(t=>`<span class="tag-chip tc-${t.status}">${formatDt(t.tag)}</span>`).join('');
    const heuteIso=toIso(new Date());
    const hatVergangeneTage = a.tage.some(t=>t.tag<heuteIso);
    // Für's Stornieren zählt NICHT "keine Tage in der Vergangenheit", sondern
    // "es gibt noch mind. einen offenen Tag in der Zukunft/heute" - sonst
    // verschwindet der Stornieren-Button komplett, sobald bei einem
    // mehrtägigen, bereits beim AG beantragten/genehmigten Antrag auch nur
    // EIN Tag begonnen hat, obwohl die übrigen Tage noch stornierbar wären.
    const hatOffeneZukunftsTage = a.tage.some(t=>t.status!=='storno_buero' && t.tag>=heuteIso);
    const kannLoeschen = ['ausstehend','freigabe_buero'].includes(a.status) && !hatVergangeneTage;
    const kannStornieren = ['beantragt_ag','genehmigt'].includes(a.status) && hatOffeneZukunftsTage;
    item.innerHTML=`
      <div class="antrag-item-head">
        <div><div class="ai-titel">${htmlEsc(a.veranstaltung)}</div><div class="ai-zeitraum">${zeitraum}</div>${tkZeile}</div>
        <div style="display:flex;align-items:center;gap:8px">
          <span class="status-badge ${bc}">${STATUS_LABEL[a.status]||a.status}</span>
          ${kannLoeschen?`<button onclick="antragLoeschenBestaetigen(this,${a.id})"
            style="background:none;border:1px solid #e0c4c4;color:#c0392b;border-radius:6px;padding:3px 10px;font-size:12px;cursor:pointer;white-space:nowrap;transition:background .15s,color .15s"
            title="Antrag löschen">🗑 Löschen</button>`:''}
          ${kannStornieren?`<button onclick="oeffneStornoDialog(${a.id})"
            style="background:none;border:1px solid #e0c4c4;color:#c0392b;border-radius:6px;padding:3px 10px;font-size:12px;cursor:pointer;white-space:nowrap"
            title="Antrag stornieren">✕ Stornieren</button>`:''}
        </div>
      </div>
      <div class="antrag-item-body">
        <div class="ai-field"><strong>Airline</strong>${htmlEsc(a.airline)}</div>
        <div class="ai-field"><strong>Position</strong>${htmlEsc(a.position)}</div>
        <div class="ai-field"><strong>Muster</strong>${htmlEsc(a.flugzeugmuster)}</div>
        <div class="ai-field"><strong>Eingereicht</strong>${formatDt(a.eingereicht_am)}</div>
        ${entsch}
      </div>
      ${chips?`<div class="antrag-tage-list">${chips}</div>`:''}`;
    list.appendChild(item);
  });
}

/* ── Kalender-Aktions-Popup ──────────────────────────────── */
function zeigeKalenderAktionen(cell, info){
  // Altes Popup entfernen
  document.getElementById('kal-popup')?.remove();

  const popup = document.createElement('div');
  popup.id = 'kal-popup';
  popup.style.cssText = `
    position:absolute; z-index:100;
    background:#fff; border:1px solid var(--border,#dde2ea);
    border-radius:10px; box-shadow:0 4px 20px rgba(0,0,0,.15);
    padding:14px; min-width:220px; font-size:13px;
  `;
  const kannLoeschenHier    = ['ausstehend','freigabe_buero'].includes(info.status);
  const kannStornierenHier  = ['beantragt_ag','genehmigt'].includes(info.status);
  const aktionBtn = kannLoeschenHier
    ? `<button onclick="antragLoeschenBestaetigen(this,${info.antrag_id})"
        style="background:#c0392b;color:#fff;border:none;border-radius:7px;padding:8px 12px;font-size:13px;font-weight:700;cursor:pointer;text-align:left;transition:background .15s">
        🗑 Antrag löschen
      </button>`
    : kannStornierenHier
    ? `<button onclick="document.getElementById('kal-popup')?.remove();oeffneStornoDialog(${info.antrag_id});"
        style="background:#c0392b;color:#fff;border:none;border-radius:7px;padding:8px 12px;font-size:13px;font-weight:700;cursor:pointer;text-align:left">
        ✕ Antrag stornieren
      </button>`
    : '';
  popup.innerHTML = `
    <div style="font-weight:700;color:var(--navy2,#1a3a5c);margin-bottom:4px">${htmlEsc(veranstaltungLabel(info)||'Antrag')}</div>
    <div style="font-size:12px;color:var(--muted);margin-bottom:12px">Status: ${htmlEsc(STATUS_LABEL[info.status]||info.status)}</div>
    <div style="display:flex;flex-direction:column;gap:6px">
      ${aktionBtn}
      <button onclick="document.querySelectorAll('.tab-btn')[2].click();setTimeout(()=>{const el=document.getElementById('antrag-${info.antrag_id}');if(el)el.scrollIntoView({behavior:'smooth',block:'center'});},100);document.getElementById('kal-popup')?.remove();"
        style="background:#f0f2f5;color:var(--navy2,#1a3a5c);border:none;border-radius:7px;padding:8px 12px;font-size:13px;font-weight:600;cursor:pointer;text-align:left">
        📋 In Liste anzeigen
      </button>
      <button onclick="document.getElementById('kal-popup')?.remove();"
        style="background:none;border:none;color:var(--muted);font-size:12px;cursor:pointer;padding:4px 0;text-align:left">
        Schließen
      </button>
    </div>
  `;

  // Popup relativ zum Kalender-Container positionieren
  const kal = cell.closest('.mk-card-body') || cell.closest('.card-body') || document.body;
  kal.style.position = 'relative';
  kal.appendChild(popup);

  // Position berechnen
  const cr  = cell.getBoundingClientRect();
  const kr  = kal.getBoundingClientRect();
  let left  = cr.left - kr.left;
  let top   = cr.bottom - kr.top + 4;

  // Nicht über den Rand hinaus
  if (left + 230 > kal.offsetWidth) left = kal.offsetWidth - 234;
  popup.style.left = left + 'px';
  popup.style.top  = top  + 'px';

  // Klick außerhalb schließt Popup
  setTimeout(()=>{
    document.addEventListener('click', function closer(e){
      if(!popup.contains(e.target) && e.target !== cell){
        popup.remove();
        document.removeEventListener('click', closer);
      }
    });
  }, 0);
}

/* ── Antrag löschen ──────────────────────────────────────── */
// Zwei-Klick-Bestätigung DIREKT am Button statt eines nativen
// confirm()-Popups: das native Popup sieht optisch völlig anders aus als
// der Rest der Oberfläche (schmale, ungestylte System-Box) und wurde
// dadurch beim Stornieren bereits einmal leicht übersehen bzw. unbewusst
// weggeklickt (Klick schien "nichts zu tun"). Erster Klick "bewaffnet" den
// Button (Text/Farbe ändern sich sichtbar), erst der zweite Klick löst
// tatsächlich das Löschen aus. Nach 4s ohne zweiten Klick wird automatisch
// zurückgesetzt.
function antragLoeschenBestaetigen(btn, id){
  if(btn.dataset.armed === '1'){
    clearTimeout(Number(btn.dataset.armTimer));
    antragLoeschen(id);
    return;
  }
  btn.dataset.armed = '1';
  btn.dataset.originalHtml = btn.innerHTML;
  btn.innerHTML = 'Wirklich löschen?';
  btn.style.background = '#c0392b';
  btn.style.color = '#fff';
  btn.style.borderColor = '#c0392b';
  const timer = setTimeout(()=>{
    if(!btn.isConnected) return;
    btn.dataset.armed = '';
    btn.innerHTML = btn.dataset.originalHtml;
    btn.style.background = 'none';
    btn.style.color = '#c0392b';
    btn.style.borderColor = '#e0c4c4';
  }, 4000);
  btn.dataset.armTimer = timer;
}
async function antragLoeschen(id){
  const fd=new FormData();
  fd.append('csrf','<?= csrf_token() ?>');
  fd.append('antrag_id',id);
  try{
    const j=await(await fetch('api/antrag_loeschen.php',{method:'POST',body:fd,credentials:'same-origin'})).json();
    if(j.ok){
      document.getElementById('antrag-'+id)?.remove();
      document.getElementById('kal-popup')?.remove();
      await ladeTage();await ladeAntraege();renderKal();renderKal2();
      showAlert('Antrag wurde gelöscht.');
      // Alert grün färben
      const b=document.getElementById('alert-box');
      b.style.background='#d4edda';b.style.color='#155724';b.style.borderColor='#c3e6cb';
      setTimeout(()=>{b.classList.remove('show');b.style.background='';b.style.color='';b.style.borderColor='';},4000);
    } else {
      showAlert(j.error||'Fehler beim Löschen.');
    }
  }catch(e){
    showAlert('Verbindungsfehler.');
  }
}

/* ── Antrag stornieren (nur ab Status „beantragt_ag") ────── */
// ── Storno-Dialog mit Tage-Auswahl ─────────────────────────────
function oeffneStornoDialog(id){
  const a = (window._meineAntraege||[]).find(x => x.id === id);
  if(!a){ showAlert('Antrag nicht gefunden.'); return; }
  const heuteIso=toIso(new Date());
  // Bereits vergangene Tage sind grundsätzlich nicht mehr stornierbar
  // (Büro kontaktieren) - werden hier einfach aus der Auswahl
  // ausgeschlossen, statt den kompletten Dialog zu blockieren, damit noch
  // offene, zukünftige Tage desselben Antrags weiterhin storniert werden
  // können.
  const offeneTage = a.tage.filter(t => t.status !== 'storno_buero' && t.tag >= heuteIso);
  if(!offeneTage.length){ showAlert('Für diesen Antrag sind keine stornierbaren Tage mehr vorhanden.'); return; }

  const alt = document.getElementById('storno-dialog-overlay');
  if(alt) alt.remove();

  const tageHtml = offeneTage.map(t => `
    <label style="display:flex;align-items:center;gap:8px;padding:6px 4px;border-bottom:1px solid #f0f2f5;cursor:pointer;font-size:14px">
      <input type="checkbox" class="storno-tag-cb" value="${t.tag}" checked style="width:16px;height:16px">
      ${formatDt(t.tag)}
    </label>`).join('');

  const overlay = document.createElement('div');
  overlay.id = 'storno-dialog-overlay';
  overlay.style.cssText = 'position:fixed;inset:0;background:rgba(15,39,68,.45);display:flex;align-items:center;justify-content:center;z-index:200;padding:20px';
  overlay.innerHTML = `
    <div style="background:#fff;border-radius:12px;max-width:440px;width:100%;padding:26px;box-shadow:0 12px 40px rgba(0,0,0,.25)">
      <h3 style="margin:0 0 6px;color:var(--navy,#0f2744)">Antrag stornieren</h3>
      <p style="margin:0 0 14px;color:var(--muted,#7a8fa6);font-size:13px">„${htmlEsc(a.veranstaltung)}" – wählen Sie, welche Tage storniert werden sollen.</p>
      <div style="display:flex;gap:10px;margin-bottom:10px">
        <button type="button" onclick="document.querySelectorAll('.storno-tag-cb').forEach(c=>c.checked=true)" style="font-size:12px;background:none;border:1px solid #dde2ea;border-radius:6px;padding:3px 10px;cursor:pointer">Alle</button>
        <button type="button" onclick="document.querySelectorAll('.storno-tag-cb').forEach(c=>c.checked=false)" style="font-size:12px;background:none;border:1px solid #dde2ea;border-radius:6px;padding:3px 10px;cursor:pointer">Keine</button>
      </div>
      <div style="max-height:220px;overflow-y:auto;border:1px solid #eef1f5;border-radius:8px;padding:0 10px;margin-bottom:14px">${tageHtml}</div>
      <label style="font-size:12px;font-weight:600;color:var(--muted,#7a8fa6);display:block;margin-bottom:4px">Stornierungsgrund (Pflichtfeld)</label>
      <textarea id="storno-grund-input" rows="2" style="width:100%;box-sizing:border-box;padding:8px 10px;border:1px solid #dde2ea;border-radius:6px;font-size:14px;font-family:inherit;resize:vertical" placeholder="z.B. Termin fällt aus"></textarea>
      <p style="margin:10px 0 0;font-size:12px;color:var(--muted,#7a8fa6);line-height:1.4">Der Arbeitgeber wird per E-Mail informiert (Büro in CC). Dieser Vorgang kann nicht rückgängig gemacht werden.</p>
      <div style="display:flex;gap:10px;margin-top:18px">
        <button onclick="document.getElementById('storno-dialog-overlay').remove()" style="flex:1;padding:10px;border-radius:8px;border:1px solid #dde2ea;background:#fff;cursor:pointer">Abbrechen</button>
        <button onclick="stornoDialogAbsenden(${a.id})" style="flex:1;padding:10px;border-radius:8px;border:none;background:#c0392b;color:#fff;font-weight:700;cursor:pointer">Stornieren</button>
      </div>
    </div>`;
  document.body.appendChild(overlay);
}

async function stornoDialogAbsenden(id){
  const gewaehlt = [...document.querySelectorAll('.storno-tag-cb:checked')].map(cb => cb.value);
  if(!gewaehlt.length){ alert('Bitte mindestens einen Tag auswählen.'); return; }
  const grund = document.getElementById('storno-grund-input').value.trim();
  if(grund === ''){ alert('Bitte einen Stornierungsgrund angeben.'); return; }
  // Bewusst KEIN zusätzliches natives confirm()-Popup mehr hier: der
  // eigentliche Dialog (Tage-Auswahl + Pflichtfeld Grund + expliziter Klick
  // auf "Stornieren") ist bereits Bestätigung genug. Ein natives
  // Browser-confirm() sah optisch völlig anders aus als der Rest des
  // Dialogs (schmale, ungestylte System-Box) und wurde dadurch leicht
  // übersehen bzw. unbewusst weggeklickt - für das Mitglied sah es dann so
  // aus, als würde der "Stornieren"-Klick gar nichts tun, obwohl technisch
  // einfach nur das unsichtbare zweite Popup mit "Abbrechen" beendet wurde.

  const fd=new FormData();
  fd.append('csrf','<?= csrf_token() ?>');
  fd.append('antrag_id',id);
  fd.append('grund',grund);
  gewaehlt.forEach(t => fd.append('tage[]', t));
  try{
    const j=await(await fetch('api/antrag_storno.php',{method:'POST',body:fd,credentials:'same-origin'})).json();
    if(j.ok){
      document.getElementById('storno-dialog-overlay')?.remove();
      await ladeAntraege();await ladeTage();renderKal();renderKal2();
      const meldung = j.vollstaendig
        ? (j.email_gesendet ? 'Antrag wurde vollständig storniert. Der Arbeitgeber wurde per E-Mail informiert.' : 'Antrag wurde vollständig storniert. Hinweis: E-Mail an den Arbeitgeber konnte nicht versendet werden – bitte beim Büro melden.')
        : (j.email_gesendet ? `${gewaehlt.length} Tag(e) wurden storniert, der Rest des Antrags bleibt bestehen. Der Arbeitgeber wurde per E-Mail informiert.` : `${gewaehlt.length} Tag(e) wurden storniert. Hinweis: E-Mail an den Arbeitgeber konnte nicht versendet werden – bitte beim Büro melden.`);
      showAlert(meldung);
      const b=document.getElementById('alert-box');
      b.style.background='#d4edda';b.style.color='#155724';b.style.borderColor='#c3e6cb';
      setTimeout(()=>{b.classList.remove('show');b.style.background='';b.style.color='';b.style.borderColor='';},5000);
    } else {
      showAlert(j.error||'Fehler beim Stornieren.');
    }
  }catch(e){
    showAlert('Verbindungsfehler.');
  }
}

/* ── Tage + Deadlines laden ──────────────────────────────── */
function ladeTage(){
  return fetch('api/meine_tage.php',{credentials:'same-origin'}).then(r=>r.json()).then(d=>{
    meineTage={};
    if(d.ok)d.data.forEach(t=>{meineTage[t.tag]={status:t.status,veranstaltung:t.veranstaltung,ereignis_bezeichnung:t.ereignis_bezeichnung,antrag_id:t.antrag_id};});
  }).catch(()=>{});
}
function ladeDeadlines(){
  return fetch('api/deadlines.php',{credentials:'same-origin'}).then(r=>r.json()).then(d=>{
    deadlines=[];
    if(d.ok)deadlines=d.data;
  }).catch(()=>{});
}

/* ── Session-Timer & Logout ──────────────────────────────── */
(function(){
  // Muss mit MITGLIED_SESSION_LIFETIME (_backend/config.php) übereinstimmen,
  // sonst zeigt der Countdown eine falsche Restzeit an und die Session
  // läuft serverseitig ab, bevor der Client-Timer bei 0 ankommt.
  const S=15*60; let r=S;
  const el=document.getElementById('session-timer');

  function updateTimer(){
    if(!el) return;
    const m=String(Math.floor(r/60)).padStart(2,'0'),s=String(r%60).padStart(2,'0');
    el.textContent=`⏱ ${m}:${s}`;
    el.classList.toggle('warn', r<=300);
  }

  function logout(){ window.location.href='api/mitglieder_logout.php'; }

  updateTimer(); // sofort beim Laden anzeigen
  setInterval(()=>{
    r--;
    if(r<=0){ logout(); return; }
    updateTimer();
  },1000);

  ['mousemove','keydown','click','scroll'].forEach(ev=>
    document.addEventListener(ev,()=>{ r=S; updateTimer(); },{passive:true}));

  document.getElementById('btn-logout').addEventListener('click',logout);
  document.getElementById('footer-logout')?.addEventListener('click',e=>{e.preventDefault();logout();});
})();

/* ── Init ────────────────────────────────────────────────── */
(function(){
  const now=new Date();
  kal1Year=kal2Year=now.getFullYear();
  kal1Month=kal2Month=now.getMonth();
  Promise.all([ladeTage(), ladeDeadlines(), ladeAntraege()]).then(()=>{ renderKal(); renderKal2(); });
  document.getElementById('kal-prev').addEventListener('click',()=>{if(--kal1Month<0){kal1Month=11;kal1Year--;}renderKal();});
  document.getElementById('kal-next').addEventListener('click',()=>{if(++kal1Month>11){kal1Month=0;kal1Year++;}renderKal();});
  document.getElementById('kal2-prev').addEventListener('click',()=>{if(--kal2Month<0){kal2Month=11;kal2Year--;}renderKal2();});
  document.getElementById('kal2-next').addEventListener('click',()=>{if(++kal2Month>11){kal2Month=0;kal2Year++;}renderKal2();});
})();
</script>
</body>
</html>
