<?php
// buero/buero_kontakt.php  –  Büro-Kontakt (CC-Adresse für Storno-Emails)
require_once __DIR__ . '/../_backend/bootstrap.php';
require_once __DIR__ . '/../_backend/panel.php';
send_security_headers();
$auth = require_login('buero');

panel_head('Büro Kontakt');
panel_sidebar('buero_kontakt', $auth['rolle'], $auth['user']);
panel_topbar('Büro Kontakt');
?>

<style>
.fg { display:flex; flex-direction:column; gap:5px; margin-bottom:18px; }
.fg label { font-size:12px; font-weight:700; color:var(--muted,#7a8fa6);
  text-transform:uppercase; letter-spacing:.04em; }
.fg input { padding:10px 12px; border:1px solid var(--border,#dde2ea);
  border-radius:7px; font-size:14px; max-width:480px; }
.fg input:focus { outline:none; border-color:var(--navy2,#1a3a5c);
  box-shadow:0 0 0 3px rgba(26,58,92,.08); }
.hint { font-size:12px; color:var(--muted,#7a8fa6); margin-top:2px; }
.alert { padding:10px 14px; border-radius:7px; font-size:13px;
  margin-bottom:16px; display:none; }
.alert-success { background:#e6f4ea; color:#1e7e34; border:1px solid #81c784; }
.alert-error   { background:#fde8e8; color:#c0392b; border:1px solid #e57373; }
</style>

<div class="card" style="max-width:680px">
  <div class="card-head"><h2>Büro Kontakt</h2></div>
  <div class="card-body">
    <div id="save-alert" class="alert"></div>

    <p style="font-size:14px;color:var(--muted,#7a8fa6);margin-bottom:20px">
      Diese Adresse wird bei jedem Storno-E-Mail-Versand in CC gesetzt,
      und erhält außerdem die Freigabe-Mails (inkl. Token) für
      <strong>TK-Sitzungen</strong>. Leer lassen um CC zu deaktivieren
      (TK-Sitzungs-Freigaben gehen dann übergangsweise wieder an den
      Tarifreferenten).
    </p>

    <div class="fg">
      <label>E-Mail-Adresse Büro</label>
      <input type="email" id="storno-cc" placeholder="z.B. buero@vcockpit.de">
      <span class="hint">CC bei Storno-Mails an den Arbeitgeber · Empfänger der Freigabe-Mails für TK-Sitzungen · Empfänger der Frist-Erinnerungsmail.</span>
    </div>

    <div class="fg">
      <label>Frist-Erinnerung (Tage vorher)</label>
      <input type="number" id="erinnerung-frist-tage" min="1" max="90" step="1" style="max-width:120px">
      <span class="hint">
        Anzahl Tage vor Beginn eines Ereignisses, ab der die tägliche Erinnerungsmail
        (Anträge mit Status „Freigabe Büro“ oder „Beantragt bei AG“) verschickt wird. Standard: 7.
      </span>
    </div>

    <button class="btn btn-primary" id="save-btn" onclick="speichern()">
      Speichern
    </button>
  </div>
</div>

<div class="card" style="max-width:680px;margin-top:20px">
  <div class="card-head"><h2>Frist-Erinnerung testen</h2></div>
  <div class="card-body">
    <div id="test-alert" class="alert"></div>
    <p style="font-size:14px;color:var(--muted,#7a8fa6);margin-bottom:16px">
      Löst die Erinnerungsmail sofort aus (unabhängig vom täglichen Cron-Lauf) und
      schickt sie an die oben hinterlegte Büro-Adresse. Nützlich zum Testen der
      Konfiguration, ohne auf den nächsten planmäßigen Lauf warten zu müssen.
    </p>
    <button class="btn btn-outline" id="test-btn" onclick="testmailSenden()">
      ✉ Testmail jetzt senden
    </button>
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

async function laden() {
  const d = await api('get_einstellungen');
  if (d.ok) {
    document.getElementById('storno-cc').value = d.data.storno_email_cc || '';
    document.getElementById('erinnerung-frist-tage').value = d.data.erinnerung_frist_tage || 7;
  }
}

async function speichern() {
  const btn      = document.getElementById('save-btn');
  const alertBox = document.getElementById('save-alert');
  alertBox.style.display = 'none';
  btn.disabled = true; btn.textContent = '…';

  const d = await api('save_einstellungen', {
    storno_email_cc: document.getElementById('storno-cc').value.trim(),
    erinnerung_frist_tage: document.getElementById('erinnerung-frist-tage').value.trim() || '7',
  });

  btn.disabled = false; btn.textContent = 'Speichern';

  alertBox.className = d.ok ? 'alert alert-success' : 'alert alert-error';
  alertBox.innerHTML = d.ok
    ? '✓ Gespeichert.'
    : '⚠ ' + esc(d.error || 'Fehler beim Speichern.');
  alertBox.style.display = 'block';
  if (d.ok) setTimeout(() => { alertBox.style.display = 'none'; }, 3000);
}

async function testmailSenden() {
  const btn      = document.getElementById('test-btn');
  const alertBox = document.getElementById('test-alert');
  alertBox.style.display = 'none';
  btn.disabled = true; btn.textContent = 'Wird gesendet …';

  let d;
  try {
    d = await api('sende_frist_erinnerung_test');
  } finally {
    btn.disabled = false; btn.textContent = '✉ Testmail jetzt senden';
  }

  alertBox.className = (d.ok && d.data.versendet) ? 'alert alert-success' : 'alert alert-error';
  if (!d.ok) {
    alertBox.innerHTML = '⚠ ' + esc(d.error || 'Fehler beim Senden.');
  } else if (d.data.versendet) {
    alertBox.innerHTML = `✓ Testmail versendet – ${d.data.anzahl_antraege} Antrag/Anträge, ${d.data.anzahl_ereignisse} Ereignis(se) (Frist: ${d.data.frist_tage} Tage).`;
  } else {
    alertBox.innerHTML = 'ℹ Keine Mail versendet: ' + esc(d.data.grund || 'unbekannter Grund');
  }
  alertBox.style.display = 'block';
}

laden();
</script>

<?php panel_foot(); ?>
