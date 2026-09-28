<?php
// buero/tks.php  –  Tarifkommissionen & Referenten verwalten
require_once __DIR__ . '/../_backend/bootstrap.php';
require_once __DIR__ . '/../_backend/panel.php';
send_security_headers();
$auth = require_login('buero');

panel_head('Tarifkommissionen');
panel_sidebar('tks', $auth['rolle'], $auth['user']);
panel_topbar('Tarifkommissionen & Referenten');
?>

<div id="alert-global" style="display:none" class="alert"></div>

<div style="display:flex;flex-direction:column;gap:22px">

  <!-- ── TKs ─────────────────────────────────────────────────── -->
  <div class="card">
    <div class="card-head"><h2>Tarifkommissionen</h2></div>
    <div class="card-body">
      <div style="display:grid;grid-template-columns:1fr 2fr 1fr auto;gap:8px;margin-bottom:16px;align-items:end">
        <div class="fg" style="margin:0">
          <label>Kürzel <span style="color:#e63946">*</span></label>
          <input type="text" id="tk-kuerzel" placeholder="z.B. LH-TK3">
        </div>
        <div class="fg" style="margin:0">
          <label>Bezeichnung <span style="color:#e63946">*</span></label>
          <input type="text" id="tk-bezeichnung" placeholder="z.B. Lufthansa Kurzstrecke">
        </div>
        <div class="fg" style="margin:0">
          <label>Airline</label>
          <input type="text" id="tk-airline" placeholder="z.B. Lufthansa">
        </div>
        <button class="btn btn-primary btn-sm" style="align-self:end;white-space:nowrap" onclick="createTK()">+ Anlegen</button>
      </div>
      <div class="table-scroll">
        <table style="min-width:500px">
          <thead><tr>
            <th>Kürzel</th><th>Bezeichnung</th><th>Airline</th>
            <th style="text-align:center">Ref.</th><th>Status</th><th style="width:130px">Aktionen</th>
          </tr></thead>
          <tbody id="tk-tbody">
            <tr><td colspan="6" style="text-align:center;padding:20px;color:var(--muted)">Wird geladen …</td></tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- ── Tarifreferenten ──────────────────────────────────────── -->
  <div class="card">
    <div class="card-head"><h2>Tarifreferenten</h2></div>
    <div class="card-body">
      <div style="display:grid;grid-template-columns:auto 1fr 1fr auto;gap:8px;margin-bottom:16px;align-items:end">
        <div class="fg" style="margin:0">
          <label>TK <span style="color:#e63946">*</span></label>
          <select id="ref-tk" style="padding:9px 10px;border:1px solid var(--border,#dde2ea);border-radius:7px;font-size:14px">
            <option value="">– Wählen –</option>
          </select>
        </div>
        <div class="fg" style="margin:0">
          <label>Name <span style="color:#e63946">*</span></label>
          <input type="text" id="ref-name" placeholder="Vor- und Nachname">
        </div>
        <div class="fg" style="margin:0">
          <label>E-Mail <span style="color:#e63946">*</span></label>
          <input type="email" id="ref-email" placeholder="referent@vc.de">
        </div>
        <button class="btn btn-primary btn-sm" style="align-self:end" onclick="createRef()">+ Hinzufügen</button>
      </div>
      <div class="table-scroll"><table>
        <thead><tr><th>TK</th><th>Name</th><th>E-Mail</th><th></th></tr></thead>
        <tbody id="ref-tbody">
          <tr><td colspan="4" style="text-align:center;padding:20px;color:var(--muted)">Wird geladen …</td></tr>
        </tbody>
      </table></div>
    </div>
  </div>

</div>

<script>
const CSRF = '<?= csrf_token() ?>';

function showMsg(msg, ok = true) {
  const b = document.getElementById('alert-global');
  b.className = 'alert ' + (ok ? 'alert-success' : 'alert-error');
  b.textContent = (ok ? '✓ ' : '⚠ ') + msg;
  b.style.display = 'block';
  setTimeout(() => b.style.display = 'none', 4000);
}

async function api(action, extra = {}) {
  const fd = new FormData();
  fd.append('action', action); fd.append('csrf', CSRF);
  Object.entries(extra).forEach(([k,v]) => fd.append(k, v));
  return fetch('../api/buero.php', {method:'POST', body:fd}).then(r=>r.json());
}

