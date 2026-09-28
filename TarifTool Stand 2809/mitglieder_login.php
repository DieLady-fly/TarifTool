<?php
// ============================================================
// mitglieder_login.php  –  Login-Seite für TK-Mitglieder
// Handles: Login · Einladung aktivieren · Passwort-Reset
// ============================================================
require_once __DIR__ . '/_backend/bootstrap.php';
require_once __DIR__ . '/_backend/mitglieder_auth.php';
send_security_headers();

// Bereits eingeloggt → direkt zum Portal
if (mitglied_aus_session()) {
    header('Location: index.php');
    exit;
}

// Token aus URL lesen
$einladung_token = trim($_GET['einladung'] ?? '');
$pw_reset_token  = trim($_GET['pw_reset']  ?? '');

// Einladungs-Token validieren
$einladung_gueltig = false;
$einladung_vorname = '';
if ($einladung_token && preg_match('/^[a-f0-9]{64}$/', $einladung_token)) {
    $chk = db()->prepare(
        "SELECT vorname FROM mitglieder
         WHERE einladung_token = ? AND einladung_ablauf > NOW() AND aktiv = 1 LIMIT 1"
    );
    $chk->execute([$einladung_token]);
    $row = $chk->fetch();
    if ($row) {
        $einladung_gueltig = true;
        $einladung_vorname = $row['vorname'];
    }
}

