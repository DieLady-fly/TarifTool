<?php
// buero/mitglieder.php  –  Mitglieder-Verwaltung pro TK
require_once __DIR__ . '/../_backend/bootstrap.php';
require_once __DIR__ . '/../_backend/panel.php';
send_security_headers();
$auth = require_login('buero');

panel_head('Mitglieder');
panel_sidebar('mitglieder', $auth['rolle'], $auth['user']);
panel_topbar('Mitglieder');
?>
<style>
/* ── Modal: responsive & scrollbar-fähig ── */
#modal-overlay {
  /* Sicherstellen dass Overlay den vollen Viewport nutzt */
  padding: max(16px, env(safe-area-inset-top)) 16px
           max(16px, env(safe-area-inset-bottom)) 16px;
}
#modal-overlay > div {
  /* Kein festes max-height mehr – Scroll liegt am Overlay */
  position: relative;
}
/* Verhindert Zeilenbrüche im Modal-Header-Titel */
#modal-titel {
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  min-width: 0;
}
/* Auf sehr schmalen Screens (<400 px): kein horizontales Padding */
@media (max-width: 400px) {
  #modal-overlay {
    padding: 8px;
  }
  #modal-overlay > div > div:last-child {   /* modal body */
    padding: 14px 14px;
  }
}
</style>

<!-- ── TK-Auswahl ──────────────────────────────────────────── -->
<div class="card" style="margin-bottom:20px">
  <div class="card-head">
    <h2>Tarifkommission wählen</h2>
  </div>
  <div class="card-body">
    <div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap">
      <select id="tk-select" style="padding:8px 12px;border:1px solid var(--border,#dde2ea);border-radius:7px;font-size:14px;min-width:260px">
        <option value="">– TK wählen –</option>
      </select>
      <button class="btn btn-primary" id="btn-mitglied-neu" disabled>+ Mitglied anlegen</button>
    </div>
  </div>
</div>

<!-- ── Mitglieder-Tabelle ─────────────────────────────────── -->
<div class="card" id="card-mitglieder" style="display:none">
  <div class="card-head">
    <h2 id="card-titel">Mitglieder</h2>
    <div style="display:flex;gap:8px;align-items:center">
      <span id="mitglieder-count" style="font-size:13px;color:var(--muted)"></span>
    </div>
  </div>
  <div class="card-body-raw">
    <div class="table-scroll">
      <table>
        <thead><tr>
          <th>#</th>
          <th>Name</th>
          <th>Flugzeugmuster</th>
          <th>Stationierung</th>
          <th>Status</th>
          <th>Letzter Login</th>
          <th>Angelegt von</th>
          <th>Angelegt am</th>
          <th></th>
        </tr></thead>
        <tbody id="mitglieder-tbody">
          <tr><td colspan="9" style="text-align:center;padding:24px;color:var(--muted)">TK wählen …</td></tr>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- ══════════════════════════════════════════════════════════
     MODAL: Mitglied anlegen / bearbeiten
