<?php
// buero/finance.php  –  Finance-Übersicht
require_once __DIR__ . '/../_backend/bootstrap.php';
require_once __DIR__ . '/../_backend/panel.php';
send_security_headers();
$auth = require_login('buero');

panel_head('Finance');
panel_sidebar('finance', $auth['rolle'], $auth['user']);
panel_topbar('Finance');
?>

<?php
// Inline-Styles nur für diese Seite
?>
<style>
.fin-tabs           { display:flex; gap:0; border-bottom:1px solid var(--border); margin-bottom:1.5rem; }
.fin-tab            { padding:10px 20px; cursor:pointer; font-size:14px; color:var(--muted);
                      border-bottom:2px solid transparent; background:none; border-top:none;
                      border-left:none; border-right:none; transition:color .15s; }
.fin-tab:hover      { color:var(--text); }
.fin-tab.active     { color:var(--text); border-bottom-color:var(--primary); font-weight:600; }
.fin-section        { display:none; }
.fin-section.active { display:block; }

.fin-filters        { display:flex; gap:10px; flex-wrap:wrap; align-items:flex-end; margin-bottom:1.25rem; }
.fin-filters > div  { display:flex; flex-direction:column; gap:4px; }
.fin-filters label  { font-size:12px; color:var(--muted); font-weight:500; }
.fin-filters select,
.fin-filters input  { font-size:13px; }

.fin-metrics        { display:grid; grid-template-columns:repeat(4,1fr); gap:12px; margin-bottom:1.25rem; }
.fin-metric         { background:var(--surface); border:1px solid var(--border);
                      border-radius:var(--radius); padding:1rem; }
.fin-metric .label  { font-size:12px; color:var(--muted); margin-bottom:6px; }
.fin-metric .value  { font-size:22px; font-weight:700; color:var(--text); }
.fin-metric .sub    { font-size:11px; color:var(--muted); margin-top:3px; }

.pos-table          { width:100%; border-collapse:collapse; font-size:13px; }
.pos-table th       { font-size:11px; font-weight:600; color:var(--muted); text-align:left;
                      padding:7px 10px; border-bottom:1px solid var(--border); white-space:nowrap; }
.pos-table td       { padding:7px 10px; border-bottom:1px solid var(--border); vertical-align:middle; }
.pos-table tbody tr:last-child td { border-bottom:none; }
.pos-table tbody tr:hover td      { background:var(--surface); }
.pos-table tfoot td { padding:8px 10px; font-weight:700; border-top:1px solid var(--border); }
.pos-table input[type=number] { width:80px; font-size:13px; text-align:right; }

.chart-toggles      { display:flex; gap:4px; }
.chart-toggle       { padding:5px 14px; border:1px solid var(--border); border-radius:var(--radius);
                      background:var(--surface); color:var(--muted); cursor:pointer; font-size:12px; }
