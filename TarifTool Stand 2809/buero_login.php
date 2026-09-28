<?php
// buero_login.php  –  Login-Seite für Büro- und Admin-Panel
// Handles: Login · Konto aktivieren (Einladungstoken) · Passwort-Reset
require_once __DIR__ . '/_backend/bootstrap.php';
send_security_headers();
session_start_secure();

// Token aus URL lesen
$pw_reset_token = trim($_GET['pw_reset'] ?? '');

// Nur weiterleiten wenn kein Token in der URL – sonst Token-Flow zeigen
if (!$pw_reset_token && !empty($_SESSION['panel_user'])) {
    // panel_hat_rolle() statt striktem === Vergleich: bei Mehrfachrollen
    // (z.B. "buero,admin") würde ein exakter String-Vergleich mit 'admin'
    // fehlschlagen und Admins fälschlich ins Büro- statt Admin-Panel
    // schicken.
    $redirect = panel_hat_rolle('admin') ? 'admin/' : 'buero/';
    header('Location: ' . $redirect);
    exit;
}

// PW-Reset-Token validieren
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
<title>Anmeldung – Freistellungssystem</title>
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
        <?php if ($pwreset_gueltig): ?>
          <p>Passwort festlegen</p>
        <?php else: ?>
          <p>Panel-Anmeldung</p>
        <?php endif; ?>
      </div>
      <div class="login-body">
        <div id="alert-box" style="display:none" class="alert alert-error"></div>

        <?php if ($pwreset_gueltig): ?>
        <!-- ── PASSWORT ZURÜCKSETZEN ─────────────────────────── -->
        <p style="margin:0 0 18px;font-size:14px;color:var(--muted)">
          Bitte legen Sie Ihr neues Passwort fest.
        </p>
        <form id="pwreset-form">
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
        <!-- ── LOGIN ────────────────────────────────────────── -->
        <form id="login-form">
          <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
          <div class="fg" style="margin-bottom:18px">
            <label for="username">Benutzername oder E-Mail</label>
            <input type="text" id="username" name="username" autocomplete="username" placeholder="Benutzername oder E-Mail-Adresse" required autofocus>
          </div>
          <div class="fg" style="margin-bottom:22px">
            <label for="password">Passwort</label>
            <input type="password" id="password" name="password" autocomplete="current-password" required>
          </div>
          <button type="submit" class="btn btn-primary btn-xl btn-block" id="login-btn">Anmelden →</button>
        </form>
        <div style="text-align:center;margin-top:14px">
          <a href="passwort_vergessen.php" style="font-size:13px;color:var(--muted,#7a8fa6);text-decoration:none">
            Passwort vergessen?
          </a>
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
  // WICHTIG: submit statt nur click auf den Button - so funktioniert auch
  // das Bestätigen per Enter-Taste in einem der beiden Passwortfelder.
  // Vorher lagen die Felder in keinem <form>, wodurch Enter komplett
  // wirkungslos war (kein Request, keine Fehlermeldung) - der Button-Klick
  // war der einzige Weg, das Passwort tatsächlich zu speichern.
  e.preventDefault();
  const pw  = document.getElementById('rst-pw').value;
  const pw2 = document.getElementById('rst-pw2').value;
  hideErr();
  if (pw.length < 12) return showErr('Passwort muss mindestens 12 Zeichen haben und mind. 3 von 4 Arten enthalten (Klein-/Großbuchstaben, Ziffern, Sonderzeichen).');
  if (pw !== pw2)    return showErr('Passwörter stimmen nicht überein.');
  const btn = document.getElementById('btn-pwreset');
  btn.disabled = true; btn.textContent = 'Wird gespeichert …';
  const j = await postApi('api/panel_auth.php', {
    action: 'pw_reset',
    token:  '<?= htmlspecialchars($pw_reset_token) ?>',
    password: pw, password2: pw2
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

<?php else: ?>
document.getElementById('login-form').addEventListener('submit', async function(e) {
  e.preventDefault();
  const btn = document.getElementById('login-btn');
  hideErr();
  btn.disabled = true;
  btn.innerHTML = '<span class="spinner"></span> Anmelden …';
  const fd = new FormData(this);
  try {
    const res  = await fetch('api/buero_login.php', {method:'POST', body:fd});
    const json = await res.json();
    if (json.ok) {
      const rolle = json.data.rolle;
      // Mehrfachrollen sind kommagetrennt (z.B. "buero,admin") - daher
      // Teilstring-Prüfung statt exaktem Vergleich, sonst würde ein Admin
      // mit Zweitrolle fälschlich ins Büro- statt Admin-Panel geleitet.
      const istAdmin = String(rolle || '').split(',').map(r => r.trim()).includes('admin');
      const dest = istAdmin ? 'admin/' : 'buero/';
      window.location.href = dest;
    } else {
      showErr(json.error);
      btn.disabled = false;
      btn.innerHTML = 'Anmelden →';
    }
  } catch {
    showErr('Verbindungsfehler.');
    btn.disabled = false;
    btn.innerHTML = 'Anmelden →';
  }
});
<?php endif; ?>
</script>
</body>
</html>