══════════════════════════════════════════════════════════ -->
<div id="modal-overlay" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:1000;align-items:flex-start;justify-content:center;overflow-y:auto;padding:16px;box-sizing:border-box">
  <div style="background:#fff;border-radius:12px;width:100%;max-width:520px;margin:auto;box-shadow:0 8px 32px rgba(0,0,0,.2);overflow:hidden;flex-shrink:0">
    <div style="background:var(--navy2,#1a3a5c);color:#fff;padding:20px 24px;border-bottom:3px solid var(--gold,#c8a84b);display:flex;justify-content:space-between;align-items:center">
      <h3 id="modal-titel" style="margin:0;font-size:17px">Mitglied anlegen</h3>
      <button id="modal-close" style="background:none;border:none;color:#fff;font-size:22px;cursor:pointer;line-height:1;padding:0">×</button>
    </div>
    <div style="padding:20px 24px;max-height:calc(100dvh - 120px);overflow-y:auto">
      <div id="modal-msg" style="display:none;padding:10px 14px;border-radius:7px;margin-bottom:16px;font-size:14px"></div>

      <input type="hidden" id="m-id" value="">
      <input type="hidden" id="m-tk-id" value="">

      <div style="margin-bottom:14px">
        <label style="display:block;font-size:13px;font-weight:600;color:var(--navy2,#1a3a5c);margin-bottom:5px">
          Vorname <span style="color:#e63946">*</span>
        </label>
        <input type="text" id="m-vorname" placeholder="Vorname"
          style="width:100%;box-sizing:border-box;padding:9px 12px;border:1px solid var(--border,#dde2ea);border-radius:7px;font-size:14px">
      </div>
      <div style="margin-bottom:14px">
        <label style="display:block;font-size:13px;font-weight:600;color:var(--navy2,#1a3a5c);margin-bottom:5px">
          Nachname <span style="color:#e63946">*</span>
        </label>
        <input type="text" id="m-nachname" placeholder="Nachname"
          style="width:100%;box-sizing:border-box;padding:9px 12px;border:1px solid var(--border,#dde2ea);border-radius:7px;font-size:14px">
      </div>
      <div style="margin-bottom:14px">
        <label style="display:block;font-size:13px;font-weight:600;color:var(--navy2,#1a3a5c);margin-bottom:5px">
          VC-E-Mail-Adresse <span style="color:#e63946">*</span>
        </label>
        <input type="email" id="m-email" placeholder="name@vc.de"
          style="width:100%;box-sizing:border-box;padding:9px 12px;border:1px solid var(--border,#dde2ea);border-radius:7px;font-size:14px">
        <div id="m-email-hint" style="display:none;font-size:12px;color:#856404;margin-top:5px;padding:6px 10px;background:#fff3cd;border-radius:5px">
          ⚠ E-Mail ändern sendet keine neue Einladungsmail. Nutze „Einladung erneut senden" wenn nötig.
        </div>
        <div id="m-email-existiert" style="display:none;font-size:13px;margin-top:8px;padding:12px 14px;background:#eef2ff;border:1px solid #c7d2fe;border-radius:7px">
          <div style="color:#3730a3;margin-bottom:8px">
            Es existiert bereits ein Mitglied mit dieser E-Mail: <strong id="m-email-existiert-name"></strong>
            (Heim-TK: <span id="m-email-existiert-tk"></span>).
          </div>
          <button type="button" class="btn btn-primary btn-sm" id="m-email-existiert-btn">
            Stattdessen zu <span id="m-email-existiert-ziel-tk"></span> hinzufügen
          </button>
        </div>
      </div>

      <div style="border-top:1px solid var(--border,#dde2ea);margin:16px 0 14px;padding-top:14px">
        <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--muted,#7a8fa6);margin-bottom:12px">
          Beschäftigung
        </div>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;margin-bottom:12px">
          <div>
            <label style="display:block;font-size:13px;font-weight:600;color:var(--navy2,#1a3a5c);margin-bottom:5px">
              Airline <span style="color:#e63946">*</span>
            </label>
            <select id="m-airline"
              style="width:100%;box-sizing:border-box;padding:9px 12px;border:1px solid var(--border,#dde2ea);border-radius:7px;font-size:14px">
              <option value="">– Bitte wählen –</option>
            </select>
          </div>
          <div>
            <label style="display:block;font-size:13px;font-weight:600;color:var(--navy2,#1a3a5c);margin-bottom:5px">
              Position <span style="color:#e63946">*</span>
            </label>
            <select id="m-position"
              style="width:100%;box-sizing:border-box;padding:9px 12px;border:1px solid var(--border,#dde2ea);border-radius:7px;font-size:14px">
              <option value="">– Wählen –</option>
              <option value="CPT">CPT – Captain</option>
              <option value="SFO">SFO – Senior First Officer</option>
              <option value="FO">FO – First Officer</option>
            </select>
          </div>
        </div>
        <div>
          <label style="display:block;font-size:13px;font-weight:600;color:var(--navy2,#1a3a5c);margin-bottom:5px">
            Flugzeugmuster <span style="color:#e63946">*</span>
          </label>
          <input type="text" id="m-muster" placeholder="z.B. Airbus A320"
            style="width:100%;box-sizing:border-box;padding:9px 12px;border:1px solid var(--border,#dde2ea);border-radius:7px;font-size:14px">
        </div>
        <div style="margin-top:12px">
          <label style="display:block;font-size:13px;font-weight:600;color:var(--navy2,#1a3a5c);margin-bottom:5px">
            Stationierungsort
          </label>
          <input type="text" id="m-stationierung" placeholder="z.B. Frankfurt (optional)"
            style="width:100%;box-sizing:border-box;padding:9px 12px;border:1px solid var(--border,#dde2ea);border-radius:7px;font-size:14px">
          <div style="font-size:12px;color:var(--muted,#7a8fa6);margin-top:4px">
            Optional – wird von manchen Airlines im Antrag mitgesendet. Leer lassen, falls nicht benötigt.
          </div>
        </div>
      </div>

      <div id="m-weitere-tks-wrap" style="display:none;border-top:1px solid var(--border,#dde2ea);margin:16px 0 14px;padding-top:14px">
        <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--muted,#7a8fa6);margin-bottom:4px">
          Weitere Tarifkommissionen (Mehrfachmitgliedschaft)
        </div>
        <div style="font-size:12px;color:var(--muted,#7a8fa6);margin-bottom:10px">
          Zusätzlich zur Heim-TK (<span id="m-heim-tk-name" style="font-weight:600"></span>) kann dieses Mitglied
          weiteren TKs zugeordnet werden - es kann dann für alle zugeordneten TKs im selben Portal-Login Anträge stellen.
        </div>
        <div id="m-weitere-tks-liste" style="display:grid;grid-template-columns:1fr;gap:0;max-height:200px;overflow-y:auto;border:1px solid var(--border,#dde2ea);border-radius:7px;padding:4px 0">
        </div>
      </div>

      <div style="display:flex;gap:10px;justify-content:flex-end">
        <button class="btn btn-outline" id="modal-cancel">Abbrechen</button>
        <button class="btn btn-primary" id="modal-save">Anlegen & Einladung senden</button>
      </div>
    </div>
  </div>
