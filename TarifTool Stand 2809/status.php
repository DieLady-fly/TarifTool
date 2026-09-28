<?php
// status.php  –  Öffentliche Statusseite für Antragsteller
require_once __DIR__ . '/_backend/bootstrap.php';
send_security_headers();
$token = trim($_GET['token'] ?? '');
$valid_token = preg_match('/^[a-f0-9]{64}$/', $token);
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Antragsstatus – Freistellungssystem</title>
<link rel="stylesheet" href="assets/style.css">
</head>
<body>
<header class="site-header">
  <div class="brand">Tarif<span>Freistellung</span></div>
  <div class="tagline">Antragsstatus</div>
</header>

<main style="max-width:620px;width:100%;margin:44px auto;padding:0 20px">
  <div id="loading" style="text-align:center;padding:60px;color:var(--muted)">
    <?php if (!$valid_token): ?>
      Ungültiger Link.
    <?php else: ?>
      <span class="spinner" style="border-color:rgba(15,39,68,.2);border-top-color:var(--navy)"></span>
      Antrag wird geladen …
    <?php endif; ?>
  </div>
  <div id="content" style="display:none"></div>
  <div style="text-align:center;margin-top:20px">
    <a href="index.php" style="color:var(--muted);font-size:14px;text-decoration:none">← Neuen Antrag stellen</a>
  </div>
</main>

<?php if ($valid_token): ?>
<script>
const STATUS_MAP = {
  ausstehend:   { label: 'In Bearbeitung', color: '#856404', bg: '#fff3cd', icon: '⏳' },
  genehmigt:    { label: 'Genehmigt',      color: '#1e7e34', bg: '#e6f4ea', icon: '✓' },
  abgelehnt:    { label: 'Abgelehnt',      color: '#c0392b', bg: '#fde8e8', icon: '✕' },
  abgelehnt_ag: { label: 'Abgelehnt durch Arbeitgeber', color: '#c0392b', bg: '#fde8e8', icon: '✕' },
};

function row(k, v) {
  return `<tr>
    <td style="padding:9px 12px;background:#f8f9fb;font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--muted);width:42%;border-bottom:1px solid #f0f2f5">${k}</td>
    <td style="padding:9px 12px;font-size:14px;border-bottom:1px solid #f0f2f5">${v}</td>
  </tr>`;
}

function htmlEsc(s) {
  return String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');
}

fetch('api/status.php?token=<?= urlencode($token) ?>')
  .then(r => r.json())
  .then(d => {
    document.getElementById('loading').style.display = 'none';
    const c = document.getElementById('content');
    c.style.display = 'block';

    if (!d.ok) {
      c.innerHTML = `<div class="card"><div class="card-body"><p style="color:var(--muted);text-align:center;padding:32px">Antrag nicht gefunden.</p></div></div>`;
      return;
    }
    const a = d.data;
    const s = STATUS_MAP[a.status] || STATUS_MAP.ausstehend;
    c.innerHTML = `
      <div class="card">
        <div class="card-head" style="background:var(--navy2);color:#fff"><h2 style="color:#fff">Antragsstatus</h2></div>
        <div class="card-body">
          <div style="border-radius:10px;padding:18px 22px;margin-bottom:22px;background:${s.bg};color:${s.color};display:flex;align-items:center;gap:16px">
            <div style="font-size:30px">${s.icon}</div>
            <div>
              <div style="font-size:18px;font-weight:700">${s.label}</div>
              ${a.entschieden_am ? `<div style="font-size:13px;opacity:.75">Entschieden am: ${a.entschieden_am.substring(0,10)}</div>` : ''}
            </div>
          </div>
          ${a.notiz ? `<div style="background:#f4f7fb;border-radius:8px;padding:13px 16px;margin-bottom:18px;font-size:14px"><strong>Hinweis:</strong> ${htmlEsc(a.notiz)}</div>` : ''}
          <div class="table-scroll"><table>
            ${row('Airline', htmlEsc(a.airline))}
            ${row('Position', htmlEsc(a.position))}
            ${row('Flugzeugmuster', htmlEsc(a.flugzeugmuster))}
            ${row('Veranstaltung', htmlEsc(a.veranstaltung))}
            ${row('Zeitraum', htmlEsc(a.zeitraum_von) + ' – ' + htmlEsc(a.zeitraum_bis))}
            ${row('Eingereicht', htmlEsc(a.erstellt_am ? a.erstellt_am.substring(0,16).replace('T',' ') : ''))}
          </table></div>
        </div>
      </div>`;
  });
</script>
<?php endif; ?>
</body>
</html>