// PW-Reset-Token validieren
$pwreset_gueltig = false;
if ($pw_reset_token && preg_match('/^[a-f0-9]{64}$/', $pw_reset_token)) {
    $chk = db()->prepare(
        "SELECT id FROM mitglieder
         WHERE pw_reset_token = ? AND pw_reset_ablauf > NOW() AND aktiv = 1 LIMIT 1"
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
<title>Mitglieder-Login – Freistellungssystem Tarif</title>
<link rel="stylesheet" href="assets/style.css">
<style>
.auth-wrap { max-width:440px; margin:60px auto; padding:0 20px; }
.auth-card { background:#fff; border-radius:12px; box-shadow:0 4px 24px rgba(0,0,0,.1); overflow:hidden; }
.auth-head { background:var(--navy2,#1a3a5c); color:#fff; padding:28px 32px 20px; border-bottom:3px solid var(--gold,#c8a84b); }
.auth-head h1 { margin:0; font-size:20px; font-weight:700; }
.auth-head p  { margin:6px 0 0; font-size:13px; opacity:.8; }
.auth-body { padding:28px 32px; }
.auth-body .fg { margin-bottom:16px; }
.auth-body label { display:block; font-size:13px; font-weight:600; color:var(--navy2,#1a3a5c); margin-bottom:5px; }
.auth-body input[type=email],
.auth-body input[type=password] {
  width:100%; box-sizing:border-box; padding:9px 12px;
  border:1px solid var(--border,#dde2ea); border-radius:7px; font-size:14px; transition:border-color .15s;
}
.auth-body input:focus { outline:none; border-color:var(--navy2,#1a3a5c); }
.btn-auth {
  width:100%; padding:12px; background:var(--navy2,#1a3a5c); color:#fff; border:none;
  border-radius:8px; font-size:15px; font-weight:700; cursor:pointer; margin-top:4px; transition:background .15s;
}
.btn-auth:hover    { background:#0f2744; }
.btn-auth:disabled { opacity:.6; cursor:not-allowed; }
.auth-links { text-align:center; margin-top:14px; font-size:13px; color:var(--muted,#7a8fa6); }
.auth-links a { color:var(--navy2,#1a3a5c); text-decoration:none; }
.auth-links a:hover { text-decoration:underline; }
.msg-box { padding:10px 14px; border-radius:8px; margin-bottom:12px; font-size:13px; display:none; }
.msg-box.show { display:block; }
.msg-err { background:#f8d7da; color:#721c24; border:1px solid #f5c6cb; }
.msg-ok  { background:#d4edda; color:#155724; border:1px solid #c3e6cb; }
.pw-strength { height:4px; border-radius:2px; margin-top:6px; transition:all .3s; }
.pw-s0 { background:#e9ecef; width:0; }
.pw-s1 { background:#dc3545; width:33%; }
.pw-s2 { background:#ffc107; width:66%; }
.pw-s3 { background:#28a745; width:100%; }
</style>
</head>
<body>

<header class="site-header">
  <div class="brand">Tarif<span>Freistellung</span></div>
  <div class="tagline">Mitglieder-Portal</div>
</header>

<?php if ($einladung_gueltig): ?>
<!-- ══ KONTO AKTIVIEREN ══════════════════════════════════════ -->
<div class="auth-wrap">
  <div class="auth-card">
    <div class="auth-head">
      <h1>🔐 Konto aktivieren</h1>
      <p>Hallo <?= htmlspecialchars($einladung_vorname) ?>, bitte legen Sie Ihr Passwort fest.</p>
    </div>
    <div class="auth-body">
      <div id="msg-aktiv" class="msg-box"></div>
      <form id="aktiv-form">
        <div class="fg">
          <label for="akt-pw">Neues Passwort <span style="color:#e63946">*</span></label>
          <input type="password" id="akt-pw" placeholder="Mindestens 12 Zeichen" autocomplete="new-password">
          <div class="pw-strength pw-s0" id="akt-pw-strength"></div>
        </div>
        <div class="fg">
          <label for="akt-pw2">Passwort wiederholen <span style="color:#e63946">*</span></label>
          <input type="password" id="akt-pw2" placeholder="Passwort bestätigen" autocomplete="new-password">
        </div>
        <button type="submit" class="btn-auth" id="btn-aktiv">Konto aktivieren →</button>
      </form>
    </div>
  </div>
</div>

<?php elseif ($pwreset_gueltig): ?>
<!-- ══ PASSWORT ZURÜCKSETZEN ════════════════════════════════ -->
<div class="auth-wrap">
  <div class="auth-card">
    <div class="auth-head">
      <h1>🔑 Neues Passwort</h1>
      <p>Bitte legen Sie Ihr neues Passwort fest.</p>
    </div>
    <div class="auth-body">
      <div id="msg-pwreset" class="msg-box"></div>
      <form id="pwreset-form">
        <div class="fg">
          <label for="rst-pw">Neues Passwort <span style="color:#e63946">*</span></label>
          <input type="password" id="rst-pw" placeholder="Mindestens 12 Zeichen" autocomplete="new-password">
          <div class="pw-strength pw-s0" id="rst-pw-strength"></div>
        </div>
        <div class="fg">
          <label for="rst-pw2">Passwort wiederholen <span style="color:#e63946">*</span></label>
          <input type="password" id="rst-pw2" placeholder="Passwort bestätigen" autocomplete="new-password">
        </div>
        <button type="submit" class="btn-auth" id="btn-pwreset">Passwort speichern →</button>
      </form>
    </div>
  </div>
</div>

<?php else: ?>
<!-- ══ LOGIN ════════════════════════════════════════════════ -->
<div class="auth-wrap">
  <div class="auth-card">
    <div class="auth-head">
      <h1>✈ Mitglieder-Login</h1>
      <p>Melden Sie sich mit Ihrer VC-E-Mail-Adresse an.</p>
    </div>
    <div class="auth-body">
      <div id="msg-login" class="msg-box"></div>
      <form id="login-form">
        <div class="fg">
          <label for="login-email">VC-E-Mail-Adresse</label>
          <input type="email" id="login-email" placeholder="ihre@vc.de" autocomplete="email" autofocus>
        </div>
        <div class="fg">
          <label for="login-pw">Passwort</label>
          <input type="password" id="login-pw" placeholder="Ihr Passwort" autocomplete="current-password">
        </div>
        <button type="submit" class="btn-auth" id="btn-login">Anmelden →</button>
        <div class="auth-links">
          <a href="passwort_vergessen.php">Passwort vergessen?</a>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<footer>© <?= date('Y') ?> Freistellungssystem Tarif</footer>

<script>
function showMsg(id,text,err=true){const e=document.getElementById(id);if(!e)return;e.className='msg-box show '+(err?'msg-err':'msg-ok');e.textContent=text;}
function hideMsg(id){const e=document.getElementById(id);if(e)e.className='msg-box';}
async function postApi(url,data){
  const f=new FormData();
  for(const[k,v]of Object.entries(data))f.append(k,v);
  return(await fetch(url,{method:'POST',body:f,credentials:'same-origin'})).json();
}
function pwStrength(pw){let k=0;if(/[a-z]/.test(pw))k++;if(/[A-Z]/.test(pw))k++;if(/[0-9]/.test(pw))k++;if(/[^a-zA-Z0-9]/.test(pw))k++;let s=0;if(pw.length>=12&&k>=3)s=3;else if(pw.length>=12||k>=3)s=2;else if(pw.length>0)s=1;return s;}
function updateStrength(inp,bar){const b=document.getElementById(bar);if(b)b.className='pw-strength pw-s'+pwStrength(document.getElementById(inp)?.value||'');}

<?php if ($einladung_gueltig): ?>
document.getElementById('akt-pw')?.addEventListener('input',()=>updateStrength('akt-pw','akt-pw-strength'));
document.getElementById('aktiv-form')?.addEventListener('submit',async(e)=>{
  // submit statt nur click auf den Button: vorher lagen die Felder in
  // keinem <form>, wodurch Enter in einem der beiden Passwortfelder
  // komplett wirkungslos war (kein Request, kein Fehler) - das erklärte
  // Fälle, in denen trotz Eingabe kein password_hash gespeichert wurde.
  e.preventDefault();
  const pw=document.getElementById('akt-pw').value,pw2=document.getElementById('akt-pw2').value;
  hideMsg('msg-aktiv');
  if(pw.length<12)return showMsg('msg-aktiv','Passwort muss mindestens 12 Zeichen haben und mind. 3 von 4 Arten enthalten (Klein-/Großbuchstaben, Ziffern, Sonderzeichen).');
  if(pw!==pw2)return showMsg('msg-aktiv','Passwörter stimmen nicht überein.');
  const btn=document.getElementById('btn-aktiv');btn.disabled=true;btn.textContent='Wird gespeichert …';
  const j=await postApi('api/mitglieder_login.php',{action:'aktivieren',token:'<?= htmlspecialchars($einladung_token) ?>',password:pw,password2:pw2});
  if(j.ok){showMsg('msg-aktiv','✓ Konto aktiviert! Weiterleitung …',false);setTimeout(()=>location.href='mitglieder_login.php',2000);}
  else{showMsg('msg-aktiv',j.error||'Fehler.');btn.disabled=false;btn.textContent='Konto aktivieren →';}
});

<?php elseif ($pwreset_gueltig): ?>
document.getElementById('rst-pw')?.addEventListener('input',()=>updateStrength('rst-pw','rst-pw-strength'));
document.getElementById('pwreset-form')?.addEventListener('submit',async(e)=>{
  e.preventDefault();
  const pw=document.getElementById('rst-pw').value,pw2=document.getElementById('rst-pw2').value;
  hideMsg('msg-pwreset');
  if(pw.length<12)return showMsg('msg-pwreset','Passwort muss mindestens 12 Zeichen haben und mind. 3 von 4 Arten enthalten (Klein-/Großbuchstaben, Ziffern, Sonderzeichen).');
  if(pw!==pw2)return showMsg('msg-pwreset','Passwörter stimmen nicht überein.');
  const btn=document.getElementById('btn-pwreset');btn.disabled=true;btn.textContent='Wird gespeichert …';
  const j=await postApi('api/mitglieder_login.php',{action:'pw_reset_ausfuehren',token:'<?= htmlspecialchars($pw_reset_token) ?>',password:pw,password2:pw2});
  if(j.ok){showMsg('msg-pwreset','✓ Passwort geändert! Weiterleitung …',false);setTimeout(()=>location.href='mitglieder_login.php',2000);}
  else{showMsg('msg-pwreset',j.error||'Fehler.');btn.disabled=false;btn.textContent='Passwort speichern →';}
});

<?php else: ?>
document.getElementById('login-form')?.addEventListener('submit',async(e)=>{
  // submit statt click: funktioniert jetzt per Enter aus JEDEM Feld
  // (vorher nur aus dem Passwortfeld per manuellem keydown-Hack, das
  // E-Mail-Feld hatte gar keine Enter-Unterstützung).
  e.preventDefault();
  const email=document.getElementById('login-email').value.trim(),pw=document.getElementById('login-pw').value;
  hideMsg('msg-login');
  if(!email||!pw)return showMsg('msg-login','Bitte E-Mail und Passwort eingeben.');
  const btn=document.getElementById('btn-login');btn.disabled=true;btn.textContent='Anmelden …';
  const j=await postApi('api/mitglieder_login.php',{action:'login',email,password:pw});
  if(j.ok){window.location.href='index.php';}
  else{showMsg('msg-login',j.error||'Anmeldung fehlgeschlagen.');btn.disabled=false;btn.textContent='Anmelden →';}
});
<?php endif; ?>
</script>
</body>
</html>
