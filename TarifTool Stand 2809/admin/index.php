<?php
// admin/index.php  –  Admin-Panel
require_once __DIR__ . '/../_backend/bootstrap.php';
require_once __DIR__ . '/../_backend/panel.php';
send_security_headers();
$auth = require_login('admin');

panel_head('Admin-Panel');
panel_sidebar('admin', $auth['rolle'], $auth['user']);
panel_topbar('Administration');
?>

<div id="alert-global" style="display:none" class="alert"></div>

<!-- Panel-Benutzer -->
  <div class="card">
    <div class="card-head"><h2>Panel-Benutzer</h2></div>
    <div class="card-body">
      <p style="color:var(--muted);font-size:13px;margin-bottom:14px">Neuen Benutzer anlegen: Nach dem Speichern kann per E-Mail ein Einladungslink versendet werden, über den der Benutzer selbst ein Passwort setzt.</p>
      <div id="edit-hinweis" style="display:none;background:#eef2ff;color:#3730a3;padding:8px 12px;border-radius:6px;font-size:13px;margin-bottom:12px">
        ✎ Bearbeite Benutzer „<strong id="edit-hinweis-name"></strong>" &nbsp;·&nbsp;
        <a href="#" onclick="resetForm();return false;" style="color:#3730a3;text-decoration:underline">Abbrechen</a>
      </div>
      <div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:18px;align-items:flex-end">
        <div class="fg" style="flex:0 0 150px"><label>Benutzername</label><input type="text" id="u-name" minlength="3" placeholder="min. 3 Zeichen"></div>
        <div class="fg" style="flex:0 0 220px"><label>E-Mail-Adresse</label><input type="email" id="u-email" placeholder="benutzer@example.com"></div>
        <div class="fg" style="flex:0 0 220px"><label>Rollen</label>
          <div style="display:flex;gap:14px;padding:9px 0">
            <label style="display:flex;align-items:center;gap:5px;font-weight:400;font-size:13px;cursor:pointer">
              <input type="checkbox" class="u-rolle-cb" value="buero" checked> Büro
            </label>
            <label style="display:flex;align-items:center;gap:5px;font-weight:400;font-size:13px;cursor:pointer">
              <input type="checkbox" class="u-rolle-cb" value="finance"> Finance
            </label>
            <label style="display:flex;align-items:center;gap:5px;font-weight:400;font-size:13px;cursor:pointer">
              <input type="checkbox" class="u-rolle-cb" value="admin"> Admin
            </label>
          </div>
        </div>
        <button class="btn btn-primary" id="btn-save-user" onclick="saveUser()">+ Benutzer anlegen</button>
      </div>
      <p style="color:var(--muted);font-size:12px;margin:-10px 0 14px">
        Mehrfachauswahl möglich (z. B. Büro + Finance gleichzeitig). Zum nachträglichen Ändern der Rollen
        eines bestehenden Benutzers: Benutzernamen exakt erneut eingeben, gewünschte Rollen ankreuzen, speichern.
      </p>
      <div class="table-scroll"><table>
        <thead><tr><th>Benutzername</th><th>E-Mail</th><th>Rolle</th><th>Aktiv</th><th>Letzter Login</th><th></th></tr></thead>
        <tbody id="user-tbody"><tr><td colspan="6" style="text-align:center;padding:16px;color:var(--muted)">Wird geladen …</td></tr></tbody>
      </table></div>
    </div>
  </div>

<!-- ── Eigenes Passwort ändern ──────────────────────────────── -->
<div class="card" style="margin-top:22px">
  <div class="card-head"><h2>Eigenes Passwort ändern</h2></div>
  <div class="card-body">
    <div style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;max-width:680px">
      <div class="fg" style="flex:1;min-width:180px">
        <label>Aktuelles Passwort</label>
        <input type="password" id="pw-alt" autocomplete="current-password">
      </div>
      <div class="fg" style="flex:1;min-width:180px">
        <label>Neues Passwort (min. 12 Zeichen, 3 von 4 Arten)</label>
        <input type="password" id="pw-neu" autocomplete="new-password">
      </div>
      <div class="fg" style="flex:1;min-width:180px">
        <label>Neues Passwort wiederholen</label>
        <input type="password" id="pw-neu2" autocomplete="new-password">
      </div>
      <button class="btn btn-primary" onclick="changePassword()">Passwort ändern</button>
    </div>
  </div>
</div>

<script>
const CSRF = '<?= csrf_token() ?>';

