<?php
// buero/deadlines.php  –  Deadlines für Antragseingaben verwalten
require_once __DIR__ . '/../_backend/bootstrap.php';
require_once __DIR__ . '/../_backend/panel.php';
send_security_headers();
$auth = require_login('buero');

panel_head('Deadlines');
panel_sidebar('deadlines', $auth['rolle'], $auth['user']);
panel_topbar('Eingabe-Deadlines');
?>

<div style="display:grid;grid-template-columns:1fr 360px;gap:22px;align-items:start">

  <!-- ── Bestehende Deadlines ──────────────────────────────── -->
  <div class="card">
    <div class="card-head">
      <h2>Aktive &amp; geplante Deadlines</h2>
      <span id="dl-count" style="font-size:13px;color:rgba(255,255,255,.7)"></span>
    </div>
    <div class="card-body-raw">
      <div class="table-scroll">
        <table>
          <thead><tr>
            <th>Gesperrt bis</th>
            <th>Deadline (ab wann gesperrt)</th>
            <th>Status</th>
            <th>Notiz</th>
            <th>Gesetzt von</th>
            <th></th>
          </tr></thead>
          <tbody id="dl-tbody">
            <tr><td colspan="6" style="text-align:center;padding:28px;color:var(--muted)">Wird geladen …</td></tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- ── Neue Deadline setzen ──────────────────────────────── -->
  <div class="card" style="align-self:start">
    <div class="card-head"><h2>Neue Deadline setzen</h2></div>
    <div class="card-body">
      <div id="alert-dl" style="display:none" class="alert"></div>

      <div class="fg" style="margin-bottom:14px">
        <label>Gesperrt bis (Datum) <span style="color:#e63946">*</span></label>
        <input type="date" id="dl-bis" style="width:100%;box-sizing:border-box;padding:9px 12px;border:1px solid var(--border,#dde2ea);border-radius:7px;font-size:14px">
        <div style="font-size:12px;color:var(--muted);margin-top:4px">
          Alle Anträge bis einschließlich diesem Datum werden gesperrt.
        </div>
      </div>
      <div class="fg" style="margin-bottom:14px">
        <label>Deadline – ab welchem Tag gesperrt <span style="color:#e63946">*</span></label>
        <input type="date" id="dl-deadline" style="width:100%;box-sizing:border-box;padding:9px 12px;border:1px solid var(--border,#dde2ea);border-radius:7px;font-size:14px">
        <div style="font-size:12px;color:var(--muted);margin-top:4px">
          Ab Beginn dieses Tages (00:00 Uhr) können Mitglieder keine Anträge mehr einreichen oder ändern.
        </div>
      </div>
      <div class="fg" style="margin-bottom:20px">
        <label>Interne Notiz (optional)</label>
        <input type="text" id="dl-notiz" placeholder="z.B. Abrechnungsschluss April 2025"
          style="width:100%;box-sizing:border-box;padding:9px 12px;border:1px solid var(--border,#dde2ea);border-radius:7px;font-size:14px">
      </div>

      <div style="background:#f0f7ff;border:1px solid #c5e0ff;border-radius:8px;padding:12px 14px;font-size:13px;color:#1a3a5c;margin-bottom:20px;line-height:1.5">
        <strong>Beispiel:</strong><br>
        Gesperrt bis: 30.04.2025<br>
        Deadline: 20.03.2025 12:00<br>
        → Ab 20. März können für alle Zeiträume bis 30. April keine Anträge mehr eingereicht werden.
      </div>

      <button class="btn btn-primary btn-block" id="btn-save-dl" onclick="saveDeadline()">
        Deadline speichern
      </button>
    </div>
  </div>

</div>

<script>
const CSRF = '<?= csrf_token() ?>';

function fmt(iso) {
  if (!iso) return '–';
  return new Date(iso).toLocaleString('de-DE', {
    day:'2-digit', month:'2-digit', year:'numeric',
    hour:'2-digit', minute:'2-digit'
  });
}
function fmtDate(iso) {
  if (!iso) return '–';
  return new Date(iso + 'T00:00:00').toLocaleDateString('de-DE',
    {day:'2-digit', month:'2-digit', year:'numeric'});
}

