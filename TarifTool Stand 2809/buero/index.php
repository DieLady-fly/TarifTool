<?php
// buero/index.php  –  Büro-Panel Dashboard
require_once __DIR__ . '/../_backend/bootstrap.php';
require_once __DIR__ . '/../_backend/panel.php';
send_security_headers();
$auth = require_login('buero');

panel_head('Dashboard');
panel_sidebar('dashboard', $auth['rolle'], $auth['user']);
panel_topbar('Dashboard', '../', $auth['user']);
?>

<div class="stats-grid" id="stats-grid">
  <div class="stat">        <div class="s-label">Gesamt</div>     <div class="s-value" id="s-gesamt">…</div></div>
  <div class="stat orange"> <div class="s-label">Ausstehend</div> <div class="s-value" id="s-ausstehend">…</div></div>
  <div class="stat green">  <div class="s-label">Genehmigt durch AG</div>  <div class="s-value" id="s-genehmigt">…</div></div>
  <div class="stat red">    <div class="s-label">Abgelehnt</div>  <div class="s-value" id="s-abgelehnt">…</div></div>
</div>

<div class="card">
  <div class="card-head">
    <h2>Neueste Anträge</h2>
    <a href="antraege.php" class="btn btn-outline btn-sm">Alle anzeigen →</a>
  </div>
  <div class="card-body-raw">
    <div class="table-scroll">
      <table>
        <thead><tr>
          <th>#</th><th>Name</th><th class="hide-mobile">TK</th><th class="hide-mobile">Airline</th><th class="hide-mobile">Pos.</th>
          <th class="hide-mobile">Zeitraum</th><th>Status</th><th></th>
        </tr></thead>
        <tbody id="recent-tbody"><tr><td colspan="8" style="text-align:center;padding:24px;color:var(--muted)">Wird geladen …</td></tr></tbody>
      </table>
    </div>
  </div>
</div>

<script src="../assets/status.js"></script>
<script>
const CSRF = '<?= csrf_token() ?>';

// Hinweis: badge() kommt jetzt aus dem gemeinsamen assets/status.js
// (statusBadge()) - keine lokale, abweichende Label-Liste mehr hier.
function badge(status) { return statusBadge(status); }

async function apiPost(action, extra = {}) {
  const fd = new FormData();
  fd.append('action', action);
  fd.append('csrf', CSRF);
  Object.entries(extra).forEach(([k,v]) => fd.append(k, v));
  const r = await fetch('../api/buero.php', { method: 'POST', body: fd });
  return r.json();
}

// Stats
apiPost('get_stats').then(d => {
  if (!d.ok) return;
  document.getElementById('s-gesamt').textContent = d.data.gesamt;
  document.getElementById('s-ausstehend').textContent = d.data.ausstehend;
  document.getElementById('s-genehmigt').textContent = d.data.genehmigt;
  document.getElementById('s-abgelehnt').textContent = d.data.abgelehnt;
});

// Neueste Anträge (letzten 10)
apiPost('list_antraege').then(d => {
  const tbody = document.getElementById('recent-tbody');
  if (!d.ok || !d.data.length) {
    tbody.innerHTML = '<tr><td colspan="8" style="text-align:center;padding:24px;color:var(--muted)">Keine Anträge vorhanden.</td></tr>';
    return;
  }
  tbody.innerHTML = d.data.slice(0, 10).map(a => `
    <tr>
      <td class="mono">${a.id}</td>
      <td>${a.vorname} ${a.nachname}</td>
      <td class="mono hide-mobile" style="font-size:12px">${a.tk_kuerzel}</td>
      <td class="hide-mobile">${a.airline}</td>
      <td class="hide-mobile"><span style="display:inline-block;padding:3px 9px;border-radius:20px;font-size:12px;font-weight:700;background:#eef2ff;color:#3730a3">${a.position}</span></td>
      <td class="td-nowrap hide-mobile" style="font-size:13px;color:var(--muted)">${a.zeitraum_von}<br>${a.zeitraum_bis}</td>
      <td>${badge(a.status)}</td>
      <td><a href="antrag.php?id=${a.id}" class="btn btn-outline btn-sm">Bearbeiten</a></td>
    </tr>`).join('');
});
</script>

<?php panel_foot(); ?>
