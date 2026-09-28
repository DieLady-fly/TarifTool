<?php
// ============================================================
// panel_pw_reset.php  –  Passwort setzen für Panel-Benutzer
// (Einladung ODER "Passwort vergessen"), OHNE Login nötig.
//
// Bewusst eine EIGENE, öffentliche Datei statt Teil von
// buero_login.php: buero_login.php ist serverseitig (nginx) auf die
// Büro-/VPN-Ausgangs-IP beschränkt (location = /buero_login.php).
// Ein Büro-Nutzer, der seine Einladungs- oder Reset-Mail außerhalb
// des VPNs öffnet (z.B. von zuhause, bevor er überhaupt Zugang zum
// Büro-Netz hat), würde dort nur eine 403-Fehlerseite vom Webserver
// sehen, ohne das Formular je zu Gesicht zu bekommen. Diese Seite
// hier muss daher von überall erreichbar bleiben - genau wie
// api/panel_pw_reset.php im Backend (das diese Seite auch aufruft,
// NICHT das VPN-beschränkte api/panel_auth.php oder api/admin.php,
// welches zusätzlich sogar eine aktive Admin-Session voraussetzt und
// für einen noch nicht eingeloggten Nutzer daher ohnehin immer mit
// "Zugriff verweigert" fehlschlagen würde).
//
// Ersetzt eine ältere, nie funktionsfähige Version dieser Datei, die
// den falschen GET-Parameter ("token" statt "pw_reset", passend zu
// den tatsächlich verschickten Mail-Links aus _backend/mail_panel.php)
// las und den falschen, Session-geschützten Endpoint aufrief.
// ============================================================
require_once __DIR__ . '/_backend/bootstrap.php';
send_security_headers();
session_start_secure();

// Token aus URL lesen
$pw_reset_token = trim($_GET['pw_reset'] ?? '');