async function api(action, extra = {}) {
  const fd = new FormData();
  fd.append('action', action);
  fd.append('csrf', CSRF);
  Object.entries(extra).forEach(([k,v]) => fd.append(k, v));
  return fetch('../api/buero.php', { method:'POST', body:fd }).then(r=>r.json());
}

function statusBadge(row) {
  const now = new Date();
  const dl  = new Date(row.deadline_am);
  const von = new Date(row.zeitraum_von + 'T00:00:00');
  const bis = new Date(row.zeitraum_bis + 'T23:59:59');

  if (dl > now) {
    // Deadline noch nicht erreicht
    const diff = Math.round((dl - now) / 60000); // Minuten
    const label = diff < 60
      ? `in ${diff} Min.`
      : diff < 1440
        ? `in ${Math.round(diff/60)} Std.`
        : `in ${Math.round(diff/1440)} Tagen`;
    return `<span style="background:#fff3cd;color:#856404;padding:3px 10px;border-radius:20px;font-size:12px;font-weight:700">⏳ Greift ${label}</span>`;
  }
  if (bis < new Date()) {
    return `<span style="background:#e2e3e5;color:#383d41;padding:3px 10px;border-radius:20px;font-size:12px;font-weight:700">Archiv</span>`;
  }
  return `<span style="background:#f8d7da;color:#721c24;padding:3px 10px;border-radius:20px;font-size:12px;font-weight:700">🔒 Gesperrt</span>`;
}

function ladeDeadlines() {
  api('list_deadlines').then(d => {
    const tbody = document.getElementById('dl-tbody');
    if (!d.ok || !d.data.length) {
      tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;padding:28px;color:var(--muted)">Keine Deadlines eingetragen.</td></tr>';
      document.getElementById('dl-count').textContent = '';
      return;
    }
    document.getElementById('dl-count').textContent = d.data.length + ' Einträge';
    tbody.innerHTML = d.data.map(r => `
      <tr>
        <td style="font-weight:600;white-space:nowrap">
          bis ${fmtDate(r.zeitraum_bis)}
        </td>
        <td style="white-space:nowrap">${fmt(r.deadline_am)}</td>
        <td>${statusBadge(r)}</td>
        <td style="font-size:13px;color:var(--muted)">${r.notiz || '–'}</td>
        <td style="font-size:12px;color:var(--muted)">${r.gesetzt_von || '–'}</td>
        <td>
          <button class="btn btn-outline btn-sm" style="color:#c0392b"
            onclick="loeschenDeadline(${r.id})" title="Löschen">🗑</button>
        </td>
      </tr>`).join('');
  });
}

async function saveDeadline() {
  const alertBox = document.getElementById('alert-dl');
  alertBox.style.display = 'none';
  const bis      = document.getElementById('dl-bis').value;
  const deadline = document.getElementById('dl-deadline').value;
  const notiz    = document.getElementById('dl-notiz').value.trim();

  if (!bis || !deadline) {
    alertBox.className = 'alert alert-error';
    alertBox.innerHTML = '⚠ Bitte alle Pflichtfelder ausfüllen.';
    alertBox.style.display = 'flex';
    return;
  }

  const btn = document.getElementById('btn-save-dl');
  btn.disabled = true; btn.textContent = 'Wird gespeichert …';

  const d = await api('save_deadline', { zeitraum_bis: bis, deadline_am: deadline, notiz });
  btn.disabled = false; btn.textContent = 'Deadline speichern';

  if (d.ok) {
    alertBox.className = 'alert alert-success';
    alertBox.innerHTML = '✓ Deadline gespeichert.';
    alertBox.style.display = 'flex';
    document.getElementById('dl-bis').value      = '';
    document.getElementById('dl-deadline').value = '';
    document.getElementById('dl-notiz').value    = '';
    ladeDeadlines();
  } else {
    alertBox.className = 'alert alert-error';
    alertBox.innerHTML = '⚠ ' + d.error;
    alertBox.style.display = 'flex';
  }
}

async function loeschenDeadline(id) {
  if (!confirm('Deadline wirklich löschen? Der Zeitraum wird wieder freigeschaltet.')) return;
  const d = await api('delete_deadline', { id });
  if (d.ok) {
    ladeDeadlines();
  } else {
    alert('Fehler: ' + d.error);
  }
}

ladeDeadlines();
</script>

<?php panel_foot(); ?>