</div>

<script>
const CSRF    = '<?= csrf_token() ?>';
const IS_ADMIN = <?= panel_hat_rolle('admin') ? 'true' : 'false' ?>;
let currentTkId   = null;
let currentTkName = '';

// ── API-Hilfsfunktion ─────────────────────────────────────────
async function api(action, extra = {}) {
  const fd = new FormData();
  fd.append('action', action);
  fd.append('csrf', CSRF);
  Object.entries(extra).forEach(([k, v]) => {
    if (Array.isArray(v)) {
      // Arrays müssen als "key[]" mit mehreren Einträgen gesendet werden,
      // damit PHP sie in $_POST[k] als Array zusammenfasst (ein einzelner
      // fd.append(k, arrayWert) würde sonst nur den gestringifyten Wert
      // "1,2,3" als einzelnen String senden).
      v.forEach(item => fd.append(k.endsWith('[]') ? k : `${k}[]`, item));
    } else {
      fd.append(k, v);
    }
  });
  const r = await fetch('../api/buero.php', { method: 'POST', body: fd });
  return r.json();
}

// ── Status-Badge ──────────────────────────────────────────────
function statusBadge(aktiv, einladung_ausstehend) {
  if (!aktiv) {
    return `<span style="display:inline-block;padding:3px 10px;border-radius:20px;font-size:12px;font-weight:700;background:#e2e3e5;color:#383d41">Inaktiv</span>`;
  }
  if (einladung_ausstehend) {
    return `<span style="display:inline-block;padding:3px 10px;border-radius:20px;font-size:12px;font-weight:700;background:#fff3cd;color:#856404">Einladung ausstehend</span>`;
  }
  return `<span style="display:inline-block;padding:3px 10px;border-radius:20px;font-size:12px;font-weight:700;background:#e6f4ea;color:#1e7e34">Aktiv</span>`;
}

function formatDatum(iso) {
  if (!iso) return '<span style="color:var(--muted)">–</span>';
  return new Date(iso).toLocaleDateString('de-DE', {
    day: '2-digit', month: '2-digit', year: 'numeric',
    hour: '2-digit', minute: '2-digit'
  });
}

// ── TKs laden ─────────────────────────────────────────────────
api('list_tks', {}).then(d => {
  // list_tks ist in admin.php – buero.php hat keine eigene TK-Liste
  // Fallback: tks.php direkt aufrufen
}).catch(() => {});

fetch('../api/tks.php')
  .then(r => r.json())
  .then(d => {
    const sel = document.getElementById('tk-select');
    if (!d.ok || !d.data.length) {
      sel.innerHTML = '<option value="">Keine TKs vorhanden</option>';
      return;
    }
    d.data.forEach(tk => {
      const o = document.createElement('option');
      o.value = tk.id;
      o.textContent = tk.kuerzel + ' – ' + tk.bezeichnung;
      sel.appendChild(o);
    });

    // TK aus URL-Parameter vorauswählen
    const urlTk = new URLSearchParams(location.search).get('tk');
    if (urlTk) {
      sel.value = urlTk;
      sel.dispatchEvent(new Event('change'));
    }
  });

