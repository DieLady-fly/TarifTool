<?php
// ============================================================
// api/login.php  –  POST: Panel-Login
// ============================================================
require_once __DIR__ . '/../_backend/bootstrap.php';
send_security_headers();
session_start_secure();
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { json_err('Nur POST.', 405); }
csrf_check();

$username = trim($_POST['username'] ?? '');
$password = $_POST['password'] ?? '';

// Anmeldung per Benutzername ODER E-Mail-Adresse: wird eine E-Mail-Adresse
// eingegeben, lösen wir sie hier auf den zugehörigen tatsächlichen
// Benutzernamen auf, BEVOR die bestehende panel_login()-Prüfung (Passwort-
// Hash etc., unverändert) läuft. So wird an der eigentlichen
// Authentifizierung nichts geändert - nur die Identifikation davor.
if (strpos($username, '@') !== false) {
    $stmt = db()->prepare(
        "SELECT username FROM panel_users WHERE email = ? AND aktiv = 1 LIMIT 1"
    );
    $stmt->execute([$username]);
    $per_email = $stmt->fetchColumn();
    if ($per_email) {
        $username = $per_email;
    }
    // Kein Treffer per E-Mail: $username bleibt wie eingegeben (die
    // ursprüngliche Eingabe), damit panel_login() wie gewohnt mit
    // "Benutzername oder Passwort falsch" fehlschlägt - ohne zu verraten,
    // ob die E-Mail-Adresse im System existiert.
}

// Brute-Force-Schutz: IP-basiertes Rate-Limit über bereits fehlgeschlagene
// Versuche der letzten Stunde (wie beim Mitglieder-Login gedacht, dort aber
// wirkungslos, weil 'mk_login' nie geloggt wird - hier verwenden wir bewusst
// denselben Aktionsnamen, der unten bei jedem Fehlschlag bereits protokolliert
// wird, damit der Zähler auch tatsächlich hochzählt).
if (!rate_limit_ok('login_fehlgeschlagen')) {
    json_err('Zu viele fehlgeschlagene Anmeldeversuche. Bitte später erneut versuchen.', 429);
}

$user = panel_login($username, $password);
if (!$user) {
    sleep(1); // Verzögerung nur bei Fehlschlag
    audit('login_fehlgeschlagen', null, "Benutzer: {$username}");
    json_err('Benutzername oder Passwort falsch.', 401);
}

session_regenerate_id(true);
$_SESSION['panel_user']  = $user['username'];
$_SESSION['panel_rolle'] = $user['rolle'];
db()->prepare("UPDATE panel_users SET letzter_login = NOW() WHERE id = ?")->execute([$user['id']]);
audit('login_erfolg', null, "Benutzer: {$username}");

json_ok(['rolle' => $user['rolle']]);