// ── TKs ──────────────────────────────────────────────────────
async function loadTKs() {
  const d = await api('list_tks');
  const tbody = document.getElementById('tk-tbody');
  if (!d.ok) {
    tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;padding:20px;color:#c0392b">Fehler beim Laden.</td></tr>';
    return;
  }
  if (!d.data.length) {
    tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;padding:20px;color:var(--muted)">Noch keine Tarifkommissionen angelegt.</td></tr>';
    return;
  }

  // TK-Select für Referenten befüllen
  ['ref-tk'].forEach(selId => {
    const sel = document.getElementById(selId);
    const curVal = sel.value;
    sel.innerHTML = '<option value="">– Wählen –</option>';
    d.data.filter(t => t.aktiv == 1).forEach(t => {
      const o = document.createElement('option');
      o.value = t.id;
      o.textContent = t.kuerzel + ' – ' + t.bezeichnung;
      sel.appendChild(o);
    });
    if (curVal) sel.value = curVal;
  });

  tbody.innerHTML = d.data.map(t => `
    <tr>
      <td class="mono" style="font-size:12px;font-weight:700">${t.kuerzel}</td>
      <td style="font-size:13px">${t.bezeichnung}</td>
      <td style="font-size:13px;color:var(--muted)">${t.airline || '–'}</td>
      <td style="text-align:center;font-size:13px">${t.ref_count}</td>
      <td>${t.aktiv == 1
        ? '<span style="display:inline-block;padding:2px 9px;border-radius:20px;font-size:12px;font-weight:700;background:#e6f4ea;color:#1e7e34">Aktiv</span>'
        : '<span style="display:inline-block;padding:2px 9px;border-radius:20px;font-size:12px;font-weight:700;background:#fde8e8;color:#c0392b">Inaktiv</span>'
      }</td>
      <td style="width:130px">
        <div style="display:flex;gap:4px;flex-wrap:nowrap">
          <button class="btn btn-outline btn-sm" style="font-size:11px;padding:3px 7px" onclick="toggleTK(${t.id})" title="${t.aktiv == 1 ? 'Deaktivieren' : 'Aktivieren'}">${t.aktiv == 1 ? '⏸' : '▶'}</button>
          <button class="btn btn-outline btn-sm" style="font-size:11px;padding:3px 7px;color:#c0392b" onclick="deleteTK(${t.id}, '${t.kuerzel.replace(/'/g, "\\'")}')" title="Löschen">🗑</button>
        </div>
      </td>
    </tr>`).join('');
}

async function createTK() {
  const kuerzel     = document.getElementById('tk-kuerzel').value.trim();
  const bezeichnung = document.getElementById('tk-bezeichnung').value.trim();
  const airline     = document.getElementById('tk-airline').value.trim();
  if (!kuerzel || !bezeichnung) { showMsg('Kürzel und Bezeichnung sind Pflichtfelder.', false); return; }
  const d = await api('create_tk', { kuerzel, bezeichnung, airline });
  showMsg(d.ok ? 'Tarifkommission angelegt.' : d.error, d.ok);
  if (d.ok) {
    document.getElementById('tk-kuerzel').value = '';
    document.getElementById('tk-bezeichnung').value = '';
    document.getElementById('tk-airline').value = '';
    loadTKs();
  }
}

async function toggleTK(id) {
  const d = await api('toggle_tk', { tk_id: id });
  showMsg(d.ok ? 'Status geändert.' : d.error, d.ok);
  if (d.ok) loadTKs();
}

async function deleteTK(id, kuerzel) {
  if (!confirm(`TK „${kuerzel}" wirklich löschen?\n\nNur möglich wenn keine Anträge oder Mitglieder mehr zugeordnet sind.`)) return;
  const d = await api('delete_tk', { tk_id: id });
  showMsg(d.ok ? 'TK gelöscht.' : d.error, d.ok);
  if (d.ok) loadTKs();
}

// ── Referenten ────────────────────────────────────────────────
async function loadRefs() {
  const d = await api('list_referenten');
  const tbody = document.getElementById('ref-tbody');
  if (!d.ok || !d.data.length) {
    tbody.innerHTML = '<tr><td colspan="4" style="text-align:center;padding:20px;color:var(--muted)">Keine Referenten hinterlegt.</td></tr>';
    return;
  }
  tbody.innerHTML = d.data.map(r => `
    <tr>
      <td class="mono" style="font-size:12px">${r.kuerzel}</td>
      <td style="font-size:13px">${r.name}</td>
      <td style="font-size:12px;color:var(--muted)">${r.email}</td>
      <td><button class="btn btn-outline btn-sm" style="color:#c0392b" onclick="deleteRef(${r.id})">✕</button></td>
    </tr>`).join('');
}

async function createRef() {
  const tk_id = document.getElementById('ref-tk').value;
  const name  = document.getElementById('ref-name').value.trim();
  const email = document.getElementById('ref-email').value.trim();
  if (!tk_id || !name || !email) { showMsg('Alle Felder sind Pflichtfelder.', false); return; }
  const d = await api('create_referent', { tk_id, name, email });
  showMsg(d.ok ? 'Referent hinzugefügt.' : d.error, d.ok);
  if (d.ok) {
    document.getElementById('ref-name').value = '';
    document.getElementById('ref-email').value = '';
    loadRefs();
  }
}

async function deleteRef(id) {
  if (!confirm('Referent wirklich entfernen?')) return;
  const d = await api('delete_referent', { ref_id: id });
  showMsg(d.ok ? 'Referent entfernt.' : d.error, d.ok);
  if (d.ok) loadRefs();
}

loadTKs(); loadRefs();
</script>

<?php panel_foot(); ?>