// ── TK wechseln ───────────────────────────────────────────────
document.getElementById('tk-select').addEventListener('change', function () {
  currentTkId = this.value || null;
  currentTkName = this.options[this.selectedIndex]?.textContent || '';

  const btnNeu = document.getElementById('btn-mitglied-neu');
  if (btnNeu) btnNeu.disabled = !currentTkId;

  if (!currentTkId) {
    document.getElementById('card-mitglieder').style.display = 'none';
    return;
  }

  ladeMitglieder();
});

// ── Mitglieder laden ──────────────────────────────────────────
function ladeMitglieder() {
  const tbody = document.getElementById('mitglieder-tbody');
  tbody.innerHTML = '<tr><td colspan="9" style="text-align:center;padding:24px;color:var(--muted)">Wird geladen …</td></tr>';
  document.getElementById('card-mitglieder').style.display = '';
  document.getElementById('card-titel').textContent = 'Mitglieder – ' + currentTkName;

  api('list_mitglieder', { tk_id: currentTkId }).then(d => {
    if (!d.ok) {
      tbody.innerHTML = `<tr><td colspan="9" style="text-align:center;padding:24px;color:#c0392b">Fehler: ${d.error}</td></tr>`;
      return;
    }

    const count = d.data.length;
    document.getElementById('mitglieder-count').textContent =
      count === 0 ? 'Keine Mitglieder' : count + ' Mitglied' + (count !== 1 ? 'er' : '');

    if (!count) {
      tbody.innerHTML = `<tr><td colspan="9" style="text-align:center;padding:32px;color:var(--muted)">
        Noch keine Mitglieder für diese TK angelegt.
        <br><br><button class="btn btn-primary btn-sm" onclick="modalOeffnen()">+ Erstes Mitglied anlegen</button>
      </td></tr>`;
      return;
    }

    tbody.innerHTML = d.data.map(m => `
      <tr id="row-${m.id}">
        <td class="mono" style="font-size:12px;color:var(--muted)">${m.id}</td>
        <td style="font-weight:600">${esc(m.vorname)} ${esc(m.nachname||'')}${m.ist_zusatz_tk?` <span title="Heim-TK: ${esc(m.heim_tk_kuerzel||'')} – über Mehrfachmitgliedschaft dieser TK zugeordnet" style="display:inline-block;font-size:10px;font-weight:700;padding:1px 7px;border-radius:20px;background:#eef2ff;color:#3730a3;vertical-align:middle;margin-left:4px">Zusatz-TK</span>`:''}</td>
        <td>
          <input type="text" value="${esc(m.flugzeugmuster || '')}" placeholder="z.B. A320"
            id="muster-${m.id}"
            onchange="flugzeugmusterSpeichern(${m.id}, this)"
            onkeydown="if(event.key==='Enter') this.blur()"
            style="width:100px;padding:5px 8px;border:1px solid var(--border,#dde2ea);border-radius:6px;font-size:13px">
        </td>
        <td>
          <input type="text" value="${esc(m.stationierung || '')}" placeholder="– keine –"
            id="stat-${m.id}"
            onchange="stationierungSpeichern(${m.id}, this)"
            onkeydown="if(event.key==='Enter') this.blur()"
            style="width:130px;padding:5px 8px;border:1px solid var(--border,#dde2ea);border-radius:6px;font-size:13px">
        </td>
        <td>${statusBadge(m.aktiv, m.einladung_ausstehend)}</td>
        <td style="font-size:13px;color:var(--muted)">${formatDatum(m.letzter_login)}</td>
        <td style="font-size:12px;color:var(--muted)">${esc(m.erstellt_von || '–')}</td>
        <td style="font-size:13px;color:var(--muted)">${formatDatum(m.erstellt_am)}</td>
        <td style="white-space:nowrap">
          ${m.einladung_ausstehend
            ? `<button class="btn btn-outline btn-sm" onclick="einladungErneut(${m.id})" title="Einladungsmail erneut senden">📧 Erneut senden</button>`
            : ''}
          <button class="btn btn-outline btn-sm" onclick="modalBearbeiten(${m.id})" title="Bearbeiten">✎</button>
          <button class="btn btn-outline btn-sm" style="color:${m.aktiv ? '#c0392b' : '#1e7e34'}"
            onclick="toggleMitglied(${m.id}, ${m.aktiv})"
            title="${m.aktiv ? 'Deaktivieren' : 'Aktivieren'}">
            ${m.aktiv ? '⏸' : '▶'}
          </button>
          <button class="btn btn-outline btn-sm" style="color:#c0392b"
            onclick="loeschenMitglied(${m.id}, '${esc(m.vorname)} ${esc(m.nachname||'')}')" title="Löschen">🗑</button>
        </td>
      </tr>
    `).join('');
  });
}

