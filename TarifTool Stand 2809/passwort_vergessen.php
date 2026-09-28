<?php
// passwort_vergessen.php  –  Passwort-Reset für Panel-Benutzer UND TK-Mitglieder
// Erkennt anhand der E-Mail-Adresse, welcher Benutzertyp gemeint ist,
// und löst den jeweils passenden Reset-Flow aus.
require_once __DIR__ . '/_backend/bootstrap.php';
require_once __DIR__ . '/_backend/mail_mitglieder.php';
require_once __DIR__ . '/_backend/mail_panel.php';
send_security_headers();
session_start_secure();

// Bereits als Mitglied eingeloggt → weiterleiten
// (Panel-Session wird in buero/login.php geprüft)
?>
<!DOCTYPE html>
<html lang="de">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Passwort vergessen – Freistellungssystem</title>
  <link rel="stylesheet" href="assets/style.css">
  <style>
    .auth-wrap  { max-width:440px; margin:60px auto; padding:0 20px; }
    .auth-card  { background:#fff; border-radius:12px;
                  box-shadow:0 4px 24px rgba(0,0,0,.1); overflow:hidden; }
    .auth-head  { background:var(--navy2,#1a3a5c); color:#fff;
                  padding:28px 32px 20px; border-bottom:3px solid var(--gold,#c8a84b); }
    .auth-head h1 { margin:0; font-size:20px; font-weight:700; }
    .auth-head p  { margin:6px 0 0; font-size:13px; opacity:.8; }
    .auth-body  { padding:28px 32px; }
    .auth-body .fg { margin-bottom:16px; }
    .auth-body label { display:block; font-size:13px; font-weight:600;
                       color:var(--navy2,#1a3a5c); margin-bottom:5px; }
    .auth-body input[type=email] {
      width:100%; box-sizing:border-box; padding:9px 12px;
      border:1px solid var(--border,#dde2ea); border-radius:7px;
      font-size:14px; transition:border-color .15s; }
    .auth-body input:focus { outline:none; border-color:var(--navy2,#1a3a5c); }
    .btn-auth { width:100%; padding:12px; background:var(--navy2,#1a3a5c);
                color:#fff; border:none; border-radius:8px; font-size:15px;
                font-weight:700; cursor:pointer; margin-top:4px; transition:background .15s; }
    .btn-auth:hover    { background:#0f2744; }
    .btn-auth:disabled { opacity:.6; cursor:not-allowed; }
    .auth-links { text-align:center; margin-top:14px; font-size:13px;
                  color:var(--muted,#7a8fa6); }
    .auth-links a { color:var(--navy2,#1a3a5c); text-decoration:none; }
    .auth-links a:hover { text-decoration:underline; }
    .msg-box { padding:10px 14px; border-radius:8px; margin-bottom:12px;
               font-size:13px; display:none; }
    .msg-box.show { display:block; }
    .msg-err  { background:#f8d7da; color:#721c24; border:1px solid #f5c6cb; }
    .msg-ok   { background:#d4edda; color:#155724; border:1px solid #c3e6cb; }
    .success-wrap { text-align:center; padding:12px 0 4px; }
    .success-wrap .ico { font-size:48px; margin-bottom:12px; }
    .success-wrap h2 { font-size:18px; font-weight:700; color:#1c2b3a; margin-bottom:8px; }
    .success-wrap p { font-size:13px; color:var(--muted,#7a8fa6);
                      line-height:1.6; margin-bottom:20px; }
  </style>
</head>
<body>

<header class="site-header">
  <div class="brand">Tarif<span>Freistellung</span></div>
  <div class="tagline">Passwort zurücksetzen</div>
</header>

<div class="auth-wrap">

  <!-- Anfrage-Formular -->
  <div id="view-form">
    <div class="auth-card">
      <div class="auth-head">
        <h1>🔑 Passwort vergessen?</h1>
        <p>Geben Sie Ihre E-Mail-Adresse ein.</p>
      </div>
      <div class="auth-body">
        <div id="msg-req" class="msg-box"></div>
        <div class="fg">
          <label for="inp-email">E-Mail-Adresse</label>
          <input type="email" id="inp-email" placeholder="ihre@adresse.de"
                 autocomplete="email" autofocus>
        </div>
        <button class="btn-auth" id="btn-req">Reset-Link anfordern →</button>
        <div class="auth-links" style="margin-top:16px">
          <a href="mitglieder_login.php">← Zum Login</a>
        </div>
      </div>
    </div>
  </div>

  <!-- Erfolgs-Ansicht -->
  <div id="view-success" style="display:none">
    <div class="auth-card">
      <div class="auth-head">
        <h1>✉️ E-Mail unterwegs</h1>
        <p>Bitte prüfen Sie Ihren Posteingang.</p>
      </div>
      <div class="auth-body">
        <div class="success-wrap">
          <div class="ico">📬</div>
          <h2>Reset-Link versandt</h2>
          <p>
            Falls die eingegebene E-Mail-Adresse einem Konto zugeordnet ist,
            erhalten Sie in wenigen Minuten eine Nachricht mit einem Link
            zum Zurücksetzen Ihres Passworts.<br><br>
            Bitte prüfen Sie auch Ihren Spam-Ordner.
          </p>
        </div>
        <div class="auth-links">
          <a href="mitglieder_login.php">← Zum Login</a>
        </div>
      </div>
    </div>
  </div>

</div>

<footer>© <?= date('Y') ?> Freistellungssystem Tarif</footer>

<script>
const CSRF = '<?= csrf_token() ?>';

async function post(url, data) {
  const fd = new FormData();
  for (const [k, v] of Object.entries(data)) fd.append(k, v);
  return (await fetch(url, {method:'POST', body:fd, credentials:'same-origin'})).json();
}

function showMsg(text, err = true) {
  const el = document.getElementById('msg-req');
  el.className = 'msg-box show ' + (err ? 'msg-err' : 'msg-ok');
  el.textContent = text;
}

document.getElementById('btn-req').addEventListener('click', async () => {
  const email = document.getElementById('inp-email').value.trim();
  if (!email) { showMsg('Bitte E-Mail-Adresse eingeben.'); return; }

  const btn = document.getElementById('btn-req');
  btn.disabled = true;
  btn.textContent = 'Wird gesendet …';

  // Rate-Limiting: max. 3 Anfragen pro E-Mail-Adresse pro 15 Minuten (clientseitig)
  // Serverseitig wird dies in den jeweiligen API-Endpunkten ebenfalls geprüft.
  const rlKey = 'pw_reset_' + btoa(email);
  const rlData = JSON.parse(sessionStorage.getItem(rlKey) || '{"count":0,"first":0}');
  const now = Date.now();
  if (rlData.first && now - rlData.first < 15 * 60 * 1000 && rlData.count >= 3) {
    showMsg('Zu viele Anfragen. Bitte warten Sie 15 Minuten.', true);
    btn.disabled = false;
    btn.textContent = 'Reset-Link anfordern →';
    return;
  }
  rlData.count = rlData.first && now - rlData.first < 15 * 60 * 1000 ? rlData.count + 1 : 1;
  rlData.first = rlData.first && now - rlData.first < 15 * 60 * 1000 ? rlData.first : now;
  sessionStorage.setItem(rlKey, JSON.stringify(rlData));

  // Parallel in panel_users UND mitglieder suchen und ggf. beide Mails auslösen.
  // Aus Sicherheitsgründen geben beide Endpunkte immer OK zurück – kein Hinweis ob
  // ein Konto gefunden wurde. Bewusst Promise.allSettled() statt Promise.all():
  // Letzteres würde bei EINEM fehlgeschlagenen Request die gesamte Anzeige hängen
  // lassen (kein .catch() vorhanden) - und selbst mit .catch() wäre eine sichtbare
  // Fehlermeldung hier unerwünscht, da sie verraten würde, dass bei einem der beiden
  // Konten etwas anders lief als beim anderen. allSettled() wartet auf beide
  // Ergebnisse (egal ob erfolgreich oder fehlgeschlagen) und läuft garantiert weiter.
  await Promise.allSettled([
    // Panel-User Reset (eigener öffentlicher Endpunkt, kein Admin-Login nötig)
    post('api/panel_pw_reset.php', {
      action: 'request_pw_reset_panel',
      csrf:   CSRF,
      email:  email,
    }),
    // TK-Mitglied Reset (bestehender Endpunkt)
    post('api/mitglieder_login.php', {
      action: 'pw_reset_request',
      email:  email,
    }),
  ]);

  // Immer Erfolg zeigen – verhindert Enumeration
  document.getElementById('view-form').style.display    = 'none';
  document.getElementById('view-success').style.display = 'block';
});

document.getElementById('inp-email').addEventListener('keydown', e => {
  if (e.key === 'Enter') document.getElementById('btn-req').click();
});
</script>

</body>
</html>