function showMsg(msg, ok = true) {
  const b = document.getElementById('alert-global');
  b.className = 'alert ' + (ok ? 'alert-success' : 'alert-error');
  b.textContent = (ok ? '✓ ' : '⚠ ') + msg;
  b.style.cssText = 'display:block !important; position:fixed !important; top:20px !important; right:20px !important; z-index:9999 !important; max-width:400px !important; box-shadow:0 4px 12px rgba(0,0,0,.2) !important;';
  setTimeout(() => { b.style.cssText = 'display:none !important'; }, 5000);
}

async function api(action, extra = {}) {
  const fd = new FormData();
  fd.append('action', action); fd.append('csrf', CSRF);
  Object.entries(extra).forEach(([k,v]) => fd.append(k, v));
  try {
    const r = await fetch('../api/admin.php', {method:'POST',body:fd});
    const text = await r.text();
    try {
      return JSON.parse(text);
    } catch {
      console.error('api(' + action + ') – kein JSON:', text.substring(0, 300));
      return { ok: false, error: 'Serverfehler (kein JSON). Details in der Browser-Konsole.' };
    }
  } catch (e) {
    console.error('api(' + action + ') – Netzwerkfehler:', e);
    return { ok: false, error: 'Netzwerkfehler: ' + e.message };
  }
}

// ── Benutzer ─────────────────────────────────────────────────
function rollenBadges(rolleStr) {
  const rollen = String(rolleStr || '').split(',').map(r => r.trim()).filter(Boolean);
  const stil = { admin: 'background:#fde8e8;color:#8b1c1c', finance: 'background:#d1fae5;color:#065f46', buero: 'background:#eef2ff;color:#3730a3' };
  const label = { admin: 'Admin', finance: 'Finance', buero: 'Büro' };
  return rollen.map(r => `<span style="display:inline-block;padding:2px 9px;border-radius:20px;font-size:12px;font-weight:700;margin:1px;${stil[r]||'background:#eee;color:#555'}">${label[r]||r}</span>`).join(' ');
}
async function loadUsers() {
  const d = await api('list_users');
  const tbody = document.getElementById('user-tbody');
  if (!d.ok) return;
  tbody.innerHTML = d.data.map(u => `<tr>
    <td class="mono" style="font-size:13px">${u.username}</td>
    <td style="font-size:12px;color:var(--muted)">${u.email || '–'}</td>
    <td>${rollenBadges(u.rolle)}</td>
    <td>${u.aktiv==1?'<span style="display:inline-block;padding:2px 9px;border-radius:20px;font-size:12px;font-weight:700;background:#e6f4ea;color:#1e7e34">Ja</span>':'<span style="display:inline-block;padding:2px 9px;border-radius:20px;font-size:12px;font-weight:700;background:#fde8e8;color:#c0392b">Nein</span>'}</td>
    <td class="mono" style="font-size:12px;color:var(--muted)">${u.letzter_login ? u.letzter_login.substring(0,16) : '–'}</td>
    <td style="display:flex;gap:4px;flex-wrap:wrap">
      <button class="btn btn-outline btn-sm" onclick="editUser('${u.username.replace(/'/g,"\\'")}', '${(u.email||'').replace(/'/g,"\\'")}', '${u.rolle}')">✎ Bearbeiten</button>
      ${u.email ? `<button class="btn btn-outline btn-sm" onclick="sendInvite(${u.id}, '${u.username.replace(/'/g,"\\'")}')">✉ Einladen</button>` : ''}
      ${u.email ? `<button class="btn btn-outline btn-sm" onclick="sendPwReset(${u.id}, '${u.username.replace(/'/g,"\\'")}')">🔑 PW-Reset</button>` : ''}
      <button class="btn btn-outline btn-sm" onclick="toggleUser(${u.id})">${u.aktiv==1?'Deaktivieren':'Aktivieren'}</button>
      <button class="btn btn-danger btn-sm" onclick="deleteUser(${u.id}, '${u.username.replace(/'/g,"\\'")}')">✕</button>
    </td>
  </tr>`).join('');
}
function editUser(username, email, rolleStr) {
  document.getElementById('u-name').value    = username;
  document.getElementById('u-name').disabled = true;
  document.getElementById('u-email').value   = email;
  const rollen = rolleStr.split(',').map(r => r.trim());
  document.querySelectorAll('.u-rolle-cb').forEach(cb => cb.checked = rollen.includes(cb.value));
  document.getElementById('edit-hinweis').style.display = 'block';
  document.getElementById('edit-hinweis-name').textContent = username;
  document.getElementById('btn-save-user').textContent = '✎ Änderungen speichern';
  document.getElementById('u-email').scrollIntoView({ behavior: 'smooth', block: 'center' });
}
function resetForm() {
  document.getElementById('u-name').value    = '';
  document.getElementById('u-name').disabled = false;
  document.getElementById('u-email').value   = '';
  document.querySelectorAll('.u-rolle-cb').forEach(cb => cb.checked = cb.value === 'buero');
  document.getElementById('edit-hinweis').style.display = 'none';
  document.getElementById('btn-save-user').textContent = '+ Benutzer anlegen';
}
async function saveUser() {
  const rollen = [...document.querySelectorAll('.u-rolle-cb:checked')].map(cb => cb.value);
  if (!rollen.length) { showMsg('Bitte mindestens eine Rolle auswählen.', false); return; }
  const d = await api('save_user', {username: document.getElementById('u-name').value, email: document.getElementById('u-email').value, rolle: rollen.join(',')});
  showMsg(d.ok ? (d.data?.message || 'Benutzer gespeichert.') : d.error, d.ok);
  if (d.ok) { resetForm(); loadUsers(); }
}
async function sendInvite(id, username) {
  const d = await api('send_panel_invite', {user_id: id});
  showMsg(d.ok ? `Einladung an „${username}" versendet.` : d.error, d.ok);
}
async function sendPwReset(id, username) {
  const d = await api('send_panel_pw_reset', {user_id: id});
  showMsg(d.ok ? `Passwort-Reset-Link an „${username}" versendet.` : d.error, d.ok);
}
async function toggleUser(id) { const d = await api('toggle_user', {user_id: id}); showMsg(d.ok ? 'Benutzer-Status geändert.' : d.error, d.ok); loadUsers(); }
async function deleteUser(id, username) {
  if (!confirm(`Benutzer "${username}" wirklich löschen?\n\nDieser Vorgang kann nicht rückgängig gemacht werden.`)) return;
  const d = await api('delete_user', {user_id: id});
  showMsg(d.ok ? 'Benutzer gelöscht.' : d.error, d.ok);
  loadUsers();
}