// ── Stationierungsort inline in der Tabelle speichern ─────────
async function stationierungSpeichern(id, input) {
  const wert = input.value.trim();
  input.disabled = true;
  const d = await api('update_mitglied_stationierung', { id, stationierung: wert });
  input.disabled = false;

  if (d.ok) {
    input.style.borderColor = '#1e7e34';
    input.style.background  = '#e6f4ea';
    showToast('Stationierungsort gespeichert.', 'ok');
  } else {
    input.style.borderColor = '#c0392b';
    input.style.background  = '#fde8e8';
    showToast('Fehler: ' + d.error, 'err');
  }
  setTimeout(() => {
    input.style.borderColor = '';
    input.style.background  = '';
  }, 1500);
}

// ── Flugzeugmuster inline in der Tabelle speichern ────────────
async function flugzeugmusterSpeichern(id, input) {
  const wert = input.value.trim();
  if (!wert) {
    input.style.borderColor = '#c0392b';
    input.style.background  = '#fde8e8';
    showToast('Flugzeugmuster darf nicht leer sein.', 'err');
    setTimeout(() => { input.style.borderColor = ''; input.style.background = ''; }, 1500);
    return;
  }
  input.disabled = true;
  const d = await api('update_mitglied_flugzeugmuster', { id, flugzeugmuster: wert });
  input.disabled = false;

  if (d.ok) {
    input.style.borderColor = '#1e7e34';
    input.style.background  = '#e6f4ea';
    showToast('Flugzeugmuster gespeichert.', 'ok');
  } else {
    input.style.borderColor = '#c0392b';
    input.style.background  = '#fde8e8';
    showToast('Fehler: ' + d.error, 'err');
  }
  setTimeout(() => {
    input.style.borderColor = '';
    input.style.background  = '';
  }, 1500);
}

// ── Einladung erneut senden ───────────────────────────────────
async function einladungErneut(id) {
  if (!confirm('Einladungsmail erneut senden?')) return;
  const d = await api('resend_einladung', { id });
  if (d.ok) {
    showToast('Einladungsmail wurde erneut gesendet.', 'ok');
    ladeMitglieder();
  } else {
    showToast('Fehler: ' + d.error, 'err');
  }
}

// ── Mitglied toggle (aktivieren/deaktivieren) ─────────────────
async function toggleMitglied(id, aktiv) {
  const aktion = aktiv ? 'deaktivieren' : 'aktivieren';
  if (!confirm(`Mitglied wirklich ${aktion}?`)) return;
  const d = await api('toggle_mitglied', { id });
  if (d.ok) {
    showToast(`Mitglied wurde ${aktion === 'deaktivieren' ? 'deaktiviert' : 'aktiviert'}.`, 'ok');
    ladeMitglieder();
  } else {
    showToast('Fehler: ' + d.error, 'err');
  }
}

// ── Mitglied löschen ──────────────────────────────────────────
async function loeschenMitglied(id, name) {
  if (!confirm(`Mitglied „${name}" wirklich löschen?\n\nDie Anträge bleiben erhalten, werden aber vom Mitglied-Account getrennt.`)) return;
  const d = await api('delete_mitglied', { id });
  if (d.ok) {
    showToast('Mitglied gelöscht.', 'ok');
    ladeMitglieder();
  } else {
    showToast('Fehler: ' + d.error, 'err');
  }
}