.chart-toggle.active{ background:var(--primary); border-color:var(--primary); color:#fff; }

.q-bars      { display:flex; align-items:flex-end; gap:3px; height:26px; }
.q-bar-col   { width:9px; height:100%; background:rgba(0,0,0,.06); border-radius:2px;
               display:flex; align-items:flex-end; overflow:hidden; }
.q-bar-fill  { width:100%; border-radius:2px 2px 0 0; min-height:2px; }
.q-bar-label { font-size:9px; color:var(--muted); text-align:center; width:9px; }

.badge-kat          { display:inline-block; padding:2px 9px; border-radius:20px;
                      font-size:11px; font-weight:700; }
.kat-verhandlung    { background:#dbeafe; color:#1e40af; }
.kat-tk_sitzung     { background:#d1fae5; color:#065f46; }
.kat-sonstiges      { background:#fef3c7; color:#92400e; }

@media(max-width:700px) {
  .fin-metrics { grid-template-columns:1fr 1fr; }
  .hide-mobile { display:none; }
}
</style>

<!-- ══ Tabs ══════════════════════════════════════════════════ -->
<div class="fin-tabs">
  <button class="fin-tab active" onclick="finTab('chart')">Statistik</button>
  <button class="fin-tab"        onclick="finTab('stats')">Kosten &amp; Tage</button>
  <button class="fin-tab"        onclick="finTab('config')">Konfiguration</button>
  <button class="fin-tab"        onclick="finTab('budget')">Budgets</button>
</div>

<div class="fin-section active" id="fin-chart">

  <div class="card" style="margin-bottom:1rem">
    <div class="card-body">
      <div class="fin-filters">
        <div>
          <label>Von</label>
          <input type="date" id="ch-von" value="<?= date('Y') ?>-01-01">
        </div>
        <div>
          <label>Bis</label>
          <input type="date" id="ch-bis" value="<?= date('Y') ?>-12-31">
        </div>
        <div>
          <label>Airline</label>
          <select id="ch-airline">
            <option value="">Alle</option>
          </select>
        </div>
        <div>
          <label>Granularität</label>
          <div class="chart-toggles" style="margin-top:4px">
            <button class="chart-toggle active" id="gr-monthly"   onclick="setGran('monthly')">Monatlich</button>
            <button class="chart-toggle"        id="gr-quarterly" onclick="setGran('quarterly')">Quartal</button>
            <button class="chart-toggle"        id="gr-yearly"    onclick="setGran('yearly')">Jährlich</button>
          </div>
        </div>
        <div>
          <label>&nbsp;</label>
          <button class="btn btn-primary" onclick="loadChart()">Aktualisieren</button>
        </div>
      </div>
    </div>
  </div>

  <div class="card" style="margin-bottom:1rem">
    <div class="card-head"><h2>Kosten je Periode &amp; Code</h2></div>
    <div class="card-body">
      <div id="chart-legend" style="display:flex;gap:16px;flex-wrap:wrap;margin-bottom:12px;font-size:12px;color:var(--muted)">
        <!-- wird dynamisch befüllt -->
      </div>
      <div style="position:relative;width:100%;height:320px">
        <canvas id="chart-bar"
                role="img"
                aria-label="Gestapeltes Balkendiagramm der Freistellungskosten je Periode">
          Freistellungskosten nach Periode.
        </canvas>
      </div>
    </div>
  </div>

  <div class="card">
    <div class="card-head"><h2>Kostenverteilung nach Code</h2></div>
    <div class="card-body" style="display:flex;gap:2rem;align-items:center;flex-wrap:wrap">
      <div style="position:relative;width:220px;height:220px;flex-shrink:0">
        <canvas id="chart-donut"
                role="img"
                aria-label="Donut-Diagramm Kostenverteilung nach Freistellungscode">
          Kostenverteilung.
        </canvas>
      </div>
      <div id="donut-legend" style="font-size:13px;color:var(--muted)">–</div>
    </div>
  </div>

</div><!-- /fin-chart -->


<div class="fin-section" id="fin-stats">

  <div class="card" style="margin-bottom:1rem">
    <div class="card-body">
      <div class="fin-filters">
        <div>
          <label>Von</label>
          <input type="date" id="st-von" value="<?= date('Y') ?>-01-01">
        </div>
        <div>
          <label>Bis</label>
          <input type="date" id="st-bis" value="<?= date('Y') ?>-12-31">
        </div>
        <div>
          <label>Airline</label>
          <select id="st-airline">
            <option value="">Alle</option>
          </select>
        </div>
        <div>
          <label>Kategorie</label>
          <select id="st-code">
            <option value="">Alle</option>
            <option value="verhandlung">Verhandlung</option>
            <option value="tk_sitzung">TK-Sitzung</option>
            <option value="sonstiges">Sonstiges</option>
          </select>
        </div>
        <div>
          <label>Freistellungscode</label>
          <select id="st-fscode">
            <option value="">Alle</option>
          </select>
        </div>
        <div>
          <label>&nbsp;</label>
          <button class="btn btn-primary" onclick="loadStats()">Filtern</button>
        </div>
        <div>
          <label>&nbsp;</label>
          <button class="btn btn-outline" onclick="exportRechnungsdb()">CSV-Export (Rechnungsdatenbank)</button>
        </div>
        <div>
          <label>&nbsp;</label>
          <button class="btn btn-outline" onclick="exportFinanceQuartal()">Excel-Export (Quartalsmonitoring)</button>
        </div>
      </div>
    </div>
  </div>

  <div class="fin-metrics">
    <div class="fin-metric">
      <div class="label">Gesamtkosten</div>
      <div class="value" id="m-kosten">–</div>
      <div class="sub">Alle Airlines</div>
    </div>
    <div class="fin-metric">
      <div class="label">Freistellungstage</div>
      <div class="value" id="m-tage">–</div>
      <div class="sub">Gesamt</div>
    </div>
    <div class="fin-metric">
      <div class="label">Ø Kosten / Tag</div>
      <div class="value" id="m-avg">–</div>
      <div class="sub">Alle Codes</div>
    </div>
    <div class="fin-metric">
      <div class="label">Anträge</div>
      <div class="value" id="m-antraege">–</div>
      <div class="sub">Im Zeitraum</div>
    </div>
  </div>

  <div class="card">
    <div class="card-body-raw">
      <div class="table-scroll">
        <table class="pos-table">
          <thead><tr>
            <th>Airline</th>
            <th>Code</th>
            <th style="text-align:right" class="hide-mobile">CPT-Tage</th>
            <th style="text-align:right" class="hide-mobile">SFO-Tage</th>
            <th style="text-align:right" class="hide-mobile">FO-Tage</th>
            <th style="text-align:right">Tage ges.</th>
            <th style="text-align:right">Kosten</th>
          </tr></thead>
          <tbody id="stats-tbody">
            <tr><td colspan="7" style="text-align:center;padding:24px;color:var(--muted)">
              Bitte Filter anwenden …
            </td></tr>
          </tbody>
          <tfoot id="stats-tfoot"></tfoot>
        </table>
      </div>
    </div>
  </div>

</div><!-- /fin-stats -->


<!-- ══ Tab 3: Konfiguration ══════════════════════════════════ -->
<div class="fin-section" id="fin-config">

  <div class="card" style="margin-bottom:1rem">
    <div class="card-body">
      <div class="fin-filters" style="margin-bottom:0">
        <div>
          <label>Airline</label>
          <select id="cfg-airline" onchange="loadConfig()">
            <option value="">– Airline wählen –</option>
          </select>
        </div>
      </div>
    </div>
  </div>

  <div class="card">
    <div class="card-head">
      <h2>Freistellungsarten, Codes &amp; Tagessätze</h2>
      <span style="font-size:12px;color:var(--muted)">Konfiguration je Airline</span>
    </div>
    <div class="card-body-raw">
      <div class="table-scroll">
        <table class="pos-table">
          <thead><tr>
            <th>Freistellungsart</th>
            <th>Freistellungscode</th>
            <th style="text-align:right">CPT (€/Tag)</th>
            <th style="text-align:right">SFO (€/Tag)</th>
            <th style="text-align:right">FO (€/Tag)</th>
          </tr></thead>
          <tbody id="cfg-tbody">
            <tr><td colspan="5" style="text-align:center;padding:24px;color:var(--muted)">Bitte Airline wählen …</td></tr>
          </tbody>
        </table>
      </div>
    </div>
    <div class="card-foot" style="display:flex;justify-content:flex-end;gap:8px;padding:1rem 1.25rem">
      <div id="cfg-msg" style="font-size:13px;align-self:center"></div>
      <?php if (panel_hat_rolle('finance')): ?>
      <button class="btn btn-primary" onclick="saveConfig()">Speichern</button>
      <?php else: ?>
      <span style="font-size:12px;color:var(--muted);align-self:center">Nur Finance-Administratoren können Preise ändern.</span>
      <?php endif; ?>
    </div>
  </div>

</div><!-- /fin-config -->

<div class="fin-section" id="fin-budget">

  <div style="display:flex;align-items:center;gap:6px;margin-bottom:20px;flex-wrap:wrap">
    <button class="btn btn-outline" id="jahr-prev" title="Vorheriges Jahr">‹</button>
    <div id="year-btns" style="display:flex;gap:6px;flex-wrap:wrap"></div>
    <button class="btn btn-outline" id="jahr-next" title="Nächstes Jahr">›</button>
  </div>

  <div style="display:grid;grid-template-columns:1fr 320px;gap:22px">

    <div class="card">
      <div class="card-head"><h2>Budgets <span id="jahr-label"><?= date('Y') ?></span></h2></div>
      <div class="card-body-raw">
        <div class="table-scroll">
          <table>
            <thead><tr>
              <th>Airline / TK / Code</th><th>Budget (Tage)</th>
              <th title="Tage, die aktuell noch unentschieden sind (ausstehend, Freigabe Büro oder beantragt beim Arbeitgeber)">Noch offen (unentschieden)</th>
              <th title="Tage, die final genehmigt sind - inkl. Storno Büro, solange der Arbeitgeber die Stornierung noch nicht bestätigt hat">Final genehmigt</th>
              <th>Verbleibend</th><th>Auslastung</th>
              <th>Final genehmigt je Quartal</th><th></th>
            </tr></thead>
            <tbody id="budget-tbody"><tr><td colspan="8" style="text-align:center;padding:24px;color:var(--muted)">Wird geladen …</td></tr></tbody>
          </table>
        </div>
      </div>
    </div>

    <div class="card" style="align-self:start">
      <div class="card-head"><h2>Budget hinterlegen</h2></div>
      <div class="card-body">
        <div id="alert-budget" style="display:none" class="alert"></div>
        <div class="fg" style="margin-bottom:13px">
          <label>Airline</label>
          <select id="b-airline" onchange="filterTKs(); loadCodesForAirline()">
            <option value="">– Bitte wählen –</option>
          </select>
        </div>
        <div class="fg" style="margin-bottom:13px">
          <label>Tarifkommission <span style="color:var(--muted);font-weight:400">(optional – für getrenntes Budget je TK)</span></label>
          <select id="b-tk">
            <option value="">– keine Aufteilung (ganze Airline) –</option>
          </select>
        </div>
        <div class="fg" style="margin-bottom:13px">
          <label>Freistellungscode <span style="color:var(--muted);font-weight:400">(optional – separates Budget je Code)</span></label>
          <select id="b-code">
            <option value="">– keine Aufteilung (ganze Airline) –</option>
          </select>
        </div>
        <div class="fg" style="margin-bottom:13px">
          <label>Jahr</label>
          <input type="number" id="b-jahr" value="<?= date('Y') ?>" min="2020">
        </div>
        <div class="fg" style="margin-bottom:20px">
          <label>Budget (Tage)</label>
          <input type="number" id="b-tage" step="0.5" min="0" placeholder="z.B. 20">
        </div>
        <button class="btn btn-primary btn-block" onclick="saveBudget()">Speichern</button>
      </div>
    </div>

  </div>

</div><!-- /fin-budget -->

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
const CSRF = '<?= csrf_token() ?>';
let granularitaet = 'monthly';
let barChart = null;
let donutChart = null;

function esc(s) {
  return String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');
}

// ── Hilfsfunktionen ───────────────────────────────────────
async function api(action, extra = {}) {
  const fd = new FormData();
  fd.append('action', action);
  fd.append('csrf', CSRF);
  Object.entries(extra).forEach(([k, v]) => fd.append(k, v));
  const r = await fetch('../api/buero.php', { method: 'POST', body: fd });
  return r.json();
}

function eur(n) {
  return n.toLocaleString('de-DE', { style: 'currency', currency: 'EUR', maximumFractionDigits: 0 });
}

function katLabel(k) {
  return { verhandlung: 'Verhandlung', tk_sitzung: 'TK-Sitzung', sonstiges: 'Sonstiges' }[k] ?? k;
}

// "Bis"-Felder: kein Datum vor "Von" erlauben (und umgekehrt)
function linkDateRange(vonId, bisId) {
  const von = document.getElementById(vonId);
  const bis = document.getElementById(bisId);
  if (!von || !bis) return;
  von.addEventListener('change', function () {
    bis.min = this.value || '';
    if (bis.value && bis.value < this.value) bis.value = this.value;
  });
  bis.addEventListener('change', function () {
    von.max = this.value || '';
    if (von.value && von.value > this.value) von.value = this.value;
  });
}
linkDateRange('ch-von', 'ch-bis');
linkDateRange('st-von', 'st-bis');

// ── Tab-Switching ─────────────────────────────────────────
function finTab(name) {
  document.querySelectorAll('.fin-tab').forEach((b, i) => {
    b.classList.toggle('active', ['chart','stats','config','budget'][i] === name);
  });
  document.querySelectorAll('.fin-section').forEach(s => s.classList.remove('active'));
  document.getElementById('fin-' + name).classList.add('active');

  if (name === 'stats'  && document.getElementById('stats-tbody').children.length === 1) loadStats();
  if (name === 'chart'  && !barChart) loadChart();
  if (name === 'config') loadConfig();
  if (name === 'budget' && !budgetInitialisiert) { budgetInitialisiert = true; loadAirlinesAndTKs(); loadBudgets(); }
}

// ── Airlines laden (einmalig) ─────────────────────────────
async function loadAirlines() {
  const d = await api('list_finance_airlines');
  if (!d.ok) return;
  const airlines = d.data.airlines;

  ['st-airline', 'ch-airline'].forEach(id => {
    const sel = document.getElementById(id);
    sel.innerHTML = '<option value="">Alle</option>' +
      airlines.map(a => `<option value="${a}">${a}</option>`).join('');
  });

  const cfgSel = document.getElementById('cfg-airline');
  cfgSel.innerHTML = '<option value="">– Airline wählen –</option>' +
    airlines.map(a => `<option value="${a}">${a}</option>`).join('');
}

// ── Freistellungscodes laden (einmalig, airlineübergreifend) ──
async function loadFreistellungscodesAlle() {
  const d = await api('list_freistellungscodes_alle');
  if (!d.ok) return;
  const sel = document.getElementById('st-fscode');
  sel.innerHTML = '<option value="">Alle</option>' +
    d.data.codes.map(c => `<option value="${c}">${c}</option>`).join('');
}

// ── Tab 3: Konfiguration ──────────────────────────────────
const KAT_LABELS = { verhandlung: 'Verhandlung', tk_sitzung: 'TK-Sitzung', sonstiges: 'Sonstiges' };

async function loadConfig() {
  const airline = document.getElementById('cfg-airline').value;
  const tbody   = document.getElementById('cfg-tbody');
  if (!tbody) return;

  if (!airline) {
    tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;padding:24px;color:var(--muted)">Bitte Airline wählen …</td></tr>';
    return;
  }

  tbody.innerHTML = '<tr><td colspan="5" style="text-align:center;padding:24px;color:var(--muted)">Wird geladen …</td></tr>';
  const d = await api('get_finance_config', { airline });
  if (!d.ok) {
    tbody.innerHTML = `<tr><td colspan="5" style="color:var(--danger);padding:16px">${d.error}</td></tr>`;
    return;
  }

  const { preise, codes } = d.data;
  tbody.innerHTML = ['verhandlung', 'tk_sitzung', 'sonstiges'].map(kat => `
    <tr>
      <td><span class="badge-kat kat-${kat}">${KAT_LABELS[kat]}</span></td>
      <td>
        <input type="text" id="code-${kat}" value="${codes?.[kat] ?? ''}"
               placeholder="z.B. FS"
               style="width:80px;font-family:monospace;font-size:13px;text-transform:uppercase"
               oninput="this.value=this.value.toUpperCase()">
      </td>
      <td style="text-align:right">
        <input type="number" min="0" step="0.01" id="preis-${kat}-CPT"
               value="${(preise[kat]?.CPT ?? 0).toFixed(2)}"
               style="width:85px;text-align:right;font-size:13px">
      </td>
      <td style="text-align:right">
        <input type="number" min="0" step="0.01" id="preis-${kat}-SFO"
               value="${(preise[kat]?.SFO ?? 0).toFixed(2)}"
               style="width:85px;text-align:right;font-size:13px">
      </td>
      <td style="text-align:right">
        <input type="number" min="0" step="0.01" id="preis-${kat}-FO"
               value="${(preise[kat]?.FO ?? 0).toFixed(2)}"
               style="width:85px;text-align:right;font-size:13px">
      </td>
    </tr>`).join('');
}

async function saveConfig() {
  const airline = document.getElementById('cfg-airline').value;
  if (!airline) { alert('Bitte zuerst eine Airline wählen.'); return; }

  const extra = { airline };
  ['verhandlung', 'tk_sitzung', 'sonstiges'].forEach(kat => {
    extra[`codes[${kat}]`] = document.getElementById(`code-${kat}`)?.value.trim().toUpperCase() ?? '';
    ['CPT', 'SFO', 'FO'].forEach(pos => {
      extra[`preise[${kat}][${pos}]`] = document.getElementById(`preis-${kat}-${pos}`)?.value ?? '0';
    });
  });

  const msg = document.getElementById('cfg-msg');
  const d = await api('save_finance_config', extra);
  if (d.ok) {
    msg.style.color = 'var(--success)'; msg.textContent = '✓ Gespeichert';
    setTimeout(() => msg.textContent = '', 3000);
  } else {
    msg.style.color = 'var(--danger)'; msg.textContent = d.error ?? 'Fehler beim Speichern.';
  }
}

// ── Tab 2: Kosten & Tage ──────────────────────────────────
async function loadStats() {
  const tbody = document.getElementById('stats-tbody');
  const tfoot = document.getElementById('stats-tfoot');
  tbody.innerHTML = '<tr><td colspan="7" style="text-align:center;padding:20px;color:var(--muted)">Wird geladen …</td></tr>';

  const d = await api('get_finance_stats', {
    von:     document.getElementById('st-von').value,
    bis:     document.getElementById('st-bis').value,
    airline: document.getElementById('st-airline').value,
    code:    document.getElementById('st-code').value,
    freistellungscode: document.getElementById('st-fscode').value,
  });

  if (!d.ok) {
    tbody.innerHTML = `<tr><td colspan="7" style="text-align:center;padding:20px;color:var(--danger)">${d.error}</td></tr>`;
    return;
  }

  const { zeilen, gesamt_kosten, gesamt_tage, avg_kosten_tag, gesamt_antraege } = d.data;

  // Metriken
  document.getElementById('m-kosten').textContent   = eur(gesamt_kosten);
  document.getElementById('m-tage').textContent     = gesamt_tage;
  document.getElementById('m-avg').textContent      = eur(avg_kosten_tag);
  document.getElementById('m-antraege').textContent = gesamt_antraege;

  if (!zeilen.length) {
    tbody.innerHTML = '<tr><td colspan="7" style="text-align:center;padding:20px;color:var(--muted)">Keine Daten für diesen Zeitraum.</td></tr>';
    tfoot.innerHTML = '';
    return;
  }

  tbody.innerHTML = zeilen.map(z => {
    const cpt = z.pos?.CPT?.tage ?? '–';
    const sfo = z.pos?.SFO?.tage ?? '–';
    const fo  = z.pos?.FO?.tage  ?? '–';
    return `<tr>
      <td>${z.airline}</td>
      <td><span class="badge-kat kat-${z.kategorie}">${katLabel(z.kategorie)}</span></td>
      <td class="mono hide-mobile" style="text-align:right">${cpt}</td>
      <td class="mono hide-mobile" style="text-align:right">${sfo}</td>
      <td class="mono hide-mobile" style="text-align:right">${fo}</td>
      <td class="mono" style="text-align:right;font-weight:600">${z.tage}</td>
      <td class="mono" style="text-align:right;font-weight:600">${eur(z.kosten)}</td>
    </tr>`;
  }).join('');

  tfoot.innerHTML = `<tr>
    <td colspan="5" class="hide-mobile"></td>
    <td colspan="2" style="display:none"></td>
    <td style="font-weight:700">Gesamt</td>
    <td class="mono hide-mobile" style="text-align:right"></td>
    <td class="mono hide-mobile" style="text-align:right"></td>
    <td class="mono hide-mobile" style="text-align:right"></td>
    <td class="mono" style="text-align:right;font-weight:700">${gesamt_tage}</td>
    <td class="mono" style="text-align:right;font-weight:700">${eur(gesamt_kosten)}</td>
  </tr>`;

  // Einfacherer Tfoot
  tfoot.innerHTML = `<tr>
    <td colspan="5"><strong>Gesamt</strong></td>
    <td class="mono" style="text-align:right"><strong>${gesamt_tage}</strong></td>
    <td class="mono" style="text-align:right"><strong>${eur(gesamt_kosten)}</strong></td>
  </tr>`;
}

// ── Tab 3: Charts ─────────────────────────────────────────
function setGran(g) {
  granularitaet = g;
  document.querySelectorAll('.chart-toggle').forEach(b => b.classList.remove('active'));
  document.getElementById('gr-' + g).classList.add('active');
}

async function loadChart() {
  const d = await api('get_finance_chart', {
    granularitaet,
    von:     document.getElementById('ch-von').value,
    bis:     document.getElementById('ch-bis').value,
    airline: document.getElementById('ch-airline').value,
  });
  if (!d.ok) return;

  const perioden = d.data.perioden;
  const codes    = d.data.codes || ['FS', 'V4', 'VCB']; // Fallback
  const labels   = perioden.map(p => p.periode);

  // Farben pro Code
  const COLORS = ['#1d4ed8','#059669','#d97706','#7c3aed','#dc2626','#0891b2','#84cc16'];
  const datasets = codes.map((code, i) => ({
    label: code,
    data: perioden.map(p => Math.round((p[code]?.kosten ?? 0))),
    backgroundColor: COLORS[i % COLORS.length],
    borderRadius: 3,
  }));

  const ctxBar = document.getElementById('chart-bar').getContext('2d');
  if (barChart) barChart.destroy();
  barChart = new Chart(ctxBar, {
    type: 'bar',
    data: { labels, datasets },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: { display: false },
        tooltip: {
          callbacks: {
            label: ctx => ctx.dataset.label + ': ' + eur(ctx.raw),
          },
        },
      },
      scales: {
        x: {
          stacked: true,
          grid: { display: false },
          ticks: { color: '#6b7280', font: { size: 11 }, autoSkip: false, maxRotation: 45 },
        },
        y: {
          stacked: true,
          grid: { color: 'rgba(0,0,0,.06)' },
          ticks: {
            color: '#6b7280',
            font: { size: 11 },
            callback: v => '€' + Math.round(v / 1000) + 'k',
          },
        },
      },
    },
  });

  // Obere Legende befüllen
  document.getElementById('chart-legend').innerHTML = codes.map((code, i) =>
    `<span style="display:flex;align-items:center;gap:5px">
      <span style="width:12px;height:12px;border-radius:2px;background:${COLORS[i % COLORS.length]};display:inline-block"></span>${code}
    </span>`
  ).join('');

  // Donut-Diagramm
  const totals = codes.map(code => perioden.reduce((s, p) => s + Math.round((p[code]?.kosten ?? 0)), 0));
  const grand  = totals.reduce((a, b) => a + b, 0);

  const ctxDo = document.getElementById('chart-donut').getContext('2d');
  if (donutChart) donutChart.destroy();
  donutChart = new Chart(ctxDo, {
    type: 'doughnut',
    data: {
      labels: codes,
      datasets: [{
        data: totals,
        backgroundColor: COLORS.slice(0, codes.length),
        borderWidth: 0,
        hoverOffset: 4,
      }],
    },
    options: {
      responsive: false,
      plugins: {
        legend: { display: false },
        tooltip: {
          callbacks: {
            label: ctx => {
              const pct = grand > 0 ? Math.round(ctx.raw / grand * 100) : 0;
              return ctx.label + ': ' + eur(ctx.raw) + ' (' + pct + '%)';
            },
          },
        },
      },
    },
  });

  // Legende dynamisch
  document.getElementById('donut-legend').innerHTML = grand === 0
    ? 'Keine Daten.'
    : codes.map((code, i) => {
        const val = totals[i];
        const pct = grand > 0 ? Math.round(val / grand * 100) : 0;
        return `<div style="display:flex;align-items:center;gap:8px;margin-bottom:8px">
          <span style="width:12px;height:12px;border-radius:2px;background:${COLORS[i % COLORS.length]};flex-shrink:0"></span>
          <span>${code}: <strong>${eur(val)}</strong> <span style="color:var(--muted)">(${pct}%)</span></span>
        </div>`;
      }).join('');
}

// ── Init ──────────────────────────────────────────────────
loadAirlines();
loadFreistellungscodesAlle();
loadChart();

// ══════════════════════════════════════════════════════════
// Budgets (ehemals eigene Seite budget.php, jetzt als 4. Tab
// hier integriert). Keine Namenskollisionen mit den obigen
// Finance-Funktionen, daher unverändert übernommen - lädt
// erst beim ersten Öffnen des Tabs (siehe finTab() oben).
// ══════════════════════════════════════════════════════════
let budgetInitialisiert = false;
let activeJahr = <?= date('Y') ?>;
let centerJahr = <?= date('Y') ?>; // Mittelpunkt des sichtbaren Bereichs

function renderYearBtns() {
  const wrap = document.getElementById('year-btns');
  wrap.innerHTML = '';
  for (let y = centerJahr - 2; y <= centerJahr + 2; y++) {
    const btn = document.createElement('button');
    btn.className = 'btn ' + (y === activeJahr ? 'btn-primary' : 'btn-outline') + ' year-btn';
    btn.dataset.year = y;
    btn.textContent = y;
    btn.addEventListener('click', function() {
      activeJahr = parseInt(this.dataset.year);
      document.getElementById('b-jahr').value = activeJahr;
      document.getElementById('jahr-label').textContent = activeJahr;
      renderYearBtns();
      loadAirlinesAndTKs();
      loadBudgets();
    });
    wrap.appendChild(btn);
  }
}

document.getElementById('jahr-prev').addEventListener('click', () => {
  centerJahr--;
  renderYearBtns();
});
document.getElementById('jahr-next').addEventListener('click', () => {
  centerJahr++;
  renderYearBtns();
});

renderYearBtns();

let allTKs = [];

async function loadAirlinesAndTKs() {
  const fd1 = new FormData();
  fd1.append('action', 'list_finance_airlines'); fd1.append('csrf', CSRF);
  const da = await fetch('../api/buero.php', {method:'POST',body:fd1}).then(r=>r.json());
  if (da.ok) {
    const sel = document.getElementById('b-airline');
    sel.innerHTML = '<option value="">– Bitte wählen –</option>' +
      da.data.airlines.map(a => `<option value="${a}">${a}</option>`).join('');
  }

  const fd2 = new FormData();
  fd2.append('action', 'list_tks'); fd2.append('csrf', CSRF);
  const dt = await fetch('../api/buero.php', {method:'POST',body:fd2}).then(r=>r.json());
  if (dt.ok) {
    allTKs = dt.data.filter(t => t.aktiv == 1);
  }
}

function filterTKs() {
  const airline = document.getElementById('b-airline').value;
  const sel     = document.getElementById('b-tk');
  const filtered = airline
    ? allTKs.filter(t => (t.airline || '').toLowerCase() === airline.toLowerCase())
    : allTKs;

  const placeholder = '<option value="">– keine Aufteilung (ganze Airline) –</option>';
  sel.innerHTML = placeholder + filtered.map(t => `<option value="${t.id}">${t.kuerzel} – ${t.bezeichnung}</option>`).join('');
}

async function loadBudgets() {
  const tbody = document.getElementById('budget-tbody');
  tbody.innerHTML = '<tr><td colspan="8" style="text-align:center;padding:24px;color:var(--muted)">Wird geladen …</td></tr>';
  const fd = new FormData();
  fd.append('action','list_budgets'); fd.append('csrf',CSRF); fd.append('jahr',activeJahr);
  const d = await fetch('../api/buero.php', {method:'POST',body:fd}).then(r=>r.json());
  if (!d.ok || !d.data.budgets.length) {
    tbody.innerHTML = '<tr><td colspan="8" style="text-align:center;padding:32px;color:var(--muted)">Keine Daten für ' + activeJahr + '.</td></tr>';
    return;
  }

  // Die Liste kommt vom Backend bereits vorsortiert: Airline, dann TK
  // (erst "alle TKs", danach je TK), dann Code (erst "alle Codes",
  // danach je Code) - hier nur noch als Airline -> TK -> Code gruppiert
  // mit Einrückung darstellen.
  let html = '';
  let lastAirline = null;
  for (const b of d.data.budgets) {
    if (b.airline !== lastAirline) {
      html += `<tr class="budget-group-airline">
        <td colspan="8" style="font-weight:700;padding:12px 10px 6px;background:var(--surface)">${b.airline}</td>
      </tr>`;
      lastAirline = b.airline;
    }

    const pctColor  = b.pct > 90 ? '#c0392b' : (b.pct > 70 ? '#f59e0b' : '#1e7e34');
    const restColor = b.rest < 0 ? 'color:#c0392b;font-weight:700' : 'color:#1e7e34;font-weight:700';
    const q = b.quartale || {1:0,2:0,3:0,4:0};
    const qBudget = b.budget_tage / 4; // Freistellungsjahresbudget / 4 = Quartalsbudget
    const qBars = [1,2,3,4].map(i => {
      const tage  = q[i] || 0;
      const pct   = qBudget > 0 ? Math.min(100, Math.round(tage / qBudget * 100)) : 0;
      const color = pct > 90 ? '#c0392b' : (pct > 70 ? '#f59e0b' : '#1e7e34');
      return { i, tage, pct, color };
    });
    const qTitle = qBars.map(qb => `Q${qb.i}: ${qb.tage.toFixed(1)}/${qBudget.toFixed(1)} Tage (${qb.pct}%)`).join(' · ');
    const qCell = `
      <div class="q-bars" title="${qTitle}">
        ${qBars.map(qb => `<div class="q-bar-col"><div class="q-bar-fill" style="height:${qb.pct}%;background:${qb.color}"></div></div>`).join('')}
      </div>
      <div style="display:flex;gap:3px">
        ${qBars.map(qb => `<span class="q-bar-label">Q${qb.i}</span>`).join('')}
      </div>`;

    // Beschriftung + Einrückung je nach Ebene:
    //   Ganze Airline (tk=null, code='')   -> fett, keine Einrückung
    //   TK: XY          (tk gesetzt, code='') -> fett, 1x eingerückt
    //   ↳ Code: XY      (code gesetzt)        -> normal, 1 Ebene tiefer als ihre TK/Airline-Zeile
    let label, indent, bold;
    if (b.tk_id === null && !b.freistellungscode) {
      label = 'Ganze Airline'; indent = 18; bold = true;
    } else if (b.tk_id !== null && !b.freistellungscode) {
      label = `TK: ${esc(b.tk_kuerzel)}`; indent = 18; bold = true;
    } else {
      label = `↳ Code: ${esc(b.freistellungscode)}`; indent = 40; bold = false;
    }

    const delBtn = b.budget_id
      ? `<button class="btn btn-outline" style="padding:2px 8px;font-size:11px" onclick="deleteBudget(${b.budget_id})" title="Budget löschen">🗑</button>`
      : '';

    const hatEigenesBudget = b.budget_tage > 0;

    html += `<tr>
      <td style="padding-left:${indent}px;font-weight:${bold ? 700 : 400}">${label}</td>
      <td class="mono">${hatEigenesBudget ? b.budget_tage.toFixed(1) : '–'}</td>
      <td class="mono">${b.beantragt.toFixed(1)}</td>
      <td class="mono">${b.verbrauch.toFixed(1)}</td>
      <td class="mono" style="${hatEigenesBudget ? restColor : 'color:var(--muted)'}">${hatEigenesBudget ? b.rest.toFixed(1) : '–'}</td>
      <td style="min-width:120px">
        ${hatEigenesBudget ? `
        <div class="pbar-wrap">
          <div class="pbar"><div class="pbar-fill" style="width:${b.pct}%;background:${pctColor}"></div></div>
          <span class="mono" style="font-size:12px">${b.pct}%</span>
        </div>` : '<span style="color:var(--muted);font-size:12px">– (kein Budget)</span>'}
      </td>
      <td class="mono" style="font-size:11px;white-space:nowrap;color:var(--muted)">${qCell}</td>
      <td>${delBtn}</td>
    </tr>`;
  }
  tbody.innerHTML = html;
}

async function deleteBudget(id) {
  if (!confirm('Dieses Budget wirklich löschen? Das kann nicht rückgängig gemacht werden.')) return;
  const fd = new FormData();
  fd.append('action', 'delete_budget'); fd.append('csrf', CSRF); fd.append('id', id);
  const d = await fetch('../api/buero.php', {method:'POST', body:fd}).then(r=>r.json());
  if (!d.ok) { alert('⚠ ' + d.error); return; }
  loadBudgets();
}

async function loadCodesForAirline() {
  const airline = document.getElementById('b-airline').value;
  const sel = document.getElementById('b-code');
  sel.innerHTML = '<option value="">– keine Aufteilung (ganze Airline) –</option>';
  if (!airline) return;
  const d = await api('list_freistellungscodes', { airline });
  if (d.ok) {
    sel.innerHTML += d.data.codes.map(c => `<option value="${c}">${c}</option>`).join('');
  }
}

async function saveBudget() {
  const alertBox = document.getElementById('alert-budget');
  alertBox.style.display = 'none';
  const fd = new FormData();
  fd.append('action','save_budget'); fd.append('csrf',CSRF);
  fd.append('tk_id', document.getElementById('b-tk').value);
  fd.append('airline_name', document.getElementById('b-airline').value);
  fd.append('freistellungscode', document.getElementById('b-code').value);
  fd.append('budget_tage', document.getElementById('b-tage').value);
  fd.append('jahr', document.getElementById('b-jahr').value);
  const d = await fetch('../api/buero.php', {method:'POST',body:fd}).then(r=>r.json());
  alertBox.className = 'alert ' + (d.ok ? 'alert-success' : 'alert-error');
  alertBox.textContent = d.ok ? '✓ Budget gespeichert.' : ('⚠ ' + d.error);
  alertBox.style.display = 'block';
  if (d.ok) { loadAirlinesAndTKs(); loadCodesForAirline(); loadBudgets(); }
}

async function exportRechnungsdb() {
  const von = document.getElementById('st-von').value;
  const bis = document.getElementById('st-bis').value;
  if (!von || !bis) { alert('Bitte Zeitraum (Von/Bis) wählen.'); return; }
  const fd = new FormData();
  fd.append('action', 'export_rechnungsdb_csv'); fd.append('csrf', CSRF);
  fd.append('von', von); fd.append('bis', bis);
  const r = await fetch('../api/buero.php', { method: 'POST', body: fd });
  if (!r.ok) { alert('Export fehlgeschlagen.'); return; }
  const blob = await r.blob();
  const url  = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = `freistellungsbericht_${von}_${bis}_rechnungsdb.csv`;
  a.click();
  URL.revokeObjectURL(url);
}

// Excel-Export fürs Quartalsmonitoring, gegliedert nach Freistellungscode.
// Nutzt Zeitraum + Airline aus den aktuell gesetzten Filtern der
// "Kosten & Tage"-Ansicht (Kategorie/Freistellungscode-Filter wirken hier
// bewusst NICHT mit - das Quartalsmonitoring soll immer alle Codes im
// gewählten Zeitraum/Airline zeigen, nicht nur einen bereits gefilterten).
async function exportFinanceQuartal() {
  const von     = document.getElementById('st-von').value;
  const bis     = document.getElementById('st-bis').value;
  const airline = document.getElementById('st-airline').value;
  if (!von || !bis) { alert('Bitte Zeitraum (Von/Bis) wählen.'); return; }
  const fd = new FormData();
  fd.append('action', 'export_finance_quartal_excel'); fd.append('csrf', CSRF);
  fd.append('von', von); fd.append('bis', bis); fd.append('airline', airline);
  const r = await fetch('../api/buero.php', { method: 'POST', body: fd });
  if (!r.ok) {
    let msg = 'Export fehlgeschlagen.';
    try { const j = await r.json(); if (j.error) msg = j.error; } catch (e) {}
    alert(msg);
    return;
  }
  const blob = await r.blob();
  const url  = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = `quartalsmonitoring_${von}_${bis}.xlsx`;
  a.click();
  URL.revokeObjectURL(url);
}

// Alte Lesezeichen auf budget.php landen jetzt hier mit ?tab=budget
<?php if (($_GET['tab'] ?? '') === 'budget'): ?>
finTab('budget');
<?php endif; ?>
</script>

<?php panel_foot(); ?>