// ── Eigenes Passwort ändern ───────────────────────────────────
async function changePassword() {
  const alt  = document.getElementById('pw-alt').value;
  const neu  = document.getElementById('pw-neu').value;
  const neu2 = document.getElementById('pw-neu2').value;
  if (!alt || !neu || !neu2) { showMsg('Bitte alle drei Felder ausfüllen.', false); return; }
  const d = await api('change_own_password', {alt_passwort: alt, neu_passwort: neu, neu_passwort2: neu2});
  showMsg(d.ok ? 'Passwort erfolgreich geändert.' : d.error, d.ok);
  if (d.ok) {
    document.getElementById('pw-alt').value = '';
    document.getElementById('pw-neu').value = '';
    document.getElementById('pw-neu2').value = '';
  }
}

loadUsers();
</script>

<!-- ── Mail-Einstellungen ──────────────────────────────────── -->
<div class="card" style="margin-top:22px">
  <div class="card-head"><h2>Mail-Einstellungen</h2></div>
  <div class="card-body">
    <p style="color:var(--muted);font-size:13px;margin-bottom:4px">
      Absender-Name und Adressen für ausgehende System-E-Mails.
      Leer lassen übernimmt den Server-Standard aus den Umgebungsvariablen.
    </p>
    <p id="ms-aktiv-hinweis" style="font-size:12px;color:#1e7e34;background:#e6f4ea;border-radius:6px;padding:7px 12px;margin-bottom:14px;display:none">
      ✓ Derzeit aktiv: <strong id="ms-aktiv-text"></strong>
    </p>
    <div style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;max-width:780px">
      <div class="fg" style="flex:1;min-width:220px">
        <label>Absender-Name <span style="font-weight:400;color:var(--muted)">(MAIL_FROM_NAME)</span></label>
        <input type="text" id="ms-from-name" placeholder="wird geladen …">
      </div>
      <div class="fg" style="flex:1;min-width:220px">
        <label>Absender-Adresse <span style="font-weight:400;color:var(--muted)">(MAIL_FROM)</span></label>
        <input type="email" id="ms-from" placeholder="wird geladen …">
      </div>
      <div class="fg" style="flex:1;min-width:220px">
        <label>Antwort-Adresse <span style="font-weight:400;color:var(--muted)">(MAIL_REPLY_TO)</span></label>
        <input type="email" id="ms-reply-to" placeholder="wird geladen …">
      </div>
    </div>
    <p style="font-size:12px;color:var(--muted);margin:10px 0 14px">
      Die Antwort-Adresse (<em>Reply-To</em>) ist die Adresse, die dem Empfänger beim Antworten
      auf eine System-Mail vorgeschlagen wird. Bleibt sie leer, wird die Absender-Adresse verwendet.
    </p>
    <button class="btn btn-primary" onclick="saveMailSettings()">Einstellungen speichern</button>
    <button class="btn btn-outline" style="margin-left:8px" onclick="loadMailSettings()">Zurücksetzen</button>
  </div>