// Airline-Dropdown befüllen - ausschließlich aus den angelegten
// Tarifkommissionen (list_tks, bereits an anderer Stelle in dieser Datei
// für "Weitere TKs" genutzt), NICHT aus dem geteilten
// list_finance_airlines-Endpoint (der zusätzlich Airlines aus Anträgen
// zieht - für dieses Dropdown laut Vorgabe nicht gewünscht).
// selectedValue wird danach vorbelegt - existiert dieser Wert (noch) nicht
// in der Liste (z.B. bei einem alten/inzwischen entfernten Airline-Namen),
// wird er als zusätzliche Option ergänzt, damit beim Bearbeiten nichts
// stillschweigend verloren geht.
// autoSelectTkId (optional, nur beim Neuanlegen relevant): ist noch kein
// selectedValue gesetzt, wird versucht die Airline der aktuell gewählten
// TK automatisch vorzubelegen (bleibt änderbar) - spart in der Praxis
// meist einen Klick, da ein Mitglied i.d.R. für die Airline der gerade
// angezeigten TK angelegt wird.
async function ladeAirlinesFuerSelect(selectedValue, autoSelectTkId) {
  const sel = document.getElementById('m-airline');
  sel.innerHTML = '<option value="">– Bitte wählen –</option>';
  const d = await api('list_tks');
  let autoAirline = '';
  if (d.ok) {
    const airlines = [...new Set(
      d.data.filter(tk => tk.aktiv && tk.airline).map(tk => tk.airline)
    )].sort((a, b) => a.localeCompare(b, 'de'));
    airlines.forEach(a => {
      const o = document.createElement('option');
      o.value = a; o.textContent = a;
      sel.appendChild(o);
    });
    if (autoSelectTkId) {
      const tk = d.data.find(x => String(x.id) === String(autoSelectTkId));
      if (tk && tk.airline) autoAirline = tk.airline;
    }
  }
  const finalValue = selectedValue || autoAirline;
  if (finalValue && ![...sel.options].some(o => o.value === finalValue)) {
    const o = document.createElement('option');
    o.value = finalValue; o.textContent = finalValue + ' (nicht mehr in der Liste)';
    sel.appendChild(o);
  }
  sel.value = finalValue || '';
}

// ══════════════════════════════════════════════════════════════
// MODAL: Anlegen / Bearbeiten
// ══════════════════════════════════════════════════════════════
async function modalOeffnen() {
  document.getElementById('m-id').value       = '';
  document.getElementById('m-tk-id').value    = currentTkId;
  document.getElementById('m-vorname').value  = '';
  document.getElementById('m-nachname').value = '';
  document.getElementById('m-email').value    = '';
  document.getElementById('m-position').value = '';
  document.getElementById('m-muster').value   = '';
  document.getElementById('m-stationierung').value = '';
  document.getElementById('m-email-hint').style.display = 'none';
  document.getElementById('m-email-existiert').style.display = 'none';
  // Weitere TKs sind erst nach dem Anlegen zuweisbar (das Mitglied
  // braucht dafür schon eine ID) - beim Neuanlegen daher ausgeblendet.
  document.getElementById('m-weitere-tks-wrap').style.display = 'none';
  document.getElementById('modal-titel').textContent    = 'Mitglied anlegen';
  document.getElementById('modal-save').textContent     = 'Anlegen & Einladung senden';
  modalMsg('');
  await ladeAirlinesFuerSelect('', currentTkId);
  const ov = document.getElementById('modal-overlay');
  ov.style.display = 'flex';
  ov.scrollTop = 0;
  setTimeout(() => document.getElementById('m-vorname').focus(), 50);
}

// Beim Verlassen des E-Mail-Felds im Anlegen-Modus prüfen, ob unter dieser
// E-Mail schon ein Mitglied existiert (z.B. bereits Mitglied einer anderen
// TK) - falls ja, statt eines fehlschlagenden "Anlegen"-Versuchs direkt
// anbieten, das bestehende Mitglied der aktuell gewählten TK
// hinzuzufügen (Mehrfachmitgliedschaft), ohne einen doppelten
// Mitglieder-Datensatz für dieselbe Person anzulegen.
document.getElementById('m-email').addEventListener('blur', async function(){
  const box = document.getElementById('m-email-existiert');
  box.style.display = 'none';
  if (document.getElementById('m-id').value) return; // nur im Anlegen-Modus relevant
  const email = this.value.trim();
  if (!email || !email.includes('@')) return;

  const d = await api('find_mitglied_by_email', { email });
  if (!d.ok || !d.data) return;
  const m = d.data;

  document.getElementById('m-email-existiert-name').textContent = `${m.vorname} ${m.nachname}`;
  document.getElementById('m-email-existiert-tk').textContent   = `${m.tk_kuerzel} – ${m.tk_bezeichnung}`;
  document.getElementById('m-email-existiert-ziel-tk').textContent = currentTkName;

  const schonZugeordnet = String(m.tk_id) === String(currentTkId) ||
    (m.weitere_tk_ids || []).map(String).includes(String(currentTkId));
  const btn = document.getElementById('m-email-existiert-btn');
  if (schonZugeordnet) {
    btn.style.display = 'none';
    document.getElementById('m-email-existiert').querySelector('div').textContent =
      `${m.vorname} ${m.nachname} ist bereits Mitglied dieser TK.`;
  } else {
    btn.style.display = '';
    btn.onclick = async () => {
      btn.disabled = true; btn.textContent = 'Wird hinzugefügt …';
      const r = await api('add_mitglied_zu_tk', { id: m.id, tk_id: currentTkId });
      btn.disabled = false; btn.textContent = `Stattdessen zu ${currentTkName} hinzufügen`;
      if (r.ok) {
        modalSchliessen();
        showToast(`${m.vorname} ${m.nachname} wurde ${currentTkName} zugeordnet.`, 'ok');
        ladeMitglieder();
      } else {
        showToast('Fehler: ' + r.error, 'err');
      }
    };
  }
  box.style.display = 'block';
});

