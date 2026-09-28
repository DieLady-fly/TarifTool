<?php
// buero/flugbetrieb_kontakte.php  –  AG-Kontakte je Airline verwalten
require_once __DIR__ . '/../_backend/bootstrap.php';
require_once __DIR__ . '/../_backend/panel.php';
send_security_headers();
$auth = require_login('buero');

panel_head('AG-Kontakte Planung');
panel_sidebar('flugbetrieb_kontakte', $auth['rolle'], $auth['user']);
panel_topbar('AG-Kontakte Planung');
?>

<style>
.fk-table { width:100%; border-collapse:collapse; font-size:14px; }
.fk-table th { padding:9px 14px; text-align:left; background:#f8f9fb;
  border-bottom:2px solid var(--border,#dde2ea); font-size:11px; font-weight:700;
  text-transform:uppercase; letter-spacing:.04em; color:var(--muted,#7a8fa6); }
.fk-table td { padding:10px 14px; border-bottom:1px solid var(--border,#dde2ea);
  vertical-align:middle; }
.fk-table tr:last-child td { border-bottom:none; }
.fk-table tr:hover td { background:#f8f9fb; }
.tag-aktiv   { display:inline-block; padding:2px 9px; border-radius:20px;
  font-size:11px; font-weight:700; background:#e6f4ea; color:#1e7e34; }
.tag-inaktiv { display:inline-block; padding:2px 9px; border-radius:20px;
  font-size:11px; font-weight:700; background:#fde8e8; color:#c0392b; }

.modal-backdrop { display:none; position:fixed; inset:0; background:rgba(0,0,0,.45);
  z-index:1000; align-items:center; justify-content:center; }
.modal-backdrop.open { display:flex; }
.modal { background:#fff; border-radius:12px; padding:28px 32px; min-width:420px;
  max-width:500px; width:100%; box-shadow:0 8px 32px rgba(0,0,0,.18); }
.modal h3 { margin:0 0 20px; font-size:18px; color:var(--navy2,#1a3a5c); }
.fg { display:flex; flex-direction:column; gap:5px; margin-bottom:16px; }
.fg label { font-size:12px; font-weight:700; color:var(--muted,#7a8fa6);
  text-transform:uppercase; letter-spacing:.04em; }
.fg input, .fg select { padding:9px 11px; border:1px solid var(--border,#dde2ea);
  border-radius:7px; font-size:14px; }
.fg input:focus, .fg select:focus { outline:none; border-color:var(--navy2,#1a3a5c); }
.modal-actions { display:flex; gap:10px; justify-content:flex-end; margin-top:4px; }
.alert { display:none; padding:10px 14px; border-radius:7px; font-size:13px; margin-bottom:14px; }
.alert-success { background:#e6f4ea; color:#1e7e34; border:1px solid #81c784; }
.alert-error   { background:#fde8e8; color:#c0392b; border:1px solid #e57373; }
.airline-badge { display:inline-block; padding:2px 9px; border-radius:20px; font-size:12px;
  font-weight:700; background:#eef2ff; color:#3730a3; }
.hint { font-size:12px; color:var(--muted,#7a8fa6); margin-top:2px; }
</style>

<div class="card">
  <div class="card-head">
    <h2>AG-Kontakte Planung</h2>
    <button class="btn btn-primary btn-sm" onclick="openModal()">+ Neuer Eintrag</button>
  </div>
  <div class="card-body" style="padding:0">
    <div class="table-scroll">
      <table class="fk-table">
        <thead><tr>
          <th>Airline</th>
          <th>Bezeichnung</th>
          <th>E-Mail</th>
          <th>Status</th>
          <th></th>
        </tr></thead>
        <tbody id="fk-tbody">
          <tr><td colspan="5" style="text-align:center;padding:32px;color:var(--muted)">Wird geladen …</td></tr>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Modal -->
<div class="modal-backdrop" id="modal-backdrop" onclick="closeModal(event)">
  <div class="modal" onclick="event.stopPropagation()">
    <h3 id="modal-title">Neuer Eintrag</h3>
    <div class="alert" id="modal-alert"></div>
    <input type="hidden" id="edit-id">

    <div class="fg">
      <label>Airline <span style="color:#e63946">*</span></label>
      <select id="edit-airline">
        <option value="">– bitte wählen –</option>
      </select>
      <span class="hint">Airlines stammen aus den angelegten Tarifkommissionen. Fehlt eine Airline? Dann bei „Tarifkommissionen“ prüfen, ob dort eine Airline hinterlegt ist.</span>
    </div>
    <div class="fg">
      <label>Bezeichnung des Postfachs <span style="color:#e63946">*</span></label>
      <input type="text" id="edit-bezeichnung" placeholder="z.B. Freistellungspostfach LH">
    </div>
    <div class="fg">
      <label>E-Mail-Adresse <span style="color:#e63946">*</span></label>
      <input type="email" id="edit-email" placeholder="z.B. FRACLFRST@dlh.de">
    </div>
    <div class="fg">
      <label>Status</label>
      <select id="edit-aktiv">
        <option value="1">Aktiv</option>
        <option value="0">Inaktiv</option>
      </select>
    </div>
    <p class="hint" style="margin:-6px 0 16px">
      Mehrere aktive Kontakte für dieselbe Airline sind möglich – bei der
      Beantragung beim AG erhalten dann alle gleichzeitig die Mail.
    </p>

    <div class="modal-actions">
      <button class="btn btn-outline" onclick="closeModal()">Abbrechen</button>
      <button class="btn btn-primary" id="modal-save-btn" onclick="saveEintrag()">Speichern</button>
    </div>
  </div>
</div>

<script>
const CSRF = '<?= csrf_token() ?>';

async function api(action, extra = {}) {
  const fd = new FormData();
  fd.append('action', action);
  fd.append('csrf', CSRF);
  for (const [k, v] of Object.entries(extra)) fd.append(k, v);
  return fetch('../api/buero.php', { method: 'POST', body: fd }).then(r => r.json());
}
function esc(s) {
  return String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

// ── Airlines für Dropdown laden (gleiche Quelle wie Finance/Beantragung) ──
async function ladeAirlines() {
  const d = await api('list_finance_airlines');
  if (!d.ok) return;
  const sel = document.getElementById('edit-airline');
  const aktuell = sel.value;
  sel.innerHTML = '<option value="">– bitte wählen –</option>' +
    d.data.airlines.map(a => `<option value="${esc(a)}">${esc(a)}</option>`).join('');
  if (aktuell) sel.value = aktuell;
}

// ── Kontakte laden & rendern ──────────────────────────────────
async function laden() {
  const d = await api('list_flugbetrieb_kontakte');
  const tbody = document.getElementById('fk-tbody');
  if (!d.ok || !d.data.length) {
    tbody.innerHTML = '<tr><td colspan="5" style="text-align:center;padding:32px;color:var(--muted)">Keine Einträge vorhanden.</td></tr>';
    return;
  }
  tbody.innerHTML = d.data.map(k => `
    <tr>
      <td><span class="airline-badge">${esc(k.airline)}</span></td>
      <td>${esc(k.bezeichnung)}</td>
      <td><a href="mailto:${esc(k.email)}" style="color:var(--navy2,#1a3a5c)">${esc(k.email)}</a></td>
      <td><span class="${k.aktiv ? 'tag-aktiv' : 'tag-inaktiv'}">${k.aktiv ? 'Aktiv' : 'Inaktiv'}</span></td>
      <td style="text-align:right;white-space:nowrap">
        <button class="btn btn-outline btn-sm"
          onclick='editEintrag(${JSON.stringify(k)})'>Bearbeiten</button>
        <button class="btn btn-outline btn-sm"
          style="color:#c0392b;border-color:#e57373;margin-left:4px"
          onclick="loeschen(${k.id}, '${esc(k.airline)}', '${esc(k.bezeichnung)}')">Löschen</button>
      </td>
    </tr>`).join('');
}

// ── Modal ─────────────────────────────────────────────────────
function openModal(data = null) {
  document.getElementById('edit-id').value          = data?.id ?? '';
  document.getElementById('edit-airline').value     = data?.airline ?? '';
  document.getElementById('edit-bezeichnung').value = data?.bezeichnung ?? '';
  document.getElementById('edit-email').value       = data?.email ?? '';
  document.getElementById('edit-aktiv').value       = data ? (data.aktiv ? '1' : '0') : '1';
  document.getElementById('modal-title').textContent = data ? 'Eintrag bearbeiten' : 'Neuer Eintrag';
  document.getElementById('modal-alert').style.display = 'none';
  document.getElementById('modal-backdrop').classList.add('open');
  document.getElementById('edit-airline').focus();
}
function editEintrag(k) { openModal(k); }
function closeModal(e) {
  if (e && e.target !== document.getElementById('modal-backdrop')) return;
  document.getElementById('modal-backdrop').classList.remove('open');
}

async function saveEintrag() {
  const id          = document.getElementById('edit-id').value;
  const airline     = document.getElementById('edit-airline').value.trim();
  const bezeichnung = document.getElementById('edit-bezeichnung').value.trim();
  const email       = document.getElementById('edit-email').value.trim();
  const aktiv       = document.getElementById('edit-aktiv').value;
  const alertBox    = document.getElementById('modal-alert');

  if (!airline || !bezeichnung || !email) {
    alertBox.className = 'alert alert-error';
    alertBox.textContent = 'Bitte alle Pflichtfelder ausfüllen.';
    alertBox.style.display = 'block';
    return;
  }

  const btn = document.getElementById('modal-save-btn');
  btn.disabled = true; btn.textContent = '…';

  const action = id ? 'update_flugbetrieb_kontakt' : 'create_flugbetrieb_kontakt';
  const extra  = { airline, bezeichnung, email, aktiv };
  if (id) extra.id = id;

  const d = await api(action, extra);
  btn.disabled = false; btn.textContent = 'Speichern';

  if (d.ok) {
    document.getElementById('modal-backdrop').classList.remove('open');
    ladeAirlines(); // falls gerade eine neue Airline getippt wurde, direkt mit aufnehmen
    laden();
  } else {
    alertBox.className = 'alert alert-error';
    alertBox.textContent = '⚠ ' + (d.error || 'Fehler beim Speichern.');
    alertBox.style.display = 'block';
  }
}

async function loeschen(id, airline, bezeichnung) {
  if (!confirm(`Eintrag „${bezeichnung}" (${airline}) wirklich löschen?`)) return;
  const d = await api('delete_flugbetrieb_kontakt', { id });
  if (d.ok) laden();
  else alert('Fehler: ' + (d.error || 'Unbekannt'));
}

document.addEventListener('keydown', e => {
  if (e.key === 'Escape') document.getElementById('modal-backdrop').classList.remove('open');
});

(async () => {
  await ladeAirlines();
  await laden();
})();
</script>

<?php panel_foot(); ?>