</div>

<script>
// ── Mail-Einstellungen ────────────────────────────────────────
async function loadMailSettings() {
  const d = await api('get_mail_settings');
  if (!d.ok) {
    showMsg('Mail-Einstellungen: ' + d.error, false);
    ['ms-from-name','ms-from','ms-reply-to'].forEach(id => {
      document.getElementById(id).placeholder = '– Fehler beim Laden –';
    });
    return;
  }

  const fromName = d.data.mail_from_name ?? '';
  const from     = d.data.mail_from      ?? '';
  const replyTo  = d.data.mail_reply_to  ?? '';

  // Felder mit DB-Werten befüllen
  document.getElementById('ms-from-name').value = fromName;
  document.getElementById('ms-from').value      = from;
  document.getElementById('ms-reply-to').value  = replyTo;

  // Derzeit-aktiv-Hinweis: zeigt was tatsächlich versendet wird
  // (DB-Wert wenn gesetzt, sonst Fallback-Hinweis)
  const aktiv = d.data.aktiv_werte ?? {};
  const teile = [];
  if (aktiv.mail_from_name) teile.push(`Name: ${aktiv.mail_from_name}`);
  if (aktiv.mail_from)      teile.push(`Von: ${aktiv.mail_from}`);
  if (aktiv.mail_reply_to)  teile.push(`Reply-To: ${aktiv.mail_reply_to}`);

  const hinweis = document.getElementById('ms-aktiv-hinweis');
  const hinweisText = document.getElementById('ms-aktiv-text');
  if (teile.length) {
    hinweisText.textContent = teile.join(' · ');
    hinweis.style.display = 'block';
  } else {
    hinweis.style.display = 'none';
  }

  // Platzhalter zeigen den Fallback-Wert aus der Konfiguration
  document.getElementById('ms-from-name').placeholder = aktiv.fallback_from_name || 'Freistellungssystem Tarif';
  document.getElementById('ms-from').placeholder      = aktiv.fallback_from      || 'granvogl@vcockpit.de';
  document.getElementById('ms-reply-to').placeholder  = aktiv.fallback_reply_to  || 'granvogl@vcockpit.de';
}
async function saveMailSettings() {
  const fromName = document.getElementById('ms-from-name').value.trim();
  const from     = document.getElementById('ms-from').value.trim();
  const replyTo  = document.getElementById('ms-reply-to').value.trim();
  const d = await api('save_mail_settings', {
    mail_from_name: fromName,
    mail_from:      from,
    mail_reply_to:  replyTo,
  });
  showMsg(d.ok ? 'Mail-Einstellungen gespeichert.' : d.error, d.ok);
}
</script>

<!-- ── Erweiterte Freistellungsgründe ─────────────────────── -->
<div class="card" style="margin-top:22px">
  <div class="card-head"><h2>Erweiterte Freistellungsgründe pro Airline</h2></div>
  <div class="card-body">
    <p style="color:var(--muted);font-size:13px;margin-bottom:16px">
      Standard-Typen (Verhandlung, TK-Sitzung, Sonstiges) sind immer verfügbar.
      Hier können pro Airline zusätzliche Freistellungsgründe konfiguriert werden.
      Mitglieder dieser Airlines sehen das erweiterte Dropdown bei der Antragstellung.
      Gleiches gilt für die Umwidmungs-Funktion im Büro-Bereich.
    </p>

    <!-- Neuen Eintrag anlegen -->
    <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;margin-bottom:18px;padding:14px;background:#f8f9fb;border-radius:8px;border:1px solid var(--border,#dde2ea)">
      <div class="fg" style="flex:0 0 160px;margin:0">
        <label style="font-size:12px">Airline</label>
        <select id="av-airline" style="width:100%">
          <option value="">Wird geladen …</option>
        </select>
      </div>
      <div class="fg" style="flex:1;min-width:180px;margin:0">
        <label style="font-size:12px">Bezeichnung (Anzeigename)</label>
        <input type="text" id="av-label" placeholder="z. B. Schlichtung" maxlength="100">
      </div>
      <div class="fg" style="flex:0 0 80px;margin:0">
        <label style="font-size:12px">Reihenfolge</label>
        <input type="number" id="av-sort" value="10" min="0" max="99" style="width:100%">
      </div>
      <button class="btn btn-primary" onclick="saveVeranstaltung()">+ Hinzufügen</button>
    </div>

    <!-- Bestehende Einträge -->
    <div class="table-scroll">
      <table>
        <thead>
          <tr>
            <th>Airline</th>
            <th>Bezeichnung</th>
            <th>Reihenfolge</th>
            <th>Aktiv</th>
            <th></th>
          </tr>
        </thead>
        <tbody id="av-tbody">
          <tr><td colspan="5" style="text-align:center;padding:16px;color:var(--muted)">Wird geladen …</td></tr>
        </tbody>
      </table>
    </div>
  </div>