async function modalBearbeiten(id) {
  const d = await api('get_mitglied', { id });
  if (!d.ok) { showToast('Fehler beim Laden: ' + d.error, 'err'); return; }
  const m = d.data;

  document.getElementById('m-id').value       = m.id;
  document.getElementById('m-tk-id').value    = m.tk_id;
  document.getElementById('m-vorname').value  = m.vorname;
  document.getElementById('m-nachname').value = m.nachname;
  document.getElementById('m-email').value    = m.email;
  await ladeAirlinesFuerSelect(m.airline || '');
  document.getElementById('m-position').value = m.position || '';
  document.getElementById('m-muster').value   = m.flugzeugmuster || '';
  document.getElementById('m-stationierung').value = m.stationierung || '';
  document.getElementById('m-email-hint').style.display = 'block';
  document.getElementById('modal-titel').textContent    = 'Mitglied bearbeiten';
  document.getElementById('modal-save').textContent     = 'Änderungen speichern';
  modalMsg('');
  await weitereTksLaden(m);
  const ov = document.getElementById('modal-overlay');
  ov.style.display = 'flex';
  ov.scrollTop = 0;
}

// Checkbox-Liste "Weitere Tarifkommissionen" befüllen - alle aktiven TKs
// außer der Heim-TK, mit den bereits zugeordneten vorausgewählt.
async function weitereTksLaden(m) {
  document.getElementById('m-heim-tk-name').textContent = m.tk_bezeichnung || '';
  const wrap = document.getElementById('m-weitere-tks-wrap');
  const liste = document.getElementById('m-weitere-tks-liste');
  liste.innerHTML = '<div style="font-size:12px;color:var(--muted)">Wird geladen …</div>';
  wrap.style.display = 'block';

  const dTks = await api('list_tks');
  if (!dTks.ok) { liste.innerHTML = '<div style="font-size:12px;color:#c0392b">Fehler beim Laden der TKs.</div>'; return; }

  const zugeordnet = new Set((m.weitere_tk_ids || []).map(String));
  const auswahl = dTks.data.filter(tk => tk.aktiv && tk.id != m.tk_id);
  if (!auswahl.length) {
    liste.innerHTML = '<div style="font-size:12px;color:var(--muted)">Keine weiteren aktiven TKs vorhanden.</div>';
    return;
  }
  liste.innerHTML = auswahl.map(tk => `
    <label style="display:grid;grid-template-columns:18px 1fr;align-items:center;gap:10px;padding:7px 12px;font-size:13px;cursor:pointer;border-bottom:1px solid var(--border,#dde2ea);margin:0">
      <input type="checkbox" class="m-weitere-tk-cb" value="${tk.id}" ${zugeordnet.has(String(tk.id)) ? 'checked' : ''} style="margin:0;width:15px;height:15px;flex-shrink:0;cursor:pointer">
      <span style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis" title="${tk.kuerzel} – ${tk.bezeichnung}">
        <strong style="color:var(--navy2,#1a3a5c)">${tk.kuerzel}</strong>
        <span style="color:var(--muted,#7a8fa6);margin-left:4px">–</span>
        ${tk.bezeichnung}
      </span>
    </label>
  `).join('').replace(/(<label[^>]*>[\s\S]*?<\/label>)(?=\s*$)/, s => s.replace('border-bottom:1px solid var(--border,#dde2ea)', 'border-bottom:none'));
}

function modalSchliessen() {
  document.getElementById('modal-overlay').style.display = 'none';
}