// PW-Reset-Token validieren (deckt sowohl Einladung als auch
// "Passwort vergessen" ab - beide nutzen dieselben Spalten
// pw_reset_token/pw_reset_ablauf, siehe _backend/mail_panel.php)
$pwreset_gueltig = false;
if ($pw_reset_token && preg_match('/^[a-f0-9]{64}$/', $pw_reset_token)) {
    $chk = db()->prepare(
        "SELECT id FROM panel_users
         WHERE pw_reset_token = ? AND pw_reset_ablauf > NOW() AND aktiv = 1
         LIMIT 1"
    );
    $chk->execute([$pw_reset_token]);
    $pwreset_gueltig = (bool)$chk->fetch();
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Passwort festlegen – Freistellungssystem</title>
<link rel="stylesheet" href="assets/style.css">
<style>
.pw-strength{height:4px;border-radius:2px;margin-top:6px;transition:all .3s}
.pw-s0{background:#e9ecef;width:0}
.pw-s1{background:#dc3545;width:33%}
.pw-s2{background:#ffc107;width:66%}
.pw-s3{background:#28a745;width:100%}
</style>
</head>
<body>
<div class="login-wrap">
  <div>
    <div class="login-card">
      <div class="login-head">
        <h1>Tarif<span>Freistellung</span></h1>
        <p>Passwort festlegen</p>
      </div>
      <div class="login-body">
        <div id="alert-box" style="display:none" class="alert alert-error"></div>

        <?php if ($pwreset_gueltig): ?>
        <p style="margin:0 0 18px;font-size:14px;color:var(--muted)">
          Bitte legen Sie Ihr neues Passwort fest.
        </p>
        <form id="pwreset-form">
          <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
          <div class="fg" style="margin-bottom:16px">
            <label for="rst-pw">Neues Passwort</label>
            <input type="password" id="rst-pw" placeholder="Mindestens 12 Zeichen" autocomplete="new-password">
            <div class="pw-strength pw-s0" id="rst-pw-strength"></div>
          </div>
          <div class="fg" style="margin-bottom:22px">
            <label for="rst-pw2">Passwort wiederholen</label>
            <input type="password" id="rst-pw2" placeholder="Passwort bestätigen" autocomplete="new-password">
          </div>
          <button type="submit" class="btn btn-primary btn-xl btn-block" id="btn-pwreset">Passwort speichern →</button>
        </form>
        <?php else: ?>
        <p style="margin:0 0 18px;font-size:14px;color:var(--muted)">
          Dieser Link ist ungültig oder abgelaufen. Bitte fordern Sie ggf. einen neuen Link an oder wenden Sie sich ans Büro.
        </p>
        <div style="text-align:center">
          <a href="passwort_vergessen.php" class="btn btn-primary btn-xl btn-block" style="display:block;text-decoration:none;box-sizing:border-box">Neuen Link anfordern →</a>
        </div>
        <?php endif; ?>

      </div>
    </div>
  </div>
</div>

<script>
function showErr(msg) {
  const b = document.getElementById('alert-box');
  b.textContent = '⚠ ' + msg;
  b.style.display = 'block';
}
function hideErr() {
  document.getElementById('alert-box').style.display = 'none';
}
async function postApi(url, data) {
  const f = new FormData();
  for (const [k, v] of Object.entries(data)) f.append(k, v);
  return (await fetch(url, {method:'POST', body:f, credentials:'same-origin'})).json();
}
function pwStrength(pw) {
  let s = 0;
  if (pw.length >= 8) s++;
  if (pw.length >= 12) s++;
  if (/[^a-zA-Z0-9]/.test(pw) || /[0-9]/.test(pw)) s++;
  return s;
}
function updateStrength(inpId, barId) {
  const bar = document.getElementById(barId);
  if (bar) bar.className = 'pw-strength pw-s' + pwStrength(document.getElementById(inpId)?.value || '');
}

<?php if ($pwreset_gueltig): ?>
document.getElementById('rst-pw')?.addEventListener('input', () => updateStrength('rst-pw', 'rst-pw-strength'));
document.getElementById('pwreset-form')?.addEventListener('submit', async function(e) {
  // submit statt nur click auf den Button, damit auch Enter in einem der
  // beiden Passwortfelder funktioniert (siehe buero_login.php-Historie).
  e.preventDefault();
  const pw  = document.getElementById('rst-pw').value;
  const pw2 = document.getElementById('rst-pw2').value;
  hideErr();
  if (pw.length < 12) return showErr('Passwort muss mindestens 12 Zeichen haben und mind. 3 von 4 Arten enthalten (Klein-/Großbuchstaben, Ziffern, Sonderzeichen).');
  if (pw !== pw2)    return showErr('Passwörter stimmen nicht überein.');
  const btn = document.getElementById('btn-pwreset');
  btn.disabled = true; btn.textContent = 'Wird gespeichert …';
  // WICHTIG: api/panel_pw_reset.php (öffentlich, nicht VPN-beschränkt) -
  // NICHT api/panel_auth.php oder api/admin.php, die laut nginx-Config
  // auf die Büro-VPN-IP beschränkt sind (api/admin.php würde zusätzlich
  // sogar eine aktive Admin-Session verlangen).
  const j = await postApi('api/panel_pw_reset.php', {
    action: 'do_pw_reset_panel',
    csrf:   document.querySelector('#pwreset-form input[name=csrf]').value,
    token:  '<?= htmlspecialchars($pw_reset_token) ?>',
    neu_passwort: pw, neu_passwort2: pw2
  });
  if (j.ok) {
    document.getElementById('alert-box').className = 'alert alert-success';
    document.getElementById('alert-box').textContent = '✓ Passwort geändert! Weiterleitung …';
    document.getElementById('alert-box').style.display = 'block';
    setTimeout(() => location.href = 'buero_login.php', 2000);
  } else {
    showErr(j.error || 'Fehler.');
    btn.disabled = false; btn.textContent = 'Passwort speichern →';
  }
});
<?php endif; ?>
</script>
</body>
</html>