</div>

<script>
// ── Erweiterte Freistellungsgründe ────────────────────────────
async function loadVeranstaltungen() {
  // Airlines für Dropdown laden (dieselbe Liste wie im Büro-Bereich)
  const dA = await api('list_finance_airlines');
  const avSel = document.getElementById('av-airline');
  if (dA.ok && dA.data.airlines?.length) {
    avSel.innerHTML = '<option value="">– Airline wählen –</option>' +
      dA.data.airlines.map(a => `<option value="${a}">${a}</option>`).join('');
  } else {
    avSel.innerHTML = '<option value="">Keine Airlines verfügbar</option>';
  }

  // Bestehende Einträge laden
  const d = await api('list_airline_veranstaltungen');
  const tbody = document.getElementById('av-tbody');
  if (!d.ok) { tbody.innerHTML = `<tr><td colspan="5" style="color:#c0392b;padding:12px">${d.error}</td></tr>`; return; }
  if (!d.data.eintraege.length) {
    tbody.innerHTML = '<tr><td colspan="5" style="text-align:center;padding:16px;color:var(--muted)">Noch keine Einträge.</td></tr>';
    return;
  }
  tbody.innerHTML = d.data.eintraege.map(e => `<tr>
    <td><strong>${e.airline}</strong></td>
    <td>${e.label}</td>
    <td style="text-align:center">${e.sort_order}</td>
    <td>
      <label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-size:13px">
        <input type="checkbox" ${e.aktiv ? 'checked' : ''} onchange="toggleVeranstaltung(${e.id}, this.checked)">
        ${e.aktiv ? 'Ja' : 'Nein'}
      </label>
    </td>
    <td>
      <button class="btn btn-danger btn-sm" onclick="deleteVeranstaltung(${e.id}, '${e.airline}', '${e.label.replace(/'/g,"\\'")}')">✕ Löschen</button>
    </td>
  </tr>`).join('');
}
async function saveVeranstaltung() {
  const airline = document.getElementById('av-airline').value;
  const label   = document.getElementById('av-label').value.trim();
  const sort    = document.getElementById('av-sort').value;
  if (!airline) { showMsg('Bitte eine Airline auswählen.', false); return; }
  if (!label)   { showMsg('Bitte eine Bezeichnung eingeben.', false); return; }
  const d = await api('save_airline_veranstaltung', { airline, label, sort_order: sort, aktiv: 1 });
  showMsg(d.ok ? 'Eintrag gespeichert.' : d.error, d.ok);
  if (d.ok) {
    document.getElementById('av-airline').value = '';
    document.getElementById('av-label').value = '';
    document.getElementById('av-sort').value = '10';
    loadVeranstaltungen();
  }
}
async function toggleVeranstaltung(id, aktiv) {
  const d = await api('save_airline_veranstaltung', { id, aktiv: aktiv ? 1 : 0 });
  showMsg(d.ok ? 'Gespeichert.' : d.error, d.ok);
  if (d.ok) loadVeranstaltungen();
}
async function deleteVeranstaltung(id, airline, label) {
  if (!confirm(`„${label}" (${airline}) wirklich löschen?`)) return;
  const d = await api('save_airline_veranstaltung', { id, delete: 1 });
  showMsg(d.ok ? 'Gelöscht.' : d.error, d.ok);
  if (d.ok) loadVeranstaltungen();
}

// Alle Ladevorgänge starten nachdem alle Funktionen definiert sind
loadMailSettings();
loadVeranstaltungen();
</script>

<?php panel_foot(); ?>