function modalMsg(text, isErr = true) {
  const el = document.getElementById('modal-msg');
  if (!text) { el.style.display = 'none'; return; }
  el.style.display  = 'block';
  el.style.background = isErr ? '#f8d7da' : '#d4edda';
  el.style.color      = isErr ? '#721c24' : '#155724';
  el.style.border     = `1px solid ${isErr ? '#f5c6cb' : '#c3e6cb'}`;
  el.textContent      = text;
}

document.getElementById('modal-save').addEventListener('click', async () => {
  const id       = document.getElementById('m-id').value;
  const tk_id    = document.getElementById('m-tk-id').value;
  const vorname  = document.getElementById('m-vorname').value.trim();
  const nachname = document.getElementById('m-nachname').value.trim();
  const email    = document.getElementById('m-email').value.trim();
  const airline  = document.getElementById('m-airline').value.trim();
  const position = document.getElementById('m-position').value;
  const muster   = document.getElementById('m-muster').value.trim();
  const stationierung = document.getElementById('m-stationierung').value.trim(); // optional, keine Pflicht

  modalMsg('');
  if (!vorname)  return modalMsg('Bitte Vorname eingeben.');
  if (!nachname) return modalMsg('Bitte Nachname eingeben.');
  if (!email)    return modalMsg('Bitte E-Mail eingeben.');
  if (!airline)  return modalMsg('Bitte Airline eingeben.');
  if (!position) return modalMsg('Bitte Position wählen.');
  if (!muster)   return modalMsg('Bitte Flugzeugmuster eingeben.');

  const btn = document.getElementById('modal-save');
  btn.disabled = true;
  const altText = btn.textContent;
  btn.textContent = 'Wird gespeichert …';

  let d;
  if (id) {
    d = await api('update_mitglied', { id, vorname, nachname, email, airline, position, flugzeugmuster: muster, stationierung });
  } else {
    d = await api('create_mitglied', { tk_id, vorname, nachname, email, airline, position, flugzeugmuster: muster, stationierung });
  }

  // Weitere TKs (Mehrfachmitgliedschaft) nur beim Bearbeiten eines
  // bestehenden Mitglieds mitspeichern (beim Neuanlegen ist das Feld
  // ausgeblendet, siehe modalOeffnen).
  if (d.ok && id) {
    const tkIds = [...document.querySelectorAll('.m-weitere-tk-cb:checked')].map(cb => cb.value);
    const dTks2 = await api('update_mitglied_tks', { id, tk_ids: tkIds });
    if (!dTks2.ok) {
      btn.disabled = false; btn.textContent = altText;
      return modalMsg('Mitglied gespeichert, aber weitere TKs konnten nicht gespeichert werden: ' + (dTks2.error || ''));
    }
  }

  btn.disabled = false;
  btn.textContent = altText;

  if (d.ok) {
    modalSchliessen();
    showToast(id ? 'Mitglied gespeichert.' : 'Mitglied angelegt – Einladungsmail gesendet.', 'ok');
    ladeMitglieder();
  } else {
    modalMsg(d.error || 'Unbekannter Fehler.');
  }
});

document.getElementById('modal-close').addEventListener('click',  modalSchliessen);
document.getElementById('modal-cancel').addEventListener('click', modalSchliessen);
document.getElementById('modal-overlay').addEventListener('click', e => {
  if (e.target === document.getElementById('modal-overlay')) modalSchliessen();
});

// Escape-Taste schließt Modal
document.addEventListener('keydown', e => {
  if (e.key === 'Escape') modalSchliessen();
});

const btnNeu = document.getElementById('btn-mitglied-neu');
if (btnNeu) btnNeu.addEventListener('click', modalOeffnen);

// ── Toast-Nachrichten ─────────────────────────────────────────
function showToast(text, type = 'ok') {
  let toast = document.getElementById('toast');
  if (!toast) {
    toast = document.createElement('div');
    toast.id = 'toast';
    toast.style.cssText = `
      position:fixed;bottom:28px;right:28px;z-index:2000;
      padding:12px 20px;border-radius:8px;font-size:14px;font-weight:600;
      box-shadow:0 4px 16px rgba(0,0,0,.15);
      transition:opacity .3s;max-width:340px;
    `;
    document.body.appendChild(toast);
  }
  toast.style.background = type === 'ok' ? '#1e7e34' : '#c0392b';
  toast.style.color       = '#fff';
  toast.style.opacity     = '1';
  toast.textContent        = text;
  clearTimeout(toast._t);
  toast._t = setTimeout(() => { toast.style.opacity = '0'; }, 3500);
}

function esc(s) {
  return String(s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
</script>

<?php panel_foot(); ?>
