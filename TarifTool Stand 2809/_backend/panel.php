<?php
// _backend/panel.php  –  Gemeinsames HTML-Gerüst für Büro + Admin
// Wird per require_once eingebunden, NICHT direkt aufgerufen.

function panel_head(string $title, string $css_path = '../assets/style.css'): void { ?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars($title) ?> – Freistellungssystem</title>
<link rel="stylesheet" href="<?= $css_path ?>">
</head>
<body>
<div class="panel-wrap">
<?php }

function panel_sidebar(string $active, string $rolle, string $username, string $prefix = '../'): void {
    // $rolle kann seit der Mehrfachrollen-Umstellung eine kommagetrennte
    // Liste sein (z.B. "buero,finance") - hier nur fürs Label aufbereiten,
    // die eigentliche Zugriffsprüfung läuft über panel_hat_rolle().
    $rollen_arr   = array_map('trim', explode(',', $rolle));
    $rollen_label = ['admin' => 'Administrator', 'finance' => 'Finance', 'buero' => 'Büro'];
    $anzeige      = implode(' + ', array_map(fn($r) => $rollen_label[$r] ?? $r, $rollen_arr));
    $ist_admin    = in_array('admin', $rollen_arr, true);
?>
<aside class="sidebar">
  <div class="sidebar-brand">
    Tarif<span>Freistellung</span>
    <span class="role"><?= htmlspecialchars($anzeige) ?></span>
  </div>
  <nav>
    <div class="nav-group">Übersicht</div>
    <a href="<?= $prefix ?>buero/" class="nav-link <?= $active==='dashboard'?'active':'' ?>">
      <span class="ni">◈</span> Dashboard
    </a>
    <a href="<?= $prefix ?>buero/antraege.php" class="nav-link <?= $active==='antraege'?'active':'' ?>">
      <span class="ni">☰</span> Antragsübersicht
    </a>
    <a href="<?= $prefix ?>buero/antrag_neu.php" class="nav-link <?= $active==='antrag_neu'?'active':'' ?>">
      <span class="ni">✚</span> Antrag anlegen
    </a>
    <a href="<?= $prefix ?>buero/storno.php" class="nav-link <?= $active==='storno'?'active':'' ?>">
      <span class="ni">✕</span> Storno / Umwidmung
    </a>
    <a href="<?= $prefix ?>buero/beantragung_ag.php" class="nav-link <?= $active==='beantragung_ag'?'active':'' ?>">
      <span class="ni">📤</span> Beantragung beim AG
    </a>
    <a href="<?= $prefix ?>buero/ag_rueckmeldung.php" class="nav-link <?= $active==='ag_rueckmeldung'?'active':'' ?>">
      <span class="ni">✓</span> AG-Rückmeldung
    </a>
    <a href="<?= $prefix ?>buero/deadlines.php" class="nav-link <?= $active==='deadlines'?'active':'' ?>">
      <span class="ni">🔒</span> Deadlines
    </a>

<div class="nav-group">TK Konfiguration</div>
    <a href="<?= $prefix ?>buero/mitglieder.php" class="nav-link <?= $active==='mitglieder'?'active':'' ?>">
      <span class="ni">👤</span> Mitglieder
    </a>
    <a href="<?= $prefix ?>buero/tks.php" class="nav-link <?= $active==='tks'?'active':'' ?>">
      <span class="ni">✈</span> Tarifkommissionen
    </a>

    <div class="nav-group">Arbeitgeber</div>
    <a href="<?= $prefix ?>buero/flugbetrieb_kontakte.php" class="nav-link <?= $active==='flugbetrieb_kontakte'?'active':'' ?>">
      <span class="ni">✉</span> AG-Kontakte Planung
    </a>
    <a href="<?= $prefix ?>buero/buero_kontakt.php" class="nav-link <?= $active==='buero_kontakt'?'active':'' ?>">
      <span class="ni">@</span> Büro Kontakt
    </a>

    <div class="nav-group">Finance</div>
    <a href="<?= $prefix ?>buero/finance.php" class="nav-link <?= $active==='finance'?'active':'' ?>">
      <span class="ni">€</span> Finance
    </a>


    <?php if ($ist_admin): ?>
    <div class="nav-group">Administration</div>
    <a href="<?= $prefix ?>admin/" class="nav-link <?= $active==='admin'?'active':'' ?>">
      <span class="ni">⚙</span> Admin-Panel
    </a>
    <?php endif; ?>
  </nav>
  <div class="sidebar-foot">
    <a href="<?= $prefix ?>api/buero_logout.php">← Abmelden (<?= htmlspecialchars($username) ?>)</a>
  </div>
</aside>
<?php }

function panel_topbar(string $title, string $prefix = '../', string $username = ''): void { ?>
<div class="panel-main">
<div class="topbar">
  <button class="hamburger" id="hamburger" aria-label="Menü öffnen" onclick="toggleSidebar()">
    <span></span><span></span><span></span>
  </button>
  <h1><?= htmlspecialchars($title) ?></h1>
  <?php if ($username !== ''): ?>
  <a href="<?= $prefix ?>api/buero_logout.php" class="topbar-logout" title="Abmelden (<?= htmlspecialchars($username) ?>)">⏻</a>
  <?php endif; ?>
</div>
<div id="sidebar-overlay" class="sidebar-overlay" onclick="toggleSidebar()"></div>
<div class="content">
<?php }

function panel_foot(): void { ?>
</div><!-- /content -->
</div><!-- /panel-main -->
</div><!-- /panel-wrap -->
<script>
function toggleSidebar() {
  document.querySelector('.sidebar').classList.toggle('sidebar-open');
  document.getElementById('sidebar-overlay').classList.toggle('active');
}
</script>
</body></html>
<?php }
