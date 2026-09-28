<?php
// buero/antrag.php  –  Einzelantrag anzeigen & bearbeiten
require_once __DIR__ . '/../_backend/bootstrap.php';
require_once __DIR__ . '/../_backend/panel.php';
send_security_headers();
$auth = require_login('buero');
$id = (int)($_GET['id'] ?? 0);

panel_head('Antrag bearbeiten');
panel_sidebar('antraege', $auth['rolle'], $auth['user']);
panel_topbar('Antrag bearbeiten');
?>

<div id="loading" style="color:var(--muted);padding:32px;text-align:center">Antrag wird geladen …</div>
<div id="main-content" style="display:none">

  <div style="display:grid;grid-template-columns:1fr 340px;gap:22px">

    <!-- Detail-Karte -->
    <div class="card">
      <div class="card-head"><h2>Antragsdaten</h2></div>
      <div class="card-body">
        <table style="width:100%;border-collapse:collapse" id="detail-table"></table>
      </div>
    </div>

    <!-- Aktions-Spalte -->
    <div>
      <div class="card">
        <div class="card-head"><h2>Status & Entscheidung</h2></div>
        <div class="card-body">
          <div id="status-badge-area" style="margin-bottom:16px"></div>
          <div id="alert-update" style="display:none" class="alert"></div>
          <div class="fg" style="margin-bottom:14px">
            <label>Status ändern auf</label>
            <select id="new-status">
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
          <div class="fg" style="margin-bottom:20px">
            <label>Notiz / Begründung</label>
            <textarea id="notiz" rows="4" placeholder="Optionale Begründung …"></textarea>
          </div>
          <button class="btn btn-primary btn-block" id="save-btn" onclick="saveAntrag()">
            Speichern &amp; ggf. benachrichtigen
          </button>
        </div>
      </div>
      <div class="card">
        <div class="card-body">
          <a class="btn btn-primary btn-block" id="btn-ag-beantragen" href="#" target="_blank" rel="noopener">
            → Bei Arbeitgeber beantragen
          </a>
          <div style="font-size:12px;color:var(--muted,#7a8fa6);margin-top:8px">
            Öffnet "Beantragung beim AG", vorgefiltert auf Airline und Zeitraum
            dieses Termins - zur Prüfung, bevor tatsächlich versendet wird.
          </div>
        </div>
      </div>
      <div class="card">
        <div class="card-body">
          <a href="antraege.php" class="btn btn-outline btn-block">← Zurück zur Liste</a>
        </div>
      </div>
    </div>

  </div>
</div>

<script src="../assets/status.js"></script>
<script>
const CSRF = '<?= csrf_token() ?>';
const ANTRAG_ID = <?= $id ?>;

function badge(status) { return statusBadge(status); }
function row(k, v) {
  return `<tr>
    <td style="padding:9px 0;border-bottom:1px solid #f0f2f5;font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--muted);width:42%">${k}</td>
    <td style="padding:9px 0;border-bottom:1px solid #f0f2f5;font-size:14px">${v}</td>
  </tr>`;
}

async function load() {
  const fd = new FormData();
  fd.append('action', 'get_antrag');
  fd.append('csrf', CSRF);
  fd.append('id', ANTRAG_ID);
  const d = await fetch('../api/buero.php', { method:'POST', body:fd }).then(r=>r.json());
  document.getElementById('loading').style.display = 'none';
  if (!d.ok) { document.getElementById('loading').textContent = 'Antrag nicht gefunden.'; document.getElementById('loading').style.display='block'; return; }
  const a = d.data;

  document.getElementById('main-content').style.display = 'block';
  document.getElementById('detail-table').innerHTML = [
    row('Vorname', a.vorname),
    row('Nachname', a.nachname),
    row('TK', a.tk_kuerzel + ' – ' + a.tk_name),
    row('VC-E-Mail', a.vc_email),
    row('Airline', a.airline),
    row('Position', a.position),
    row('Flugzeugmuster', a.flugzeugmuster),
    row('Veranstaltung', a.veranstaltung),
    ...(a.veranstaltung === 'sonstige' ? [row('Bezeichnung', a.ereignis_bezeichnung || '–')] : []),
    row('Zeitraum von', a.zeitraum_von),
    row('Zeitraum bis', a.zeitraum_bis),
    row('Eingereicht', a.erstellt_am ? a.erstellt_am.substring(0,16) : '–'),
    row('Entschieden am', a.entschieden_am ? a.entschieden_am.substring(0,16) : '–'),
    row('Entschieden von', a.entschieden_von || '–'),
  ].join('');
  document.getElementById('status-badge-area').innerHTML = 'Aktuell: ' + badge(a.status);
  document.getElementById('new-status').value = a.status;
  document.getElementById('notiz').value = a.notiz || '';

  const params = new URLSearchParams({
    airline: a.airline || '', von: a.zeitraum_von || '',
    bis: a.zeitraum_bis || '', ereignis_id: a.ereignis_id || '',
  });
  document.getElementById('btn-ag-beantragen').href = 'beantragung_ag.php?' + params.toString();
}

async function saveAntrag() {
  const btn = document.getElementById('save-btn');
  const alertBox = document.getElementById('alert-update');
  btn.disabled = true; btn.innerHTML = '<span class="spinner"></span>';
  alertBox.style.display = 'none';

  const fd = new FormData();
  fd.append('action', 'update_antrag');
  fd.append('csrf', CSRF);
  fd.append('id', ANTRAG_ID);
  fd.append('status', document.getElementById('new-status').value);
  fd.append('notiz', document.getElementById('notiz').value);

  const d = await fetch('../api/buero.php', { method:'POST', body:fd }).then(r=>r.json());
  if (d.ok) {
    alertBox.className = 'alert alert-success';
    alertBox.innerHTML = '✓ Gespeichert. Antragsteller wurde ggf. per E-Mail informiert.';
  } else {
    alertBox.className = 'alert alert-error';
    alertBox.innerHTML = '⚠ ' + d.error;
  }
  alertBox.style.display = 'flex';
  btn.disabled = false;
  btn.innerHTML = 'Speichern &amp; ggf. benachrichtigen';
  load();
}

load();
</script>

<?php panel_foot(); ?>
